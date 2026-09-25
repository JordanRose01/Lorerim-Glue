# pt19 - GAME DESIGN DIRECTOR, round 2 review of `pt19-menuless-v1-spec.md` (revision 1)

2026-09-24. Read-only. Reviewed revision 1 against my round-1 note, the AI director's round-1 note, the three
designers' notes and the survey; facts re-checked in the code (`LRG_Dialogue.psc`, `LRG_Main.psc`, `LRG_DlgUI.psc`,
`lrg_dialogue.php`, `lrg_prompt_index.php`), the live index (37,561 rows, hash e756e311) and the server log
(`/var/www/html/HerikaServer/log/lorerim_glue.log`, to 03:25 today). Marks: **[C]** code, **[X]** index, **[L]** log,
**[S]** spec section. Currency: septims.

**Verdict: NOT approved yet - one more pass, six small changes.** Every required change of round 1 (R1-R12) is in
the revision and in the right place; the reconciliation on both open points is correct (checks inside a paused
journal scene, 0.65 on the single-entry floor - confirmed in section 3). The shape does not move. But the round-2
checks against the OWNER'S OWN LOG show that three of the first-evening steps, as the spec now words them, would not
happen on his install: the scene test the revision adopted from my R1 is the wrong proxy (I pointed at the wrong
half of the line I cited - corrected here, with the precise test the game already computes), the ambient globs do
not name the scenes his innkeeper is actually in, and the pre-LLM open cannot fire on the step-2 sentence or on
"what have you got?" without a list she has never shown. Each fix is a line or a list; none adds a mechanism; the
harness can prove all of them offline. Below: the first evening as revision 1 would run it, then R1-R6, then
suggestions, then what stays.

---

## 0. The first evening under revision 1, on the owner's install

1. **Step 2, Hulda, "Nice inn you have here, do you get many visitors?"** Her row is `ACFDialogueWhiterun`
   (moretosaywhiterun.esp:000940, scripted=0, top-level, goodbye=0, nconds=3) **[X]**. Her `q` on this install is
   `ACFWhiterunVampires,WITavern,BQ01,DialogueWhiterun` **[L 02:53:37]** - `ACFDialogueWhiterun` is not in it (the
   "More to Say" quests condition on GetIsID, they hold no aliases, and `q` comes from PO3's alias sweep,
   `QuestCsv` :4049). So S2.1's index alternative ("rows whose quest is in HER q") cannot fire; there is no service
   word; there is no join ask; and her ROOT is not cached until one session has forwarded it (`lrg_dialogue.php:769`,
   TTL 1800 s). Pre-LLM: nothing. The step falls to the post-LLM open on the MODEL's item - if the model fills `item`
   for small talk at all - at the old 11-14 s with her CHIM answer first and "Thanks!" second. The page tells him
   "if step 2 does not pick, stop there and send the log". 3.7's `FE.hulda.open` does not test this sentence.
2. **Step 2 again, when she is in a scene.** At 02:54:52 her scene was `sq=DialogueWhiterunBanneredMareScene3
   sqj=0` **[L]**; earlier evenings show `BardAudienceQuest` and `DialogueGenericScene04` **[L]**. None of these
   matches `scenes.ambient` (`*MapTableScene* BardSongs* *Idle* *Sandbox* WI*`, `lrg_dialogue.php:101`), so
   `lrgDlgSceneAmbient` (:553-561) answers "not on the ambient list" and the ONLY ambient verdict the log has ever
   recorded is the Solitude map table **[L]**. S2.2 lets the open through on `ambient=1` - which her tavern scene
   is not. Game side, `OpenBlockedReason` at `iSceneGate=1` refuses when `GetCurrentScene() != None AND
   HasActiveJournalQuest(npc)` (:1342-1345), and `HasActiveJournalQuest` (:4131) is PO3's alias sweep filtered by
   `Quest.IsActive()` - an alias-of-a-tracked-quest test, not a scene test. Hulda is a BQ01 alias **[L]**; if that
   bounty is the tracked quest, her glue-open is refused and her E-press classifies `sj=1` (read-only before the
   route proof). Steps 2, 2b and 3 are luck again - the thing R5 was meant to end.
3. **Step 3, "What have you got?"** `services.kinds.barter.phrases` are `what have you got for sale`, `let me see
   your wares`, `show me your goods`, `your wares`, `what are you selling`, `let me see what you have` ...
   (`lrg_dialogue.php:196-199`) - "what have you got" is not one, "got" is not a `service_words` word (:363), so
   `lrgDlgServiceKind` is ''. Same for "I'd like a room" (inn phrases: `a room for the night`, `rent a room`, `how
   much for a room`, `do you have a bed`; :181-183) and "I need a bed". With her root cached, the >= 0.5 root path
   carries them; with `list=none` - which S2.1 names as the condition - it does not, and `list=none` and "her
   cached root" are mutually exclusive as the server derives them (:1503-1532: a fresh cached root IS `list=root`).
   Test 26's "what do you sell" on Belethor is not a barter phrase either. Fixture rows `FE.hulda.open` and
   `FE.hulda.room` ("I need a bed", mode kind) fail on the shipped lists.
4. **Step 6, Kodlak, one sentence.** S4.3's new-utterance guard reads `utter.at > last_exec.at (or utter.at >=
   session.at of this gen when nothing was clicked yet)`. At first contact the sentence is spoken 3-8 s BEFORE the
   session exists (S2.1's own budget), so the parenthetical refuses the explicit release, the join parks, and she
   asks - against 5.2 and the step-6 log line `mode=explicit`. The harness would not catch it: 3.4 step 2 says
   `fxDlgSay` then `fxDlgTopics` on a FIXED clock, so `utter.at == session.at` and `>=` holds offline while it
   fails in game.
5. **The oath.** S4.6 anchors the grace to "the subtitle CLEARED or the progress timer completed".
   `ProgressTimerId()` (`LRG_DlgUI.psc:593`) changes when a line STARTS (`setInterval` per `StartProgressTimer`) and
   never signals an end; and "cleared" alone starts the count on the empty read BEFORE Tullius's line begins (the
   voice file loads after the click) - 2.5 s later he is cut off mid-line, the exact report R11 was about.

Everything below is ordered by how early that evening it bites.

---

## 1. REQUIRED changes

### R1 - The scene test is the scene's OWNING quest with an unfinished journal objective, on both sides; `scene=` keeps today's meaning
- **Where:** S1.1 (`Arm()` classifies by `HasActiveJournalQuest(npc)`), S1.3 (`sess.sj` fallback), S2.2 (the
  ambient globs), S8 (`scene=` "now carries the same journal-only value"), S12 ("1 native"), Lane C task, Lane A
  task, 5.3 (2b).
- **What (game, Lane C):** `sj` = `speaker.GetCurrentScene() != None AND LRG_Main.EscortHasJournal(scene.GetOwningQuest())`
  - the test `SendFacts` already computes for `sq=`/`sqj=` (`LRG_Dialogue.psc:3698-3710`; `EscortHasJournal`
  `LRG_Main.psc:2805`: an objective displayed, not completed, not failed). `Arm()` reuses the last snapshot's
  `sq`/`sqj` when it is < 30 s old (0 new natives) and computes them otherwise (the same code). `OpenBlockedReason`
  at `iSceneGate=1` uses the SAME test in place of `HasActiveJournalQuest` (:1345). `HasActiveJournalQuest` is no
  longer used by the driver. `ev=open` gains `sq=<id> sqj=0|1` next to `sj=`; `scene=` keeps today's value (any
  scene, :1772) so an old server sees what it sees today - strike S8's "now carries the same journal-only value".
- **What (server, Lane A):** `sess.sj = max(game sj, scene=1 && !ambient)` where ambient is `lrgDlgSceneAmbient`
  with ONE change: the glob test becomes an allow-list shortcut, not a requirement - a scene is ambient when the
  facts are fresh AND `sqj == 0` AND `!lrgDlgQuestKnown(sq)` (the index has 0 rows for
  `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest`, `WITavern`, `DialogueGenericScene04` and 31/22
  journal rows for TG00/MQ102 **[X]**, so `lrgDlgQuestKnown` is exactly "a quest scene"), OR it matches a glob.
  The read-only clause, the scene rail and the ambient open all read this `sj`/`ambient`.
- **Why:** the alias proxy fires on every innkeeper, merchant and quest-giver who is an alias of a tracked quest
  while standing in patter (Hulda/BQ01, Delphine/MQ106 at her bar, Balgruuf's steward), and misses nothing the
  precise test catches: Irileth at the gate, Balgruuf's court, Arngeir, the shack and the sacrament all run under a
  quest with a displayed objective (`sqj=1`), and TG00's approach - no objective yet - is caught server-side by
  `lrgDlgQuestKnown`. `sq=` in the log is the fact; the globs were a guess that the log contradicts.
- **Test:** `test_dialogue` 16 + 26: Hulda snapshot `scene=1 sq=DialogueWhiterunBanneredMareScene3 sqj=0` +
  "what have you got?" -> `do=open amb=1`; E-press `ev=open scene=1 sq=... sqj=0` -> `sj=0`, driven; Irileth
  `sq=MQ102 sqj=1` -> `sj=1`, read-only at `clicks_ok=0`, scene rail at 1; Brynjolf `sq=TG00 sqj=0` -> server
  `sj=1` (known journal quest); Helgen `sq=MQ101` -> no open; an old `ev=open` with `scene=1` and no `sq` -> `sj`
  from `scene && !ambient`. `d50`/`d53`: `sq`/`sqj` on `ev=open` parse. `test_mcm_wiring`: the `iSceneGate` help
  text names the real test ("a scene whose quest has an unfinished objective in your journal"). In game: (2b)
  `clicked pos= origin=engine sj=0` while the bard sings; (5b) `sj=1` at Irileth.
- **Cost:** arming -1 PO3 sweep (`GetActiveAssociatedQuests`), +0 when the snapshot is fresh; server +1
  `lrgDlgQuestKnown` per session (a hash lookup).

### R2 - The pre-LLM open fires on "no open session", its service alternative recognises the sentences the tests name, and the harness runs first contact WITHOUT a cached root
- **Where:** S2.1 (`list=none`; `lrgDlgServiceKind`), S5 (kind pick), S9, Lane B (config), 3.7 `FE.hulda.open`
  / `FE.hulda.room`, tests 25/26/29, 5.3 steps 3-4.
- **What:** (a) S2.1's condition is "no OPEN session for this NPC" (`session.state` closed); a fresh cached root
  (`list=root`) is allowed and IS the ">= 0.5 against her cached root" alternative. (b) Lane B extends the kind
  phrase lists (config, `services.kinds.*.phrases`) so that every service sentence the spec's tests and 3.7 name
  is a kind hit on its own: barter += `what have you got`, `what do you have`, `what do you sell`, `show me your
  wares`, `anything for sale`; inn += `i'd like a room`, `i need a bed`, `a room`, `a bed for the night`; the
  existing `not_after` guards apply ("can I buy you a drink" stays a gesture). `lrgDlgIsService`'s bare
  `service_words` are NOT admitted to the pre-LLM marker (one word, `buy`/`ride`, is too wide for a visible open).
  (c) `FE.hulda.open` runs TWICE: with no cached root ("what have you got?", "I'd like a room" -> open by kind;
  "uh some beer" -> none; "Nice inn you have here..." -> NO pre-LLM open, asserted, so the page's step order in R3
  is proven necessary) and with her root cached ("Nice inn you have here..." -> open by the 0.5 root path).
- **Why:** section 0 items 1 and 3. Step 3 is the sentence he will say to every merchant he has never met, and
  the spec's own tests 26/29 and fixture rows fail on the shipped lists.
- **Test:** tests 25 + 26 + 29 rows as listed, both cached-root states; `test_services` (Lane B) asserts each new
  phrase maps to exactly one kind; 3.7 green in both states.
- **Cost:** 0 (config strings; one condition wording).

### R3 - The owner's step order makes step 2 the fast path: step 1 is an E-press on HULDA, and the page says why
- **Where:** 5.3 steps (1)-(2), 5.2 first bullet, Lane F.
- **What:** step (1) becomes "Press E on Hulda in the Bannered Mare, look at her list, press Tab" (this learns the
  four rows AND caches her list for half an hour); step (2) "Nice inn you have here, do you get many visitors?"
  within that half hour -> `do=open` pre-LLM (R2's root path) -> `clicked pos=`. Add one sentence: "if you wait
  longer than half an hour, press E on her again first". Keep (2b) as the bard-song proof. Everything the page
  names in (2)-(4) is then on the pre-LLM path with the bridging line, never the double answer.
- **Why:** section 0 item 1; the linchpin step must not depend on whether the model fills `item` for small talk.
- **Test:** 3.7 `FE.hulda.plain` gains the cached-root open (R2c); Lane F verifies the sentence has rows (already
  required) AND that its open path is the root path at 0 index/kind/faction markers.
- **Cost:** 0.

### R4 - "New utterance" is defined against the last CLICK and a short window, never against `session.at`; the harness advances the clock between the sentence and the list
- **Where:** S4.3 (the parenthetical), S4.5, 3.4 step 2, tests 19/21/23, `d66`/`d67`.
- **What:** on the fast path (`want=1`) `lrgDlgExplicit` / `lrgDlgSingleEntryRelease` accept the stored utterance
  when `utter.at > last_exec.at` for this NPC (the last click, any session) AND (`now - utter.at <=
  confirm.utter_window` (30 s) OR `open_pending`/`ask=` carries this utterance's cid - the sentence whose open
  produced this list). Strike "(or utter.at >= session.at of this gen when nothing was clicked yet)". The gate path
  keeps its cid test. Harness: `fxAdvance(5)` between `fxDlgSay` and the first `fxDlgTopics` of every beat, so
  `utter.at < session.at` as in game; one beat per quest presents a 300 s-old utterance on an E-press session and
  asserts nothing.
- **Why:** section 0 item 4: the first-contact release is the flagship ("I want to join the Companions - one
  sentence") and the parenthetical refuses it by construction; the previous-layer case (MQ103 intro -> the
  assignment on the old word `need`) is already covered by `last_exec.at`, and the 30 s window closes the stale
  case (a sentence from five minutes ago releasing on an E-press) that the parenthetical was there for.
- **Test:** 19: Kodlak first contact (utterance 6 s before `session.at`, `open_pending` set) -> explicit; the same
  utterance 300 s old on a new engine session -> nothing; MQ103 intro pick then B1 with the same utterance ->
  nothing (kept). 21b/23 likewise. `d66` first-contact Kodlak asserts `mode=explicit`.
- **Cost:** one timestamp compare.

### R5 - The auto-advance grace counts CONTINUOUS empty subtitle AFTER a line was seen; the progress timer is not an end signal
- **Where:** S4.6 (grace anchor), S4.10 row "unscripted single continuation", S12 row "auto-advance", Lane C task,
  5.5, open decision 2.
- **What:** for an `adv` pick, `StepClicking` sets `sawLine` when `Subtitle()` returns non-blank after the layer
  appeared; the grace counts only while `sawLine` and the subtitle is blank (`""` or `" "`), and ANY non-blank read
  restarts it (multi-response lines and the gap between voice files never fire it). `ProgressTimerId` is not used
  as an anchor (it marks a line's START, `LRG_DlgUI.psc:593-600`). With `bDialogueSubtitles` off (`SubtitlesOn()`)
  the auto-advance is not attempted and the pick waits for his words, and 5.5 says "the short breath needs
  dialogue subtitles on (they are on your install)". Cost stays the `Subtitle()` read the CLICKING poll already
  makes plus one bool.
- **Why:** section 0 item 5; the oath is the one thing on the page that clicks by itself, and the owner will hear
  the first mid-line cut.
- **Test:** in game: each `result auto=1` timestamp >= its `ev=line` timestamp + the line's length; offline none
  beyond `adv=` riding (the harness cannot see subtitles) - Lane C logs `adv anchor: line seen at=.. blank
  since=..` on the click so the first evening's log proves it.

### R6 - The assent test is a LEADING phrase, refusal words win, and the page names a fresh-start line
- **Where:** S4.4, S4.5, S9 (`confirm.assent_words`), tests 20/21, fixture rows MS05.viarmo.comein "yes, I'll do
  it", MQ105.arngeir.next "yes, what now", DB01.aventus.contract "yes, I'll take the contract", MS05.verse.final
  "yes, that's it", MQ103.farengar.assignment "alright, what's the task you need", MQ102.balgruuf.reward "yes,
  I'll help"; 5.3 / 3.7 for the fresh start.
- **What:** (a) "an assent" = the utterance's first 1-3 tokens equal a phrase of `confirm.assent_words`, followed
  by nothing or by more words (`^\s*(phrase)\b`, the convention `lrgDlgLayerIsOwnConfirmation` already uses,
  `lrg_dialogue.php:2881`), and no back-out/refusal word anywhere in it (`lrgDlgIsBackOut` first). The same
  definition serves the park release in S4.4 (so "yes, I'll do it" after her question releases instead of
  re-parking - the "she keeps asking me" report) and the single-entry release in S4.5. (b) 5.3 names ONE
  rail-passing line for a FRESH START in Riverwood, verified by `--first-evening` like Hulda's: a top-level
  `scripted=0` row of `DialogueRiverwood_Revised` / `ACFRiverwoodConversations` on a non-alias NPC ("Do you know
  any old ballads about dragons?", "Anything interesting going on in town?", "What can you tell me about
  Riverwood?" are candidates **[X]**; Lane E picks the one whose speaker `esp_dump.py` confirms and whose
  conditions hold at MQ102 stage <= 30) - because on a new game the first NPCs with lists (Alvor, Gerdur) are
  MQ102 aliases under a scene, and "ask me something simple first" then has no answer he can find.
- **Why:** (a) six fixture rows the spec itself marks `single` release only under a leading-phrase reading; the
  bare "yes" park rule as written is exact-match. (b) he said "the whole main questline"; a new save is the
  natural way to do that and it begins in Riverwood.
- **Test:** 20/21 rows above; "yes, but not now" -> nothing; test_gates 35 (no assent phrase is a back-out
  phrase); 3.7 gains `FE.riverwood.plain`.
- **Cost:** 0.

---

## 2. SUGGESTIONS (not blocking)

- **S1 - The re-arm example.** S4.6 uses "Who are the Greybeards?" as the question that cancels the auto and is
  answered by the model, while S4.5 (correctly) releases "who are the Greybeards" on the shared word. Both cannot
  be the example; use "what do you think of them" (the fixture's own `never` line) for the re-arm and say that a
  question ABOUT the entry clicks it (Balgruuf's real answer IS the answer).
- **S2 - A generic single-entry sweep in `test_questline`.** For every index layer line with exactly one norm
  whose quest is `MQ*`, `C0*`, `TG*`, `DB*`, `MG*`, `CW*`, `MS*`, assert the verbatim line releases through
  `lrgDlgSingleEntryRelease` and "uh what now" does not. No fixture rows, test time only - and it is the only way
  the brief's "whole main questline" (MQ201-MQ305, untouched by the 16-quest fixture) gets any offline coverage
  before v1.0.1.
- **S3 - Step (7).** Asking him to carry a bounty on the first evening invites a detour; word it "if you happen to
  have a bounty, a guard's list stays yours".
- **S4 - At Irileth, wait for the list.** With R4 a sentence spoken while she walks up is inside the 30 s window
  but arrives on a session the glue could not open (a journal scene); 5.3 (5b) should say "when her list appears,
  say ...", and 5.2's "do not repeat yourself" should except that case.
- **S5 - A pre-LLM single-entry release.** On a player speech turn with an OPEN one-entry layer, run
  `lrgDlgSingleEntryRelease` in `lrgDlgPrepareTurn` and queue the pick before the model runs (< 1 ms), so the
  flagship "So what do you need me to do?" never depends on the model choosing T1 over prose; the mute/bridging
  rules already cover the double. Decide on the first evening's log; not for gate A if Lane A is short.
- **S6 - Log the sj basis.** The arming line prints `sq= sqj=` next to `sj=` (R1) so a wrong classification is one
  grep, and the gate-B evidence line "clicked origin=engine sj=1 at Irileth" cannot be satisfied by an alias case.
- **S7 - Version-skew note for `scene=`.** Since `scene=` keeps its meaning (R1), S8's row for it becomes
  "unchanged" and the fallback row for `sj` reads "missing sj -> scene && !ambient".

---

## 3. Confirmed (the two reconciliation points and what should not move)

- **Checks inside a paused journal scene:** confirmed. A persuade on Brynjolf's pitch or an intimidate in the
  shack is the same click the scene is waiting for, under the same check rails (>= 4 words, live affordability,
  retry suppression), and the fixture's sc/check beats are in my own "every sc beat green". The one extra rule
  stays `clicks_ok >= 1`.
- **Single-entry floor 0.65:** confirmed. "what comes next" at 0.68 is the natural paraphrase of "Thank you.
  What's next?"; on a one-entry layer the false-release cost is bounded by the entry being the only thing to say,
  and scripted singles still need an assent or 0.65 on the whole line.
- Everything from round 1's section 3 stands, plus revision 1's grafts: the journal-scene rule behind the route
  proof with `session.drive_scene` as the kill switch, `lrgDlgDecide`/`lrgDlgWillEmit` as twins, the narrow
  pre-LLM marker with the bridging line, the hub-question rule, the `min(4, entry tokens)` floor and the 2-token
  slot, the shared assent list, the shape test, the leading-`?` "named" rule, the conditional reward line, the
  plain-sentence refusals, `--words` and `--first-evening`, the fixture built from layer lines, the talk key.

## 4. Facts checked for this review
- Code: `HasActiveJournalQuest` = `PO3_SKSEFunctions.GetActiveAssociatedQuests(npc, true)` filtered by
  `IsActive()` (`LRG_Dialogue.psc:4131-4143`); `OpenBlockedReason` scene branch (:1342-1348); `Arm()` `inScene`
  (:1772); `sq`/`sqj` in `SendFacts` (:3698-3710) and `EscortHasJournal` (`LRG_Main.psc:2805-2823`);
  `Subtitle()`/`SubtitlesOn()`/`ProgressTimerId()` (`LRG_DlgUI.psc:580-600`); the settle gate (:2585-2604);
  `lrgDlgSceneAmbient` (`lrg_dialogue.php:550-561`) and the globs (:101); `lrgDlgBusinessMarker` (:3025-3040);
  `lrgPromptLookup`/`lrgPromptBest` rank by quest (+10), never filter; the root cache write (:769-770) and TTL
  (`cache.max_age_seconds` 1800, :73/:1506); `list=root` from a fresh cache (:1503-1532); the kind phrases
  (:181-199) and `service_words` (:363); `lrgDlgLayerIsOwnConfirmation` yes-regex `^\s*(yes|aye|...)\b`
  (:2881); `lrgDlgQuestKnown` (index rows for the quest); `lrgPromptWords` stop list (:188-191).
- Index: `moretosaywhiterun.esp:000940` "Nice inn you have here. Do you get many visitors?" quest
  `ACFDialogueWhiterun`, scripted=0, toplevel=1, goodbye=0, nconds=3, resp "Thanks!"; `OfferServicesTopic`
  scripted=1 x3 plugins; `RentRoomTopic` scripted 1/0 by plugin, cost token; `MQ102ALegionOath1-3` scripted=0
  walkaway=1, `Oath4` scripted=1 goodbye=1; 0 rows for `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest`,
  `WITavern`, `DialogueGenericScene04`; TG00 31/31 journal rows, MQ102 22/22; 129 top-level plain rows mentioning
  Riverwood.
- Log: Hulda `q=ACFWhiterunVampires,WITavern,BQ01,DialogueWhiterun`, `sq=- sqj=0` (02:53:37, 02:56:15),
  `sq=DialogueWhiterunBanneredMareScene3 sqj=0` (02:54:52); sessions armed `scene=1` at 02:54:53 and 02:55:10;
  `sq=` values seen this install: `-` x32, `CW00SolitudeMapTableScene` x5, `MQ101` x2,
  `DialogueWhiterunBanneredMareScene3`, `DialogueGenericScene04`, `BardAudienceQuest`; the only ambient verdicts
  ever logged are the map table; every `do=open` in the log is `mode=calib`; 0 "a quest scene is running".
