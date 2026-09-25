# pt19 - AI DESIGN DIRECTOR, round 2 review of `research/pt19-menuless-v1-spec.md` (revision 1)

2026-09-24. Read-only. Same lens as round 1 (`pt19-oversight-ai-r1.md`): what the model is told vs what is enforced,
reliability against paraphrase and STT noise, the never-false / never-empty / never-silent rails, latency and token cost,
model-agnostic robustness, the matcher / model split. Sources this round: the revised spec (every `[rev1]` item), the three
design notes and the survey, the code the spec names (`lrg_dialogue.php`: lrgDlgMatchText :2220, lrgDlgBusinessMarker,
lrgDlgIsBackOut :1375, lrgDlgServiceKind :3863 and `services.kinds` :180-229, `service_words` :363; `lrg_prompt_index.php`:
lrgPromptWords :186, lrgPromptLookup :365; `LRG_Main.psc`: OnChimSpeechStarted :3442, the sentence-gap logic :4223-4227),
the live index (37,561 rows, hash e756e311), and two scripts over them in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\`:
`r2_single.php` (the revised S4.5 rules replicated byte for byte over the spec's test-21 rows plus adversarial lines) and
`r2_index_stats.py` / `r2_index_b.py` / `r2_index_c.py` (stop-list counts, the owner page's Hulda line, quest membership of
the service topics). Marks as before: **[C]** code, **[X]** index, **[M]** the matcher run, **[S]** spec section.

## 0. Verdict

**Not approved as written - but every remaining defect is in one of five places and each fix is a paragraph, not a
redesign.** Revision 1 folded in all eleven of my round-1 requirements faithfully: the shape test, the shared assent list,
the decision twin (`lrgDlgDecide` / `lrgDlgWillEmit`), the talk key, `named` by her question, the fresh `last_exec` read
plus the bridging directive, the new-utterance guard, the kept rails plus the read-only clause, the harness built from
layer lines, plain-sentence refusals, and the window-gated reward branch. The shape is right and it is what I would ship.

What is still wrong reaches the owner on the first evening, so it must move before the lanes start:

1. The revised single-entry content test, run through the real scoring formula, **enlists him in the Legion on "long live
   Ulfric"** (0.721) and **clicks Farengar's assignment on "I need a drink"** (0.783) - and seven of the spec's own
   test-21 paraphrases score BELOW its 0.65 floor, so test 21 is red on both sides. The reason is structural: the F1
   score cannot tell "what do you need done" from "I need a drink" on an entry whose only meaning word is `need`.
2. The widened stop list costs the fast path more than it buys: it doubles the index's zero-meaning-word lines
   (70 -> 140) and adds 448 one-word lines, and it turns a fixture pick ("tell me who you are" on "Who are you?") from
   0.78 into 0.37 - while none of the spec's own numbers needed it.
3. The narrow pre-LLM marker, as its clauses are actually implemented, **does not fire on the owner page's step 2** ("Nice
   inn you have here, do you get many visitors?" is in `ACFDialogueWhiterun`, which is NOT in Hulda's `q`), nor on "what
   have you got?" / "I'd like a room" / "I need a bed" at an innkeeper whose root is not yet cached - so the very first
   thing the page tells him to say is answered twice, through the post-LLM open with no bridging line.
4. The re-armed auto-advance ("who are the Greybeards?" during the breath) anchors its 2.5 s on a vanilla subtitle that
   is already clear, so it clicks Balgruuf's next line 2.5 s INTO her CHIM answer - the mid-sentence click the rule was
   written to prevent, on the exact example the owner page uses.
5. `okay` releases a parked commit and `ok` does not; the microphone, not the owner, chooses the spelling.

Everything else I checked holds (section 3). Round 2 is the last round, so each requirement below carries its exact
numbers, the rule that fixes it, and the test row that proves it.

## 1. REQUIRED changes

### R1. S4.5 scripted content test: add a precision term and an "entry has <= 2 meaning words" rule; the rows the spec lists do not pass as written

**What [M].** `r2_single.php` replicates lrgDlgMatchText exactly (`score = max(exact 1.0, containment 0.85,
0.35 + 0.65 * F1, similar_text * 0.8)` **[C :2220-2262]**), the widened stop list, the shape test and the per-`scripted`
content test of S4.5 as revised. Results on scripted singles at the 0.65 floor:

| entry (meaning words under the widened list) | utterance | S4.5 rev1 | wanted |
|---|---|---|---|
| MQ103 B1 `so what do you need me to do` [need] | "I need a drink" | RELEASE 0.783 | nothing |
| same | "I need to think" | RELEASE 0.783 | nothing |
| MS05 Task2 `what do you need me to do` [need] | "I need a drink first" | RELEASE 0.675 | nothing |
| MS05 App2 `and that's where i come in` [come] | "can I come in" | RELEASE **1.000** (F1 = 1 on the one word) | nothing |
| MQ104 D1 `what do these greybeards want with me` [these, greybeards, want] | "the Greybeards want me dead?" | RELEASE 0.783 | nothing (a question to HER) |
| MQ105 HornA2 `thank you what's next` [thank, next] | "thank you for the horn" | RELEASE 0.675 | nothing |
| CW01A Oath4 `long live the emperor long live the empire` [long, live, emperor, empire] | "long live the Stormcloaks" | RELEASE 0.721 | nothing |
| same | "long live Ulfric" | RELEASE 0.721 | nothing |
| same | "the emperor is a fool" | nothing 0.567 | nothing (ok) |

And the spec's OWN test-21 / fixture rows that the revised rule refuses:

| entry | spec row (via single) | S4.5 rev1 |
|---|---|---|
| MQ104 D1 | "what do they want with me" | nothing - **0.645**, five thousandths under the floor |
| MQ103 HaveStone | "you mean this stone here" | nothing 0.61 |
| MQ102 reward | "is there anything else I can do" | nothing 0.61 |
| MS05 App2 | "yes, I'll do it" | nothing 0.16 (not in the assent list, no shared word) |
| MS05 Final `is that it` [] | "is that all of it" / "yes, that's it" | nothing 0.593 / 0.626 (the zero-word rule needs 0.85) |
| MQ105 HornA2 | "yes, what now" | nothing 0.39 |

Neither 0.65 nor 0.70 separates the two tables: "long live Ulfric" sits at 0.721 and "what do you need done" at 0.783,
both above both floors. F1 over one or two meaning words is not a signal; what separates them is (a) whether the
utterance carries a FOREIGN meaning word (Ulfric, Stormcloaks, drink, dead) and (b) whether its shape matches the
entry's (a statement against a question entry is not an answer to it).

**Where.** S4.5 (content test), S4.8, S4.10 rows "scripted single entry", S9 (`confirm.single_entry_*`), Lane A task,
test_dialogue 21 / 21b, fixture beats MQ103.farengar.assignment, .stone, MQ104.balgruuf.want, MQ102.balgruuf.reward,
MQ105.arngeir.next, MS05.viarmo.comein, .task, .final, CW01A.oath.4, CW01B.oath.4.

**Change.** For `scripted=1` singles, replace "an assent OR score >= 0.65" with, in order:
1. an assent (the shared list) -> release;
2. **assent-led**: the first token is an assent word AND >= 1 shared meaning word -> release ("yes, I have the stone",
   "yes, I'll help", "alright, what's the task you need");
3. exact / containment (`score >= 0.85`) -> release ("what do you need" on MS05 Task2; "all hail the Stormcloaks");
4. the entry has **>= 3 meaning words**: shape-equal (both questions or both statements) AND `score >= 0.65` AND
   **precision `shared / |utterance meaning words| >= 0.75`** -> release. This keeps "long live the Emperor" (0.907,
   prec 1.0), "long live the Empire", "the empire will live long" (0.838, prec 0.75), "hail the Stormcloaks, the true sons
   of Skyrim" (0.892, 1.0), "do you mean this old stone" (0.87, 1.0); it refuses "long live the Stormcloaks" / "long live
   Ulfric" (prec 0.67), "the Greybeards want me dead?" (0.67), "death to the emperor" (0.567);
5. the entry has **<= 2 meaning words** (the `is that it` rule widened; 2,115 of the index's 21,148 distinct scripted
   norms are in this class **[X]**): shape-equal AND >= 1 shared meaning word -> release; otherwise nothing. "what do you
   need done" (question / question, shares `need`) releases; "I need a drink" (statement / question) does not;
   "can I come in" is the one probe this still admits (question / question, shares `come`) - it is a plain non-commit
   single, the click is the only way forward on that layer, and I accept it.
6. Everything else on a scripted single waits for the model: **state in S4.5 and S4.8 that on a T-key pick of a scripted
   single that is NOT a commit the gate applies only the shape test (a question to her is never clicked) and otherwise
   trusts the model** (S4.8 already says T-keys are unaffected; S4.5's "in the gate and on want=1" reads as if the content
   test gated the T-key too - say which). On a COMMIT single (Oath4: scripted && goodbye) the T-key pick must pass the
   same content test as the fast path, else it parks and she asks, quoting the line. This is the matcher / model split
   the design rests on: the matcher guards the fast path, the model reaches.
7. `lrgDlgMatchText`'s new `f1` is 1.0 on an exact or containment hit (otherwise S4.8's `f1 > 0` blocks "is that it" said
   verbatim: its entry has no meaning words, so F1 is structurally 0).

Then re-label the fixture rows by the path that carries them (3.4 step 4 already counts a resolve on either path):
"what do you need done", "alright, what's the task you need", "tell me what you need done", "what comes next", "is there
anything else I can do", "yes, I'll do it", "yes, what now", "is that all of it", "yes, that's it", "tell me what the
Greybeards want" resolve on the LLM path (the model's T-key on a non-commit single); the verbatim, contained and
assent-led rows resolve on the fast path. "you mean this stone here" (0.61 on every path) is replaced ("you mean this old
stone" scores 0.87). "what do they want with me" needs R2's pronoun stop words to clear (0.645 -> 0.783 with `they` a
stop word; see R2) - keep it only with R2.

**Test.** test_dialogue 21 lists every row of both tables above with its expected outcome AND its path (fast / T-key /
nothing); 21b runs the fixture's single beats through `lrgDlgSingleEntryRelease` on the fast path and through the gate
with the T-key. test_gates: no assent word is a `lrgDlgIsBackOut` word (kept). The harness's `never` lines on scripted
singles hold on BOTH paths (a `never` line fed with the target's T-key must yield nothing on a commit single and only the
shape refusal on a plain one - 3.4 step 4 must say so, or the LLM path clicks "uh what now" through the forced T-key).

### R2. The widened stop list: score with the old list, judge "shared word" with the strict one

**What [X, M].** Over the live index's 21,148 distinct norms, the old stop list leaves 70 with no meaning word and 1,408
with one; the widened list leaves **140 and 1,856** (`r2_index_stats.py`). The newly empty lines are the short questions
the fast path meets most ("what can i do for you", "who are you", "what do you mean", "what now", "i can do that",
"where is it"); a zero-word entry can only match exactly or by containment (the F1 branch is skipped when either side is
empty **[C :2240]**), and a one-word entry matches ANY utterance containing that word at F1 = 2/(n+1) - which is how
"can I come in" scores 1.0 against `and that's where i come in` in R1 (`can` became a stop word). Concrete casualty in the
fixture: DB02.captive.who "tell me who you are" on `who are you` scores 0.78 with the old list and **0.37** with the new.
None of the spec's own numbers needed the widening: "uh what now" is 0.33 / F1 0 under both lists, "what comes next" 0.675
under both, "who are the Greybeards" shares `greybeards` under both. What the widening protects is only S4.5's
one-shared-word rule on unscripted singles (so `who` / `how` / `what` never count as the shared word) and S4.4's `named`.

**Where.** S4.5 bullet "The stop list widens", S9, Lane A task (`lib/lrg_prompt_index.php`), test_dialogue 21, 25 (the
`>= 2 shared meaning words` clause), fixture DB02.captive.who.

**Change.** Two lists, one function: `lrgPromptWords($norm, bool $strict = false)`. Scoring (`lrgDlgMatchText`, the
explicit release, the kind and slot paths, the narrow marker's score clauses) keeps the OLD list, so the index's reach is
unchanged and every round-1 number stands. The STRICT list (old + why how who where when which can could would should does
mean now then wait uh **+ they them these those there here we us he she him his her its our their**) is used only where
a single shared word decides: S4.5's one-word rule and the `<= 2 meaning words` class of R1, S4.4's `named`, and S2.1's
`>= 2 shared meaning words`. The pronoun additions are what make R1's "what do they want with me" clear: under the strict
list `they` is a stop word, the utterance's only meaning word is `want`, F1 = 0.67, score 0.783, precision 1.0.
(With pronouns in the strict list: 198 zero-word norms, but scoring never sees that list.)

**Test.** test_dialogue 21: "tell me who you are" on the DB02 layer -> pick at 0.78 (scoring, old list); "who are the
Greybeards" -> single by the strict shared word `greybeards`; "who are you" against `who are the greybeards` (a probe
layer) -> NOT a shared word under the strict list. test_prompt_index asserts the hash unchanged (both lists are
match-time only).

### R3. First contact: the narrow marker's clauses, as the code implements them, miss the owner page's own sentences

**What [C, X].** The four clauses of S2.1 map onto real functions this way:
- "a service kind in his words (`lrgDlgServiceKind`)" - that function matches the PHRASE lists of `services.kinds`
  **[C :180-229, :3863]**. "I'd like a room" and "I need a bed" hit NO inn phrase ('a room for the night', 'rent a room',
  'how much for a room', 'do you have a bed', 'somewhere to sleep', 'a bed for the night', 'room for the night', 'rent me
  a room', ...); "what have you got" hits NO barter phrase ('what have you got for sale', 'for sale', 'what do you sell',
  ...). "show me your wares" and "let me see your goods" do hit (`your wares`, `see your goods`). Today's WIDE marker uses
  the other function, `lrgDlgIsService` over `service_words` **[C :363, :1382]** - a WORD list (rent, room, bed, wares,
  goods, sale, ...) that catches "room" and "bed" but still not "what have you got".
- "an index hit restricted to rows whose quest is in HER q AND sharing >= 2 meaning words" - `lrgPromptLookup` is an
  exact-norm / layer-fingerprint / token-pattern lookup **[C lrg_prompt_index.php:365-395]**; there is no shared-word
  query, and the `quests` fact only ranks among exact hits. As written the clause cannot be implemented by the function
  the spec names.
- The owner page's step 2, "Nice inn you have here, do you get many visitors?", is ONE row **[X]**:
  `ACFDialogueWhiterunHuldaBranchChatTopic`, quest `ACFDialogueWhiterun`, `moretosaywhiterun.esp`, toplevel, scripted 0.
  `ACFDialogueWhiterun` is not in Hulda's `q` (`ACFWhiterunVampires, WITavern, BQ01, DialogueWhiterun` per the spec's own
  snapshot) - it conditions on her by GetIsID, not by alias, and the index does not store `conds` per row (only `nconds`),
  so no q-scoped or NPC-scoped clause can see it. Her root is not cached on the first visit. Result: step 2 takes the
  POST-LLM open (the model's item, wide marker `a quest this NPC is in`): her CHIM answer to the question plays in full,
  then 5-8 s later the list opens, `want=1` clicks the exact line, her engine line plays. The first sentence the page tells
  him to say is answered twice, with no bridging directive (it rides only on `open_pending` turns), at today's 11-14 s.
  "What have you got?" at any innkeeper he has NOT already spoken to that evening goes the same way, and the S2.1 hold on
  `OpenInventory` / `Rent_Room` does not apply on the post-LLM path.

**Where.** S2.1 (the NARROW marker), S2.2, S5 (the kind pick: "I need a bed" -> RentRoom by kind needs
`lrgDlgServiceKind` to return `inn`, which it does not today), test_dialogue 25 and 29, test_services, 3.7 rows
FE.hulda.open / FE.hulda.room / FE.hulda.trade, owner page 5.2 / 5.3, S12 (the <= 5 ms line).

**Change.** Define the narrow marker by the functions that exist, in this order, each logged by name:
1. `lrgFacMarker` (a join ask) - unchanged;
2. `lrgDlgIsService` (the WORD list) - "I'd like a room" (`room`), "I need a bed" (`bed`), "show me your wares"
   (`wares`); NOT `lrgDlgServiceKind` here;
3. **an exact-norm or containment hit on a TOPLEVEL index row** (`lrgPromptLookup([$utter])`, `toplevel = 1`; add
   `>= 0.85` containment over the same lookup's `pattern` tier if it is cheap, else exact only) - a sentence that IS a
   real opening prompt of this load order is the surest sign of business there is, whoever she is; step 2 fires here
   because the page tells him the exact words;
4. `lrgDlgMatchText($utter, her cached root) >= 0.5` - unchanged; "what have you got" after step 2 is a containment hit
   (0.85) on `what have you got for sale`;
5. `lrgDlgMatchText($utter, her q rows) >= 0.55` over a per-NPC row set fetched ONCE per session from Postgres
   (`quest IN (her q)`, toplevel first, capped at 300 rows; DialogueWhiterun alone has 97 toplevel rows **[X]**) and
   cached in state like `root` (1800 s) - this is what "sharing >= 2 meaning words" has to mean if it is to run in 5 ms.
Then: extend `services.kinds.inn.phrases` with `need a bed`, `need a room`, `like a room`, `want a room`, `get a room`,
`a bed for`, and `barter.phrases` with `what have you got`, `what do you have` guarded by `not_after` += `against`,
`to say`, `planned`, `in mind`, `for me`, `there`, `left` - so the S5 kind pick and the S2.1 shortcut hold on the page's
sentences. State in the page's 5.2 that the first sentence to an NPC she has not listed that evening can still be
answered twice when it is not a real prompt in its own words (the honest sentence), and keep 5.3's step 2 VERBATIM (it
is an exact hit).

**Test.** test_dialogue 25 names the clause each sentence fires: "Nice inn you have here, do you get many visitors?" on a
cold Hulda -> row (clause 3, toplevel exact); "what have you got?" cold -> NO row (stated), warm (root cached) -> row
(clause 4); "I'd like a room" cold -> row (clause 2, `room`); "I need a bed" -> row (`bed`); "uh some beer" -> none;
"where can I get a drink" -> row (`drink`, and the line is on DialogueWhiterun's root **[X]**). test_services: every
fixture paraphrase marked `mode kind` and every owner-page service sentence returns its kind from `lrgDlgServiceKind`
("I need a bed" -> inn, "show me your wares" -> barter, "what have you got" -> barter, "what have you got against the
Stormcloaks" -> ''). test_latency section 6 measures clauses 3 and 5 warm (<= 5 ms) with the q-row cache primed.

### R4. The re-armed auto-advance clicks 2.5 s into her CHIM answer: anchor it on the end of CHIM speech

**What [C].** S4.6's anchor - the vanilla subtitle clearing or the progress timer completing - is right for the FIRST
`adv=` pick (it arrives on `want=1`, while the engine line plays). The re-armed pick (S4.6 last bullet) arrives in the LLM
reply that answers "who are the Greybeards?": by then the vanilla subtitle cleared long ago, so the grace starts at once
and the click lands 2.5 s into her TTS answer (5-10 s long) - Balgruuf's engine line then plays over CHIM's. This is the
owner page's own example ("asking 'who are the Greybeards?' during a breath is answered and the line then carries on").
The game already has the signal the spec says it lacks for vanilla lines: CHIM raises `CHIM_SpeechStarted` per sentence
for HER (`NoteSpeechToDriver(1, now)`, LRG_Main.psc:3442-3471) and `isActorTalking` reads CHIM's TTS; LRG_Main:4223-4227
already waits "isActorTalking == 0 and no new SpeechStarted for the gap" so a window never cuts across her own sentence.

**Where.** S4.6 (re-arm bullet), Lane C task (S4.6 game half), S12 (the "0 new natives for the grace anchor" line), 5.2
bullet on the oath, test_dialogue 22.

**Change.** For an `adv=` pick that arrives while she is speaking through CHIM (`isActorTalking != 0` or a `NoteSpeech(1)`
younger than 1.0 s), the grace starts at the END of CHIM speech: `isActorTalking == 0` held for 1.0 s with no new
`NoteSpeech(1)` in that second (the :4223 logic, reused); the subtitle / timer anchor applies when the line is the
engine's. Budget: +1 native per 0.1 s poll ONLY while an `adv` pick waits on a CHIM line (correct S12's row). Server side
nothing changes; optionally the re-armed emit carries `adv=2500;rearm=1` for the log.

**Test.** Offline: test_dialogue 22's re-arm case asserts the `adv=` pick is emitted in the SAME reply as her answer
(the harness can see nothing else). In game (5.3): after "who are the Greybeards?" the log shows `adv wait: her line` then
`clicked ... auto=1` with a timestamp later than the last `CHIM_SpeechStarted` for Balgruuf plus the sentence length;
the owner hears her whole answer, then the breath, then the line.

### R5. `ok` and `okay` must behave the same

**What.** S9 puts `okay` in the shared `confirm.assent_words` (releases a parked commit) and `ok` in
`confirm.single_entry_extra` (single-entry only, "never the park release", game S3's caveat). Whisper writes either; the
owner reports "I said okay and she joined, I said ok and she asked again" as a bug, and it is one - his input was the
same.

**Where.** S4.4, S4.5, S9, Lane A/B config, test_dialogue 20.

**Change.** `lrgPromptNorm`-level equivalence for the assent tests only (`ok` -> `okay`, `alright` / `all right` already
both listed), and ONE decision on which list `okay` belongs to. My recommendation: the shared list (a bare "okay" after
her explicit "You wish to join the Companions, then?" is a yes; the caveat was about `ok` as a filler during the oath,
where the single-entry rule and R1's shape test govern anyway). Either way, both spellings on the same list.

**Test.** test_dialogue 20: her question + "ok" and + "okay" -> the same outcome; test_gates: neither is a back-out word.

## 2. SUGGESTIONS (not blocking)

S1. **Stage-rail label once per prompt, not per entry.** Eight labelled T-keys x ~110 chars is ~880 chars on the first
conversation's prompt (over the 2,500 budget if test_latency_prompt ever measures a rail turn). One `<business>` line -
"Until he has asked me something simple I can only pick T1, T4; for anything else say: I have not picked a line for you
yet - ask me something simple first, a question, then I can pick this one" - is model-agnostic and ~200 chars; the gate
refuses the labelled keys regardless. Keep the per-entry form only if 10.21's voicing needs the label on the key.

S2. **Widen `lrgDlgIsBackOut` for the breath.** The real hesitations STT will hear during the oath and before a park
release are not in the regex **[C :1375]**: `need to think`, `let me think`, `give me a (moment|minute|second)`, `hold
on`, `hang on`, `one moment`, `not so fast`. With R1's shape rule "I need to think" is safe anyway; as a back-out it also
un-parks, which is what he means.

S3. **Wording in S4.5.** "uh what now" is refused by CONTENT (0.33, no shared word), not by shape - it starts with `uh`
and has no `?`. The outcome is right; the sentence claims the wrong rule and test 21 should assert the true one.

S4. **Fixture target for FE.hulda.plain.** The table names `ACFDialogueWhiterun` (the quest). The topic is
`ACFDialogueWhiterunHuldaBranchChatTopic` (`moretosaywhiterun.esp:000940`) **[X]**; the harness's `norm` form also works.

S5. **`named` by `?` should yield to the two-candidate hint.** When the hint fired on the parking turn her message is a
question about WHICH entry, and a bare "yes" is not an answer to it; skip the `?` clause for that park (one flag on the
park write).

S6. **Re-arm condition wording.** "when the gate found no T-key" should read "when no pick was emitted this turn" - a
T-key the gate REFUSED (shape) must also re-arm, or the oath stalls after one question.

S7. **Two `<business>` truths to keep exact.** (a) On an `open_pending` turn the directive replaces, not joins, the
"the list is on screen" state wording (there is no list yet). (b) On a read-only session the plain list must not carry
the `(+N more)` bucket wording that presumes keys.

S8. **The reconciliation items.** Checks inside a paused journal scene once `clicks_ok >= 1`: acceptable from this lens,
provided the S11 sentence "Never say whether a persuasion, a threat or a bribe worked" rides on every turn that offers a
`kind` entry (it does) and the outcome is told next turn from `ev=result` as today. The 0.65 floor: acceptable ONLY with
R1's precision term and `<= 2 meaning words` rule - without them neither 0.65 nor 0.70 stops "long live Ulfric" (0.721).

S9. **Say the scoring gap out loud in S4.5** so Lane A does not "fix" it: `lrgDlgMatchText` skips the F1 branch when
either side has no meaning word; `is that it`, `who are you`, `what does that mean` are carried by exact / containment /
similar_text only, by design.

S10. **test_dialogue 25's latency row.** Prime the q-row cache in the test's warm pass and assert the cold pass separately
(one Postgres round trip, < 30 ms); the 5 ms target is the warm number.

## 3. What is right and should not move

- The eleven round-1 fixes as folded in: the shape test that lets question entries take question paraphrases (verbatim
  "So what do you need me to do?" now releases), the one shared assent list with `i swear`, the decision twin so the mute
  and the never-empty let-through cannot disagree, `named` by her `?` within 60 s, the fresh `last_exec` read plus the
  <= 220-char bridging directive, the new-utterance guard on `want=1`, the kept `sent < n` and check-outcome rails, plain
  sentences in `<what_just_happened>`, the harness built from `parent_info` layer lines, the window-gated reward branch.
- The read-only session (S1.3): T-keys replaced by the plain list, the clause pre-LLM, `lrgDlgWillEmit` false, no funcret
  turn - exactly the never-silent / never-false shape. `session.drive_scene` default true with `clicks_ok >= 1` as the
  proof is the right default for an owner who reports symptoms, not settings.
- The matcher / model split is unchanged and correct: the model's only lever is this turn's T-key, the matcher guards the
  fast path, ambiguity is always her question, no synonym table, no second judge call. R1 sharpens it (the fast path gets
  a precision term; the model keeps its reach on non-commit singles) rather than moving it.
- Real numbers that stand under R1/R2: "long live the Emperor" 0.907, "long live the Empire" 0.907, "hail the
  Stormcloaks, the true sons of Skyrim" 0.892, "do you mean this old stone" 0.87, "what do you need" contained 0.85,
  "what else can I help with" 1.0, "I swear" / "I swear it" / "count me in" by assent; "the emperor is a fool" 0.567,
  "death to the emperor" 0.567, "all hail the Empire" on the Stormcloak oath 0.61, "what do you think of them" 0.12,
  "..." nothing on a scripted single - all refused **[M]**.
- Token cost: the directive rides only when no list exists, the reward line only when he bargains, the hint once and only
  on the offer; the 2,500 / 600 budget is achievable. LLM calls per turn stay at exactly one; the oath costs none.
- Latency: 3-8 s first contact after speech end is honest and the page says so; R3 does not change it, it only moves step
  2 from the 11-14 s post-LLM path onto it.
