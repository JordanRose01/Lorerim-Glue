<?php
// 32 - [pt19 / script 511] QUIET MODE, the server half. While the game says quiet=1 for an NPC (LRG_Main.QuietOn: a
// curated scripted intro such as Helgen's Unbound runs), CHIM's own movement / re-tasking actions are withheld from
// the offer - the follower policy's shape, an OFFER change and never a mute - and one line with the reason is prepared
// for prompt_bottom (NEVER SILENT). The flag is honoured from the snapshot and from the facts line, it goes stale by
// itself, and quiet=0 changes nothing. Every sentence below is the PLAYER's.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('32', 'quiet mode: quiet=1 withholds Follow / MoveTo / EndConversation and keeps Talk; the reason line; stale or 0 hides nothing', function (FxT $t) {
    $npc = 'Hadvar Flowtest';
    fxDlgReset();
    // ---- A. the snapshot carrier
    $q = fxDlgSay($npc, fxDlgSnap(['quiet' => '1']), 'Come with me.');
    $t->must('A: quiet=1 on a fresh snapshot: Follow, MoveTo and EndConversation are withheld',
        !in_array('Follow', $q['enabled'], true) && !in_array('MoveTo', $q['enabled'], true) && !in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('A: ... Talk and GiveGoldTo stay (an offer change, never a mute)',
        in_array('Talk', $q['enabled'], true) && in_array('GiveGoldTo', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('A: the reason line is prepared for the prompt and names the NPC',
        str_contains((string) ($GLOBALS['LRG_QUIET']['line'] ?? ''), $npc), json_encode($GLOBALS['LRG_QUIET'] ?? null));
    // ---- B. quiet=0
    $q0 = fxDlgSay($npc, fxDlgSnap(['quiet' => '0']), 'Come with me.');
    $t->must('B: quiet=0 hides nothing and prepares no line',
        in_array('Follow', $q0['enabled'], true) && in_array('MoveTo', $q0['enabled'], true) && in_array('EndConversation', $q0['enabled'], true) && !isset($GLOBALS['LRG_QUIET']), implode(',', $q0['enabled']));
    // ---- C. the facts line alone, the snapshot silent about it
    $ev = fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'quiet' => '1', 'mq101' => '200', 'mq101c' => '0']);
    $t->must('C: ev=facts quiet=1 is stored with its stamp',
        (int) ($ev['state']['facts']['quiet'] ?? 0) === 1 && (int) ($ev['state']['facts']['quiet_at'] ?? 0) === fxNow(), json_encode($ev['state']['facts'] ?? null));
    $qf = fxDlgSay($npc, fxDlgSnap(), 'Follow me.');
    $t->must('C: ... and withholds the movement actions on its own', !in_array('Follow', $qf['enabled'], true) && in_array('Talk', $qf['enabled'], true), implode(',', $qf['enabled']));
    $t->must('C: the words name the Helgen intro (mq101 on the facts, not complete)',
        str_contains((string) ($GLOBALS['LRG_QUIET']['line'] ?? ''), 'Helgen'), json_encode($GLOBALS['LRG_QUIET'] ?? null));
    // ---- D. it goes stale by itself
    fxAdvance((int) fxCfg('quiet.fresh_seconds', 300) + 1);
    $qs = fxDlgSay($npc, fxDlgSnap(), 'Follow me.');
    $t->must('D: after quiet.fresh_seconds the facts flag no longer counts', in_array('Follow', $qs['enabled'], true) && in_array('MoveTo', $qs['enabled'], true), implode(',', $qs['enabled']));
});
