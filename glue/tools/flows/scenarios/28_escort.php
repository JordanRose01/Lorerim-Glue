<?php
// 28 - [0.5.4 / pt13] "I COULDN'T GET HER TO FOLLOW ME".
//
// Playtest 13, 01:43-01:44: the player said "come with me and talk to me in private", "come on follow
// me now" and "are you gonna follow me?" to Lisette. The glue logged intent=none three times; CHIM chose
// Follow_<player> three times and its package really went on - and she stayed on the stage, because a
// bard's running ENGINE scene (snapshot scene=1, gate reason quest_scene) outranks every package.
// This scenario plays the whole loop the way the server now closes it:
//   - the words are recognised in every mode outside a scene with her (escort/follow|wait|release);
//   - the directive names Follow_<player> plainly and asks for ONE short line;
//   - the post-LLM gate puts ExtCmdLRG_Escort in front of CHIM's own line when she is on a stage,
//     carries wait / release itself (CHIM's WaitHere is off in the catalog), and never touches a
//     companion a follower framework owns;
//   - the game's funcret is recorded and ends before the lock; an old game's "unknown command" is never
//     told to her and pauses the escort for that game session;
//   - a fol= the game stopped sending mid-session is remembered for the session.
// Every sentence below is the PLAYER's (search input), never a line an NPC says.
require_once __DIR__ . '/../dlg_adapter.php';

/** Everything the plugin logged while $fn ran. */
function fx28_log(callable $fn): string
{
    $file = fxLogFile();
    $before = is_file($file) ? (int) filesize($file) : 0;
    $fn();
    clearstatcache(true, $file);
    if (!is_file($file)) { return ''; }
    $fh = fopen($file, 'rb');
    fseek($fh, $before);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

/** The escort lines among what reached the game. */
function fx28_escorts(array $w): array
{
    return array_values(array_filter($w, static fn($l) => fxCode((string) $l) === 'ExtCmdLRG_Escort'));
}

fx_scenario('28', 'pt13: "follow me" is understood in every mode, Follow_<player> is named plainly, ExtCmdLRG_Escort takes a bard off her stage (wait / release too), its funcret ends before the lock, a fol= lost mid-session is remembered - and [pt17] the escort hands a stranger the framework can take, and a companion of SFF\'s, to the game (CHIM\'s Follow_<player> dropped), an OK with fb= is voiced, a custom follower SAYS her dialogue is the way', function (FxT $t) {
    $npc = 'Lisette Flowtest';
    $offered = array_merge(fxEnabledDefault(), ['FollowPlayer']);
    $opt = ['enabled' => $offered];
    // the playtest-13 room: a bard on her stage (an engine scene), four people listening -> mode closed
    $stage = fxSnap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1', 'wit' => '4', 'sess' => '6083']);
    $free = ['scene' => '0'] + $stage;
    $follow = fxLine($npc, 'FollowPlayer', '');
    $safe = 'BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*';

    // ---------------------------------------------------------------- 1. the words (the three lines of pt13)
    $log = fx28_log(function () use (&$r, $npc, $stage, $opt) { $r = fxSay($npc, $stage, 'okay so come on follow me now', 'inputtext', $opt); });
    $t->must('set-up: the stage is mode closed, for the reasons playtest 13 logged',
        $r->mode() === 'closed' && in_array('quest_scene', $r->reasons(), true), $r->summary());
    $t->must('"okay so come on follow me now" is escort/follow at high confidence (pt13: intent=none)',
        $r->intentIs('escort', 'high') && (string) ($r->intent()['kv']['do'] ?? '') === 'follow', json_encode($r->intent()));
    $t->must('the turn line shows it', (bool) preg_match('/ turn npc=Lisette Flowtest .*intent=escort\/follow conf=high/', $log), fx_short($log, 400));
    $d = $r->directive();
    $t->must('the directive names Follow_<player> plainly and asks for ONE short line',
        str_contains($d, 'choose Follow_Flowtest Player in this same reply') && str_contains($d, 'one short line'), fx_short($d, 400));
    $t->must('... it is not the closed-mode intimacy refusal, and it says so',
        str_contains($d, 'This is not intimacy') && !str_contains($d, 'It is not happening'), fx_short($d, 400));
    $r2 = fxSay($npc, $stage, 'uh i need you to come with me and talk to me in private for a moment', 'inputtext', $opt);
    $t->must('"i need you to come with me and talk to me in private" is escort/follow (pt13: intent=none)',
        $r2->intentIs('escort', 'high') && (string) ($r2->intent()['kv']['do'] ?? '') === 'follow', json_encode($r2->intent()));
    $r3 = fxSay($npc, $stage, 'hey are you gonna follow me?', 'inputtext', $opt);
    $t->must('"hey are you gonna follow me?" stays a question: no escort, no directive',
        !$r3->intentIs('escort') && (string) ($r3->intent()['why'] ?? '') === 'question' && !$r3->hasDirective(), json_encode($r3->intent()));
    $t->must('... and nothing at all is added to what the model chose', fxLlmNothing($npc) === []);

    // ---------------------------------------------------------------- 2. Follow_<player> + a stage: the escort goes FIRST
    $r = fxSay($npc, $stage, 'okay so come on follow me now', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('two lines reach the game: ExtCmdLRG_Escort, then CHIM\'s own line byte for byte',
        count($w) === 2 && fxCode($w[0]) === 'ExtCmdLRG_Escort' && $w[1] === $follow, json_encode(array_map('trim', $w)));
    $kv = $w ? fxParamKv($w[0]) : [];
    $t->must('the escort line is server-built: ok=1;cid=<turn cid>;npc=..;do=follow;safe=<the config list>, in that order',
        $w && (bool) preg_match('/^ok=1;cid=[A-Za-z0-9_-]+;npc=Lisette Flowtest;do=follow;safe=[^;]+$/', fxParam($w[0]))
        && ($kv['cid'] ?? '') === (string) $r->turn['cid'] && ($kv['safe'] ?? '') === $safe && str_ends_with($w[0], "\r\n"),
        fxParam($w[0] ?? ''));
    $t->must('one greppable "escort" line says why', (bool) preg_match('/ escort follow npc=Lisette Flowtest scene=1 .*why=the reply chose FollowPlayer/', $log), fx_short($log, 400));
    $t->must('the display name the core sends when it cannot map the code back counts too',
        count(fx28_escorts(fxLlmLines([fxLine($npc, 'Follow_Flowtest_Player', '')]))) === 1);

    // ---------------------------------------------------------------- 3. the game answers: recorded, never reaches CHIM
    $t->must('the escort funcret is handled BEFORE the lock (no CHIM infoaction, no LLM turn)',
        fxFuncret($w[0], 'Lisette Flowtest leaves the stage and walks with you.') === 'handled');
    $lr = (array) (fxMem($npc)['last_result'] ?? []);
    $t->must('... and recorded as her last result', ($lr['cmd'] ?? '') === 'ExtCmdLRG_Escort' && !empty($lr['ok']) && ($lr['do'] ?? '') === 'follow', json_encode($lr));
    // [0.5.5] every glue SUCCESS ends before the lock now; a FAILURE the player waits on is voiced (scenario 29)
    $t->must('an ordinary glue success ends before the lock too (no funcret turn)', fxFuncret(fxLine($npc, FX_ACT_CLOTHING, 'ok=1;cid=sx;npc=' . $npc . ';do=undress;who=npc;part=all'), 'Lisette Flowtest undresses.') === 'handled');

    // ---------------------------------------------------------------- 4. the model only talked: the net shape carries it
    fxSay($npc, $stage, 'come with me', 'inputtext', $opt);
    $w = fxLlmNothing($npc);
    $t->must('a high-confidence follow, a stage, and a reply with no movement: the escort goes out alone',
        count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Escort' && fxDo($w[0]) === 'follow', json_encode(array_map('trim', $w)));
    fxSay($npc, $stage, 'come with me', 'inputtext', $opt);
    $w = fxLlmLines([fxLine($npc, 'TravelTo', 'Whiterun')]);
    $t->must('... but a reply that chose ANOTHER movement is not overridden', fx28_escorts($w) === [], json_encode(array_map('trim', $w)));

    // ---------------------------------------------------------------- 5. no stage: CHIM's own follow is enough
    fxSay($npc, $free, 'follow me', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('no engine scene: CHIM\'s FollowPlayer goes out alone, untouched', $w === [$follow], json_encode(array_map('trim', $w)));
    $t->must('... and the log says why nothing was added', str_contains($log, 'escort skipped: she is in no engine scene'), fx_short($log, 300));

    // ---------------------------------------------------------------- 5b. [pt17] no stage, but Simple Follower Framework can take her: the escort carries the follow
    // fol= says nobody's, PotentialFollowerFaction (pff 0), a free slot (cap 1, slot 0/1 at Speech 15): the game recruits her
    // through SFF's own SetFollower, so CHIM's Follow_<player> line comes out of the batch (its priority-100 override
    // would sit on the new companion; the game re-adds CHIM's follow itself when it has to fall back).
    $take = ['fol' => 'fw:none,mate:0,cff:-1,pff:0,wait:0,chim:0,ghost:0,slot:0/1,cap:1'] + $free;
    $r = fxSay($npc, $take, 'follow me', 'inputtext', $opt);
    $t->must('[pt17] the directive still names Follow_<player>, and says the game may take her on as his companion',
        str_contains($r->directive(), 'choose Follow_Flowtest Player') && str_contains($r->directive(), "take Lisette Flowtest on as Flowtest Player's companion"), fx_short($r->directive(), 500));
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('[pt17] a promotable stranger outside a scene: ONE escort line, do=follow, and CHIM\'s own Follow_<player> is dropped',
        count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Escort' && fxDo($w[0]) === 'follow', json_encode(array_map('trim', $w)));
    $t->must('... the log says the framework may take her, and why CHIM\'s line went',
        str_contains($log, 'take=1') && str_contains($log, 'the game can make her his companion')
        && str_contains($log, 'escort: dropped FollowPlayer - the escort carries the follow to her framework'), fx_short($log, 500));
    fxSay($npc, $take, 'follow me', 'inputtext', $opt);
    $w = fxLlmNothing($npc);
    $t->must('... a reply with no movement at all: the escort goes out alone (the in-scene safety net, outside a scene too)',
        count($w) === 1 && fxDo($w[0]) === 'follow', json_encode(array_map('trim', $w)));
    fxSay($npc, $take, 'follow me', 'inputtext', $opt);
    $t->must('... a reply that chose ANOTHER movement is still not overridden', fx28_escorts(fxLlmLines([fxLine($npc, 'TravelTo', 'Whiterun')])) === []);
    fxSay($npc, $take, 'Hello.', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('... but the model\'s OWN Follow_<player>, unasked, stays CHIM\'s package follow as before',
        $w === [$follow] && str_contains($log, 'the player did not ask'), fx_short($log, 300));
    $full = ['fol' => 'fw:none,mate:0,cff:-1,pff:0,wait:0,chim:0,ghost:0,slot:1/1,cap:0'] + $free;
    fxSay($npc, $full, 'follow me', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('... no free slot: CHIM\'s follow goes out alone as before, and the log says what is missing',
        $w === [$follow] && str_contains($log, 'not promotable: no free companion slot'), fx_short($log, 300));
    $noPff = ['fol' => 'fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:0/1,cap:1'] + $free;
    fxSay($npc, $noPff, 'follow me', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('... not recruitable (Lisette before her FDE stage adds PotentialFollowerFaction): the old way, and the log says so',
        $w === [$follow] && str_contains($log, 'not in PotentialFollowerFaction'), fx_short($log, 300));
    fxSay($npc, $take + $stage, 'follow me', 'inputtext', $opt);
    $w = fxLlmLines([$follow]);
    $t->must('... on her stage too: the escort alone, CHIM\'s line dropped (the game stops the scene, then recruits her)',
        count($w) === 1 && fxDo($w[0]) === 'follow', json_encode(array_map('trim', $w)));
    $walking = ['fol' => 'fw:chim,mate:0,cff:-1,pff:-1,wait:0,chim:1,ghost:0,slot:0/1,cap:1'] + $free;
    $r = fxSay($npc, $walking, 'follow me', 'inputtext', ['enabled' => fxEnabledDefault()]);
    $t->must('[pt17] CHIM\'s follow already on her, not promotable, Follow_<player> not offered: the truth ("already walking"), never "nothing can"',
        str_contains($r->directive(), 'is already walking with Flowtest Player') && !str_contains($r->directive(), 'Nothing can make'), fx_short($r->directive(), 300));
    $ghost = ['fol' => 'fw:chim,mate:0,cff:1,pff:0,wait:0,chim:0,ghost:1,slot:0/1,cap:1'] + $free;
    $r = fxSay($npc, $ghost, 'follow me', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $npc) { $w = fxLlmNothing($npc); });
    $t->must('[pt17] CHIM\'s half-recruited ghost is the game\'s to route: do=follow goes out (recruited properly, or the old way with the reason voiced)',
        count($w) === 1 && fxDo($w[0]) === 'follow' && str_contains($log, 'owner=ghost'), json_encode(array_map('trim', $w)) . ' ' . fx_short($log, 300));
    $t->must('... and the directive says the game may take her on as his companion',
        str_contains($r->directive(), "take Lisette Flowtest on as Flowtest Player's companion"), fx_short($r->directive(), 400));

    // ---------------------------------------------------------------- 5c. [pt17] the game came back with a "but": voiced, and told next turn when the voice gap skips it
    fxSay($npc, $take, 'follow me', 'inputtext', $opt);
    $esc = fx28_escorts(fxLlmLines([$follow]));
    $t->must('set-up: an escort line went out', count($esc) === 1);
    $fbLine = fxLine($npc, 'ExtCmdLRG_Escort', fxParam($esc[0]) . ';fb=you have no free companion slot (1/1 at Speech 15)');
    $log = fx28_log(function () use (&$res, $fbLine, $npc) { $res = fxFuncret($fbLine, 'OK: ' . $npc . ' comes with you.'); });
    $t->must('an OK funcret with fb= (she came along the old way) is PASSED to CHIM\'s funcret turn like a failure', $res === 'pass', $res);
    $v = fxVoiced();
    $t->must('... with the fact "comes along, but not as his sworn companion" and the slot reason in plain words',
        is_array($v) && !empty($v['partial']) && str_contains((string) $v['fact'], 'comes along with Flowtest Player, but not as his sworn companion')
        && str_contains((string) $v['fact'], 'can lead only one companion at a time'), json_encode($v));
    $t->must('... the log says so', str_contains($log, '[but: you have no free companion slot') && str_contains($log, '(partial: came along, not as a companion)'), fx_short($log, 400));
    $lr = (array) (fxMem($npc)['last_result'] ?? []);
    $t->must('... and last_result keeps it as an OK with the reason', !empty($lr['ok']) && ($lr['partial'] ?? '') === 'you have no free companion slot (1/1 at Speech 15)', json_encode($lr));
    fxSay($npc, $take, 'follow me', 'inputtext', $opt);
    $esc = fx28_escorts(fxLlmLines([$follow]));
    $fbLine2 = fxLine($npc, 'ExtCmdLRG_Escort', fxParam($esc[0]) . ';fb=the game does not let you recruit her yet');
    $log = fx28_log(function () use (&$res, $fbLine2, $npc) { $res = fxFuncret($fbLine2, 'OK: ' . $npc . ' comes with you.'); });
    $t->must('... inside the voice gap it is handled quietly and remembered as missed', $res === 'handled' && str_contains($log, 'came along but not as a companion'), $res . ' ' . fx_short($log, 300));
    $r = fxSay($npc, $take, 'so are you with me?', 'inputtext', $opt);
    $t->must('... and her next turn is told, in plain words (never silent)',
        str_contains($r->volatile, "did not manage to become Flowtest Player's companion") && str_contains($r->volatile, 'not somebody who can be taken on as a companion yet'), fx_short($r->volatile, 400));

    // ---------------------------------------------------------------- 6. wait / release: carried by the glue
    $r = fxSay($npc, $free, 'wait here', 'inputtext', $opt);
    $t->must('"wait here" outside a scene is escort/wait, not the scene\'s hold', $r->intentIs('escort', 'high')
        && (string) ($r->intent()['kv']['do'] ?? '') === 'wait', json_encode($r->intent()));
    $t->must('the directive: nothing to choose, and not Follow_<player> either',
        str_contains($r->directive(), 'the game stops Lisette Flowtest following Flowtest Player by itself') && str_contains($r->directive(), 'not Follow_Flowtest Player either'), fx_short($r->directive(), 300));
    $w = fxLlmLines([$follow]);
    $t->must('a FollowPlayer the model chose against "wait here" is dropped, and do=wait goes out',
        count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Escort' && fxDo($w[0]) === 'wait', json_encode(array_map('trim', $w)));
    fxSay($npc, $stage, 'you can go back to singing now', 'inputtext', $opt);
    $w = fxLlmNothing($npc);
    $t->must('"you can go back to singing now" -> do=release', count($w) === 1 && fxDo($w[0]) === 'release', json_encode(array_map('trim', $w)));

    // ---------------------------------------------------------------- 7. [pt17] a companion of Simple Follower Framework is the GAME's to route now
    // (SFF's own FollowerWait / FollowerFollow through the escort); a custom follower's own quest still owns hers
    $sff = ['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1,prim:1'] + $stage;
    $r = fxSay($npc, $sff, 'wait here', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $npc) { $w = fxLlmNothing($npc); });
    $t->must('[pt17] an SFF follower: do=wait goes out - the game makes her wait through SFF\'s own call', count($w) === 1 && fxDo($w[0]) === 'wait', json_encode(array_map('trim', $w)));
    $t->must('... the log names her owner, and the directive says the game itself does it, naming no CHIM action',
        str_contains($log, 'owner=fw:sff') && str_contains($r->directive(), 'the game itself makes Lisette Flowtest wait where she is') && !str_contains($r->directive(), 'Follow_'), fx_short($log, 300) . ' | ' . fx_short($r->directive(), 300));
    $w = fxLlmLines([$follow]);
    $t->must('... and a Follow_<player> the model chose against "wait here" is dropped', count($w) === 1 && fxDo($w[0]) === 'wait', json_encode(array_map('trim', $w)));
    $r = fxSay($npc, $sff, 'follow me', 'inputtext', $opt);
    $w = fxLlmLines([$follow]);
    $t->must('... "follow me" to her: do=follow alone, CHIM\'s line dropped - she follows again through SFF, and the directive says so',
        count($w) === 1 && fxDo($w[0]) === 'follow' && str_contains($r->directive(), 'follow Flowtest Player again'), json_encode(array_map('trim', $w)) . ' | ' . fx_short($r->directive(), 300));
    $r = fxSay($npc, $sff, 'you can go', 'inputtext', $opt);
    $t->must('... "you can go" to a companion is a WAIT, never a dismissal: the directive says so, and where parting ways is said',
        str_contains($r->directive(), 'stop following and wait where she is') && str_contains($r->directive(), 'parting ways for good'), fx_short($r->directive(), 400));
    $w = fxLlmNothing($npc);
    $t->must('... and do=release goes out (the game turns it into her wait)', count($w) === 1 && fxDo($w[0]) === 'release', json_encode(array_map('trim', $w)));
    $custom = ['fol' => 'fw:custom,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1'] + $stage;
    $r = fxSay($npc, $custom, 'wait here', 'inputtext', $opt);
    $log = fx28_log(function () use (&$w, $npc) { $w = fxLlmNothing($npc); });
    $t->must('a custom follower (Inigo, Lucien ...): no escort line - her own dialogue does this',
        $w === [] && str_contains($log, 'escort skipped: a follower framework owns her (fw:custom)'), json_encode(array_map('trim', $w)) . ' ' . fx_short($log, 300));
    $t->must('... and the directive makes her SAY so (ask her the way he always does) - never "her dialogue carries it out" while no such dialogue runs',
        str_contains($r->directive(), 'ask her the way he always does') && !str_contains($r->directive(), 'carries that out') && !str_contains($r->directive(), 'Follow_'), fx_short($r->directive(), 400));
    $t->must('... and CHIM\'s own line (if the model still chose it) passes untouched, with nothing added', fxLlmLines([$follow]) === [$follow]);

    // ---------------------------------------------------------------- 8. fol= retired mid-session (the game's false abort alarm)
    fxSay($npc, $sff, 'Hello.', 'inputtext', $opt);        // the last fol= the session sent is SFF's again (section 7 ended on a custom follower)
    fxSay($npc, $stage, 'wait here', 'inputtext', $opt);   // same session 6083, NO fol= on this snapshot
    $fol = lrgFolFor($npc);
    $t->must('the fol= the same session sent is remembered when the game stops sending it',
        ($fol['fw'] ?? '') === 'sff' && !empty($fol['_remembered']), json_encode($fol));
    $w = fxLlmNothing($npc);
    $t->must('... so the escort still routes her as SFF\'s (do=wait), not as a stranger', count($w) === 1 && fxDo($w[0]) === 'wait', json_encode(array_map('trim', $w)));
    $blk = lrgFollowerBlock($npc, $fol);
    $t->must('... and the companion block still says who she is, without claiming what she is doing this minute',
        str_contains($blk, 'as his closest companion') && !str_contains($blk, 'Right now'), fx_short($blk, 300));
    $next = ['sess' => '7001'] + $stage;                     // the game was reloaded: a new session tag
    fxSay($npc, $next, 'wait here', 'inputtext', $opt);
    $t->must('a reload (new session tag) forgets it: nothing from another save is carried over', lrgFolFor($npc) === [], json_encode(lrgFolFor($npc)));
    $w = fxLlmNothing($npc);
    $t->must('... and the escort is back for somebody nobody owns', count($w) === 1 && fxDo($w[0]) === 'wait', json_encode(array_map('trim', $w)));

    // ---------------------------------------------------------------- 9. an old game script: "unknown command"
    fxSay($npc, $next, 'follow me', 'inputtext', $opt);
    $w = fxLlmLines([$follow]);
    $esc = fx28_escorts($w);
    $t->must('set-up: an escort line went out', count($esc) === 1);
    $log = fx28_log(function () use (&$res, $esc) { $res = fxFuncret($esc[0], 'Error: unknown command'); });
    $t->must('an old game\'s "unknown command" is handled before the lock', $res === 'handled');
    $t->must('... is never told to her (nothing she tried failed)', !empty((fxMem($npc)['last_result'] ?? [])['told']), json_encode(fxMem($npc)['last_result'] ?? []));
    $t->must('... and the log names it', str_contains($log, 'this game script does not know ExtCmdLRG_Escort'), fx_short($log, 300));
    $r = fxSay($npc, $next, 'follow me', 'inputtext', $opt);
    $t->must('her next turn carries no "what you last tried did not happen"', empty($r->turn['fail']) && !str_contains($r->volatile, 'did not happen'), fx_short($r->volatile, 300));
    $log = fx28_log(function () use (&$w, $follow) { $w = fxLlmLines([$follow]); });
    $t->must('the escort pauses for the rest of THAT game session (one corner note, not one per "follow me")',
        $w === [$follow] && str_contains($log, 'escort skipped: the game script answered "unknown command"'), fx_short($log, 300));
    fxSay($npc, ['sess' => '7002'] + $stage, 'follow me', 'inputtext', $opt);
    $t->must('... and tries again after a reload (the new script may know it)', count(fx28_escorts(fxLlmLines([$follow]))) === 1);

    // ---------------------------------------------------------------- 10. silent for a reason the game KNOWS
    $off = ['on' => '0', 'sess' => '7002'] + $stage;         // the owner's intimacy switch is off
    $r = fxSay($npc, $off, 'follow me', 'inputtext', $opt);
    $t->must('set-up: the turn is silent (feature_off)', $r->mode() === 'silent' && in_array('feature_off', $r->reasons(), true), $r->summary());
    $t->must('"follow me" is still recognised (the escort-only recogniser: no sexual reading exists)', $r->intentIs('escort', 'high'), json_encode($r->intent()));
    $t->must('... the one directive a silent turn may carry is injected', str_contains($r->volatile, 'choose Follow_Flowtest Player'), fx_short($r->volatile, 300));
    $t->must('... and no glue action is offered because of it', $r->glueOffered() === [], $r->glueNames());
    $t->must('... and the escort goes out in front of CHIM\'s line', ($w = fxLlmLines([$follow])) && count($w) === 2 && fxCode($w[0]) === 'ExtCmdLRG_Escort');
    $r = fxSay($npc, $off, 'take off your clothes', 'inputtext', $opt);
    $t->must('... while an intimate request on the same silent turn is still not even parsed', $r->intent()['kind'] === 'none' && !$r->hasDirective(), json_encode($r->intent()));

    // ---------------------------------------------------------------- 11. the model can never emit it itself
    fxSay($npc, $free, 'Hello.', 'inputtext', $opt);
    $t->must('an ExtCmdLRG_Escort line FROM the model is dropped like any unoffered glue code',
        fxLlm($npc, 'ExtCmdLRG_Escort', 'ok=1;cid=sFORGED;npc=' . $npc . ';do=follow;safe=*') === []);

    // ---------------------------------------------------------------- 11b. the conversation hold hears it too
    // While LRG_Main holds her for a conversation (SetDontMove), CHIM's movement actions are only kept on
    // the table when the player's own words asked her to move - and "come along" was not in that list.
    fxDlgLoad();
    {
        lrgDlgPut($npc, ['utter' => ['text' => 'come along', 'at' => fxNow(), 'type' => 'inputtext']]);
        $t->must('the conversation hold counts "come along" as a request to move', lrgDlgPlayerAskedToMove($npc) === 'come along');
        lrgDlgPut($npc, ['utter' => ['text' => 'oh come on, really', 'at' => fxNow(), 'type' => 'inputtext']]);
        $t->must('... but not an exasperated "oh come on"', lrgDlgPlayerAskedToMove($npc) === '', lrgDlgPlayerAskedToMove($npc));
    }

    // ---------------------------------------------------------------- 12. inside an intimate scene with the player
    $c = fxCast('innkeeper'); $lover = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($lover, $alone, 10);
    $sc = fxBeginScene($t, $lover, $alone);
    $r = fxSay($lover, $sc['snap'], 'wait here', 'inputtext', ['enabled' => array_merge(fxEnabledDefault(), ['FollowPlayer'])]);
    $t->must('inside a scene with her "wait here" keeps its scene reading (hold)', $r->intentIs('hold'), json_encode($r->intent()));
    $t->must('... and no escort line ever goes out there', fx28_escorts(fxLlmNothing($lover)) === []
        && fx28_escorts(fxLlmLines([fxLine($lover, 'FollowPlayer', '')])) === []);
});
