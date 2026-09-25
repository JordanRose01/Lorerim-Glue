<?php
// PHASE 2 d63 - [pt17] QUEST TALK BY VOICE OUT OF THE BOX, and the two dry runs told apart.
//
// Playtest 2026-09-23 19:55-20:13: (1) the owner switched the DEVELOPER dry run on (it sat on page 1 as "Dry-run
// mode") when the menuless-questing one was meant, and seven correct requests were refused in silence; (2) menuless
// questing shipped inert (bMenuless=0, bCalibActive=0) so "turn it on" could never have finished by itself; (3) Legate
// Rikke lives in the Castle Dour map-table scene, and any scene at all switched the dialogue module off for her - no
// faction role, no locked facts. This scenario pins the shipped defaults, the wire keys the game now sends (ml is the
// EFFECTIVE state, cal= the learning counter, sq= / sqj= the scene's owning quest), the ambient-scene rule, and that a
// dry-run refusal is VOICED and names the switch.
require_once __DIR__ . '/../dlg_adapter.php';

/** settings.ini as [Section][key] => value (the same reader tools/test_mcm_wiring.php uses). */
function fx63_ini(string $file): array
{
    $ini = [];
    $section = '';
    foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') { continue; }
        if (preg_match('/^\[(.+)\]$/', $line, $m)) { $section = trim($m[1]); continue; }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) { $ini[$section][$m[1]] = trim($m[2]); }
    }
    return $ini;
}

fx_scenario('d63', '[pt17] out of the box: quest talk by voice ships ON (dry run until learned), the developer dry run ships OFF on the last page, ml is the effective state, an ambient scene keeps her facts on, a dry-run refusal is voiced', function (FxT $t) {
    fxDlgReset();
    $root = dirname(__DIR__, 3);
    $iniFile = $root . '/game/LoreRimGlue/MCM/Config/LoreRimGlue/settings.ini';
    $cfgFile = $root . '/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json';
    if (!is_file($iniFile) || !is_file($cfgFile)) { $t->pending('the shipped MCM files', 'settings.ini / config.json are not in the staged tree'); return; }

    // ---------------------------------------------------------------- 1. the shipped defaults
    $ini = fx63_ini($iniFile);
    // [pt19 v1.0 / S3.4, S10] the menuless dry run ships OFF and is never forced or auto-cleared (the hold switch and the
    // whole [Calib] section are gone: four passive rows, the route proven by the first real click)
    $t->must('settings.ini ships bMenuless=1, bDlgDryRun=0, and no bDlgDryRunHold (nothing clears or forces a dry run)',
        ($ini['Dialogue']['bMenuless'] ?? '') === '1' && ($ini['Dialogue']['bDlgDryRun'] ?? '') === '0' && !isset($ini['Dialogue']['bDlgDryRunHold']),
        json_encode($ini['Dialogue'] ?? []));
    $t->must('...no [Calib] section any more (the calibration is passive and has no switches)', !isset($ini['Calib']), json_encode($ini['Calib'] ?? []));
    $t->must('...bDriveSceneMenus=1, iSceneGate=1, bAutoAdvance=1, iKeyPushToTalk=29 (S10)',
        ($ini['Dialogue']['bDriveSceneMenus'] ?? '') === '1' && ($ini['Dialogue']['iSceneGate'] ?? '') === '1'
        && ($ini['Dialogue']['bAutoAdvance'] ?? '') === '1' && ($ini['Dialogue']['iKeyPushToTalk'] ?? '') === '29', json_encode($ini['Dialogue'] ?? []));
    $t->must('...bDryRun=0: the developer switch is OFF', ($ini['General']['bDryRun'] ?? '') === '0', json_encode($ini['General'] ?? []));
    $raw = (string) file_get_contents($cfgFile);
    $t->must('config.json is UTF-8 without a BOM and LF-ended (MCM Helper reads it as data)', strncmp($raw, "\xEF\xBB\xBF", 3) !== 0 && !str_contains($raw, "\r\n"));
    $cfg = json_decode($raw, true);
    $pages = is_array($cfg) ? array_column($cfg['pages'] ?? [], 'pageDisplayName') : [];
    $devPage = '';
    $devText = '';
    foreach ((array) ($cfg['pages'] ?? []) as $pg) {
        foreach ((array) ($pg['content'] ?? []) as $c) { if (($c['id'] ?? '') === 'bDryRun:General') { $devPage = (string) $pg['pageDisplayName']; $devText = (string) ($c['text'] ?? ''); } }
    }
    $t->must('the developer dry run is the LAST page\'s control, labelled DEV, never the first thing anyone meets',
        $devPage === 'Diagnostics' && end($pages) === 'Diagnostics' && str_starts_with($devText, 'DEV'), "$devPage / $devText");

    // ---------------------------------------------------------------- 2. the wire: ml (effective), cal, sq / sqj
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $a = fxDlgSay($npc, fxDlgSnap(['ml' => '0']), 'Hello.');
    $t->must('ml=0 on the snapshot reads back as 0', (int) lrgDlgMcm('ml', 1, $npc) === 0);
    fxDlgEvent($npc, 'facts', ['q' => '-', 'cal' => '3', 'dlg' => '0', 'sq' => '-', 'sqj' => '0', 'qst' => '-']);
    $t->must('cal=3 on the facts line reads back (the learning counter)', (int) lrgDlgMcm('cal', -1, $npc) === 3, (string) lrgDlgMcm('cal', -1, $npc));
    $t->must('...and the ml=0 wording says "still learning (3 of 4)" - four passive rows, the next conversation of any kind',
        str_contains(lrgDlgLearningText($npc), '3 of 4') && str_contains(lrgDlgLearningText($npc), 'any kind'), lrgDlgLearningText($npc));
    $mark = fxDlgLogMark();
    unset($GLOBALS['LRG_DLG_ML_SAID']);
    fxDlgSay($npc, fxDlgSnap(['ml' => '0']), 'I need a room.');
    $t->must('the log\'s ml=0 line says so too', (bool) preg_match('/ml=0: .*still learning.*3 of 4/', fxDlgLogFrom($mark)), fx_short(fxDlgLogFrom($mark), 300));
    $b = fxDlgSay($npc, fxDlgSnap(['ml' => '1']), 'Hello.');
    $t->must('snapshot ml=1 while the driver\'s own dlg=0 is fresh: the server reads the EFFECTIVE ml=0 (the forced dry run)',
        (int) lrgDlgMcm('ml', 1, $npc) === 0, json_encode(lrgDlgMcmFromSnapshot($npc)));
    fxDlgEvent($npc, 'facts', ['q' => '-', 'cal' => '10', 'dlg' => '1']);
    $c = fxDlgSay($npc, fxDlgSnap(['ml' => '1']), 'Hello.');
    $t->must('...dlg=1 (the gate is green and the dry run cleared): ml=1', (int) lrgDlgMcm('ml', 1, $npc) === 1);

    // ---------------------------------------------------------------- 3. the ambient scene: Legate Rikke at the map table
    fxDlgReset();
    $rikke = 'Legate Rikke';
    $q = 'CWObj,CW00A,CWReservations,CW,CW00SolitudeMapTableScene';
    fxDlgEvent($rikke, 'facts', ['q' => $q, 'cal' => '5', 'dlg' => '0', 'sq' => 'CW00SolitudeMapTableScene', 'sqj' => '0', 'qst' => 'CW00A:0,CW00SolitudeMapTableScene:10']);
    $r = fxDlgSay($rikke, fxDlgSnap(['scene' => '1', 'ml' => '0', 'fac' => 'CWImperialFaction,CWFieldCOFaction']), 'I want to join the Legion.');
    $turn = (array) ($r['turn'] ?? []);
    $t->must('Rikke in the map-table scene (snapshot scene=1, sq on the allow-list, sqj=0): the module stays ON, ambient=1',
        !empty($turn['on']) && !empty($turn['ambient']), json_encode(['on' => $turn['on'] ?? null, 'ambient' => $turn['ambient'] ?? null, 'why' => $turn['why'] ?? []]));
    $t->must('...she is the Legion\'s recruiter on that turn (the 20:12 turn had no faction= at all)',
        (($turn['faction']['role'] ?? '') === 'recruiter') && (($turn['faction']['asked'] ?? '') === 'legion'), json_encode($turn['faction'] ?? null));
    $t->must('...and her locked facts carry the faction line', in_array('faction', array_column((array) ($turn['locked'] ?? []), 'class'), true),
        json_encode(array_column((array) ($turn['locked'] ?? []), 'class')));
    $t->must('...no open was queued for her under ml=0 (a recruiter answers in her own dialogue): words and facts only', fxDlgQueue() === [], json_encode(fxDlgQueue()));
    $t->must('...TakeUpBusiness is not offered on the ambient turn (there is no list and no open)', !in_array(FX_DLG_ACT, $r['enabled'], true) || $turn['list'] === 'none', implode(',', $r['enabled']));
    // the safety kept: an unfinished objective on the owning quest, or a quest the INDEX knows as a journal quest, switches
    // her off as before ([pt19 v1.0 / S1.3, U8]: the glob is a shortcut; the index decides what "a quest scene" is)
    fxDlgEvent($rikke, 'facts', ['q' => $q, 'sq' => 'CW00SolitudeMapTableScene', 'sqj' => '1']);
    $r2 = fxDlgSay($rikke, fxDlgSnap(['scene' => '1', 'ml' => '0']), 'I want to join the Legion.');
    $t->must('sqj=1 (the scene\'s quest has an unfinished objective): off, as before', empty($r2['turn']['on']) && empty($r2['turn']['ambient']), json_encode($r2['turn']['why'] ?? []));
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => [fxDlgRow('Stay close to me.', ['quest' => 'MQ101', 'journal' => 1, 'toplevel' => 0])], 'layers' => []];
    fxDlgEvent($rikke, 'facts', ['q' => $q, 'sq' => 'MQ101', 'sqj' => '0']);
    $r3 = fxDlgSay($rikke, fxDlgSnap(['scene' => '1', 'ml' => '0']), 'I want to join the Legion.');
    $t->must('a scene owned by MQ101 (journal rows in the index, no glob): off, as before', empty($r3['turn']['on']), json_encode($r3['turn']['why'] ?? []));
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => [], 'layers' => []];
    fxDlgEvent($rikke, 'facts', ['q' => $q, 'sq' => 'DialogueWhiterunBanneredMareScene3', 'sqj' => '0']);
    $r3b = fxDlgSay($rikke, fxDlgSnap(['scene' => '1', 'ml' => '0']), 'Nice weather.');
    $t->must('a scene no glob names and the index does not know as a quest (Hulda\'s tavern patter): ambient, on',
        !empty($r3b['turn']['on']) && !empty($r3b['turn']['ambient']), json_encode($r3b['turn']['why'] ?? []));
    fxDlgEvent($rikke, 'facts', ['q' => $q, 'sq' => '-', 'sqj' => '0']);
    $r4 = fxDlgSay($rikke, fxDlgSnap(['scene' => '1', 'ml' => '0']), 'I want to join the Legion.');
    $t->must('an unknown owner (sq=-): off, as before', empty($r4['turn']['on']), json_encode($r4['turn']['why'] ?? []));
    $r5 = fxDlgSay($rikke, fxDlgSnap(['scene' => '0', 'ml' => '0']), 'I want to join the Legion.');
    $t->must('and with no scene at all nothing changed: on=1, ambient=0', !empty($r5['turn']['on']) && empty($r5['turn']['ambient']));

    // ---------------------------------------------------------------- 4. a dry-run refusal is voiced and names the switch
    fxReset();
    $c = fxCast('innkeeper'); $n2 = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($n2, $alone, 10);
    fxWarm($n2, $alone);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $v = fxFuncretTurn($n2, $w[0] ?? '', 'Error: dry-run mode, nothing changed');
    $t->must('a dry-run funcret is VOICED, and the cue names the LoreRim Glue dry-run switch and the Diagnostics page',
        $v->mode() === 'voiced' && str_contains($v->cue, 'dry-run switch') && str_contains($v->cue, 'Diagnostics'), fx_short($v->cue, 400));
});
