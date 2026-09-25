<?php
/**
 * LoreRim Glue - [0.5.7 / pt16-legion] FACTION ENLISTMENT TRUTH ("I want to join the Legion").
 *
 * Playtest 2026-09-23 16:38, Captain Aldis, Solitude: the player asked to serve in the Legion, no menu was
 * open, menuless questing was off, and the prompt carried nothing true about recruitment - so grok, working
 * from CHIM's own auto-profile of Aldis ("trainer for the Imperial Legion"), invented an appointment
 * ("Report to the training yard at Castle Dour when the bell rings") and repeated it on the rechat turn.
 * Nothing could have started the quest: Aldis owns no join line at all. research/pt16-legion.md has the
 * line-by-line evidence and the truth table this file carries.
 *
 * WHAT THIS MODULE DOES (server only, no wire change, no Papyrus change):
 *  1. RECOGNISES an enlistment ask in the player's own words (first / second person only; "join me" stays
 *     a follower verb; "why did you join the Legion?" is not an ask; a negated ask is not an ask).
 *  2. Decides the NPC's ROLE for that faction from data the game already sends: display name, the
 *     snapshot's faction EditorIDs (`fac`), her own cached topic list, and the quests she is in.
 *        recruiter - she really takes recruits (Tullius / Rikke by name; or the recruiter faction set plus
 *                    a recruiter topic really on her list). Quest membership alone never makes a recruiter.
 *        redirect  - a member of the faction who cannot enlist anyone (Aldis, every Solitude guard).
 *        none      - anybody else.
 *  3. Puts the POSITIVE fact into <locked_facts> (class `faction`): who recruits, where, that she cannot,
 *     and that she may not set a time, a place, a drill or a test. Worded to override CHIM's persona text,
 *     which the glue cannot edit. The same fact rides the rechat turn and the next player turn inside a
 *     short window, because the worst line of the evening came on the rechat.
 *  4. Never opens for awareness on a redirect (Aldis's Say-Once greeting is not burnt on a question he
 *     cannot answer), and under ml=0 never on a recruiter either (the game would refuse the open).
 *  5. On a recruiter with menuless ON, hands the ask to the REAL entry by exact topic / exact line, never
 *     by similarity: one candidate runs (every existing rail still applies), two ask, none answer in words.
 *  6. [pt17-replies] A CARRIED ask must never make an unrelated line answerless. Playtest 2026-09-23 20:10:31,
 *     Captain Aldis: twelve seconds after "I would like to join the Legion" the player said "it says it's locked"
 *     (the Castle Dour door) and the model returned a schema-valid JSON whose message was "" - nothing was spoken
 *     (research/pt17-replies.md). The carried block was byte-identical to the ask turn's: all prohibition, nothing
 *     saying the player has moved on, nothing saying "answer him anyway". So on a carried LIVE turn (his words now,
 *     no ask in them) the first line is marked "(background to what he asked a moment ago, not what he is saying
 *     now)" and the rule says his words now may be about something else - answer them, in words; on every other
 *     faction turn the rule ends "Answer what he says, in words." (31 characters: test_dialogue 13(c) holds the
 *     ask-turn block under 900). A rechat turn (the NPC continuing, no player words) keeps the plain wording. Switches: factions.carried_note,
 *     factions.answer_in_words. The guaranteed floor for an empty reply lives in lib/lrg_replies.php, not here.
 *  7. [pt18-quest] THE ROAD, AND THE CLICK-FREE ENTRY. Playtest 2026-09-23 23:19: three enlistment asks to General
 *     Tullius / Legate Rikke were words only by construction (ml=0, no list, no model item), and the pt17 'before'
 *     line sent the owner to "General Tullius first, at the map table" - a dead end on this save: Alternate
 *     Perspective overrides GLOB MQQuickstart to 7.0 and its winning Tullius greet 0D5146 needs < 7, so the FreeToGo
 *     line (0D5150 -> 0D5136 SetStage 10) is unreachable until AP's Helgen (MQ101, stage 900 = complete) has been
 *     played; after it the road is greet 0D5145 -> "I was at Helgen" -> alternateperspective.esp:206783 ("I don't want
 *     to sit idly by ... I want to join the Legion", TIF = GetOwningQuest().SetStage(10)) (research/pt18-quest.md).
 *     Now: (a) a per-recruiter `closed` rule (lrgFacRoad) keyed on MQ101 completion - known incomplete -> the Helgen
 *     line replaces every other recruiter line (Rikke's too); unknown -> nothing is asserted open and Rikke's 'before'
 *     line is hedged; (b) a per-recruiter `effects` entry (Tullius only; Rikke stays words-only this round) that the
 *     server may apply WITHOUT a click when the driver cannot click (ml=0, an ambient scene actor the game never opens
 *     on, or the entry not on her list): lrgFacQuestPlan decides BEFORE the LLM from the player's own words and a
 *     complete, fresh pre-check on the facts line; lrgFacQuestNet appends ONE `ExtCmdLRG_QuestEntry` line in the
 *     post-gate (D1 only, the escort pattern - no D2 row, so the game's 5 s de-dupe ring is never asked to catch a
 *     second delivery); the game re-checks every engine condition on the live forms and applies Quest.SetStage - the
 *     very call the line's own fragment makes - or refuses with the reason. `executed` (the words lane's flag,
 *     research/pt18-words.md 4.4) is set pre-LLM ONLY when every server-checkable condition passed on fresh facts
 *     (licensed); otherwise the game's own OK is VOICED through the funcret turn (lrg_actions.php) - never both.
 *     Direct SetStage is the one carve-out from research/dialogue-engine.md's rule, and only for a line whose whole
 *     fragment IS SetStage(n) (decoded, not inferred), on the conditions the engine itself puts on that line.
 *
 * ROWS ARE LOAD-ORDER FACTS, not per-turn game confirmation: each carries `verified_from` (comment only,
 * never sent) and every line is prefixed "of this world, not of this moment" inside the block whose header
 * says the game confirmed the rest. Cells the investigation could not verify are left EMPTY and emit
 * nothing (the Penitus Oculatus speaker, the Vigilants' recruiter, Astrid's rows). Overridable through
 * `dialogue.factions` in lrg_config.json (lrgMerge: maps merge, lists replace).
 *
 * The truth gate (lrgDlgTruthCheck) never acts on this class - it only ever drops an action on an
 * unconfirmed price / destination, and a faction line carries no number.
 */

if (defined('LRG_FACTIONS_LOADED')) { return; }
define('LRG_FACTIONS_LOADED', true);

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_prompt_index.php';

// [pt18-quest] the click-free quest entry's code. Guarded: lrg_core.php is the constant's home (LRG_ACT_ESCORT lives
// there); until it carries this one, both this file (loaded by lrg_dialogue.php on the fast path, where lrg_actions.php
// is NOT loaded) and lrg_actions.php define it the same way.
if (!defined('LRG_ACT_QUESTENTRY')) { define('LRG_ACT_QUESTENTRY', 'ExtCmdLRG_QuestEntry'); }

// ================================================================== the truth table
function lrgFacDefaults(): array
{
    return [
        'enabled' => true,
        // the fact rides every turn of the same NPC inside this window after the ask: the rechat turn (the
        // NPC continuing after an audience line) and the player's next line ("yes sir")
        'window_seconds' => 90,
        'rechat_types' => ['rechat'],
        'max_lines' => 2,
        // [pt17-replies] on a carried LIVE turn (the player's next words carry no ask) the first line is marked
        // as background to what he asked a moment ago, and the rule tells the model to answer his words now
        'carried_note' => true,
        // [pt17-replies] the rule ends "Answer what he says, in words." on every faction turn (the carried LIVE clause replaces it)
        'answer_in_words' => true,
        // [pt18-quest] the click-free quest entry (lrgFacQuestPlan / lrgFacQuestNet). enabled: a scripted join line whose
        // whole effect is one stage change may be applied by the game on the player's own ask when the driver cannot
        // click. pending_seconds: a second ask for the same quest / stage inside this window is not sent again (the
        // first answer is awaited). require_facts_fresh: the facts line the pre-check reads (qst=, mq101c=, mqq=) must be
        // younger than this, else nothing is sent and she says the game has not confirmed where he stands.
        // cache_seconds: another recruiter's facts line (quest stages are global) counts for the ROAD this long.
        'quest_entry' => ['enabled' => true, 'pending_seconds' => 120, 'require_facts_fresh' => 300, 'cache_seconds' => 1800,
            // [pt19h-quest] table: the click-free quest-entry TABLE (config/lrg_quest_entries.default.json, lrgFacQeTurn) - the same
            // CmdQuestEntry for scripted quest lines beyond the enlistment rows, on his explicit sentence, when the driver cannot click
            'table' => true],
        // an entry on a matching topic is a candidate only when its own words are about joining, because
        // some topics are SHARED between prompts (CRNoWorkBranchTopic carries both "Can I join the
        // Companions?" and "I'm looking for work.")
        'entry_words' => ['join', 'rejoin', 'enlist', 'sign', 'apply', 'oath', 'test', 'recruit', 'recruiting', 'enter', 'serve', 'duty'],
        // "can i join YOU" / "join MY party" are follower talk, never an enlistment
        'object_stops' => ['you', 'your', 'me', 'us', 'him', 'her', 'them', 'my', 'our'],
        // "why did YOU join the Legion?" / "you should join the Legion" are about somebody else (two tokens back)
        'subject_stops' => ['you', 'he', 'she', 'they', 'we', 'who',
            // [pt19h-quest / G18, G5] "can ANYONE join the Dawnguard?" asks who may, it does not ask to join (it clicked Isran's line)
            'anyone', 'anybody', 'someone', 'somebody', 'everyone', 'everybody', 'people'],
        // [pt19h-quest / G18] shapes that make a LINE ON HER LIST an enlistment for a faction (lrgFacEntryJoins only - never his ask):
        // Serana's "I want you to turn me into a vampire.", "I don't see another way. I'll become a vampire.", Harkon's "I will
        // accept your gift and become a vampire." - word-bound like word_phrases (a faction word must follow)
        'entry_join_phrases' => ['turn me into a', 'turn me into an', 'turn me into one of', "i'll become a", "i'll become an",
            'i will become a', 'i will become an', 'and become a', 'and become an'],
        // STRONG: an enlistment on its own; the faction word is optional (the NPC's own faction fills it)
        'strong_phrases' => ['i want to join', "i'd like to join", 'i would like to join', 'id like to join', 'let me join',
            'can i join', 'could i join', 'may i join', 'how do i join', 'how can i join', 'how could i join',
            'how does one join', 'how would i join', 'where do i join', 'where can i join', 'i wish to join',
            'i came to join', 'i came here to join', "i'm here to join", 'i am here to join', 'im here to join',
            'looking to join', 'want to join', 'like to join', 'join up', 'sign me up', 'sign up', 'where do i sign',
            'where can i sign', 'where could i sign', 'i want to enlist', "i'd like to enlist", 'i would like to enlist',
            'how do i enlist', 'where do i enlist', 'let me enlist', 'enlist me', 'recruit me', 'take me as a recruit',
            'i want to be a soldier', 'i want to become a soldier', 'make me a soldier', "i'm ready to take the oath",
            'i want to apply', "i'd like to apply", 'looking to apply', 'i want to enter the college', 'may i enter the college',
            // the bare shapes the model's own TakeUpBusiness item arrives in ("join the Legion", "enlist")
            'join the', 'joining the', 'enlist in', 'enlist with', 'enlist', 'enlisting', 'sign on with'],
        // WEAK: an enlistment only with a faction word in the sentence OR an NPC who belongs to one
        'weak_phrases' => ['i want to serve', 'i was looking to serve', 'looking to serve', 'i came to serve',
            'i wish to serve', 'let me serve', "i'd like to serve", 'i would like to serve', 'take me on',
            'count me in', 'i want in', 'i want to fight for', "i'll fight for", 'i will fight for',
            'start being a', 'start being an'],
        // WORD-BOUND: an enlistment only with a faction word in the sentence ("i want to be a" is anything)
        'word_phrases' => ['i want to be a', 'i want to be an', 'i want to become a', 'i want to become an',
            "i'd like to become a", "i'd like to become an", 'make me a', 'make me an', 'i want to be one of',
            'become one of',
            // [pt19c fixer / walkthrough FRICTION] "I'd like to be a Companion" (Kodlak) asked first: the polite forms
            "i'd like to be a", "i'd like to be an", 'i would like to be a', 'i would like to be an', 'i would like to become a',
            'i wish to be a', 'i wish to become a', "i'd like to be one of", 'i would like to be one of'],
        'rows' => [
            'legion' => [
                'name' => 'the Imperial Legion',
                'words' => ['imperial legion', 'the legion', 'legion', 'imperial army', 'the imperials', 'imperials',
                    'imperial', "empire's army", 'the empire', 'empire', 'legionnaire'],
                // [pt18-quest] Tullius's line is the AP road's (alternateperspective.esp:206783, reachable once MQ101 is
                // complete); the USSEP FreeToGo line stays in `entries` (exact-line match) for a save whose greet 0D5146
                // is open (MQQuickstart < 7 - not this one: AP pins it at 7 until its Helgen has been played)
                'recruiters' => ['General Tullius' => "I don't want to sit idly by after what I've witnessed. I want to join the Legion",
                    'Legate Rikke' => 'About that test...'],
                'recruiter_factions' => ['CWFieldCOFaction', 'CWDialogueSoldierFaction', 'CWImperialFaction'],
                'member_factions' => ['CWImperialFaction*'],
                'quests' => ['CW00A', 'CW01', 'CW01A'],
                'recruiter_topics' => ['CW00TulliusGreetFreeToGo', 'CW00TulliusGreetHadvar', 'CW00TulliusGreetTalkRikkateAP', 'CW00RikkeBlockingTopic', 'MQ103TulliusBookTopic',
                    'CW01AJoinLegionBranchTopic'],
                'redirect_topics' => ['SolitudeFreeformGuardSolitudeArmy', 'CW00JoinTopic', 'MQ102AHadvarJoinLegionTopic',
                    'ACFDialogueDragonbridgeTasiusBranchLegionTopic'],
                'entries' => ['i want to join the legion', "i'd like to join the imperial legion", 'about that test',
                    "i'm ready to take the oath", 'how do i join the imperial legion', 'how does one join the imperial legion',
                    "i was set free i could've gone anywhere i came here to fight for the empire", "i helped hadvar escape he said he'd vouch for me"],
                'how' => 'General Tullius first, then Legate Rikke, both in Castle Dour in Solitude',
                'how_entry' => '',
                'member' => 'any recruit or soldier you deal with is already enlisted - you enlist nobody. ',
                'redirect' => 'in this world one joins the Imperial Legion only through General Tullius and then Legate Rikke,'
                    . ' in Castle Dour in Solitude; never through you, and you may not set a time, a place, a drill or a test for it',
                'gate' => '',
                'offer_topics' => ['ANDR_AJO_Guard*'],
                'offer' => 'the one thing you yourself can offer a willing hand is a shift of guard duty, and only if he asks for that',
                // [pt17] Rikke's "About that test..." is closed until Tullius has sent the player to her (CW00A stage 10) -
                // the line she must say before that. [pt18-quest] `say` is the plain sentence; it is only the whole truth once
                // the ROAD below is open (MQ101 complete) - with the road unknown the line is hedged, with it closed the
                // Helgen line replaces this one.
                'before' => ['Legate Rikke' => ['quest' => 'CW00A', 'stage' => 10,
                    'line' => "only after General Tullius has sent him to you - 'About that test...' is not open before that",
                    'say' => 'General Tullius first, at the map table in Castle Dour']],
                // [pt18-quest] THE ROAD (lrgFacRoad). On this load order Alternate Perspective pins GLOB MQQuickstart at 7.0
                // and its winning Tullius greet 0D5146 needs < 7, so every Tullius line that sets CW00A stage 10 - and with
                // it Rikke's test - is unreachable until AP's Helgen (MQ101; stage 900 carries CompleteQuest) has been
                // played. Known incomplete (mq101c=0 / qst MQ101 below 900 / mqq >= 7, on this NPC's facts line or another
                // recruiter's cached one) -> `line` replaces every other recruiter line; unknown -> nothing is asserted
                // open. In-world words only (the refusal names Helgen, never a global or a stage).
                'closed' => [
                    'General Tullius' => ['quest' => 'MQ101', 'complete_stage' => 900,
                        'line' => "on this start the Legion's road runs through Helgen first: the game opens no enlistment line of"
                            . ' yours until Helgen is behind him, and it has recorded none'],
                    'Legate Rikke' => ['quest' => 'MQ101', 'complete_stage' => 900,
                        'line' => "on this start the Legion's road runs through Helgen first: the game opens no enlistment line of"
                            . ' yours or of General Tullius until Helgen is behind him, and it has recorded none'],
                ],
                // [pt18-quest] THE EFFECT a scripted join line has (lrgFacQuestPlan / ExtCmdLRG_QuestEntry). Only a line whose
                // whole fragment is GetOwningQuest().SetStage(n), decoded: alternateperspective.esp:206783 (DIAL 206784
                // CW00TulliusGreetTalkRikkateAP, GetIsID only, Goodbye; tif_ap_04206783.pex = SetStage(10)), reached from AP's
                // greet 0D5145 (GetQuestCompleted(MQ101), CW.PlayerGotIntro == 0, CW00A 20 not done) via 0D5113 "I was at
                // Helgen" -> 05206780. conds, as the GAME re-checks them on the live forms: isid = the speaker's base form id
                // (low 24 bits, decimal: 0x01327E Tullius); qdone = quests that must be complete; notdone = stages of the
                // quest that must not be done; max = the highest current stage allowed (never lower a quest); qnd =
                // quest:stage pairs that must not be done - CW00B:10 stands in for CW.PlayerGotIntro == 0 this round (the
                // CWScript stub is deliberately not built: research/pt18-quest.md 6). Rikke has NO effect: her stage 20 needs
                // stage 10 first, an alive-count branch and an explicit yes - words only until then.
                'effects' => [
                    'General Tullius' => ['entry' => 'CW00TulliusGreetTalkRikkateAP', 'info' => 'alternateperspective.esp:206783',
                        'quest' => 'CW00A', 'stage' => 10,
                        'conds' => ['isid' => 78462, 'qdone' => ['MQ101'], 'notdone' => [10, 20], 'max' => 9, 'qnd' => ['CW00B' => 10]],
                        'words' => 'I suspect we might have use for someone resourceful like you. Not many survived Helgen.',
                        'meaning' => 'he is sent to Legate Rikke; nothing enters his journal until she gives him the Fort Hraggstad test',
                        'say' => 'he is to speak with Legate Rikke - no oath, no rank, no time or place',
                        'hint' => 'CW00A stage 10 - speak with Legate Rikke (no journal entry until her test)',
                        'journal' => 0],
                ],
                'verified_from' => 'INFO 0C348B CW00TulliusForcegreetTopic (AP) / 0D514F CW00RikkeBlockingTopic (USSEP) / AP 206783'
                    . ' CW00TulliusGreetTalkRikkateAP / 034B35 SolitudeFreeformGuardSolitudeArmy (USSEP: "speak to Legate Rikke in'
                    . ' Castle Dour"); CW00JoinTopic conds (CWFieldCOFaction+CWDialogueSoldierFaction+CWImperialFaction) = raw scan'
                    . ' pass 2, not the index; Aldis factions AIAgent.log:1925; index 2026-09-22'
                    . ' / [pt17] USSEP 0D5150 CW00TulliusGreetFreeToGo (GetIsID only) -> 0D5136 CW00TulliusGreetTalkRikke (VMAD -> CW00A stage 10);'
                    . ' AP 206781 CW00TulliusAP01 unlinked in this load order; 0D514F needs CW00A stage 10 done'
                    . ' / [pt18-quest, CORRECTION of pt17] AP greet 0D5146 is CLOSED on this save: AlternatePerspective.esp adds'
                    . ' GetGlobalValue(MQQuickstart) < 7 (USSEP\'s 0D5146 has no such condition) and overrides GLOB 0004679E to 7.0;'
                    . ' the only writer in the load order is AP\'s QF_MQ101_0003372B_new (6.0 on its Helgen path; MQ101 at stage 0'
                    . ' here); Save15 / Save16 GLOB decode = 7.0. 0C348B / 0D5145 need GetQuestCompleted(MQ101). Post-Helgen road:'
                    . ' 0D5145 -> 0D5113 -> 05206780 -> 05206784 / INFO 206783 (tif_ap_04206783.pex = SetStage 10). QUST CW00A winner'
                    . ' LoreRim - Dialogue Patch.esp; TCIY qf_cw00_000d3c5f.pex comments out setObjectiveDisplayed(1) at stage 10'
                    . ' (no journal line until CW01A 1 from stage 20); MQ101 stage 900 = CompleteQuest (AP override)',
            ],
            'stormcloaks' => [
                'name' => 'the Stormcloaks',
                'words' => ['stormcloaks', 'stormcloak', 'the rebellion', 'rebellion', 'the rebels', 'rebels', 'ulfric',
                    'sons of skyrim', 'true sons of skyrim'],
                // Ulfric is a REDIRECT on purpose: his AP greet lines ("I came here to fight the Empire") carry no join
                // word and end in "speak with Galmar" - the redirect text says exactly that
                'recruiters' => ['Galmar Stone-Fist' => "That's why I'm here. I want to join."],
                'recruiter_factions' => ['CWSonsFaction'],
                'member_factions' => ['CWSonsFaction*'],
                'quests' => ['CW00B', 'CW01B'],
                'recruiter_topics' => ['CW00BGalmarSignMeUp', 'CW00BGalmarBlockingTopic', 'CW01BGalmarGreetReady'],
                'redirect_topics' => ['CW00JoinTopic', 'MQ102BRalofJoinStormcloaksTopic'],
                'entries' => ["that's why i'm here i want to join", 'about that test', "i'm ready to take the oath",
                    'how do i join the stormcloaks', 'how does one join the stormcloaks'],
                'how' => 'Galmar Stone-Fist, in the Palace of the Kings in Windhelm (Ulfric sends people to him)',
                'how_entry' => '',
                'member' => 'any soldier you deal with already swore to Ulfric - you enlist nobody. ',
                'redirect' => 'in this world one joins the Stormcloaks only through Galmar Stone-Fist in the Palace of the Kings'
                    . ' in Windhelm; never through you, and you may not set a time, a place, a drill or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'CW00BGalmarSignMeUp INFO 0E1AE8 (scripted, twat CW00BGalmarClearIsland); CW00JoinTopic'
                    . ' Stormcloak variant (AP); CW00UlfricPlayerAP02 / CW00BUlfricGreetFreeToGo (AP); TheChoiceIsYours.esp wins'
                    . ' CW00BGalmarNotSure; index 2026-09-22',
            ],
            'companions' => [
                'name' => 'the Companions',
                'words' => ['the companions', 'companions', 'a companion', 'jorrvaskr', 'the circle'],
                'recruiters' => ['Kodlak Whitemane' => 'I would like to join the Companions.'],
                'recruiter_factions' => ['CompanionsFaction'],
                'member_factions' => ['CompanionsFaction*'],
                'quests' => ['C00'],
                'recruiter_topics' => ['C00KodlakJoinUpStartTopic'],
                'redirect_topics' => ['CRNoWorkBranchTopic'],
                'entries' => ['i would like to join the companions', 'can i join the companions'],
                'how' => 'Kodlak Whitemane, the Harbinger, in Jorrvaskr in Whiterun',
                'how_entry' => '',
                'member' => 'you are one of them, but the Harbinger alone decides who joins - you enlist nobody. ',
                'redirect' => 'in this world one joins the Companions only by asking Kodlak Whitemane, the Harbinger, in'
                    . ' Jorrvaskr in Whiterun; never through you, and you may not set a time, a place or a test for it',
                'gate' => 'the Companions are said to turn away anyone who is not already a capable fighter, and you cannot judge that for him',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'INFO 0A3E7A C00KodlakJoinUpStartTopic (winner Companions at Mirmulnir.esp); CRNoWorkBranchTopic'
                    . ' winner LoreRim - Dialogue Patch.esp with NGCDT 000F75/76 gate NGCDT_GLOB_Aela_AVMin=30 / HMSMin=150 (GLOB dump);'
                    . ' CompanionsFaction 048362 (scan)',
            ],
            'college' => [
                'name' => 'the College of Winterhold',
                'words' => ['college of winterhold', 'the mages college', 'mages college', 'the college', 'college',
                    'the mages', 'mages', 'winterhold', 'mages guild'],
                'recruiters' => ['Faralda' => 'May I enter the College?'],
                'recruiter_factions' => ['CollegeofWinterholdFaction'],
                'member_factions' => ['CollegeofWinterhold*'],
                'quests' => ['MG01'],
                'recruiter_topics' => ['MG01FaraldaEntryBranchIntro', 'MG01Faralda1WhyAreYouHereBranchTopic'],
                'redirect_topics' => [],
                'entries' => ['may i enter the college'],
                'how' => 'Faralda, on the bridge to the College in Winterhold, who sets a test of magic',
                'how_entry' => '',
                'member' => 'you study or teach there, but Faralda alone admits anyone - you enlist nobody. ',
                'redirect' => 'in this world one enters the College of Winterhold only by asking Faralda on the bridge to the'
                    . ' College in Winterhold, and she sets a test of magic; never through you, and you may not set a time,'
                    . ' a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'INFOs 0B810F/0B8136 MG01FaraldaEntryBranchIntro, 0AF0DF MG01Faralda1WhyAreYouHereBranchTopic'
                    . ' (CollegeEntry.esp) - entry rows only; the test branches are not decoded',
            ],
            'thieves_guild' => [
                'name' => 'the Thieves Guild',
                'words' => ['thieves guild', "thieves' guild", 'the thieves', 'thieves', 'the guild'],
                'recruiters' => ['Brynjolf' => ''],
                'recruiter_factions' => ['ThievesGuildFaction'],
                'member_factions' => ['ThievesGuild*'],
                'quests' => ['TG00'],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => 'Brynjolf, in the Riften market, who offers a job to those he judges useful',
                'how_entry' => '',
                'member' => 'you are one of them, but Brynjolf alone brings people in - you enlist nobody. ',
                'redirect' => 'in this world the Thieves Guild takes nobody who merely asks; Brynjolf in the Riften market'
                    . ' approaches those he judges useful and offers a job; never through you, and you may not set a time,'
                    . ' a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'recruiter_line' => 'you ARE the one who brings people into the Thieves Guild, and it begins with the job you'
                    . ' offer in the market - whether you offer it is settled in your own dialogue with him, not in this talk',
                'verified_from' => 'INFOs 04FCDA/0352E0 TG00BrynjolfIntroBranchTopic (his own hello, TG00 stage < 5); no player'
                    . ' join line exists (absence)',
            ],
            'dark_brotherhood' => [
                'name' => 'the Dark Brotherhood',
                'words' => ['dark brotherhood', 'the brotherhood', 'brotherhood', 'the assassins', 'assassins'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['DarkBrotherhood*'],
                'quests' => ['DB01', 'DB02'],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => '',
                'how_entry' => '',
                'member' => '',
                'redirect' => 'nobody enlists anyone into the Dark Brotherhood by asking; in this world they come to those they'
                    . ' choose - you cannot arrange it, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'DB01/DB02 index rows (Innocence Lost - Quest Expansion.esp); no walk-up join line in the'
                    . ' index (absence); Astrid\'s DB02 rows not decoded',
            ],
            'bards' => [
                'name' => 'the Bards College',
                'words' => ['bards college', "bard's college", 'the bards', 'bards', 'bard', 'the college', 'college'],
                'recruiters' => ['Viarmo' => "I'm looking to apply to the college."],
                'recruiter_factions' => [],
                'member_factions' => ['*BardsCollege*'],
                'quests' => ['MS05'],
                'recruiter_topics' => ['MS05ViarmoApplicationTopic'],
                'redirect_topics' => [],
                'entries' => ["i'm looking to apply to the college"],
                'how' => 'Viarmo, the headmaster, at the Bards College in Solitude',
                'how_entry' => '',
                'member' => 'you belong to the College, but Viarmo alone takes applicants - you enlist nobody. ',
                'redirect' => 'in this world one applies to the Bards College only to Viarmo, its headmaster, at the College in'
                    . ' Solitude; never through you, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'INFO 053504 MS05ViarmoApplicationTopic (winner TheGiftofSaturalia.esp); conditions not decoded;'
                    . ' SolitudeBardsCollegeFaction seen on Ataf / Giraud / Pantea snapshots',
            ],
            'dawnguard' => [
                'name' => 'the Dawnguard',
                'words' => ['the dawnguard', 'dawnguard', 'vampire hunters', 'vampire hunter',
                    // [pt19h-quest / G18, COVERAGE fix] "Killing vampires? Where do I sign up?" is Durak's own line: hunting vampires
                    // is the Dawnguard, never the Volkihar row ("count me in, I want to hunt vampires" went to Volkihar and clicked
                    // nothing); the STT's "dawn guards" beat the one-token 'guards' row ("i'm here to join the dawn guards")
                    'hunt vampires', 'hunting vampires', 'hunt some vampires', 'kill vampires', 'killing vampires', 'kill some vampires',
                    'slay vampires', 'slaying vampires', 'fight vampires', 'fighting vampires', 'vampire hunting',
                    'dawn guard', 'dawn guards', 'the dawn guard', 'the dawn guards'],
                'recruiters' => ['Isran' => "I'm here to join the Dawnguard.", 'Durak' => 'Killing vampires? Where do I sign up?'],
                'recruiter_factions' => ['DLC1DawnguardFaction'],
                'member_factions' => ['DLC1Dawnguard*'],
                'quests' => ['DLC1VQ00', 'DLC1VQ01', 'DLC1VQ01MiscObjective'],
                'recruiter_topics' => ['DLC1VQ01IntroA1'],
                'redirect_topics' => ['DLC1VQ00IntroC'],
                'entries' => ["i'm here to join the dawnguard", 'killing vampires where do i sign up'],
                'how' => 'Isran, at Fort Dawnguard south-east of Riften (Durak recruits on the road)',
                'how_entry' => '',
                'member' => 'you hunt with them, but Isran alone takes recruits - you enlist nobody. ',
                'redirect' => 'in this world one joins the Dawnguard only at Fort Dawnguard, south-east of Riften, through Isran;'
                    . ' never through you, and you may not set a time, a place or a test for it',
                'gate' => 'the Dawnguard is said to take only seasoned fighters, and you cannot judge whether he is one',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'INFOs 00E997/00D8E2 DLC1VQ00IntroC (Durak), 00D901 DLC1VQ01IntroA1 (Isran); DawnguardQuestPrerequisite.esp'
                    . ' 0100D057/58: MS14 complete and GetLevel >= DLC1VQMinLevel = 30 (Requiem.esp / LoreRim - Global Modifiers.esp GLOB dump)',
            ],
            'volkihar' => [
                'name' => 'the Volkihar vampires',
                'words' => ['volkihar', 'the vampires', 'vampires', 'a vampire', 'vampire'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['DLC1Vampire*'],
                'quests' => ['DLC1VQ02'],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => '',
                'how_entry' => '',
                'member' => '',
                'redirect' => 'nobody joins the Volkihar vampires by asking; in this world Lord Harkon alone offers his blood, and'
                    . ' only to those he chooses - you cannot arrange it, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'DLC1VQ02 INFO 003BA1 (Harkon, inside Bloodline); index rows',
            ],
            'blades' => [
                'name' => 'the Blades',
                'words' => ['the blades', 'blades'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['Blades*'],
                'quests' => ['MQ203'],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => '',
                'how_entry' => '',
                'member' => '',
                'redirect' => 'nobody joins the Blades by asking; in this world they take in only those the dragon business of'
                    . ' the main story brings to them - you cannot arrange it, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'no recruit line exists in the index (absence); MQ203 index rows',
            ],
            'penitus' => [
                'name' => 'the Penitus Oculatus',
                'words' => ['penitus oculatus', 'penitus', 'oculatus', "emperor's guard", 'emperors guard'],
                'recruiters' => [],
                'recruiter_factions' => ['PenitusOculatusFaction'],
                'member_factions' => ['PenitusOculatus*'],
                'quests' => ['zzzPO00'],
                'recruiter_topics' => ['zzzPO00JoinTopic', 'zzzPO00RejoinTopic'],
                'redirect_topics' => [],
                'entries' => ['i want to join the penitus oculatus', 'i want to rejoin the penitus oculatus'],
                'how' => 'their own outpost, and only once the Dark Brotherhood is no more',
                'how_entry' => 'I want to join the Penitus Oculatus.',
                'member' => 'you serve with them, but their commander alone takes a recruit - you enlist nobody. ',
                'redirect' => 'in this world the Penitus Oculatus take a recruit only at their own outpost, and only once the Dark'
                    . ' Brotherhood is no more; never through you, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'zzzPO00JoinTopic INFO penitus_oculatus.esp:10DD93, conds DBDestroy stage >= 200 and zzzPO00 stage 0;'
                    . ' the speaker alias and the outpost location were NOT verified (so no name and no place is named)',
            ],
            'vigilants' => [
                'name' => 'the Vigilants of Stendarr',
                'words' => ['vigilants of stendarr', 'vigil of stendarr', 'the vigilants', 'vigilants', 'vigilant', 'the vigil', 'stendarr'],
                'recruiters' => [],
                'recruiter_factions' => ['VigilantOfStendarrFaction'],
                'member_factions' => ['Vigilant*'],
                'quests' => ['zzzAoMMq00'],
                'recruiter_topics' => ['zzAoMMq0B1Tvigilant', 'zzAoMMq0B1Yes'],
                'redirect_topics' => [],
                'entries' => ['are you recruiting for the vigil of stendarr', "yes i'll join you"],
                'how' => 'their own recruiter, and only for the seasoned',
                'how_entry' => "Yes, I'll join you.",
                'member' => 'you are one of them, but only their recruiter takes anyone in - you enlist nobody. ',
                'redirect' => 'in this world the Vigilants of Stendarr take a recruit only through their own recruiter, and only a'
                    . ' seasoned one; never through you, and you may not set a time, a place or a test for it',
                'gate' => 'they are said to take only the seasoned, and you cannot judge whether he is one',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'Vigilant.esm zzAoMMq0B1Tvigilant / zzAoMMq0B1Yes index rows; Vigilant - Delayed Start.esp'
                    . ' zzzVigilantMinLevel = 25 (GLOB dump); the recruiter alias and location were NOT verified',
            ],
            'guards' => [
                'name' => 'the guard',
                'words' => ['the guard', 'the guards', 'a guard', 'city guard', 'town guard', 'hold guard', 'guard duty', 'the watch', 'guards'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['IsGuardFaction', 'GuardDialogueFaction', 'GuardFaction*'],
                'quests' => [],
                'recruiter_topics' => ['ANDR_AJO_Guard*'],
                'redirect_topics' => [],
                'entries' => ['i would like to take guard duty'],
                'how' => "the hold's guard captain, for a single shift of guard duty",
                'how_entry' => 'I would like to take guard duty.',
                'member' => 'you wear the uniform, but nobody is enlisted by you. ',
                'redirect' => 'in this world nobody is enlisted as a guard by asking; a hold\'s guard captain can put a willing'
                    . ' hand on a shift of guard duty, nothing more; never through you, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'recruiter_line' => 'you ARE the one who can put a willing hand on a shift of guard duty - six hours, by daylight,'
                    . ' and not while he carries a bounty in this hold; that is the whole of it, there is no enlisting as a guard',
                'verified_from' => 'ANDR_AJO_GuardHaafingarImp_StartTopic01 INFO 000A3B GetIsID(00041FB8)=Aldis, GameHour 9-15 or'
                    . ' AJO_NoShifts, no Haafingar bounty, not CWSonsFaction (scan pass 2); IsGuardFaction / GuardFactionSolitude on'
                    . ' every Solitude guard snapshot',
            ],
            'eec' => [
                'name' => 'the East Empire Company',
                'words' => ['east empire company', 'east empire', 'the east empire'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['*EastEmpire*'],
                'quests' => [],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => '',
                'how_entry' => '',
                'member' => '',
                'redirect' => 'the East Empire Company hires nobody by asking - it is a trading house, not a company one joins;'
                    . ' never through you, and you may not set a time, a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'no join rows in the index (absence); only EEC building / navmesh patches',
            ],
            'thalmor' => [
                'name' => 'the Thalmor',
                'words' => ['the thalmor', 'thalmor', 'aldmeri dominion', 'the dominion', 'dominion'],
                'recruiters' => [],
                'recruiter_factions' => [],
                'member_factions' => ['Thalmor*'],
                'quests' => [],
                'recruiter_topics' => [],
                'redirect_topics' => [],
                'entries' => [],
                'how' => '',
                'how_entry' => '',
                'member' => '',
                'redirect' => 'the Thalmor take no recruits from strangers, and never through you; you may not set a time,'
                    . ' a place or a test for it',
                'gate' => '',
                'offer_topics' => [], 'offer' => '',
                'verified_from' => 'no join rows in the index (absence)',
            ],
        ],
    ];
}

/** Defaults in code + the owner's `dialogue.factions` block (through lrgDlgCfg, which already merged it). */
function lrgFacCfg(string $path = '', $default = null)
{
    static $cfg = null;
    static $seam = false;   // the offline seam was merged into the cache: rebuild once it is gone
    if ($cfg === null || $seam || array_key_exists('LRG_FAC_TEST_OVERRIDE', $GLOBALS)) {
        $cfg = lrgFacDefaults();
        $over = function_exists('lrgDlgCfg') ? (array) lrgDlgCfg('factions', []) : [];
        if ($over) { $cfg = lrgMerge($cfg, $over); }
        $seam = is_array($GLOBALS['LRG_FAC_TEST_OVERRIDE'] ?? null);
        if ($seam) { $cfg = lrgMerge($cfg, $GLOBALS['LRG_FAC_TEST_OVERRIDE']); }
    }
    if ($path === '') { return $cfg; }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

function lrgFacRow(string $id): ?array
{
    $row = lrgFacCfg('rows.' . $id);
    return is_array($row) ? $row : null;
}

// ================================================================== who she is
/** The display name without CHIM's bracket suffix ("Hans [Solitude Guard]" -> "Hans"). */
function lrgFacName(string $npc): string
{
    return trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/', '', $npc));
}

/** The faction EditorIDs the game last sent on the snapshot (PROTOCOL 1.1 `fac`, <= 48 of them). */
function lrgFacFactions(array $t): array
{
    $csv = (string) (((array) ($t['snap'] ?? []))['fac'] ?? '');
    if (trim($csv) === '') { return []; }
    return array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
}

/** Every topic EditorID this NPC's lists carry: the live layer, the cached root and the open session. */
function lrgFacTopics(array $t): array
{
    $out = [];
    $npc = (string) ($t['npc'] ?? '');
    $st = $npc !== '' ? lrgDlgState($npc) : [];
    $pools = [(array) ($t['entries'] ?? []), (array) ($t['tail'] ?? []),
        (array) (((array) ($st['root'] ?? []))['entries'] ?? []), (array) (((array) ($st['session'] ?? []))['entries'] ?? [])];
    foreach ($pools as $pool) {
        foreach ($pool as $e) {
            $topic = (string) (((array) $e)['topic'] ?? '');
            if ($topic !== '') { $out[$topic] = 1; }
        }
    }
    return array_keys($out);
}

/**
 * recruiter | redirect | none for ONE row. Name first (Tullius / Rikke keep their names in this modlist as
 * far as seen); then the recruiter faction set AND a recruiter topic really on her list (a captain with the
 * guard-duty line, an unnamed Penitus officer with zzzPO00JoinTopic); quest membership and the member
 * faction globs yield at most `redirect` - a CW01 alias is not a recruiter.
 */
function lrgFacRoleOf(array $row, string $npc, array $fac, array $topics, array $q): string
{
    $name = lrgFacName($npc);
    foreach (array_keys((array) ($row['recruiters'] ?? [])) as $rn) {
        if ($name !== '' && strcasecmp($name, (string) $rn) === 0) { return 'recruiter'; }
    }
    $rt = (array) ($row['recruiter_topics'] ?? []);
    if ($rt && $topics && lrgAnyGlob($rt, $topics)) {
        $all = true;
        foreach ((array) ($row['recruiter_factions'] ?? []) as $need) {
            if (!lrgAnyGlob([(string) $need], $fac)) { $all = false; break; }
        }
        if ($all) { return 'recruiter'; }
    }
    $ql = array_map('strtolower', $q);
    foreach ((array) ($row['quests'] ?? []) as $rq) {
        if (in_array(strtolower((string) $rq), $ql, true)) { return 'redirect'; }
    }
    $mf = (array) ($row['member_factions'] ?? []);
    if ($mf && $fac && lrgAnyGlob($mf, $fac)) { return 'redirect'; }
    return 'none';
}

/** row id => role, for every row this NPC has a role in, in table order. */
function lrgFacRoles(array $t): array
{
    $out = [];
    $npc = (string) ($t['npc'] ?? '');
    $fac = lrgFacFactions($t);
    $topics = lrgFacTopics($t);
    $q = array_map('strval', array_values(array_filter((array) ($t['q'] ?? []))));
    foreach ((array) lrgFacCfg('rows', []) as $id => $row) {
        $role = lrgFacRoleOf((array) $row, $npc, $fac, $topics, $q);
        if ($role !== 'none') { $out[(string) $id] = $role; }
    }
    return $out;
}

// ================================================================== what he said
/**
 * The enlistment ask in the player's own words, or none. Exact token runs with the follower verbs' negation
 * guard and clause stops, never similarity. $tiers = the row ids this NPC belongs to (table order): they
 * resolve a bare "i want to join" and break a tie between two factions that share a word ("the college").
 * Returns ['join' => 0|1, 'faction' => id|'', 'phrase' => .., 'word' => .., 'strength' => strong|weak|word|'',
 *          'ambiguous' => [ids]].
 */
function lrgFacAsk(string $utter, array $tiers = [], array $extraWord = []): array
{
    $none = ['join' => 0, 'faction' => '', 'phrase' => '', 'word' => '', 'strength' => '', 'ambiguous' => []];
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    if (!$hay) { return $none; }
    $stops = lrgDlgClauseStarts($utter);
    $objStops = array_map('strval', (array) lrgFacCfg('object_stops', []));
    $subjStops = array_map('strval', (array) lrgFacCfg('subject_stops', []));
    $phrase = '';
    $strength = '';
    foreach ([['strong', 'strong_phrases'], ['weak', 'weak_phrases'], ['word', 'word_phrases']] as [$s, $key]) {
        $best = '';
        $bestN = 0;
        // [pt19h-quest / G18] $extraWord: word-bound shapes only lrgFacEntryJoins reads a LINE ON HER LIST with (never his ask)
        $plist = (array) lrgFacCfg($key, []);
        if ($key === 'word_phrases' && $extraWord) { $plist = array_merge($plist, $extraWord); }
        foreach ($plist as $p) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $p));
            if (!$pt || count($pt) <= $bestN) { continue; }
            $at = lrgDlgTokenAt($hay, $pt);
            if ($at < 0 || lrgDlgNegatedAt($hay, $at, $stops)) { continue; }
            $next = (string) ($hay[$at + count($pt)] ?? '');
            if ($next !== '' && in_array($next, $objStops, true)) { continue; }   // "can i join YOU"
            $third = false;                                                        // "why did YOU join the Legion?"
            for ($b = max(0, $at - 2); $b < $at; $b++) { if (in_array($hay[$b], $subjStops, true)) { $third = true; } }
            if ($third) { continue; }
            $best = (string) $p;
            $bestN = count($pt);
            $bestAt = $at;
        }
        if ($best !== '') { $phrase = $best; $strength = $s; $phraseEnd = (int) $bestAt + $bestN; break; }
    }
    if ($phrase === '') { return $none; }
    // [pt19c fixer / adversarial QA @adv_facask_q] a QUESTION WORD over the ask's own clause asks ABOUT joining, it does not ask to
    // join: "what does it take to join the Companions?", "why would I join?". "how do I enlist" stays the ask (the way in is what
    // he wants), and so does a request shaped as a question ("can I join the Companions?", "do you know where I could sign up for
    // the Legion?") and an ask with a question after it ("I want to join the Legion, but what's in it for me?")
    if (function_exists('lrgDlgQuestionKind')) {
        $pt = lrgDlgTokens(lrgPromptNorm($phrase));
        foreach (preg_split('/[,;:.!?]+|\bbut\b/iu', $utter) ?: [] as $cl) {
            if (!lrgDlgTokenRun(lrgDlgTokens(lrgPromptNorm((string) $cl)), $pt)) { continue; }
            // [pt19h-quest / G18] ... unless the WHERE asks for the way in ("where do I sign up", "where can I join", "where do I go
            // to sign up for this vampire hunting thing" - the strong phrases say so). "where do I sign up for the Companions?" said
            // to Durak was no ask, so the hand-off stood aside and the explicit test clicked Durak's Dawnguard sign-up on the shared
            // "where do i sign up"
            $qk = lrgDlgQuestionKind(trim((string) $cl) . '?');
            if (in_array($qk, ['wh:what', 'wh:why', 'wh:who', 'wh:when', 'wh:where'], true) && !lrgFacWayIn((string) $cl)) { return $none; }
            break;
        }
    }
    // the faction word, longest run wins across rows; a negated word does not count ("not the stormcloaks")
    $cands = [];
    foreach ((array) lrgFacCfg('rows', []) as $id => $row) {
        $bestN = 0;
        $bestW = '';
        foreach ((array) ($row['words'] ?? []) as $w) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $w));
            if (!$pt || count($pt) <= $bestN) { continue; }
            $at = lrgDlgTokenAt($hay, $pt);
            if ($at < 0 || lrgDlgNegatedAt($hay, $at, $stops)) { continue; }
            $bestN = count($pt);
            $bestW = (string) $w;
            $bestWAt = $at;
        }
        if ($bestN > 0) { $cands[(string) $id] = ['n' => $bestN, 'w' => $bestW, 'at' => (int) $bestWAt]; }
    }
    $faction = '';
    $word = '';
    $amb = [];
    // [pt19h-quest / G18] THE OBJECT OF THE ASK wins: a faction word within two tokens after the phrase ("I'm here to join the
    // Stormcloaks and fight the Empire" is the Stormcloaks - the longest-run rule below gave the Legion its two-token "the empire")
    $obj = '';
    $objD = 99;
    $objTie = false;
    foreach ($cands as $id => $c) {
        $d = (int) $c['at'] - (int) $phraseEnd;
        if ($d < 0 || $d > 2) { continue; }
        if ($d < $objD) { $obj = (string) $id; $objD = $d; $objTie = false; }
        elseif ($d === $objD) { $objTie = true; }   // "the college" names two rows at once: the old rule (tiers, ambiguity) decides
    }
    if ($objTie) { $obj = ''; }
    if ($obj !== '') {
        $faction = $obj;
        $word = (string) $cands[$obj]['w'];
    } elseif ($cands) {
        $max = 0;
        foreach ($cands as $c) { $max = max($max, (int) $c['n']); }
        $top = [];
        foreach ($cands as $id => $c) { if ((int) $c['n'] === $max) { $top[] = (string) $id; } }
        if (count($top) === 1) {
            $faction = $top[0];
        } else {
            $inTier = array_values(array_intersect(array_map('strval', $tiers), $top));
            if ($inTier) { $faction = $inTier[0]; } else { $amb = $top; }
        }
        $word = (string) ($cands[$faction]['w'] ?? '');
    }
    $join = false;
    if ($strength === 'strong') { $join = true; }
    elseif ($strength === 'weak') { $join = $faction !== '' || $amb !== [] || $tiers !== []; }
    else { $join = $faction !== '' || $amb !== []; }
    if (!$join) { return $none; }
    if ($faction === '' && !$amb && $tiers) { $faction = (string) $tiers[0]; }   // "i want to join", said to a legionary
    return ['join' => 1, 'faction' => $faction, 'phrase' => $phrase, 'word' => $word, 'strength' => $strength, 'ambiguous' => $amb];
}

// ================================================================== the turn
/**
 * The faction data of this turn, built once in lrgDlgPrepareTurn(). $live = a player-speech or lrg_dlgtalk
 * turn (the utterance is his words now); otherwise only a rechat type inside the window carries the last
 * ask. A speech turn WITHOUT an ask inside the window carries it too ("yes sir" after "I want to join").
 * Returns [] or ['asked' => id|'', 'join' => 1, 'role' => .., 'phrase', 'word', 'ambiguous', 'carried' => 0|1, 'at'].
 */
function lrgFacTurn(array $t, string $utter, bool $live): array
{
    if (empty($t['on']) || empty(lrgFacCfg('enabled', true))) { return []; }
    $npc = (string) ($t['npc'] ?? '');
    if ($npc === '') { return []; }
    // CHIM's own 5 s poll and every funcret reach here too: nothing is computed for them
    if (!$live && !in_array((string) ($t['type'] ?? ''), array_map('strval', (array) lrgFacCfg('rechat_types', ['rechat'])), true)) { return []; }
    $st = lrgDlgState($npc);
    $prev = (array) ($st['facask'] ?? []);
    $window = max(10, (int) lrgFacCfg('window_seconds', 90));
    $roles = lrgFacRoles($t);
    $tiers = array_keys($roles);
    $now = lrgNow();
    if ($live) {
        $ask = trim($utter) !== '' ? lrgFacAsk($utter, $tiers) : ['join' => 0];
        if (!empty($ask['join'])) {
            $id = (string) $ask['faction'];
            // [pt19h-quest / G1, G5, G11, G18] may these words ACT (own a click, queue the entry)? The truth rides either way.
            // [pt19h-quest r2 / use P1-P3] judged clause by clause, her own join line exempt (lrgFacAskQualm); `qualm` says why not:
            // refuses / defers -> her words answer; hedges / asks -> nothing is clicked and she asks, quoting the join line
            $qualm = lrgFacAskQualm($utter, $ask, lrgFacOwnLines($t, $id));
            $out = ['asked' => $id, 'join' => 1, 'role' => $id !== '' ? (string) ($roles[$id] ?? 'none') : 'none',
                'phrase' => (string) $ask['phrase'], 'word' => (string) $ask['word'], 'ambiguous' => (array) $ask['ambiguous'],
                'carried' => 0, 'at' => $now, 'executed' => [], 'plan' => [], 'road' => [],
                'acts' => $qualm === '' ? 1 : 0, 'qualm' => $qualm];
            if ($id !== '') {
                lrgDlgPut($npc, ['facask' => ['id' => $id, 'role' => $out['role'], 'at' => $now,
                    'utter' => substr(trim($utter), 0, 120)]]);
            }
            // [pt18-quest] the road (MQ101 on this load order) and, on a recruiter, the click-free plan decided from his
            // own words BEFORE the LLM. `executed` (research/pt18-words.md 4.4) is set only when the plan is LICENSED:
            // every server-checkable condition passed on a fresh facts line. The command itself goes out in the
            // post-gate (lrgFacQuestNet) - one route, after the words.
            if ($id !== '' && $out['role'] === 'recruiter') {
                $row = lrgFacRow($id);
                if ($row !== null) {
                    $out['road'] = lrgFacRoad($t, $row, lrgFacName($npc));
                    $out['plan'] = lrgFacQuestPlan($t, $out);
                    if ((string) ($out['plan']['state'] ?? '') === 'queued' && !empty($out['plan']['licensed'])) {
                        $out['executed'] = ['quest' => (string) $out['plan']['quest'], 'stage' => (int) $out['plan']['stage'],
                            'how' => 'entry', 'cid' => (string) ($t['cid'] ?? '')];
                    }
                }
            }
            return $out;
        }
        // [pt19h-quest / G17] no enlistment in his words: the click-free quest-entry TABLE (config/lrg_quest_entries.default.json) -
        // his explicit sentence of a scripted quest line whose whole fragment is one SetStage, when the driver cannot click
        $qe = lrgFacQeTurn($t, $utter);
        if ($qe) { return $qe; }
    }
    // no ask in these words (or a rechat, which has none): carry the last one while its window is open
    if (!$prev || $now - (int) ($prev['at'] ?? 0) > $window) { return []; }
    $id = (string) ($prev['id'] ?? '');
    if ($id === '') { return []; }
    $out = ['asked' => $id, 'join' => 1, 'role' => (string) ($roles[$id] ?? ($prev['role'] ?? 'none')),
        'phrase' => '', 'word' => '', 'ambiguous' => [], 'carried' => 1, 'at' => (int) ($prev['at'] ?? 0),
        'executed' => [], 'plan' => [], 'road' => []];
    if ($out['role'] === 'recruiter') {
        $row = lrgFacRow($id);
        if ($row !== null) { $out['road'] = lrgFacRoad($t, $row, lrgFacName($npc)); }
    }
    return $out;
}

/** Does any of this NPC's lists carry an entry on one of these topic globs? */
function lrgFacListHasTopic(array $t, array $globs): bool
{
    if (!$globs) { return false; }
    $topics = lrgFacTopics($t);
    return $topics && lrgAnyGlob(array_map('strval', $globs), $topics);
}

/** The recruiter's own player line for this NPC: by name, else the row's generic one, else ''. */
function lrgFacRecruiterEntry(array $row, string $npc): string
{
    $name = lrgFacName($npc);
    foreach ((array) ($row['recruiters'] ?? []) as $rn => $line) {
        if ($name !== '' && strcasecmp($name, (string) $rn) === 0) { return trim((string) $line); }
    }
    return trim((string) ($row['how_entry'] ?? ''));
}

// ================================================================== the locked lines
/**
 * The `faction` lines of <locked_facts>, plain strings, at most factions.max_lines. Each starts with
 * "of this world, not of this moment" because the block's header says the game confirmed the rest; a cell the
 * investigation could not verify is empty and yields nothing. Never a '<' or '>' (the $add closure drops those).
 * [pt17-replies] On a carried LIVE turn the first line is additionally marked as background to what he asked a
 * moment ago, not what he is saying now (lrgFacCarriedLive) - the rechat carry keeps the plain wording.
 */
function lrgFacLockedLines(array $t): array
{
    $f = (array) ($t['faction'] ?? []);
    if (!empty($f['qe'])) { return lrgFacQeLockedLines($t); }   // [pt19h-quest] a quest-entry TABLE turn has its own line
    $id = (string) ($f['asked'] ?? '');
    if ($id === '' || empty($f['join'])) { return []; }
    $row = lrgFacRow($id);
    if ($row === null) { return []; }
    $npc = (string) ($t['npc'] ?? '');
    $name = (string) ($row['name'] ?? $id);
    $role = (string) ($f['role'] ?? 'none');
    $prefix = (lrgFacCarriedLive($t) ? '(background to what he asked a moment ago, not what he is saying now) ' : '')
        . 'of this world, not of this moment: ';
    $lines = [];
    if ($role === 'recruiter') {
        $who = trim((string) ($row['recruiter_line'] ?? ''));
        if ($who === '') {
            $who = 'you ARE one of those who take recruits for ' . $name
                . (trim((string) ($row['how'] ?? '')) !== '' ? ' (' . trim((string) $row['how']) . ')' : '');
        }
        $entry = lrgFacRecruiterEntry($row, $npc);
        $menuless = (int) lrgDlgMcm('ml', 1, $npc) === 1;
        // [pt17] a recruiter whose own entry is CLOSED until another quest stage (Rikke before Tullius has sent the
        // player to her): the game sends qst=<quest>:<stage> on the facts line and lrgDlgPrepareTurn copies it into
        // $turn['facts']['qst']; while the stage is short she says so, names the one who comes first, and sets nothing
        $qst = (array) ((($t['facts'] ?? [])['qst'] ?? []));
        $npcKey = function_exists('lrgFacName') ? lrgFacName($npc) : trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/', '', $npc));
        $before = (array) ((($row['before'] ?? [])[$npcKey] ?? []));
        // [pt18-quest] ORDER: the road (MQ101 on this load order, lrgFacRoad) first - known closed, the Helgen line
        // replaces everything; then the click-free plan's own states (queued licensed / queued / already / pending);
        // then pt17's 'before' line, hedged while the road is unknown; then the ml=1 / ml=0 lines of pt16 / pt18-words.
        // Every line stays under the block's 600-char body cap (a longer line is dropped whole by lrgDlgLockedBlock).
        $road = (array) ($f['road'] ?? []);
        if ($road === []) { $road = lrgFacRoad($t, $row, $npcKey); }
        $plan = (array) ($f['plan'] ?? []);
        $pstate = (string) ($plan['state'] ?? '');
        $pq = (string) ($plan['quest'] ?? '');
        $ps = (int) ($plan['stage'] ?? 0);
        $pm = trim((string) ($plan['meaning'] ?? ''));
        $never = 'promise nothing, and never swear him in, take his oath, give him a rank, orders, a place, a time, a drill or a test';
        if ((string) ($road['state'] ?? '') === 'closed') {
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - but ' . trim((string) ($road['line'] ?? ''))
                . ' - tell him so plainly in one line (Helgen first), ' . $never;
        } elseif ($pstate === 'queued' && !empty($plan['licensed'])) {
            // the words lane's executed line (spliced in below) says what the game is recording; this line keeps the frame
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - he asked you the proper way and the game answers it this'
                . ' moment (stated in the next line): say that, and nothing beyond it - no oath, no rank, no time or place';
        } elseif ($pstate === 'queued') {
            // shorter prohibitions here on purpose: with the meaning in it the line must stay under the body cap
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - the game is being asked this moment to record ' . $pq
                . ' stage ' . $ps . ($pm !== '' ? ' (' . $pm . ')' : '') . '; it has NOT confirmed it yet, so do not say it is'
                . ' done: answer him in one plain line, promise nothing, and never swear him in or give him a rank, orders,'
                . " a time or a place - the game's own answer follows";
        } elseif ($pstate === 'already' && (int) ($plan['cur'] ?? $ps) > $ps) {
            // [pt18-quest review] PAST the stage (CW00A 20 / 21: Rikke's test given or done): the stage-10 meaning ("he is
            // sent to Legate Rikke") is stale here, so the line says only that the step is behind him (E7)
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - the game has already recorded ' . $pq . ' past stage ' . $ps
                . ' (it stands at stage ' . (int) $plan['cur'] . '): that step is behind him and what followed it is not this ask -'
                . ' you may say so plainly, and nothing beyond it; ' . $never;
        } elseif ($pstate === 'already') {
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - the game has already recorded ' . $pq . ' stage ' . $ps
                . ($pm !== '' ? ' (' . $pm . ')' : '') . ' - you may say so plainly, and nothing beyond it; ' . $never;
        } elseif ($pstate === 'pending') {
            $lines[] = $prefix . lrgFacRecruiterWho($row, $name) . ' - the game was asked a moment ago to record ' . $pq
                . ' stage ' . $ps . ' and has not answered yet - say you have heard him and that it is being seen to; ' . $never;
        } elseif ($before !== [] && isset($qst[(string) ($before['quest'] ?? '')])
            && (int) $qst[(string) $before['quest']] < (int) ($before['stage'] ?? 0)) {
            $say = trim((string) ($before['say'] ?? ''));
            if ($say === '') { $say = 'General Tullius first, at the map table in Castle Dour'; }
            // [pt18-quest] while the road is UNKNOWN the plain sentence is not the whole truth on this start: hedge it
            $hedge = (string) ($road['state'] ?? '') === 'unknown'
                ? ', and on this start Helgen comes before all of it - the game has not told you whether that is behind him' : '';
            $who .= '; ' . (string) ($before['line'] ?? '') . ' - tell him so plainly in one line: ' . $say . $hedge
                . '; set no time, place, drill or test; nothing has begun';
            $lines[] = $prefix . $who;
        } elseif ($entry !== '' && $menuless) {
            $who .= "; the real way in is the entry '" . rtrim($entry, '.') . "' and it happens only through "
                . lrgDlgActionName() . ' on that entry - nothing has begun until the game says so';
            $lines[] = $prefix . $who;
        } else {
            // [pt18-words] ml=0 (still learning / dry run): the old line said "settled in your own dialogue with him, not
            // in this talk (the glue is still learning ... 0 of 10 ...)" - from the model's seat it IS in dialogue with
            // him, the meta text leaked, and grok-4.3 enacted the oath (23:19, research/pt18-words.md). Now: the fact
            // (the game records an enlistment only through the real dialogue entry, and it has recorded none), the
            // plain line to say, and the concrete prohibitions. The learning counter stays in the log ('dlg ml=0' line).
            $who = lrgFacRecruiterWho($row, $name)
                . ' - but not by words: the game records an enlistment only when he takes your real entry in your own dialogue'
                . ' menu, and it has recorded none, whatever he says or swears here; nothing has begun - tell him so plainly in'
                . ' one line (he must come to you the proper way), promise nothing, and never swear him in, take his oath, give'
                . ' him a rank, orders, a place, a time, a drill or a test';
            $lines[] = $prefix . $who;
        }
    } else {
        $redirect = trim((string) ($row['redirect'] ?? ''));
        if ($redirect === '') { return []; }
        $member = $role === 'redirect' ? (string) ($row['member'] ?? '') : '';
        $gate = trim((string) ($row['gate'] ?? ''));
        $lines[] = $prefix . $member . ($member !== '' ? ucfirst($redirect) : $redirect) . ($gate !== '' ? '. ' . ucfirst($gate) : '')
            . '. Whether he can join right now you do not know unless the game says so';
        if (trim((string) ($row['offer'] ?? '')) !== '' && lrgFacListHasTopic($t, (array) ($row['offer_topics'] ?? []))) {
            $lines[] = trim((string) $row['offer']);
        }
    }
    // [pt18-words] the quest lane's flag: this turn a stage change was queued through the REAL entry (set before
    // call_llm, PROTOCOL 10.22 / 10.25). The confirming sentence is then prompted, not merely tolerated - and
    // nothing beyond it. The meaning of the stage comes from never_false.rows.<id>.stages (lib/lrg_replies.php).
    $exec = (array) ($f['executed'] ?? []);
    if ((string) ($exec['quest'] ?? '') !== '' && isset($exec['stage'])) {
        $meaning = function_exists('lrgNfStageMeaning') ? lrgNfStageMeaning($id, (string) $exec['quest'], (int) $exec['stage']) : '';
        $execLine = 'the game has just recorded ' . (string) $exec['quest'] . ' stage ' . (int) $exec['stage']
            . ($meaning !== '' ? ' - ' . $meaning : '') . ' - you may say so, and nothing beyond it';
        array_splice($lines, 1, 0, [$execLine]);
    }
    $out = [];
    foreach ($lines as $l) {
        $l = str_replace(['<', '>'], ['', ''], $l);
        if (trim($l) !== '') { $out[] = $l; }
    }
    return array_slice($out, 0, max(1, (int) lrgFacCfg('max_lines', 2)));
}

/** [pt18-words] The recruiter's opening clause: the row's own recruiter_line, else the generic one with `how`. */
function lrgFacRecruiterWho(array $row, string $name): string
{
    $who = trim((string) ($row['recruiter_line'] ?? ''));
    if ($who !== '') { return $who; }
    return 'you are one of those who take recruits for ' . $name
        . (trim((string) ($row['how'] ?? '')) !== '' ? ' (' . trim((string) $row['how']) . ')' : '');
}

/** The same lines in lrgDlgLockedFacts()'s row shape - what a rechat turn carries instead of nothing. */
function lrgFacLockedFacts(array $t): array
{
    $classes = array_map('strval', (array) lrgDlgCfg('truth.classes', []));
    if (!in_array('faction', $classes, true)) { return []; }
    $out = [];
    foreach (lrgFacLockedLines($t) as $line) { $out[] = ['class' => 'faction', 'line' => $line, 'num' => null]; }
    return $out;
}

/**
 * [pt17-replies] Is this a CARRIED ask on a LIVE turn - the player's own words now, with no ask in them, inside
 * the window? Then the fact is background and his words may be about something else entirely. A rechat carry
 * (the NPC continuing after an audience line, no player words) is not: there the ask is still the subject.
 */
function lrgFacCarriedLive(array $t): bool
{
    $f = (array) ($t['faction'] ?? []);
    if (empty($f['carried']) || empty($f['join'])) { return false; }
    if (!($t['speech'] ?? false) && !($t['talk'] ?? false)) { return false; }
    return !empty(lrgFacCfg('carried_note', true));
}

/**
 * The one rule the locked block adds on a faction turn (and only then): no invented next step.
 * [pt17-replies] It ends "Answer what he says, in words." (factions.answer_in_words; 31 characters, because
 * test_dialogue 13(c) holds the whole ask-turn block under 900), and on a carried live turn instead says his words
 * now may be about something else - answer them, in words. Rules only, no example lines. The rule sits OUTSIDE the
 * block's 600-char body cap (lrgDlgLockedBlock appends it after the trimmed body).
 */
function lrgFacRule(array $t): string
{
    // [pt19h-quest] a quest-entry TABLE turn: the same no-invented-step rule, in the words of a quest step (no enlistment)
    $fq = (array) ($t['faction'] ?? []);
    if (!empty($fq['qe'])) {
        if ((string) ((($fq['plan'] ?? [])['state'] ?? '')) === '') { return ''; }
        return "Never say the step he just asked for is done, and never invent a meeting, a time, a place, a reward or a next\n"
            . "step, unless it is stated above or the game told you. Answer what he says, in words.\n";
    }
    if ((string) (((array) ($t['faction'] ?? []))['asked'] ?? '') === '') { return ''; }
    $rule = "Never invent an appointment, a meeting time or place, a drill, a test, a rank or a next step for any\n"
        . "quest or enlistment, and never say a quest, a job or an enlistment has begun unless it is stated\n"
        . "above or the game told you.";
    // [pt18-words] the rule keeps its length on purpose: test_dialogue 13(c) holds the redirect ask-turn block under
    // 900 chars and it sits at 898; the concrete prohibitions (swear him in, his oath, a rank, orders) are in the
    // recruiter's own line above, in the never-false nudge and in the validator that judges the words themselves.
    $exec = (array) (((array) ($t['faction'] ?? []))['executed'] ?? []);
    if ((string) ($exec['quest'] ?? '') !== '' && isset($exec['stage'])) {
        $rule .= ' What the game has just recorded (stated above) you may say - that, and nothing beyond it.';
    }
    if (!empty(lrgFacCfg('answer_in_words', true))) {
        $rule .= lrgFacCarriedLive($t)
            ? " His words now may be about something else - answer them, in words; the enlistment above is\nbackground only."
            : ' Answer what he says, in words.';
    }
    return $rule . "\n";
}

// ================================================================== open-for-awareness and the hand-off
/** menuless questing really on for this NPC (ml = bMenuless && !bDlgDryRun as the game sends it; default 1). */
function lrgFacMenuless(string $npc): bool
{
    return (int) lrgDlgMcm('ml', 1, $npc) === 1;
}

/**
 * [reviewer fix] The ask that may decide a CLICK or an OPEN on this turn: the player's own words THIS turn, else
 * the model's item ("join the Legion"). A CARRIED ask (the 90 s window) is prompt context only - it rides
 * <locked_facts> so the rechat and "yes sir" stay truthful - and never owns a click: 30 s after "I want to join
 * the Legion" an exact "How goes the training?" must still run, and a first-contact open for other business
 * must still happen. Returns ['asked' => id, 'join' => 1, 'role' => ..] or [].
 */
function lrgFacClickAsk(array $t, string $item): array
{
    $f = (array) ($t['faction'] ?? []);
    if (!empty($f['join']) && (string) ($f['asked'] ?? '') !== '' && empty($f['carried'])) {
        // [pt19h-quest / G1, G5, G11] his words THIS turn refuse, defer, hedge, echo or ask ABOUT joining: they own no click and
        // no open, and the model's item does not either ("no, I'm ready to take the Oath" clicked the Oath line in faction mode)
        if (array_key_exists('acts', $f) && empty($f['acts'])) { return []; }
        return ['asked' => (string) $f['asked'], 'join' => 1, 'role' => (string) ($f['role'] ?? 'none')];
    }
    if (trim($item) === '') { return []; }
    $roles = lrgFacRoles($t);
    $probe = lrgFacAsk($item, array_keys($roles));
    if (empty($probe['join']) || (string) $probe['faction'] === '') { return []; }
    return ['asked' => (string) $probe['faction'], 'join' => 1, 'role' => (string) ($roles[(string) $probe['faction']] ?? 'none')];
}

/**
 * True when a first-contact open must NOT happen because of the ask: the answer is a redirect (a click cannot
 * enlist him and the open would burn a Say-Once greeting), or she IS a recruiter but menuless questing is off
 * so the open would be refused by the game ("the feature is switched off") - she was told to say so instead.
 * $item = the model's own words for the business: on a carried turn only an ask-bearing item counts.
 */
function lrgFacRefusesOpen(array $t, string $cid, string $item = ''): bool
{
    // [pt19 v1.0 / S2.2] THE ROAD RULE, for ANY open - not only a join ask: a recruiter whose Say-Once greeting stands behind
    // a faction road known CLOSED (a row's `closed` cell for her: Tullius's 0D5145, Rikke's) is never opened on, whatever he
    // said ("what do you sell" to Rikke included) - the open would burn the greeting on a menu that carries no line of hers
    $sayonce = lrgFacSayOnceClosed($t);
    $f = lrgFacClickAsk($t, $item);
    if ($sayonce !== '') {
        // [pt19c-B fix 1 / architect review] the log says only what happened: on a join ask her faction line tells her the road
        // is closed; on any other sentence ("what do you sell" to Rikke before Helgen) nothing tells her anything - her own words
        lrgDlgLog('faction npc=' . (string) ($t['npc'] ?? '') . ' - no open: her Say-Once greeting stands behind a closed road ('
            . $sayonce . '); ' . ($f ? 'she was told the road is closed' : 'her own words answer'), $cid);
        return true;
    }
    // [pt19h-quest] ONE carrier per sentence: a queued quest-entry TABLE row already answers it - no open beside it
    $fq = (array) ($t['faction'] ?? []);
    if (!empty($fq['qe']) && (string) ((($fq['plan'] ?? [])['state'] ?? '')) === 'queued') {
        lrgDlgLog('faction npc=' . (string) ($t['npc'] ?? '') . ' - no open: the click-free quest entry (10.26 table row '
            . (string) ((($fq['qe'] ?? [])['row'] ?? '?')) . ') answers this sentence', $cid);
        return true;
    }
    if (!$f) { return false; }
    $id = (string) $f['asked'];
    $npc = (string) ($t['npc'] ?? '');
    $role = (string) ($f['role'] ?? 'none');
    if ($role !== 'recruiter') {
        lrgDlgLog('faction npc=' . $npc . ' asked=' . $id . ' role=' . $role
            . ' - no open for awareness: the answer is a redirect in her own words, not a click', $cid);
        return true;
    }
    if (!lrgFacMenuless($npc)) {
        lrgDlgLog('faction npc=' . $npc . ' asked=' . $id . ' role=recruiter ml=0 - no hand-off: menuless questing is'
            . ' off (or in dry run), so she was told to settle it in her own dialogue and say so', $cid);
        return true;
    }
    // [pt18-quest] the road is known CLOSED (Helgen first): an open would burn a Say-Once greeting on a menu that carries
    // no join line; and a QUEUED click-free entry already answers this very ask - no open beside it
    $fr = (array) ($t['faction'] ?? []);
    if ((string) ((($fr['road'] ?? [])['state'] ?? '')) === 'closed' && empty($fr['carried'])) {
        lrgDlgLog('faction npc=' . $npc . ' asked=' . $id . ' role=recruiter road=closed - no open: the Legion road runs through'
            . ' Helgen first on this start (' . (string) ((($fr['road'] ?? [])['src'] ?? '-')) . '), she was told to say so', $cid);
        return true;
    }
    if ((string) ((($fr['plan'] ?? [])['state'] ?? '')) === 'queued') {
        lrgDlgLog('faction npc=' . $npc . ' asked=' . $id . ' role=recruiter - no open: the click-free quest entry answers this ask', $cid);
        return true;
    }
    return false;
}

/**
 * [pt19 v1.0 / S2.2] '' or why: a faction row's `closed` cell names this NPC (her greeting is Say-Once behind that road;
 * `sayonce: false` in the cell opts out) and lrgFacRoad says the road is CLOSED. One loop over the rows.
 */
function lrgFacSayOnceClosed(array $t): string
{
    $name = lrgFacName((string) ($t['npc'] ?? ''));
    if ($name === '') { return ''; }
    foreach ((array) lrgFacCfg('rows', []) as $id => $row) {
        $c = (array) ((((array) $row)['closed'] ?? [])[$name] ?? []);
        if ($c === [] || !($c['sayonce'] ?? true)) { continue; }
        $road = lrgFacRoad($t, (array) $row, $name);
        if ((string) ($road['state'] ?? '') === 'closed') { return (string) $id . ' road closed, ' . (string) ($road['src'] ?? '-'); }
    }
    return '';
}

/** The business marker of a recruiter turn (the open IS worth a Say-Once greeting there), else ''. */
function lrgFacMarker(array $t, string $item = ''): string
{
    $f = lrgFacClickAsk($t, $item);
    if (!$f || (string) ($f['role'] ?? '') !== 'recruiter') { return ''; }
    // [pt19c fixer / walkthrough M9] a recruiter with NO join line of her own (Brynjolf: the Thieves Guild is joined through his
    // pitch, not a line) is no reason to open: the list would sit on screen after her bridging line with nothing to pick
    $row = lrgFacRow((string) ($f['asked'] ?? ''));
    $name = lrgFacName((string) ($t['npc'] ?? ''));
    foreach ((array) ($row['recruiters'] ?? []) as $rn => $line) {
        if ($name !== '' && strcasecmp($name, (string) $rn) === 0 && trim((string) $line) === '') { return ''; }
    }
    return lrgFacMenuless((string) ($t['npc'] ?? '')) ? 'a faction this NPC recruits for' : '';
}

/** Are this entry's own words about joining (the shared-topic guard)? */
function lrgFacNormHasEntryWord(string $norm): bool
{
    $tok = lrgDlgTokens($norm);
    return $tok && array_intersect($tok, array_map('strval', (array) lrgFacCfg('entry_words', []))) !== [];
}

/**
 * THE RECRUITER HAND-OFF (the follower-verb pattern, D4): the ask owns the turn.
 *   null              -> no enlistment was asked (by the player, or in the model's own item): nothing changes
 *   ['entry' => e]    -> run THAT entry (an exact topic or an exact line), every existing rail still applies
 *   ['entry' => null] -> an ask that no single entry answers: NOTHING is executed and she answers in words
 *                        (two candidates: she asks which; none: the locked facts are her answer). The similarity
 *                        matcher is never consulted on an enlistment - it would click "Are you with the Legion?"
 */
function lrgFacArbitrate(array $t, array $pool, string $item, string $cid): ?array
{
    if (empty(lrgFacCfg('enabled', true))) { return null; }
    $npc = (string) ($t['npc'] ?? '');
    // the player's words THIS turn, else the model's own item ("join the Legion"), as with the follower verbs;
    // a CARRIED ask never owns a click (lrgFacClickAsk)
    $f = lrgFacClickAsk($t, $item);
    if (!$f) {
        // [pt19h-quest review / G1, G5, G11] HIS words this turn ask to join but may not act (a refusal, deferral, hedge, echo or
        // advice question - lrgFacAskActs): on the WORDS path nothing is executed and her words answer, exactly as the want=1 hand-off
        // (lrgFacArbitrateWant) returns false. Returning null here handed the model's item to the ordinary matchers, and an item that
        // names the join line clicked it in intent mode ("maybe I'll join the Legion one day" -> "I'd like to join the Imperial Legion.")
        $f0 = (array) ($t['faction'] ?? []);
        if (!empty($f0['join']) && (string) ($f0['asked'] ?? '') !== '' && empty($f0['carried']) && array_key_exists('acts', $f0) && empty($f0['acts'])) {
            // [pt19h-quest r2 / use P3] ... a HEDGE, an ECHO or a question that is no request ("I'm not sure, I'd like to join the
            // Imperial Legion", "wait, I'm here to join the Dawnguard?") makes her ASK: the one join line is handed on as a commit that is
            // never explicit (fcommit, as a follower dismissal) - lrgDlgDecideEntry PARKS it and her question quotes it; his "yes" then
            // releases it (S4.4). A refusal or a deferral stays nothing: her words answer and nothing is asked. The model's item must
            // name that enlistment or that line - any other line is not clicked on this turn either (the model's key still can be)
            $qualm = (string) ($f0['qualm'] ?? 'refuses');
            $ask = in_array($qualm, ['hedges', 'asks'], true) ? lrgFacAskLine($t, $pool, (string) $f0['asked'], $item) : null;
            if ($ask !== null) {
                lrgDlgLog(sprintf('faction npc=%s asked=%s pos=%d why=his-words-%s - "%s" is never clicked on them: she asks, quoting it'
                    . ' (a commit that is never explicit - the park)', $npc, (string) $f0['asked'], (int) ($ask['pos'] ?? -1), $qualm,
                    substr((string) ($ask['text'] ?? ''), 0, 40)), $cid);
                return ['entry' => ['commit' => true, 'fcommit' => 1] + $ask];
            }
            lrgDlgLog(sprintf('faction npc=%s asked=%s entry=- why=his-words-%s - nothing is executed, she answers in words'
                . ' (the model\'s item owns no click either)', $npc, (string) $f0['asked'], $qualm), $cid);
            return ['entry' => null];
        }
        return null;
    }
    $id = (string) $f['asked'];
    $row = lrgFacRow($id);
    if ($row === null) { return null; }
    $role = (string) ($f['role'] ?? 'none');
    $globs = array_map('strval', array_merge((array) ($row['recruiter_topics'] ?? []), (array) ($row['redirect_topics'] ?? [])));
    $norms = [];
    foreach ((array) ($row['entries'] ?? []) as $ln) { $n = lrgPromptNorm((string) $ln); if ($n !== '') { $norms[] = $n; } }
    $cands = [];
    foreach ($pool as $e) {
        $e = (array) $e;
        if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
        $topic = (string) ($e['topic'] ?? '');
        $norm = (string) ($e['norm'] ?? '');
        $byTopic = $topic !== '' && $globs && lrgAnyGlob($globs, [$topic]) && lrgFacNormHasEntryWord($norm);
        $byNorm = $norm !== '' && in_array($norm, $norms, true);
        if ($byTopic || $byNorm) { $cands[] = $e; }
    }
    $said = array_key_exists('said', $t) ? (string) $t['said']
        : (!empty($t['speech']) ? (string) (((array) (lrgDlgState($npc)['utter'] ?? []))['text'] ?? '') : '');
    if (count($cands) === 1) {
        $e = $cands[0];
        // [pt19h-quest / G18] his wh-question IS the line's own question ("Killing vampires? Where do I sign up?" said to Durak,
        // "where do I sign up to kill vampires"): the hand-off stands aside and the explicit test compares the two questions - the
        // faction mode vetoes every wh-question on a COMMIT, so taking it here would click nothing where the line itself was said (a
        // plain line - the soldier's "How does one join the Imperial Legion?" - stays the faction pick: no veto applies to it)
        $isCommit = (string) ($e['class'] ?? '') === 'commit' || !empty($e['commit']);
        if ($isCommit && $said !== '' && function_exists('lrgDlgQuestionKind')) {
            $qk = lrgDlgQuestionKind($said);
            if (strncmp($qk, 'wh:', 3) === 0 && lrgDlgQuestionKind((string) ($e['text'] ?? ''), true) === $qk) {
                lrgDlgLog(sprintf('faction npc=%s asked=%s role=%s pos=%d - his %s question is the line\'s own: the hand-off stands aside,'
                    . ' the explicit test compares the two questions', $npc, $id, $role, (int) ($e['pos'] ?? -1), $qk), $cid);
                return null;
            }
        }
        lrgDlgLog(sprintf('faction npc=%s asked=%s role=%s pos=%d topic=%s why=%s', $npc, $id, $role, (int) ($e['pos'] ?? -1),
            (string) ($e['topic'] ?? '-'), in_array((string) ($e['norm'] ?? ''), $norms, true) ? 'exact-line' : 'exact-topic'), $cid);
        return ['entry' => $e];
    }
    if (count($cands) > 1) {
        lrgDlgLog(sprintf('faction npc=%s asked=%s role=%s AMBIGUOUS - %d entries could be meant, she asks instead',
            $npc, $id, $role, count($cands)), $cid);
        return ['entry' => null];
    }
    // [pt19h-quest / G18] FACTION WORDS TAKE QUEST LINES. No join entry of hers by topic or exact line - but a line on her list IS
    // an enlistment for this very faction in its own words (Tullius's "I just want to join the Legion. Consider the crown a gift.",
    // Ulfric's "I made a mistake. I want to be a Stormcloak. ...", the council's "I accept your offer. I'd like to join the
    // Stormcloaks.", Serana's "I want you to turn me into a vampire."), or he answered her with a leading yes and her list carries a
    // yes-line (Ulfric's "Yes, sir."): the hand-off STANDS ASIDE (null) and the ordinary matchers decide with every rail - a commit
    // clicks only on his own plain sentence, else she asks. A list with no such line keeps pt16's rule: nothing is clicked and her
    // words answer (Aldis's "Are you with the Legion?" is no enlistment). $said = his words this turn (read above).
    $defer = lrgFacDeferTo($pool, $id, $said, array_keys(lrgFacRoles($t)));
    if ($defer !== '') {
        lrgDlgLog(sprintf('faction npc=%s asked=%s role=%s entry=- why=%s - the hand-off stands aside, the ordinary matchers decide'
            . ' with every rail', $npc, $id, $role, $defer), $cid);
        return null;
    }
    lrgDlgLog(sprintf('faction npc=%s asked=%s role=%s entry=- why=no-join-entry-on-her-list - nothing is executed,'
        . ' she answers in words (the similarity matcher is not consulted on an enlistment)', $npc, $id, $role), $cid);
    return ['entry' => null];
}

/**
 * [pt19h-quest / G18] Why the enlistment hand-off stands aside for this list ('' = it does not): a visible line that is itself an
 * enlistment for faction $id in its own words (lrgFacEntryJoins), or his leading yes with a yes-line on her list.
 */
function lrgFacDeferTo(array $pool, string $id, string $said, array $tiers): string
{
    if ($id === '') { return ''; }
    $extra = array_map('strval', (array) lrgFacCfg('entry_join_phrases', []));
    $yesLine = false;
    foreach ($pool as $e) {
        $e = (array) $e;
        $txt = trim((string) ($e['text'] ?? ''));
        if ($txt === '' || (string) ($e['class'] ?? '') === 'hidden') { continue; }
        if (lrgFacEntryJoins($txt, $id, $tiers, $extra)) { return 'a line on her list is itself this enlistment ("' . substr($txt, 0, 40) . '")'; }
        if (function_exists('lrgDlgAssent') && lrgDlgAssent($txt) !== null) { $yesLine = true; }
    }
    // [pt19h-quest r2 / arch P2] ... only for a faction SHE belongs to (Ulfric, a CWSonsFaction member, for the Stormcloaks), and never
    // when his words turn against it ("yes sir, but I want to join the Stormcloaks instead" to Tullius clicked "Yes, sir." - the Legion)
    if ($yesLine && trim($said) !== '' && function_exists('lrgDlgAssent') && lrgDlgAssent($said) !== null
        && in_array($id, array_map('strval', $tiers), true) && !lrgFacContrasts($said, $id)) {
        return 'he answered her with a yes and her list carries a yes-line';
    }
    return '';
}

/**
 * [pt19h-quest r2 / arch P2] Do his words after a yes turn against it or look elsewhere: a contrast ("but", "instead", "rather",
 * "though", "however", "except", "otherwise") or a word of ANOTHER faction row ("yes sir, I'd rather be a Stormcloak" to Tullius)?
 */
function lrgFacContrasts(string $said, string $id): bool
{
    if (preg_match('/\b(?:but|instead|rather|though|although|however|except|otherwise|unless)\b/i', $said)) { return true; }
    $hay = lrgDlgTokens(lrgPromptNorm($said));
    foreach ((array) lrgFacCfg('rows', []) as $rid => $row) {
        if ((string) $rid === $id) { continue; }
        foreach ((array) (((array) $row)['words'] ?? []) as $w) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $w));
            if ($pt && lrgDlgTokenRun($hay, $pt)) { return true; }
        }
    }
    return false;
}

/** [pt19h-quest / G18] Is this line (the text on her list) an enlistment for faction $id in its own first-person words? */
function lrgFacEntryJoins(string $text, string $id, array $tiers = [], array $extra = []): bool
{
    // a sentence end is a clause end ("I don't see another way. I'll become a vampire." - the first sentence negates nothing of the
    // second), and "I want you to turn me into ..." is his own request, not a third person's ("you" before "turn me")
    $text = (string) preg_replace('/[.!?\x{2026}]+(?=\s|$)/u', ',', $text);
    $text = (string) preg_replace("/\\b(?:i want|i'd like|i would like) you to (turn|make) me\\b/i", '$1 me', $text);
    $a = lrgFacAsk($text, $tiers, $extra);
    return !empty($a['join']) && (string) ($a['faction'] ?? '') === $id;
}

/**
 * [pt19h-quest / G1, G5, G11, G18] May these words ACT - own a click on his join line, queue the click-free entry - as an
 * enlistment? lrgFacAskQualm() says why not ('' = they act). The ask (lrgFacAsk) still carries the faction's truth into
 * <locked_facts> either way; only the act is withheld.
 */
function lrgFacAskActs(string $utter, array $ask, array $lines = []): bool
{
    return lrgFacAskQualm($utter, $ask, $lines) === '';
}

/**
 * [pt19h-quest r2 / use P1, P2, P3] WHY his words may not act as an enlistment, or '' when they do:
 *   refuses | defers -> a refusal ("no, I'm ready to take the Oath") or a deferral ("not now, ... later"): nothing, her words answer;
 *   hedges | asks    -> a hedge ("maybe I'll join", "I'm not sure, ...") or a question that is no request (an echo "wait, I'm here to
 *                       join the Dawnguard?", advice "do you think I should join the Legion?", "does this make me a bard"): nothing is
 *                       clicked and she ASKS, quoting the join line (lrgFacArbitrate parks it; lrgFacArbitrateWant hands a commit to the
 *                       fast path's commit rail, which never clicks it).
 * Read CLAUSE BY CLAUSE, never over the whole sentence (use P2):
 *   - THE ASK'S CLAUSE (the one holding the join phrase), cut before his reasons ("... so I can prove myself one day", "because
 *     ..."): every test. A trailing "if I can / could / may", "if that's all right", "if you'll have me" is politeness. A request
 *     shaped as a question acts ("can I join ...", "is it possible (for me) to join ...", "would it be possible ...", "any chance I
 *     could ...", "do you think I could ...", "I was wondering if I could ...", "how / where do I ...") and is still judged for a
 *     refusal, deferral or hedge ("can I join later?");
 *   - a LEADING clause only as a short marker (<= 4 tokens: "no", "not yet", "I'm not sure", "maybe"), a leading hedge ("maybe I'm
 *     crazy, ..."), or a back-out of the decision at any length ("never mind", "on second thought", "first I need to ..."): "First I
 *     want to say thank you." and "I don't want to sit idly by after what I've witnessed." are sentences of his own;
 *   - a TRAILING clause only as a short tag (<= 3 tokens: "later", "but not yet", "I guess", "or not") or a back-out of the decision
 *     ("but I'll do it later", "let me think about it"): "I'll prove myself later", "I might be of use to you", "I'll probably need
 *     training", "I don't want to sit around anymore" are his own. A sentence that ENDS as an echo (a statement with a '?') asks.
 * $lines (use P1): her own lines of this enlistment (lrgFacOwnLines). When his words carry one WHOLE, saying it is choosing it: its
 * own words are never judged, only what he said around it. Tullius's AP line "I don't want to sit idly by after what I've witnessed.
 * I want to join the Legion" said word for word acts; "not now, <that line>" still defers; "<a statement line>?" still asks.
 */
function lrgFacAskQualm(string $utter, array $ask, array $lines = []): string
{
    $utter = trim($utter);
    if ($utter === '' || empty($ask['join'])) { return 'refuses'; }
    $pt = lrgDlgTokens(lrgPromptNorm((string) ($ask['phrase'] ?? '')));
    $text = $utter;
    $lineAsks = false;
    // his words carry a line of hers WHOLE (the longest first): that span is his choosing it - a neutral token stands in its place
    $lines = array_values(array_filter(array_map('strval', $lines), static fn($s) => trim($s) !== ''));
    usort($lines, static fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($lines as $ln) {
        $lt = lrgDlgTokens(lrgPromptNorm($ln));
        if (count($lt) < 3) { continue; }
        $at = lrgFacRawRun($text, $lt);
        if ($at === null) { continue; }
        $text = substr($text, 0, $at[0]) . ' lrgline ' . substr($text, $at[0] + $at[1]);
        $pt = ['lrgline'];
        // (a question line said with its '?' asks nothing of his own: "How does one join the Imperial Legion?")
        $lineAsks = (bool) preg_match('/\?\s*["\')\]]*\s*$/', trim($ln)) || lrgFacClauseAsks($ln);
        break;
    }
    $polite = "(?:if (?:i|we) (?:can|could|may)|if (?:it'?s|that'?s|it is|that is) (?:ok|okay|alright|all right|fine)(?: with you)?"
        . "|if you(?:'ll| will| would)? (?:have|take) (?:me|us)|if you please|if you don'?t mind|please)";
    $reqRe = "/^\\W*(?:(?:so|well|uh|um|hey|and|ok|okay|then|now|sorry|say|excuse me|please)\\W+){0,3}(?:(?:can|could|may|might) (?:i|we)\\b"
        . "|(?:do|would|did) you (?:know|happen to know) (?:where|how|if|whether) (?:i|we)\\b"
        . "|(?:will|would|can|could) you (?:take|let|have|enlist|sign|accept|recruit|admit|allow|consider) (?:me|us)\\b"
        . "|is there (?:a|any) (?:way|chance) (?:for (?:me|us)|i|we)\\b"
        . "|(?:is it|would it be|will it be|would that be) possible (?:for (?:me|us) )?to\\b"
        . "|(?:any|is there any|is there a) chance (?:i|we) (?:could|can|might|may)\\b"
        . "|(?:do|would) you think (?:i|we) (?:could|can|might|may)\\b"
        . "|(?:i was|i am|i'?m|we were|we are|we'?re) (?:just )?wondering (?:if|whether) (?:i|we) (?:could|can|might|may)\\b)/i";
    $req = static fn(string $c): bool => lrgFacWayIn($c) || (bool) preg_match($reqRe, $c);
    $hedgeRe = "/\\b(?:maybe|perhaps|possibly|probably|might|someday|some day|one day|eventually|i'?m (?:thinking|considering|wondering)"
        . "|thinking (?:about|of)|considering|not sure|unsure|i wonder|if i (?:could|can|should|decide|ever))\\b/i";
    $tailHedge = "/\\b(?:i (?:guess|suppose|reckon)|if i (?:must|have to)|or not)\\W*$/i";
    $deferRe = '/\b(?:later|tomorrow|next time|some other time|another time|one of these days|not (?:now|yet|today)|after (?:i|we)|first i)\b/i';
    // a back-out of the DECISION, at any length and in any clause
    $backRef = "/\\b(?:never ?mind|forget (?:it|that|i asked|about it)|on second thought|changed my mind|cancel that|rather not|not interested"
        . "|i'?ll pass|no thanks|no thank you|let'?s not|(?:i|we)(?:'d| had)? better not)\\b/i";
    $backDef = "/\\b(?:not (?:yet|now|right now|today|this time)|(?:some other|another|next) time|maybe (?:later|not|another time)"
        . "|come back (?:later|tomorrow|another time)|(?:do|finish|decide|sort) (?:it|that|this|so) (?:later|tomorrow|another time|some other time)"
        . "|(?:i'?ll|i will|let me|lemme|i need to|i have to|i must|i should|i want to) think (?:about it|it over|on it|this over|that over|it through)"
        . "|sleep on it|need (?:more |some |a little |a bit of )?time|first,? (?:i|we) (?:need|have|must|gotta|got to|should|will|'ll)"
        . "|(?:after|once|when) (?:i|we)(?:'ve| have|'m| am)? (?:finish|finished|deal|dealt|take care|get back|return|rest|sleep|think|handle"
        . "|done|ready|settle|sort|talk|speak)|(?:before|until) (?:i|we) (?:join|sign|enlist|commit|decide))\\b/i";
    $short = static function (string $c) use ($hedgeRe, $tailHedge, $deferRe): string {
        if (function_exists('lrgDlgRefuses') && lrgDlgRefuses($c)) { return 'refuses'; }
        if (preg_match($deferRe, $c)) { return 'defers'; }
        if (preg_match($hedgeRe, $c) || preg_match($tailHedge, $c)) { return 'hedges'; }
        return '';
    };
    // the clauses, each with the punctuation that closes it
    $parts = preg_split('/([,;:.!?\x{2026}]+|\bbut\b)/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $cls = [];
    for ($i = 0; $i < count($parts); $i += 2) {
        $c = trim((string) $parts[$i]);
        $d = (string) ($parts[$i + 1] ?? '');
        if ($c === '') { if ($cls) { $cls[count($cls) - 1][1] .= $d; } continue; }
        $cls[] = [$c, $d];
    }
    $ji = -1;
    foreach ($cls as $k => $cd) {
        if (!$pt || lrgDlgTokenRun(lrgDlgTokens(lrgPromptNorm((string) $cd[0])), $pt)) { $ji = (int) $k; break; }
    }
    if ($ji < 0) { $cls = [[$text, '']]; $ji = 0; }   // (the phrase spans a comma: the whole sentence is the ask's clause)
    [$jc, $jd] = $cls[$ji];
    // ---- THE ASK'S CLAUSE, cut before his reasons, politeness and the request's own frame dropped
    $core = $jc;
    $run = $pt ? lrgFacRawRun($jc, $pt) : null;
    if ($run !== null && preg_match('/\b(?:so that|so|because|cause|cuz|since|as long as|in order to)\b/i', $jc, $sm, PREG_OFFSET_CAPTURE, $run[0] + $run[1])) {
        $core = substr($jc, 0, (int) $sm[0][1]);
    }
    $core = trim((string) preg_replace('/[\s,]*\b' . $polite . '\W*$/i', '', $core));
    $asked = (str_contains($jd, '?') && !($pt === ['lrgline'] && $lineAsks)) || lrgFacClauseAsks($jc);
    if ($asked && !$req($jc)) { return 'asks'; }
    $coreT = trim((string) preg_replace($reqRe, ' ', $core, 1));
    if (function_exists('lrgDlgRefuses') && lrgDlgRefuses($coreT)) { return 'refuses'; }
    if (preg_match($backRef, $coreT)) { return 'refuses'; }
    if (preg_match($deferRe, $coreT) || preg_match($backDef, $coreT)) { return 'defers'; }
    if (preg_match($hedgeRe, $coreT) || preg_match($tailHedge, $coreT) || (function_exists('lrgDlgHedges') && lrgDlgHedges($coreT))) { return 'hedges'; }
    // ---- TRAILING clauses: a short tag, a back-out of the decision; politeness is neither
    $lastCl = '';
    $lastD = '';
    $trail = '';
    for ($k = $ji + 1; $k < count($cls); $k++) {
        [$c, $d] = $cls[$k];
        if (preg_match('/^\W*' . $polite . '\W*$/i', (string) $c)) { continue; }
        if ($trail === '') {
            if (preg_match($backRef, (string) $c)) { $trail = 'refuses'; }
            elseif (preg_match($backDef, (string) $c)) { $trail = 'defers'; }
            elseif (count(lrgDlgTokens(lrgPromptNorm((string) $c))) <= 3) { $trail = $short((string) $c); }
        }
        $lastCl = (string) $c;
        $lastD = (string) $d;
    }
    // [pt19h-quest review / G11] his sentence ENDS as an echo: a later clause said as a statement with a '?' ("wait, I just want to
    // join the Legion. Consider the crown a gift?") - an echo as much as "wait, <the ask>?" is, whatever leads it. A real question or a
    // request after it ("... Can I?", "... What do I do?") does not
    if (!$asked && $trail === '' && $lastCl !== '' && str_contains($lastD, '?') && !lrgFacClauseAsks($lastCl) && !$req($lastCl)) { return 'asks'; }
    // ---- LEADING clauses: a short marker, a leading hedge, a back-out of the decision
    for ($k = 0; $k < $ji; $k++) {
        $c = (string) $cls[$k][0];
        if ($k === 0 && function_exists('lrgDlgHedges') && lrgDlgHedges($c)) { return 'hedges'; }
        if (preg_match($backRef, $c)) { return 'refuses'; }
        if (preg_match($backDef, $c)) { return 'defers'; }
        if (count(lrgDlgTokens(lrgPromptNorm($c))) <= 4 && ($s = $short($c)) !== '') { return $s; }
    }
    if ($trail !== '') { return $trail; }
    if (preg_match($tailHedge, rtrim((string) preg_replace('/[\s,]*\b' . $polite . '\W*$/i', '', $text)))) { return 'hedges'; }
    return '';
}

/** [pt19h-quest r2] Where a NORMALISED token run (lrgDlgTokens(lrgPromptNorm(..))) sits in raw words: [byte offset, length], or null. */
function lrgFacRawRun(string $raw, array $tokens): ?array
{
    if (!$tokens) { return null; }
    $parts = [];
    foreach ($tokens as $w) { $parts[] = str_replace('\#', '\d[\d,.]*', preg_quote((string) $w, '/')); }
    $re = "/(?<![a-z0-9'])" . implode("[^a-z0-9']+", $parts) . "(?![a-z0-9'])/iu";
    if (!@preg_match($re, $raw, $m, PREG_OFFSET_CAPTURE)) { return null; }
    return [(int) $m[0][1], strlen((string) $m[0][0])];
}

/**
 * [pt19h-quest r2 / use P1] Her own lines of enlistment $id: the row's `entries`, her recruiter line, and a line on her lists that is
 * this enlistment (on the row's topics with a join word, or lrgFacEntryJoins). Said whole, one of them is his choosing it.
 */
function lrgFacOwnLines(array $t, string $id, ?array $tiers = null): array
{
    $row = $id !== '' ? lrgFacRow($id) : null;
    if ($row === null) { return []; }
    $out = array_map('strval', (array) ($row['entries'] ?? []));
    $rl = lrgFacRecruiterEntry($row, (string) ($t['npc'] ?? ''));
    if ($rl !== '') { $out[] = $rl; }
    $globs = array_map('strval', array_merge((array) ($row['recruiter_topics'] ?? []), (array) ($row['redirect_topics'] ?? [])));
    $tiers = $tiers ?? array_keys(lrgFacRoles($t));
    $extra = array_map('strval', (array) lrgFacCfg('entry_join_phrases', []));
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        $e = (array) $e;
        $txt = trim((string) ($e['text'] ?? ''));
        if ($txt === '' || (string) ($e['class'] ?? '') === 'hidden') { continue; }
        $topic = (string) ($e['topic'] ?? '');
        if (($topic !== '' && $globs && lrgAnyGlob($globs, [$topic]) && lrgFacNormHasEntryWord(lrgPromptNorm($txt)))
            || lrgFacEntryJoins($txt, $id, $tiers, $extra)) {
            $out[] = $txt;
        }
    }
    return array_values(array_unique(array_filter($out, static fn($s) => trim((string) $s) !== '')));
}

/**
 * [pt19h-quest r2 / use P3] THE ONE join line of enlistment $id on this list her question can quote when his words hedge, echo or
 * ask (lrgFacAskQualm hedges | asks), or null: by topic or exact line as the hand-off finds it, else a line that is this enlistment
 * in its own words (lrgFacEntryJoins - Tullius's crown line). Never two candidates, never the layer's single entry (S4.5 judges that
 * one), never on the engine's own Yes / No layer, never while a park of that very line stands (his hedge again is no answer - the
 * question already asked waits). $item (the words path): the model's words must name this enlistment or this line, else nothing.
 */
function lrgFacAskLine(array $t, array $pool, string $id, ?string $item = null): ?array
{
    $row = lrgFacRow($id);
    if ($row === null) { return null; }
    if (function_exists('lrgDlgLayerIsOwnConfirmation') && lrgDlgLayerIsOwnConfirmation($t)) { return null; }
    $globs = array_map('strval', array_merge((array) ($row['recruiter_topics'] ?? []), (array) ($row['redirect_topics'] ?? [])));
    $norms = [];
    foreach ((array) ($row['entries'] ?? []) as $ln) { $n = lrgPromptNorm((string) $ln); if ($n !== '') { $norms[] = $n; } }
    $tiers = array_keys(lrgFacRoles($t));
    $extra = array_map('strval', (array) lrgFacCfg('entry_join_phrases', []));
    $vis = [];
    $cands = [];
    foreach ($pool as $e) {
        $e = (array) $e;
        if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
        $vis[] = $e;
        $topic = (string) ($e['topic'] ?? '');
        $norm = (string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? '')));
        if (($topic !== '' && $globs && lrgAnyGlob($globs, [$topic]) && lrgFacNormHasEntryWord($norm)) || ($norm !== '' && in_array($norm, $norms, true))
            || lrgFacEntryJoins((string) ($e['text'] ?? ''), $id, $tiers, $extra)) {
            $cands[(int) ($e['pos'] ?? -1) . '|' . $norm] = $e;
        }
    }
    if (count($cands) !== 1) { return null; }
    $e = array_values($cands)[0];
    $same = static fn(array $x): bool => (int) ($x['pos'] ?? -1) === (int) ($e['pos'] ?? -2) && (string) ($x['norm'] ?? '') === (string) ($e['norm'] ?? '');
    $sg = is_array($t['single'] ?? null) ? (array) $t['single'] : null;
    if ($sg && $same($sg)) { return null; }
    $pk = (array) (lrgDlgState((string) ($t['npc'] ?? ''))['parked'] ?? []);
    if ($pk && (string) ($pk['norm'] ?? '') === (string) ($e['norm'] ?? '') && lrgNow() <= (int) ($pk['expires'] ?? 0)) { return null; }
    if ($item !== null) {
        $a = trim($item) !== '' ? lrgFacAsk($item, $tiers) : [];
        $named = !empty($a['join']) && (string) ($a['faction'] ?? '') === $id;
        if (!$named && trim($item) !== '') {
            $m = lrgDlgMatchText($item, $vis);
            $named = $m !== null && $same((array) $vis[(int) $m['i']]) && (float) $m['score'] >= (float) lrgDlgCfg('match.min_score', 0.55);
        }
        if (!$named) { return null; }
    }
    return $e;
}

/**
 * [pt19h-quest / G18] Does this clause ask for THE WAY IN - "how do I join", "how would someone go about joining ...", "where do
 * I sign up", "where do I go to sign up for this vampire hunting thing" - rather than about joining ("why would I join", "what does
 * it take")? A how / where with a first-person or generic subject.
 */
function lrgFacWayIn(string $cl): bool
{
    return (bool) preg_match("/^\\W*(?:(?:so|well|uh|um|hey|and|ok|okay|then|now|sorry|say|excuse me)\\W+){0,3}(?:how|where)"
        . " (?:do|can|could|would|might|should|does|did) (?:i|we|one|someone|somebody|anyone|anybody|a person|a man|a woman|people)\\b/i", trim($cl));
}

/**
 * [pt19h-quest] Does this clause ASK, with its '?' dropped by the STT? A leading question word, or an auxiliary with its subject
 * after it ("do you think I should ...", "does this make me a bard", "can anyone ..."). An auxiliary with NO subject is his own
 * statement with the "I" dropped - "would like to join the Companions" (the owner's STT) asks nothing.
 */
function lrgFacClauseAsks(string $cl): bool
{
    $tok = array_map(static fn($w) => str_replace("'", '', (string) $w), lrgDlgTokens(lrgPromptNorm($cl)));
    $lead = ['so', 'well', 'uh', 'um', 'er', 'and', 'ok', 'okay', 'hey', 'oh', 'then', 'but', 'now', 'right', 'say', 'sorry'];
    while ($tok && in_array($tok[0], $lead, true)) { array_shift($tok); }
    if (!$tok) { return false; }
    $wh = ['what', 'whats', 'why', 'who', 'whos', 'whom', 'when', 'where', 'wheres', 'how', 'hows', 'which'];
    if (in_array($tok[0], $wh, true)) { return true; }
    $aux = ['do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'is', 'are', 'am', 'was', 'were',
        'have', 'has', 'had', 'dont', 'doesnt', 'didnt', 'cant', 'couldnt', 'wouldnt', 'shouldnt', 'wont', 'isnt', 'arent', 'wasnt', 'werent'];
    $subj = ['i', 'you', 'we', 'he', 'she', 'they', 'it', 'this', 'that', 'there', 'anyone', 'anybody', 'someone', 'somebody',
        'everyone', 'everybody', 'one', 'people', 'your', 'my', 'the', 'a', 'an'];
    return in_array($tok[0], $aux, true) && in_array((string) ($tok[1] ?? ''), $subj, true);
}

/**
 * The same hand-off on the want=1 FAST PATH (lrgDlgAnswerWant), which has no turn record: the player's last
 * utterance and the model's ask= are probed in that order.
 *   null  -> no enlistment asked: the existing matchers run
 *   false -> asked, nothing single to click: the caller returns (nothing is clicked)
 *   ['i' => index into $entries, 'utter' => the words that carried it]
 */
function lrgFacArbitrateWant(string $npc, array $entries, array $st, string $ask, string $cid)
{
    if (empty(lrgFacCfg('enabled', true))) { return null; }
    $snap = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
    $t = ['npc' => $npc, 'cid' => $cid, 'on' => true, 'entries' => $entries, 'tail' => [],
        'snap' => is_array($snap) ? $snap : [], 'q' => array_values(array_filter((array) ($st['q'] ?? [])))];
    $roles = lrgFacRoles($t);
    foreach ([(string) (((array) ($st['utter'] ?? []))['text'] ?? ''), $ask] as $k => $cand) {
        if (trim((string) $cand) === '') { continue; }
        $probe = lrgFacAsk((string) $cand, array_keys($roles));
        if (empty($probe['join']) || (string) $probe['faction'] === '') { continue; }
        // [pt19h-quest / G1, G5, G11] HIS words that refuse, defer, hedge, echo or ask ABOUT joining click nothing through the
        // hand-off ("no, i'm ready to take the Oath" and "not now, I'm ready to take the oath later" clicked the Oath line)
        // [pt19h-quest r2 / use P1-P3] judged clause by clause, her own join line exempt (lrgFacAskQualm)
        $qualm = $k === 0 ? lrgFacAskQualm((string) $cand, $probe, lrgFacOwnLines($t, (string) $probe['faction'], array_keys($roles))) : '';
        if ($qualm !== '') {
            // [pt19h-quest r2 / use P3] a hedge, an echo or a question that is no request, on a COMMIT join line: that line goes to the
            // fast path's commit rail, which clicks a commit only on his plain sentence (lrgDlgExplicit) - so it is handed on ONLY when
            // that very test says no here: nothing is clicked and the fast path's word is "the model asks, quoting it" (the gate then
            // parks it on the model's key or words). A plain join line, a refusal or a deferral: nothing, her words answer
            if (in_array($qualm, ['hedges', 'asks'], true) && function_exists('lrgDlgExplicit')) {
                $al = lrgFacAskLine($t, $entries, (string) $probe['faction']);
                if ($al !== null && (!empty($al['commit']) || (string) ($al['class'] ?? '') === 'commit')
                    && !lrgDlgExplicit(['speech' => true, 'npc' => $npc, 'cid' => $cid, 'entries' => $entries, 'tail' => [], 'crit' => 0,
                        'arrest' => '', 'facts' => (array) ($st['facts'] ?? [])], $al, (string) $cand, 'faction', $st)) {
                    foreach ($entries as $i => $e) {
                        if ((int) ($e['pos'] ?? -1) === (int) ($al['pos'] ?? -2) && (string) ($e['norm'] ?? '') === (string) ($al['norm'] ?? '')) {
                            lrgDlgLog(sprintf('faction npc=%s asked=%s - "%s" %s: the commit "%s" goes to the commit rail, never clicked on'
                                . ' these words - she asks, quoting it', $npc, (string) $probe['faction'], substr((string) $cand, 0, 50), $qualm,
                                substr((string) ($al['text'] ?? ''), 0, 40)), $cid);
                            return ['i' => (int) $i, 'utter' => (string) $cand];
                        }
                    }
                }
            }
            lrgDlgLog(sprintf('faction npc=%s asked=%s - "%s" %s (a refusal, deferral, hedge, echo or question about joining): the hand-off'
                . ' clicks nothing, her words answer', $npc, (string) $probe['faction'], substr((string) $cand, 0, 50), $qualm), $cid);
            return false;
        }
        $t['faction'] = ['asked' => (string) $probe['faction'], 'join' => 1,
            'role' => (string) ($roles[(string) $probe['faction']] ?? 'none')];
        $t['said'] = $k === 0 ? (string) $cand : '';
        $r = lrgFacArbitrate($t, $entries, '', $cid);
        // [pt19h-quest / G18] null = the hand-off stands aside (a line on her list is this enlistment in its own words, or his yes
        // answers her yes-line): the ordinary matchers run with every rail
        if ($r === null) { return null; }
        if (!is_array($r) || $r['entry'] === null) { return false; }
        foreach ($entries as $i => $e) {
            if ((int) ($e['pos'] ?? -1) === (int) ($r['entry']['pos'] ?? -2)
                && (string) ($e['norm'] ?? '') === (string) ($r['entry']['norm'] ?? '')) {
                return ['i' => (int) $i, 'utter' => (string) $cand];
            }
        }
        return false;
    }
    return null;
}

// ================================================================== the turn log line
/**
 * " faction=<id|?>:<role>[:carried][:road=closed|unknown][:qe=<state>[:licensed]][:executed=<Q>:<S>]" or ''.
 * [pt18-quest] road = the Helgen gate as read this turn (open is silent); qe = the click-free plan's state
 * (queued / closed / unknown / stale / no-stage / already / pending / engine-on / closed-intro); executed = the words
 * lane's flag (research/pt18-words.md 4.4), set only on a licensed queue.
 */
function lrgFacTurnTail(array $t): string
{
    $f = (array) ($t['faction'] ?? []);
    // [pt19h-quest] " qe=<row>:<state>" on a quest-entry TABLE turn
    if (!empty($f['qe'])) { return ' qe=' . (string) ((($f['qe'] ?? [])['row'] ?? '?')) . ':' . (string) ((($f['plan'] ?? [])['state'] ?? '-')); }
    if (empty($f['join'])) { return ''; }
    $id = (string) ($f['asked'] ?? '');
    if ($id === '') { $id = ($f['ambiguous'] ?? []) ? implode('|', (array) $f['ambiguous']) : '?'; }
    $tail = ' faction=' . $id . ':' . (string) ($f['role'] ?? 'none') . (!empty($f['carried']) ? ':carried' : '');
    $road = (string) ((($f['road'] ?? [])['state'] ?? ''));
    if ($road === 'closed' || $road === 'unknown') { $tail .= ':road=' . $road; }
    $plan = (array) ($f['plan'] ?? []);
    $ps = (string) ($plan['state'] ?? '');
    if ($ps !== '') { $tail .= ':qe=' . $ps . (!empty($plan['licensed']) ? ':licensed' : ''); }
    $ex = (array) ($f['executed'] ?? []);
    if ((string) ($ex['quest'] ?? '') !== '' && isset($ex['stage'])) { $tail .= ':executed=' . (string) $ex['quest'] . ':' . (int) $ex['stage']; }
    return $tail;
}

// ================================================================== [pt18-quest] the road, and the click-free entry
/**
 * THE ROAD to a recruiter's join line on this load order: ['state' => open|closed|unknown, 'src', 'at', 'line', 'quest'].
 * The row's `closed[<name>]` names the quest that must be COMPLETE first (MQ101 - Alternate Perspective's Helgen) and
 * its completing stage (900). Sources, this NPC's facts line first: `<quest>c=` (mq101c, 1 / 0, when the game sends it),
 * else `qst=` <quest>:<stage> against complete_stage, else `mqq=` >= 7 (AP's untouched start global = incomplete);
 * then another recruiter's cached facts line (quest stages are global) within quest_entry.cache_seconds. Nothing
 * known = unknown: nothing is asserted open, and nothing is ever SENT on an unknown (the refuters' fail-closed rule).
 */
function lrgFacRoad(array $t, array $row, string $name): array
{
    $c = (array) ((($row['closed'] ?? [])[$name] ?? []));
    $quest = (string) ($c['quest'] ?? '');
    if ($c === [] || $quest === '') { return ['state' => 'open', 'src' => 'no-rule', 'at' => 0, 'line' => '', 'quest' => '']; }
    $done = (int) ($c['complete_stage'] ?? 900);
    $now = lrgNow();
    $max = max(60, (int) lrgFacCfg('quest_entry.cache_seconds', 1800));
    $read = static function (array $f, string $src) use ($quest, $done, $now, $max): ?array {
        $at = (int) ($f['at'] ?? 0);
        if ($at > 0 && $now - $at > $max) { return null; }          // too old to trust either way
        $ck = strtolower($quest) . 'c';
        if (isset($f[$ck]) && (string) $f[$ck] !== '' && (string) $f[$ck] !== '-') {
            return ['state' => (int) $f[$ck] === 1 ? 'open' : 'closed', 'src' => $src . ':' . $ck, 'at' => $at];
        }
        $qst = (array) ($f['qst'] ?? []);
        if (isset($qst[$quest])) { return ['state' => (int) $qst[$quest] >= $done ? 'open' : 'closed', 'src' => $src . ':qst', 'at' => $at]; }
        if ($quest === 'MQ101' && isset($f['mqq']) && (string) $f['mqq'] !== '' && (string) $f['mqq'] !== '-' && (int) $f['mqq'] >= 7) {
            return ['state' => 'closed', 'src' => $src . ':mqq', 'at' => $at];
        }
        return null;
    };
    $r = $read((array) ($t['facts'] ?? []), 'facts');
    if ($r === null && function_exists('lrgDlgState')) {
        foreach (array_keys((array) ($row['recruiters'] ?? [])) as $rn) {
            if (strcasecmp((string) $rn, $name) === 0) { continue; }
            $r = $read((array) (lrgDlgState((string) $rn)['facts'] ?? []), 'cache:' . (string) $rn);
            if ($r !== null) { break; }
        }
    }
    if ($r === null) { $r = ['state' => 'unknown', 'src' => 'none', 'at' => 0]; }
    $r['line'] = str_replace(['<', '>'], '', trim((string) ($c['line'] ?? '')));
    $r['quest'] = $quest;
    return $r;
}

/**
 * THE CLICK-FREE PLAN for this ask, decided BEFORE the LLM from the player's own words (research/pt18-quest.md 4c,
 * as rebuilt after the refuters). [] when the row has no `effects` entry for her (honestly unknown: words only, as
 * today) or the ask is not hers to answer this turn; else a plan whose `state` is one of
 *   queued       everything checked out: lrgFacQuestNet appends ONE ExtCmdLRG_QuestEntry line in the post-gate.
 *                `licensed` = every server-checkable condition passed on a fresh facts line - then `executed` is set
 *                pre-LLM and the game's OK stays quiet; unlicensed (a must-not-be-done quest is not on the facts line)
 *                = the game decides and its OK is VOICED. Never both.
 *   closed       the road runs through Helgen first (lrgFacRoad) - the Helgen line, nothing sent
 *   unknown      the road is not known - nothing is asserted, nothing sent (fail closed)
 *   engine-on    CHIM's own quest engine is on: STAND DOWN
 *   stale        no facts line younger than quest_entry.require_facts_fresh
 *   no-stage     the quest is not on the facts line
 *   already      the quest is at or past the stage (facts line, or our own OK that came back after it)
 *   pending      the same entry went out inside quest_entry.pending_seconds and has not answered
 *   closed-intro a must-not-be-done quest:stage IS done on the facts line
 *   open-first   [pt19 v1.0 / S2.3] an ambient actor the game may open on: the open (lrgDlgPreOpen / lrgDlgMaybeOpen) brings
 *                her list and the real line wins - nothing is sent; the entry only when lrgFacOpenImpossible says why not
 * click-wins (ml=1, not an ambient actor, the entry on her list) returns [] - lrgFacArbitrate owns the real click.
 */
function lrgFacQuestPlan(array $t, array $f): array
{
    if (empty(lrgFacCfg('quest_entry.enabled', true))) { return []; }
    if (empty($f['join']) || (string) ($f['asked'] ?? '') === '' || !empty($f['carried']) || empty($t['speech'])) { return []; }
    if ((string) ($f['role'] ?? '') !== 'recruiter') { return []; }
    // [pt19c fixer / adversarial QA @adv_bargain] a BARGAIN is no enlistment (S6.1: the reward is fixed, nothing may be promised):
    // "I'll enlist if you pay me three hundred septims" starts no quest - the click-free entry never goes out on a condition the
    // game will never honour; her words answer (an open may still show his list: the real line commits nothing by itself)
    $said = (string) (((array) (lrgDlgState((string) ($t['npc'] ?? ''))['utter'] ?? []))['text'] ?? '');
    if (function_exists('lrgDlgBargains') && lrgDlgBargains($said)) {
        lrgDlgLog('faction npc=' . (string) ($t['npc'] ?? '') . ' asked=' . (string) $f['asked'] . ' -> no quest entry: "'
            . substr($said, 0, 50) . '" bargains (a condition or a sum) - her words answer', (string) ($t['cid'] ?? ''));
        return [];
    }
    // [pt19h-quest / G1, G11] ... and neither is a refusal, a deferral, a hedge or an echo: "not now, I want to join the Legion
    // later" queues nothing (lrgFacAskActs, decided in lrgFacTurn on his words this turn)
    if (array_key_exists('acts', $f) && empty($f['acts'])) {
        lrgDlgLog('faction npc=' . (string) ($t['npc'] ?? '') . ' asked=' . (string) $f['asked'] . ' -> no quest entry: his words refuse, defer,'
            . ' hedge, echo or ask about joining - her words answer', (string) ($t['cid'] ?? ''));
        return [];
    }
    $id = (string) $f['asked'];
    $row = lrgFacRow($id);
    if ($row === null) { return []; }
    $npc = (string) ($t['npc'] ?? '');
    $cid = (string) ($t['cid'] ?? '');
    $name = lrgFacName($npc);
    $eff = (array) ((($row['effects'] ?? [])[$name] ?? []));
    $quest = (string) ($eff['quest'] ?? '');
    $stage = (int) ($eff['stage'] ?? 0);
    if ($eff === [] || $quest === '' || $stage <= 0) { return []; }
    $plan = ['state' => '', 'why' => '', 'licensed' => 0, 'quest' => $quest, 'stage' => $stage,
        'entry' => (string) ($eff['entry'] ?? ''), 'meaning' => str_replace(['<', '>'], '', (string) ($eff['meaning'] ?? '')),
        'say' => (string) ($eff['say'] ?? ''), 'hint' => (string) ($eff['hint'] ?? ''), 'param' => '', 'x' => '', 'road' => ''];
    $log = static function (string $state, string $why) use (&$plan, $npc, $id, $quest, $stage, $cid): array {
        $plan['state'] = $state;
        $plan['why'] = $why;
        lrgDlgLog(sprintf('faction npc=%s asked=%s role=recruiter quest=%s stage=%d entry=%s -> %s', $npc, $id, $quest, $stage,
            $state, $why), $cid);
        return $plan;
    };
    $road = (array) ($f['road'] ?? []);
    if ($road === []) { $road = lrgFacRoad($t, $row, $name); }
    $plan['road'] = (string) ($road['state'] ?? '');
    if ($plan['road'] === 'closed') { return $log('closed', 'the road runs through Helgen first (' . (string) ($road['src'] ?? '-') . ') - nothing is sent'); }
    if ($plan['road'] !== 'open') {
        return $log('unknown', 'the game has not said whether ' . (string) ($road['quest'] ?? '?') . ' is complete - nothing is sent on an unknown');
    }
    if (function_exists('lrgDlgQuestEngineOn') && lrgDlgQuestEngineOn()) { return $log('engine-on', 'STAND DOWN: CHIM AI Quest Progression is on'); }
    $entry = (string) $plan['entry'];
    // [pt19 v1.0 / S2.3] ONE changed condition. "The driver cannot click" = ml=0, OR the entry is not on her cached list, OR
    // (an ambient actor AND the open is not possible - lrgFacOpenImpossible). An ambient actor the game may open on is OPENED
    // first (her list comes up and lrgFacArbitrate clicks the real line); 10.26's click-free entry is the fallback only when
    // the open cannot happen (`open_refused` from the do=open funcret, lib/lrg_actions.php).
    $cant = '';
    if (lrgFacMenuless($npc) && $entry !== '') {
        $onList = lrgFacListHasTopic($t, [$entry]);
        if (empty($t['ambient'])) {
            if ($onList) {
                $log('click-wins', 'her list carries the entry and the driver can click - lrgFacArbitrate owns it');
                return [];
            }
        } else {
            $cant = lrgFacOpenImpossible($t);
            if ($cant === '' && ($onList || !lrgFacTopics($t))) {
                return $log('open-first', 'an ambient actor the game may open on - the open brings her list and the real line'
                    . ' wins; the click-free entry only when the open cannot happen');
            }
        }
    }
    $facts = (array) ($t['facts'] ?? []);
    $fat = (int) ($facts['at'] ?? 0);
    $fresh = max(30, (int) lrgFacCfg('quest_entry.require_facts_fresh', 300));
    $now = lrgNow();
    if ($fat <= 0 || $now - $fat > $fresh) {
        return $log('stale', 'no facts line younger than ' . $fresh . ' s (' . ($fat > 0 ? ($now - $fat) . ' s old' : 'none') . ') - nothing is sent');
    }
    $qst = (array) ($facts['qst'] ?? []);
    // the freshest stage: the facts line, or our own OK that came back after it (exec_qst, the words lane's contract)
    $cur = isset($qst[$quest]) ? (int) $qst[$quest] : -1;
    $st = function_exists('lrgDlgState') ? (array) lrgDlgState($npc) : [];
    $ex = (array) ($st['exec_qst'] ?? []);
    // >= : an OK that came back in the same second as the facts line is the later event (the line precedes the turn)
    if ((string) ($ex['quest'] ?? '') === $quest && (int) ($ex['at'] ?? 0) >= $fat) { $cur = max($cur, (int) ($ex['stage'] ?? 0)); }
    if ($cur < 0) { return $log('no-stage', $quest . ' is not on the facts line (qst=) - nothing is sent'); }
    $maxStage = (int) ((($eff['conds'] ?? [])['max'] ?? ($stage - 1)));
    // [pt18-quest review] `cur` rides on 'already' so the locked line can tell "at that stage" from "past it" (E7)
    $plan['cur'] = $cur;
    if ($cur > $maxStage || $cur >= $stage) { return $log('already', $quest . ' is at stage ' . $cur . ' already - nothing is sent'); }
    $pend = (array) ($st['facexec'] ?? []);
    if ((string) ($pend['quest'] ?? '') === $quest && (int) ($pend['stage'] ?? 0) === $stage && empty($pend['done'])
        && $now - (int) ($pend['at'] ?? 0) <= max(10, (int) lrgFacCfg('quest_entry.pending_seconds', 120))) {
        return $log('pending', 'the same entry went out ' . ($now - (int) ($pend['at'] ?? 0)) . ' s ago (cid ' . (string) ($pend['cid'] ?? '-')
            . ') and has not answered - not sent again');
    }
    // quest:stage pairs that must NOT be done: known and done = closed; known and clear = part of the licence;
    // not on the facts line = the game decides on the live form, and its OK is voiced (no licence)
    $licensed = true;
    foreach ((array) ((($eff['conds'] ?? [])['qnd'] ?? [])) as $q2 => $s2) {
        if (!isset($qst[(string) $q2])) { $licensed = false; continue; }
        if ((int) $qst[(string) $q2] >= (int) $s2) {
            return $log('closed-intro', (string) $q2 . ' is at stage ' . (int) $qst[(string) $q2] . ' (stage ' . (int) $s2 . ' done) - this line of hers is closed');
        }
    }
    $plan['licensed'] = $licensed ? 1 : 0;
    $plan['x'] = substr(md5(uniqid((string) $now, true)), 0, 10);
    $plan['param'] = lrgFacQuestParam($eff, $npc, $cid, $plan['x']);
    $how = !lrgFacMenuless($npc) ? 'the driver cannot click (ml=0)'
        : ($cant !== '' ? 'an ambient actor and the open cannot happen: ' . $cant : 'the entry is not on her list');
    return $log('queued', ($licensed ? 'licensed (every condition passed on fresh facts: executed is set, the OK stays quiet)'
        : 'unlicensed (a must-not-be-done quest is not on the facts line: the game decides, its OK is voiced)') . ' - ' . $how);
}

/**
 * [pt19 v1.0 / S2.3] '' when the game may open this NPC's list for the ask, else why not: bIntentOpen off (io), the game
 * refused the last do=open for her within open.refused_seconds (lrgDlgOpenRefused - written by the funcret handler for the
 * OpenBlockedReason / FailOpen reasons only, model F4), or no click is verified on this install yet (the pre-LLM open of a
 * join line waits for the first verified click - F19 - so the open would bring nothing and the entry must carry the ask).
 */
function lrgFacOpenImpossible(array $t): string
{
    $npc = (string) ($t['npc'] ?? '');
    if (function_exists('lrgDlgMcm') && !lrgDlgMcm('io', !empty(lrgDlgCfg('intent_open', true)) ? 1 : 0, $npc)) { return 'io off (bIntentOpen)'; }
    $ref = function_exists('lrgDlgOpenRefused') ? lrgDlgOpenRefused(lrgDlgState($npc)) : [];
    if ($ref) { return 'the game refused the last open (' . substr((string) ($ref['why'] ?? '?'), 0, 60) . ')'; }
    if ((int) ($t['clicks_ok'] ?? 1) < 1 && !empty(lrgDlgCfg('session.stage_rail', true))) { return 'no click is verified on this install yet'; }
    // [pt19c-B fix 1 / game, ai 4, use P3 reviews] lrgDlgPreOpen's OWN stand-downs, the same reads (no native, no new state):
    // "open first" must never leave a join ask with neither carrier. Beyond open.max_distance on a fresh snapshot, or in a fight,
    // the game refuses the open (OpenBlockedReason: "too far apart", "combat"); quiet mode, a voice order (10.27) and an escort
    // order (10.20) own the sentence - so the click-free entry carries the FIRST ask, as it did before v1.0.
    $snap = is_array($t['snap'] ?? null) ? (array) $t['snap'] : [];
    if ($snap && (int) ($snap['_age'] ?? 9999) <= 30 && isset($snap['dist']) && (string) $snap['dist'] !== ''
        && (int) $snap['dist'] > (int) lrgDlgCfg('open.max_distance', 200)) { return 'too far apart'; }
    if ($snap && (string) ($snap['combat'] ?? '0') === '1'
        && (int) ($snap['_age'] ?? 9999) <= max(5, (int) lrgDlgCfg('services.direct.combat_max_age_seconds', 90))) { return 'combat'; }
    if (function_exists('lrgDlgQuietOn') && lrgDlgQuietOn($npc)) { return 'quiet mode'; }
    if (function_exists('lrgMktState') && defined('LRG_MKT_ACTIVE') && in_array(lrgMktState($t), LRG_MKT_ACTIVE, true)) { return 'a voice order owns the turn'; }
    $u = (string) ((((array) (lrgDlgState($npc)['utter'] ?? []))['text']) ?? '');
    if ($u !== '' && function_exists('lrgIntentEscort') && lrgIntentEscort($u) !== null) { return 'an escort order owns the sentence'; }
    return '';
}

/** The ExtCmdLRG_QuestEntry parameter (PROTOCOL 10.26): fixed keys first, the cond keys only when the effect names them. */
function lrgFacQuestParam(array $eff, string $npc, string $cid, string $x): string
{
    $c = (array) ($eff['conds'] ?? []);
    $kv = ['ok' => 1, 'cid' => $cid !== '' ? $cid : 'dlg', 'npc' => $npc, 'quest' => (string) ($eff['quest'] ?? ''), 'stage' => (int) ($eff['stage'] ?? 0)];
    if ((int) ($c['isid'] ?? 0) > 0) { $kv['isid'] = (int) $c['isid']; }
    if ((array) ($c['notdone'] ?? [])) { $kv['notdone'] = implode(',', array_map('intval', (array) $c['notdone'])); }
    if (isset($c['max'])) { $kv['max'] = (int) $c['max']; }
    if ((array) ($c['qdone'] ?? [])) { $kv['qdone'] = implode(',', array_map('strval', (array) $c['qdone'])); }
    $qnd = [];
    foreach ((array) ($c['qnd'] ?? []) as $q => $s) { $qnd[] = (string) $q . ':' . (int) $s; }
    if ($qnd) { $kv['qnd'] = implode(',', $qnd); }
    $kv['entry'] = (string) ($eff['entry'] ?? '');
    $kv['hint'] = substr((string) ($eff['hint'] ?? ''), 0, 100);
    $kv['x'] = $x;
    $kv['z'] = 1;
    return lrgKv($kv);
}

/**
 * THE NET (called from lrgPostProcessActions in lib/lrg_actions.php, after the model's own lines): a queued plan
 * becomes ONE `<npc>|command|ExtCmdLRG_QuestEntry@<param>` line - D1 only, the escort pattern; no D2 row, so the
 * game's de-dupe ring (4 entries, 5 s) is never asked to catch a second delivery. Records `facexec` (the pending
 * guard) in Phase 2's state and the `sent` entry + `qe_lic` (licensed or not) in lrg_memory for lrgFuncretVerdict.
 */
function lrgFacQuestNet(array $out): array
{
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($t) || empty($t['on'])) { return $out; }
    $plan = (array) ((($t['faction'] ?? [])['plan'] ?? []));
    if ((string) ($plan['state'] ?? '') !== 'queued' || trim((string) ($plan['param'] ?? '')) === '') { return $out; }
    $npc = (string) ($t['npc'] ?? '');
    $cid = (string) ($t['cid'] ?? '');
    if ($npc === '' || !empty($GLOBALS['LRG_FAC_QE_SENT'][$cid])) { return $out; }   // once per request
    $GLOBALS['LRG_FAC_QE_SENT'][$cid] = 1;
    $out = array_values($out);
    $out[] = $npc . '|command|' . LRG_ACT_QUESTENTRY . '@' . (string) $plan['param'] . "\r\n";
    $now = lrgNow();
    $rec = ['quest' => (string) $plan['quest'], 'stage' => (int) $plan['stage'], 'at' => $now, 'cid' => $cid,
        'x' => (string) ($plan['x'] ?? ''), 'lic' => !empty($plan['licensed']) ? 1 : 0, 'done' => 0];
    lrgDlgPut($npc, ['facexec' => $rec]);
    if (function_exists('lrgNoteSent')) {
        // [pt19h-quest] table=1 marks a quest-entry TABLE row: the pre-lock funcret (lrg_factions.php not loaded) words its
        // refusal as a quest step, not an enlistment (lrgVoicedWhat)
        lrgNoteSent($t, LRG_ACT_QUESTENTRY, ['do' => 'entry'], 'speech', $cid,
            ['qe_lic' => $rec + ['say' => (string) ($plan['say'] ?? ''), 'meaning' => (string) ($plan['meaning'] ?? ''), 'table' => !empty($plan['table']) ? 1 : 0]]);
    }
    lrgDlgLog(sprintf('faction net npc=%s %s appended (D1 only): quest=%s stage=%d entry=%s licensed=%d x=%s', $npc,
        LRG_ACT_QUESTENTRY, (string) $plan['quest'], (int) $plan['stage'], (string) ($plan['entry'] ?? '-'),
        !empty($plan['licensed']) ? 1 : 0, (string) ($plan['x'] ?? '')), $cid);
    return $out;
}

/**
 * The effect row (plus 'row' => id, 'name' => recruiter) whose quest / stage these are, or [] (the funcret side).
 * [pt19h-quest] ... then the quest-entry TABLE's rows ('row' => 'qe:<id>', 'name' => the speaker, 'table' => 1).
 */
function lrgFacEffectFor(string $quest, int $stage): array
{
    foreach ((array) lrgFacCfg('rows', []) as $id => $row) {
        foreach ((array) (((array) $row)['effects'] ?? []) as $name => $eff) {
            if ((string) (((array) $eff)['quest'] ?? '') === $quest && (int) (((array) $eff)['stage'] ?? 0) === $stage) {
                return (array) $eff + ['row' => (string) $id, 'name' => (string) $name];
            }
        }
    }
    foreach (lrgFacQeRows() as $r) {
        if (strcasecmp((string) ($r['quest'] ?? ''), $quest) === 0 && (int) ($r['stage'] ?? 0) === $stage) {
            return $r + ['row' => 'qe:' . (string) ($r['id'] ?? ''), 'name' => (string) ($r['npc'] ?? ''), 'table' => 1];
        }
    }
    return [];
}

// ================================================================== [pt19h-quest] the click-free quest-entry TABLE
/**
 * [pt19h-quest / G17] The rows of config/lrg_quest_entries.default.json (PROTOCOL 10.26, extended): the same ExtCmdLRG_QuestEntry
 * for a scripted quest line whose whole fragment is Quest.SetStage(n), beyond the enlistment rows. A row ships only when every
 * key of its `conds` is one the game re-checks today (LRG_Main.CmdQuestEntry: isid, qdone, notdone, max, qnd), isid names the
 * speaker, max is below the stage (never lower a quest) and every accepted sentence has three words or more; anything else is
 * skipped whole and logged once. Each row gains `_norms` (its line and `words`, as lrgFacQeNorm reads them). Cached per request;
 * the offline seam $GLOBALS['LRG_FAC_QE_TEST_ROWS'] replaces the file (never cached over).
 */
function lrgFacQeRows(): array
{
    $seam = $GLOBALS['LRG_FAC_QE_TEST_ROWS'] ?? null;
    static $cache = null;
    if (!is_array($seam) && $cache !== null) { return $cache; }
    $src = is_array($seam) ? ['rows' => $seam]
        : json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_quest_entries.default.json'), true);
    $keys = ['isid', 'qdone', 'notdone', 'max', 'qnd'];
    $out = [];
    foreach ((array) ((is_array($src) ? $src : [])['rows'] ?? []) as $r) {
        if (!is_array($r)) { continue; }
        $c = (array) ($r['conds'] ?? []);
        $stage = (int) ($r['stage'] ?? 0);
        $why = '';
        if (trim((string) ($r['npc'] ?? '')) === '' || trim((string) ($r['quest'] ?? '')) === '' || $stage <= 0) { $why = 'no npc / quest / stage'; }
        elseif (array_diff(array_keys($c), $keys)) { $why = 'a condition the game cannot re-check (' . implode(',', array_diff(array_keys($c), $keys)) . ')'; }
        elseif ((int) ($c['isid'] ?? 0) <= 0) { $why = 'no isid (the speaker is not pinned)'; }
        elseif (!isset($c['max']) || (int) $c['max'] <= 0 || (int) $c['max'] >= $stage) { $why = 'max must be 1..stage-1 (never lower a quest)'; }
        $norms = [];
        foreach (array_merge([(string) ($r['line'] ?? '')], array_map('strval', (array) ($r['words'] ?? []))) as $w) {
            $n = lrgFacQeNorm($w, (string) ($r['npc'] ?? ''));
            if (count(lrgDlgTokens($n)) >= 3) { $norms[$n] = 1; }
        }
        if ($why === '' && !$norms) { $why = 'no sentence of three words or more'; }
        if ($why !== '') {
            if (!is_array($seam) && function_exists('lrgDlgLog')) { lrgDlgLog('questentry table: row ' . (string) ($r['id'] ?? '?') . ' skipped - ' . $why, ''); }
            continue;
        }
        $r['_norms'] = array_keys($norms);
        $out[] = $r;
    }
    if (!is_array($seam)) { $cache = $out; }
    return $out;
}

/**
 * [pt19h-quest] His sentence (or a row's) as the table compares it: lrgDlgStripVocative, lrgPromptNorm, then up to four leading
 * fillers / her name / a title and up to three trailing ones stripped. NEVER a negator, "wait", "maybe" or a question word - those
 * stay and break the equality.
 */
function lrgFacQeNorm(string $s, string $npc = ''): string
{
    if (function_exists('lrgDlgStripVocative')) { $s = lrgDlgStripVocative($s, $npc); }
    $tok = lrgDlgTokens(lrgPromptNorm($s));
    $name = $npc !== '' ? lrgDlgTokens(lrgPromptNorm(lrgFacName($npc))) : [];
    $title = ['master', 'sir', 'friend', 'elder', 'lord', 'jarl', 'brother', 'sister', 'general', 'captain', 'legate'];
    $lead = array_merge(['uh', 'um', 'er', 'erm', 'hmm', 'well', 'so', 'ok', 'okay', 'alright', 'right', 'look', 'listen', 'hey', 'oh',
        'now', 'and'], $name, $title);
    $tail = array_merge(['please', 'then', 'now'], $name, $title);
    for ($i = 0; $i < 4 && $tok && in_array($tok[0], $lead, true); $i++) { array_shift($tok); }
    for ($i = 0; $i < 3 && $tok && in_array($tok[count($tok) - 1], $tail, true); $i++) { array_pop($tok); }
    return implode(' ', $tok);
}

/**
 * [pt19h-quest] The row his words ARE, or ''. His words after lrgFacQeNorm must EQUAL the line or one of its `words` - never
 * containment, never similarity; and never: an echo (a trailing '?' on a line that asks nothing), a question shape on a statement
 * line, a refusal on a line that refuses nothing, a bargain.
 */
function lrgFacQeMatch(string $utter, array $row, string $npc): string
{
    $utter = trim($utter);
    if ($utter === '') { return ''; }
    $u = lrgFacQeNorm($utter, $npc);
    if ($u === '' || !in_array($u, (array) ($row['_norms'] ?? []), true)) { return ''; }
    $line = trim((string) ($row['line'] ?? ''));
    $lineAsks = (bool) preg_match('/\?\s*["\')\]]*\s*$/', $line);
    if (preg_match('/\?\s*["\')\]]*\s*$/', $utter) && !$lineAsks) { return ''; }                       // an echo: "wait, ... ?" / "... ?"
    if (!$lineAsks && lrgFacClauseAsks($utter) && !lrgFacClauseAsks($line)) { return ''; }                  // "is it true that ..."
    if (function_exists('lrgDlgRefuses') && lrgDlgRefuses($utter) && !lrgDlgRefuses($line)) { return ''; }
    if (function_exists('lrgDlgBargains') && lrgDlgBargains($utter)) { return ''; }
    return $u;
}

/**
 * [pt19h-quest / G17] THE TABLE'S PLAN for his words this turn, decided BEFORE the LLM (lrgFacTurn, when no enlistment was asked).
 * [] = no row of hers is what he said, or the driver can click (then the real line wins and this turn is an ordinary one):
 *   a menu of hers is open (the list is on his screen); menuless on and she is not an ambient scene actor (the E-press / the pre-LLM
 *   open carry the real line); menuless on, ambient, and the open is possible (open-first); a voice order or an escort owns the
 *   sentence. Else a faction-shaped record ['asked' => '', 'join' => 0, 'qe' => [row, line, npc], 'plan' => [...]] whose plan state is
 *   queued | engine-on | quiet | stale | no-stage | already | pending | closed-intro - lrgFacQuestNet sends ONE ExtCmdLRG_QuestEntry
 *   (D1 only) on `queued`, lrgFacQeLockedLines gives every state its line (never silent). NEVER licensed: `executed` is never set, the
 *   model is told the game has NOT confirmed it, and the game's OK is voiced from the row's meaning / say (lrgQuestEntryResult).
 */
function lrgFacQeTurn(array $t, string $utter): array
{
    if (empty(lrgFacCfg('quest_entry.enabled', true)) || empty(lrgFacCfg('quest_entry.table', true))) { return []; }
    if (empty($t['on']) || empty($t['speech']) || trim($utter) === '') { return []; }
    $npc = (string) ($t['npc'] ?? '');
    $name = lrgFacName($npc);
    if ($name === '') { return []; }
    $cid = (string) ($t['cid'] ?? '');
    $hits = [];
    foreach (lrgFacQeRows() as $r) {
        if (strcasecmp((string) ($r['npc'] ?? ''), $name) !== 0) { continue; }
        if (lrgFacQeMatch($utter, $r, $npc) !== '') { $hits[] = $r; }
    }
    if (!$hits) { return []; }
    $log = static function (array $r, string $state, string $why) use ($npc, $cid): void {
        lrgDlgLog(sprintf('questentry table npc=%s row=%s quest=%s stage=%d -> %s (%s)', $npc, (string) ($r['id'] ?? '?'),
            (string) ($r['quest'] ?? ''), (int) ($r['stage'] ?? 0), $state, $why), $cid);
    };
    // the driver can click: the real line wins, this is an ordinary turn
    $r0 = $hits[0];
    if (!empty($t['open'])) { $log($r0, 'menu', 'a menu of hers is open - the list on his screen carries the line'); return []; }
    $ml = lrgFacMenuless($npc);
    $cant = '';
    if ($ml) {
        if (empty($t['ambient'])) {
            $log($r0, 'menu', 'menuless on and she is no ambient scene actor - the E-press or the open carries the real line');
            return [];
        }
        $cant = lrgFacOpenImpossible($t);
        if (in_array($cant, ['a voice order owns the turn', 'an escort order owns the sentence'], true)) { $log($r0, 'busy', $cant); return []; }
        // [pt19h-quest r2 / arch P3, S2.3] OPEN FIRST only when the open really brings THIS line: her cached root list carries it, or no
        // root of hers is known yet and the line is a top-level one the pre-LLM open's own clause 4 fires on (lrgFacQeOpenBrings). A
        // line below a top-level topic (MQ301's lure lines, Esbern's plan, Paarthurnax's "where") is on no list an open brings, and
        // nothing else would open for it - the table carries it, one carrier (lrgFacRefusesOpen and the pre-LLM interlock stand down)
        if ($cant === '') {
            $brings = lrgFacQeOpenBrings($t, $r0);
            if ($brings !== '') { $log($r0, 'open-first', 'an ambient actor the game may open on - ' . $brings); return []; }
            $cant = 'the open cannot bring this line (' . (!empty($r0['top']) ? 'her known list does not carry it, or no open clause fires on it'
                : 'it sits below a top-level topic of hers') . ')';
        }
    }
    $rec = static function (array $r, array $plan) use ($npc): array {
        return ['asked' => '', 'join' => 0, 'role' => 'none', 'phrase' => '', 'word' => '', 'ambiguous' => [], 'carried' => 0,
            'at' => lrgNow(), 'executed' => [], 'road' => [], 'qe' => ['row' => (string) ($r['id'] ?? ''), 'line' => (string) ($r['line'] ?? ''),
            'npc' => $npc], 'plan' => $plan];
    };
    $base = static function (array $r, string $state, string $why): array {
        return ['state' => $state, 'why' => $why, 'licensed' => 0, 'quest' => (string) ($r['quest'] ?? ''), 'stage' => (int) ($r['stage'] ?? 0),
            'entry' => (string) ($r['entry'] ?? ''), 'meaning' => str_replace(['<', '>'], '', (string) ($r['meaning'] ?? '')),
            'say' => (string) ($r['say'] ?? ''), 'hint' => (string) ($r['hint'] ?? ''), 'param' => '', 'x' => '', 'road' => 'open', 'table' => 1];
    };
    if (function_exists('lrgDlgQuestEngineOn') && lrgDlgQuestEngineOn()) {
        $log($r0, 'engine-on', 'STAND DOWN: CHIM AI Quest Progression is on');
        return $rec($r0, $base($r0, 'engine-on', 'CHIM AI Quest Progression is on'));
    }
    if (function_exists('lrgDlgQuietOn') && lrgDlgQuietOn($npc)) {
        $log($r0, 'quiet', 'quiet mode: the glue touches no quest while a scripted intro runs');
        return $rec($r0, $base($r0, 'quiet', 'quiet mode'));
    }
    $facts = (array) ($t['facts'] ?? []);
    $fat = (int) ($facts['at'] ?? 0);
    $fresh = max(30, (int) lrgFacCfg('quest_entry.require_facts_fresh', 300));
    $now = lrgNow();
    if ($fat <= 0 || $now - $fat > $fresh) {
        $log($r0, 'stale', 'no facts line younger than ' . $fresh . ' s - nothing is sent');
        return $rec($r0, $base($r0, 'stale', 'no fresh facts line'));
    }
    $qst = (array) ($facts['qst'] ?? []);
    $st = function_exists('lrgDlgState') ? (array) lrgDlgState($npc) : [];
    $ex = (array) ($st['exec_qst'] ?? []);
    $pend = (array) ($st['facexec'] ?? []);
    $best = null;
    foreach ($hits as $r) {
        $q = (string) $r['quest'];
        $stage = (int) $r['stage'];
        $c = (array) ($r['conds'] ?? []);
        $cur = isset($qst[$q]) ? (int) $qst[$q] : -1;
        if ((string) ($ex['quest'] ?? '') === $q && (int) ($ex['at'] ?? 0) >= $fat) { $cur = max($cur, (int) ($ex['stage'] ?? 0)); }
        if ($cur < 0) { $cand = [$r, 'no-stage', $q . ' is not on the facts line (qst=) - nothing is sent', -1]; }
        elseif ($cur >= $stage || $cur > (int) $c['max']) { $cand = [$r, 'already', $q . ' is at stage ' . $cur . ' already - nothing is sent', $cur]; }
        elseif ((string) ($pend['quest'] ?? '') === $q && (int) ($pend['stage'] ?? 0) === $stage && empty($pend['done'])
            && $now - (int) ($pend['at'] ?? 0) <= max(10, (int) lrgFacCfg('quest_entry.pending_seconds', 120))) {
            $cand = [$r, 'pending', 'the same entry went out ' . ($now - (int) ($pend['at'] ?? 0)) . ' s ago and has not answered', $cur];
        } else {
            $cand = [$r, 'queued', 'every server check passed on fresh facts - the game re-checks the rest', $cur];
            foreach ((array) ($c['qnd'] ?? []) as $q2 => $s2) {
                if (isset($qst[(string) $q2]) && (int) $qst[(string) $q2] >= (int) $s2) {
                    $cand = [$r, 'closed-intro', (string) $q2 . ' is at stage ' . (int) $qst[(string) $q2] . ' - this line is closed', $cur];
                    break;
                }
            }
        }
        $rank = ['queued' => 0, 'pending' => 1, 'already' => 2, 'closed-intro' => 3, 'no-stage' => 4];
        if ($best === null || $rank[$cand[1]] < $rank[$best[1]]) { $best = $cand; }
    }
    [$r, $state, $why, $cur] = $best;
    $plan = $base($r, $state, $why);
    $plan['cur'] = $cur;
    if ($state === 'queued') {
        $plan['x'] = substr(md5(uniqid((string) $now, true)), 0, 10);
        $plan['param'] = lrgFacQuestParam($r, $npc, $cid, $plan['x']);
        $how = !$ml ? 'the driver cannot click (ml=0)' : 'an ambient actor and the open cannot happen: ' . $cant;
        $why .= ' - ' . $how . '; never licensed (the game decides, its OK is voiced)';
        $plan['why'] = $why;
    }
    $log($r, $state, $why);
    return $rec($r, $plan);
}

/**
 * [pt19h-quest r2 / arch P3] Why an open for this ambient actor would bring the table row's line onto his screen, or '' (it would
 * not, and the table carries the sentence). Her cached ROOT list (lrgDlgRoot: not stale) carries it by topic or by its words; or no
 * root of hers is known and the row is a TOP-LEVEL line (`top`) that the pre-LLM open's clause 4 can fire on (open.toplevel_marker,
 * at least open.toplevel_min_words strict meaning words - "What's the mission?" has one - and no refusal shape, which ends the marker
 * before its clauses 3 to 5: "No, but he told me how to find out."). A line below a top-level topic is on no list an open brings.
 */
function lrgFacQeOpenBrings(array $t, array $r): string
{
    $npc = (string) ($t['npc'] ?? '');
    $line = (string) ($r['line'] ?? '');
    if (function_exists('lrgDlgRefuses') && lrgDlgRefuses($line)) { return ''; }
    $st = $npc !== '' ? lrgDlgState($npc) : [];
    $root = function_exists('lrgDlgRoot') ? lrgDlgRoot($npc, $st) : null;
    if (is_array($root)) {
        $entry = (string) ($r['entry'] ?? '');
        $norms = (array) ($r['_norms'] ?? []);
        foreach ((array) ($root['entries'] ?? []) as $e) {
            $e = (array) $e;
            if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
            if (($entry !== '' && strcasecmp((string) ($e['topic'] ?? ''), $entry) === 0)
                || in_array(lrgFacQeNorm((string) ($e['text'] ?? ''), $npc), $norms, true)) {
                return 'her cached root list carries the line, the open brings it';
            }
        }
        return '';
    }
    if (empty($r['top']) || empty(lrgDlgCfg('open.toplevel_marker', true))) { return ''; }
    if (count(lrgPromptWords(lrgPromptNorm($line), true)) < max(2, (int) lrgDlgCfg('open.toplevel_min_words', 3))) { return ''; }
    return 'no list of hers is known and the line is a top-level one the open fires on';
}

/**
 * [pt19h-quest] The `faction` locked line of a quest-entry TABLE turn (never silent, never false, never a quest id or a stage
 * number): queued -> the game is being asked this moment and has NOT confirmed it; already -> the game already has him past that
 * step; pending -> asked a moment ago, no answer yet; every other state -> nothing about that step has been recorded this moment.
 */
function lrgFacQeLockedLines(array $t): array
{
    $f = (array) ($t['faction'] ?? []);
    $plan = (array) ($f['plan'] ?? []);
    $state = (string) ($plan['state'] ?? '');
    if ($state === '') { return []; }
    $said = rtrim(str_replace(['<', '>'], ['', ''], (string) ((($f['qe'] ?? [])['line'] ?? ''))), '.');
    $pm = trim((string) ($plan['meaning'] ?? ''));
    $pre = 'of this world, not of this moment: he has just said to you what your own dialogue offers as \'' . $said . '\'';
    $never = 'promise nothing, and invent no time, place, reward or next step';
    if ($state === 'queued') {
        $line = $pre . ' - the game is being asked this moment to record that step' . ($pm !== '' ? ' (' . $pm . ')' : '')
            . '; it has NOT confirmed it yet, so do not say it is done: answer him in one plain line as yourself, ' . $never
            . " - the game's own answer follows";
    } elseif ($state === 'already' && (int) ($plan['cur'] ?? -1) === (int) ($plan['stage'] ?? -2)) {
        $line = $pre . ' - the game already has him at that step: say so plainly if it comes up, and nothing beyond it; ' . $never;
    } elseif ($state === 'already') {
        // [pt19h-quest r2 / arch P4] PAST it (or past its max): another branch may have moved the quest on without that step ever being
        // taken (MQ301 at 20 from Paarthurnax's branch, Esbern's plan at 18 never done) - the step is not "behind him", the game only
        // cannot record it without lowering the quest
        $line = $pre . ' - the game already stands further on in this matter and cannot record that step now: say only that, if it'
            . ' comes up, and nothing beyond it; ' . $never;
    } elseif ($state === 'pending') {
        $line = $pre . ' - the game was asked a moment ago to record that step and has not answered yet: say you have heard him; ' . $never;
    } else {
        $line = $pre . ' - nothing about that step has been recorded by the game this moment: answer him in words and never say it'
            . ' is done; ' . $never;
    }
    return [str_replace(['<', '>'], ['', ''], $line)];
}
