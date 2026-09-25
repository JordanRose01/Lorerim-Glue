<?php
// LoreRim Glue - loaded at the end of prompts/prompts.php.
// Defines the two LLM-triggering events the game side sends by itself:
//   lrg_scenetalk  (AIAgentFunctions.requestMessageForActor(.., "lrg_scenetalk", npc)) - during a scene. Text "lead" =
//                  the scene-lead tick: the NPC may also act (see prerequest.php); every other text is speech only.
//   lrg_initiative (text "approach") - outside a scene: the NPC may make a move of her own. Only ticks admitted by
//                  preprocessing.php ever get this far.
// WHEN they fire is decided in game (MCM). WHAT may be said, and how plainly, is set by the notes in context_pre.php -
// the cues here only carry the rhythm: one short spoken sentence or none, never poetic. Rules only, no example lines.
require_once __DIR__ . '/lib/lrg_actions.php';

// The cue is the LAST text in the prompt, so an intimate cue must never be defined on a turn the glue
// itself decided is silent. Each of the two request types repeats its own precondition here:
//  - lrg_scenetalk needs a live scene with THIS NPC on a fresh, adult, child-free snapshot with the
//    owner's switch on (the same test lrgPrepareTurn's scene branch makes)
//  - lrg_initiative needs a tick that preprocessing.php admitted for THIS NPC
// Leaving the PROMPTS entry out is safe: processor/request.php:171-175 logs "Request cue is empty!"
// and falls back to TEMPLATE_DIALOG, i.e. an ordinary neutral reply.
$lrgName = $GLOBALS['HERIKA_NAME'] ?? 'The character';
$lrgReqType = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
$lrgOk = lrgEnabled() && !defined('LRG_SHARMAT_PRESENT') && $lrgName !== '';
$lrgIsOutro = false;
if ($lrgOk && $lrgReqType === 'lrg_scenetalk') {
    $lrgScene = lrgGetActiveScene();
    $lrgSnap = lrgGetNpcState((string) $lrgName);
    $lrgRails = $lrgSnap !== null
        && (int) ($lrgSnap['_age'] ?? 9999) <= (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90)
        && ($lrgSnap['adult'] ?? '') === '1' && ($lrgSnap['witkid'] ?? '1') === '0' && ($lrgSnap['on'] ?? '0') === '1';
    // [0.3.1 / R3-O5] The outro fires AFTER ev=end, when the scene row is already active=0, so the
    // ordinary precondition (a live scene row) can never hold for it: the request would get an EMPTY
    // cue and CHIM would fall back to TEMPLATE_DIALOG - an ordinary neutral reply. A valid, unexpired
    // outro ticket stands in for the live row here. EVERY other safety test is unchanged.
    $lrgIsOutro = lrgIsOutroTick() && lrgOutroTicket((string) $lrgName) !== null;
    $lrgOk = $lrgRails && ($lrgIsOutro
        || ($lrgScene !== null && strcasecmp((string) $lrgScene['_npc'], (string) $lrgName) === 0));
} elseif ($lrgOk && $lrgReqType === 'lrg_initiative') {
    $lrgOk = strcasecmp((string) ($GLOBALS['LRG_INITIATIVE']['npc'] ?? ''), (string) $lrgName) === 0;
} else {
    $lrgOk = false; // no other request type gets a glue cue
}

if ($lrgOk) {
    $lrgTpl = $GLOBALS['TEMPLATE_DIALOG'] ?? '';
    $lrgIsTalk = $lrgReqType === 'lrg_scenetalk';
    $lrgLead = $lrgIsTalk && (bool) preg_match('/^(?:[^:]{1,40}:\s*)?lead$/', lrgRequestText());
    $lrgMax = (int) (lrgConfig()['scene_talk']['max_chars'] ?? 120);
    // the DLL prefixes requestMessageForActor text with "(Context location: X)": strip it
    $lrgWhat = trim(lrgStripContext((string) ($GLOBALS['gameRequest'][3] ?? '')));
    // [0.3.1 / R3-O4] The outro is the ONE turn around a scene that is not capped at one sentence.
    // The owner's complaint - "after sex they say something short and just leave" - was four separate
    // caps stacked on a line that was never meant to be a goodbye at all.
    $lrgOutroChars = (int) (lrgConfig()['outro']['max_chars'] ?? 420);
    $lrgOutroSent = (string) (lrgConfig()['outro']['sentences'] ?? 'two to four');
    $GLOBALS['PROMPTS']['lrg_scenetalk'] = [
        'cue' => $lrgIsOutro
            ? ["($lrgName has just finished having sex with the player and is getting dressed. $lrgName says goodbye out loud: $lrgOutroSent plain spoken sentences, at most $lrgOutroChars characters, following the notes above - what they did, what the player is to $lrgName now, and what $lrgName does next and why. Not one line. No poetry, no narration, no stage directions, and no action this turn) $lrgTpl"]
            : ($lrgLead
            // [0.3 / R10] the [silent] licence is gone: every change she makes herself is spoken first
            ? ["($lrgName leads now and makes ONE change that suits them, following the scene notes: one short spoken line of $lrgMax characters or less that makes plain what $lrgName is about to do, then the action; the way a real person talks in the middle of this; no description, no poetry) $lrgTpl"]
            : [
                "($lrgName says one short, blunt sentence - $lrgMax characters or less - about what is happening right now, the way a real person talks in the middle of it, or only a breath or a sound; no description, no poetry) $lrgTpl",
                "($lrgName reacts out loud to what they feel right now: one short sentence of plain words in their own voice, $lrgMax characters or less, nothing flowery) $lrgTpl",
                "($lrgName tells the player in one short plain sentence, $lrgMax characters or less, what they want or how it feels; no metaphors) $lrgTpl",
            ]),
        // [0.3] "(a while passes; the player leaves it to <npc>)" stacked FOUR times in one playtest-6
        // context while the player was in fact talking non-stop. The hold (G2) already guarantees no lead
        // tick fires while the player is steering, so nothing has to be claimed about the player at all.
        'player_request' => [$lrgIsOutro ? '(they are getting dressed)' : ($lrgLead ? '(the moment continues)' : '(' . ($lrgWhat !== '' ? $lrgWhat : 'a quiet moment') . ')')],
    ];
    $GLOBALS['PROMPTS']['lrg_initiative'] = [
        // [0.3 / R10, G3b] The "an action with an empty message" licence is gone here too. It was the LAST
        // text in the prompt on the one request type whose whole purpose is to let her act, and the
        // post-gate drops exactly that reply (an initiative tick recognises no player intent, so every
        // action on it is self-initiated): a paid LLM + TTS turn that produced nothing at all.
        'cue' => ["($lrgName acts on how they feel about the player right now, in their own way, as this moment's notes describe: one short plain spoken sentence of $lrgMax characters or less that makes plain what they are about to do, then the action; no description, no poetry) $lrgTpl"],
        'player_request' => ['(a quiet moment; nobody has spoken for a while)'],
    ];
    // R7: TTS time is linear in characters and runs under CHIM's MAIN lock. This cap is only the safety net behind
    // the one-sentence instruction; it has to fit the whole JSON reply (about 45 tokens of fields around the message).
    // context_pre.php sets the same cap for player-speech turns inside a scene / follow-through (lrgApplyTurnRuntime).
    $lrgTok = ((lrgConfig()['scene_talk']['max_tokens'] ?? []) + ['talk' => 140, 'act' => 200, 'outro' => 300]);
    $GLOBALS['FORCE_MAX_TOKENS'] = (int) ($lrgIsOutro ? $lrgTok['outro']
        : (($lrgLead || $lrgReqType === 'lrg_initiative') ? $lrgTok['act'] : $lrgTok['talk']));
}

// ---------------------------------------------------------------------------------------------------
// [0.5.5 / owner addendum 11] NEVER SILENT: the cue of a VOICED failure. preprocessing.php decided that this
// funcret is a failure the player has to hear about (lrgFuncretVerdict); CHIM runs its own follow-up turn for
// it (processor/funcret.php, catalog follow-up on since actions v11) and processor/request.php takes
// $PROMPTS['afterfunc']['cue'][<code>] as that turn's last user line. Keyed on the code, set for this
// request only, and only for the NPC the result belongs to. Rules only, no example lines.
if (strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'funcret' && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) {
    $lrgVoiced = lrgVoicedCue((string) ($GLOBALS['HERIKA_NAME'] ?? ''));
    if ($lrgVoiced !== null) {
        $GLOBALS['PROMPTS']['afterfunc']['cue'][$lrgVoiced['code']] = $lrgVoiced['cue'] . ' ' . ($GLOBALS['TEMPLATE_DIALOG'] ?? '');
    }
}

// ---------------------------------------------------------------------------------------------------
// [0.4.0] PHASE 2's one LLM-triggering type. Deliberately OUTSIDE the block above: lrg_dlgtalk has
// nothing to do with a scene, it must work under SHARMAT, and it is the type CHIM sends after the glue
// harvested a list from a session that was going to happen anyway ("answer the player's last words
// yourself; your business list is now known"). preprocessing.php has already refused it unless a player
// utterance for this NPC is <= 30 s old and no session is open.
// Rules only, NO example lines (PROTOCOL section 0 rail). Leaving the entry out is safe: CHIM logs
// "Request cue is empty!" and falls back to TEMPLATE_DIALOG - an ordinary reply, a degradation not an error.
require_once __DIR__ . '/lib/lrg_dialogue.php';
if (strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'lrg_dlgtalk' && lrgDlgEnabled()
    && strcasecmp((string) (($GLOBALS['LRG_DLG_TALK'] ?? [])['npc'] ?? ''), (string) $lrgName) === 0) {
    $lrgDlgTpl = $GLOBALS['TEMPLATE_DIALOG'] ?? '';
    $lrgDlgHeard = trim(lrgStripContext((string) (($GLOBALS['LRG_DLG_TALK']['utter'] ?? [])['text'] ?? '')));
    $GLOBALS['PROMPTS']['lrg_dlgtalk'] = [
        'cue' => ["($lrgName answers the player's last words in their own voice: one or two short spoken "
            . "sentences, plain words, no narration and no stage directions. If one of the listed matters is "
            . "plainly what the player means, take it up with the action instead of describing it. Never say "
            . "whether a persuasion, a threat or a bribe worked) $lrgDlgTpl"],
        'player_request' => ['(' . ($lrgDlgHeard !== '' ? $lrgDlgHeard : 'the player has just spoken') . ')'],
    ];
}
