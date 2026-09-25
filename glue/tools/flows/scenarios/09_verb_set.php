<?php
// 09 - R9 (9) / R1 / R8: every verb of PROTOCOL.md sections 2 + 3 maps to the exact parameter string the game parses.
// Item texts are the catalog's own keys (6.5). Shapes are matched right after the ok=1;cid;npc prefix, to the end of the param.
fx_scenario('09', 'verb set: every verb -> the exact parameter string (start, goto, pace, hold, climax, pullout, winddown, furniture, lead, clothing)', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);

    // ---- start
    $sc = fxBeginScene($t, $npc, $alone, ['nearf' => 'doublebed,chair,bench']);
    $in = $sc['snap']; $cid = $sc['cid'];
    $t->must('start: one line for ' . FX_ACT_START, fxCode($sc['wire']) === FX_ACT_START);
    $t->mustCap('actions_v7', 'start: prefix ok=1;cid=<cid>;npc=<name>', fn() => fxPrefixOk($sc['wire'], $npc), fn() => fxParam($sc['wire']));
    $t->mustCap('actions_v7', 'start: scene=<id>;undress=<0|1>;furn=<type or empty>;fscene=<id or empty>;maxwit=<n>;folok=<0|1>',
        fn() => fxShape($sc['wire'], 'scene=[^;]*;undress=[01];furn=[a-z]*;fscene=[^;]*;maxwit=\d+;folok=[01]'), fn() => fxParam($sc['wire']));
    $kv = fxParamKv($sc['wire']);
    $t->must('start: undress follows scene_start.use_ostim_undress_rules', ($kv['undress'] ?? '') === (fxCfg('scene_start.use_ostim_undress_rules', true) ? '1' : '0'));
    $t->must('start: the standing fallback scene is always named and gentle', in_array(fxSceneTier($kv['scene'] ?? ''), ['kissing', 'affection'], true), ($kv['scene'] ?? '') . ' = ' . (fxSceneTier($kv['scene'] ?? '') ?? 'unknown'));
    $t->mustCap('actions_v7', 'start: no furniture in reach (snapshot nearf empty) -> furn and fscene empty', fn() => ($kv['furn'] ?? 'x') === '' && ($kv['fscene'] ?? 'x') === '', fn() => fxParam($sc['wire']));

    // start next to a bed: furn + fscene come as a pair, and the furniture scene is gentle too
    $t->mustCap(['actions_v7', 'index_v3'], 'start with a bed in reach: furn/fscene are both set or both empty; a set fscene is gentle and belongs to that furniture', function () use ($c) {
        $keepDb = $GLOBALS['db']; $GLOBALS['db'] = new FxDb();
        $npc2 = fxCast('patron')['name'];
        $s = fxSnap(['nearf' => 'doublebed,chair', 'bedown' => 'player', 'prent' => '1'] + fxCast('patron')['snap']);
        fxSetScore($npc2, $s, 10);
        fxWarm($npc2, $s); // [0.3] 11.2
        fxSay($npc2, $s, 'Hello.');
        $w = fxLlm($npc2, FX_ACT_START, 'Player');
        $GLOBALS['db'] = $keepDb;
        $k = fxParamKv($w[0] ?? '');
        if (count($w) !== 1 || (($k['furn'] ?? '') === '') !== (($k['fscene'] ?? '') === '')) { throw new RuntimeException(fxParam($w[0] ?? '(dropped)')); }
        if (($k['furn'] ?? '') === '') { return true; } // prefer_furniture=never, or no gentle start scene for that bed type: standing start
        return in_array($k['furn'], ['doublebed', 'chair'], true) && in_array(fxSceneTier($k['fscene']), ['kissing', 'affection'], true)
            && in_array(fxSceneTier($k['scene'] ?? ''), ['kissing', 'affection'], true);
    });

    // ---- gentle tier: what is NOT available yet
    $r = fxSay($npc, $in, 'Hello.');
    $t->mustCap('actions_v7', 'climax is not available below the sensual tier: dropped', fn() => fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'climax')));
    $t->mustCap('actions_v7', 'lead=auto is not available before the ladder reached scene_progression.auto_mode_min_tier: dropped', fn() => fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'auto')));

    // ---- climb, then the whole table
    [$sensual, $sexual] = fxClimbToTop($npc, $sc['start'], $cid, ['nearf' => 'doublebed,chair,bench']);
    if ($sexual === '') { $t->pending('verb table at the top tier', 'the installed packs have no sexual-tier scene for this pair'); return; }
    $r = fxSay($npc, $in, 'Hello.');
    $t->must('after the game reported a sensual and then a sexual scene: reached = ceiling = 4', (int) ($r->progress()['reached'] ?? -1) === 4 && (int) ($r->progress()['ceiling'] ?? -1) === 4, json_encode($r->progress()));
    $p1 = $r->options()['P1']['id'] ?? '';
    $linger = max(5, min(120, (int) fxCfg('winddown.linger_seconds', 20)));
    $table = [ // [item the LLM writes, regex of the shape, needs v7?, must?]
        ['P1',        'do=goto;scene=' . preg_quote($p1, '/') . '(;warp=1)?', false, true],
        ['faster',    'do=faster',  false, true],
        ['slower',    'do=slower',  false, true],
        ['hold',      'do=hold',    false, true],
        ['release',   'do=release', false, true],
        ['stop',      'do=stop',    false, true],
        ['climax',    'do=climax;who=(npc|player|both)', true, true],
        ['pull out',  'do=pullout', true, true],
        ['wind down', 'do=winddown;scene=[^;]*;warp=[01];linger=' . $linger, true, true],
        ['auto',      'do=lead;who=auto', true, true],
        [$npc . ' leads', 'do=lead;who=npc', true, true],
        ['player leads',  'do=lead;who=player', true, true],
        ['speed 2',   'do=speed;speed=2', true, false],
    ];
    foreach ($table as [$item, $shape, $v7, $must]) {
        $check = function () use ($npc, $item, $shape) {
            $w = fxLlm($npc, FX_ACT_CONTROL, $item);
            if (count($w) !== 1 || fxCode($w[0]) !== FX_ACT_CONTROL || !fxShape($w[0], $shape)) { throw new RuntimeException($w ? fxParam($w[0]) : 'dropped'); }
            return true;
        };
        $name = "ChangeIntimacy \"$item\" -> $shape";
        if ($must) { $t->mustCap($v7 ? ['actions_v7'] : [], $name, $check); } else { $t->shouldCap($v7 ? ['actions_v7'] : [], $name, $check); }
    }
    $t->mustCap('actions_v7', 'every rewritten param carries npc=<name> (funcret attribution) and the turn\'s cid', function () use ($npc, $r) {
        foreach (['faster', 'stop', 'climax', 'wind down'] as $item) {
            $w = fxLlm($npc, FX_ACT_CONTROL, $item);
            if (!$w || !fxPrefixOk($w[0], $npc) || (fxParamKv($w[0])['cid'] ?? '') !== ($r->turn['cid'] ?? '-')) { throw new RuntimeException($item . ': ' . fxParam($w[0] ?? 'dropped')); }
        }
        return true;
    });
    // the two lead keys must be unambiguous: a NAME, never a second-person pronoun whose meaning
    // depends on who is speaking (the model echoes the player's words)
    $t->mustCap('actions_v7', 'the two lead keys are two different leaders', fn() => (fxParamKv(fxLlm($npc, FX_ACT_CONTROL, $npc . ' leads')[0] ?? '')['who'] ?? 'a') !== (fxParamKv(fxLlm($npc, FX_ACT_CONTROL, 'player leads')[0] ?? '')['who'] ?? 'a'));
    $t->mustCap('actions_v7', 'a bare "i lead" / "you lead" is dropped - it would invert the player\'s meaning',
        fn() => fxLlm($npc, FX_ACT_CONTROL, 'i lead') === [] && fxLlm($npc, FX_ACT_CONTROL, 'you lead') === []);
    $t->mustCap('actions_v7', 'the scene notes offer NAME keys for the lead and no second-person phrasing',
        fn() => str_contains($r->volatile, $npc . ' leads') && !preg_match('/\byou lead\b|\bi lead\b/i', $r->volatile));
    // prompt and resolver must agree: whatever key the notes offer her has to DO something when she writes it back
    $t->shouldCap('actions_v7', 'every item key the scene notes offer resolves to a command (no option that silently does nothing)', function () use ($npc, $r) {
        $keys = fxOfferedItemKeys($r->volatile);
        if (!$keys) { throw new FxPending('the notes layout was not recognised: adjust fxOfferedItemKeys() in adapter.php'); }
        $dead = array_values(array_filter($keys, fn($k) => count(fxLlm($npc, FX_ACT_CONTROL, $k)) !== 1));
        if ($dead) { throw new RuntimeException('offered but dropped: ' . implode(' | ', $dead)); }
        foreach ($keys as $k) { // the notes' own lead keys must land on the leader they name
            if (!preg_match('/\bleads?\b/i', $k)) { continue; }
            $who = fxParamKv(fxLlm($npc, FX_ACT_CONTROL, $k)[0])['who'] ?? '';
            $want = stripos($k, 'player') !== false ? 'player' : 'npc';
            if ($who !== $want) { throw new RuntimeException("\"$k\" -> who=$who, expected $want"); }
        }
        return count($keys) >= 8;
    });
    $t->mustCap('actions_v7', 'wind-down target: empty or a gentle scene (afterglow), never a step up', function () use ($npc) {
        $id = fxParamKv(fxLlm($npc, FX_ACT_CONTROL, 'wind down')[0] ?? '')['scene'] ?? '';
        return $id === '' || fxIsGentle(fxSceneTier($id));
    });

    // ---- furniture: only real, nearby, in-ceiling furniture; the server ALWAYS names the scene
    $t->mustCap(['actions_v7', 'index_v3', 'modes'], 'furniture: a label from this turn\'s furn_options -> do=furniture;furn=<type>;scene=<non-empty id>', function () use ($npc, $r) {
        $opts = (array) ($r->turn['furn_options'] ?? []);
        if (!$opts) { throw new FxPending('no furniture option under the ceiling for nearf=doublebed,chair,bench with the installed packs'); }
        $o = reset($opts);
        $w = fxLlm($npc, FX_ACT_CONTROL, (string) $o['label']);
        $k = fxParamKv($w[0] ?? '');
        return count($w) === 1 && fxShape($w[0], 'do=furniture;furn=[a-z]+;scene=[^;]+') && $k['furn'] === $o['furn'] && $k['scene'] === $o['scene'] && fxTierNum(fxSceneTier($k['scene'])) <= 4;
    });
    $t->mustCap(['actions_v7', 'index_v3'], 'furniture that is not in reach is dropped', function () use ($npc, $in, $cid, $sexual) {
        fxSendScene($npc, 'change', ['scene' => $sexual, 'cid' => $cid, 'nearf' => '']);
        fxSay($npc, $in, 'Hello.');
        $w = fxLlm($npc, FX_ACT_CONTROL, 'bed');
        return $w === [] || !str_contains(fxParam($w[0]), 'do=furniture'); // "bed" may still resolve as a position word; it must not become a furniture move
    });

    // ---- clothing, in the scene
    $cloth = [
        ['undress',       'do=undress;who=npc;part=all',    false],
        ['undress both',  'do=undress;who=both;part=all',   false],
        ['dress',         'do=dress;who=npc;part=all',      false],
        ['dress both',    'do=dress;who=both;part=all',     false],
        ['undress you',   'do=undress;who=player;part=all', true],
        ['dress you',     'do=dress;who=player;part=all',   true],
        ['undress body',  'do=undress;who=npc;part=body',   true],
        ['undress head',  'do=undress;who=npc;part=head',   true],
        ['undress hands', 'do=undress;who=npc;part=hands',  true],
        ['undress feet',  'do=undress;who=npc;part=feet',   true],
        ['dress feet',    'do=dress;who=npc;part=feet',     true],
        ['undress both hands', 'do=undress;who=both;part=hands', true],
    ];
    fxSay($npc, $in, 'Hello.');
    foreach ($cloth as [$item, $shape, $new]) {
        $t->mustCap('actions_v7', "ChangeClothing \"$item\" (in a scene) -> $shape", function () use ($npc, $item, $shape) {
            $w = fxLlm($npc, FX_ACT_CLOTHING, $item);
            if (count($w) !== 1 || fxCode($w[0]) !== FX_ACT_CLOTHING || !fxShape($w[0], $shape)) { throw new RuntimeException($w ? fxParam($w[0]) : 'dropped'); }
            return true;
        });
    }
    $t->must('[v1 wire] undress / dress still start with do=<verb>;who=<npc|both>', (function () use ($npc) {
        $a = fxLlm($npc, FX_ACT_CLOTHING, 'undress'); $b = fxLlm($npc, FX_NAME[FX_ACT_CLOTHING], '{"item":"Dress both"}');
        return count($a) === 1 && str_contains($a[0], ';do=undress;who=npc') && count($b) === 1 && str_contains($b[0], ';do=dress;who=both');
    })());
    $t->must('unknown clothing text is dropped', fxLlm($npc, FX_ACT_CLOTHING, 'armor') === [] && fxLlm($npc, FX_ACT_CLOTHING, '') === []);

    // ---- clothing outside a scene carries the witness limits
    fxSendScene($npc, 'end', ['scene' => $sexual, 'cid' => $cid]);
    // [0.3 / 11.2] after a scene her own unasked-for undressing is held back for post_scene_cooldown_seconds
    fxAdvance((int) fxCfg('start.post_scene_cooldown_seconds', 300) + 30);
    fxWarm($npc, $alone);
    $r = fxSay($npc, $alone, 'Hello.');
    $w = fxLlm($npc, FX_ACT_CLOTHING, 'undress');
    $t->mustCap('actions_v7', 'outside a scene: do=undress;who=npc;part=all;maxwit=<n>;folok=<0|1>', fn() => count($w) === 1 && fxShape($w[0], 'do=undress;who=npc;part=all;maxwit=\d+;folok=[01]'), fn() => implode(' ', $w));
    $t->must('[v1 wire] outside a scene the limits are attached', count($w) === 1 && (bool) preg_match('/;maxwit=\d+;folok=[01]' . FX_RE_DECOR . '$/', fxParam($w[0])), implode(' ', $w));
    $t->must('outside a scene ChangeIntimacy verbs are dropped', fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'faster')) && fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'stop')));
});
