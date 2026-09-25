<?php
/**
 * LoreRim Glue - Phase 2 MENULESS QUESTING, server module. Design: glue/PHASE2_DESIGN.md rev 2 sections
 * 2, 3, 4, 5, 6, 7.1; wire: glue/PROTOCOL.md v0.4 section 8. Build plan: glue/V04_BUILD_PLAN.md section 5.
 *
 * THE HARD RAILS (a review rejects any violation):
 *  - the glue never calls a TIF fragment, never SetStage / SetObjectiveCompleted / CompleteQuest, never
 *    SetBribed / SetIntimidated / SetCrimeGold, never fakes a dialogue effect. It only selects the REAL
 *    entry in the REAL hidden session; the ENGINE does the work.
 *  - a wrong match can never cause a wrong effect: two-step confirmation for branching / consequential
 *    entries; for speech checks the LLM judges only WHETHER the player attempted, the engine decides.
 *  - the index is ADVISORY. A wrong index costs a confirmation too many or too few, never a wrong effect.
 *  - CHIM's "AI Quest Progression" must stay OFF; if it is on, this module stands down with one notice.
 *
 * COEXISTENCE WITH PHASE 1 (design 6, the easiest thing to get wrong): Phase 1's $GLOBALS['LRG_TURN'] is
 * untouched and this module adds its own $GLOBALS['LRG_DLG_TURN']. Every Phase 2 gate is computed from
 * Phase 2's OWN state and NEVER from LRG_TURN - under SHARMAT functions.php takes the if branch,
 * lrgPrepareTurn() never runs and LRG_TURN is never built, so a gate in Phase 1's terms would evaluate to
 * "not blocked" exactly where ext/aiagent_nsfw is running its own scenes.
 *
 * OWNERSHIP NOTE for this round: lib/lrg_core.php, lib/lrg_actions.php, lib/lrg_intent.php and
 * config/lrg_config.default.json belong to the INTIMACY lane, so this file calls into them READ-ONLY,
 * keeps its own defaults in code (lrgDlgDefaults(), overridden by a 'dialogue' block in lrg_config.json),
 * and installs its own action row with its own version marker.
 */

if (defined('LRG_DIALOGUE_LOADED')) { return; }
define('LRG_DIALOGUE_LOADED', true);

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_prompt_index.php';
require_once __DIR__ . '/lrg_speech.php';
require_once __DIR__ . '/lrg_factions.php';   // [0.5.7 / pt16-legion] enlistment truth (lrgFac*); hooks are marked below
require_once __DIR__ . '/lrg_market.php';     // [pt19-purchase] buying food and drink by voice (lrgMkt*); hooks are marked below

/** The one new action. Defined here, not in lrg_core.php, because that file is another lane's this round. */
if (!defined('LRG_ACT_TOPIC')) { define('LRG_ACT_TOPIC', 'ExtCmdLRG_SelectTopic'); }
// [0.5.0] ACTIONS stays 1 (the row's shape is unchanged; only metadata.requirements is added, and
// lrgDlgEnsureActions() re-upserts when the requirements it would write differ from the stored row).
// STATE -> 2 and SCHEMA -> 2: readers must tolerate a v1 payload, which they do (every read is `?? default`).
if (!defined('LRG_DLG_ACTIONS_VERSION')) { define('LRG_DLG_ACTIONS_VERSION', 1); }
if (!defined('LRG_DLG_STATE_VERSION')) { define('LRG_DLG_STATE_VERSION', 2); }
if (!defined('LRG_DLG_SCHEMA_VERSION')) { define('LRG_DLG_SCHEMA_VERSION', 2); }

const LRG_DLG_NAME = 'TakeUpBusiness';            // the LLM-facing name of LRG_ACT_TOPIC
/**
 * Every spelling of this one action that may arrive, ALREADY NORMALISED (lowercase, '_' and ' ' stripped).
 * The wire carries the CODE name (HerikaServer/functions/functions.php builds "$actor|$channel|$code@$param"),
 * the JSON schema's enum carries CHIM's snake-cased DISPLAY name (Take_Up_Business), and the model sometimes
 * echoes the plain display name. The gate and the TTS transformer both test against this list.
 */
const LRG_DLG_GATE_NAMES = ['extcmdlrgselecttopic', 'takeupbusiness'];
const LRG_DLG_TYPES = ['lrg_topics', 'lrg_dlg', 'lrg_dlgtalk'];
const LRG_DLG_CLASSES = ['plain', 'service', 'check', 'pay', 'commit', 'meta', 'silent', 'back', 'hidden'];
/** Phase 1 actions that must not be offered while a matter is open (design 6.3). */
const LRG_DLG_INTIMACY_ACTIONS = ['ExtCmdLRG_StartIntimacy', 'ExtCmdLRG_Clothing', 'ExtCmdLRG_RequestAct', 'ExtCmdLRG_Invite'];

// ================================================================== config
function lrgDlgDefaults(): array
{
    return [
        'enabled' => true,
        'reorder_json' => true,                       // decision D3: action before message on speech turns
        // [pt17-replies] 'always' (D3 as decided) | 'business': reorder only when the turn really carries something the
        // action-first order is for (a <business> list, a service, a follower verb, an enlistment ask, an arrest).
        // Since the reorder went live 10 of 100 action-first replies came back with message "" against 1 of 41
        // character-first ones (research/pt17-replies.md); the message description now carries a never-empty clause
        // on every reordered turn, and this switch is the second lever after dialogue.reorder_json=false.
        // [pt19 v1.0 / S11, S12] 'business' is the shipped default now: empty replies <= 3 % (10 % action-first under 'always')
        'reorder_json_scope' => 'business',
        'dedupe_player_prompt' => 'relabel',          // relabel | keep | drop
        'intent_open' => true,                        // bIntentOpen
        'cache' => ['max_age_seconds' => 1800],
        'entries' => ['head' => 8, 'closed_cap' => 12, 'keys' => 12],   // [pt19 v1.0 / S11] more_words retired with the word bucket
        // [pt19 v1.0 / S4.8, S5] scripted_needs_word: on the similarity path a scripted entry clicks only when a meaning
        // word is shared (f1 > 0); service_kind_pick: "show me your wares" / "I need a bed" pick the ONE service entry of
        // that kind on a root list when the similarity path chose nothing (capability map U4)
        'match' => ['min_score' => 0.55, 'min_margin' => 0.15, 'cont_score' => 0.70, 'cont_margin' => 0.25,
            'side_call' => false, 'scripted_needs_word' => true, 'service_kind_pick' => true, 'hint_gap' => 0.10],
        // [pt19 v1.0 / S4.3-S4.5, S9] the player's own sentence is the confirmation (explicit), a bare assent confirms what
        // she just asked (bare_yes), a single-entry layer never asks (single_entry). assent_words is ONE list for the park
        // and the single, compared as a LEADING phrase over lrgPromptNorm tokens (research/pt19c-language.md A1-A4)
        'confirm' => ['min_gold' => 100, 'gold_fraction' => 0.25, 'park_seconds' => 60,
            'said_line_score' => 0.70, 'said_line_margin' => 0.25, 'said_line_tokens' => 4, 'said_line_precision' => 0.75, 'slot_tokens' => 2,
            'assent_words' => ['yes', 'aye', 'yeah', 'yep', 'deal', 'agreed', 'sure', 'sure thing', 'do it', 'i do', 'i will',
                'i swear', 'i swear it', 'so be it', "i'm ready", 'i am ready', 'fine', 'alright', 'all right', 'ok', 'okay',
                'very well', 'of course', "let's do it", 'i accept', "i'm in", 'count me in',
                'yup', 'yea', 'yah', 'o k', 'okey', 'mm hmm', 'uh huh', 'absolutely', 'certainly', 'definitely', 'indeed',
                'you bet', 'for sure', 'sounds good', 'go ahead', 'understood', 'as you wish',
                // [pt19c-A fix 1 / language review 9] the common idioms of assent the owner's STT delivers ("why not" stays OFF
                // the list - it leads real questions, research/pt19c-language.md 1.1 - it only no longer vetoes "sure, why not")
                'i suppose so', 'i guess so', "let's go", 'no problem', 'no worries'],
            'single_entry_extra' => ['go on', 'carry on'],
            'named_score' => 0.45, 'single_entry_score' => 0.65, 'single_entry_exact' => 0.85,
            'single_entry_precision' => 0.75, 'utter_window' => 30, 'bare_yes' => true, 'single_entry' => true],
        'attempt' => ['min_words' => 4, 'scoff_first' => true, 'retry_suppression' => true],
        // [pt19 v1.0 / S4.6] an unscripted single continuation line advances by itself: adv=<grace_ms> on the pick (adv=0
        // for a continuer text), and rearm=1 on the pick that follows a question he asked in the breath
        'auto_advance' => ['enabled' => true, 'grace_ms' => 2500, 'rearm' => true,
            'continuer_words' => ['...', 'go on', 'and?', 'continue', 'then what']],
        // [pt19 v1.0 / S2.1] the pre-LLM open's NARROW marker (five clauses); kind_factions: the job factions a carriage
        // driver / ferryman / trainer carries (capability map U1), compared with her snapshot's fac= (faction EditorIDs).
        // [pt19c final fixer] VERIFIED read-only in the plugins (research/pt19c-final.md): every vanilla / Dawnguard / CFTO
        // driver carries CarriageSystemFaction (CFTO's three new ones KmodCarriageFreeFaction), the Dawnguard ferrymen
        // DLC1FerrySystemFaction and CFTO's KmodFerryRoute1-4Faction, every skill trainer JobTrainerFaction (not
        // JobAnimalTrainerFaction: Banning's dogs are no lesson); a snapshot that lacks it keeps that kind post-LLM
        // [pt19c-A fix 1] toplevel_min_words / toplevel_max_topics: clause 4 fires only on a verbatim top-level line with >= 3
        // strict meaning words that at most 5 distinct TOPICS of the load order carry (the spec's own test row "where can I
        // get a drink" -> NO open, FE.hulda.open; measured on the live index: 24 of 50 everyday sentences to a plain NPC -> 0,
        // the companion / escort / buying phrases 8 of 59 -> 0, every capability-map "top" row kept - "The dragon is dead." and
        // "I was told you would have a weapon for me" (2 strict words) open through clause 5 instead; "Heard any rumors lately?"
        // is 5 topics' line). max_distance: the game's fOpenDistance (LRG_Dialogue.psc) - farther than that, or in combat, the
        // game refuses the open, so none is queued
        'open' => ['narrow_marker' => true, 'toplevel_marker' => true, 'qrows_marker' => true, 'qrows_score' => 0.55,
            'qrows_cap' => 300, 'refused_seconds' => 120, 'toplevel_min_words' => 3, 'toplevel_max_topics' => 5,
            'max_distance' => 200,
            'kind_factions' => ['carriage' => ['CarriageSystemFaction', 'KmodCarriageFreeFaction'],
                'ferry' => ['DLC1FerrySystemFaction', 'KmodFerryRoute1Faction', 'KmodFerryRoute2Faction', 'KmodFerryRoute3Faction',
                    'KmodFerryRoute4Faction'],
                'train' => ['JobTrainerFaction']]],
        // [pt19 v1.0] talk_again: the retired "again" turn (lrg_dlgtalk) - false answers it pre-lock; stage_rail: until one
        // click is verified on this install only an indexed plain line is clicked (S3.3); drive_scene: the scene rail's
        // kill switch (S1.3), the proof itself is clicks_ok >= 1; open_seconds: an open session older than this is
        // treated as closed (the game's own 900 s cap, model F23)
        'session' => ['result_fresh_seconds' => 10, 'settle_seconds' => 1.5, 'talk_again' => false,
            'stage_rail' => true, 'drive_scene' => true, 'open_seconds' => 900],
        'crit' => ['lethal_twat' => ['DGCrimeResistArrest']],
        'hide_chim' => ['RentRoom', 'HireCarriage', 'HireFerry', 'Brawl', 'Training', 'OpenInventory', 'OpenInventory2'],
        'hide_gold' => ['GiveGoldTo', 'TakeGoldFromPlayer'],
        'hide_always' => ['ForgiveCrime'],            // decision D4, while menuless is on
        // [pt19 v1.0 / S6.1] on every QUEST turn (lrgDlgQuestTurn) CHIM's five gold / item hand-overs are off the table and
        // registered in LRG_DLG_SVC_HIDDEN, so the post-gate drops a stray one: v1.0 never invents a reward
        'hide_reward' => ['GiveGoldTo', 'SpawnGold', 'SpawnItem', 'GiveItemTo', 'TakeGoldFromPlayer'],
        // [0.5.1 / owner addendum 10] THE THIRD HIDE LIST, and it has its own name for a reason.
        // hide_chim fires whenever ANY list is known; these codes may only ever step aside for a list
        // that really carries the follower framework's own entries, because "she has a quest topic"
        // is no reason at all to stop CHIM being able to say "follow me". lrgDlgServiceHidePolicy()
        // special-cases the `follower` kind on exactly that condition, and d56 asserts that the kind's
        // hide list is a subset of hide_chim + hide_always + hide_follower.
        'hide_follower' => ['MakeFollower', 'Follow', 'FollowPlayer', 'WaitHere', 'ComeCloser', 'ReturnBackHome'],
        'hide_in_session' => ['EndConversation'],
        // [pt17] AMBIENT SCENES. Any scene at all used to switch this module OFF for the speaker (on=0, list=none,
        // no faction role, no locked facts): Legate Rikke and General Tullius LIVE in the looping Castle Dour
        // map-table scene, so "I want to join the Legion" got them nothing (2026-09-23 20:12). The game now sends
        // sq= (the EditorID of the quest that owns her scene) and sqj= (1 = that quest shows an unfinished journal
        // objective) on the facts line; a scene whose owning quest matches one of these globs, with sqj=0, is
        // TALKABLE. [pt19 v1.0 / S1.3, S2.2] The globs are a SHORTCUT now, not a requirement: a scene with sqj=0 whose
        // owning quest the INDEX does not know as a journal quest is ambient without one (Hulda's
        // DialogueWhiterunBanneredMareScene3, BardAudienceQuest, DialogueGenericScene04 match no glob), and an ambient
        // actor may be opened on the narrow marker. fresh_seconds bounds how old the facts line may be for the verdict.
        'scenes' => ['ambient' => ['*MapTableScene*', 'BardSongs*', '*Idle*', '*Sandbox*', 'WI*'], 'fresh_seconds' => 300],
        // [0.4.1 / owner addendum 8] The server half of the CONVERSATION HOLD. While the game is
        // holding this very NPC for a live one-on-one (snapshot hold=1), EndConversation is not put
        // on the table at all, so the model cannot choose to walk out of a conversation the player
        // is still having. It is the only leave cause with hard log evidence (8 firings in one
        // session, research/pt8-walkaway.md 3.1), and it is what the owner's own Action Editor
        // toggle would do permanently - this does it only for the held NPC, only while held.
        'hold_hides_end_conversation' => true,
        'hold_max_age_seconds' => 45,                 // never trust a hold= older than one window
        'rank' => ['quest' => 4, 'new' => 3, 'tagged' => 2, 'service' => 2, 'similarity' => 6,
            'filler' => -3, 'shared' => -2, 'shared_over' => 20],
        'filler_patterns' => ['DialogueGeneric*', 'DialogueFavorGeneric*', 'FreeformGeneric*', 'WI*',
            'ANDR_AJO_Quest*', 'ACFDialogue*', 'ACFMTS*', 'WTDialogueIdle*', 'SFF*', 'SOS_*', 'NFF*'],
        'quests' => ['enabled' => true, 'lines' => 3, 'max_chars' => 120,
            'bookkeeping_patterns' => ['000FCQuest*', '*Status*', 'DialogueGeneric*', 'DialogueFavorGeneric*',
                'WI*', 'FreeformGeneric*', 'TutorialShouts*'],
            'summary' => ['enabled' => true, 'max' => 8, 'approximate_max' => 6],
            'next' => ['enabled' => true, 'lines' => 3],
            'hint' => ['enabled' => true, 'per_session' => 1],
        ],
        // [0.5.0 / E1] the calibration wire. `accept` false makes the server answer ev=calib with a log
        // line and nothing else - the game half is unaffected either way.
        // [pt19 v1.0 / S3] the automatic calibration session (calib.auto.*) is retired: the four rows are passive and
        // the route is proven by the first real click (clicks_ok on the '*install*' row).
        'calib' => ['accept' => true, 'log' => true],
        // [0.5.0 / E2(b)(c)] the two things she can answer in words, with no menu and no click
        'ask_list_phrases' => ['what can i ask you', 'what can i ask', 'anything i should know',
            'anything you need', 'what do you need doing', 'do you have work', 'got any work',
            'what have you got for me', 'is there anything', 'what is there to do'],
        'ask_next_phrases' => ['what should i do next', 'where was i', 'what was i doing',
            'what am i supposed to do', 'remind me what', 'where do i go now', 'what now'],
        // [0.5.0 / E4(b)] the movement stall while a conversation hold is live (research/pt8-walkaway.md).
        // hold_move_actions is deliberately the SAME list, same spellings, as LRG_Main.CONV_MOVE_ACTIONS
        // (LRG_Main.psc:26) - flow test d52_holdmove parses the .psc and asserts the two are equal.
        'hold_hides_movement' => true,
        'hold_release_on_move' => true,
        'hold_move_actions' => ['ComeCloser', 'FollowPlayer', 'Follow', 'MakeFollower', 'MoveTo', 'TravelTo',
            'TravelToRaw', 'LeadTheWayTo', 'ReturnBackHome', 'Sandbox', 'GoToSleep', 'TakeASeat', 'Relax',
            'WaitHere', 'HireCarriage', 'HireFerry'],
        'move_request_words' => ['come here', 'come closer', 'follow me', 'with me', 'over here', 'lead the way',
            'take me to', 'walk with me', 'sit down', 'wait here', 'stay here', 'go home'],
        // [0.5.0 / E4(a)] the prompt index's own Postgres schema (migration 007)
        'index' => ['schema' => 'lrg_index'],
        // [0.5.0 / E6] SERVICE DIALOGUE. `kinds.*.hide` must stay a SUBSET of hide_chim + hide_always -
        // d56_services asserts it, so the two lists cannot drift into a second source of truth.
        'services' => [
            'enabled' => true,
            'shortcut_fallback' => true,
            // [0.5.0 fix pass / S-1, risk R12] min_priced was 2 and that switched the whole slot guard
            // OFF on the VANILLA carriage list and 6 other real destination layers in this load order,
            // because a carriage list prices ONE row ("Whiterun. (20 gold)") and leaves the rest bare.
            // With the guard off, want=1 and the post-LLM gate fell back to the 0.55 similarity matcher
            // and neither the price-question guard nor the negation guard ran at all - "How much to
            // Morthal?" and "Don't take me to Morthal." both scored 0.85 against "Morthal." and WOULD
            // have spent the gold and teleported him. 0 is also what the index builder itself means by a
            // price list (build_prompt_index.py: ">= 3 siblings of one to three words", no price test),
            // so the two halves now agree. Measured blast radius on the live index: 138 of 5,718 layers
            // (2.4 %) become slot lists, and a sample of 30 is destinations, follower names, house rooms,
            // spell names and Daedric princes - exactly the lists where similarity is dangerous and
            // exact naming is right. It can only ever make execution STRICTER.
            'slot' => ['min_entries' => 3, 'max_words' => 3, 'min_priced' => 0,
                // require_named is descriptive, not configurable: turning it off would put the similarity
                // matcher back on a price list, which is the one thing R12 forbids. See lrgDlgServiceSlot().
                'require_named' => true, 'ambiguous' => 'ask'],
            'catalog' => 'data/service_catalog.json',
            'kinds' => [
                'inn' => ['enabled' => true, 'hide' => ['RentRoom'],
                    'phrases' => ['a room for the night', 'rent a room', 'how much for a room', 'do you have a bed',
                        // [pt19-purchase] 'something to eat' / 'something to drink' moved to the market lane (lib/lrg_market.php
                        // class words): an order of food or drink is answered from her real stock, never with Rent_Room
                        'somewhere to sleep', 'a bed for the night', 'room for the night', 'rent me a room',
                        // [pt19 v1.0 / S2.1 clause 2] each a kind hit on its own ("I need a bed", "can I get a room");
                        // "what's the news" / "any rumours" are rumour questions, not a room (capability map U1 / U4)
                        'need a bed', 'need a room', 'like a room', 'want a room', 'get a room', 'a bed for',
                        'a room please', 'a room for me',
                        // [pt19c-B fix 1 / game review] the natural asks: "Do you have any rooms available?", "Got any rooms?"
                        'any rooms', 'rooms available', 'a room available',
                        // [pt19c fixer / adversarial QA @adv_chain] the terse asks, no verb: "a room for one night please"
                        'a room for', 'room for one night', 'room for a night', 'room for tonight', 'bed for one night',
                        'room for the nite'],
                    // [pt19c-B fix 1 / lang review P5] a bed or a room for someone else's need, or a figure of speech, is no rent
                    'not_after' => ['for the wounded', 'the wounded', 'for the injured', 'the injured', 'for the sick', 'the sick',
                        'you two', 'to think']],
                'carriage' => ['enabled' => true, 'hide' => ['HireCarriage'], 'refuse_fallback_offlist' => true,
                    'phrases' => ['take me to', 'hire your carriage', 'hire a carriage', 'your carriage',
                        'can you get me to', 'ride to', 'drive me to', 'a ride to']],
                'ferry' => ['enabled' => true, 'hide' => ['HireFerry'], 'refuse_fallback_offlist' => true,
                    'phrases' => ['hire your boat', 'your boat', 'the ferry', 'sail me to', 'row me to',
                        'take me across']],
                'train' => ['enabled' => true, 'hide' => ['Training'], 'never_quote_price' => true,
                    // [pt19c-B fix 1 / lang review P4, use review P4] REQUEST frames only: the same list classifies her lines and his
                    // words, and the bare 'train me' / 'training in' / 'train in' made "where did you train in swordplay", "I have been
                    // training in two handed for years" and C00's own "So you're supposed to train me?" a click on the trainer's line.
                    // Every DialogueTrainers text of the index is still covered: I'd like / I need (some) / I want training in, I want /
                    // I'd like to train in.
                    'phrases' => ['can you train me', 'could you train me', 'will you train me', 'would you train me',
                        'you to train me', 'please train me', 'train me in', 'teach me', 'i want to learn', 'what can you teach',
                        'lessons in', 'like training in', 'need training in', 'some training in', 'want training in',
                        'like to train in', 'want to train in', 'need to train in'],
                    // "I want to learn more about the Companions", "teach me about the Greybeards" are questions, not a lesson
                    'not_after' => ['more', 'about the', 'about you', 'about your', 'about this', 'about that', 'about them',
                        'about him', 'about her', 'about it']],
                'barter' => ['enabled' => true, 'hide' => ['OpenInventory', 'OpenInventory2'],
                    'phrases' => ['what have you got for sale', 'let me see your wares', 'show me your goods',
                        'i want to sell', "i'd like to trade", 'do you buy', 'anything to trade', 'your wares',
                        'what are you selling', 'let me see what you have',
                        // [0.5.7 / pt16] tonight's own words. Exact token-run containment matched ONE of
                        // five barter utterances at Solitude's market ("what have you what have you got for
                        // sale sir?"); "for sale", "thing for sale", "excuse me what do you have for sale?"
                        // and the owner's literal "what do you have for sale" all returned ''. The two-token
                        // "for sale" covers every STT fragment of that sentence; the rest are the plain
                        // ways of asking to buy that the ten phrases above never had.
                        'for sale', 'what do you sell', 'do you sell', 'anything to sell', 'got anything to sell',
                        'i want to buy', "i'd like to buy", 'can i buy', 'let me buy', 'buy something',
                        'see your goods', 'your goods', 'sell me', 'like to trade', 'trade with you',
                        // [pt19 v1.0 / S2.1 clause 2] the plain ways of asking to see her goods, each a kind hit on its own
                        'what have you got', 'what do you have', 'show me your wares', 'anything for sale',
                        // [pt19c-B fix 1] "Got any wares?" (game review); the longer "on sale" / "on offer" forms keep the 'on' stop
                        // below from losing a real request (lang review P3)
                        'any wares', 'what do you have on sale', 'what have you got on sale', 'what do you have on offer',
                        'what have you got on offer'],
                    // [0.5.7 / pt16 review] a hit followed by one of these is NOT a request for her goods:
                    // "can i buy | you a drink" (a gesture, the tavern opener), "can i buy | your silence"
                    // (a bribe - lrg_speech.php owns it), "do you sell | your body" (addendum 6 owns it),
                    // "like to trade | places". Without this the barter window opened on a flirt.
                    // [pt19 v1.0] "what have you got | against the Stormcloaks", "what do you have | in mind", "... | to say for
                    // yourself" are not a request for her goods either
                    'not_after' => ['you', 'ya', 'us', 'everyone', 'everybody', 'yourself', 'myself',
                        'your silence', 'your body', 'your soul', 'places', 'stories',
                        'against', 'to say', 'planned', 'in mind', 'for me', 'there', 'left', 'to do', 'to lose',
                        'to hide', 'to eat', 'to drink',
                        // [pt19c-B fix 1 / lang review P3] information questions: "what have you got on Bleak Falls Barrow", "what do
                        // you have to report", "what do you have in store for me" clicked "What have you got for sale?" at Farengar
                        'on', 'about', 'to tell', 'to report', 'to show', 'in store', 'going on', 'for us', 'for him', 'for her',
                        'in common', 'that i need']],
                'crime' => ['enabled' => true, 'hide' => ['ForgiveCrime'], 'lethal' => true,
                    'phrases' => ['pay my fine', 'pay the bounty', 'pay my bounty', 'i surrender',
                        "i'll go quietly", 'i will go quietly', "i didn't do anything", 'let me go',
                        "here's for your trouble", 'do you know who i am', 'take me to jail']],
                // [0.5.1 / owner addendum 10] THE FOLLOWER FRAMEWORK'S OWN DIALOGUE, as a service kind.
                // `phrases` only decides that this turn is ABOUT a follower command (the svc= log fact
                // and the guidance block); WHICH entry runs is decided by services.follower.verbs
                // below, entry by entry, against the live list. `hide` is a subset of hide_follower.
                'follower' => ['enabled' => true,
                    'hide' => ['MakeFollower', 'Follow', 'FollowPlayer', 'WaitHere', 'ComeCloser', 'ReturnBackHome'],
                    'phrases' => ['come with me', 'follow me', 'wait here', 'stay here', 'stay put',
                        "you're dismissed", 'you are dismissed', 'part ways', "let's trade", 'lets trade',
                        'i need to trade', 'this is your home now', 'settle down here',
                        'i need you to do something', 'travel with me', 'join me']],
            ],
            // ---------------------------------------------------------------------------------------
            // [0.5.1] THE FOLLOWER VERB TABLE (pt9 build brief item 3, investigation section 6.1).
            //
            // Recruit / dismiss / wait / follow / trade / favour / settle-home belong to the REAL
            // dialogue entry and to nothing else. The entries are found in the LIVE list by exact
            // token containment - `say` is what the player may say, `entry` is what the entry itself
            // says, `topics` are the topic EditorIDs of that verb's family. All three were read out of
            // THIS load order's index (37,561 rows): the vanilla set is won by Missing Follower
            // Dialogue Fix on quest DialogueFollower, Set Follower Home owns SetHomeSetTopic /
            // SetHomeUnsetTopic, and Inigo / Lucien / Remiel / ksws07 carry their own copies with the
            // same player lines. Nothing here is a guess about a mod that might be installed.
            //
            // `commit` marks the two that cannot be undone by talking - a dismissal and giving her a
            // home - so the existing two-step confirmation runs on them (design 4.5).
            // ---------------------------------------------------------------------------------------
            'follower' => [
                'enabled' => true,
                // DialogueFavorGenericFollowBranchTopic is the COMMONEST recruit topic in the game -
                // the vanilla "Follow me. I need your help." lives on quest DialogueFavorGeneric, not
                // on DialogueFollower, so none of the globs above reach it. Named exactly, never as
                // 'DialogueFavorGeneric*': that quest also carries ordinary favour topics, and a
                // family glob over all of them would let a two-word entry text resolve as a follower
                // verb.
                'families' => ['DialogueFollower*', '*FollowerRecruit*', '*FollowerFollow*', '*FollowerWait*',
                    '*FollowerDismiss*', '*FollowerTrade*', '*FollowerFavor*', '*FollowerDoSomethingStart',
                    '*FollowerWaiterea*', 'SetHomeSetTopic', 'SetHomeUnsetTopic',
                    'DialogueFavorGenericFollowBranchTopic'],
                // never selectable by voice, at any setting: a blocking topic while a favour runs, the
                // ANIMAL twins (a separate slot - "follow me" on a dog must not run the humanoid
                // fragment), and a refusal variant that only exists to be shown.
                // The globs are follower-specific on purpose: '*Animal*' on its own would also match an
                // ordinary quest topic about animals, and this list is a HARD RAIL - lrgDlgGateItem()
                // refuses an entry on it however it was resolved, not only through a follower verb.
                'never_topics' => ['*DoingFavorBlocking*', '*FollowerRecruitReject*', '*FollowerAnimal*',
                    'DialogueFollowerAnimal*', '*AnimalFollowTopic*', '*AnimalWaitTopic*', '*AnimalDismiss*'],
                'verbs' => [
                    'recruit' => [
                        'say' => ['follow me i need your help', 'come with me', 'travel with me', 'join me',
                            'will you come with me', 'come along with me', 'be my companion', 'join my party',
                            'i could use your help out there'],
                        'entry' => ['follow me i need your help', 'i need you to come with me', 'come with me',
                            'how would you like to come along'],
                        'topics' => ['*FollowerRecruit*', 'DialogueFavorGenericFollowBranchTopic'],
                    ],
                    'follow' => [
                        'say' => ['follow me', 'come with me', 'come on then', "let's go", 'lets go',
                            'stop waiting', 'you can follow me again', 'with me again'],
                        'entry' => ['follow me', "let's head out"],
                        'topics' => ['DialogueFollowerFollowTopic', 'DialogueFollowerFollowDummyTopic',
                            '*FollowerFollowTopic', '*FollowerFollowMeFollow'],
                    ],
                    'wait' => [
                        'say' => ['wait here', 'stay here', 'stay put', 'wait for me here', 'hold position',
                            'stay where you are', 'wait for me'],
                        'entry' => ['wait here'],
                        'topics' => ['DialogueFollowerWaitTopic', 'DialogueFollowerWaitDummyTopic',
                            '*FollowerWaitTopic*', '*FollowerWaitHere', '*FollowerWaiterea*'],
                    ],
                    'dismiss' => [
                        'say' => ["you're dismissed", 'you are dismissed', 'we should part ways',
                            "it's time for us to part ways", 'part ways', 'go home now',
                            'i do not need you any more', "i don't need you any more", 'leave my service'],
                        'entry' => ["it's time for us to part ways", "it's time for you to go back home"],
                        'topics' => ['*FollowerDismiss*'],
                        'commit' => true,
                    ],
                    'trade' => [
                        'say' => ["let's trade", 'lets trade', 'i need to trade', 'trade some things',
                            'hold this for me', 'carry this for me', 'take this for me',
                            'let me see what you are carrying'],
                        'entry' => ['i need to trade some things with you', "give me everything you're carrying"],
                        'topics' => ['*FollowerTrade*'],
                    ],
                    'favor' => [
                        'say' => ['i need you to do something', 'do something for me', 'i need a favour',
                            'i need a favor'],
                        'entry' => ['i need you to do something'],
                        'topics' => ['*FollowerFavorStateTopic', '*FollowerFavorTopic', '*FollowerDoSomethingStart'],
                    ],
                    'home' => [
                        'say' => ['this is your home now', 'settle down here', 'you should live here',
                            'make this your home', 'you live here now'],
                        'entry' => ["you should live here when you're not traveling with me"],
                        'topics' => ['SetHomeSetTopic'],
                        'commit' => true,
                    ],
                    'unhome' => [
                        'say' => ['come with me again', 'stop living here', 'you do not live here any more',
                            "you don't live here any more"],
                        'entry' => ['you should stop living at'],
                        'topics' => ['SetHomeUnsetTopic'],
                    ],
                ],
            ],
            'price_words' => ['how much', 'what does it cost', 'what is the fare', "what's the fare",
                'how much for', 'what do you charge', 'your price', 'how much to'],
            // [0.5.7 / pt16] DIRECT BARTER, and the "do it, or say why" block for the other kinds.
            // `barter`: a recognised barter request on a turn where no real entry can win is carried on
            // CHIM's OWN OpenInventory (= the vanilla barter window, AIAgentAIMind.OpenInventory ->
            // ShowBarterMenu): the directive names it, and the post-gate appends it when the reply
            // lacks it. `request_block`: inn / carriage / ferry / train under ml=0 get a <player_request>
            // naming CHIM's own Rent_Room / Hire_Carriage / Hire_Ferry / Training when offered, else
            // "say plainly why not". `combat_max_age_seconds`: how fresh a snapshot's combat=1 has to be
            // to hold barter back (a missing or older snapshot is NOT a reason - first contact at a
            // stall has none).
            'direct' => ['barter' => true, 'request_block' => true, 'combat_max_age_seconds' => 90],
            // extended FROM THE CENSUS (data/service_catalog.json crime_topics), never hand-typed
            'crime_twat_seed' => ['DGCrimeResistArrest'],
        ],
        // [0.5.0 / E7] fact locking and condition truthfulness
        'truth' => [
            'locked_facts' => true, 'gate' => true, 'max_chars' => 600, 'max_lines' => 6,
            // [0.5.7 / pt16-legion] `faction`: who really recruits for a faction (lib/lrg_factions.php)
            // [pt19-purchase] `stock`: what a vendor really sells and asks for it (lib/lrg_market.php, from the snapshot)
            // [pt19 v1.0 / S6.1] `reward`: the reward is what the world gives - only when he bargains or a reward window is open
            'classes' => ['price', 'service', 'bounty', 'gold', 'check', 'quest', 'guard', 'faction', 'stock', 'reward'],
            'bounty_max_age_seconds' => 45,
        ],
        // [0.5.0 / E8] latency. ev=lat is never LLM-bearing; `keep` is the ring-buffer depth per NPC.
        'latency' => ['accept' => true, 'log' => true, 'warn_ms' => 9000, 'keep' => 200],
        'checks' => lrgDlgCheckDefaults(),
        'paid_intimacy' => lrgDlgPriceDefaults(),
        'deny_wordings' => ['not on the table', 'nothing like that', 'no such', "there's nothing", 'i have nothing for you',
            'no business', 'nothing of the sort', 'no work for you'],
        'choice_words' => ['kill', 'spare', 'join', 'side with', 'accept', 'refuse', 'decline', 'give', 'keep',
            'free', 'arrest', 'betray', 'promise', 'marry', 'attack', 'pay', 'surrender', 'yes', 'no'],
        'commit_tags' => ['attack', 'brawl', 'go to jail', 'remain silent', 'start romance', 'end romance', 'family',
            'skip quest', 'fail quest'],
        'meta_tags' => ['skip quest', 'fail quest'],
        // [0.5.0 fix pass / S-8] 'sale', 'goods' and 'merchandise' were missing, so the owner's own
        // headline barter phrase - services.kinds.barter.phrases[0], "what have you got for sale" - did
        // not classify as a service entry at all, while "let me see your wares" did.
        'service_words' => ['rent', 'room', 'carriage', 'ferry', 'ride', 'trade', 'buy', 'sell', 'wares', 'shop',
            'sale', 'goods', 'merchandise',
            'train', 'training', 'barter', 'stable', 'horse', 'bed', 'food', 'drink', 'invest'],
        'log_turn' => true,
    ];
}

/** Defaults in code + the owner's 'dialogue' block from lrg_config.json (that file is another lane's). */
function lrgDlgConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $cfg = lrgDlgDefaults();
    $over = (array) (lrgConfig()['dialogue'] ?? []);
    if ($over) { $cfg = lrgMerge($cfg, $over); }
    return $cfg;
}

function lrgDlgCfg(string $path, $default = null)
{
    // offline seam (tools/test_dialogue.php): one dotted path overridden for a check, e.g. the session.drive_scene kill switch
    if (isset($GLOBALS['LRG_DLG_TEST_CFG']) && array_key_exists($path, (array) $GLOBALS['LRG_DLG_TEST_CFG'])) {
        return $GLOBALS['LRG_DLG_TEST_CFG'][$path];
    }
    $v = lrgDlgConfig();
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

/**
 * The data-only override file of design 5.4. [pt19 v1.0 / model F32] BOTH files are read: the owner's
 * lrg_dialogue_overrides.json wins key by key, and its `entries` rules come FIRST (first match wins) with the shipped
 * default file's rules AFTER them - so an owner file can never shadow the default's `commit: true` rows (the two Oath4
 * lines, spec S4.1 / S4.5) by merely existing. Test seam: $GLOBALS['LRG_DLG_TEST_OVERRIDES'] replaces both files.
 */
function lrgDlgOverrides(): array
{
    $base = ['version' => 1, 'npcs' => [], 'quests' => [], 'entries' => [], 'services' => [], 'critical' => []];
    if (array_key_exists('LRG_DLG_TEST_OVERRIDES', $GLOBALS)) { return array_replace($base, (array) $GLOBALS['LRG_DLG_TEST_OVERRIDES']); }
    static $ov = null;
    if ($ov !== null) { return $ov; }
    $ov = $base;
    $read = [];
    foreach (['owner' => '/config/lrg_dialogue_overrides.json', 'default' => '/config/lrg_dialogue_overrides.default.json'] as $who => $f) {
        if (!is_file(LRG_DIR . $f)) { continue; }
        $d = json_decode((string) @file_get_contents(LRG_DIR . $f), true);
        if (is_array($d)) { $read[$who] = $d; } else { lrgDlgLog('overrides: ' . $f . ' is not valid JSON - ignored'); }
    }
    $own = (array) ($read['owner'] ?? []);
    $def = (array) ($read['default'] ?? []);
    $ov = array_replace($ov, $def, $own);
    $ov['entries'] = array_values(array_merge((array) ($own['entries'] ?? []), (array) ($def['entries'] ?? [])));
    return $ov;
}

function lrgDlgEnabled(): bool
{
    return lrgEnabled() && !empty(lrgDlgCfg('enabled')) && !lrgDlgQuestEngineOn();
}

/** CHIM's own quest engine and this module must never both drive a quest (design 5.2 C5, flow test d35). */
function lrgDlgQuestEngineOn(): bool
{
    static $said = false;
    $on = function_exists('chimQuestEngineFeatureEnabled') && (bool) chimQuestEngineFeatureEnabled();
    if ($on && !$said) {
        $said = true;
        lrgDlgLog('STAND DOWN: CHIM AI Quest Progression is ON - the menuless questing module is idle '
            . '(two engines would set the same stage twice). Switch it off in the CHIM MCM.');
    }
    return $on;
}

function lrgDlgLog(string $msg, string $cid = ''): void
{
    // [pt19 v1.0 / S1.3] lrgDlgWillEmit() runs the gate's own decision once per streamed sentence; its arbitration
    // helpers log, and that twin must never write a line the real gate did not decide
    if (!empty($GLOBALS['LRG_DLG_SILENT'])) { return; }
    lrgLog('dlg ' . $msg, $cid);
}

/**
 * [0.4.0 fix pass] The value of one MCM knob the GAME sent, or the server's own config default.
 * Order: the newest value seen this request (MCM is global), then this NPC's own stored facts, then the
 * SNAPSHOT (see below), then the default. `$npc` may be empty when there is no NPC in hand.
 * Never throws; the snapshot tier queries at most once per NPC per request and is cached.
 */
function lrgDlgMcm(string $key, $default, string $npc = '')
{
    $g = (array) ($GLOBALS['LRG_DLG_MCM'] ?? []);
    if (array_key_exists($key, $g)) { return $g[$key]; }
    if ($npc !== '') {
        $m = (array) ((lrgDlgGet($npc)['facts'] ?? [])['mcm'] ?? []);
        if (array_key_exists($key, $m)) { return $m[$key]; }
        $s = lrgDlgMcmFromSnapshot($npc);
        if (array_key_exists($key, $s)) { return $s[$key]; }
    }
    return $default;
}

/**
 * [0.4.0 release pass] THE SNAPSHOT TIER, and why it has to exist.
 * `chk` / `bias` / `ql` are the three CHECK and QUEST knobs, and the game puts them on the ordinary
 * `lrg_npcstate` SNAPSHOT (LRG_Profile.psc), not on `lrg_topics` / `lrg_dlg ev=facts` - which is right,
 * because a FREE-conversation check happens in ordinary CHIM talk where no dialogue message is ever sent.
 * The facts tier above therefore never saw them, and `bFreeChecks` / `bCheckHostility` / `iCheckBias`
 * still changed nothing. Phase 1 already stores the whole snapshot kv, so reading it back here needs no
 * new wire key and no game-side change; an ABSENT key still means "keep the server's own default".
 */
function lrgDlgMcmFromSnapshot(string $npc): array
{
    if (isset($GLOBALS['LRG_DLG_MCM_SNAP'][$npc])) { return (array) $GLOBALS['LRG_DLG_MCM_SNAP'][$npc]; }
    $out = [];
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) {          // the offline seam: no database in a unit test
        $kv = (array) ($GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? []);
    } else {
        try {
            $kv = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
        } catch (Throwable $e) {
            $kv = null;
        }
    }
    if (is_array($kv)) {
        if (isset($kv['chk']) && preg_match('/^[01][01]$/', (string) $kv['chk'])) {
            $out['free_checks'] = (int) $kv['chk'][0];   // bFreeChecks
            $out['hostility'] = (int) $kv['chk'][1];     // bCheckHostility
        }
        // [0.5.0] sv = bServiceDialogue, lf = bLockedFacts (W11). They ride the SNAPSHOT for exactly the reason
        // chk / bias / ql do: a service intent and the locked-facts block have to be decidable on an ordinary CHIM
        // turn where no dialogue message was ever sent. An ABSENT key still means "keep the server's own default".
        // [pt19 v1.0] qi / qig (bQuestInitiative / iQuestInitiativeGap) are retired with <she_may_raise> (spec
        // section 4): an older script that still sends them is ignored.
        //
        // [0.5.0 fix pass / S-3] qx and svx are SHAPE bytes, parsed the way chk is, because the numeric
        // branch below only accepts one or two digits and would have thrown svx="111" away. Each one is
        // consulted at its own site below.
        if (isset($kv['qx']) && preg_match('/^[01][01]$/', (string) $kv['qx'])) {
            $out['qsum'] = (int) $kv['qx'][0];            // bQuestSummary  - "what can I ask you"
            $out['qnext'] = (int) $kv['qx'][1];           // bQuestNext     - "where was I"
        }
        if (isset($kv['svx']) && preg_match('/^[01][01][01]$/', (string) $kv['svx'])) {
            $out['svsc'] = (int) $kv['svx'][0];           // bServiceShortcut - CHIM's shortcut as fallback
            $out['svbn'] = (int) $kv['svx'][1];           // bCarriageByName  - name the destination
            $out['svnp'] = (int) $kv['svx'][2];           // bNamePrices      - she may say a price out loud
        }
        // ml = bMenuless AND NOT bDlgDryRun (S-2). It is the ONLY thing that tells the server whether the
        // driver can really run a hidden session, and without it the server hid CHIM's own RentRoom /
        // HireCarriage / HireFerry / Training / OpenInventory on every ordinary turn at any NPC whose menu
        // had been read in the last 30 minutes - while the game answered CmdSelectTopic with "the feature
        // is switched off". DEFAULT 1: an older game script that does not send it behaves exactly as 0.4.1.
        // [0.5.1] fv = bFollowerVerbsReal. Same rule: it has to be decidable on an ordinary CHIM turn
        // where no dialogue message was ever sent, so it rides the snapshot, and an ABSENT key keeps
        // the server's own default (1) - an older game script behaves exactly as 0.5.0.
        foreach (['bias' => [-2, 2], 'ql' => [0, 6], 'sv' => [0, 1], 'lf' => [0, 1],
            'tg' => [0, 1], 'ml' => [0, 1], 'fv' => [0, 1], 'cal' => [0, 10]] as $mk => $range) {
            if (isset($kv[$mk]) && (string) $kv[$mk] !== '' && (string) $kv[$mk] !== '-'
                && preg_match('/^-?\d{1,2}$/', (string) $kv[$mk])) {
                $out[$mk] = max($range[0], min($range[1], (int) $kv[$mk]));
            }
        }
        // [pt17] ml is the RAW MCM pair on the snapshot (bMenuless && !bDlgDryRun, LRG_Profile.psc), while the driver's
        // EFFECTIVE state is dlg= on the facts line (sMenuless && !sDryRun AFTER the calibration gate forced the dry
        // run back on). The effective state is only ever MORE restrictive, so the two are ANDed while the facts line
        // is fresh: an owner who unticks bDlgDryRun while the gate is still red no longer has the server hide CHIM's
        // RentRoom / OpenInventory for a driver that answers WOULD CLICK.
        if (isset($out['ml']) && (int) $out['ml'] === 1) {
            $facts = (array) (lrgDlgGet($npc)['facts'] ?? []);
            if (isset($facts['dlg']) && (int) $facts['dlg'] === 0
                && lrgNow() - (int) ($facts['at'] ?? 0) <= (int) lrgDlgCfg('scenes.fresh_seconds', 300)) {
                $out['ml'] = 0;
                $out['ml_forced'] = 1;
            }
        }
    }
    $GLOBALS['LRG_DLG_MCM_SNAP'][$npc] = $out;
    return $out;
}

/**
 * [pt17] The plain "still learning" sentence for an NPC while menuless questing cannot run (ml=0): from cal= on the
 * facts line (how many of the calibration rows are answered), or the bare fact when the game never sent it. Used by
 * the ml=0 log line here and by the recruiter's locked line (lib/lrg_factions.php) and the service request block.
 * [pt19 v1.0 / S3.1, S3.4] Four passive rows, learned from the first menu of ANY kind; the automatic calibration session
 * and the emergency key are gone, so neither is promised any more.
 */
function lrgDlgLearningText(string $npc): string
{
    $cal = lrgDlgMcm('cal', -1, $npc);
    if (!is_int($cal) || $cal < 0) { return 'menuless questing is off (or in dry run)'; }
    if ($cal >= 4) { return 'the dialogue menu is learned but the menuless dry run is on (Menuless questing page)'; }
    return 'the glue is still learning this install\'s dialogue menu (' . $cal . ' of 4 measured) - the next conversation'
        . ' of any kind measures it';
}

/**
 * [pt17] The AMBIENT-scene verdict: a scene she may be talked through (facts and words on).
 * [pt19 v1.0 / S1.3, S2.2, capability map U8] ONE thing changed: the glob is an allow-list SHORTCUT, not a requirement. A
 * scene is ambient when the facts are fresh AND sqj=0 AND (it matches a scenes.ambient glob OR the INDEX has no journal row
 * for its owning quest - lrgDlgQuestIndexed, not CHIM's questlog, so TG00's approach, with no journal entry yet, is still a
 * quest scene; [pt19c-A fix 1] with the index unreadable (Postgres down) the verdict FAILS CLOSED: not ambient). An
 * ambient actor may be opened on the narrow
 * marker (S2.2); the game refuses an open only inside a scene whose owning quest shows an unfinished objective.
 */
function lrgDlgSceneAmbient(string $npc, array $st): array
{
    $facts = (array) ($st['facts'] ?? []);
    $sq = trim((string) ($facts['sq'] ?? ''));
    if ($sq === '') { return ['ambient' => false, 'why' => 'the scene\'s owning quest is unknown']; }
    if (lrgNow() - (int) ($facts['sq_at'] ?? 0) > (int) lrgDlgCfg('scenes.fresh_seconds', 300)) {
        return ['ambient' => false, 'why' => 'the scene facts are stale'];
    }
    if ((int) ($facts['sqj'] ?? 0) === 1) { return ['ambient' => false, 'why' => "$sq shows an unfinished journal objective"]; }
    $globs = array_map('strval', (array) lrgDlgCfg('scenes.ambient', []));
    if ($globs && lrgAnyGlob($globs, [$sq])) {
        return ['ambient' => true, 'why' => "ambient scene $sq (on the ambient list) - talkable, and the narrow marker may open"];
    }
    if (lrgDlgQuestIndexed($sq)) { return ['ambient' => false, 'why' => "$sq is a quest the index knows as a journal quest"]; }
    return ['ambient' => true, 'why' => "ambient scene $sq (no journal row in the index) - talkable, and the narrow marker may open"];
}

/**
 * [pt19 v1.0 / S1.3, capability map U8] Does the prompt INDEX know this quest as a journal quest (>= 1 journal=1 row)?
 * This is what "a quest scene" means server-side: MQ102 (22 journal rows), TG00 (31) yes; DialogueWhiterunBanneredMareScene3,
 * BardAudienceQuest, WITavern, DialogueGenericScene04 no. One lookup per quest per request (cached), never CHIM's questlog -
 * lrgDlgQuestKnown keeps its no-spoiler job unchanged.
 */
function lrgDlgQuestIndexed(string $quest): bool
{
    $quest = trim($quest);
    if ($quest === '' || $quest === '-') { return false; }
    // the offline seam is swapped between scenarios in one process: never cached over
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) { return lrgPromptRowsForQuests([$quest], 1) !== []; }
    // [pt19c-A fix 1 / U8] FAIL CLOSED: an unreadable index is no proof that a quest has no journal row - with Postgres down
    // the scene stays a quest scene (never ambient, so no narrow open; sj from the game's own flag). Not cached: the next
    // request asks again once the database is back.
    if (!lrgDb()) { return true; }
    $k = strtolower($quest);
    if (!isset($GLOBALS['LRG_DLG_QIDX'][$k])) {
        $GLOBALS['LRG_DLG_QIDX'][$k] = lrgPromptRowsForQuests([$quest], 1) !== [];
    }
    return (bool) $GLOBALS['LRG_DLG_QIDX'][$k];
}

/**
 * The name the MODEL really sees, which is not always LRG_DLG_NAME. CHIM normalises a catalog display name
 * by inserting '_' before each capital, so the strict JSON-schema enum ($GLOBALS['FUNC_LIST'], "strict":true)
 * offers `Take_Up_Business` while the <business> block used to say "Use TakeUpBusiness". The gate and the
 * transformer accept either spelling, but a strict enum and a prompt naming DIFFERENT strings costs
 * reliability for nothing - so every model-facing sentence reads the stored form back from the catalog.
 * A row that does not normalise to our own name is ignored: nothing outside can rename this action.
 */
function lrgDlgActionName(): string
{
    static $name = null;
    if ($name !== null) { return $name; }
    $name = LRG_DLG_NAME;
    if (function_exists('herikaGetActionCatalogRow')) {
        try {
            $row = herikaGetActionCatalogRow(LRG_ACT_TOPIC);
            $n = is_array($row) ? trim((string) ($row['action_name'] ?? '')) : '';
            if ($n !== '' && str_replace(['_', ' '], '', strtolower($n)) === strtolower(LRG_DLG_NAME)) { $name = $n; }
        } catch (Throwable $e) { /* the default stands */ }
    }
    return $name;
}

// ================================================================== schema + state
/**
 * Phase 2 runs its own schema ensure with its own marker, so it never depends on another lane moving
 * LRG_SCHEMA_VERSION. Only Phase 2's OWN migrations are run here.
 *
 * The marker file alone is NOT enough (see lrgTableExists()): switching CHIM playthrough drops the whole
 * public schema, which takes lrg_dialogue, lrg_prompt and lrg_prompt_layer with it while the marker
 * survives - and Phase 2 would then be dead for ever, index included, on every later playthrough.
 */
function lrgDlgEnsureSchema(): void
{
    $marker = LRG_DIR . '/data/.dlg_schema_v' . LRG_DLG_SCHEMA_VERSION;
    $hadMarker = is_file($marker);
    if ($hadMarker && lrgTableExists('lrg_dialogue')) { return; }
    $db = lrgDb();
    if (!$db) { return; }
    // [0.5.0] 006 is NOT in this list any more: 007 supersedes it (it moves an existing public copy and
    // then creates the bodies inside lrg_index), and running 006 first would leave an empty public
    // lrg_prompt next to the real one. The splitter is dollar-quote aware because 007's DO block
    // carries ';' inside its body.
    foreach (['005_lrg_dialogue.sql', '007_lrg_prompt_schema.sql'] as $f) {
        $sql = str_replace('{s}', lrgPromptSchema(), (string) @file_get_contents(LRG_DIR . '/migrations/' . $f));
        foreach (lrgPromptSqlStatements($sql) as $stmt) {
            try { $db->execQuery($stmt); } catch (Throwable $e) { lrgDlgLog('schema ' . $f . ': ' . $e->getMessage()); }
        }
    }
    unset($GLOBALS['LRG_PROMPT_SCHEMA_ACTIVE']);
    lrgDlgLog('index schema=' . lrgPromptSchema());
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents($marker, date('c'));
    lrgDlgLog('schema ensured (dlg v' . LRG_DLG_SCHEMA_VERSION . ')');
    // The tables are back but EMPTY, and the 37k-row prompt index is load-order data that only the loader
    // can put back. Say so once, in the owner's own log, instead of running unlabelled for a whole session.
    if ($hadMarker) {
        lrgDlgLog('NOTE the Phase 2 tables had to be recreated - the public schema was dropped since the last run '
            . '(switching CHIM playthrough does that). The prompt index is now EMPTY: re-load it with '
            . '`php ext/lorerim_glue/lib/lrg_prompt_index.php load` or re-run tools/deploy_server.ps1. '
            . 'Until then every prompt shows as unindexed - one confirmation too many, never a wrong effect.');
    }
}

/**
 * The per-request cache lives in $GLOBALS so a value written this request always wins over the row read
 * earlier (a static would go stale the moment lrgDlgSet() ran).
 */
function lrgDlgGet(string $npc): array
{
    if ($npc === '') { return []; }
    if (isset($GLOBALS['LRG_DLG_STATE'][$npc])) { return (array) $GLOBALS['LRG_DLG_STATE'][$npc]; }
    // [pt19c-A fix 1 / CHIM brief P15] the offline STORE seam: a stand-in for the lrg_dialogue table that is NOT this request's
    // cache, so a test can write "from another request" (the fast path) and see the read-modify-write keep it
    if (array_key_exists('LRG_DLG_TEST_STORE', $GLOBALS)) {
        $p = (array) ($GLOBALS['LRG_DLG_TEST_STORE'][$npc] ?? []);
        $GLOBALS['LRG_DLG_STATE'][$npc] = $p;
        return $p;
    }
    $db = lrgDb();
    $row = null;
    if ($db) {
        try { $row = $db->fetchOne('SELECT payload, updated_at FROM lrg_dialogue WHERE npc_name=' . $db->escapeLiteral($npc)); }
        catch (Throwable $e) { lrgDlgLog('state read failed for ' . $npc . ': ' . $e->getMessage()); }
    }
    $p = [];
    if (is_array($row) && isset($row['payload'])) {
        $p = is_array($row['payload']) ? $row['payload'] : (json_decode((string) $row['payload'], true) ?: []);
    }
    $p['_updated_at'] = (int) (is_array($row) ? ($row['updated_at'] ?? 0) : 0);
    $GLOBALS['LRG_DLG_STATE'][$npc] = $p;
    return $p;
}

/**
 * [pt19 v1.0 / CHIM brief P1, spec S2.1] The row as the DATABASE has it now, bypassing this request's cache, and the cache
 * refreshed with it. The LLM request primes the cache at lrgDlgPrepareTurn and acts seconds later; the fast path
 * (lrg_topics / lrg_dlg, pre-lock, in parallel) writes session, last_exec, last_click and want_none in between - the pre-LLM
 * open makes that interleave the NORMAL case. A failed read keeps the cached copy (never an empty row that a write would
 * then store). Without a database (the offline tests) the cache IS the store.
 */
function lrgDlgFresh(string $npc): array
{
    if ($npc === '') { return []; }
    if (array_key_exists('LRG_DLG_TEST_STORE', $GLOBALS)) {
        $p = (array) ($GLOBALS['LRG_DLG_TEST_STORE'][$npc] ?? []);
        $GLOBALS['LRG_DLG_STATE'][$npc] = $p;
        return $p;
    }
    $db = lrgDb();
    if (!$db) { return lrgDlgGet($npc); }
    try {
        $row = $db->fetchOne('SELECT payload, updated_at FROM lrg_dialogue WHERE npc_name=' . $db->escapeLiteral($npc));
    } catch (Throwable $e) {
        lrgDlgLog('state fresh read failed for ' . $npc . ': ' . $e->getMessage());
        return lrgDlgGet($npc);
    }
    $p = [];
    if (is_array($row) && isset($row['payload'])) {
        $p = is_array($row['payload']) ? $row['payload'] : (json_decode((string) $row['payload'], true) ?: []);
    }
    $p['_updated_at'] = (int) (is_array($row) ? ($row['updated_at'] ?? 0) : 0);
    $GLOBALS['LRG_DLG_STATE'][$npc] = $p;
    return $p;
}

function lrgDlgSet(string $npc, array $patch): void
{
    if ($npc === '') { return; }
    lrgDlgEnsureSchema();
    // [pt19 v1.0 / P1] read-modify-write on the row as it is NOW: a patch never restores keys the fast path wrote since
    // this request's first read (a landed fast pick's last_exec, the next layer's session)
    $cur = lrgDlgFresh($npc);
    unset($cur['_updated_at']);
    foreach ($patch as $k => $v) {
        if ($v === null) { unset($cur[$k]); } else { $cur[$k] = $v; }
    }
    $cur['v'] = LRG_DLG_STATE_VERSION;
    if (array_key_exists('LRG_DLG_TEST_STORE', $GLOBALS)) {
        $GLOBALS['LRG_DLG_TEST_STORE'][$npc] = $cur;
        $GLOBALS['LRG_DLG_STATE'][$npc] = $cur;
        return;
    }
    $db = lrgDb();
    if ($db) {
        try {
            $db->upsertRowOnConflict('lrg_dialogue',
                ['npc_name' => $npc, 'payload' => json_encode($cur), 'updated_at' => lrgNow()], 'npc_name');
        } catch (Throwable $e) { lrgDlgLog('state write failed for ' . $npc . ': ' . $e->getMessage()); }
    }
    $cur['_updated_at'] = lrgNow();
    $GLOBALS['LRG_DLG_STATE'][$npc] = $cur;
}

/** Alias kept because the design's own text says "state" in a dozen places. */
function lrgDlgState(string $npc): array { return lrgDlgGet($npc); }
function lrgDlgPut(string $npc, array $patch): void { lrgDlgSet($npc, $patch); }

// ================================================================== game -> server
/**
 * 'handled' = the caller terminates (every Phase 2 state message); 'pass' = the request continues.
 * This is the ONLY entry point preprocessing.php uses, and it is placed BEFORE Phase 1's lrg_ block so the
 * module stays alive under SHARMAT.
 */
function lrgDlgHandleGameMessage(array $gameRequest): string
{
    $type = strtolower((string) ($gameRequest[0] ?? ''));
    if (!in_array($type, LRG_DLG_TYPES, true)) { return 'pass'; }
    if (!lrgDlgEnabled()) { return $type === 'lrg_dlgtalk' ? 'handled' : 'handled'; }
    lrgDlgEnsureSchema();
    $raw = lrgStripContext((string) ($gameRequest[3] ?? ''));
    if ($type === 'lrg_topics') { return lrgDlgOnTopics($raw); }
    if ($type === 'lrg_dlg') { return lrgDlgOnEvent($raw); }
    return lrgDlgOnTalk($raw, $gameRequest);
}

/**
 * csv of IDENTIFIERS, case preserved. Phase 1's lrgCsv() lowercases, which is right for words and wrong
 * for a quest EditorID: questlog.id_quest is case sensitive and a lowercased IN (...) would find nothing.
 */
function lrgDlgIds(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $x) {
        $x = trim($x);
        if ($x !== '' && $x !== '-') { $out[] = $x; }
    }
    return array_values(array_unique($out));
}

/** Split a payload whose LAST key is raw and may itself contain ';' and '=' (PROTOCOL rule). */
function lrgDlgSplitLast(string $raw, string $lastKey): array
{
    $needle = ';' . $lastKey . '=';
    $p = strpos($raw, $needle);
    if ($p === false) {
        if (strncmp($raw, $lastKey . '=', strlen($lastKey) + 1) === 0) {
            return [[], substr($raw, strlen($lastKey) + 1)];
        }
        return [lrgParseKv($raw), ''];
    }
    return [lrgParseKv(substr($raw, 0, $p)), substr($raw, $p + strlen($needle))];
}

/** One engine-built list (PROTOCOL v0.4 w1). e= is LAST and taken raw. */
function lrgDlgOnTopics(string $raw): string
{
    [$kv, $eRaw] = lrgDlgSplitLast($raw, 'e');
    $npc = (string) ($kv['npc'] ?? '');
    if ($npc === '') { lrgDlgLog('lrg_topics without npc= - dropped'); return 'handled'; }
    $part = (int) ($kv['part'] ?? 1);
    $entries = lrgDlgParseEntries($eRaw, $part);
    $st = lrgDlgState($npc);
    $sid = (string) ($kv['sid'] ?? '');
    $gen = (int) ($kv['gen'] ?? 0);
    $layer = (int) ($kv['layer'] ?? 0);
    $n = (int) ($kv['n'] ?? count($entries));
    $sess = (array) ($st['session'] ?? []);
    // part=2 is a TAIL of texts only: merge by position into the head we already have
    if ($part === 2 && (string) ($sess['sid'] ?? '') === $sid && (int) ($sess['gen'] ?? -1) === $gen) {
        $have = (array) ($sess['entries'] ?? []);
        $byPos = [];
        foreach ($have as $e) { $byPos[(int) $e['pos']] = $e; }
        foreach ($entries as $e) {
            $e['tail'] = 1;
            if (!isset($byPos[(int) $e['pos']])) { $byPos[(int) $e['pos']] = $e; }
        }
        ksort($byPos);
        $entries = array_values($byPos);
    }
    $facts = lrgDlgFactsFrom($kv, (array) ($st['facts'] ?? []));
    $records = lrgPromptLookup(array_map(static fn($e) => (string) $e['text'], $entries),
        ['quests' => lrgDlgIds((string) ($kv['q'] ?? '')), 'bamt' => (int) ($kv['bamt'] ?? 0)]);
    $kindLayer = lrgPromptLayerKind($records);
    $entries = lrgDlgDecorateEntries($entries, $records, $kv, $facts);
    $same = (string) ($sess['sid'] ?? '') === $sid;
    $prev = $sess;
    $sess = [
        'sid' => $sid, 'gen' => $gen, 'layer' => $layer, 'origin' => (string) ($kv['origin'] ?? 'glue'),
        'crit' => (int) ($kv['crit'] ?? 0), 'scene' => (int) ($kv['scene'] ?? 0),
        'state' => 'open', 'at' => lrgNow(), 'n' => $n, 'sent' => count($entries),
        'kind' => $kindLayer === 'unknown' ? ($layer === 0 ? 'root' : 'closed') : $kindLayer,
        'entries' => $entries, 'path' => (array) ($prev['path'] ?? []),
        'fam' => (int) ($kv['fam'] ?? 0), 'st' => (int) ($kv['st'] ?? 0),
        // the freeze rule (B1): the shown variant of every entry reflects the evaluation made when the
        // ENGINE built this list, so nothing may be clicked once the player's gold or Speech has moved.
        'pg' => (int) ($kv['pg'] ?? ($facts['pg'] ?? 0)), 'sp' => (int) ($kv['sp'] ?? ($facts['sp'] ?? 0)),
        // same-session continuation depth, carried across the layers of ONE session (D-18: depth 1 only)
        'cont' => $same ? (int) ($prev['cont'] ?? 0) : 0,
    ];
    // [pt19 v1.0 / model F1] what ev=open said about WHO DRIVES this session survives its lists: the driver verdict (drv),
    // the scene basis (sj, sq, sqj), ambient, quiet and a stop. A lrg_topics that carries sj= / drv= itself wins (the game
    // re-tests a scene on a changed layer, F24); a missing drv= on an old game's session means 1.
    foreach (['sj', 'sq', 'sqj', 'drv', 'amb', 'quiet', 'stopped', 'opened_at'] as $k) {
        if ($same && array_key_exists($k, $prev)) { $sess[$k] = $prev[$k]; }
    }
    if (isset($kv['sj']) && (string) $kv['sj'] !== '') { $sess['sj'] = (string) $kv['sj'] === '1' ? 1 : 0; }
    if (isset($kv['drv']) && (string) $kv['drv'] !== '') { $sess['drv'] = (string) $kv['drv'] === '1' ? 1 : 0; }
    // a session this server never saw an ev=open for (an older game, a lost message): the old fallback, scene && !ambient
    if (!isset($sess['sj'])) {
        $sess['sj'] = ((int) $sess['scene'] === 1 && empty(lrgDlgSceneAmbient($npc, ['facts' => $facts])['ambient'])) ? 1 : 0;
    }
    $patch = ['session' => $sess, 'facts' => $facts, 'ref' => (string) ($kv['ref'] ?? ($st['ref'] ?? '')),
        'vt' => (string) ($kv['vt'] ?? ($st['vt'] ?? '')), 'q' => lrgDlgIds((string) ($kv['q'] ?? ''))];
    // [pt19 v1.0 / model F9] hc=1: HIS OWN click changed this layer (no driven pick was in flight). An older sentence and a
    // park must not act on the layer he chose: the click is the newest thing he did.
    if ((string) ($kv['hc'] ?? '') === '1') {
        $patch['last_click'] = ['at' => lrgNow(), 'cid' => 'hand'];
        $patch['parked'] = null;
    }
    // a ROOT list is the cache the next turn's keys come from
    if ($sess['kind'] === 'root' && $layer === 0) {
        $patch['root'] = ['sid' => $sid, 'gen' => $gen, 'at' => lrgNow(), 'loc' => lrgDlgLoc($npc),
            'qsig' => lrgDlgQsig(), 'n' => $n, 'entries' => $entries];
    }
    lrgDlgPut($npc, $patch);
    $unindexed = count(array_filter($records, static fn($r) => $r === null));
    lrgDlgLog(sprintf('topics npc=%s sid=%s gen=%d layer=%d origin=%s part=%d n=%d sent=%d kind=%s crit=%d scene=%d sj=%d drv=%d unindexed=%d want=%s%s',
        $npc, $sid, $gen, $layer, $sess['origin'], $part, $n, count($entries), $sess['kind'], $sess['crit'],
        $sess['scene'], (int) ($sess['sj'] ?? 0), (int) ($sess['drv'] ?? 1), $unindexed, (string) ($kv['want'] ?? '0'),
        (string) ($kv['hc'] ?? '') === '1' ? ' hc=1' : ''), (string) ($kv['cid'] ?? ''));
    if ((string) ($kv['want'] ?? '0') === '1' && $part !== 2) { lrgDlgAnswerWant($npc, $kv, $sess); }
    return 'handled';
}

/** '<pos>~<topicIndex>~<new>~<col>~<text>' joined by '~~'; a part=2 entry is '<pos>~-~-~-~<text>'. */
function lrgDlgParseEntries(string $eRaw, int $part): array
{
    $out = [];
    if (trim($eRaw) === '') { return $out; }
    foreach (explode('~~', $eRaw) as $chunk) {
        if (trim($chunk) === '') { continue; }
        $f = explode('~', $chunk, 5);
        if (count($f) < 5) { continue; }
        $out[] = ['pos' => (int) $f[0], 'i' => $f[1] === '-' ? -1 : (int) $f[1],
            'new' => $f[2] === '-' ? 0 : (int) $f[2], 'col' => $f[3] === '-' ? 0 : (int) $f[3],
            'text' => trim((string) $f[4]), 'tail' => $part === 2 ? 1 : 0];
    }
    return $out;
}

/** The player / NPC facts a state message carries, merged over what we already knew. */
function lrgDlgFactsFrom(array $kv, array $prev): array
{
    $f = $prev;
    // [pt18-quest] mqq / mq101 / mq101c: the Legion road on this load order (lib/lrg_factions.php lrgFacRoad); "-" = unknown
    foreach (['pg', 'bamt', 'sp', 'lvl', 'wis', 'dist', 'sub', 'fam', 'dlg', 'cal', 'sqj', 'mqq', 'mq101', 'mq101c'] as $k) {
        if (isset($kv[$k]) && $kv[$k] !== '' && $kv[$k] !== '-') { $f[$k] = (int) $kv[$k]; }
    }
    // [pt17] sq= the EditorID of the quest owning the scene she is in ('' = none / unknown), stamped so a
    // stale verdict never keeps a real scene "ambient"; qst= <quest>:<current stage> for the quests she is in
    if (isset($kv['sq'])) {
        $sq = trim((string) $kv['sq']);
        $f['sq'] = ($sq === '-' || $sq === '') ? '' : substr($sq, 0, 60);
        $f['sq_at'] = lrgNow();
        if (!isset($kv['sqj'])) { $f['sqj'] = 0; }
    }
    if (isset($kv['qst'])) {
        $qst = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $kv['qst']))) as $pair) {
            if ($pair === '-') { continue; }
            $p = strrpos($pair, ':');
            if ($p === false) { continue; }
            $q = trim(substr($pair, 0, $p));
            if ($q !== '' && count($qst) < 6) { $qst[$q] = (int) substr($pair, $p + 1); }
        }
        $f['qst'] = $qst;
    }
    // [pt19 / script 511] quiet= 1 while the game's QUIET MODE is on for this NPC (LRG_Main.QuietOn: a curated scripted
    // intro such as MQ101 runs) - stamped, so a stale 1 never outlives quiet.fresh_seconds; 0 / '-' = off; absent = unchanged
    if (isset($kv['quiet']) && (string) $kv['quiet'] !== '') {
        $f['quiet'] = ((string) $kv['quiet'] === '1') ? 1 : 0;
        $f['quiet_at'] = lrgNow();
    }
    // The split is INLINE on purpose. lrgCsv() lives in lib/lrg_actions.php, and preprocessing.php handles
    // lrg_topics / lrg_dlg at line 21 with lrg_dialogue.php alone loaded - lrg_actions.php is only required
    // at line 36, which that branch never reaches. Calling it here fatals every message that carries a
    // perk (i.e. every one, the moment the player owns a Speech perk or the Amulet of Articulation).
    // Behaviour is identical to lrgCsv(): trim, lowercase, drop empties.
    if (isset($kv['perk'])) {
        $f['perk'] = $kv['perk'] === '-' ? [] : array_values(array_filter(
            array_map(static fn($x) => strtolower(trim((string) $x)), explode(',', (string) $kv['perk'])), 'strlen'));
    }
    // [0.4.0 fix pass] THE MCM KNOBS THAT ARE SERVER BEHAVIOUR (PROTOCOL 10.10). Seven MCM controls did
    // nothing at all because they had no wire carrier: the game read them (or did not) and the server
    // went on using its own config default, so `bFreeChecks` off still ran the check, still injected
    // <what_just_happened> stating the outcome as fact and still emitted do=award, which the game then
    // discarded - the owner got the narration of a check they had switched off. All optional and
    // additive: an ABSENT key keeps the server's own default, so script 310 behaves exactly as before.
    $mcm = (array) ($prev['mcm'] ?? []);
    if (isset($kv['chk']) && preg_match('/^[01][01]$/', (string) $kv['chk'])) {
        $mcm['free_checks'] = (int) $kv['chk'][0];       // bFreeChecks
        $mcm['hostility'] = (int) $kv['chk'][1];         // bCheckHostility
    }
    // [pt19 v1.0 / section 4] bi (iBranchInput), rw / rwd (bRewalk / iRewalkDepth) are retired: an older script's
    // keys are ignored. cal = how many calibration rows the game has answered, a global fact like the knobs.
    foreach (['bias' => [-2, 2], 'ql' => [0, 6], 'io' => [0, 1], 'cal' => [0, 10]] as $mk => $range) {
        if (isset($kv[$mk]) && (string) $kv[$mk] !== '' && (string) $kv[$mk] !== '-'
            && preg_match('/^-?\d{1,2}$/', (string) $kv[$mk])) {
            $mcm[$mk] = max($range[0], min($range[1], (int) $kv[$mk]));
        }
    }
    if ($mcm) {
        $f['mcm'] = $mcm;
        // MCM is global, the facts row is per NPC: the newest values win for every NPC this request
        $GLOBALS['LRG_DLG_MCM'] = $mcm + (array) ($GLOBALS['LRG_DLG_MCM'] ?? []);
    }
    if (isset($kv['sg']) && (string) $kv['sg'] !== '') {
        $sg = array_map('floatval', explode(',', (string) $kv['sg']));
        if (count($sg) >= 5) {
            $f['sg'] = ['SpeechVeryEasy' => $sg[0], 'SpeechEasy' => $sg[1], 'SpeechAverage' => $sg[2],
                'SpeechHard' => $sg[3], 'SpeechVeryHard' => $sg[4]];
        }
    }
    $f['at'] = lrgNow();
    return $f;
}

/** Session events keyed by ev (PROTOCOL v0.4 w2). */
function lrgDlgOnEvent(string $raw): string
{
    $kv0 = lrgParseKv($raw);
    $ev = strtolower((string) ($kv0['ev'] ?? ''));
    // [0.5.0] ev=calib carries k= LAST and RAW (it is a comma-separated name:int list, no ';' in it)
    $last = ['line' => 't', 'result' => 'txt', 'calib' => 'k'][$ev] ?? '';
    [$kv, $tail] = $last !== '' ? lrgDlgSplitLast($raw, $last) : [$kv0, ''];
    if ($last !== '' && !isset($kv['ev'])) { $kv = $kv0; }
    $npc = (string) ($kv['npc'] ?? '');
    if ($npc === '' && $ev !== '') { lrgDlgLog('lrg_dlg ev=' . $ev . ' without npc= - dropped'); return 'handled'; }
    $cid = (string) ($kv['cid'] ?? '');
    switch ($ev) {
        case 'open':
            $sess = ['sid' => (string) ($kv['sid'] ?? ''), 'gen' => 0, 'layer' => 0, 'state' => 'open',
                'origin' => (string) ($kv['origin'] ?? 'glue'), 'crit' => (int) ($kv['crit'] ?? 0),
                'scene' => (int) ($kv['scene'] ?? 0),
                'at' => lrgNow(), 'opened_at' => lrgNow(), 'entries' => [], 'path' => []];
            // [0.5.0 W13] svck= is the DRIVER's own cheap "is this a price list" verdict, from entries it
            // already holds. The server re-derives it from the list when that list arrives and logs a
            // 'svck drift' if the two disagree - nothing branches on it either way.
            $svck = strtolower(trim((string) ($kv['svck'] ?? '')));
            if ($svck !== '' && $svck !== '-') { $sess['svck'] = substr($svck, 0, 12); }
            $st0 = lrgDlgState($npc);
            $facts = lrgDlgFactsFrom($kv, (array) ($st0['facts'] ?? []));
            // [pt19 v1.0 / S1.3, S8] WHO DRIVES THIS SESSION. drv=1|0 (missing = 1: an old game drives what it opens);
            // sq= / sqj= the scene's owning quest and its journal state (the basis, so a wrong verdict is one grep);
            // sj = the game's own "a journal-quest scene" OR (scene=1 AND the index knows sq as a journal quest - TG00's
            // approach has no objective yet but 31 journal rows); an OLD ev=open without sj= falls back to
            // scene && !ambient. quiet=1 (model F17): a curated intro runs - the session is read-only.
            $sq = trim((string) ($kv['sq'] ?? ''));
            $sq = ($sq === '-') ? '' : substr($sq, 0, 60);
            $sess['sq'] = $sq;
            $sess['sqj'] = (string) ($kv['sqj'] ?? '') === '1' ? 1 : 0;
            $sess['drv'] = (string) ($kv['drv'] ?? '1') === '0' ? 0 : 1;
            $sess['quiet'] = (string) ($kv['quiet'] ?? '') === '1' ? 1 : 0;
            $scene = (int) $sess['scene'] === 1;
            if (isset($kv['sj']) && (string) $kv['sj'] !== '') {
                $sess['sj'] = ((string) $kv['sj'] === '1' || ($scene && $sq !== '' && lrgDlgQuestIndexed($sq))) ? 1 : 0;
                $sjSrc = (string) $kv['sj'] === '1' ? 'game' : ($sess['sj'] ? 'index' : 'game');
            } elseif ($sq !== '') {
                $sess['sj'] = ($scene && ($sess['sqj'] === 1 || lrgDlgQuestIndexed($sq))) ? 1 : 0;
                $sjSrc = 'sq';
            } else {
                $amb = $scene ? lrgDlgSceneAmbient($npc, ['facts' => $facts]) : ['ambient' => false];
                $sess['sj'] = ($scene && empty($amb['ambient'])) ? 1 : 0;
                $sjSrc = 'old';
            }
            $patch = ['ref' => (string) ($kv['ref'] ?? ''), 'vt' => (string) ($kv['vt'] ?? ''),
                'facts' => $facts, 'session' => $sess];
            // [0.5.0 W2] cal= is the resolved calibration in four letters (cal=rm3cm1rt2g1). It is a property of the
            // INSTALL, so it is mirrored onto the '*install*' row only (a diagnostic: the log line prints it).
            $cal = lrgDlgParseCal((string) ($kv['cal'] ?? ''));
            if ($cal) { lrgDlgPut('*install*', ['cal' => $cal, 'cal_at' => lrgNow()]); }
            lrgDlgPut($npc, $patch);
            lrgDlgLog(sprintf('open npc=%s sid=%s origin=%s crit=%s fam=%s dist=%s sg=%s cal=%s svck=%s scene=%d sq=%s sqj=%d sj=%d(%s) drv=%d hid=%s%s clicks_ok=%d',
                $npc, (string) ($kv['sid'] ?? ''), (string) ($kv['origin'] ?? ''), (string) ($kv['crit'] ?? '0'),
                (string) ($kv['fam'] ?? '?'), (string) ($kv['dist'] ?? '?'), (string) ($kv['sg'] ?? '-'),
                (string) ($kv['cal'] ?? '-'), $svck !== '' ? $svck : '-', $scene ? 1 : 0, $sq !== '' ? $sq : '-',
                $sess['sqj'], $sess['sj'], $sjSrc, $sess['drv'], (string) ($kv['hid'] ?? '-'), $sess['quiet'] ? ' quiet=1' : '',
                lrgDlgClicksOk()), $cid);
            return 'handled';
        case 'stopped':
            // [pt19 v1.0 / model F2] StopDriving(why): the menu is his from now on. The session stays OPEN (the list is still
            // on screen and is still read), it is read-only, and the next turn may say why once (lrgDlgGroundTruth, Lane B).
            $st1 = lrgDlgState($npc);
            $sess1 = (array) ($st1['session'] ?? []);
            $why = substr(preg_replace('/[^a-z_-]+/', '', strtolower((string) ($kv['why'] ?? 'unknown'))), 0, 20);
            if ($sess1 && (string) ($sess1['sid'] ?? '') === (string) ($kv['sid'] ?? ($sess1['sid'] ?? ''))) {
                $sess1['drv'] = 0;
                $sess1['stopped'] = ['why' => $why !== '' ? $why : 'unknown', 'at' => lrgNow()];
                lrgDlgPut($npc, ['session' => $sess1]);
            }
            lrgDlgLog('stopped npc=' . $npc . ' sid=' . (string) ($kv['sid'] ?? '-') . ' why=' . ($why !== '' ? $why : '?')
                . ' - read-only from now on (the menu is his)', $cid);
            return 'handled';
        case 'line':
            return lrgDlgOnLine($npc, $kv, $tail, $cid);
        case 'result':
            return lrgDlgOnResult($npc, $kv, $tail, $cid);
        case 'closed':
            return lrgDlgOnClosed($npc, $kv, $cid);
        case 'unhide':
            // [0.5.0 W5] the hand-back now says WHICH layer went back and whether a resume is armed.
            // Added to the existing message rather than a new ev: one message, not two.
            lrgDlgLog(sprintf('unhide npc=%s why=%s sid=%s layer=%s n=%s kind=%s resume=%s', $npc,
                (string) ($kv['why'] ?? '?'), (string) ($kv['sid'] ?? ''), (string) ($kv['layer'] ?? '-'),
                (string) ($kv['n'] ?? '-'), (string) ($kv['kind'] ?? '-'), (string) ($kv['resume'] ?? '-')), $cid);
            lrgDlgLog(sprintf('handback npc=%s why=%s layer=%s kind=%s resume=%s', $npc,
                (string) ($kv['why'] ?? '?'), (string) ($kv['layer'] ?? '-'), (string) ($kv['kind'] ?? '-'),
                (string) ($kv['resume'] ?? '-')), $cid);
            return 'handled';
        case 'facts':
            return lrgDlgOnFacts($npc, $kv, $cid);
        case 'calib':
            return lrgDlgOnCalib($npc, $kv, $tail, $cid);
        case 'resume':
            return lrgDlgOnResume($npc, $kv, $cid);
        case 'lat':
            return lrgDlgOnLatency($npc, $kv, $cid);
    }
    lrgDlgLog('lrg_dlg with unknown ev=' . $ev . ' - ignored (additive wire: this is not an error)');
    return 'handled';
}

/**
 * [0.5.0 W2] `cal=rm3cm1rt2g1` -> ['rm' => 3, 'cm' => 1, 'rt' => 2, 'g' => 1]. Anything that is not
 * exactly <letters><digits> pairs is dropped whole: a half-read calibration is worse than none.
 */
function lrgDlgParseCal(string $s): array
{
    $s = strtolower(trim($s));
    if ($s === '' || $s === '-') { return []; }
    if (!preg_match_all('/([a-z]{1,4})(\d{1,3})/', $s, $m, PREG_SET_ORDER)) { return []; }
    $out = [];
    foreach ($m as $p) { $out[$p[1]] = (int) $p[2]; }
    return $out;
}

/**
 * [0.5.0 W1 / E1] The self-calibrating driver's answers. Stored against '*install*' because the
 * calibration is a property of THIS INSTALL (its SWF family, its frame rate, its Smart Talk settings),
 * not of an NPC - the next NPC the player talks to must see the same answers.
 * [pt19 v1.0 / S3, S8] k= carries `cm rm fam st route timer x1 apd ms3 tail` (an old ten-row k= still parses: every pair is
 * stored as sent). reset=1 is "Forget everything it learned" (CalForget): the proven clicks go with it, so the stage rail
 * (S3.3) and the scene rail (S1.3) close again until the next real click.
 * This ev NEVER reaches the LLM: it is answered inside preprocessing, before the MAIN lock.
 */
function lrgDlgOnCalib(string $npc, array $kv, string $kRaw, string $cid): string
{
    if (empty(lrgDlgCfg('calib.accept', true))) {
        lrgDlgLog('calib npc=' . $npc . ' ignored: dialogue.calib.accept is off', $cid);
        return 'handled';
    }
    $keys = [];
    foreach (explode(',', trim($kRaw)) as $pair) {
        $p = strpos($pair, ':');
        if ($p === false) { continue; }
        $k = strtolower(trim(substr($pair, 0, $p)));
        $v = trim(substr($pair, $p + 1));
        if ($k === '' || !preg_match('/^-?\d{1,12}$/', $v)) { continue; }
        $keys[$k] = (int) $v;
    }
    $gate = (string) ($kv['gate'] ?? '0') === '1';
    $reset = (string) ($kv['reset'] ?? '') === '1';
    $rec = ['at' => lrgNow(), 'src' => substr((string) ($kv['src'] ?? '?'), 0, 12), 'gate' => $gate ? 1 : 0,
        'miss' => substr((string) ($kv['miss'] ?? '-'), 0, 160), 'k' => $keys];
    $patch = ['calib' => $rec];
    $before = lrgDlgClicksOk();
    if ($reset) { $patch['clicks_ok'] = 0; }
    lrgDlgPut('*install*', $patch);
    if ($reset) {
        lrgDlgLog('calib npc=' . $npc . ' reset=1 - the calibration was forgotten in game: clicks_ok ' . $before
            . ' -> 0 (the stage rail and the scene rail close until the next real click)', $cid);
    }
    if (!empty(lrgDlgCfg('calib.log', true))) {
        lrgDlgLog(sprintf('calib npc=%s src=%s gate=%d miss=%s k=%s', $npc, $rec['src'], $rec['gate'],
            $rec['miss'] !== '' ? $rec['miss'] : '-', substr($kRaw, 0, 300)), $cid);
    }
    return 'handled';
}

/** [pt19 v1.0 / S3.3] Proven clicks on this INSTALL (ev=result ok=1 of driven clicks), 0 after a Forget. */
function lrgDlgClicksOk(): int
{
    return max(0, (int) (lrgDlgGet('*install*')['clicks_ok'] ?? 0));
}

/**
 * [0.5.0 W6 / E3] ev=resume. [pt19 v1.0 / S1.1] The driver never hands a session back and never resumes one any more
 * (StopDriving is final for the session); an older script may still send it. A quiet logging stub (version skew).
 */
function lrgDlgOnResume(string $npc, array $kv, string $cid): string
{
    lrgDlgLog(sprintf('resume npc=%s sid=%s layer=%s - ignored (v1.0 never resumes a session; an older game script sent it)',
        $npc, (string) ($kv['sid'] ?? '-'), (string) ($kv['layer'] ?? '-')), $cid);
    return 'handled';
}

/**
 * [0.5.0 W14 / E8] THE FIRST NUMBER THAT INCLUDES HER VOICE. CHIM's own [PERF] marks stop at
 * llm_complete; they do not include TTS synthesis, the trip back to the game, or the moment her voice
 * actually starts - which is the thing the owner asked about. The game measures all four from the three
 * OnChim speech events it already handles and sends them here.
 * NEVER LLM-bearing: this is a fast command answered in preprocessing, measured at 2-7 ms server side.
 */
function lrgDlgOnLatency(string $npc, array $kv, string $cid): string
{
    if (empty(lrgDlgCfg('latency.accept', true))) { return 'handled'; }
    $row = ['at' => lrgNow(),
        'ask' => max(0, (int) ($kv['ask'] ?? 0)), 'reply' => max(0, (int) ($kv['reply'] ?? 0)),
        'voice' => max(0, (int) ($kv['voice'] ?? 0)), 'total' => max(0, (int) ($kv['total'] ?? 0)),
        'first' => (string) ($kv['first'] ?? '0') === '1' ? 1 : 0, 'sid' => (string) ($kv['sid'] ?? '0')];
    $st = lrgDlgState($npc);
    $keep = max(10, min(1000, (int) lrgDlgCfg('latency.keep', 200)));
    $ring = (array) ($st['lat'] ?? []);
    $ring[] = $row;
    if (count($ring) > $keep) { $ring = array_slice($ring, -$keep); }
    lrgDlgPut($npc, ['lat' => $ring]);
    if (!empty(lrgDlgCfg('latency.log', true))) {
        lrgDlgLog(sprintf('lat npc=%s total=%dms reply=%dms voice=%dms first=%d', $npc, $row['total'],
            $row['reply'], $row['voice'], $row['first']), $cid);
        $warn = (int) lrgDlgCfg('latency.warn_ms', 9000);
        if ($warn > 0 && $row['total'] > $warn) {
            lrgDlgLog('lat SLOW npc=' . $npc . ' total=' . $row['total'] . 'ms over=' . $warn
                . ' - the model is 85-95% of this wait on this install; see V05 section 20', $cid);
        }
    }
    return 'handled';
}

/** A new non-blank subtitle. De-duplicated on the PAIR (gen, string): shared DNAM responses repeat. */
function lrgDlgOnLine(string $npc, array $kv, string $text, string $cid): string
{
    $text = trim($text);
    if ($text === '') { return 'handled'; }
    $st = lrgDlgState($npc);
    $lines = (array) ($st['lines'] ?? []);
    $gen = (int) ($kv['gen'] ?? 0);
    $key = $gen . "\x1f" . $text;
    foreach ($lines as $l) {
        if ((string) ($l['k'] ?? '') === $key) { return 'handled'; }
    }
    $lines[] = ['k' => $key, 'gen' => $gen, 't' => substr($text, 0, 300), 'at' => lrgNow()];
    if (count($lines) > 6) { $lines = array_slice($lines, -6); }
    lrgDlgPut($npc, ['lines' => $lines]);
    lrgDlgLog('line npc=' . $npc . ' gen=' . $gen . ' t="' . substr($text, 0, 90) . '"', $cid);
    // [pt19h-money round 2 / G14] her figure may arrive after the list: a hand-over still waiting for its sum is priced now
    if (function_exists('lrgDlgProseReprice')) { lrgDlgProseReprice($npc, $cid); }
    return 'handled';
}

/** The settled outcome of one click: this is where <what_just_happened> comes from. */
function lrgDlgOnResult(string $npc, array $kv, string $txt, string $cid): string
{
    $st = lrgDlgState($npc);
    $sess = (array) ($st['session'] ?? []);
    $kind = (string) ($kv['kind'] ?? 'plain');
    $ok = (string) ($kv['ok'] ?? '1') === '1';
    $res = ['x' => (string) ($kv['x'] ?? ''), 'at' => lrgNow(), 'cid' => $cid, 'kind' => $kind, 'ok' => $ok,
        'why' => (string) ($kv['why'] ?? ''), 'closed' => (int) ($kv['closed'] ?? 0),
        'other' => (string) ($kv['other'] ?? ''), 'pos' => (int) ($kv['pos'] ?? -1), 'i' => (int) ($kv['i'] ?? -1),
        'dP' => (int) ($kv['dP'] ?? 0), 'dB' => (int) ($kv['dB'] ?? 0), 'dI' => (int) ($kv['dI'] ?? 0),
        'gold' => (int) ($kv['gold'] ?? 0), 'bribed' => (int) ($kv['bribed'] ?? 0), 'intim' => (int) ($kv['intim'] ?? 0),
        'bamt' => (int) ($kv['bamt'] ?? 0), 'wis' => (int) ($kv['wis'] ?? 0), 'sp' => (int) ($kv['sp'] ?? 0),
        'dur' => (float) ($kv['dur'] ?? 0), 'txt' => substr(trim($txt), 0, 200), 'told' => false];
    $entry = lrgDlgEntryByPos($sess, $res['pos'], $res['txt']);
    $res['norm'] = $entry ? (string) $entry['norm'] : lrgPromptNorm($res['txt']);
    $res['entry'] = $entry ? (string) $entry['text'] : $res['txt'];
    // [pt19c-B fix 1 / code + game reviews - one line in Lane A's function] the clicked row's topic EditorID and class: the reward
    // window reads *Reward* (S6.2 (1)), the quest-turn test skips a service / priced / back-out line (S6.1, lib/lrg_speech.php)
    $res['topic'] = $entry ? (string) ($entry['topic'] ?? '') : ''; $res['eclass'] = $entry ? (string) ($entry['class'] ?? '') : '';
    if (in_array($kind, ['persuade', 'intimidate', 'bribe'], true)) {
        $res['verdict'] = lrgDlgOutcome($res, $entry);
        $att = (array) ($st['attempts'] ?? []);
        // 'pg' is the player's PURSE at the attempt (what retry suppression compares against), 'gold' is
        // the delta this click moved. Comparing the purse against a delta is exactly how retry suppression
        // silently stops working.
        $att[$res['norm']] = ['kind' => $kind, 'result' => $res['verdict'], 'at' => lrgNow(), 'sp' => $res['sp'],
            'pg' => (int) (($st['facts'] ?? [])['pg'] ?? 0), 'gold' => $res['gold'], 'wis' => $res['wis'],
            'bamt' => $res['bamt']];
        lrgDlgPut($npc, ['attempts' => $att]);
    }
    // path[] is what a bounded re-walk may replay, and ONLY what it may replay
    $clean = ($res['dP'] === 0 && $res['dB'] === 0 && $res['dI'] === 0 && $res['gold'] === 0
        && $res['bribed'] === 0 && $res['intim'] === 0 && $res['closed'] === 0);
    $path = (array) ($sess['path'] ?? []);
    $path[] = ['pos' => $res['pos'], 'i' => $res['i'], 'text' => $res['entry'], 'norm' => $res['norm'],
        'kind' => $kind, 'result_clean' => $clean ? 1 : 0,
        'scripted' => $entry ? (int) ($entry['scripted'] ?? 0) : 0,
        'sayonce' => $entry ? (int) ($entry['sayonce'] ?? 0) : 0,
        'goodbye' => $entry ? (int) ($entry['goodbye'] ?? 0) : 0,
        'twat' => $entry ? (string) ($entry['twat'] ?? '') : '', 'sig' => (string) ($sess['sig'] ?? '')];
    if (count($path) > 8) { $path = array_slice($path, -8); }
    $sess['path'] = $path;
    // [pt19 v1.0 / S4.6, S8] auto=1: this click was an auto-advance (the breath after her line)
    $res['auto'] = (string) ($kv['auto'] ?? '') === '1' ? 1 : 0;
    lrgDlgPut($npc, ['last_result' => $res, 'session' => $sess]);
    // [pt19 v1.0 / S3.3] a verified click is the proof the route works on THIS install: the stage rail and the scene rail
    // read clicks_ok off the '*install*' row, and the log line quotes it for the owner's evening report
    $clicks = lrgDlgClicksOk();
    if ($ok) {
        $clicks++;
        lrgDlgPut('*install*', ['clicks_ok' => $clicks, 'clicks_at' => lrgNow()]);
    }
    lrgDlgLog(sprintf('result npc=%s x=%s kind=%s ok=%d why=%s verdict=%s dP=%d dB=%d dI=%d gold=%d closed=%d auto=%d clicks_ok=%d txt="%s"',
        $npc, $res['x'], $kind, $ok ? 1 : 0, $res['why'] ?: '-', (string) ($res['verdict'] ?? '-'), $res['dP'],
        $res['dB'], $res['dI'], $res['gold'], $res['closed'], $res['auto'], $clicks, substr($res['entry'], 0, 60)), $cid);
    return 'handled';
}

/**
 * Outcome classification, in THIS order (design 4.5): stat delta -> gold delta == bamt -> flags ->
 * the NPC's real line against the index's success/failure response -> the shown variant.
 * "No delta" is NOT proof of failure (~31 % of persuade successes never call FavorDialogueScript):
 * fall through to the line match, or report 'unknown' - and an unknown is told with NO verdict attached.
 */
function lrgDlgOutcome(array $res, ?array $entry): string
{
    $kind = (string) $res['kind'];
    // 1 stat delta. ArrestPersuade() increments Persuasions and THEN calls SetBribed(), so a flipped bribe
    // flag alone never means "bribe succeeded".
    if ($kind === 'persuade' && (int) $res['dP'] > 0) { return 'pass'; }
    if ($kind === 'intimidate' && (int) $res['dI'] > 0) { return 'pass'; }
    if ($kind === 'bribe' && (int) $res['dB'] > 0) { return 'pass'; }
    // 2 gold delta equals the engine's own price
    if ($kind === 'bribe' && (int) $res['bamt'] > 0 && abs((int) $res['gold']) === (int) $res['bamt']) { return 'pass'; }
    // 3 flags
    if ($kind === 'bribe' && (int) $res['bribed'] === 1 && (int) $res['dP'] === 0) { return 'pass'; }
    if ($kind === 'intimidate' && (int) $res['intim'] === 1) { return 'pass'; }
    // 4 the real line against the index's responses
    $line = strtolower(trim((string) ($res['line'] ?? '')));
    if ($line !== '' && $entry) {
        $succ = strtolower((string) ($entry['resp'] ?? ''));
        if ($succ !== '' && (strpos($line, substr($succ, 0, 24)) !== false)) {
            return ((string) ($entry['variant'] ?? '') === 'failure') ? 'fail' : 'pass';
        }
    }
    // 5 the shown variant
    if ($entry && (string) ($entry['variant'] ?? '') === 'failure') { return 'fail'; }
    if ($entry && (string) ($entry['variant'] ?? '') === 'success' && (int) $res['wis'] === 0 && $kind === 'intimidate') { return 'fail'; }
    return 'unknown';
}

function lrgDlgOnClosed(string $npc, array $kv, string $cid): string
{
    $st = lrgDlgState($npc);
    $sess = (array) ($st['session'] ?? []);
    $why = (string) ($kv['why'] ?? 'goodbye');
    $pending = (string) ($kv['pending'] ?? '0') === '1';
    // [pt19 v1.0 / S1.3, model F22] a closed menu is closed: the 'lost' state (a Tab-out with a layer read, shown next turn
    // as "waiting for an answer") and the assisted rail it fed are gone. `losses` stays, as a log counter only.
    $sess['state'] = 'closed';
    $sess['closed_at'] = lrgNow();
    $sess['why'] = $why;
    $losses = (int) ($st['losses'] ?? 0) + ($pending ? 1 : 0);
    $drift = (string) ($kv['drift'] ?? '');
    lrgDlgPut($npc, ['session' => $sess, 'losses' => $losses]);
    lrgDlgLog(sprintf('closed npc=%s why=%s pending=%d layer=%s losses=%d drift=%s', $npc, $why, $pending ? 1 : 0,
        (string) ($kv['layer'] ?? '?'), $losses, $drift !== '' ? $drift : '-'), $cid);
    if ($drift !== '' && $drift !== '0' && $drift !== '0,0,0,0,0') {
        lrgDlgLog('SPEECH GLOBAL DRIFT on close: ' . $drift . ' - upstream data bug (Little Lessons skips its '
            . '+10 restore whenever SpeechVeryHard >= 100). The glue patches nothing; report it to the owner.', $cid);
    }
    return 'handled';
}

/** The only place quest and speech facts travel outside a session. Throttled 60 s per NPC, game side. */
function lrgDlgOnFacts(string $npc, array $kv, string $cid): string
{
    $st = lrgDlgState($npc);
    $qobj = [];
    // NOT lrgCsv(): a quest EditorID is case sensitive and questlog is keyed by it
    foreach (array_filter(array_map('trim', explode(',', (string) ($kv['qobj'] ?? '')))) as $trip) {
        // <questEditorID>:<stage>:<objId>.<d><c><f>
        $p = explode(':', $trip);
        if (count($p) < 3) { continue; }
        $q = (string) $p[0];
        $objs = (array) ($qobj[$q]['obj'] ?? []);
        $objs[] = (string) $p[2];
        $qobj[$q] = ['stage' => (int) $p[1], 'obj' => $objs];
    }
    $patch = ['ref' => (string) ($kv['ref'] ?? ($st['ref'] ?? '')),
        'vt' => (string) ($kv['vt'] ?? ($st['vt'] ?? '')),
        'q' => lrgDlgIds((string) ($kv['q'] ?? '')), 'qobj' => $qobj,
        'facts' => lrgDlgFactsFrom($kv, (array) ($st['facts'] ?? []))];
    // [0.5.0 W3 / W4 - E2(e)] HER OWN PART in the quests she is an alias of. These are facts an NPC
    // would know about herself, never a spoiler about the quest: the clause is dropped entirely when
    // the quest has no questlog row (lrgDlgQuestKnown()).
    $qal = [];
    foreach (array_filter(array_map('trim', explode(',', (string) ($kv['qal'] ?? '')))) as $pair) {
        if ($pair === '-') { continue; }
        $p = strpos($pair, ':');
        if ($p === false) { continue; }
        $q = trim(substr($pair, 0, $p));
        $a = trim(substr($pair, $p + 1));
        if ($q === '' || $a === '') { continue; }
        $qal[] = ['q' => substr($q, 0, 60), 'alias' => substr($a, 0, 24)];
        if (count($qal) >= 6) { break; }
    }
    if ($qal || isset($kv['qal'])) { $patch['qal'] = $qal; }
    if (isset($kv['qgiver'])) { $patch['qgiver'] = (string) $kv['qgiver'] === '1' ? 1 : 0; }
    // [0.5.0 W10 / E6+E7] guard / bounty / crime faction. ABSENT means the server has NO bounty fact and
    // must not let her claim one (E7). bounty=0 with cf=- is "there is no crime faction here", which is
    // a different statement from "the key never arrived" - so both are stored explicitly.
    if (isset($kv['guard']) || isset($kv['bounty']) || isset($kv['cf'])) {
        $cf = trim((string) ($kv['cf'] ?? '-'));
        $patch['crime'] = [
            'guard' => (string) ($kv['guard'] ?? '0') === '1' ? 1 : 0,
            'bounty' => max(0, (int) ($kv['bounty'] ?? 0)),
            'cf' => ($cf === '' || $cf === '-') ? '' : substr($cf, 0, 10),
            'at' => lrgNow(),
        ];
    }
    lrgDlgPut($npc, $patch);
    $stale = lrgPromptMaybeStale();
    // [pt17] sq / sqj / cal / qst are logged as sent (the refuter's ask: the scene's OWNING quest must be visible on the
    // first in-game turn, so a wrong ambient assumption shows up in the log and not only in her words)
    lrgDlgLog(sprintf('facts npc=%s q=%s qobj=%d sp=%s perk=%s lvl=%s wis=%s dlg=%s qal=%d qgiver=%s guard=%s bounty=%s cal=%s sq=%s sqj=%s qst=%s%s',
        $npc, (string) ($kv['q'] ?? '-'), count($qobj), (string) ($kv['sp'] ?? '-'), (string) ($kv['perk'] ?? '-'),
        (string) ($kv['lvl'] ?? '-'), (string) ($kv['wis'] ?? '-'), (string) ($kv['dlg'] ?? '-'), count($qal),
        (string) ($kv['qgiver'] ?? '-'), (string) ($kv['guard'] ?? '-'), (string) ($kv['bounty'] ?? '-'),
        (string) ($kv['cal'] ?? '-'), (string) ($kv['sq'] ?? '-'), (string) ($kv['sqj'] ?? '-'), (string) ($kv['qst'] ?? '-'),
        $stale ? ' INDEX-STALE' : ''), $cid);
    return 'handled';
}

/**
 * The "again" turn (design 2.4). [pt19 v1.0 / S2.1] First contact never pays a second LLM turn any more: the open is
 * queued BEFORE the model runs and the list is answered on the fast path, so the game no longer requests lrg_dlgtalk.
 * With session.talk_again false (the default) an older script's request is answered here, pre-lock, and costs nothing.
 * talk_again true restores the retired admission (a fresh utterance, no open session, no scene) for a diagnosis only.
 */
function lrgDlgOnTalk(string $raw, array $gameRequest): string
{
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if (function_exists('lrgResolveRequestNpc')) { $npc = (string) lrgResolveRequestNpc(); }
    if ($npc === '') { lrgDlgLog('lrg_dlgtalk for an unknown NPC - dropped'); return 'handled'; }
    if (empty(lrgDlgCfg('session.talk_again', false))) {
        lrgDlgLog('dlgtalk npc=' . $npc . ' dropped: the "again" turn is retired (session.talk_again=false) - no LLM call');
        return 'handled';
    }
    $st = lrgDlgState($npc);
    $sess = (array) ($st['session'] ?? []);
    $utter = (array) ($st['utter'] ?? []);
    $age = lrgNow() - (int) ($utter['at'] ?? 0);
    $maxAge = (int) lrgDlgCfg('confirm.utter_window', 30);
    if (lrgDlgSessionOpen($sess) || (int) ($sess['scene'] ?? 0) === 1 || ($utter['text'] ?? '') === '' || $age > $maxAge) {
        lrgDlgLog('dlgtalk npc=' . $npc . ' dropped: a session is open, a scene runs, or no utterance within ' . $maxAge . 's');
        return 'handled';
    }
    $GLOBALS['LRG_DLG_TALK'] = ['npc' => $npc, 'utter' => $utter];
    lrgDlgLog('dlgtalk npc=' . $npc . ' admitted (session.talk_again=true, utterance ' . $age . 's old)');
    return 'pass';
}

// ================================================================== entry records
/**
 * One live list entry becomes a record: verbatim text, the INDEX's claims about it, and the class the gate
 * and the prompt use. class in plain|service|check|pay|commit|meta|silent|back|hidden.
 */
function lrgDlgDecorateEntries(array $entries, array $records, array $kv, array $facts): array
{
    $pg = (int) ($kv['pg'] ?? ($facts['pg'] ?? 0));
    $bamt = (int) ($kv['bamt'] ?? 0);
    // [pt19 v1.0 / S4.1] the sibling rule is about a CLOSED layer: a root list whose top-level topics happen to be scripted
    // (Hulda's OfferServicesTopic, DBRumorsTopic, RentRoomTopic) is not a branching choice, so its service line is not a
    // commit and a service by kind can click it (S5). A list the index cannot place keeps the old test (more than one entry).
    $closed = count($entries) > 1 && lrgPromptLayerKind($records) !== 'root';
    // [pt19 v1.0 / S4.1] THE HUB RULE, one loop over links per layer. A scripted entry whose links lead back to the layer
    // it sits on (after her answer the same list returns) is a hub QUESTION, not a commit, and is not a scripted sibling;
    // siblings count when they end the talk (goodbye), walk out (twat) or branch away (their links leave the layer, or
    // there are none - the list does not come back). "Leads back" = its links name >= 60 % (and >= 2) of the topics on
    // this live list. A norm several INFOs share (Delphine's C3Dragonborn: one INFO loops, one branches) is a hub when ANY
    // of them loops - the engine shows whichever passes its conditions, and a hub question is never irreversible.
    // [capability map U2] An Invisible Continue entry is graded scripted=1 here (its continuation may carry the fragment):
    // it never auto-advances, needs a shared word and counts as a scripted sibling.
    $hub = lrgDlgHubFlags($entries, $records);
    // [pt19h-grading / G3, COVERAGE G3] SIBLINGS THAT CONVERGE ARE ONE CHOICE. An Invisible Continue sibling whose own INFO
    // runs no fragment (scripted=0 in the index: its only effect is the continuation) counts once per DESTINATION - the set
    // of topics its continuation links. Karliah's three answers and Savos's two all continue into the same NPC topic: one
    // choice, so the layer is not a branching commit and his sentence clicks instead of costing a question. Two different
    // destinations still count twice, and every sibling with a fragment of its own counts on its own (its fragment may differ:
    // Faralda's eight school answers each run one, so they stay commits), so a real fork is graded as before - never less safe.
    // [pt19h-grading r2 / review P1] DORMANT until the gate has its key-mode rail: a converging sibling is still scripted (its
    // continuation runs the quest fragment - MG02 for Savos), and on her T-key the gate trusts the model's key on every plain
    // line with no test of his words, so "no, I'm not telling you anything about the orb" + T1 would click it. Until
    // grading.converge_plain is true (lrgDlgConvergeOn) the siblings count one by one and stay commits (she asks, as shipped).
    [$scriptedCount, $conv] = lrgDlgSiblingCount($records, $hub);
    $out = [];
    foreach ($entries as $idx => $e) {
        $rec = $records[$idx] ?? null;
        $text = (string) $e['text'];
        $norm = lrgPromptNorm($text);
        // [pt19h-grading / G16, r2 review BLOCKING] a HAND-OVER TAG's object ("(Give Journal)", "(Give Auriel's Bow)") is carried
        // as the row's `hand` - NEVER in its match norm. In the norm the object alone passed the S4.3 explicit test and the intent
        // pick: "I have Auriel's Bow, but you can't have it", "is that the unusual gem?" and "I'll keep the <object>" picked the
        // commit hand-over (Harkon's "Very well. (Give Auriel's Bow)"), and the verbatim-ish "very well, take it" / "take them" lost
        // their click. `hand` is for an object-aware hand-over rule with negation, question and keep guards (the matcher's owner).
        $hand = lrgDlgHandOverWords($text, $norm);
        $flags = is_array($rec) ? (array) ($rec['flags'] ?? []) : [];
        $row = [
            'pos' => (int) $e['pos'], 'i' => (int) $e['i'], 'new' => (int) $e['new'], 'col' => (int) $e['col'],
            'text' => $text, 'norm' => $norm, 'tail' => (int) ($e['tail'] ?? 0),
            'indexed' => is_array($rec) ? 1 : 0,
            // [pt19h-grading / G19] the INFO the index resolved (overrides match it: {"match": {"info": "skyrim.esm:04DE3C"}}),
            // whether its INFO links anywhere (G15), and whether it is one of a converging sibling set (G3; diagnostics)
            'info' => is_array($rec) ? (string) ($rec['info_key'] ?? '') : '',
            'linked' => is_array($rec) && !empty($rec['links']) ? 1 : 0,
            'conv' => !empty($conv[$idx]) ? 1 : 0,
            // [pt19h-grading r2 / G16] the hand-over tag's object words, match-only data ('' when none; never in `norm`)
            'hand' => $hand,
            // the TOPIC's kind when this very INFO implements no check: a shown FAILURE variant is still
            // part of a persuade / intimidate / bribe, which is what scoff-first and the label need.
            'kind' => is_array($rec) ? (string) (($rec['kind'] ?? '') !== '' ? $rec['kind'] : ($rec['topic_kind'] ?? '')) : '',
            'kind_src' => is_array($rec) && (string) (($rec['kind'] ?? '') !== '' ? $rec['kind'] : ($rec['topic_kind'] ?? '')) !== '' ? 'index' : '',
            'own_check' => is_array($rec) && (string) ($rec['kind'] ?? '') !== '' ? 1 : 0,
            'variant' => is_array($rec) ? (string) ($rec['variant'] ?? 'na') : 'na',
            'quest' => is_array($rec) ? (string) ($rec['quest'] ?? '') : '',
            'journal' => is_array($rec) ? (int) ($rec['journal'] ?? 0) : 0,
            'toplevel' => is_array($rec) ? (int) ($rec['toplevel'] ?? 0) : 0,
            'scripted' => is_array($rec) ? (int) ($rec['scripted'] ?? 0) : 0,
            'compound' => is_array($rec) ? (int) ($rec['compound'] ?? 0) : 0,
            'twat' => is_array($rec) ? (string) ($rec['twat'] ?? '') : '',
            // [0.5.0 / E6] the TOPIC EditorID, carried through so arrest detection can match the
            // DGCrime* family the --services census found. Costs nothing: the record already has it.
            'topic' => is_array($rec) ? (string) ($rec['topic'] ?? '') : '',
            'crit' => is_array($rec) ? (int) ($rec['crit'] ?? 0) : 0,
            'resp' => is_array($rec) ? (string) ($rec['resp'] ?? '') : '',
            'shared' => is_array($rec) ? (int) ($rec['shared'] ?? 1) : 1,
            'goodbye' => (int) ($flags['goodbye'] ?? 0), 'sayonce' => (int) ($flags['sayonce'] ?? 0),
            'walkaway' => (int) ($flags['walkaway'] ?? 0), 'invis' => (int) ($flags['invis'] ?? 0),
            'placeholder' => (int) ($flags['placeholder'] ?? 0),
            // [pt19 v1.0 / S4.1] a hub question (its links lead back to this layer) is never a commit by the sibling rule
            'hub' => !empty($hub[$idx]) ? 1 : 0,
            'fail_brawl' => is_array($rec) ? (int) ($rec['fail_brawl'] ?? 0) : 0,
            'fail_hard' => is_array($rec) ? (int) ($rec['fail_hard'] ?? 0) : 0,
            'unreachable' => is_array($rec) ? (int) ($rec['unreachable'] ?? 0) : 0,
            'cost' => 0,
        ];
        // THE VISIBLE TAG IS A SECOND SOURCE OF TRUTH. 54 rows in this load order show a (Persuade) /
        // (Intimidate) / (Bribe) tag and carry neither kind nor topic_kind in the index. Without this they
        // were graded as ordinary entries, labelled '[commits - cannot be undone]', and none of the
        // check rails (min words, afford, retry suppression, scoff-first) applied to them.
        if ($row['kind'] === '') {
            $row['kind'] = lrgDlgKindFromTag($text);
            if ($row['kind'] !== '') { $row['kind_src'] = 'tag'; }
        } elseif (is_array($rec) && (int) ($rec['ambiguous'] ?? 0) >= 2 && in_array($row['kind'], ['persuade', 'intimidate', 'bribe'], true)
            && lrgDlgKindFromTag($text) === '' && !lrgDlgOwnTopicChecks($rec, $text)) {
            // [pt19c fixer / walkthrough F3, F4] A CHECK KIND THE FAIL-SAFE MERGE LENT is no check: Irileth's "I have news from
            // Helgen about the dragon attack" shares its prompt with the Whiterun gate guard's (Persuade) row, and the lent
            // kind=persuade made the game settle her quest line as a Speech check (4 s, then a false "nothing came of that") and
            // put the 4-word check rail on it. The line's own topic carries no check and its live text shows no tag: it is
            // graded as what it is. The merge's RISK claims (crit, goodbye, scripted) are untouched - never less safe.
            $row['kind'] = '';
            $row['kind_src'] = '';
            $row['own_check'] = 0;
        }
        // [capability map U2] an Invisible Continue prompt is a VISIBLE choice whose continuation may carry the fragment
        if ($row['invis'] === 1) { $row['scripted'] = 1; }
        $cost = lrgPromptCost($text);
        if ($cost === -1) { $cost = $bamt > 0 ? $bamt : 0; }
        if ($cost === 0 && is_array($rec) && (int) ($rec['cost'] ?? 0) > 0) { $cost = (int) $rec['cost']; }
        // [pt19h-money / G14] a price outside a "(N gold)" tag: the line's own words ("Here's the 500 gold.", "Buy unusual gem for
        // 1000 gold.", "It's 2,000 coins."), or the sum she just named for a bare hand-over ("Here's the gold you wanted.") - so the
        // >= 100 septims ask and the afford rail run on it (lib/lrg_speech.php lrgDlgProseCost; never on a check line)
        if ($cost === 0 && $row['kind'] === '' && function_exists('lrgDlgProseCost')) { $cost = lrgDlgProseCost($text, (string) ($kv['npc'] ?? '')); $row['prose'] = lrgDlgProseKind($text, $cost); }
        $row['cost'] = max(0, $cost);
        $row['afford'] = $row['cost'] > 0 ? ($pg >= $row['cost'] ? 1 : 0) : 1;
        $row['class'] = lrgDlgClass($row, $closed, $scriptedCount, $pg);
        $row['commit'] = lrgDlgIsCommit($row, $closed, $scriptedCount, $pg);
        // [pt19c-A fix 1 / model R12, spec S4.3] a follower DISMISS / HOME (services.follower.verbs.<verb>.commit) is a commit
        // WHEREVER it is resolved - a T-key, the fast path, the model's words - and never explicit (fcommit): it always parks
        // and she asks. Before, the flag rode only on lrgDlgFollowerArbitrate's copy, so T2 or an E-press dismissed her at once.
        if (!empty(lrgDlgCfg('services.follower.enabled', true))) {
            $fv = lrgDlgFollowerVerbOf($row);
            if ($fv !== '' && !empty(lrgDlgCfg('services.follower.verbs.' . $fv . '.commit', false))) {
                $row['commit'] = true;
                $row['fcommit'] = 1;
            }
        }
        // [pt19h-grading / G20] an override may name a follower commit the verb table cannot see ("fcommit": true - Serana's
        // DLC1NPCMentalModelDismissTopic is on no *FollowerDismiss* glob): a commit wherever it is resolved, never explicit
        $ovf = lrgDlgOverrideFor('entries', $row);
        if ($ovf && !empty($ovf['fcommit'])) {
            $row['commit'] = true;
            $row['fcommit'] = 1;
        }
        // [pt19h-money round 2 / G14] a hand-over whose sum is not known yet asks before it pays (lib/lrg_speech.php lrgDlgProseMark)
        if (function_exists('lrgDlgProseMark')) { $row = lrgDlgProseMark($row); }
        $row['label'] = lrgDlgLabel($row, $pg);
        $out[] = $row;
    }
    return $out;
}

/**
 * [pt19c fixer / F3, F4] Does any index row of THIS record's own topic carry a check (kind or topic_kind)? One read of the rows
 * that share its prompt, only for an ambiguous check-kind entry with no visible tag. The rows whose own text IS the live line
 * decide when there are any (the layer tier may have kept another topic's row for the norm); else the record's own topic. A
 * topic the rows do not name keeps its kind (true: never less safe than the merge).
 */
function lrgDlgOwnTopicChecks(array $rec, string $live = ''): bool
{
    $tk = (string) ($rec['topic_key'] ?? '');
    $norm = (string) ($rec['norm'] ?? '');
    if ($norm === '') { return true; }
    $rows = (array) (lrgPromptRowsFor([$norm])[$norm] ?? []);
    $same = static fn(string $a, string $b): bool => strtolower(trim($a)) === strtolower(trim($b));
    $byText = array_values(array_filter($rows, static fn($r) => $live !== '' && $same((string) ($r['txt'] ?? ''), $live)));
    if ($byText) {
        $tks = array_map(static fn($r) => (string) ($r['topic_key'] ?? ''), $byText);
        $rows = array_values(array_filter($rows, static fn($r) => in_array((string) ($r['topic_key'] ?? ''), $tks, true)));
        $tk = '';
    } elseif ($tk === '') {
        return true;
    }
    $seen = false;
    foreach ($rows as $r) {
        if ($tk !== '' && (string) ($r['topic_key'] ?? '') !== $tk) { continue; }
        $seen = true;
        if ((string) ($r['kind'] ?? '') !== '' || (string) ($r['topic_kind'] ?? '') !== '' || lrgDlgKindFromTag((string) ($r['txt'] ?? '')) !== '') { return true; }
    }
    return !$seen;
}

/**
 * [pt19 v1.0 / S4.1] idx => true for every scripted (or Invisible Continue) entry of this live list whose links lead back
 * to it. One pass over the records' links; a norm that several INFOs share and whose chosen record branches away gets ONE
 * extra read of its sibling records (only then, and only for scripted entries of a closed list).
 */
function lrgDlgHubFlags(array $entries, array $records): array
{
    if (count($entries) < 2) { return []; }
    $tks = [];
    foreach ($records as $r) {
        if (is_array($r) && (string) ($r['topic_key'] ?? '') !== '') { $tks[(string) $r['topic_key']] = true; }
    }
    if (count($tks) < 2) { return []; }
    $need = max(2, (int) ceil(0.6 * count($tks)));
    $leadsBack = static function (array $links) use ($tks, $need): bool {
        $n = 0;
        foreach ($links as $l) { if (isset($tks[(string) $l])) { $n++; } }
        return $n >= $need;
    };
    $out = [];
    $retry = [];
    foreach ($records as $idx => $r) {
        if (!is_array($r)) { continue; }
        $rf = (array) ($r['flags'] ?? []);
        $sc = (int) ($r['scripted'] ?? 0) === 1 || (int) ($rf['invis'] ?? 0) === 1;
        if (!$sc) { continue; }
        $links = is_array($r['links'] ?? null) ? $r['links'] : (json_decode((string) ($r['links'] ?? ''), true) ?: []);
        if ($links && $leadsBack($links)) { $out[$idx] = true; continue; }
        if ((int) ($r['ambiguous'] ?? 0) >= 2 && (string) ($r['norm'] ?? '') !== '') { $retry[$idx] = (string) $r['norm']; }
    }
    if ($retry) {
        $rows = lrgPromptRowsFor(array_values(array_unique($retry)));
        foreach ($retry as $idx => $norm) {
            foreach ((array) ($rows[$norm] ?? []) as $alt) {
                $links = is_array($alt['links'] ?? null) ? $alt['links'] : (json_decode((string) ($alt['links'] ?? ''), true) ?: []);
                if ($links && $leadsBack($links)) { $out[$idx] = true; break; }
            }
        }
    }
    return $out;
}

/**
 * [pt19h-grading / G3] The S4.1 scripted-sibling COUNT of one live list, and idx => true for the siblings that converge.
 * A candidate is a scripted or Invisible Continue record that is no hub question. A candidate with a fragment of its own
 * (scripted=1), a goodbye, a walk-out (twat), a check, a price or crit 2 counts once, as before. An Invisible Continue candidate
 * whose own INFO runs nothing (scripted=0) is only its continuation, so such candidates count ONCE PER DESTINATION - the sorted
 * set of topics their links name: Karliah's three answers and Savos's two continue into one topic and are one choice; two
 * answers that continue into different topics are two (Kodlak's). With no candidate converging the count is the old one.
 */
function lrgDlgSiblingCount(array $records, array $hub): array
{
    $n = 0;
    $dest = [];
    foreach ($records as $idx => $r) {
        if (!is_array($r) || !empty($hub[$idx])) { continue; }
        $rf = (array) ($r['flags'] ?? []);
        $own = (int) ($r['scripted'] ?? 0) === 1;
        if (!$own && (int) ($rf['invis'] ?? 0) !== 1) { continue; }
        $links = is_array($r['links'] ?? null) ? $r['links'] : (json_decode((string) ($r['links'] ?? ''), true) ?: []);
        $links = array_values(array_unique(array_filter(array_map('strval', (array) $links), 'strlen')));
        sort($links);
        $bare = !$own && $links && (int) ($rf['goodbye'] ?? 0) === 0 && (string) ($r['twat'] ?? '') === ''
            && (string) ($r['kind'] ?? '') === '' && (int) ($r['cost'] ?? 0) === 0 && (int) ($r['crit'] ?? 0) < 2;
        if (!$bare) { $n++; continue; }
        $dest[implode('|', $links)][] = $idx;
    }
    // [pt19h-grading r2 / review P1] a destination counts ONCE only while the converge rule is on (lrgDlgConvergeOn); dormant,
    // every member counts as it did before G3 (the conv flags are still set: diagnostics, and the question rule of lrgDlgIsCommit)
    $merge = lrgDlgConvergeOn();
    $conv = [];
    foreach ($dest as $members) {
        $n += $merge ? 1 : count($members);
        if (count($members) >= 2) { foreach ($members as $idx) { $conv[$idx] = true; } }
    }
    return [$n, $conv];
}

/**
 * [pt19h-grading r2 / G3, review P1] Is the G3 converge rule ON? Only when config grading.converge_plain is true (default FALSE).
 * A converging sibling graded plain is still scripted (its continuation runs the fragment), and the gate picks a plain line on
 * the model's key with no test of his words. The switch belongs with a KEY-MODE RAIL in lrgDlgDecideEntry (the gate owner's):
 * for a plain scripted entry (conv=1) on a closed layer, lrgDlgNo when his utterance refuses, defers or hedges (lrgDlgRefuses /
 * lrgDlgDefers / lrgDlgHedges / lrgDlgQuoteQualm) or asks while the line does not. Turn it on in the same change as that rail.
 */
function lrgDlgConvergeOn(): bool
{
    return !empty(lrgDlgCfg('grading.converge_plain', false));
}

/**
 * [pt19h-grading / G16] The object words of a HAND-OVER tag in the live text that its norm does not carry yet, as normalised
 * tokens ('' when none): "(Give Journal)" -> "journal", "(Give Delvin the amulet)" -> "amulet", "(Show invitation)" ->
 * "invitation", "(Give Auriel's Bow)" -> "auriel's bow", "(Give Dragonstone to Farengar)" -> "dragonstone". The RECIPIENT is no
 * object (he does not name the one he is talking to, and each extra word thins every paraphrase's score): "... to <name>" and
 * "<name> the / his / her ..." keep only the object. A price ("(Give 100 gold)"), a token ("(Show <Alias=Evidence>)") or a
 * pronoun alone ("(Show her)") gives nothing. Words for MATCHING only; the line shown is the live text. [r2] They ride as the
 * row's `hand`, never in its norm: the object alone must never pass the explicit test or the intent pick (review BLOCKING).
 */
function lrgDlgHandOverWords(string $text, string $norm): string
{
    if (strpbrk($text, '([') === false
        || !preg_match_all('/[\(\[]\s*(?:give|show|hand over|hand|present|offer|return)\b([^\)\]]{0,60})[\)\]]/i', $text, $mm)) {
        return '';
    }
    static $skip = ['the' => 1, 'a' => 1, 'an' => 1, 'him' => 1, 'her' => 1, 'his' => 1, 'hers' => 1, 'them' => 1, 'their' => 1,
        'it' => 1, 'its' => 1, 'my' => 1, 'your' => 1, 'our' => 1, 'to' => 1, 'of' => 1, 'some' => 1, 'this' => 1, 'that' => 1,
        'these' => 1, 'those' => 1, 'over' => 1, 'back' => 1, 'and' => 1, 'for' => 1, 'on' => 1, 'in' => 1, 'at' => 1, 'with' => 1];
    $have = array_fill_keys(lrgDlgTokens($norm), true);
    $add = [];
    foreach ($mm[1] as $body) {
        if (preg_match('/<|\b(gold|septims?|coins?)\b/i', (string) $body)) { continue; }
        $body = trim((string) $body);
        if (preg_match('/^(.+?)\s+to\s+\S.*$/i', $body, $bm)) { $body = $bm[1]; }
        if (preg_match('/^[^\s"]+\s+(?:the|his|her|their|a|an)\s+(.+)$/i', $body, $bm)) { $body = $bm[1]; }
        foreach (lrgDlgTokens(lrgPromptNorm($body)) as $w) {
            if (strlen($w) < 3 || isset($skip[$w]) || isset($have[$w]) || strpos($w, '#') !== false) { continue; }
            $add[$w] = true;
        }
    }
    return implode(' ', array_keys($add));
}

/**
 * [pt19h r2 / G16, grading P4] He KEEPS what the line hands over, or denies having it: "I'll keep the X", "I'm keeping it", "you can't
 * have it", "it's mine", "not yours", "I'm not giving you the X", "I don't have the X", "I lost the X", "I no longer have it". A keep is
 * never a hand-over, whatever else his sentence says.
 */
function lrgDlgKeepsIt(string $utter): bool
{
    $low = ' ' . implode(' ', lrgDlgFoldTokens($utter)) . ' ';
    return (bool) preg_match('/ (?:ill keep|i will keep|im keeping|i am keeping|i keep|we keep|well keep|keeping (?:it|them|this|these|the|my)|'
        . '(?:its|theyre|thats|this is|these are) mine|not yours|(?:you|ya) (?:cant|cannot|wont|will not|can not|may not|shall not|shant) '
        . '(?:have|take|get|keep) (?:it|them|this|these|the|my)|(?:im|i am|were|we are) not (?:giving|handing|returning|showing|parting)|'
        . '(?:i|we) (?:wont|will not|cant|cannot|refuse to|am not going to|will never|would never|dont want to|do not want to) '
        . '(?:give|hand|return|show|part|surrender)|(?:i|we) (?:dont|do not|didnt|did not|never|no longer) (?:have|got|had|carry) '
        . '(?:it|them|the|that|this|those|these|any)|i (?:lost|misplaced|sold|destroyed|broke|no longer have|havent got|left) |'
        . 'i (?:gave|sold|traded) (?:it|them) (?:away|to)|hands off|mine now|(?:it|they|this|these) (?:stays|stay) with me|isnt yours|'
        . 'arent yours|is not yours|are not yours|not for you|not giving|not handing|never (?:give|hand)) /', $low);
}

/**
 * [pt19h r2 / G16 - THE OBJECT-AWARE HAND-OVER RULE] His words HAND OVER the object of this line's tag (the row's `hand`, read from
 * "(Give X)" / "(Show X)" / "(Hand over X)" by lrgDlgHandOverWords - never part of the norm) when they name it INSIDE A GIVING FRAME:
 * "here's the X", "here, take the X", "here are the X", "take the X", "I have the X for you", "I brought (you) the X", "I've got the
 * X right here", "this is the X", "you can have the X", "(I'll) give / hand / return / show you the X", "it's yours". The object ALONE
 * is no hand-over ("I have Auriel's Bow", "I have an invitation" - it stays with him: at most she asks), and nothing that takes it
 * back is one: a question ("do you want the X?", "is that the X?"), a keep or a denial (lrgDlgKeepsIt), his TAKING ("I'll take the
 * X"), a refusal, a deferral, a hedge, a bargain, a negated object ("not the fragments"). Matching data only: the line shown is the
 * live text. The test the grading review asked for before the object may count at all (review BLOCKING, G16).
 */
function lrgDlgHandsOver(string $utter, array $e): bool
{
    $hand = trim((string) ($e['hand'] ?? ''));
    if ($hand === '' || trim($utter) === '') { return false; }
    if (lrgDlgIsQuestion($utter) || lrgDlgRefuses($utter) || lrgDlgHedges($utter) || lrgDlgKeepsIt($utter)
        || preg_match(LRG_DLG_DEFER_RE, strtolower($utter)) || lrgDlgBargains($utter, $e)) { return false; }
    $ut = lrgDlgTokens(lrgPromptNorm(lrgDlgSttFold($utter, [$e])));
    if (!$ut) { return false; }
    // the object: a word of the tag of >= 4 letters, as said or in another form ("fragments" / "fragment", "bow" of "auriel's bow")
    $named = -1;
    foreach (lrgDlgTokens($hand) as $h) {
        $h = (string) $h;
        foreach ($ut as $k => $w) {
            if ($w === $h || (strlen($h) >= 4 && lrgDlgStemIn($h, [$w]))) { $named = (int) $k; break 2; }
        }
    }
    $low = ' ' . implode(' ', array_map(static fn($w) => str_replace("'", '', (string) $w), $ut)) . ' ';
    // his TAKING is no giving
    if (preg_match('/ (?:i|ill|i will|we|well|we will|can i|could i|may i|let me|lemme|gonna|im gonna|im going to|i want to|i wanna|should i) take /', $low)) {
        return false;
    }
    if ($named < 0) {
        // the object by its PRONOUN only inside a strong giving frame ("here, take them", "I return them with honor", "you can have it",
        // "it's yours") - never "give it a rest"
        return (bool) preg_match('/ (?:heres? (?:it|they|them|these|those|em)|take (?:it|them|these|this|those|em)|(?:give|hand|bring|brought|gave|handed) (?:it|them|these|this|those|em) (?:back|over)|'
            . '(?:you|ya) (?:can|may) (?:have|take|keep) (?:it|them|these|this|those|em)|(?:its|theyre|these are|this is|they are|it is) (?:all )?yours|'
            . '(?:i|we) (?:return|returned) (?:it|them|these|this|those|em)|here (?:it|they) (?:is|are)) /', $low);
    }
    if (lrgDlgNegatedAt($ut, $named, lrgDlgClauseStarts($utter))) { return false; }
    // "take <the object>" ("here, take Auriel's Bow"): the object within three words of an imperative take
    $tk = array_search('take', array_map(static fn($w) => str_replace("'", '', (string) $w), $ut), true);
    if ($tk !== false && $named > (int) $tk && $named - (int) $tk <= 3) { return true; }
    $frames = [
        '/ heres /', '/ here (?:is|are|you go|you are|ya go|it is|they are|the|your|my|it|them|these|those|this) /',
        '/(?:^| |, |please |just |go ahead |go on |you can |you may |now |so |here |well )take (?:it|them|em|this|these|the|your|my|a|an|one|back|what) /',
        '/ (?:i|ive|i have|we|weve|we have) (?:have |got |brought |carry |hold |found |do have )?(?:got |brought )?(?:you |ya )?(?:[a-z\'#]+ ){1,5}?for you /',
        '/ (?:i|ive|i have|we|weve) (?:brought|got|found|have|carry) (?:you|ya) /', '/ (?:you|ya) (?:can|may|should|could) (?:have|take|keep|hold) /',
        '/ (?:its|theyre|thats|this is|these are|the [a-z\'#]+ is|the [a-z\'#]+ are|they are|it is) (?:all )?yours /',
        '/ (?:i|ill|i will|let me|lemme|we|well|we will|im|i am|were|we are|allow me to|permit me to) (?:gonna |going to |just |now |here to )?'
            . '(?:give|giving|hand|handing|return|returning|present|presenting|show|showing|offer|offering|bring|bringing|surrender|surrendering|deliver|delivering) /',
        '/ this is (?:the|your|it|what you|for you|my) /', '/ these are (?:the|your|for you|them|what you) /', '/ for you /',
        '/ (?:i|we) (?:return|returned|give back|gave back|hand back|bring back|brought back) /',
        '/ (?:i|ive|i have|we|weve) (?:have |got |brought )?(?:the |your |this |these |them |it |an |a |some )?(?:[a-z\'#]+ ){1,3}?(?:with me|right here|on me|for ya) /',
        '/ (?:you|ya) (?:wanted|asked for|needed|were after|sent me for) (?:this|these|the|it|them|your) /', '/ (?:take|have) a look at (?:this|these|the|it|them|what) /',
    ];
    foreach ($frames as $rx) { if (preg_match($rx, $low)) { return true; } }
    return false;
}

/**
 * [pt19h r2 / G16] The ONE visible entry his words hand something over to (lrgDlgHandsOver), as an index into $entries, or null: two
 * lines that hand the same object over (Eorlund's "Here, take them." and "I return them with honor.") go to the one his own words say
 * more of, else - when they converge (the same continuation, `conv`) - to the first; else nothing, the model decides.
 */
function lrgDlgHandOverPick(array $entries, string $utter): ?int
{
    $cands = [];
    foreach ($entries as $i => $e) {
        $e = (array) $e;
        if ((string) ($e['class'] ?? '') === 'hidden' || trim((string) ($e['hand'] ?? '')) === '') { continue; }
        if (lrgDlgHandsOver($utter, $e) && !lrgDlgNegationClash($utter, $e)) { $cands[] = (int) $i; }
    }
    if (!$cands) { return null; }
    if (count($cands) === 1) { return $cands[0]; }
    $pool = [];
    foreach ($cands as $i) { $pool[] = (array) $entries[$i]; }
    $m = lrgDlgMatchText($utter, $pool);
    if ($m !== null && (float) $m['margin'] > 0.0 && (float) $m['f1'] > 0.0) { return $cands[(int) $m['i']]; }
    $conv = true;
    $hands = [];
    foreach ($pool as $p) { if (empty($p['conv'])) { $conv = false; } $hands[(string) ($p['hand'] ?? '')] = 1; }
    return ($conv && count($hands) === 1) ? $cands[0] : null;
}

/**
 * [pt19h-grading / G15, COVERAGE G15] A QUEST LINE that one service word would misgrade: indexed, its words carry no service
 * PHRASE (lrgDlgServiceKind: "What have you got for sale?", "I'd like to rent a room", "Can you train me in ..." keep their
 * class), its topic is no service topic, and it acts - scripted, Invisible Continue, or it leads on (links). Saadia's horse
 * lie, Vilkas's spar, Sam's second drink, the DA02 potion, Razelan's drink and Delvin's "Will you buy it?" are graded by the
 * commit heuristics instead of as a service. An ambient leaf line ("Where can I get a drink around here?" with no links) and
 * every unindexed line keep the word rule.
 */
function lrgDlgWordOnlyQuestLine(array $e): bool
{
    if ((int) ($e['indexed'] ?? 0) !== 1) { return false; }
    if (lrgDlgServiceKind((string) ($e['text'] ?? '')) !== '') { return false; }
    $topic = (string) ($e['topic'] ?? '');
    // [pt19h-grading r2 / review G15 reach] the shop, fence and trade openers keep their class by TOPIC: "Looking to trade."
    // (0WindhelmDialogueGeneralDarkElfShopTopic), "Just looking to sell today." (...AxeshopSell), "Looking to move some goods.
    // Discreetly." (...SootFenceTopic), "I need you to trade something with me, Zeus." (DialogueFollowerTradeTopic), "Could you
    // show me my room?" (TAIF_ShowRoomDialogueTopic). Not a bare "Trade": Inigo's InigoSteedTrade* gives his horse away.
    if ($topic !== '' && preg_match('/(OfferServices|Services|RentRoom|Trainer|Training|Barter|Vendor|Merchant|Carriage|Ferry|Stable|'
        . 'Shop|Fence|TradeTopic|LetsTrade|ShowRoom)/i', $topic)) {
        return false;
    }
    return (int) ($e['scripted'] ?? 0) === 1 || (int) ($e['invis'] ?? 0) === 1 || !empty($e['linked']);
}

/**
 * [pt19h-grading / COVERAGE G8, Brotherhood C15] An ENTRY that backs out (class back): the shipped phrases anywhere in the line
 * ("I'm not sure.", "Fine, forget it.", "I'll come back later."), with two exceptions that were never back-outs. "nothing" counts
 * only LEADING the line (after leading tags and up to two lead-ins) with no clause of its own after it - "Nothing.", "Nothing for
 * now.", "Nothing more.", "Nothing else."; never Astrid's "I did what had to be done. Nothing more.", "I have nothing to hide",
 * "The Thalmor know nothing about the dragons", "I'll do nothing of the sort" or "Nothing I couldn't handle." And "not sure"
 * that opens a clause of its own is an answer: "We're not sure, but they have an Elder Scroll."
 */
function lrgDlgEntryBacksOut(string $text): bool
{
    if (preg_match('/\b(never ?mind|forget (i|it)|not (sure(?!,?\s+but\b)|yet|right now|now)|need (more )?time|'
        . 'come back later|maybe later|another time|i(\'| a)?ll think|on second thought|no thanks)\b/i', $text)) {
        return true;
    }
    $t = trim((string) preg_replace('/^\s*(?:[\(\[][^\)\]]{1,40}[\)\]]\s*)+/u', '', $text));
    $lead = '(?:(?:well|oh|um|uh|er|hmm|hm|ah|actually|sorry|no|nah|okay|ok|alright|all right|perhaps|maybe|thanks|thank you)'
        . '[\s,.!\x{2026}-]+){0,2}';
    return (bool) preg_match('/^\W*' . $lead . 'nothing(?!\s+(?:i|we|you|he|she|they|it|that|this|to|of|but|like|compared|worth|'
        . 'could|would|can|will|is|was|has|wrong|left)\b)\b/iu', $t);
}

/** The class vocabulary of design 3.3. First hit wins. */
function lrgDlgClass(array $e, bool $closed, int $scriptedCount, int $pg): string
{
    $ov = lrgDlgOverrideFor('entries', $e);
    if ($ov && isset($ov['class']) && in_array((string) $ov['class'], LRG_DLG_CLASSES, true)) { return (string) $ov['class']; }
    $low = strtolower($e['text']);
    // [capability map U2] only a placeholder or an empty text is hidden. The Invisible Continue flag belongs to the NPC's
    // RESPONSE (she carries on without a new choice); the prompt itself is on his screen - 2,105 prompts on 1,003 layers,
    // Faralda's "what do you seek" and Irileth's "give the message directly to the jarl" among them. It is graded scripted.
    if ($e['placeholder'] || $e['text'] === '') { return 'hidden'; }
    foreach ((array) lrgDlgCfg('meta_tags', []) as $t) {
        if (strpos($low, '(' . $t . ')') !== false || strpos($low, '[' . $t . ']') !== false) { return 'meta'; }
    }
    if ($e['kind'] !== '') { return 'check'; }
    // The index is the first authority, but it is not the only one. 54 rows in this load order carry a
    // visible (Persuade) / (Intimidate) / (Bribe) tag in their own text and have kind='' AND
    // topic_kind='' - e.g. the Whiterun gate guard's "Stand aside, or else. (Intimidate)". Without this
    // fallback they were graded by lrgDlgIsCommit() and shown as '[commits - cannot be undone]' rather
    // than as the attempt the player can see they are. The tag is the game's own label: trust it.
    // (Rows built by lrgDlgDecorateEntries() already carry the derived kind and never reach this line;
    // it is here so a DIRECT lrgDlgClass() call answers the same way.)
    if (lrgDlgKindFromTag((string) $e['text']) !== '') { return 'check'; }
    if (preg_match('/\(\s*remain silent\s*\)|\(\s*say nothing\s*\)/i', $e['text'])) { return 'silent'; }
    if (lrgDlgIsBackOut($e['text'])) { return 'back'; }
    if ($e['cost'] > 0) { return 'pay'; }
    // [pt19h-grading / G15] ONE service word is no service on a quest line: Saadia's "There's a horse waiting at the stables"
    // (the betrayal), Vilkas's "So you're supposed to train me?" (the spar), Sam's "A second drink." and Delvin's "Will you buy
    // it?" were class service, so the commit ask and S4.5 never ran on them (lrgDlgWordOnlyQuestLine)
    if (lrgDlgIsService($low) && !lrgDlgWordOnlyQuestLine($e)) { return 'service'; }
    if (lrgDlgIsCommit($e, $closed, $scriptedCount, $pg)) { return 'commit'; }
    return 'plain';
}

/**
 * The check kind the ENTRY'S OWN VISIBLE TAG claims, or ''. Only the three kinds this load order's engine
 * really implements (the builder's census: persuade / intimidate / bribe - no mod adds a fourth), because
 * an invented kind would reach lrgDlgEmit()'s kind= and <what_just_happened>'s verdict wording.
 * A tag inside a longer parenthesis counts ("(Persuade - Speech 50)", "(Bribe 100 gold)").
 */
function lrgDlgKindFromTag(string $text): string
{
    if (!preg_match('/[\(\[]\s*(persuade|persuasion|intimidate|intimidation|bribe|bribery)\b[^\)\]]*[\)\]]/i', $text, $m)) {
        return '';
    }
    $w = strtolower($m[1]);
    if (strncmp($w, 'persua', 6) === 0) { return 'persuade'; }
    if (strncmp($w, 'intimid', 7) === 0) { return 'intimidate'; }
    return 'bribe';
}

/**
 * A back-out. For an ENTRY's text (class `back`) the shipped list, unchanged. [pt19 v1.0 / S4.5 step 0, ai S2] For the
 * player's SPOKEN words ($spoken) it is widened with the breath's hesitations and the plain refusals
 * (research/pt19c-language.md R1/R2): "need to think", "let me think", "give me a moment", "hold on", "hang on", "one
 * moment", "not so fast", "changed my mind", "cancel that", "I'd rather not", "not interested", "I'll pass", "no thank
 * you", "I don't want to"; and `nothing` only when it LEADS ("nothing for now"), never inside a sentence ("I have nothing to
 * lose, count me in"). A hesitation that carries a question ("hold on, what do you mean a contract?") is judged on the
 * question (G6). The hesitations stay OFF the entry side: an entry "Not so fast." may be a real branch, not a back-out.
 */
function lrgDlgIsBackOut(string $text, bool $spoken = false, bool $narrow = false): bool
{
    if (!$spoken) {
        // [pt19h-grading / COVERAGE G8 shape, Brotherhood C15] the ENTRY side (lrgDlgEntryBacksOut): "nothing" anywhere in the
        // line made Astrid's report "I did what had to be done. Nothing more." and Tullius's "Nothing I couldn't handle." class
        // back (LEAVE took them), and "not sure" did the same to "We're not sure, but they have an Elder Scroll."
        return lrgDlgEntryBacksOut($text);
    }
    // [pt19c-A fix 2 / language + game review, round 2] the QUESTION PREAMBLE - "I have a question", "I've got a question
    // for you", "can I ask you something" - is judged like G6's hesitations: when a question FOLLOWS it, the words without
    // the preamble are judged ("I have a question, who are they?" asks: her answer plays, the breath re-arms, a park stays
    // parked); alone ("... for you", "... first") it defers - no answer to her question: nothing is released, a park is
    // dropped, the breath waits for the question he announced. First person only: "do you have a question for me?" asks her.
    if (preg_match('/\b(?:(?:i|we)(?:\'?ve| have| had)?(?: got| do have| still have)?\s+(?:a|one|another|some|a few|a couple of|'
        . 'one more|a quick|one quick|one last)\s+(?:(?:more|quick|small|last|few)\s+)?questions?|'
        . '(?:can|could|may) i ask(?: you)? (?:something|a question))\b/i', $text, $pm, PREG_OFFSET_CAPTURE)) {
        $at = (int) $pm[0][1];
        $rest = trim((string) preg_replace('/^\W*(?:(?:for|to) you|first|then|though|here|now)\b/i', '',
            substr($text, $at + strlen((string) $pm[0][0]))));
        if (lrgDlgTokens(lrgPromptNorm($rest)) && lrgDlgIsQuestion($rest)) {
            return lrgDlgIsBackOut(trim(substr($text, 0, $at) . ' ' . $rest), true, $narrow);
        }
        return true;
    }
    // [pt19c-A fix 1 / language review 1, 9; ai review "consent"] the deferrals the STT really delivers: "I will think about
    // it", "I'll sleep on it", "I need some time". [fix 2] "think about it" only with HIS deferring head ("I'll have to /
    // I must / let me ... think about it"): unanchored it matched "what do you think about it?" and the persuasion "think
    // about it, there's no point earning all that gold..." (TG00's line, language brief) - a question and an argument
    if (preg_match('/\b(never ?mind|forget (i|it)|not (sure|yet|right now|now)|need (more |some |a little |a bit of )?time|'
        . 'come back later|maybe later|another time|i(\'| a| wi)?ll think|on second thought|no thanks|'
        . 'need to think|let me think|changed my mind|cancel that|rather not|not interested|i\'?ll pass|no thank you|'
        . 'i(\'ll| will) (consider|sleep on)|sleep on it|'
        . '(i(\'ll| will|\'d| would)?( (need|have|want|like|ought|got))? to|i must|i should|i gotta|i\'?m (gonna|going to)( have to)?|'
        . 'lemme) think (about (it|that|this)|it over|this over|that over|on it|it through)|'
        . 'don\'?t want to|do not want to)\b/i', $text)) { return true; }
    // [pt19h-safety / G10, G1] the refusals that lead with a verb of his own: "let's not", "better not", "we'd better not",
    // "not today", "some other time" ("let's not" released Brynjolf's "Then let's get to it." on step 5)
    // [pt19h r2 / safety P19] ... LEADING his reply (after a filler or a no), or the whole of it - never inside an assent ("yes, we'd better
    // not keep them waiting", "yes, and don't get lost on the way"; pt19c-language R1 / R2 anchor not / no / never the same way)
    if (preg_match('/^\W*((uh|um|er|erm|well|oh|so|hey|hmm|no|nah|nope)\W+){0,2}(let\'?s not|let us not|(we|i|you)(\'d| had)? better not|not today|not this time|some other time|go away|leave me alone|get lost)\b/i', $text)) {
        return true;
    }
    // [pt19h-safety review] ... and a BARE "better not" (with no pronoun, the whole sentence): it released a parked commit on S4.4's
    // "fuller answer" rule; "better not keep him waiting" is no refusal and keeps its words
    if (preg_match('/^\W*((no|nah|hmm|uh|um|well)\W+)?better not\W*$/i', $text)) { return true; }
    $hes = '/^\W*(hold on|hang on|one moment|not so fast|give me an? (moment|minute|second))\b/i';
    if (preg_match($hes, $text)) {
        // G6: "hold on, what do you mean a contract, who am I supposed to kill?" asks, it does not refuse
        return !preg_match('/\?\s*["\')\]]*\s*$/', trim($text));
    }
    // idioms of ASSENT that lead with a negator: "no problem", "no worries", "not a problem", "why not"
    // [pt19h r2 / extended TG08A.karliah.possess, urag.elderscroll] ... and the "no" that opens no refusal: "no one should have it", "no joke,
    // you can have it", "no wonder", "no kidding", "no matter what", "no idea" (a leading "no, one moment" keeps its comma and refuses)
    if (preg_match('/^\W*((uh|um|er|well|oh|so|hey|sure|yes|yeah|okay|ok)\W+){0,2}(no (problem|worries|doubt|one|joke|wonder|kidding|matter|idea|offense|offence|rush|hurry)'
        . '|not a problem|why not)\b/i', $text)) {
        return false;
    }
    // $narrow (the breath, S4.6 - language review 4): R1's widened heads (i don't / i do not / i can't / not) and "never
    // heard" say what he does not know or cannot do - they refuse a RELEASE, never the auto-advance of an unscripted line
    if ($narrow) {
        return (bool) preg_match('/^\W*((uh|um|er|well|oh|so|hey)\W+){0,2}(nothing|nah|no|nope|stop|never(?! heard)|wait\W*$|'
            . 'i refuse|i won\'?t|i will not|absolutely not|hell no|no way)\b/i', $text);
    }
    return (bool) preg_match('/^\W*((uh|um|er|well|oh|so|hey)\W+){0,2}(nothing|nah|no|nope|stop|never|not|wait\W*$|'
        . 'i refuse|i won\'?t|i will not|i do not|i don\'?t|i can\'?t|absolutely not|hell no|no way)\b/i', $text);
}

/**
 * [pt19c-A fix 1 / ai review, architect P1] He is LEAVING: "goodbye", "I have to go", "I want to leave now". Never a reason
 * to click the line in front of her, and never a reason to re-arm the breath on it.
 */
function lrgDlgLeaveWords(string $text): bool
{
    return (bool) preg_match('/\b(good ?bye|farewell|bye|i (have|need|must|want|got|ought) to (go|leave|be going)|'
        . 'i(\'m| am) (leaving|off|going now)|gotta go|i should (go|leave|be going)|i(\'ll| will) be (going|on my way)|'
        . 'see you (later|around|soon))\b/i', $text);
}

function lrgDlgIsService(string $low): bool
{
    foreach ((array) lrgDlgCfg('service_words', []) as $w) {
        if (preg_match('/\b' . preg_quote((string) $w, '/') . '\b/', $low)) { return true; }
    }
    return false;
}

/**
 * Irreversible ('commit') heuristics, FIRST HIT WINS (design 4.5). The UI-only word list is LAST on purpose:
 * it has 50 % recall and 28 % false alarms, so it may only ever speak when the index said nothing.
 */
function lrgDlgIsCommit(array $e, bool $closed, int $scriptedCount, int $pg): bool
{
    $ov = lrgDlgOverrideFor('entries', $e);
    if ($ov && array_key_exists('never_auto', $ov)) { return (bool) $ov['never_auto']; }
    // [pt19 v1.0 / S4.1, S4.5] `commit: true` (config/lrg_dialogue_overrides.default.json): MQ102BStormcloakOath4 is the
    // oath's last line and carries no Goodbye flag, so no heuristic can see it
    if ($ov && !empty($ov['commit'])) { return true; }
    // [pt19c fixer / walkthrough M6, M7, W4] A SERVICE LINE - its own words ask for trade, training, a room, a ride or a crossing
    // (lrgDlgServiceKind: "What have you got for sale?", "I'd like training in Alchemy.", "I'd like to hire your carriage.") -
    // opens a window or a price list he can close: never irreversible by the scripted / goodbye / sibling heuristics (the
    // carriage hire borrowed Better Carriage Destinations' scripted+goodbye, a trainer's two lines made each other commits on a
    // list the index calls closed - "train me in alchemy" asked first, and she was told "[commits - cannot be undone]"). Its
    // PRICE still makes it a commit (below: >= confirm.min_gold or a quarter of his purse), and so do the overrides and tags.
    $svcLine = in_array(lrgDlgServiceKind((string) $e['text']), ['barter', 'train', 'inn', 'carriage', 'ferry'], true);
    if ($e['indexed'] && !$svcLine) {
        if ($e['scripted'] && $e['goodbye']) { return true; }
        // [pt19 v1.0 / S4.1] WALK-AWAY IS NOT A COMMIT, and neither is crit 1: a walk-away flag guards LEAVING a list,
        // never clicking (the leave guard, S4.2); crit 2 stays LETHAL. A hub question is not a scripted sibling.
        if ($closed && $scriptedCount >= 2 && $e['scripted'] && empty($e['hub'])) { return true; }
        // [pt19h-grading / G3] a converging sibling that is a QUESTION stays a commit: his statement is no answer to it on a
        // scripted line (S4.5's shape rule), and only the commit path keeps "it's just a coincidence" off "I don't understand.
        // What coincidence?" and "I believe you" off "Why should I believe you?" - a near-miss never clicks a scripted line
        if ($closed && !empty($e['conv']) && lrgDlgEntryIsQuestion($e)) { return true; }
    }
    $low = strtolower($e['text']);
    foreach ((array) lrgDlgCfg('commit_tags', []) as $t) {
        if (strpos($low, '(' . $t . ')') !== false || strpos($low, '[' . $t . ']') !== false) { return true; }
    }
    if ($e['cost'] > 0) {
        $min = (int) lrgDlgCfg('confirm.min_gold', 100);
        $frac = (float) lrgDlgCfg('confirm.gold_fraction', 0.25);
        if ($e['cost'] >= $min || ($pg > 0 && $e['cost'] >= $frac * $pg)) { return true; }
    }
    if (!$e['indexed'] && !$svcLine) {
        // LAST: the UI-only fallback
        if ($closed && $e['new']) { return true; }
        foreach ((array) lrgDlgCfg('choice_words', []) as $w) {
            if (preg_match('/\b' . preg_quote((string) $w, '/') . '\b/', $low)) { return true; }
        }
    }
    return false;
}

/** The label that replaces the leading tag. It comes from the INDEX kind, never from the text tag. */
function lrgDlgLabel(array $e, int $pg): string
{
    $ov = lrgDlgOverrideFor('entries', $e);
    if ($ov && !empty($ov['label'])) { return (string) $ov['label']; }
    switch ($e['kind']) {
        case 'persuade':
            return '[persuasion attempt]';
        case 'intimidate':
            return $e['fail_brawl'] ? '[threat - refusing means a brawl]' : '[threat]';
        case 'bribe':
            $n = (int) $e['cost'];
            if ($n <= 0) { return '[a bribe]'; }
            // [pt19c-A fix 1 / language brief 6.3, owner rule] septims: she echoes her labels
            return $e['afford'] ? sprintf('[bribe: costs %d septims; the player has %d]', $n, $pg)
                : sprintf('[bribe: costs %d septims; the player cannot pay]', $n);
    }
    if ($e['class'] === 'pay') {
        return $e['afford'] ? sprintf('[costs %d septims]', (int) $e['cost'])
            : sprintf('[costs %d septims - the player cannot pay]', (int) $e['cost']);
    }
    if ($e['class'] === 'silent') { return '[says nothing]'; }
    // [pt19h r2 / safety P12] "[leave]" only on a line LEAVE really clicks (lrgDlgRealBackOut); a class-back line it will not is unlabelled
    if ($e['class'] === 'back') { return $e['crit'] >= 1 ? '[leaving now ends this]' : (lrgDlgRealBackOut($e) ? '[leave]' : ''); }
    if ($e['class'] === 'meta') { return '[out-of-character option]'; }
    if ($e['commit']) { return '[commits - cannot be undone]'; }
    if ($e['class'] === 'service') { return '[a service]'; }
    return '';
}

/** First matching rule of the data-only override file, or null. */
function lrgDlgOverrideFor(string $section, array $e): ?array
{
    foreach ((array) (lrgDlgOverrides()[$section] ?? []) as $rule) {
        $m = (array) ($rule['match'] ?? []);
        $ok = true;
        foreach ($m as $k => $pat) {
            $have = (string) ($k === 'text' ? ($e['text'] ?? '') : ($e[$k] ?? ''));
            if (!lrgGlob((string) $pat, $have)) { $ok = false; break; }
        }
        if ($ok) { return $rule; }
    }
    return null;
}

function lrgDlgEntryByPos(array $sess, int $pos, string $txt): ?array
{
    foreach ((array) ($sess['entries'] ?? []) as $e) {
        if ((int) $e['pos'] === $pos) { return $e; }
    }
    if ($txt !== '') {
        $n = lrgPromptNorm($txt);
        foreach ((array) ($sess['entries'] ?? []) as $e) {
            if ((string) $e['norm'] === $n) { return $e; }
        }
    }
    return null;
}

// ================================================================== cache / freshness
function lrgDlgLoc(string $npc): string
{
    $s = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
    return is_array($s) ? (string) ($s['loc'] ?? '') : '';
}

/** max(questlog.rowid) at harvest: a new journal row invalidates a cached root list. */
function lrgDlgQsig(): int
{
    if (isset($GLOBALS['LRG_DLG_TEST_QUESTLOG'])) {
        return (int) ($GLOBALS['LRG_DLG_TEST_QSIG'] ?? count((array) $GLOBALS['LRG_DLG_TEST_QUESTLOG']));
    }
    $db = lrgDb();
    if (!$db) { return 0; }
    try {
        $r = $db->fetchOne('SELECT max(rowid) AS m FROM questlog');
        return (int) ($r['m'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/** The cached ROOT list, or null when it is stale (age, location change, journal change). */
function lrgDlgRoot(string $npc, array $st): ?array
{
    $root = (array) ($st['root'] ?? []);
    if (!$root || !($root['entries'] ?? [])) { return null; }
    $age = lrgNow() - (int) ($root['at'] ?? 0);
    if ($age > (int) lrgDlgCfg('cache.max_age_seconds', 1800)) { return null; }
    if ((string) ($root['loc'] ?? '') !== '' && lrgDlgLoc($npc) !== '' && (string) $root['loc'] !== lrgDlgLoc($npc)) { return null; }
    $qsig = lrgDlgQsig();
    if ((int) ($root['qsig'] ?? 0) !== 0 && $qsig !== 0 && (int) $root['qsig'] !== $qsig) { return null; }
    return $root;
}

/**
 * [pt19 v1.0 / model F23] Is this session OPEN - on his screen right now? state open AND its newest layer no older than the
 * game's own 900 s session cap: a lost ev=closed (a load, a crash) must not leave "the list is on screen" true for ever,
 * nor block every pre-LLM open for that NPC.
 */
function lrgDlgSessionOpen(array $sess): bool
{
    if ((string) ($sess['state'] ?? '') !== 'open') { return false; }
    return lrgNow() - (int) ($sess['at'] ?? 0) <= max(60, (int) lrgDlgCfg('session.open_seconds', 900));
}

/**
 * [pt19 v1.0 / S1.3, model R1] Why the server may not pick on this open session, or ''. The list is still READ (the model
 * sees it as facts, never as keys) and her words say "choose it on the list yourself - I cannot pick for you here" in the
 * same turn: stopped (the game gave the menu back, F2), quiet (a curated intro runs, F17), vis (drv=0: the game does not
 * drive it - lethal, a journal scene before the route proof, module off, Smart Talk skip, an unknown menu), and a
 * journal-quest scene (sj=1) until this install has one verified click (scene-unproven) or while session.drive_scene is off.
 */
function lrgDlgReadOnlyWhy(array $sess, string $npc): string
{
    if (!lrgDlgSessionOpen($sess)) { return ''; }
    if (!empty($sess['stopped'])) { return 'stopped'; }
    if ((int) ($sess['quiet'] ?? 0) === 1 || lrgDlgQuietOn($npc)) { return 'quiet'; }
    if ((int) ($sess['drv'] ?? 1) === 0) { return 'vis'; }
    if ((int) ($sess['sj'] ?? 0) === 1) {
        if (empty(lrgDlgCfg('session.drive_scene', true))) { return 'drive_scene off'; }
        if (lrgDlgClicksOk() < 1) { return 'scene-unproven'; }
    }
    return '';
}

/**
 * [pt19 v1.0 / S3.3] THE STAGE RAIL (probation). Until one click is verified on this install the server clicks ONLY an
 * indexed plain (or back) line with no script, no price, no check and no goodbye - the first live click is a harmless line
 * the player asked for, on a menu he can see. True = this entry may NOT be picked yet. Kind picks obey it too.
 */
function lrgDlgStageRailBlocks(array $e): bool
{
    if (empty(lrgDlgCfg('session.stage_rail', true)) || lrgDlgClicksOk() >= 1) { return false; }
    return !((int) ($e['indexed'] ?? 0) === 1 && in_array((string) ($e['class'] ?? ''), ['plain', 'back'], true)
        && (int) ($e['scripted'] ?? 0) === 0 && (int) ($e['cost'] ?? 0) === 0 && (string) ($e['kind'] ?? '') === ''
        && (int) ($e['goodbye'] ?? 0) === 0);
}

/**
 * [pt19 v1.0 / S2.3] The game refused the last do=open for this NPC within open.refused_seconds (120): ['why','at'] or [].
 * Written by the funcret handler (lib/lrg_actions.php, Lane B) for the OpenBlockedReason / FailOpen reasons only (F4);
 * read here (the pre-LLM open is not retried, and the direct-barter net comes back) and by lrgFacQuestPlan (10.26).
 */
function lrgDlgOpenRefused(array $st): array
{
    $r = (array) ($st['open_refused'] ?? []);
    if (!$r) { return []; }
    if (lrgNow() - (int) ($r['at'] ?? 0) > max(5, (int) lrgDlgCfg('open.refused_seconds', 120))) { return []; }
    return $r;
}

/** Which list this turn shows: ['kind' => root|pending|none, 'entries' => [], 'n' => n, 'sent' => n]. */
function lrgDlgListFor(string $npc, array $st): array
{
    $sess = (array) ($st['session'] ?? []);
    if (lrgDlgSessionOpen($sess) && ($sess['entries'] ?? [])) {
        return ['kind' => 'pending', 'entries' => (array) $sess['entries'], 'n' => (int) ($sess['n'] ?? 0),
            'sent' => (int) ($sess['sent'] ?? 0), 'sid' => (string) ($sess['sid'] ?? ''), 'gen' => (int) ($sess['gen'] ?? 0),
            'layer_kind' => (string) ($sess['kind'] ?? 'closed'), 'pg' => (int) ($sess['pg'] ?? 0)];
    }
    $root = lrgDlgRoot($npc, $st);
    if ($root !== null) {
        return ['kind' => 'root', 'entries' => (array) $root['entries'], 'n' => (int) ($root['n'] ?? 0),
            'sent' => count((array) $root['entries']), 'sid' => '0', 'gen' => 0, 'layer_kind' => 'root'];
    }
    return ['kind' => 'none', 'entries' => [], 'n' => 0, 'sent' => 0, 'sid' => '0', 'gen' => 0, 'layer_kind' => 'unknown'];
}

// ================================================================== ranking
/**
 * A CLOSED layer shows ALL entries (cap 12, engine order, never bucketed). A ROOT list shows a ranked top 8
 * plus "(+N more)". Ranking runs over head UNION tail, never over the head alone (R12) - which is exactly
 * what keeps a Missives topic at position 19 reachable by voice.
 */
function lrgDlgRank(array $entries, string $layerKind, string $utter, array $q): array
{
    $vis = array_values(array_filter($entries, static fn($e) => (string) $e['class'] !== 'hidden'));
    if ($layerKind === 'closed') {
        return [array_slice($vis, 0, (int) lrgDlgCfg('entries.closed_cap', 12)), array_slice($vis, (int) lrgDlgCfg('entries.closed_cap', 12))];
    }
    $w = (array) lrgDlgCfg('rank', []);
    $uw = lrgPromptWords(lrgPromptNorm($utter));
    $qLow = array_map('strtolower', $q);
    $scored = [];
    foreach ($vis as $i => $e) {
        $s = 0.0;
        if ($e['quest'] !== '' && in_array(strtolower((string) $e['quest']), $qLow, true)) { $s += (float) ($w['quest'] ?? 4); }
        if ($e['journal']) { $s += 1; }
        if ($e['new']) { $s += (float) ($w['new'] ?? 3); }
        if ($e['kind'] !== '' || $e['cost'] > 0) { $s += (float) ($w['tagged'] ?? 2); }
        if ($e['class'] === 'service') { $s += (float) ($w['service'] ?? 2); }
        if ($uw) {
            $ew = lrgPromptWords((string) $e['norm']);
            $hit = $ew ? count(array_intersect($uw, $ew)) / max(1, count($uw)) : 0.0;
            $s += $hit * (float) ($w['similarity'] ?? 6);
        }
        if ($e['quest'] !== '' && lrgAnyGlob((array) lrgDlgCfg('filler_patterns', []), [(string) $e['quest']])) { $s += (float) ($w['filler'] ?? -3); }
        if ((int) $e['shared'] > (int) ($w['shared_over'] ?? 20)) { $s += (float) ($w['shared'] ?? -2); }
        $scored[] = ['s' => $s, 'i' => $i, 'e' => $e];
    }
    usort($scored, static fn($a, $b) => ($b['s'] <=> $a['s']) ?: ($a['i'] <=> $b['i']));
    $head = (int) lrgDlgCfg('entries.head', 8);
    $keep = array_map(static fn($x) => $x['e'], array_slice($scored, 0, $head));
    $rest = array_map(static fn($x) => $x['e'], array_slice($scored, $head));
    // the shown head keeps the engine's own order, so the player's screen and the prompt agree
    usort($keep, static fn($a, $b) => (int) $a['pos'] <=> (int) $b['pos']);
    return [$keep, $rest];
}

// ================================================================== the turn
/**
 * Build $GLOBALS['LRG_DLG_TURN'] and decide what this turn may offer. Registered in functions.php at brace
 * depth 0, so it runs on BOTH SHARMAT branches.
 */
function lrgDlgPrepareTurn(): void
{
    unset($GLOBALS['LRG_DLG_TURN']);
    if (!lrgDlgEnabled()) { return; }
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    $isSpeech = in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
    $isTalk = $type === 'lrg_dlgtalk';
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($npc === '') { return; }
    lrgDlgEnsureSchema();
    $st = lrgDlgState($npc);
    // the cid must be unique per REQUEST, not per second: the two-step confirmation releases only when the
    // second selection arrives from a DIFFERENT request, and two turns can easily land in the same second.
    static $seq = 0;
    $seq++;
    $cid = 'd' . substr(md5($npc . '|' . (string) ($GLOBALS['gameRequest'][1] ?? '') . '|' . lrgNow() . '|' . $seq), 0, 8);
    $utterText = lrgStripContext((string) ($GLOBALS['gameRequest'][3] ?? ''));
    if ($isSpeech && trim($utterText) !== '') {
        // [pt19 v1.0 / model F8] the sentence carries its request's cid: the list its own pre-LLM open produces comes back
        // with that cid (lrg_topics cid=), which is how the fast path knows the sentence is this list's (S4.3 rev2)
        lrgDlgPut($npc, ['utter' => ['text' => substr(trim($utterText), 0, 400), 'at' => lrgNow(), 'type' => $type, 'cid' => $cid]]);
        $st = lrgDlgState($npc);
    }
    $snap = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
    $facts = (array) ($st['facts'] ?? []);
    $sess = (array) ($st['session'] ?? []);
    $list = lrgDlgListFor($npc, $st);
    // Phase 2's gates, from Phase 2's OWN state - never from $GLOBALS['LRG_TURN'] (design 6.3)
    // [pt19 v1.0 / model F6] while a session is OPEN a scene never switches the module off: the list is on his screen and
    // sj / drv / clicks_ok / drive_scene decide (read-only, or the scene rail). With no open session the SNAPSHOT's scene
    // flag decides, as before (D-17), unless the scene is ambient.
    $open = lrgDlgSessionOpen($sess);
    $snapScene = (int) (($snap['scene'] ?? 0) ?: 0) === 1;
    $scene = $open ? (int) ($sess['scene'] ?? 0) === 1 : $snapScene;
    $ostim = (int) (($snap['ostim'] ?? 0) ?: 0) === 1;
    $crit = (int) ($sess['crit'] ?? 0);
    $q = array_values(array_filter((array) ($st['q'] ?? [])));
    $turn = [
        'npc' => $npc, 'cid' => $cid, 'type' => $type, 'speech' => $isSpeech, 'talk' => $isTalk,
        'on' => true, 'list' => $list['kind'], 'entries' => [], 'tail' => [], 'n' => (int) $list['n'],
        'sent' => (int) $list['sent'], 'sid' => (string) $list['sid'], 'gen' => (int) $list['gen'],
        'layer_kind' => (string) $list['layer_kind'], 'offer' => [], 'parked' => (array) ($st['parked'] ?? []),
        'crit' => $crit, 'scene' => $scene ? 1 : 0, 'ostim' => $ostim ? 1 : 0, 'open' => $open ? 1 : 0,
        'facts' => $facts, 'q' => $q, 'mute' => false, 'why' => [],
        'result_block' => '', 'check' => null, 'price' => null, 'snap' => is_array($snap) ? $snap : [],
        'list_pg' => (int) ($list['pg'] ?? 0),
        // [pt19 v1.0] ro: why this open session is read-only ('' = the server may pick); single: the ONE visible entry of
        // a closed layer (S4.5); hint2: the two keys the two-candidate hint names (S5); open_pending: this turn queued the
        // pre-LLM open (S2.1)
        'ro' => '', 'single' => null, 'hint2' => [], 'open_pending' => 0, 'clicks_ok' => lrgDlgClicksOk(),
    ];
    // scene / OStim: no business action, no block, and the model may not invite the player to speak (D-17)
    // [pt17] ... unless the scene is AMBIENT and no session is open: then her facts, her faction role and her locked lines
    // stay ON. [pt19 v1.0 / S2.2] and the narrow marker may open on her (the game refuses only a journal-quest scene).
    $turn['ambient'] = 0;
    $amb = ($snapScene && !$ostim && !$open) ? lrgDlgSceneAmbient($npc, $st) : ['ambient' => false, 'why' => ''];
    if (!empty($amb['ambient'])) {
        $turn['ambient'] = 1;
        $turn['why'][] = (string) $amb['why'];
    } elseif ((!$open && $snapScene) || $ostim) {
        $turn['on'] = false;
        $turn['why'][] = $ostim ? 'an intimate scene is running'
            : 'the speaker is in a scene' . ((string) $amb['why'] !== '' ? ' (' . $amb['why'] . ')' : '');
        $turn['list'] = 'none';
    }
    $mode = lrgDlgMode($npc, $q);
    if ($mode === 'vanilla') {
        $turn['on'] = false;
        $turn['why'][] = 'override: this NPC is in vanilla mode';
        $turn['list'] = 'none';
    }
    if ($turn['on'] && $open && (string) $list['kind'] === 'pending') {
        $turn['ro'] = lrgDlgReadOnlyWhy($sess, $npc);
        if ($turn['ro'] !== '') { $turn['why'][] = 'read-only session (' . $turn['ro'] . ')'; }
    }
    if ($turn['on'] && ($isSpeech || $isTalk)) {
        [$head, $tail] = lrgDlgRank((array) $list['entries'], (string) $list['layer_kind'],
            (string) ($st['utter']['text'] ?? ''), $q);
        $turn['entries'] = $head;
        $turn['tail'] = $tail;
        $keys = [];
        $max = (int) lrgDlgCfg('entries.keys', 12);
        // [pt19 v1.0 / S1.3] a read-only session offers NO key: the entries are facts, nothing is offered that cannot be
        // clicked (the gate would refuse it anyway, and a refused pick is a false sentence)
        foreach ($turn['ro'] === '' ? $head : [] as $i => $e) {
            if ($i >= $max) { break; }
            $keys['T' . ($i + 1)] = ['pos' => (int) $e['pos'], 'i' => (int) $e['i'], 'norm' => (string) $e['norm'],
                'text' => (string) $e['text'], 'class' => (string) $e['class'], 'kind' => (string) $e['kind'],
                'cost' => (int) $e['cost'], 'commit' => $e['commit'] ? 1 : 0, 'crit' => (int) $e['crit']];
        }
        $turn['offer'] = ['turn_cid' => $cid, 'keys' => $keys, 'at' => lrgNow(), 'sid' => (string) $list['sid'],
            'gen' => (int) $list['gen'], 'list' => (string) $list['kind']];
        if ($keys) { lrgDlgPut($npc, ['offer' => $turn['offer']]); }
        $turn['single'] = lrgDlgSingleEntryOf($turn);
    }
    // the free-conversation check (plan 6.10) runs only when no engine entry of that kind is listed
    if ($turn['on'] && $isSpeech) { $turn['check'] = lrgDlgCheck($turn, lrgDlgIntentOf($turn)); }
    // [pt19c-A fix 1 / CHIM review] NOT on our own click's funcret: CHIM builds (and throws away - followup off,
    // processor/funcret.php) a whole prompt for each ExtCmdLRG_SelectTopic funcret, which lands AFTER its ev=result - telling
    // the result there marked it told and the player's next turn, the only carrier left (lrg_dlgtalk is retired), lost it
    $selfFuncret = $type === 'funcret'
        && stripos(lrgStripContext((string) ($GLOBALS['gameRequest'][3] ?? '')), 'command@' . LRG_ACT_TOPIC . '@') === 0;
    $turn['result_block'] = $selfFuncret ? '' : lrgDlgGroundTruth($npc, $st);
    $turn['quests'] = lrgDlgQuestBlock($turn);
    // ---------------------------------------------------------------- [0.5.0] E2 / E6 / E7 turn data
    $utter = (string) ($st['utter']['text'] ?? '');
    $turn['crime'] = (array) ($st['crime'] ?? []);
    $turn['qal'] = (array) ($st['qal'] ?? []);
    $turn['qgiver'] = (int) ($st['qgiver'] ?? 0);
    // E2(b)(c): "what can I ask you" / "what should I do next" - answered in words, nothing clicked
    $turn['ask'] = ($turn['on'] && ($isSpeech || $isTalk)) ? lrgDlgAskKind($utter) : '';
    // E6: the service kind the player's own words ask for, and the slot verdict on a live price list
    $turn['svc'] = ['kind' => '', 'slot' => null, 'mode' => '', 'slots' => [], 'offlist' => [], 'pricelist' => 0,
        'direct' => 0, 'direct_why' => '', 'vendor' => 'unknown'];
    if ($turn['on'] && ($isSpeech || $isTalk) && lrgDlgServicesOn($npc)) {
        // [pt19c-B fix 1 / lang review P5 - one token in Lane A's function] the NEGATION-guarded kind: "I don't need a room" and
        // "can't get a room around here" are no Rent_Room directive (the kind pick and the pre-LLM open already use it)
        $turn['svc']['kind'] = lrgDlgServiceKindSaid($utter);
        $live = array_merge((array) $turn['entries'], (array) $turn['tail']);
        $slot = $live ? lrgDlgServiceSlot($live, $utter, (string) $turn['svc']['kind'], $npc) : null;
        if (is_array($slot)) {
            $turn['svc']['pricelist'] = 1;
            $turn['svc']['mode'] = (string) $slot['mode'];
            $turn['svc']['slot'] = $slot['entry'] ?? null;
            $turn['svc']['slots'] = (array) ($slot['slots'] ?? []);
            if ((string) $slot['mode'] === 'pick') { $turn['svc']['slots'] = [(string) $slot['slot']]; }
            // every slot name the layer really has, so the truth gate can spot one she invented
            // [pt19c-A fix 1 / usefulness review, U3] a days list's slots are named from the LIVE text, digits kept ("1 day",
            // "2 days") - the norm folds every one of them to "# days", and she was told only those two names existed
            $all = [];
            foreach ($live as $e) {
                $tok = lrgDlgTokens((string) ($e['norm'] ?? ''));
                if (!$tok || count($tok) > (int) lrgDlgCfg('services.slot.max_words', 3)) { continue; }
                if (preg_match('/^# days?$/', implode(' ', $tok)) && preg_match('/(\d+)/', (string) ($e['text'] ?? ''), $dm)) {
                    $all[] = (int) $dm[1] . ((int) $dm[1] === 1 ? ' day' : ' days');
                    continue;
                }
                $all[] = implode(' ', $tok);
            }
            $turn['svc']['all'] = array_values(array_unique($all));
            // a destination the CENSUS knows but THIS list does not: naming one of those is a false claim.
            // The NARROW list (carriage / ferry topics only) is used here on purpose - a priced one-word
            // entry on some unrelated layer is not somewhere anyone can be taken (risk R15).
            $off = [];
            foreach ((array) (lrgDlgServiceCatalog()['destinations_travel'] ?? []) as $d) {
                $n = lrgPromptNorm((string) $d);
                if ($n !== '' && !in_array($n, $turn['svc']['all'], true)) { $off[] = $n; }
            }
            $turn['svc']['offlist'] = array_slice(array_values(array_unique($off)), 0, 60);
        }
        // the driver's own verdict (W13) against ours - a diagnostic, nothing branches on it
        $drv = (string) ($sess['svck'] ?? '');
        if ($drv !== '') {
            $ours = lrgDlgServiceKindOfLayer($live);
            if (($ours === 'pricelist') !== ($drv !== '-' && $drv !== '')) {
                lrgDlgLog('svck drift npc=' . $npc . ' driver=' . $drv . ' server=' . ($ours ?: '-'), $cid);
            }
        }
        // [0.5.7 / pt16] DIRECT BARTER: decided once, here, from the player's own words and Phase 2's
        // own state. The directive (lrgDlgServiceRequestBlock) and the post-gate net
        // (lrgDlgServiceNet) both read this and nothing else.
        if ((string) $turn['svc']['kind'] === 'barter') {
            $d = lrgDlgServiceDirect($turn, $utter, is_array($snap) ? $snap : null, $isSpeech);
            $turn['svc']['direct'] = (int) $d['direct'];
            $turn['svc']['direct_why'] = (string) $d['why'];
            $turn['svc']['vendor'] = (string) $d['vendor'];
        }
    }
    // ---- [pt19-purchase] begin: an ORDER of food or drink, from her real stock on the snapshot (lib/lrg_market.php) ----
    // Decided here, after the service kind and before the hide policy / the locked facts / the directive read it. On an
    // active state the barter net stands down (the carrier is the real route this turn); an order with no fresh stock
    // falls back to the barter window (the pt16 route) instead of a refusal she cannot truthfully give.
    $turn['buy'] = ['state' => ''];
    if ($turn['on'] && $isSpeech && lrgDlgServicesOn($npc)) {
        $turn['buy'] = lrgMktPlan($turn, $utter, $isSpeech);
        $bs = (string) ($turn['buy']['state'] ?? '');
        // [pt19c-A fix 1 / architect P5, model F18] the fast path runs in ANOTHER request (an E-press within the window): it
        // reads this marker and clicks no service line beside the order
        if (in_array($bs, LRG_MKT_ACTIVE, true)) { lrgDlgPut($npc, ['mkt_order' => ['cid' => $cid, 'at' => lrgNow()]]); }
        if (in_array($bs, LRG_MKT_ACTIVE, true) && (string) $turn['svc']['kind'] === 'barter') {
            $turn['svc']['direct'] = 0;
            $turn['svc']['direct_why'] = 'voice buy';
        } elseif ($bs === 'no-stock' && (string) $turn['svc']['kind'] === '') {
            $turn['svc']['kind'] = 'barter';
            $d = lrgDlgServiceDirect($turn, $utter, is_array($snap) ? $snap : null, $isSpeech);
            $turn['svc']['direct'] = (int) $d['direct'];
            $turn['svc']['direct_why'] = (string) $d['why'];
            $turn['svc']['vendor'] = (string) $d['vendor'];
        }
    }
    // ---- [pt19-purchase] end ----
    // [pt19 v1.0 / S5, ai S7] the two-candidate hint: over THIS turn's offer only, from his words, never on a price list
    if ($turn['on'] && $isSpeech && !empty($turn['offer']['keys']) && empty($turn['svc']['pricelist'])) {
        $turn['hint2'] = lrgDlgTwoCandidates($turn, $utter);
    }
    // [0.5.1] the follower verbs really on this turn's list, and whether the player asked for one.
    // Costs one pass over the live entries and only while the module is on for this NPC.
    $turn['fol'] = ['verbs' => [], 'asked' => '', 'resolved' => 0];
    if ($turn['on'] && ($isSpeech || $isTalk) && lrgDlgFollowerOn($npc)) {
        $live = array_merge((array) $turn['entries'], (array) $turn['tail']);
        $byVerb = $live ? lrgDlgFollowerEntries($live) : [];
        $turn['fol']['verbs'] = array_keys($byVerb);
        $hits = lrgDlgFollowerIntents($utter);
        if ($hits) {
            $turn['fol']['asked'] = (string) $hits[0]['verb'];
            foreach ($hits as $h) {
                if (count((array) ($byVerb[(string) $h['verb']] ?? [])) === 1) { $turn['fol']['resolved'] = 1; break; }
            }
        }
    }
    // E6: is this an arrest? Three independent routes; a missed one is a voice line that jails the player
    $turn['arrest'] = ($turn['on'] && lrgDlgServicesOn($npc)) ? lrgDlgArrestClass($turn) : '';
    // ---- [0.5.7 / pt16-legion] begin: the enlistment ask and this NPC's role for it (lib/lrg_factions.php) ----
    $turn['faction'] = lrgFacTurn($turn, $utter, $isSpeech || $isTalk);
    // ---- [0.5.7 / pt16-legion] end ----
    // E7: the facts the game has confirmed this moment
    $turn['locked'] = ($turn['on'] && ($isSpeech || $isTalk)) ? lrgDlgLockedFacts($turn) : [];
    // ---- [0.5.7 / pt16-legion] begin: the faction class ALONE rides a rechat turn inside the window ---------
    // (16:38:43: the "Report at the bell" double-down came on a rechat, which carries no locked facts by design)
    if (!$turn['locked'] && $turn['on'] && !empty($turn['faction']['asked'])) { $turn['locked'] = lrgFacLockedFacts($turn); }
    // ---- [0.5.7 / pt16-legion] end ----
    // [pt19 v1.0 / S2.1] THE PRE-LLM OPEN: first contact never pays a second LLM turn. Decided AFTER the market plan (a
    // voice order owns its turn, F18) and the enlistment turn (the road rule refuses an open, S2.2).
    if ($turn['on'] && $isSpeech && !$open) {
        $openKind = '';
        $pre = lrgDlgPreOpen($turn, $utter, $st, $openKind);
        if ($pre !== '') {
            $turn['open_pending'] = 1;
            $turn['open_kind'] = $openKind;
            $turn['why'][] = 'pre-LLM open (' . $pre . ')';
            // the real entry is about to win: no barter window beside it this turn (the net is held back)
            if (!empty($turn['svc']['direct'])) { $turn['svc']['direct'] = 0; $turn['svc']['direct_why'] = 'open pending'; }
        }
    }
    $GLOBALS['LRG_DLG_TURN'] = $turn;
    lrgDlgApplyOffer($turn);
    // [0.4 integrator] CHIM's own 5 s request poll reaches this function too, with no speaker and no
    // request type ("npc=(actor) type="), and it produced one turn line every five seconds - about 700
    // an hour of pure noise in the log the owner has to grep during a playtest. Nothing is suppressed
    // that carries information: a real turn always has a speaker AND a type. Everything else still logs,
    // "on=1 list=none" for a real NPC included, because that one IS a diagnostic.
    $placeholder = ((string) $turn['type'] === '')
        && (((string) $turn['npc'] === '') || strcasecmp((string) $turn['npc'], '(actor)') === 0);
    if (!empty(lrgDlgCfg('log_turn')) && !$placeholder) { lrgDlgLog(lrgDlgTurnLine($turn), $cid); }
}

/**
 * [pt19 v1.0 / S4.5] The ONE visible entry of a closed layer on his screen (the oath lines, "So what do you need me to
 * do?", "The Greybeards?"), when it is indexed and class plain or commit - or null. Such a layer never asks: his answer
 * releases it (lrgDlgSingleEntryRelease), or it advances by itself when it is unscripted (S4.6).
 */
function lrgDlgSingleEntryOf(array $t): ?array
{
    if ((string) ($t['list'] ?? '') !== 'pending' || (string) ($t['layer_kind'] ?? '') === 'root') { return null; }
    $vis = array_values(array_filter(array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])),
        static fn($e) => (string) ($e['class'] ?? '') !== 'hidden'));
    if (count($vis) !== 1) { return null; }
    $e = $vis[0];
    if ((int) ($e['indexed'] ?? 0) !== 1 || !in_array((string) ($e['class'] ?? ''), ['plain', 'commit'], true)) { return null; }
    return $e;
}

/**
 * [pt19 v1.0 / S5, ai S7] Two of THIS turn's keys fit his words within match.hint_gap (0.10) of each other, both at the
 * fast-path floor: ['T<a>', 'T<b>'] or []. The prompt then tells her to ask which, naming both; a park written on that
 * turn remembers it (her "?" asks WHICH, and a bare assent is no answer to it - S4.4).
 */
function lrgDlgTwoCandidates(array $t, string $utter): array
{
    $keys = (array) (($t['offer'] ?? [])['keys'] ?? []);
    if (count($keys) < 2 || trim($utter) === '') { return []; }
    $pool = [];
    $names = [];
    foreach ($keys as $k => $v) { $pool[] = ['norm' => (string) ($v['norm'] ?? '')]; $names[] = (string) $k; }
    $m = lrgDlgMatchText($utter, $pool);
    if ($m === null || !empty($m['short']) || $m['j'] === null) { return []; }
    $min = (float) lrgDlgCfg('match.min_score', 0.55);
    if ((float) $m['score'] < $min || (float) $m['second'] < $min) { return []; }
    if ((float) $m['score'] - (float) $m['second'] > (float) lrgDlgCfg('match.hint_gap', 0.10)) { return []; }
    return [$names[(int) $m['i']], $names[(int) $m['j']]];
}

/**
 * [pt19 v1.0 / S2.1, S2.2, model row 1] THE PRE-LLM OPEN. On a player speech turn with no open session for this NPC, the
 * narrow marker's first clause queues do=open (D2 only; the DLL polls it while the model is still writing) and sets
 * open_pending, so the list is on screen and answered on the fast path from his own sentence: no second paid turn.
 * Returns the clause that fired ('' = no open). Guards, in order: io on, not quiet (F17), the game did not refuse the last
 * open (S2.3), the enlistment road rule (lrgFacRefusesOpen), the marker, and - while no click is verified on this
 * install - only a clause whose predicted row passes the stage rail (F19: a join or a kind open would show the menu for
 * nothing; those turns fall to the direct-barter net and her own words as shipped).
 */
function lrgDlgPreOpen(array $t, string $utter, array $st, ?string &$openKind = null): string
{
    $openKind = '';
    $npc = (string) $t['npc'];
    $cid = (string) $t['cid'];
    if (trim($utter) === '') { return ''; }
    if (!lrgDlgMcm('io', !empty(lrgDlgCfg('intent_open')) ? 1 : 0, $npc)) { return ''; }
    // the real entry can only win where the driver can really run the session (ml = bMenuless && !bDlgDryRun, the same
    // condition every "the real entry wins" hide is under, 10.10): under the dry run or with the module off, 10.15's own
    // direct net and her words answer, as shipped
    if ((int) lrgDlgMcm('ml', 1, $npc) !== 1) { return ''; }
    if (lrgDlgQuietOn($npc)) { lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - quiet mode (a curated intro runs)', $cid); return ''; }
    // [pt19c-A fix 1 / game review 2, architect P5, model F18] a VOICE ORDER of food or drink owns this turn (10.27): no menu
    // beside it, whatever clause would fire - her market line and ExtCmdLRG_Buy answer it
    if (function_exists('lrgMktState') && in_array(lrgMktState($t), LRG_MKT_ACTIVE, true)) {
        lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - a voice order owns this turn (10.27)', $cid);
        return '';
    }
    $snap = is_array($t['snap'] ?? null) ? (array) $t['snap'] : [];
    // [pt19c-A fix 1 / game review 3, usefulness review U1] an ESCORT order owns this sentence (10.20) when the escort carries
    // it - a stranger it walks, an SFF companion or CHIM's ghost it routes: the menu would take the camera and her movement
    // while ExtCmdLRG_Escort moves her ("let's go", "follow me, I need your help"). A teammate / custom follower's own
    // follower line is hers to answer (10.20 stands aside for it), so her kind clause may still open.
    if (function_exists('lrgIntentEscort') && lrgIntentEscort($utter) !== null) {
        $owner = lrgDlgCompanionOwner($npc, $snap ?: null);
        if ($owner === '' || (function_exists('lrgEscortSffRouted') && lrgEscortSffRouted($owner))) {
            lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - an escort order owns this sentence (10.20)', $cid);
            return '';
        }
    }
    // [pt19c-A fix 1 / game review 6] the game refuses an open beyond fOpenDistance and in combat (OpenBlockedReason): a
    // doomed do=open would hold back 10.15's barter net and swap her answer for the bridge hedge, for nothing
    if ($snap && (int) ($snap['_age'] ?? 9999) <= 30 && isset($snap['dist']) && (string) $snap['dist'] !== ''
        && (int) $snap['dist'] > (int) lrgDlgCfg('open.max_distance', 200)) {
        lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - she is ' . (int) $snap['dist'] . ' units away (the game opens within '
            . (int) lrgDlgCfg('open.max_distance', 200) . ')', $cid);
        return '';
    }
    if ($snap && (string) ($snap['combat'] ?? '0') === '1'
        && (int) ($snap['_age'] ?? 9999) <= max(5, (int) lrgDlgCfg('services.direct.combat_max_age_seconds', 90))) {
        lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - combat (the game opens no menu in a fight)', $cid);
        return '';
    }
    $ref = lrgDlgOpenRefused($st);
    if ($ref) {
        lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - the game refused the last open ' . (lrgNow() - (int) ($ref['at'] ?? 0))
            . 's ago (' . substr((string) ($ref['why'] ?? '?'), 0, 60) . ')', $cid);
        return '';
    }
    // [pt19c-A fix 1 / code review, capability map CW00A] ONE carrier per enlistment ask: 10.26's click-free quest entry was
    // queued for this very sentence (the faction pass runs first) - no open beside it, whatever lrgFacClickAsk makes of the
    // words (Lane B's S2.3 condition decides WHICH of the two; this interlock makes "both" impossible)
    if ((string) (((array) (((array) ($t['faction'] ?? []))['plan'] ?? []))['state'] ?? '') === 'queued') {
        lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - the click-free quest entry (10.26) already answers this ask', $cid);
        return '';
    }
    $hit = [];
    $narrow = !empty(lrgDlgCfg('open.narrow_marker', true));
    $clause = lrgDlgBusinessMarker($t, $utter, $narrow, $hit);
    if ($clause === '') {
        if ((string) ($hit['why'] ?? '') !== '') { lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - ' . (string) $hit['why'], $cid); }
        return '';
    }
    if (lrgFacRefusesOpen($t, $cid, $utter)) { return ''; }
    if ($narrow && (int) ($t['clicks_ok'] ?? 0) < 1 && !empty(lrgDlgCfg('session.stage_rail', true))) {
        $pred = (array) ($hit['entry'] ?? []);
        // [v1.0.1 / Helgen] an override open passes: there the list on screen IS the point (he can click it by hand, and the
        // Helgen innkeeper is the first NPC of a new game); the click itself stays under the stage rail and never_auto
        $passes = $clause === 'override' || in_array($clause, ['root', 'toplevel', 'qrows'], true) && $pred
            && (int) ($pred['scripted'] ?? 1) === 0 && (string) ($pred['kind'] ?? '') === '' && (int) ($pred['cost'] ?? 0) === 0
            && (int) ($pred['goodbye'] ?? 0) === 0 && (int) ($pred['crit'] ?? 0) === 0
            && ($clause !== 'root' || !lrgDlgStageRailBlocks($pred));
        if (!$passes) {
            lrgDlgLog('open npc=' . $npc . ' pre-LLM: none - marker=' . $clause . ' but no click is verified on this install yet'
                . ' and the line it would open for does not pass the stage rail (the menu would show for nothing)', $cid);
            return '';
        }
    }
    $amb = !empty($t['ambient']) ? 1 : 0;
    lrgDlgEmit($npc, ['do' => 'open', 'sid' => '0', 'gen' => 0, 'pos' => -1, 'i' => -1, 'txt' => '', 'kind' => 'plain',
        'ask' => substr(trim((string) preg_replace('/[^A-Za-z0-9 \']+/', ' ', $utter)), 0, 60), 'amb' => $amb], $cid, 'open-pre', null);
    lrgDlgPut($npc, ['open_pending' => ['cid' => $cid, 'at' => lrgNow(), 'clause' => $clause, 'row' => (string) ($hit['row'] ?? '')]]);
    // the kind that opened (the open_pending hide adds that kind's own CHIM codes - a follower open hides FollowPlayer & co.)
    $openKind = $clause === 'kind' ? (string) ($hit['kind'] ?? '') : '';
    lrgDlgLog(sprintf('open marker=%s row=%s npc=%s pre-LLM%s - do=open queued (D2); the list answers from his own words',
        $clause, (string) ($hit['row'] ?? '') !== '' ? (string) $hit['row'] : '-', $npc, $amb ? ' amb=1' : ''), $cid);
    return $clause;
}

/** vanilla | assisted | menuless, from the override file (design 5.4). */
function lrgDlgMode(string $npc, array $q): string
{
    foreach ((array) (lrgDlgOverrides()['npcs'] ?? []) as $rule) {
        $m = (array) ($rule['match'] ?? []);
        if (isset($m['name']) && lrgGlob((string) $m['name'], $npc)) { return (string) ($rule['mode'] ?? 'menuless'); }
    }
    foreach ((array) (lrgDlgOverrides()['quests'] ?? []) as $rule) {
        $pat = (string) (($rule['match'] ?? [])['quest'] ?? '');
        if ($pat !== '' && lrgAnyGlob([$pat], $q)) { return (string) ($rule['mode'] ?? 'menuless'); }
    }
    return 'menuless';
}

/** What this turn may show and what it must hide (design 2.3 / 5.2 C5 / decision D4). */
function lrgDlgApplyOffer(array $turn): void
{
    // [0.5.1 pt9 go-live / S11] one turn's hide list never leaks into the next (the flow harness and
    // the CLI replay several turns in one process); the post-gate reads this to drop a hidden code.
    unset($GLOBALS['LRG_DLG_SVC_HIDDEN']);
    if (!function_exists('lrgHideActions')) { return; }
    $hide = [];
    // [pt19c-A fix 1 / ai review] a READ-ONLY session offers no key and the gate refuses every item there: the action is off
    // the table too, so "nothing is offered that cannot be clicked" (S1.3) holds for the enum as well as for the list
    if (!$turn['on'] || (!$turn['speech'] && !$turn['talk']) || (string) ($turn['ro'] ?? '') !== '') {
        $hide[] = LRG_ACT_TOPIC;
    }
    // an open matter: Phase 1's intimacy actions stand down for the turn
    if (!empty($turn['open'])) {
        $hide = array_merge($hide, LRG_DLG_INTIMACY_ACTIONS);
        $hide = array_merge($hide, (array) lrgDlgCfg('hide_in_session', []));
    }
    // [pt19c-A fix 1 / CHIM brief P13] the pre-LLM open's turn: her reply and its actions land at stream end, after the list
    // is on screen - an EndConversation then would release packages and start CHIM's talk cooldown under the vanilla menu
    if (!empty($turn['open_pending'])) { $hide = array_merge($hide, (array) lrgDlgCfg('hide_in_session', [])); }
    if ($turn['crit'] >= 1) { $hide = array_merge($hide, ['ForgiveCrime', 'PayBounty_never_hidden_placeholder']); }
    if ($turn['on']) {
        // [0.5.0 fix pass / S-2] EVERY "the real entry wins" hide below is conditional on the driver
        // really being able to open a hidden session. `ml` is bMenuless && !bDlgDryRun as the game sees
        // it. In the SHIPPED state (bMenuless = 0, bDlgDryRun = 1) the old code still hid RentRoom,
        // HireCarriage, HireFerry, Training, OpenInventory (+ the gold pair on a priced list, +
        // ForgiveCrime) on every ordinary CHIM turn as soon as a topic list had been cached - and the
        // game then refused CmdSelectTopic with "the feature is switched off". The owner could not rent
        // a room, hire a carriage, train or open a merchant's inventory by voice all evening.
        // DEFAULT 1 on purpose: a script that never sends ml behaves exactly as 0.4.1 did, so a 401
        // game script + this server is unchanged and no existing test moves. See PROTOCOL 10.10.
        $menuless = (int) lrgDlgMcm('ml', 1, (string) $turn['npc']) === 1;
        if (!$menuless && !isset($GLOBALS['LRG_DLG_ML_SAID'])) {
            $GLOBALS['LRG_DLG_ML_SAID'] = 1;
            // [pt17] "still learning (N of 10)" when the game sends cal=, the old wording otherwise
            lrgDlgLog('ml=0: ' . lrgDlgLearningText((string) $turn['npc']) . ', so CHIM\'s own RentRoom / '
                . 'HireCarriage / HireFerry / Training / OpenInventory are LEFT ON THE TABLE - the glue '
                . 'has no session to replace them with.');
        }
        if ($menuless) {
            $hide = array_merge($hide, (array) lrgDlgCfg('hide_always', []));   // ForgiveCrime, decision D4
            // the REAL entry wins whenever the known list contains it
            if ($turn['entries'] || $turn['tail']) {
                $hide = array_merge($hide, (array) lrgDlgCfg('hide_chim', []));
            }
            $priced = false;
            foreach (array_merge((array) $turn['entries'], (array) $turn['tail']) as $e) {
                if ((int) $e['cost'] > 0 || (string) $e['kind'] === 'bribe') { $priced = true; break; }
            }
            if ($priced) { $hide = array_merge($hide, (array) lrgDlgCfg('hide_gold', [])); }
        }
        // [0.5.0 / E6] the per-kind service lists. A SUBSET of hide_chim + hide_always by contract
        // (d56_services asserts it), so this can only ever repeat a decision already made above -
        // except when bServiceShortcut is OFF, where it also hides the shortcuts with no list at all.
        // That one case runs at ANY ml: "hide the shortcut even when you cannot read a list" is an
        // explicit owner instruction, not a promise to replace it, and the owner said he would rather
        // she admitted she cannot help than teleport him with a flat 20 gold.
        $svcHide = lrgDlgServiceHidePolicy($turn, $menuless);
        // [pt19 v1.0 / S2.1, CHIM brief P2] the pre-LLM open's turn: the REAL entry is about to win, so CHIM's own trade /
        // room / carriage shortcut is off the table this turn ("what have you got?" must not yield both the engine's trade
        // click and CHIM's OpenInventory a second later). Hidden here, before the enum is built; the post-gate drops a code
        // hidden this turn whatever the model wrote.
        if (!empty($turn['open_pending'])) {
            // [pt19c-A fix 1 / CHIM review] ... and the opening KIND's own CHIM codes: a follower open (a teammate, a custom
            // follower) takes FollowPlayer / WaitHere & co. off the table, or CHIM's package runs beside the real line
            $ok = (string) ($turn['open_kind'] ?? '');
            $svcHide = array_values(array_unique(array_merge($svcHide,
                ['OpenInventory', 'OpenInventory2', 'RentRoom', 'HireCarriage'],
                $ok !== '' ? array_map('strval', (array) lrgDlgCfg('services.kinds.' . $ok . '.hide', [])) : [])));
        }
        // [pt19 v1.0 / S6.1, Lane B] THE REWARD IS FIXED BY THE ENGINE: on a quest turn CHIM's five gold / item actions are
        // hidden unconditionally and registered here, so the existing "hidden this turn -> dropped" rail drops a stray GiveGoldTo
        if (lrgDlgQuestTurn($turn)) {
            $svcHide = array_values(array_unique(array_merge($svcHide, array_map('strval', (array) lrgDlgCfg('hide_reward', [])))));
        }
        if ($svcHide) {
            $GLOBALS['LRG_DLG_SVC_HIDDEN'] = $svcHide;
            $hide = array_merge($hide, $svcHide);
        }
    }
    $hide = array_values(array_unique(array_filter($hide, static fn($c) => $c !== 'PayBounty_never_hidden_placeholder')));
    // function_exists: lrgHideActions() is another lane's file. This path only ever runs from the four
    // hooks that require lrg_actions.php first, but the preprocessing branch does not - so the guard
    // makes the dependency impossible to break by moving a call (the lrgCsv() trap, fixed 0.4.0).
    if ($hide && function_exists('lrgHideActions')) { lrgHideActions($hide); }
}

/**
 * [0.4.1 / owner addendum 8] THE CONVERSATION HOLD, server half. Called at brace depth 0 from
 * functions.php, AFTER both lanes have built their offer, so it only ever REMOVES and cannot be undone
 * by a later filter.
 *
 * It fires only when the game says this very NPC is being held right now: the snapshot carries
 * `hold=1` (LRG_Profile.BuildSnapshot, set from LRG_Main.IsConvHeldActor) and is no older than one hold
 * window. That is exactly "a live one-on-one with the NPC we are answering as" - a hold exists for one
 * NPC at a time and the game releases it the moment the conversation stops, the player walks off, a
 * fight starts, a menu opens or a scene begins.
 *
 * An ABSENT `hold` key (an older game script, or any NPC not being held) changes nothing at all, and
 * a stale snapshot changes nothing either: both fall through and CHIM's own behaviour applies.
 * Never throws - a database that is not there simply means "not holding".
 */
function lrgDlgHideEndConversationOnHold(): void
{
    if (empty(lrgDlgCfg('hold_hides_end_conversation', true))) { return; }
    if (!function_exists('lrgHideActions') || !function_exists('lrgIsOffered')) { return; }
    if (!lrgIsOffered('EndConversation')) { return; }          // already off: nothing to do, nothing to log
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($npc === '') { return; }
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) {                // the offline seam: no database in a unit test
        $kv = (array) ($GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? []);
    } else {
        try {
            $kv = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
        } catch (Throwable $e) {
            return;
        }
    }
    if (!is_array($kv) || (string) ($kv['hold'] ?? '') !== '1') { return; }
    $maxAge = max(5, (int) lrgDlgCfg('hold_max_age_seconds', 45));
    if ((int) ($kv['_age'] ?? 9999) > $maxAge) { return; }
    lrgHideActions(['EndConversation']);
    lrgDlgLog('EndConversation withheld from ' . $npc . ': the game is holding her for this conversation');
}

/** main.php:1078 switched actions off for lrg_dlgtalk; switch them back on for that one request. */
function lrgDlgPrerequest(): void
{
    if (!lrgDlgEnabled()) { return; }
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    if ($type === 'lrg_dlgtalk') {
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        lrgDlgLog('prerequest: actions switched back on for lrg_dlgtalk');
    }
}

// ================================================================== prompt blocks
/** Static, character_bottom, <= 70 words, only while menuless is on for this NPC (design 4.3). */
function lrgDlgStaticGuidance(array $t): string
{
    if (empty($t['on'])) { return ''; }
    if (!($t['speech'] ?? false) && !($t['talk'] ?? false)) { return ''; }
    // Only when this NPC really has business to settle. On a cold turn with no list at all the rules would
    // be 70 wasted words on every ordinary CHIM exchange, and Phase 1's own "an NPC with no snapshot gets
    // nothing injected" contract (flow 16) would break. Intent mode still works there: the action row's own
    // description carries the two rules that matter, and the catalog pays for it once, not per turn.
    if (!($t['entries'] ?? []) && (string) ($t['list'] ?? 'none') === 'none') { return ''; }
    // [pt19 v1.0 / S11] SUPPRESSED when <business> is present (a list with entries, or the open_pending bridge): its four
    // standing rules say the same, and its one unique line (never announce a check's outcome) rides <business> instead
    // whenever an offered entry is a persuasion, a threat or a bribe (lrgDlgBusinessBlock)
    if (($t['entries'] ?? []) || !empty($t['open_pending'])) { return ''; }
    return "<real_business>\n"
        . "Jobs, quests, payments, favours, access, secrets, services and arrests are settled by the world,\n"
        . "not by talk. You can only grant, accept, refuse or conclude such a thing with the action "
        . lrgDlgActionName() . ".\n"
        . "Until then stay in character: deflect, haggle, ask.\n"
        . "Never say whether a persuasion, a threat or a bribe worked. The world decides; you are told afterwards.\n"
        . "</real_business>";
}

/** Volatile, prompt_bottom, only when a list is known - plus the quest clauses and the ground truth. */
function lrgDlgVolatileGuidance(array $t): string
{
    $parts = [];
    $b = lrgDlgBusinessBlock($t);
    if ($b !== '') { $parts[] = $b; }
    if (!empty($t['quests'])) { $parts[] = (string) $t['quests']; }
    // [0.5.0 / E2(b)(c)] answered in WORDS: no menu is opened and nothing is clicked on either branch
    if ((string) ($t['ask'] ?? '') === 'list') {
        $a = lrgDlgAskListBlock($t);
        if ($a !== '') { $parts[] = $a; }
    } elseif ((string) ($t['ask'] ?? '') === 'next') {
        $a = lrgDlgAskNextBlock($t);
        if ($a !== '') { $parts[] = $a; }
    }
    // [0.5.0 / E6] a price list: her own words about it, and the rule that stops a guess
    $svc = lrgDlgServiceBlock($t);
    if ($svc !== '') { $parts[] = $svc; }
    // [0.5.7 / pt16] a recognised service request that no real entry answers this turn: "do it with
    // CHIM's own action, or say plainly why not - never ignore it" (owner addendum 11 (b))
    $sreq = lrgDlgServiceRequestBlock($t);
    if ($sreq !== '') { $parts[] = $sreq; }
    // [pt19-purchase] an order of food or drink: hand it over for the listed price (the game does the rest), name what
    // she really has, quote the listed price, or say plainly why not (lib/lrg_market.php)
    $mreq = lrgMktRequestBlock($t);
    if ($mreq !== '') { $parts[] = $mreq; }
    // [0.5.1] the follower commands she really answers to, and the rule that stops the model
    // narrating an order the game has not carried out
    $folv = lrgDlgFollowerVerbBlock($t);
    if ($folv !== '') { $parts[] = $folv; }
    if (!empty($t['result_block'])) { $parts[] = (string) $t['result_block']; }
    $chk = lrgDlgCheckDirective($t);
    if ($chk !== '') { $parts[] = $chk; }
    // [pt17-replies] her last reply carried no words at all (lib/lrg_replies.php): one rule, once, within note_seconds
    if (function_exists('lrgNeNote')) {
        $ne = lrgNeNote((string) ($t['npc'] ?? ''));
        if ($ne !== '') { $parts[] = $ne; }
    }
    return implode("\n", $parts);
}

/**
 * [0.5.0 fix pass / S-3] May she say a price out loud on this turn? Two inputs, one place:
 *  - bNamePrices (svx byte 2). OFF means she never quotes a number, even a real one.
 *  - services.kinds.<kind>.never_quote_price. Declared for `train` when E6 shipped and read by nobody;
 *    Requiem / LoreRim compute a training fee from the skill level, so it is not on the entry at all
 *    and any number she said would be invented.
 */
function lrgDlgMayQuotePrice(array $t): bool
{
    if ((int) lrgDlgMcm('svnp', 1, (string) ($t['npc'] ?? '')) !== 1) { return false; }
    $kind = (string) (($t['svc'] ?? [])['kind'] ?? '');
    if ($kind === '') { return true; }
    return empty(lrgDlgCfg('services.kinds.' . $kind . '.never_quote_price', false));
}

/**
 * [0.5.0 / E6] What she is allowed to say about a price list, and what she must do instead of guessing.
 * Emitted only when the live layer really IS a price list (decided from the list, never from the index).
 */
function lrgDlgServiceBlock(array $t): string
{
    $svc = (array) ($t['svc'] ?? []);
    if (empty($svc['pricelist'])) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $mode = (string) ($svc['mode'] ?? '');
    $names = array_slice((array) ($svc['all'] ?? $svc['slots'] ?? []), 0, 8);
    $out = '<her_list kind="' . ($svc['kind'] !== '' ? (string) $svc['kind'] : 'service') . "\">\n";
    if ($names) { $out .= "These are the only ones on her list: " . implode(', ', $names) . ".\n"; }
    // [0.5.0 fix pass / S-3] bNamePrices (svx byte 2) and the per-kind never_quote_price, which was a
    // second declared-and-never-read config key. Requiem / LoreRim training costs are computed by the
    // engine from skill level and are NOT on the entry, so a trainer must never be invited to name one.
    if (lrgDlgMayQuotePrice($t)) {
        $out .= "Never name one that is not on it, and never invent a price - only a price this list showed.\n";
    } else {
        $out .= "Never name one that is not on it. Do NOT say a price out loud at all this turn - not even a\n"
            . "rough one. If he asks what it costs, tell him to look at what you are offering him.\n";
    }
    if ($mode === 'ask') {
        $out .= 'Two of them could be meant (' . implode(' or ', array_slice((array) ($svc['slots'] ?? []), 0, 3))
            . '). Ask ' . $player . " which, in your own words, and settle nothing this turn.\n";
    } elseif ($mode === 'price') {
        $out .= $player . " asked what it costs. Tell him the price and do nothing else this turn - he has not\n"
            . "asked you to take him anywhere.\n";
    } elseif ($mode === 'none') {
        $out .= $player . " has not named one of them. Ask which he means; do not choose for him.\n";
    }
    $out .= '</her_list>';
    return $out;
}

/**
 * [pt19 v1.0 / S2.1] The bridging directive of the open_pending turn: her list is being brought up, so ONE short line
 * that settles nothing - her real answer follows from the list (<= 220 chars; it replaces the "list is on screen" state).
 */
function lrgDlgBridgeLine(): string
{
    // "he", not the player's name: the spec's line with a 15-letter name is 228 chars, over its own 220 budget
    return 'The list of what he can raise is being brought up now. Say ONE short line that does not settle'
        . ' the matter - an acknowledgement or a question back; if the list carries what he asked, his real answer follows from it.';
}

/**
 * <business>. [pt19 v1.0 / S1.3, S2.1, S3.3, S4.2, S4.5, S5] The TEXT this lane owns: the state wording (conditioned on
 * the session being OPEN), the read-only clause (a plain list, no keys), the stage-rail line (ONE per prompt), the
 * leave-guard line, the single-entry wording, the two-candidate hint and the bridging directive.
 * [pt19 v1.0 / Lane B second pass] the S11 diet of the standing rules below (and 13 (j)'s size assertions) is Lane B's.
 */
function lrgDlgBusinessBlock(array $t): string
{
    if (empty($t['on'])) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $npc = (string) $t['npc'];
    $pending = !empty($t['open_pending']);
    // [pt19c fixer / QA wording] HER list only for her: the game's snapshot says sex=0 for a man ("his list is being brought up"
    // to Farengar, Galmar, Tullius ...); an unknown sex keeps the shipped wording
    $his = lrgDlgNpcIsMale($npc) ? 'his' : 'her';
    if (!($t['entries'] ?? [])) {
        // the open_pending turn with no list known yet (a cold first contact): the directive alone, in the volatile block
        return $pending ? '<business for="' . $npc . '" state="' . $his . ' list is being brought up">' . "\n" . lrgDlgBridgeLine()
            . "\n</business>" : '';
    }
    $ro = (string) ($t['ro'] ?? '');
    $single = is_array($t['single'] ?? null) ? $t['single'] : null;
    $closed = (string) ($t['layer_kind'] ?? '') === 'closed';
    $onScreen = (string) ($t['list'] ?? '') === 'pending';
    if ($pending) {
        $state = $his . ' list is being brought up';
    } elseif ($onScreen && $single) {
        $state = $npc . ' waits for one answer, something like: \'' . str_replace('"', "'", lrgDlgShownText((string) $single['text'])) . '\'';
    } elseif ($onScreen && $closed) {
        $state = $npc . ' is waiting for an answer - the list is on screen';
    } elseif ($onScreen) {
        $state = 'the list is on screen in front of ' . $player . '; things he can raise';
    } else {
        $state = 'things ' . $player . ' could raise with ' . $npc . ' (' . $his . ' list is not open now)';
    }
    $out = '<business for="' . $npc . '" state="' . $state . '">' . "\n";
    if ($pending) { $out .= lrgDlgBridgeLine() . "\n"; }
    if ($ro !== '') {
        // [S1.3] READ-ONLY: the entries are facts, not keys - nothing is offered that cannot be clicked, and no bucket
        // wording that presumes keys (ai S7). Her words say the one thing that is true, in this same turn.
        foreach ((array) $t['entries'] as $e) { $out .= '- ' . lrgDlgShownText((string) $e['text']) . "\n"; }
        $out .= 'This list is ' . $player . '\'s to choose from by hand. If he asks you to choose one, tell him in your own'
            . ' words: choose it on the list yourself - I cannot pick for you here. Settle nothing else this turn.' . "\n";
        // [pt19c-B fix 1 / ai review 6] the kept never-false rail rides the read-only list too (<real_business> is suppressed
        // here as well): a journal scene before the route proof, a lethal list or quiet mode may be longer than what is written out
        if ((int) ($t['sent'] ?? 0) < (int) ($t['n'] ?? 0)) {
            $out .= 'Not every matter between you is listed above (' . (int) $t['sent'] . ' of ' . (int) $t['n']
                . "). You may NOT say that a thing is not on the table, that there is nothing like that between you,\n"
                . "or any paraphrase of that. Ask " . $player . " to be more specific instead.\n";
        }
        $out .= '</business>';
        return $out;
    }
    $i = 0;
    foreach ((array) $t['entries'] as $e) {
        $i++;
        $key = 'T' . $i;
        $label = (string) $e['label'];
        $text = lrgDlgShownText((string) $e['text']);
        $quest = ($e['quest'] !== '' && (int) $e['journal'] === 1 && lrgDlgQuestKnown((string) $e['quest']))
            ? (' {' . $e['quest'] . '}') : '';
        $out .= $key . ' ' . ($label !== '' ? $label . ' ' : '') . $text . $quest . "\n";
    }
    $rest = (array) ($t['tail'] ?? []);
    if ($rest) {
        if ((string) ($t['layer_kind'] ?? '') === 'closed') {
            // a closed layer is NEVER bucketed: the overflow is stated plainly instead of grouped
            $out .= count($rest) . ' further answer(s) are on this list and are not written out above; if '
                . $player . ' means one of those, ask which.' . "\n";
        } else {
            // [pt19 v1.0 / S11] the count only - the word bucket is gone (it cost ~60 chars a turn and named no key)
            $out .= '(+' . count($rest) . " more)\n";
        }
    }
    $out .= 'Use ' . lrgDlgActionName() . ' with item = one key ONLY when ' . $player . "'s last words clearly do that thing.\n";
    $out .= "Two keys fit, or unsure: do not act - ask which they mean, in character.\n";
    // [pt17-replies] This line used to read "With the action leave message empty: your real answer follows by
    // itself." It taught the model that an empty message is a valid reply: request 706 (Beirand, 2026-09-23 04:43)
    // picked a [commits] key with message "", the gate PARKED it and her confirming question - which IS the message
    // - was never heard. The mute (lrgDlgTransformer) only ever silences a pick that is really EMITTED, so words
    // cost nothing there and are needed everywhere else.
    $out .= "Say your one line in message even with the action - never leave message empty; when the world answers\n"
        . "for you, that line is simply not played.\n";
    // [pt19 v1.0 / S4.3, S4.4] his own plain sentence IS the confirmation; otherwise she asks, QUOTING the line, so that a
    // bare "yes" answers exactly that question
    $out .= 'A [commits] key: unless he already said it plainly, ask plainly whether they mean it, quoting the choice in its'
        . " own words, and use the action in the same reply; it only happens after they confirm.\n";
    // while sent < n the NPC MAY NOT deny the matter exists (CMP-M4): a follower with command frameworks
    // would otherwise truthfully make a Missives quest unreachable by voice
    if ((int) $t['sent'] < (int) $t['n']) {
        $out .= 'Not every matter between you is listed above (' . (int) $t['sent'] . ' of ' . (int) $t['n']
            . "). You may NOT say that a thing is not on the table, that there is nothing like that between you,\n"
            . "or any paraphrase of that. Ask " . $player . " to be more specific instead.\n";
    }
    // [pt19 v1.0 / S11] <real_business> is suppressed while this block is present: its one unique rule rides here, and only
    // when an offered entry IS a persuasion, a threat or a bribe (the only place the model is told so)
    foreach ((array) $t['entries'] as $e) {
        if ((string) ($e['kind'] ?? '') !== '') {
            $out .= "Never say whether a persuasion, a threat or a bribe worked; you are told afterwards.\n";
            break;
        }
    }
    // [pt19 v1.0 / S3.3] THE STAGE RAIL: ONE line per prompt naming the keys that may be picked before this install's
    // first verified click (never eight per-entry labels), in her own words (10.21), no machinery word; and when NONE may
    // be, a sentence that can come true (capability map U7: asking her a question unlocks nothing on such a list).
    $rail = lrgDlgStageRailLine($t);
    if ($rail !== '') { $out .= $rail . "\n"; }
    // [pt19 v1.0 / S4.2] THE LEAVE GUARD: no line backs out cleanly and a line here walks out on her
    // [pt19c fixer / QA wording] ... and ONLY on the turn his words are about leaving or backing out ("I have to go", "never
    // mind", "goodbye"): on every other turn of a walk-away layer the line invited her to volunteer "leaving ends things with
    // me" unasked (124 turns in the walkthrough). A man is named, never "her".
    if (lrgDlgLeaveGuarded($t)) {
        $lu = !empty($t['speech']) ? (string) (((array) (lrgDlgState($npc)['utter'] ?? []))['text'] ?? '') : '';
        if (trim($lu) !== '' && (lrgDlgLeaveWords($lu) || lrgDlgIsBackOut($lu, true))) {
            $out .= 'No line here backs out cleanly; leaving is his to do by hand, and it ends things with '
                . ($his === 'his' ? $npc : 'her') . " - tell him so.\n";
        }
    }
    // [pt19 v1.0 / S5] two of the keys fit what he said: she asks which, naming both
    $h2 = (array) ($t['hint2'] ?? []);
    if (count($h2) === 2) { $out .= $h2[0] . ' or ' . $h2[1] . " fit what he said - ask which, naming both.\n"; }
    // [0.5.0 / E3] THE FORESEEN HAND-BACK: a LETHAL / arrest session. The model's own message IS the one short line;
    // lrgDlgWillEmit() returns false on these turns, so the mute never fires and the line is spoken.
    if (lrgDlgHandBackForeseen($t)) {
        $out .= 'You cannot settle this one by talking. Tell ' . $player . ' plainly, in one short line: choose that'
            . " yourself - and say nothing else this turn.\n";
    }
    $out .= '</business>';
    return $out;
}

/**
 * Does the server already know this turn will hand the menu back? (E3, plan 8.2 - the foreseen case.)
 * [pt19 v1.0 / S1.3] crit 2, an arrest, or a READ-ONLY session (drv=0, a journal scene before the route proof or with
 * session.drive_scene off, quiet, stopped). The assisted-after-losses, iBranchInput and X1 branches are retired.
 */
function lrgDlgHandBackForeseen(array $t): bool
{
    if ((string) ($t['ro'] ?? '') !== '') { return true; }
    if ((int) ($t['crit'] ?? 0) === 2) { return true; }
    return (string) ($t['arrest'] ?? '') !== '';
}

/**
 * [pt19 v1.0 / S3.3, U7] The ONE stage-rail line for this prompt, or '' (a verified click exists, the rail is off, or
 * every key passes). Keys that pass are named; when none does, the sentence says so honestly.
 */
function lrgDlgStageRailLine(array $t): string
{
    if (empty(lrgDlgCfg('session.stage_rail', true)) || (int) ($t['clicks_ok'] ?? lrgDlgClicksOk()) >= 1) { return ''; }
    $ok = [];
    $blocked = 0;
    $i = 0;
    foreach ((array) ($t['entries'] ?? []) as $e) {
        $i++;
        if (!isset(((array) (($t['offer'] ?? [])['keys'] ?? []))['T' . $i])) { continue; }
        if (lrgDlgStageRailBlocks((array) $e)) { $blocked++; } else { $ok[] = 'T' . $i; }
    }
    if ($blocked === 0) { return ''; }
    if (!$ok) {
        // [pt19c-A fix 1 / ai review, capability map U7] no key passes: asking something simple unlocks nothing HERE, so the
        // condition is not said - only what is true
        return 'I cannot pick any of these for him yet; if he asks for one, say: I cannot pick any of these for you yet -'
            . ' choose this one yourself, this once';
    }
    // [pt19c fixer / QA wording] "a question" was false where the blocked line is itself a simple question ("What do you think
    // about the war?" on Alvor's list): she names a line that CAN be picked instead
    $ex = '';
    $i = 0;
    foreach ((array) ($t['entries'] ?? []) as $e) {
        $i++;
        if ($ex === '' && in_array('T' . $i, $ok, true)) { $ex = substr(str_replace(['"', "'"], ['', ''], lrgDlgShownText((string) $e['text'])), 0, 60); }
    }
    return 'Until he has asked me something simple I can only pick ' . implode(', ', $ok) . '; for anything else say: I have'
        . ' not picked a line for you yet - ask me something simple first' . ($ex !== '' ? ', like "' . $ex . '"' : '')
        . ', then I can pick this one';
}

/** [pt19c fixer] Is this NPC a man, by the game's snapshot (sex=0)? False when the snapshot does not say. */
function lrgDlgNpcIsMale(string $npc): bool
{
    if ($npc === '') { return false; }
    $snap = lrgDlgSnapshotKv($npc);
    return is_array($snap) && (string) ($snap['sex'] ?? '') === '0';
}

/** [pt19 v1.0 / S4.2] No back entry is listed and a visible entry walks out on her (walk-away flag or a twat target). */
function lrgDlgLeaveGuarded(array $t): bool
{
    $guard = false;
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
        // [pt19h-safety / G8] only a line LEAVE may really click backs out cleanly (lrgDlgRealBackOut)
        if (lrgDlgRealBackOut((array) $e)) { return false; }
        if ((int) ($e['walkaway'] ?? 0) === 1 || (string) ($e['twat'] ?? '') !== '') { $guard = true; }
    }
    return $guard;
}

/** Entries are shown VERBATIM; only the leading tag is dropped, because the label replaces it. */
function lrgDlgShownText(string $text): string
{
    $s = (string) preg_replace('/^\s*[\(\[][^)\]]{1,40}[\)\]]\s*/u', '', $text, 1);
    return trim($s) !== '' ? trim($s) : trim($text);
}

/** A quest name may be shown only when CHIM's journal already has a row for it - no spoilers. */
function lrgDlgQuestKnown(string $quest): bool
{
    $rows = lrgDlgQuestRows([$quest]);
    return isset($rows[strtolower($quest)]);
}

/**
 * <what_just_happened>, once, on the next turn. Composed from ev=result + ev=line + new questlog rows.
 * An 'unknown' outcome is told as "you answered: ..." with NO verdict attached (design 4.5).
 */
function lrgDlgGroundTruth(string $npc, array $st): string
{
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $now = lrgNow();
    $pre = [];
    $patch = [];
    // [pt19 v1.0 / S7, model F2] she stopped driving (ev=stopped): told ONCE, within 180 s - the menu is his from then on
    $sess = (array) ($st['session'] ?? []);
    $stop = (array) ($sess['stopped'] ?? []);
    if ($stop && $now - (int) ($stop['at'] ?? 0) <= 180 && (int) ($st['stopped_told'] ?? 0) !== (int) ($stop['at'] ?? 0)) {
        $because = ['combat' => 'because a fight began', 'scene' => 'because a scene began', 'lethal' => 'because that choice is his alone'][(string) ($stop['why'] ?? '')]
            ?? 'to choose from by hand';
        $pre[] = 'The menu was left to ' . $player . ' ' . $because . '.';
        $patch['stopped_told'] = (int) ($stop['at'] ?? 0);
    }
    // [pt19 v1.0 / S6.2 (5)-(6), gate B] what a do=award REALLY gave (the game's OK, read back by lib/lrg_actions.php), told once
    $ag = (array) ($st['award_gave'] ?? []);
    if ($ag && empty($ag['told']) && $now - (int) ($ag['at'] ?? 0) <= 180) {
        $pre[] = 'You gave ' . $player . ' ' . (int) $ag['n'] . ' septims on top of his reward.'
            . ((int) ($ag['want'] ?? 0) > (int) $ag['n'] ? ' You found you had only ' . (int) $ag['n'] . ' septims to give.' : '');
        $patch['award_gave'] = ['told' => true] + $ag;
    }
    // [pt19 v1.0 / S7] a server-side gate refusal the voice gap skipped (lrgDlgNoteRefusal, lib/lrg_actions.php): its plain
    // sentence, told once within 180 s. [pt19c-B fix 1 / CHIM review, use review P2] its OWN key: it no longer overwrites Phase 2's
    // last_result, so a click result that landed mid-stream, the engine's check verdict and the reward window all survive it
    $rn = (array) ($st['refusal_note'] ?? []);
    if ($rn && empty($rn['told']) && $now - (int) ($rn['at'] ?? 0) <= 180 && trim((string) ($rn['why'] ?? '')) !== '') {
        $pre[] = 'Nothing came of it: ' . rtrim((string) $rn['why'], '. ') . '.';
        $patch['refusal_note'] = ['told' => true] + $rn;
    }
    if ($patch) { lrgDlgPut($npc, $patch); }
    $res = (array) ($st['last_result'] ?? []);
    if (!$res || !empty($res['told']) || $now - (int) ($res['at'] ?? 0) > 180) {
        return $pre ? "<what_just_happened>\n" . implode("\n", $pre) . "\nThis is fact.\n</what_just_happened>" : '';
    }
    $lines = $pre;
    $entry = (string) ($res['entry'] ?? '');
    if ($entry !== '') { $lines[] = $player . ' said: "' . $entry . '"'; }
    foreach (array_slice((array) ($st['lines'] ?? []), -2) as $l) {
        if ((int) ($l['at'] ?? 0) >= (int) ($res['at'] ?? 0) - 6) { $lines[] = 'You answered: "' . (string) $l['t'] . '"'; }
    }
    $kind = (string) ($res['kind'] ?? '');
    $verdict = (string) ($res['verdict'] ?? '');
    if (in_array($kind, ['persuade', 'intimidate', 'bribe'], true)) {
        if ($verdict === 'pass') {
            $lines[] = $kind === 'bribe'
                ? ('You took the gold: exactly ' . abs((int) $res['gold']) . ' septims left ' . $player . "'s purse. Treat the matter as settled.")
                : ('It worked on you. Treat the matter as settled in ' . $player . "'s favour and do not reopen it.");
        } elseif ($verdict === 'fail') {
            $lines[] = 'It FAILED. You were not moved' . ($kind === 'intimidate' ? ' and you are not afraid of ' . $player : '')
                . '. Do not soften this, and do not change your mind unless something about ' . $player . ' changes.';
        }
        // 'unknown': no verdict is stated at all
    } elseif ((int) ($res['gold'] ?? 0) !== 0) {
        $lines[] = abs((int) $res['gold']) . ' septims changed hands.';
    }
    // [pt19 v1.0 / S7] the game's result reason in her words (never the log string, never a closed-list word).
    // [pt19c-B fix 1 / ai review 7, architect] "unverified" (or no why at all) is S7's "nothing came of that" ALONE - it read
    // "Nothing came of it: nothing came of that." before. A stored note of the older shape (refusal=1) still reads its sentence.
    if (empty($res['ok'])) {
        $why = !empty($res['refusal']) ? (string) ($res['why'] ?? '') : lrgDlgResultWhy((string) ($res['why'] ?? ''));
        $lines[] = trim($why) !== '' ? 'Nothing came of it: ' . rtrim($why, '. ') . '.' : 'Nothing came of it.';
    }
    foreach (lrgDlgNewQuestRows($st, (int) ($res['at'] ?? 0)) as $obj) {
        $lines[] = 'The matter moved on: "' . $obj . '".';
    }
    lrgDlgPut($npc, ['last_result' => ['told' => true] + $res]);
    if (!$lines) { return ''; }
    return "<what_just_happened>\n" . implode("\n", array_slice($lines, 0, 5)) . "\nThis is fact. You may"
        . " elaborate in your own voice but never contradict it and never add an outcome of your own.\n"
        . '</what_just_happened>';
}

/**
 * [pt19 v1.0 / S7] The game's ev=result why= in plain words, or '' when there is nothing to add to "Nothing came of it."
 * ("unverified" and an empty why ARE that sentence - ai review 7); the rest through lrgVoicedWhy.
 */
function lrgDlgResultWhy(string $why): string
{
    $w = strtolower(trim($why));
    if ($w === '' || $w === 'unverified' || $w === 'nothing came of that') { return ''; }
    return function_exists('lrgVoicedWhy') ? lrgVoicedWhy(['reason' => $why]) : '';
}

function lrgDlgNewQuestRows(array $st, int $since): array
{
    $out = [];
    foreach (lrgDlgQuestRows(array_values((array) ($st['q'] ?? []))) as $row) {
        if ((int) ($row['at'] ?? 0) > $since - 2) {
            $b = lrgDlgCleanObjective((string) ($row['briefing'] ?? ''), '');
            if ($b !== '') { $out[] = $b; }
        }
    }
    return array_slice($out, 0, 2);
}

// ================================================================== matching
function lrgDlgIntentOf(array $t): array
{
    if (!function_exists('lrgRecogniseIntent')) { return ['kind' => 'none', 'conf' => 'low', 'text' => '']; }
    $npc = (string) $t['npc'];
    $mem = function_exists('lrgMemGet') ? lrgMemGet($npc) : [];
    $text = (string) (lrgDlgState($npc)['utter']['text'] ?? '');
    try { return (array) lrgRecogniseIntent($text, null, $mem, true); }
    catch (Throwable $e) { return ['kind' => 'none', 'conf' => 'low', 'text' => $text]; }
}

/**
 * Server-side match of free text against a list the model never saw (design 4.4):
 * exact / containment -> token overlap with stop words removed -> trigram similarity.
 * Returns ['i' => index into $entries, 'score' => f, 'margin' => f] or null.
 * [pt19 v1.0] SCORES UNTOUCHED; the top hit also reports what it is made of:
 *  - `tier` exact | contain | f1 | trigram (the tier that gave the score) and `f1` (the raw word overlap, reported as 1.0 on
 *    an exact or containment hit - S4.8: a scripted entry on the similarity path needs f1 > 0);
 *  - `short` (model F16): a CHARACTER containment where the utterance is the shorter side and has fewer than
 *    min(3, tokens(entry)) tokens - "hi" inside "anything interesting going on in town", "the" inside the Legion oath,
 *    "is that" inside "is that it". Such a hit never counts: `eff` is then the score without the containment tier;
 *  - `j` / `second`: the runner-up (the two-candidate hint, S5).
 */
function lrgDlgMatchText(string $utter, array $entries): ?array
{
    $utter = lrgDlgSttFold($utter, $entries);
    $u = lrgPromptNorm($utter);
    if ($u === '' || !$entries) { return null; }
    $uw = lrgPromptWords($u);
    $utok = lrgDlgTokens($u);
    $ut = count($utok);
    $scores = [];
    $meta = [];
    foreach ($entries as $i => $e) {
        $n = (string) $e['norm'];
        if ($n === '') { $scores[$i] = 0.0; $meta[$i] = ['tier' => '', 'f1' => 0.0, 'short' => false, 'alt' => 0.0]; continue; }
        if ($n === $u) { $scores[$i] = 1.0; $meta[$i] = ['tier' => 'exact', 'f1' => 1.0, 'short' => false, 'alt' => 1.0]; continue; }
        $s = 0.0;
        $tier = 'trigram';
        // [pt19c-A fix 1 / ai review, completing F16 in the other direction] a containment counts only when TOKEN-aligned:
        // the shorter norm's tokens are a contiguous run of the longer one's. A word FRAGMENT ("no" inside "i know", "so"
        // inside "sorry", "well" inside "farewell", "con" inside "contract") is scored by the tiers below, with its real F1
        $contain = false;
        if (strpos($u, $n) !== false || strpos($n, $u) !== false) {
            $ntok = lrgDlgTokens($n);
            $contain = count($utok) >= count($ntok) ? lrgDlgTokenRun($utok, $ntok) : lrgDlgTokenRun($ntok, $utok);
        }
        if ($contain) { $s = 0.85; $tier = 'contain'; }
        $ew = lrgPromptWords($n);
        $f1 = 0.0;
        $alt = 0.0;
        if ($uw && $ew) {
            $inter = count(array_intersect($uw, $ew));
            // F1 over the meaning-carrying words: symmetric, so a long utterance does not out-score a
            // short entry and the other way round. No shared word at all scores nothing.
            if ($inter > 0) {
                $f1 = 2 * $inter / (count($uw) + count($ew));
                $fs = 0.35 + 0.65 * $f1;
                $alt = $fs;
                if ($fs > $s) { $s = $fs; $tier = 'f1'; }
            }
        }
        similar_text($u, $n, $pct);
        $ts = ($pct / 100) * 0.8;
        $alt = max($alt, $ts);
        if ($ts > $s) { $s = $ts; $tier = 'trigram'; }
        $scores[$i] = $s;
        $short = $tier === 'contain' && strlen($u) < strlen($n) && $ut < min(3, count(lrgDlgTokens($n)));
        $meta[$i] = ['tier' => $tier, 'f1' => $contain ? 1.0 : $f1, 'short' => $short, 'alt' => $alt];
    }
    arsort($scores);
    $keys = array_keys($scores);
    $top = $keys[0];
    $best = (float) $scores[$top];
    $second = count($keys) > 1 ? (float) $scores[$keys[1]] : 0.0;
    $m = $meta[$top];
    return ['i' => $top, 'score' => round($best, 3), 'margin' => round($best - $second, 3),
        'tier' => (string) $m['tier'], 'f1' => round((float) $m['f1'], 3), 'short' => (bool) $m['short'],
        'eff' => round($m['short'] ? (float) $m['alt'] : $best, 3),
        'j' => count($keys) > 1 ? $keys[1] : null, 'second' => round($second, 3)];
}

/**
 * [pt19 v1.0] The matcher's verdict as a decision may use it: the top entry index, or null when it does not clear
 * $minS / $minM on its EFFECTIVE score (F16's short containment never counts) or - S4.8, match.scripted_needs_word - when
 * the entry is scripted and shares no meaning word (f1 = 0) on this similarity path.
 */
function lrgDlgMatchPick(?array $m, array $entries, float $minS, float $minM): ?int
{
    if ($m === null) { return null; }
    if ((float) $m['eff'] < $minS || ((float) $m['eff'] - ((float) $m['score'] - (float) $m['margin'])) < $minM) { return null; }
    $e = (array) ($entries[$m['i']] ?? []);
    if (!empty(lrgDlgCfg('match.scripted_needs_word', true)) && (int) ($e['scripted'] ?? 0) === 1 && (float) $m['f1'] <= 0.0) {
        return null;
    }
    return (int) $m['i'];
}

/**
 * [pt19c-A fix 1 / language review 11, research/pt19c-language.md G2] THE STT COMPOUND FOLD, for MATCHING only (never
 * lrgPromptNorm, which the index builder mirrors byte for byte), entry-driven, no table: two adjacent words of his sentence
 * whose concatenation IS a word of a line on this list, or within one letter of a line's word of >= 8 letters, are joined
 * ("storm cloaks" -> stormcloaks, "gray beards" -> greybeards, "con tract" -> contract, "white run" -> whiterun; each half >= 3
 * letters, so "a long" never becomes "along"); "alright"
 * becomes "all right" when a line carries it. Punctuation is kept (the question shape reads the `?`). O(tokens x words).
 */
function lrgDlgSttFold(string $text, array $entries): string
{
    $u = lrgDlgTokens(lrgPromptNorm($text));
    if (!$u || !$entries) { return $text; }
    $et = [];
    $pairs = '';
    foreach ($entries as $e) {
        $n = (string) ($e['norm'] ?? '');
        if ($n === '') { continue; }
        $pairs .= ' ' . $n . ' ';
        foreach (lrgDlgTokens($n) as $w) { if (strlen($w) >= 4 && strpos($w, "'") === false) { $et[$w] = 1; } }
    }
    // [pt19h-reach G2] the aligned homophones first - a line of short words ("Who are you?") has no word of >= 4 letters
    $out = lrgDlgSttHomophones($text, $u, $entries);
    if (!$et) { return $out; }
    // [pt19c fixer / adversarial QA @adv_stt_homophone] the owner's STT writes the inn as "in" and here as "hear": folded ONLY
    // when a line on this list carries the word and his sentence does not ("nice in you got here" -> Hulda's "Nice inn ...")
    foreach (['in' => 'inn', 'hear' => 'here'] as $heard => $meant) {
        if (in_array($heard, $u, true) && !in_array($meant, $u, true) && strpos($pairs, ' ' . $meant . ' ') !== false
            && strpos($pairs, ' ' . $heard . ' ') === false) {
            $out = (string) preg_replace('/\b' . $heard . '\b/i', $meant, $out, 1);
        }
    }
    if (in_array('alright', $u, true) && strpos($pairs, ' all right ') !== false) {
        $out = (string) preg_replace('/\balright\b/i', 'all right', $out);
    }
    for ($i = 0, $c = count($u); $i + 1 < $c; $i++) {
        $a = (string) $u[$i];
        $b = (string) $u[$i + 1];
        // both halves >= 3 letters: "a long" is never "along", "no body" never "nobody" - an STT split halves a real word.
        // [pt19h-reach G2] ... or ONE half of 2 letters when the join is a line's EXACT word of >= 5 letters ("is ran" -> isran,
        // "re turn" -> return, "in side" -> inside, "co incidence" -> coincidence); never a leading "no" ("no body" stays two words)
        $short = strlen($a) < 3 || strlen($b) < 3;
        if ((isset($et[$a]) && isset($et[$b])) || strpos($a . $b, "'") !== false || min(strlen($a), strlen($b)) < 2
            || ($short && (strlen($a . $b) < 5 || !isset($et[$a . $b]) || $a === 'no'))) { continue; }
        $j = $a . $b;
        $to = isset($et[$j]) ? $j : '';
        if ($to === '' && strlen($j) >= 8) {
            foreach (array_keys($et) as $w) {
                $w = (string) $w;
                if (strlen($w) >= 8 && abs(strlen($w) - strlen($j)) <= 1 && levenshtein($j, $w) <= 1) { $to = $w; break; }
            }
        }
        if ($to === '') { continue; }
        $out = (string) preg_replace('/\b' . preg_quote($a, '/') . '\W+' . preg_quote($b, '/') . '\b/i', $to, $out, 1);
        $i++;
    }
    return $out;
}

/**
 * [pt19c-A fix 1 / game review 1, ai review] Do these WORDS carry this line? An exact or token-aligned containment hit does;
 * on the F1 / trigram tier a shared strict word OUTSIDE the frame words must be shared, and then S4.5's precision (>= 0.75)
 * or half of his subject words - so generic frame words (tell, about, know, any, like) never pick a line alone. Used where
 * HIS words pick a line with no model in between: the pre-LLM open's root clause and the fast path's similarity pick.
 * Scores untouched.
 */
function lrgDlgWordsCarry(string $words, array $e): bool
{
    if (trim($words) === '') { return false; }
    $words = lrgDlgSttFold($words, [$e]);
    $m = lrgDlgMatchText($words, [$e]);
    if ($m === null) { return false; }
    if (in_array((string) $m['tier'], ['exact', 'contain'], true) && empty($m['short'])) { return true; }
    // [pt19h-reach G2] ... and so do his words when they are the line with a word dropped: "need to talk to you" inside "I need to
    // talk to you." scores F1 1.0 over the containment's 0.85, and the tier label then hid it (lrgDlgReachContains)
    if (lrgDlgReachContains($words, $e)) { return true; }
    // [pt19h-reach G4] a request for WORK carries no line on the work NOUN alone: "put me to work" is no "You take your work very
    // seriously." (the radiant start is found by kind, lrgDlgReachPick); a line that offers work, or shares another word, still can
    if (lrgDlgReachWorkAsk($words) !== '' && !lrgDlgReachWorkLine($e)
        && !array_diff(array_intersect(lrgPromptWords(lrgPromptNorm($words), true), lrgPromptWords((string) ($e['norm'] ?? ''), true)),
            LRG_DLG_REACH_WORK_WORDS)) {
        return false;
    }
    // [pt19h-reach G2] THE STT REPAIR, against THIS line's own meaning words: a word of his the line lacks that is an STT echo of
    // a meaning word of the line he did not say ("torque" talk, "had it" headed, "partner knacks" Paarthurnax) is read as that
    // word - and his repaired sentence must then BE the line (or the line with a word dropped, lrgDlgReachContains). The
    // precision rail below reads only a SPLIT word joined back (2-3 of his words into one of the line's: "great beards"
    // Greybeards, "mal oral" Maluril) - never a one-word echo: "do you have any rooms" is no "Heard any rumors lately?", however
    // alike rooms and rumors sound. It only judges a pick the matcher already made (min_score, min_margin).
    $rep = lrgDlgSttRepair($words, $e);
    // [pt19h r2 / reach P5] a ONE-WORD echo read as the line ("I'm here to talk about Markarth" / "... Margret.", "your mother" / "your
    // master") carries a PROTECTED line for no click: at most the model asks (the split-word join below still carries it)
    if ($rep !== '' && lrgDlgReachContains($rep, $e) && !lrgDlgProtected($e)) { return true; }
    $joined = lrgDlgSttRepair($words, $e, true);
    if ($joined !== '') { $words = $joined; }
    // the FRAME words of a request or a question carry no subject of their own (ai review): a shared word outside them is
    // required, and then either S4.5's precision over all his strict words, or half of his subject words shared ("is there
    // work going" -> "I need work.", "I'd like a room for the night" -> the room line; never "tell me about the war" ->
    // "Tell me about Whiterun.", "do you have any mead" -> "Heard any rumors lately?")
    static $frame = ['tell', 'about', 'know', 'think', 'like', 'want', 'need', 'got', 'get', 'see', 'say', 'ask', 'talk', 'hear',
        'heard', 'any', 'anything', 'something', 'going', 'go'];
    $wu = lrgPromptWords(lrgPromptNorm($words), true);
    $shared = array_intersect($wu, lrgPromptWords((string) ($e['norm'] ?? ''), true));
    $subject = array_diff($shared, $frame);
    if (!$subject) { return false; }
    if (count($shared) / max(1, count($wu)) >= (float) lrgDlgCfg('confirm.single_entry_precision', 0.75)) { return true; }
    // [pt19h-orch / G9] ONE shared subject word carries the line only when it is ALL he said: "Paarthurnax is dead" is no
    // "Paarthurnax has changed, I cannot do what you ask of me.", "nice coat" no "I need your coat." - his other content word is
    // foreign to the line, so the name alone is a topic, not the line ("is there work going" -> "I need work." still carries)
    $mine = array_diff($wu, $frame);
    // [pt19h r2 / the first-evening words rows] ... unless the line ASKS (a question of hers to answer) and his other word only NARROWS
    // the shared subject in a tail "about / of / on <topic>" after it ("any rumors about the dragons" -> "Heard any rumors lately?"), or
    // both name the same "about <topic>" ("sing me something about dragons", "got any songs about dragons" -> "Do you know any old ballads
    // about dragons?"); a statement line keeps the rule ("Paarthurnax is dead" is no "Paarthurnax has changed, ..."), and so does a
    // question whose foreign word is no narrowing ("who is the Jarl's steward" is no "Who is the Jarl?")
    if (count($subject) < 2 && array_diff($mine, $subject)
        && !(lrgDlgEntryIsQuestion($e) && lrgDlgNarrowsSubject($words, $e, array_values($subject), array_values(array_diff($mine, $subject))))) {
        return false;
    }
    return count($subject) / max(1, count($mine)) >= 0.5;
}

/**
 * [pt19h r2 / the first-evening words rows] Do his FOREIGN content words only narrow the line's subject? TRUE when every one of them
 * sits in a tail "about / of / on / regarding / concerning ..." that follows the shared subject word in his sentence, or when his
 * sentence and the line both say "about <topic>" with a topic word in common (stems count). Used by lrgDlgWordsCarry on a question
 * line only.
 */
function lrgDlgNarrowsSubject(string $words, array $e, array $subject, array $foreign): bool
{
    static $preps = ['about', 'of', 'on', 'regarding', 'concerning'];
    $tok = lrgDlgTokens(lrgPromptNorm($words));
    $tok = array_map(static fn($w) => str_replace("'", '', (string) $w), $tok);
    $stemAt = static function (array $tok, string $w): int {
        foreach ($tok as $i => $t) { if ($t === $w || lrgDlgStemIn($w, [$t])) { return (int) $i; } }
        return -1;
    };
    $sAt = -1;
    foreach ($subject as $w) { $i = $stemAt($tok, (string) $w); if ($i >= 0 && ($sAt < 0 || $i < $sAt)) { $sAt = $i; } }
    if ($sAt >= 0) {
        $pAt = -1;
        for ($i = $sAt + 1; $i < count($tok); $i++) { if (in_array($tok[$i], $preps, true)) { $pAt = $i; break; } }
        if ($pAt >= 0) {
            $tail = array_slice($tok, $pAt + 1);
            $all = true;
            foreach ($foreign as $w) { if ($stemAt($tail, (string) $w) < 0) { $all = false; break; } }
            if ($all) { return true; }
        }
    }
    // the same "about <topic>" on both sides
    $aboutOf = static function (array $tok): array {
        $p = array_search('about', $tok, true);
        return $p === false ? [] : lrgPromptWords(implode(' ', array_slice($tok, (int) $p + 1)), true);
    };
    $his = $aboutOf($tok);
    $hers = $aboutOf(array_map(static fn($w) => str_replace("'", '', (string) $w), lrgDlgTokens(lrgPromptNorm((string) ($e['text'] ?? '')))));
    if (!$his || !$hers) { return false; }
    foreach ($his as $w) { if (lrgDlgStemIn((string) $w, $hers)) { return true; } }
    return false;
}

// ================================================================== [pt19h-reach] REACH: STT repair, work asks, reports
/*
 * [pt19h-reach] Three ways his own words missed the line he plainly meant (research/pt19h-measure.md G2, G4, and the Nazir
 * turn-ins of research/pt19x-coverage/brotherhood.md gap 1): STT damage to the one meaning word (lrgDlgSttHomophones in the
 * fold, lrgDlgSttRepair in lrgDlgWordsCarry), a request for work that shares no word with the radiant start ("got any work?"
 * -> "What can I do to help?"), and a report that names its target ("Narfi has been dealt with" -> "Narfi is dead.", beside
 * "Tell me about Narfi."). No floor moves: the repair only lets through a pick the matcher ALREADY made (min_score,
 * min_margin), and the two picks (lrgDlgReachPick) run only where the similarity path chose nothing, as a service BY KIND
 * does (S5) - every rail after them (explicit on a commit, the stage rail, the price rail, LETHAL, read-only) is unchanged.
 * The lists are dialogue.reach.* in the config JSON (these constants are the code defaults; tools/test_services.php checks
 * the JSON carries every one of them).
 */
const LRG_DLG_REACH_WORK_SAY = ['any work', 'some work for me', 'more work for me', 'any more work', 'work for me to do',
    'have work for me', 'got work for me', 'looking for work', 'need work', 'need some work', 'want work', 'want some work', 'find work',
    'put me to work', 'extra work', 'a job for me', 'any jobs', 'any job', 'got a job', 'need a job', 'want a job', 'looking for a job',
    'give me a job', 'give me work', 'give me some work', 'any tasks', 'a task for me', 'odd jobs', 'anything need doing',
    'anything needs doing', 'anything that needs doing', 'something that needs doing', 'anything to be done', 'anything need done',
    'anything that needs to be done', 'can i help', 'how can i help', 'can i be of help', 'can i be of service', 'can i be of use',
    'anything i can do', 'something i can do', 'anything i can help with', 'can i assist', 'any college business', 'lend a hand',
    'lend you a hand', 'help you out',
    // [pt19h r2 / reach P9] the commonest offers ("what can I do for you", "let me help", "any errands?")
    'what can i do for you', 'anything i can do for you', 'can i do anything for you', 'let me help', 'i want to help', 'how may i help',
    'any errands', 'any bounties', 'need a hand', 'got anything for me'];
// (never "do you need help?" / "need a hand?": a question about HER need is the matcher's and the model's - the coverage team's
// near-miss for J'zargo's and Brelyna's "What did you need help with?")
// [pt19h r2 / reach P1, P8, P9] + with / here / for my ...; "for you" is out (it cancelled "anything I can do for you", the most natural offer)
const LRG_DLG_REACH_WORK_NOT_AFTER = ['myself', 'yourself', 'it', 'on', 'for her', 'for him', 'for them', 'for my', 'for a', 'for the', 'about',
    'done', 'being', 'with', 'here', 'from you', 'or should', 'elsewhere'];
const LRG_DLG_REACH_WORK_ENTRY = ['what can i do to help', 'anything i can do to help', 'something i can do to help',
    'anything else i can do to help', 'looking for work', 'is there any work', 'any work to be done', 'any work i can help with',
    'any work available', 'any work that needs doing', 'any work you need done', 'any more work for me', 'any work for me',
    'work you need done', 'extra work', 'have work for me', 'had work for me', 'have some work for me', 'had a job for me',
    'have a job for me', 'college business i can assist with', 'college business i can help with', 'need any more help with',
    'might need help', 'what do you need help with', 'what did you need help with', 'do you need help with',
    'anything you need help with', 'need help with anything', 'need help with something', 'anything i can help you with',
    'something i can help you with', 'anything i could help you with', 'can i help with your research', 'you could use some help',
    'you could use a hand', "what's the next target", 'anything that needs doing', "let's get to work", "let's just get to work",
    // [pt19h r2 / reach P2] Urag's second radiant start: with the College-business line on one list, she asks which
    'any special books'];
/** The NOUNS of a work ask: sharing only these with a line is no reason to click it (lrgDlgWordsCarry). */
const LRG_DLG_REACH_WORK_WORDS = ['work', 'job', 'jobs', 'business', 'task', 'tasks'];
const LRG_DLG_REACH_REPORT_SAY = ['dead', 'dealt with', 'taken care of', 'took care of', 'done', 'finished', 'killed', 'handled',
    'is no more', "won't be a problem", 'wont be a problem', "won't be bothering", 'wont be bothering', 'silenced', 'eliminated',
    'assassinated', 'slain', 'murdered', 'complete', 'completed', 'taken out', 'took out', 'disposed of'];
const LRG_DLG_REACH_REPORT_ENTRY = ['is dead', 'are dead', 'is dead now', 'was killed', 'were killed', 'has been killed',
    'have been killed', 'has been taken care of', 'have been taken care of', 'is taken care of', 'has been dealt with',
    'have been dealt with', 'is dealt with', 'is no more', 'has been slain', 'have been slain', 'was slain', 'were slain'];
/** Words that make a report no report: a plan, a condition, a wish ("Narfi will be dealt with", "once Narfi is dead"). */
const LRG_DLG_REACH_REPORT_NOT_WITH = ['will', "i'll", 'ill', "we'll", "it'll", "he'll", "she'll", "they'll", "you'll", 'shall', 'gonna', 'going',
    'soon', 'later', 'yet', 'must', 'should', 'need', 'needs', 'want', 'wants', 'can', 'could', 'would', 'might', 'may', 'if', 'once',
    'when', 'before', 'until', 'unless', 'try', 'trying', 'plan', 'planning', 'about', 'tell', 'hope', 'wish', 'supposed',
    // [pt19h-reach review] a HEDGE or hearsay is no report ("i think / i guess / maybe Narfi is dead", "Narfi is probably /
    // supposedly / almost dead", "i heard Narfi is dead", "i doubt Narfi is dead", "Narfi is as good as dead", "..., right")
    'think', 'guess', 'maybe', 'perhaps', 'probably', 'possibly', 'likely', 'supposedly', 'apparently', 'heard', 'hear', 'doubt',
    'believe', 'reckon', 'suppose', 'seems', 'seem', 'almost', 'nearly', 'practically', 'good', 'rumor', 'rumour', 'said', 'says',
    'told', 'claims', 'right', 'wonder', 'whether', 'bet'];
/** [pt19h-reach review] An idiom after the report word is no report ("Narfi is dead to me", "dead tired", "dead wrong"). */
const LRG_DLG_REACH_REPORT_NOT_AFTER = ['to me', 'to us', 'to him', 'to her', 'to them', 'tired', 'drunk', 'wrong', 'serious', 'set',
    'end', 'ringer', 'weight', 'meat', 'broke'];
/** The words of a report line's subject that name nobody ("It's done. Paarthurnax is dead.", "Your friend Gavros is dead."). */
const LRG_DLG_REACH_REPORT_GENERIC = ['done', 'over', 'well', 'yes', 'yeah', 'its', 'last', 'leader', 'master', 'friend', 'old',
    'your', 'our', 'their', 'kind', 'great', 'little', 'big', 'all', 'every', 'one', 'ones', 'rest', 'whole', 'company', 'found',
    'but', 'really', 'finally', 'now', 'daughter', 'son', 'wife', 'husband', 'father', 'mother', 'brother', 'sister'];

/**
 * [pt19h-reach G2] ALIGNED HOMOPHONES, a step of lrgDlgSttFold: "i no that's why" / "so we no his name" / "you no where to find"
 * say "know", "what do we do know" says "now", "i have know doubts" says "no", "talk to ewe" says "you". A word of his is
 * replaced ONLY where a line on this list carries the meant word between the SAME neighbours (his "<a> no <b>" against a
 * line's "<a> know <b>", apostrophes folded; at his sentence's end the left neighbour alone, at its start the right one alone
 * against the line's own start - "hoo are you" - and NEVER for a negator: a word that is or becomes no / not needs both
 * neighbours, so "I... no." stays a refusal and "no, I know" stays as said); "we no longer need him" folds nothing (no line
 * says "we know longer"). Two passes, so a fold may align the next one ("i'm knot shore" -> "i'm not sure"). Returns $out
 * with the replacements made.
 */
function lrgDlgSttHomophones(string $out, array $u, array $entries): string
{
    static $map = ['no' => ['know', 'now'], 'know' => ['no', 'now'], 'now' => ['know'], 'knew' => ['new'], 'new' => ['knew'],
        'four' => ['for'], 'won' => ['one'], 'weight' => ['wait'], 'weighting' => ['waiting'], 'write' => ['right'],
        'their' => ['there'], 'there' => ['their'], 'wit' => ['with'], 'un' => ['on'], 'dew' => ['do'], 'due' => ['do'],
        'ewe' => ['you'], 'yew' => ['you'], 'u' => ['you'], 'ya' => ['you'], 'r' => ['are'], 'mi' => ['me'], 'hoo' => ['who'],
        'dat' => ['that'], 'vat' => ['that'], 'bin' => ['been'], 'meat' => ['meet'], 'wear' => ['where'], 'watt' => ['what'],
        'whole' => ['hole'], 'hole' => ['whole'], 'piece' => ['peace'], 'peace' => ['piece'], 'shore' => ['sure'],
        'sense' => ['since'], 'weather' => ['whether'], 'too' => ['to'], 'knot' => ['not'], 'bough' => ['bow'], 'thyme' => ['time'],
        'dun' => ['done'], 'hair' => ['here'], 'sea' => ['see'], 'sew' => ['so'], 'tail' => ['tale'], 'tale' => ['tail']];
    static $neg = ['no', 'not', 'knot'];
    $c = count($u);
    if ($c < 2) { return $out; }
    $f = static fn($w): string => str_replace("'", '', (string) $w);
    $lines = [];
    foreach ($entries as $e) {
        $t = array_map($f, lrgDlgTokens((string) ($e['norm'] ?? '')));
        if ($t) { $lines[] = $t; }
    }
    $cur = array_map($f, array_values($u));
    // a neighbour agrees when it is the line's word, or itself one of its homophones ("knot shore" against "not sure")
    $same = static fn(string $his, string $line): bool => $his === $line || in_array($line, (array) ($map[$his] ?? []), true);
    for ($pass = 0; $pass < 2; $pass++) {
        for ($i = 0; $i < $c; $i++) {
            $heard = (string) $cur[$i];
            if (!isset($map[$heard])) { continue; }
            $to = '';
            foreach ($map[$heard] as $meant) {
                $isNeg = in_array($heard, $neg, true) || in_array($meant, $neg, true);
                foreach ($lines as $n) {
                    foreach ($n as $j => $w) {
                        if ($w !== $meant) { continue; }
                        $left = $i === 0 ? ($j === 0 && !$isNeg) : ($j > 0 && $same((string) $cur[$i - 1], (string) $n[$j - 1]));
                        $right = isset($cur[$i + 1]) ? (isset($n[$j + 1]) && $same((string) $cur[$i + 1], (string) $n[$j + 1]))
                            : (!$isNeg && !isset($n[$j + 1]) && $i > 0);
                        if ($left && $right) { $to = $meant; break 3; }
                    }
                }
            }
            if ($to === '') { continue; }
            // replace THIS occurrence in the raw text: the norm's tokens keep the text's word order, and $cur already carries
            // the replacements made before it (one word for one word)
            $want = count(array_keys(array_slice($cur, 0, $i + 1), $heard, true));
            $k = 0;
            // [pt19h-reach review] never INSIDE a contraction: $cur folds "won't" to "wont" and "there's" to "theres", so the raw
            // text skips them too ("i won't pay, give me the won on the left" had become "i one't pay" - his negation gone)
            $out = (string) preg_replace_callback('/(?<!\')(?<!\xE2\x80\x99)\b' . preg_quote($heard, '/') . '\b(?!\'|\xE2\x80\x99)/i', static function (array $mm) use (&$k, $want, $to): string {
                $k++;
                return $k === $want ? $to : $mm[0];
            }, $out);
            $cur[$i] = $to;
        }
    }
    return $out;
}

/**
 * [pt19h-reach G2] His words ARE the line with a word dropped: equal, or a token-aligned run inside it that covers >= 3/4 of its
 * tokens (never short, F16): "need to talk to you" in "I need to talk to you.", "need to talk" in "We need to talk." - never
 * "what do you want" in "So, what do you want me to do?". The other direction (the line inside a longer sentence of his:
 * "wait, what do we do now?", "not yet, I don't know what to say") is the matcher's containment tier alone, as shipped.
 */
function lrgDlgReachContains(string $words, array $e): bool
{
    // (apostrophes folded: the owner's STT writes "lets go", "whats in it for me", "ill need to be going"; a sum in words is
    // the line's digits - "it's two thousand coins" is "It's 2,000 coins.", both "#" once normalised)
    static $num = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty',
        'seventy', 'eighty', 'ninety', 'hundred', 'thousand', '#'];
    $hash = static function (array $tok) use ($num): array {
        $o = [];
        foreach ($tok as $w) {
            $isNum = in_array($w, $num, true);
            if ($isNum && $o && end($o) === '#') { continue; }
            $o[] = $isNum ? '#' : $w;
        }
        return $o;
    };
    $ut = $hash(lrgDlgFoldTokens($words));
    $nt = $hash(lrgDlgFoldTokens((string) ($e['norm'] ?? '')));
    if (!$ut || !$nt) { return false; }
    if ($ut === $nt) { return true; }
    return count($ut) < count($nt) && count($ut) >= max(min(3, count($nt)), (int) ceil(0.75 * count($nt))) && lrgDlgTokenRun($nt, $ut);
}

/**
 * [pt19h-reach G2] THE STT REPAIR of lrgDlgWordsCarry: his sentence (normalised) with every run of 1-3 of his tokens that the line
 * lacks and that is an STT echo (lrgDlgReachEcho) of a MEANING word of the line he did not say replaced by that word - each
 * line word used once, longest run first ("had it" -> headed, "partner knacks" -> paarthurnax, "weighting" -> waiting). ''
 * when nothing was repaired. Only the line's strict words are targets: a function word ("you", "the") is the aligned
 * homophone fold's to repair, never an echo's (his foreign content word must not vanish into a stop word).
 */
function lrgDlgSttRepair(string $words, array $e, bool $joinsOnly = false): string
{
    $fold = static fn($w): string => str_replace("'", '', (string) $w);
    $et = array_map($fold, lrgDlgTokens((string) ($e['norm'] ?? '')));
    $ut = lrgDlgTokens(lrgPromptNorm($words));
    if (!$et || !$ut) { return ''; }
    $uf = array_map($fold, $ut);
    $missing = [];
    foreach (lrgPromptWords((string) ($e['norm'] ?? ''), true) as $w) {
        $w = $fold($w);
        if (strlen($w) >= 3 && ctype_alpha($w) && !in_array($w, $uf, true)) { $missing[$w] = 1; }
    }
    if (!$missing) { return ''; }
    $out = [];
    $did = false;
    for ($i = 0, $c = count($uf); $i < $c; $i++) {
        $hit = '';
        $span = 0;
        for ($k = min(3, $c - $i); $k >= ($joinsOnly ? 2 : 1) && $hit === '' && !in_array($uf[$i], $et, true); $k--) {
            $parts = array_slice($uf, $i, $k);
            $ok = true;
            foreach ($parts as $p) { if (in_array($p, $et, true) || !ctype_alpha((string) $p)) { $ok = false; break; } }
            $cand = implode('', $parts);
            if (!$ok || ($k > 1 && strlen($cand) < 5)) { continue; }
            foreach (array_keys($missing) as $mw) {
                // (a split word joined back EXACTLY - "a bout" about, "dust man's" dustman's - or a bounded echo)
                if (($k > 1 && $cand === (string) $mw) || lrgDlgReachEcho($cand, (string) $mw)) { $hit = (string) $mw; $span = $k; break; }
            }
        }
        if ($hit === '') { $out[] = $ut[$i]; continue; }
        $out[] = $hit;
        unset($missing[$hit]);
        $did = true;
        $i += $span - 1;
    }
    return $did ? implode(' ', $out) : '';
}

/**
 * [pt19h-reach G2] A BOUNDED STT echo: $heard (his, 1-3 words joined, apostrophes folded) for $meant (a meaning word of the line).
 * The SAME first letter (an STT keeps the onset - never "the war" for Whiterun) unless one letter is all that differs
 * ("kneed" need), and then EITHER a letter edit distance of at most a quarter of the word (one for a word of 3-7 letters:
 * "drank" drink, "jab" job, "sar thal" Saarthal; two from 8: "malls born" Malborn, "great beards" Greybeards) OR the same
 * sound with codes of >= 3 (metaphone equal or one step apart - "had it" headed, "torque" talk, "hell again" Helgen, "invite a
 * shun" invitation; two steps for a long name, codes >= 6 - "partner knacks" Paarthurnax). Short words are never echoes by
 * sound ("there" is no "top", "down" no "drew", "will" no "way": codes of 2), and another FORM of the line's word is a word he
 * chose, not damage ("a rumor" is no "rumors", "escape" no "escaped").
 */
function lrgDlgReachEcho(string $heard, string $meant): bool
{
    $lh = strlen($heard);
    $lm = strlen($meant);
    if ($lh < 3 || $lm < 3 || $heard === $meant) { return false; }
    $lev = levenshtein($heard, $meant);
    if ($heard[0] !== $meant[0] && $lev > 1) { return false; }
    [$s, $l] = $lh <= $lm ? [$heard, $meant] : [$meant, $heard];
    if (str_starts_with($l, $s) && in_array(substr($l, strlen($s)), ['s', 'es', 'd', 'ed', 'ing', 'er', 'ers', 'ly', 'n', 'en'], true)) { return false; }
    if ($lev <= max(1, intdiv(max($lh, $lm), 4))) { return true; }
    if (min($lh, $lm) < 4) { return false; }
    $mh = str_replace('0', 'T', metaphone($heard));
    $mm = str_replace('0', 'T', metaphone($meant));
    $n = min(strlen($mh), strlen($mm));
    if ($n < 3 || $mh[0] !== $mm[0]) { return false; }
    $ml = levenshtein($mh, $mm);
    return $ml <= 1 || ($ml === 2 && $n >= 6);
}

/**
 * [pt19h-reach G4] Does he ASK FOR WORK ("got any work?", "anything need doing?", "put me to work", "can I help?")? The phrase
 * that hit (dialogue.reach.work.say), or ''. Never negated ("I don't need any work"), never a price question ("how much does
 * the work pay"), never a refusal or a deferral ("not now, maybe later").
 */
function lrgDlgReachWorkAsk(string $utter): string
{
    if (empty(lrgDlgCfg('reach.work.enabled', true)) || trim($utter) === '') { return ''; }
    $hit = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('reach.work.say', LRG_DLG_REACH_WORK_SAY));
    if ($hit === '' || lrgDlgHitFollowedBy($utter, $hit, (array) lrgDlgCfg('reach.work.not_after', LRG_DLG_REACH_WORK_NOT_AFTER))) { return ''; }
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $at = lrgDlgTokenAt($hay, lrgDlgTokens(lrgPromptNorm($hit)));
    if ($at < 0 || lrgDlgNegatedAt($hay, $at, lrgDlgClauseStarts($utter))) { return ''; }
    if (lrgDlgIsPriceQuestion($utter) || lrgDlgRefuses($utter)) { return ''; }
    // [pt19h r2 / reach P1, P8] never a deferral, a hedge or a refusal anywhere ("I'll help you out later", "help you out? not a chance"),
    // never taken back ("any work? actually no, I want to buy something"), never a condition ("can I help after I finish my training")
    if (preg_match(LRG_DLG_DEFER_RE, strtolower($utter)) || lrgDlgHedges($utter)
        || preg_match('/\b(not a chance|no chance|forget it|never ?mind|actually no|no wait|scratch that|on second thought|after i|once i|when i|if i|unless|elsewhere)\b/i', $utter)
        // (a musing is no request: "I wonder if there is anything I can do" - reach P6 / P8; he asks plainly and she offers)
        || preg_match('/\bi (wonder|was wondering|am wondering|m wondering)\b/i', str_replace("'", '', $utter))) {
        return '';
    }
    $hitT = lrgDlgTokens(lrgPromptNorm($hit));
    $hitN = implode(' ', $hitT);
    $nextT = str_replace("'", '', (string) ($hay[$at + count($hitT)] ?? ''));
    // a HELP offer is to HER: "can I help him", "how can I help the stranger", "can I help Cicero" offer her nothing
    if (preg_match('/\bhelp$/', $hitN) && $nextT !== ''
        && !in_array($nextT, ['you', 'ya', 'out', 'with', 'here', 'around', 'somehow', 'at', 'in', 'then', 'today', 'now', 'again', 'somewhere', 'anywhere'], true)) {
        return '';
    }
    // "anything I can do to <verb>" offers work only for help ("anything I can do to change your mind" asks a favour)
    if (preg_match('/^(?:anything|something) i can do$/', $hitN) && $nextT === 'to' && str_replace("'", '', (string) ($hay[$at + count($hitT) + 1] ?? '')) !== 'help') { return ''; }
    // the VERB right before the phrase with a second- or third-person subject asks about THEM ("did you find any work?", "have you got a
    // job here?"); "do you have any work (for me)" still asks for it
    static $verbs = ['find', 'found', 'finish', 'finished', 'get', 'got', 'gotten', 'need', 'needed', 'want', 'wanted', 'do', 'doing', 'done',
        'lend', 'lending', 'looking', 'seek', 'seeking', 'give', 'giving', 'offer', 'offering', 'take', 'taking', 'start', 'started'];
    static $them = ['you', 'youre', 'youve', 'youd', 'youll', 'ya', 'u', 'ye', 'he', 'hes', 'she', 'shes', 'they', 'theyre', 'it', 'its', 'that',
        'thats', 'this', 'who', 'whos', 'someone', 'anyone', 'somebody', 'anybody', 'everyone', 'nobody'];
    static $me = ['i', 'im', 'ive', 'id', 'ill', 'me', 'we', 'weve', 'us', 'lets', 'my'];
    $stops = lrgDlgClauseStarts($utter);
    $from = 0;
    foreach ($stops as $s) { if ((int) $s <= $at) { $from = max($from, (int) $s); } }
    if (!preg_match('/\b(for me|for us|me to do|i can|i could|put me|give me|myself)\b/', $hitN)) {
        $k = $at - 1;
        while ($k >= $from && in_array((string) $hay[$k], ['a', 'an', 'the', 'some', 'any', 'more', 'me', 'us'], true)) { $k--; }
        if ($k >= $from && in_array(str_replace("'", '', (string) $hay[$k]), $verbs, true)) {
            for ($j = $k - 1; $j >= $from; $j--) {
                $w = str_replace("'", '', (string) $hay[$j]);
                if (in_array($w, $me, true)) { break; }
                if (in_array($w, $them, true)) { return ''; }
            }
        }
        // the phrases that need HIS frame ("need a job", "got a job", "lend a hand", "help you out", "extra work"): a first person in the
        // clause, or the whole sentence is the phrase ("need work?")
        if (in_array($hitN, ['need work', 'need some work', 'want work', 'want some work', 'need a job', 'want a job', 'got a job', 'lend a hand',
            'lend you a hand', 'help you out', 'extra work', 'have work for me', 'got work for me', 'any work', 'any job', 'any jobs'], true) && count($hay) > count($hitT)) {
            $next2 = str_replace("'", '', (string) ($hay[$at + count($hitT) + 1] ?? ''));
            if ($nextT === 'for' && in_array($next2, ['you', 'ya', 'him', 'her', 'them'], true)) { return ''; }   // "I got a job for you" offers HER one
            if ($hitN === 'got a job' && $nextT === 'to') { return ''; }   // "I got a job to do" tells her of his own ("I need a job to pay my rent" still asks)
            $cl = array_map(static fn($w) => str_replace("'", '', (string) $w), array_slice($hay, $from));
            if (!in_array($hitN, ['any work', 'any job', 'any jobs'], true) && !array_intersect($cl, $me) && $nextT !== 'for') { return ''; }
        }
    }
    // [pt19h-reach review] HIS request only. A need / seek phrase whose nearest subject is HER or somebody else asks about THEM
    // ("are you looking for work?", "do you need work?", "can you lend a hand?", "did you find work?", "is he looking for work",
    // "you should find work") - it never picks her radiant start; his own ("i'm looking for work", "where can i find work", "let
    // me lend a hand") or none ("need work?") still does. And a remark is no request ("that's a lot of extra work").
    $lead = (string) (lrgDlgTokens(lrgPromptNorm($hit))[0] ?? '');
    if (in_array($lead, ['looking', 'need', 'want', 'find', 'lend', 'help'], true)) {
        for ($k = $at - 1; $k >= 0; $k--) {
            $w = str_replace("'", '', (string) $hay[$k]);
            if (in_array($w, ['i', 'im', 'ive', 'id', 'ill', 'me', 'we', 'weve', 'us', 'lets', 'my'], true)) { break; }
            if (in_array($w, ['you', 'youre', 'youve', 'youd', 'youll', 'ya', 'u', 'ye', 'he', 'hes', 'she', 'shes', 'they', 'theyre',
                'it', 'its', 'that', 'thats', 'this', 'who', 'whos', 'someone', 'anyone', 'somebody', 'anybody', 'everyone', 'nobody'], true)) {
                return '';
            }
        }
    }
    if ($lead === 'extra' && $at > 0 && in_array(str_replace("'", '', (string) $hay[$at - 1]), ['of', 'thats', 'its', 'is', 'was', 'lot'], true)) {
        return '';
    }
    return $hit;
}

/**
 * [pt19h-reach G4] Is this line of hers a request for work - a radiant start ("What can I do to help?", "I'm looking for work.
 * ...", "Is there any College business I can assist with?", "I'm ready for some extra work.")? A phrase of
 * dialogue.reach.work.entry on its norm, and never a back-out, a check, a priced or a meta line. Index rows (no class) count
 * by their text alone.
 */
function lrgDlgReachWorkLine(array $e, bool $leads = false): bool
{
    if (in_array((string) ($e['class'] ?? 'plain'), ['hidden', 'meta', 'back', 'check', 'pay', 'service'], true)) { return false; }
    if ((string) ($e['kind'] ?? '') !== '' || (int) ($e['cost'] ?? 0) !== 0) { return false; }
    $n = (string) ($e['norm'] ?? '');
    $hit = $n !== '' ? lrgDlgPhraseHit($n, (array) lrgDlgCfg('reach.work.entry', LRG_DLG_REACH_WORK_ENTRY)) : '';
    if ($hit === '') { return false; }
    if (!$leads) { return true; }
    // [pt19h r2 / reach P8] the offer LEADS the line (a radiant start: "Is there any College business I can assist with?"), after at most
    // two lead-in words - never a reply that carries it later ("Hmm. You have a point. What can I do to help?")
    $nt = lrgDlgTokens($n);
    while ($nt && in_array($nt[0], ['so', 'well', 'uh', 'um', 'oh', 'yes', 'okay', 'alright', 'fine', 'hmm', 'then', 'and', 'now', 'right', 'sure'], true)) { array_shift($nt); }
    return lrgDlgTokenAt($nt, lrgDlgTokens(lrgPromptNorm($hit))) === 0;
}

/**
 * [pt19h-reach G4 / Nazir] THE REACH PICK, run by lrgDlgKindPick before the service kinds - so only where the similarity path chose
 * nothing, on the fast path and in the gate alike. On a root or closed layer: a request for WORK -> the ONE work line of the
 * list (two -> she asks which); a REPORT naming its target -> the ONE report line that names it (lrgDlgReachReport).
 * ['entry' => e|null, 'why' => ...] (mode `kind`: a commit still needs his explicit sentence, S4.3), or null (not his ask).
 */
function lrgDlgReachPick(array $entries, string $utter, string $layerKind, bool $noReport = false): ?array
{
    if (trim($utter) === '' || !in_array($layerKind, ['root', 'closed'], true)) { return null; }
    $vis = array_values(array_filter($entries, static fn($e) => (string) ($e['class'] ?? '') !== 'hidden'));
    if (!$vis) { return null; }
    // [pt19h r2 / reach P6] a PROTECTED line found by kind passes the rails his words face on every other path: a hedge, a deferral, a
    // bargain, a negation or a qualm around the line ("maybe narfi is dead", "I'll help you out later", "Hern is dead? good") clicks nothing
    $guard = static function (?array $r) use ($utter): ?array {
        if ($r === null || !is_array($r['entry'] ?? null)) { return $r; }
        $e = (array) $r['entry'];
        if (!lrgDlgProtected($e)) { return $r; }
        $qq = lrgDlgQuoteQualm($utter, $e);
        // (a report phrase that carries its own negator - "won't be a problem anymore" - is the report, no clash with "Narfi is dead.")
        $sayHit = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('reach.report.say', LRG_DLG_REACH_REPORT_SAY));
        $ownNeg = $sayHit !== '' && (bool) preg_match('/\b(won\'?t|wont|not|no)\b/i', $sayHit);
        if ($qq['qualm'] !== '' || lrgDlgHedges($utter) || lrgDlgDefers($utter, $e) || lrgDlgBargains($utter, $e) || (!$ownNeg && lrgDlgNegationClash($utter, $e))) {
            return ['entry' => null, 'why' => 'reach: "' . substr($utter, 0, 40) . '" hedges, defers, bargains, negates or asks about "'
                . substr((string) ($e['text'] ?? ''), 0, 40) . '" - a protected line is not clicked by kind on it'];
        }
        return $r;
    };
    $work = lrgDlgReachWorkAsk($utter);
    if ($work !== '') {
        // [pt19h r2 / reach P8] on a CLOSED layer only a radiant START (the offer leads the line), never a reply choice ("Hmm. You have a
        // point. What can I do to help?" sides with Loreius)
        $c = array_values(array_filter($vis, static fn($e) => lrgDlgReachWorkLine((array) $e, $layerKind === 'closed')));
        // his QUESTION that says the work line back ("I heard you're offering extra work?", "is it true that I'm looking for
        // work") is the echo the matcher already turned down (G11): the kind pick never brings it back
        if (count($c) === 1 && lrgDlgIsQuestion($utter) && !lrgDlgEntryIsQuestion((array) $c[0])) {
            $me = lrgDlgMatchText($utter, [$c[0]]);
            if ($me !== null && (float) $me['score'] >= 0.85) { return null; }
        }
        if (count($c) === 1) { return $guard(['entry' => $c[0], 'why' => 'reach: a request for work ("' . $work . '") - the one line that offers it']); }
        if (count($c) > 1) { return ['entry' => null, 'why' => 'reach: ' . count($c) . ' lines offer work and his words name none of them - she asks which']; }
        return null;
    }
    // [pt19h r2 / reach P7] the similarity path saw a word of his on ANOTHER line: the report pick never overrides that veto (she asks)
    if ($noReport) { return null; }
    return $guard(lrgDlgReachReport($vis, $utter));
}

/**
 * [pt19h-reach / Nazir] A REPORT that names its target: "Narfi has been dealt with", "the Narfi contract is done", "I finished
 * the job on Narfi", STT "narfy is taken care of" -> the ONE line of this list that reports that target ("Narfi is dead."),
 * never the "Tell me about Narfi." beside it (it shares the name and nothing else, so the margin never reached 0.15). A
 * statement only: never a question ("is Narfi dead?"), a refusal, a negation outside the report phrase ("Narfi isn't dead
 * yet"), a plan or a condition ("Narfi will be dealt with", "once Narfi is dead"). The name: a meaning word of the line's
 * subject, said exactly, or - for a name of >= 4 letters - an STT echo of >= 5 letters that starts with the same letter
 * ("enodius", "bay tilled", "mal oral"; never "here" for Hern). Two report lines named -> she asks which.
 */
function lrgDlgReachReport(array $vis, string $utter): ?array
{
    if (empty(lrgDlgCfg('reach.report.enabled', true))) { return null; }
    $say = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('reach.report.say', LRG_DLG_REACH_REPORT_SAY));
    if ($say === '' || lrgDlgIsQuestion($utter) || lrgDlgRefuses($utter)) { return null; }
    $fold = static fn($w): string => str_replace("'", '', (string) $w);
    $tok = lrgDlgTokens(lrgPromptNorm($utter));
    $st = lrgDlgTokens(lrgPromptNorm($say));
    $at = lrgDlgTokenAt($tok, $st);
    if ($at < 0) { return null; }
    $rest = $tok;
    array_splice($rest, $at, count($st));
    $restF = array_map($fold, $rest);
    if (array_intersect($restF, LRG_DLG_NEG) || array_intersect($rest, (array) lrgDlgCfg('reach.report.not_with', LRG_DLG_REACH_REPORT_NOT_WITH))
        || lrgDlgHitFollowedBy($utter, $say, (array) lrgDlgCfg('reach.report.not_after', LRG_DLG_REACH_REPORT_NOT_AFTER))) {
        return null;
    }
    $named = [];
    foreach ($vis as $e) {
        $names = lrgDlgReachReportNames((array) $e);
        // [pt19h-reach review] ... and the name is what the report is ABOUT (lrgDlgReachReportShape): never the doer ("Narfi
        // killed my dog", "Narfi finished his soup"), never a deed that is not the contract ("I finished talking to Narfi")
        if ($names && lrgDlgReachNames($restF, $names) && lrgDlgReachReportShape($tok, $at, count($st), $say, $names)) { $named[] = $e; }
    }
    if (count($named) === 1) {
        return ['entry' => $named[0], 'why' => 'reach: a report ("' . $say . '") that names "' . substr((string) ($named[0]['text'] ?? ''), 0, 40) . '"'];
    }
    if (count($named) > 1) { return ['entry' => null, 'why' => 'reach: his report names ' . count($named) . ' report lines - she asks which']; }
    return null;
}

/** [pt19h-reach / Nazir] The name words of a REPORT line ("Narfi is dead." -> [narfi]), or [] when it is none (or names nobody). */
function lrgDlgReachReportNames(array $e): array
{
    if (!in_array((string) ($e['class'] ?? ''), ['plain', 'commit'], true) || (string) ($e['kind'] ?? '') !== '' || (int) ($e['cost'] ?? 0) !== 0) {
        return [];
    }
    // [pt19h r2 / reach P3] a QUESTION line reports nothing ("Arch-Mage Aren is dead?" asks; "arch mage aren is done for" is no answer to it)
    if (lrgDlgEntryIsQuestion($e)) { return []; }
    $nt = lrgDlgTokens((string) ($e['norm'] ?? ''));
    $best = 0;
    foreach ((array) lrgDlgCfg('reach.report.entry', LRG_DLG_REACH_REPORT_ENTRY) as $p) {
        $pt = lrgDlgTokens(lrgPromptNorm((string) $p));
        $n = count($pt);
        if ($n > $best && $n < count($nt) && array_slice($nt, -$n) === $pt) { $best = $n; }
    }
    if ($best === 0) { return []; }
    // the subject's meaning tokens, apostrophes folded ("Ma'randru-jo" -> marandru, jo): names of >= 3 letters, and the whole
    // name joined (marandrujo) when it has more than one part
    $parts = [];
    foreach (array_slice($nt, 0, count($nt) - $best) as $w) {
        $f = str_replace("'", '', (string) $w);
        if (ctype_alpha($f) && lrgPromptWords((string) $w, true) && !in_array($f, LRG_DLG_REACH_REPORT_GENERIC, true)) { $parts[] = $f; }
    }
    $out = array_values(array_filter($parts, static fn($w) => strlen($w) >= 3));
    if (!$out) { return []; }
    if (count($parts) > 1) { $out[] = implode('', $parts); }
    return array_values(array_unique($out));
}

/**
 * [pt19h-reach / Nazir] Do his tokens (the report phrase taken out) name one of $names - a word said exactly, 1-3 of his words
 * joined into it exactly ("her n" Hern), or a BOUNDED STT echo (lrgDlgReachEcho) of >= 5 letters with the same first letter
 * ("bay tilled" Beitild, "deacons" Deekus - never "my mother" for Maluril, never "here" for Hern)?
 */
function lrgDlgReachNames(array $tok, array $names): bool
{
    for ($i = 0, $c = count($tok); $i < $c; $i++) {
        if (in_array($tok[$i], $names, true)) { return true; }
        for ($k = 2; $k <= 3 && $i + $k <= $c; $k++) {
            if (in_array(implode('', array_slice($tok, $i, $k)), $names, true)) { return true; }
        }
        for ($k = 1; $k <= 3 && $i + $k <= $c; $k++) {
            $span = implode('', array_slice($tok, $i, $k));
            if (strlen($span) < 5 || !ctype_alpha($span)) { continue; }
            foreach ($names as $nm) {
                if (strlen($nm) >= 4 && $span[0] === $nm[0] && lrgDlgReachEcho($span, $nm)) { return true; }
            }
        }
    }
    return false;
}

/**
 * [pt19h-reach review] The SHAPE of a report: the name is what it is ABOUT. The name BEFORE the report word with a copula or an
 * auxiliary between ("Narfi is dead", "Narfi has been dealt with", "the Narfi contract is done"; "Narfi won't be a problem" and
 * "Narfi is no more" carry their own verb), or a DEED done TO the name after it ("I killed Narfi", "I took care of Narfi", "I
 * finished the job on Narfi" - only the job's words between). Never the name as the doer ("Narfi killed my dog", "Narfi finished
 * his soup"), never a deed that is not the contract ("I finished talking to Narfi", "I'm done with Narfi"). A narrowing filter
 * only, after lrgDlgReachNames. $tok: his tokens; $at / $len: the report phrase's place in them.
 */
function lrgDlgReachReportShape(array $tok, int $at, int $len, string $say, array $names): bool
{
    static $cop = ['is', 'was', 'are', 'were', 'has', 'have', 'had', 'been', 'be', 'got', 'gets', 'hes', 'shes', 'its', 'theyre'];
    static $job = ['job', 'contract', 'task', 'work', 'target', 'mark', 'bounty', 'hit'];
    static $obj = ['off', 'the', 'that', 'this', 'those', 'these', 'every', 'each', 'all', 'both', 'your', 'our', 'old', 'on', 'of',
        'for', 'you', 'with'];
    static $deed = ['dealt with', 'taken care of', 'took care of', 'killed', 'handled', 'silenced', 'eliminated', 'assassinated',
        'murdered', 'slain', 'taken out', 'took out', 'disposed of', 'finished', 'done', 'complete', 'completed'];
    $fold = static fn($w): string => str_replace("'", '', (string) $w);
    $sayF = array_map($fold, lrgDlgTokens(lrgPromptNorm($say)));
    $t = array_map($fold, array_values($tok));
    $pre = array_slice($t, 0, $at);
    $ownVerb = in_array($sayF[0] ?? '', ['is', 'wont'], true);
    for ($i = 0, $c = count($pre); $i < $c; $i++) {
        foreach (lrgDlgReachNameSpans($pre, $i, $names) as $k) {
            $mid = array_slice($pre, $i + $k);
            if ($ownVerb || array_intersect($mid, $cop)) { return true; }
        }
    }
    $sayS = implode(' ', $sayF);
    if (!in_array($sayS, array_map(static fn($d) => str_replace("'", '', $d), $deed), true)) { return false; }
    $post = array_slice($t, $at + $len);
    for ($i = 0, $c = count($post); $i < $c; $i++) {
        if (!lrgDlgReachNameSpans($post, $i, $names)) { continue; }
        $mid = array_slice($post, 0, $i);
        if (array_diff($mid, array_merge($obj, $job))) { return false; }
        // a completion word says the CONTRACT is done ("I finished the job on Narfi"), never that he is done with her
        return !in_array($sayS, ['finished', 'done', 'complete', 'completed'], true) || !$mid || array_intersect($mid, $job)
            || ($sayS === 'finished' && !array_diff($mid, ['off']));
    }
    return false;
}

/** [pt19h-reach review] The spans (1-3 of his tokens from $i) that name one of $names, as lrgDlgReachNames reads a name. */
function lrgDlgReachNameSpans(array $tok, int $i, array $names): array
{
    $out = [];
    for ($k = 1, $c = count($tok); $k <= 3 && $i + $k <= $c; $k++) {
        $span = implode('', array_slice($tok, $i, $k));
        if (in_array($span, $names, true)) { $out[] = $k; continue; }
        if (strlen($span) < 5 || !ctype_alpha($span)) { continue; }
        foreach ($names as $nm) {
            if (strlen($nm) >= 4 && $span[0] === $nm[0] && lrgDlgReachEcho($span, $nm)) { $out[] = $k; break; }
        }
    }
    return $out;
}

// ================================================================== [pt19 v1.0] the words that confirm, refuse or name
/** Tokens with the apostrophe folded ("i'm" = "im", "don't" = "dont"): the owner's STT drops it (research/pt19c-language.md A1). */
function lrgDlgFoldTokens(string $text): array
{
    return array_values(array_filter(array_map(static fn($w) => str_replace("'", '', (string) $w),
        lrgDlgTokens(lrgPromptNorm($text))), 'strlen'));
}

const LRG_DLG_NEG = ['not', 'no', 'never', 'dont', 'wont', 'cant', 'cannot', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt',
    'werent', 'havent', 'hasnt', 'shouldnt', 'wouldnt', 'couldnt', 'aint', 'nope', 'neither', 'nor'];

/**
 * [pt19 v1.0 / S4.4, S4.5 step 1] A LEADING phrase of $phrases: its first 1-3 folded tokens equal one (longest wins),
 * tried as said and again past up to two leading fillers ("uh yes", "well alright" - A2). Returns
 * ['phrase' => p, 'tail' => [tokens after it]] or null. THE TAIL VETO (A3): a tail that starts with but / wait / if / not /
 * later ... or carries any negator is no assent at all ("I do not", "yeah no", "okay wait", "alright stop", "yes, if you pay
 * me") - she asks again, which only ever costs a question.
 */
function lrgDlgLeadPhrase(string $utter, array $phrases): ?array
{
    $best = lrgDlgLeadPhraseRaw($utter, $phrases);
    return ($best === null || lrgDlgLeadVetoed((array) $best['tail'])) ? null : $best;
}

/** A3: does this tail take the leading assent back? */
function lrgDlgLeadVetoed(array $tail): bool
{
    // [pt19c-A fix 1 / ai review "consent", language review 1, 9] the idioms of assent carry a negator and veto nothing
    // ("yes, no problem", "sure, why not", "not a problem"); a DEFERRAL anywhere in the tail takes the assent back ("sure,
    // after I rest", "I will do it later", "yes, first I need a drink") - a veto only ever costs one question
    $t = [];
    for ($i = 0, $n = count($tail); $i < $n; $i++) {
        $w = (string) $tail[$i];
        $nx = (string) ($tail[$i + 1] ?? '');
        if (($w === 'no' && in_array($nx, ['problem', 'worries', 'doubt'], true)) || ($w === 'why' && $nx === 'not')) { $i++; continue; }
        if ($w === 'not' && $nx === 'a' && (string) ($tail[$i + 2] ?? '') === 'problem') { $i += 2; continue; }
        $t[] = $w;
    }
    $veto = ['but', 'stop', 'wait', 'hold', 'hang', 'only', 'nothing'];
    $anywhere = ['later', 'tomorrow', 'first', 'after', 'yet', 'unless', 'if', 'though', 'although', 'someday', 'eventually'];
    if ($t && (in_array($t[0], $veto, true) || in_array($t[0], LRG_DLG_NEG, true))) { return true; }
    foreach ($t as $w) { if (in_array($w, LRG_DLG_NEG, true) || in_array($w, $anywhere, true)) { return true; } }
    return false;
}

/** The leading phrase WITHOUT the tail veto (the park uses it to tell "yeah no" from a fuller answer). */
function lrgDlgLeadPhraseRaw(string $utter, array $phrases): ?array
{
    $hay = lrgDlgFoldTokens($utter);
    if (!$hay) { return null; }
    $fill = ['uh', 'um', 'er', 'erm', 'ah', 'oh', 'hmm', 'mm', 'well', 'so', 'hey', 'and', 'then', 'look', 'listen'];
    $pts = [];
    foreach ($phrases as $p) {
        $pt = lrgDlgFoldTokens((string) $p);
        if ($pt && count($pt) <= 3) { $pts[] = [(string) $p, $pt]; }
    }
    // the same tokens UNFOLDED ("i'll" stays "i'll", whose words are the stop words i + ll): the tail's meaning words are
    // read from these, so "yes, I'll help" carries no foreign word "ill"
    $rawTok = lrgDlgTokens(lrgPromptNorm($utter));
    $aligned = count($rawTok) === count($hay);
    $best = null;
    for ($skip = 0; $skip <= 2 && $skip < count($hay); $skip++) {
        if ($skip > 0 && !in_array($hay[$skip - 1], $fill, true)) { break; }
        $h = array_slice($hay, $skip);
        $bn = 0;
        foreach ($pts as [$p, $pt]) {
            $n = count($pt);
            if ($n <= $bn || $n > count($h)) { continue; }
            if (array_slice($h, 0, $n) === $pt) {
                $bn = $n;
                $best = ['phrase' => $p, 'tail' => array_slice($h, $n),
                    'raw_tail' => $aligned ? array_slice($rawTok, $skip + $n) : array_slice($h, $n)];
            }
        }
        if ($best !== null) { break; }
    }
    return $best;
}

/** The assent list (S4.4 / S4.5), config. */
function lrgDlgAssent(string $utter): ?array
{
    return lrgDlgLeadPhrase($utter, (array) lrgDlgCfg('confirm.assent_words', []));
}

/**
 * [pt19 v1.0 / S4.5 step 0] Is the player's sentence a QUESTION? `?` at the end, or a leading question word after up to two
 * lead-ins (so / well / uh / um / and / ok / okay / hey / oh / sorry / then / but / now / right). The STT often drops the `?`,
 * so did / was / were / am and the negated auxiliaries lead a question too; will / shall / may / have / has only before a
 * pronoun or anyone / anything ("Will do." and "Have a look." stay statements - research/pt19c-language.md Q1).
 */
function lrgDlgIsQuestion(string $text): bool
{
    if (preg_match('/\?\s*["\')\]]*\s*$/', trim($text))) { return true; }
    $tok = lrgDlgTokens(lrgPromptNorm($text));
    if (lrgDlgLeadsQuestion($tok, true)) { return true; }
    // [pt19h-safety / G10] a TAG question with its `?` dropped by the STT: "I could just kill you now, couldn't I", "you're the
    // new Arch-Mage, aren't you", "that's the plan, isn't it" - a negated auxiliary and a pronoun END the sentence
    $n = count($tok);
    $negAux = ['isnt', 'arent', 'dont', 'doesnt', 'didnt', 'wont', 'cant', 'wasnt', 'werent', 'couldnt', 'wouldnt', 'shouldnt',
        'havent', 'hasnt', 'hadnt', 'aint'];
    // (it / there only after a comma: "the key wasn't there" is a statement, "there was a key, wasn't there" asks)
    $pro = preg_match('/,\s*\S+\s+\S+\W*$/u', trim($text)) ? ['i', 'you', 'we', 'he', 'she', 'they', 'it', 'there']
        : ['i', 'you', 'we', 'he', 'she', 'they'];
    return $n >= 4 && in_array(str_replace('\'', '', (string) $tok[$n - 2]), $negAux, true) && in_array((string) $tok[$n - 1], $pro, true);
}

/**
 * [pt19c-A fix 1 / language review 5] Over UNFOLDED tokens: "we are" folded from "we're" is not the question word "were",
 * and "what's" still is one. A negated auxiliary leads a question only by inversion - a pronoun after it ("don't you",
 * "can't we", "isn't it"): "Don't worry, I will handle it", "Can't wait to start", "Didn't think so" are statements. The
 * STT's dropped apostrophe ("dont you") is folded for that test only.
 */
function lrgDlgLeadsQuestion(array $tok, bool $leadIns): bool
{
    // ([pt19h-safety] + whose, whom: "whose contract was it" asks)
    $q = ['what', 'whats', 'what\'s', 'why', 'how', 'hows', 'how\'s', 'who', 'whos', 'who\'s', 'where', 'wheres', 'where\'s',
        'when', 'which', 'is', 'are', 'do', 'does', 'can', 'could', 'would', 'should', 'did', 'was', 'were', 'am', 'whose', 'whom'];
    // [pt19h-safety / G10] + havent / hasnt / hadnt / aint: "haven't you had enough of this" asks (it clicked "I've had enough of
    // this." - the fight - as his own plain sentence)
    $negAux = ['isnt', 'arent', 'dont', 'doesnt', 'didnt', 'wont', 'cant', 'wasnt', 'werent', 'couldnt', 'wouldnt', 'shouldnt',
        'havent', 'hasnt', 'hadnt', 'aint'];
    $cond = ['will', 'shall', 'may', 'have', 'has'];
    $pron = ['i', 'you', 'we', 'he', 'she', 'they', 'it', 'anyone', 'anything', 'someone', 'anybody', 'somebody', 'there', 'ya',
        'that', 'this'];
    // + wait / hold / hang / on / hmm / huh (language review 11): "wait what does that mean" with the `?` dropped still asks
    $lead = ['so', 'well', 'uh', 'um', 'and', 'ok', 'okay', 'hey', 'oh', 'sorry', 'then', 'but', 'now', 'right', 'wait', 'hold', 'hang',
        'on', 'hmm', 'huh'];
    for ($i = 0; $i <= 2 && $i < count($tok); $i++) {
        $raw = (string) $tok[$i];
        $w = str_replace('\'', '', $raw);
        $next = str_replace('\'', '', (string) ($tok[$i + 1] ?? ''));
        if (in_array($w, $negAux, true)) { return in_array($next, $pron, true); }
        if (in_array($raw, $q, true)) { return true; }
        // [pt19h-safety / G5] has / have + a name + a past participle asks too ("has Malborn got my gear"; never the STT's "has burn
        // open the door" for "Esbern? Open the door.")
        // ([pt19h-safety review] the word after it must be able to be a NAME: "has been done", "have already seen it" - the STT's clipped
        // "It has been done." - state)
        if (in_array($w, ['has', 'have'], true) && $next !== ''
            && !in_array($next, ['been', 'not', 'never', 'already', 'just', 'also', 'always', 'still', 'only'], true)
            && in_array(str_replace('\'', '', (string) ($tok[$i + 2] ?? '')),
            ['got', 'gotten', 'been', 'seen', 'heard', 'made', 'done', 'taken', 'found', 'left', 'come', 'gone', 'finished', 'brought'], true)) {
            return true;
        }
        if (in_array($w, $cond, true)) { return in_array($next, $pron, true); }
        if (!$leadIns || !in_array($w, $lead, true)) { return false; }
    }
    return false;
}

/** Is the ENTRY a question? Its text ends in `?` once trailing tags are stripped, or its norm leads with a question word. */
function lrgDlgEntryIsQuestion(array $e): bool
{
    $txt = trim((string) ($e['text'] ?? ''));
    for ($i = 0; $i < 2; $i++) { $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $txt)); }
    if (preg_match('/\?\s*["\')\]]*$/', $txt)) { return true; }
    return lrgDlgLeadsQuestion(lrgDlgTokens((string) ($e['norm'] ?? '')), false);
}

/**
 * [pt19c fixer / adversarial QA @adv_q_plain, @adv_q_single, @adv_frame] WHAT KIND OF QUESTION a sentence (or, $entry, a
 * line's text) asks: 'wh:<what|why|how|who|where|when>' (which = what, "what's" = what, "what ... for?" = why), 'yn:<subject>'
 * (a leading auxiliary - "is it", "do you", "can I" - or a declarative question with a subject: "You want my opinion?"; the
 * subject class from lrgDlgSubjectClass, 'yn' alone when none is read), 'echo' (a fragment asked back: "Contract?", "The
 * Greybeards?", and the proposals "what about / how about ..."), or '' (no question). The LAST sentence that asks decides, and
 * in his run-on sentence its last clause that leads a question.
 */
function lrgDlgQuestionKind(string $text, bool $entry = false): string
{
    if ($entry) {
        $txt = trim($text);
        for ($i = 0; $i < 2; $i++) { $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $txt)); }
        // [pt19h r2 / safety P4, P16 (c)] a leading form of address is no part of the line's question ("Kodlak, is that you?")
        if (preg_match('/^\s*[A-Z][\w\'-]*,\s+(\S.*)$/su', $txt, $vm) && strpos((string) $vm[1], '?') !== false) { $txt = trim((string) $vm[1]); }
        if (!lrgDlgEntryIsQuestion(['text' => $txt, 'norm' => lrgPromptNorm($txt)])) { return ''; }
        $text = $txt;
    } elseif (!lrgDlgIsQuestion($text)) {
        return '';
    }
    // the LAST sentence that asks ("Thank you. What's next?" asks "What's next?")
    $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [trim($text)];
    for ($p = count($parts) - 1; $p > 0; $p--) {
        if (preg_match('/\?\s*["\')\]]*\s*$/', (string) $parts[$p]) || lrgDlgLeadsQuestion(lrgDlgTokens(lrgPromptNorm((string) $parts[$p])), true)) { break; }
    }
    $text = (string) $parts[max(0, $p)];
    if (!$entry) {
        // his run-on sentence asks in its LAST clause that leads a question ("I just got into Whiterun and this is a nice inn you
        // have here, do you get many visitors?")
        $cl = preg_split('/[,;:]+/u', $text) ?: [$text];
        for ($c = count($cl) - 1; $c > 0; $c--) {
            if (lrgDlgLeadsQuestion(lrgDlgTokens(lrgPromptNorm((string) $cl[$c])), true)) { $text = (string) $cl[$c]; break; }
        }
    }
    $tok = lrgDlgTokens(lrgPromptNorm($text));
    $lead = ['so', 'well', 'uh', 'um', 'er', 'and', 'ok', 'okay', 'hey', 'oh', 'sorry', 'then', 'but', 'now', 'right', 'wait', 'hold',
        'hang', 'on', 'hmm', 'huh'];
    // "what ... for?" asks WHY ("what do you need a poem for?" is no "What do you need me to do?")
    $last = str_replace('\'', '', (string) (array_slice($tok, -1)[0] ?? ''));
    $wh = ['what' => 'what', 'whats' => 'what', 'which' => 'what', 'why' => 'why', 'how' => 'how', 'hows' => 'how', 'who' => 'who',
        'whos' => 'who', 'whom' => 'who', 'whose' => 'who', 'where' => 'where', 'wheres' => 'where', 'when' => 'when'];
    $auxQ = ['is', 'are', 'am', 'was', 'were', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'shall', 'may',
        'might', 'have', 'has', 'isnt', 'arent', 'dont', 'doesnt', 'didnt', 'wont', 'cant', 'wasnt', 'werent', 'couldnt', 'wouldnt',
        'shouldnt'];
    $pron = ['i', 'im', 'you', 'youre', 'we', 'he', 'she', 'they', 'it', 'its', 'that', 'thats', 'this', 'there', 'theres'];
    for ($i = 0; $i < count($tok) && $i <= 2; $i++) {
        $w = str_replace('\'', '', (string) $tok[$i]);
        $nx = str_replace('\'', '', (string) ($tok[$i + 1] ?? ''));
        if (isset($wh[$w])) {
            if ($nx === 'about' && in_array($w, ['what', 'how'], true)) { return 'echo'; }
            return ($wh[$w] === 'what' && $last === 'for' && count($tok) - $i > 3) ? 'wh:why' : 'wh:' . $wh[$w];
        }
        // a yes/no question carries its SUBJECT ("do YOU know any old ballads" is not "do DRAGONS sing ballads"): yn:<class>
        // [pt19h-safety / G9] read past a determiner: "is THE AUGUR dangerous" has the subject augur, not an unread one that
        // agreed with every yes/no line ("Have you ever heard of the Augur of Dunlain?")
        if (in_array($w, $auxQ, true)) {
            $j = $i + 1;
            while (in_array((string) ($tok[$j] ?? ''), ['the', 'a', 'an', 'my', 'your', 'these', 'those', 'his', 'her', 'our', 'their'], true)
                && isset($tok[$j + 1])) { $j++; }
            return 'yn' . lrgDlgSubjectClass((string) ($tok[$j] ?? ''));
        }
        if (in_array($w, $pron, true)) { return 'yn' . lrgDlgSubjectClass($w); }
        if (!in_array($w, $lead, true)) { break; }
    }
    return 'echo';
}

/** [pt19c fixer] The subject class of a yes/no question: ':you' ':i' ':we' ':it' ':there' ':<noun>', '' when none is read. */
function lrgDlgSubjectClass(string $w): string
{
    $w = str_replace('\'', '', strtolower($w));
    if ($w === '') { return ''; }
    $map = ['you' => 'you', 'ya' => 'you', 'ye' => 'you', 'youre' => 'you', 'yall' => 'you', 'i' => 'i', 'im' => 'i', 'ive' => 'i',
        'id' => 'i', 'ill' => 'i', 'me' => 'i', 'we' => 'we', 'were' => 'we', 'weve' => 'we', 'us' => 'we', 'it' => 'it', 'its' => 'it',
        'that' => 'it', 'thats' => 'it', 'this' => 'it', 'there' => 'there', 'theres' => 'there', 'he' => 'he', 'she' => 'he',
        'they' => 'they', 'anyone' => 'any', 'anybody' => 'any', 'someone' => 'any', 'somebody' => 'any', 'anything' => 'any',
        'something' => 'any', 'the' => '', 'a' => '', 'an' => '', 'my' => '', 'your' => '', 'these' => '', 'those' => ''];
    return array_key_exists($w, $map) ? ($map[$w] === '' ? '' : ':' . $map[$w]) : ':' . $w;
}

/**
 * [pt19c fixer] A REQUEST shaped as a question is his line said politely: "can I rent a room?", "could I get a room", "could you
 * spare some supplies?", "would you tell me about Whiterun?" - can / could / may / might + I / we, or can / could / would / will
 * + you. Never with a word of doubt after the subject ("can we even kill a dragon?", "could I really do that?"): that one asks.
 */
function lrgDlgIsRequest(string $text): bool
{
    $tok = array_map(static fn($w) => str_replace('\'', '', (string) $w), lrgDlgTokens(lrgPromptNorm($text)));
    $lead = ['so', 'well', 'uh', 'um', 'er', 'and', 'ok', 'okay', 'hey', 'oh', 'sorry', 'then', 'but', 'now', 'excuse', 'me', 'please'];
    $i = 0;
    while ($i < count($tok) && $i < 3 && in_array($tok[$i], $lead, true)) { $i++; }
    $aux = (string) ($tok[$i] ?? '');
    $sub = (string) ($tok[$i + 1] ?? '');
    $doubt = ['even', 'really', 'ever', 'actually', 'seriously', 'truly', 'possibly', 'honestly'];
    if (in_array((string) ($tok[$i + 2] ?? ''), $doubt, true)) { return false; }
    return (in_array($aux, ['can', 'could', 'may', 'might'], true) && in_array($sub, ['i', 'we'], true))
        || (in_array($aux, ['can', 'could', 'would', 'will'], true) && in_array($sub, ['you', 'ya'], true));
}

/**
 * [pt19c fixer] Do two QUESTIONS ask the same thing, by their shape? Same kind; a fragment asked back ('echo': "Contract?",
 * "What about my reward?") agrees with anything. Two questions of DIFFERENT words (another question word, or yes/no questions
 * about another subject - "do dragons sing ballads?" / "Do you know any old ballads about dragons?") agree only when he says
 * nothing of his own (lrgDlgOwnWords: "why do the Greybeards want me" = "What do these Greybeards want with me?"; never "who
 * gives you your orders?" / "What are your orders?", "what do you need a poem for?" / "What do you need me to do?"). A
 * question word against a yes/no question disagrees ("what does a bard do?" / "Does that mean I'm a bard now?", "do you
 * mind?" / "What do you have in mind?", "what old stone?" / "Oh, do you mean this old stone?") unless the yes/no side carries
 * that very question word ("so where do I come in" / "And that's where I come in?"), asks for ANY- / SOME- thing ("what else
 * can I help with" / "Is there anything else I can help with?"), or is the wh-line with its first word clipped by the STT
 * ("do you have in mind"). True when either side is no question (the shape rule judges that).
 */
function lrgDlgQuestionsAgree(string $utter, array $e): bool
{
    $ku = lrgDlgQuestionKind($utter);
    $ke = lrgDlgQuestionKind((string) ($e['text'] ?? ''), true);
    if ($ku === '' || $ke === '' || $ku === $ke || $ku === 'echo' || $ke === 'echo') { return true; }
    $ynU = strncmp($ku, 'yn', 2) === 0;
    $ynE = strncmp($ke, 'yn', 2) === 0;
    if ($ynU === $ynE) {
        // the same shape, other words: a subject not read agrees; otherwise only with no word of his own
        if ($ynU && ($ku === 'yn' || $ke === 'yn')) { return true; }
        return !lrgDlgOwnWords($utter, $e);
    }
    $en = (string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? '')));
    $ut = lrgDlgTokens(lrgPromptNorm($utter));
    $etk = lrgDlgTokens($en);
    // the STT clipped the question word: "do you have in mind" IS "What do you have in mind?" without its first word (G3)
    if ($ynU && count($ut) >= 3 && lrgDlgTokenRun(array_slice($etk, 1), $ut)) { return true; }
    [$wh, $ynTok] = $ynU ? [substr($ke, 3), $ut] : [substr($ku, 3), $etk];
    $ynTok = array_map(static fn($w) => str_replace('\'', '', (string) $w), $ynTok);
    $forms = ['what' => ['what', 'whats', 'which'], 'why' => ['why'], 'how' => ['how', 'hows'], 'who' => ['who', 'whos', 'whom'],
        'where' => ['where', 'wheres'], 'when' => ['when']][$wh] ?? [$wh];
    if (array_intersect($ynTok, $forms)) { return true; }
    $any = ['anything', 'something', 'any', 'anyone', 'someone', 'anybody', 'somebody', 'anywhere', 'somewhere'];
    return $wh === 'what' && (bool) array_intersect($ynTok, $any);
}

/**
 * [pt19c fixer / adversarial QA FRICTION, @adv_sibling] HIS WORDS WITHOUT THE VOCATIVE: "what else can I help you with, my
 * jarl", "I have your shield, Aela", "my lord, may I have a word", "else can i help you with sir". A form of address is no
 * content of his sentence - it costs no precision where his words must carry a line, and it never picks a line on its own.
 * Only in the positions of an address (leading with a comma, trailing, or "my <title>"), so "I need to speak to the jarl"
 * keeps its jarl. The NPC's own first name counts as one.
 */
function lrgDlgStripVocative(string $utter, string $npc = '', string $keep = ''): string
{
    if ($keep !== '') {
        // a word the LINE itself carries is no mere address here ("the Dark Brotherhood has come, Grelod")
        $s0 = lrgDlgStripVocative($utter, $npc);
        $gone = array_diff(lrgDlgTokens(lrgPromptNorm($utter)), lrgDlgTokens(lrgPromptNorm($s0)));
        return array_intersect($gone, lrgDlgTokens($keep)) ? $utter : $s0;
    }
    // [pt19c final fixer] + the ranks lrgDlgNameWord no longer returns ("General, ..." stays an address)
    $titles = 'jarl|sir|lord|lady|madam|ma\'?am|thane|housecarl|friend|stranger|traveller|traveler|boy|girl|lad|lass|kid|child|mister'
        . '|general|captain|commander|legate|priest|priestess';
    $name = ($npc !== '' && function_exists('lrgDlgNameWord')) ? preg_quote((string) lrgDlgNameWord($npc), '/') : '';
    $voc = '(?:my\s+(?:' . $titles . ')|(?:' . $titles . ')' . ($name !== '' ? '|' . $name : '') . ')';
    $s = trim($utter);
    // "my jarl" / "my lord" wherever it stands, as an aside
    $s = (string) preg_replace('/(^|[,;.!?]\s*|\s)my\s+(?:jarl|lord|lady|thane)\b\s*[,.!]?/iu', '$1', $s);
    // a leading address: "Jarl, ...", "Aela, ...", "Hey friend, ..." (the comma is its mark)
    $s = (string) preg_replace('/^\s*(?:(?:uh|um|well|so|hey|oh|excuse me)\s*,?\s*)?' . $voc . '\s*[,!]\s*/iu', '', $s);
    // a trailing address: ", my jarl" / ", Aela" (the comma is its mark), or a bare "sir" / "ma'am" / "lad" at the very end
    // ("... can i help you with sir" - the owner's STT drops the comma; never after "the" / "a")
    $s = (string) preg_replace('/\s*,\s*' . $voc . '\s*[.!?]*\s*$/iu', '', $s);
    $s = (string) preg_replace('/(?<!\bthe)(?<!\ban)(?<!\ba)\s+(?:sir|madam|ma\'?am|lad|lass|kid)\s*[.!?]*\s*$/iu', '', $s);
    $s = trim((string) preg_replace('/\s*,\s*$/', '', $s));
    return $s !== '' ? $s : $utter;
}

/**
 * [pt19c fixer / adversarial QA @adv_bargain] A BARGAIN or a CONDITION in his words on a line that names no price: a named sum
 * ("I'll join the Legion if you pay me five hundred septims", "I'll kill the ice wraith for fifty septims", "I have your shield,
 * that'll be twenty septims"), a money word beside a price word, a reward-bargain phrase (checks.stakes.reward), or - on any
 * line - a condition on a payment or a share ("... if you pay me", "... unless I get a cut"; a courtesy condition "if you'll
 * have me" is none). S6.1: the reward is fixed and nothing may be promised - so his sentence is not the plain line, and the
 * line is never clicked on it (it asks).
 */
function lrgDlgBargains(string $utter, array $e = []): bool
{
    if (trim($utter) === '') { return false; }
    $priced = (int) ($e['cost'] ?? 0) > 0 || (string) ($e['kind'] ?? '') === 'bribe' || (string) ($e['class'] ?? '') === 'pay';
    if (!$priced) {
        $u = function_exists('lrgDlgMoneyFold') ? lrgDlgMoneyFold($utter) : $utter;
        if (lrgDlgNamedAmount($u) > 0) { return true; }
        if (preg_match('/\b(septims?|gold|coins?)\b/i', $u) && preg_match('/\b(pay|paid|for|give|cost|costs|price|worth|be)\b/i', $u)) { return true; }
        // (a reward phrase on a line that IS the reward ask - "What about my reward?", "I think I deserve a reward" - says the line)
        $rcfg = (array) lrgDlgCfg('checks.stakes.reward', []);
        if (function_exists('lrgDlgRewardPhrase') && lrgDlgRewardPhrase($u, $rcfg) !== ''
            && !preg_match('/\b(reward|pay|paid|payment|gold|septims?)\b/i', (string) ($e['text'] ?? ''))) { return true; }
    }
    // a condition on a payment or a share ("... if you pay me", "... unless I get a cut", "... provided you give me the
    // reward"); a courtesy condition ("if you'll have me", "if I may") is none
    $tok = lrgDlgFoldTokens($utter);
    $pay = ['pay', 'pays', 'paid', 'payment', 'reward', 'gold', 'septim', 'septims', 'coin', 'coins', 'money', 'price', 'fee', 'cut',
        'share', 'give', 'gives', 'bonus'];
    foreach ($tok as $i => $w) {
        if ($i < 1 || !in_array($w, ['if', 'unless', 'provided', 'providing', 'long'], true)) { continue; }
        if ($w === 'long' && !((string) ($tok[$i - 1] ?? '') === 'as' && (string) ($tok[$i + 1] ?? '') === 'as')) { continue; }
        if (array_intersect(array_slice($tok, $i + 1, 8), $pay)) { return true; }
    }
    return false;
}

/**
 * [pt19c fixer / adversarial QA @adv_q_plain, hidden-by-merge] HIS ANSWER TO THE LINE'S OWN QUESTION is not the line: a yes/no
 * question line that asks HIM ("Are you all right?", "Do you know ...?") against his first-person statement of the same words
 * ("I'm all right", "I know ..."). The fast path never picks it (the model may).
 */
function lrgDlgMirrorsQuestion(string $utter, array $e): bool
{
    if (lrgDlgIsQuestion($utter) || strncmp(lrgDlgQuestionKind((string) ($e['text'] ?? ''), true), 'yn', 2) !== 0) { return false; }
    $et = lrgDlgFoldTokens((string) ($e['text'] ?? ''));
    $ut = lrgDlgFoldTokens($utter);
    $lead = ['so', 'well', 'uh', 'um', 'er', 'oh', 'yes', 'yeah', 'no', 'ok', 'okay', 'hey'];
    while ($ut && in_array($ut[0], $lead, true)) { array_shift($ut); }
    return count($et) >= 2 && in_array($et[1], ['you', 'ya', 'ye'], true) && $ut && in_array($ut[0], ['i', 'im', 'ive', 'id', 'ill'], true);
}

/**
 * [pt19c fixer] His words without the leading fillers (G7: uh um er erm ah oh hmm mm well so hey) and without the vocative -
 * what the explicit test and the single-entry release compare ("well, I need to talk to you", "hey, who are you?").
 */
function lrgDlgBareWords(string $utter, string $npc = '', string $keep = ''): string
{
    $s = (string) preg_replace('/^\s*(?:(?:uh|um|er|erm|ah|oh|hmm|mm|well|so|hey)\b[\s,.!-]*){1,3}/iu', '', trim($utter));
    // (a filler the line itself opens with stays: "Oh, do you mean this old stone?", "oh laugh found him asleep" for Olaf)
    if ($keep !== '' && lrgDlgTokens(lrgPromptNorm($s)) && array_intersect(array_slice(lrgDlgTokens(lrgPromptNorm($utter)), 0, 3), array_slice(lrgDlgTokens($keep), 0, 1))) {
        $s = $utter;
    }
    $s = lrgDlgStripVocative(trim($s) !== '' ? $s : $utter, $npc, $keep);
    return trim($s) !== '' ? $s : $utter;
}


/**
 * [pt19h r2] THE QUESTION PART of a text: for a LINE ($entry), its last sentence that asks (tags dropped; the whole text when none asks);
 * for HIS words, the last clause when his sentence ends in a `?` and has several ("nice place, get a lot of visitors?" asks "get a lot
 * of visitors?"), else the whole sentence.
 */
function lrgDlgQuestionPart(string $text, bool $entry): string
{
    $t = trim($text);
    if ($entry) {
        for ($i = 0; $i < 2; $i++) { $t = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $t)); }
        $qs = array_values(array_filter(preg_split('/(?<=[.!?])\s+/u', $t) ?: [], static fn($s) => strpos((string) $s, '?') !== false));
        return $qs ? (string) end($qs) : $t;
    }
    if (!preg_match('/\?\s*["\')\]]*$/', $t)) { return $t; }
    $cls = array_values(array_filter(array_map('trim', preg_split('/[,;:]+/u', $t) ?: []), 'strlen'));
    return count($cls) >= 2 ? (string) end($cls) : $t;
}

/**
 * [pt19h r2] Where the line's token run sits in his RAW words - [byte offset, length] of the occurrence that starts at his token $at
 * (punctuation between the words is tolerated), or null.
 */
function lrgDlgRawRun(string $raw, array $tokens, int $at): ?array
{
    if (!$tokens) { return null; }
    $parts = [];
    foreach ($tokens as $w) { $parts[] = str_replace('\#', '\d[\d,.]*', preg_quote((string) $w, '/')); }
    $re = "/(?<![a-z0-9'])" . implode("[^a-z0-9']+", $parts) . "(?![a-z0-9'])/iu";
    if (!@preg_match_all($re, $raw, $mm, PREG_OFFSET_CAPTURE) || !$mm[0]) { return null; }
    foreach ($mm[0] as $m) {
        $off = (int) $m[1];
        if (count(lrgDlgTokens(lrgPromptNorm(substr($raw, 0, $off)))) === $at) { return [$off, strlen((string) $m[0])]; }
    }
    $m = $mm[0][count($mm[0]) - 1];
    return [(int) $m[1], strlen((string) $m[0])];
}

/**
 * [pt19h r2 / safety P18] A deferral word AFTER the line: "first" only as "but first" / "first I ..." / ending a later clause - never
 * "show me the first one", never a bare "... first" right after the line (it puts the line first, it does not put it off).
 */
function lrgDlgDeferAfter(array $after, array $defer): bool
{
    foreach ($after as $k => $w) {
        if (!in_array($w, $defer, true)) { continue; }
        if ($w === 'first') {
            $nx = (string) ($after[$k + 1] ?? '');
            $pv = (string) ($after[$k - 1] ?? '');
            if (!(in_array($nx, ['i', 'we', 'let', 'ill', 'well', 'im', 'ive', 'lets', 'id'], true) || $pv === 'but' || ($nx === '' && $k > 0))) { continue; }
        }
        return true;
    }
    return false;
}

/**
 * [pt19h r2 / grading P1, P12 - THE KEY-MODE RAIL] Why the model's T-key may not click a PLAIN line that still runs a script (scripted,
 * an Invisible Continue - a converging sibling among them) on his words this turn, or '': his words around the line refuse / ask /
 * hedge (lrgDlgQuoteQualm), refuse it, put it off, hedge it, negate it, keep what it hands over, or ask what the line does not.
 * What lrgDlgWordsQualm does for the fast path, on the key path; a plain unscripted line keeps the model's key as before.
 */
function lrgDlgKeyRailWhy(string $utter, array $e): string
{
    // (a CHECK line has its own rails - lrgDlgCheckRails, the G13 ask rail - and a persuasion is often said in the negative; a SERVICE line
    // is asked for with a question - "do you have a room free" - and the kind guards judge it: neither is this rail's)
    if ((string) ($e['kind'] ?? '') !== '' || in_array((string) ($e['class'] ?? ''), ['service', 'pay'], true)) { return ''; }
    $eTx = (string) ($e['text'] ?? '');
    $qq = lrgDlgQuoteQualm($utter, $e);
    if ($qq['qualm'] !== '') { return 'his words around the line ' . $qq['qualm']; }
    // (the line said whole, or its own fragment - "was told to come see you" for "I was told to come see you." - is the line, however it leads)
    if (in_array($qq['kind'], ['whole', 'frag'], true)) { return ''; }
    if (lrgDlgRefuses($utter) && !lrgDlgIsBackOut($eTx, true)) { return 'his words refuse'; }
    if (lrgDlgDefers($utter, $e)) { return 'a deferral'; }
    if (lrgDlgHedges($utter) && !lrgDlgHedges($eTx)) { return 'a hedge'; }
    if (lrgDlgNegationClash($utter, $e)) { return 'his words say the opposite'; }
    if (lrgDlgIsQuestion($utter) && !lrgDlgEntryIsQuestion($e) && !(lrgDlgIsRequest($utter) && lrgDlgRequestFits($utter, $e))) {
        return 'a question to her against a line that asks nothing';
    }
    if ((string) ($e['hand'] ?? '') !== '' && lrgDlgKeepsIt($utter)) { return 'he keeps what the line hands over'; }
    return '';
}

/**
 * [pt19c fixer / adversarial QA: @adv_q_plain, @adv_neg_plain, @adv_sibling, @adv_bargain, the hidden merge pair] WHY HIS WORDS
 * ARE NOT THIS LINE, for a pick the similarity matcher made with no model in between (the fast path's intent pick, the gate's
 * words item) - '' when nothing speaks against it. In order: his words refuse (a line that is itself refusal-shaped excepted);
 * a QUESTION to her against a line that is no question ("is the dragon dead?" is no "The dragon is dead.", "is the room
 * expensive?" rents nothing - a REQUEST shaped as a question excepted: "can I rent a room?"); a question of another kind or
 * about another subject (lrgDlgQuestionsAgree: "who gives you your orders?" / "What are your orders?", "do dragons sing
 * ballads?" / "Do you know any old ballads about dragons?"); his ANSWER to the line's own question ("I'm all right" / "Are
 * you all right?"); a bargain or a
 * condition (S6.1); a word of his that only ANOTHER line of the list carries while this one lacks it ("here's the tablet from
 * Bleak Falls Barrow" is not "Anything you can tell me about Bleak Falls Barrow?" beside "I have the stone tablet"). Scores
 * untouched: the model's key still decides these lines.
 */
function lrgDlgWordsQualm(string $words, array $e, array $pool, string $npc = '', ?array $m = null): string
{
    if (trim($words) === '') { return ''; }
    $bare = lrgDlgBareWords($words, $npc);
    // [pt19h r2 / safety P4] the line said word for word - his form of address included ("Kodlak, is that you?") - is never refused
    // (... a statement line asked back - "I did what had to be done. Nothing more?" - is the echo it always was)
    if ((lrgPromptNorm($words) === (string) ($e['norm'] ?? '') || lrgPromptNorm($bare) === (string) ($e['norm'] ?? ''))
        && !(preg_match('/\?\s*["\')\]]*$/', trim($words)) && !lrgDlgEntryIsQuestion($e))) {
        return lrgDlgBargains($words, $e) ? 'a bargain or a condition, not the line' : '';
    }
    // [pt19h r2 / G16, grading P4] he KEEPS what a hand-over line gives away ("I'm keeping the amulet" against "I found this amulet. (Give
    // Delvin the amulet)"): never that line
    if (lrgDlgKeepsIt($words) && ((string) ($e['hand'] ?? '') !== ''
        || preg_match('/[\(\[]\s*(?:give|show|hand over|hand|present|offer|return)\b/i', (string) ($e['text'] ?? '')))) {
        return 'he keeps what the line hands over';
    }
    if (lrgDlgRefuses($words) && !lrgDlgIsBackOut((string) ($e['text'] ?? ''), true)) { return 'his words refuse'; }
    // [pt19h-safety / G1, G11, G5, G9] what he said AROUND the line ("is it true that <line>", "<line>? says who", "not now, <line>
    // later"), a hedge on a protected line, and a goodbye of his ("sorry, I have to go" is no "Sorry. So, the Staff of Magnus?")
    $prot = lrgDlgProtected($e);
    $eTx = (string) ($e['text'] ?? '');
    $qq = lrgDlgQuoteQualm($words, $e);
    if (in_array($qq['qualm'], ['refuses', 'asks'], true) || ($qq['qualm'] === 'hedges' && $prot)) { return 'his words around the line ' . $qq['qualm']; }
    // [pt19h r2 / safety P20] ... unless it is an assent phrase of the config said whole ("I guess so", "I suppose so"): one rule on every path
    $assentWhole = in_array(lrgPromptNorm($words), array_map(static fn($a) => lrgPromptNorm((string) $a), (array) lrgDlgCfg('confirm.assent_words', [])), true);
    if ($prot && !$assentWhole && lrgDlgHedges($words) && !lrgDlgHedges($eTx)) { return 'a hedge is no yes to a line that commits'; }
    if ($prot && lrgDlgDefers($words, $e)) { return 'a deferral is no line said now'; }
    // (a goodbye CLAUSE of his - short, and none of its words the line's: "sorry, I have to go"; never "we'll see you soon, Ulfric" /
    // "We'll be seeing you soon.", "Yamarz says I should go to Malacath", "i want to bye back the elder scroll")
    if (!lrgDlgLeaveWords($eTx) && !lrgDlgRealBackOut($e)) {
        $weL = lrgPromptWords((string) ($e['norm'] ?? ''), true);
        foreach (preg_split('/[,;:.!?]+/u', $words) ?: [] as $cl) {
            $ct = lrgDlgTokens(lrgPromptNorm((string) $cl));
            if ($ct && count($ct) <= 5 && lrgDlgLeaveWords((string) $cl)
                && !array_filter(lrgPromptWords(lrgPromptNorm((string) $cl), true), static fn($w) => lrgDlgStemIn((string) $w, $weL))) {
                return 'he is leaving, not saying this line';
            }
        }
    }
    $uq = lrgDlgIsQuestion($words);
    // [pt19h-safety / G5] a request stands for the line only when it asks for what the line says: "will you be right back" /
    // "can you help me?" ask HER, "I'll be right back." / "I'm here to help." are his own; "can I kill the escaped prisoner?" asks
    // leave, "I can kill the escaped prisoner." offers (lrgDlgRequestFits)
    if ($uq && !lrgDlgEntryIsQuestion($e) && (!lrgDlgIsRequest($words) || !lrgDlgRequestFits($words, $e))) {
        return 'a question to her is not this line (it is no question)';
    }
    if ($uq && !lrgDlgQuestionsAgree($bare, $e)) { return 'he asks another question than this line'; }
    // [pt19h-safety / G9] a protected line takes only the SAME question
    if ($uq && $prot && lrgDlgEntryIsQuestion($e) && !lrgDlgQuestionsSame($bare, $e)) { return 'he asks another question than this line'; }
    // ... and on any line two question WORDS ask two things ("who wrote the first verse" / "We can do this. What's the first verse?";
    // what / why and how / what as lrgDlgQuestionsSame allows)
    if ($uq && !$prot && lrgDlgEntryIsQuestion($e)) {
        $kuW = lrgDlgQuestionKind($bare);
        $keW = lrgDlgQuestionKind($eTx, true);
        if (strncmp($kuW, 'wh:', 3) === 0 && strncmp($keW, 'wh:', 3) === 0 && $kuW !== $keW && !lrgDlgQuestionsSame($bare, $e)) {
            return 'he asks another question than this line';
        }
    }
    if (lrgDlgMirrorsQuestion($bare, $e)) { return 'he answers the question this line asks'; }
    // [pt19h-safety / G9] a statement of his against a protected QUESTION line ("the situation is bad" / "What's the situation?",
    // "I know what happened" / "What happened?", "we surrender" / "Do you surrender?") and a near-miss on a protected line ("close
    // the door" / "Open the door.", "I've finished that special Solitude job" / "... Markarth job.")
    if (lrgDlgDeclares($words, $e)) { return 'a statement of his is no question line'; }
    if ($prot && $qq['kind'] !== 'whole' && lrgDlgSubstitutes($bare, $e)) { return 'a near-miss: a word of his where the line has another'; }
    if (lrgDlgBargains($words, $e)) { return 'a bargain or a condition, not the line'; }
    if ($m === null || !in_array((string) ($m['tier'] ?? ''), ['exact', 'contain'], true) || !empty($m['short'])) {
        static $frame = ['tell', 'about', 'know', 'think', 'like', 'want', 'need', 'got', 'get', 'see', 'say', 'ask', 'talk', 'hear',
            'heard', 'any', 'anything', 'something', 'going', 'go', 'have', 'give', 'take', 'come', 'make'];
        $we = lrgPromptWords((string) ($e['norm'] ?? ''), true);
        $mine = array_diff(lrgPromptWords(lrgPromptNorm($bare), true), $we, $frame);
        if ($mine) {
            foreach ($pool as $o) {
                if ((string) ($o['class'] ?? '') === 'hidden' || ((int) ($o['pos'] ?? -1) === (int) ($e['pos'] ?? -2)
                    && (string) ($o['norm'] ?? '') === (string) ($e['norm'] ?? ''))) { continue; }
                if (array_intersect($mine, lrgPromptWords((string) ($o['norm'] ?? ''), true))) {
                    return 'a word of his belongs to another line ("' . substr((string) ($o['text'] ?? ''), 0, 40) . '")';
                }
            }
        }
    }
    return '';
}

/**
 * [pt19c fixer] HIS OWN meaning words against a line: his strict words the line does not carry, less the fillers of speech
 * (am, just, only, fine, okay, from, back ...) and less an STT ECHO of a token the line has and he did not say ("lauren" for
 * learn, "born" for horn, "a long" for along, "grey lod" for Grelod, "the sauce" for this - lrgDlgSoundsLike, alone or joined
 * with a neighbour).
 */
function lrgDlgOwnWords(string $words, array $e): array
{
    static $fill = ['am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'will', 'shall', 'from', 'at', 'by', 'into', 'onto', 'up',
        'out', 'over', 'off', 'down', 'back', 'just', 'only', 'really', 'very', 'too', 'also', 'please', 'yeah', 'yep', 'okay', 'ok',
        'fine', 'alright', 'well', 'oh', 'ah', 'ugh', 'look', 'listen', 'sure', 'right', 'already', 'still', 'even', 'all', 'some',
        'any', 'guess', 'think', 'suppose', 'presume', 'reckon', 'imagine', 'assume', 'bet', 'go', 'going', 'got', 'get', 'um', 'er',
        'hmm', 'hey', 'exactly', 'perhaps', 'maybe', 'possibly', 'actually', 'done', 'say', 'said', 'thanks', 'thank', 'after'];
    $en = (string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? '')));
    $un = lrgPromptNorm($words);
    $own = array_values(array_diff(lrgPromptWords($un, true), lrgPromptWords($en, true), $fill));
    // on a PRICED line (a bribe, a paid service) the sum he names is the line's own price, no word of his own
    if ($own && ((int) ($e['cost'] ?? 0) > 0 || (string) ($e['kind'] ?? '') === 'bribe' || (string) ($e['class'] ?? '') === 'pay')) {
        $own = array_values(array_filter($own, static fn($w) => !preg_match('/^(#|\d+|gold|septims?|coins?|money|' . (defined('LRG_MONEY_NUMWORD')
            ? LRG_MONEY_NUMWORD : 'one|two|three|four|five|six|seven|eight|nine|ten|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand') . ')$/', (string) $w)));
    }
    if (!$own) { return []; }
    $ut = lrgDlgTokens($un);
    $missing = array_values(array_diff(lrgDlgTokens($en), $ut));
    if (!$missing) { return $own; }
    $out = [];
    foreach ($own as $w) {
        $echo = false;
        $i = array_search($w, $ut, true);
        $cands = [$w];
        if ($i !== false) {
            if ($i > 0) { $cands[] = $ut[$i - 1] . $w; }
            if (isset($ut[$i + 1])) { $cands[] = $w . $ut[$i + 1]; }
        }
        foreach ($missing as $mw) {
            foreach ($cands as $c) { if (lrgDlgSoundsLike((string) $c, (string) $mw)) { $echo = true; break 2; } }
        }
        if (!$echo) { $out[] = $w; }
    }
    return $out;
}

/** [pt19c fixer] Two words an STT could swap: equal once apostrophes go, within a third of their letters, or one metaphone step. */
function lrgDlgSoundsLike(string $a, string $b): bool
{
    $a = str_replace("'", '', strtolower($a));
    $b = str_replace("'", '', strtolower($b));
    if ($a === '' || $b === '') { return false; }
    if ($a === $b) { return true; }
    // [pt19h r2 / safety P7] the question words as the STT writes them ("hoo are you")
    static $stt = ['hoo' => 'who', 'wat' => 'what', 'ware' => 'where', 'wen' => 'when', 'wich' => 'which'];
    if (($stt[$a] ?? '') === $b || ($stt[$b] ?? '') === $a) { return true; }
    $l = max(strlen($a), strlen($b));
    if ($l >= 4 && levenshtein($a, $b) <= max(1, intdiv($l, 3))) { return true; }
    // ("den" / "then": the metaphone of th is 0, a t to the ear)
    $ma = str_replace('0', 'T', ltrim(metaphone($a), 'AEIOU'));
    $mb = str_replace('0', 'T', ltrim(metaphone($b), 'AEIOU'));
    if ($ma === '' || $mb === '') { return false; }
    if ($ma === $mb) { return true; }
    if (levenshtein($ma, $mb) <= 1 && $ma[0] === $mb[0]) { return true; }
    // a long NAME the STT mangled ("your gun" for Jurgen): half its sound kept
    if (min(strlen($a), strlen($b)) >= 5 && min(strlen($ma), strlen($mb)) >= 3) {
        similar_text($ma, $mb, $pct);
        return $pct >= 50.0;
    }
    return false;
}

/** [S4.5] refusal first: the widened back-out (spoken) - a leading no / nope / stop / never is part of it. */
function lrgDlgRefuses(string $utter): bool
{
    return trim($utter) !== '' && lrgDlgIsBackOut($utter, true);
}

/** [pt19h-safety / G1] A DEFERRAL in words: not now / not yet / later / another time / tomorrow ("not this time" declines, it defers nothing). */
const LRG_DLG_DEFER_RE = '/\b(not (yet|now|right now)|maybe later|later|another time|some other time|some other day|another day|next time|tomorrow|someday|eventually)\b/';

/**
 * [pt19h-safety / G1] A HEDGE of his LEADING his words (after fillers): "maybe", "perhaps", "I guess", "I suppose", "probably",
 * "not sure", "dunno", "I don't know", "if I must". A hedge is no yes: on a protected line (lrgDlgProtected) it never clicks.
 */
function lrgDlgHedges(string $text): bool
{
    // (maybe / perhaps hedge HIS doing - "maybe I'll help", "perhaps later", a bare "maybe" - never a proposal: "maybe some septims
    // will change your mind" offers the bribe)
    $t = trim($text);
    // ([pt19h-safety review] + "maybe yes", "probably yeah": the hedge still leads)
    if (preg_match('/^\W*((uh|um|er|erm|well|oh|so|hmm|hey|and|then)\W+){0,2}(maybe|perhaps|possibly|probably)(\W*$|\W+(i|i\'?ll|i\'?m|'
        . 'i\'?d|i will|i would|we|we\'?ll|later|not|so|someday|another|one day|if|yes|yeah|yep)\b)/i', $t)) { return true; }
    // [pt19h-safety review] ... and a hedge right after his ASSENT word is still a hedge: "yeah maybe I'm ready to return to
    // Tamriel", "sure, I guess I'm here to join the Dawnguard", "yes, probably" clicked the commit (explicit) - "I guess" / "I
    // suppose" / "I might" only when they hedge HIS act (the end, I / we ...): "okay i guess you're the guy i talked to" names who
    // she is and still acts, and "yeah, I guess so" is the config's own assent "i guess so" (confirm.assent_words). A trailing
    // "... or not" / "maybe not" / "... not" takes the line back ("I'm ready to return to Tamriel... or not", "I'm sure... not").
    if (preg_match('/^\W*((uh|um|er|erm|well|oh|so|hmm|hey|and|then)\W+){0,2}(yes|yeah|yep|yup|yah|sure|ok|okay|alright|all right|fine)\W+'
        . '((uh|um|er|well|so|hmm|then)\W+)?((maybe|perhaps|possibly|probably)(\W*$|\W+(i|i\'?ll|i\'?m|i\'?d|i will|i would|we|we\'?ll|'
        . 'later|not|so|someday|another|one day|if)\b)|(i guess|i suppose|i might)(\W*$|\W+(i|i\'?ll|i\'?m|i\'?d|i\'?ve|we|we\'?ll|'
        . 'we\'?re)\b))/i', $t)
        || (preg_match('/[.!\x{2026}]\s*(or not|or maybe not|maybe not|not)\W*$/iu', $t) && !lrgDlgIsQuestion($t))) {
        // (only after a sentence break: "dragon or not", "you know dragonrend or not", "Mercer killed Gallus, not" are the line's own
        // words; a QUESTION that ends "or not" is his impatience - "so can i join the legion or not" still asks to join)
        return true;
    }
    // [pt19h r2 / safety P20] "I guess" / "I suppose" / "I might" hedge HIS OWN doing (bare, or before I / I'll / we / not / maybe / later):
    // "I guess that's where I come in" and "I suppose you're right" infer, they hedge nothing - one rule on every path
    return (bool) preg_match('/^\W*((uh|um|er|erm|well|oh|so|hmm|hey|and|then)\W+){0,2}((i guess|i suppose|i might)(\W*$|\W+(i|i\'?ll|i\'?m|i\'?d|i\'?ve|'
        . 'i will|i would|i can|i could|we|we\'?ll|we\'?d|we\'?re|not|maybe|later|yes|yeah|so)\b)|not sure|'
        . 'i\'?m not sure|i am not sure|dunno|i don\'?t know|i do not know|who knows|if i must|if i have to|i\'?m unsure|i am unsure)\b/i', $t);
}

/**
 * [pt19h-safety] THE NEVER-CLICK SET of the safety rule: a commit, a check, a priced, a scripted (an Invisible Continue too), a
 * crit or a goodbye line. A question, a negation, a deferral, an echo, a hedge or a near-miss never clicks one of these.
 */
/**
 * [pt19h-orch / S4.10, G14] Does this line hand over money? A priced entry, the pay class, or a hand-over of coin in its own words
 * ("Here's the gold.", "Here's your money.", "I'll pay the fine.", "Take the septims."). Used so that a bare assent never pays.
 */
function lrgDlgPaysOut(array $e): bool
{
    if ((int) ($e['cost'] ?? 0) > 0 || (string) ($e['class'] ?? '') === 'pay') { return true; }
    $n = ' ' . (string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? ''))) . ' ';
    return (bool) preg_match('/\b(gold|septims?|coins?|money|payment|fine|bounty|debt|fee)\b/', $n)
        && (bool) preg_match('/\b(here|heres|take|pay|paid|give|hand)\b/', $n);
}

function lrgDlgProtected(array $e): bool
{
    return !empty($e['commit']) || in_array((string) ($e['class'] ?? ''), ['commit', 'check', 'pay'], true)
        || (int) ($e['scripted'] ?? 0) === 1 || (int) ($e['invis'] ?? 0) === 1 || (int) ($e['crit'] ?? 0) >= 1
        || (int) ($e['cost'] ?? 0) > 0 || (int) ($e['goodbye'] ?? 0) === 1 || (string) ($e['kind'] ?? '') !== '';
}

/**
 * [pt19h-safety / G1, G11, G10] WHAT HE SAID AROUND THE LINE. When his sentence carries the line WHOLE (its tokens a contiguous
 * run of his - "saying the only line is choosing it"), the words before and after it are HIS words ABOUT it: a refusal, a
 * deferral or a negator there ("not now, <line> later", "no, <line>", "<line>, but not yet", "I don't think <line>"), a hedge
 * ("maybe <line>", "wait, <line>"), or a question ("wait, <line>?", "is it true that <line>", "<line>? says who", "<line>,
 * couldn't I") - never the line said. When his words are a FRAGMENT of the line (his tokens a run of the line's), his refusal
 * words are the line's own; only a `?` of his asks. Returns ['kind' => whole|frag|none, 'qualm' => ''|refuses|hedges|asks,
 * 'before' => tokens, 'after' => tokens]. 'none': a paraphrase - the caller's own shape and refusal tests judge it.
 */
function lrgDlgQuoteQualm(string $utter, array $e): array
{
    $out = ['kind' => 'none', 'qualm' => '', 'before' => [], 'after' => []];
    if (trim($utter) === '') { return $out; }
    $raw = lrgDlgSttFold($utter, [$e]);
    $et = lrgDlgTokens((string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? ''))));
    $ut = lrgDlgTokens(lrgPromptNorm($raw));
    if (!$et || !$ut) { return $out; }
    $etext = (string) ($e['text'] ?? '');
    for ($i = 0; $i < 2; $i++) { $etext = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', trim($etext))); }
    $lineAsks = strpos($etext, '?') !== false;
    $lineQ = lrgDlgEntryIsQuestion($e);
    $hisQ = strpos($raw, '?') !== false;
    // (a `?` of HIS - more of them than the line has, from where the line starts in his words: "wait, really? Silus Vesuius says
    // otherwise?" against "Really? Silus Vesuius says otherwise." asks its statement back)
    $at = lrgDlgTokenAt($ut, $et);
    if ($at < 0) {
        if (count($ut) < count($et) && lrgDlgTokenRun($et, $ut)) {
            $out['kind'] = 'frag';
            if (!$lineQ && preg_match('/\?\s*["\')\]]*\s*$/', trim($raw)) && !preg_match('/\?\s*["\')\]]*\s*$/', $etext)) { $out['qualm'] = 'asks'; }
        }
        return $out;
    }
    $out['kind'] = 'whole';
    $before = array_slice($ut, 0, $at);
    $after = array_slice($ut, $at + count($et));
    // (his `?` counted from where the line starts in his words: "a dangerous book hunt? sure, I'm ready for some adventure" asks
    // its own question BEFORE the line and says the line)
    $from0 = 0;
    if ($before && preg_match_all('/\b' . preg_quote((string) $et[0], '/') . '\b/iu', $raw, $om, PREG_OFFSET_CAPTURE)) {
        $nth = count(array_keys(array_map('strval', $before), (string) $et[0], true));
        $from0 = (int) (($om[0][$nth] ?? $om[0][count($om[0]) - 1])[1]);
    }
    $moreQ = substr_count(substr($raw, $from0), '?') > substr_count($etext, '?');
    // [pt19h r2 / safety P5, P17] his `?` asks the line back only when it CLOSES the line's own clause ("<line>?", "wait, <line>?", "<line>?
    // says who"); a question of his in a LATER clause ("<line>, what's next?") is a question after the line, and a line left unfinished
    // ("Speaking of which...") is completed by his question ("speaking of which, my payment?"), never asked back
    $run = lrgDlgRawRun($raw, $et, $at);
    if ($run !== null) {
        $tailRaw = (string) substr($raw, $run[0] + $run[1]);
        $unfinished = (bool) preg_match('/(\.\.\.|\x{2026})\s*["\')\]]*$/u', $etext);
        $moreQ = !$unfinished && (bool) preg_match('/^\s*["\')\]]*\?/', $tailRaw) && !preg_match('/\?\s*["\')\]]*$/', $etext);
    }
    $out['before'] = $before;
    $out['after'] = $after;
    // (a polite REQUEST for the line is the line: "could you tell me about Whiterun?" / "Tell me about Whiterun.")
    $req = $hisQ && !$lineAsks && lrgDlgIsRequest($raw) && lrgDlgRequestFits($raw, $e) && !$after;
    // his own `?` on a line that asks nothing: he asks the line back ("<line>?", "wait, <line>?", "<line>? says who"); after a line that
    // asks, his question asks about the same thing ("the Greybeards? who are they?")
    if ($moreQ && !$req && !$lineQ) { $out['qualm'] = 'asks'; return $out; }
    if (!$before && !$after) { return $out; }
    $fold = static fn(array $a): array => array_map(static fn($w) => str_replace('\'', '', (string) $w), $a);
    $lead = ['so', 'well', 'uh', 'um', 'er', 'erm', 'ah', 'oh', 'hmm', 'mm', 'hey', 'and', 'then', 'now', 'look', 'listen', 'right',
        'okay', 'ok', 'alright'];
    $b = $before;
    $hes = false;
    while ($b && (in_array(str_replace('\'', '', (string) $b[0]), $lead, true) || in_array((string) $b[0], ['wait', 'hmm', 'huh'], true))) {
        if ((string) $b[0] === 'wait') { $hes = true; }
        array_shift($b);
    }
    // (G6 of the language brief: "hold on, <the line>, ... is that who you mean?" - a hesitation before a QUESTION of his is no refusal)
    if ($hisQ && $b && preg_match('/^(hold on|hang on|one moment|not so fast|give me an? (moment|minute|second))\b/', implode(' ', $b), $hm)) {
        $b = array_slice($b, count(lrgDlgTokens((string) $hm[0])));
        $hes = true;
    }
    $bt = implode(' ', $b);
    $atx = implode(' ', $after);
    // the words of the line's OWN clause around it (a negator there negates the line; "I don't get it, why ..., <line>" does not)
    $stops = lrgDlgClauseStarts((string) preg_replace('/[.!?\x{2026}]+(?=\s|$)/u', ',', $raw));
    $from = 0;
    $to = count($ut);
    foreach ($stops as $s) {
        if ((int) $s <= $at) { $from = max($from, (int) $s); }
        if ((int) $s >= $at + count($et) && (int) $s < $to) { $to = (int) $s; }
    }
    $adjB = array_slice($ut, max($from, $at - count($b)), $at - max($from, $at - count($b)));
    $adjA = array_slice($ut, $at + count($et), max(0, $to - $at - count($et)));
    // (a question lead of his before a line that is itself a question is its own wording - "wait, is that it?"; before a statement,
    // even one with a `?` inside - "is it true that esbern? Open the door. I'm a friend" - it asks the line back)
    if (!$lineQ && !$req) {
        // (a lone question word glued to the line with no `?` is an exclamation or an embedded clause: "look what I found, your
        // letter to Olfina" / "I found your letter to Olfina.")
        if ($b && (count($b) >= 2 || $hisQ) && lrgDlgLeadsQuestion($b, false)) { $out['qualm'] = 'asks'; return $out; }
        // (an echo frame directly before the line - "is it true that <line>", "you're telling me <line>" - never a report of his own:
        // "you said you had work for me, so <line>")
        if (preg_match('/^(so )?(you\'?re|you are|are you) (saying|telling me)( that)?$|^(you|do you|did you) (mean|said|say)( that)?$|^(is it|it\'?s|it is) true( that)?$|^really$/', $bt)) {
            $out['qualm'] = 'asks';
            return $out;
        }
        // (after it only a TAG asks - an inverted auxiliary and its pronoun: "<line>, couldn't I", "<line>, isn't it", "<line>, is it";
        // "Ready when you are." / "reporting in, what's the plan for Windhelm" are no tags)
        $fa = $fold($after);
        $auxT = ['is', 'are', 'do', 'does', 'did', 'can', 'could', 'would', 'will', 'should', 'have', 'has', 'was', 'were', 'am', 'isnt',
            'arent', 'dont', 'doesnt', 'didnt', 'wont', 'cant', 'wasnt', 'werent', 'couldnt', 'wouldnt', 'shouldnt', 'havent', 'hasnt'];
        if ($fa && ((in_array($fa[0], $auxT, true) && in_array((string) ($fa[1] ?? ''), ['i', 'you', 'we', 'he', 'she', 'they', 'it', 'there'], true)
            && count($fa) <= 3) || preg_match('/^(is that so|or what|eh|right|huh|is that right|am i right)$/', $atx))) {
            // ([pt19h-safety review] + "<line>, right" / "huh" with the STT's `?` lost: "I'm ready to return to Tamriel, right" clicked)
            $out['qualm'] = 'asks';
            return $out;
        }
    }
    // (a line that itself REFUSES agrees with his refusal around it - "not tonight, I don't really feel like drinking", "no thanks, I
    // don't need a guide", "No thanks, not interested." - only a DEFERRAL of his that the line does not carry still takes it back:
    // "not now, no, Onmund. I'm asking you. Be the Arch-Mage instead of me later")
    $deferRe = LRG_DLG_DEFER_RE;
    $lineLow = strtolower($etext);
    // (the line refuses by its own words - never by a question preamble it happens to open with: "I have some questions about your
    // daughter." asks, it does not refuse)
    if (lrgDlgIsBackOut($etext, true) && !preg_match('/\b(i|we)(\'?ve| have| had)?( got)?\s+(a|one|another|some|a few|a couple of|one more|a quick|one last)\s+((more|quick|small|last|few)\s+)?questions?\b/i', $etext)) {
        if ((preg_match($deferRe, $bt) || preg_match($deferRe, $atx)) && !preg_match($deferRe, $lineLow)) { $out['qualm'] = 'refuses'; }
        return $out;
    }
    // the words of its own clause around it, with the negator idioms of ASSENT taken out ("<line>, no problem", "sure, why not")
    $around = [];
    $ab = $fold(array_merge($adjB, $adjA));
    for ($i = 0, $n = count($ab); $i < $n; $i++) {
        $nx = (string) ($ab[$i + 1] ?? '');
        if (($ab[$i] === 'no' && in_array($nx, ['problem', 'worries', 'doubt'], true)) || ($ab[$i] === 'why' && $nx === 'not')) { $i++; continue; }
        $around[] = $ab[$i];
    }
    // ("yet" after a line that negates agrees with it: "don't destroy the staff yet")
    $defer = preg_match('/\b(not|no|never|don\'?t|won\'?t|can\'?t)\b/', $lineLow)
        ? ['later', 'tomorrow', 'someday', 'eventually', 'afterwards', 'first', 'soon']
        : ['later', 'tomorrow', 'someday', 'eventually', 'afterwards', 'yet', 'first', 'soon'];
    // (the breath's narrow refusal set before it: "I don't get it, ..." or "I can't believe it, ..." says what he does not know, not
    // that he refuses; "not now", "no", "I'm not sure", "never mind", "maybe later" do refuse; after it a deferral anywhere, a bare
    // refusal only as a short tail - "understood, no free rides" refuses nothing; and a question line's "... or not?" is its tag)
    $hisAsk = $lineQ && lrgDlgIsQuestion($raw);
    if (($bt !== '' && lrgDlgIsBackOut($bt, true, true)) || preg_match($deferRe, $atx)
        || ($atx !== '' && count($after) <= 2 && lrgDlgIsBackOut($atx, true, true) && !$hisAsk)
        || (in_array((string) ($fold($b)[0] ?? ''), ['not', 'no', 'nope', 'nah', 'never'], true)
            && !preg_match('/^(no (problem|worries|doubt)|not a problem)\b/', $bt))
        || lrgDlgDeferAfter($fold($after), $defer) || (!$hisAsk && array_intersect($around, LRG_DLG_NEG))) {
        $out['qualm'] = 'refuses';
        return $out;
    }
    if (($bt !== '' && lrgDlgHedges($bt)) || ($atx !== '' && lrgDlgHedges($atx)) || ($hes && !$lineAsks)) { $out['qualm'] = 'hedges'; }
    return $out;
}

/**
 * [pt19h-safety / G9] The line COMMITS in an opening sentence of assent ("Agreed. What's the passphrase?", "Deal. Where do I find
 * him?", "Fine. I'll set you free if ...") and his words carry no assent of their own: he asked its question, he agreed to nothing.
 */
function lrgDlgSkipsAssent(string $utter, array $e): bool
{
    // (only his QUESTION skips it: "I'll come quietly" says "Fine. I'll come quietly.", "take me to Skuldafn" says "I'm ready. Take
    // me to Skuldafn." - the commitment itself carries the assent)
    if (!lrgDlgIsQuestion($utter)) { return false; }
    $s = preg_split('/(?<=[.!?])\s+/u', trim((string) ($e['text'] ?? ''))) ?: [];
    if (count($s) < 2 || !preg_match('/\?\s*["\')\]]*\s*$/', trim((string) end($s)))) { return false; }
    $first = lrgPromptNorm((string) $s[0]);
    $as = array_map(static fn($p) => lrgPromptNorm((string) $p), array_merge((array) lrgDlgCfg('confirm.assent_words', []),
        ['agreed', 'deal', 'fine', 'very well', 'all right', 'alright', 'understood', 'done', 'yes', 'it\'s a deal', 'you have a deal']));
    if (!in_array($first, $as, true)) { return false; }
    return lrgDlgLeadPhraseRaw($utter, array_merge((array) lrgDlgCfg('confirm.assent_words', []), ['agreed', 'deal', 'fine', 'understood'])) === null;
}

/**
 * [pt19h-safety / G1, G9] A DEFERRAL anywhere in his words the line does not carry: "can you read the Elder Scroll later?", "I'll
 * restore the stones tomorrow", "someday I'll join you" - never now, so never the protected line now (she asks, or her words answer).
 */
function lrgDlgDefers(string $utter, array $e): bool
{
    $d = ['later', 'tomorrow', 'someday', 'eventually', 'afterwards'];
    $hit = array_intersect(lrgDlgFoldTokens($utter), $d);
    // [pt19h-safety review] ... and the PHRASES that put it off: "I'm ready to return to Tamriel in a bit", "... once I've said
    // goodbye", "... after I finish here", "as soon as I'm done" (each clicked the commit as explicit) - unless the line says it too
    $pre = '/\b(in a (bit|moment|minute|second|while|few (minutes|moments))|once (i|we)\b|after (i|we)\b|as soon as (i|we)\b)/i';
    if (!$hit && preg_match($pre, $utter) && !preg_match($pre, (string) ($e['text'] ?? ''))) { return true; }
    // (a line that itself puts it off agrees: "later, I've got more pressing matters" / "I have more pressing matters at the moment.")
    return $hit && !array_intersect(lrgDlgFoldTokens((string) ($e['text'] ?? '')), array_merge($hit, ['moment', 'later', 'now', 'busy']));
}

/**
 * [pt19h-safety / G1] Does his refusal OPEN like the line does ("no" / "No, but I know how to find out.", "I don't get it" / "I
 * don't understand what's going on.")? Then his refusal words may be the line's own; otherwise ("never mind" against "I don't
 * understand what's going on.") they are his, and the line is refused - no key, no breath.
 */
function lrgDlgSameLead(string $utter, array $e): bool
{
    $fill = ['uh', 'um', 'er', 'erm', 'ah', 'oh', 'hmm', 'mm', 'well', 'so', 'hey'];
    $u = lrgDlgFoldTokens($utter);
    $n = lrgDlgFoldTokens((string) ($e['text'] ?? ''));
    while ($u && in_array($u[0], $fill, true)) { array_shift($u); }
    while ($n && in_array($n[0], $fill, true)) { array_shift($n); }
    if (!$u || !$n) { return false; }
    // [pt19h r2 / safety P6] both LEAD with a refusal, or both with a hedge ("no idea, but they're hunting Esbern" / "I don't know, but the
    // Thalmor are looking for someone named Esbern.", "not sure yet, but ..." / "We're not sure, but ..."): the same opening
    // (the same KIND only: not knowing / declining / hedging - "never mind" against "I don't understand what's going on." stays his own)
    $lu = implode(' ', array_slice($u, 0, 4));
    $ln = implode(' ', array_slice($n, 0, 4));
    static $kinds = ['/^(?:no idea|i dont know|i do not know|i dunno|dunno|not sure|im not sure|i am not sure|no clue|who knows|beats me|i have no idea|i couldnt say|i cant say)\b/',
        '/^(?:never ?mind|no thanks|no thank you|not interested|forget it|ill pass|i will pass|rather not|id rather not|no)\b/'];
    foreach ($kinds as $rx) { if (preg_match($rx, $lu) && preg_match($rx, $ln)) { return true; } }
    if (lrgDlgHedges($lu) && lrgDlgHedges($ln)) { return true; }
    $k = min(2, count($u), count($n));
    // (the same leading negator is the same opening: "no, just some wolves" / "No. Some wolves, but no dogs.")
    if (in_array($u[0], ['no', 'nope', 'nah', 'never', 'not'], true) && $u[0] === $n[0]) { return true; }
    return array_slice($u, 0, $k) === array_slice($n, 0, $k);
}

/**
 * [pt19h-safety / G9] A STATEMENT of his against a QUESTION line: "I have a plan" is no "What's the plan?", "everyone is here" no
 * "Where is everyone?", "we surrender" no "Do you surrender?", "the situation is bad" no "What's the situation?", "I understand"
 * no "Understand? How?" - he asserts, the line asks. Not a statement: a question, a request ("can I ..."), an ask in statement
 * form ("tell me ...", "I want to know ...", "I wonder ..."), or a bare noun phrase naming the line's subject ("the plan").
 */
function lrgDlgDeclares(string $utter, array $e): bool
{
    if (trim($utter) === '' || !lrgDlgEntryIsQuestion($e) || lrgDlgIsQuestion($utter) || lrgDlgIsRequest($utter)) { return false; }
    // [pt19h r2 / safety P13] a leading ASSENT answers her question ("yes, I'll help" on "What else can I help you with?") - S4.5 judges it
    if (lrgDlgAssent($utter) !== null) { return false; }
    // (a CHECK line's question is rhetorical - "Will this change your mind? (50 gold)", "Isn't Whiterun your hometown?": his
    // statement is the attempt, and the check rails judge it; a REQUEST line - "Can I sell this Queen Bee Statue to you?" - asks
    // leave for what his statement offers: "I've got the Queen Bee Statue for you")
    if ((string) ($e['kind'] ?? '') !== '' || lrgDlgIsRequest((string) ($e['text'] ?? ''))) { return false; }
    $tok = lrgDlgFoldTokens(lrgDlgBareWords($utter));
    if (!$tok) { return false; }
    // [pt19h r2 / safety P15] a DECLARATIVE yes/no question with its `?` lost ("you got any supplies", "you need help with something", "you
    // ok") against a line that asks HIM, and his want / need against a line that asks for it ("I need to get to Whiterun" / "How do I get
    // to Whiterun from here?", "I'm here for work" / "Is there any work to be done?"): a question, a request - no statement
    $low0 = implode(' ', $tok);
    if (preg_match('/^(?:you|ya|u)\b/', $low0) && preg_match('/\b(?:any|anything|anyone|ever|still|some|something|got|need|want|ok|okay|alright|all right|ready|sure|there)\b/', $low0)
        && strncmp(lrgDlgQuestionKind((string) ($e['text'] ?? ''), true), 'yn:you', 6) === 0) { return false; }
    if (preg_match('/^(?:i need|i want|im here for|im after|i could use|i require|we need|i came for|im looking for|im in need of|i have to|i must)\b/', $low0)) { return false; }
    // (the STT's stutter is one word: "i'm sorry what what")
    $tok = array_values(array_filter($tok, static fn($w, $k) => $k === 0 || $w !== $tok[$k - 1], ARRAY_FILTER_USE_BOTH));
    // the line itself with its question word clipped by the STT - its END said, with or without a hedge before it ("you learned
    // anything about the dragons", "that's where I come in", "I suppose that's where I come in") - is no statement of his;
    // "Thorald is alive" (the line's condition, not its question) still is
    // (the line's own words as shown - its norm may lead with a hand-over tag's object, "(Give Dragonstone)")
    $etx = trim((string) ($e['text'] ?? ''));
    for ($i = 0; $i < 2; $i++) { $etx = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $etx)); }
    $et = lrgDlgFoldTokens($etx !== '' ? $etx : (string) ($e['norm'] ?? ''));
    $hedge = ['i', 'suppose', 'guess', 'think', 'reckon', 'believe', 'so', 'yeah', 'yes', 'okay', 'ok', 'well', 'right', 'and', 'oh'];
    $qLead = ['what', 'whats', 'why', 'how', 'hows', 'who', 'whos', 'where', 'wheres', 'when', 'which', 'is', 'are', 'do', 'does',
        'did', 'can', 'could', 'would', 'should', 'will', 'have', 'has', 'was', 'were', 'am'];
    // (the STT clips ONE word: the line less its first word after its lead-ins - "I believe you" is two words short of "Why should
    // I believe you?" and states)
    $e0 = 0;
    while ($e0 < count($et) && in_array($et[$e0], ['and', 'so', 'oh', 'well', 'then', 'but', 'now', 'right'], true)) { $e0++; }
    for ($k = 0; $k <= min(3, count($tok) - 1); $k++) {
        $rest = array_slice($tok, $k);
        if (count($rest) >= 2 && count($rest) <= count($et)) {
            if (count($rest) >= count($et) - $e0 - 1 && array_slice($et, -count($rest)) === $rest) { return false; }
            // ... or the rest of its first question after the clipped question word ("[Have] you learned anything about the
            // Dragons? Do you need any help?")
            $at = lrgDlgTokenAt($et, $rest);
            if ($at === $e0 + 1 && in_array($et[$at - 1], $qLead, true)) { return false; }
            // ... or its auxiliary AND subject clipped ("[Do you] want to be the new steward of Tel Mithryn?")
            if ($at === $e0 + 2 && in_array($et[$e0], $qLead, true) && in_array($et[$e0 + 1], ['you', 'i', 'we', 'they', 'he', 'she', 'it'], true)) {
                return false;
            }
        }
        if (!in_array($tok[$k], $hedge, true)) { break; }
    }
    // a clause of his that asks ("here's fifty septims, will that change your mind"), or the line's own question opening in his
    // words with its `?` lost ("break the law are you kidding" / "Break the law? Are you kidding?") is no statement either
    foreach (preg_split('/[,;:.!?]+/u', lrgDlgBareWords($utter)) ?: [] as $cl) {
        if (lrgDlgLeadsQuestion(lrgDlgTokens(lrgPromptNorm((string) $cl)), true)) { return false; }
    }
    // (a line that ALSO states - "I need a way to lure a dragon to Dragonsreach. Any ideas?", "Did anyone else? I'm looking for a
    // Dark Elf named Rudin Filaro." - may be answered by his statement of its statement part: only an all-question line is judged)
    $allS = array_values(array_filter(preg_split('/(?<=[.!?\x{2026}])\s+/u', $etx) ?: [], static fn($s) => trim((string) $s) !== ''));
    foreach ($allS as $s) { if (!preg_match('/\?\s*["\')\]]*\s*$/', trim((string) $s))) { return false; } }
    // (a line that asks whether she KNOWS a fact - "Did you know some Alik'r warriors are looking for a Redguard woman?" - is told
    // that fact by his statement of it)
    if (preg_match('/^\W*((so|and|well|oh)\W+)?(did|do) you (know|hear|realize|realise)\b|^\W*((so|and|well|oh)\W+)?(have|had) you heard\b/i', $etx)) {
        return false;
    }
    // (nor a line that PROPOSES or ECHOES a statement - "What if I were to pay you for the amulet?", "How about ...?", "Are you saying you
    // want my help?", "You mean ...?": his statement of it is the proposal, the echo)
    if (preg_match('/^\W*((so|and|well|oh)\W+)?(what if|how about|what about|are you saying|you\'?re saying|so you\'?re saying|(do |did )?you mean)\b/i', $etx)) {
        return false;
    }
    $sents = array_values(array_filter($allS, static fn($s) => strpos((string) $s, '?') !== false));
    if ($sents) {
        // (the line's QUESTION clause: the last clause of its last asking sentence - "If Thorald is alive, where is he?" asks "where
        // is he"; an inverted opening of it in his words is its question said with the `?` lost - "I know what happened" embeds
        // "what happened" and still states)
        $qcl = array_values(array_filter(preg_split('/[,;:]+/u', (string) end($sents)) ?: [], static fn($c) => trim((string) $c) !== ''));
        $qt = lrgDlgFoldTokens((string) ($qcl ? end($qcl) : end($sents)));
        $aux = ['is', 'are', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'have', 'has', 'was', 'were', 'am'];
        if (count($qt) >= 2 && lrgDlgTokenRun($tok, array_slice($qt, 0, 2)) && in_array($qt[0], $aux, true)) { return false; }
        // ... and the whole question clause in his words, not embedded by a verb of knowing ("if he's alive where is he" asks it;
        // "I know what happened" states it)
        // (heard, not spelt: "for get me who are ya" carries "Forget me, who are you?")
        $p = -1;
        for ($s = 0; count($qt) >= 2 && $s + count($qt) <= count($tok) && $p < 0; $s++) {
            $okRun = true;
            foreach ($qt as $j => $w) { if ($tok[$s + $j] !== $w && !lrgDlgSoundsLike((string) $tok[$s + $j], (string) $w)) { $okRun = false; break; } }
            if ($okRun) { $p = $s; }
        }
        if ($p >= 0 && ($p === 0 || !in_array($tok[$p - 1], ['know', 'knew', 'wonder', 'wondered', 'ask', 'asked', 'tell', 'told', 'see',
            'saw', 'understand', 'remember', 'forget', 'forgot', 'guess', 'think', 'sure', 'idea', 'care', 'mind', 'matter', 'decide',
            'learn', 'learned', 'heard', 'hear', 'show', 'explain', 'found', 'find', 'out'], true))) { return false; }
        // a line that asks in the SHAPE OF A STATEMENT ("So, you're Orthorn?", "You'll help me escape then?", "And that's where I
        // come in?") is his statement said with its `?` lost
        // (read as said, not folded: "So we're learning about Wards?" leads with "we're", no "were")
        $qr = lrgDlgTokens(lrgPromptNorm((string) ($qcl ? end($qcl) : end($sents))));
        while ($qr && in_array($qr[0], ['and', 'so', 'oh', 'well', 'then', 'but', 'now', 'right'], true)) { array_shift($qr); }
        if ($qr && ((string) $qr[0] === "we're" || !in_array(str_replace("'", '', (string) $qr[0]), $qLead, true))) { return false; }
    }
    // the line word for word with one word misheard ("wear can i find him" / "Where can I find him?")
    $a = $tok;
    $b = $et;
    while ($b && in_array($b[0], ['and', 'so', 'oh', 'well', 'then', 'but', 'now', 'right'], true)) { array_shift($b); }
    if (count($a) === count($b)) {
        $diff = 0;
        foreach ($a as $k => $w) {
            if ($w === $b[$k]) { continue; }
            $diff += (lrgDlgSoundsLike($w, (string) $b[$k]) || (max(strlen($w), strlen((string) $b[$k])) >= 3 && levenshtein($w, (string) $b[$k]) <= 1)) ? 1 : 2;
        }
        if ($diff <= 1) { return false; }
    }
    $low = implode(' ', $tok);
    if (preg_match('/^(please )?(tell|show|explain|describe|give|say|remind|teach|help|let me know|let me see|let me ask)\b|\b(tell|show|give) me\b|\bexplain\b|'
        . '\b(want|like|need|wish|have|got|wanted|love) to (know|ask|hear|see|learn|understand)\b|\bi wonder|\bi was wondering|\bcurious\b|'
        . '\bquestion\b|\bask you\b|\bask about\b|\b(no|any) (idea|clue)\b|\byou know\b|\bknow anything\b|\byou mean\b|'
        . '\b(youre|you are|are you) (saying|telling me)\b|\bso youre\b|\b(looking|searching|hunting) for\b|\btrying to find\b/', $low)) { return false; }
    $verbs = ['is', 'are', 'was', 'were', 'am', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could',
        'should', 'must', 'shall', 'may', 'might', 'im', 'youre', 'theyre', 'hes', 'shes', 'its', 'thats', 'theres', 'ive', 'youve',
        'weve', 'theyve', 'ill', 'youll', 'theyll', 'id', 'youd', 'wed', 'theyd', 'dont', 'doesnt', 'didnt', 'wont', 'cant', 'isnt',
        'arent', 'wasnt', 'werent', 'havent', 'hasnt'];
    $pron = ['i', 'we', 'you', 'he', 'she', 'they', 'it', 'everyone', 'everybody', 'nobody', 'someone', 'somebody', 'nothing', 'everything'];
    return (bool) array_intersect($tok, $verbs) || in_array($tok[0], $pron, true);
}

/**
 * [pt19h-safety / G9, G5] THE SAME QUESTION, strictly - the test of a PROTECTED line (a commit's explicit line, a commit single, the
 * words path on a scripted line), on top of lrgDlgQuestionsAgree: another question WORD asks another thing ("who are the Blood
 * Horkers" / "So where are the Blood Horkers?", "where is Riftweald Manor?" / "What's the best way to get into Riftweald Manor?",
 * "who is Madanach" / "Where's Madanach?") - only what / why about the same words agree ("why do the Greybeards want me" / "What
 * do these Greybeards want with me?"); a line asked back as a fragment ("Contract?", "Orders, sir?", "Found this bust ... Worth
 * anything?") takes his question only when it adds no word but a frame word ("what contract do you speak of?", "who are the
 * Greybeards"; never "who gave those orders?", "where can I find the bust of the Gray Fox?"); "is THERE ..." asks whether a thing
 * exists, no "do YOU ..." ("is there a way out" / "Do you know the way out of here?"); an STT-clipped question word is the WHOLE
 * rest of the line ("else can I help you with"), never a run inside it ("do you need help" / "What do you need help with?").
 */
function lrgDlgQuestionsSame(string $utter, array $e): bool
{
    // [pt19h r2 / safety P4] the line word for word is its own question
    if (lrgPromptNorm($utter) === (string) ($e['norm'] ?? '')) { return true; }
    // his words the line's own (the line said inside his sentence, or its OPENING run - "so what's your plan"): its own question;
    // a run from inside it drops its question word and asks another ("do you need help" / "What do you need help with?")
    $qq = lrgDlgQuoteQualm($utter, $e);
    if ($qq['qualm'] === '' && ($qq['kind'] === 'whole' || ($qq['kind'] === 'frag'
        && lrgDlgTokenAt(lrgDlgTokens((string) ($e['norm'] ?? '')), lrgDlgTokens(lrgPromptNorm(lrgDlgSttFold($utter, [$e])))) === 0))) {
        return true;
    }
    // a line of SEVERAL questions takes any of them ("so what's your plan" / "So what's your plan? How do I infiltrate the Thalmor
    // Embassy", "who's the new owner" / "New owner? What are you talking about?")
    $etx = trim((string) ($e['text'] ?? ''));
    for ($i = 0; $i < 2; $i++) { $etx = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $etx)); }
    $qs = array_values(array_filter(preg_split('/(?<=[.!?])\s+/u', $etx) ?: [], static fn($s) => strpos((string) $s, '?') !== false));
    if (count($qs) >= 2) {
        foreach ($qs as $s) {
            if (lrgDlgQuestionsSame1($utter, ['text' => (string) $s, 'norm' => lrgPromptNorm((string) $s), 'full' => (string) ($e['text'] ?? '')] + $e)) { return true; }
        }
        return false;
    }
    return lrgDlgQuestionsSame1($utter, $e);
}

/** [pt19h-safety] lrgDlgQuestionsSame for a line of one question. */
function lrgDlgQuestionsSame1(string $utter, array $e): bool
{
    if (!lrgDlgQuestionsAgree($utter, $e)) { return false; }
    $ku = lrgDlgQuestionKind($utter);
    $ke = lrgDlgQuestionKind((string) ($e['text'] ?? ''), true);
    $etk = array_map(static fn($w) => str_replace('\'', '', (string) $w), lrgDlgTokens((string) ($e['norm'] ?? '')));
    $ut = lrgDlgFoldTokens(lrgDlgBareWords($utter));
    if ($ku !== '' && $ku === $ke && strncmp($ku, 'wh:', 3) === 0) { $ke = $ke . '#'; }   // (the same question word: the person below)
    if ($ku === '' || $ke === '' || $ku === $ke) { return true; }
    $ynU = strncmp($ku, 'yn', 2) === 0;
    $ynE = strncmp($ke, 'yn', 2) === 0;
    if ($ke === 'echo' || $ku === 'echo') {
        static $frame = ['tell', 'about', 'know', 'think', 'like', 'want', 'need', 'got', 'get', 'see', 'say', 'ask', 'talk', 'hear',
            'heard', 'any', 'anything', 'something', 'going', 'go', 'have', 'give', 'take', 'come', 'make', 'speak', 'mean', 'meant',
            'refer', 'kind', 'sort', 'type', 'exactly', 'again', 'really', 'supposed', 'one', 'ones', 'through', 'around', 'near',
            'past', 'toward', 'towards', 'across', 'along', 'inside', 'outside', 'behind', 'beyond', 'within', 'above', 'below',
            'under', 'between', 'among', 'during', 'before', 'after', 'since', 'until', 'via', 'into', 'onto', 'from', 'out', 'up',
            'down', 'over', 'off', 'back', 'just', 'even', 'still', 'all', 'some', 'here', 'there', 'now', 'then', 'look', 'will',
            'would', 'can', 'could', 'shall', 'should', 'may', 'might', 'must', 'did', 'does', 'had', 'been', 'being', 'am', 'were',
            'was',
            // [pt19h r2 / first evening FE.hulda.plain] a quantifier is a frame word ("a lot of visitors" / "many visitors")
            'lot', 'lots', 'many', 'much', 'few', 'several', 'plenty', 'bit', 'little', 'enough', 'more', 'less', 'most'];
        // [pt19h r2] his QUESTION CLAUSE against the line's QUESTION sentence: "nice place, get a lot of visitors?" asks "get a lot of
        // visitors" of "Nice inn you have here. Do you get many visitors?" - the lead-in clauses are no part of either question
        $we = lrgPromptWords(lrgPromptNorm(lrgDlgQuestionPart((string) ($e['text'] ?? ''), true)), true);
        $wu = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords(lrgDlgQuestionPart($utter, false))), true);
        $mine = array_filter(array_diff($wu, $frame), static fn($w) => !lrgDlgStemIn((string) $w, $we));
        // ... and it names at least half of what the line asks about ("where is Dragon Bridge?" is no "Know anything about a moth
        // priest visiting Dragon Bridge?")
        $weC = array_values(array_diff($we, $frame));
        $cov = $weC ? count(array_filter($weC, static fn($w) => lrgDlgStemIn((string) $w, $wu))) / count($weC) : 1.0;
        return !$mine && $cov >= 0.5;
    }
    if (!$ynU && !$ynE) {
        $pair = [substr($ku, 3), rtrim(substr($ke, 3), '#')];
        sort($pair);
        if ($pair === ['how', 'what']) {
            // how / what about the SAME words, all of them ("how can I help?" / "What can I do to help?"; never "what is Sky Haven
            // Temple" / "How do we find the entrance to Sky Haven Temple?")
            $we = lrgPromptWords((string) ($e['norm'] ?? ''), true);
            $wu = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords($utter)), true);
            $cov = $we ? count(array_filter($we, static fn($w) => lrgDlgStemIn((string) $w, $wu))) / count($we) : 1.0;
            return $cov >= 0.75 && !lrgDlgOwnWords($utter, $e);
        }
        if ($pair[0] !== $pair[1]) {
            if ($pair === ['what', 'why']) { return true; }
            // [pt19h r2 / safety P3, P16 (b)] where / how (and what / where) about FINDING or GETTING somewhere are one question ("where
            // can I find your master" / "How can I find your master?", "how do I get into Riftweald Manor" / "What's the best way to get
            // into Riftweald Manor?"): half of the line's words, and no word of his own
            $fg = '/\\b(find|get|go|reach|enter|way|into|locate)\\b/i';
            if (in_array($pair, [['how', 'where'], ['what', 'where']], true) && preg_match($fg, $utter) && preg_match($fg, (string) ($e['text'] ?? ''))) {
                $we = lrgPromptWords((string) ($e['norm'] ?? ''), true);
                $wu = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords($utter)), true);
                $cov = $we ? count(array_filter($we, static fn($w) => lrgDlgStemIn((string) $w, $wu))) / count($we) : 1.0;
                return $cov >= 0.5 && !lrgDlgOwnWords($utter, $e);
            }
            return false;
        }
        // the same question word about another PERSON ("how are you doing?" is no "How am I doing?", "who are you?" no "Who am I?"):
        // how / who with its verb and its subject right after it - his side (I / we), hers (you), a third (he / she / they)
        $side = static function (array $tk): string {
            $map = ['i' => 'me', 'we' => 'me', 'you' => 'you', 'ya' => 'you', 'he' => 'them', 'she' => 'them', 'they' => 'them'];
            $at = -1;
            foreach ($tk as $k => $w) { if (in_array($w, ['how', 'who'], true)) { $at = $k; break; } }
            if ($at < 0 || !in_array((string) ($tk[$at + 1] ?? ''), ['am', 'are', 'is', 'was', 'were', 'do', 'does', 'did', 'have', 'has'], true)) {
                return '';
            }
            return $map[(string) ($tk[$at + 2] ?? '')] ?? '';
        };
        // (the line's person from its last asking sentence: "Thank you. What's next?" reads nobody, not the "you" it thanks)
        $qs = array_values(array_filter(preg_split('/(?<=[.!?])\s+/u', trim((string) ($e['text'] ?? ''))) ?: [], static fn($s) => strpos((string) $s, '?') !== false));
        $su = $side($ut);
        $se = $side(lrgDlgFoldTokens((string) ($qs ? end($qs) : ($e['text'] ?? ''))));
        if (!($su === '' || $se === '' || $su === $se)) { return false; }
        // [pt19h r2 / extended MS01.margret.business] the same question WORD about the same THING: his meaning words (no auxiliary, no
        // filler, no STT echo - lrgDlgOwnWords) are the line's, and they name half of what its question asks about ("what do you think
        // of Markarth" is no "What are you doing in Markarth?"; "what happened here" is "What happened to this place?")
        static $wf = ['tell', 'about', 'know', 'any', 'anything', 'something', 'exactly', 'again', 'really', 'supposed', 'one', 'ones',
            'just', 'even', 'still', 'all', 'some', 'here', 'there', 'now', 'then', 'look', 'please', 'mean', 'kind', 'sort', 'type',
            'like', 'ever', 'else', 'going', 'gonna', 'wanna', 'gotta', 'thing', 'things', 'stuff'];
        // (his words may name any part of the WHOLE line - "where do I sign up to kill vampires" / "Killing vampires? Where do I sign up?" -
        // and an STT echo of a line word is that word only as the reach lane reads one (lrgDlgReachEcho: "health" / "help", never "think" /
        // "doing"); the coverage is of the line's QUESTION sentence)
        $full = trim((string) ($e['full'] ?? ($e['text'] ?? '')));
        for ($i = 0; $i < 2; $i++) { $full = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $full)); }
        // (the line with its LAST word garbled by the STT - "what do you have in my" / "What do you have in mind?" - is the line)
        $utk = lrgDlgFoldTokens(lrgDlgBareWords($utter));
        $etk2 = lrgDlgFoldTokens(lrgDlgQuestionPart((string) ($e['text'] ?? ''), true));
        if (count($utk) >= 4 && count($utk) === count($etk2) && array_slice($utk, 0, -1) === array_slice($etk2, 0, -1)) { return true; }
        // (an STT slip of a line word he did not say - lrgDlgSoundsLike with the same first letter: "half" / "have", "health" / "help",
        // "wood" / "would"; never "think" / "doing")
        $slipOf = static function (string $w, array $pool): bool {
            foreach ($pool as $m) { $m = (string) $m; if ($m !== '' && $w !== '' && $m[0] === $w[0] && lrgDlgSoundsLike($w, $m)) { return true; } }
            return false;
        };
        $weAll = lrgPromptWords(lrgPromptNorm($full), true);
        $eQ = lrgDlgQuestionPart((string) ($e['text'] ?? ''), true);
        $wuQ = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords(lrgDlgQuestionPart($utter, false))), true);
        $wuAll = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords($utter)), true);
        $missing = array_values(array_diff($weAll, $wuAll));
        // half or more of the content words the line's question asks about are in his words ("what do they want with me" is "What do
        // these Greybeards want with me?"; "who's Gianna" names nothing of "Who's the Gourmet here?"); a question about ONE thing needs
        // that thing ("what do you think of Markarth" lacks "doing" - and its own "think" fails it below)
        $weQ = array_values(array_diff(lrgPromptWords(lrgPromptNorm($eQ), true), $wf));
        $cov = 0;
        foreach ($weQ as $w) {
            if (lrgDlgStemIn((string) $w, $wuAll) || $slipOf((string) $w, $wuAll)) { $cov++; }
        }
        if ($weQ && $cov * 2 < count($weQ)) { return false; }
        // ... and a question about TWO or more things takes no word of his own ("who's Gianna" is no "Who's the Gourmet here?"; a question
        // about one thing does - "what comes next" / "What's next?", "what do you need done" / "What do you need me to do?")
        if (count($weQ) >= 2) {
            foreach (array_diff($wuQ, $wf) as $w) {
                if (!lrgDlgStemIn((string) $w, $weAll) && !$slipOf((string) $w, $missing)) { return false; }
            }
        }
        return true;
    }
    if ($ynU && $ynE) {
        // "is THERE ..." (does it exist) against "do YOU ..." (her knowledge): "is there a way out" / "Do you know the way out of here?";
        // "can we do anything" still is "Is there anything we can do?"
        // [pt19h r2 / safety P3, P16 (a)] ... only when the "do YOU" side asks her KNOWLEDGE (know, think, remember, seen, heard, any
        // idea): "do you have any more contracts" asks the same as "Are there any more contracts available?"
        $knows = static fn(string $s): bool => (bool) preg_match('/\\b(know|knows|known|think|remember|recall|seen|heard|idea|familiar|aware)\\b/i', $s);
        if ($ku === 'yn:there' && $ke === 'yn:you') { return !$knows((string) ($e['text'] ?? '')); }
        if ($ku === 'yn:you' && $ke === 'yn:there') { return !$knows($utter); }
        return true;
    }
    // a question word against a yes/no line (or the other way round): an STT clip is the whole rest of the line
    if ($ynU && count($ut) >= 3 && array_slice($etk, 1) === $ut) { return true; }
    [$wh, $ynTok] = $ynU ? [substr($ke, 3), $ut] : [substr($ku, 3), $etk];
    $forms = ['what' => ['what', 'whats', 'which'], 'why' => ['why'], 'how' => ['how', 'hows'], 'who' => ['who', 'whos', 'whom'],
        'where' => ['where', 'wheres'], 'when' => ['when']][$wh] ?? [$wh];
    if (array_intersect($ynTok, $forms)) { return true; }
    $any = ['anything', 'something', 'any', 'anyone', 'someone', 'anybody', 'somebody', 'anywhere', 'somewhere'];
    return $wh === 'what' && (bool) array_intersect($ynTok, $any);
}

/**
 * [pt19h-safety / G5] Does his REQUEST ask for what the line says? "can I rent a room?" is "I'd like to rent a room." (his own wish
 * said politely), "could you tell me about Whiterun?" is "Tell me about Whiterun." - but a request of HER ("will you be right
 * back", "can you help me?") is no line in which HE says what he will do ("I'll be right back.", "I'm here to help.", "I will help
 * you cure yourself."), and "can I kill the escaped prisoner?" asking leave is no "I can kill the escaped prisoner." (the line's
 * own modal statement asked back).
 */
function lrgDlgRequestFits(string $utter, array $e): bool
{
    $u = lrgDlgFoldTokens(lrgDlgBareWords($utter));
    $n = lrgDlgFoldTokens((string) ($e['text'] ?? ''));
    $fill = ['so', 'well', 'uh', 'um', 'er', 'and', 'ok', 'okay', 'hey', 'oh', 'sorry', 'then', 'but', 'now', 'excuse', 'me', 'please'];
    $skipN = ['yes', 'yeah', 'yep', 'fine', 'okay', 'ok', 'alright', 'sure', 'well', 'so', 'oh', 'then', 'very', 'good', 'all', 'right',
        'and', 'look', 'listen'];
    while ($u && in_array($u[0], $fill, true)) { array_shift($u); }
    while ($n && in_array($n[0], $skipN, true)) { array_shift($n); }
    if (count($u) < 2 || !$n) { return true; }
    [$aux, $sub] = [$u[0], $u[1]];
    if (in_array($sub, ['you', 'ya'], true)) {
        // a request of HER fits a line of HIS only when that line states what he NEEDS or wants from her ("can you let me into the
        // Pelagius Wing?" / "I need to get into the Pelagius Wing.", "will you help me fight Alduin?" / "I need your help to defeat
        // Alduin."); a line saying what HE will do is his commitment, not her favour ("will you be right back" / "I'll be right back.",
        // "can you help me?" / "I'm here to help.", "will you take me to Skuldafn if I set you free?" / "I'll set you free if ...")
        if (!in_array($n[0], ['i', 'im', 'ill', 'ive', 'id', 'we', 'weve', 'wed', 'well'], true)) { return true; }
        return (bool) preg_match('/^(i|we)(\'?d| would)? (need|want|like|must|have to|seek|require|wish)\b|^(i\'?m|i am|we\'?re|we are) '
            . '(looking|searching|hoping|here) (for|to find)\b|^(i\'?ve|i have|we\'?ve) come (for|to ask)\b/', implode(' ', $n))
            || (bool) preg_match('/^(id|wed) (like|love)\b|^(ive|weve) come (for|to ask)\b|^(im|were) (looking|searching) for\b/', implode(' ', $n));
    }
    return !(in_array($sub, ['i', 'we'], true) && isset($n[1]) && $n[0] === $sub && $n[1] === $aux);
}

/** [pt19h-safety] Is $w in $set, or the same word in another form (kill / killing, vampire / vampires - a shared stem of >= 4)? */
function lrgDlgStemIn(string $w, array $set): bool
{
    foreach ($set as $s) {
        $s = (string) $s;
        if ($s === $w) { return true; }
        $l = min(strlen($s), strlen($w));
        if ($l >= 4 && strncmp($s, $w, $l) === 0) { return true; }
    }
    return false;
}

/**
 * [pt19h-safety / G9] A NEAR-MISS: his words put a meaning word of their own IN THE PLACE of one of the line's - "close the door" /
 * "Open the door.", "sounds hard" / "Sounds easy.", "I lost the letter" / "I found this letter.", "I've finished that special
 * Solitude job" / "... Markarth job.", "... champion of the Stormcloaks" / "... champion of the Imperial Legion ...": a strict word
 * of his the line lacks (no STT echo of one of its words - lrgDlgOwnWords - and no other form of one) standing where a strict word
 * of the line he did not say stands, with the same neighbour on one side (or both at an edge). "I brought back Jurgen's horn" /
 * "I have the Horn of Jurgen Windcaller." swaps nothing: his own word stands in no place of the line's.
 */
function lrgDlgSubstitutes(string $words, array $e): bool
{
    static $frame = ['tell', 'about', 'know', 'think', 'like', 'want', 'need', 'got', 'get', 'see', 'say', 'ask', 'talk', 'hear',
        'heard', 'any', 'anything', 'something', 'going', 'go', 'have', 'give', 'take', 'come', 'make', 'please', 'yeah', 'okay', 'ok',
        'sure', 'well', 'just', 'really', 'right', 'alright', 'fine', 'yes', 'maybe'];
    $we = lrgPromptWords((string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? ''))), true);
    $wu = lrgPromptWords(lrgPromptNorm($words), true);
    $own = array_values(array_filter(array_diff(lrgDlgOwnWords($words, $e), $frame), static fn($w) => !lrgDlgStemIn((string) $w, $we)));
    $missing = array_values(array_filter(array_diff($we, $wu, $frame), static fn($w) => !lrgDlgStemIn((string) $w, $wu)));
    if (!$missing) { return false; }
    // (an OPPOSITE of a missing word counts even when the line carries its stem elsewhere: "... champion of the Stormcloaks" /
    // "... champion of the Imperial Legion and defeat Ulfric Stormcloak")
    foreach (array_diff($wu, $we, $frame) as $w) {
        foreach ($missing as $m) { if (lrgDlgOpposite((string) $w, (string) $m) && !in_array($w, $own, true)) { $own[] = (string) $w; } }
    }
    if (!$own) { return false; }
    $ht = lrgDlgTokens(lrgPromptNorm($words));
    $lt = lrgDlgTokens((string) ($e['norm'] ?? lrgPromptNorm((string) ($e['text'] ?? ''))));
    // (only a swap that CHANGES the line counts: an opposite - "close" / "open", "hard" / "easy", "lost" / "found" - or another NAME
    // where the line names one - "Solitude" / "Markarth"; "Consider Fort Hraagstad already taken" / "Consider that fort already
    // yours." and "the greatest mage" / "the best mage" swap nothing that matters: his own sentence still says the line)
    // (a NAME swap needs his word written as a name too - the STT capitalises names: "that special Solitude job"; "any beast dens" /
    // "Do any Animal Dens need cleared?" and "the high" / "the Eye!" are no names of his)
    $capsOf = static function (string $s): array {
        $out = [];
        if (preg_match_all('/(?<![.!?]\s)(?<!^)\b([A-Z][a-z\']{2,})/u', trim($s), $nm)) {
            foreach ($nm[1] as $n) { $out[strtolower(str_replace('\'', '', (string) $n))] = 1; }
        }
        return $out;
    };
    $names = $capsOf((string) ($e['text'] ?? ''));
    $hisNames = $capsOf($words);
    $at = static function (array $t, int $i): string { return $i < 0 || $i >= count($t) ? '#edge#' : (string) $t[$i]; };
    foreach ($ht as $i => $w) {
        if (!in_array($w, $own, true)) { continue; }
        foreach ($lt as $j => $m) {
            if (!in_array($m, $missing, true)) { continue; }
            $nameSwap = isset($names[str_replace('\'', '', (string) $m)]) && isset($hisNames[str_replace('\'', '', (string) $w)]);
            if (!$nameSwap && !lrgDlgOpposite((string) $w, (string) $m)) { continue; }
            if ($at($ht, $i - 1) === $at($lt, $j - 1) || $at($ht, $i + 1) === $at($lt, $j + 1)) { return true; }
        }
    }
    return false;
}

/** [pt19h-safety / G9] Two words of opposite sense (a near-miss swaps one for the other), plural / past forms folded. */
function lrgDlgOpposite(string $a, string $b): bool
{
    static $pairs = [['open', 'close'], ['opened', 'closed'], ['easy', 'hard'], ['easy', 'difficult'], ['lost', 'found'], ['lose', 'find'],
        // (no verbs of a hand-over - buy / sell, give / take, come / go: "want to buy this statue?" offers what "Can I sell this
        // statue to you?" says, from her side)
        ['alive', 'dead'], ['living', 'dead'], ['accept', 'refuse'], ['accept', 'decline'], ['agree', 'disagree'], ['win', 'lose'],
        ['won', 'lost'], ['stay', 'leave'], ['friend', 'enemy'], ['friends', 'enemies'], ['always', 'never'], ['love', 'hate'], ['good', 'bad'], ['right', 'wrong'],
        ['true', 'false'], ['safe', 'dangerous'], ['more', 'less'], ['before', 'after'], ['up', 'down'], ['first', 'last'],
        ['start', 'stop'], ['start', 'finish'], ['begin', 'end'], ['kill', 'spare'], ['killed', 'spared'], ['help', 'hurt'],
        ['join', 'leave'], ['remember', 'forget'], ['trust', 'distrust'], ['succeed', 'fail'], ['succeeded', 'failed'],
        ['success', 'failure'], ['guilty', 'innocent'], ['possible', 'impossible'], ['best', 'worst'], ['better', 'worse'],
        ['cheap', 'expensive'], ['rich', 'poor'], ['strong', 'weak'], ['early', 'late'], ['inside', 'outside'], ['above', 'below'],
        ['all', 'none'], ['everything', 'nothing'], ['everyone', 'nobody'], ['yes', 'no'], ['now', 'later'], ['legion', 'stormcloaks'],
        ['imperial', 'stormcloak'], ['imperials', 'stormcloaks'], ['empire', 'stormcloaks'], ['emperor', 'ulfric'], ['tullius', 'ulfric'],
        ['dawnguard', 'volkihar'], ['vampire', 'hunter'], ['master', 'slave'], ['north', 'south'], ['east', 'west'], ['left', 'right']];
    $a = strtolower(str_replace('\'', '', $a));
    $b = strtolower(str_replace('\'', '', $b));
    foreach ($pairs as [$x, $y]) {
        foreach ([[$a, $b], [$b, $a]] as [$p, $q]) {
            if (($p === $x || $p === $x . 's') && ($q === $y || $q === $y . 's')) { return true; }
        }
    }
    return false;
}

/**
 * [pt19h-safety / G8] A REAL BACK-OUT LINE - the only line LEAVE may click: class back, no effect of its own (not a commit, not
 * scripted - an Invisible Continue included, no check, no price, crit 0, no walk-out target), and a back-out phrase LEADS one
 * of its sentences ("Never mind.", "Not yet. These things can't be rushed.", "I see. Never mind then.", "No, nothing. I'll just
 * be moving on."). A line whose class came from a word INSIDE it ("Nothing I couldn't handle.", "The Thalmor know nothing about
 * the dragons.", "I'm not sure I understand the terms.", "I did what had to be done. Nothing more.") is no way out, and neither
 * is a scripted one ("Forget it. I'll just open it myself." starts the fight): LEAVE falls to the engine cancel or the guard.
 */
function lrgDlgRealBackOut(array $e): bool
{
    if ((string) ($e['class'] ?? '') !== 'back') { return false; }
    if (!empty($e['commit']) || (int) ($e['scripted'] ?? 0) === 1 || (int) ($e['invis'] ?? 0) === 1 || (string) ($e['kind'] ?? '') !== ''
        || (int) ($e['cost'] ?? 0) > 0 || (int) ($e['crit'] ?? 0) >= 1 || (string) ($e['twat'] ?? '') !== '') { return false; }
    $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', (string) ($e['text'] ?? '')));
    foreach (preg_split('/(?<=[.!?])\s+|\.{2,}\s*|\x{2026}\s*/u', $txt) ?: [] as $s) {
        if (preg_match('/^\W*(?:(?:actually|well|oh|hmm|uh|um|ah|no|nah|so|then|fine|okay|ok|alright|hold on|i see|on second thought)\W+){0,2}'
            . '(?:never ?mind|nevermind|forget (?:it|i|that|about it)|not (?:yet|now|right now|today|this time)|maybe (?:later|another time|'
            . 'some other time)|another time|some other time|on second thought|nothing(?:\W*$|\W*[.,!;]| for now| right now| else)|'
            . 'no,? thanks|no,? thank you|i(?:\'ll| will) come back|i(?:\'ll| will) (?:think|be back|pass)|maybe i(?:\'ll| will) pass|'
            . 'i need (?:more |some )?time|let me think|i(?:\'m| am) not sure\W*$|good ?bye|farewell|i(?:\'ll| will) be (?:going|on my way)|'
            . 'i (?:have|need|must|should) (?:to )?go\b)/iu', $s)) {
            return true;
        }
    }
    return false;
}

/**
 * [pt19 v1.0 / research/pt19c-language.md G1] NEGATION PARITY. `not` / `no` are scoring stop words and "don't" splits into
 * "don", so "I'm not ready to learn" scores 1.000 against Arngeir's commit "I'm ready to learn". A shared STRICT word is
 * negated on a side when every occurrence has a negator within the 6 tokens before it inside its clause (a negator followed
 * by a pronoun is question inversion - "don't you", "isn't it" - and does not count). Used by the explicit test, the
 * fast pick on the entry that would be clicked and the single-entry content steps; never by an assent, a slot or an
 * enlistment line (they have their own guards).
 * [pt19c fixer / adversarial QA @negation, @adv_neg_subject, @adv_neg_plain] PER-WORD PARITY: clash when >= half the shared
 * words are negated on ONE side only. The old test ("the entry has no negator anywhere") let the line's own "don't" over
 * another clause switch the guard off ("I don't want to join the Legion" clicked "I don't want to sit idly by ... I want to
 * join the Legion"). A NEGATED PREDICATE covers its subject too: a shared word up to 5 tokens BEFORE an auxiliary negator
 * of its clause ("hasn't", "don't", "is not", "did not") is negated ("the Dark Brotherhood hasn't come" is no "The Dark
 * Brotherhood has come"). And the negating pronouns and refusal verbs count (nobody, no one, nothing, nowhere, refuse,
 * decline): "nobody told me to see you" is no "I was told to come see you.", "I refuse your test" no "About that test...".
 */
function lrgDlgNegationClash(string $utter, array $e): bool
{
    // [pt19h r2 / safety P7] the STT's split adverbs ("i all most got killed")
    $utter = (string) preg_replace(['/\ball most\b/i', '/\bnear ly\b/i'], ['almost', 'nearly'], $utter);
    // [pt19h r2 / grading P6] two QUESTIONS: a negated auxiliary asks the same as the plain one ("won't you buy it" / "You sure you won't buy
    // it?", "isn't the dragon dead?" / "Is the dragon dead?") - only not / no / never count between them
    $bothQ = lrgDlgIsQuestion($utter) && lrgDlgEntryIsQuestion($e);
    $u = lrgDlgFoldTokens($utter);
    $n = lrgDlgFoldTokens((string) ($e['text'] ?? $e['norm'] ?? ''));
    if (!$u || !$n) { return false; }
    $stems = ['don', 'won', 'isn', 'aren', 'wasn', 'didn', 'doesn', 'haven', 'shouldn', 'wouldn', 'couldn', 'ain', 'can'];
    // [pt19h-safety / G9, G10] + the adverbs that deny their predicate: "I almost ran out of scrolls" did not run out ("I ran out of
    // scrolls." is the line), and neither did "I nearly" / "hardly" / "barely" / "scarcely"
    $neg = array_merge(LRG_DLG_NEG, ['nobody', 'noone', 'nothing', 'nowhere', 'refuse', 'refused', 'refusing', 'decline', 'declined',
        'almost', 'nearly', 'hardly', 'barely', 'scarcely']);
    // an auxiliary negator: the predicate of its clause is negated, and with it the subject before it
    $auxNeg = ['dont', 'doesnt', 'didnt', 'wont', 'cant', 'cannot', 'isnt', 'arent', 'wasnt', 'werent', 'havent', 'hasnt',
        'shouldnt', 'wouldnt', 'couldnt', 'aint'];
    if ($bothQ) { $neg = array_values(array_diff($neg, $auxNeg)); $auxNeg = []; }
    $shared = array_values(array_diff(array_intersect(lrgPromptWords(lrgPromptNorm($utter), true),
        lrgPromptWords(lrgPromptNorm((string) ($e['text'] ?? $e['norm'] ?? '')), true)), $stems, $neg));
    if (!$shared) { return false; }
    $pron = ['you', 'we', 'i', 'he', 'she', 'they', 'there', 'that', 'this', 'it'];
    $aux = ['is', 'are', 'was', 'were', 'am', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should',
        'must', 'shall', 'may', 'might', 'im', 'its', 'thats'];
    // (the idioms a negator opens negate nothing of the line: "there's no point earning all that gold", "no doubt", "not only")
    $idiom = ['point', 'use', 'sense', 'doubt', 'matter', 'problem', 'problems', 'worries', 'only', 'but', 'less'];
    // [pt19h-safety / G10] ... unless the idiom's word IS the word judged: "that makes no sense" negates "Makes sense."
    $isNeg = static function (array $tok, int $k, string $w = '') use ($neg, $pron, $idiom): bool {
        $nx = (string) ($tok[$k + 1] ?? '');
        return in_array($tok[$k], $neg, true) && !in_array($nx, $pron, true) && ($nx === $w || !in_array($nx, $idiom, true));
    };
    $negated = static function (array $tok, string $raw, string $w) use ($isNeg, $auxNeg, $aux, $pron): bool {
        // (the language brief's clauses: a sentence end splits too - "I have news. It isn't good." negates no news)
        // [pt19h r2 / safety P22] ... and "but" opens a clause ("I almost gave up but I found your crystal")
        $stops = lrgDlgClauseStarts((string) preg_replace(['/[.!?\x{2026}]+(?=\s|$)/u', '/\bbut\b/i'], [',', ', but'], $raw));
        $seen = false;
        foreach ($tok as $i => $t) {
            if ($t !== $w) { continue; }
            $seen = true;
            $from = max(0, $i - 6);
            $to = count($tok);
            foreach ($stops as $s) {
                if ((int) $s > $from && (int) $s <= $i) { $from = (int) $s; }
                if ((int) $s > $i && (int) $s < $to) { $to = (int) $s; }
            }
            // [pt19h-safety / G10] NEGATION PARITY: two negators cancel ("I don't want to refuse your gift" accepts it - it
            // clicked "I don't want to become a vampire. I refuse your gift."); a leading interjection no / nope / nah agrees
            // with the negator after it ("no, I don't want that") and alone still negates, as before
            // (a "no" right after a negated auxiliary is the STT's "know" or a colloquial echo - "I don't no any ward spells" - never a
            // second negator; almost / nearly / hardly / barely deny their predicate - "I almost ran out of scrolls" - never a
            // quantity: "not nearly enough", "almost all")
            $cnt = 0;
            $lead = false;
            $adv = ['almost', 'nearly', 'hardly', 'barely', 'scarcely'];
            for ($k = $from; $k < $i; $k++) {
                if (!$isNeg($tok, $k, $w)) { continue; }
                if ($k === 0 && in_array($tok[$k], ['no', 'nope', 'nah'], true)) { $lead = true; continue; }
                if ($tok[$k] === 'no' && $k > 0 && in_array((string) $tok[$k - 1], $auxNeg, true)) { continue; }
                // ("no" + a noun denies that noun only when a negated verb follows it: "he's done me no wrong i won't kill him" - the
                // STT's lost full stop - has ONE negator of "kill"; "there's no way I'm joining" keeps its "no")
                if ($tok[$k] === 'no' && $k > 0 && $k !== $i - 1 && array_intersect(array_slice($tok, $k + 1, $i - $k - 1), $auxNeg)) { continue; }
                if (in_array($tok[$k], $adv, true) && in_array((string) ($tok[$k + 1] ?? ''), ['enough', 'all', 'every', 'everyone',
                    'everything', 'as', 'so', 'always'], true)) { continue; }
                // [pt19h r2 / safety P22] almost / nearly deny the NEXT verb only; barely / hardly / scarcely + an achievement ("I barely
                // killed them both", "I barely made it out") assert it - they negate before know / any / ever / a stative word alone
                if (in_array($tok[$k], $adv, true)) {
                    if ($i - $k > 2) { continue; }
                    if (in_array($tok[$k], ['hardly', 'barely', 'scarcely'], true) && !in_array((string) ($tok[$k + 1] ?? ''), ['know', 'knew', 'knows',
                        'any', 'anything', 'anyone', 'ever', 'enough', 'a', 'the', 'recognize', 'recognise', 'remember', 'believe', 'think', 'seem',
                        'seems', 'see', 'hear', 'heard', 'call', 'need', 'worth', 'matters', 'counts'], true)) { continue; }
                }
                $cnt++;
            }
            $isn = $cnt % 2 === 1 || ($cnt === 0 && $lead);
            if ($cnt >= 2) { if (!$isn) { return false; } continue; }
            // the subject of a negated predicate: an auxiliary negator (or aux + not / never) within 5 tokens AFTER it
            for ($k = $i + 1; !$isn && $k < min($to, $i + 6); $k++) {
                $nx = (string) ($tok[$k + 1] ?? '');
                if (in_array($nx, $pron, true)) { continue; }
                // [pt19h r2 / extended MGRArniel04.courier] ... and a bare "never" right after it ("the courier never arrived" is "the courier
                // didn't come" - parity across the two negators, no clash)
                if (in_array($tok[$k], $auxNeg, true) || $tok[$k] === 'never'
                    || ($tok[$k] === 'not' && $k > 0 && in_array((string) $tok[$k - 1], $aux, true))) { $isn = true; }
            }
            if (!$isn) { return false; }
        }
        return $seen;
    };
    $mismatch = 0;
    foreach ($shared as $w) {
        if ($negated($u, $utter, $w) !== $negated($n, (string) ($e['text'] ?? ''), $w)) { $mismatch++; }
    }
    return $mismatch >= (int) ceil(count($shared) / 2);
}

/**
 * [pt19 v1.0 / research/pt19c-language.md G5, spec S4.3 (c)] A NAMED-CHOICE list: >= 3 visible entries of equal token
 * length that differ in exactly ONE position ("I'd like a sword / a dagger / a waraxe ..."): the differing tokens are its
 * slot names. Returns ['entry' => e, 'slot' => name, 'clean' => bool] when exactly one slot is named, not negated; `clean`
 * = nothing else was said but fillers ("a sword, please", "the sword", "I'll take the sword"), which is what may stand
 * for the explicit line on a commit - "the sword is heavy" names it and does not ask for it.
 */
function lrgDlgNamedChoice(array $entries, string $utter): ?array
{
    $vis = array_values(array_filter($entries, static fn($e) => (string) ($e['class'] ?? '') !== 'hidden'));
    if (count($vis) < 3) { return null; }
    $toks = [];
    foreach ($vis as $e) { $toks[] = lrgDlgFoldTokens((string) ($e['norm'] ?? '')); }
    $len = count($toks[0]);
    if ($len < 2) { return null; }
    foreach ($toks as $t) { if (count($t) !== $len) { return null; } }
    $pos = -1;
    for ($p = 0; $p < $len; $p++) {
        $col = array_unique(array_column($toks, $p));
        if (count($col) === 1) { continue; }
        if ($pos !== -1 || count($col) !== count($toks)) { return null; }
        $pos = $p;
    }
    if ($pos < 0) { return null; }
    $hay = lrgDlgFoldTokens($utter);
    if (!$hay) { return null; }
    $stops = lrgDlgClauseStarts($utter);
    $hit = [];
    foreach ($toks as $i => $t) {
        $at = array_search($t[$pos], $hay, true);
        if ($at === false || lrgDlgNegatedAt($hay, (int) $at, $stops)) { continue; }
        $hit[] = $i;
    }
    if (count($hit) !== 1) { return null; }
    $slot = (string) $toks[$hit[0]][$pos];
    $fill = ['a', 'an', 'the', 'please', 'one', 'ill', 'take', 'i', 'want', 'like', 'id', 'give', 'me', 'that', 'this', 'just',
        'then', 'okay', 'ok', 'yes', 'yeah', 'will', 'have', 'would',
        // [pt19c fixer / walkthrough M5, @filler_choice] the owner's leading fillers (A2 / G7): "uh, the sword"
        'uh', 'um', 'er', 'erm', 'ah', 'oh', 'hmm', 'mm', 'well', 'so', 'hey'];
    $rest = array_diff($hay, [$slot], $fill);
    return ['entry' => $vis[$hit[0]], 'slot' => $slot, 'clean' => !$rest];
}

/**
 * [pt19 v1.0 / S4.3] THE PLAYER'S SENTENCE IS STEP ONE. True when his words themselves are the confirmation of this
 * commit: (a) the matcher lands on THIS entry at >= said_line_score (0.70) with margin >= said_line_margin (0.25) and
 * min(said_line_tokens 4, the entry's tokens) tokens - an exact 1.0 needs 3; a first-word STT clip ("like a sword") lowers
 * the floor by one (G3); never against negation parity (G1); (b) the enlistment's exact join line ($mode faction); (c) an
 * exact slot of a price list or a named-choice list, said cleanly, with >= slot_tokens (2) tokens. Never for meta,
 * never_auto, crit 2 / arrest, a scoff-first failure variant, follower dismiss / home, or a price >= min_gold or >= a
 * quarter of the purse. $t needs npc, speech, entries (+tail), crit, arrest, facts.
 */
function lrgDlgExplicit(array $t, array $e, string $utter, string $mode, array $st): bool
{
    if (empty($t['speech']) || trim($utter) === '') { return false; }
    if ((string) ($e['class'] ?? '') === 'meta' || !empty($e['fcommit'])) { return false; }
    $ov = lrgDlgOverrideFor('entries', $e);
    if ($ov && !empty($ov['never_auto'])) { return false; }
    if ((int) ($e['crit'] ?? 0) === 2 || (int) ($t['crit'] ?? 0) === 2 || (string) ($t['arrest'] ?? '') !== '') { return false; }
    if (lrgDlgScoffFirst($t, $e, $st)) { return false; }
    $cost = (int) ($e['cost'] ?? 0);
    $pg = (int) (($t['facts'] ?? [])['pg'] ?? 0);
    if ($cost > 0 && ($cost >= (int) lrgDlgCfg('confirm.min_gold', 100)
        || ($pg > 0 && $cost >= (float) lrgDlgCfg('confirm.gold_fraction', 0.25) * $pg))) { return false; }
    $utter = lrgDlgSttFold($utter, array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])));   // G2
    $tok = count(lrgDlgTokens(lrgPromptNorm($utter)));
    $slotTok = (int) lrgDlgCfg('confirm.slot_tokens', 2);
    $npc = (string) ($t['npc'] ?? '');
    // [pt19c fixer / adversarial QA @adv_bargain] a bargain or a condition is not the plain line, on EVERY path - the
    // enlistment's exact line included ("I'll join the Legion if you pay me five hundred septims"): she asks, quoting it
    if (lrgDlgBargains($utter, $e)) { return false; }
    // [pt19h-safety / G1, G11, G10] his words ABOUT the line, on every path - the enlistment's exact line included: what he said
    // AROUND it (lrgDlgQuoteQualm: "not now, <line> later", "wait, <line>?", "is it true that <line>", "<line>, couldn't I"), a
    // refusal of his own (a line opening with the SAME refusal words is his choosing it; "not now, no, Onmund. ... instead of me
    // later" handed over the Arch-Mage title), a hedge ("maybe", "I guess")
    $eText = (string) ($e['text'] ?? '');
    $qq = lrgDlgQuoteQualm($utter, $e);
    // (a line that itself refuses agrees with his refusal - "no, just some wolves" / "No. Some wolves, but no dogs.", "I refuse your
    // gift", "not this time, I'd rather not take it" - unless his refusal is a DEFERRAL the line does not carry)
    $handLine = (string) ($e['hand'] ?? '') !== '' || (bool) preg_match('/[\(\[]\s*(?:give|show|hand over|hand|present|offer|return)\b/i', $eText);
    $refuses = $qq['qualm'] !== ''
        || (lrgDlgRefuses($utter) && (!lrgDlgIsBackOut($eText, true)
            || (preg_match(LRG_DLG_DEFER_RE, strtolower($utter)) && !preg_match(LRG_DLG_DEFER_RE, strtolower($eText)))))
        || (lrgDlgHedges($utter) && !lrgDlgHedges($eText)) || lrgDlgDefers($utter, $e)
        // [pt19h r2 / G16, grading P4] he KEEPS what a hand-over line gives away ("I'm keeping the amulet", "you can't have it")
        || ($handLine && lrgDlgKeepsIt($utter));
    // [pt19c fixer / @adv_facask_q] ... and a question WORD asks about the enlistment, it does not ask for it ("what does it take
    // to join the Companions?"); a request shaped as a question ("can I join the Companions?") still is the ask
    // [pt19h-safety / G1, G5, G11] ... and so does any other question that is no request of his own ("can anyone join the
    // Dawnguard?", "wait, I'm here to join the Dawnguard?") or another question than a join line that asks ("can anyone join the
    // Companions?" / "Can I join the Companions?"), and so does his refusal ("no, I'm here to join the Dawnguard")
    if ($mode === 'faction') {
        if ($refuses) { return false; }
        // (the join line with its first word clipped by the STT - "would like to join the companions" - is no question of his)
        $hisQ = lrgDlgIsQuestion($utter) && !($qq['kind'] === 'frag' && $qq['qualm'] === '');
        if ($hisQ && (lrgDlgEntryIsQuestion($e) ? !lrgDlgQuestionsSame($utter, $e)
            : (!lrgDlgIsRequest($utter) || !lrgDlgRequestFits($utter, $e)))) { return false; }
        return !$hisQ || strncmp(lrgDlgQuestionKind($utter), 'wh:', 3) !== 0;
    }
    // [pt19c fixer] a refusal never says the line (unless the line itself is refusal-shaped - saying it is choosing it)
    // [pt19h r2 / safety P9] ... judged BEFORE the slot shortcut: "not now, three nights later", "no, three nights", "I guess three nights"
    if ($refuses) { return false; }
    // the price-list / named-choice slot said cleanly (S4.3 c) - as a statement or a polite request ("can I rent a room for one night" names
    // the "1 day" slot of the list it opened); a question about it ("three nights? says who") falls to the question tests below
    if ($mode === 'slot' && $tok >= $slotTok && (!lrgDlgIsQuestion($utter) || lrgDlgIsRequest($utter))) { return true; }
    // [pt19c-A fix 1 / language review 2] a QUESTION to her never stands for a statement line ("what is a battleaxe" is no "I'd
    // like a battleaxe.") - S4.5 step 0's shape rule, here too; a question line said as a question still can be
    if (lrgDlgIsQuestion($utter) && !lrgDlgEntryIsQuestion($e)) { return false; }
    // [pt19c fixer / @adv_frame] ... and only when it is the SAME question: "what would change your mind?" is no "Will this change
    // your mind?" (the bribe), "who gives you your orders?" no "What are your orders?"
    if (!lrgDlgQuestionsAgree($utter, $e)) { return false; }
    // [pt19h-safety / G9] ... strictly: a commit line's question word or subject ("who are the Blood Horkers" / "So where are the
    // Blood Horkers?", "is there a way out" / "Do you know the way out of here?")
    if (lrgDlgIsQuestion($utter) && lrgDlgEntryIsQuestion($e) && !lrgDlgQuestionsSame($utter, $e)) { return false; }
    // [pt19h r2 / G16] a hand-over said in his own words (lrgDlgHandsOver: the object inside a giving frame, never the object alone,
    // never a keep) is the line, on every path - his "here's the journal" on the fast path and her key on it alike
    if ((string) ($e['hand'] ?? '') !== '' && lrgDlgHandsOver($utter, $e)) { return !lrgDlgNegationClash($utter, $e); }
    if ($mode === 'hand') { return false; }
    // [pt19h-safety / G9] a statement of his is no question line ("I understand" / "Understand? How?"), and a line that commits in an
    // opening assent is not said by its question alone ("what's the passphrase?" / "Agreed. What's the passphrase?")
    if (lrgDlgDeclares($utter, $e) || lrgDlgSkipsAssent($utter, $e)) { return false; }
    $pool = array_values(array_filter(array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])),
        static fn($x) => (string) ($x['class'] ?? '') !== 'hidden'));
    $same = static fn(array $x): bool => (int) ($x['pos'] ?? -1) === (int) ($e['pos'] ?? -2) && (string) ($x['norm'] ?? '') === (string) ($e['norm'] ?? '');
    if ($tok >= $slotTok) {
        $nc = lrgDlgNamedChoice($pool, $utter);
        // a question names nothing it asks for ("the sword?" - language review 2): she asks, quoting it
        if ($nc && !empty($nc['clean']) && $same((array) $nc['entry']) && !lrgDlgIsQuestion($utter)) { return true; }
        $sl = lrgDlgServicesOn((string) ($t['npc'] ?? '')) ? lrgDlgServiceSlot($pool, $utter, '', (string) ($t['npc'] ?? '')) : null;
        if (is_array($sl) && (string) $sl['mode'] === 'pick' && $same((array) $sl['entry'])) { return true; }
    }
    // [pt19c fixer / FRICTION] his leading fillers and his form of address are no part of the line: "well, I need to talk to
    // you", "hey, who are you?", "what else can I help you with, my jarl" are said plainly (G7, the vocative)
    $bare = lrgDlgBareWords($utter, $npc, (string) ($e['norm'] ?? ''));
    $tokB = count(lrgDlgTokens(lrgPromptNorm($bare)));
    $m = lrgDlgMatchText($bare, $pool);
    if ($m === null || !empty($m['short']) || !$same((array) $pool[$m['i']])) { return false; }
    $eff = (float) $m['eff'];
    $margin = $eff - ((float) $m['score'] - (float) $m['margin']);
    $exact = (string) $m['tier'] === 'exact';
    $et = lrgDlgTokens((string) ($e['norm'] ?? ''));
    // [pt19c-A fix 1 / ai review] a one-token entry ("Yes.", "No.", "Well?", "So...") or one with no strict meaning word is
    // explicit only when he said EXACTLY it: a containment or an F1 hit on such a line is a fragment of some other sentence
    if (!$exact && (count($et) < 2 || !lrgPromptWords((string) ($e['norm'] ?? ''), true))) { return false; }
    // [pt19c-A fix 1 / game review 8] the margin guards a PARAPHRASE between near-twin lines; his exact line ("I will kill
    // him for you." beside "I will spare him.", margin 0.217) is itself the confirmation - any margin at all will do
    if ($eff < (float) lrgDlgCfg('confirm.said_line_score', 0.70)
        || ($exact ? $margin <= 0.0 : $margin < (float) lrgDlgCfg('confirm.said_line_margin', 0.25))) {
        return false;
    }
    $need = min((int) lrgDlgCfg('confirm.said_line_tokens', 4), count($et));
    if ((float) $m['score'] >= 1.0) { $need = max($need, 3); }
    if ($eff >= 0.85 && count($et) >= 3 && implode(' ', array_slice($et, 1)) === lrgPromptNorm($bare)) {
        $need = min($need, count($et) - 1);
    }
    if ($tokB < $need) { return false; }
    // [pt19c fixer / adversarial QA @adv_frame] HIS words must be the line's: unless he said the line itself (exactly, or inside
    // a longer sentence of his), his words of his own - not the line's, no filler, no STT echo of a word the line has and he
    // did not say ("i'm ready to lauren", "the horn" / "the born") - may be at most 1 - confirm.said_line_precision of his
    // meaning words: "I need to talk to Farengar" is no "I need to talk to you.", "I need to talk to you about the civil war"
    // no "... about Helgen." - such a paraphrase asks once
    $inside = lrgDlgTokenRun(lrgDlgTokens(lrgPromptNorm($bare)), $et);
    $wuB = lrgPromptWords(lrgPromptNorm($bare), true);
    if (!$exact && !$inside && $wuB
        && count(lrgDlgOwnWords($bare, $e)) / count($wuB) > 1.0 - (float) lrgDlgCfg('confirm.said_line_precision', 0.75)) {
        return false;
    }
    if (!$exact && !$inside) {
        $weS = lrgPromptWords((string) ($e['norm'] ?? ''), true);
        // [pt19h r2 / safety P21] the line's own ASSENT lead is no content: "I'll do it" says the whole of "Fine, I'll do it."
        $eLead = lrgDlgLeadPhraseRaw((string) ($e['text'] ?? ''), array_merge((array) lrgDlgCfg('confirm.assent_words', []), ['agreed', 'deal', 'fine', 'understood']));
        if ($eLead !== null) { $weS = array_values(array_diff($weS, lrgDlgTokens(lrgPromptNorm((string) $eLead['phrase'])))); }
        $bt = lrgDlgTokens(lrgPromptNorm($bare));
        // [pt19h-safety / G9] his words a FRAGMENT of a line of several clauses carry it only with at least half of its strict words
        // (S4.5's $hit cover): "I made a mistake" is no "I made a mistake. I want to be a Stormcloak. The crown belongs to you.",
        // "tell me where it is" no "Tell me where it is, or else.", "yes she wants you" no "Yes. She wants you to leave her alone.";
        // "I don't want to become a vampire" still says "I don't want to become a vampire. I refuse your gift.", and the head of a
        // one-clause line still says it ("I'm just a traveler")
        $clauses = count(array_filter(preg_split('/[.,;:!?\x{2026}]+|\s[-\x{2013}\x{2014}]+\s/u', (string) ($e['text'] ?? '')) ?: [],
            static fn($c) => trim((string) $c) !== ''));
        if ($weS && $clauses >= 2 && count($bt) < count($et) && lrgDlgTokenRun($et, $bt)
            && count(array_intersect($wuB, $weS)) / count($weS) < 0.4) {
            return false;
        }
        // [pt19h-safety / G9] a question with a word of its own asks another thing - only a spelling slip of one of the line's words
        // is allowed on a commit, no sound-alike ("who's behind the door" / "Who's behind all this?", "where do I sign up for the
        // Companions?" / "Killing vampires? Where do I sign up?"; "are we reddy" still says "Are we ready?")
        // (the frame and filler words of a question are no words of its own - "do you KNOW how I might find out" / "Do you have any
        // idea how I might find out?", "WOULD you LIKE me to break anything else", "even after ALL I did"; a misheard NAME may sound
        // like the line's - "what did mailing do" / "What did Malyn do?" - and a word may be spelt or heard one letter off: "sun" /
        // "son", "taste" / "test")
        static $qFrame = ['tell', 'about', 'know', 'think', 'like', 'want', 'need', 'got', 'get', 'see', 'say', 'ask', 'talk', 'hear',
            'heard', 'any', 'anything', 'something', 'going', 'go', 'have', 'give', 'take', 'come', 'make', 'really', 'actually', 'even',
            'still', 'just', 'all', 'some', 'idea', 'please', 'sure', 'exactly', 'ever', 'again', 'mean', 'maybe', 'whose', 'whom',
            // [pt19h r2 / safety P2, P14] the auxiliaries and the informal forms are no words of his own
            'am', 'is', 'are', 'was', 'were', 'be', 'been', 'will', 'would', 'did', 'do', 'does', 'can', 'could', 'shall', 'should',
            'may', 'might', 'has', 'had', 'wanna', 'gonna', 'gotta', 'whos', 'whats', 'wheres', 'hows', 'im', 'ill', 'ive', 'id',
            'youre', 'thats', 'its', 'or', 'not'];
        $namesL = [];
        if (preg_match_all('/\b([A-Z][a-z\']{2,})/u', (string) ($e['text'] ?? ''), $nmL)) {
            foreach ($nmL[1] as $n) { $namesL[] = strtolower(str_replace('\'', '', (string) $n)); }
        }
        $slip = static function (string $w) use ($weS, $namesL): bool {
            if (lrgDlgStemIn($w, $weS)) { return true; }
            foreach ($weS as $m) {
                $m = (string) $m;
                $l = max(strlen($w), strlen($m));
                if ($l >= 3 && levenshtein($w, $m) <= max(1, intdiv($l, 3))) { return true; }
                if ($l >= 4 && metaphone($w) !== '' && metaphone($w) === metaphone($m)) { return true; }
                if (in_array($m, $namesL, true) && lrgDlgSoundsLike($w, $m)) { return true; }
            }
            return false;
        };
        // [pt19h r2 / safety P2, P14] his OWN words as lrgDlgOwnWords reads them: no filler, no STT echo of a line word he did not say
        // ("whos the target", "do you think this wood help", "I am looking for Miraak. Do you know him?" say their lines)
        if (lrgDlgIsQuestion($utter) && array_filter(array_diff($wuB, $qFrame), static fn($w) => !$slip((string) $w))) { return false; }
        // [pt19h-safety / G9] a near-miss: a word of his where the line has another ("I want to fight as champion of the
        // Stormcloaks" / "I want to fight as champion of the Imperial Legion and defeat Ulfric ...")
        if (lrgDlgSubstitutes($bare, $e)) { return false; }
    }
    return !lrgDlgNegationClash($utter, $e);
}

/**
 * [pt19 v1.0 / S4.5] SINGLE-ENTRY RELEASE, steps 0-5 in order. $e = the ONE visible entry of a closed, indexed layer;
 * $utter = a player sentence NEWER than the last click (the caller's test). Returns ['release' => bool, 'refused' =>
 * bool (step 0: nothing, and no auto-advance), 'shape' => bool (refused because a question to HER met a non-question
 * entry - that re-arms the breath, S4.6), 'step' => the step that decided].
 * SCORE = lrgDlgMatchText as shipped; meaning / shared / foreign / precision = the STRICT list.
 */
function lrgDlgSingleEntryRelease(array $e, string $utter): array
{
    $out = ['release' => false, 'refused' => false, 'shape' => false, 'step' => '6 none'];
    if (empty(lrgDlgCfg('confirm.single_entry', true))) { $out['step'] = 'off'; return $out; }
    if (lrgPromptNorm($utter) === '') { $out['step'] = 'no words'; return $out; }
    $utter = lrgDlgSttFold($utter, [$e]);   // G2: "all hail the storm cloaks" says "Stormcloaks"
    $commit = !empty($e['commit']);
    $scripted = (int) ($e['scripted'] ?? 0) === 1;
    $m = lrgDlgMatchText($utter, [$e]);
    $score = $m === null ? 0.0 : (float) $m['eff'];
    // 0. refusal, then shape. [pt19c-A fix 1 / language review 3] CONTENT OUTRANKS THE REFUSAL REGEX when the line itself is
    // refusal-shaped ("I don't want to sit idly by ... I want to join the Legion", "No, but I know how to find out. I need an
    // Elder Scroll.") or his words hit it at the step-2 floor: saying the only line is choosing it
    // ... and his words must carry the LINE, not just open it: >= half of its strict meaning words ("I don't want to" is a
    // prefix of Tullius's line and refuses; "I don't want to sit idly by, I want to join the Legion" says it)
    $we0 = lrgPromptWords((string) ($e['norm'] ?? ''), true);
    $cover = $we0 ? count(array_intersect(lrgPromptWords(lrgPromptNorm($utter), true), $we0)) / count($we0)
        : ($m !== null && (string) $m['tier'] === 'exact' ? 1.0 : 0.0);
    $hit = $score >= (float) lrgDlgCfg('confirm.single_entry_exact', 0.85) && $cover >= 0.5 && !lrgDlgNegationClash($utter, $e);
    // [pt19h-safety / G1, G11, G5] THE $hit EXEMPTION HOLDS ONLY FOR THE LINE'S OWN WORDS. His sentence carrying the line whole
    // is judged by what he said AROUND it (lrgDlgQuoteQualm): "not now, <line> later", "no, <line>", "I'm not sure, <line>" refuse
    // it, "wait, <line>?", "is it true that <line>", "<line>?" ask it back - 998 deferrals and 333 echo questions released commit
    // singles here on the containment alone. A paraphrase hit (no whole quote) keeps his refusal unless the line itself refuses,
    // and his question unless the line asks too ("have you brought the fragment?" is no "I've brought the fragment.")
    $qq = lrgDlgQuoteQualm($utter, $e);
    $qual = ($qq['qualm'] === 'hedges' && !lrgDlgProtected($e)) ? '' : (string) $qq['qualm'];
    // (a deferral anywhere in his words - "can you read the Elder Scroll later?" - releases no protected single now)
    if ($qual === '' && lrgDlgProtected($e) && lrgDlgDefers($utter, $e)) { $qual = 'refuses'; }
    $eText = (string) ($e['text'] ?? '');
    // [pt19h r2 / G16, grading P4] he KEEPS what the single hands over ("I'm keeping the amulet" / "I found this amulet. (Give Delvin the
    // amulet)"): nothing, and no auto-advance
    if (((string) ($e['hand'] ?? '') !== '' || preg_match('/[\(\[]\s*(?:give|show|hand over|hand|present|offer|return)\b/i', $eText)) && lrgDlgKeepsIt($utter)) {
        return ['release' => false, 'refused' => true, 'shape' => false, 'step' => '0 refusal (he keeps what the line hands over)'];
    }
    // (a line with a question inside - "So what's the problem? I'm sure he'll pay you..." - asked back in its own words is its own
    // question: "what's the problem, he'll pay you")
    $ownQ = strpos($eText, '?') !== false && !lrgDlgOwnWords($utter, $e);
    if ($hit && ($qual !== '' || ($qq['kind'] === 'none' && ((lrgDlgRefuses($utter) && !lrgDlgIsBackOut($eText, true))
        || (lrgDlgIsQuestion($utter) && !lrgDlgEntryIsQuestion($e) && !$ownQ))))) {
        $hit = false;
    }
    // [pt19h r2 / safety P6] a HEDGE around a commit single parks it (the gate: she asks, quoting it), never nothing
    if ($qual === 'hedges' && $commit && !$hit) {
        return ['release' => false, 'refused' => false, 'shape' => false, 'step' => '0 hedge around the commit line - the model asks, quoting it'];
    }
    if ((lrgDlgRefuses($utter) || in_array($qual, ['refuses', 'hedges'], true)) && !$hit) {
        // [pt19h-safety / G1] ... and the line opens with HIS refusal words only when it opens like his sentence: "never mind"
        // against "I don't understand what's going on." is his refusal - refused, and the breath never says the line for him
        if (!lrgDlgIsBackOut($eText, true) || $qual !== '' || !lrgDlgSameLead($utter, $e)) {
            return ['release' => false, 'refused' => true, 'shape' => false,
                'step' => '0 refusal' . ($qual !== '' ? ' (his words around the line ' . $qual . ')' : '')];
        }
        // the LINE opens with the same refusal words: nothing is released from them, and nothing is refused either - the
        // model's key decides (a commit single parks and she asks, quoting it)
        return ['release' => false, 'refused' => false, 'shape' => false, 'step' => '0 his words refuse, and so does the line itself - the model decides'];
    }
    $uq = lrgDlgIsQuestion($utter) || $qual === 'asks';
    $eq = lrgDlgEntryIsQuestion($e);
    // [pt19h-safety / G11] a question line asked back with words of his around it ("is it true that <question line>"): not the line
    if ($qual === 'asks' && $eq) {
        $out['step'] = '6 none (he asks about the line)';
        return $out;
    }
    // (the line said back as it stands is no question to her, whatever its lead-in: "So what's the problem? I'm sure...")
    if ($uq && !$eq && !$hit) {
        // [pt19c fixer / adversarial QA @adv_rearm] a question whose words are the LINE's own disputes it ("why would anyone apply
        // to the college?" against "I'm looking to apply to the college."): he argues with the line, he is not moving on - no
        // re-arm of the breath (refused, not shape); a question about something else still re-arms it
        $wq = lrgPromptWords(lrgPromptNorm(lrgDlgBareWords($utter)), true);
        $weq = lrgPromptWords((string) ($e['norm'] ?? ''), true);
        $own = $weq ? count(array_intersect($wq, $weq)) : 0;
        if ($own >= 1 && $own >= (int) ceil(count($weq) / 2)) {
            return ['release' => false, 'refused' => true, 'shape' => false, 'step' => '0 shape (his question disputes the line itself)'];
        }
        return ['release' => false, 'refused' => true, 'shape' => true, 'step' => '0 shape (a question to her)'];
    }
    // [pt19h r2 / grading P2] a line that must ALWAYS ask (never_auto, a follower commit) or one that pays >= confirm.min_gold is released
    // by no step 1-5: the fast path does nothing, the gate parks it and S4.4 releases it on his yes to her naming question (S4.10)
    $ovS = lrgDlgOverrideFor('entries', $e);
    if (($ovS && !empty($ovS['never_auto'])) || !empty($e['fcommit']) || (int) ($e['cost'] ?? 0) >= (int) lrgDlgCfg('confirm.min_gold', 100)) {
        $out['step'] = '6 none (never released by his words alone - the model asks, naming it)';
        return $out;
    }
    $ws = static fn(string $s): array => lrgPromptWords(lrgPromptNorm($s), true);
    $we = lrgPromptWords((string) ($e['norm'] ?? ''), true);
    // 1. a leading assent - and, on an unscripted single, a continuer said BARE ("go on", "and?", "then what"; ai review:
    // "and who are they?" / "then what do you want from me" lead with a continuer and are questions, not answers)
    $lead = lrgDlgLeadPhrase($utter, (array) lrgDlgCfg('confirm.assent_words', []));
    if ($lead === null && !$scripted) {
        $cont = lrgDlgLeadPhraseRaw($utter, array_merge((array) lrgDlgCfg('confirm.single_entry_extra', []),
            (array) lrgDlgCfg('auto_advance.continuer_words', [])));
        $fill = ['then', 'please', 'uh', 'um', 'er', 'so', 'now', 'on', 'already', 'ahead'];
        if ($cont !== null && !array_diff((array) $cont['tail'], $fill)) {
            return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '1 continuer'];
        }
    }
    if ($lead !== null) {
        $tail = (array) $lead['tail'];
        // [pt19h-orch / S4.10, G14] a bare "yes" / "okay" never PAYS: a line that hands over money ("Here's the gold." - Vex's
        // 1,000 septims, a fine, a buy-back) waits for the model, which asks once and names the sum (her key, or his own words
        // saying the payment, release it)
        if (lrgDlgPaysOut($e)) {
            return ['release' => false, 'refused' => false, 'shape' => false, 'step' => '1 assent on a payment - the model asks, naming the sum'];
        }
        if (!$tail || !$commit) { return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '1 assent']; }
        if (!array_diff($ws(implode(' ', (array) ($lead['raw_tail'] ?? $tail))), $we)) {
            return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '1 assent, nothing foreign'];
        }
    }
    // 2-5. content
    // [pt19c fixer / FRICTION] his leading fillers and his form of address cost no precision ("what else can I help you with, my
    // jarl" says the reward line); the vocative still never counts as a shared word
    $wu = $ws(lrgDlgBareWords($utter));
    $shared = array_values(array_intersect($wu, $we));
    $prec = $wu ? count($shared) / count($wu) : 1.0;
    $pmin = (float) lrgDlgCfg('confirm.single_entry_precision', 0.75);
    $clash = lrgDlgNegationClash($utter, $e);
    // [pt19c fixer / adversarial QA @single_neg] his NEGATED restatement of the only line ("I'm not looking to apply to the
    // college", "I'd never apply to the college") refuses it: nothing, and no auto-advance (S4.5 step 0 / S4.6)
    if ($clash && $shared) {
        return ['release' => false, 'refused' => true, 'shape' => false, 'step' => '0 refusal (his words negate the line)'];
    }
    $shapeEq = $uq === $eq;
    // [pt19c fixer / adversarial QA @adv_q_single] a QUESTION releases the line only when it is the line's own question, of the
    // same kind ("what does a bard do?" is no "Does that mean I'm a bard now?", "do you mind?" no "What do you have in mind?") and
    // with no word of its own ("what do you need a dragonstone for?" is no "What do you need?")
    if ($uq && $eq && !lrgDlgQuestionsAgree(lrgDlgBareWords($utter, '', (string) ($e['norm'] ?? '')), $e)) {
        $out['step'] = '6 none (' . round($score, 3) . ', a question of its own)';
        return $out;
    }
    // [pt19h-safety / G5, G9] ... and a PROTECTED single (a commit, a scripted line) needs the SAME question (lrgDlgQuestionsSame):
    // "what's on Alduin's Wall" is no "Where can we find Alduin's Wall?", "how are you doing?" no "How am I doing?" - a commit
    // single parks and she asks once, quoting it; a scripted one waits for the model
    $bareQ = lrgDlgBareWords($utter, '', (string) ($e['norm'] ?? ''));
    if ($uq && $eq && lrgDlgProtected($e) && ($commit || (strncmp(lrgDlgQuestionKind($bareQ), 'wh:', 3) === 0
        && strncmp(lrgDlgQuestionKind((string) ($e['text'] ?? ''), true), 'wh:', 3) === 0)) && !lrgDlgQuestionsSame($bareQ, $e)) {
        $out['step'] = '6 none (' . round($score, 3) . ', another question than the commit line)';
        return $out;
    }
    // [pt19h-safety / G9] a STATEMENT of his against a question line is no step-2 hit, whatever its F1: "I have a plan" / "What's
    // the plan?", "Thorald is alive" / "If Thorald is alive, where is he?" (an unscripted single still takes step 3)
    $declares = lrgDlgDeclares($utter, $e);
    // [pt19h-safety / G9] a NEAR-MISS - a word of his where the line has another - releases no scripted single: "sounds hard" /
    // "Sounds easy.", "I lost the letter" / "I found this letter."
    $subst = $scripted && $qq['kind'] !== 'whole' && lrgDlgSubstitutes(lrgDlgBareWords($utter), $e);
    // [pt19h-safety / G9] ... and a commit single that agrees in its opening sentence takes his question alone at no step
    if ($commit && $qq['kind'] !== 'whole' && lrgDlgSkipsAssent($utter, $e)) {
        $out['step'] = '6 none (' . round($score, 3) . ', the line agrees first and his words do not)';
        return $out;
    }
    if (!$clash && !$declares && !$subst && $score >= (float) lrgDlgCfg('confirm.single_entry_exact', 0.85) && (!$commit || $prec >= $pmin)) {
        return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '2 exact ' . round($score, 3)];
    }
    if (!$clash && !$scripted && ($shared || $score >= (float) lrgDlgCfg('confirm.single_entry_score', 0.65))) {
        return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '3 unscripted ' . round($score, 3)];
    }
    if (!$clash && !$subst && $scripted && count($we) >= 3 && $shapeEq && $score >= (float) lrgDlgCfg('confirm.single_entry_score', 0.65)
        && $prec >= $pmin) {
        return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '4 scripted ' . round($score, 3)];
    }
    if (!$clash && !$subst && $scripted && count($we) >= 1 && count($we) <= 2 && $shapeEq && $shared && (!$commit || $prec >= $pmin)) {
        return ['release' => true, 'refused' => false, 'shape' => false, 'step' => '5 scripted, shares ' . implode('/', $shared)];
    }
    $out['step'] = '6 none (' . round($score, 3) . ($clash ? ', negation' : '') . ')';
    return $out;
}

/**
 * [pt19 v1.0 / S4.6] The auto-advance milliseconds for this single entry, or -1 when it may not advance by itself: indexed,
 * scripted=0 (an Invisible Continue entry is scripted), no check, no price, not crit 2, not goodbye (a walk-away flag is
 * allowed: the oath lines). A continuer text ("...", "Go on.") advances at once (adv=0).
 */
function lrgDlgAdvMs(array $e): int
{
    if (empty(lrgDlgCfg('auto_advance.enabled', true))) { return -1; }
    if ((int) ($e['indexed'] ?? 0) !== 1 || (int) ($e['scripted'] ?? 0) !== 0 || (string) ($e['kind'] ?? '') !== ''
        || (int) ($e['cost'] ?? 0) !== 0 || (int) ($e['crit'] ?? 0) === 2 || (int) ($e['goodbye'] ?? 0) !== 0
        || !empty($e['commit'])) { return -1; }
    $n = (string) ($e['norm'] ?? '');
    if ($n === '') { return 0; }
    foreach ((array) lrgDlgCfg('auto_advance.continuer_words', []) as $c) {
        if (lrgPromptNorm((string) $c) === $n) { return 0; }
    }
    return max(0, (int) lrgDlgCfg('auto_advance.grace_ms', 2500));
}

/**
 * [pt19 v1.0 / S4.3 rev2, model 2.1 / F7 / F8] May the stored sentence drive a FAST-PATH decision on the list that just
 * arrived? Only when it is NEWER than the last click on this NPC (a driven pick, a back click, or his own hand click - so
 * it can never release the next layer on its old words) AND it is <= confirm.utter_window (30 s) old, or it is the very
 * sentence whose open produced this list (its cid came back on lrg_topics, or it is the pending open). `chain`: the
 * sentence produced the last click itself, within the window - it may still name the next PRICE-LIST slot it asked for
 * ("a room for the night": the room line, then "1 day"; "take me to Whiterun": the hire line, then the destination).
 */
function lrgDlgUtterFor(array $st, string $listCid): array
{
    $u = (array) ($st['utter'] ?? []);
    $text = trim((string) ($u['text'] ?? ''));
    $out = ['ok' => false, 'chain' => false, 'text' => $text, 'why' => ''];
    if ($text === '') { $out['why'] = 'no words'; return $out; }
    $at = (int) ($u['at'] ?? 0);
    $ucid = (string) ($u['cid'] ?? '');
    $lc = (array) ($st['last_click'] ?? []);
    $win = max(5, (int) lrgDlgCfg('confirm.utter_window', 30));
    $fresh = lrgNow() - $at <= $win;
    if ($at <= (int) ($lc['at'] ?? 0)) {
        $out['chain'] = $fresh && $ucid !== '' && (string) ($lc['cid'] ?? '') === $ucid;
        $out['why'] = 'not newer than the last click';
        return $out;
    }
    $op = (array) ($st['open_pending'] ?? []);
    $mine = $ucid !== '' && ($ucid === $listCid
        || ((string) ($op['cid'] ?? '') === $ucid && lrgNow() - (int) ($op['at'] ?? 0) <= 2 * $win));
    if (!$fresh && !$mine) { $out['why'] = 'older than ' . $win . ' s'; return $out; }
    $out['ok'] = true;
    return $out;
}

/**
 * [pt19 v1.0 / S5, capability map 1.4] The service kind his words ask for, with the negation guard the slot matcher
 * already has: "I don't need a room" and "can't get a room around here" are no inn request.
 */
function lrgDlgServiceKindSaid(string $utter): string
{
    $kind = lrgDlgServiceKind($utter);
    if ($kind === '') { return ''; }
    $hit = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('services.kinds.' . $kind . '.phrases', []));
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $at = $hit !== '' ? lrgDlgTokenAt($hay, lrgDlgTokens(lrgPromptNorm($hit))) : -1;
    if ($at >= 0 && lrgDlgNegatedAt($hay, $at, lrgDlgClauseStarts($utter))) { return ''; }
    return $kind;
}

/**
 * [pt19 v1.0 / S5, U4] A service BY KIND: on a ROOT list, his words ask for inn / barter / carriage / ferry / train and
 * exactly ONE service (or priced service) entry of that kind is listed -> that entry; two -> null (she asks); never a
 * crime or follower kind. Runs only when the similarity path chose nothing ("heard any rumors?" is the rumours line or
 * nothing, never the room). One compare per entry.
 */
function lrgDlgKindPick(array $entries, string $utter, string $layerKind, bool $noReport = false): array
{
    // [pt19h r2 / safety gap G1 12, reach P6] a refusal, a deferral or a hedge picks NO line by kind ("not now, what do you have for sale
    // later", "no, what have you got for sale", "I'm not sure, ..." each opened the trade window): her words answer
    if (lrgDlgRefuses($utter) || preg_match(LRG_DLG_DEFER_RE, strtolower($utter)) || lrgDlgHedges($utter)) { return ['entry' => null, 'why' => '']; }
    // [pt19h-reach G4 / Nazir] a request for WORK, or a REPORT naming its target: the ONE line of the list that answers it (the
    // radiant start, the turn-in) - before the service kinds, on a root or a closed layer (lrgDlgReachPick)
    $rp = lrgDlgReachPick($entries, $utter, $layerKind, $noReport);
    if ($rp !== null) { return $rp; }
    if (empty(lrgDlgCfg('match.service_kind_pick', true)) || $layerKind !== 'root') { return ['entry' => null, 'why' => '']; }
    // [pt19c fixer] a PRICE question asks what it costs, it buys nothing ("how much for a room?", "is the room expensive?")
    if (lrgDlgIsPriceQuestion($utter)) { return ['entry' => null, 'why' => '']; }
    // [pt19h r2 / safety gap G5] a question about an ITEM asks, it requests no service ("is this for sale?" opened the trade window)
    if (lrgDlgIsQuestion($utter) && in_array(lrgDlgQuestionKind($utter), ['yn:it'], true)) { return ['entry' => null, 'why' => '']; }
    $kind = lrgDlgServiceKindSaid($utter);
    if (!in_array($kind, ['inn', 'barter', 'carriage', 'ferry', 'train'], true)) { return ['entry' => null, 'why' => '']; }
    $cands = [];
    foreach ($entries as $e) {
        if (!in_array((string) ($e['class'] ?? ''), ['service', 'pay'], true)) { continue; }
        if (lrgDlgServiceKind((string) ($e['text'] ?? '')) === $kind) { $cands[] = $e; }
    }
    if (count($cands) === 1) {
        // [pt19c build fixer] his words name a SIBLING of this line - another line of the list that shares its words ("rent",
        // "room") - by a word only that sibling carries: "can I have the ATTIC room for the night" on Delphine's root list is
        // no request for "I'd like to rent a room. (10 gold)" (her attic line is no inn line by its phrase). The kind pick
        // stands down and the model decides - never the wrong room. A line sharing nothing with it ("Heard any rumors?")
        // names no sibling, whatever words it has in common with his sentence.
        $wu = lrgPromptWords(lrgPromptNorm($utter), true);
        $cw = lrgPromptWords(lrgPromptNorm((string) ($cands[0]['text'] ?? '')), true);
        foreach ($entries as $e) {
            if ((int) ($e['pos'] ?? -1) === (int) ($cands[0]['pos'] ?? -2) || (string) ($e['class'] ?? '') === 'hidden') { continue; }
            $ew = lrgPromptWords(lrgPromptNorm((string) ($e['text'] ?? '')), true);
            if (!array_intersect($ew, $cw)) { continue; }
            $hit = array_diff(array_intersect($wu, $ew), $cw);
            if ($hit) {
                return ['entry' => null, 'why' => 'his words name "' . substr((string) ($e['text'] ?? ''), 0, 40) . '" ('
                    . implode(' ', array_slice(array_values($hit), 0, 3)) . '), not the ' . $kind . ' line - the model decides'];
            }
        }
        return ['entry' => $cands[0], 'why' => 'kind ' . $kind];
    }
    return ['entry' => null, 'why' => count($cands) > 1 ? count($cands) . ' ' . $kind . ' entries - she asks which' : ''];
}

/**
 * [pt19 v1.0 / S5] What the similarity matcher's pick means as a SERVICE: '' (not a service line, or nothing said about it),
 * 'kind' (his words ask for exactly this entry's service kind - "I'd like a room" on the priced room line: the same click
 * as the kind pick, so its price may go), 'contradicted' (his words name this kind's phrase but not as a request -
 * "what have you got AGAINST the Stormcloaks", "I don't need a room": never clicked), 'ambiguous' (two or more lines of
 * that kind are listed - she asks which).
 */
function lrgDlgServiceSense(array $entries, array $e, string $utter): string
{
    $ek = lrgDlgServiceKind((string) ($e['text'] ?? ''));
    if ($ek === '' || trim($utter) === '') { return ''; }
    $said = lrgDlgServiceKindSaid($utter);
    // [pt19c-A fix 1 / game review 5] he asked for ANOTHER service: "I'd like to buy a mead" (barter) is no request for "I'd
    // like to rent a room." however close the words score
    if ($said !== '' && $said !== $ek) { return 'contradicted'; }
    if ($said !== $ek) {
        return lrgDlgPhraseHit($utter, (array) lrgDlgCfg('services.kinds.' . $ek . '.phrases', [])) !== '' ? 'contradicted' : '';
    }
    $n = 0;
    foreach ($entries as $x) {
        if ((string) ($x['class'] ?? '') !== 'hidden' && lrgDlgServiceKind((string) ($x['text'] ?? '')) === $ek) { $n++; }
    }
    return $n >= 2 ? 'ambiguous' : 'kind';
}

/**
 * [pt19 v1.0 / research/pt19c-language.md G4] A UNIQUE-WORD TIE: the top two candidates both clear the floor within the
 * margin, and exactly one of them holds a strict word of his sentence that no other candidate has ("the attic room"
 * against "a room" and "the attic room") -> that one. Plain / pay / check only; never grants a commit.
 */
function lrgDlgUniqueWordTie(array $m, array $entries, string $utter): ?int
{
    if ($m['j'] === null || !empty($m['short'])) { return null; }
    $min = (float) lrgDlgCfg('match.min_score', 0.55);
    if ((float) $m['eff'] < $min || (float) $m['second'] < $min) { return null; }
    $wu = lrgPromptWords(lrgPromptNorm($utter), true);
    $own = [];
    foreach ($entries as $i => $e) { $own[$i] = array_intersect($wu, lrgPromptWords((string) ($e['norm'] ?? ''), true)); }
    $win = [];
    foreach ([(int) $m['i'], (int) $m['j']] as $c) {
        $others = [];
        foreach ($own as $i => $w) { if ($i !== $c) { $others = array_merge($others, $w); } }
        if (array_diff($own[$c], $others)) { $win[] = $c; }
    }
    if (count($win) !== 1) { return null; }
    $e = (array) $entries[$win[0]];
    if (!empty($e['commit']) || !in_array((string) ($e['class'] ?? ''), ['plain', 'pay', 'check', 'service'], true)) { return null; }
    return $win[0];
}

/**
 * The reply to lrg_topics want=1: match and emit, on BOTH delivery routes, with the SAME x.
 * No LLM call happens here - this runs in preprocessing.php, before the MAIN lock.
 * [pt19 v1.0 / model 2.2 W, S4.9] In order: a read-only session picks nothing -> the words that may act (2.1: newer than
 * the last click, and fresh or this list's own sentence) -> the enlistment's exact line -> a price list's exact slot ->
 * a named-choice slot -> a single-entry layer (his words release it; else the auto-advance pick) -> the similarity matcher
 * over {ask, his words} (both must agree, S4.7; a unique-word tie resolves, G4) -> a service BY KIND on a root list (S5,
 * only when the matcher chose nothing) -> the rails. A commit clicks here only on his OWN plain sentence (S4.3) or a
 * single-entry release (S4.5), never on ask=. Nothing picked: want_none = this cid (the open turn's reply may act, F15).
 */
function lrgDlgAnswerWant(string $npc, array $kv, array $sess): void
{
    $cid = (string) ($kv['cid'] ?? '');
    $ask = (string) ($kv['ask'] ?? '');
    $none = static function (string $why) use ($npc, $cid): void {
        lrgDlgPut($npc, ['want_none' => ['cid' => $cid, 'at' => lrgNow()]]);
        lrgDlgLog('want=1 npc=' . $npc . ': ' . $why, $cid);
    };
    $entries = array_values(array_filter((array) $sess['entries'], static fn($e) => (string) $e['class'] !== 'hidden'));
    if (!$entries) { $none('nothing to match against'); return; }
    $st = lrgDlgState($npc);
    $ro = lrgDlgReadOnlyWhy($sess, $npc);
    if ($ro !== '') { $none('gate: read-only session why=' . $ro . ' - nothing is picked (the list is his)'); return; }
    // ---- 2.1 the words that may act ---------------------------------------------------------------------------------
    $uf = lrgDlgUtterFor($st, $cid);
    $utter = $uf['ok'] ? (string) $uf['text'] : '';
    if (!$uf['ok'] && (string) $uf['text'] !== '') {
        lrgDlgLog('want=1 npc=' . $npc . ': his last words are not this list\'s (' . $uf['why'] . ')'
            . ($uf['chain'] ? ' - they may still name a slot of a price list' : '') . ': "' . substr((string) $uf['text'], 0, 40) . '"', $cid);
    }
    $stU = $st;
    $stU['utter'] = ['text' => $utter] + (array) ($st['utter'] ?? []);
    $arrest = lrgDlgArrestClass(['entries' => $entries, 'tail' => [], 'crime' => (array) ($st['crime'] ?? [])]);
    $tW = ['npc' => $npc, 'cid' => $cid, 'speech' => $utter !== '', 'entries' => $entries, 'tail' => [],
        'crit' => (int) ($sess['crit'] ?? 0), 'arrest' => $arrest, 'facts' => (array) ($st['facts'] ?? [])];
    $pick = null;
    $mode = 'intent';
    $said = '';
    $m = null;
    $adv = -1;
    // ---- [0.5.7 / pt16-legion] an ENLISTMENT owns the fast path (exact topic / line, never similarity) --------------
    $facPick = lrgFacArbitrateWant($npc, $entries, $stU, $ask, $cid);
    if ($facPick === false) { $none('an enlistment was asked and nothing single can be clicked - her words answer'); return; }
    if (is_array($facPick)) { $pick = (int) $facPick['i']; $mode = 'faction'; $said = (string) $facPick['utter']; }
    // ---- [0.5.0 / E6, risk R12] THE PRICE-LIST RULE: exact slot, longest wins, a price question executes nothing, and
    // the similarity matcher is NOT consulted. [pt19 v1.0] his words chain here from the click they produced (a room for
    // the night -> "1 day"), and only here.
    $slotText = $utter !== '' ? $utter : (!empty($uf['chain']) ? (string) $uf['text'] : '');
    // [pt19c fixer / walkthrough M6] the click that produced this list was a PARK released by his "yes": the sentence that parked
    // it still names the slot ("take me to Whiterun" -> she asked about the hire line -> "yes" -> the destinations: Whiterun)
    $cu = (array) ($st['chain_utter'] ?? []);
    if ($utter === '' && !empty($uf['chain']) && (string) ($cu['cid'] ?? '') !== ''
        && (string) ($cu['cid'] ?? '') === (string) (((array) ($st['last_click'] ?? []))['cid'] ?? '')
        && lrgNow() - (int) ($cu['at'] ?? 0) <= max(5, (int) lrgDlgCfg('confirm.utter_window', 30)) && trim((string) ($cu['text'] ?? '')) !== '') {
        $slotText = (string) $cu['text'];
    }
    $isPriceList = $pick === null && lrgDlgServicesOn($npc) && lrgDlgServiceSlot($entries, $slotText, '', $npc) !== null;
    if ($isPriceList) {
        foreach ([$slotText, $ask] as $cand) {
            if (trim((string) $cand) === '') { continue; }
            $r = lrgDlgServiceSlot($entries, (string) $cand, '', $npc);
            if (!is_array($r)) { continue; }
            if ((string) $r['mode'] === 'pick') {
                // [pt19c-A fix 1 / usefulness review, U3] by position AND norm: every Xtended Stay "N days. (P gold)" row
                // normalises to "# days", so the norm alone clicked the FIRST of them ("three nights" -> 2 days)
                $re = (array) $r['entry'];
                foreach ($entries as $ei => $ee) {
                    if ((int) $ee['pos'] === (int) ($re['pos'] ?? -1) && (string) $ee['norm'] === (string) ($re['norm'] ?? '')) { $pick = $ei; break; }
                }
                $said = (string) $cand;
                break;
            }
            if (in_array((string) $r['mode'], ['price', 'ask'], true)) { break; }
        }
        if ($pick === null) {
            $none('a price list and no single slot was named - nothing is clicked (the similarity matcher is NOT consulted on a price list)');
            return;
        }
        $mode = 'slot';
        lrgDlgLog('want=1 npc=' . $npc . ': slot "' . lrgPromptNorm((string) $entries[$pick]['text'])
            . '" named exactly (longest wins) - the similarity matcher was bypassed', $cid);
    }
    // ---- [G5] a named-choice list ("I'd like a sword / a dagger ..."): the one slot named, not negated, >= 2 tokens,
    // [pt19c-A fix 1 / language review 2] said CLEANLY and not as a question: "is the waraxe any good?", "the greatsword looks
    // heavy", "I already have a dagger" name a slot and ask for nothing. Mode `named`: lrgDlgExplicit then runs its own clean
    // test instead of the price list's slot shortcut.
    if ($pick === null && $utter !== '' && count(lrgDlgTokens(lrgPromptNorm($utter))) >= (int) lrgDlgCfg('confirm.slot_tokens', 2)) {
        $nc = lrgDlgNamedChoice($entries, $utter);
        if ($nc !== null && !empty($nc['clean']) && !lrgDlgIsQuestion($utter)) {
            foreach ($entries as $ei => $ee) {
                if ((int) $ee['pos'] === (int) $nc['entry']['pos'] && (string) $ee['norm'] === (string) $nc['entry']['norm']) { $pick = $ei; break; }
            }
            if ($pick !== null) { $mode = 'named'; $said = $utter; }
        }
    }
    // ---- [pt19h r2 / G16] A HAND-OVER in his own words: "here's the X", "here, take the X", "I have the X for you" names the line
    // whose tag hands X over (lrgDlgHandsOver: the object inside a giving frame - never the object alone, a question, a keep or a
    // negation). Before the single-entry step: Storn's, Crescius's and Delvin's hand-overs are alone on their layer
    if ($pick === null && $utter !== '') {
        $hp = lrgDlgHandOverPick($entries, $utter);
        if ($hp !== null) {
            $pick = $hp;
            $mode = 'hand';
            $said = $utter;
            lrgDlgLog('want=1 npc=' . $npc . ': "' . substr($utter, 0, 40) . '" hands over "' . (string) ($entries[$pick]['hand'] ?? '') . '" - the line "'
                . substr((string) $entries[$pick]['text'], 0, 40) . '" (G16)', $cid);
        }
    }
    // ---- [S4.5 / S4.6] A SINGLE-ENTRY LAYER never asks: his new words release it, or it advances by itself -----------
    $single = $pick === null ? lrgDlgSingleEntryOf(['list' => 'pending', 'layer_kind' => (string) ($sess['kind'] ?? 'closed'),
        'entries' => $entries, 'tail' => []]) : null;
    if ($single !== null) {
        $rel = $utter !== '' ? lrgDlgSingleEntryRelease($single, $utter) : ['release' => false, 'refused' => false, 'shape' => false, 'step' => 'no new words'];
        if ($rel['release']) {
            $pick = 0;
            $mode = 'single';
            $said = $utter;
            lrgDlgLog('want=1 npc=' . $npc . ': single entry "' . substr((string) $single['text'], 0, 40) . '" released by his words (step '
                . $rel['step'] . ')', $cid);
        } elseif (!empty($rel['refused'])) {
            $none('single entry "' . substr((string) $single['text'], 0, 40) . '": ' . $rel['step'] . ' - nothing, and no auto-advance');
            return;
        } else {
            // [pt19h r2 / grading P3, P13, safety P8] he is LEAVING ("goodbye", "I'm done here"): the breath never says the line for
            // him (the re-arm already stands down on it)
            if ($utter !== '' && lrgDlgLeaveWords($utter)) {
                $none('single entry "' . substr((string) $single['text'], 0, 40) . '": he is leaving - nothing, and no auto-advance');
                return;
            }
            $adv = lrgDlgAdvMs($single);
            if ($adv < 0) {
                $none('single entry "' . substr((string) $single['text'], 0, 40) . '": ' . $rel['step'] . ' - it waits for his words'
                    . ' (scripted, priced, a check or a goodbye never advances by itself)');
                return;
            }
            $pick = 0;
            $mode = 'advance';
        }
    }
    // ---- [pt19c-A fix 1 / CHIM review, S4.9] A FOLLOWER ORDER in his words resolves through the verb table (exact
    // containment against the framework's OWN entries), never by similarity - as the gate does. A dismiss / home is a commit
    // and never explicit: it is not clicked here, the model asks.
    if ($pick === null && $utter !== '' && lrgDlgFollowerOn($npc)
        && (lrgDlgFollowerIntents($utter) || lrgDlgFollowerNegatedOnly($utter))) {
        $fo = lrgDlgFollowerArbitrate(['npc' => $npc], $entries, '', $cid);
        if (is_array($fo)) {
            if ($fo['entry'] === null) { $none('a follower order is resolved on her own follower lines exactly, or not at all - nothing is clicked'); return; }
            foreach ($entries as $ei => $ee) {
                if ((int) $ee['pos'] === (int) $fo['entry']['pos'] && (string) $ee['norm'] === (string) $fo['entry']['norm']) { $pick = $ei; break; }
            }
            if ($pick !== null) { $entries[$pick] = (array) $fo['entry'] + (array) $entries[$pick]; $mode = 'follower'; $said = $utter; }
        }
    }
    // ---- the similarity matcher: two candidates, and they must AGREE (S4.7) --------------------------------------------
    $noReport = false;
    $leftToModel = null;
    if ($pick === null) {
        $minS = (float) lrgDlgCfg('match.min_score', 0.55);
        $minM = (float) lrgDlgCfg('match.min_margin', 0.15);
        $mA = $ask !== '' ? lrgDlgMatchText($ask, $entries) : null;
        // [pt19c fixer / @adv_sibling] his form of address is no content: "I'd like a word, jarl" never picks "My Jarl, I seek an
        // audience." on the vocative alone
        $mU = $utter !== '' ? lrgDlgMatchText(lrgDlgStripVocative($utter, $npc), $entries) : null;
        $pA = lrgDlgMatchPick($mA, $entries, $minS, $minM);
        $pU = lrgDlgMatchPick($mU, $entries, $minS, $minM);
        if ($pU === null && $mU !== null) {
            $tie = lrgDlgUniqueWordTie($mU, $entries, $utter);
            if ($tie !== null) { $pU = $tie; lrgDlgLog('want=1 npc=' . $npc . ': a unique-word tie resolves to "' . substr((string) $entries[$tie]['text'], 0, 40) . '"', $cid); }
        }
        // [pt19c-A fix 1 / game review 1, ai review] WORDS CARRY THE LINE: on the F1 / trigram tier the frame words (tell,
        // about, know, any) cannot pick a line alone - the strict-word precision of S4.5 (>= 0.75) must hold. "tell me about
        // the war" no longer clicks "Tell me about Whiterun.", "do you have any mead" no longer clicks "Heard any rumors lately?"
        // HIS words only: ask= is his own sentence on the pre-LLM open's list (then it is guarded like it), and the MODEL's
        // item on an open for awareness - the model read his words and named the business; the shipped floors judge that
        $na = lrgPromptNorm($ask);
        $nu = lrgPromptNorm((string) (((array) ($st['utter'] ?? []))['text'] ?? ''));
        $askIsHis = $na !== '' && ($na === $nu || (strlen($na) >= 40 && str_starts_with($nu, $na)));   // ask= is cut at 60 chars
        foreach (['pU' => $utter, 'pA' => $askIsHis ? $ask : ''] as $var => $words) {
            if ($words !== '' && $$var !== null && !lrgDlgWordsCarry($words, (array) $entries[$$var])) {
                lrgDlgLog('want=1 npc=' . $npc . ': "' . substr($words, 0, 40) . '" -> "' . substr((string) $entries[$$var]['text'], 0, 40)
                    . '" shares too few of his meaning words (precision < ' . lrgDlgCfg('confirm.single_entry_precision', 0.75) . ') - not his line', $cid);
                $$var = null;
            }
        }
        if ($pA !== null && $pU !== null && $pA !== $pU) {
            $none(sprintf('want=1 ambiguous model=%d player=%d - the model\'s words and his own land on different entries, nothing is clicked', $pA, $pU));
            return;
        }
        if ($pU !== null) { $pick = $pU; $m = $mU; $said = $utter; }
        elseif ($pA !== null) { $pick = $pA; $m = $mA; $said = $ask; }
        if ($pick !== null && lrgDlgNegationClash($said, (array) $entries[$pick])) {
            $none('"' . substr($said, 0, 40) . '" says the opposite of "' . substr((string) $entries[$pick]['text'], 0, 40) . '" (negation) - nothing is clicked');
            return;
        }
        // [pt19h-orch / G5, G9] a QUESTION of his that is not the line word for word: against a question line it must be the line's
        // own question ("what do you think of Markarth" is no "What are you doing in Markarth?"), and it never takes a PROTECTED
        // line (scripted, priced, a commit, a check, a goodbye) on the fast path - "can the Sanctuary be repaired?" leaves Delvin's
        // refit line to the model, which reads the whole conversation and asks
        if ($pick !== null && lrgDlgIsQuestion($said)) {
            $pm = $pick === $pU ? $mU : $mA;
            $whole = is_array($pm) && in_array((string) ($pm['tier'] ?? ''), ['exact', 'contain'], true) && empty($pm['short']);
            $pe = (array) $entries[$pick];
            $qWhy = '';
            if (!$whole && lrgDlgEntryIsQuestion($pe) && !lrgDlgQuestionsSame(lrgDlgBareWords($said, '', (string) ($pe['norm'] ?? '')), $pe)) {
                $qWhy = 'his question is not the line\'s own question';
            } elseif (!$whole && lrgDlgProtected($pe)) {
                $qWhy = 'a question never takes a protected line from the fast path';
            }
            if ($qWhy !== '') {
                lrgDlgLog('want=1 npc=' . $npc . ': "' . substr($said, 0, 40) . '" -> "' . substr((string) $pe['text'], 0, 40) . '": ' . $qWhy
                    . ' - the model decides', $cid);
                $pick = null;
                $m = null;
                $said = '';
                $leftToModel = $pe;
            }
        }
        // [pt19c fixer / adversarial QA] a question, a refusal, a bargain, his answer to the line's own question or a word of
        // another line is not this line: the fast path stands down and the model decides (lrgDlgWordsQualm)
        if ($pick !== null && ($q = lrgDlgWordsQualm($said, (array) $entries[$pick], $entries, $npc, $pick === $pU ? $mU : $mA)) !== '') {
            // (the service-by-kind pick below may still answer a request his words make: "do you have any rooms?")
            lrgDlgLog('want=1 npc=' . $npc . ': "' . substr($said, 0, 40) . '" -> "' . substr((string) $entries[$pick]['text'], 0, 40) . '": '
                . $q . ' - not this line', $cid);
            // [pt19h r2 / reach P7] a word of his named ANOTHER line: the report pick never overrides that veto (she asks)
            $noReport = str_starts_with($q, 'a word of his belongs to another line');
            $pick = null;
            $m = null;
            $said = '';
        }
        if ($pick !== null) {
            $sense = lrgDlgServiceSense($entries, (array) $entries[$pick], $said);
            if ($sense === 'contradicted') {
                $none('"' . substr($said, 0, 40) . '" names that service but does not ask for it - nothing is clicked');
                return;
            }
            if ($sense === 'ambiguous' && (float) (($pick === $pU ? $mU : $mA)['eff'] ?? 0) < 0.85) {
                $none('two lines of that service are listed and his words name neither exactly - she asks which');
                return;
            }
            if ($sense === 'kind') { $mode = 'kind'; }
        }
    }
    // ---- [pt19c-A fix 1 / architect P5, model F18] A VOICE ORDER of food or drink owns its sentence (10.27): no service
    // line (the trade window, the room) is clicked from the fast path while it is fresh - by kind or by similarity
    $mo = (array) ($st['mkt_order'] ?? []);
    $order = $mo && lrgNow() - (int) ($mo['at'] ?? 0) <= max(5, (int) lrgDlgCfg('confirm.utter_window', 30));
    if ($order && $pick !== null && in_array((string) $entries[$pick]['class'], ['service', 'pay'], true)
        && !in_array($mode, ['slot', 'faction', 'single', 'advance'], true)) {
        $none('a voice order of food or drink owns this sentence (10.27, F18) - "' . substr((string) $entries[$pick]['text'], 0, 40) . '" is not clicked');
        return;
    }
    // ---- [S5 / U4] a service BY KIND, only when the matcher chose nothing ----------------------------------------------
    if ($pick === null && $utter !== '' && $order) { $none('service by kind: a voice order of food or drink owns this sentence (F18)'); return; }
    if ($pick === null && $utter !== '') {
        $kp = lrgDlgKindPick($entries, $utter, (string) ($sess['kind'] ?? ''), $noReport);
        // [pt19h r2 / extended TGCSG.buyback.ask] ... never ANOTHER line than the one his words matched best when the rails left that one to
        // the model: "can I buy that unusual gem back?" names the buy-back line (protected, a question against it), not the shop
        if (is_array($kp['entry']) && is_array($leftToModel) && ((int) $kp['entry']['pos'] !== (int) $leftToModel['pos']
            || (string) $kp['entry']['norm'] !== (string) $leftToModel['norm'])) {
            $none('service by kind: his words match "' . substr((string) $leftToModel['text'], 0, 40) . '" best, which is left to the model - "'
                . substr((string) $kp['entry']['text'], 0, 40) . '" is not clicked in its place');
            return;
        }
        if (is_array($kp['entry'])) {
            foreach ($entries as $ei => $ee) {
                if ((int) $ee['pos'] === (int) $kp['entry']['pos'] && (string) $ee['norm'] === (string) $kp['entry']['norm']) { $pick = $ei; break; }
            }
            if ($pick !== null) { $mode = 'kind'; $said = $utter; }
        } elseif ((string) $kp['why'] !== '') {
            $none('service by kind: ' . $kp['why']);
            return;
        }
    }
    if ($pick === null) {
        $none(sprintf('no match (score=%s margin=%s) - the NPC answers in her own words',
            $m['score'] ?? '-', $m['margin'] ?? '-'));
        return;
    }
    $e = $entries[$pick];
    // ---- the rails the POST-LLM gate applies, repeated here (S4.9) ------------------------------------------------------
    // [pt19c final fixer / spec 3.5 "show on both paths"] a resist-arrest line, an arrest session, a lethal line: the fast path
    // answers do=show kind=meta exactly as the gate does (lrgDlgDecideEntry) - the game stops driving that layer at once (F3), nothing
    // is clicked, and her words on the LLM turn say it is his (the open turn's reply is held: this sentence is decided)
    $show = static function (string $why, string $svc) use ($npc, $cid, $sess, $e): void {
        lrgDlgLog('want=1 npc=' . $npc . ': ' . $why . ' - do=show, the menu is his', $cid);
        lrgDlgEmit($npc, ['do' => 'show', 'sid' => (string) ($sess['sid'] ?? '0'), 'gen' => (int) ($sess['gen'] ?? 0), 'pos' => -1, 'i' => -1,
            'txt' => '', 'kind' => 'meta', 'svc' => $svc], $cid, 'lethal', $e);
    };
    if (lrgDlgIsResistArrest($e)) { $show('"' . substr((string) $e['text'], 0, 40) . '" is a RESIST-ARREST entry - never selectable by voice', 'crime'); return; }
    // [pt19c-A fix 1 / CHIM review, S4.9 "gate and fast path alike"] a follower topic that is never selectable by voice
    if (lrgDlgFollowerBlockedEntry($e)) {
        $none('"' . substr((string) $e['text'], 0, 40) . '" is on topic ' . (string) ($e['topic'] ?? '?') . ' - a follower topic never selectable by voice');
        return;
    }
    if ($arrest !== '' && !empty(lrgDlgCfg('services.kinds.crime.lethal', true)) && (int) ($sess['crit'] ?? 0) !== 1) {
        $show('ARREST session (detected by ' . $arrest . ') - nothing is chosen by voice', 'crime');
        return;
    }
    if ((int) ($sess['crit'] ?? 0) === 2 || (int) $e['crit'] === 2) { $show('LETHAL ("' . substr((string) $e['text'], 0, 40) . '") - never clicked', ''); return; }
    if ((string) $e['class'] === 'meta') { $none('META entry - never executed by voice'); return; }
    if (lrgDlgStageRailBlocks($e)) {
        $none('gate: stage rail - no click is verified on this install yet and "' . substr((string) $e['text'], 0, 40)
            . '" is not an indexed plain line (scripted, priced, a check, a goodbye or unindexed)');
        return;
    }
    if ((int) $e['cost'] > 0 && empty($e['afford'])) {
        $none('priced entry ' . (int) $e['cost'] . ' septims, the player has ' . (int) ($st['facts']['pg'] ?? 0) . ' - never clicked');
        return;
    }
    $isCommit = !empty($e['commit']) || (string) $e['class'] === 'commit';
    if ($isCommit && $mode !== 'single') {
        // [S4.3] a commit executes from the fast path ONLY on the PLAYER's own plain sentence, never on ask=.
        // [pt19c-A fix 1 / usefulness review, U3] ... and the price-list CHAIN is his own sentence too: "I'd like a room for
        // the night" produced the room click and names the "1 day" slot of the list it opened (Xtended Stay's days rows and
        // every carriage destination are scripted, so a closed layer grades them commit); the explicit rails still apply
        $own = $utter !== '' ? $utter : (($mode === 'slot' && !empty($uf['chain'])) ? $slotText : '');
        if ($said !== $own || $own === '' || !lrgDlgExplicit(['speech' => true] + $tW, $e, $own, $mode, $st)) {
            $none(sprintf('matched a commit ("%s") - not his own plain sentence, so it is not clicked here (the model asks, quoting it)',
                substr((string) $e['text'], 0, 40)));
            return;
        }
        if ($mode !== 'hand') { $mode = 'explicit'; }
    }
    // intent mode may execute plain / service (+ pay in a same-session continuation, a kind pick's priced service)
    $cont = !$isCommit && in_array($mode, ['intent'], true) && is_array($m) && lrgDlgContinuationOk($npc, $st, $e, $m, $said, $sess);
    $allowed = ['plain', 'service'];
    if ($cont || in_array($mode, ['kind', 'slot', 'named', 'faction', 'single', 'advance', 'explicit', 'hand'], true)) { $allowed[] = 'pay'; }
    if (in_array($mode, ['slot', 'faction', 'single', 'explicit', 'advance', 'hand'], true)) { $allowed[] = 'commit'; }
    if (!in_array((string) $e['class'], $allowed, true)) {
        $none(sprintf('matched a %s entry ("%s") - NOT executed from the fast path', (string) $e['class'], substr((string) $e['text'], 0, 50)));
        return;
    }
    // D-18: ONE priced pick per session from the fast path (the room line, then its days list; the hire line, then the
    // destination) - a second one waits for his words and the model
    if ((string) $e['class'] === 'pay' || $cont) {
        if ((int) ($sess['cont'] ?? 0) >= 1) {
            $none('a second priced pick in one session is refused on the fast path (depth 1)');
            return;
        }
        $sess['cont'] = (int) ($sess['cont'] ?? 0) + 1;
        lrgDlgPut($npc, ['session' => $sess]);
    }
    lrgDlgEmit($npc, [
        'do' => 'pick', 'sid' => (string) ($sess['sid'] ?? '0'), 'gen' => (int) ($sess['gen'] ?? 0),
        'pos' => (int) $e['pos'], 'i' => (int) $e['i'], 'txt' => lrgDlgSafePrefix((string) $e['text']),
        'kind' => (string) ($e['kind'] !== '' ? $e['kind'] : $e['class']), 'cost' => (int) $e['cost'],
        'ask' => substr($ask, 0, 60),
        'svc' => in_array($mode, ['slot', 'kind'], true) ? lrgDlgServiceKind($said) : '',
        'adv' => $mode === 'advance' ? $adv : -1, 'overlap' => is_array($m) ? (float) $m['f1'] : -1,
    ], $cid, $cont ? 'continuation' : $mode, $e);
}

/**
 * Same-session continuation (design 4.4 / D-18). ALL of these are required, and they are stricter than
 * intent mode's: the utterance names the value the layer asks for; score >= 0.70 with margin >= 0.25;
 * pg >= N for a priced entry; depth 1 only; no twat; class service or pay and NEVER check/commit/meta.
 * [pt19 v1.0 / section 4] depth 1, always: the rewalk depth (bRewalk / iRewalkDepth, match.cont_depth) is retired.
 */
function lrgDlgContinuationOk(string $npc, array $st, array $e, array $m, string $utter, array $sess): bool
{
    if (!lrgDlgSessionOpen($sess)) { return false; }
    if ((string) ($sess['kind'] ?? '') !== 'closed') { return false; }
    if (!in_array((string) $e['class'], ['service', 'pay'], true)) { return false; }
    if ((string) $e['kind'] !== '' || $e['commit'] || (string) $e['twat'] !== '') { return false; }
    if ((float) $m['score'] < (float) lrgDlgCfg('match.cont_score', 0.70)) { return false; }
    if ((float) $m['margin'] < (float) lrgDlgCfg('match.cont_margin', 0.25)) { return false; }
    if ((int) ($sess['cont'] ?? 0) >= 1) { return false; }
    if ((int) $e['cost'] > 0 && (int) ($st['facts']['pg'] ?? 0) < (int) $e['cost']) { return false; }
    // the utterance must NAME the value the layer asks for
    $uw = lrgPromptWords(lrgPromptNorm($utter));
    $ew = lrgPromptWords((string) $e['norm']);
    $named = false;
    foreach ($ew as $w) { if (in_array($w, $uw, true) && (preg_match('/^#|night|day|week|hour|month/', $w) || strlen($w) > 3)) { $named = true; } }
    return $named;
}

// ================================================================== the post-LLM gate
/**
 * Phase 2's post-gate. Registered AFTER Phase 1's, and Phase 1 passes ExtCmdLRG_SelectTopic through
 * untouched (integrator edit D-12). This gate never touches a Phase 1 line.
 */
/**
 * [0.5.1 pt9 go-live / S11] Was this action code hidden by THIS module on THIS turn? Both lists are
 * compared with the post-gate's own normalisation, so Make_Follower and MakeFollower are one code.
 */
function lrgDlgHiddenThisTurn(string $norm): bool
{
    foreach (array_merge((array) ($GLOBALS['LRG_DLG_SVC_HIDDEN'] ?? []),
        (array) ($GLOBALS['LRG_DLG_HOLD_HIDDEN'] ?? [])) as $code) {
        if (str_replace(['_', ' '], '', strtolower((string) $code)) === $norm) { return true; }
    }
    return false;
}

function lrgDlgPostProcessActions(array $lines): array
{
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    // [pt19 v1.0 / CHIM brief P1] the fast path may have written since this request's first read - a landed fast pick's
    // last_exec, want_none, the next layer's session, a hand click: the gate decides on the row as it is NOW
    if (is_array($t) && !empty($t['on']) && (string) ($t['npc'] ?? '') !== '') { lrgDlgFresh((string) $t['npc']); }
    $out = [];
    $seen = false;
    $picked = false;
    unset($GLOBALS['LRG_DLG_GATE_REARM']);
    foreach ($lines as $line) {
        $raw = rtrim((string) $line, "\r\n");
        $parts = explode('|', $raw, 3);
        $cmd = explode('@', $parts[2] ?? '', 2);
        $code = trim($cmd[0] ?? '');
        $norm = str_replace(['_', ' '], '', strtolower($code));
        // BOTH sides are normalised. The wire really carries the CODE name (functions.php:2543 builds
        // "$actor|$channel|$functionCodeName@$param"), and CHIM snake-cases the DISPLAY name, so the three
        // shapes that can arrive are ExtCmdLRG_SelectTopic, Take_Up_Business and TakeUpBusiness. Comparing
        // the stripped code against an unstripped strtolower(LRG_ACT_TOPIC) matched none of them, and the
        // raw LLM line was passed through ungated - every rail below was unreachable in production.
        ///[0.5.1 pt9 go-live / S11] A CODE THIS MODULE HID FOR THIS TURN IS NEVER FORWARDED. The hide
        // policy only edits ENABLED_FUNCTIONS, which is advisory - if the model emits the code anyway,
        // nothing here dropped it, and 'Ysolda|command|GiveGoldTo@500' and a MakeFollower hidden the same
        // turn both survived the gate untouched. Whether CHIM core refuses an unoffered action could not
        // be tested from here, so this closes our own net rather than relying on someone else's.
        if ($norm !== '' && lrgDlgHiddenThisTurn($norm)) {
            lrgDlgLog('gate: dropped ' . $code . ' - this module hid it for this turn', (string) ($t['cid'] ?? ''));
            continue;
        }
        if (!in_array($norm, LRG_DLG_GATE_NAMES, true)) {
            $out[] = $line;
            continue;
        }
        if ($seen) { lrgDlgLog('gate: a second ' . LRG_DLG_NAME . ' line in one turn - dropped', (string) ($t['cid'] ?? '')); continue; }
        $seen = true;
        if (!is_array($t) || empty($t['on'])) {
            lrgDlgLog('gate: dropped ' . LRG_DLG_NAME . ' - the module is not offering business this turn ('
                . (implode('; ', (array) ($t['why'] ?? [])) ?: 'no turn') . ')', (string) ($t['cid'] ?? ''));
            continue;
        }
        $actor = (string) ($parts[0] ?? '');
        if (strcasecmp($actor, (string) $t['npc']) !== 0) {
            lrgDlgLog('gate: dropped ' . LRG_DLG_NAME . " from '" . $actor . "' - not the NPC of this turn ("
                . (string) $t['npc'] . ')', (string) $t['cid']);
            continue;
        }
        $item = trim((string) ($cmd[1] ?? ''));
        $emitted = lrgDlgGateItem($t, $item);
        if ($emitted !== null) {
            $out[] = $emitted;
            // a do=show (the leave guard on LEAVE, the lethal hand-back) is a decision about this layer too: the breath is
            // never re-armed beside it (model row 24 re-arms only on "none found, or a T-key refused by shape")
            if (strpos($emitted, ';do=pick;') !== false || strpos($emitted, ';do=leave;') !== false
                || strpos($emitted, ';do=show;') !== false) { $picked = true; }
        }
    }
    // ---------------------------------------------------------------- [pt19 v1.0 / S4.4, model F27] the bare yes
    // He answered her naming question with an assent and the model answered in WORDS only: the parked line goes out with
    // her reply (not muted: her words play, the click waits for her voice).
    if (is_array($t) && !empty($t['on']) && !$seen) {
        $by = lrgDlgBareYesLine($t);
        if ($by !== null && $by !== '') { $out[] = $by; $picked = true; }
    }
    // ---------------------------------------------------------------- [pt19 v1.0 / S4.6] the re-arm
    // A question on a single unscripted layer, answered in her words, and nothing picked this turn: the breath is re-armed
    // in the SAME reply (rearm=1 - the game counts from the end of HER answer).
    // [pt19c-A fix 1 / architect P1, ai review] ... and only after "no item at all", "the item matched nothing" or "a T-key
    // refused by SHAPE" (model row 24): never beside a LEAVE, a leave-guard show, a park, the rail, B1 or a check refusal
    if (is_array($t) && !empty($t['on']) && !$picked && (!$seen || !empty($GLOBALS['LRG_DLG_GATE_REARM']))) {
        $ra = lrgDlgRearmLine($t);
        if ($ra !== null && $ra !== '') { $out[] = $ra; }
    }
    // the free-conversation check's own effect carrier: emitted by the SERVER, never chosen by the LLM
    if (is_array($t) && is_array($t['check'] ?? null) && !empty($t['check']['award'])) {
        $line = lrgDlgAwardLine($t);
        if ($line !== '') { $out[] = $line; }
    }
    // ---------------------------------------------------------------- [0.5.0] E7 the truth gate
    // It looks ONLY at numbers and slot names, and ONLY on a turn that carried a price / bounty /
    // service fact. If she named one the game has not confirmed AND this same reply would act on it,
    // the ACTION is dropped - her words still play, and nothing was spent (plan 19.2(c)).
    unset($GLOBALS['LRG_DLG_TRUTH_CLAIM']);
    if (is_array($t) && !empty($t['on'])) {
        $reply = (string) ($GLOBALS['LAST_LLM_RESPONSE']['message'] ?? '');
        $bad = $reply !== '' ? lrgDlgTruthCheck($t, $reply, $out) : null;
        // [pt19-purchase] the buy net reads this: nothing is sold under a price she invented (NEVER FALSE)
        if (is_array($bad)) { $GLOBALS['LRG_DLG_TRUTH_CLAIM'] = $bad; }
        if (is_array($bad)) {
            $acts = lrgDlgActsOnMoney($out);
            // [0.5.0 fix pass / S-3] bTruthGate (tg), NOT bLockedFacts (lf). The shipped line read `lf`,
            // so switching bLockedFacts off silently disabled the truth gate as well and bTruthGate did
            // nothing at all - the reverse of what both MCM help texts promise. The two are separate
            // controls: lf decides whether <locked_facts> is built at all (lrgDlgLockedFacts():3405),
            // tg decides whether an unconfirmed number is allowed to COST GOLD. With tg = 0 the claim is
            // still detected and still logged, it just no longer drops the action.
            $gateOn = !empty(lrgDlgCfg('truth.gate', true))
                && (int) lrgDlgMcm('tg', 1, (string) $t['npc']) === 1;
            if ($acts && $gateOn) {
                $out = array_values(array_filter($out, static fn($l) => !lrgDlgActsOnMoney([$l])));
                lrgDlgPut((string) $t['npc'], ['truth_note' => ['at' => lrgNow(), 'claim' => (string) $bad['claim']]]);
                lrgDlgLog(sprintf('truth npc=%s claim=%s said="%s" fact="%s" action=dropped', (string) $t['npc'],
                    (string) $bad['claim'], substr((string) $bad['said'], 0, 40), substr((string) $bad['fact'], 0, 40)),
                    (string) $t['cid']);
            } else {
                lrgDlgPut((string) $t['npc'], ['truth_note' => ['at' => lrgNow(), 'claim' => (string) $bad['claim']]]);
                lrgDlgLog(sprintf('truth npc=%s claim=%s said="%s" fact="%s" action=logged', (string) $t['npc'],
                    (string) $bad['claim'], substr((string) $bad['said'], 0, 40), substr((string) $bad['fact'], 0, 40)),
                    (string) $t['cid']);
            }
        }
    }
    // ---------------------------------------------------------------- [0.5.7 / pt16] direct barter net
    // The player asked to see her wares and no real entry could answer: CHIM's own OpenInventory (the
    // vanilla barter window) goes out with this reply whether or not the model chose it. BEFORE the
    // corner hint on purpose - a player who is about to trade is not to be nudged about a quest.
    if (is_array($t) && !empty($t['on'])) { $out = lrgDlgServiceNet($t, $out); }
    // ---------------------------------------------------------------- [pt19-purchase] the buy net
    // A queued order becomes ONE ExtCmdLRG_Buy line after the model's own lines (D1 only, the 10.26 shape) - never when the
    // reply already chose a trade / hand-over action, never under a price the truth gate flagged. BEFORE the corner hint
    // on purpose: a player who has just ordered a mead is not to be nudged about a quest.
    if (is_array($t) && !empty($t['on'])) { $out = lrgMktNet($t, $out); }
    // ---------------------------------------------------------------- [0.5.0] E2(d) the corner hint
    // Only on a turn that emitted NOTHING and where the model did not even TRY to take business up.
    // "She has something worth asking about" is noise the moment the player has taken it up, and it
    // must never turn a one-decision batch into two - a dropped attempt is still an attempt.
    if (is_array($t) && !empty($t['on']) && !$out && !$seen) {
        $hint = lrgDlgQuestHint($t);
        if ($hint !== null && $hint !== '') { $out[] = $hint; }
    }
    // ---------------------------------------------------------------- [0.5.0] E4(b) release the hold
    // PREPENDED, so the game lets go of her BEFORE CHIM's own movement action executes in the same batch.
    $rel = lrgDlgReleaseLine($out);
    if ($rel !== null && $rel !== '') { array_unshift($out, $rel); }
    return array_values($out);
}

/**
 * [pt19 v1.0 / S2.1, model F15] The open turn: this sentence's pre-LLM open decides. '' = gate normally (the fast path
 * already answered this cid with NOTHING - want_none); otherwise why the reply's pick is not emitted: the fast pick for
 * this sentence already landed (the T-key is dropped: a stale-gen re-match would run a want=1 against the NEXT layer), or
 * the list has not answered yet (held: the open for this sentence decides; her line plays).
 */
function lrgDlgOpenTurnHold(array $t, array $st): string
{
    if (empty($t['open_pending'])) { return ''; }
    $cid = (string) ($t['cid'] ?? '');
    $ex = (array) ($st['last_exec'] ?? []);
    if ((string) ($ex['do'] ?? '') === 'pick' && (string) ($ex['cid'] ?? '') === $cid) {
        return 'the fast pick for this sentence already landed - the reply\'s pick is dropped';
    }
    if ((string) (((array) ($st['want_none'] ?? []))['cid'] ?? '') === $cid) { return ''; }
    return 'held - the open for this sentence decides';
}

/** One TakeUpBusiness item. Returns the wire line, or null when nothing may be executed. */
function lrgDlgGateItem(array $t, string $item): ?string
{
    $npc = (string) $t['npc'];
    $cid = (string) $t['cid'];
    $st = lrgDlgState($npc);
    $item = (string) preg_replace('/^\s*item\s*=\s*/i', '', $item);
    // [pt19c-A fix 1 / architect P1, ai review] the re-arm (S4.6, model row 24) may follow ONLY "none found for the item" or
    // "a T-key refused by SHAPE" - never a LEAVE, a leave-guard show, a park, a rail, B1 or a check refusal
    $GLOBALS['LRG_DLG_GATE_REARM'] = false;
    $hold = lrgDlgOpenTurnHold($t, $st);
    if ($hold !== '') { lrgDlgLog('gate: ' . $hold, $cid); return null; }
    $d = lrgDlgDecide($t, $item, $st);
    // no list at all: this is a first contact - ask the game to open for awareness (gated, design 2.1)
    if ((string) $d['do'] === 'open') { return lrgDlgMaybeOpen($t, $item); }
    $GLOBALS['LRG_DLG_GATE_REARM'] = (string) $d['do'] === 'none' && (!empty($d['shape']) || !empty($d['nomatch']));
    return lrgDlgApplyDecision($t, $d, $st);
}

/**
 * [pt19c-A fix 1 / architect P4, spec S1.3] The model named the business in WORDS (intent mode), decided side-effect free so
 * lrgDlgWillEmit covers it too: follower verbs -> the enlistment -> the price-list slot -> the matcher -> a service by kind,
 * then the rails (lrgDlgDecideEntry). ['do' => 'open'] when no list is known (the gate asks the game to open for awareness).
 * The arbitrators log (silenced under WillEmit) and write nothing.
 */
function lrgDlgDecideWords(array $t, string $item, array $st): array
{
    $cid = (string) ($t['cid'] ?? '');
    $pool = array_values(array_filter(array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])),
        static fn($x) => (string) $x['class'] !== 'hidden'));
    if (!$pool) { return ['do' => 'open', 'mode' => 'open', 'why' => '', 'entry' => null]; }
    if ((string) ($t['ro'] ?? '') !== '') { return lrgDlgNo('read-only session why=' . (string) $t['ro'] . ' - nothing is picked (the list is his)'); }
    $e = null;
    $mode = 'intent';
    $m2 = null;
    // ---- [0.5.1] THE FOLLOWER VERBS RUN FIRST: exact containment against the framework's OWN entries, never similarity
    $folOut = lrgDlgFollowerArbitrate($t, $pool, $item, $cid);
    if (is_array($folOut)) {
        if ($folOut['entry'] === null) { return lrgDlgNo(''); }
        $e = $folOut['entry'];
        $mode = 'follower';
    }
    // ---- [0.5.7 / pt16-legion] an ENLISTMENT: the exact join entry or her words, never similarity
    $facOut = $e === null ? lrgFacArbitrate($t, $pool, $item, $cid) : null;
    if (is_array($facOut)) {
        if ($facOut['entry'] === null) { return lrgDlgNo(''); }
        $e = $facOut['entry'];
        $mode = 'faction';
    }
    // ---- [0.5.0 / E6] THE SLOT MATCHER, and it blocks the fallback on a price list (risk R12)
    $svcOut = $e === null ? lrgDlgServiceArbitrate($t, $pool, $item, $cid) : null;
    if (is_array($svcOut)) {
        if ($svcOut['entry'] === null) { return lrgDlgNo(''); }
        $e = $svcOut['entry'];
        $mode = 'slot';
    }
    $mkt = function_exists('lrgMktState') && in_array(lrgMktState($t), LRG_MKT_ACTIVE, true);
    if ($e === null) {
        $utter = !empty($t['speech']) ? (string) ($st['utter']['text'] ?? '') : '';
        $m2 = lrgDlgMatchText(lrgDlgStripVocative($item, (string) ($t['npc'] ?? '')), $pool);   // (his form of address picks nothing)
        $pi = lrgDlgMatchPick($m2, $pool, (float) lrgDlgCfg('match.min_score', 0.55), (float) lrgDlgCfg('match.min_margin', 0.15));
        if ($pi === null && $m2 !== null) { $pi = lrgDlgUniqueWordTie($m2, $pool, $item); }
        if ($pi !== null && lrgDlgNegationClash($item, (array) $pool[$pi])) { $pi = null; }
        // [pt19c fixer / adversarial QA "WORDS MODE"] the model's WORDS resolve a line only as his own words would on the fast
        // path: a question, a refusal, a bargain, an answer to the line's own question or another line's word is not it
        // (lrgDlgWordsQualm) - and when they are HIS words (he said them), his negation / refusal / bargain count too
        // (and when the model's item IS his sentence, his words must carry the line exactly as on the fast path - lrgDlgWordsCarry:
        // "I need to talk to Farengar" is no "I need to talk to you.", "who am I?" no "Who are you?")
        $ni = lrgPromptNorm($item);
        $itemIsHis = $utter !== '' && $ni !== '' && ($ni === lrgPromptNorm($utter) || (strlen($ni) >= 40 && str_starts_with(lrgPromptNorm($utter), $ni)));
        // (a single-entry layer is judged by S4.5 below instead)
        $sg0 = is_array($t['single'] ?? null) ? (array) $t['single'] : null;
        $isSg0 = $pi !== null && $sg0 && (int) ($sg0['pos'] ?? -1) === (int) ($pool[$pi]['pos'] ?? -2)
            && (string) ($sg0['norm'] ?? '') === (string) ($pool[$pi]['norm'] ?? '');
        if ($pi !== null && $itemIsHis && !$isSg0 && !lrgDlgWordsCarry(lrgDlgStripVocative($item, (string) ($t['npc'] ?? '')), (array) $pool[$pi])) {
            // [pt19h r2 / reach P5, the first-evening words rows] a one-word STT echo read as a PROTECTED line ("i'd like to rent a groom" / "I'd like
            // to rent a room. (10 gold)") is no click on his words alone - but she asks, quoting it, and his yes releases it (S4.4); a commit, a
            // qualm, a negation, a bargain, a refusal, a hedge or a deferral around it: nothing, her words answer
            $pe = (array) $pool[$pi];
            $sv = lrgDlgStripVocative($item, (string) ($t['npc'] ?? ''));
            $rp = lrgDlgSttRepair($sv, $pe);
            if ($rp !== '' && lrgDlgReachContains($rp, $pe) && lrgDlgProtected($pe) && empty($pe['commit']) && (string) ($pe['class'] ?? '') !== 'commit'
                && lrgDlgWordsQualm($item, $pe, $pool, (string) ($t['npc'] ?? ''), $m2) === '' && !lrgDlgNegationClash($utter, $pe) && !lrgDlgBargains($utter, $pe)
                && !lrgDlgRefuses($utter) && !lrgDlgHedges($utter) && !lrgDlgDefers($utter, $pe)) {
                $p = lrgDlgParkOrRelease($t, $pe, $st);
                if ($p['do'] === 'release') { return ['do' => 'pick', 'mode' => (string) $p['mode'], 'entry' => $pe, 'why' => (string) $p['why']]; }
                return ['do' => (string) $p['do'], 'mode' => 'park', 'entry' => $pe, 'why' => 'an STT echo in "' . substr($item, 0, 40) . '" reads as the protected line "'
                    . substr((string) ($pe['text'] ?? ''), 0, 40) . '" - she asks, quoting it (S4.4)' . ((string) $p['why'] !== '' ? ' - ' . (string) $p['why'] : '')];
            }
            $pi = null;
        }
        if ($pi !== null && (lrgDlgWordsQualm($item, (array) $pool[$pi], $pool, (string) ($t['npc'] ?? ''), $m2) !== ''
            || ($utter !== '' && (lrgDlgNegationClash($utter, (array) $pool[$pi]) || lrgDlgBargains($utter, (array) $pool[$pi])
                || (lrgDlgRefuses($utter) && !lrgDlgIsBackOut((string) (((array) $pool[$pi])['text'] ?? ''), true)))))) {
            $pi = null;
        }
        if ($pi !== null) {
            // [S5] his own words decide what a service line means: named but not asked for, or two of that kind -> nothing
            $sense = $utter !== '' ? lrgDlgServiceSense($pool, (array) $pool[$pi], $utter) : '';
            if ($sense === 'contradicted' || ($sense === 'ambiguous' && (float) $m2['eff'] < 0.85)) {
                return lrgDlgNo('"' . substr($item, 0, 40) . '" -> a service line his words do not ask for, or one of two (' . $sense . ') - nothing executed');
            }
            if ($sense === 'kind') { $mode = 'kind'; }
        }
        // [pt19c fixer / adversarial QA "WORDS MODE"] on a SINGLE-entry layer the model's words (no key) are judged as his would
        // be (S4.5): "what does that mean?", "..." or "why should I swear?" never say the oath for him - the similarity of one
        // candidate against itself is no evidence (its margin is its score)
        $sg = is_array($t['single'] ?? null) ? (array) $t['single'] : null;
        $sgRel = ($pi !== null && $sg && (int) ($sg['pos'] ?? -1) === (int) ($pool[$pi]['pos'] ?? -2)
            && (string) ($sg['norm'] ?? '') === (string) ($pool[$pi]['norm'] ?? '')) ? lrgDlgSingleEntryRelease($sg, $item) : null;
        if (is_array($sgRel) && empty($sgRel['release'])) {
            // (a refusal re-arms nothing - model row 24; a question or no match may: the breath goes on after her answer)
            return lrgDlgNo('"' . substr($item, 0, 40) . '" - the model\'s words do not release the single line (S4.5 ' . $sgRel['step']
                . ') - nothing executed', (!empty($sgRel['refused']) && empty($sgRel['shape'])) ? [] : ['nomatch' => 1]);
        }
        if ($pi !== null) {
            $e = $pool[$pi];
        } else {
            // [S5 / U4] a service BY KIND from his words, only when the similarity path chose nothing - and never beside an
            // active voice order of food or drink (10.27 owns that turn: no trade line next to ExtCmdLRG_Buy, model F18)
            $kp = ($utter !== '' && !$mkt) ? lrgDlgKindPick($pool, $utter, (string) ($t['layer_kind'] ?? ''))
                : ['entry' => null, 'why' => $mkt ? 'a voice order owns this turn (F18)' : ''];
            if (is_array($kp['entry'])) {
                $e = $kp['entry'];
                $mode = 'kind';
            } else {
                return lrgDlgNo(sprintf('"%s" matched nothing well enough (score=%s margin=%s%s)%s - nothing executed',
                    substr($item, 0, 40), $m2['score'] ?? '-', $m2['margin'] ?? '-', !empty($m2['short']) ? ', a short containment' : '',
                    (string) $kp['why'] !== '' ? ' (' . $kp['why'] . ')' : ''), ['nomatch' => 1]);
            }
        }
    }
    // [pt19c-A fix 1 / architect P5, model F18] a voice order owns this turn: no service line beside ExtCmdLRG_Buy, however
    // the model's words resolved (a similarity hit on "What have you got for sale?" too - lrgMktNet would stand down for it)
    if ($mkt && in_array($mode, ['intent', 'kind'], true) && in_array((string) (((array) $e)['class'] ?? ''), ['service', 'pay'], true)) {
        return lrgDlgNo('a voice order of food or drink owns this turn (10.27, F18) - "' . substr((string) ((array) $e)['text'], 0, 40) . '" is not clicked');
    }
    $d = lrgDlgDecideEntry($t, (array) $e, $mode, $st);
    if (is_array($m2)) { $d['overlap'] = (float) $m2['f1']; }
    return $d;
}

/**
 * [pt19 v1.0 / S1.3] THE ONE DECISION, side-effect free: what the gate would do with this item (a T-key, LEAVE, or the model's
 * WORDS - lrgDlgDecideWords, pt19c-A fix 1) on this turn. ['do' => pick | leave | show | park | unpark | still | none | open,
 * 'mode', 'why', 'entry', 'kind', 'rail', 'shape', 'nomatch']. lrgDlgGateItem applies it (the park write, the emit, the open
 * for awareness); lrgDlgWillEmit is its twin (TRUE for an immediate pick - key, words, kind, slot, follower, faction,
 * explicit, single, released park - and for LEAVE with a back line).
 */
function lrgDlgDecide(array $t, string $item, array $st): array
{
    $item = (string) preg_replace('/^\s*item\s*=\s*/i', '', $item);
    $up = strtoupper(trim($item));
    if ($up === 'LEAVE') {
        if ((string) ($t['ro'] ?? '') !== '') { return lrgDlgNo('read-only session why=' . (string) $t['ro'] . ' - the list is his'); }
        // [pt19h-safety / G8] only a REAL back-out line (lrgDlgRealBackOut): class back from a word inside a quest line ("Nothing I
        // couldn't handle.", "Ulfric holds nothing worth trading Markarth for.") or a scripted one ("Forget it. I'll just open it
        // myself." - the fight) is no way out; LEAVE then takes the engine cancel, or the guard below
        // [pt19h r2 / safety P11] ... on the ranked head AND the tail (a 15-line layer ranked "Never mind." into the tail: LEAVE took the
        // engine cancel, and the walk-away topic played - the very thing the guard exists for)
        foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
            if (lrgDlgRealBackOut((array) $e)) { return ['do' => 'leave', 'mode' => 'key', 'why' => 'the back line', 'entry' => $e]; }
        }
        // [S4.2] THE LEAVE GUARD: no line backs out cleanly and a line here walks out on her - the engine cancel
        // (pos=-1) would play the walk-away topic, so nothing is clicked (do=show kind=back: the game answers OK and
        // keeps driving, F3) and her own line says leaving is his to do
        if (lrgDlgLeaveGuarded($t)) {
            return ['do' => 'show', 'mode' => 'leave-guard', 'kind' => 'back', 'entry' => null,
                'why' => 'leave-guard: no back line and a walk-away line on this layer - leaving is his to do by hand'];
        }
        return ['do' => 'leave', 'mode' => 'key', 'why' => 'the engine cancel', 'entry' => null];
    }
    if (!preg_match('/^T(\d{1,2})$/', $up, $mm)) { return lrgDlgDecideWords($t, $item, $st); }
    // [S1.3] a read-only session offered no key: say WHY in the log the owner greps (gate: read-only session why=...)
    if ((string) ($t['ro'] ?? '') !== '') { return lrgDlgNo('read-only session why=' . (string) $t['ro'] . ' - nothing is picked (the list is his)'); }
    $keys = (array) (($t['offer'] ?? [])['keys'] ?? []);
    $k = $keys['T' . (int) $mm[1]] ?? null;
    if (!is_array($k)) { return lrgDlgNo('key ' . $up . ' was not offered this turn - dropped stale'); }
    foreach ((array) ($t['entries'] ?? []) as $cand) {
        if ((int) $cand['pos'] === (int) $k['pos'] && (string) $cand['norm'] === (string) $k['norm']) {
            return lrgDlgDecideEntry($t, (array) $cand, 'key', $st);
        }
    }
    return lrgDlgNo('key ' . $up . ' is not in this turn\'s list any more - dropped stale');
}

function lrgDlgNo(string $why, array $extra = []): array
{
    return ['do' => 'none', 'mode' => '', 'why' => $why, 'entry' => null] + $extra;
}

/**
 * [pt19 v1.0 / S4.9] The rails, in order, for ONE resolved entry (gate and fast path alike): hidden -> read-only ->
 * resist-arrest (do=show) -> follower never_topics -> arrest-class (do=show) -> freeze B1 -> crit 2 (do=show) -> meta ->
 * stage rail -> pay & !afford -> check rails -> the single-entry layer (step 0 on every pick of it; a commit single
 * releases through S4.5) -> intent mode's classes -> the two-step (explicit, the engine's own confirmation, the park).
 */
function lrgDlgDecideEntry(array $t, array $e, string $mode, array $st): array
{
    $txt = substr((string) ($e['text'] ?? ''), 0, 40);
    if ((string) ($e['class'] ?? '') === 'hidden') { return lrgDlgNo('a hidden entry'); }
    if ((string) ($t['ro'] ?? '') !== '') { return lrgDlgNo('read-only session why=' . (string) $t['ro'] . ' - nothing is picked (the list is his)'); }
    // [0.5.0 / E6, risk R16] RESIST ARREST IS NEVER VOICE-SELECTABLE, AT ANY SETTING
    if (lrgDlgIsResistArrest($e)) {
        return ['do' => 'show', 'mode' => 'lethal', 'kind' => 'meta', 'svc' => 'crime', 'entry' => $e,
            'why' => '"' . $txt . '" is a RESIST-ARREST entry - never selectable by voice at any setting; the menu is his'];
    }
    // [0.5.1 / pt9 6.2] follower topics that are never selectable by voice, however they were resolved
    if (lrgDlgFollowerBlockedEntry($e)) {
        return lrgDlgNo('"' . $txt . '" is on topic ' . (string) ($e['topic'] ?? '?') . ' - a follower topic that is never selectable by voice');
    }
    // [0.5.0 / E6 section 18.5] an ARREST session: the menu is his (iCritical 0, decision D5)
    if ((string) ($t['arrest'] ?? '') !== '' && !empty(lrgDlgCfg('services.kinds.crime.lethal', true)) && (int) ($t['crit'] ?? 0) !== 1) {
        return ['do' => 'show', 'mode' => 'lethal', 'kind' => 'meta', 'svc' => 'crime', 'entry' => $e,
            'why' => 'ARREST session (detected by ' . (string) $t['arrest'] . ') - the menu is his, nothing is chosen by voice'];
    }
    // the freeze rule (B1 / design 4.4): the shown variant is bound when the list is BUILT
    if ((int) ($t['list_pg'] ?? 0) > 0 && (int) (($t['facts'] ?? [])['pg'] ?? 0) !== (int) $t['list_pg']) {
        return lrgDlgNo('the player\'s gold moved since the list was read (' . (int) $t['list_pg'] . ' -> '
            . (int) (($t['facts'] ?? [])['pg'] ?? 0) . ') - re-read, never click (freeze rule B1)');
    }
    if ((int) ($e['crit'] ?? 0) === 2 || (int) ($t['crit'] ?? 0) === 2) {
        return ['do' => 'show', 'mode' => 'lethal', 'kind' => 'meta', 'entry' => $e,
            'why' => 'LETHAL entry ("' . $txt . '") - the menu is his, never clicked'];
    }
    if ((string) ($e['class'] ?? '') === 'meta') { return lrgDlgNo('META entry - never executed by voice'); }
    if (lrgDlgStageRailBlocks($e)) {
        return lrgDlgNo('stage rail - no click is verified on this install yet and "' . $txt . '" is not an indexed plain line', ['rail' => 1]);
    }
    if ((string) ($e['class'] ?? '') === 'pay' || (int) ($e['cost'] ?? 0) > 0) {
        if (empty($e['afford'])) {
            return lrgDlgNo('priced entry ' . (int) $e['cost'] . ' septims, the player has ' . (int) (($t['facts'] ?? [])['pg'] ?? 0) . ' - never clicked');
        }
    }
    if ((string) ($e['kind'] ?? '') !== '') {
        $bad = lrgDlgCheckRails($t, $e, $st);
        if ($bad !== '') { return lrgDlgNo($bad); }
    }
    $utter = !empty($t['speech']) ? (string) (((array) ($st['utter'] ?? []))['text'] ?? '') : '';
    $commit = !empty($e['commit']) || (string) ($e['class'] ?? '') === 'commit';
    // ---- [S4.5] THE SINGLE-ENTRY LAYER never asks. Step 0 applies to EVERY pick of it (a question to her is never
    // clicked; a refusal is not an answer); a COMMIT single must pass steps 0-5, else it parks and she asks, quoting it.
    $single = is_array($t['single'] ?? null) ? $t['single'] : null;
    $isSingle = $single && (int) $single['pos'] === (int) ($e['pos'] ?? -1) && (string) $single['norm'] === (string) ($e['norm'] ?? '');
    $rel = null;
    if ($isSingle && $utter !== '') {
        $rel = lrgDlgSingleEntryRelease($e, $utter);
        if (!empty($rel['refused'])) {
            return lrgDlgNo('single entry "' . $txt . '": ' . $rel['step'] . ' - nothing is clicked', ['shape' => !empty($rel['shape']) ? 1 : 0]);
        }
        if (!empty($rel['release'])) {
            return ['do' => 'pick', 'mode' => 'single', 'entry' => $e, 'why' => 'single entry released by his words (step ' . $rel['step'] . ')'];
        }
        if (!$commit) {
            return ['do' => 'pick', 'mode' => $mode, 'entry' => $e, 'why' => 'a single entry that is not a commit: the model\'s pick is trusted'];
        }
    }
    // ---- [pt19h r2 / grading P1, P12 - THE KEY-MODE RAIL] a plain line that still runs a script (scripted, an Invisible Continue - a
    // converging sibling among them) on her T-key: his words this turn refuse, defer or hedge it, quote it with a qualm, negate it, or ask
    // what the line does not - her words answer, nothing is clicked (lrgDlgKeyRailWhy; what the fast path's WordsQualm does, here)
    if ($mode === 'key' && !$commit && !$isSingle && $utter !== '' && ((int) ($e['scripted'] ?? 0) === 1 || (int) ($e['invis'] ?? 0) === 1)) {
        $kw = lrgDlgKeyRailWhy($utter, $e);
        // [pt19h r2 / extended G3 MQ04.neloth.justtell, SV01.tharstan.paying] a QUESTION of his against the line, the line asked back ("something
        // dangerous?") or a hedge around it parks it - she asks, quoting it, and his yes releases it (S4.4) - as the commit it converged from did
        // ("where's the book" / "Just tell me where the book is and I'll go get it."); a refusal, a deferral, a negation or a keep gets her words,
        // nothing is clicked
        $parkIt = str_starts_with($kw, 'a question') || $kw === 'his words around the line asks' || $kw === 'a hedge';
        if ($kw !== '' && $parkIt) {
            $p = lrgDlgParkOrRelease($t, $e, $st);
            if ($p['do'] === 'release') { return ['do' => 'pick', 'mode' => (string) $p['mode'], 'entry' => $e, 'why' => (string) $p['why']]; }
            return ['do' => (string) $p['do'], 'mode' => 'park', 'entry' => $e, 'why' => 'key rail: "' . substr($utter, 0, 40) . '" asks what the scripted line "'
                . $txt . '" does not - she asks, quoting it (S4.4)' . ((string) $p['why'] !== '' ? ' - ' . (string) $p['why'] : '')];
        }
        if ($kw !== '') { return lrgDlgNo('key rail: "' . substr($utter, 0, 40) . '" against the scripted line "' . $txt . '" - ' . $kw . ', her words answer'); }
    }
    // ---- intent mode (the model's WORDS, or a kind): plain / service, a priced service by kind; a commit only through
    // his own plain sentence (S4.3) - checked below
    if (in_array($mode, ['intent', 'kind'], true)) {
        $ok = ['plain', 'service'];
        if ($mode === 'kind') { $ok[] = 'pay'; }
        if (!in_array((string) ($e['class'] ?? ''), $ok, true) && !$commit) {
            return lrgDlgNo('"' . $txt . '" is a ' . (string) ($e['class'] ?? '?') . ' entry - intent mode never executes that class');
        }
    }
    // ---- two-step confirmation ------------------------------------------------------------------------------------------
    // (a single-entry commit's content test IS S4.5 - the explicit test's margin is trivially met against no sibling)
    $scoff = lrgDlgScoffFirst($t, $e, $st);
    if ($commit || $scoff) {
        if (!$scoff && !$isSingle && lrgDlgExplicit($t, $e, $utter, $mode, $st)) {
            return ['do' => 'pick', 'mode' => 'explicit', 'entry' => $e, 'why' => 'his own sentence said it plainly (S4.3)'];
        }
        if (in_array($mode, ['intent', 'kind'], true) && !$scoff) {
            // a commit from the model's WORDS alone is never clicked: the model asks with a key next turn
            return lrgDlgNo('"' . $txt . '" commits and his words did not say it plainly - intent mode never executes it');
        }
        // [pt19c fixer / QA wording "her question can turn his words around"] HIS words negate a plain STATEMENT line that has
        // no negator of its own ("I don't want the attic room" against "I'd like to rent the attic room", "I won't come with you"
        // against "I'll come along with you."): she never asks him to confirm it - his "yes" would then pay for what he refused.
        // Nothing, her words answer (G1 on the park path, one direction only: a line that is itself a refusal or a question -
        // "I don't have time for this.", "Break the law? Are you kidding?" - still asks, quoting it; a release of a park already
        // standing is judged by S4.4 below)
        $pk0 = (array) ($st['parked'] ?? []);
        $newPark = !$pk0 || (string) ($pk0['norm'] ?? '') !== (string) ($e['norm'] ?? '') || lrgNow() > (int) ($pk0['expires'] ?? 0);
        if ($newPark && $utter !== '' && !lrgDlgLayerIsOwnConfirmation($t) && !lrgDlgEntryIsQuestion($e)
            && !preg_match('/\b(not|no|never|don\'?t|won\'?t|can\'?t|cannot|isn\'?t|aren\'?t|didn\'?t|doesn\'?t|wasn\'?t|nothing|nobody)\b/i', (string) ($e['text'] ?? ''))
            && lrgDlgNegationClash($utter, $e)) {
            return lrgDlgNo('"' . substr($utter, 0, 40) . '" negates "' . $txt . '" - she does not ask him to confirm it');
        }
        $p = lrgDlgParkOrRelease($t, $e, $st);
        if ($p['do'] === 'release') { return ['do' => 'pick', 'mode' => (string) $p['mode'], 'entry' => $e, 'why' => (string) $p['why']]; }
        return ['do' => (string) $p['do'], 'mode' => 'park', 'entry' => $e, 'why' => (string) $p['why']];
    }
    return ['do' => 'pick', 'mode' => $mode, 'entry' => $e, 'why' => ''];
}

/**
 * Apply one decision: the park write, the emit, the log. The stage rail's corner note rides once per session (S3.3).
 */
function lrgDlgApplyDecision(array $t, array $d, array $st): ?string
{
    $npc = (string) $t['npc'];
    $cid = (string) $t['cid'];
    $e = is_array($d['entry'] ?? null) ? $d['entry'] : null;
    $do = (string) $d['do'];
    if ($do === 'pick') {
        if ((string) $d['why'] !== '') { lrgDlgLog('gate: ' . (string) $d['why'], $cid); }
        // a park belongs to the layer it was written on: ANY click moves the menu on, so the park goes with it - a stale
        // park of another line must never be released by a later "yes" (S4.4 names ONE choice on ONE layer)
        $pk = (array) ($st['parked'] ?? []);
        if ($pk) {
            lrgDlgPut($npc, ['parked' => null]);
            // [pt19c fixer / walkthrough M6] a park released by his assent: the sentence that parked it may still name the slot of
            // the price list this click opens (the hire line, then "Whiterun") - kept for that one list (lrgDlgAnswerWant)
            if ((string) ($pk['norm'] ?? '') === (string) ($e['norm'] ?? '') && in_array((string) $d['mode'], ['bare-yes', 'release'], true)
                && trim((string) ($pk['raw'] ?? '')) !== '') {
                lrgDlgPut($npc, ['chain_utter' => ['text' => (string) $pk['raw'], 'cid' => $cid, 'at' => lrgNow()]]);
            }
            if ((string) ($pk['norm'] ?? '') !== (string) ($e['norm'] ?? '')) {
                lrgDlgLog('gate: the park of "' . substr((string) ($pk['text'] ?? ''), 0, 40) . '" is dropped - another line was picked', $cid);
            }
        }
        return lrgDlgEmit($npc, [
            'do' => 'pick', 'sid' => (string) $t['sid'], 'gen' => (int) $t['gen'], 'pos' => (int) $e['pos'],
            'i' => (int) $e['i'], 'txt' => lrgDlgSafePrefix((string) $e['text']),
            'kind' => (string) ($e['kind'] !== '' ? $e['kind'] : $e['class']), 'cost' => (int) $e['cost'],
            'overlap' => isset($d['overlap']) ? (float) $d['overlap'] : -1,
        ], $cid, (string) $d['mode'], $e);
    }
    if ($do === 'leave') {
        return lrgDlgEmit($npc, ['do' => 'leave', 'sid' => (string) $t['sid'], 'gen' => (int) $t['gen'],
            'pos' => $e ? (int) $e['pos'] : -1, 'i' => $e ? (int) $e['i'] : -1,
            'txt' => $e ? lrgDlgSafePrefix((string) $e['text']) : '', 'kind' => 'back'], $cid, (string) $d['mode'], $e);
    }
    if ($do === 'show') {
        lrgDlgLog('gate: ' . (string) $d['why'], $cid);
        return lrgDlgEmit($npc, ['do' => 'show', 'sid' => (string) $t['sid'], 'gen' => (int) $t['gen'],
            'pos' => -1, 'i' => -1, 'txt' => '', 'kind' => (string) ($d['kind'] ?? 'meta'), 'svc' => (string) ($d['svc'] ?? '')],
            $cid, (string) $d['mode'], $e);
    }
    if ($do === 'park' && $e) {
        $now = lrgNow();
        $said = '';
        $tsf = $GLOBALS['talkedSoFar'] ?? null;
        if (is_array($tsf) && $tsf) { $said = implode(' ', array_map('strval', $tsf)); }
        if (trim($said) === '') { $said = (string) ($GLOBALS['LAST_LLM_RESPONSE']['message'] ?? ''); }
        lrgDlgPut($npc, ['parked' => ['norm' => (string) $e['norm'], 'text' => (string) $e['text'],
            'kind' => (string) ($e['kind'] ?: $e['class']), 'pos' => (int) $e['pos'], 'i' => (int) $e['i'],
            'sid' => (string) $t['sid'], 'gen' => (int) $t['gen'], 'at' => $now,
            // the words that parked it, so the release can tell a real second player turn from the same sentence again
            'utter' => lrgPromptNorm((string) (((array) ($st['utter'] ?? []))['text'] ?? '')),
            // [pt19c fixer / M3] his sentence as said (a check's rails judge it again when his assent releases the park)
            'raw' => substr(trim((string) (((array) ($st['utter'] ?? []))['text'] ?? '')), 0, 200),
            // [S4.4] what she asked (her question names the choice), and whether the two-candidate hint asked WHICH
            'said' => substr(trim($said), 0, 300), 'hint' => count((array) ($t['hint2'] ?? [])) === 2 ? 1 : 0,
            'expires' => $now + (int) lrgDlgCfg('confirm.park_seconds', 60), 'req' => $cid]]);
        lrgDlgLog('gate: first selection of "' . substr((string) $e['text'], 0, 40) . '" PARKED - her question is spoken'
            . ' and his answer releases it' . ((string) $d['why'] !== '' ? ' (' . (string) $d['why'] . ')' : ''), $cid);
        return null;
    }
    if ($do === 'unpark') {
        lrgDlgPut($npc, ['parked' => null]);
        lrgDlgLog('gate: ' . (string) $d['why'] . ' - the parked selection is dropped, nothing is executed', $cid);
        return null;
    }
    // (an arbitrator that refused already logged its own line: why '' adds nothing)
    if ((string) $d['why'] !== '') { lrgDlgLog('gate: ' . (string) $d['why'], $cid); }
    // [pt19 v1.0 / S7, Lane B] her words were spoken and the pick was refused here (afford / amount / words / retry / frozen):
    // the plain sentence is kept for the next turn's <what_just_happened> (lib/lrg_actions.php lrgDlgNoteRefusal)
    if ($do === 'none' && !empty($t['speech']) && function_exists('lrgDlgNoteRefusal')) { lrgDlgNoteRefusal($npc, (string) $d['why']); }
    if (!empty($d['rail'])) { return lrgDlgRailNote($t); }
    return null;
}

/** [S3.3] The stage rail's corner note, once per session (a do=noop with note=, the same carrier as the quest hint). */
function lrgDlgRailNote(array $t): ?string
{
    $npc = (string) $t['npc'];
    $sid = (string) ($t['sid'] ?? '0');
    $st = lrgDlgState($npc);
    if ((string) ($st['rail_note_sid'] ?? '') === $sid) { return null; }
    lrgDlgPut($npc, ['rail_note_sid' => $sid]);
    // [pt19c-A fix 1 / game review 7, ai review] never false: no "any line" (commits still ask, lethal / arrest / scene lines
    // are never picked), no "her" for every NPC, and on a list where no key can pass (U7) no condition that cannot come true
    $any = false;
    $i = 0;
    foreach ((array) ($t['entries'] ?? []) as $e) {
        $i++;
        if (isset(((array) (($t['offer'] ?? [])['keys'] ?? []))['T' . $i]) && !lrgDlgStageRailBlocks((array) $e)) { $any = true; break; }
    }
    $note = $any ? 'not picked yet - ask something simple first (a question) and then this line can be picked for you'
        : 'not picked - this list is yours to choose from this once';
    // [pt19c final fixer / S3.3] kind=rail, not kind=hint: the stage rail's note is no quest hint, so "Tell me when she has something"
    // (bQuestHint) must not silence it - LRG_Main's kind=rail branch shows it once per conversation (its sid) whatever that toggle says
    return lrgDlgEmit($npc, ['do' => 'noop', 'sid' => $sid, 'gen' => (int) ($t['gen'] ?? 0), 'pos' => -1, 'i' => -1, 'txt' => '',
        'kind' => 'rail', 'note' => $note], (string) $t['cid'], 'rail-note', null);
}

/**
 * [pt19 v1.0 / S4.4, model F27] The parked line, when he answered her naming question with an assent and the model
 * answered in words only (no key): released as mode bare-yes, through the same rails (lrgDlgDecideEntry). A refusal
 * un-parks. Returns the wire line or null.
 */
function lrgDlgBareYesLine(array $t): ?string
{
    if (empty($t['speech']) || empty(lrgDlgCfg('confirm.bare_yes', true))) { return null; }
    $npc = (string) $t['npc'];
    $st = lrgDlgState($npc);
    $p = (array) ($st['parked'] ?? []);
    if (!$p || lrgNow() > (int) ($p['expires'] ?? 0) || (string) ($p['req'] ?? '') === (string) $t['cid']) { return null; }
    $e = null;
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $c) {
        if ((string) ($c['norm'] ?? '') === (string) ($p['norm'] ?? '')) { $e = $c; break; }
    }
    if ($e === null) { return null; }
    $utter = (string) (((array) ($st['utter'] ?? []))['text'] ?? '');
    if (lrgDlgRefuses($utter)) {
        return lrgDlgApplyDecision($t, ['do' => 'unpark', 'why' => '"' . substr($utter, 0, 40) . '" refuses "' . substr((string) $e['text'], 0, 30) . '"',
            'entry' => $e], $st);
    }
    // [pt19c-A fix 1 / ai review "consent", language review 1] the SAME park decision the gate makes (the question back, the
    // other line named, the "I will ..." sentence of its own, the unnamed park): only its bare-yes release is appended
    $pr = lrgDlgParkOrRelease($t, $e, $st);
    if ((string) $pr['do'] !== 'release' || (string) $pr['mode'] !== 'bare-yes') { return null; }
    $d = lrgDlgDecideEntry($t, $e, 'bare-yes', $st);
    if ((string) $d['do'] !== 'pick') { return null; }
    $d['mode'] = 'bare-yes';
    $d['why'] = 'his assent answers her naming question - the parked line goes out with her reply (model F27)';
    return lrgDlgApplyDecision($t, $d, $st);
}

/**
 * [pt19 v1.0 / S4.6 rev2] THE RE-ARM: he asked her something on a single unscripted layer during the breath, she answered in
 * words, and NO pick went out this turn (none found, or a T-key refused by shape): the same reply carries the adv pick
 * again with rearm=1 (the game counts 2.5 s from the END of her answer). A refusal never re-arms; a player speech turn only
 * (a rechat or a bystander never re-arms the breath - CHIM brief P12). WillEmit is FALSE for it: her answer plays.
 */
function lrgDlgRearmLine(array $t): ?string
{
    if (empty($t['speech']) || empty(lrgDlgCfg('auto_advance.rearm', true))) { return null; }
    if ((string) ($t['ro'] ?? '') !== '' || (int) ($t['crit'] ?? 0) === 2) { return null; }
    $e = is_array($t['single'] ?? null) ? $t['single'] : null;
    if ($e === null) { return null; }
    $adv = lrgDlgAdvMs($e);
    if ($adv < 0 || lrgDlgStageRailBlocks($e)) { return null; }
    $npc = (string) $t['npc'];
    $utter = (string) (((array) (lrgDlgState($npc)['utter'] ?? []))['text'] ?? '');
    // [pt19c-A fix 1 / language review 4] the breath's own refusal set: S4.5 step 0's back-out, hesitations and a leading
    // no / nope / stop / never - not R1's "I don't" / "I can't" / "not" ("I don't understand", "I don't know who they are",
    // "never heard of them" are answered and the line then advances) - and he is not LEAVING ("goodbye", "I have to go")
    if (trim($utter) !== '' && (lrgDlgIsBackOut($utter, true, true) || lrgDlgLeaveWords($utter))) { return null; }
    // [pt19c fixer / adversarial QA @single_neg, @adv_rearm] ... nor when he NEGATES the line or DISPUTES it with its own words
    // ("I'm not looking to apply to the college", "why would anyone apply to the college?"): the breath would say it for him
    if (trim($utter) !== '') {
        $rel = lrgDlgSingleEntryRelease($e, $utter);
        if (!empty($rel['refused']) && empty($rel['shape']) && preg_match('/negate|disputes/', (string) $rel['step'])) { return null; }
        // [pt19h r2 / safety arch P1] ... nor after a refusal or a deferral AROUND the line ("tell me about the College later", "<line>
        // tomorrow", "not now, <line>") or a keep: S4.5 step 0 refused it, so the breath may not say it for him (table 2.4 step 0 -
        // nothing, and no auto-advance); R1's "I don't understand" / "never heard of them" still re-arm
        if (!empty($rel['refused']) && empty($rel['shape'])
            && (str_starts_with((string) $rel['step'], '0 refusal (his words around the line') || str_starts_with((string) $rel['step'], '0 refusal (he keeps'))) { return null; }
        if (preg_match(LRG_DLG_DEFER_RE, strtolower($utter))) { return null; }
    }
    lrgDlgLog('gate: re-armed the breath on "' . substr((string) $e['text'], 0, 40) . '" - nothing was picked this turn and he did'
        . ' not refuse (adv=' . $adv . ' rearm=1: after her answer ends)', (string) $t['cid']);
    return lrgDlgEmit($npc, ['do' => 'pick', 'sid' => (string) $t['sid'], 'gen' => (int) $t['gen'], 'pos' => (int) $e['pos'],
        'i' => (int) $e['i'], 'txt' => lrgDlgSafePrefix((string) $e['text']), 'kind' => (string) ($e['kind'] !== '' ? $e['kind'] : $e['class']),
        'cost' => (int) $e['cost'], 'adv' => $adv, 'rearm' => 1], (string) $t['cid'], 'rearm', $e);
}

/** Rules that stop a speech-check click before it happens (design 4.5 + plan 6). */
function lrgDlgCheckRails(array $t, array $e, array $st): string
{
    $kind = (string) $e['kind'];
    $utter = (string) ($st['utter']['text'] ?? '');
    // [pt19c fixer / walkthrough M3, W5; adversarial QA @check_park] HIS ASSENT RELEASES A PARKED CHECK: she asked "you mean to
    // bribe him?", he said "yes" - the rails judge the sentence that PARKED it (its words, its named sum), never the one-word
    // answer to her own question ("a bribe attempt needs at least 4 words (1)" left him stuck, and the next turn told her the
    // false "he did not say it in a whole sentence")
    $pk = (array) ($st['parked'] ?? []);
    if ($pk && (string) ($pk['norm'] ?? '') === (string) ($e['norm'] ?? '') && lrgNow() <= (int) ($pk['expires'] ?? 0)
        && (string) ($pk['req'] ?? '') !== (string) ($t['cid'] ?? '') && trim((string) ($pk['raw'] ?? $pk['utter'] ?? '')) !== ''
        && lrgDlgLeadPhraseRaw($utter, (array) lrgDlgCfg('confirm.assent_words', [])) !== null) {
        $utter = (string) ($pk['raw'] ?? $pk['utter']);
    }
    // [pt19h-money / G13] a question, a price question, is no attempt - on her key and on the fast path alike (lib/lrg_speech.php);
    // BEFORE the min-words rail, so "how much?" is no refusal ("did not say it in a whole sentence" was false: nothing was tried)
    if (function_exists('lrgDlgCheckAskRail') && ($ask = lrgDlgCheckAskRail($t, $e, $utter)) !== '') { return $ask; }
    $words = count(preg_split('/\s+/', trim($utter)) ?: []);
    $min = (int) lrgDlgCfg('attempt.min_words', 4);
    if (($t['type'] ?? '') !== 'lrg_dlgtalk' && $words > 0 && $words < $min) {
        return 'a ' . $kind . ' attempt needs at least ' . $min . ' words on voice input (' . $words . ')';
    }
    if ($kind === 'bribe') {
        $n = (int) $e['cost'];
        if ($n > 0 && (int) ($t['facts']['pg'] ?? 0) < $n) { return 'bribe costs ' . $n . ', the player cannot pay'; }
        $named = lrgDlgNamedAmount($utter);
        if ($n > 0 && $named > 0 && $named < $n) { return 'the player offered ' . $named . ', the price is ' . $n; }
    }
    // retry suppression: a failed norm is re-clickable only when something relevant changed. OFF for
    // compound or unindexed entries, or the glue would talk the player out of attempts the engine passes.
    if (!empty(lrgDlgCfg('attempt.retry_suppression')) && !$e['compound'] && $e['indexed']) {
        $att = (array) ($st['attempts'][(string) $e['norm']] ?? []);
        if ($att && (string) ($att['result'] ?? '') === 'fail') {
            $sp = (int) ($t['facts']['sp'] ?? 0);
            $pg = (int) ($t['facts']['pg'] ?? 0);
            $wis = (int) ($t['facts']['wis'] ?? 0);
            $changed = $sp !== (int) ($att['sp'] ?? -1) || $pg !== (int) ($att['pg'] ?? -1)
                || $wis !== (int) ($att['wis'] ?? -1);
            if (!$changed) { return 'this exact ' . $kind . ' already failed and nothing relevant changed'; }
        }
    }
    return '';
}

/** Scoff-first (default on): the first selection parks, the second executes. OFF for compound/unindexed. */
function lrgDlgScoffFirst(array $t, array $e, array $st): bool
{
    if (empty(lrgDlgCfg('attempt.scoff_first'))) { return false; }
    if ((string) ($e['kind'] ?? '') === '') { return false; }
    if (!empty($e['compound']) || empty($e['indexed'])) { return false; }
    if ((int) ($t['facts']['wis'] ?? 1) === 0 && (string) $e['kind'] === 'intimidate') { return true; }
    return (string) ($e['variant'] ?? '') === 'failure' && ((int) ($e['scripted'] ?? 0) === 1 || (int) ($e['goodbye'] ?? 0) === 1);
}

/**
 * The confirmation state machine (design 4.5), SIDE-EFFECT FREE ([pt19 v1.0]: the caller writes). Returns ['do' =>
 * release | park | unpark | still, 'mode', 'why']. A vanilla "Are you sure?" sub-layer IS the confirmation.
 * [pt19 v1.0 / S4.4] THE BARE YES: on a new player turn, a refusal (checked FIRST) un-parks; a LEADING assent releases
 * when her parking message NAMED the line (a strict word of it, a match >= named_score, or a question - unless the
 * two-candidate hint asked WHICH on that turn - or it is the layer's only commit); a fuller answer (>= 2 tokens) releases;
 * a single other token stays parked.
 */
function lrgDlgParkOrRelease(array $t, array $e, array $st): array
{
    if (lrgDlgLayerIsOwnConfirmation($t)) {
        // [pt19h-safety / G1, LLM path] the engine's own Yes / No layer IS the confirmation - of HIS yes. After his refusal, deferral,
        // hedge or question ("I'm sure I need to think about it", "never mind", "no", "not now, <line> later", "I'm not sure I
        // understand the terms") the key on its YES side releases nothing: her words answer and the layer waits
        $u = !empty($t['speech']) ? trim((string) (((array) ($st['utter'] ?? []))['text'] ?? '')) : '';
        $eTx = (string) ($e['text'] ?? '');
        $noSide = lrgDlgIsBackOut($eTx, true)
            || (bool) preg_match('/^\s*(no|wait|never ?mind|not yet|on second thought|actually|i\'m not sure|i need to think)\b/i', $eTx);
        if ($u !== '' && !$noSide) {
            $qq = lrgDlgQuoteQualm($u, $e);
            // ([pt19h-safety review] + a deferral the line does not carry: "yeah, later" released "I'm sure.")
            if (lrgDlgRefuses($u) || lrgDlgHedges($u) || lrgDlgDefers($u, $e) || $qq['qualm'] !== ''
                || (lrgDlgIsQuestion($u) && !lrgDlgEntryIsQuestion($e))) {
                return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($u, 0, 40) . '" is no yes to "' . substr($eTx, 0, 30)
                    . '" - the engine\'s own confirmation waits for his yes'];
            }
        }
        return ['do' => 'release', 'mode' => 'confirm', 'why' => 'the engine\'s own confirmation layer'];
    }
    $p = (array) ($st['parked'] ?? []);
    $now = lrgNow();
    $txt = substr((string) ($e['text'] ?? ''), 0, 30);
    if (!$p || (string) ($p['norm'] ?? '') !== (string) ($e['norm'] ?? '') || $now > (int) ($p['expires'] ?? 0)) {
        return ['do' => 'park', 'mode' => 'park', 'why' => ''];
    }
    if ((string) ($p['req'] ?? '') === (string) $t['cid']) { return ['do' => 'still', 'mode' => '', 'why' => 'same request - still step one']; }
    // ---- [0.5.1 pt9 go-live / S2] A SECOND SELECTION IS NOT A CONFIRMATION by itself: the player must have SPOKEN since
    // the park (an lrg_dlgtalk poll or a rechat re-emits the item with no player turn between), and not the same words.
    if (empty($t['speech'])) {
        return ['do' => 'still', 'mode' => '', 'why' => 'no player turn since "' . $txt . '" was parked - still parked, never confirmed by silence'];
    }
    $utter = trim((string) (((array) ($st['utter'] ?? []))['text'] ?? ''));
    // [pt19h r2 / money arch P4] a park written from his BARE ASSENT (a priced hand-over alone on its layer: "yes" parked it, she named the
    // sum) is released by his next assent, whatever the same-words rule says - or his plain yes would never move it (model row 16)
    $parkedOnAssent = trim((string) ($p['raw'] ?? '')) !== '' && lrgDlgAssent((string) $p['raw']) !== null && lrgDlgAssent($utter) !== null;
    if ($utter === '' || (!$parkedOnAssent && (string) ($p['utter'] ?? '') !== '' && lrgPromptNorm($utter) === (string) $p['utter'])) {
        return ['do' => 'still', 'mode' => '', 'why' => 'the same words that parked "' . $txt . '" are not a confirmation of it - still parked'];
    }
    if (lrgDlgRefuses($utter)) {
        return ['do' => 'unpark', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" refuses "' . $txt . '"'];
    }
    // [pt19h r2 / safety P10] the words the rules refuse everywhere else release no park either: a refusal or a deferral AROUND the parked
    // line ("not now, <line> later", "no, <line>") and a bare deferral ("perhaps later", "yes, some other day") un-park it - "as you like";
    // an echo ("wait, <line>?", "is it true that <line>") or a hedge around it keeps the park, she asks again
    $qqP = lrgDlgQuoteQualm($utter, $e);
    if ($qqP['qualm'] === 'refuses' || preg_match(LRG_DLG_DEFER_RE, strtolower($utter))) {
        return ['do' => 'unpark', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" puts "' . $txt . '" off or refuses it'];
    }
    if (in_array($qqP['qualm'], ['asks', 'hedges'], true)) {
        return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" asks about or hedges "' . $txt . '" - still parked, she asks again'];
    }
    // [pt19h-safety review / G1 on S4.4] a HEDGE or a DEFERRAL is no yes to the parked line ("hmm maybe", "maybe I will", "I might",
    // "yes, maybe", "sure, in a bit", "yeah, later" each released it as a fuller answer): still parked, she asks again. An assent
    // phrase of the config said whole ("I guess so", "I suppose so" - confirm.assent_words) stays an assent.
    $assentWhole = in_array(lrgPromptNorm($utter), array_map(static fn($a) => lrgPromptNorm((string) $a), (array) lrgDlgCfg('confirm.assent_words', [])), true);
    if (!$assentWhole && (lrgDlgHedges($utter) || lrgDlgDefers($utter, $e))) {
        return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" hedges or puts off "' . $txt . '" - still parked, she asks again'];
    }
    $raw = lrgDlgLeadPhraseRaw($utter, (array) lrgDlgCfg('confirm.assent_words', []));
    if ($raw !== null && lrgDlgLeadVetoed((array) $raw['tail'])) {
        return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" takes its own assent back (yeah no, okay wait) - still parked, she asks again'];
    }
    // [pt19c-A fix 1 / language review 8] a QUESTION back ("wait, what?", "why would I do that?", "what happens if I do?")
    // is no answer to her question - unless it restates the parked line itself (a question line said again)
    if (lrgDlgIsQuestion($utter)) {
        // [pt19h r2 / safety P10] ... a line that is itself a question, only: a statement line asked back is no confirmation
        $mq = lrgDlgMatchText($utter, [$e]);
        if ($mq === null || (float) $mq['eff'] < (float) lrgDlgCfg('confirm.single_entry_exact', 0.85) || !lrgDlgEntryIsQuestion($e)) {
            return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" asks her something back - still parked, she answers and asks again'];
        }
    }
    // the strict meaning words of the OTHER visible lines (not the parked one's): a tail or a fuller answer carrying one of
    // those points at a sibling, never at the parked line ("I will kill him" beside the parked "I will spare him.")
    $ew = lrgPromptWords((string) ($e['norm'] ?? ''), true);
    $sib = [];
    $vis = [];
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $c) {
        if ((string) ($c['class'] ?? '') === 'hidden') { continue; }
        $vis[] = $c;
        if ((string) ($c['norm'] ?? '') === (string) ($e['norm'] ?? '')) { continue; }
        foreach (lrgPromptWords((string) ($c['norm'] ?? ''), true) as $w) { $sib[$w] = 1; }
    }
    // [pt19c fixer / walkthrough M4, @assent_sure] the words of an ASSENT never name a sibling: "yes, I'm sure" answers her
    // question, it does not point at "I'm not sure about this." (whose `sure` is negated there anyway)
    $assentW = [];
    foreach ((array) lrgDlgCfg('confirm.assent_words', []) as $a) { foreach (lrgDlgFoldTokens((string) $a) as $w) { $assentW[] = $w; } }
    $sib = array_values(array_diff(array_keys($sib), $ew, $assentW));
    $as = !empty(lrgDlgCfg('confirm.bare_yes', true)) ? lrgDlgAssent($utter) : null;
    if ($as !== null) {
        $tw = lrgPromptWords(lrgPromptNorm(implode(' ', (array) ($as['raw_tail'] ?? $as['tail']))), true);
        // [pt19c-A fix 1 / language review 1 (a)] the tail names ANOTHER line: an assent to that one, not to the park
        if (array_intersect($tw, $sib)) {
            return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" names another line than "' . $txt . '" - still parked, she asks again'];
        }
        // (b) "i do / i will / i swear" + a tail: a sentence of its own ("I will think it over", "I do have a question"),
        // an assent only when the tail says nothing the parked line does not
        if (in_array((string) $as['phrase'], ['i do', 'i will', 'i swear', 'i swear it'], true) && array_diff($tw, $ew)) {
            return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" is a sentence of its own, not an assent to "' . $txt . '" - still parked'];
        }
        if (lrgDlgParkNamed($p, $e, $t)) {
            return ['do' => 'release', 'mode' => 'bare-yes', 'why' => 'his "' . (string) $as['phrase'] . '" answers her question that named "' . $txt . '"'];
        }
        // [pt19c-A fix 1 / architect P3, model 2.5] her message did not name it (or the two-candidate hint asked WHICH): an
        // assent answers that only when its own tail names the parked line - never by the token count below
        if (!$as['tail'] || !array_intersect($tw, $ew)) {
            return ['do' => 'still', 'mode' => '', 'why' => 'an assent to a question that did not name "' . $txt . '" (or asked which) - still parked'];
        }
        return ['do' => 'release', 'mode' => 'release', 'why' => 'his assent names "' . $txt . '" in its own words'];
    }
    if (count(lrgDlgTokens(lrgPromptNorm($utter))) < 2) {
        return ['do' => 'still', 'mode' => '', 'why' => 'a single-token answer is not a confirmation - still parked'];
    }
    // a fuller answer that points at ANOTHER line is no confirmation of this one
    $uw = lrgPromptWords(lrgPromptNorm($utter), true);
    $mAll = $vis ? lrgDlgMatchText($utter, $vis) : null;
    if (array_intersect($uw, $sib) || ($mAll !== null && (float) $mAll['eff'] >= (float) lrgDlgCfg('match.min_score', 0.55)
        && (string) (($vis[$mAll['i']] ?? [])['norm'] ?? '') !== (string) ($e['norm'] ?? ''))) {
        return ['do' => 'still', 'mode' => '', 'why' => '"' . substr($utter, 0, 40) . '" points at another line than "' . $txt . '" - still parked'];
    }
    return ['do' => 'release', 'mode' => 'release', 'why' => 'his fuller answer confirms "' . $txt . '"'];
}

/** [S4.4] Did her parking message NAME the parked line (so a bare assent answers it)? */
function lrgDlgParkNamed(array $p, array $e, array $t): bool
{
    if (lrgNow() - (int) ($p['at'] ?? 0) > (int) lrgDlgCfg('confirm.park_seconds', 60)) { return false; }
    $commits = 0;
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $c) {
        if ((string) ($c['class'] ?? '') !== 'hidden' && (!empty($c['commit']) || (string) ($c['class'] ?? '') === 'commit')) { $commits++; }
    }
    if ($commits === 1 && (!empty($e['commit']) || (string) ($e['class'] ?? '') === 'commit')) { return true; }
    $said = trim((string) ($p['said'] ?? ''));
    if ($said === '') { return false; }
    if (array_intersect(lrgPromptWords(lrgPromptNorm($said), true), lrgPromptWords((string) ($e['norm'] ?? ''), true))) { return true; }
    $m = lrgDlgMatchText($said, [$e]);
    if ($m !== null && (float) $m['score'] >= (float) lrgDlgCfg('confirm.named_score', 0.45)) { return true; }
    return strpos($said, '?') !== false && empty($p['hint']);
}

/**
 * An NPC question with two opposite entries is the engine's OWN confirmation layer.
 * [pt19 v1.0 / section 2.2 Lane A] yes-shape += i'm ready, i am ready, let's do it, i'm sure; no-shape += actually, i'm not
 * sure, i need to think, not yet. (MQ102JoinLegionYes/No is the engine's own confirmation and matches neither: it asks.)
 */
function lrgDlgLayerIsOwnConfirmation(array $t): bool
{
    $entries = (array) ($t['entries'] ?? []);
    if (count($entries) !== 2) { return false; }
    $yes = $no = false;
    foreach ($entries as $e) {
        $low = strtolower((string) $e['text']);
        if (preg_match('/^\s*(yes|aye|i am sure|i\'m sure|do it|go ahead|i\'m ready|i am ready|let\'s do it)\b/', $low)) { $yes = true; }
        if (preg_match('/^\s*(no|wait|never ?mind|not yet|on second thought|actually|i\'m not sure|i need to think)\b/', $low)) { $no = true; }
    }
    return $yes && $no;
}

function lrgDlgNamedAmount(string $utter): int
{
    ///[0.5.1 pt9 go-live / S12] SPELLED-OUT AMOUNTS. lrgDlgCheckKind() recognises the bribe through
    // lrgIntentAmount(), which understands words - but this function, which both the free check and
    // lrgDlgCheckRails() use to PRICE it, was digits-only. So "Here is two hundred gold, just look the
    // other way" was classified kind=bribe and then priced at 0: the free check fell straight to
    // result='ask' ("no amount was named") every single turn, and on an engine bribe entry the
    // "he offered less than she will take" rail was skipped entirely because it is guarded by
    // $named > 0. lrgIntentAmount is asked FIRST and in its STRICT form (a number beside a money word,
    // either order) - it returns 0 for everything the two regexes below already handle on their own,
    // so nothing that worked before changes.
    if (function_exists('lrgIntentAmount')) {
        $n = (int) lrgIntentAmount($utter, false);
        if ($n > 0) { return $n; }
    }
    // [pt19c final fixer] the STT sum root lives in lrgIntentAmount (lrgIntentSumFold, LRG_MONEY_WORD): "5 hundred gold" 500,
    // "five hundred sept ums" 500 - so the engine bribe rail (lrgDlgCheckRails) reads his raw words right
    if (preg_match('/(\d[\d,\.]*)\s*(gold|septims?|coins?)\b/i', $utter, $m)) { return (int) preg_replace('/[^0-9]/', '', $m[1]); }
    if (preg_match('/\b(?:for|pay|offer|take)\s+(\d[\d,\.]*)\b/i', $utter, $m)) { return (int) preg_replace('/[^0-9]/', '', $m[1]); }
    return 0;
}

/** [pt19 / script 511] The game's quiet flag on this NPC's facts line, fresh (quiet.fresh_seconds, 300): a scripted intro runs. */
function lrgDlgQuietOn(string $npc): bool
{
    if ($npc === '') { return false; }
    $facts = (array) (lrgDlgState($npc)['facts'] ?? []);
    if ((int) ($facts['quiet'] ?? 0) !== 1) { return false; }
    $fresh = function_exists('lrgQuietCfg') ? (int) lrgQuietCfg('fresh_seconds', 300) : 300;
    return lrgNow() - (int) ($facts['quiet_at'] ?? 0) <= $fresh;
}

/**
 * First contact with no list at all, from the model's WORDS (post-LLM). do=open is an open-FOR-AWARENESS and it CAN burn
 * a Say-Once greeting, so it is gated (design 2.1 step 1): bIntentOpen AND a nameable business marker.
 * [pt19 v1.0 / S2.1, S2.2] never beside the pre-LLM open of this very sentence (open_pending); never right after the game
 * refused an open (S2.3); an AMBIENT actor opens on the SAME narrow clauses as the pre-LLM open (never on "she is in some
 * quest"), and do=open carries amb=1 (log only).
 */
function lrgDlgMaybeOpen(array $t, string $item): ?string
{
    $npc = (string) $t['npc'];
    $cid = (string) $t['cid'];
    if (!empty($t['open_pending'])) {
        lrgDlgLog('gate: no second open - the pre-LLM open for this sentence is already queued', $cid);
        return null;
    }
    // bIntentOpen, from the GAME when it sends it (PROTOCOL 10.10), else this module's own default
    if (!lrgDlgMcm('io', !empty(lrgDlgCfg('intent_open')) ? 1 : 0, $npc)) {
        lrgDlgLog('gate: no list and bIntentOpen is off - the NPC answers in her own words', $cid);
        return null;
    }
    if (lrgDlgOpenRefused(lrgDlgState($npc))) {
        lrgDlgLog('gate: no open - the game refused the last open for ' . $npc . ' moments ago', $cid);
        return null;
    }
    // [0.5.7 / pt16-legion] a redirect answers in words (no Say-Once greeting burnt); a recruiter under ml=0 too
    // ($item: on a CARRIED ask only an ask-bearing item refuses - other business still opens)
    if (lrgFacRefusesOpen($t, $cid, $item)) { return null; }
    $amb = !empty($t['ambient']);
    $hit = [];
    $marker = lrgDlgBusinessMarker($t, $item, $amb, $hit);
    if ($marker === '') {
        lrgDlgLog('gate: no list and no business marker in "' . substr($item, 0, 40)
            . '"' . ($amb ? ' (an ambient actor opens on the narrow marker only)' : '')
            . ((string) ($hit['why'] ?? '') !== '' ? ' (' . (string) $hit['why'] . ')' : '') . ' - no open for awareness', $cid);
        return null;
    }
    if ($amb) { lrgDlgLog('open marker=' . $marker . ' row=' . ((string) ($hit['row'] ?? '') ?: '-') . ' npc=' . $npc . ' ambient', $cid); }
    return lrgDlgEmit($npc, ['do' => 'open', 'sid' => '0', 'gen' => 0, 'pos' => -1, 'i' => -1, 'txt' => '',
        'kind' => 'plain', 'ask' => substr(preg_replace('/[^A-Za-z0-9 \']+/', ' ', $item), 0, 60), 'amb' => $amb ? 1 : 0],
        $cid, 'open', null);
}

/**
 * The business marker. WIDE (the post-LLM open on the model's own item, a non-ambient actor, as shipped): a faction she
 * recruits for, a service word, a quest this NPC is in, an index hit, a hit against her cached root.
 * [pt19 v1.0 / S2.1] NARROW (the pre-LLM open, and any open on an ambient actor): FIVE clauses, cheapest first, the first
 * hit wins and is returned by name - join | kind | root | toplevel | qrows; $hit gets ['row' => info_key, 'entry' => the
 * line it predicts]. NEVER "a quest this NPC is in" (Hulda's q fires it on "uh some beer"), never a whole-index hit.
 */
/**
 * [v1.0.1 / owner 2026-09-25 - Helgen] An overrides entry may name the WORDS that bring her list up for its line before the
 * model runs (`open_on`: phrase runs, lrgDlgPhraseHit) and WHEN (`open_when`: {<facts key>: {min, max}} against the facts
 * line; a key the facts do not carry is no bar). Shipped for Alternate Perspective's "Give me your best room. (<RoomCost>
 * gold) (Start Intro)": "best room" to the Helgen innkeeper while MQ101 is below 5 (the intro not started - quiet mode's own
 * floor). No vendor test: the window names the NPCs. It only opens the list - the click that follows keeps the entry's own
 * class (never_auto: she quotes the line and asks; "yes" clicks it), and a refusal opens nothing.
 */
function lrgDlgOverrideOpen(array $t, string $utter, array &$hit): string
{
    if (trim($utter) === '' || lrgDlgRefuses($utter)) { return ''; }
    $facts = (array) ($t['facts'] ?? []);
    foreach ((array) (lrgDlgOverrides()['entries'] ?? []) as $rule) {
        $on = (array) ($rule['open_on'] ?? []);
        if (!$on || lrgDlgPhraseHit($utter, $on) === '') { continue; }
        $ok = true;
        foreach ((array) ($rule['open_when'] ?? []) as $key => $cond) {
            $key = strtolower((string) $key);
            if (!isset($facts[$key]) || !is_array($cond)) { continue; }
            $v = (int) $facts[$key];
            if ((isset($cond['max']) && $v > (int) $cond['max']) || (isset($cond['min']) && $v < (int) $cond['min'])) { $ok = false; break; }
        }
        if (!$ok) { continue; }
        $topic = (string) (($rule['match'] ?? [])['topic'] ?? '');
        $hit = ['row' => $topic, 'entry' => ['topic' => $topic, 'scripted' => 1, 'goodbye' => 1, 'kind' => '', 'cost' => 0, 'crit' => 0], 'override' => $topic];
        return 'override';
    }
    return '';
}

function lrgDlgBusinessMarker(array $t, string $item, bool $narrow = false, ?array &$hit = null): string
{
    $hit = ['row' => '', 'entry' => []];
    if (!$narrow) {
        $low = strtolower($item);
        $facMarker = lrgFacMarker($t, $item);   // [0.5.7 / pt16-legion] a recruiter she really is (a carried ask needs an ask-bearing item)
        if ($facMarker !== '') { return $facMarker; }
        if (lrgDlgIsService($low)) { return 'service word'; }
        if ((array) $t['q']) { return 'a quest this NPC is in'; }
        $recs = lrgPromptLookup([$item], ['quests' => (array) $t['q']]);
        if (is_array($recs[0] ?? null)) { return 'an index hit'; }
        $st = lrgDlgState((string) $t['npc']);
        $stale = (array) (($st['root'] ?? [])['entries'] ?? []);
        if ($stale) {
            $m = lrgDlgMatchText($item, $stale);
            if ($m !== null && $m['score'] >= 0.5) { return 'a hit against the stale cache'; }
        }
        return '';
    }
    $npc = (string) $t['npc'];
    $st = lrgDlgState($npc);
    // 1 an enlistment ask she is the recruiter for
    if (lrgFacMarker($t, $item) !== '') { return 'join'; }
    // 1b [v1.0.1 / owner 2026-09-25, Helgen] an overrides entry's own open words (open_on / open_when) - BEFORE the kind
    // guard, which refuses "best room" on an innkeeper the snapshot does not call a vendor (Alternate Perspective's Matlara)
    $ovOpen = lrgDlgOverrideOpen($t, $item, $hit);
    if ($ovOpen !== '') { return $ovOpen; }
    // 2 a service kind in his words (the PHRASE lists, never a bare service word), guarded (capability map U1, 1.4).
    // [pt19c-A fix 1 / game review 3] a kind the guard REFUSES ends the marker: the sentence is that service's (a follow
    // order to a stranger, a crime phrase, an unverified carriage), and a verbatim line elsewhere must not open for it
    $kind = lrgDlgServiceKindSaid($item);
    if ($kind !== '') {
        if (lrgDlgKindMayOpen($t, $kind)) { $hit['kind'] = $kind; return 'kind'; }
        lrgDlgLog('open npc=' . $npc . ': none - "' . substr($item, 0, 40) . '" asks for ' . $kind . ', which may not open her menu here (U1)', (string) ($t['cid'] ?? ''));
        return '';
    }
    // [pt19c-A fix 1 / language review 6, 7; game review 4] clauses 3-5 are about a LINE he said: never on a refusal ("no
    // rumors please", "I don't need a room, just a drink"), and never on a companion (her commands go through clause 2; her
    // small talk is not a menu - "what do you think about the war" to Lydia) except her own journal-quest rows (clause 5)
    if (lrgDlgRefuses($item)) { return ''; }
    $companion = lrgDlgCompanionOwner($npc, is_array($t['snap'] ?? null) && $t['snap'] ? (array) $t['snap'] : null) !== '';
    // 3 her cached root carries it (F16: a short containment never counts). [pt19c-A fix 1 / game review 1] on the F1 /
    // trigram tier his words must CARRY the line (lrgDlgWordsCarry: precision >= 0.75), and never against negation parity
    $root = $companion ? null : lrgDlgRoot($npc, $st);
    // [pt19h-reach G4] a request for WORK shares no word with the radiant start ("got any work?" -> "What can I do to help?"): her
    // cached root's ONE work line (lrgDlgReachWorkLine), else her journal-quest rows' one (clause 5 below), opens the list for it
    $workAsk = !$companion && lrgDlgReachWorkAsk($item) !== '';
    if ($root !== null && $workAsk) {
        $wl = array_values(array_filter((array) $root['entries'], static fn($e) => (string) ($e['class'] ?? '') !== 'hidden' && lrgDlgReachWorkLine((array) $e)));
        if (count($wl) === 1) { $hit = ['row' => '', 'entry' => $wl[0]]; return 'root'; }
    }
    if ($root !== null) {
        $vis = array_values(array_filter((array) $root['entries'], static fn($e) => (string) ($e['class'] ?? '') !== 'hidden'));
        $m = $vis ? lrgDlgMatchText($item, $vis) : null;
        if ($m !== null && empty($m['short']) && (float) $m['eff'] >= 0.5 && lrgDlgWordsCarry($item, (array) $vis[$m['i']])
            && !lrgDlgNegationClash($item, (array) $vis[$m['i']])) {
            $hit = ['row' => '', 'entry' => $vis[$m['i']]];
            return 'root';
        }
    }
    $n = lrgPromptNorm($item);
    // 4 a VERBATIM top-level prompt of this load order ("Nice inn you have here. Do you get many visitors?" is one row,
    // moretosaywhiterun.esp:000940, whoever she is). [pt19c-A fix 1 - the spec's own test row "where can I get a drink" -> NO
    // open, and 15 of 100 everyday sentences were such rows] >= open.toplevel_min_words (3) strict meaning words, and a line
    // at most open.toplevel_max_topics (5) distinct topics carry ("tell me about yourself" is 20 topics' line: small talk,
    // not her list); never on a companion. [pt19c-A fix 2 / language review 7, game review 4, spec reconciliation 2] and
    // only a row HER list can carry (lrgDlgRowIsHers): "do you need any help" is Raven Rock's DLC2TTR8 start line, "what
    // can you tell me about the jarl" Alvor's and Gerdur's MQ102A/B line, "tell me about the thieves guild" a Riften
    // townsman's DialogueRiften line - to a Whiterun guard each opened a list that cannot carry it (the bridge hedge
    // replaced her answer, nothing picked). The topic cap counts the topics HER list can carry.
    $minW = max(2, (int) lrgDlgCfg('open.toplevel_min_words', 3));
    if ($n !== '' && !$companion && !empty(lrgDlgCfg('open.toplevel_marker', true)) && count(lrgPromptWords($n, true)) >= $minW) {
        $qs = array_map('strtolower', (array) ($t['q'] ?? []));
        $name = lrgDlgNameWord($npc);
        $best = null;
        $alien = null;
        $topics = [];
        foreach ((array) (lrgPromptRowsFor([$n])[$n] ?? []) as $r) {
            if ((int) ($r['toplevel'] ?? 0) !== 1) { continue; }
            if (!lrgDlgRowIsHers($r, $name, $qs)) { $alien = $alien ?? $r; continue; }
            $topics[(string) ($r['topic_key'] ?? ($r['info_key'] ?? ''))] = 1;
            if ($best === null || in_array(strtolower((string) ($r['quest'] ?? '')), $qs, true)) { $best = $r; }
        }
        if ($best !== null && count($topics) <= max(1, (int) lrgDlgCfg('open.toplevel_max_topics', 5))) {
            $hit = ['row' => (string) ($best['info_key'] ?? ''), 'entry' => lrgDlgRowFacts($best)];
            return 'toplevel';
        }
        if ($best === null && $alien !== null) {
            $hit['why'] = 'clause 4: ' . (string) ($alien['info_key'] ?? '?') . ' is ' . (string) ($alien['topic'] ?? '?') . ' ('
                . (string) ($alien['quest'] ?? '?') . ') - a top-level line her list cannot carry (not her quest, not her name)';
        }
    }
    // 5 her JOURNAL-quest rows (the quests she is an alias of), fetched once per session: >= open.qrows_score with >= 2
    // shared strict words, or (U5) an exact / containment line with >= 1 ("I have your shield", "What are your orders?")
    $q = array_values(array_filter((array) ($t['q'] ?? [])));
    if ($n !== '' && $q && !empty(lrgDlgCfg('open.qrows_marker', true))) {
        $rows = lrgDlgQRows($npc, $st, $q);
        if ($workAsk && $root === null) {
            $wl = array_values(array_filter($rows, static fn($r) => lrgDlgReachWorkLine((array) $r)));
            if (count($wl) === 1) { $hit = ['row' => (string) ($wl[0]['info_key'] ?? ''), 'entry' => $wl[0]]; return 'qrows'; }
        }
        $m = $rows ? lrgDlgMatchText($item, $rows) : null;
        if ($m !== null && empty($m['short'])) {
            $r = $rows[$m['i']];
            $shared = count(array_intersect(lrgPromptWords($n, true), lrgPromptWords((string) $r['norm'], true)));
            $exact = in_array((string) $m['tier'], ['exact', 'contain'], true) && (float) $m['score'] >= 0.85 && $shared >= 1;
            if ((($shared >= 2 && (float) $m['eff'] >= (float) lrgDlgCfg('open.qrows_score', 0.55)) || $exact)
                && !lrgDlgNegationClash($item, ['text' => (string) ($r['norm'] ?? ''), 'norm' => (string) ($r['norm'] ?? '')])) {
                $hit = ['row' => (string) ($r['info_key'] ?? ''), 'entry' => $r];
                return 'qrows';
            }
        }
    }
    return '';
}

/** [S2.1] The fields of an index row the stage rail reads (F19), in the shape of an entry. */
function lrgDlgRowFacts(array $r): array
{
    $flags = is_array($r['flags'] ?? null) ? $r['flags'] : (json_decode((string) ($r['flags'] ?? ''), true) ?: []);
    return ['norm' => (string) ($r['norm'] ?? ''), 'text' => (string) ($r['txt'] ?? ''), 'info_key' => (string) ($r['info_key'] ?? ''),
        'scripted' => max((int) ($r['scripted'] ?? 0), (int) ($flags['invis'] ?? 0)), 'kind' => (string) ($r['kind'] ?? ''),
        'cost' => (int) ($r['cost'] ?? 0), 'goodbye' => (int) ($flags['goodbye'] ?? ($r['goodbye'] ?? 0)), 'crit' => (int) ($r['crit'] ?? 0)];
}

/**
 * [pt19c-A fix 2 / S2.1 clause 4] CAN HER LIST CARRY this top-level row? The index keeps no conditions (`nconds` only), so
 * the two facts it has: (1) the row's quest is one she is an alias of (her q) - but never a town's shared journal-free
 * DIALOGUE quest (DialogueWhiterun: 97 top-level rows across many NPCs; "where can I get a drink" is Jon Battle-Born's row
 * of it - spec reconciliation 2), or (2) the row's topic or quest EDITOR ID names her: the "More to Say" rows condition by
 * GetIsID, never by alias ("Nice inn you have here..." is ACFDialogueWhiterunHuldaBranchChatTopic), and so do vanilla's
 * named branches (DialogueSolitudeCorpulusBranchTopic, MQ103FarengarRetrieveBookTopic, ICQEGuardNoTopic for a guard).
 */
function lrgDlgRowIsHers(array $r, string $name, array $qs): bool
{
    $quest = strtolower((string) ($r['quest'] ?? ''));
    if ($quest !== '' && in_array($quest, $qs, true) && ((int) ($r['journal'] ?? 0) === 1 || strpos($quest, 'dialogue') === false)) {
        return true;
    }
    if ($name === '') { return false; }
    return in_array($name, lrgDlgEdidWords((string) ($r['topic'] ?? '')), true)
        || in_array($name, lrgDlgEdidWords((string) ($r['quest'] ?? '')), true);
}

/** The words of an editor id, lower case: "ACFDialogueWhiterunHuldaBranchChatTopic" -> acf dialogue whiterun hulda branch ... */
function lrgDlgEdidWords(string $edid): array
{
    $s = (string) preg_replace(['/([a-z])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $edid);
    return preg_split('/[^a-z]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * The word of her display name an editor id would carry: the first word of >= 3 letters that is no title, no article and
 * no place ("Balgruuf the Greater" -> balgruuf, "Whiterun Guard" -> guard, "Eorlund Gray-Mane" -> eorlund); '' when none.
 * [pt19c final fixer / completeness critic] a RANK or a bare ROLE is no name either: "General Tullius" read "general", and
 * RoriksteadFreeformErikGeneralTopic2Topic ("Have you lived here all your life?") then counted as his - a false pre-LLM open;
 * likewise captain / commander / legate / priest / hunter. "General Tullius" -> tullius, "Captain Aldis" -> aldis, "Priest
 * of Arkay" and "Hunter" -> '' (a god's name is no name of hers: rows count only through her own quests then).
 */
function lrgDlgNameWord(string $npc): string
{
    static $stop = ['the', 'and', 'jarl', 'lord', 'lady', 'sir', 'master', 'mistress', 'brother', 'sister', 'old', 'young', 'imperial',
        'general', 'captain', 'commander', 'legate', 'priest', 'priestess', 'hunter', 'huntress', 'high', 'king', 'queen', 'prince',
        'princess', 'thane', 'housecarl', 'steward', 'court', 'wizard', 'archmage', 'elder', 'chief', 'headman', 'sergeant',
        'lieutenant', 'officer', 'quartermaster', 'marshal', 'emperor', 'count', 'countess', 'reeve', 'praetor', 'tribune', 'arkay',
        'akatosh', 'dibella', 'julianos', 'kynareth', 'mara', 'stendarr', 'talos', 'zenithar',
        'stormcloak', 'whiterun', 'riften', 'solitude', 'windhelm', 'markarth', 'falkreath', 'morthal', 'dawnstar', 'winterhold',
        'riverwood', 'rorikstead', 'ivarstead', 'helgen', 'raven', 'rock', 'skaal', 'dragon', 'bridge', 'eastmarch', 'haafingar',
        'reach', 'pale', 'rift', 'hjaalmarch', 'city', 'hold',
        // [pt19h-safety / name-word proxy] a GENERIC display name is a role, a faction or a people, never her name: "Dark
        // Brotherhood Assassin" read "dark" (205 top-level rows counted as hers), "Courier" 153, "College Apprentice" 120,
        // "Innkeeper" 96, "Thalmor Justiciar" 30, "Vigilant of Stendarr" 27, "Dawnguard Veteran" 20 - each a false pre-LLM open.
        // ("guard" stays: the guard topics are condition-free by design - ICQEGuardNoTopic is every guard's.)
        'dark', 'brotherhood', 'assassin', 'courier', 'college', 'apprentice', 'innkeeper', 'thalmor', 'justiciar', 'bandit',
        'vigilant', 'bard', 'dawnguard', 'veteran', 'forsworn', 'khajiit', 'caravan', 'orc', 'thief', 'legion', 'soldier', 'farmer',
        'silver', 'hand', 'lost', 'soul', 'mercenary', 'penitus', 'oculatus', 'agent', 'dremora', 'fisherman', 'beggar',
        'guardian', 'keeper', 'blacksmith', 'merchant', 'citizen', 'traveler', 'traveller', 'warrior', 'mage', 'necromancer',
        'vampire', 'werewolf', 'companion', 'companions', 'cultist', 'miner', 'hunter', 'servant', 'prisoner', 'stranger', 'nord',
        'breton', 'redguard', 'altmer', 'bosmer', 'dunmer', 'argonian', 'elf', 'woman', 'man', 'boy', 'girl', 'child', 'spirit',
        'ghost', 'draugr', 'monk', 'acolyte', 'initiate', 'adept', 'novice', 'scholar', 'healer', 'cook', 'guide', 'lookout', 'watchman',
        'embassy', 'gate', 'camp', 'fort', 'patrol', 'sentry', 'scout', 'jailor', 'jailer', 'warden', 'executioner', 'headsman', 'wood'];
    // [pt19h-safety review] "Moth Priest" is a role, but "moth" alone is a real name (Moth gro-Bagol read "gro"): the phrase goes
    foreach (preg_split('/[^a-z]+/', (string) preg_replace('/\bmoth priest\b/', ' ', strtolower($npc)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stop, true)) { return $w; }
    }
    return '';
}

/**
 * [S2.1 clause 5] Her journal-quest rows, cached like the root (cache.max_age_seconds, and only while her q is the same): ONE
 * index round trip per NPC per session. [pt19c-A fix 1 / code review, CHIM brief P15] in a row of their OWN
 * ('*qrows*<npc>'), never in her state row: up to 300 rows there made every lrgDlgSet on her (a read-modify-write of the
 * whole payload since the P1 fix) move 30-40 KB of JSON, the fast path's included.
 */
function lrgDlgQRowsKey(string $npc): string { return '*qrows*' . $npc; }

function lrgDlgQRows(string $npc, array $st, array $q): array
{
    $sig = implode(',', array_map('strtolower', $q));
    $c = (array) (lrgDlgGet(lrgDlgQRowsKey($npc))['qrows'] ?? []);
    if ($c && (string) ($c['sig'] ?? '') === $sig && lrgNow() - (int) ($c['at'] ?? 0) <= (int) lrgDlgCfg('cache.max_age_seconds', 1800)) {
        return (array) ($c['rows'] ?? []);
    }
    $rows = [];
    foreach (lrgPromptRowsForQuests($q, max(1, (int) lrgDlgCfg('open.qrows_cap', 300))) as $r) {
        $rows[] = ['norm' => (string) $r['norm'], 'info_key' => (string) $r['info_key'], 'scripted' => (int) $r['scripted'],
            'kind' => (string) $r['kind'], 'cost' => (int) $r['cost'], 'goodbye' => (int) $r['goodbye'], 'crit' => (int) $r['crit']];
    }
    lrgDlgPut(lrgDlgQRowsKey($npc), ['qrows' => ['sig' => $sig, 'at' => lrgNow(), 'rows' => $rows]]);
    return $rows;
}

/**
 * [pt19 v1.0 / capability map U1, model F18] May a service KIND open her menu before the model runs? inn / barter only on
 * a vendor (10.15's own test - a missing snapshot still opens), follower only on somebody's follower (SFF or the party),
 * carriage / ferry / train only when her factions carry that job (open.kind_factions - verified in the plugins; a snapshot
 * only), crime never. A voice order of food or drink owns its turn (10.27): no open beside it.
 */
function lrgDlgKindMayOpen(array $t, string $kind): bool
{
    if (function_exists('lrgMktState') && in_array(lrgMktState($t), LRG_MKT_ACTIVE, true)) { return false; }
    $snap = is_array($t['snap'] ?? null) && $t['snap'] ? $t['snap'] : null;
    switch ($kind) {
        case 'inn':
        case 'barter':
            return lrgDlgVendorHint($snap) !== 'none';
        case 'follower':
            // [pt19c-A fix 1 / CHIM review] an SFF companion or CHIM's ghost: 10.20 already carries follow / wait / release
            // through the game with no menu (ExtCmdLRG_Escort) - a menu beside it is a second carrier and a camera grab
            $owner = lrgDlgCompanionOwner((string) ($t['npc'] ?? ''), $snap);
            if ($owner === '' || (function_exists('lrgEscortSffRouted') && lrgEscortSffRouted($owner))) { return false; }
            return true;
        case 'carriage':
        case 'ferry':
        case 'train':
            $want = array_map('strtolower', (array) lrgDlgCfg('open.kind_factions.' . $kind, []));
            if (!$want || !$snap) { return false; }
            $fac = array_map('strtolower', array_map('trim', explode(',', (string) ($snap['fac'] ?? ''))));
            return (bool) array_intersect($want, $fac);
    }
    return false;
}

// ================================================================== emitting
function lrgDlgSafePrefix(string $text): string
{
    $s = (string) preg_replace('/[;=@|"~]/', ' ', $text);
    $s = trim((string) preg_replace('/\s+/', ' ', $s));
    ///[0.5.1 pt9 go-live / S9] THE 12-CHARACTER FLOOR IS GONE. It emitted txt= EMPTY for 2,895 of the
    // live index's 37,561 rows (7.7%) - and the set is exactly the dangerous one: "Morthal.",
    // "Riften.", "Dawnstar.", "Falkreath.", "Haafingar.", "The Pale.", every bare destination name. An
    // empty txt disables the game's only content check on the click (TryResolvePick reads
    // `reqTxt == "" || Find(eText[reqPos], reqTxt) == 0`), so POSITION ALONE decided - on entries where
    // a mis-positioned click spends gold and teleports. The sanitiser above already strips ; = @ | " ~,
    // which is the whole of what the floor was protecting.
    if ($s === '') { return ''; }
    return substr($s, 0, 40);
}

/**
 * One decision leaves the server here, on BOTH delivery routes, with the SAME x (design 2.4):
 *  D1 the same HTTP reply (fast, ~0.5 s) - only from the fast preprocessing path, where we may echo;
 *  D2 a responselog row, echoed on the DLL's request poll (5 s spacing).
 * The game de-duplicates by an executed ring of 8, so two arrivals are one execution.
 */
function lrgDlgEmit(string $npc, array $kv, string $cid, string $mode, ?array $entry): ?string
{
    $st = lrgDlgState($npc);
    $x = substr(md5(uniqid((string) lrgNow(), true)), 0, 10);
    $param = lrgKv([
        'ok' => 1, 'cid' => $cid !== '' ? $cid : 'dlg', 'npc' => $npc, 'ref' => (string) ($st['ref'] ?? ''),
        'do' => (string) ($kv['do'] ?? 'noop'), 'sid' => (string) ($kv['sid'] ?? '0'), 'gen' => (int) ($kv['gen'] ?? 0),
        'pos' => (int) ($kv['pos'] ?? -1), 'i' => (int) ($kv['i'] ?? -1), 'txt' => (string) ($kv['txt'] ?? ''),
        'kind' => (string) ($kv['kind'] ?? 'plain'), 'cost' => (int) ($kv['cost'] ?? 0),
        // [pt19 v1.0 / S8] res= is sent EMPTY: nobody reads it on either side; the slot is kept for the fixed key order
        'res' => '', 'xp' => (int) ($kv['xp'] ?? 0), 'stat' => (string) ($kv['stat'] ?? ''),
        'take' => (int) ($kv['take'] ?? 0), 'ask' => (string) ($kv['ask'] ?? ''),
        'vt' => (string) ($st['vt'] ?? '0'), 'x' => $x, 'z' => 1,
    ]);
    // [0.5.0] TWO ADDITIVE KEYS, BOTH AFTER z=1, both ignored by an older game script.
    //  note= (W9) is the quest corner hint - already handled by game 401 (LRG_Main.psc:532-535), which
    //        reads note= off ANY ExtCmdLRG_ command and raises a Debug.Notification before dispatch.
    //  svc=  (W12) is diagnostic and corner-note wording only. NOTHING branches on it, on either side.
    // The fixed key order of PROTOCOL 10.4 is kept byte for byte up to z=1; these come after it.
    $note = trim((string) ($kv['note'] ?? ''));
    if ($note !== '') { $param .= ';note=' . substr((string) preg_replace('/[;=@|"~\r\n]/', ' ', $note), 0, 120); }
    $svc = trim((string) ($kv['svc'] ?? ''));
    if ($svc === '') {
        $t0 = $GLOBALS['LRG_DLG_TURN'] ?? null;
        $svc = is_array($t0) ? (string) (($t0['svc'] ?? [])['kind'] ?? '') : '';
    }
    if ($svc !== '' && preg_match('/^[a-z]{2,10}$/', $svc)) { $param .= ';svc=' . $svc; }
    // [pt19 v1.0 / S4.6, S8] adv=<ms> on an auto-advance pick (0 = a continuer, at once), rearm=1 after it when the count
    // starts at the end of HER answer; amb=1 on an open of an ambient scene actor (log only). All after z=1, additive.
    $adv = isset($kv['adv']) ? (int) $kv['adv'] : -1;
    if ((string) ($kv['do'] ?? '') === 'pick' && $adv >= 0) {
        $param .= ';adv=' . $adv;
        if ((int) ($kv['rearm'] ?? 0) === 1) { $param .= ';rearm=1'; }
    }
    if ((string) ($kv['do'] ?? '') === 'open' && (int) ($kv['amb'] ?? 0) === 1) { $param .= ';amb=1'; }
    $line = $npc . '|command|' . LRG_ACT_TOPIC . '@' . $param . "\r\n";
    $patch = ['last_exec' => ['x' => $x, 'cid' => $cid, 'at' => lrgNow(), 'do' => (string) ($kv['do'] ?? ''),
        'text' => $entry ? (string) $entry['text'] : '', 'norm' => $entry ? (string) $entry['norm'] : '',
        'kind' => (string) ($kv['kind'] ?? ''), 'mode' => $mode, 'adv' => $adv, 'told' => false]];
    // [pt19 v1.0 / model 2.1, F8] a CLICK (a pick, a back line - never an open, a show, a hint or an award): no sentence
    // spoken before it may act on the layer it produces; its cid lets that very sentence name a price-list slot next
    if ((string) ($kv['do'] ?? '') === 'pick' || ((string) ($kv['do'] ?? '') === 'leave' && (int) ($kv['pos'] ?? -1) >= 0)) {
        // [pt19c-A fix 1 / CHIM review, model row 7] `lead`: a click for this cid that was NOT an auto-advance, kept across the
        // auto-advance picks that follow it with the SAME cid (the game echoes the pick's cid back) - the mute reads it
        $plc = (array) ($st['last_click'] ?? []);
        $lead = $adv < 0 || ((string) ($plc['cid'] ?? '') === $cid && !empty($plc['lead']));
        $patch['last_click'] = ['at' => lrgNow(), 'cid' => $cid, 'lead' => $lead ? 1 : 0];
    }
    lrgDlgPut($npc, $patch);
    lrgDlgQueue($npc, $param);                       // D2 always
    $ov = isset($kv['overlap']) && (float) $kv['overlap'] >= 0 ? sprintf(' overlap=%.2f', (float) $kv['overlap']) : '';
    lrgDlgLog(sprintf('emit npc=%s do=%s mode=%s pos=%s i=%s kind=%s cost=%s x=%s%s%s%s txt="%s"', $npc,
        (string) ($kv['do'] ?? ''), $mode, (string) ($kv['pos'] ?? '-'), (string) ($kv['i'] ?? '-'),
        (string) ($kv['kind'] ?? ''), (int) ($kv['cost'] ?? 0), $x, $ov,
        $adv >= 0 ? ' adv=' . $adv . ((int) ($kv['rearm'] ?? 0) === 1 ? ' rearm=1' : '') : '',
        (int) ($kv['amb'] ?? 0) === 1 && (string) ($kv['do'] ?? '') === 'open' ? ' amb=1' : '',
        substr($entry ? (string) $entry['text'] : '', 0, 50)), $cid);
    // D1 is only possible on the fast path, where nothing has been echoed yet and the caller terminates
    if (lrgDlgFastPath()) {
        echo $line;
        return null;
    }
    return $line;
}

/** True while we are inside preprocessing.php's handling of one of our own fast messages. */
function lrgDlgFastPath(): bool
{
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    return in_array($type, ['lrg_topics', 'lrg_dlg'], true);
}

/**
 * D2: a responselog row, echoed on the DLL's request poll. Insert shape copied verbatim from
 * lib/rolemaster_helpers.php:790-800. Isolated so the offline tests can capture it:
 * $GLOBALS['LRG_DLG_TEST_QUEUE'] collects instead of writing.
 */
function lrgDlgQueue(string $npc, string $param): void
{
    $row = ['localts' => lrgNow(), 'sent' => 0, 'actor' => $npc, 'text' => '',
        'action' => 'command|' . LRG_ACT_TOPIC . '@' . $param, 'tag' => ''];
    if (array_key_exists('LRG_DLG_TEST_QUEUE', $GLOBALS)) {
        $GLOBALS['LRG_DLG_TEST_QUEUE'][] = $row;
        return;
    }
    $db = lrgDb();
    if (!$db) { return; }
    try { $db->insert('responselog', $row); }
    catch (Throwable $e) { lrgDlgLog('D2 queue insert failed: ' . $e->getMessage()); }
}

/**
 * do=award - the free-conversation effect carrier (plan 6.9). Emitted by the server's own post-gate, NEVER
 * chosen by the LLM; carries no pos / i / sid. It touches no menu, no dialogue, no engine flag and no stage.
 */
function lrgDlgAwardLine(array $t): string
{
    $c = (array) $t['check'];
    $npc = (string) $t['npc'];
    $st = lrgDlgState($npc);
    // [pt19c-B fix 1 / CHIM review, gate B] a reward bonus was queued PRE-LLM by lrgDlgCheck (award_x): the same x again, no second
    // D2 row - the driver de-dups D1 / D2 by x, so the bonus moves once whether or not this post-gate ever runs
    $preX = (string) ($c['award_x'] ?? '');
    $x = $preX !== '' ? $preX : substr(md5(uniqid('award' . lrgNow(), true)), 0, 10);
    $param = lrgKv([
        'ok' => 1, 'cid' => (string) $t['cid'], 'npc' => $npc, 'ref' => (string) ($st['ref'] ?? ''),
        'do' => 'award', 'sid' => '0', 'gen' => 0, 'pos' => -1, 'i' => -1, 'txt' => '',
        'kind' => (string) ($c['kind'] ?? 'persuade'), 'cost' => 0, 'res' => '',   // [pt19 v1.0 / S8] sent empty
        'xp' => (int) ($c['xp'] ?? 0), 'stat' => (string) ($c['stat'] ?? ''), 'take' => (int) ($c['take'] ?? 0),
        'ask' => '', 'vt' => (string) ($st['vt'] ?? '0'), 'x' => $x, 'z' => 1,
    ] + ((int) ($c['give'] ?? 0) > 0 ? ['give' => (int) $c['give']] : []));   // [pt19 v1.0 / S6.2, S8, gate B] give= after z=1
    if ($preX === '') { lrgDlgQueue($npc, $param); }
    lrgDlgLog('emit npc=' . $npc . ' do=award kind=' . (string) ($c['kind'] ?? '') . ' xp=' . (int) ($c['xp'] ?? 0)
        . ' take=' . (int) ($c['take'] ?? 0) . ' stat=' . ((string) ($c['stat'] ?? '') ?: '-') . ' x=' . $x, (string) $t['cid']);
    return $npc . '|command|' . LRG_ACT_TOPIC . '@' . $param . "\r\n";
}

// ================================================================== JSON template / transformer / chat
/**
 * Decision D3: on a turn where TakeUpBusiness is offered, action / target / item come BEFORE message, and
 * item's and target's own descriptions gain one clause each - because CHIM's schema is strict and item's
 * description ends "Leave item blank for SpawnGold and SpawnNPC and CreateNewNPC and DirectorCommand."
 */
function lrgDlgJsonTemplate(): void
{
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($t) || empty($t['on']) || empty(lrgDlgCfg('reorder_json'))) { return; }
    if (!($t['speech'] ?? false) && !($t['talk'] ?? false)) { return; }
    // [pt17-replies] reorder_json_scope 'business': the action-first order only when the turn carries what it is for
    $reorder = (string) lrgDlgCfg('reorder_json_scope', 'business') !== 'business' || lrgDlgTurnHasBusiness($t);
    $sch = &$GLOBALS['structuredOutputTemplate'];
    if (isset($sch['json_schema']['schema']['properties']) && is_array($sch['json_schema']['schema']['properties'])) {
        $p = $sch['json_schema']['schema']['properties'];
        $first = [];
        if ($reorder) { foreach (['action', 'target', 'item'] as $k) { if (isset($p[$k])) { $first[$k] = $p[$k]; } } }
        foreach ($p as $k => $v) { if (!isset($first[$k])) { $first[$k] = $v; } }
        if ($reorder && isset($first['item']['description'])) {
            $first['item']['description'] = rtrim((string) $first['item']['description'])
                . ' OR, when action is ' . lrgDlgActionName() . ', one key from the <business> block, e.g. T3,'
                . ' or 3-8 plain words naming the matter.';
        }
        if ($reorder && isset($first['target']['description'])) {
            $first['target']['description'] = rtrim((string) $first['target']['description'])
                . ' When action is ' . lrgDlgActionName() . ', the NPC you are speaking to.';
        }
        // [pt17-replies] the never-empty clause: a DESCRIPTION, never a schema keyword (strict structured-output modes
        // reject unsupported keywords such as minLength with a 400). 5.3 % of all replies came back with message ""
        // and the model commits to `action` before it writes words in this order (research/pt17-replies.md).
        if (isset($first['message']['description'])) {
            $first['message']['description'] = rtrim((string) $first['message']['description']) . ' ' . lrgDlgNeverEmptyClause();
        }
        $sch['json_schema']['schema']['properties'] = $first;
        if ($reorder && isset($sch['json_schema']['schema']['required']) && is_array($sch['json_schema']['schema']['required'])) {
            $req = array_values(array_unique(array_merge(['action'], (array) $sch['json_schema']['schema']['required'])));
            $sch['json_schema']['schema']['required'] = $req;
        }
    }
    if (isset($GLOBALS['responseTemplate']) && is_array($GLOBALS['responseTemplate'])) {
        $r = $GLOBALS['responseTemplate'];
        $ordered = [];
        if ($reorder) { foreach (['action', 'target', 'item'] as $k) { if (array_key_exists($k, $r)) { $ordered[$k] = $r[$k]; } } }
        foreach ($r as $k => $v) { if (!array_key_exists($k, $ordered)) { $ordered[$k] = $v; } }
        if (isset($ordered['message']) && is_string($ordered['message'])) { $ordered['message'] = rtrim($ordered['message']) . ' - ' . lrgDlgNeverEmptyClause(); }
        $GLOBALS['responseTemplate'] = $ordered;
    }
    lrgDlgLog($reorder ? 'json template: action/target/item ahead of message, item + target descriptions extended, message never empty'
        : 'json template: message never empty (reorder_json_scope=business, no business this turn)', (string) $t['cid']);
}

/** [pt17-replies] The clause appended to the message description / text template. Rules only, no example line. */
function lrgDlgNeverEmptyClause(): string
{
    return 'Never empty: at least one short spoken sentence, even when the action says it all.';
}

/**
 * [pt17-replies] Does this turn carry what the action-first order is FOR: a <business> list, a service, a follower
 * verb, an enlistment ask, an arrest, or a list / next question? Captain Aldis's 20:10:31 turn carried none of them
 * (list=none n=0 sent=0 keys=0, only a carried faction ask) and was still reordered.
 */
function lrgDlgTurnHasBusiness(array $t): bool
{
    if (!empty($t['entries']) || !empty($t['tail'])) { return true; }
    $svc = (array) ($t['svc'] ?? []);
    if (!empty($svc['pricelist']) || !empty($svc['direct']) || (string) ($svc['kind'] ?? '') !== '') { return true; }
    if ((string) ((($t['fol'] ?? [])['asked'] ?? '')) !== '') { return true; }
    if ((string) ((($t['faction'] ?? [])['asked'] ?? '')) !== '' && empty($t['faction']['carried'])) { return true; }
    if ((string) ($t['arrest'] ?? '') !== '') { return true; }
    if (lrgMktState($t) !== '') { return true; }   // [pt19-purchase] an order of food or drink is business
    return in_array((string) ($t['ask'] ?? ''), ['list', 'next'], true);
}

/**
 * The mute (design 4.6 layer 2). Runs per sentence before TTS; a result shorter than 2 characters skips the
 * sentence entirely. It returns '' ONLY when the pick will actually be EMITTED this turn - when the pick is
 * going to be parked or dropped the text IS the NPC's question or deflection and must be heard.
 */
function lrgDlgTransformer(string $s): string
{
    $prev = $GLOBALS['LRG_DLG_PREV_TRANSFORMER'] ?? null;
    if (is_callable($prev)) { $s = (string) $prev($s); }
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($t) || empty($t['on'])) { return $s; }
    // [pt19 v1.0 / S2.1] SHE DOES NOT ANSWER TWICE: on the pre-LLM open's turn the fast pick may already have clicked her
    // real line. last_exec is read FRESH (one read per sentence, this turn only): a pick for THIS sentence (and not an
    // auto-advance) mutes her bridging line; otherwise it plays - never silence.
    if (!empty($t['open_pending'])) {
        // [pt19c-A fix 1 / CHIM review] "landed" = last_click for this cid led by a real pick (model row 7): the next layer's
        // auto-advance pick overwrites last_exec with the same cid, and her bridge must stay muted over the engine line
        $fr = lrgDlgFresh((string) $t['npc']);
        $lc = (array) ($fr['last_click'] ?? []);
        if ((string) ($lc['cid'] ?? '') === (string) $t['cid'] && !empty($lc['lead'])) { return ''; }
        $ex = (array) ($fr['last_exec'] ?? []);
        if ((string) ($ex['do'] ?? '') === 'pick' && (string) ($ex['cid'] ?? '') === (string) $t['cid'] && (int) ($ex['adv'] ?? -1) < 0) {
            return '';
        }
        return $s;
    }
    $act = (string) ($GLOBALS['LAST_LLM_RESPONSE']['action'] ?? '');
    if ($act === '') { return $s; }
    $norm = str_replace(['_', ' '], '', strtolower($act));
    if (!in_array($norm, LRG_DLG_GATE_NAMES, true)) { return $s; }
    if (!lrgDlgWillEmit($t, (string) ($GLOBALS['LAST_LLM_RESPONSE']['item'] ?? ''))) { return $s; }
    return '';
}

/**
 * Will this turn's pick really be emitted? [pt19 v1.0 / S1.3] THE GATE'S TWIN: the SAME decision (lrgDlgDecide), with its
 * logging silenced - TRUE for an immediate pick (key, the model's words resolved in intent mode - pt19c-A fix 1, explicit,
 * single release, released park, the engine's own confirmation) and for LEAVE with a back line; FALSE for a park, the stage
 * rail, a read-only session, the leave guard, the open turn's hold, an unreleased commit and an open for awareness. So the
 * TTS mute and never-empty's let-through (lrgNeWillEmitPick) agree with the gate on every input: a refused pick is never
 * muted, and silence is impossible by construction.
 */
function lrgDlgWillEmit(array $t, string $item): bool
{
    $item = trim((string) preg_replace('/^\s*item\s*=\s*/i', '', $item));
    if ($item === '' || empty($t['on'])) { return false; }
    $st = lrgDlgState((string) ($t['npc'] ?? ''));
    $prev = $GLOBALS['LRG_DLG_SILENT'] ?? null;
    $GLOBALS['LRG_DLG_SILENT'] = true;
    try {
        if (lrgDlgOpenTurnHold($t, $st) !== '') { return false; }
        $d = lrgDlgDecide($t, $item, $st);
    } finally {
        if ($prev === null) { unset($GLOBALS['LRG_DLG_SILENT']); } else { $GLOBALS['LRG_DLG_SILENT'] = $prev; }
    }
    if ((string) $d['do'] === 'pick') { return true; }
    return (string) $d['do'] === 'leave' && is_array($d['entry'] ?? null);
}

/**
 * CHIM logs the clicked prompt as the player's own speech, after the player already spoke in their own
 * words. dedupe_player_prompt = relabel (default): a chat row that starts "(Context", names the player and
 * contains last_exec.text within 8 s is rewritten to '(in effect: "<prompt>")'. The NPC row is NEVER
 * touched - it is the ground truth.
 */
function lrgDlgFilterChat(array &$gameRequest): void
{
    if (!lrgDlgEnabled()) { return; }
    $mode = (string) lrgDlgCfg('dedupe_player_prompt', 'relabel');
    if ($mode === 'keep') { return; }
    $text = (string) ($gameRequest[3] ?? '');
    if ($text === '' || stripos(ltrim($text), '(Context') !== 0) { return; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? '');
    if ($player !== '' && stripos($text, $player) === false) { return; }
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $ex = (array) (lrgDlgState($npc)['last_exec'] ?? []);
    $clicked = trim((string) ($ex['text'] ?? ''));
    if ($clicked === '' || lrgNow() - (int) ($ex['at'] ?? 0) > 8) { return; }
    if (stripos($text, substr($clicked, 0, min(24, strlen($clicked)))) === false) { return; }
    if ($mode === 'drop') {
        $gameRequest[3] = '';
        lrgDlgLog('chat: dropped the duplicate player prompt row');
        return;
    }
    $gameRequest[3] = '(in effect: "' . $clicked . '")';
    lrgDlgLog('chat: relabelled the duplicate player prompt row');
}

// ================================================================== the action row
/**
 * The single new action, with its OWN marker data/.dlg_actions_v1 and NEVER inside Phase 1's
 * LRG_GLUE_ACTIONS - so Phase 1's lrgHideActions(LRG_GLUE_ACTIONS) under SHARMAT cannot switch it off.
 */
function lrgDlgEnsureActions(): void
{
    if (!function_exists('herikaActionCatalogUpsertCustomRow')) { return; }
    $marker = LRG_DIR . '/data/.dlg_actions_v' . LRG_DLG_ACTIONS_VERSION;
    if (is_file($marker)) {
        if (!function_exists('herikaGetActionCatalogRow')) { return; }
        $row0 = herikaGetActionCatalogRow(LRG_ACT_TOPIC);
        if (is_array($row0)) {
            // [0.5.0 / E7(a)] a row installed by 0.4 carries no metadata.requirements.activity block
            $md = $row0['metadata'] ?? [];
            if (is_string($md)) { $md = json_decode($md, true) ?: []; }
            $md = (array) $md;
            $req0 = (array) ($md['requirements'] ?? []);
            $act = (array) ($req0['activity'] ?? []);
            if (!empty($act['current_action_not_in'])) { return; }
            lrgDlgLog('action catalog: the ' . LRG_DLG_NAME . ' row predates CHIM requirements - reinstalling');
        } else {
            lrgDlgLog('action catalog: the ' . LRG_DLG_NAME . ' row is missing - reinstalling');
        }
    }
    $types = array_merge(LRG_PLAYER_SPEECH_TYPES, ['lrg_dlgtalk']);
    $row = [
        'code_name' => LRG_ACT_TOPIC,
        'action_name' => LRG_DLG_NAME,
        'description' => 'Take up a real matter of the world with the player: a job, a quest, a payment, a '
            . 'favour, access, a secret, a service, an arrest. Use it ONLY when the player\'s last words '
            . 'clearly do that thing, and only with a key from the <business> block of this turn (or the '
            . 'word leave). Never announce whether a persuasion, a threat or a bribe worked.',
        'parameters_json' => ['type' => 'object', 'properties' => ['item' => ['type' => 'string',
            'description' => 'One key T1..T12 from the <business> block of THIS turn, or leave, or - only '
                . 'when no block was shown - the matter in 3-8 plain words']], 'required' => ['item']],
        'metadata' => [
            'dispatch' => 'plugin_command',
            'source' => 'LoreRimGlue',
            'bridge_script' => 'LRG',
            'bridge_entrypoint' => 'DispatchExternalCommand',
            // [0.5.0 / E7(a)] CHIM's own condition truthfulness, on the same code path as RentRoom's.
            // A dialogue menu cannot be driven with a corpse or mid-combat, and CHIM knows the activity.
            // STATED PLAINLY because it matters: CHIM's requirement vocabulary CANNOT express "a session
            // with this NPC is open and its list is known" - that is per-turn state in our own Postgres
            // rows - so OUR per-turn filter remains the primary gate and does not move. This is a second,
            // independent net for the turns our filter never sees (a rechat, a narrator turn, a stale cache).
            'requirements' => ['request_types_any' => $types]
                + (defined('LRG_ACT_ALIVE_REQ') ? LRG_ACT_ALIVE_REQ
                    : ['activity' => ['current_action_not_in' => ['dead', 'unconscious', 'sleeping', 'combat', 'attacking']]]),
            'confirmation' => ['default_policy' => 'automatic'],
            'suppress_placeholder_infoaction' => true,
            'followup' => ['enabled' => false, 'arg_name' => 'item', 'use_functions_again' => false, 'prompt' => ''],
        ],
        'return_message' => '', 'available_to_npc' => 1, 'available_to_followers' => 1, 'available_to_narrator' => 0,
        'is_activated' => 1, 'game_function' => 1, 'import_version' => LRG_DLG_ACTIONS_VERSION,
    ];
    if (!herikaActionCatalogUpsertCustomRow($row)) { lrgDlgLog('action catalog: upsert failed for ' . LRG_ACT_TOPIC); return; }
    if (function_exists('herikaActionCatalogResetCache')) { herikaActionCatalogResetCache(); }
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents($marker, date('c'));
    lrgDlgLog('action catalog: ' . LRG_DLG_NAME . ' installed (dlg actions v' . LRG_DLG_ACTIONS_VERSION . ')');
}

// ##################################################################################################
// [0.5.0] E2 quest awareness v2 · E3 assisted polish · E4(b) the hold · E6 services · E7 truth
// Everything below is new this round. Nothing above it changed behaviour except where a [0.5.0]
// comment says so.
// ##################################################################################################

// ------------------------------------------------------------------ small text helpers
/** Meaning-preserving token list of a normalised string (stop words KEPT: phrases contain them). */
function lrgDlgTokens(string $norm): array
{
    $t = preg_split('/\s+/', trim($norm));
    return is_array($t) ? array_values(array_filter($t, 'strlen')) : [];
}

/** Is $needle a CONTIGUOUS run of tokens inside $hay? Exact, never fuzzy - that is the whole point. */
function lrgDlgTokenRun(array $hay, array $needle): bool
{
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $n > $h) { return false; }
    for ($i = 0; $i + $n <= $h; $i++) {
        $ok = true;
        for ($j = 0; $j < $n; $j++) {
            if ($hay[$i + $j] !== $needle[$j]) { $ok = false; break; }
        }
        if ($ok) { return true; }
    }
    return false;
}

/** The index of the first token where $needle starts inside $hay, or -1. */
function lrgDlgTokenAt(array $hay, array $needle): int
{
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $n > $h) { return -1; }
    for ($i = 0; $i + $n <= $h; $i++) {
        $ok = true;
        for ($j = 0; $j < $n; $j++) {
            if ($hay[$i + $j] !== $needle[$j]) { $ok = false; break; }
        }
        if ($ok) { return $i; }
    }
    return -1;
}

/** The LONGEST phrase of $phrases that appears in $utter as a contiguous token run, or ''. */
function lrgDlgPhraseHit(string $utter, array $phrases): string
{
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    if (!$hay) { return ''; }
    $best = '';
    $bestN = 0;
    foreach ($phrases as $p) {
        $pt = lrgDlgTokens(lrgPromptNorm((string) $p));
        if (!$pt || count($pt) <= $bestN) { continue; }
        if (lrgDlgTokenRun($hay, $pt)) { $best = (string) $p; $bestN = count($pt); }
    }
    return $best;
}

/** Negation immediately before a token run: "don't take me to Morthal" is not an order. */
// lrgPromptNorm() KEEPS the apostrophe, so both spellings have to be here - "don't take me to Morthal"
// is the exact case this guard exists for, and it is the one that costs the player his gold.
const LRG_DLG_NEG_WORDS = ['dont', "don't", 'not', 'never', 'no', 'cant', "can't", 'cannot', 'wont',
    "won't", 'doesnt', "doesn't", 'didnt', "didn't", 'rather', 'stop', 'without', 'anywhere'];

function lrgDlgNegatedAt(array $hay, int $at, array $stops = []): bool
{
    ///SIX tokens, not three: "don't take me to Morthal" and "I don't want to go to Morthal" both put the
    // negation further back than a three-token window reaches, and those are exactly the sentences this
    // guard exists for. Six is the point where a false refusal ("I don't need a room, take me to
    // Morthal") starts to outweigh a wrong teleport - and a refusal only ever costs a question.
    // [0.5.1 pt9 go-live / S13] ...but the flat window also reached ACROSS A CLAUSE BOUNDARY, so in
    // "Don't wait here, come with me instead." the leading don't negated the wait verb AND the recruit
    // verb six tokens later: lrgDlgFollowerNegatedOnly() then reported a pure refusal, the turn settled
    // nothing and the model's own paraphrase of the same words could not revive it. $stops carries the
    // token index of every clause start (lrgDlgClauseStarts), and the scan never walks back past one.
    // Passing no stops keeps the old flat behaviour, which is what every caller without the raw
    // utterance in hand gets.
    $from = max(0, $at - 6);
    foreach ($stops as $s) {
        if ((int) $s > $from && (int) $s <= $at) { $from = (int) $s; }
    }
    for ($i = $from; $i < $at; $i++) {
        if (in_array($hay[$i], LRG_DLG_NEG_WORDS, true)) { return true; }
    }
    return false;
}

/**
 * [0.5.1 pt9 go-live / S13] The token index at which each new clause of $utter begins, aligned with
 * lrgDlgTokens(lrgPromptNorm($utter)). lrgPromptNorm strips punctuation, so the boundaries have to be
 * marked BEFORE it runs; if the marked and the plain tokenisations disagree for any reason the answer
 * is [] and the caller keeps the flat six-token window.
 */
function lrgDlgClauseStarts(string $utter): array
{
    if (trim($utter) === '') { return []; }
    $mark = 'lrgclausebreak';
    $marked = (string) preg_replace('/[,;:]+|\s[-\x{2013}\x{2014}]+\s/u', ' ' . $mark . ' ', $utter);
    if ($marked === '' || strpos($marked, $mark) === false) { return []; }
    $starts = [];
    $i = 0;
    foreach (lrgDlgTokens(lrgPromptNorm($marked)) as $w) {
        if ($w === $mark) { $starts[] = $i; } else { $i++; }
    }
    return $i === count(lrgDlgTokens(lrgPromptNorm($utter))) ? array_values(array_unique($starts)) : [];
}

// ------------------------------------------------------------------ E2(b)(c): answering in words
/**
 * "What can I ask you?" -> 'list'   ·   "What should I do next?" -> 'next'   ·   otherwise ''.
 * Both lists are config (ask_list_phrases / ask_next_phrases) and both are matched as WHOLE phrases
 * after normalisation, longest wins - so "what now" never beats "what should i do next" and a passing
 * "is there anything in that chest" does not become a request for her whole list.
 */
function lrgDlgAskKind(string $utter): string
{
    $utter = trim($utter);
    if ($utter === '') { return ''; }
    $l = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('ask_list_phrases', []));
    $n = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('ask_next_phrases', []));
    if ($l === '' && $n === '') { return ''; }
    return strlen($n) > strlen($l) ? 'next' : 'list';
}

/**
 * <what_you_can_ask>. TWO sources and the difference is stated to the model in the tag itself:
 *  certain="1" - the ranked root entries the glue really saw, verbatim, in engine order;
 *  certain="0" - only the quests the game already said she is part of, from the index, explicitly
 *                approximate, and the block FORBIDS the action so nothing can execute from it.
 * No menu is opened and nothing is clicked on either branch.
 */
function lrgDlgAskListBlock(array $t): string
{
    $npc = (string) $t['npc'];
    // [0.5.0 fix pass / S-3] bQuestSummary, qx byte 0. Config default when the game never said.
    if ((int) lrgDlgMcm('qsum', !empty(lrgDlgCfg('quests.summary.enabled', true)) ? 1 : 0, $npc) !== 1) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $st = lrgDlgState($npc);
    $root = lrgDlgRoot($npc, $st);
    $cap = max(1, (int) lrgDlgCfg('quests.summary.max', 8));
    $lines = [];
    if ($root !== null) {
        foreach ((array) $root['entries'] as $e) {
            if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
            $lines[] = lrgDlgShownText((string) $e['text']);
            if (count($lines) >= $cap) { break; }
        }
    }
    if ($lines) {
        lrgDlgLog('ask npc=' . $npc . ' kind=list source=cache rows=' . count($lines), (string) $t['cid']);
        return "<what_you_can_ask certain=\"1\">\n" . $player . ' asked what he can bring up with you. These are'
            . " really on the table between you:\n- " . implode("\n- ", $lines) . "\n"
            . "Answer in character, in your own words - do not read the list out as a list, mention the two or\n"
            . "three that matter most to you, and let the rest wait.\n</what_you_can_ask>";
    }
    $q = array_values(array_filter((array) ($t['q'] ?? [])));
    if (!$q) { return ''; }
    $rows = lrgPromptByQuest($q, max(1, (int) lrgDlgCfg('quests.summary.approximate_max', 6)));
    foreach ($rows as $r) {
        $txt = trim((string) ($r['txt'] ?? ''));
        // NEVER an index template: '<Global=KmodFerryCost>' is a template, not a thing she can offer
        if ($txt === '' || strpos($txt, '<') !== false) { continue; }
        $lines[] = lrgDlgShownText($txt);
    }
    if (!$lines) { return ''; }
    lrgDlgLog('ask npc=' . $npc . ' kind=list source=index-approx rows=' . count($lines), (string) $t['cid']);
    return "<what_you_can_ask certain=\"0\">\n" . $player . ' asked what he can bring up with you. You are NOT'
        . " certain what is open between you -\nthese are only matters you MIGHT have, from the quests you are"
        . " part of:\n- " . implode("\n- ", $lines) . "\n"
        . "Speak like somebody who is not sure: \"I might have something about X\", \"come and ask me properly\".\n"
        . 'Never quote these word for word as if they were on offer, and never use ' . lrgDlgActionName()
        . " from this list.\n</what_you_can_ask>";
}

/**
 * <your_quests>. From CHIM's own questlog through the SAME lrgDlgQuestRows() + lrgDlgCleanObjective()
 * that <shared_business> uses, so "current objective only, never a later stage, never a stage number"
 * and the alias-tag cleanup come for free. The Narrator answers it when nobody is in front of you.
 */
function lrgDlgAskNextBlock(array $t): string
{
    $npc = (string) $t['npc'];
    // [0.5.0 fix pass / S-3] bQuestNext, qx byte 1. Config default when the game never said.
    if ((int) lrgDlgMcm('qnext', !empty(lrgDlgCfg('quests.next.enabled', true)) ? 1 : 0, $npc) !== 1) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $q = array_values(array_filter((array) ($t['q'] ?? [])));
    $rows = $q ? lrgDlgQuestRows($q) : lrgDlgQuestRowsAll();
    $cap = max(1, (int) lrgDlgCfg('quests.next.lines', 3));
    $out = [];
    foreach ($rows as $row) {
        if (count($out) >= $cap) { break; }
        $id = (string) ($row['id_quest'] ?? '');
        if ($id !== '' && lrgAnyGlob((array) lrgDlgCfg('quests.bookkeeping_patterns', []), [$id])) { continue; }
        $obj = lrgDlgCleanObjective((string) ($row['briefing'] ?? ''), $npc);
        if ($obj === '') { continue; }
        $max = (int) lrgDlgCfg('quests.max_chars', 120);
        if (strlen($obj) > $max) { $obj = rtrim(substr($obj, 0, $max - 1)) . '.'; }
        $out[] = $obj;
    }
    if (!$out) { return ''; }
    lrgDlgLog('ask npc=' . $npc . ' kind=next source=journal rows=' . count($out), (string) $t['cid']);
    return "<your_quests>\n" . $player . " asked where he stands. What his journal says right now:\n- \""
        . implode("\"\n- \"", $out) . "\"\n"
        . "Say it the way you would say it - what you know of it, what you would do. Never mention a stage,\n"
        . "a number or a step he has not been shown.\n</your_quests>";
}

/** The player's own active quests, newest row per quest. Used when the responder is part of none. */
function lrgDlgQuestRowsAll(): array
{
    if (isset($GLOBALS['LRG_DLG_TEST_QUESTLOG'])) {
        $out = [];
        foreach ((array) $GLOBALS['LRG_DLG_TEST_QUESTLOG'] as $id => $row) {
            $out[strtolower((string) $id)] = ['id_quest' => (string) $id] + (array) $row;
        }
        return $out;
    }
    $db = lrgDb();
    if (!$db) { return []; }
    try {
        $rows = (array) $db->fetchAll('SELECT DISTINCT ON (id_quest) id_quest, briefing, stage FROM questlog'
            . ' ORDER BY id_quest, rowid DESC LIMIT 20');
    } catch (Throwable $e) {
        lrgDlgLog('questlog read failed: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) { $out[strtolower((string) ($r['id_quest'] ?? ''))] = (array) $r; }
    return $out;
}

// ------------------------------------------------------------------ E2(d): the corner hint
/**
 * do=noop;note=<text> - the ONE-LINE server change LRG_Main.psc:532-535 predicted when it shipped the
 * note= reader dormant. Emitted at most once per session per NPC, and only when the list the glue just
 * read really does carry something quest-bearing.
 */
function lrgDlgQuestHint(array $t): ?string
{
    if (empty(lrgDlgCfg('quests.hint.enabled', true))) { return null; }
    if (empty($t['on']) || (empty($t['speech']) && empty($t['talk']))) { return null; }
    $npc = (string) $t['npc'];
    $st = lrgDlgState($npc);
    $sid = (string) ($t['sid'] ?? '0');
    if ((string) ($st['hint_sid'] ?? '') === $sid && $sid !== '') { return null; }
    if ((int) ($st['hint_n'] ?? 0) >= max(1, (int) lrgDlgCfg('quests.hint.per_session', 1))
        && (string) ($st['hint_sid'] ?? '') === $sid) { return null; }
    $q = array_map('strtolower', (array) ($t['q'] ?? []));
    $quest = '';
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        if (!in_array((string) $e['class'], ['plain', 'service'], true)) { continue; }
        if ((int) $e['crit'] !== 0 || !empty($e['commit'])) { continue; }
        if ((string) $e['quest'] !== '' && (int) $e['journal'] === 1
            && in_array(strtolower((string) $e['quest']), $q, true)) { $quest = (string) $e['quest']; break; }
        if ((int) $e['new'] === 1 && (int) $e['indexed'] === 1 && (int) $e['toplevel'] === 1) { $quest = '-'; }
    }
    if ($quest === '') { return null; }
    $note = $npc . ' has something worth asking about';
    if ($quest !== '-' && lrgDlgQuestKnown($quest)) {
        $rows = lrgDlgQuestRows([$quest]);
        $row = (array) ($rows[strtolower($quest)] ?? []);
        $name = lrgDlgCleanObjective((string) ($row['briefing'] ?? ''), $npc);
        if ($name !== '') { $note = $npc . ' has something about ' . rtrim(substr($name, 0, 70), " .,"); }
    }
    $note = trim((string) preg_replace('/[;=@|"~]/', ' ', $note));
    if (strlen($note) > 120) { $note = rtrim(substr($note, 0, 117)) . '...'; }
    lrgDlgPut($npc, ['hint_sid' => $sid, 'hint_n' => (int) ($st['hint_n'] ?? 0) + 1]);
    lrgDlgLog('hint npc=' . $npc . ' quest=' . ($quest !== '-' ? $quest : '?') . ' note="' . $note
        . '" (once per session)', (string) $t['cid']);
    return lrgDlgEmit($npc, ['do' => 'noop', 'sid' => $sid, 'gen' => (int) ($t['gen'] ?? 0), 'pos' => -1,
        'i' => -1, 'txt' => '', 'kind' => 'hint', 'note' => $note], (string) $t['cid'], 'hint', null);
}

// ------------------------------------------------------------------ E4(b): the hold / movement policy
/**
 * [0.5.0 / E4(b), research/pt8-walkaway.md] THE MOVEMENT STALL.
 * bConvHold holds the NPC with SetDontMove(true). CHIM may then be told ComeCloser / FollowPlayer /
 * TravelTo - and the game-side release NoteChimAction() is DORMANT for CHIM's own catalog actions,
 * because the DLL raises CHIM_CommandReceived only on the ExtCmd branch (LRG_Main.psc:1144-1150).
 * She cannot move, the command never completes, and the player sees her refuse to follow.
 *
 * Half 1 (here): while a fresh hold is live and the player's own words did NOT ask her to move, the
 * movement actions are not on the table at all - the same reasoning that already withholds
 * EndConversation. Half 2 (lrgDlgPostProcessActions): when the player DID ask, the actions stay and a
 * do=release line is PREPENDED to the batch so the game lets go of her first.
 *
 * Placement: brace depth 0 in functions.php, immediately AFTER lrgDlgHideEndConversationOnHold(), so it
 * only ever REMOVES and is evaluated LAST - a service toggle can never put one of these back (R21).
 */
function lrgDlgHoldMovementPolicy(): void
{
    unset($GLOBALS['LRG_DLG_HOLD_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_MOVE']);
    if (empty(lrgDlgCfg('hold_hides_movement', true))) { return; }
    if (!function_exists('lrgHideActions') || !function_exists('lrgIsOffered')) { return; }
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($npc === '') { return; }
    $kv = lrgDlgSnapshotKv($npc);
    if (!is_array($kv) || (string) ($kv['hold'] ?? '') !== '1') { return; }
    $maxAge = max(5, (int) lrgDlgCfg('hold_max_age_seconds', 45));
    if ((int) ($kv['_age'] ?? 9999) > $maxAge) { return; }
    $moves = (array) lrgDlgCfg('hold_move_actions', []);
    $asked = lrgDlgPlayerAskedToMove($npc);
    if ($asked !== '') {
        // the player's own words asked her to move: the actions stay, and the post-gate releases the
        // hold in the same batch, BEFORE CHIM's movement action executes
        $GLOBALS['LRG_DLG_HOLD_MOVE'] = ['npc' => $npc, 'why' => $asked];
        lrgDlgLog('hold npc=' . $npc . ' movement allowed - the player asked ("' . $asked . '")');
        return;
    }
    $hid = [];
    foreach ($moves as $code) { if (lrgIsOffered((string) $code)) { $hid[] = (string) $code; } }
    if (!$hid) { return; }
    lrgHideActions($hid);
    $GLOBALS['LRG_DLG_HOLD_HIDDEN'] = $hid;
    foreach ($hid as $code) {
        lrgDlgLog('hold npc=' . $npc . ' movement ' . $code . ' hidden (the game is holding her)');
    }
}

/** The snapshot kv for one NPC, through the offline seam when a test set one. Never throws. */
function lrgDlgSnapshotKv(string $npc): ?array
{
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) { return (array) ($GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? []); }
    try {
        $kv = function_exists('lrgGetNpcState') ? lrgGetNpcState($npc) : null;
    } catch (Throwable $e) {
        return null;
    }
    return is_array($kv) ? $kv : null;
}

/** Did the player's own last words ask her to move? Returns the phrase, or ''. */
function lrgDlgPlayerAskedToMove(string $npc): string
{
    $utter = (string) (lrgDlgGet($npc)['utter']['text'] ?? '');
    if (trim($utter) === '') { return ''; }
    $hit = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('move_request_words', []));
    if ($hit === '' && function_exists('lrgIntentEscort')) {
        // [0.5.4 / pt13] the escort recogniser knows more ways to say it ("come along", a bare "come on",
        // "you can go back") and already applies its own question / negation / hypothetical blockers
        $esc = lrgIntentEscort($utter);
        if ($esc !== null) { return (string) $esc['frag']; }
    }
    if ($hit === '') { return ''; }
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $at = lrgDlgTokenAt($hay, lrgDlgTokens(lrgPromptNorm($hit)));
    if ($at >= 0 && lrgDlgNegatedAt($hay, $at, lrgDlgClauseStarts($utter))) { return ''; }   // [S13]
    return $hit;
}

/**
 * [0.5.0 W8] do=release. Prepended to the batch when this turn carries one of CHIM's own movement
 * actions while the game is holding this NPC. A game script 401 answers one "Error: unknown command"
 * funcret and nothing else - the server never depends on it, because half 1 alone is correct and safe.
 */
function lrgDlgReleaseLine(array $lines): ?string
{
    if (empty(lrgDlgCfg('hold_release_on_move', true))) { return null; }
    $ctx = (array) ($GLOBALS['LRG_DLG_HOLD_MOVE'] ?? []);
    $npc = (string) ($ctx['npc'] ?? '');
    if ($npc === '') { return null; }
    $moves = array_map('strtolower', (array) lrgDlgCfg('hold_move_actions', []));
    foreach ($lines as $line) {
        $parts = explode('|', rtrim((string) $line, "\r\n"), 3);
        $cmd = explode('@', $parts[2] ?? '', 2);
        $code = strtolower(trim($cmd[0] ?? ''));
        if ($code !== '' && in_array($code, $moves, true)) {
            lrgDlgLog('hold npc=' . $npc . ' movement ' . trim($cmd[0]) . ' released (do=release goes first)');
            return lrgDlgEmit($npc, ['do' => 'release', 'sid' => '0', 'gen' => 0, 'pos' => -1, 'i' => -1,
                'txt' => '', 'kind' => 'hold'], 'holdmove', 'holdmove', null);
        }
    }
    return null;
}

// ------------------------------------------------------------------ E6: service dialogue
/** Is the service half on at all? The MCM's bServiceDialogue rides the snapshot as sv= (W11). */
function lrgDlgServicesOn(string $npc): bool
{
    return (int) lrgDlgMcm('sv', !empty(lrgDlgCfg('services.enabled', true)) ? 1 : 0, $npc) === 1;
}

/** The service kind the player's own words ask for, or ''. Longest phrase across all enabled kinds. */
function lrgDlgServiceKind(string $utter): string
{
    $best = '';
    $bestN = 0;
    foreach ((array) lrgDlgCfg('services.kinds', []) as $kind => $spec) {
        if (empty($spec['enabled'])) { continue; }
        $hit = lrgDlgPhraseHit($utter, (array) ($spec['phrases'] ?? []));
        // [0.5.7 / pt16 review] "can i buy | you a drink" is not this kind at all (services.kinds.<kind>.not_after)
        if ($hit !== '' && lrgDlgHitFollowedBy($utter, $hit, (array) ($spec['not_after'] ?? []))) { continue; }
        if ($hit !== '' && strlen($hit) > $bestN) { $best = (string) $kind; $bestN = strlen($hit); }
    }
    return $best;
}

/**
 * [0.5.7 / pt16 review] Are the tokens right AFTER the matched phrase one of $stops? Exact runs, like the hit
 * itself: "can i buy | you a drink" is a gesture, "can i buy | your silence" a bribe, "sell me | a sweetroll" trade.
 */
function lrgDlgHitFollowedBy(string $utter, string $hit, array $stops): bool
{
    if ($hit === '' || !$stops) { return false; }
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $pt = lrgDlgTokens(lrgPromptNorm($hit));
    $at = lrgDlgTokenAt($hay, $pt);
    if ($at < 0) { return false; }
    $after = array_slice($hay, $at + count($pt));
    foreach ($stops as $s) {
        $st = lrgDlgTokens(lrgPromptNorm((string) $s));
        if ($st && array_slice($after, 0, count($st)) === $st) { return true; }
    }
    return false;
}

/** Is the player asking what a thing COSTS rather than telling her to do it? */
function lrgDlgIsPriceQuestion(string $utter): bool
{
    return lrgDlgPhraseHit($utter, (array) lrgDlgCfg('services.price_words', [])) !== '';
}

/**
 * THE ONE GENUINELY HARD PROBLEM E6 HAS TO SOLVE (plan 18.3).
 * A destination layer does not look like a sentence. CFTO's entries are literally "Morthal. (35 gold)"
 * and "Solitude Lighthouse. (35 gold)", and 4,620 of the 37,561 index rows (12.3 %) normalise to two
 * words or fewer. The similarity matcher (min_score 0.55, tuned on sentences) is both unreliable and
 * DANGEROUS there: "Solitude." and "Solitude Lighthouse." differ by one token, and picking the wrong
 * one spends the player's gold and teleports him to the wrong hold. That is risk R12, the worst bug
 * this round could ship.
 *
 * So: a price list is recognised FROM THE LIVE LIST (never from the index), slot names come from the
 * layer itself (never from a hard-coded list of 19 vanilla destinations), matching is EXACT token
 * containment with LONGEST-WINS, ambiguity ASKS instead of guessing, a price question executes nothing,
 * and when no slot matches the similarity matcher is not consulted for execution at all.
 *
 * Returns null when this is not a price list (ordinary matching applies), or
 *   ['mode' => 'pick',  'entry' => e, 'slot' => name]
 *   ['mode' => 'ask',   'slots' => [name, name]]
 *   ['mode' => 'price', 'slots' => [name, ...]]     (a price question: answer, execute nothing)
 *   ['mode' => 'none']                              (a price list and nothing matched: execute NOTHING)
 */
function lrgDlgServiceSlot(array $entries, string $utter, string $kind = '', string $npc = ''): ?array
{
    $cfg = (array) lrgDlgCfg('services.slot', []);
    $minEntries = max(1, (int) ($cfg['min_entries'] ?? 3));
    $maxWords = max(1, (int) ($cfg['max_words'] ?? 3));
    $minPriced = max(0, (int) ($cfg['min_priced'] ?? 0));
    $short = 0;
    $priced = 0;
    $slots = [];
    foreach ($entries as $e) {
        $cls = (string) ($e['class'] ?? 'plain');
        if ($cls === 'hidden') { continue; }
        $tok = lrgDlgTokens((string) ($e['norm'] ?? ''));
        if (count($tok) <= $maxWords && $tok) { $short++; }
        if ((int) ($e['cost'] ?? 0) > 0) { $priced++; }
        if ($cls === 'back' || !$tok || count($tok) > $maxWords) { continue; }
        $slots[] = ['name' => implode(' ', $tok), 'tok' => $tok, 'e' => $e];
    }
    ///[0.5.1 pt9 go-live / S10] min_priced = 0 IS FOR SERVICE TURNS, NOT FOR EVERY SHORT LIST. The
    // config comment explains why a carriage list needs it (CFTO prices one row and leaves the rest
    // bare), but with nothing else to hold it back any layer of >= 3 short entries and 2 candidates was
    // treated as a price list - 138 of 5,718 live layers (2.4%) carry no priced entry at all and were
    // routed through here anyway: "feim / fus / yol", "heavy / light / medium", "consider it done /
    // forget it / i don't have time right now / i'll do it", "master bedroom / new banners / poisoner's
    // nook / secret entrance". On those, execution was restricted to exact token containment and a
    // paraphrase of a short quest answer could not be taken up at all. So the relaxation now needs a
    // reason: a priced entry somewhere on the layer, or words that really ask for a service.
    if ($minPriced < 1 && $priced < 1 && $kind === '' && lrgDlgServiceKind($utter) === '') {
        $minPriced = 1;
    }
    if ($short < $minEntries || $priced < $minPriced || count($slots) < 2) { return null; }
    // [pt19 v1.0 / capability map U3] A DAYS LIST (Xtended Stay: "1 day. (25 gold)" ... "7 days"): lrgPromptNorm turns every
    // digit into '#', so six slots read "# days" and nothing but a literal "1 day" could be said. On a layer whose every
    // slot is a number of days the number is read from the LIVE text, number words count ("one", "two"), a night counts
    // as a day, and "tonight" / "for the night" / "a night" are one day; the negation and price-question guards stay.
    $daysList = true;
    foreach ($slots as $s) { if (!preg_match('/^# days?$/', (string) $s['name'])) { $daysList = false; break; } }
    if ($daysList) { return lrgDlgDaysSlot($slots, $utter, $npc); }
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $names = array_map(static fn($s) => (string) $s['name'], $slots);
    if (lrgDlgIsPriceQuestion($utter)) {
        $hit = [];
        foreach ($slots as $s) { if (lrgDlgTokenRun($hay, $s['tok'])) { $hit[] = (string) $s['name']; } }
        return ['mode' => 'price', 'slots' => $hit ?: $names];
    }
    $stops = lrgDlgClauseStarts($utter);   // [S13] a negation never reaches across a clause boundary
    $matched = [];
    foreach ($slots as $s) {
        $at = lrgDlgTokenAt($hay, $s['tok']);
        if ($at < 0 || lrgDlgNegatedAt($hay, $at, $stops)) { continue; }
        $matched[] = $s;
    }
    if (!$matched) { return ['mode' => 'none', 'slots' => $names]; }
    // LONGEST WINS: a slot contained in another matching slot is never the answer.
    // "take me to the solitude lighthouse" matches both 'solitude' and 'solitude lighthouse';
    // "take me to solitude" matches only 'solitude'. Both directions are asserted by d57_slots.
    $maximal = [];
    foreach ($matched as $a) {
        $covered = false;
        foreach ($matched as $b) {
            if ($a['name'] === $b['name']) { continue; }
            if (count($b['tok']) > count($a['tok']) && lrgDlgTokenRun($b['tok'], $a['tok'])) { $covered = true; break; }
        }
        if (!$covered) { $maximal[] = $a; }
    }
    if (count($maximal) === 1) {
        // [pt19c fixer / adversarial QA @adv_price] a sum BELOW the slot's live price is no agreement to pay it: she names the price
        $named = lrgDlgNamedAmount(function_exists('lrgDlgMoneyFold') ? lrgDlgMoneyFold($utter) : $utter);
        $price = (int) (((array) $maximal[0]['e'])['cost'] ?? 0);
        if ($named > 0 && $price > 0 && $named < $price) { return ['mode' => 'price', 'slots' => [(string) $maximal[0]['name']]]; }
        // [0.5.0 fix pass / S-3] bCarriageByName, svx byte 1. OFF means "do not let me pick it off her
        // list by naming it" - and the ONLY safe reading of that is "ask instead", never "go back to the
        // similarity matcher", which is the thing R12 forbids. So the verdict degrades to 'none'.
        if ($npc !== '' && (int) lrgDlgMcm('svbn', 1, $npc) !== 1) {
            lrgDlgLog('svc npc=' . $npc . ' slot="' . (string) $maximal[0]['name']
                . '" named exactly, but bCarriageByName is OFF - she asks instead of picking');
            return ['mode' => 'none', 'slots' => $names];
        }
        // ---- [0.5.1 pt9 go-live / S3] MENTIONING A PLACE IS NOT ORDERING A RIDE ----------------------
        // Exact token containment answers "did he say this word", never "did he ask for this". On live
        // CFTO layers that made all of these an executable order: "I've just come from Riften.", "My
        // brother lives in Morthal.", "They call me Riften, after the city." (the player's own name),
        // "Is the road to Dawnstar safe?", "Solitude is a beautiful city, is it not?". On the vanilla
        // list the click was only deferred by the accidental scripted+goodbye commit park - which the
        // next turn then released - and on a short priced layer whose entries are not both scripted and
        // goodbye (KmodFastTravelCarriageEastmarch, ...FalkreathHold, ...Haafingar) it fired on the
        // FIRST turn with no confirmation at all. So an imperative frame is now required: either the
        // phrase table recognises the sentence as a service request ('take me to', 'a ride to', 'rent a
        // room', 'train me in', ...), or the whole utterance IS the slot name ("Morthal."). Anything
        // else degrades to 'none' - the verdict that already means "a price list and nothing matched:
        // execute NOTHING", and whose <her_list> wording is exactly right here ("he has not named one
        // of them - ask which he means; do not choose for him"). 'ask' would have her say "two of them
        // could be meant", which is not what happened.
        if (lrgDlgServiceKind($utter) === '' && implode(' ', $hay) !== (string) $maximal[0]['name']) {
            lrgDlgLog('svc npc=' . ($npc !== '' ? $npc : '-') . ' slot="' . (string) $maximal[0]['name']
                . '" is named in "' . substr(trim($utter), 0, 50) . '" but nothing in it asks for it'
                . ' - she asks instead of executing');
            return ['mode' => 'none', 'slots' => $names];
        }
        return ['mode' => 'pick', 'entry' => $maximal[0]['e'], 'slot' => (string) $maximal[0]['name']];
    }
    if ((string) ($cfg['ambiguous'] ?? 'ask') !== 'ask') { return ['mode' => 'none', 'slots' => $names]; }
    return ['mode' => 'ask', 'slots' => array_map(static fn($s) => (string) $s['name'], $maximal)];
}

/**
 * [pt19 v1.0 / capability map U3] The slot verdict on a DAYS list (every slot "<n> day(s)"): the same shapes as
 * lrgDlgServiceSlot (pick / price / none), the number read from the live text. "one night", "two nights", "a room for the
 * night", "for one night", "tonight" name a stay; "not tonight" is negated; "how much for a night" is a price question;
 * "for a week" names no listed number (she asks which).
 */
function lrgDlgDaysSlot(array $slots, string $utter, string $npc = ''): array
{
    $names = [];
    $byN = [];
    foreach ($slots as $s) {
        $e = (array) $s['e'];
        $n = preg_match('/(\d+)/', (string) ($e['text'] ?? ''), $mm) ? (int) $mm[1] : 0;
        $names[] = $n > 0 ? ($n . ($n === 1 ? ' day' : ' days')) : (string) $s['name'];
        if ($n > 0 && !isset($byN[$n])) { $byN[$n] = $e; }
    }
    if (lrgDlgIsPriceQuestion($utter)) { return ['mode' => 'price', 'slots' => $names]; }
    $tok = array_values(array_filter(preg_split('/\s+/', trim((string) preg_replace('/[^a-z0-9\' ]+/', ' ', strtolower($utter)))) ?: [], 'strlen'));
    // [pt19c fixer / adversarial QA @adv_chain] the owner's STT spells the night "nite" ("i'd like a room for the nite")
    $tok = array_map(static fn($w) => ['nite' => 'night', 'nites' => 'nights', 'tonite' => 'tonight', 'nigh' => 'night'][$w] ?? $w, $tok);
    $num = defined('LRG_MKT_NUMWORDS') ? LRG_MKT_NUMWORDS : ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    $num += ['single' => 1, 'won' => 1];
    $want = 0;
    $at = -1;
    foreach ($tok as $i => $w) {
        $next = (string) ($tok[$i + 1] ?? '');
        $v = ctype_digit($w) ? (int) $w : (int) ($num[$w] ?? 0);
        if ($v > 0 && in_array($next, ['day', 'days', 'night', 'nights'], true)) { $want = $v; $at = $i; break; }
        if ($w === 'tonight' || ($w === 'the' && $next === 'night')) { $want = 1; $at = $i; break; }
    }
    if ($want <= 0) { return ['mode' => 'none', 'slots' => $names]; }
    if (lrgDlgNegatedAt($tok, $at, lrgDlgClauseStarts($utter))) { return ['mode' => 'none', 'slots' => $names]; }
    if (!isset($byN[$want])) { return ['mode' => 'none', 'slots' => $names]; }
    // [pt19c fixer / adversarial QA @adv_price] the idiom "one day I'll come back" (a subject right after the day) names no stay
    $after = (string) ($tok[$at + 2] ?? '');
    if (in_array($after, ['i', "i'll", 'ill', "i'm", 'im', "i'd", 'id', 'you', "you'll", 'youll', 'we', "we'll", 'well', 'he', 'she',
        'they', 'it', "it'll", 'someone', 'somebody'], true) && preg_match('/^(one|a|1)$/', (string) ($tok[$at] ?? ''))) {
        return ['mode' => 'none', 'slots' => $names];
    }
    // ... and a sum BELOW the live price is no agreement to pay it ("twenty septims for one day" on the 25-septim day): the price
    // question's verdict - she names the price, nothing is paid (the bribe rails refuse the same)
    $named = lrgDlgNamedAmount(function_exists('lrgDlgMoneyFold') ? lrgDlgMoneyFold($utter) : $utter);
    $price = (int) ($byN[$want]['cost'] ?? 0) > 0 ? (int) $byN[$want]['cost'] : lrgPromptCost((string) ($byN[$want]['text'] ?? ''));
    if ($named > 0 && $price > 0 && $named < $price) { return ['mode' => 'price', 'slots' => $names]; }
    if ($npc !== '' && (int) lrgDlgMcm('svbn', 1, $npc) !== 1) { return ['mode' => 'none', 'slots' => $names]; }
    return ['mode' => 'pick', 'entry' => $byN[$want], 'slot' => $want . ($want === 1 ? ' day' : ' days')];
}

/**
 * REAL ENTRY vs CHIM SHORTCUT, arbitrated in one place (decision D4 + plan 18.3).
 * Returns null when this is not a price list (ordinary matching applies), or
 * ['entry' => <the entry to execute>] / ['entry' => null] (execute NOTHING this turn).
 * Both the model's own words ($item) and the player's own utterance are tried, because either may be
 * the closer paraphrase - but BOTH go through exact containment, never similarity.
 */
function lrgDlgServiceArbitrate(array $t, array $pool, string $item, string $cid): ?array
{
    $npc = (string) $t['npc'];
    if (!lrgDlgServicesOn($npc)) { return null; }
    $utter = (string) (lrgDlgGet($npc)['utter']['text'] ?? '');
    $kind = (string) (($t['svc'] ?? [])['kind'] ?? '');
    $probe = lrgDlgServiceSlot($pool, $utter, $kind, $npc);
    if ($probe === null) { return null; }                       // not a price list: nothing changes
    $names = implode(', ', array_slice((array) ($probe['slots'] ?? []), 0, 4));
    foreach ([$utter, $item] as $cand) {
        if (trim((string) $cand) === '') { continue; }
        $r = lrgDlgServiceSlot($pool, (string) $cand, $kind, $npc);
        if (!is_array($r)) { continue; }
        if ((string) $r['mode'] === 'price') {
            lrgDlgLog(sprintf('svc npc=%s kind=%s slot=- pos=-1 cost=0 src=none why=price-question', $npc,
                $kind !== '' ? $kind : 'service'), $cid);
            return ['entry' => null];
        }
        if ((string) $r['mode'] === 'ask') {
            lrgDlgLog(sprintf('svc npc=%s AMBIGUOUS %s - asked instead of guessing', $npc,
                implode(' vs ', array_slice((array) $r['slots'], 0, 3))), $cid);
            return ['entry' => null];
        }
        if ((string) $r['mode'] === 'pick') {
            $e = (array) $r['entry'];
            lrgDlgLog(sprintf('svc npc=%s kind=%s slot=%s pos=%d cost=%d src=entry why=exact-longest-wins',
                $npc, $kind !== '' ? $kind : 'service', (string) $r['slot'], (int) $e['pos'], (int) $e['cost']), $cid);
            return ['entry' => $e];
        }
    }
    lrgDlgLog(sprintf('svc npc=%s kind=%s slot=- pos=-1 cost=0 src=none why=no-slot-named (the similarity'
        . ' matcher is NOT consulted on a price list: %s)', $npc, $kind !== '' ? $kind : 'service', $names), $cid);
    return ['entry' => null];
}

/** The service kind a LAYER looks like, for the svck= drift check and the corner note's wording. */
function lrgDlgServiceKindOfLayer(array $entries): string
{
    $cfg = (array) lrgDlgCfg('services.slot', []);
    $maxWords = max(1, (int) ($cfg['max_words'] ?? 3));
    $short = 0;
    $priced = 0;
    foreach ($entries as $e) {
        if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
        $tok = lrgDlgTokens((string) ($e['norm'] ?? ''));
        if ($tok && count($tok) <= $maxWords) { $short++; }
        if ((int) ($e['cost'] ?? 0) > 0) { $priced++; }
    }
    if ($short < max(1, (int) ($cfg['min_entries'] ?? 3)) || $priced < max(0, (int) ($cfg['min_priced'] ?? 0))) {
        return '';
    }
    return 'pricelist';
}

/**
 * The hide wiring (decision D4, plan 18.4). The shipped rule is unchanged and still does the work:
 * when a list is KNOWN, hide_chim steps aside for the real entry. What E6 adds is
 *  (a) the per-kind lists, asserted to be a SUBSET of hide_chim + hide_always so the two cannot drift;
 *  (b) bServiceShortcut = OFF -> the shortcuts are hidden even when no list can be read, because the
 *      owner said he would rather she admitted she cannot help than teleport him with a flat 20 gold;
 *  (c) R21: whatever the HOLD policy hid is never given back by a service toggle. The hold is
 *      evaluated last and wins, and the restore below subtracts its set explicitly.
 */
function lrgDlgServiceHidePolicy(array $turn, bool $menuless = true): array
{
    $npc = (string) $turn['npc'];
    if (!lrgDlgServicesOn($npc)) { return []; }
    // [0.5.0 fix pass / S-2] a list the driver cannot actually open is not a replacement for anything,
    // so it counts as no list: with ml = 0 the only thing that may still hide a shortcut is the owner
    // having switched bServiceShortcut off himself.
    $known = (bool) (($turn['entries'] ?? []) || ($turn['tail'] ?? [])) && $menuless;
    $hide = [];
    foreach ((array) lrgDlgCfg('services.kinds', []) as $kind => $spec) {
        if (empty($spec['enabled'])) { continue; }
        // [0.5.1] THE FOLLOWER KIND HAS ITS OWN CONDITION, and it is narrower than $known on purpose.
        // "she has some list" is no reason to take CHIM's Follow_<player> away; only a list that
        // really carries the follower framework's own entries is a replacement for it. Never hidden
        // on the bServiceShortcut=off path either - that toggle is about rooms and rides.
        if ($kind === 'follower') {
            if ($menuless && lrgDlgFollowerOn($npc) && (array) (($turn['fol'] ?? [])['verbs'] ?? [])) {
                foreach ((array) ($spec['hide'] ?? []) as $code) { $hide[] = (string) $code; }
            }
            continue;
        }
        foreach ((array) ($spec['hide'] ?? []) as $code) {
            // ForgiveCrime: always, while the glue can really run the crime session (decision D4)
            if ($kind === 'crime') { if ($menuless) { $hide[] = (string) $code; } continue; }
            if ($known) { $hide[] = (string) $code; }
            // [0.5.0 fix pass / S-3] bServiceShortcut, svx byte 0. Config default when the game never said.
            // [0.5.7 / pt16] ... except for BARTER: the owner's reason for that switch was a flat 10 / 20 /
            // 50 gold where the real entry knows the real price, and CHIM's OpenInventory IS the real
            // barter window with the real prices. Direct barter (lrgDlgServiceNet) rides on this code
            // when no session is possible, so bServiceShortcut never takes trade away.
            elseif ($kind !== 'barter' && (int) lrgDlgMcm('svsc', !empty(lrgDlgCfg('services.shortcut_fallback', true)) ? 1 : 0,
                $npc) !== 1) { $hide[] = (string) $code; }
        }
    }
    // [pt19-purchase] an order of food or drink: Give_Item_To is bound to her PERSONAL inventory and can never hand over
    // stock from the merchant chest; on a queued order the carrier is the route, so the barter window stands aside too
    foreach (lrgMktHide($turn) as $code) { $hide[] = (string) $code; }
    return array_values(array_unique($hide));
}

// ------------------------------------------------------------------ [0.5.7 / pt16] DIRECT BARTER
/*
 * "What do you have for sale?" - Jala, Addvar and Evette San, Solitude market, 2026-09-23 16:40-16:43:
 * five turns, nothing opened, nobody said why. The REAL entry (E6) needs a hidden session, which the
 * shipped bMenuless = 0 / bDlgDryRun = 1 never gives (and the red calibration forces dry run whatever the
 * toggle says). CHIM's own OpenInventory (Trade_Items) IS the vanilla barter window -
 * AIAgentAIMind.OpenInventory -> npc.ShowBarterMenu() for every non-teammate, npc.OpenInventory(true) for
 * a teammate - and it was on the table every time; the model chose it on 2 of 7 barter turns today
 * (Beirand, 04:41 / 04:42, once with no spoken line) and on none of tonight's five. So barter is now
 * carried on CHIM's OWN code, deterministically: lrgDlgServiceRequestBlock() names it, and
 * lrgDlgServiceNet() appends it when the reply lacks it. No new command, no game-side change, no
 * catalog row: PROTOCOL 10.15 D4 already makes CHIM's shortcut the fallback whenever no session is
 * possible, and owner addendum 3h wants fewer actions, not more.
 *
 * What still holds barter back (lrgDlgServiceDirect): a price question, a negated phrase, the real
 * entry (ml=1 with a known list - hide_chim takes OpenInventory off the table for it, D4), a FRESH
 * snapshot saying combat, and a companion (CHIM's own teammate branch answers her; she is never
 * refused). A MISSING or stale snapshot is deliberately NOT a reason: Jala's and Addvar's first turns
 * tonight had none (age 212647 s / 42945 s), and first contact at a stall is exactly when this is asked.
 */

/** Phase 2's own view of "is CHIM offering this code this turn" - lrgIsOffered() when the intimacy lane is loaded. */
function lrgDlgOffered(string $code): bool
{
    if (function_exists('lrgIsOffered')) { return lrgIsOffered($code); }
    $en = array_map(static fn($c) => strtolower((string) $c), (array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    return in_array(strtolower($code), $en, true);
}

/** The display name the MODEL sees for one of CHIM's own codes ("Trade_Items" for OpenInventory), or the code. */
function lrgDlgChimName(string $code): string
{
    if (function_exists('lrgChimActionName')) { return lrgChimActionName($code); }
    return $code;
}

/**
 * vendor | none | unknown, from the snapshot the game last sent. Wording only - the server never tells her
 * she "sells nothing" on its own authority, because fac= is capped (48 factions / 560 chars) and the
 * barter window itself is the authority on what she has. `none` needs a snapshot that DID carry factions.
 */
function lrgDlgVendorHint(?array $snap): string
{
    if (!is_array($snap)) { return 'unknown'; }
    $class = strtolower((string) ($snap['class'] ?? ''));
    if ($class !== '' && str_starts_with($class, 'vendor')) { return 'vendor'; }
    $fac = ',' . strtolower(trim((string) ($snap['fac'] ?? ''))) . ',';
    if ($fac === ',,') { return 'unknown'; }
    if (preg_match('/,(services[a-z0-9_]*|job(merchant|streetvendor|innkeeper|blacksmith|apothecary|fletcher|spell'
        . '|fence|jeweler|animaltrainer|generalmerchant)faction)(,|$)/', $fac)) { return 'vendor'; }
    return 'none';
}

/** '' or why a follower framework / the player's party owns her (lrgEscortFacts' own verdict when it is loaded). */
function lrgDlgCompanionOwner(string $npc, ?array $snap): string
{
    if ($npc !== '' && function_exists('lrgEscortFacts')) {
        try { $owner = (string) (lrgEscortFacts($npc)['owner'] ?? ''); } catch (Throwable $e) { $owner = ''; }
        if ($owner !== '') { return $owner; }
    }
    if (is_array($snap) && (string) ($snap['mate'] ?? '') === '1') { return 'teammate'; }
    return '';
}

/**
 * The ONE decision: may this barter request be carried on CHIM's own OpenInventory this turn?
 * Returns ['direct' => 0|1, 'why' => '' | not barter | off | not player speech | price question | negated |
 *          real entry | combat | companion, 'vendor' => vendor|none|unknown].
 */
function lrgDlgServiceDirect(array $turn, string $utter, ?array $snap, bool $isSpeech): array
{
    $out = ['direct' => 0, 'why' => '', 'vendor' => lrgDlgVendorHint($snap)];
    $npc = (string) ($turn['npc'] ?? '');
    if ((string) (($turn['svc'] ?? [])['kind'] ?? '') !== 'barter') { $out['why'] = 'not barter'; return $out; }
    if (empty(lrgDlgCfg('services.direct.barter', true))) { $out['why'] = 'off'; return $out; }
    // the module's own lrg_dlgtalk turn has a hidden session of its own; a directive belongs to player speech
    if (!$isSpeech) { $out['why'] = 'not player speech'; return $out; }
    // "how much for the salmon" asks, it does not order (the same rule the slot guard applies, R12)
    if (lrgDlgIsPriceQuestion($utter)) { $out['why'] = 'price question'; return $out; }
    // a negation within six tokens before the phrase, never across a clause boundary: "don't sell me anything"
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    $hit = lrgDlgPhraseHit($utter, (array) lrgDlgCfg('services.kinds.barter.phrases', []));
    $at = $hit !== '' ? lrgDlgTokenAt($hay, lrgDlgTokens(lrgPromptNorm($hit))) : -1;
    if ($at >= 0 && lrgDlgNegatedAt($hay, $at, lrgDlgClauseStarts($utter))) { $out['why'] = 'negated'; return $out; }
    // the REAL entry wins (D4): with ml=1 and a known list hide_chim takes OpenInventory away for it
    $menuless = (int) lrgDlgMcm('ml', 1, $npc) === 1;
    if ($menuless && (($turn['entries'] ?? []) || ($turn['tail'] ?? []))) { $out['why'] = 'real entry'; return $out; }
    // a FRESH snapshot saying she (or the player) is fighting; a missing or stale one is no reason
    if (is_array($snap) && (string) ($snap['combat'] ?? '0') === '1'
        && (int) ($snap['_age'] ?? 9999) <= max(5, (int) lrgDlgCfg('services.direct.combat_max_age_seconds', 90))) {
        $out['why'] = 'combat';
        return $out;
    }
    // somebody's companion: CHIM's own teammate branch answers her, and the net never refuses one
    if (lrgDlgCompanionOwner($npc, $snap) !== '') { $out['why'] = 'companion'; return $out; }
    // a snapshot that DID list her factions and found no vendor among them: the model decides, the net stays out
    if ($out['vendor'] === 'none') { $out['why'] = 'not a vendor'; return $out; }
    $out['direct'] = 1;
    return $out;
}

/**
 * [0.5.7 / pt16] "Do it, or say why - never ignore it" (owner addendum 11 (b)) for a recognised SERVICE
 * request that no real entry answers this turn. Volatile, prompt_bottom (priority 910, i.e. AFTER the
 * intimacy lane's <this_moment>, whose blind-turn text says nothing physical can be carried out - the
 * barter shape overrides that sentence explicitly, because showing wares is done by the game itself).
 *  barter   direct: choose CHIM's Trade_Items in this same reply, one short line, NO prices;
 *           otherwise the plain reason (price question / companion / combat / not on the table);
 *           the real-entry and negated cases say nothing here (the <business> block, or nothing, applies).
 *  inn / carriage / ferry / train   only while ml=0 (the real entry cannot run): choose CHIM's own
 *           Rent_Room / Hire_Carriage / Hire_Ferry / Training when it is on the table, else say why not.
 */
function lrgDlgServiceRequestBlock(array $t): string
{
    if (empty($t['on']) || empty($t['speech'])) { return ''; }
    if (empty(lrgDlgCfg('services.direct.request_block', true))) { return ''; }
    // [pt19 v1.0 / S2.1] her real list is being brought up this turn: the bridging directive speaks, nothing else
    if (!empty($t['open_pending'])) { return ''; }
    $svc = (array) ($t['svc'] ?? []);
    $kind = (string) ($svc['kind'] ?? '');
    if ($kind === '') { return ''; }
    $npc = (string) ($t['npc'] ?? '') ?: 'she';
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $end = ' Never ignore it.</player_request>';
    $onTable = static fn(string $code): bool => lrgDlgOffered($code)
        && !lrgDlgHiddenThisTurn(str_replace(['_', ' '], '', strtolower($code)));
    if ($kind === 'barter') {
        $why = (string) ($svc['direct_why'] ?? '');
        // [pt19-purchase] 'voice buy': the market lane's own <player_request> speaks for this turn
        // [pt19 v1.0 / S2.1] 'open pending': her real list is being brought up this turn - the bridging line speaks
        if (in_array($why, ['real entry', 'negated', 'not player speech', 'voice buy', 'open pending'], true)) { return ''; }
        $trade = $onTable('OpenInventory') ? lrgDlgChimName('OpenInventory') : '';
        $head = "<player_request>$player asked to see what $npc has for sale. This is trade, not intimacy:"
            . ' nothing said above about intimacy applies to it. ';
        if (!empty($svc['direct']) && $trade !== '') {
            return $head . "Do it now: choose $trade in this same reply - that is what shows $npc's wares - and say ONE"
                . " short line inviting $player to look. Name no prices and list no goods: the wares speak for"
                . " themselves, and the trade is not done until $player has looked. Nothing said above about this"
                . ' moment or about missing facts changes that - showing wares is done by the game itself.' . $end;
        }
        if ($why === 'price question') {
            return $head . "$player asked what something costs. Never invent a price" . ($trade !== ''
                ? ": choose $trade in this same reply so $player sees the real prices himself, and say one short line."
                : ": say in one short line that $player will see the prices when you can show your wares, and choose no action.") . $end;
        }
        if ($why === 'companion') {
            return $head . "$npc travels with $player" . ($trade !== ''
                ? ": choose $trade in this same reply - it opens what $npc carries - and say one short line."
                : ": say in one short line that trading goes through $npc's own companion orders, and choose no action.") . $end;
        }
        if ($why === 'combat') {
            return $head . "Not in the middle of a fight: $npc says so in one short line and chooses no action." . $end;
        }
        if ($trade !== '') {
            // off in config, or a snapshot that found no vendor faction: the model decides, the net stays out
            return $head . "If $npc has anything to sell, choose $trade in this same reply - that is what shows the"
                . " wares - and say one short line; if $npc sells nothing, say so plainly in one short line and"
                . ' choose no action. Name no prices and never describe a trade as done.' . $end;
        }
        return $head . "$npc cannot show any wares this moment: say so plainly in one short line, in $npc's own"
            . ' voice, with a plain reason (not right now). Choose no action and never describe a trade as done.' . $end;
    }
    $codes = ['inn' => 'RentRoom', 'carriage' => 'HireCarriage', 'ferry' => 'HireFerry', 'train' => 'Training'];
    if (!isset($codes[$kind])) { return ''; }
    // the real entry runs only under ml=1; there the <business> / <her_list> blocks already speak
    if ((int) lrgDlgMcm('ml', 1, (string) ($t['npc'] ?? '')) === 1) { return ''; }
    if (!empty($svc['pricelist'])) { return ''; }
    $asked = ['inn' => 'a room for the night', 'carriage' => 'a ride somewhere', 'ferry' => 'a crossing by boat',
        'train' => 'training'][$kind];
    $head = "<player_request>$player asked $npc for $asked. This is not intimacy: nothing said above about intimacy applies to it. ";
    $price = lrgDlgMayQuotePrice($t) ? 'Name no price the game has not shown.' : 'Do NOT say a price out loud at all.';
    // [pt19-purchase] the room price is a FACT on the snapshot (room= RoomCost, the number every real "rent a room" line
    // charges - 25 here, not CHIM's own 10): she may quote that number and no other
    if ($kind === 'inn' && lrgDlgMayQuotePrice($t)) {
        $bf = ($t['buy'] ?? [])['facts'] ?? null;
        $room = is_array($bf) ? (int) ($bf['room'] ?? 0) : 0;
        if ($room > 0 && !empty($bf['fresh'])) { $price = "A room here costs $room septims - quote only that number, never another."; }
    }
    if (lrgDlgIsPriceQuestion((string) (lrgDlgGet((string) ($t['npc'] ?? ''))['utter']['text'] ?? ''))) {
        return $head . "$player asked what it costs. Answer in one short line and choose no action; $price"
            . " If you do not know the exact price, say $player will see it when it is arranged." . $end;
    }
    $act = $onTable($codes[$kind]) ? lrgDlgChimName($codes[$kind]) : '';
    if ($act !== '') {
        return $head . "Do it now: choose $act in this same reply if $npc really offers that, and say one short line -"
            . " otherwise say plainly why not. $price Never describe it as done before it happens." . $end;
    }
    return $head . "There is no action for it this turn: $npc says plainly in one short line why not (not right now,"
        . " or not something $npc does), and never describes it as done. $price" . $end;
}

/**
 * [0.5.7 / pt16] The barter net, after the model's own lines: when the turn decided direct=1 and the reply
 * carries neither a trade action nor a business pick from this NPC, CHIM's own OpenInventory goes out
 * with the reply - "<npc>|command|OpenInventory@<player>", the exact line CHIM itself emits when the
 * model chooses Trade_Items (chim.log 10:41:26+02 "Beirand|command|OpenInventory@Jordan"). Only when the
 * code is really on the table: a hidden or unoffered code is never smuggled past the hide policy, and
 * she was told to say why instead.
 */
function lrgDlgServiceNet(array $t, array $out): array
{
    $svc = (array) ($t['svc'] ?? []);
    if (empty($svc['direct']) || (string) ($svc['kind'] ?? '') !== 'barter' || empty($t['speech'])) { return $out; }
    $npc = (string) ($t['npc'] ?? '');
    $cid = (string) ($t['cid'] ?? '');
    if ($npc === '') { return $out; }
    foreach ($out as $line) {
        $parts = explode('|', rtrim((string) $line, "\r\n"), 3);
        if (count($parts) < 3 || strcasecmp(trim((string) $parts[0]), $npc) !== 0) { continue; }
        $raw = trim((string) explode('@', (string) $parts[2], 2)[0]);
        $code = str_replace(['_', ' '], '', strtolower($raw));
        if (in_array($code, ['openinventory', 'openinventory2', 'tradeitems', 'acceptgift'], true)
            || in_array($code, LRG_DLG_GATE_NAMES, true)) {
            lrgDlgLog('net: the reply already chose ' . $raw . ' - nothing added (direct barter)', $cid);
            return $out;
        }
    }
    if (!lrgDlgOffered('OpenInventory') || lrgDlgHiddenThisTurn('openinventory')) {
        lrgDlgLog('net: OpenInventory is not on the table this turn (hidden, or not offered) - ' . $npc
            . ' was told to say why instead (direct barter)', $cid);
        return $out;
    }
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'Player';
    $out[] = $npc . '|command|OpenInventory@' . $player . "\r\n";
    lrgDlgLog('net: OpenInventory appended - ' . $player . ' asked to see ' . $npc . "'s wares and the reply carried no"
        . ' trade action (direct barter, vendor=' . (string) ($svc['vendor'] ?? 'unknown') . ')', $cid);
    return array_values($out);
}

// ================================================================== [0.5.1] FOLLOWER VERBS (addendum 10)
/*
 * "Work together" means one owner per verb, and for recruit / dismiss / wait / follow / trade /
 * favour / settle-home the owner is the follower framework's OWN dialogue entry. Driving that entry
 * is what makes Requiem's recruitment conditions, SFF's Speech-based slot cap, the hireling rehire
 * globals and each custom follower's own state machine apply automatically - none of which CHIM's
 * MakeFollower can do here, because the framework it was written for is not installed (pt9 4.1).
 *
 * Matching is EXACT token containment with longest-wins and a negation guard, exactly like the price
 * slots - never similarity. The player lines are short and generic ("wait", "follow me", "trade"),
 * which is precisely where a similarity score is worthless and a wrong pick dismisses the wrong
 * follower. When nothing is named exactly, NOTHING is executed and she asks.
 */

/** bFollowerVerbsReal (wire key fv), else the server's own config default. */
function lrgDlgFollowerOn(string $npc): bool
{
    if (empty(lrgDlgCfg('services.follower.enabled', true))) { return false; }
    return (int) lrgDlgMcm('fv', 1, $npc) === 1;
}

/** An entry that must never be selected by voice: a favour-blocking topic, an ANIMAL twin, a refusal. */
function lrgDlgFollowerBlockedEntry(array $e): bool
{
    $topic = (string) ($e['topic'] ?? '');
    if ($topic === '') { return false; }
    return lrgAnyGlob((array) lrgDlgCfg('services.follower.never_topics', []), [$topic]);
}

/**
 * The follower verb an ENTRY implements, or ''. The topic EditorID is the first authority (it is what
 * the index really carries); the entry's own text is the fallback, so a mod that renames the topic but
 * keeps the line still resolves.
 */
function lrgDlgFollowerVerbOf(array $e): string
{
    if (lrgDlgFollowerBlockedEntry($e)) { return ''; }
    $topic = (string) ($e['topic'] ?? '');
    $norm = lrgDlgTokens((string) ($e['norm'] ?? ''));
    $verbs = (array) lrgDlgCfg('services.follower.verbs', []);
    if ($topic !== '') {
        foreach ($verbs as $verb => $spec) {
            if (lrgAnyGlob((array) ($spec['topics'] ?? []), [$topic])) { return (string) $verb; }
        }
    }
    if (!$norm) { return ''; }
    // the text fallback only counts inside the follower FAMILY, or "follow me" on any quest topic in
    // Skyrim would look like a follower command
    $inFamily = $topic !== '' && lrgAnyGlob((array) lrgDlgCfg('services.follower.families', []), [$topic]);
    $best = '';
    $bestN = 0;
    foreach ($verbs as $verb => $spec) {
        foreach ((array) ($spec['entry'] ?? []) as $phrase) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $phrase));
            if (!$pt || count($pt) <= $bestN) { continue; }
            ///the ENTRY has to be that line, not merely contain it: an entry is one short sentence.
            // [0.5.1 pt9 go-live / S7] `|| $norm === $pt` - THE TWO-WORD COMMANDS. On the live index the
            // topic lookup for "Follow me." and "Wait here." loses its tie-break to a CWMission04 row
            // (CWPrisonerFollower / CWPrisonerWait), which is in no family glob, and the text fallback
            // then refused them because it demanded three tokens. Since dismiss and trade still resolved,
            // fol.verbs was non-empty and the hide policy had already taken CHIM's own Follow /
            // WaitHere / FollowPlayer / MakeFollower shortcuts off the table - so the two most-used
            // follower commands in the game did nothing at all and their fallback was gone. Requiring
            // the entry to normalise to EXACTLY the configured phrase (not merely contain it) cannot
            // widen into ordinary quest lines: "Follow me. I need your help." is a different token run.
            if (lrgDlgTokenRun($norm, $pt) && ($inFamily || count($pt) >= 3 || $norm === $pt)) {
                $best = (string) $verb;
                $bestN = count($pt);
            }
        }
    }
    return $best;
}

/** verb => [entries], for every follower entry really on this turn's list. */
function lrgDlgFollowerEntries(array $pool): array
{
    $out = [];
    foreach ($pool as $e) {
        if ((string) ($e['class'] ?? '') === 'hidden') { continue; }
        $v = lrgDlgFollowerVerbOf((array) $e);
        if ($v !== '') { $out[$v][] = (array) $e; }
    }
    return $out;
}

/**
 * The follower verbs the player's own words ask for, longest phrase first, then the table's order.
 * More than one may hit on purpose: "come with me" is a recruit to a stranger and a follow-again to
 * somebody already waiting for him, and only her real list can say which.
 */
function lrgDlgFollowerIntents(string $utter): array
{
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    if (!$hay) { return []; }
    $stops = lrgDlgClauseStarts($utter);   // [S13] "Don't wait here, come with me instead."
    $hits = [];
    $order = 0;
    foreach ((array) lrgDlgCfg('services.follower.verbs', []) as $verb => $spec) {
        $order++;
        $bestN = 0;
        $bestSay = '';
        foreach ((array) ($spec['say'] ?? []) as $phrase) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $phrase));
            if (!$pt || count($pt) <= $bestN) { continue; }
            $at = lrgDlgTokenAt($hay, $pt);
            if ($at < 0 || lrgDlgNegatedAt($hay, $at, $stops)) { continue; }
            $bestN = count($pt);
            $bestSay = (string) $phrase;
        }
        if ($bestN > 0) { $hits[] = ['verb' => (string) $verb, 'n' => $bestN, 'say' => $bestSay, 'o' => $order]; }
    }
    usort($hits, static fn($a, $b) => ($b['n'] <=> $a['n']) ?: ($a['o'] <=> $b['o']));
    return $hits;
}

/**
 * True when the player's words DID carry a follower order and every occurrence of it was negated.
 * That is a refusal, not silence: the turn must settle nothing, and the model's own paraphrase of the
 * same words may not be consulted afterwards.
 */
function lrgDlgFollowerNegatedOnly(string $utter): bool
{
    $hay = lrgDlgTokens(lrgPromptNorm($utter));
    if (!$hay) { return false; }
    $stops = lrgDlgClauseStarts($utter);   // [S13] the affirmative half of a compound sentence counts
    $saw = false;
    foreach ((array) lrgDlgCfg('services.follower.verbs', []) as $spec) {
        foreach ((array) ($spec['say'] ?? []) as $phrase) {
            $pt = lrgDlgTokens(lrgPromptNorm((string) $phrase));
            if (!$pt) { continue; }
            $at = lrgDlgTokenAt($hay, $pt);
            if ($at < 0) { continue; }
            if (!lrgDlgNegatedAt($hay, $at, $stops)) { return false; }   // one plain order is enough
            $saw = true;
        }
    }
    return $saw;
}

/**
 * REAL ENTRY vs CHIM SHORTCUT for a follower command, arbitrated in one place (the D4 pattern).
 * null                -> the player named no follower verb: ordinary matching applies, nothing changes
 * ['entry' => <e>]    -> run THAT entry, and nothing else this turn
 * ['entry' => null]   -> a follower verb was named but could not be resolved exactly: execute NOTHING
 *                        (she asks instead - a wrong pick here dismisses the player's real follower)
 */
function lrgDlgFollowerArbitrate(array $t, array $pool, string $item, string $cid): ?array
{
    $npc = (string) $t['npc'];
    if (!lrgDlgFollowerOn($npc)) { return null; }
    $byVerb = lrgDlgFollowerEntries($pool);
    $utter = (string) (lrgDlgGet($npc)['utter']['text'] ?? '');
    $hits = lrgDlgFollowerIntents($utter);
    if (!$hits) {
        // "Don't wait here, come with me instead." names the wait verb and then NEGATES it. The model's
        // own paraphrase must not be able to bring it back: when the player's words carried a follower
        // order and every occurrence of it was negated, the turn settles nothing at all.
        if (lrgDlgFollowerNegatedOnly($utter)) {
            lrgDlgLog('fol npc=' . $npc . ' entry=- why=the-player-negated-it ("' . substr($utter, 0, 48)
                . '") - nothing is executed and the model\'s own wording cannot revive it', $cid);
            return ['entry' => null];
        }
        if (trim($item) !== '') { $hits = lrgDlgFollowerIntents($item); }
    }
    if (!$hits) { return null; }
    $named = implode('/', array_map(static fn($h) => (string) $h['verb'], $hits));
    if (!$byVerb) {
        // [pt19h-reach G6] NO follower entry on this list and she is nobody's companion: no framework line is here to protect
        // and nobody to move, so a follower verb in his words is no follower order - "Ready. Let's go.", "let's go find him",
        // "why wait for me" are QUEST lines, and ordinary matching (every floor and rail of it) decides them. A companion's
        // list keeps the old answer: CHIM's own Follow / WaitHere and the escort (10.20) carry her orders, never a quest line.
        $snap = is_array($t['snap'] ?? null) && $t['snap'] ? (array) $t['snap'] : lrgDlgSnapshotKv($npc);
        if (lrgDlgCompanionOwner($npc, $snap ?: null) === '') {
            lrgDlgLog(sprintf('fol npc=%s verb=%s entry=- why=no-follower-entry-on-this-list and she is nobody\'s companion'
                . ' - no follower order to arbitrate, ordinary matching decides', $npc, $named), $cid);
            return null;
        }
        lrgDlgLog(sprintf('fol npc=%s verb=%s entry=- why=she-has-no-follower-entry (the real dialogue'
            . ' is what owns this verb; CHIM\'s shortcut is not a stand-in for it)', $npc, $named), $cid);
        return ['entry' => null];
    }
    foreach ($hits as $h) {
        $cands = (array) ($byVerb[(string) $h['verb']] ?? []);
        if (!$cands) { continue; }
        if (count($cands) > 1) {
            lrgDlgLog(sprintf('fol npc=%s verb=%s AMBIGUOUS - %d entries could be meant, she asks instead',
                $npc, (string) $h['verb'], count($cands)), $cid);
            return ['entry' => null];
        }
        $e = $cands[0];
        // a dismissal and giving her a home cannot be undone by talking: the existing two-step
        // confirmation runs on them whatever the index said about the entry
        if (!empty(lrgDlgCfg('services.follower.verbs.' . (string) $h['verb'] . '.commit', false))) {
            $e['commit'] = true;
            // [pt19 v1.0 / S4.3, S4.10, model R12] a dismissal / a home ALWAYS asks: never explicit, even said word for word
            $e['fcommit'] = 1;
        }
        lrgDlgLog(sprintf('fol npc=%s verb=%s say="%s" pos=%d topic=%s why=exact-containment',
            $npc, (string) $h['verb'], (string) $h['say'], (int) $e['pos'], (string) ($e['topic'] ?? '-')), $cid);
        return ['entry' => $e];
    }
    lrgDlgLog(sprintf('fol npc=%s verb=%s entry=- why=no-entry-for-that-verb (she has %s)', $npc, $named,
        implode(', ', array_keys($byVerb))), $cid);
    return ['entry' => null];
}

/**
 * What she may be told about a follower command this turn. Short on purpose: the <business> block
 * already lists the entries themselves, and this only has to stop the model narrating an order it
 * did not give and stop it reaching for CHIM's shortcut instead.
 */
function lrgDlgFollowerVerbBlock(array $t): string
{
    $fol = (array) ($t['fol'] ?? []);
    if (empty($fol['verbs'])) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $out = "<follower_commands>\n";
    $out .= 'These are ' . $t['npc'] . "'s own answers to being commanded: "
        . implode(', ', array_slice((array) $fol['verbs'], 0, 8)) . ".\n";
    $out .= 'When ' . $player . ' plainly gives one of those orders, take it up with the listed key and'
        . " say nothing about the result - the game carries it out and answers in her own voice.\n";
    if (!empty($fol['asked']) && empty($fol['resolved'])) {
        $out .= $player . ' asked for something like that but did not name it exactly enough. Ask which'
            . " he means, in your own words, and settle nothing this turn.\n";
    }
    $out .= '</follower_commands>';
    return $out;
}

// ------------------------------------------------------------------ E6: crime, and the LETHAL rule
/** The walk-away targets that mean "this is an arrest": the seed plus whatever the census found. */
function lrgDlgLethalTwats(): array
{
    static $out = null;
    // the offline seam may replace the census between scenarios, so it is checked before the cache
    if ($out !== null && !array_key_exists('LRG_DLG_TEST_SERVICE_CATALOG', $GLOBALS)) { return $out; }
    $out = array_values(array_unique(array_merge(
        (array) lrgDlgCfg('crit.lethal_twat', []),
        (array) lrgDlgCfg('services.crime_twat_seed', []),
        (array) (lrgDlgServiceCatalog()['crime_topics'] ?? [])
    )));
    return $out;
}

/** data/service_catalog.json, written offline by build_prompt_index.py --services. Never queried live. */
function lrgDlgServiceCatalog(): array
{
    static $cat = null;
    // the offline seam is checked FIRST and never cached over: a flow run replaces it per scenario
    if (array_key_exists('LRG_DLG_TEST_SERVICE_CATALOG', $GLOBALS)) {
        return (array) $GLOBALS['LRG_DLG_TEST_SERVICE_CATALOG'];
    }
    if ($cat !== null) { return $cat; }
    $cat = [];
    $rel = (string) lrgDlgCfg('services.catalog', 'data/service_catalog.json');
    $f = LRG_DIR . '/' . ltrim($rel, '/');
    if (is_file($f)) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d)) { $cat = $d; } else { lrgDlgLog('service catalog: ' . $rel . ' is not valid JSON - ignored'); }
    }
    return $cat;
}

/**
 * [0.5.0 / E7(a)] THE GUARD FACTION IDS, READ FROM CHIM'S OWN BUILT-IN TABLE, NEVER RETYPED.
 * herikaActionCatalogGetBuiltinRequirements('PayBounty') is the single place CHIM states which
 * factions make an NPC a guard (lib/core/action_catalog.php:1171-1181). Retyping them here would be a
 * second source of truth that a CHIM update could silently invalidate - and the one thing a wrong
 * guard list costs is a missed arrest, which is exactly risk R16. d59_requirements asserts both that
 * this function agrees with CHIM's table and that no such literal appears in our source.
 */
function lrgDlgGuardFactions(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $ids = [];
    if (function_exists('herikaActionCatalogGetBuiltinRequirements')) {
        try {
            $r = (array) herikaActionCatalogGetBuiltinRequirements('PayBounty');
            foreach ((array) ($r['npc_factions_any'] ?? []) as $f) {
                $f = lrgDlgHexNorm((string) $f);
                if ($f !== '') { $ids[] = $f; }
            }
        } catch (Throwable $e) { /* no CHIM here: the other two arrest routes still work */ }
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

/**
 * One spelling for a FormID. The game sends it as 0x-prefixed hex8 and CHIM's table stores it as bare
 * hex8, so both are reduced to the same thing here. `ltrim($s, '0x')` is NOT the answer: it strips a
 * character SET, so a 0x-prefixed id loses its leading zero, stops at the 'x', and matches nothing.
 */
function lrgDlgHexNorm(string $s): string
{
    $s = strtolower(trim($s));
    if (strncmp($s, '0x', 2) === 0) { $s = substr($s, 2); }
    $s = ltrim($s, '0');
    return preg_match('/^[0-9a-f]{1,8}$/', $s) ? $s : '';
}

/** Is the crime faction the game sent one CHIM itself calls a guard faction? cf= is a hex8 (W10). */
function lrgDlgIsGuardFaction(string $cf): bool
{
    $cf = lrgDlgHexNorm($cf);
    if ($cf === '') { return false; }
    foreach (lrgDlgGuardFactions() as $id) {
        if ($id === $cf) { return true; }
    }
    return false;
}

/**
 * Is this an ARREST session? Three independent routes, because a missed arrest is a voice line that
 * sends the player to jail (risk R16):
 *   1 an entry whose walk-away target is a known crime topic (the census, never a hand-typed list);
 *   2 the game says guard=1 AND bounty>0 on a fresh ev=facts (wire W10);
 *   3 the layer is the index's "I know you..." family.
 * Returns '' or the route that fired.
 */
function lrgDlgArrestClass(array $t): string
{
    if (empty(lrgDlgCfg('services.kinds.crime.enabled', true))) { return ''; }
    $lethal = array_map('strtolower', lrgDlgLethalTwats());
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        $tw = strtolower((string) ($e['twat'] ?? ''));
        if ($tw !== '' && in_array($tw, $lethal, true)) { return 'twat'; }
        // the entry's own TOPIC, against the DGCrime* family the census found
        $tp = strtolower((string) ($e['topic'] ?? ''));
        if ($tp !== '' && in_array($tp, $lethal, true)) { return 'topic'; }
    }
    $crime = (array) ($t['crime'] ?? []);
    if ((int) ($crime['bounty'] ?? 0) > 0) {
        $age = lrgNow() - (int) ($crime['at'] ?? 0);
        if ($age <= max(5, (int) lrgDlgCfg('truth.bounty_max_age_seconds', 45))) {
            if ((int) ($crime['guard'] ?? 0) === 1) { return 'guard'; }
            // Actor.IsGuard() can be false for a hold's own enforcers who still arrest (housecarls,
            // some mod-added city watch). CHIM's own guard-faction list answers that case.
            if (lrgDlgIsGuardFaction((string) ($crime['cf'] ?? ''))) { return 'crimefaction'; }
        }
    }
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        if (preg_match('/^(wait |hold |now )?i know you\b/', (string) ($e['norm'] ?? ''))) { return 'greeting'; }
    }
    return '';
}

/**
 * "Resist arrest" is NEVER voice-selectable, at any setting. This is checked before every other rail in
 * the gate, so relaxing iCritical can never reach it.
 */
function lrgDlgIsResistArrest(array $e): bool
{
    foreach ([strtolower((string) ($e['twat'] ?? '')), strtolower((string) ($e['topic'] ?? ''))] as $id) {
        if ($id === '') { continue; }
        foreach (lrgDlgLethalTwats() as $x) {
            if (strtolower((string) $x) === $id && stripos((string) $x, 'resist') !== false) { return true; }
        }
    }
    $n = (string) ($e['norm'] ?? '');
    return (bool) preg_match('/\b(never take me alive|you.?ll never take me|i.?ll never (go|be taken)|'
        . 'resist arrest|you will have to kill me|i.?d rather (die|fight))\b/', $n);
}

// ------------------------------------------------------------------ E7: locked facts and the truth gate
/**
 * [0.5.0 / E7] THE LOCKED-FACTS BLOCK, and an honest note about what CHIM's own "fact locking" is.
 * CHIM's lock_profile (lib/core/npc_master.class.php:1030) and relationships_locked
 * (ext/relationship_system/relationship_llm.php:638) are WRITE protection on the owner's edits - they
 * stop automatic regeneration from overwriting an authored profile. They are NOT a set of facts the LLM
 * may not contradict, and there is no post-hoc verifier of the model's claims anywhere in CHIM core.
 * The real condition-truthfulness mechanism in CHIM is the action catalog's `requirements`
 * (lib/core/action_catalog.php:1834, applied at functions/functions.php:2745), which we now also use -
 * see lrgDlgEnsureActions().
 *
 * This block is the read-time half: facts THE GAME HAS CONFIRMED THIS MOMENT, in CHIM's own injection
 * slot (character_bottom, priority 205, between our boundaries at 200 and our business rules at 210).
 * Hard caps: <= truth.max_chars (600), <= truth.max_lines (6), only the seven classes, every one
 * freshness-bounded, and NEVER an index template - the index says "<Global=KmodFerryCost>", which is a
 * template and not a price. Prices only ever come from a LIVE entry the game sent this session.
 */
function lrgDlgLockedFacts(array $t): array
{
    $npc = (string) ($t['npc'] ?? '');
    if ($npc === '') { return []; }
    ///[0.5.1 pt9 go-live / S5] bLockedFacts NO LONGER LIVES HERE. It used to return [] whenever lf !== 1,
    // and lrgDlgTruthCheck() returns null the moment $t['locked'] is empty - so switching the injection
    // off silently switched the TRUTH GATE off with it, and an unconfirmed price claim was free to cost
    // gold. That is the exact bug the comment at the gate claims was fixed in 0.5.0: the fix named the
    // right switch there but the facts were still gated by the wrong one here. The facts are now always
    // computed (they are cheap and already in hand); lrgDlgLockedBlock() alone honours lf and decides
    // whether the NPC is TOLD them. bTruthGate (tg) alone decides whether an unconfirmed number may act.
    $classes = array_map('strval', (array) lrgDlgCfg('truth.classes', []));
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $st = lrgDlgState($npc);
    $out = [];
    $add = static function (string $cls, string $line, $num = null) use (&$out, $classes) {
        if (!in_array($cls, $classes, true)) { return; }
        if (strpos($line, '<') !== false || strpos($line, '>') !== false) { return; }   // never a template
        $out[] = ['class' => $cls, 'line' => $line, 'num' => $num];
    };
    // ---- [0.5.7 / pt16-legion] begin: the enlistment fact goes FIRST - the block's 600-char cap trims from the
    // end, and on a faction turn this is what the turn is about (who recruits, that she cannot, no invented
    // appointment). A positive fact, worded to override CHIM's own persona text (lib/lrg_factions.php).
    foreach (lrgFacLockedLines($t) as $facLine) { $add('faction', $facLine); }
    // ---- [0.5.7 / pt16-legion] end ----
    // ---- [pt19-purchase] begin: what she really sells and asks for it (the snapshot's stock=), the room price, the order's total
    $mktLines = lrgMktLockedLines($t);
    foreach ($mktLines as $ml) { $add((string) $ml[0], (string) $ml[1], $ml[2]); }
    // ---- [pt19-purchase] end ----
    // [pt19 v1.0 / S6.1] THE REWARD IS FIXED BY THE ENGINE - said only when he BARGAINS (checks.stakes.reward, or a named sum
    // with no live price) or a reward window is open, on a quest turn. Without either the line is ABSENT: <locked_facts> is
    // what models surface unprompted ("and I cannot add septims to your reward" to a barrow question is a bug report).
    // [review fix] NOT beside a merchant barter check (lrgDlgCheckKind returns barter on a quest turn only over a LIVE price):
    // "offer nothing else" would contradict the haggle's own outcome - a price haggle is no reward bargain.
    // [pt19c-B fix 1 / game review 3] NOT on a market turn (a market fact rides, a priced line is on her list, or a price list is
    // live) unless a reward window is open - "I need more ale" is an order (10.27), and the line ahead of it cost Hulda the purse
    // fact; and AFTER the market lines, so the cap drops the reward line before an order's total.
    // [ai review 3] NOT beside a gate-B reward check: <reward_talk> states that outcome, and "you cannot add septims" would
    // contradict a bonus the check just granted.
    $chk = (array) ($t['check'] ?? []);
    if (!empty($t['speech']) && lrgDlgQuestTurn($t) && (string) ($chk['kind'] ?? '') !== 'barter' && empty($chk['reward'])) {
        $win = lrgDlgRewardWindow($npc);
        $market = $mktLines !== [] || !empty((($t['svc'] ?? [])['pricelist'] ?? 0)) || (function_exists('lrgMktShowFacts') && lrgMktShowFacts($t));
        foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) { if ((int) ($e['cost'] ?? 0) > 0) { $market = true; break; } }
        if ($win || (!$market && lrgDlgRewardAsk((string) (((array) ($st['utter'] ?? []))['text'] ?? ''), $t) !== '')) {
            $add('reward', lrgDlgRewardLine());
        }
    }
    // price / service: only from the LIVE layer of this session
    $live = array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? []));
    $priced = [];
    foreach ($live as $e) {
        if ((int) ($e['cost'] ?? 0) > 0) { $priced[lrgDlgShownText((string) $e['text'])] = (int) $e['cost']; }
    }
    $n = 0;
    foreach ($priced as $label => $cost) {
        if ($n++ >= 2) { break; }
        $add('price', 'what she is asking for "' . rtrim($label, ' .') . '": ' . $cost . ' septims', $cost);
    }
    $svc = (array) ($t['svc'] ?? []);
    ///[0.5.1 pt9 go-live / S4] svc.all, NOT svc.slots. On a pick turn lrgDlgPrepareTurn() collapses
    // svc.slots to the ONE matched slot, and this line renders whatever it is given under a header that
    // says the listed facts are confirmed and true - so on a four-destination layer the NPC was told, as
    // fact, that Morthal was "the only one on her list" and then denied her own list to the player.
    // svc.all is the full census of the same layer, built on the same turn a few lines above svc.slots.
    // svc.slots keeps its job in the AMBIGUITY wording ('ask'), where naming the two candidates is the
    // whole point - see lrgDlgServiceBlock().
    $svcAll = (array) ($svc['all'] ?? []);
    if (!$svcAll) { $svcAll = (array) ($svc['slots'] ?? []); }
    if ($svcAll) {
        // [pt19 v1.0 / Lane A hand-off] "the only ones" only when all of them are named: a 7-slot days list shows 4 and says so
        $more = count($svcAll) - 4;
        $add('service', $more > 0 ? 'on her list: ' . implode(', ', array_slice($svcAll, 0, 4)) . ' and ' . $more . ' more'
            : 'the only ones on her list: ' . implode(', ', $svcAll));
    }
    // bounty / guard: wire W10 only, and only while fresh. ABSENT means she may claim nothing.
    $crime = (array) ($t['crime'] ?? []);
    if ($crime !== []) {
        $age = lrgNow() - (int) ($crime['at'] ?? 0);
        if ($age <= max(5, (int) lrgDlgCfg('truth.bounty_max_age_seconds', 45))) {
            if ((int) ($crime['guard'] ?? 0) === 1) { $add('guard', 'you are a guard of this hold'); }
            if ((int) ($crime['bounty'] ?? 0) > 0) {
                $add('bounty', $player . "'s bounty here: " . (int) $crime['bounty'] . ' septims', (int) $crime['bounty']);
            }
        }
    }
    $pg = (int) (($t['facts'] ?? [])['pg'] ?? 0);
    // [pt19-purchase] the facts line never carries pg= (it rides the list-open message only); the SNAPSHOT carries pgold on
    // every send, so a fresh one is the purse - on a MONEY turn only (a price / stock / bounty / service fact is already in
    // hand): an idle chat, a non-adult or a SHARMAT turn must keep injecting nothing at all (flows 11b / 16). Before this
    // the gold class was dead on every ordinary turn (pg=- in the log).
    if ($pg <= 0) {
        $money = false;
        foreach ($out as $f) { if (in_array((string) $f['class'], ['price', 'stock', 'bounty', 'service'], true)) { $money = true; break; } }
        $mf = is_array((($t['buy'] ?? [])['facts'] ?? null)) ? $t['buy']['facts'] : ($money ? lrgMktFacts(is_array($t['snap'] ?? null) ? $t['snap'] : null) : []);
        if ($money && !empty($mf['fresh']) && $mf['pg'] !== null) { $pg = (int) $mf['pg']; }
    }
    if ($pg > 0) { $add('gold', $player . ' is carrying ' . $pg . ' septims', $pg); }
    // check: the outcome the ENGINE produced this turn
    $res = (array) ($st['last_result'] ?? []);
    $verdict = (string) ($res['verdict'] ?? '');
    if ($verdict === 'pass' || $verdict === 'fail') {
        if (lrgNow() - (int) ($res['at'] ?? 0) <= 180) {
            $add('check', 'the ' . (string) ($res['kind'] ?? 'attempt') . ' he just tried: '
                . ($verdict === 'pass' ? 'WORKED' : 'FAILED'));
        }
    }
    // quest: the current objective only, through the same cleaner <shared_business> uses
    $q = array_values(array_filter((array) ($t['q'] ?? [])));
    if ($q) {
        foreach (lrgDlgQuestRows($q) as $row) {
            $obj = lrgDlgCleanObjective((string) ($row['briefing'] ?? ''), $npc);
            if ($obj === '') { continue; }
            $add('quest', 'his current task: "' . rtrim(substr($obj, 0, 80), ' .') . '"');
            break;
        }
    }
    return array_slice($out, 0, max(1, (int) lrgDlgCfg('truth.max_lines', 6)));
}

function lrgDlgLockedBlock(array $t): string
{
    $facts = (array) ($t['locked'] ?? []);
    if (!$facts) { return ''; }
    // [0.5.1 pt9 go-live / S5] bLockedFacts is HERE now: it decides whether she is told, and nothing
    // else. The truth gate keeps its own facts either way - see lrgDlgLockedFacts().
    if ((int) lrgDlgMcm('lf', !empty(lrgDlgCfg('truth.locked_facts', true)) ? 1 : 0,
        (string) ($t['npc'] ?? '')) !== 1) { return ''; }
    $body = '';
    $classes = [];
    foreach ($facts as $f) {
        $line = '- ' . (string) $f['line'] . "\n";
        // [pt19c-B fix 1 / ai review 2 - one token in Lane A's function] a line that would pass the cap is SKIPPED, not the end of the
        // block: the short "his current task" line after an over-long one is still told (a real faction line + the reward line)
        if (strlen($body) + strlen($line) > max(120, (int) lrgDlgCfg('truth.max_chars', 600))) { continue; }
        $body .= $line;
        $classes[(string) $f['class']] = 1;
    }
    if ($body === '') { return ''; }
    lrgDlgLog('lock npc=' . (string) $t['npc'] . ' facts=' . count($facts) . ' chars=' . strlen($body)
        . ' classes=' . implode(',', array_keys($classes)), (string) ($t['cid'] ?? ''));
    return "<locked_facts>\n"
        . "These are facts the game has confirmed this moment. They are true. You may say them.\n"
        . "Anything not listed here you do NOT know - say you are not sure rather than name a number,\n"
        . "a place, a price, a bounty or a quest step.\n"
        . $body . lrgFacRule($t) . '</locked_facts>';   // [pt16-legion] the no-invented-next-step rule, faction turns only
}

/**
 * [0.5.0 / E7(c)] THE TRUTH GATE, and the honest limit on it.
 * We cannot rewrite her sentence, and trying to would produce worse dialogue than the problem it fixes.
 * What we CAN do is refuse to let an unconfirmed claim have consequences. So: only when this turn
 * carried a price / bounty / service fact AND the reply makes a claim about one AND the same reply
 * carries an action that would ACT on it, the ACTION is dropped. Her words still play - she was wrong
 * out loud, which happens - and nothing was spent.
 * Returns ['claim' => .., 'said' => .., 'fact' => ..] or null.
 */
function lrgDlgTruthCheck(array $t, string $reply, array $lines): ?array
{
    if (empty(lrgDlgCfg('truth.gate', true))) { return null; }
    $facts = (array) ($t['locked'] ?? []);
    // [pt19 v1.0 / S6.1] THE REWARD BACKSTOP (hide_reward drops these first): on a quest turn a GiveGoldTo / TakeGoldFromPlayer
    // whose amount no live entry or confirmed fact carries - or with NO amount (CHIM fills it with the last number anyone said,
    // CHIM brief P3) - is an invented reward: the action is dropped, her words still play, both strings are logged
    if (lrgDlgQuestTurn($t)) {
        $okN = [];
        foreach ($facts as $f) { if ($f['num'] !== null && (string) $f['class'] !== 'gold') { $okN[] = (int) $f['num']; } }
        foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) { if ((int) ($e['cost'] ?? 0) > 0) { $okN[] = (int) $e['cost']; } }
        foreach ($lines as $l) {
            $ma = lrgDlgRewardAct((string) $l);   // [pt19c-B fix 1 / CHIM review] any channel, a JSON param's `item` (lrg_speech.php)
            if ($ma === null) { continue; }
            if ($ma['amt'] <= 0 || !in_array($ma['amt'], $okN, true)) {
                return ['claim' => 'reward', 'said' => $ma['code'] . ($ma['amt'] > 0 ? ' ' . $ma['amt'] . ' septims' : ' with no amount'),
                    'fact' => $okN ? implode('/', array_unique($okN)) . ' septims' : 'no amount is confirmed'];
            }
        }
    }
    if (!$facts) { return null; }
    $nums = [];
    $slots = [];
    $have = [];
    foreach ($facts as $f) {
        $have[(string) $f['class']] = 1;
        if ($f['num'] !== null) { $nums[] = (int) $f['num']; }
    }
    // [pt19-purchase] `stock`: a vendor's real prices are a price fact too; every figure she may name is in lrgMktAllowedPrices
    if (!array_intersect(array_keys($have), ['price', 'bounty', 'service', 'stock'])) { return null; }
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        if ((int) ($e['cost'] ?? 0) > 0) { $nums[] = (int) $e['cost']; }
        $slots[] = (string) ($e['norm'] ?? '');
    }
    foreach (lrgMktAllowedPrices($t) as $n) { $nums[] = (int) $n; }
    $nums = array_values(array_unique(array_filter($nums)));
    // [pt19-purchase] NUMBER WORDS TOO: "That'll be one septim for a bottle of Honningbrew" (Hulda, 02:54:35) is the line
    // that started this, and a digits-only frame let it through. lrgMktPriceMentions reads digits and words alike.
    if ($nums) {
        foreach (lrgMktPriceMentions($reply) as $mn) {
            if ((int) $mn['n'] > 0 && !in_array((int) $mn['n'], $nums, true)) {
                return ['claim' => 'price', 'said' => (int) $mn['n'] . ' septims', 'fact' => implode('/', array_slice($nums, 0, 8)) . ' septims'];
            }
        }
    }
    ///1. a septim amount she named that no confirmed fact and no live entry carries.
    // [0.5.1 pt9 go-live / S6] A PRICE FRAME IS REQUIRED AROUND THE NUMBER. The bare pattern matched any
    // number followed by a money word ANYWHERE in the reply, so a driver reminiscing - "Aye. I lost 300
    // gold at dice in Riften last winter. Climb up." - cancelled the ride the player had just confirmed:
    // the player heard her agree out loud and the ride silently did not happen, which is the hardest
    // failure of all to diagnose from inside the game. Past-tense narration is now left alone; only a
    // number she is QUOTING as a price, a fare or a demand can drop the action.
    if ($nums && preg_match_all('/\b(?:costs?|charges?|fare|price|fee|owes?|asking|for|'
        . '(?:will|would|shall|\'ll)\s+be|that(?:\'s| is)|pay(?: me)?|give me|hand over)\b[^.]{0,20}?'
        . '(\d[\d,]*)\s*(?:gold|septims?|coins?)\b/i', $reply, $m)) {
        foreach ($m[1] as $raw) {
            $said = (int) preg_replace('/[^0-9]/', '', $raw);
            if ($said > 0 && !in_array($said, $nums, true)) {
                return ['claim' => 'price', 'said' => $said . ' septims', 'fact' => implode('/', $nums) . ' septims'];
            }
        }
    }
    // 2. a destination she named that is not on the live list at all
    $svc = (array) ($t['svc'] ?? []);
    if (!empty($svc['slots']) && !empty($have['service'])) {
        $hay = lrgDlgTokens(lrgPromptNorm($reply));
        foreach ((array) ($svc['offlist'] ?? []) as $bad) {
            if (lrgDlgTokenRun($hay, lrgDlgTokens(lrgPromptNorm((string) $bad)))) {
                return ['claim' => 'destination', 'said' => (string) $bad,
                    'fact' => implode(', ', array_slice((array) $svc['slots'], 0, 4))];
            }
        }
    }
    return null;
}

/** Does this batch carry an action that would SPEND something on the claim just made? */
function lrgDlgActsOnMoney(array $lines): bool
{
    foreach ($lines as $line) {
        $raw = rtrim((string) $line, "\r\n");
        $parts = explode('|', $raw, 3);
        $cmd = explode('@', $parts[2] ?? '', 2);
        $code = strtolower(trim($cmd[0] ?? ''));
        if (in_array($code, ['givegoldto', 'takegoldfromplayer', 'paybounty', 'hirecarriage', 'hireferry',
            'rentroom', 'training'], true)) { return true; }
        $p = (string) ($cmd[1] ?? '');
        if (strpos($p, ';do=pick;') !== false && preg_match('/;cost=(\d+);/', $p, $m) && (int) $m[1] > 0) { return true; }
        // [pt19-purchase] the buy carrier spends the player's septims
        if ($code === strtolower(LRG_ACT_BUY) && preg_match('/;price=(\d+);/', $p, $m) && (int) $m[1] > 0) { return true; }
    }
    return false;
}

// ================================================================== the turn log line
function lrgDlgTurnLine(array $t): string
{
    $keys = (array) ($t['offer']['keys'] ?? []);
    // [0.4.0] mcm= is present only when the GAME really sent the knobs; its absence is itself the answer
    // to "is my MCM reaching the server at all?".
    // [0.4.0 release pass] the snapshot tier too, or the line would say "no MCM" on the three knobs that
    // only ever arrive on a snapshot (chk / bias / ql) - the exact controls the owner asked for by name.
    $mcm = (array) ($GLOBALS['LRG_DLG_MCM'] ?? ((lrgDlgGet((string) $t['npc'])['facts'] ?? [])['mcm'] ?? []))
        + lrgDlgMcmFromSnapshot((string) $t['npc']);
    $mcmTxt = '';
    if ($mcm) {
        ksort($mcm);
        foreach ($mcm as $mk => $mv) { $mcmTxt .= ($mcmTxt === '' ? '' : ',') . $mk . ':' . (int) $mv; }
        $mcmTxt = ' mcm=' . $mcmTxt;
    }
    // [0.5.0] the four new facts a v0.5 turn can carry, and only when they carry information
    $svc = (array) ($t['svc'] ?? []);
    $extra = '';
    if ((string) ($svc['kind'] ?? '') !== '') { $extra .= ' svc=' . (string) $svc['kind']; }
    if (!empty($svc['pricelist'])) { $extra .= ' slot=' . ((string) ($svc['mode'] ?? '') ?: '-'); }
    // [0.5.7 / pt16] direct barter: carried on CHIM's own OpenInventory this turn, or why not
    if ((string) ($svc['kind'] ?? '') === 'barter') {
        $extra .= !empty($svc['direct']) ? ' direct=barter' : (' direct=no:' . ((string) ($svc['direct_why'] ?? '') ?: '-'));
    }
    if ((string) ($t['ask'] ?? '') !== '') { $extra .= ' ask=' . (string) $t['ask']; }
    if ((string) ($t['arrest'] ?? '') !== '') { $extra .= ' arrest=' . (string) $t['arrest']; }
    if ((array) ($t['locked'] ?? [])) { $extra .= ' locked=' . count((array) $t['locked']); }
    // [0.5.1] the follower facts, and only when they carry information: which verbs her real list can
    // answer, and which one the player asked for (with ! when it could not be resolved exactly)
    $fol = (array) ($t['fol'] ?? []);
    if ((array) ($fol['verbs'] ?? [])) { $extra .= ' fol=' . implode('/', (array) $fol['verbs']); }
    if ((string) ($fol['asked'] ?? '') !== '') {
        $extra .= ' folask=' . (string) $fol['asked'] . (empty($fol['resolved']) ? '!' : '');
    }
    if (!empty($t['ambient'])) { $extra .= ' ambient=1'; }   // [pt17] a talkable scene
    // [pt19 v1.0] who may pick on this turn, and what the prompt carries
    if ((string) ($t['ro'] ?? '') !== '') { $extra .= ' ro=' . str_replace(' ', '_', (string) $t['ro']); }
    if (!empty($t['open_pending'])) { $extra .= ' open_pending=1'; }
    if (is_array($t['single'] ?? null)) { $extra .= ' single=1'; }
    if (count((array) ($t['hint2'] ?? [])) === 2) { $extra .= ' hint=' . implode('/', (array) $t['hint2']); }
    if ((int) ($t['clicks_ok'] ?? 1) < 1) { $extra .= ' clicks_ok=0'; }
    $extra .= lrgFacTurnTail($t);   // [0.5.7 / pt16-legion] " faction=<id>:<role>[:carried]" when an enlistment was asked
    $extra .= lrgMktTurnTail($t);   // [pt19-purchase] " buy=<state>[:<name>@<price>]" when an order of food or drink was heard
    return sprintf('turn npc=%s type=%s list=%s layer=%s n=%d sent=%d keys=%d crit=%d scene=%d open=%d'
        . ' pg=%s sp=%s wis=%s q=%s on=%d%s%s%s',
        (string) $t['npc'], (string) $t['type'], (string) $t['list'], (string) $t['layer_kind'], (int) $t['n'],
        (int) $t['sent'], count($keys), (int) $t['crit'], (int) $t['scene'], (int) $t['open'],
        (string) ($t['facts']['pg'] ?? '-'), (string) ($t['facts']['sp'] ?? '-'), (string) ($t['facts']['wis'] ?? '-'),
        implode(',', (array) $t['q']) ?: '-', !empty($t['on']) ? 1 : 0, $extra, $mcmTxt,
        ($t['why'] ?? []) ? (' why=' . implode('; ', (array) $t['why'])) : '');
}
