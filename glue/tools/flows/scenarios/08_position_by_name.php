<?php
// 08 - R9 (8) / R1 "if I say a position it should go to that position": reachable -> goto; above the ceiling -> "too soon"
// guidance and NO command; nonsense -> dropped. Every position word is discovered from the plugin's own config and index.
fx_scenario('08', 'position by name: reachable -> goto, above the ceiling -> too soon + no command, nonsense -> dropped', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];

    $r = fxSay($npc, $in, 'Hello.');
    $ceiling = (int) ($r->progress()['ceiling'] ?? 4);
    $words = fxWords($sc['start'], $ceiling);
    $t->must('the index knows plain position words that lead somewhere under the current ceiling', count($words) > 0, 'ceiling=' . $ceiling);
    // [0.3 / G5] the notes no longer list example position words: they list the ACTS that are open now,
    // by a stable act key, which is what the player and the model both name
    $t->should('the scene notes name the acts that are open right now', !Fx::has('acts') || count($r->acts()) > 0, json_encode(array_keys($r->acts())));

    $tierOf = function (array $wire): int { return fxTierNum(fxSceneTier(fxParamKv($wire[0] ?? '')['scene'] ?? '')); };
    foreach (array_slice(array_reverse($words), 0, 3) as $w) { // the gentlest three
        $wire = fxLlm($npc, FX_ACT_CONTROL, $w);
        $t->must("reachable word -> exactly one do=goto;scene=<id> at or below the ceiling [$w]", count($wire) === 1 && fxShape($wire[0], 'do=goto;scene=[^;]+')
            && $tierOf($wire) >= 0 && $tierOf($wire) <= $ceiling, implode(' ', $wire));
    }
    $w = (string) end($words);
    $wire = fxLlm($npc, FX_NAME[FX_ACT_CONTROL], json_encode(['target' => 'Player', 'item' => "let us move to $w now"]));
    $t->must('a whole sentence, display name + JSON parameter: still one goto', count($wire) === 1 && str_contains($wire[0], '|' . FX_ACT_CONTROL . '@ok=1;') && str_contains($wire[0], ';do=goto;scene='), implode(' ', $wire));
    $t->mustCap('actions_v7', 'the rewritten command carries ok=1, the server cid and npc=', fn() => fxPrefixOk($wire[0] ?? '', $npc) && (fxParamKv($wire[0])['cid'] ?? '') === ($r->turn['cid'] ?? '-'), fn() => fxParam($wire[0] ?? ''));
    // [0.3 / G5.5] a move SHE chose never warps: it carries nowarp=1 and the game refuses instead of
    // fade-jumping. warp=1 is reserved for a position the PLAYER asked for by name.
    $t->must('a move she chose herself carries nowarp=1 and never warp=1', (function () use ($wire) {
        $kv = fxParamKv($wire[0] ?? '');
        return !isset($kv['warp']) && (!Fx::has('decorate') || ($kv['nowarp'] ?? '') === '1');
    })(), implode(' ', $wire));
    $r = fxSay($npc, $in, 'Hello.');
    $key = array_key_first($r->options());
    $wire = fxLlm($npc, FX_ACT_CONTROL, (string) $key);
    $t->must('an option key still works: P-key -> goto that option', count($wire) === 1 && str_contains(fxParam($wire[0]), ';do=goto;scene=' . $r->options()[$key]['id']), implode(' ', $wire));
    $wire = fxLlm($npc, FX_ACT_CONTROL, $r->options()[$key]['id']);
    $t->must('echoing the scene id instead of the key works too', count($wire) === 1 && str_contains(fxParam($wire[0]), ';do=goto;scene=' . $r->options()[$key]['id']));

    // above the ceiling
    $soon = fxTooSoonWord($sc['start'], $ceiling);
    if ($soon === '') {
        $t->pending('above the ceiling -> no command', 'no configured position word resolves only above tier ' . $ceiling . ' for this pair');
    } else {
        $t->must('above the ceiling -> NO command reaches the game', fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, $soon)) && fxNothingSent(fxLlm($npc, FX_NAME[FX_ACT_CONTROL], json_encode(['item' => $soon]))));
        // [0.3 / 4.4.3] the ambiguous "anything beyond foreplay is too soon" sentence was replaced by two
        // unambiguous ones about what SHE starts; the limit is still stated, in the new wording
        $t->should('... and the notes state the limit on what she starts herself', (bool) preg_match('/\bnot yet\b|' . substr(FX_RE_TOO_SOON, 1, -2) . '/i', $r->volatile), fx_short($r->volatile, 300));
        $t->must('the resolver marks it too_soon rather than unknown', isset(fxResolveControl($soon, $r->options(), $r->turn['ctx'])['too_soon']));
        // the same word is fine once the ladder got there
        [$sensual, $sexual] = fxClimbToTop($npc, $sc['start'], $sc['cid']);
        if ($sexual === '') { $t->pending('the same word works once the ladder reached the top', 'no sexual-tier scene for this pair in the installed packs'); }
        else {
            $top = fxSay($npc, $in, 'Hello.');
            $wire = fxLlm($npc, FX_ACT_CONTROL, $soon);
            $t->must('the same word works once the ladder reached the top tier', (int) $top->progress()['ceiling'] === 4 && count($wire) === 1 && str_contains($wire[0], ';do=goto;scene='), json_encode($top->progress()) . ' ' . implode(' ', $wire));
        }
    }

    // nonsense
    $r = fxSay($npc, $in, 'Hello.');
    foreach (['hanging from the chandelier', 'qwertyuiop', '{"item":""}', '', 'P99'] as $junk) {
        $t->must('nonsense is dropped, the NPC just answers in words [' . ($junk === '' ? 'empty item' : $junk) . ']', fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, $junk)));
    }
    $t->must('"hold me" is a position request, "hold" is the climax key', (fxResolveControl('hold', [], null)['do'] ?? '') === 'hold' && (fxResolveControl('hold me', [], null)['do'] ?? '') !== 'hold');
});
