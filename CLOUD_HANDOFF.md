# Cloud handoff - LoreRim Glue, menuless questing v1.0 hardening

Read this first. You are the CLOUD half of a split: heavy agent work happens here (cloud credits); the owner's LOCAL
session keeps the things only his machine can do. Everything lands inside LoreRim Glue itself (one MO2 mod + one
HerikaServer ext plugin). The owner calls the currency septims.

## What the cloud can and cannot do
- CAN: edit everything under `glue/`, run the PHP suites and the questline harness (PHP 8 CLI; `apt-get install -y
  php-cli php-mbstring` if missing), run the flows, write research notes, commit and push to a branch.
- CANNOT: compile Papyrus (Windows PapyrusCompiler from the MO2 install), deploy to HerikaServer (the owner's WSL),
  install into MO2, start Skyrim. Leave `.psc` edits compile-clean by construction (no try/catch, no {docstring} over
  500 chars, OnInit trivial, None never cast to a typed array) and say in the push message which `.psc` changed; the
  local session compiles, deploys and installs.

## How to run the tests (from `glue/`)
The prompt index the tests need is committed at `glue/server/lorerim_glue/data/prompt_index.ndjson` (+ service
catalog, scene index). Run: `php tools/test_gates.php --quiet`, `test_intent`, `test_phrases`, `test_dialogue`,
`test_prompt_index`, `test_mcm_wiring`, `test_services`, `test_scene_index`, `test_latency_prompt`;
`php tools/test_questline.php` plain, `--words`, `--first-evening`, `--extended`; `php tools/flows/run_flows.php --quiet`.

## State (2026-09-25)
- Shipped in game: script 512 (buying food/drink by voice, quiet mode for Helgen, butt physics, invitation-frame
  recogniser). Built but NOT installed: menuless v1.0 (script 513) + hardening round 1.
- Spec and contracts: `research/pt19-menuless-v1-spec.md`, `research/pt19c-interaction-model.md`,
  `research/pt19c-capability-map.md`, `research/pt19c-language.md`, `research/pt19c-chim-brief.md`,
  `glue/PROTOCOL.md` (10.13-10.29). Coverage of the whole game: `research/pt19x-coverage/COVERAGE.md` (gaps G1-G20).
- Hardening round 1 notes: `research/pt19h-<safety|grading|reach|money|quest|harness>.md`, measure
  `research/pt19h-measure.md`. Round 2 never ran (usage limit): the reviewers' open problems per fixer are in
  `research/pt19h-r2-problems.json` (keys safety, grading, reach, money, quest, harness -> problems, gaps_open).
- Done by the orchestrator after round 1 (committed): a question never takes a protected line from the fast path and
  must be the line's own question (lrgDlgQuestionsSame); one shared subject word no longer carries a line when his
  other content words are foreign (lrgDlgWordsCarry); a bare "yes"/"okay" never releases a money hand-over
  (lrgDlgPaysOut); three fixture rows relabelled with reasons. --extended went from 8 fails to 3, then the relabels.

## What remains, in order
1. test_gates section (g), 3 fails - the enlistment fast path `lrgFacArbitrateWant` (lib/lrg_factions.php). Probe:
   Tullius "do you think I should join the Legion?" returns {i:0} (the commit, handed to the commit rail by the r2
   'asks' branch - the rail should then click nothing); test expects false. Ulfric "Yes sir, I want to join the
   Stormcloaks and fight for Skyrim" returns false; test expects null. Isran "wait, i'm here to join the Dawnguard?"
   returns {i:2}; test expects false. Decide per the interaction model whether code or test is wrong; the invariant
   is: a question / echo / deferral NEVER clicks the enlistment commit end to end (assert the end-to-end outcome
   through lrgDlgAnswerWant, not only the raw return).
2. test_dialogue: (l) a second enlistment ask 30 s later with no answer must be 'pending' (nothing sent again);
   two G16 hand-over rows ("Very well." = Harkon's hand-over; "here, take the fragments" = Eorlund 0E3064).
3. Re-run --extended; every `never` row must click nothing; report the remaining `never_red` by gap.
4. Round 2 of the hardening: resolve or rebut every problem in `research/pt19h-r2-problems.json`, fixer by fixer,
   with regression tests. Then all suites + questline modes + flows green TWICE on one tree.
5. Update `glue/PROTOCOL.md` / `glue/OWNER_MENULESS_V1.md` for anything that changed; push; tell the owner the local
   session must pull, compile (tools/compile.ps1), deploy (tools/deploy_server.ps1 with the CHIM launcher running)
   and install (hash-verified copy into F:\Modlists\LoreRim\mods\LoreRim Glue, MO2 open is fine, Skyrim closed).

## Rules that never bend
Never lower a matcher floor to make a paraphrase pass (make her ASK instead); never delete or weaken a `never` test
row to go green unless the row is provably mislabelled (write why); a question, negation, deferral, echo, hedge or
near-miss must never click a commit, crit, priced, scripted or irreversible line; never silent / never empty /
never false; condition truthfulness; light on latency. Keep agent counts sensible - the owner is paying and asked
for quality over speed, with independent verification of every fix.
