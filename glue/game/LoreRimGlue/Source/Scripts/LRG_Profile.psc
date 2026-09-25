Scriptname LRG_Profile Hidden
;/LoreRim Glue - builds the per-NPC snapshot (hard facts) sent to the server.
 Nothing here decides anything about romance: it only reports facts. The server's
 data-driven config turns faction EditorIDs / class / actor values into a strictness
 profile, so factions added by LoreRim's mods work without any FormID list.

 Payload format: k=v;k=v (no quotes, no pipes, no @) - see LRG_Main.ReportResult./;

; ---------------------------------------------------------------------------
; Adult check - FAIL CLOSED. Any doubt = not adult.
; (OStim's own check runs separately in LRG_OStim; the server repeats a third one.)
; ---------------------------------------------------------------------------
bool Function HasChildToken(string asText, bool abVoiceType = false) Global
	if asText == ""
		return false
	endif
	if StringUtil.Find(asText, "child") >= 0 || StringUtil.Find(asText, "kid") >= 0
		return true
	endif
	if StringUtil.Find(asText, "teen") >= 0
		return true
	endif
	; "young": the vanilla ADULT voice types MaleYoungEager / FemaleYoungEager (Ysolda, Camilla,
	; Sven and hundreds more) must not read as children - otherwise every town reports
	; "a child is nearby". For voice types only that exact vanilla name is exempt.
	if StringUtil.Find(asText, "young") >= 0
		if !abVoiceType || StringUtil.Find(asText, "YoungEager") < 0
			return true
		endif
	endif
	if StringUtil.Find(asText, "little") >= 0 || StringUtil.Find(asText, "infant") >= 0 || StringUtil.Find(asText, "baby") >= 0
		return true
	endif
	return false
EndFunction

bool Function IsAdult(Actor akActor) Global
	if !akActor || akActor.IsChild()
		return false
	endif
	if !akActor.HasKeywordString("ActorTypeNPC")
		return false
	endif
	Race r = akActor.GetRace()
	if !r
		return false
	endif
	string raceId = PO3_SKSEFunctions.GetFormEditorID(r)
	string raceName = r.GetName()
	if raceId == "" && raceName == ""
		return false ; unknown race with no evidence at all
	endif
	if HasChildToken(raceId) || HasChildToken(raceName)
		return false
	endif
	VoiceType vt = akActor.GetVoiceType()
	if vt && HasChildToken(PO3_SKSEFunctions.GetFormEditorID(vt), true)
		return false
	endif
	ActorBase ab = akActor.GetLeveledActorBase()
	if ab
		Class c = ab.GetClass()
		if c && HasChildToken(PO3_SKSEFunctions.GetFormEditorID(c))
			return false
		endif
	endif
	return true
EndFunction

; ---------------------------------------------------------------------------
; Marriage (verified in Skyrim.esm: association type "Spouse", faction
; "PlayerMarriedFaction"; there is no "MarriedFaction")
; ---------------------------------------------------------------------------
bool Function IsMarried(Actor akActor) Global
	AssociationType spouse = PO3_SKSEFunctions.GetFormFromEditorID("Spouse") as AssociationType
	if !spouse
		; EditorID lookup needs po3 Tweaks' editor-id cache; fall back to the vanilla FormID
		spouse = Game.GetFormFromFile(0x000142CA, "Skyrim.esm") as AssociationType
	endif
	if spouse && akActor.HasAssociation(spouse)
		return true
	endif
	return false
EndFunction

bool Function IsPlayerSpouse(Actor akActor) Global
	Faction f = PO3_SKSEFunctions.GetFormFromEditorID("PlayerMarriedFaction") as Faction
	if !f
		f = Game.GetFormFromFile(0x000C6472, "Skyrim.esm") as Faction
	endif
	return f && akActor.IsInFaction(f)
EndFunction

bool Function IsCourting(Actor akActor, Actor akPlayer) Global
	;/ Courting WITH THE PLAYER. HasAssociation(type) with the second argument left out asks "does
	 this actor have that association with ANYONE", which is why playtest 7 reported courting=1 for
	 Jala, Vivienne Onis and Sorex Vinius - none of them courting the player - and handed each of
	 them the server's +10 courting bonus. The partner is now named explicitly. /;
	AssociationType courting = PO3_SKSEFunctions.GetFormFromEditorID("Courting") as AssociationType
	return courting && akPlayer && akActor.HasAssociation(courting, akPlayer)
EndFunction

; ---------------------------------------------------------------------------
; Status inputs: faction EditorIDs (bounded), class
; ---------------------------------------------------------------------------
string Function FactionList(Actor akActor) Global
	Faction[] facs = akActor.GetFactions(0, 127)
	string out = ""
	int i = 0
	; [0.5.6] an array is only ever tested with its own truth test (never "== None"), then measured
	int n = 0
	if facs
		n = facs.Length
	endif
	if n > 48
		n = 48
	endif
	while i < n && StringUtil.GetLength(out) < 560
		if facs[i]
			string id = PO3_SKSEFunctions.GetFormEditorID(facs[i])
			if id != ""
				if out != ""
					out += ","
				endif
				out += id
			endif
		endif
		i += 1
	endwhile
	return out
EndFunction

; ---------------------------------------------------------------------------
; [0.4 / OWNER ADDENDA 4b] A CLOSED DOOR MAKES A ROOM PRIVATE.
; The owner's complaint: "because the tavern is a crowded place people dont want to hook up in the
; rooms, lets change that so if someone is in a room with a closed door, it will be considered
; somewhere private". The cause was one clause in WitnessScan below, which counted every awake adult
; within 600 units with NO line-of-sight test at all - through a closed door, a floor or a stone wall.
; In an occupied inn the count could therefore never reach 0 whatever door was shut.
; This is deliberately a ROOM-LEVEL boolean, not a per-actor "is there a door between us" test: Papyrus
; has no ray cast to an arbitrary actor, so that cannot be done reliably, and it would cost one scan per
; actor. A shut room is private, an open one is not - which is the owner's case. Please do not "improve"
; this into 40 door scans per snapshot.
; ---------------------------------------------------------------------------
int Function DoorState(Actor akPlayer, float afRadius) Global
	{1 = the player is in an interior and every door within afRadius is shut. 0 = an open door is in
	 reach, or this is not an interior. An ABSENT door= key on the wire means "say nothing".}
	; GetOpenState: 0 none, 1 open, 2 opening, 3 closed, 4 closing (installed ObjectReference.psc:347).
	; Form type 29 = door, confirmed against two installed call sites: Biggie Traits
	; Traits_HomeownerVisitHouseScript.psc:9 (allDoors) and CHIM AIAgentAIMind.psc:2798 (doors).
	; A LOAD door counts as a door on purpose: a closed load door is the most private door there is.
	if !akPlayer
		return 0
	endif
	Cell c = akPlayer.GetParentCell()
	if !c || !c.IsInterior()
		return 0 ; outdoors there is no room to shut
	endif
	if afRadius < 50.0
		afRadius = 250.0 ; a missing MCM key reads as 0 and would find no door at all
	elseif afRadius > 2000.0
		afRadius = 2000.0
	endif
	ObjectReference[] refs = PO3_SKSEFunctions.FindAllReferencesOfFormType(akPlayer, 29, afRadius)
	int n = 0
	if refs
		n = refs.Length
	endif
	if n > 12
		n = 12 ; bounded: one native plus at most twelve very cheap ones
	endif
	int i = 0
	while i < n
		ObjectReference d = refs[i]
		i += 1
		if d
			int st = d.GetOpenState()
			if st == 1 || st == 2
				return 0 ; an open (or opening) door in reach: this room is not shut
			endif
		endif
	endwhile
	return 1
EndFunction

; ---------------------------------------------------------------------------
; Witness scan - bounded, run only when a snapshot is built.
; Returns "wit=<n>;witfol=<n>;witkid=<0|1>"
; [0.4] abDoorShut / afHearRadius default to exactly today's behaviour, so an un-updated call site
; cannot become more permissive by accident.
; ---------------------------------------------------------------------------
string Function WitnessScan(Actor akNpc, Actor akPlayer, float afRadius, bool abDoorShut = false, float afHearRadius = 600.0, bool abFollowerAware = false) Global
	;/[0.5.1] witchim: the player's OWN companions that the teammate flag does not see - a CHIM
	 MakeFollower ghost (pt9 section 4.1). She used to be counted as an ordinary stranger, so
	 "my companion is standing right there" read as "a stranger is watching".
	 She is STILL counted in wit as well, on purpose: witchim only ever makes the privacy gate
	 stricter (the server adds companion_present), never looser. One extra native call per checked
	 adult, and only while bFollowerAware is on./;
	Actor[] near = PO3_SKSEFunctions.GetActorsByProcessingLevel(0) ; high process = loaded and close
	int nearN = 0
	if near
		nearN = near.Length
	endif
	Faction cff = None
	if abFollowerAware
		cff = LRG_Followers.FacCurrentFollower()
	endif
	int wit = 0
	int fol = 0
	int chim = 0
	int kid = 0
	int checked = 0
	int i = 0
	while i < nearN && i < 80 && checked < 40
		Actor a = near[i]
		i += 1
		; distance first: one native call rejects most of the high-process list before the
		; more expensive checks run (LoreRim cities keep 60+ actors in high process)
		float d = afRadius + 1.0
		if a && a != akNpc && a != akPlayer
			d = a.GetDistance(akPlayer)
		endif
		if d <= afRadius
			if !a.IsDead() && !a.IsUnconscious() && a.HasKeywordString("ActorTypeNPC")
				checked += 1
				if !IsAdult(a)
					; a child in range blocks everything anyway: stop scanning, skip all further LOS work
					return "wit=" + wit + ";witfol=" + fol + ";witchim=" + chim + ";witkid=1"
				elseif a.GetSleepState() < 3 ; 3 = sleeping
					if a.IsPlayerTeammate()
						fol += 1
					else
						if cff && a != akNpc && a.GetFactionRank(cff) >= 1
							chim += 1
						endif
						if a.HasLOS(akPlayer) || a.HasLOS(akNpc)
							; SEEN is always seen. Actor.HasLOS is a real engine line-of-sight test and
							; already fails through a closed door, so this half of the old condition was
							; right all along - an open doorway or a shared room still counts, door or no.
							wit += 1
						elseif !abDoorShut && d < afHearRadius
							; HEARD: somebody near enough to hear who can see NEITHER of them. That only
							; counts when no door is shut between them. This one clause was the whole bug.
							wit += 1
						endif
					endif
				endif
			endif
		endif
	endwhile
	return "wit=" + wit + ";witfol=" + fol + ";witchim=" + chim + ";witkid=" + kid
EndFunction

; ---------------------------------------------------------------------------
; Player renown / means - used for the leverage block (bribery, status)
; ---------------------------------------------------------------------------
string Function PlayerFacts(Actor akPlayer) Global
	int dragonborn = 0
	Quest mq = Quest.GetQuest("MQ104") ; Dragon Rising: the player is publicly the Dragonborn afterwards
	if mq && mq.IsCompleted()
		dragonborn = 1
	endif
	string s = "plvl=" + akPlayer.GetLevel()
	s += ";pgold=" + akPlayer.GetGoldAmount()
	s += ";pspeech=" + (akPlayer.GetActorValue("Speechcraft") as int)
	s += ";pdb=" + dragonborn
	s += ";psouls=" + Game.QueryStat("Dragon Souls Collected")
	s += ";pquests=" + Game.QueryStat("Quests Completed")
	; player sex for the server's scene filter: 0 male, 1 female, -1 unknown (NPC's is "sex=")
	int psex = -1
	ActorBase pab = akPlayer.GetLeveledActorBase()
	if pab
		psex = pab.GetSex()
	endif
	s += ";psex=" + psex
	return s
EndFunction

; ---------------------------------------------------------------------------
; [pt19-purchase / PROTOCOL 10.27] Market facts: what a vendor sells, at the game's own barter price.
; vend=0|1[;room=N;bp=max,min,buymin,speech,spmod,sppow,mult,mods;stock=<hex8>:<name>:<price>:<count>:<value>,...]
; One PO3 call for a non-vendor; a vendor's scan (<= 40 food/drink forms looked at, <= 6 drinks then <= 8 food)
; is cached 60 s per NPC in StorageUtil, and LRG_Main.CmdBuy drops the cache after a sale. The price model is
; UESP Skyrim:Speech with LIVE inputs (Game.GetGameSettingFloat, the Speech actor values, the held price perks of
; this load order by MASTER FormID); the server mirrors it (lrgMktModelPrice) and logs a drift.
; Natives verified in the installed sources: PO3_SKSEFunctions.GetVendorFaction :101 / AddItemsOfTypeToArray :792,
; Faction.IsVendor :100 / IsNotSellBuy :127 / GetMerchantContainer :124, Potion.IsFood :8, Form.GetGoldValue :7,
; Game.GetGameSettingFloat :108, Actor.GetActorValue :143 / HasPerk :325 / IsInFaction :391, ActorBase.GetSex :19.
; ---------------------------------------------------------------------------
string Function MarketFacts(Actor akNpc, Actor akPlayer, LRG_Main akMain) Global
	{The market block for the snapshot, "" while bServiceDialogue:Services is off or nobody can read settings.}
	if akMain == None || !akMain.SettingBool("bServiceDialogue:Services", true)
		return ""
	endif
	Faction vf = PO3_SKSEFunctions.GetVendorFaction(akNpc)
	; [review] NOT IsNotSellBuy(): that flag only makes the vendor list an EXCLUSION list. Every general-goods vendor and
	; Riften's food stall carry it (Belethor, Marise, the caravans: VENV notSellBuy=1, list VendorItemsMisc) and sell food.
	if vf == None || !vf.IsVendor()
		return ";vend=0"
	endif
	float now = Utility.GetCurrentRealTime()
	float at = StorageUtil.GetFloatValue(akNpc, "LRG.mkt.at", 0.0)
	if at > 0.0 && now >= at && (now - at) < 60.0
		return StorageUtil.GetStringValue(akNpc, "LRG.mkt", ";vend=1")
	endif
	string s = ";vend=1"
	GlobalVariable rc = Game.GetFormFromFile(0x0009CC98, "Skyrim.esm") as GlobalVariable
	if rc != None
		s += ";room=" + (rc.GetValue() as int)
	endif
	string mods = BuyMods(akNpc, akPlayer)
	float mult = BuyMultFrom(mods, akPlayer.GetActorValue("Speechcraft"))
	s += ";bp=" + BuyWire(akPlayer, mods, mult)
	ObjectReference src = vf.GetMerchantContainer()
	if src == None
		src = akNpc as ObjectReference
	endif
	s += ";stock=" + StockCsv(src, mult)
	StorageUtil.SetStringValue(akNpc, "LRG.mkt", s)
	StorageUtil.SetFloatValue(akNpc, "LRG.mkt.at", now)
	return s
EndFunction

string Function BuyMods(Actor akNpc, Actor akPlayer) Global
	{The held price perks of this load order as "<name>:<value>/..." ("-" for none): multiply entries in priority
	 order, Requiem's Haggling as haggling:<add> (-0.01 x Speech). Forms by MASTER FormID, None-safe.}
	string s = ""
	s = BuyModAdd(s, akPlayer, 0x0002029E, "Unofficial Skyrim Special Edition Patch.esp", "giftofgab", 0.95)
	s = BuyModAdd(s, akPlayer, 0x000E5F57, "Skyrim.esm", "loversign", 1.15)
	s = BuyModAdd(s, akPlayer, 0x0000080D, "LoreRim Traits.esp", "silentdovah", 2.0)
	s = BuyModAdd(s, akPlayer, 0x00000833, "DVSsurvivaltweaks.esp", "weary", 1.5)
	s = BuyModAdd(s, akPlayer, 0x00000834, "DVSsurvivaltweaks.esp", "debilitated", 2.0)
	s = BuyModAdd(s, akPlayer, 0x0047B5B8, "Requiem.esp", "painfulregrets", 0.75)
	s = BuyModAdd(s, akPlayer, 0x00000847, "SubclassesOfSkyrim.esp", "bard", 0.9)
	s = BuyModAdd(s, akPlayer, 0x000BA909, "Apocalypse - Magic of Skyrim.esp", "kingsheart", 0.85)
	s = BuyModAdd(s, akPlayer, 0x0012A7A5, "Vigilant.esm", "goldcat", 0.5)
	; Lover's Insight: the opposite sex only
	Perk li = Game.GetFormFromFile(0x0001E7F1, "Dragonborn.esm") as Perk
	if li != None && akPlayer.HasPerk(li) && BuyOppositeSex(akNpc, akPlayer)
		s = BuyModCat(s, "loversinsight", 0.9)
	endif
	; the Arch-Mage's discount, inside the College only
	Perk am = Game.GetFormFromFile(0x0010F9DB, "Skyrim.esm") as Perk
	if am != None && akPlayer.HasPerk(am)
		Faction col = Game.GetFormFromFile(0x0001F259, "Skyrim.esm") as Faction
		if col != None && akPlayer.IsInFaction(col)
			s = BuyModCat(s, "archmage", 0.9)
		endif
	endif
	; prio 4 / 5: Requiem's Fortify Speech enchantments / potions as a price change (AV 107 SpeechcraftMod, 146 SpeechcraftPowerMod)
	Perk sb = Game.GetFormFromFile(0x000CF788, "Skyrim.esm") as Perk
	if sb != None && akPlayer.HasPerk(sb)
		float spmod = akPlayer.GetActorValue("SpeechcraftMod")
		if spmod != 0.0
			s = BuyModCat(s, "skillboosts", 1.0 - 0.01 * spmod)
		endif
	endif
	Perk pb = Game.GetFormFromFile(0x000A725C, "Skyrim.esm") as Perk
	if pb != None && akPlayer.HasPerk(pb)
		float sppow = akPlayer.GetActorValue("SpeechcraftPowerMod")
		if sppow != 0.0
			s = BuyModCat(s, "powerboosts", 1.0 - 0.01 * sppow)
		endif
	endif
	; prio 100 Merchant x0.80, prio 101 Haggling ADD -0.01 x Speech, prio 103 Silver Tongue x0.90
	s = BuyModAdd(s, akPlayer, 0x00058F7A, "Skyrim.esm", "merchant", 0.8)
	Perk hg = Game.GetFormFromFile(0x000BE128, "Skyrim.esm") as Perk
	if hg != None && akPlayer.HasPerk(hg)
		s = BuyModCat(s, "haggling", -0.01 * akPlayer.GetActorValue("Speechcraft"))
	endif
	s = BuyModAdd(s, akPlayer, 0x00058F72, "Skyrim.esm", "silvertongue", 0.9)
	; detected only - its condition (GetIsID on one vendor) is not readable here; carried as 1.0 so the log names it
	Perk rvt = Game.GetFormFromFile(0x000008D6, "Requiem - Vendor tweaks.esp") as Perk
	if rvt != None && akPlayer.HasPerk(rvt)
		s = BuyModCat(s, "unmodelled_rvt", 1.0)
	endif
	if s == ""
		return "-"
	endif
	return s
EndFunction

string Function BuyModAdd(string asMods, Actor akPlayer, int aiFormId, string asFile, string asName, float afMult) Global
	Perk p = Game.GetFormFromFile(aiFormId, asFile) as Perk
	if p == None || !akPlayer.HasPerk(p)
		return asMods
	endif
	return BuyModCat(asMods, asName, afMult)
EndFunction

string Function BuyModCat(string asMods, string asName, float afValue) Global
	if asMods == ""
		return asName + ":" + afValue
	endif
	return asMods + "/" + asName + ":" + afValue
EndFunction

bool Function BuyOppositeSex(Actor akNpc, Actor akPlayer) Global
	ActorBase a = akNpc.GetLeveledActorBase()
	ActorBase b = akPlayer.GetLeveledActorBase()
	if a == None || b == None
		return false
	endif
	return a.GetSex() != b.GetSex()
EndFunction

float Function BuyMultFrom(string asMods, float afSpeech) Global
	{f = fBarterMax - (fBarterMax - fBarterMin) x min(Speech,100)/100; the mods in order (multiply; haggling adds);
	 floored at fBarterBuyMin. The entry-point order (add vs multiply) is an assumption the server's drift line and
	 CmdBuy's re-quote guard; research/pt19-purchase.md section 3.}
	float bmax = Game.GetGameSettingFloat("fBarterMax")
	float bmin = Game.GetGameSettingFloat("fBarterMin")
	float floorMult = Game.GetGameSettingFloat("fBarterBuyMin")
	float sp = afSpeech
	if sp > 100.0
		sp = 100.0
	elseif sp < 0.0
		sp = 0.0
	endif
	float f = bmax - (bmax - bmin) * sp / 100.0
	float mod = 1.0
	if asMods != "" && asMods != "-"
		string[] parts = PapyrusUtil.StringSplit(asMods, "/")
		int i = 0
		while i < parts.Length
			string[] kv = PapyrusUtil.StringSplit(parts[i], ":")
			if kv.Length >= 2
				float v = kv[1] as float
				if kv[0] == "haggling"
					mod += v
				elseif StringUtil.Find(kv[0], "unmodelled") != 0
					mod *= v
				endif
			endif
			i += 1
		endwhile
	endif
	float mult = f * mod
	if mult < floorMult
		mult = floorMult
	endif
	return mult
EndFunction

string Function BuyWire(Actor akPlayer, string asMods, float afMult) Global
	{bp= max,min,buymin,speech,spmod,sppow,mult,mods - every input of the price, so the server can recompute and the log can name the wrong one.}
	return "" + Game.GetGameSettingFloat("fBarterMax") + "," + Game.GetGameSettingFloat("fBarterMin") + "," \
		+ Game.GetGameSettingFloat("fBarterBuyMin") + "," + (akPlayer.GetActorValue("Speechcraft") as int) + "," \
		+ akPlayer.GetActorValue("SpeechcraftMod") + "," + akPlayer.GetActorValue("SpeechcraftPowerMod") + "," + afMult + "," + asMods
EndFunction

int Function BuyPrice(Form akItem, Actor akNpc, Actor akPlayer, bool abLog) Global
	{The price the barter window asks for ONE of akItem: floor(value x mult + 0.5). abLog writes one line naming every input.}
	string mods = BuyMods(akNpc, akPlayer)
	float sp = akPlayer.GetActorValue("Speechcraft")
	float mult = BuyMultFrom(mods, sp)
	int value = akItem.GetGoldValue()
	int price = Math.Floor(value * mult + 0.5)
	if abLog
		LRG_Main m = LRG.GetMain()
		if m
			m.LogC("", "buy price " + akItem.GetName() + " value=" + value + " sp=" + (sp as int) + " bp=" + BuyWire(akPlayer, mods, mult) + " price=" + price, akNpc.GetDisplayName())
		endif
	endif
	return price
EndFunction

string Function StockCsv(ObjectReference akSource, float afMult) Global
	{<hex8>:<name>:<price>:<count>:<value>,... for the food and drink akSource holds (FormType 46 + Potion.IsFood): drinks first (<= 6), then food (<= 8), <= 40 forms looked at. "-" for none.}
	Form[] items = PO3_SKSEFunctions.AddItemsOfTypeToArray(akSource, 46, false, false, false)
	if !items || items.Length == 0
		return "-"
	endif
	string drinks = ""
	string food = ""
	int nd = 0
	int nf = 0
	int looked = 0
	int i = 0
	while i < items.Length && looked < 40 && (nd < 6 || nf < 8)
		Potion p = items[i] as Potion
		if p != None && p.IsFood()
			looked += 1
			int count = akSource.GetItemCount(p)
			if count > 0
				string name = MktName(p.GetName())
				if name != ""
					int value = p.GetGoldValue()
					string row = Hex8G(p.GetFormID()) + ":" + name + ":" + Math.Floor(value * afMult + 0.5) + ":" + count + ":" + value
					if IsDrinkName(name)
						if nd < 6
							if drinks != ""
								drinks += ","
							endif
							drinks += row
							nd += 1
						endif
					elseif nf < 8
						if food != ""
							food += ","
						endif
						food += row
						nf += 1
					endif
				endif
			endif
		endif
		i += 1
	endwhile
	if drinks == "" && food == ""
		return "-"
	endif
	if drinks == ""
		return food
	endif
	if food == ""
		return drinks
	endif
	return drinks + "," + food
EndFunction

bool Function IsDrinkName(string asName) Global
	return StringUtil.Find(asName, "Mead") >= 0 || StringUtil.Find(asName, "Ale") >= 0 || StringUtil.Find(asName, "Beer") >= 0 \
		|| StringUtil.Find(asName, "Wine") >= 0 || StringUtil.Find(asName, "Brandy") >= 0 || StringUtil.Find(asName, "Cider") >= 0 \
		|| StringUtil.Find(asName, "Milk") >= 0 || StringUtil.Find(asName, "Juice") >= 0 || StringUtil.Find(asName, "Water") >= 0 \
		|| StringUtil.Find(asName, "Sujamma") >= 0 || StringUtil.Find(asName, "Flin") >= 0 || StringUtil.Find(asName, "Matze") >= 0 \
		|| StringUtil.Find(asName, "Shein") >= 0 || StringUtil.Find(asName, "Tea") >= 0 || StringUtil.Find(asName, "Skooma") >= 0
EndFunction

string Function MktName(string asName) Global
	{A name safe inside a k=v;k=v wire and a <id>:<name>:<n> row: ; = : @ | " and newlines become spaces, a comma is
	 dropped ("Bread, Half" -> "Bread Half"); <= 24 characters.}
	string s = MktReplace(asName, ";", " ")
	s = MktReplace(s, "=", " ")
	s = MktReplace(s, ":", " ")
	s = MktReplace(s, ",", "")
	s = MktReplace(s, "@", " ")
	s = MktReplace(s, "|", " ")
	s = MktReplace(s, "\"", "'")
	s = MktReplace(s, "\n", " ")
	return Clip(s, 24)
EndFunction

string Function MktReplace(string asText, string asFrom, string asTo) Global
	string rest = asText
	string out = ""
	int guard = 0
	int i = StringUtil.Find(rest, asFrom)
	while i >= 0 && guard < 40
		if i > 0
			out += StringUtil.Substring(rest, 0, i)
		endif
		out += asTo
		rest = StringUtil.Substring(rest, i + 1)
		i = StringUtil.Find(rest, asFrom)
		guard += 1
	endwhile
	return out + rest
EndFunction

string Function Hex8G(int aiValue) Global
	{Eight upper-case hex digits, no prefix (LRG_Dialogue.Hex8 as a Global).}
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

; ---------------------------------------------------------------------------
; Place facts (snapshot v=2). All of these are plain game facts; the server words them.
; None of them runs per snapshot: LRG_Main caches the results per location / per NPC + cell.
; Natives verified in the installed SKSE sources: Keyword.GetKeyword (Keyword.psc:13),
; Form.HasKeyword (Form.psc:10), Cell.GetActorOwner / GetFactionOwner (Cell.psc:4/7),
; ObjectReference.GetEditorLocation (:251), Quest.GetQuest (Quest.psc:247), IsCompleted (:76).
; ---------------------------------------------------------------------------
bool Function LocHas(Location akLoc, string asKeyword) Global
	Keyword k = Keyword.GetKeyword(asKeyword)
	if !k
		return false
	endif
	return akLoc.HasKeyword(k)
EndFunction

string Function LocationType(Location akLoc) Global
	{First match of: inn, phouse, house, castle, temple, store, guild, jail, city, town; else wild.}
	if !akLoc
		return "wild"
	endif
	if LocHas(akLoc, "LocTypeInn")
		return "inn"
	elseif LocHas(akLoc, "LocTypePlayerHouse")
		return "phouse"
	elseif LocHas(akLoc, "LocTypeHouse") || LocHas(akLoc, "LocTypeDwelling")
		return "house"
	elseif LocHas(akLoc, "LocTypeCastle")
		return "castle"
	elseif LocHas(akLoc, "LocTypeTemple")
		return "temple"
	elseif LocHas(akLoc, "LocTypeStore")
		return "store"
	elseif LocHas(akLoc, "LocTypeGuild")
		return "guild"
	elseif LocHas(akLoc, "LocTypeJail")
		return "jail"
	elseif LocHas(akLoc, "LocTypeCity")
		return "city"
	elseif LocHas(akLoc, "LocTypeTown") || LocHas(akLoc, "LocTypeSettlement")
		return "town"
	endif
	return "wild"
EndFunction

string Function OwnerWord(ActorBase akOwner, Faction akFaction, Actor akNpc, Actor akPlayer) Global
	{npc / player / other / none for an owner pair read from a cell or a reference.}
	if !akOwner && !akFaction
		return "none"
	endif
	if akOwner && akNpc && akOwner == akNpc.GetActorBase()
		return "npc"
	endif
	if akFaction && akNpc && akNpc.IsInFaction(akFaction)
		return "npc"
	endif
	if akOwner && akOwner == akPlayer.GetActorBase()
		return "player"
	endif
	if akFaction && akPlayer.IsInFaction(akFaction)
		return "player"
	endif
	return "other"
EndFunction

string Function Clip(string asText, int aiMax) Global
	if StringUtil.GetLength(asText) > aiMax
		return StringUtil.Substring(asText, 0, aiMax)
	endif
	return asText
EndFunction

int Function SdeState(Actor akNpc) Global
	{Serana Dialogue Expansion (Romance): number of completed romance quests SDE_R001..SDE_R005,
	 or -1 when this is not Serana or the mod is not there. Stage meanings are undocumented, so
	 nothing finer is reported (marriage arrives through fac, lover status through rank).}
	ActorBase ab = akNpc.GetActorBase()
	if !ab
		return -1
	endif
	bool isSerana = PO3_SKSEFunctions.GetFormEditorID(ab) == "DLC1Serana"
	if !isSerana
		; EditorIDs of actor bases need po3 Tweaks' cache: fall back to the Dawnguard FormID
		isSerana = (ab == (Game.GetFormFromFile(0x00002B6C, "Dawnguard.esm") as ActorBase))
	endif
	if !isSerana
		return -1
	endif
	if Quest.GetQuest("SDE_R001") == None
		return -1
	endif
	int done = 0
	int i = 1
	while i <= 5
		Quest q = Quest.GetQuest("SDE_R00" + i)
		if q && q.IsCompleted()
			done += 1
		endif
		i += 1
	endwhile
	return done
EndFunction

; ---------------------------------------------------------------------------
; The snapshot
; ---------------------------------------------------------------------------
int Function B2I(bool abValue) Global
	if abValue
		return 1
	endif
	return 0
EndFunction

string Function Mult2(float afValue) Global
	{A float as two decimals for the wire ("1.00", "2.50"). Papyrus has no format specifier, and the
	 default float-to-string gives six decimals, which is noise in every log line.}
	int hundredths = ((afValue * 100.0) + 0.5) as int
	if hundredths < 0
		hundredths = 0
	endif
	string frac = "" + (hundredths % 100)
	if (hundredths % 100) < 10
		frac = "0" + frac
	endif
	return (hundredths / 100) + "." + frac
EndFunction

string Function BuildSnapshot(Actor akNpc, Actor akPlayer, float afRadiusInterior, float afRadiusExterior, bool abInOurScene, bool abIntimacyEnabled, string asPlaceFacts = "", int aiTok = 0) Global
	{asPlaceFacts: the ready-made v=2 block (x, loc, ltype, cellown, home, nhome, prent, nearf,
	 bedown, sde) from LRG_Main.PlaceFacts - it goes in right before class, fac stays LAST.
	 aiTok: the snapshot attempt's token (0.5.6) - every stamp below carries it.}
	;/[0.5.6] Every m.SnapStage / SnapDoorTry / SnapFolTry passes aiTok, and LRG_Main ignores a mark
	 whose token does not hold the rail - two attempts in flight at once can no longer move each
	 other's stage (research/pt13-game-fix.md section 2)./;
	;/[0.5.2] The quest script is fetched FIRST now (it used to be looked up halfway down) so that
	 every step below can stamp its progress on it. Papyrus has no try/catch and a runtime error
	 unwinds the whole stack, so the only way to learn where a snapshot died is to leave a mark as
	 it is built; LRG_Main.MaybeSnapshot reads that mark on its next call. A stamp is one member
	 write. `m` is None only when the ESP is not loaded, and then nothing below needs it anyway./;
	LRG_Main m = LRG.GetMain()
	bool interior = false
	Cell c = akPlayer.GetParentCell()
	if c
		interior = c.IsInterior()
	endif
	float radius = afRadiusExterior
	if interior
		radius = afRadiusInterior
	endif

	ActorBase ab = akNpc.GetLeveledActorBase()
	int sex = -1
	string classId = ""
	if ab
		sex = ab.GetSex()
		Class cl = ab.GetClass()
		if cl
			classId = PO3_SKSEFunctions.GetFormEditorID(cl)
		endif
	endif

	string s = "v=2"
	s += ";npc=" + akNpc.GetDisplayName()
	s += ";ref=" + akNpc.GetFormID()
	s += ";on=" + B2I(abIntimacyEnabled)
	s += ";adult=" + B2I(IsAdult(akNpc))
	s += ";sex=" + sex
	s += ";lvl=" + akNpc.GetLevel()
	s += ";combat=" + B2I(akNpc.IsInCombat() || akPlayer.IsInCombat())
	s += ";scene=" + B2I(akNpc.GetCurrentScene() != None)
	s += ";ostim=" + B2I(abInOurScene)
	s += ";mate=" + B2I(akNpc.IsPlayerTeammate())
	s += ";married=" + B2I(IsMarried(akNpc))
	s += ";pspouse=" + B2I(IsPlayerSpouse(akNpc))
	s += ";courting=" + B2I(IsCourting(akNpc, akPlayer))
	s += ";rank=" + akNpc.GetRelationshipRank(akPlayer)
	s += ";conf=" + (akNpc.GetActorValue("Confidence") as int)
	s += ";moral=" + (akNpc.GetActorValue("Morality") as int)
	s += ";aggr=" + (akNpc.GetActorValue("Aggression") as int)
	s += ";gold=" + akNpc.GetGoldAmount()
	s += ";interior=" + B2I(interior)
	; [0.4] the door fact and the two arguments the witness scan needs. The MCM values are read through
	; LRG_Main (one accessor call), so a missing MCM Helper degrades to the code defaults - and those
	; defaults reproduce the pre-0.4 behaviour exactly. The sliders also self-heal: a missing ini key
	; reads as 0 and a 0 radius would find no door and no listener at all, so 0 becomes the default.
	; NOTE for the owner: bPrivacyDoors reads FALSE until its settings.ini default line exists, and
	; then this whole rule is simply off - i.e. the old behaviour. See research/pt8-intimacy-handoff.md.
	int doorShut = 0
	float hearRadius = 600.0
	bool folAware = false
	if m
		m.SnapStage(2, aiTok)
		;/[0.5.2] The door block is OPTIONAL and it is the first thing here that leaves the glue's own
		 scripts: a po3 reference scan plus GetOpenState on what it finds. m.SnapDoorTry(true, aiTok) is the
		 breadcrumb - if it is still set when the next snapshot starts, this block killed the last one
		 and it is switched off for the rest of the session. door= is then simply absent, which the
		 server already reads as "say nothing", and every other fact still goes out./;
		if m.SnapDoorOk() && m.SettingBool("bPrivacyDoors:Intimacy", true)
			m.SnapDoorTry(true, aiTok)
			; through LRG_OStim, which caches the scan (same cell, moved < 200 units, < 15 s); the
			; uncached global is the fallback for a load order without the intimacy module
			LRG_OStim ost = m.GetOStim()
			if ost
				doorShut = ost.DoorFacts(akPlayer)
			else
				doorShut = DoorState(akPlayer, m.SettingFloat("fDoorRadius:Intimacy", 250.0))
			endif
			m.SnapDoorTry(false, aiTok)
		endif
		m.SnapStage(3, aiTok)
		hearRadius = m.SettingFloat("fHearRadius:Intimacy", 600.0)
		;/[0.5.1 / owner addendum 10] The follower block. A MISSING ini key reads as FALSE and then
		 NOTHING about followers is sent at all - the 0.5.0 wire exactly. The server reads an absent
		 fol= / witchim= as "say nothing", so an older game script is unchanged in both directions./;
		; [0.5.2] ...and the same rail for the follower block, which reaches into Simple Follower
		; Framework's own SKSE natives and script. SnapFolOk() is false once it has aborted a snapshot.
		folAware = m.SnapFolOk() && m.SettingBool("bFollowerAware:Followers", true)
	endif
	if hearRadius < 100.0
		hearRadius = 600.0
	elseif hearRadius > 2000.0
		hearRadius = 2000.0
	endif
	s += ";door=" + doorShut
	; [0.5.2] witchim is the follower block's other half - the scan asks LRG_Followers for the vanilla
	; follower faction - so it carries the same breadcrumb.
	if m && folAware
		m.SnapFolTry(true, aiTok)
	endif
	s += ";" + WitnessScan(akNpc, akPlayer, radius, doorShut == 1, hearRadius, folAware)
	if m
		m.SnapFolTry(false, aiTok)
		m.SnapStage(4, aiTok)
	endif
	s += ";" + PlayerFacts(akPlayer)
	if m
		m.SnapStage(5, aiTok)
	endif
	; [0.4] the two paid-intimacy switches ride along on the snapshot: paidok is the MCM master toggle
	; (the server treats an ABSENT key as ON, so an old game script keeps the feature) and pm is the
	; price multiplier, clamped here as the first of its two independent guards - a 0 would otherwise
	; make every NPC in Skyrim free.
	if m
		s += ";paidok=" + B2I(m.SettingBool("bPaidIntimacy:Intimacy", true))
		float pm = m.SettingFloat("fPriceMultiplier:Intimacy", 1.0)
		if pm <= 0.0
			pm = 1.0 ; a missing MCM key reads as 0.0
		elseif pm < 0.25
			pm = 0.25
		elseif pm > 4.0
			pm = 4.0
		endif
		s += ";pm=" + Mult2(pm)
		;/[0.4 fix pass, D11] The three CHECK / QUEST knobs that no script read and no message
		 carried, so the MCM controls changed nothing and bFreeChecks was honoured game-side only
		 (the server still ran the check, still narrated its outcome and still emitted do=award,
		 which the game then threw away). chk is two digits - free-conversation checks, then
		 "a failed threat can turn ugly"; bias is iCheckBias; ql is iQuestLines. An ABSENT key
		 means "keep the server's config default", so both halves stay additive./;
		s += ";chk=" + B2I(m.SettingBool("bFreeChecks:Checks", true)) \
			+ B2I(m.SettingBool("bCheckHostility:Checks", false))
		int bias = m.SettingInt("iCheckBias:Checks", 0)
		if bias < -2
			bias = -2
		elseif bias > 2
			bias = 2
		endif
		s += ";bias=" + bias
		int ql = m.SettingInt("iQuestLines:Quests", 3)
		if ql < 1 || ql > 3
			ql = 3 ; a missing MCM key reads as 0 and 0 would silence every quest reminder
		endif
		s += ";ql=" + ql
		;/[0.5] The v0.5 knobs that live on the SERVER but are switched in the MCM. Same rule as
		 chk / bias / ql above: an ABSENT key means "keep the server's own config default", so both
		 halves stay additive and a 0.4 script changes nothing.
		   qx  = bQuestSummary, bQuestNext - "what can I ask you" / "where was I"
		   sv  = bServiceDialogue      - rooms, rides, training and trade by talking (W11)
		   svx = bServiceShortcut, bCarriageByName, bNamePrices
		   lf  = bLockedFacts          - only let her state what the game confirmed (W11)
		   tg  = bTruthGate            - do not let a wrong number cost septims
		 [v1.0 / spec section 4, S9] qi / qig (bQuestInitiative, iQuestInitiativeGap) are retired with
		 the server's initiative (<she_may_raise>, quests.initiative.*) and their MCM controls: no key./;
		s += ";qx=" + B2I(m.SettingBool("bQuestSummary:Quests", true)) \
			+ B2I(m.SettingBool("bQuestNext:Quests", true))
		s += ";sv=" + B2I(m.SettingBool("bServiceDialogue:Services", true))
		s += ";svx=" + B2I(m.SettingBool("bServiceShortcut:Services", true)) \
			+ B2I(m.SettingBool("bCarriageByName:Services", true)) \
			+ B2I(m.SettingBool("bNamePrices:Services", true))
		s += ";lf=" + B2I(m.SettingBool("bLockedFacts:Truth", true))
		s += ";tg=" + B2I(m.SettingBool("bTruthGate:Truth", true))
		;/[0.5 release pass, S-2] ml = "the driver can really drive a session right now",
		 i.e. bMenuless AND NOT bDlgDryRun. The server cannot work this out for itself, and
		 without it it hid CHIM's own RentRoom / HireCarriage / HireFerry / Training /
		 OpenInventory on every ordinary turn at any NPC whose menu had been read in the last
		 30 minutes - while the game answered CmdSelectTopic with "the feature is switched
		 off". The server's default is 1, so a 0.4 script that never sends it is unchanged./;
		;/[pt17] ml is the driver's state (bMenuless AND NOT the owner's menuless dry run - v1.0: nothing
		 forces it), raw MCM only until the module has booted; cal= how many of the four calibration
		 rows are answered (0..4, spec S3.1). The in-code defaults equal the settings.ini lines./;
		bool mlOn = m.SettingBool("bMenuless:Dialogue", true) \
			&& !m.SettingBool("bDlgDryRun:Dialogue", false)
		int calN = -1
		LRG_Dialogue dlg = m.GetDialogue()
		if dlg
			mlOn = dlg.MenulessLive()
			calN = dlg.CalAnsweredNow()
		endif
		s += ";ml=" + B2I(mlOn)
		if calN >= 0
			s += ";cal=" + calN
		endif
		m.SnapStage(6, aiTok)
	endif
	;/[0.4.1] Two additive keys for the walk-away work (research/pt8-walkaway.md 3.4 and 5.13).
	 dist is how far she is from the player in units - the snapshot carried no distance at all, which
	 is why "she was 900 units away when she answered" could not be recovered from any log. hold is
	 1 while the conversation hold really has her (SetDontMove on), and it is what lets the server
	 stop offering the LLM EndConversation during a live one-on-one. Both absent on an older game
	 script, and an absent key changes nothing server-side./;
	s += ";dist=" + (akNpc.GetDistance(akPlayer) as int)
	int holdOn = 0
	if m && m.IsConvHeldActor(akNpc)
		holdOn = 1
	endif
	s += ";hold=" + holdOn
	if m
		m.SnapStage(7, aiTok)
	endif
	;/[0.5.1] fol= - what this NPC is to the player's party and who owns her, as one value:
	 fw:<none|sff|custom|chim>,mate:,cff:,pff:,wait:,chim:,ghost:[,slot:u/m,cap:,prim:]
	 cap: is SFF's own SFF_CanRecruitMore, which is what the server needs to stop offering CHIM's
	 "join my party" when the framework would refuse the recruit anyway (pt9 build brief item 2)./;
	if folAware
		if m
			m.SnapFolTry(true, aiTok)
		endif
		s += ";fol=" + LRG_Followers.FolState(akNpc)
		if m
			m.SnapFolTry(false, aiTok)
		endif
	endif
	if m
		m.SnapStage(8, aiTok)
	endif
	;/[0.5.1] fv = bFollowerVerbsReal, the SERVER-side half: route recruit / dismiss / wait / follow /
	 trade / favour / home to her real dialogue entry and take CHIM's shortcut off the table for that
	 turn. It rides the snapshot for the same reason sv / lf / ml do - the decision has to be makeable
	 on an ordinary CHIM turn where no dialogue message was ever sent. ABSENT = the server's default./;
	if m
		s += ";fv=" + B2I(m.SettingBool("bFollowerVerbsReal:Followers", true))
		; [pt19 / script 511] quiet= 1 while the glue's quiet mode is on (a curated scripted intro runs, LRG_Main.QuietOn).
		; In practice 0: MaybeSnapshot sends nothing while quiet - the key documents the wire and covers a future light snapshot.
		s += ";quiet=" + B2I(m.QuietOn())
	endif
	; [pt19-purchase / script 512] vend= / room= / bp= / stock= - what a vendor really sells at the game's own barter price
	; (MarketFacts above; "" without settings, ";vend=0" after ONE PO3 call for a non-vendor). A separate call on purpose:
	; a Papyrus error inside it aborts that call alone and the rest of the snapshot still goes out.
	s += MarketFacts(akNpc, akPlayer, m)
	if asPlaceFacts != ""
		s += ";" + asPlaceFacts
	endif
	s += ";class=" + classId
	if m
		m.SnapStage(9, aiTok)
	endif
	s += ";fac=" + FactionList(akNpc)
	if m
		m.SnapStage(10, aiTok)
	endif
	return s
EndFunction
