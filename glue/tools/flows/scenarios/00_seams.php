<?php
// 00 - the test seams of PROTOCOL.md 7.2 themselves. When one of these fails, distrust every later scenario.
fx_scenario('00', 'test seams: clock, dice, pre-lock entry point, memory, DB shapes', function (FxT $t) {
    $npc = fxCast('innkeeper')['name'];
    $snap = fxSnap(fxCast('innkeeper')['snap']);

    $t->mustCap('clock', 'lrgNow() follows LRG_TEST_NOW and falls back to the real time', function () {
        $keep = $GLOBALS['LRG_TEST_NOW'];
        $GLOBALS['LRG_TEST_NOW'] = 1234567890; $a = fxPluginNow();
        unset($GLOBALS['LRG_TEST_NOW']); $b = fxPluginNow();
        $GLOBALS['LRG_TEST_NOW'] = $keep;
        return $a === 1234567890 && abs($b - time()) <= 2;
    });
    $t->mustCap('dice', 'lrgRoll() reads LRG_TEST_ROLL[what], then [*], then rolls 1..100', function () {
        $keep = $GLOBALS['LRG_TEST_ROLL'];
        $GLOBALS['LRG_TEST_ROLL'] = ['announce' => 7, '*' => 93]; $a = fxPluginRoll('announce'); $b = fxPluginRoll('initiative');
        $GLOBALS['LRG_TEST_ROLL'] = []; $ok = true;
        for ($i = 0; $i < 50; $i++) { $v = fxPluginRoll('initiative'); $ok = $ok && is_int($v) && $v >= 1 && $v <= 100; }
        $GLOBALS['LRG_TEST_ROLL'] = $keep;
        return $a === 7 && $b === 93 && $ok;
    });

    // state messages: stored under the name in the PAYLOAD (the adapter sets HERIKA_NAME to a decoy for them), and always 'handled'
    $t->must('lrg_npcstate is handled before the lock', fxSendSnapshot($npc, $snap) === 'handled');
    $t->must('... and stored under the npc= of the payload, not under HERIKA_NAME', isset(fxDb()->t['lrg_npc_state'][$npc]) && !isset(fxDb()->t['lrg_npc_state']['Somebody Else']),
        fn() => 'rows: ' . implode(',', array_keys(fxDb()->t['lrg_npc_state'] ?? [])));
    $t->must('the stored snapshot reads back with a small _age', function () use ($npc) {
        $s = fxNpcState($npc);
        return is_array($s) && ($s['adult'] ?? '') === '1' && ($s['ltype'] ?? '') === 'inn' && (int) ($s['_age'] ?? 99) <= 2;
    });
    $t->mustCap('clock', 'time travel: after +100000 s a fresh snapshot is fresh again (every time read goes through lrgNow)', function () use ($npc, $snap) {
        fxAdvance(100000);
        $old = (int) (fxNpcState($npc)['_age'] ?? 0);
        fxSendSnapshot($npc, $snap);
        $new = (int) (fxNpcState($npc)['_age'] ?? 99999);
        return $old >= 100000 && $new <= 2 && (int) fxDb()->t['lrg_npc_state'][$npc]['updated_at'] === fxNow();
    }, fn() => '_age after the new snapshot: ' . (fxNpcState($npc)['_age'] ?? '?') . ', updated_at - now = ' . ((int) (fxDb()->t['lrg_npc_state'][$npc]['updated_at'] ?? 0) - fxNow()));

    $before = (int) @filesize(fxLogFile());
    $res = fxGameMessage(['lrg_log', fxNow(), 100000, FX_CTX . 'cid=sflow77;msg=flowtest: actors ok; undress=1']);
    clearstatcache();
    $tail = (string) @file_get_contents(fxLogFile(), false, null, $before);
    $t->must('lrg_log is handled and written as "[cid=..] GAME <msg>" (msg may contain ; and =)', $res === 'handled' && str_contains($tail, '[cid=sflow77] GAME flowtest: actors ok; undress=1'), fx_short($tail));

    $t->must('player speech passes the pre-lock entry point', fxGameMessage(['inputtext', fxNow(), 100000, 'Hello.'], $npc) === 'pass');
    $t->must('a funcret of a foreign command passes', fxGameMessage(['funcret', fxNow(), 100000, 'command@Follow@Player@ok'], $npc) === 'pass');
    $t->must('a request type the glue does not know passes', fxGameMessage(['bored', fxNow(), 100000, ''], $npc) === 'pass');

    $t->mustCap('memory', 'lrgMemGet() of an unknown NPC is []', fn() => fxMem('Nobody Flowtest') === []);
    $t->mustCap('memory', 'lrgMemSet() merges shallowly and a null value removes the key', function () use ($npc) {
        fxMemSet($npc, ['interest' => 'curious', 'initiative_at' => 5]);
        fxMemSet($npc, ['initiative_at' => 9, 'invite' => ['place' => 'quiet']]);
        $a = fxMem($npc);
        fxMemSet($npc, ['invite' => null]);
        $b = fxMem($npc);
        return ($a['interest'] ?? '') === 'curious' && ($a['initiative_at'] ?? 0) === 9 && ($a['invite']['place'] ?? '') === 'quiet' && !array_key_exists('invite', $b) && ($b['interest'] ?? '') === 'curious';
    }, fn() => json_encode(fxMem($npc)));
    $t->mustCap('memory', 'memory lives in table lrg_memory, keyed by npc_name', fn() => isset(fxDb()->t['lrg_memory'][$npc]), fn() => 'tables: ' . implode(',', array_keys(fxDb()->t)));

    $t->must('every DB call so far used a shape PROTOCOL.md section 5 allows', fxDb()->unhandled === [], fn() => implode(' | ', array_map('fx_short', fxDb()->unhandled)));
});
