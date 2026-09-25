# pt19h round 2 - resolution of the problem list (2026-09-25, the cloud session)

Input: `research/pt19h-r2-problems.json` (78 problems in six lanes, 38 open gaps), the plan in `CLOUD_HANDOFF.md`.
Output: the code under `glue/server/lorerim_glue/` (server only - `lib/lrg_dialogue.php`, `lib/lrg_factions.php`,
`lib/lrg_actions.php`, `lib/lrg_speech.php`, `config/lrg_config.default.json`), the tests under `glue/tools/`, and the
amendments in `glue/PROTOCOL.md` 10.29 section 14 and `glue/OWNER_MENULESS_V1.md`. No Papyrus file changed, no wire item,
no matcher floor.

Every problem below is **resolved** (code + a regression test), **landed earlier** (found already fixed on the tree this
session started from - commit 800ebed, "Menuless questing v1.0"), **rebutted** (judged not a defect, with the reason), or
**measurement** (a finding about a report or a method, not the code). The code tags are `[pt19h r2 / <lane> P<n>]` (the
numbering below), `[pt19h-<lane> r2 / ...]` for the items the previous session landed, and `[pt19h r2 / extended <beat>]`
for the rows the extended coverage raised.

## The rules that held

- No matcher floor was lowered. Where a paraphrase did not resolve, she asks (a park, S4.4) or the model's key decides.
- No `never` row was deleted or weakened. Two fixture labels are ruled on in the baselines (section "The baselines"), with
  the ruling written next to each.
- A question, a negation, a deferral, an echo, a hedge or a near-miss never clicks a commit, a check, a priced, a scripted
  or an irreversible line: `--extended` asserts 2,463 `never` rows and 343 `not_target` rows - click 0.

## Safety lane (24)

| # | problem | status |
|---|---|---|
| P1 | [use] cost of the safety edits understated | measurement - the harness round-2 baselines (`--words`, `--extended`, line by line) now show every say line that gets worse; this session's runs list them (below) |
| P2 | [use] own-word rule too strict (auxiliaries, informal forms, STT sound-alikes) | resolved - `lrgDlgExplicit`: the question frame carries am/is/are/.../wanna/gonna/gotta, whos/whats/wheres/hows, im/ill/ive/id/youre/thats/its, or/not; the assent lead of the line is no content word (`lrgDlgLeadPhraseRaw`) |
| P3 | [use] same-question test rejects paraphrases | resolved - `lrgDlgQuestionsSame1`: the "is there / do you" split only when the "you" side asks her knowledge; how/where and what/where find-get questions agree; the same question word about the same thing needs half of the line's content words (`what do they want with me` / `What do these Greybeards want with me?`) and, on a two-thing question, no content word of his own |
| P4 | [use] the verbatim line refused (`Kodlak, is that you?`) | resolved - `lrgDlgWordsQualm` verbatim shortcut (his form of address included), the vocative stripped before the question kind is read |
| P5 | [use] lead-in lines ending in an ellipsis do nothing | resolved - `lrgDlgQuoteQualm`: a "?" after the line counts only when the line does not end in one, the raw run of the line is read (`lrgDlgRawRun`) |
| P6 | [use] refusal- or hedge-shaped lines said in other words end at nothing | resolved - `lrgDlgSameLead`: the same kind of lead (not-knowing, declining, both hedges) is the line's own lead, not a refusal |
| P7 | [use] STT folds the negation and statement tests miss | resolved - "all most" / "near ly" folded before parity; `lrgDlgSoundsLike` knows hoo/wat/ware/wen/wich |
| P8 | [arch] the breath re-arm ignores the S4.5 refusals | resolved - `lrgDlgRearmLine` returns null after a quote-qualm refusal ("<line> later", "not now, <line>"), a keep, or a deferral; R1's "I don't understand" still re-arms (test_dialogue r2 rows) |
| P9 | [arch] the slot branch of `lrgDlgExplicit` returns before step 0 | resolved - the slot shortcut sits after the refusal test and takes no question that is not a request |
| P10 | [arch] the S4.4 park release lets an echo / a hedge / a deferral through | resolved - `lrgDlgParkOrRelease`: a refusal or a deferral around the parked line un-parks, an echo or a hedge keeps it, a question restates it only when the line is itself a question; a park written from his bare assent is released by his next assent |
| P11 | [arch] LEAVE looks for the back-out on the ranked head only | resolved - `lrgDlgDecide` LEAVE reads entries + tail |
| P12 | [arch] `[leave]` label drift | resolved - `lrgDlgLabel` prints `[leave]` only on `lrgDlgRealBackOut` lines |
| P13 | [lang] `lrgDlgDeclares` before the single release refuses "yes, I'll help" | resolved - `lrgDlgDeclares` never on an assent; `--first-evening --words` green |
| P14 | [lang] STT variants lose their explicit line | resolved with P2 (the frame and the sound-alike test); the extended `--words` comparison lists no first-evening or verbatim loss |
| P15 | [lang] `lrgDlgDeclares` too broad (a flattened yes/no question, a request in statement form) | resolved - a "you ... any" question, a first-person need ("I need a room") and consecutive duplicate tokens are no statement against a question line |
| P16 | [lang] `lrgDlgQuestionsSame` too strict (possession, how/what, where/how) | resolved with P3 |
| P17 | [lang] `lrgDlgQuoteQualm` reads a follow-up question as an echo | resolved with P5 |
| P18 | [lang] a false deferral after the quoted line ("first", "soon") | resolved - `lrgDlgDeferAfter`: a deferral only right after the line |
| P19 | [lang] unanchored refusal phrases drop parks | resolved - `lrgDlgIsBackOut`: "let's not / better not / not today / some other time / go away" anchored to the lead |
| P20 | [lang] the hedge rule differs by path | resolved - one `lrgDlgHedges` on every path; the config's whole assent phrases ("I guess so") stay assents (`$assentWhole`) |
| P21 | [lang] the 40 % fragment rule counts an assent particle | resolved - the line's assent lead is removed from its content words |
| P22 | [lang] almost / nearly scope, barely / hardly + an achievement | resolved - `lrgDlgNegationClash`: "but" opens a clause, almost / nearly reach two tokens, barely / hardly / scarcely negate only a stative; and (this session) a bare "never" after the subject negates its predicate as "didn't" does |
| P23 | [lang] the report is incomplete (`--words` not run) | measurement - every mode is run in this session, twice |
| P24 | cross-lane: the verbatim Legion line refused by `lrgFacAskActs` | landed earlier (quest use P1) - `test_questline --words` green on CW00A.tullius.join |

Gaps the safety fixer left open: G1 12 (service kind picks on a refusal / deferral / hedge) - resolved, `lrgDlgKindPick`
returns none on them; G1 3 ("no, <line>" on Brotherhood lines that themselves refuse) - rebutted as the fixer did: the
click is correct, the rows stay `never_red`; G5 1 and G9 15 - the extended run's `never_red` table lists what is still red
(below); the 4 plain-line near-misses "with no lexical signal" stay reported.

## Grading lane (15)

| # | problem | status |
|---|---|---|
| P1 | [arch] the G3 regrade removes the only guard on the model's key path | resolved - THE KEY-MODE RAIL (`lrgDlgKeyRailWhy`): a plain line that still runs a script passes it on her T-key; a refusal / deferral / hedge / negation / keep -> nothing; a question of his, the line asked back, or a hedge around it -> a park (she asks, quoting it), as the commit it converged from did; `dialogue.grading.converge_plain` (true) names the switch |
| P2 | [arch] never_auto / fcommit do not hold on a single-entry layer | resolved - `lrgDlgSingleEntryRelease` step 6: a never_auto / fcommit single, or one priced at `confirm.min_gold` or more, is never released by his words alone (the model asks, naming it) |
| P3 | [arch] a click after leave words (03BC99 auto-advances after "goodbye") | resolved - leave words block the first auto-advance |
| P4 | [arch] NOTE: "I'm keeping the amulet" clicks the hand-over | resolved - `lrgDlgKeepsIt` refuses the single release, the words path and the hand rule |
| P5 | [use] BLOCKING: the Legion side switch 05A6A6 fell to plain | landed earlier - `config/lrg_dialogue_overrides.default.json` marks `MQ103BTulliusBookB1` never_auto |
| P6 | [use] collateral: Vex's "You sure you won't buy it?" reads his agreement as a refusal | resolved - two questions compare only not / no / never in the negation parity |
| P7 | [use] the report counts asks removed, not added | measurement - the baselines list them |
| P8 | [use] LLM-path scale of the T-key exposure | resolved by P1 |
| P9 | [use] context (outcome shares) | measurement |
| P10 | [code] BLOCKING: hand-over object words lead the match norm (G16 floor) | resolved - the object words are `hand`, never in the norm; the hand rule (`lrgDlgHandsOver`, `lrgDlgHandOverPick`) reads his giving frame |
| P11 | [code] G16 regression: `lrgDlgWordsCarry`, not the explicit test, is the guard | resolved with P10 (the test_dialogue Harkon rows: "very well, take it" hands over, "alright, uh, very well" does not) |
| P12 | [code] T-key exposure from G3 | resolved by P1 |
| P13 | [code] 03BC99 no longer class back | resolved with P3 |
| P14 | [code] G15 reaches ~480 rows | landed earlier (`[pt19h-grading r2 / review G15 reach]`: the barter and fence openers keep class service by their topic) |
| P15 | [code] note on `lrg_prompt_index.php` edits | measurement - verified, no action |

## Reach lane (11)

| # | problem | status |
|---|---|---|
| P1 | [use] BLOCKER: the work ask reaches too wide | resolved - `lrgDlgReachWorkAsk`: never a deferral, a hedge, a refusal or a take-back anywhere in the sentence; never a second- or third-person subject ("did you find any work?", "who's looking for work?"); hiring and repairs are no ask; `not_after` grew (done, being, with, here, from you, or should, elsewhere); the same guard on the pre-LLM open |
| P2 | [use] G4 wrong-line clicks (Urag's two work lines, MGRitual05) | resolved - `reach.work.entry` carries Urag's books line, so two radiant starts make her ask which (test_services); MGRitual05's pick of "You look like you could use a hand." is ruled defensible, as the fixer said (baseline ruling below) |
| P3 | [use] the report pick reads a question line as a report | resolved - `lrgDlgReachReportNames` skips question lines |
| P4 | [use] G6 double carrier with the escort | resolved - `lrgEscortPlan` stands down for `follow` while her menu is driven by his words (a pick within 15 s, or an open session) |
| P5 | [lang] a one-word STT echo clicks a protected line | resolved - `lrgDlgWordsCarry` carries a one-word echo on an unprotected line only; on a protected one the words path now PARKS it (she asks, quoting it) - "i'd like to rent a groom" asks |
| P6 | [lang] a hedge / question / deferral clicks a scripted line through the reach pick | resolved - `lrgDlgKindPick` refuses on a refusal / deferral / hedge; `lrgDlgReachPick` passes a protected entry through the qualms (quote, hedge, deferral, bargain, negation - a report phrase with its own "won't" excepted); a musing ("I wonder if there is anything I can do") is no ask |
| P7 | [lang] the report reading ignores who died | resolved - the report phrase must name the line's target, never the name as the doer, never a plan or hearsay (`report.not_with`) |
| P8 | [lang] the work ask reads requests that are not his offer | resolved - help offers to somebody else, second-person asks, "need work done", "got a job to do", "anything I can do to <verb>" are no ask; on a closed layer only a radiant start whose phrase leads the line |
| P9 | [lang] natural work offers do not resolve | resolved - `reach.work.say` + "what can I do for you", "anything I can do for you", "let me help", "I want to help", "how may I help", "any errands", "any bounties", "need a hand", "got anything for me" |
| P10 | dependency: MS05.viarmo.comein fixture label | landed earlier (the harness lane relabelled it; `test_questline` green) |
| P11 | verified OK (G6 deferrals / negations / questions) | measurement |

## Money lane (13)

All landed earlier (`[pt19h-money round 2 / ...]` tags in `lib/lrg_speech.php`, `lib/lrg_intent.php`, `lib/lrg_dialogue.php`),
verified by `tools/test_gates.php`, `tools/test_intent.php` and `tools/test_dialogue.php`: arch P1 (the G13 ask rail before
the min-words rail), P2 (0C9A08 re-priced on `ev=line`), P3 (only a demanded sum prices a line), P4 (a park written from his
bare assent is released by his next assent - `[pt19h r2 / money arch P4]`), P5 (a price question with a figure keeps its
figure); lang P1 ("a grand total"), P2 ("five and twenty"), P3 (a later clause still demands the reward), P4 (a vague coin
quantity is a bargain), P5 ("rewarded in Sovngarde"), P6 (STT forms of the evidence), P7 (question-shaped bribe offers are
attempts - `lrgDlgCoinOffer`), P8 (a deferral is never priced). This session adds: the G13 ask rail reads the line with one
STT slip as the line ("does it madder here" / "Does it matter? Here.", extended DA10.logrolf.bribe).

## Quest lane (10)

Landed earlier (`[pt19h-quest r2 / ...]`): use P1 (the verbatim join line that carries refusal words), P2 (the clause that
holds the join phrase is judged, polite requests ask), P3 (a hedge or an echo makes her ask - `lrgFacClickAsk` returns [] and
`lrgFacArbitrate` stands down; this session pins the words path: "maybe I want to join the Legion", "I want to join the
Legion?", "do you think I should join the Legion" + the model's words that ARE the join line click nothing), arch P2 (the
faction he named must be hers - "yes sir, but I want to join the Stormcloaks instead" to Tullius is no yes, test_gates (g)),
P3 (open-first only when the open can bring the line), P4 (the "already" line on alternative-branch stages). Use P4 and the
two context notes are measurement. This session's test_gates (g) rewrite proves the invariant end to end through
`lrgDlgAnswerWant`: a question, an echo or a deferral never clicks the enlistment commit (852 checks).

## Harness lane (5)

All landed earlier (`[pt19h-harness r2]`): P1 (the Companions decline beats' live_text), P2 (MQ05's never row), P3 (the
line-by-line baselines - `--words` and `--extended` against the 06:00 code; a verbatim or first-evening line getting worse
fails), P4 (alias-tag targets kept out of the tallies), P5 (W.open.balgruuf / W.irileth.B1 folded). This session ran every
mode against those baselines (below).

## The extended rows this session raised

- `MGRArniel04.courier` "it never arrived, the courier didn't come": a false negation clash (the line's bare "never" after its
  subject was not read as "didn't") - resolved, parity.
- `TG08A.karliah.possess` "no one should have it", `DialogueWinterholdCollege.urag.elderscroll` "no joke, you can have it": a
  leading "no" that opens no refusal - resolved, `lrgDlgIsBackOut` exempts "no one / no joke / no wonder / no kidding / no
  matter / no idea".
- `MQ04.neloth.justtell` "where's the book", `SV01.tharstan.paying` "something dangerous?", `RR02.tilisu.lying`: the key rail
  dropped what the commit used to park - resolved, the rail parks on a question, an echo or a hedge.
- `TGCSG.buyback.ask` "can I buy that unusual gem back?" clicked the shop line by kind after the rails left the buy-back line
  to the model - resolved, the kind pick never clicks another line than the one his words matched best.
- `MS01.margret.business` "what do you think of Markarth" / "What are you doing in Markarth?" - a same-question false positive:
  resolved (the content-word rule with the own-word test).
- `FE.riverwood.plain` / `W.hulda.rumors` / `W.corpulus.rumors` (words mode): "sing me something about dragons", "got any songs
  about dragons", "any rumors about the dragons" no longer resolved on the words path (the G9 one-shared-word rule read the
  narrowing word as foreign) - resolved, `lrgDlgNarrowsSubject` on a question line only.

## The baselines (rulings written into `tools/fixtures/*_baseline.json` `accepted`)

Filled in from the final `--extended` run of this session (see the run log in `CLOUD_HANDOFF.md`):

Eighteen `accepted` entries on five beats in `tools/fixtures/lrg_questline_extended_baseline.json` (the words baseline needs
none: its one change, "i'd like to rent a groom" resolve -> ask, is a protected line asking first, which the comparison allows):

| beat | rows | was -> now | ruling |
|---|---|---|---|
| MGR20.business | the six G4 work asks ("got any work?", "can I help?", ...) | tkey -> ask | Urag's list carries two radiant starts (College business / the special books): a work ask makes her ask which (reach P2) - the measurer's generic probe named one of them |
| MGRitual05.start | the seven G4 work asks | tkey -> wrong | the target ("Is there anything more I can learn about Alteration magic?") offers no work; "You look like you could use a hand." is the offer of help on that list and the work ask picks it - the fixer called this pick defensible (reach P2) |
| C06.eorlund.honor | "here are the fragments", "here's the fragments", "here, take the fragments" | ask -> sibling | both lines hand the fragments over; his words name the sibling "Here, take them. (Give fragments)", clicked as he said it (the harness's own sibling reading) |
| CW02A.tullius.handled | "nothing i could and handle" | ask -> nothing | an STT garble of the class-back "Nothing I couldn't handle." with its negation lost: a refusal-shaped fragment that says the opposite - her words answer |
| DA10.logrolf.bribe | "does it madder here" | ask -> nothing | the bribe line itself with one STT slip, priced 100 by the engine: his words name no sum, so the G13 rail has her name her price in words (S4.10: 100 septims or more asks first); the harness counts her words as nothing |

Not ruled, left as the fixture reports them - the `never_red` rows still red (36, all red on the 06:00 code too; none came back):
- G1 (5): "no, i won't condemn an innocent man", "no, nah, I'll make my own way, thanks", "no, i won't disappoint you, Astrid ..." - his
  "no" agrees with a line that itself refuses (the safety fixer's G1-3 ruling: the click is right); "tell me about the first contracts"
  (x2) on Nazir's "I'm ready for the first set of contracts." - a request for what the line starts; kept reported.
- G5 (27): mostly the coverage team's "wait, <line>?" and "<line>?" probes on SINGLE-entry layers whose line is unfinished ("... I mean
  I...", "Astrid...", "Surely the Night Mother wouldn't misdirect us...") - S4.5 reads the line said whole, and an unfinished line
  completed with a rising "?" is that line (safety P5); plus "forget the deed" / "forget Mercer" / "no problem" on singles (15 of them
  are the breath - the auto-advance of an unscripted single, not a click on his words), and "is there any work in Riverwood" on
  CR14's work line (a work ask by kind).
- G9 (3): "what's a wayshrine?" / "Wayshrine?", "can't you take us to it now?" / "Does that mean you can take us to it now?",
  "who is the Gourmet here?" / "Now, now, Gianna. Who's the Gourmet here?" - the same question on a single.
- G11 (1): "my wealth is my own business" / "My wealth is none of your business." (a paraphrase of a protected line, intent).
- quarantine: G9 2, G10 1, G11 1 (the same shapes), G13 2 ("how much would it cost?" - her KEY releases the bribe; the fast path is
  green).

## The runs

Every run strictly one process at a time. (Two harness processes at once read each other's lines out of the shared
`log/lorerim_glue.log` - `qlxFastAsked` and the dialogue suite's marker checks grep it - and report false failures: eight in
`test_dialogue` v30, one in the plain questline, one in `--words`, and the "ask (words)" rows of the first extended pass, all
of which vanished when the same suites ran alone.)

Run 1 (the final tree, code as committed):
- test_gates 852 / 0; test_dialogue 1295 / 0 (25 round-2 rows among them); test_services 136 / 0; test_intent 470 / 0;
  test_phrases 47 / 0; test_prompt_index 107 / 0; test_mcm_wiring 49 / 0; test_latency_prompt (a measurement, mean 2306 chars
  / 576 tokens per turn over 10 fixtures).
- test_questline plain 1956 / 0; `--words` 2194 / 0 (39 say lines worse than the 06:00 baseline, none verbatim or first-evening,
  26 better); `--first-evening` 467 / 0; `--first-evening --words` 534 / 0 (1 worse: the groom row, a protected line asking first).
- flows 87 of 89 scenarios: `[18]` needs the MO2 profile (its warm scene-index build), `d68` pending by design (gate B).
- `--extended` 2808 / 0: `never` 2,463 asserted, click 0; `not_target` 343, click 0; `never_red` 1,860 reported, still red 36 (13 on
  a protected line - the list above); 22,088 lines against the 06:00 baseline: 1,298 worse (fast->tkey 884, tkey->ask 207,
  fast->ask 121, tkey->nothing 45, ask->nothing 25, tkey->wrong 7, fast->nothing 6, ask->sibling 3), 1,368 better; not a failure:
  "it now does what its via names" 623, "a protected line now asks first" 283, "he cannot pay it" 31, alias-tag targets 7;
  a verbatim or first-evening line got worse: 0; `never_red` rows green on the baseline code and red now: 0.
- Not runnable here: `test_scene_index.php` (the MO2 profile) - the owner's machine.

Run 2 (the same tree, the rulings written): RUN2_PLACEHOLDER
