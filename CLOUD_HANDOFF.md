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

## Round 2 status (the cloud session, 2026-09-25) - see `research/pt19h-r2-resolution.md`
Items 1-5 above are done as far as the cloud can take them; the code is server-only (no `.psc` changed, no wire item):
1. test_gates (g) rewritten end to end through `lrgDlgAnswerWant` (a question / echo / deferral never clicks the
   enlistment commit; a wrong faction's yes is no yes): 852 / 0.
2. test_dialogue (l) `pending` (an "or not" question is no hedge - `lrgFacAskQualm`), the two G16 rows (the object-aware
   hand-over rule: `hand` words out of the norm, `lrgDlgHandsOver` / `lrgDlgHandOverPick`), plus the round-2 regression
   section at its end.
3. `--extended`: every `never` (2,463) and `not_target` (343) row clicks nothing; `never_red` still red 36 (13 on a
   protected line), listed by gap in the resolution note; no `never_red` row that was green on the 06:00 code is red again.
4. Every problem of `research/pt19h-r2-problems.json` resolved, landed earlier, rebutted or classed as measurement, with
   the reason - the table in the resolution note. The main new rules: the key-mode rail (a scripted plain line on her
   T-key: nothing on a refusal / deferral / hedge / negation, a PARK on a question - as the commit it converged from did),
   the same-question half-coverage rule, the words path's narrowing rule on question lines and its park on a one-word
   STT echo of a protected line, the reach lane's "his request only" guards, the breath re-arm honouring S4.5's
   refusals, and the "no" that opens no refusal ("no one", "no joke").
5. `glue/PROTOCOL.md` 10.29 section 14 and `glue/OWNER_MENULESS_V1.md` (sections 2 and 7) carry the amendments.
   Two rulings are written into `tools/fixtures/lrg_questline_extended_baseline.json` `accepted` (Urag's two radiant
   starts make her ask which; MGRitual05's help line is the work line on that list) - the reasons sit next to them.
   The runs (all sequential - two harness processes at once pollute each other's log reads and give false failures):
   every suite, every questline mode and the flows green twice on the final tree, the extended coverage green on it
   (`test_scene_index.php` and flow `[18]` need the MO2 profile and can only run on the owner's machine).

## What remains, in order (the local session)
1. `git pull` the branch `claude/eager-wozniak-gvumed` (or merge it into main).
2. Run `tools/test_scene_index.php` and `tools/flows/run_flows.php --quiet` once locally (the two environment-bound
   checks), then `tools/compile.ps1` (no `.psc` changed: it must be a no-op, but prove it), `tools/deploy_server.ps1`
   with the CHIM launcher running, and the hash-verified copy into `F:\Modlists\LoreRim\mods\LoreRim Glue` (MO2 open is
   fine, Skyrim closed).
3. The first evening, per `glue/OWNER_MENULESS_V1.md` section 3; keep `lorerim_glue.log` and `AIAgent.log`.

## Rules that never bend
Never lower a matcher floor to make a paraphrase pass (make her ASK instead); never delete or weaken a `never` test
row to go green unless the row is provably mislabelled (write why); a question, negation, deferral, echo, hedge or
near-miss must never click a commit, crit, priced, scripted or irreversible line; never silent / never empty /
never false; condition truthfulness; light on latency. Keep agent counts sensible - the owner is paying and asked
for quality over speed, with independent verification of every fix.
