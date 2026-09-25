<?php
/**
 * [pt19h-harness] LoreRim Glue - the questline harness's EXTENDED mode (tools/test_questline.php --extended) and its fixture
 * builder (--build-extended=<research/pt19x-coverage>). Loaded by tools/test_questline.php only; every seam it uses (qlLayer,
 * qlSeam, qlWorld, qlFacts, qlFast, qlOpenWorld, qlLlm, qlChk, QlIndex) is that file's, so the extended beats run through
 * exactly the paths the standing fixture runs through (spec 3.4: the fast path, then her T-key on the target).
 *
 * The fixture (tools/fixtures/lrg_questline_extended.json) = the coverage team's research/pt19x-coverage/lrg_questline_extended.json
 * (2,005 beats in the spec 3.3 format, its quarantine list kept APART) MERGED with research/pt19x-coverage/brotherhood.verified.json
 * (the Dark Brotherhood, converted to the same beat shape). The Brotherhood file reproduces no line text (its content policy):
 * its layers name info_keys and its probes are templates over the index text ("not now, <index txt> later"), so the converted
 * beats keep them that way and this mode resolves them from the index at run time.
 *
 * What the mode asserts and what it only reports (the extended legend):
 *  - `never` (green when the coverage team measured it) and `not_target` (on the target): the FAST PATH clicks nothing - a
 *    click fails the run. Two clicks are no defect and are counted apart: he said ANOTHER listed line word for word (that
 *    line is clicked), and the effect-free breath on an unscripted single (S4.6) when his words do not refuse it.
 *  - lrgDlgWillEmit === (a pick was emitted) over every gate decision (as the standing run).
 *  - `never_red` (clicked when the coverage team measured it): REPORTED with its gap id - still red / now green - never asserted:
 *    these turn green as the pt19h fixers land.
 *  - every `say` / `say_model` / `misses` line: REPORTED by outcome - fast-path click / her T-key / she asks / nothing / wrong
 *    line / menu handed back - with the percentages, by source and by group.
 *  - the quarantine list: its beats' lines and `still_clicks` are reported in their own table, never asserted.
 *  - near-misses that are not also `never` rows: reported.
 * Her T-key on a must-not-click line (the LLM path, which needs the model to press the key) is reported beside the fast path.
 *
 * [pt19h-harness r2] (the usefulness review):
 *  - a live_text is the TARGET's text only when the target row carries a token the engine fills (or IS the row's text); any other one is
 *    moved to live_sibling and shown on the sibling whose tokens it fills (qlxLiveRule / qlxLiveSibling; six Companions declines, P1);
 *  - an alias the fixture gives no live text for is rendered neutrally (qlShown), and an alias-tag target's lines are kept out of every
 *    tally, counted apart (P4);
 *  - a say line that clicks ANOTHER listed line his words name is `sibling` (a fixture reading), not `wrong`; every WRONG must-resolve row
 *    is listed by line;
 *  - THE BASELINE (P3): tools/fixtures/lrg_questline_extended_baseline.json (measured on the 06:00 code of 2026-09-25) or --ext-baseline=<an
 *    earlier --ext-out or baseline>: every line whose outcome got WORSE (fast > her T-key > she asks > menu handed back > nothing > names a
 *    sibling > wrong line) is listed by group and gap, never_red rows green there and red now are listed, and a VERBATIM or first-evening
 *    line getting worse FAILS the run - unless it now does what its via names, a protected target now asks first (the safety rules allow
 *    it), the baseline's `accepted` names the change, or the target is an alias-tag one. --ext-baseline-out=<file> writes a new baseline.
 */

const QLX_TEMPLATE = '<index txt>';
// [pt19h-harness] fixture corrections the builder applies (each recorded in the fixture's `corrections` with its evidence)
const QLX_CORRECTIONS = [
    ['beat' => 'DLC1VQ00.durak.signup', 'quarantine' => true, 'move' => 'never->say', 't' => 'where do I sign up to kill vampires', 'via' => 'explicit',
        'why' => 'a paraphrase of the target line itself ("Killing vampires? Where do I sign up?"); research/pt19h-measure.md G18: it now clicks Durak\'s line EXPLICIT - "promote it to say"'],
    ['beat' => 'DLC1VQ04.serana.soultrap', 'move' => 'never->not_target', 't' => "I'll become a vampire",
        'why' => 'it names the SIBLING "I don\'t see another way. I\'ll become a vampire." (003C1B); research/pt19h-measure.md G10: "I\'ll become a vampire" now clicks that line, the intended fix - it must not click the soul-trap target'],
    ['beat' => 'TGR.vex.burglary.ask', 'move' => 'never->not_target', 't' => 'tell me about the heist jobs',
        'why' => 'it names the sibling "Do you have any heist jobs?" on Vex\'s job list; clicking that line is what he asked for - it must not click the burglary target'],
    ['beat' => 'TGR.vex.shill.ask', 'move' => 'never->not_target', 't' => 'tell me about the heist jobs',
        'why' => 'it names the sibling "Do you have any heist jobs?" on Vex\'s job list; clicking that line is what he asked for - it must not click the shill target'],
    ['beat' => 'TGR.vex.sweep.ask', 'move' => 'never->not_target', 't' => 'tell me about the heist jobs',
        'why' => 'it names the sibling "Do you have any heist jobs?" on Vex\'s job list; clicking that line is what he asked for - it must not click the sweep target'],
    ['beat' => 'TGR.vex.heist.ask', 'move' => 'never->not_target', 't' => 'tell me about the sweep jobs',
        'why' => 'it names the sibling "Do you have any sweep jobs?" on Vex\'s job list; clicking that line is what he asked for - it must not click the heist target'],
    // [pt19h-harness r2] the usefulness review's P2
    ['beat' => 'MQ05.neloth.anotherplace', 'move' => 'never->not_target', 't' => 'I learned a new word',
        'why' => 'it names the plain SIBLING "I learned the second Word of the Bend Will Shout." (dragonborn.esm:01D9A9: scripted 0, crit 0, cost 0, no goodbye) '
            . 'on the same list - clicking that is what he said; the row came from the beat\'s near_miss, which says only "must not click the TARGET" '
            . '("I found myself in another place.", the scripted goodbye 01D9AB). The same case as the five never->not_target rows above'],
    // [pt19h-harness r2] the usefulness review's P1: six Companions radiant DECLINE beats carry the ACCEPT line's live text (a copy slip of the
    // coverage fixture). Their target rows carry no token ("I'd rather not take that job." etc.), so that text is no text of the target: it is
    // the filled accept line on the same list ("I can clear out <Alias=AnimalDen> in <Alias=DenHold>." = "I can clear out Fallowstone Cave in
    // the Rift."). It is moved to `live_sibling`: the run shows it on the sibling whose tokens it fills, and the target shows its own text
    ['beat' => 'CR01.decline', 'move' => 'live_text->live_sibling', 't' => 'I can kill a Sabre Cat in Rorikstead.',
        'why' => 'target row "I don\'t want that job." has no token; the text is the filled accept line "I can kill a <Alias=Beast> in <Alias=VictimHometown>." (CR01.accept\'s own live_text)'],
    ['beat' => 'CR02.decline', 'move' => 'live_text->live_sibling', 't' => 'I can clear out Fallowstone Cave in the Rift.',
        'why' => 'target row "I\'d rather not take that job." has no token; the text is the filled accept line "I can clear out <Alias=AnimalDen> in <Alias=DenHold>." (CR02.accept\'s own live_text)'],
    ['beat' => 'CR04.decline', 'move' => 'live_text->live_sibling', 't' => 'I can intimidate Faendal in Riverwood.',
        'why' => 'target row "I\'d rather not do that." has no token; the text is the filled accept line "I can intimidate <Alias=Brute> in <Alias=BruteTown>." (CR04.accept\'s own live_text)'],
    ['beat' => 'CR05.decline', 'move' => 'live_text->live_sibling', 't' => 'I can clear out Halted Stream Camp in Whiterun Hold.',
        'why' => 'target row "I\'d rather not handle that." has no token; the text is the filled accept line "I can clear out <Alias=Location> in <Alias=LocationHold>." (CR05.accept\'s own live_text)'],
    ['beat' => 'CR06.decline', 'move' => 'live_text->live_sibling', 't' => 'I can get the Gold Ruby Necklace from Bleakwind Bluff in Whiterun Hold.',
        'why' => 'target row "Let\'s have somebody else do that." has no token; the text is the filled accept line "I can get the <Alias=Gewgaw> from <Alias=Location> in <Alias=LocationHold>." (quarantined CR06.accept\'s own live_text)'],
    ['beat' => 'CR07.decline', 'move' => 'live_text->live_sibling', 't' => 'I can kill the escaped prisioner in The Reach.',
        'why' => 'target row "I\'d rather not get involved." has no token; the text is the filled accept line "I can kill the escaped prisioner in <Alias=LocationHold>." (quarantined CR07.accept\'s own live_text)'],
];

// ================================================================== the builder
/** Merge the coverage team's extended fixture and the Brotherhood file into ONE fixture; write it one beat per line. */
function qlxBuild(string $cov, string $out): int
{
    $fE = rtrim($cov, '/') . '/lrg_questline_extended.json';
    $fB = rtrim($cov, '/') . '/brotherhood.verified.json';
    $E = json_decode((string) @file_get_contents($fE), true);
    $BH = json_decode((string) @file_get_contents($fB), true);
    if (!is_array($E) || ($E['_'] ?? '') !== 'lrg_questline') { fwrite(STDERR, "no coverage fixture at $fE\n"); return 1; }
    if (!is_array($BH) || empty($BH['quests'])) { fwrite(STDERR, "no Brotherhood file at $fB\n"); return 1; }
    $hash = (string) (($BH['index'] ?? [])['hash'] ?? '');
    if (!preg_match('/hash ([0-9a-f]{32})/', (string) (($E['built'] ?? [])['index'] ?? ''), $m) || $m[1] !== $hash) {
        fwrite(STDERR, "the two files were measured on different indexes (" . ($m[1] ?? '?') . " / $hash)\n"); return 1;
    }
    $ids = [];
    foreach ((array) $E['beats'] as $b) { $ids[(string) $b['id']] = 1; }
    foreach ((array) $E['quarantine'] as $q) { $ids[(string) $q['id']] = 1; }
    $beats = (array) $E['beats'];
    $quar = (array) $E['quarantine'];
    $noLine = [];
    $quests = array_flip((array) ($E['quests'] ?? []));
    $nB = 0;
    foreach ((array) $BH['quests'] as $qq) {
        foreach ((array) ($qq['beats'] ?? []) as $b) {
            if (isset($ids[(string) $b['id']])) { fwrite(STDERR, "id clash: {$b['id']}\n"); return 1; }
            $quests[(string) ($b['quest_row'] ?? $qq['quest'])] = 1;
            if (empty($b['fixture'])) {
                $noLine[] = ['id' => (string) $b['id'], 'group' => 'brotherhood', 'quest' => (string) ($b['quest_row'] ?? $qq['quest']),
                    'path' => (string) ($b['path'] ?? ''), 'why' => (string) ($b['why'] ?? '')];
                continue;
            }
            $beats[] = qlxFromBrotherhood($b, (string) $qq['quest']);
            $nB++;
        }
    }
    // the corrections (each with its evidence)
    $applied = [];
    foreach (QLX_CORRECTIONS as $c) {
        $list = !empty($c['quarantine']) ? $quar : $beats;
        foreach ($list as $i => $x) {
            $bb = !empty($c['quarantine']) ? ($x['beat'] ?? []) : $x;
            if ((string) ($bb['id'] ?? '') !== $c['beat']) { continue; }
            if ($c['move'] === 'live_text->live_sibling') {
                // [pt19h-harness r2] only when the fixture still carries exactly that text (a later coverage fixture that fixed it is left alone)
                if ((string) ($bb['live_text'] ?? '') !== $c['t']) { continue; }
                $bb['live_sibling'] = $bb['live_text'];
                unset($bb['live_text']);
                if (!empty($c['quarantine'])) { $quar[$i]['beat'] = $bb; } else { $beats[$i] = $bb; }
                $applied[] = $c;
                continue;
            }
            $k = array_search($c['t'], (array) ($bb['never'] ?? []), true);
            if ($k === false) { continue; }
            array_splice($bb['never'], (int) $k, 1);
            if ($c['move'] === 'never->say') { $bb['say'][] = ['t' => $c['t'], 'via' => $c['via'], 'src' => 'para', 'moved' => 'never->say (pt19h-harness)']; }
            else { $bb['not_target'][] = $c['t']; }
            if (!empty($c['quarantine'])) { $quar[$i]['beat'] = $bb; } else { $beats[$i] = $bb; }
            $applied[] = $c;
        }
    }
    // the measurer's per-gap sentences (research/pt19h-measure.json, when it sits beside the two files): the regression rows the
    // pt19h fixers close - a click that must stop (never_red, with the gap id) or a line that must resolve (resolve_red) - REPORTED
    $measure = qlxMeasureRows(rtrim($cov, '/') . '/pt19h-measure.json', $beats, $quar);
    $legend = (array) ($E['_legend'] ?? []);
    $legend['group=brotherhood'] = 'converted from brotherhood.verified.json (content policy: no line text): layer.entries are info_keys (layer.entries_by = info_key), '
        . 'and a `t` holding "' . QLX_TEMPLATE . '" is a template the harness fills from the target row at run time (verbatim; "not now, <index txt> later"; '
        . '"no, <index txt>"; "<index txt>?"; "wait, <index txt>?"; "is it true that <index txt>"). paraphrases with expect resolve / ask -> say, expect nothing -> '
        . 'misses; probes the verifier found clicking -> never_red (gap G1 / G5), the rest -> never; the near-miss -> near_miss (and never when it was green)';
    $legend['quarantine'] = 'reported by tools/test_questline.php --extended in its own table, never asserted (still_clicks: the lines the coverage re-check saw clicking)';
    $legend['no_line'] = 'Dark Brotherhood beats with no clickable player line (the verifier\'s `cannot` / `never_by_voice` / scene rows): counted with G17, not run';
    // [pt19h-review] set AFTER $legend is read from the coverage file (it was set before and overwritten, so the fixture lacked it)
    $legend['measure'] = 'rows added from research/pt19h-measure.json (src pt19h-measure): never_red rows of G1 G5 G9 G10 G11 (the fast path must stop '
        . 'clicking) and G13 G20 (path key: her T-key must stop releasing the line), resolve_red rows of G2 G3 G4 G6 G16 G18 (the line must be reached; '
        . 'reported by outcome). Brotherhood lines stay templates. G7 G8 G12 G14 G15 G17 G19 are list-level (no sentence) and are not rows here';
    $hdr = [
        '_' => 'lrg_questline', 'v' => 1,
        'ext' => 'pt19h-harness: research/pt19x-coverage/lrg_questline_extended.json MERGED with research/pt19x-coverage/brotherhood.verified.json',
        'index_hash' => $hash,
        'built' => ['at' => date('Y-m-d H:i'), 'by' => 'tools/test_questline.php --build-extended (pt19h-harness)',
            'inputs' => ['lrg_questline_extended.json' => md5_file($fE), 'brotherhood.verified.json' => md5_file($fB)],
            'coverage_built' => $E['built'] ?? null, 'brotherhood_verifier' => (($BH['verifier'] ?? [])['date'] ?? null)],
        'about' => 'run with: php tools/test_questline.php --extended [--quiet] [--ext-out=<json>] [--ext-baseline=<file> | --no-baseline] [--ext-baseline-out=<file>]. '
            . 'Beats ' . count((array) $E['beats']) . ' (coverage) + '
            . $nB . ' (Dark Brotherhood); quarantine ' . count($quar) . ' (reported apart); ' . count($noLine) . ' Brotherhood beats with no line',
        '_legend' => $legend,
        'quests' => array_keys($quests),
        'known' => new stdClass(),
        'corrections' => $applied,
        'measure_rows' => $measure,
        'spec_overlap' => $E['spec_overlap'] ?? [],
        'no_line' => $noLine,
    ];
    $F = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $s = rtrim(substr((string) json_encode($hdr, $F | JSON_PRETTY_PRINT), 0, -1)) . ",\n    \"beats\": [\n";
    $s .= implode(",\n", array_map(static fn($b) => json_encode($b, $F), $beats)) . "\n    ],\n    \"quarantine\": [\n";
    $s .= implode(",\n", array_map(static fn($b) => json_encode($b, $F), $quar)) . "\n    ]\n}\n";
    if (json_decode($s, true) === null) { fwrite(STDERR, "the merged fixture does not re-parse\n"); return 1; }
    file_put_contents($out, $s);
    printf("wrote %s: %d coverage beats + %d Dark Brotherhood beats, %d quarantine entries, %d Brotherhood no-line beats, %d correction(s); %d bytes\n",
        $out, count((array) $E['beats']), $nB, count($quar), count($noLine), count($applied), strlen($s));
    return 0;
}

/**
 * Attach the measurer's per-gap sentences to their beats (in place). Returns the counts. A Brotherhood beat keeps the content policy:
 * the measurer's generated probe shapes over its line ("not now, X later", "not yet, X", "I'm not sure, X", "no, X", "X?", "wait, X?",
 * "is it true that X") are written back as templates; any other sentence of it is its own words (a gap probe) and stays.
 */
function qlxMeasureRows(string $file, array &$beats, array &$quar): array
{
    $M = json_decode((string) @file_get_contents($file), true);
    if (!is_array($M) || empty($M['gaps'])) { return ['file' => 'absent']; }
    $never = ['G1' => 'fast', 'G5' => 'fast', 'G9' => 'fast', 'G10' => 'fast', 'G11' => 'fast', 'G13' => 'key', 'G20' => 'key'];
    $resolve = ['G2', 'G3', 'G4', 'G6', 'G16', 'G18'];
    $where = [];
    foreach ($beats as $i => $b) { $where[(string) $b['id']] = ['b', $i]; }
    foreach ($quar as $i => $q) { $where[(string) (($q['beat'] ?? [])['id'] ?? $q['id'])] = ['q', $i]; }
    $n = ['never_red' => 0, 'resolve_red' => 0, 'unmatched' => 0, 'templated' => 0, 'by_gap' => []];
    foreach ((array) $M['gaps'] as $g) {
        $gap = (string) ($g['gap'] ?? '');
        $isNever = isset($never[$gap]);
        if (!$isNever && !in_array($gap, $resolve, true)) { continue; }
        foreach ((array) ($g['cases'] ?? []) as $c) {
            $t = (string) ($c['say'] ?? '');
            if ($t === '' || $t[0] === '(') { continue; }
            $id = (string) preg_replace('/^[^:]*:/', '', (string) ($c['beat'] ?? ''));
            if (!isset($where[$id])) { $n['unmatched']++; continue; }
            [$set, $i] = $where[$id];
            $bb = $set === 'b' ? $beats[$i] : (array) $quar[$i]['beat'];
            if ((string) ($bb['group'] ?? '') === 'brotherhood') {
                $tpl = qlxTemplateOf($t);
                if ($tpl === null && $gap === 'G11' && str_ends_with($t, '?')) { $tpl = QLX_TEMPLATE . '?'; }
                if ($tpl !== null) { $t = $tpl; $n['templated']++; }
                // a sentence over the line that is no generated shape: the beat already carries the verifier's own row for it
                elseif (in_array($gap, ['G1', 'G5', 'G9', 'G10', 'G11'], true)) { $n['bh_skipped'] = ($n['bh_skipped'] ?? 0) + 1; continue; }
            }
            $row = ['t' => $t, 'gap' => $gap, 'src' => 'pt19h-measure', 'why' => fx_short((string) ($c['outcome'] ?? ''), 160)];
            if ($isNever) { $row['path'] = $never[$gap]; $key = 'never_red'; } else { $key = 'resolve_red'; }
            $dup = false;
            foreach ((array) ($bb[$key] ?? []) as $x) { if ((string) $x['t'] === $t && (string) ($x['gap'] ?? '') === $gap) { $dup = true; break; } }
            if ($dup) { continue; }
            $bb[$key][] = $row;
            $n[$key]++;
            $n['by_gap'][$gap] = ($n['by_gap'][$gap] ?? 0) + 1;
            if ($set === 'b') { $beats[$i] = $bb; } else { $quar[$i]['beat'] = $bb; }
        }
    }
    $n['file'] = 'research/pt19h-measure.json md5 ' . md5_file($file);
    return $n;
}

/** The measurer's generated probe shape of a sentence, as a template over the line ("not now, X later" -> "not now, <index txt> later"). */
function qlxTemplateOf(string $t): ?string
{
    $T = QLX_TEMPLATE;
    if (preg_match('/^not now, .+ later$/s', $t)) { return "not now, $T later"; }
    if (preg_match('/^not yet, /', $t)) { return "not yet, $T"; }
    if (preg_match("/^I'm not sure, /", $t)) { return "I'm not sure, $T"; }
    if (preg_match('/^no, /', $t)) { return "no, $T"; }
    if (preg_match('/^wait, .+\?$/s', $t)) { return "wait, $T?"; }
    if (preg_match('/^is it true that /', $t)) { return "is it true that $T"; }
    return null;
}

/** One Brotherhood beat (brotherhood.verified.json) in the extended fixture's beat shape - templates, never text. */
function qlxFromBrotherhood(array $b, string $qGroup): array
{
    $fx = (array) $b['fixture'];
    $lay = (array) $fx['layer'];
    $v = (array) ($b['verbatim'] ?? []);
    $say = [['t' => QLX_TEMPLATE, 'via' => (string) ($v['via'] ?? ''), 'fp' => $v['fp'] ?? null, 'src' => 'verbatim']];
    $never = [];
    $misses = [];
    foreach ((array) ($b['paraphrases'] ?? []) as $p) {
        if (in_array((string) ($p['expect'] ?? ''), ['resolve', 'ask', ''], true)) {
            $say[] = ['t' => (string) $p['say'], 'via' => (string) ($p['via'] ?? ''), 'fp' => $p['fp'] ?? null, 'src' => 'para'];
        } else {
            // a paraphrase of the target the verifier expected to do nothing is a coverage MISS, not a must-not-click row
            $misses[] = ['t' => (string) $p['say'], 'src' => 'para', 'what' => 'the verifier expected nothing (' . (string) ($p['sim'] ?? '') . ')'];
        }
    }
    foreach ((array) ($b['stt'] ?? []) as $p) {
        $say[] = ['t' => (string) $p['say'], 'via' => (string) ($p['via'] ?? ''), 'fp' => $p['fp'] ?? null, 'src' => 'stt'];
    }
    $red = [];
    $seen = [];
    foreach (array_merge((array) ($b['probes'] ?? []), array_map(static fn($x) => $x + ['never_red' => (string) ($x['gap'] ?? 'G1')], (array) ($b['never_red'] ?? []))) as $p) {
        $t = (string) ($p['say'] ?? ($p['say_ref'] ?? ''));
        if ($t === '' || isset($seen[$t])) { continue; }
        $seen[$t] = 1;
        if (!empty($p['never_red'])) {
            $red[] = ['t' => $t, 'gap' => (string) $p['never_red'], 'src' => 'probe:' . (string) ($p['probe'] ?? ''), 'why' => (string) ($p['sim'] ?? ($p['result'] ?? ''))];
        } else {
            $never[] = $t;
        }
    }
    $nm = (array) ($b['near_miss'] ?? []);
    $near = null;
    if ((string) ($nm['say'] ?? '') !== '') {
        $near = ['t' => (string) $nm['say'], 'why' => (string) ($nm['why'] ?? ''), 'now' => (string) ($nm['rescored'] ?? ($nm['sim'] ?? '')), 'ok' => $nm['ok'] ?? null];
        if (!empty($nm['ok']) && (string) ($nm['expect'] ?? '') === 'nothing' && !in_array($near['t'], $never, true)) { $never[] = $near['t']; }
    }
    $out = [
        'id' => (string) $b['id'], 'quest' => (string) ($b['quest_row'] ?? $qGroup), 'npc' => (string) ($b['npc'] ?? ''), 'group' => 'brotherhood',
        'quest_group' => $qGroup,
        'layer' => ['kind' => (string) ($lay['kind'] ?? ''), 'parent_info' => $lay['parent_info'] ?? null, 'form' => (string) ($lay['form'] ?? ''),
            'entries' => array_values((array) ($lay['entries'] ?? [])), 'entries_by' => 'info_key'],
        'target' => ['topic' => (string) (($fx['target'] ?? [])['topic'] ?? ''), 'info_key' => (string) (($fx['target'] ?? [])['info_key'] ?? '')],
        'line' => QLX_TEMPLATE, 'summary' => (string) ($b['summary'] ?? ''),
        'origin' => (string) ($fx['origin'] ?? 'glue'), 'sj' => (int) ($fx['sj'] ?? 0), 'scene' => (string) ($b['scene'] ?? '') === 'unproven' ? 'unproven' : false,
        'expect' => (string) ($fx['expect'] ?? ''), 'path' => (string) ($b['path'] ?? ''), 'class' => (string) ($b['class_v1'] ?? ''),
        'commit' => !empty($b['commit']),
        'flags' => ['scripted' => (int) ($b['scripted'] ?? 0), 'crit' => (int) ($b['crit'] ?? 0), 'cost' => (int) ($b['cost'] ?? 0),
            'kind' => (string) ($b['kind'] ?? ''), 'toplevel' => (int) ($b['toplevel'] ?? 0)] + (array) ($b['flags'] ?? []),
        'stage' => (string) ($b['stage'] ?? ''),
        'say' => $say,
    ];
    if ($never) { $out['never'] = array_values(array_unique($never)); }
    if ($misses) { $out['misses'] = $misses; }
    if ($red) { $out['never_red'] = $red; }
    if ($near) { $out['near_miss'] = $near; }
    if (!empty($b['harm_if_misclicked'])) { $out['harm'] = $b['harm_if_misclicked']; }
    if (!empty($b['leave'])) { $out['leave'] = $b['leave']; }
    $out['verify'] = (string) (($b['verify'] ?? [])['status'] ?? '');
    return $out;
}

// ================================================================== the run
final class Qlx
{
    public static array $say = [];        // [group, src, cat, outcome, fp, beat, t, mode]
    public static array $must = [];       // [cat, gap, verdict, beat, t, detail, protected, key, group, red, src, path]
    public static array $resolve = [];    // [gap, outcome, beat, t, set, how] - the measurer's must-resolve rows
    public static array $beatsFrom = ['index' => 0, 'list' => 0, 'none' => 0, 'error' => 0];
    public static array $errors = [];     // beat => why the list could not be built
    public static array $dump = [];       // --ext-out
    public static array $appended = [];   // beats whose target had to be appended to their own list
    // [pt19h-harness r2]
    public static array $liveMoved = [];  // beat => [text, where it went]: a live text that is no text of the target (P1)
    public static array $alias = [];      // beat => the target's shown text: its row prints an alias NAME the harness cannot know (P4)
    public static array $liveFromList = []; // beat => the target's filled text, taken from the fixture's own list (P4)
}

/** "<index txt>" templates, filled from the target row (the Brotherhood's content policy: the text lives in the index). */
function qlxExpand(string $t, ?array $trow, array $B): ?string
{
    if (strpos($t, QLX_TEMPLATE) === false) {
        // [pt19h-harness r2] a verbatim sentence written with the row's own tokens ("<Alias=AuthorityFigure> has granted me ...") is the line
        // as the game prints it - nobody says "<Alias=...>"
        if ($trow !== null && strpos($t, '<') !== false && $t === (string) $trow['txt']) { return qlShown($trow, $B, true); }
        return $t;
    }
    if ($trow === null) { return null; }
    $txt = isset($B['live_text']) ? (string) $B['live_text'] : (string) $trow['txt'];
    $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]*[\)\]]\s*$/', '', $txt));   // no stage direction: he does not say "(Persuade)"
    if (strpos($txt, '<') !== false) { $txt = qlShown(['txt' => $txt, 'norm' => ''] + $trow, $B, false); }
    $core = rtrim($txt, " .!?");
    if ($core === '') { return null; }
    $lc = lcfirst($core);
    if ($t === QLX_TEMPLATE) { return $txt; }
    // the probe shapes that would only repeat the line (research/pt19h-measure.md generated them the same way): no echo question
    // of a question line, no "no, <line>" on a line that itself refuses or negates
    if (qlxIsQ($txt) && ($t === QLX_TEMPLATE . '?' || str_starts_with($t, 'is it true that'))) { return null; }
    if (str_starts_with($t, 'no, ') && preg_match('/^(no|nope|never|not)\b/', lrgPromptNorm($core))) { return null; }
    if (str_starts_with($t, 'not now,')) { return "not now, $core later"; }
    if (str_starts_with($t, 'not yet, ')) { return "not yet, $lc"; }
    if (str_starts_with($t, "I'm not sure, ")) { return "I'm not sure, $lc"; }
    if (str_starts_with($t, 'no, ')) { return "no, $lc"; }
    if (str_starts_with($t, 'wait, ')) { return "wait, $lc?"; }
    if (str_starts_with($t, 'is it true that')) { return "is it true that $lc"; }
    if ($t === QLX_TEMPLATE . '?') { return "$core?"; }
    return str_replace(QLX_TEMPLATE, $core, $t);
}

/** A question by its shape (a trailing "?" or a leading question word / auxiliary). */
function qlxIsQ(string $t): bool
{
    $s = strtolower(trim((string) preg_replace('/\s*[\(\[][^)\]]*[\)\]]\s*$/', '', $t)));
    return str_ends_with($s, '?') || (bool) preg_match("/^(so |well |uh |um |and |ok |okay )?(what|why|how|who|where|when|which|is|are|do|does|did|can|could|would|should|have|has|was|were|will|won't|isn't|aren't|don't|didn't)\b/", $s);
}

/** The words of a sentence with the contractions spelled out ("what's" = "what is"): word for word means THESE are equal. */
function qlxFold(string $t): array
{
    static $c = ["what's" => 'what is', "it's" => 'it is', "that's" => 'that is', "there's" => 'there is', "who's" => 'who is', "where's" => 'where is',
        "here's" => 'here is', "he's" => 'he is', "she's" => 'she is', "i'm" => 'i am', "you're" => 'you are', "we're" => 'we are', "they're" => 'they are',
        "i'll" => 'i will', "you'll" => 'you will', "we'll" => 'we will', "i've" => 'i have', "i'd" => 'i would', "let's" => 'let us',
        "don't" => 'do not', "doesn't" => 'does not', "didn't" => 'did not', "can't" => 'can not', "cannot" => 'can not', "won't" => 'will not',
        "isn't" => 'is not', "aren't" => 'are not', "wasn't" => 'was not', "weren't" => 'were not', "haven't" => 'have not', "wouldn't" => 'would not',
        "shouldn't" => 'should not', "couldn't" => 'could not'];
    $w = preg_split('/\s+/', trim(lrgPromptNorm($t))) ?: [];
    $out = [];
    foreach ($w as $x) { if ($x === '') { continue; } foreach (explode(' ', $c[$x] ?? $x) as $y) { $out[] = $y; } }
    return $out;
}

/** Word for word the same line: the same words (contractions spelled out) AND the same shape - an echo question ("Grelod the Kind
 *  is dead?") is NOT the statement it repeats (G11). */
function qlxSame(string $t, string $line): bool
{
    return qlxFold($t) === qlxFold($line) && qlxIsQ($t) === qlxIsQ($line);
}

/**
 * Does his sentence NAME this line (so the click of it is what he asked for)? Every content word of his is a word of the line,
 * the two have the same shape (question / statement) and the same polarity (a "not" / "never" / "no" in one and not the other
 * is a clash). A stricter reading than the matcher's, used only to tell a must-not-click row that names ANOTHER listed line.
 */
function qlxNames(string $t, string $line, bool $withTag = false): bool
{
    static $stop = ['a', 'an', 'the', 'i', 'me', 'my', 'you', 'your', 'to', 'of', 'about', 'is', 'am', 'are', 'it', 'that', 'this', 'and', 'so', 'uh',
        'um', 'er', 'well', 'oh', 'please', 'just', 'then', 'some', 'any', 'do', 'does', 'did', 'be', 'was', 'were', 'will', 'would', 'can', 'could',
        'okay', 'ok', 'hey', 'now', 'here', 'there', 'for', 'in', 'on', 'at', 'with'];
    $a = qlxFold($t);
    // [pt19h-harness r2] $withTag: the line's stage direction counts too ("Here, take them. (Give fragments)" names "fragments") - used only to
    // tell a SAY line that names another listed line from a wrong click (a report category); the must-not-click reading stays without it
    $b = $withTag ? qlxFold((string) preg_replace('/[\(\)\[\]]/', ' ', $line)) : qlxFold($line);
    if (!$a || qlxIsQ($t) !== qlxIsQ($line)) { return false; }
    $neg = static fn(array $w): bool => (bool) array_intersect($w, ['not', 'never', 'no', 'nothing', 'nobody']);
    if ($neg($a) !== $neg($b)) { return false; }
    $content = array_values(array_diff($a, $stop));
    return $content !== [] && !array_diff($content, $b);
}

/** An extended beat in the harness's beat shape (the fields qlLayer / qlSeam / qlFacts / qlTopics read). */
function qlxBeat(array $b): array
{
    $npc = trim((string) preg_replace('/\s*\(.*$/', '', (string) ($b['npc'] ?? '')));
    $quest = (string) ($b['quest'] ?? '');
    $lay = (array) ($b['layer'] ?? []);
    $B = ['id' => (string) $b['id'], 'quest' => $quest, 'npc' => $npc !== '' ? $npc : 'Testnpc', 'origin' => (string) ($b['origin'] ?? 'glue') ?: 'glue',
        'expect' => (string) ($b['expect'] ?? ''), 'stageB' => (string) ($b['stageB'] ?? ''),
        'facts' => ['clicks_ok' => 1, 'q' => $quest], 'layer' => $lay,
        'target' => array_filter(['topic' => (string) (($b['target'] ?? [])['topic'] ?? ''), 'info_key' => (string) (($b['target'] ?? [])['info_key'] ?? '')], 'strlen'),
        // at clicks_ok 1 a journal scene is driven: the wire carries sj=1 as the coverage team measured it (G7 is by design)
        '_sc' => !empty($b['sj']), 'scene' => !empty($b['sj']) ? $quest : ''];
    if (isset($b['live_text'])) { $B['live_text'] = (string) $b['live_text']; }
    // [pt19h-harness r2] a live text the builder moved off a target with no token (QLX_CORRECTIONS): it is shown on the sibling it fills
    if (isset($b['live_sibling'])) { $B['live_sibling'] = (string) $b['live_sibling']; }
    return $B;
}

/**
 * [pt19h-harness r2] The usefulness review's P1 rule, at run time: a live_text is the TARGET's text only when the target row carries a token
 * the engine fills (<Alias...>, <Global...>, <BribeCost>) or when it is the row's own text. Any other live_text is moved to live_sibling
 * (and reported) - so a fixture copy slip can never build a list the game never shows. Returns the target row's text.
 */
function qlxLiveRule(array &$B, QlIndex $IX): string
{
    $tg = (array) ($B['target'] ?? []);
    $trow = isset($tg['info_key']) ? $IX->row((string) $tg['info_key']) : null;
    $rt = $trow ? (string) $trow['txt'] : '';
    if (isset($B['live_text']) && $trow && strpos($rt, '<') === false && lrgPromptNorm((string) $B['live_text']) !== lrgPromptNorm($rt)) {
        $B['live_sibling'] = (string) $B['live_text'];
        unset($B['live_text']);
        Qlx::$liveMoved[(string) $B['id']] = [(string) $B['live_sibling'], 'run-time rule'];
    }
    // the fixture's own list may carry the target as the game prints it (the coverage team wrote "Anuriel says there's a shipment of coin
    // traveling to Windhelm..." for "<Alias=Steward> says ..."): a fully filled entry that the target row's tokens fit IS the target's text
    if (!isset($B['live_text']) && $trow && strpos($rt, '<') !== false && (string) (($B['layer'] ?? [])['entries_by'] ?? '') !== 'info_key') {
        foreach ((array) (($B['layer'] ?? [])['entries'] ?? []) as $e) {
            if (is_string($e) && strpos($e, '<') === false && qlxFills($rt, $e)) { $B['live_text'] = $e; Qlx::$liveFromList[(string) $B['id']] = $e; break; }
        }
    }
    return $rt;
}

/** [pt19h-harness r2] Does this text fill that row's tokens ("I can clear out <Alias=AnimalDen> in <Alias=DenHold>." - "I can clear out
 *  Fallowstone Cave in the Rift.")? The row's own words must carry the match: a row that is (almost) only a token fits nothing. */
function qlxFills(string $rowTxt, string $text): bool
{
    $parts = preg_split('/<[^>]*>/', trim($rowTxt)) ?: [];
    if (count($parts) < 2 || strlen((string) preg_replace('/[^a-z]/i', '', implode('', $parts))) < 8) { return false; }
    return (bool) preg_match('/^' . implode('(.+?)', array_map(static fn($p) => preg_quote($p, '/'), $parts)) . '$/iu', trim($text));
}

/**
 * [pt19h-harness r2] Show a beat's live_sibling on the list entry it fills: a sibling whose row carries tokens and whose text, the tokens
 * read as "anything", is that live text (the accept line "I can clear out <Alias=AnimalDen> in <Alias=DenHold>." for "I can clear out
 * Fallowstone Cave in the Rift."). No entry fits: the text is dropped (reported).
 */
function qlxLiveSibling(array &$B, array &$L): void
{
    if (!isset($B['live_sibling']) || empty($L['entries'])) { return; }
    $lt = trim((string) $B['live_sibling']);
    $where = 'dropped: no sibling on the list fits it';
    foreach ($L['entries'] as $i => $e) {
        if (!empty($e['target'])) { continue; }
        $rt = (string) ((array) ($e['row'] ?? []))['txt'];
        if (strpos($rt, '<') === false || !qlxFills($rt, $lt)) { continue; }
        $L['entries'][$i]['text'] = $lt;
        $B['live'][(string) $e['norm']] = $lt;
        $where = 'shown on the sibling "' . $rt . '"';
        break;
    }
    Qlx::$liveMoved[(string) $B['id']] = [$lt, (Qlx::$liveMoved[(string) $B['id']][1] ?? 'fixture correction') . '; ' . $where];
}

/**
 * The beat's list: the index first (qlLayer - a layer line, the parent row's links, named top-level topics), else the
 * fixture's own list (the coverage team's method for `siblings`, a hand-built root, a prompt-less greeting): each entry
 * resolved to its index row by info_key or norm; the target by its info_key (appended when the list lacks it).
 */
function qlxLayer(array &$B, array $b, QlIndex $IX): array
{
    $lay = (array) ($b['layer'] ?? []);
    $kind = (string) ($lay['kind'] ?? '');
    $byIk = (string) ($lay['entries_by'] ?? '') === 'info_key';
    $entries = array_values((array) ($lay['entries'] ?? []));
    if ($kind === '' || $kind === 'none' || (!$entries && empty($lay['parent_info']) && empty($lay['topics']))) {
        return ['err' => 'no clickable line', 'kind' => 'none', 'entries' => [], 'ti' => -1, 'from' => 'none'];
    }
    // --- the index
    $try = $B;
    if ($kind === 'root' && empty($lay['topics']) && $byIk) {
        $tops = [];
        foreach ($entries as $ik) { $r = $IX->row((string) $ik); if ($r) { $tops[] = ['topic' => (string) $r['topic'], 'info_key' => (string) $r['info_key']]; } }
        $try['layer'] = ['kind' => 'root', 'topics' => $tops];
    } elseif ($kind === 'single' && empty($lay['parent_info'])) {
        $try['layer'] = ['kind' => 'single', 'parent_info' => '-'];
    }
    $L = qlLayer($try, $IX);
    if ($L['err'] === '') { $L['from'] = 'index'; return $L; }
    $why = $L['err'];
    // --- the fixture's own list
    $tg = (array) ($B['target'] ?? []);
    $trow = isset($tg['info_key']) ? $IX->row((string) $tg['info_key']) : null;
    if ($trow === null) { return ['err' => 'target not in this index: ' . json_encode($tg), 'kind' => $kind, 'entries' => [], 'ti' => -1, 'from' => 'error']; }
    $out = ['kind' => in_array($kind, ['closed', 'single', 'root'], true) ? $kind : 'closed', 'entries' => [], 'ti' => -1, 'err' => '', 'trow' => $trow,
        'from' => 'list', 'index_err' => $why];
    $quest = strtolower((string) ($B['quest'] ?? ''));
    foreach ($entries as $e) {
        $e = (string) $e;
        $row = null;
        if ($byIk) {
            $row = $IX->row($e);
        } else {
            $n = ($e === strtolower($e) && !preg_match("/[^a-z0-9' #]/", $e)) ? $e : lrgPromptNorm($e);
            if ($n === (string) $trow['norm']) { $row = $trow; }
            else {
                $cands = array_map(fn($i) => $IX->rows[$i], $IX->byNorm[$n] ?? []);
                foreach ($cands as $c) { if (strtolower((string) $c['quest']) === $quest) { $row = $c; break; } }
                $row = $row ?? ($cands[0] ?? null);
            }
            if ($row === null && isset($B['live_text']) && $e === (string) $B['live_text']) {
                // [pt19h-harness r2] the target as the game prints it (qlxLiveRule found it in this list): the target row, not a second line
                $row = $trow;
            }
            if ($row === null) {
                // a line the index does not carry (unindexed): shown as written
                $row = ['norm' => $n, 'txt' => $e, 'topic' => '', 'topic_key' => '', 'info_key' => '', 'quest' => '', 'scripted' => 0, 'crit' => 0,
                    'kind' => '', 'cost' => 0, 'flags' => [], 'links' => [], 'toplevel' => 0, 'journal' => 0];
            }
        }
        if ($row === null) { continue; }
        $isT = (string) $row['info_key'] !== '' ? (string) $row['info_key'] === (string) $trow['info_key'] : (string) $row['norm'] === (string) $trow['norm'];
        if (!$isT && (string) $row['norm'] === (string) $trow['norm']) { $isT = true; $row = $trow; }
        if ($isT && $out['ti'] >= 0) { continue; }
        $out['entries'][] = ['text' => qlShown($row, $B, $isT), 'norm' => (string) $row['norm'], 'row' => $row, 'target' => $isT];
        if ($isT) { $out['ti'] = count($out['entries']) - 1; }
    }
    if ($out['ti'] < 0) {
        $out['entries'][] = ['text' => qlShown($trow, $B, true), 'norm' => (string) $trow['norm'], 'row' => $trow, 'target' => true];
        $out['ti'] = count($out['entries']) - 1;
        Qlx::$appended[] = (string) $B['id'];
    }
    return $out;
}

/** The live grading of the list at clicks_ok 1 (the gate's own entries: class, commit, scripted, goodbye, crit, cost, kind). */
function qlxLive(array $B, array $L, array $seam): array
{
    qlOpenWorld($B, $L, $seam, 1);
    $out = [];
    foreach ((array) ((fxDlgStateOf((string) $B['npc'])['session'] ?? [])['entries'] ?? []) as $e) { $out[(int) $e['pos']] = $e; }
    return $out;
}

/** The rule's never-click set (research/pt19h-measure.md): commit / check / pay, scripted, goodbye, crit >= 1, a price, a check kind. */
function qlxProtected(?array $e): bool
{
    if (!$e) { return false; }
    return !empty($e['commit']) || in_array((string) ($e['class'] ?? ''), ['commit', 'check', 'pay'], true) || (int) ($e['scripted'] ?? 0) === 1
        || (int) ($e['goodbye'] ?? 0) === 1 || (int) ($e['crit'] ?? 0) >= 1 || (int) ($e['cost'] ?? 0) > 0 || (string) ($e['kind'] ?? '') !== '';
}

/** A fast-path park (his words named a commit line: she asks, quoting it) - from the gate's state or its log. */
function qlxFastAsked(array $B, array $L, array $f): bool
{
    $p = (string) ((fxDlgStateOf((string) $B['npc'])['parked'] ?? [])['norm'] ?? '');
    if ($p !== '' && $p === (string) $L['entries'][$L['ti']]['norm']) { return true; }
    return (bool) preg_match('/PARK|matched a commit|she asks which/', (string) $f['log']);
}

/** One say line: fast (his words click it) / tkey (her key on the target clicks it) / ask / nothing / wrong / show. */
function qlxSay(array $B, array $L, array $seam, string $t): array
{
    qlWorld($seam, 1, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    fxAdvance(3);
    $f = qlFast($B, $L, $t);
    if ($f['cmd'] === 'pick') {
        return $f['pos'] === $L['ti'] ? ['fast', $f['mode'] . ($f['adv'] >= 0 ? '/adv' : '')] : qlxOther($L, $f['pos'], $t, 'fast pos=' . $f['pos'] . ' mode=' . $f['mode']);
    }
    if ($f['cmd'] === 'show') { return ['show', 'fast ' . $f['kind']]; }
    if (qlxFastAsked($B, $L, $f)) { return ['ask', 'words']; }
    qlOpenWorld($B, $L, $seam, 1);
    $g = qlLlm($B, $L, $t, $B['id'] . ' ext "' . $t . '"');
    if ($g['cmd'] === 'pick') { return $g['pos'] === $L['ti'] ? ['tkey', $g['mode']] : qlxOther($L, $g['pos'], $t, 'key pos=' . $g['pos'] . ' mode=' . $g['mode']); }
    if ($g['cmd'] === 'show') { return ['show', 'gate ' . $g['kind']]; }
    if ($g['parked'] !== '' && ($g['parked'] === (string) $L['entries'][$L['ti']]['norm'] || $g['parked'] === lrgPromptNorm((string) $L['entries'][$L['ti']]['text']))) {
        return ['ask', 'key'];
    }
    return ['nothing', qlWhy($f['log'])];
}

/**
 * [pt19h-harness r2] A say line that clicked ANOTHER listed line: `sibling` when his words are that line word for word or name it (its stage
 * direction included - "here, take the fragments" names "Here, take them. (Give fragments)"): the paraphrase names a sibling, which is clicked
 * as he said - a fixture reading, not a wrong click. Otherwise `wrong`. The detail quotes the line that was clicked.
 */
function qlxOther(array $L, int $pos, string $t, string $how): array
{
    $e = $L['entries'][$pos] ?? null;
    $txt = (string) ($e['text'] ?? '');
    $how .= ' "' . substr($txt, 0, 70) . '"';
    if ($e && (qlxSame($t, $txt) || qlxNames($t, $txt, true))) { return ['sibling', $how . ' (his words name that line)']; }
    return ['wrong', $how];
}

/**
 * One must-not-click line on the FAST PATH (the extended legend's contract). Returns [verdict, detail, protected, key]:
 * verdict ok | click | sibling-verbatim | breath; key = what her T-key on the target does after it (reported only).
 */
function qlxMust(array $B, array $L, array $seam, string $t, array $live, bool $notTarget): array
{
    // a row that IS the target line (word for word) contradicts the beat - a fixture slip, reported and never run
    if (qlxSame($t, (string) $L['entries'][$L['ti']]['text'])) { return ['is-target', 'the row is the target line itself (word for word)', false, '-']; }
    qlWorld($seam, 1, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    fxAdvance(3);
    $f = qlFast($B, $L, $t);
    $verdict = 'ok';
    $detail = 'fast ' . $f['cmd'];
    $prot = false;
    if ($f['cmd'] === 'pick') {
        $e = $L['entries'][$f['pos']] ?? null;
        $prot = qlxProtected($live[$f['pos']] ?? null);
        $detail = 'fast pick pos=' . $f['pos'] . ' mode=' . $f['mode'] . ($f['adv'] >= 0 ? ' adv=' . $f['adv'] : '') . ' "' . substr((string) ($e['text'] ?? ''), 0, 60) . '"';
        if ($f['pos'] !== $L['ti']) {
            if ($notTarget) { $verdict = 'ok'; $detail .= ' (a sibling - allowed for a not_target row)'; }
            elseif ($e && qlxSame($t, (string) $e['text'])) { $verdict = 'sibling-verbatim'; }
            elseif ($e && qlxNames($t, (string) $e['text'])) { $verdict = 'sibling-named'; }
            else { $verdict = 'click'; }
        } elseif (!empty($L['collapsed']) && isset($L['collapsed'][lrgPromptNorm($t)])) {
            // the row names ANOTHER prompt of the target's own topic, which the engine does not show beside it (one INFO per topic)
            $verdict = 'variant';
            $detail .= ' - the row names a variant of the target\'s topic that is not on screen (' . $L['collapsed'][lrgPromptNorm($t)] . ')';
        } elseif ($f['adv'] >= 0) {
            // S4.6: an unscripted single advances after the breath whatever he says - unless his words REFUSE it (step 0)
            $rel = $L['kind'] === 'single' && isset($live[$L['ti']]) ? lrgDlgSingleEntryRelease($live[$L['ti']], $t) : ['refused' => false, 'shape' => false];
            // (and the harness's own reading: a refusal / deferral / hedge never rides the breath - row 24, research/pt19h-measure.md G1)
            $verdict = ((!empty($rel['refused']) && empty($rel['shape'])) || qlxShape($t) === 'refusal/deferral/hedge') ? 'click' : 'breath';
            if ($verdict === 'click') { $detail .= ' - his words REFUSE the line: nothing, and no auto-advance'; }
            // [pt19h-review] the breath is exempt only on an EFFECT-FREE single: an advance onto a protected line is a click
            elseif ($prot) { $verdict = 'click'; $detail .= ' - the breath advanced a PROTECTED line (S4.6 exempts effect-free singles only)'; }
        } else {
            $verdict = 'click';
        }
    } elseif ($f['cmd'] === 'show') {
        $detail = 'fast show kind=' . $f['kind'];
    } elseif (qlxFastAsked($B, $L, $f)) {
        $detail = 'she asks (words park)';
    }
    // her T-key on the target after these words (the LLM path - needs the model to press it; reported only)
    qlOpenWorld($B, $L, $seam, 1);
    $g = qlLlm($B, $L, $t, $B['id'] . ' ext-must "' . $t . '"');
    $key = $g['cmd'] === 'pick' ? ($g['pos'] === $L['ti'] ? 'releases' : 'other') : ($g['parked'] !== '' ? 'asks' : ($g['cmd'] === 'show' ? 'show' : 'nothing'));
    return [$verdict, $detail, $prot, $key . ($key === 'releases' ? ' (' . $g['mode'] . ')' : '')];
}

/** The shape of a must-not-click sentence (for the failure lines): refusal / deferral / hedge, echo, question, negated, statement. */
function qlxShape(string $t): string
{
    $s = strtolower(trim($t));
    if (preg_match("/^(not now|not yet|no\b|nope|never mind|i'm not sure|maybe later|later)/", $s)) { return 'refusal/deferral/hedge'; }
    if (preg_match('/^(wait,|is it true that)/', $s) || (str_ends_with($s, '?') && !preg_match('/^(what|why|how|who|where|when|which|is|are|do|does|did|can|could|would|should|have|has|was|were|will)\b/', $s))) { return 'echo'; }
    if (str_ends_with($s, '?') || preg_match('/^(what|why|how|who|where|when|which|is|are|do|does|did|can|could|would|should|have|has|was|were|will)\b/', $s)) { return 'question'; }
    if (preg_match("/\b(not|don't|won't|can't|didn't|never|isn't|aren't|wasn't|no)\b/", $s)) { return 'negated'; }
    return 'statement';
}

/** Run every beat of the extended fixture; print the report; return the exit code. */
function qlxRun(array $fx, QlIndex $IX, array $opt): int
{
    $quiet = !empty($opt['quiet']);
    $only = (string) ($opt['beat'] ?? '');
    $onlyGroup = (string) ($opt['group'] ?? '');
    [$sk, $sn] = array_map('intval', explode('/', (string) ($opt['shard'] ?? '0/1')) + [0, 1]);
    $sn = max(1, $sn);
    $T0 = microtime(true);
    $sets = [['main', (array) $fx['beats']], ['quarantine', array_map(static fn($q) => ((array) $q['beat']) + ['_q' => $q], (array) ($fx['quarantine'] ?? []))]];
    $n = 0;
    foreach ($sets as [$set, $list]) {
        foreach ($list as $bi => $b) {
            if ($only !== '' && (string) $b['id'] !== $only) { continue; }
            if ($onlyGroup !== '' && (string) ($b['group'] ?? '') !== $onlyGroup) { continue; }
            if ($sn > 1 && ($n++ % $sn) !== $sk) { continue; }
            qlxOneBeat($b, $set, $IX, $quiet);
        }
    }
    qlxReport($fx, $opt, microtime(true) - $T0);
    return 0;
}

function qlxOneBeat(array $b, string $set, QlIndex $IX, bool $quiet): void
{
    $B = qlxBeat($b);
    $group = (string) ($b['group'] ?? '?');
    qlxLiveRule($B, $IX);   // [pt19h-harness r2] P1: a live_text on a target with no token is no text of the target
    $L = qlxLayer($B, $b, $IX);
    $rec = ['id' => $B['id'], 'set' => $set, 'group' => $group, 'from' => $L['from'] ?? '', 'lines' => []];
    if (($L['err'] ?? '') !== '') {
        Qlx::$beatsFrom[$L['from'] === 'none' ? 'none' : 'error']++;
        if ($L['from'] !== 'none') { Qlx::$errors[$B['id']] = $L['err']; }
        $rec['err'] = $L['err'];
        Qlx::$dump[] = $rec;
        return;
    }
    Qlx::$beatsFrom[$L['from']]++;
    qlxLiveSibling($B, $L);   // [pt19h-harness r2] the moved live text on the sibling it fills
    $seam = qlSeam($B, $L, $IX);
    $live = qlxLive($B, $L, $seam);
    $trow = $L['entries'][$L['ti']]['row'];
    $protT = qlxProtected($live[$L['ti']] ?? null);
    // [pt19h-harness r2] a priced target above his purse (the harness world's pg, 300 by default): never clicked, never offered (never-false)
    $unaff = (int) (($live[$L['ti']] ?? [])['cost'] ?? 0) > (int) ((($B['facts'] ?? [])['pg']) ?? 300);
    // [pt19h-harness r2] P4: the target prints an alias NAME the fixture gives no live text for - the harness cannot know the words the game
    // shows, so its lines are flagged `alias` and kept out of the tallies (listed apart; never a baseline failure)
    $alias = stripos((string) ($trow['txt'] ?? ''), '<Alias') !== false && !isset($B['live_text']);
    if ($alias) { Qlx::$alias[(string) $B['id']] = (string) $L['entries'][$L['ti']]['text']; }
    $rec['target'] = ['text' => $L['entries'][$L['ti']]['text'], 'protected' => $protT, 'class' => (string) (($live[$L['ti']] ?? [])['class'] ?? '')]
        + ($alias ? ['alias' => true] : []) + (isset(Qlx::$liveMoved[(string) $B['id']]) ? ['live_moved' => Qlx::$liveMoved[(string) $B['id']]] : []);
    // the say lines: reported by outcome
    $says = [];
    foreach ((array) ($b['say'] ?? []) as $s) { $says[] = ['cat' => 'say', 't' => (string) $s['t'], 'src' => (string) ($s['src'] ?? ''), 'via' => (string) ($s['via'] ?? ''), 'fp' => $s['fp'] ?? null]; }
    foreach ((array) ($b['say_model'] ?? []) as $s) { $says[] = ['cat' => 'say_model', 't' => (string) $s['t'], 'src' => (string) ($s['src'] ?? ''), 'via' => 'model', 'fp' => null]; }
    foreach ((array) ($b['misses'] ?? []) as $s) { $says[] = ['cat' => 'misses', 't' => (string) $s['t'], 'src' => (string) ($s['src'] ?? ''), 'via' => 'miss', 'fp' => null]; }
    $seen = [];
    foreach ($says as $s) {
        $t = qlxExpand($s['t'], $trow, $B);
        if ($t === null || $t === '' || isset($seen[$s['cat'] . '|' . $t])) { continue; }
        $seen[$s['cat'] . '|' . $t] = 1;
        [$o, $how] = qlxSay($B, $L, $seam, $t);
        // [pt19h-harness r2] + [via, protected target, alias, first evening] (the baseline comparison)
        Qlx::$say[] = [$group, $s['src'], $s['cat'], $o, $s['fp'], $B['id'], $t, $how, $set, $s['via'], $protT, $alias, !empty($b['fe']), $unaff];
        $rec['lines'][] = ['cat' => $s['cat'], 't' => $t, 'src' => $s['src'], 'via' => $s['via'], 'fp' => $s['fp'], 'outcome' => $o, 'how' => $how] + ($alias ? ['alias' => true] : []);
    }
    // the must-not-click rows
    $must = [];
    foreach ((array) ($b['never'] ?? []) as $t) { $must[] = ['cat' => 'never', 't' => (string) $t, 'gap' => '']; }
    foreach ((array) ($b['not_target'] ?? []) as $t) { $must[] = ['cat' => 'not_target', 't' => (string) $t, 'gap' => '']; }
    foreach ((array) ($b['never_red'] ?? []) as $x) {
        $must[] = ['cat' => 'never_red', 't' => (string) $x['t'], 'gap' => (string) ($x['gap'] ?? '?'), 'src' => (string) ($x['src'] ?? ''), 'path' => (string) ($x['path'] ?? 'fast')];
    }
    if (!empty($b['_q'])) {
        foreach ((array) ($b['_q']['still_clicks'] ?? []) as $t) { $must[] = ['cat' => 'q_still', 't' => (string) $t, 'gap' => implode('+', (array) ($b['_q']['gaps'] ?? []))]; }
    }
    $nm = (array) ($b['near_miss'] ?? []);
    $neverSet = array_flip(array_map('strval', (array) ($b['never'] ?? [])));
    if ((string) ($nm['t'] ?? '') !== '' && !isset($neverSet[(string) $nm['t']])) { $must[] = ['cat' => 'near', 't' => (string) $nm['t'], 'gap' => '']; }
    $seen = [];
    $memo = [];   // one run per sentence and kind of row (the same sentence may be a coverage row AND a measurer row of the beat)
    foreach ($must as $m) {
        $t = qlxExpand($m['t'], $trow, $B);
        $src = (string) ($m['src'] ?? '');
        if ($t === null || $t === '' || isset($seen[$m['cat'] . '|' . $src . '|' . $m['gap'] . '|' . $t])) { continue; }
        $seen[$m['cat'] . '|' . $src . '|' . $m['gap'] . '|' . $t] = 1;
        $mk = ($m['cat'] === 'not_target' ? 'nt|' : 'm|') . $t;
        $memo[$mk] = $memo[$mk] ?? qlxMust($B, $L, $seam, $t, $live, $m['cat'] === 'not_target');
        [$v, $detail, $prot, $key] = $memo[$mk];
        $cat = $set === 'quarantine' ? ($m['cat'] === 'q_still' ? 'q_still' : 'q_' . $m['cat']) : $m['cat'];
        $path = (string) ($m['path'] ?? 'fast');
        // still red: the fast path clicks it - or, for a row whose defect is on her key (G13, G20), her key still releases the line
        $red = $path === 'key' ? str_starts_with($key, 'releases') : $v === 'click';
        Qlx::$must[] = [$cat, $m['gap'], $v, $B['id'], $t, $detail, $prot, $key, $group, $red, $src, $path];
        $rec['lines'][] = ['cat' => $cat, 't' => $t, 'gap' => $m['gap'], 'src' => $src, 'path' => $path, 'verdict' => $v, 'red' => $red, 'detail' => $detail, 'protected' => $prot, 'key' => $key];
        if (in_array($cat, ['never', 'not_target'], true)) {
            // the assertion (the extended legend): the fast path clicks nothing - a line the fixture recorded green must stay green
            qlChk("ext $group:" . $B['id'] . " $cat: \"$t\" clicks nothing on the fast path", $v !== 'click',
                $detail . ($prot ? ' - a PROTECTED line' : '') . ' [' . qlxShape($t) . ']; her key after it: ' . $key);
        }
    }
    // the measurer's must-RESOLVE rows (G2 G3 G4 G6 G16 G18): reported by outcome
    foreach ((array) ($b['resolve_red'] ?? []) as $x) {
        $t = qlxExpand((string) $x['t'], $trow, $B);
        if ($t === null || $t === '') { continue; }
        [$o, $how] = qlxSay($B, $L, $seam, $t);
        Qlx::$resolve[] = [(string) ($x['gap'] ?? '?'), $o, $B['id'], $t, $set, $how, $group, $protT, $alias, $unaff];
        $rec['lines'][] = ['cat' => 'resolve_red', 't' => $t, 'gap' => (string) ($x['gap'] ?? '?'), 'outcome' => $o, 'how' => $how] + ($alias ? ['alias' => true] : []);
    }
    Qlx::$dump[] = $rec;
}

function qlxPct(int $a, int $n): string { return $n ? sprintf('%.1f%%', 100 * $a / $n) : '-'; }

function qlxReport(array $fx, array $opt, float $secs): void
{
    $O = ['fast', 'tkey', 'ask', 'nothing', 'sibling', 'wrong', 'show'];
    $head = ['fast' => 'fast-path click', 'tkey' => 'her T-key', 'ask' => 'she asks', 'nothing' => 'nothing', 'sibling' => 'names a sibling', 'wrong' => 'WRONG line',
        'show' => 'menu handed back'];
    $table = static function (string $title, array $rows) use ($O, $head): void {
        $n = count($rows);
        $c = array_fill_keys($O, 0);
        foreach ($rows as $r) { $c[$r[3]] = ($c[$r[3]] ?? 0) + 1; }
        $cells = [];
        foreach ($O as $k) { $cells[] = sprintf('%s %s (%d)', $head[$k], qlxPct($c[$k], $n), $c[$k]); }
        printf("  %-34s %6d | %s\n", $title, $n, implode(' | ', $cells));
    };
    echo "\n== the extended coverage (" . (string) ($fx['ext'] ?? '') . ")\n";
    echo sprintf("lists: %d built from the index, %d from the fixture's own list (siblings / hand-built root / greeting), %d with no clickable line (G17), %d not buildable\n",
        Qlx::$beatsFrom['index'], Qlx::$beatsFrom['list'], Qlx::$beatsFrom['none'], Qlx::$beatsFrom['error']);
    if (Qlx::$appended) { echo '  (' . count(Qlx::$appended) . " fixture lists lacked their own target line; it was appended, as the coverage team did)\n"; }
    foreach (array_slice(Qlx::$errors, 0, 8, true) as $id => $why) { echo "  not buildable: $id - " . fx_short($why, 160) . "\n"; }
    // [pt19h-harness r2] the lines of an alias-tag target (its row prints a NAME the fixture gives no live text for) are kept OUT of every
    // tally: the harness cannot know the words the game shows there (the usefulness review's P4). They are counted on their own line
    $aliasRows = array_values(array_filter(Qlx::$say, static fn($r) => !empty($r[11])));
    $main = array_values(array_filter(Qlx::$say, static fn($r) => $r[8] === 'main' && empty($r[11])));
    $q = array_values(array_filter(Qlx::$say, static fn($r) => $r[8] === 'quarantine' && empty($r[11])));
    if (Qlx::$liveMoved) {
        echo '  live_text that is no text of its target (the target row carries no token; P1): ' . count(Qlx::$liveMoved) . " beat(s)\n";
        foreach (Qlx::$liveMoved as $id => [$lt, $where]) { echo "    $id: \"$lt\" - $where\n"; }
    }
    if (Qlx::$liveFromList) {
        echo '  alias-tag targets whose game text the fixture\'s own list carries (used as the target\'s text, P4): ' . count(Qlx::$liveFromList) . ' beat(s): '
            . fx_short(implode('; ', array_map(static fn($k, $v) => "$k \"$v\"", array_keys(Qlx::$liveFromList), Qlx::$liveFromList)), 400) . "\n";
    }
    echo "\nevery say line by outcome (fast path first, then her T-key on the line; she asks = his words named a commit line, or her key parked it;"
        . "\nnames a sibling = his words are, or name, ANOTHER listed line, which was clicked - a fixture reading, not a wrong click):\n";
    $table('ALL (say + say_model + misses)', $main);
    $table('- say lines', array_values(array_filter($main, static fn($r) => $r[2] === 'say')));
    $table('- say_model (she asks which)', array_values(array_filter($main, static fn($r) => $r[2] === 'say_model')));
    $table('- misses (did nothing when recorded)',array_values(array_filter($main, static fn($r) => $r[2] === 'misses')));
    foreach (['verbatim', 'para', 'stt', 'probe'] as $src) { $table("- src $src", array_values(array_filter($main, static fn($r) => $r[2] === 'say' && $r[1] === $src))); }
    $groups = array_unique(array_column($main, 0));
    sort($groups);
    foreach ($groups as $g) { $table("- group $g", array_values(array_filter($main, static fn($r) => $r[0] === $g))); }
    $fpT = array_values(array_filter($main, static fn($r) => $r[2] === 'say' && $r[4] === true));
    $fpF = array_values(array_filter($main, static fn($r) => $r[2] === 'say' && $r[4] === false));
    $lostBy = array_count_values(array_column(array_values(array_filter($fpT, static fn($r) => $r[3] !== 'fast')), 3));
    $gain = count(array_filter($fpF, static fn($r) => $r[3] === 'fast'));
    // [pt19h-harness r2] by outcome (her key is not the same loss as nothing); a line-by-line comparison with an EARLIER RUN is the baseline section
    echo sprintf("  vs the coverage record's fp flag (not a run): %d of %d lines it saw on the fast path are not fast now (%s); %d of %d it saw needing her key are fast now\n",
        array_sum($lostBy), count($fpT), implode(', ', array_map(static fn($k) => ($head[$k] ?? $k) . ' ' . ($lostBy[$k] ?? 0), array_filter($O, static fn($k) => $k !== 'fast'))),
        $gain, count($fpF));
    $c0 = array_count_values(array_column($aliasRows, 3));
    printf("  %-34s %6d | kept out of every tally above: the target prints an alias NAME the harness cannot know (%d beats); outcomes: %s\n",
        'alias-tag targets (not tallied)', count($aliasRows), count(Qlx::$alias), implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($c0), $c0)) ?: '-');
    $wrong = array_values(array_filter($main, static fn($r) => $r[3] === 'wrong'));
    foreach (array_slice($wrong, 0, 12) as $r) { echo "    WRONG line: {$r[5]} \"{$r[6]}\" - {$r[7]}\n"; }
    if (count($wrong) > 12) { echo '    ... ' . (count($wrong) - 12) . " more (--ext-out has every line)\n"; }
    // the must-not-click rows
    $M = array_values(array_filter(Qlx::$must, static fn($r) => !str_starts_with($r[0], 'q_') && (string) ($r[10] ?? '') !== 'pt19h-measure'));
    echo "\nmust-not-click rows on the FAST PATH. click = a defect (fails the run where ASSERTED). No defect: sibling-verbatim / sibling-named (he said or"
        . "\nnamed ANOTHER listed line, which is clicked), breath (S4.6 on an unscripted single), variant (the row names a prompt of the target's own topic the"
        . "\nengine does not show beside it), is-target (the row is the target line itself - a fixture slip). Last column: her T-key after the line (LLM path):\n";
    foreach (['never' => 'ASSERTED', 'not_target' => 'ASSERTED (on the target)', 'never_red' => 'reported (turns green as the fixers land)', 'near' => 'reported'] as $cat => $how) {
        $rows = array_values(array_filter($M, static fn($r) => $r[0] === $cat));
        $cnt = array_count_values(array_column($rows, 2));
        $keyRel = count(array_filter($rows, static fn($r) => str_starts_with($r[7], 'releases')));
        printf("  %-10s %5d %-42s ok %d | click %d (%d protected) | sibling-verbatim %d, sibling-named %d, breath %d, variant %d, is-target %d | key releases %d\n",
            $cat, count($rows), $how, $cnt['ok'] ?? 0, $cnt['click'] ?? 0, count(array_filter($rows, static fn($r) => $r[2] === 'click' && $r[6])),
            $cnt['sibling-verbatim'] ?? 0, $cnt['sibling-named'] ?? 0, $cnt['breath'] ?? 0, $cnt['variant'] ?? 0, $cnt['is-target'] ?? 0, $keyRel);
    }
    foreach (array_values(array_filter($M, static fn($r) => in_array($r[0], ['never', 'not_target'], true) && in_array($r[2], ['sibling-named', 'variant', 'is-target'], true))) as $r) {
        if (empty($opt['quiet'])) { echo "    ({$r[2]}) {$r[3]} {$r[0]} \"{$r[4]}\" - " . fx_short($r[5], 110) . "\n"; }
    }
    // never_red by gap, over the main set AND the quarantine (the gap is the unit): the coverage team's rows and the measurer's
    $allRed = array_values(array_filter(Qlx::$must, static fn($r) => in_array($r[0], ['never_red', 'q_never_red'], true)));
    foreach (['' => 'the coverage team\'s never_red rows', 'pt19h-measure' => 'the measurer\'s rows (research/pt19h-measure.json; compare its still_live)'] as $src => $title) {
        $red = array_values(array_filter($allRed, static fn($r) => ($src === 'pt19h-measure') === ((string) ($r[10] ?? '') === 'pt19h-measure')));
        if (!$red) { continue; }
        echo "\nnever_red by gap id - $title (quarantine included). still red = the fast path still clicks it (G13 / G20: her key still releases it):\n";
        $gaps = array_unique(array_column($red, 1));
        sort($gaps, SORT_NATURAL);
        foreach ($gaps as $g) {
            $rows = array_values(array_filter($red, static fn($r) => $r[1] === $g));
            $still = array_values(array_filter($rows, static fn($r) => !empty($r[9])));
            printf("  %-5s %4d rows: still red %4d (%d on a protected line), green %4d\n", $g, count($rows), count($still),
                count(array_filter($still, static fn($r) => $r[6])), count($rows) - count($still));
            if (empty($opt['quiet'])) { foreach ($still as $r) { echo "        red: {$r[3]} \"{$r[4]}\" - " . fx_short($r[5], 110) . ($r[6] ? ' (protected)' : '') . ($r[11] === 'key' ? '; key ' . $r[7] : '') . "\n"; } }
        }
    }
    if (Qlx::$resolve) {
        echo "\nthe measurer's must-RESOLVE rows by gap (quarantine included): the line must be reached - fast path best, her key next:\n";
        $gaps = array_unique(array_column(Qlx::$resolve, 0));
        sort($gaps, SORT_NATURAL);
        foreach ($gaps as $g) {
            // [pt19h-harness r2] an alias-tag target's rows are kept out of the tally (P4) and counted apart
            $all = array_values(array_filter(Qlx::$resolve, static fn($r) => $r[0] === $g));
            $rows = array_values(array_filter($all, static fn($r) => empty($r[8])));
            $c = array_count_values(array_column($rows, 1));
            printf("  %-5s %4d rows: fast %d, her key %d, she asks %d, nothing %d, names a sibling %d, WRONG line %d, show %d%s\n", $g, count($rows), $c['fast'] ?? 0,
                $c['tkey'] ?? 0, $c['ask'] ?? 0, $c['nothing'] ?? 0, $c['sibling'] ?? 0, $c['wrong'] ?? 0, $c['show'] ?? 0,
                count($all) > count($rows) ? ' (+' . (count($all) - count($rows)) . ' alias-tag rows, not tallied)' : '');
        }
        // [pt19h-harness r2] the rows that click a DIFFERENT line, by line (a count alone hides which line she clicks instead)
        foreach (array_values(array_filter(Qlx::$resolve, static fn($r) => $r[1] === 'wrong')) as $r) {
            echo "    WRONG line ({$r[0]}): {$r[2]} \"{$r[3]}\" - {$r[5]}" . (!empty($r[8]) ? ' [alias-tag target]' : '') . "\n";
        }
    }
    $near = array_values(array_filter($M, static fn($r) => $r[0] === 'near' && $r[2] === 'click'));
    foreach (array_slice($near, 0, 6) as $r) { echo "  near-miss clicks: {$r[3]} \"{$r[4]}\" - " . fx_short($r[5], 120) . "\n"; }
    // the quarantine, apart
    $QM = array_values(array_filter(Qlx::$must, static fn($r) => str_starts_with($r[0], 'q_')));
    echo "\nthe quarantine (" . count((array) ($fx['quarantine'] ?? [])) . " entries; reported, never asserted):\n";
    $table('its say lines', $q);
    $qs = array_values(array_filter($QM, static fn($r) => $r[0] === 'q_still'));
    $qn = array_values(array_filter($QM, static fn($r) => $r[0] !== 'q_still' && (string) ($r[10] ?? '') !== 'pt19h-measure'));
    printf("  still_clicks rows %d: still click %d, green %d;  its other must-not-click rows %d: click %d\n", count($qs),
        count(array_filter($qs, static fn($r) => $r[2] === 'click')), count(array_filter($qs, static fn($r) => $r[2] !== 'click')),
        count($qn), count(array_filter($qn, static fn($r) => $r[2] === 'click')));
    if (empty($opt['quiet'])) { foreach ($qs as $r) { echo '    ' . ($r[2] === 'click' ? 'STILL' : 'green') . " {$r[3]} [{$r[1]}] \"{$r[4]}\" - " . fx_short($r[5], 110) . "\n"; } }
    echo sprintf("\nno clickable line: %d coverage beats (layer none) + %d Dark Brotherhood beats (fixture no_line) - G17\n",
        Qlx::$beatsFrom['none'], count((array) ($fx['no_line'] ?? [])));
    // [pt19h-harness r2] this run against an earlier one, line by line (the usefulness review's P3), and a new baseline on request
    if ((string) ($opt['baseline'] ?? '') !== '') {
        $base = qlxBaselineLoad((string) $opt['baseline']);
        if ($base === null) { echo "\n(no extended baseline at " . $opt['baseline'] . ": nothing compared)\n"; }
        else { qlxBaselineCompare($base, (string) $opt['baseline']); }
    }
    if ((string) ($opt['baseline-out'] ?? '') !== '') { qlxBaselineWrite((string) $opt['baseline-out'], $opt); }
    if (!empty($opt['ext-out'])) {
        file_put_contents((string) $opt['ext-out'], json_encode(['_' => 'lrg_questline_extended_run', 'at' => date('c'), 'secs' => round($secs, 1),
            'beats' => Qlx::$dump], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo 'every line: ' . $opt['ext-out'] . "\n";
    }
    printf("(extended run %.1fs)\n", $secs);
}

// ================================================================== [pt19h-harness r2] the baseline: this run against an EARLIER run, line by line
/** Outcome ranks, best first (fast > her T-key > she asks > menu handed back > nothing > names a sibling > wrong line). */
const QLX_RANK = ['fast' => 0, 'tkey' => 1, 'ask' => 2, 'show' => 3, 'nothing' => 4, 'sibling' => 5, 'wrong' => 6];

/** The outcome(s) a line's recorded `via` names (the coverage team's measured path): a move INTO it is the fixture's own label, not a loss. */
function qlxWant(string $via): array
{
    return match ($via) {
        'explicit', 'single', 'auto' => ['fast'],
        'pick', 'check' => ['tkey'],
        'park', 'park-tkey' => ['ask'],
        'model' => ['tkey', 'ask'],
        'none', 'miss' => ['nothing'],
        default => [],
    };
}

/** The key of one line: set | beat | kind | the sentence (kind: say / say_model / misses, resolve_red:<gap>, <never_red cat>:<gap>:<src>). */
function qlxKey(string $set, string $beat, string $kind, string $t): string { return "$set|$beat|$kind|$t"; }

/** This run's outcomes by key: say lines and must-resolve rows -> their outcome; never_red rows -> red / green. */
function qlxBaselineNow(): array
{
    $m = [];
    foreach (Qlx::$say as $r) { $m[qlxKey($r[8], $r[5], $r[2], $r[6])] = $r[3]; }
    foreach (Qlx::$resolve as $r) { $m[qlxKey($r[4], $r[2], 'resolve_red:' . $r[0], $r[3])] = $r[1]; }
    foreach (Qlx::$must as $r) {
        if (!in_array($r[0], ['never_red', 'q_never_red'], true)) { continue; }
        $m[qlxKey(str_starts_with($r[0], 'q_') ? 'quarantine' : 'main', $r[3], $r[0] . ':' . $r[1] . ':' . (string) $r[10], $r[4])] = !empty($r[9]) ? 'red' : 'green';
    }
    return $m;
}

/** A baseline: tools/fixtures/lrg_questline_extended_baseline.json (compact), or any earlier --ext-out file. */
function qlxBaselineLoad(string $file): ?array
{
    if ($file === '' || !is_file($file)) { return null; }
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j)) { return null; }
    if (($j['_'] ?? '') === 'lrg_questline_baseline' && ($j['mode'] ?? '') === 'extended' && is_array($j['lines'] ?? null)) { return $j; }
    if (($j['_'] ?? '') !== 'lrg_questline_extended_run') { return null; }
    $m = [];
    foreach ((array) ($j['beats'] ?? []) as $b) {
        foreach ((array) ($b['lines'] ?? []) as $l) {
            $cat = (string) ($l['cat'] ?? '');
            $set = (string) ($b['set'] ?? 'main');
            if (in_array($cat, ['say', 'say_model', 'misses'], true)) { $m[qlxKey($set, (string) $b['id'], $cat, (string) $l['t'])] = (string) $l['outcome']; }
            elseif ($cat === 'resolve_red') { $m[qlxKey($set, (string) $b['id'], 'resolve_red:' . (string) ($l['gap'] ?? '?'), (string) $l['t'])] = (string) $l['outcome']; }
            elseif (in_array($cat, ['never_red', 'q_never_red'], true)) {
                $m[qlxKey($set, (string) $b['id'], $cat . ':' . (string) ($l['gap'] ?? '') . ':' . (string) ($l['src'] ?? ''), (string) $l['t'])] = !empty($l['red']) ? 'red' : 'green';
            }
        }
    }
    return ['_' => 'lrg_questline_baseline', 'mode' => 'extended', 'measured' => ['code' => 'an --ext-out run', 'at' => (string) ($j['at'] ?? '?')], 'lines' => $m];
}

/**
 * This run against the baseline: every line whose outcome got WORSE is listed (by group, by gap), never_red rows green there and red now
 * are listed; a VERBATIM or first-evening line getting worse FAILS the run - unless it now does what its `via` names (the fixture's label),
 * a protected target now asks first (the safety rules allow that; a human confirms), the baseline's `accepted` names that change, or the
 * target is an alias-tag target (its words are unknown here).
 */
function qlxBaselineCompare(array $base, string $file): void
{
    $was = (array) $base['lines'];
    $acc = (array) ($base['accepted'] ?? []);
    $info = [];
    foreach (Qlx::$say as $r) {
        $info[qlxKey($r[8], $r[5], $r[2], $r[6])] = ['group' => $r[0], 'gap' => '', 'src' => (string) $r[1], 'via' => (string) $r[9], 'prot' => !empty($r[10]),
            'alias' => !empty($r[11]), 'fe' => !empty($r[12]), 'unaff' => !empty($r[13]), 'how' => (string) $r[7], 'beat' => $r[5], 'cat' => $r[2], 't' => $r[6], 'set' => $r[8]];
    }
    foreach (Qlx::$resolve as $r) {
        $info[qlxKey($r[4], $r[2], 'resolve_red:' . $r[0], $r[3])] = ['group' => (string) ($r[6] ?? '?'), 'gap' => $r[0], 'src' => 'pt19h-measure', 'via' => '',
            'prot' => !empty($r[7]), 'alias' => !empty($r[8]), 'fe' => false, 'unaff' => !empty($r[9]), 'how' => (string) $r[5], 'beat' => $r[2], 'cat' => 'resolve_red',
            't' => $r[3], 'set' => $r[4]];
    }
    $now = qlxBaselineNow();
    $n = ['say' => 0, 'res' => 0, 'red' => 0];
    $worse = [];
    $better = 0;
    $new = 0;
    $redAgain = [];
    foreach ($now as $k => $o) {
        if (!isset($was[$k])) { $new++; continue; }
        $w = (string) $was[$k];
        if ($o === 'red' || $o === 'green') {
            $n['red']++;
            if ($w === 'green' && $o === 'red') { $redAgain[] = $k; }
            continue;
        }
        $n[isset($info[$k]) && $info[$k]['cat'] === 'resolve_red' ? 'res' : 'say']++;
        $ra = QLX_RANK[$w] ?? 4;
        $rb = QLX_RANK[$o] ?? 4;
        if ($rb < $ra) { $better++; }
        if ($rb <= $ra || !isset($info[$k])) { continue; }
        $I = $info[$k];
        $why = '';
        if ($I['alias']) { $why = 'an alias-tag target (the words the game shows are unknown here)'; }
        elseif (in_array($o, qlxWant($I['via']), true)) { $why = 'it now does what its via (' . $I['via'] . ') names'; }
        elseif ($o === 'ask' && $I['prot']) { $why = 'a protected line now asks first (the safety rules allow it; a human confirms)'; }
        elseif ($o === 'nothing' && $I['unaff']) { $why = 'he cannot pay it: a priced line above his purse is never clicked or offered (never-false)'; }
        elseif (isset($acc[$k]) && (string) (((array) $acc[$k])['now'] ?? '') === $o) { $why = 'accepted in the baseline: ' . (string) (((array) $acc[$k])['why'] ?? ''); }
        $fails = $why === '' && ($I['src'] === 'verbatim' || $I['fe']);
        $worse[] = ['k' => $k, 'was' => $w, 'now' => $o, 'why' => $why, 'fails' => $fails] + $I;
    }
    $m = (array) ($base['measured'] ?? []);
    $ih = (string) ($m['index_hash'] ?? '');
    echo "\n== vs the baseline (" . basename($file) . ': measured on ' . (string) ($m['code'] ?? '?') . ', ' . (string) ($m['at'] ?? '?')
        . ($ih !== '' ? ', index ' . substr($ih, 0, 8) : '') . ")\n";
    printf("  %d lines compared (say %d, must-resolve %d, never_red %d): %d WORSE, %d better; %d not in the baseline\n",
        $n['say'] + $n['res'] + $n['red'], $n['say'], $n['res'], $n['red'], count($worse), $better, $new);
    $cnt = static function (array $rows, callable $by): array {
        $c = [];
        foreach ($rows as $r) { $x = (string) $by($r); $c[$x] = ($c[$x] ?? 0) + 1; }
        arsort($c);
        return $c;
    };
    $fmt = static fn(array $c): string => implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($c), $c));
    if ($worse) {
        echo '  worse by transition: ' . $fmt($cnt($worse, static fn($r) => $r['was'] . '->' . $r['now'])) . "\n";
        echo '  worse by group: ' . $fmt($cnt($worse, static fn($r) => $r['group'] . ($r['set'] === 'quarantine' ? ' (quarantine)' : ''))) . "\n";
        $res = array_values(array_filter($worse, static fn($r) => $r['cat'] === 'resolve_red'));
        if ($res) { echo '  worse must-resolve rows by gap: ' . $fmt($cnt($res, static fn($r) => $r['gap'])) . "\n"; }
        echo '  worse by source: ' . $fmt($cnt($worse, static fn($r) => $r['cat'] === 'resolve_red' ? 'must-resolve' : $r['cat'] . '/' . $r['src'])) . "\n";
        $nf = array_values(array_filter($worse, static fn($r) => $r['why'] !== ''));
        if ($nf) { echo '  worse but not a failure: ' . $fmt($cnt($nf, static fn($r) => preg_replace('/ \(.*$|: .*$/', '', $r['why']))) . "\n"; }
        echo '  FAIL (a verbatim or first-evening line got worse): ' . count(array_filter($worse, static fn($r) => $r['fails'])) . "\n";
    }
    echo '  never_red rows GREEN on the baseline code and RED now (a click came back): ' . count($redAgain) . "\n";
    foreach ($redAgain as $k) { echo "    red again: $k\n"; }
    if ($worse) {
        echo "  every worse line (group [gap] beat kind/source \"sentence\": was -> now (how); FAIL = a verbatim or first-evening line):\n";
        usort($worse, static fn($a, $b) => [$a['set'], $a['group'], $a['gap'], $a['beat'], $a['t']] <=> [$b['set'], $b['group'], $b['gap'], $b['beat'], $b['t']]);
        foreach ($worse as $r) {
            printf("    %s %s%s %s %s \"%s\": %s -> %s (%s)%s\n", $r['fails'] ? 'FAIL ' : 'worse', $r['group'] . ($r['set'] === 'quarantine' ? '/q' : ''),
                $r['gap'] !== '' ? ' ' . $r['gap'] : '', $r['beat'], $r['cat'] === 'resolve_red' ? 'resolve' : $r['cat'] . '/' . $r['src'], $r['t'], $r['was'], $r['now'],
                fx_short($r['how'], 90), $r['why'] !== '' ? ' - not a failure: ' . $r['why'] : ($r['prot'] ? ' [protected target]' : ''));
            if ($r['fails']) {
                qlChk('ext ' . $r['group'] . ':' . $r['beat'] . ' ' . ($r['fe'] ? 'first-evening' : 'verbatim') . ' "' . $r['t'] . '" is no worse than on the baseline code', false,
                    'was ' . $r['was'] . ', now ' . $r['now'] . ' (' . $r['how'] . '; via ' . $r['via'] . '); accept it only by re-baselining or an `accepted` entry in '
                    . basename($file) . ' with the ruling');
            }
        }
    }
}

/** --ext-baseline-out: this run's outcomes as a compact baseline (one line per entry, so a re-baseline diffs line by line). */
function qlxBaselineWrite(string $file, array $opt): void
{
    $lines = qlxBaselineNow();
    ksort($lines);
    $F = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $hdr = ['_' => 'lrg_questline_baseline', 'v' => 1, 'mode' => 'extended',
        'measured' => ['code' => (string) ($opt['baseline-label'] ?? 'this tree'), 'at' => date('Y-m-d H:i T'), 'index_hash' => (string) ($opt['index_hash'] ?? ''),
            'fixture_md5' => (string) @md5_file((string) ($opt['fixture'] ?? ''))],
        'about' => 'tools/test_questline.php --extended: each line\'s outcome on the code named in `measured` - say / say_model / misses lines and the measurer\'s '
            . 'must-resolve rows (fast / tkey / ask / show / nothing / sibling / wrong), never_red rows (red / green); keyed set|beat|kind|sentence. A later '
            . '--extended run lists every line that got worse, by group and gap, and fails a verbatim or first-evening one (research/pt19h-harness.md, round 2). '
            . 'To accept a change: re-baseline with --ext-baseline-out, or add an `accepted` entry {"<key>": {"now": "<outcome>", "why": "<the ruling>"}}',
        'accepted' => new stdClass()];
    $s = rtrim(substr((string) json_encode($hdr, $F | JSON_PRETTY_PRINT), 0, -1));
    $s .= ",\n    \"lines\": {\n" . implode(",\n", array_map(static fn($a, $b) => '        ' . json_encode((string) $a, $F) . ': ' . json_encode($b, $F), array_keys($lines), $lines)) . "\n    }\n}\n";
    if (json_decode($s, true) === null) { fwrite(STDERR, "the baseline does not re-parse - not written\n"); return; }
    file_put_contents($file, $s);
    echo "baseline written: $file (" . count($lines) . " lines)\n";
}
