# pt19 - AI DESIGN DIRECTOR, round 1 review of `research/pt19-menuless-v1-spec.md`

2026-09-24. Read-only. Lens: how the model is prompted and constrained (the T-key protocol, prompt size, what is told vs
what is enforced), reliability against paraphrase and STT noise, hallucination control and the never-false / never-empty /
never-silent rails, latency and token cost per turn, model-agnostic robustness, and whether the matcher / model split is
right. Sources: the spec; `pt19-design-player.md`, `pt19-design-truth.md`, `pt19-design-perf.md`, `pt19-design-survey.md`;
PROTOCOL 10.10-10.26; `OWNER_ADDENDA.md`; the code the spec rewrites (`lrg_dialogue.php`: lrgDlgMatchText, lrgDlgAnswerWant,
lrgDlgGateItem, lrgDlgParkOrRelease, lrgDlgWillEmit, lrgDlgTransformer, lrgDlgBusinessBlock, lrgDlgJsonTemplate,
lrgDlgPrepareTurn, lrgDlgGet, lrgDlgLockedFacts, lrgDlgTruthCheck; `lrg_speech.php`: lrgDlgCheck, lrgDlgCheckKind;
`lrg_factions.php`: lrgFacArbitrate*, lrgFacQuestPlan; `lrg_replies.php`; `LRG_Dialogue.psc`: CmdSelectTopic, StepClicking,
NoteSpeech; `LRG_Main.psc`: OnChimSpeechStarted); the live index rows and layer lines for the beats the spec names
(`C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\oversight_rows.py`); and a standalone replica of the real
`lrgPromptNorm` / `lrgPromptWords` / `lrgDlgMatchText` run over the spec's own paraphrases against the real sibling sets
(`...\design\oversight_matcher.php`, output quoted below). Marks: **[C]** code, **[X]** index, **[M]** the matcher run, **[S]**
the spec section.

## 0. Verdict

**Not approved as written.** The shape is right - the visible menu, the T-key protocol kept as the model's only lever, the
matcher as the fast path's guard, index first and words second, ambiguity always a question, no synonym table and no second
judge call - and it is what I would build. But eight things in the spec are either promises the signals cannot keep or
rules whose own examples fail when run through the real code, and each would reach the owner as "she ignored me" /
"she answered twice" / "she went quiet" / "the oath got stuck" - exactly the reports this build is meant to end. All eight
are cheap to fix and every one has an offline test. Fix them in the spec before the lanes start; none changes the lane
ownership or the wire beyond what is already additive.

## 1. REQUIRED changes

### R1. The single-entry release (S4.5) refuses its own examples: the question filter must not apply to question-shaped entries

**What.** S4.5 refuses any utterance that ends in `?` or begins with what / why / how / who / where / when / which / is /
are / do / does / can / could / would / should. Most single-entry stage movers on the main quest ARE questions, so the
player's paraphrase of them is a question too. Run through the rule as written **[M]**:

| entry (real index norm) | paraphrase in the spec's own beat table | S4.5 as written |
|---|---|---|
| MS05 Task2 `what do you need me to do` | "what do you need me to do" (VERBATIM) | refused (question) |
| MS05 Final `is that it` | "is that it" (VERBATIM) | refused (question) |
| TG00 04 `what do you have in mind` | "what do you have in mind" (VERBATIM) | refused (question) |
| TG00 06 `what do i have to do` | "what do I have to do" (VERBATIM) | refused (question) |
| MQ104 D1 `what do these greybeards want with me` | verbatim; "what do they want with me" | refused (question) x2 |
| MQ103 B1 `so what do you need me to do` | "what do you need done" | refused (question) |
| MQ103 HaveStone `oh do you mean this old stone` | "do you mean this old stone" | refused (question) |
| MQ102 reward `what else can i help you with` | "what else can I help with"; "is there anything else I can do" | refused x2 |
| MQ105 HornA2 `thank you what's next` | "what comes next" | refused (question) |
| MQ104 C1 `the greybeards` | "the Greybeards?" | refused (question) |

Thirteen of the spec's "single" paraphrases, five of them the engine's verbatim line, are refused; test 21 and the fixture
would go red on day one, and in game the flagship "So what do you need me to do?" waits for words that can never satisfy it
(the LLM path's T1 still works, so the owner would see "sometimes she takes it, sometimes not").

**Where.** S4.5, S4.10 row "scripted single entry", Lane A task (9), test_dialogue 21, fixture beats MQ102.balgruuf.reward,
MQ103.farengar.assignment, MQ103.farengar.stone, MQ104.balgruuf.want, MQ105.arngeir.next, MS05.viarmo.task, MS05.verse.final,
TG00.brynjolf.chain.

**Change.** The question test is a test of *shape mismatch*, not of shape: refuse a question only when the ENTRY is not a
question (its norm does not start with a question word and its `txt` does not end in `?`). When the entry is a question,
decide on content alone: an assent word, or `lrgDlgMatchText(utter, [entry])` score >= `confirm.single_entry_score` (0.70)
(so "what do you need done" 0.78, "do you mean this old stone" 0.91, "what else can I help with" 1.0, "what comes next" 0.68
-> raise the floor only if the fixture shows it must be 0.65). Entries with NO meaning words at all (`is that it`,
`the greybeards`: every token is a stop word in `lrgPromptWords`, so word-overlap is structurally zero) release on exact /
containment (score >= 0.85) only. Keep "wait, what does that mean?" refused (it ends in `?` and the entry does not).

**Test.** test_dialogue 21 extended with the ten rows above (each releases) plus "uh what now" (nothing: f1 = 0 and score
0.33) and "wait, what does that mean?" (nothing); test_questline's single beats then pass on the real rows.

### R2. The single-entry release is too loose for scripted entries and too tight for the oath: one shared word must not enlist him, and "I swear" must

**What.** Two opposite defects in the same rule **[M]**:
- Oath4 `long live the emperor long live the empire` (scripted, the enlistment) releases on **"the emperor is a fool"**
  (shared meaning word `emperor`, not a question, not a refusal). MS11's evidence line (jails a man) has the same shape.
  One shared word is fine for an unscripted continuer; it is not a confirmation of a fragment.
- "I swear" / "I swear it" - the spec's own test-21 and fixture lines for Oath4 and CW01B Oath4 - release NOTHING: neither
  is in `confirm.assent_words` (yes, aye, yeah, yep, deal, agreed, sure, do it, i do, i will) and neither shares a word
  with "long live the emperor". "..." releases nothing either (`lrgPromptNorm('...')` is '' and the matcher returns null
  on an empty utterance **[C lrgDlgMatchText]**); test 21 lists it as a release.

**Where.** S4.4 (`confirm.assent_words`), S4.5, S9, Lane A tasks (8)-(9), test_dialogue 21, fixture CW01A.oath.4 /
CW01B.oath.4 / CW01A.oath.1-3.

**Change.** (a) For an entry with `scripted=1` the single-entry release needs an assent word OR score >= 0.70 (never the
one-word rule); the one-shared-word rule stays for `scripted=0` only. (b) Extend the assent list once, shared by S4.4 and
S4.5: `i swear`, `i swear it`, `so be it`, `i'm ready`, `i am ready`, `fine`, `alright`, `all right`, `okay`, `very well`,
`of course`, `let's do it`, `i accept`, `i'm in`, `count me in`. (c) Drop "..." from the Oath4 expectation (it is a
continuer for auto-advance on UNSCRIPTED singles only, S4.6); on a scripted single it does nothing, by design.

**Test.** test_dialogue 21: Oath4 on "I swear" / "I swear it" / "long live the Emperor" -> single; "the emperor is a fool"
-> nothing; "wait, what does that mean?" -> nothing; CW01B Oath4 on "I swear it" -> single. test_gates: the assent list has
no word that `lrgDlgIsBackOut` also matches.

### R3. The mute and the gate will disagree: `lrgDlgWillEmit` is not in the spec, and every new release / refusal must be mirrored there

**What.** The TTS mute (`lrgDlgTransformer`) and the never-empty let-through (`lrgNeWillEmitPick`) both decide from
`lrgDlgWillEmit($t, item)` **[C :3184-3230; lrg_replies.php:210]**, a side-effect-free twin of the gate: it returns true (mute
her line, the engine answers) only for a T-key that is not crit 2 / meta / an unreleased commit / unaffordable. The spec
changes what the gate emits in six places and never names the twin. Concretely, as the spec stands:
- **explicit release (S4.3)**: the model picks `T1 [commits]` on Kodlak's join with her confirming question as the message;
  the player said the exact line; the gate now emits at once; `lrgDlgWillEmit` still says "unreleased commit" -> NOT muted
  -> she asks "Are you sure?" out loud while the engine plays Kodlak's real answer. A double answer on the flagship beat.
- **bare yes (S4.4), single-entry release (S4.5)**: the same double.
- **stage rail (S3.3)**: the model picks a scripted T-key on the first conversation; `lrgDlgWillEmit` returns true -> the
  never-empty validator lets an EMPTY message through ("a business pick the game answers itself") and the transformer mutes
  whatever she said; the gate then refuses (`gate: stage rail`) -> nothing clicks and nothing is spoken. **Silence**, the
  one failure owner addendum 11 forbids in every case.
- **leave guard (S4.2)**: `LEAVE` returns true (mute) today; on a guarded layer the gate now emits `do=show` and her line
  "then walk away yourself" MUST play -> must return false there.
- **scripted-needs-word (S4.8)**: intent mode returns false anyway - no change needed, but say so.

**Where.** S4.3-S4.5, S3.3, S4.2, Lane A task list (add an item), test_dialogue.

**Change.** Lane A rewrites `lrgDlgWillEmit` alongside the gate so that for every input `lrgDlgWillEmit($t, item) ===
(lrgDlgGateItem($t, item) emitted a do=pick or a do=leave that clicks a back entry)`; the cheapest way is one shared
decision function the gate calls with side effects and the twin without (the park write is the only side effect). The
stage rail and the leave guard return false; explicit / bare-yes / single-entry release return true.

**Test.** A new test_dialogue section 28: for every case in sections 16-26, assert `lrgDlgWillEmit === (a pick was emitted)`;
flow d66 asserts that on the explicit Kodlak turn her message is muted and on the stage-rail turn it is spoken.

### R4. "If you start talking during that breath, she waits for you" (S4.6) has no signal behind it

**What.** The cancel is specified as `NoteSpeech(0)` "reported the player's speech start after reqAt", forwarded from
`CHIM_SpeechStarted`. That event is raised by CHIM's own Papyrus in `FakeDialogueWith` / `FakeDialogue` when CHIM *voices a
line* - for the player, only when CHIM plays back his own transcribed line via player TTS **[C LRG_Main.psc:3160-3184;
research/chim-papyrus-plumbing.md:36, :205; chim-dll-esp.md:18]**. The push-to-talk press never reaches Papyrus ("the
player's utterance travels DLL -> server directly ... Papyrus never sees it", `p2-chim-interplay.md:305, :310`). So the
"speech started" the game can see arrives seconds after he started talking, and only if player TTS is on (the owner uses
push-to-talk, `_openmic_enabled` false). As written, the breath is never interrupted; the oath's next line plays over the
owner's question and the owner page promises the opposite. (The same signal is what X1 measures - fine for a log line, not
for a cancel.)

**Where.** S4.6, S4.10 row "unscripted single continuation", section 5.2 and 5.5 (owner wording), open decision 2, Lane C
task (8).

**Change.** Either (a) give the cancel a real signal: an MCM/ini key `iKeyPushToTalk:Dialogue` (the owner's is Left Ctrl,
DirectInput 29, visible in AIAgent.log "Using mapped key code: 29" - `p2-chim-interplay.md:707`; default 29, 0 = off),
registered by LRG_Main like the leave hotkey, and `OnKeyDown` on it calls `NoteSpeech(0, now)` - one native, no polling;
or (b) drop the promise: auto-advance stays (it clicks only unscripted, cost-0, kind='' singles, so the worst case is one
harmless canned line over his words, which the next turn then answers on the new layer), and the owner page says "if you
speak during the breath your words are answered on the next line". I recommend (a) plus (b)'s wording as the fallback when
the key is 0. The one thing not allowed is the current text.

**Test.** Offline none for the key (Papyrus); `test_mcm_wiring` lists the new id; in game (5.3): "adv cancelled by speech"
when the owner presses push-to-talk during the oath's breath.

### R5. The bare "yes" (S4.4) will usually not release, because "named" is defined in the entry's words and the model speaks in hers

**What.** `named` = ">= 2 meaning words of the entry's norm appear in her parking message, or the parked entry is the
layer's only commit". The model paraphrases: Irileth's "I'll come along with you" (norm meaning words `come`, `along`)
becomes "You'll ride with me to the tower, then?"; Kodlak's three commits are all paraphrased. On every layer with two or
more commits (Irileth A1/A2, Kodlak's three, Delphine B3's three, Balgruuf's three Helgen statements, MS05's verses) a bare
"yes" stays parked unless she happened to echo two of the entry's own words. The owner page says "'Yes' is enough after
her question."

**Where.** S4.4, S11 (the [commits] rule), Lane A task (8), Lane B second pass (`lrgDlgBusinessBlock`), test 20.

**Change.** Two halves, both cheap. Prompt: the [commits] rule reads "ask plainly whether they mean it, quoting the choice
in its own words" - the T-key text is in front of the model, quoting costs nothing and is model-agnostic. Server: `named`
= >= 1 meaning word of the norm in her message, OR `lrgDlgMatchText(said, [entry]) >= 0.45`, OR her parking message contains
`?` (she asked; a park is at most 60 s old and there is one park per NPC, so a bare assent inside that window answers it).
Refusals still un-park; other single tokens stay parked.

**Test.** test_dialogue 20: her message "You would ride with me to the watchtower, then?" + "yes" -> release; her message
"The watchtower is a long way." (no `?`, no naming) + "yes" -> parked; "no" -> un-parked; the block text contains
"quoting the choice in its own words".

### R6. First contact (S2.1) will answer twice; the mute cannot fire as specified, so the model must be prompted for a bridge line

**What.** S2.1 mutes the model's message "when last_exec for the NPC is a do=pick with the same cid". Two facts defeat it:
(1) `lrgDlgState()` caches the NPC row per PHP process (`$GLOBALS['LRG_DLG_STATE']`, **[C :628-643]**); the fast pick is
written by the `lrg_topics` request in ANOTHER process, so the transformer in the LLM request never sees it without a fresh
read. (2) Timing: the transformer runs per sentence as the stream arrives (first sentence ~2-3 s after the request), the
pick lands 3-8 s after speech end (the spec's own S2.1 budget: D2 5 s poll + open + read + want=1). Her CHIM line is
voiced before the pick exists; then the click plays her real line. Today's post-LLM open has the same double and the owner
has been hearing it; v1.0 makes first contact the normal case for every "what have you got?", so it becomes the commonest
thing he hears.

**Where.** S2.1, S11, Lane A task (2) ("lrgDlgTransformer mutes ..."), Lane B second pass, test 25, owner page 5.2.

**Change.** Keep the mute as a best-effort (make it read `last_exec` fresh: bypass the cache for that one key, one query per
sentence at most), and add what the AI side can control: on an `open_pending` turn `<business>` (or the volatile block when
no list is known) carries one directive - "The list of what <player> can raise is being brought up now. Say ONE short line
that does not settle the matter (an acknowledgement or a question back); if the list carries what he asked, his real
answer follows from it." Never empty is satisfied; never false is not strained (she claims nothing). Owner page 5.2: "she
says a word, then her real line plays".

**Test.** test_dialogue 25: the directive is present on the open_pending turn and absent on the next; test_latency_prompt
counts its chars (<= 220). Flow d21: the muted-when-landed case and the unmuted-when-late case both asserted.

### R7. A fast-path release must be on a NEW utterance: S4.3 / S4.5 on `want=1` will fire on the sentence that caused the previous click

**What.** `want=1` arrives on every new layer with `st['utter']` still holding the sentence that produced it. MQ103: the
player says "do you need any help with the dragons" -> the intro entry is clicked -> the next layer is the single
`So what do you need me to do?` -> `want=1` -> S4.5 on the OLD utterance: shared word `need` -> the assignment is clicked
with no new word from him. This is the exact defect pt9 S2 fixed for the two-step ("the player must have spoken since the
park"; `lrgDlgParkOrRelease` compares the utterance that parked it) **[C :2790-2810]**; the new releases need the same
guard.

**Where.** S4.3, S4.5, S4.7, Lane A tasks (7), (9), (11).

**Change.** On the fast path a release through `lrgDlgExplicit` or `lrgDlgSingleEntryRelease` requires `utter.at >
last_exec.at` (or `utter.at >= session.at` of this gen when there was no click); otherwise the layer waits (or auto-advances
under S4.6 when unscripted). The gate path already has a new cid per player turn, so it needs only the existing "speech
turn" test.

**Test.** test_dialogue 21 + 23: MQ103 intro pick -> B1 layer with the same utterance -> nothing; a new utterance "what do
you need done" -> single. Flow d67 step "assignment waits".

### R8. The `<business>` diet (S11) removes two never-false rails; keep them, and add the stage-A scene clause

**What.** The four-line rule set drops (a) the `sent < n` line "you may NOT say that a thing is not on the table" (the
CMP-M4 rail that stops her denying a Missives / follower matter exists; `lrgDlgBusinessBlock` **[C :2060-2064]**), and
(b) `<real_business>` is suppressed whenever `<business>` is present - `<real_business>` is the only place the model is told
"Never say whether a persuasion, a threat or a bribe worked. The world decides" **[C lrgDlgStaticGuidance]**; on a scoff-first
park or a T-key that is refused, her line plays and may narrate an outcome. Both are words the truth gate does not judge.
Also: in stage A every engine-opened session inside a scene (7 of 16 quests' advancing lines) is read but not driven; the
model still sees T-keys, picks one, the game answers `Error: that is not on the table right now`, and 10.21 voices that as
a full LLM + TTS funcret turn - one paid refusal per attempt, on the beats the owner will try first. The server knows
`sess.scene` before the LLM runs.

**Where.** S11, S1.3 (`lrgDlgHandBackForeseen`), Lane B second pass (6), (11), Lane A task (1).

**Change.** (a) Keep the `sent < n` line, conditional as today (one line, only when it applies). (b) When `<real_business>`
is suppressed and any offered entry has `kind != ''`, `<business>` carries the one sentence "Never say whether a
persuasion, a threat or a bribe worked; you are told afterwards." (c) `lrgDlgHandBackForeseen` returns true for
`sess.origin = engine && sess.scene = 1 && !session.drive_scene` (a server config key Lane A adds, default false; gate B
sets true - the same flag Lane E's `--stage=B` needs), so her line is "choose that one on the list yourself" pre-LLM, the
T-key is not emitted, and no funcret turn is paid.

**Test.** test_dialogue 13 (j) asserts the two lines by text; 16: an engine session with scene=1 -> the foreseen line and
`lrgDlgWillEmit` false; test_latency_prompt: the 12-key turn still <= 2,500 chars with both lines.

### R9. The questline harness must build closed layers from the index's own layer lines, not from hand-listed topics

**What.** Spec 3.3 makes `layer.topics` "the preferred form". The engine's sibling sets differ from the spec's table: the
index's layer lines **[X]** pair `MQ102IrilethIntroA1` with `A2` ("a dragon destroyed helgen <t> is afraid riverwood is
next", parent 098D4B) and `B1` with `B2` (parent 0D39AB) - the fixture's `[A1, B1, B2]` is a menu the engine never shows.
The matcher's margins move with the siblings ("news from Helgen about the dragon": margin 0.355 on the real layer, 0.266
on the invented one **[M]**); a fixture that lists topics by hand proves the spec's picture of the menu, not the menu.

**Where.** Spec 3.3, 3.4 step 1, 3.6 (the layer column), Lane E task.

**Change.** A closed layer is named by `parent_info` and built from the index layer line's `norms` (the real sibling set,
engine order); a single-entry layer by the parent row's `links` resolved to visible rows (what `chains16b.py` does);
`topics` is allowed only for a ROOT list (no layer line exists) and must then be a subset of the NPC's real top-level rows.
`test_prompt_index 5d` pins every fixture `parent_info` to a live layer line.

**Test.** The harness refuses a closed beat whose entries are not the layer line's norms; 5d green.

### R10. The five gate refusals must reach `<what_just_happened>` in plain words, not the log string

**What.** S7's last two rows and Lane B (2) write `last_result{ok:0, why}` from the gate codes; `lrgDlgGroundTruth` prints
`'Nothing came of it: ' . $res['why']` verbatim **[C :2160]**. The gate's strings are log text ("a bribe attempt needs at
least 4 words on voice input (2)", "this exact persuade already failed and nothing relevant changed") - machinery words in
her mouth on the next turn, against S7's own rule.

**Where.** S7, Lane B task (2) (`lrgDlgNoteRefusal`), test_gates 40.

**Change.** `lrgDlgNoteRefusal($npc, $why)` stores the PLAIN sentence (afford: "he could not pay what that costs";
amount: "he offered less than it costs"; words: "he did not say it properly, in a whole sentence"; retry: "she has refused
that already and nothing has changed"; frozen: "things had just changed and she had to look again") - the same text
`lrgVoicedWhy` would produce - and `lrgDlgGroundTruth` reads it.

**Test.** test_gates 40 asserts the `<what_just_happened>` line for each of the five codes contains no machinery word
(the closed list test 35 already uses) and no digit.

### R11. Gate B's bonus (S6.2) cannot be asked for: the recogniser does not exist

**What.** S6.2 (2) says "the player ASKED ... `lrgDlgCheckKind` persuade/deceive with the new stakes class `reward`".
`checks.stakes.*` only sets the difficulty BAND (`lrgDlgStakes`) **[C lrg_speech.php:375]**; the KIND comes from
`lrgDlgCheckKind`'s regex ladder **[C :306-372]**, and "I deserve more than this, a hundred septims" / "can you sweeten it"
/ "I think I'm worth more" hit none of its persuade patterns ("you can tell me", "come on,", "help me out here" ...), are
not a bribe (no giving frame) and not barter (no price words). No check runs; she can only refuse. Test 27 as written
cannot pass. This ships inert in gate A, but the spec must be right before B.

**Where.** S6.2 (2), Lane B task (4), test_dialogue 27.

**Change.** Inside an open reward window (and only there) the reward-ask phrases (more, extra, bonus, on top, sweeten,
deserve more, worth more, in septims, in gold, in coin, pay me) make `lrgDlgCheckKind` return `persuade` with stakes
`reward` - one branch, window-gated so it cannot turn a merchant haggle into a check.

**Test.** test_dialogue 27: the three sentences above -> kind persuade / stakes reward inside the window; the same
sentences outside the window -> ''.

## 2. SUGGESTIONS (not blocking)

S1. **S4.3 token floor on short entries.** "a sword, please" scores 0.675 with margin 0.355 but has 3 tokens, so it parks
where the spec says explicit **[M]**; "I brought your shield, Aela" scores 0.675 (< 0.70) and parks too. Use
`tokens >= min(4, entry tokens)` and accept that the second Aela line is a park (fix the fixture expectation), or lower
`said_line_score` to 0.65 only where margin >= 0.35. Do not lower both floors.

S2. **Register hide_reward in the tracked hide list.** `lrgDlgPostProcessActions` already drops "a code this module hid
this turn" through `lrgDlgHiddenThisTurn`, which reads `LRG_DLG_SVC_HIDDEN` / `LRG_DLG_HOLD_HIDDEN` only **[C :2416-2440]**.
If Lane B adds the reward hides to that global, the existing rail drops a stray `GiveGoldTo` and the new truth clause (S6.1)
becomes log-only - fewer moving parts, same guarantee.

S3. **Reward locked line wording.** "if he bargains, say plainly what you can and cannot do" invites the model to invent
what she CAN do ("I could put in a word with the jarl") - a favour the never-false judge does not cover. Prefer "if he
bargains, say so and offer nothing else." Same length.

S4. **`<business>` state on a cached root list.** "the list is on screen in front of <player>" is true only while
`session.state` is open; on `list=root` (the 1800 s cache, session closed) it is a false statement to the model. Condition
the wording on `open`.

S5. **The stage-rail label word "menu".** Fine as an instruction to the model, but add to the label "say it in your own
words" or rely on 10.21's rule; check that test 35's closed-list scan does not flag the funcret text.

S6. **Name the right latency test.** "the pre-LLM open <= 5 ms (test_latency_prompt)" - that tool measures characters.
`test_latency.php` section 6 is the ms harness; note that `lrgDlgBusinessMarker` runs `lrgPromptLookup` (a Postgres query)
and the stale-cache match, so measure it rather than assume.

S7. **Two-candidate hint scope.** Compute it once per turn from the utterance over the OFFER only (never the tail), cap at
one hint, and skip it on a price list (the slot block already asks). Cheap, model-agnostic, and it removes a whole "which do
you mean?" round trip when it fires.

S8. **Owner page 5.4.** Add "on the first conversation with anyone, an answer that would move a quest is left for you to
click once" (the stage rail) so the first evening's step 5 is not reported as a bug if he goes to Kodlak first.

S9. **A paraphrase test that tests paraphrase.** The harness's LLM path feeds the TARGET's T-key, so for `pick` beats on
root lists the paraphrase column proves rails, not reach. Add to test_questline a `--words` mode that runs each paraphrase
through `lrgDlgGateItem` as the model's free-text item (intent mode) and reports resolve / ask / nothing - the number the
owner's "flexibility" really rests on, and it costs no LLM.

## 3. What is right and should not move

- The model's only lever stays the T-key of THIS turn's offer; the matcher guards the fast path and the model's free text;
  no synonym table, no second judge call, ambiguity is always her question. Right for grok and deepseek alike - `item: "T3"`
  survives every JSON connector, and `reorder_json_scope = business` is the correct second lever after the never-empty
  clause.
- S4.7 (two candidates must agree) and S4.8 (a scripted entry needs f1 > 0) are exactly the narrowings the numbers support:
  "uh what now" scores 0.33 / f1 0 against "So what do you need me to do?" and is blocked; "what do you need done" scores
  0.78 / f1 0.67 and passes **[M]**.
- The explicit release works on the real layers for the natural second paraphrase in every beat I could rebuild: "news from
  Helgen about the dragon" 0.94/0.36, "I can take care of myself" 0.72/0.38, "I'll come with you" 0.78/0.54, "I only came
  for the horn" 0.72/0.47, "when the dragon died I absorbed its power" 0.81/0.54, "fine, I'll take your test" 0.78/0.30 **[M]**;
  and every loose third paraphrase lands either below the floor or on a sibling with margin < 0.15, i.e. nothing is clicked
  - the safe side.
- Never invent a reward in v1.0, the bounded real bonus through `do=award give=` in v1.0.1, the ambient open only on a
  join ask, 10.26 kept as the fallback until the open is proven, the leave guard, the visible menu itself.
- The prompt budget (<= 2,500 chars on a 12-key turn, locked <= 600) is achievable with R8's two lines kept; the reward
  line (~150 chars) rides only on quest turns.
