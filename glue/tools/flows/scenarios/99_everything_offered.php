<?php
// 99 - runs last: a look back over EVERYTHING the plugin offered to the LLM or sent to the game during the main run.
// Rail: forced / non-consensual and creature scene categories never appear (they contradict the consent model), the player
// thread always has exactly two actors, and a transition is never a target. With --only this judges only what did run.
fx_scenario('99', 'look back: every scene id offered or sent during this run is an allowed two-person scene', function (FxT $t) {
    $seen = Fx::$seenScenes;
    if (count($seen) < 5) { $t->pending('look back', 'fewer than 5 scene ids were seen (run the whole suite, not --only=99)'); return; }
    $bad = [];
    foreach ($seen as $id => $where) {
        $why = fxSceneProblem((string) $id);
        if ($why !== '') { $bad[] = "$id ($where): $why"; }
    }
    $t->must(count($seen) . ' scene ids were offered as options, as furniture moves, as start / goto / wind-down targets: none is excluded, on the hard list, a transition or not a two-person scene', $bad === [], implode(' | ', array_slice($bad, 0, 8)));
    $wire = array_filter($seen, fn($w) => str_starts_with((string) $w, 'wire'));
    $t->must('the look back saw real traffic: ids that reached the wire as well as offered options', count($wire) >= 1 && count($seen) > count($wire), count($wire) . ' wire / ' . count($seen) . ' total');
});
