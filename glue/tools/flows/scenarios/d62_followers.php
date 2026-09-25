<?php
// PHASE 2 d62: FOLLOWER COMPATIBILITY (owner addendum 10, research/pt9-followers.md, PROTOCOL 10.19).
//
// The modlist's follower framework is Simple Follower Framework, not NFF, so CHIM's own MakeFollower
// cannot finish a recruit here: it half-recruits the NPC and the game's dismiss line then goes to the
// player's REAL follower. Everything below is the fix, end to end:
//   - recruit / follow / wait / dismiss / trade / favour / home go through HER OWN dialogue entry,
//     resolved by exact containment, never by similarity;
//   - CHIM's shortcuts step aside for that list, and only for a list that really carries them;
//   - a dismissal is a commitment, so the two-step confirmation runs;
//   - a verb with no entry executes NOTHING and she asks - the glue never substitutes the shortcut,
//     which is what makes Requiem's own recruitment conditions apply by construction;
//   - the snapshot's fol= block takes "join my party" off the table where it cannot work.
require_once __DIR__ . '/../dlg_adapter.php';

/** The real topic EditorIDs of this load order (read from the index, not invented). */
function fxFolSpec(array $which): array
{
    $all = [
        'wait' => ['Wait here.' => ['topic' => 'DialogueFollowerWaitTopic', 'quest' => 'DialogueFollower']],
        'follow' => ['Follow me.' => ['topic' => 'DialogueFollowerFollowTopic', 'quest' => 'DialogueFollower']],
        'recruit' => ['Follow me, I need your help.' => ['topic' => 'ksws07FollowerRecruitTopic', 'quest' => 'DialogueFollower']],
        'dismiss' => ["It's time for us to part ways." => ['topic' => 'DialogueFollowerDismissTopic', 'quest' => 'DialogueFollower']],
        'trade' => ['I need to trade some things with you.' => ['topic' => 'DialogueFollowerTradeTopic', 'quest' => 'DialogueFollower']],
        'favor' => ['I need you to do something.' => ['topic' => 'DialogueFollowerFavorStateTopic', 'quest' => 'DialogueFollower']],
        'home' => ["You should live here when you're not traveling with me." => ['topic' => 'SetHomeSetTopic', 'quest' => 'SetHomeQuest']],
        'blocking' => ['Yes, let me show you.' => ['topic' => 'InigoDialogueFollowerDoingFavorBlockingTopic', 'quest' => 'InigofollowerDialogue']],
    ];
    $out = [];
    foreach ($which as $k) { $out += $all[$k] ?? []; }
    $out['Never mind.'] = [];
    return $out;
}

/** The snapshot of a real SFF follower, or of whatever fol= string is passed. */
function fxFolSnap(string $fol = 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1,prim:1'): array
{
    return fxDlgSnap($fol === '' ? [] : ['fol' => $fol]);
}

fx_scenario('d62', 'followers: the framework\'s OWN entries answer recruit / wait / follow / dismiss / trade / home, CHIM\'s shortcuts step aside only for a list that really has them, a dismissal is a two-step, an unnamed verb executes nothing, and the snapshot takes "join my party" off the table where it cannot work', function (FxT $t) {
    $npc = 'Lydia Flowtest';
    // CHIM's follower shortcuts have to be really on the table, or "it was hidden" proves nothing
    $offered = array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'MakeFollower', 'Follow', 'FollowPlayer',
        'WaitHere', 'ComeCloser', 'ReturnBackHome', 'OpenInventory']);

    // ---------------------------------------------------------------- 1. the shortcuts step aside
    fxDlgCache($npc, fxFolSpec(['wait', 'follow', 'trade', 'dismiss']));
    $q = fxDlgSay($npc, fxFolSnap(), 'Wait here for me.', 'inputtext', $offered);
    $t->must('the turn knows which follower verbs her real list can answer',
        !array_diff(['wait', 'follow', 'trade', 'dismiss'], (array) (($q['turn']['fol'] ?? [])['verbs'] ?? [])),
        json_encode(($q['turn']['fol'] ?? [])['verbs'] ?? []));
    foreach (['MakeFollower', 'Follow', 'FollowPlayer', 'WaitHere', 'ComeCloser', 'ReturnBackHome'] as $code) {
        $t->must('CHIM\'s ' . $code . ' steps aside for her real follower dialogue',
            !in_array($code, $q['enabled'], true), implode(',', $q['enabled']));
    }
    $t->must('and the business action is offered instead', in_array(FX_DLG_ACT, $q['enabled'], true),
        implode(',', $q['enabled']));
    $t->must('an unrelated CHIM action is untouched', in_array('Talk', $q['enabled'], true), implode(',', $q['enabled']));

    // a list with NO follower entries must not take the shortcuts away: "she has some list" is no reason
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $q = fxDlgSay($npc, fxFolSnap('fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:0/4,cap:1'),
        'Follow me.', 'inputtext', $offered);
    $t->must('an ordinary quest list does NOT hide CHIM\'s Follow_<player>',
        in_array('FollowPlayer', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('... and nothing is executed off a list that cannot answer the verb either',
        fxDlgLlm($npc, 'follow me') === [], 'a wrong entry was picked');

    // ---------------------------------------------------------------- 2. the verb reaches the REAL entry
    fxDlgCache($npc, fxFolSpec(['wait', 'follow', 'trade', 'dismiss', 'favor']));
    $q = fxDlgSay($npc, fxFolSnap(), 'Wait here, I will be back.', 'inputtext', $offered);
    $w = fxDlgLlm($npc, 'wait here');
    $t->must('"wait here" runs exactly one command', count($w) === 1, json_encode(array_map('fxParam', $w)));
    $kv = $w ? fxDlgKv($w[0]) : [];
    $t->must('... and it is HER wait entry, by position', (string) ($kv['do'] ?? '') === 'pick'
        && (int) ($kv['pos'] ?? -1) === 0, json_encode($kv));
    $t->must('... and the key order of the wire line is unchanged', $w && fxDlgOrderOk($w[0]), fxParam($w[0] ?? ''));
    $t->must('... and no CHIM shortcut could have fired in the same reply',
        !in_array('WaitHere', $q['enabled'], true) && !in_array('FollowPlayer', $q['enabled'], true),
        implode(',', $q['enabled']));

    fxDlgSay($npc, fxFolSnap('fw:sff,mate:1,cff:1,pff:0,wait:1,chim:0,ghost:0,slot:1/4,cap:1,prim:1'),
        'Follow me again.', 'inputtext', $offered);
    $w = fxDlgLlm($npc, 'follow me');
    $kv = $w ? fxDlgKv($w[0]) : [];
    $t->must('"follow me" to somebody already waiting runs her follow-again entry',
        count($w) === 1 && (int) ($kv['pos'] ?? -1) === 1, json_encode($kv));

    fxDlgSay($npc, fxFolSnap(), "Let's trade, I need you to carry this.", 'inputtext', $offered);
    $w = fxDlgLlm($npc, "let's trade");
    $kv = $w ? fxDlgKv($w[0]) : [];
    $t->must('"let\'s trade" runs her real trade entry, not CHIM\'s OpenInventory',
        count($w) === 1 && (int) ($kv['pos'] ?? -1) === 2, json_encode($kv));

    // ---------------------------------------------------------------- 3. a dismissal is a commitment
    fxDlgClearQueue();
    $q = fxDlgSay($npc, fxFolSnap(), "You're dismissed, go home.", 'inputtext', $offered);
    $t->must('the turn records the verb the player asked for', (string) (($q['turn']['fol'] ?? [])['asked'] ?? '') === 'dismiss',
        json_encode($q['turn']['fol'] ?? []));
    $w1 = fxDlgLlm($npc, "you're dismissed", 'Do you truly mean that?');
    $t->must('the FIRST dismissal is parked, never carried out', $w1 === [], json_encode(array_map('fxParam', $w1)));
    $t->must('and nothing was queued on either route', fxDlgQueue() === [], json_encode(fxDlgQueue()));
    fxDlgSay($npc, fxFolSnap(), 'Yes, I mean it.', 'inputtext', $offered);
    $w2 = fxDlgLlm($npc, "you're dismissed");
    $kv2 = $w2 ? fxDlgKv($w2[0]) : [];
    $t->must('the SECOND, from a new request, runs her real dismiss entry',
        count($w2) === 1 && (int) ($kv2['pos'] ?? -1) === 3, json_encode($kv2));

    // ---------------------------------------------------------------- 4. nothing is ever guessed
    fxDlgCache($npc, fxFolSpec(['wait', 'dismiss']));
    fxDlgSay($npc, fxFolSnap(), "Let's trade, hold this for me.", 'inputtext', $offered);
    $t->must('a verb her list cannot answer executes NOTHING - the shortcut is not a stand-in',
        fxDlgLlm($npc, "let's trade") === [], 'something was executed');
    fxDlgSay($npc, fxFolSnap(), "Don't wait here, come with me instead.", 'inputtext', $offered);
    $w = fxDlgLlm($npc, 'wait here');
    $t->must('a NEGATED order never reaches the wait entry',
        !$w || (int) (fxDlgKv($w[0])['pos'] ?? -1) !== 0, json_encode(array_map('fxParam', $w)));

    // REQUIEM'S OWN RECRUITMENT GATES: they are INFO conditions, so the ONLY way to respect them is to
    // use the real entry and never to fabricate one. With the recruit entry conditioned away, "come
    // with me" must execute nothing at all - and MakeFollower must not be offered as a consolation.
    fxDlgCache($npc, fxFolSpec(['wait', 'dismiss']));
    $q = fxDlgSay($npc, fxFolSnap('fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:1/4,cap:1'),
        'Come with me, I need your help.', 'inputtext', $offered);
    // the free-conversation speech check may still answer this turn (it is a persuasion attempt in
    // anybody's words); what must NOT happen is an entry being clicked
    $w = fxDlgLlm($npc, 'come with me');
    $picked = array_values(array_filter($w, static fn($l) => fxDlgDo($l) === 'pick'));
    $t->must('a recruit the engine is not offering cannot be talked into existence',
        $picked === [], json_encode(array_map('fxParam', $picked)));
    $t->must('... and CHIM\'s "join my party" is not offered as a substitute either',
        !in_array('MakeFollower', $q['enabled'], true), implode(',', $q['enabled']));

    // the blocking favour topic is never selectable, whatever the player says
    fxDlgCache($npc, fxFolSpec(['blocking', 'wait']));
    fxDlgSay($npc, fxFolSnap(), 'Yes, let me show you.', 'inputtext', $offered);
    $w = fxDlgLlm($npc, 'yes let me show you');
    $t->must('a favour-blocking topic is never reached through a follower verb',
        !$w || (string) (fxDlgKv($w[0])['txt'] ?? '') !== 'Yes, let me show you.',
        json_encode(array_map('fxParam', $w)));

    // ---------------------------------------------------------------- 5. the snapshot's own rules
    $folPolicy = static function (array $snapKv, array $enabled) use ($npc) {
        fxSendSnapshot($npc, fxDlgSnap($snapKv));
        $GLOBALS['HERIKA_NAME'] = $npc;
        $GLOBALS['ENABLED_FUNCTIONS'] = $enabled;
        lrgFollowerPolicy();
        return array_values((array) $GLOBALS['ENABLED_FUNCTIONS']);
    };
    $base = ['Talk', 'MakeFollower', 'Follow', 'FollowPlayer'];
    $r = $folPolicy(['fol' => 'fw:chim,mate:0,cff:1,pff:0,wait:0,chim:0,ghost:1,slot:1/4,cap:1'], $base);
    $t->must('a HALF-RECRUITED follower takes all three "make it worse" actions off the table',
        !array_intersect(['MakeFollower', 'Follow', 'FollowPlayer'], $r) && in_array('Talk', $r, true),
        implode(',', $r));
    $r = $folPolicy(['fol' => 'fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:4/4,cap:0'], $base);
    $t->must('the framework\'s own full-party global takes "join my party" off the table',
        !in_array('MakeFollower', $r, true) && in_array('FollowPlayer', $r, true), implode(',', $r));
    $r = $folPolicy([], $base);
    $t->must('a snapshot with no fol= at all (an older game script) changes nothing',
        $r === $base, implode(',', $r));

    // ---------------------------------------------------------------- 6. what she is told
    $GLOBALS['PLAYER_NAME'] = 'Jordan';
    fxSendSnapshot($npc, fxFolSnap());
    $blk = lrgFollowerBlock($npc, lrgFolFor($npc));
    $t->must('the companion block states the facts and names the commands as HER dialogue',
        str_contains($blk, '<companion_status>') && str_contains($blk, 'part ways')
        && str_contains($blk, 'never say that one of them has happened'), fx_short($blk, 200));
    $t->must('and it never invents CHIM\'s own party block', !str_contains($blk, 'adventuring party'), fx_short($blk, 120));
    fxDlgCache($npc, fxFolSpec(['wait', 'dismiss']));
    $q = fxDlgSay($npc, fxFolSnap(), 'Wait here.', 'inputtext', $offered);
    $t->must('the volatile guidance names the verbs her list can really answer',
        str_contains($q['volatile'], '<follower_commands>') && str_contains($q['volatile'], 'wait'),
        fx_short($q['volatile'], 240));
    $t->must('the turn line carries the follower facts for the owner\'s log',
        str_contains(lrgDlgTurnLine($q['turn']), ' fol=') && str_contains(lrgDlgTurnLine($q['turn']), ' folask=wait'),
        lrgDlgTurnLine($q['turn']));
});
