# Owner addenda - v0.3 round (playtest 6)

Clarifications from the owner that arrived while the round was being built. Newest last.
They win over the round's prompt, the design and the critic.

1. (2026-09-21 ~13:50) Verbatim: "if she starts the makeout scene although, then that makes sense,
   but she should say something before initiating it, to give context as well."
   -> Her starting a kiss / makeout herself is wanted. It must never begin without a spoken line
   first. (Already in the round's prompt as G3.)

2. (2026-09-21 ~13:58) Verbatim: "if she initiates any new scene/animation she should say something
   signifying it"
   -> Applies to EVERY scene or animation change she initiates herself (start, position / act
   change on a lead turn, undressing, move to furniture), gentle ones included. Short line, before
   or as the change happens. Changes the player asked for need no announcement. (Already in the
   round's prompt as the revised R10.)

3. (2026-09-21 ~14:05) Verbatim: "also lets get rid of the possibility of them denying u during
   the romance - that just makes it too complicated and not simple. they can only deny beforehand,
   your requests for whatever in the middle wont be denied. that should make it more error free,
   easy and simple"
   THIS CHANGES THE ROUND'S PROMPT (G1, G5.4 and the wire-safety verifier lens). What it means:
   a. Her consent is decided BEFORE a scene, exactly as today: the BeginIntimacy gates, her
      profile / interest, and her own choice of the action. She can refuse beforehand, that refusal
      is never overridden, and the server never starts a scene by itself. Nothing here changes.
   b. INSIDE a running scene the player's requests are never refused in character. Remove from the
      design: the Decline action, refusal-marker detection in her reply, per-act decline memory,
      "a decline is final for that act". Do not build them; if already built, take them out.
   c. The turn directive for a recognised in-scene request is simply: he asked for X - do it (call
      ACTION with TARGET) and react in your own voice; do not refuse, stall or negotiate.
   d. The safety net gets simpler: running scene (confirmed by the game) + high-confidence
      recognised request + the LLM's reply lacks the matching action -> the server carries it out,
      whatever her words were. Still required: negated / questioned / quoted utterances are NOT
      requests ("don't get naked yet", "should I take this off?"); never outside a running scene;
      never under kill switch / dry-run / disabled module.
   e. The pacing ceiling (start gentle -> foreplay -> further, by personality) limits only HER OWN
      lead picks and proposals. An explicit player request is not held back by it.
   f. What can still say no is the GAME, not her: the act is not installed, is filtered out by the
      content filter / excluded patterns (no fetish content - unchanged), does not fit the actors
      or furniture, or cannot be reached and warping is off. Then: a short in-character "can't do
      that here / not from this position" + the corner note, never silence (G5.8 stays).
   g. Unchanged: "stop" always works (voice and hotkey); kill switch; adults only; the player is
      always a participant; R11 - when SHE proposes a sex act the PLAYER may say yes or no, and a
      no is remembered so she does not nag.
   h. Keep it simple: fewer states, fewer actions, fewer prompts. Where this addendum removes a
      mechanism, remove its tests and wire keys too (additively safe: ignore unknown keys).

4. (2026-09-21 ~21:00, after playtest 7) Verbatim: "lets expand the menuless questing feature;
   making it more useable with lorerim and the overarching game questing tree. Also sometime and
   the end of the sex scene, it will leave u in the ostim director mode so lets fix that too. also
   because the tavern is a crowded place people dont want to hook up in the rooms, lets change
   that so if someone is in a room with a closed door, it will be considered somewhere private,
   and chim should not be hearing thru closed doors and walls. i really want to focus on the
   expansion and improvements of menuless questing now."
   -> Priority is now menuless questing (Phase 2, glue\PHASE2_DESIGN.md). Two intimacy fixes ride
   along: (a) after a scene the player must be back in normal control and talking to the NPC -
   never left in a camera / control / narrator mode; (b) privacy: a room behind a closed door is
   private even in a busy inn; NPCs behind walls or closed doors are not witnesses and do not hear.
   Design decisions on PHASE2_DESIGN.md section 9, taken by the main session (design authority):
   D0 keep CHIM's dialoguemenu.swf, driver supports both SWF families; D1 probe first, nothing
   installed without asking; D2 the glue writes no IACC setting - recommended values go in the
   owner notes; D3 yes, action before message on player-speech turns; D4 yes to both;
   D5 bribe haggling may name the price, scoff-first on, grace 2.5 s, silence 45 s, iEngineOpen 0,
   iCritical 0 (lethal handed back visible), iSceneGate 0; D6 engine-only for CC plugins; D7 accept
   assisted, bounded re-walk OFF by default; D8 report both upstream data bugs in the owner notes,
   change nothing.

5. (2026-09-21 ~21:05) Verbatim: "the deceptions, persuasions, intimidation checks should be
   fully implemented with chim as well and fully working in any situation"
   -> Speech checks are a first-class feature of this round, in BOTH situations:
   a. ENGINE CHECKS (quest / vanilla dialogue): every (Persuade), (Intimidate), (Bribe N gold) entry
      and any mod-added check kind in this load order (Requiem / LoreRim / other mods may add lie,
      deceive, charm, seduce or similar entries - the index builder must discover every check kind
      present in the plugins, not assume the vanilla three). The player attempts them by talking
      normally ("come on, you can tell me", "do it or I break your arm", "here's fifty septims").
      The LLM judges only whether the player made the attempt and of which kind (with the two-step
      confirmation where the design requires it); the ENGINE decides the outcome by selecting the
      real entry; the NPC's real success/failure line plays and the LLM then reacts in character
      knowing the outcome as fact, never narrating an outcome of its own. Bribes must handle the
      real gold amount (does the player have it; the LLM may name the price).
   b. FREE-CONVERSATION CHECKS (any CHIM conversation, no engine entry): when the player tries to
      persuade, intimidate, bribe or deceive an NPC about anything (get a better price, be let
      through, talk their way out, lie about who they are), the glue runs a REAL check using the
      game's own rules rather than letting the LLM decide: persuasion / deception = the player's
      Speech skill with the engine's perks and the Speech difficulty globals (Easy / Average / Hard
      / Very Hard as LoreRim / Requiem set them) against a difficulty the server picks from the
      stakes and the NPC's stance (affinity, profile, guard, merchant, hostile ...); intimidation =
      the engine's own comparison (player level and Intimidation perk vs the NPC's level /
      confidence) and never works on people it should not (jarls, Vigilants ...); bribe = the real
      gold changes hands only after a yes. The outcome goes to the LLM as fact in that turn ("the
      attempt FAILED - she does not believe a word"), the NPC reacts in character, success grants
      Speech experience the way the engine does (verify the engine's XP mechanism and use the same
      call), failure has consequences (affinity down through CHIM's RelationshipManager; a failed
      intimidation may make a hostile NPC hostile; a caught lie is remembered), and the result is
      remembered per NPC (CHIM memory + our state) so the same trick does not work twice. No new
      LLM calls of its own; one directive + one action (or the existing action slots) per attempt.
   c. Both kinds are logged on the G6 turn line (kind, difficulty, roll inputs, outcome) so the
      owner can see why a check went the way it did. MCM: a master toggle for free-conversation
      checks (default on) and a difficulty bias slider.

6. (2026-09-21 ~21:15) "EVERYONE HAS A PRICE" - paying for sex. Verbatim (abridged): "sometimes
   paying for sex will be the easy way to get into a girls pants (if she isnt rich) but itll cost
   you gold, make it realistic, not everyone can be bought sexually with money, depends on the
   character, their wealth, how influenced they are by money, etc. make the prices realistic based
   off of a 3-5 gold per hour avg wage in skyrim ... obviously people that dont want to be bought
   are going to be exorbitantly more expensive - but hey - everyone has a price. jarls and stuff
   are going to be very immune to this as theyre rich, so its going to be a ridiculous high price
   perhaps of whatever the ai decides, unless they are genuinely interested ... poor and common
   folk will have much more reasonable prices because of their psychology."
   Design (main session; builders follow it, the investigator refines the numbers):
   a. Wage anchor: 3-5 gold/hour -> a day's wage ~35 gold, a week ~250, a month ~1000. Prices are
      expressed in days of wage and turned into gold by the server. Per-profile PRICE BAND
      [floor, ceiling] and money_influence, in config next to the existing leverage / wealth /
      gold_sway data (reuse those - wealth tier from the NPC's real gold and profile; gold_sway
      words already exist): beggar / destitute 0.5-2 days; tavern folk and poor commoners 1-4 days;
      modest commoners and merchants 4-15 days; comfortable folk 15-60 days; court, housecarls and
      the wealthy 60-300 days; jarls and nobles 300-3000 days ("ridiculous", the LLM names the
      figure inside the band). Modifiers: genuinely interested (interest score already >= 0) -> no
      price needed at all, or a token one if she wants it; curious -> floor of the band; indifferent
      -> top of the band; married per married_rule (secret -> x3, refuse -> refuse); the player's
      renown / status lowers the price the way renown_sway already works; a repeat arrangement is a
      little cheaper; "never" profiles (vigilant etc.) stay never. npc_overrides can pin a price
      or set not_for_sale.
   b. Flow, all by talking: the player offers gold or asks her price (intent recogniser: amounts,
      "how much", "name your price", "I'll pay", "for a hundred septims"); the server tells the LLM
      her band and stance in words + one number ("if he offers gold you would not go below 120
      septims; you may ask for more; below that you refuse - unless you want him anyway"); she
      haggles in her own voice; the LLM accepts by calling BeginIntimacy with the agreed price in
      the AMOUNT slot (her own action choice - the server never starts a scene); the post-process
      gate drops an acceptance below her floor unless she is genuinely interested, and drops any
      acceptance the player cannot afford (player gold is in the snapshot: add pgold=); the game
      takes the gold at scene start (additive key pay=<n> on ExtCmdLRG_StartIntimacy: the player's
      gold goes to the NPC's inventory; not enough gold -> refused with the reason, she reacts) and
      gives nothing back if the player stops early. Paid sex counts half for the relationship gain
      and is remembered (memory + state) so she can refer to it and price him next time.
   c. Adults only, consent gates, kill switch, no-fetish filter: unchanged. The price never
      overrides a "never" profile or a pre-scene refusal she gives for other reasons (she may still
      say no to a rude buyer). Log the band, the offer and the decision on the turn line. MCM:
      bPaidIntimacy (default on) and a price multiplier slider (default 1.0).

7. (2026-09-21 ~21:35) Verbatim: "make sure we have a solid, working framework for persuade,
   deception and intimidate in any free conversation, making sure it is compatible with speech
   skills mods in the lorerim modpack like requiem."
   -> Sharpens item 5b. The free-conversation check must use THIS load order's real rules, not
   vanilla assumptions: the index builder (lane C) extracts, from the WINNING override of the
   engine's own persuade / intimidate / bribe INFO records (and any mod-added kinds), the exact
   condition set they use - the Speech globals (SpeechEasy / Average / Hard / VeryHard and any
   mod-added ones), the perks referenced (Requiem and LoreRim rename and re-tier the Speech tree:
   discover the actual perk EditorIDs / form ids from the conditions, do not hardcode vanilla
   'Persuasion' / 'Intimidation' / 'Bribery'), worn items (Amulet of Dibella etc.), level
   comparisons for intimidation, and the gold formula for bribes. The game side sends the facts
   those conditions need (Speech actor value as modified, HasPerk for each discovered perk, the
   worn amulet, player level, gold; NPC level / confidence / faction facts) in one additive state
   message; the server evaluates the SAME conditions to decide the free-conversation outcome, so a
   free persuade succeeds exactly when an engine persuade of that difficulty would. Difficulty
   choice (easy / average / hard / very hard) comes from the stakes and the NPC's stance. Speech XP
   on success is granted the way the engine grants it for a real speech success (find the
   mechanism; if it is engine-internal, use Game.AdvanceSkill("Speechcraft", x) with x matching a
   real persuade's reward). Deception = a persuade-type check against a harder difficulty when the
   lie is big, with the NPC remembering a caught lie. Offline tests must cover: Requiem's globals
   as installed, a perk from the discovered set, the amulet, each difficulty, and the intimidation
   level comparison. Nothing here may write to any LoreRim / Requiem file.

8. (2026-09-21 ~21:45) Verbatim: "i noticed sometimes using CHIM the ai can be quick to walk away
   from you, maybe this is an override on the npcs schedule or idk. but i want to look into it."
   -> Investigated in its own round (research\pt8-walkaway.md); the fix is built AFTER the v0.4
   round ships, because it touches LRG_Main.psc / LRG_OStim.psc which the v0.4 lanes own. Goal:
   while a CHIM conversation with an NPC is live (the player spoke to her or she to him within a
   short window), she stays with the player - stops, faces him, does not resume her schedule -
   and is released cleanly (window expiry, distance, combat, kill switch, game load, the player
   walking off, a scene starting). Owner-side CHIM / AI-mod settings that already do part of this
   are reported first.

9. (2026-09-22 ~03:30, for the v0.5 round) Verbatim: "specifically the expanded scope of
   bartering, training, inns, carriages, guard / crime dialogue (if this isnt already implemented
   in CHIM) all the while being compatible with lorerim. also importantly we need to improve upon
   latency - the timeliness of the response from the npc's voice in game (also i have grok set to
   most of the LLMs right now and i might end up changing that depending on what you suggest -
   maybe switch over to deepseek v4 flash or see what you have to say). also important: consider
   the core's fact-locking and condition truthfulness."
   -> Three additions:
   a. SERVICE DIALOGUE, first-class in menuless questing: bartering ("what have you got for sale",
      "let me see your wares", "I want to sell"), training ("can you train me in X", Requiem's
      training limits and prices), inns (rent a room, food and drink, rumours, "where can I sleep"),
      carriages and ferries (destinations by name, price, "take me to Whiterun"), guards and crime
      (bounty talk, pay the fine, go to jail, resist / bribe / persuade where the entries exist,
      "I know you..." arrest sessions stay LETHAL-class and hand back the visible menu unless the
      owner enables iCritical). For each kind: what CHIM already provides as its own shortcut
      actions (RentRoom, HireCarriage, HireFerry, Training, OpenInventory / barter, GiveGoldTo,
      ForgiveCrime, Brawl - read the installed catalog), when the glue uses the REAL dialogue entry
      instead (always when a session's list has it: real prices, real Requiem/LoreRim rules, real
      quest hooks), when CHIM's shortcut is the fallback (no session possible), and how the two are
      kept from firing twice (D4: hide the shortcut when the real entry is known). LoreRim
      compatibility: read the installed plugins for the mods that change these services (Requiem's
      training / prices / crime, LoreRim's carriage, ferry, inn and merchant mods - find them in
      the load order) and make the index + matcher cover their custom entries and destinations;
      tests on real LoreRim NPCs (an innkeeper, a carriage driver, a trainer, a merchant, a guard).
   b. LATENCY: a separate round measures the whole chain (push-to-talk -> STT -> server ->
      LLM first token -> TTS first audio -> playback) from CHIM's logs and a small controlled
      benchmark, finds the slow links (known: PocketTTS on the game GPU, a fast connector that
      404s, our own prompt blocks and extra requests), fixes what is ours (prompt size, request
      count, semaphore waits, funcret turns), and gives the owner a model recommendation with
      measured numbers (grok-4.3 as configured vs the candidates available through his connector,
      including DeepSeek V4 Flash if available: time to first token, total, strict-JSON action
      compliance, cost) and the exact settings to change. Nothing in CHIM's config is changed by
      us without the owner; the glue's own settings may change.
   c. FACT-LOCKING AND CONDITION TRUTHFULNESS: find what CHIM core calls fact locking (locked
      profile facts the LLM may not contradict: lock_profile, relationships_locked, any 'facts' /
      'locked' mechanism in core_npc_master / prompts) and condition truthfulness (action catalog
      requirements / conditions evaluated by the server so the LLM only sees actions whose
      preconditions are TRUE, and any 'truth' checks on the LLM's claims) in the installed
      HerikaServer, and integrate: quest-tree facts, speech-check outcomes, prices and service
      facts are given to the LLM as locked facts in CHIM's own mechanism where one exists; our
      actions declare their preconditions through CHIM's catalog requirements so CHIM's own
      machinery hides them when false (in addition to our filter); the LLM is never asked to
      narrate a state the game has not confirmed. Report what CHIM offers and what the glue uses.

10. (2026-09-22 ~03:50) Verbatim: "make sure chim is compatible with the NFF and any other
    follower mods included in lorerim as well, make sure they are aware of each other, do not
    conflict, and hopefully could potentially even work together."
    -> Investigated first (research\pt9-followers.md), built after the v0.5 round ships (it
    touches LRG_Main / LRG_Dialogue which the v0.5 lanes own). Scope: every follower framework and
    follower-related mod in the load order (Nether's Follower Framework and whatever else LoreRim
    ships); how CHIM handles followers today (its Follow / Wait / ComeCloser actions and packages,
    companion modes, listener choice with followers present) and where it fights those mods;
    the glue's own follower touches (invite = followed mode, conversation hold skips followers,
    witness rules followers_ok, OStim scenes with followers present); menuless questing must handle
    the follower mods' own dialogue entries (recruit, dismiss, wait, trade, tactics, home) through
    the real entries; and cooperation: voice control of the follower framework's features through
    its own Papyrus API where it exposes one (recruit / dismiss / wait / follow / trade / roles /
    home), with CHIM's shortcut as fallback and never both. Owner-side settings first.

11. (2026-09-23 ~02:10, after playtest 13) Verbatim: "well if she's busy she should say that then and it
    will be fine, she never told me she was busy, she just didn't do anything."
    -> NEVER SILENT. Whenever the player asks an NPC for something the glue or CHIM cannot carry out
    right now (she is mid-performance, in a quest scene, in combat, the scene cannot be stopped, the
    act is not installed, she is a framework follower whose orders go through the real dialogue,
    a command failed on the game side), SHE SAYS SO in her own words in that same exchange - one
    short in-character line naming the real reason ("I'm in the middle of a song - give me a
    minute", "not here, not with the whole tavern watching") - and the corner note appears when the
    owner has notes on. Rules: (a) every glue refusal / failed command produces a VOICED result: the
    funcret that carries "Error: ..." must not be a silent info-only turn - the LLM gets the reason
    and speaks it; (b) the per-turn directive for a recognised request tells her plainly: do it, or
    say why you cannot - never ignore it; (c) for CHIM's own actions that can fail silently
    (FollowPlayer against an engine scene, ComeCloser, MoveTo, TravelTo) the glue reports the
    outcome the same way when it can see it (Escort do=follow covers follow / wait / release);
    (d) if she is busy but could stop (a bard mid-song), the Escort stops the scene and she comes -
    she may say "one moment" first; if she genuinely cannot (quest-critical scene), she says so.
    Silence after a request is a defect in every case.

12. (2026-09-23 ~05:00, after the Katana bribe attempt) Owner rulings on "everyone has a price":
    a. An "if ..." sentence ("if you were to fuck me I'd give you a thousand gold") is correctly
       NON-committing - a hypothetical, not a firm offer. But its amount must be heard and answered.
    b. Trust does not matter once he makes a FIRM offer at or above her price AND has the coin on him
       (the snapshot carries his gold): "she can see the gold for herself in plain sight".
    c. "They can confirm the offer too if they are unsure or if it is in a grey area of inference":
       on an ambiguous or hypothetical offer she asks one short confirming question, remembers the
       pending offer, and the player's yes / deal / repeating the amount completes it as a firm
       offer; no / another amount cancels or replaces it.
    d. An offer he cannot pay is called out ("show me the coin first"), never silently dropped.
    e. Wage anchor corrected by the owner: "3-10 gold per hour average, 20-40 for skilled / special
       trades, 40 the top end (alchemy, enchanting)"; 225 for Katana (adventurer, modest) is too low.
       Main session's calibration (to apply as a config change after the v3 price fix lands):
       day = 8 hours; wage tiers by profile: common (beggar, tavern folk, commoner, farmer, servant)
       5 g/h = 40/day; performer (bard) 8 g/h = 64/day; trade (merchant, innkeeper, guard, hunter,
       priest) 12 g/h = 100/day; skilled (smith, alchemist, enchanter, mage, court wizard, healer,
       adventurer / mercenary, housecarl) 25 g/h = 200/day; court / noble / jarl 60 g/h = 500/day.
       Bands stay in days (destitute 0.5-2, poor 1-4, modest 4-15, comfortable 15-60, wealthy
       60-300, noble 300-3000). Result: Katana (skilled, modest, curious) floor ~800; a poor tavern
       girl 40-160; a modest merchant 400-1500; jarls absurd as before.
    f. Owner: 800 for Katana is "a little too much, try 530". Split the skilled tier: crafts and
       magic (smith, alchemist, enchanter, mage, court wizard, healer) stay 25 g/h = 200/day; fighters
       (adventurer, mercenary, housecarl, guard captain) 16.5 g/h = 132/day -> Katana floor = 4 days
       x 132 = 528.
