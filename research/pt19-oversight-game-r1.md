# pt19 - GAME DESIGN DIRECTOR, round 1 review of `pt19-menuless-v1-spec.md`

2026-09-24. Read-only. Reviewed against `pt19-design-player.md`, `pt19-design-truth.md`, `pt19-design-perf.md`,
`pt19-design-survey.md`, the live index (37,561 rows, hash e756e311), the live log (`lorerim_glue.log` to 03:25 today)
and the code where a claim needed it (cited file:line). Lens: what the owner lives through, what he will say, what he
will report as a bug, and whether the spec delivers his brief ("the whole main questline ... flexible, capable") or a
safer subset. Currency: septims.

**Verdict: NOT approved as written.** The shape is right - the visible menu driven by voice is the design that gets a
real click into the log soonest, and the grafts (explicit release, bare yes, single-entry release, auto-advance, the
leave guard, never an invented reward) are the right grafts. But the spec contradicts itself in five places its own
tests would catch, and four of its rules produce a bug report on the owner's FIRST evening. Every required change
below is small; none adds a mechanism; three remove one.

---

## 0. The first evening as it would actually go under the spec as written

I walked the owner's own order (5.3) against the rules, the index and the log. This is what motivates R1-R5 and R10.

1. He presses E on Hulda while Mikael sings (he does this: 37 of 160 sessions in the log are `origin=engine`, and two
   of Hulda's three armed with `scene=1 visible=Scene` - `LRG_Dialogue.psc:1772` is `GetCurrentScene() != None`, and
   the tavern is a WI scene). Under S1.1 that session is READ-ONLY in gate A. He says "nice inn you have here" -> the
   model picks T1 -> the gate emits -> the game answers `Error: that is not on the table right now` (S1.1, :1148-1170)
   -> 10.21 voices it. **He hears her say a line is not on the table while it is on the screen in front of him.**
   Report: "the menu came up and she ignored what I said" (the player note's row 4 - the very report the visible
   design was meant to end).
2. He says "what have you got?" to an innkeeper standing free. The pre-LLM open queues (S2.1) - correct. But
   `OfferServicesTopic` "What have you got for sale?" is **scripted=1** in the index (Skyrim.esm, LoreRim Dialogue
   Patch), so with `clicks_ok = 0` the stage rail refuses it and she says "the first time, choose this one on the menu
   yourself". 5.2's promise ("the trade line is picked in front of you, the shop opens") is false until a PLAIN line has
   been clicked. He goes to Kodlak next: same label. Report: "she keeps telling me to choose it myself".
3. He says "uh some beer" to Hulda (he did, 02:54:23). `lrgDlgBusinessMarker` (:2987-3003) returns 'a quest this NPC is
   in' because her `q` is `ACFWhiterunVampires,WITavern,BQ01,DialogueWhiterun` - every city NPC carries a `Dialogue*`
   quest. Pre-LLM on the RAW sentence (S2.1) that means **every sentence to almost every NPC opens the vanilla menu**
   in front of him: the camera snaps in, he is locked in place, a list appears while he was ordering a beer. Hidden,
   this was a cost in seconds; visible, it is the whole experience. Report: "the dialogue menu keeps popping up".
4. Irileth at the gate, Balgruuf's court, Arngeir, Brynjolf, Aventus, Astrid, Galmar: class `scene` in gate A - "the
   list shows and you click it" (5.4). But 5.2 says "Irileth at the gate: the list shows, you say 'I have news from
   Helgen', it happens." Both cannot be true. Seven of the sixteen quests, including MQ102-MQ105, are hand-clicked at
   their advancing beats in v1.0. That is the safer subset, and the owner page promises the brief.
5. The oath: `MQ102ALegionOath1` auto-advances 2.5 s "after her line" - but the game's only anchor is the line-settle
   gate (:2585-2604: subtitle/progress-timer unchanged for 0.4 s), which is satisfied 0.4 s INTO a line, and
   `isActorTalking` sees only CHIM's TTS (:2585 comment). The next oath line is selected while Tullius is still
   speaking the previous one. Report: "she cuts herself off during the oath".

Everything below is ordered by how early in that evening it bites.

---

## 1. REQUIRED changes (what / where / why / test / cost)

### R1 - "scene" must mean a JOURNAL-QUEST scene on both sides, not any scene
- **Where:** S1.1 ("the ENGINE opened it and the speaker is not inside a running scene (`GetCurrentScene() == None`)"),
  Lane C (1) `Arm()`, Lane E's fixture `scene` flag, the `ev=open scene=` key.
- **What:** `Arm()` classifies `vis = "scene"` with the test `OpenBlockedReason` already trusts at `iSceneGate = 1`
  (:1342-1348: a journal quest owns the speaker, `HasActiveJournalQuest`), never with bare `GetCurrentScene()`. An
  engine-opened session on an actor in an ambient scene (WITavern, `*Sandbox*`, `*Idle*`, BardSongs, the map table -
  the server's own `scenes.ambient` globs, `lrg_config.default.json:516`) is driven exactly like a free-standing NPC.
  `ev=open` carries `scene=1` only for a journal-quest scene (or gains `sj=1`; additive) so the server's scene rail
  (`lrgDlgAnswerWant` :2317) means the same thing. The arming log line prints both (`scene= sj=`).
- **Why:** the log: Hulda's E-press sessions arm `scene=1` (02:54:52, 02:55:09). The snapshot's `scene=` is also
  `GetCurrentScene() != None` (`LRG_Profile.psc:447`). Innkeepers and merchants are "in a scene" for much of the day.
  As written, pressing E on the owner's daily innkeeper is read-only.
- **Test:** `d66_visible`: an engine session with `scene=0 sj=0` on an ambient actor -> T-key -> `do=pick`; with `sj=1`
  -> nothing (until R2). `test_questline`: one Hulda-in-WITavern beat (root list, origin engine). In game, step (2b) of
  the owner page: E on Hulda while the bard sings, "nice inn you have here" -> `clicked pos=`.
- **Cost:** 0 new natives (`HasActiveJournalQuest` replaces `GetCurrentScene()` at arming; both are one call).

### R2 - Scene-paused engine menus drive in gate A behind the route proof, not behind a rebuild
- **Where:** S1.1 (`bDriveSceneMenus`), S2.4 gate B, Lane C (1), Lane A (the scene rail), Lane E class `scene` /
  `--stage=B`, S10 `settings.ini`, 5.2/5.4, open decision 4.
- **What:** `bDriveSceneMenus = 1` ships in gate A. Game: an engine-opened session inside a journal-quest scene is
  driven when `bDriveSceneMenus == 1 AND CalGet("route") > 0 with src=live` (the route proven by a real click - the
  same evidence gate B asks the owner to report); before that it is `vis = "scene"` (read only, voiced per R3). Server:
  the scene rail in `lrgDlgAnswerWant` / `lrgDlgGateItem` becomes gate B's rule now: on `sj=1` sessions execute only
  `plain` / `back` / single-entry release / explicit release / auto-advance, park commits, and only while
  `clicks_ok >= 1`. The glue still never OPENS on a journal-quest scene actor (unchanged). Lane E: the `sc` beats carry
  `facts.clicks_ok = 1` and are asserted at what the table calls their stage-B class; with `clicks_ok = 0` they assert
  nothing clicked; `--stage=B` remains for the reward only. 5.4 loses the "v1.0 you click it" sentence.
- **Why:** this is the brief. MQ102 (Irileth, Balgruuf), MQ103 (Balgruuf), MQ105 (all four Arngeir beats), TG00 (all
  of Brynjolf's approach), DB01 (Aventus), DB02 (the shack), CW00A (Rikke), CW00B (Galmar) advance inside these menus.
  The engine PAUSED the scene on the player's list; a voice click there is byte-identical to the mouse click the scene
  is waiting for (the truth note's P5 and the perf note's S1 both say so). Gate B's only stated reason is "after the
  first evening's clicks are in the log" - i.e. proof that the click body works on this install. The install measures
  that itself (`route src=live`, `clicks_ok`). Tying the flip to a rebuild costs a second evening and one guaranteed bug
  report at Irileth; tying it to the proof costs one compare. The owner "wants the FINAL BUILD soon" and "reports
  symptoms, never settings".
- **Test:** `d66_visible`: `sj=1` + `clicks_ok=0` -> nothing; `sj=1` + `clicks_ok=1` -> plain pick, commit parks,
  single-entry releases, `adv=` on a single unscripted entry. `test_questline`: every `sc` beat green at its class with
  `clicks_ok=1`. In game, owner step (5b): Irileth at the gate after step 2 - "I have news from Helgen about the dragon
  attack" -> `clicked pos=` with `origin=engine sj=1`.
- **Cost:** 0 natives; one rail line each side.
- **If the panel keeps this at gate B anyway:** then 5.2 must drop the Irileth sentence, 5.4 must list the seven quests
  by name, and R3 is mandatory - the owner must never hear "not on the table" for a line on his screen.

### R3 - A pick the driver will not make is said honestly, pre-LLM, and never as "that is not on the table"
- **Where:** S1.1 (`CmdSelectTopic` `live && !IsDriving()` -> "that is not on the table right now"), S1.3
  (`lrgDlgHandBackForeseen` keeps only crit 2 and arrest), S7.
- **What:** `ev=open` gains `drv=1|0` (additive: is the driver driving this session). On `drv=0` sessions (scene before
  the route proof, lethal, module off, smart-talk skip): (a) `lrgDlgHandBackForeseen` adds the clause so her words say
  "choose it on the list yourself - I cannot pick for you here" in the SAME turn, no extra call; (b) the gate and
  `want=1` emit nothing (log `gate: read-only session why=<vis>`), so no funcret error turn ever fires; (c) the
  game-side string for a pick that still reaches an undriven live session becomes `Error: choose that one on the list
  yourself` (on test_gates 35's closed list; `lrgVoicedWhy` maps it), replacing "that is not on the table right now"
  for that branch only (keep the old string for a pick outside the live list).
- **Why:** as written the model is shown the list, picks a key, the gate emits, the game refuses with a funcret error,
  and 10.21 voices a false sentence in a second paid turn. "Never silent, never false" fails on the same turn.
- **Test:** `test_dialogue` 16: `ev=open drv=0` -> T-key -> no emit, the foreseen line present in `<business>`;
  `test_gates` 35: the new string, no machinery word. `d66` step.
- **Cost:** one key on `ev=open`; one clause; -1 LLM turn per refused pick.

### R4 - The pre-LLM open must not fire on every sentence: narrow its marker, and hold the barter net while it is pending
- **Where:** S2.1, Lane A (2), `lrgDlgBusinessMarker` (:2987), `lrgDlgServiceDirect` / `lrgDlgServiceNet`.
- **What:** the pre-LLM path uses a NARROW marker: a faction join ask (`lrgFacMarker`), or a service kind in his words
  (`lrgDlgServiceKind(utter) !== ''`), or >= 0.5 against HER cached root, or an index hit restricted to rows whose
  `quest` is in her `q` AND sharing >= 2 meaning words with the utterance. Never the bare "a quest this NPC is in",
  never a whole-index hit. The post-LLM open on the MODEL's item keeps the wide marker as today. While
  `open_pending == cid`, `lrgDlgServiceDirect` is held back for that turn and a model-chosen `OpenInventory` /
  `Rent_Room` / `Hire_Carriage` shortcut is dropped (the real entry is about to win); when `open_refused` is set the
  next turn re-allows the direct-barter net.
- **Why:** Hulda's `q` alone would open on "uh some beer". Visible, a false open is the dialogue camera and a movement
  lock. And "what have you got?" today produces BOTH the engine's click on the trade line (~6 s) and CHIM's
  `OpenInventory` from the same LLM reply (~7 s): the barter window twice, or the handoff suspending the driven
  session mid-click.
- **Test:** `test_dialogue` 25 adds: Hulda + "uh some beer" -> no queue row; Hulda + "what have you got?" -> row;
  Delphine (q=MQ106) + "nice weather" -> none, + "I'd like the attic room" -> row; Lydia + "what do you think about the
  war" -> none; `d21`: no `OpenInventory` in the reply's actions while `open_pending`; with `open_refused` the net appends.
- **Cost:** the same <= 5 ms; the index lookup is already scoped by `q`.

### R5 - Ambient actors: service opens stay allowed; only the noisy markers are refused
- **Where:** S2.2 ("on an ambient actor the business marker must be a faction join ask - never a service word"), Lane A (3).
- **What:** on `ambient=1` open on a faction join ask OR a service kind word OR >= 0.5 against her cached root; refuse
  the bare quest-in-q and whole-index markers (as R4); additionally refuse any open on an NPC whose greet row carries
  `flags.sayonce` while her faction road is closed (the Legion row's `closed` cell already does this for Tullius/Rikke
  through `lrgFacRefusesOpen`).
- **Why:** the ambient globs are `*MapTableScene*, BardSongs*, *Idle*, *Sandbox*, WI*` - merchants behind counters and
  innkeepers are `ambient=1` much of the day (Hulda's own snapshot says `scene=1`). As written, "I'd like a room" and
  "what have you got?" open only when she happens to be idle; the owner's steps 2-3 become luck. The say-once risk the
  narrowing protects is Tullius's, and the road rule already covers him.
- **Test:** `test_dialogue` 26: Belethor with snapshot `scene=1 sq=WISandbox` + "what do you sell" -> `do=open amb=1`;
  Rikke + "what do you sell" -> no open (recruiter, road closed); Tullius after Helgen + join ask -> open. In game: any
  merchant behind a counter answers "what have you got?" with the list.
- **Cost:** none.

### R6 - Single-entry release: the question exclusion contradicts test 21 and eight fixture beats
- **Where:** S4.5 ("not a question: `?` or a leading what/why/how/who/where/when/which/is/are/do/does/can/could/would/
  should"), Lane A (9), test 21, fixture rows MQ102.balgruuf.reward, MQ103.farengar.assignment / .stone,
  MQ104.balgruuf.want / .greybeards, MQ105.arngeir.next, MS05.viarmo.comein / .task / .verse.final.
- **What:** a question-shaped utterance still releases when it shares >= 1 meaning word with the entry
  (`lrgPromptWords` intersection, after adding why/how/who/where/when/which/can/could/would/should/does/mean/now/
  then/wait/uh to its stop list at `lrg_prompt_index.php:188`); a question with NO shared meaning word -> words.
  Fixture: "who are the Greybeards" becomes `single`, not `words` (asking who they are IS "The Greybeards?").
- **Why:** most single-entry targets on the questline are questions ("So what do you need me to do?", "Oh, do you
  mean this old stone?", "Is that it?", "What else can I help you with?"). The natural way to say them leads with
  what/do/is - the rule as written refuses the spec's own test line "what do you need me to do". The rule's intent
  (the player asking the MODEL something) is preserved: "uh what now" and "wait, what does that mean?" share nothing
  with the oath and stay words.
- **Test:** test 21 unchanged plus 21b: the eight fixture lines through `lrgDlgSingleEntryRelease` directly; the
  fixture's `single` rows resolve on the fast path.
- **Cost:** none.

### R7 - The explicit rule and the fixture disagree on the token floor; pin one
- **Where:** S4.3 (>= 4 tokens), test 19 ("the sword" -> park), fixture C00.eorlund.choice ("a sword, please" explicit,
  "the sword" park), MS05.court ("are we ready" explicit, 3 tokens).
- **What:** (i) path (a) floor: 4 tokens when score < 1.0, 3 tokens at an exact 1.0 hit (the engine's own line said
  verbatim - "are we ready"); (ii) path (c), the slot matcher, is explicit at >= 2 tokens (it already handles negation
  and price questions), so "the sword" on Eorlund's five is explicit - change test 19 and the fixture. A one-token STT
  "sword" stays a park by the 2-token floor.
- **Why:** "a sword, please" (3 tokens) can only be explicit through the slot path; then "the sword" is explicit by the
  same path and test 19 contradicts it. From the player's seat, on a five-weapon list "the sword" is as explicit as
  speech gets; parking it and asking "the sword, you mean?" is the "she keeps asking me if I'm sure" report.
- **Test:** test 19 + the two fixture rows; `test_questline` MS05.court / C00.eorlund.choice.

### R8 - Test 17 (Delphine's hub -> one commit) is unreachable under S4.1; decide the rule, then the owner text
- **Where:** S4.1, Lane A test 17 ("MQ106 C2Horn layer -> 1 commit C5"), 5.2 ("Delphine's room: nobody asks 'do you
  mean it?' unless you say you are leaving").
- **Fact [X]:** the C2Horn layer (parent `skyrim.esm:08649A`, 7 entries) has FOUR `scripted=1` rows: C3Dragonborn,
  C4 "you said you're in hiding from who", C2Horn, C5. The sibling rule ("scripted on a closed layer with >= 2 scripted
  siblings") grades all four commits; only `fx` (deferred to L2) tells cosmetic from stage.
- **What (pick one and write it):** (A) narrow the sibling rule with a test the index can answer: a scripted entry whose
  `links` lead back to the layer it sits on (a hub question - after her answer the same list returns) is NOT a commit;
  scripted siblings count only when they end (`goodbye`), walk out (a `twat` target) or branch away. C2Horn's hub then
  keeps C5 as its one commit; Eorlund's five (goodbye) and MS08's kill/spare stay commits; test 17 passes as written.
  (B) keep S4.1, rewrite test 17 to four commits and 5.2 to "in Delphine's room she may ask once on the four questions
  that carry a script unless you say them plainly". (A) is the player's experience the spec promises and costs one
  loop over `links`.
- **Test:** `test_prompt_index` 5d pins the C2Horn hub shape (4 scripted, links superset of the hub) so a load-order
  change is caught; test 17 as chosen.

### R9 - The `reward` locked line must not ride every quest turn
- **Where:** S6.1, Lane B (6), test 38.
- **What:** the ~150-char line rides only when the utterance carries a `checks.stakes.reward` word (Lane B adds the
  list anyway) or a reward window is open (S6.2's definition, usable in gate A: a driven click on a `*Reward*` row or
  a `resp` with reward/take this/token of, or `ev=result` gold into the purse, 180 s). `hide_reward` and the truth
  clause stay unconditional on quest turns (that is the never-false part).
- **Why:** `<locked_facts>` is exactly what models surface unprompted; Farengar answering "have you learned anything
  about the dragons?" with "and I cannot add septims to your reward" is the report. On a faction turn the line is
  trimmed anyway (the Helgen line is 500-522 of the 600), so it rides mostly where it is least wanted.
- **Test:** test 38 adds: a quest turn without a bargaining word -> the line absent; with "I deserve more" -> present
  after the faction line inside 600.

### R10 - The stage rail: honest wording, an indexed line, and a first-evening line the harness proves
- **Where:** S3.3, S7 ("the first time, choose this one on the menu yourself"), 5.1-5.3, Lane B (2), Lane F.
- **What:** (a) the label and the voiced reason become "I have not picked a line for you yet - ask me something simple
  first, a question, then I can pick this one" (and the corner note once per session says the same); (b) the rail
  requires `indexed=1` (an unindexed entry reads `scripted=0` by default and would be the first click on an unknown
  fragment); (c) 5.2 says "after your first simple question, 'What have you got?' picks the trade line" - because
  `OfferServicesTopic` is `scripted=1` [X] and `RentRoomTopic` has `scripted=1` variants (LoreRim Dialogue Patch) [X];
  (d) 5.3 step 2 names an indexed plain row on Hulda's own list - "Tell me about Whiterun." has 0 rows in the index
  (the log's `txt="Tell me about Whiterun."` is a runtime-renamed prompt); "Nice inn you have here, do you get many
  visitors?" is `ACFDialogueWhiterun`, `scripted=0`, top-level [X]. Lane F verifies every first-evening line against the
  index for the NPC it names.
- **Why:** "the first time" is false the second time he hears it, and he hears it on every scripted entry until a plain
  one is voiced. He goes to Kodlak first and never learns what unlocks it.
- **Test:** test 24 adds unindexed -> label; `test_questline` gains a "first evening" section over Hulda's real root
  rows asserting step 2 is a pick with `clicks_ok=0` and step 3 a pick with `clicks_ok=1`.

### R11 - Auto-advance is anchored to the END of her line, not to line-settle
- **Where:** S4.6 ("waits `reqAdv` ms after line-settle"), Lane C (8).
- **What:** the grace starts when the line has ended: the subtitle CLEARED (`subsOn`; the owner's sessions arm with
  `subs=1`) or the progress timer completed; when neither can be read, auto-advance is not attempted and the pick waits
  for his words (the S4.5 rule). Name the cost: the `Subtitle()` read the CLICKING poll already makes.
- **Why:** `StepClicking`'s settle gate is "subtitle / timer unchanged for 0.4 s" (:2585-2604) - true 0.4 s INTO a
  long line; selecting a topic interrupts the vanilla line. The oath, "The Greybeards?", Brynjolf's whole pitch would
  be cut mid-sentence by the one click nobody asked for.
- **Test:** in game: the four oath lines play whole; the `result auto=1` timestamp is >= the line's length after its
  `ev=line`. Offline: `d67` unchanged (`adv=` rides the pick); a fixture note that `adv` counts from line end.

### R12 - The harness must define a command on the target when `via = words` as a FAIL
- **Where:** section 3.4 step 4 (defined: resolve on the via; fail on a command on another pos or on words where
  via != words; undefined: a command on the TARGET where via == words).
- **What:** that case fails the paraphrase (the player asked a question and was clicked through). With R6 the fixture's
  remaining `words` rows are the ones that must never click ("hmm let me think", the < 4-word check, "fifty septims"
  below the price).
- **Test:** itself.

---

## 2. SUGGESTIONS (not blocking; each names its cost)

- **S1 - Service kind -> the root entry on the fast path.** "show me your wares", "let me see your goods", "I need a
  bed" share no meaning word with "What have you got for sale?" / "I'd like to rent a room" and fail 0.55; the menu
  opens (R4's service word) and nothing is picked until a second sentence and an LLM turn. `lrgDlgServiceKind(utter)`
  is already computed; the hide policy already maps kind -> the real entry. On `want=1` and in the gate's words path,
  root list, kind in {inn, barter, carriage, ferry, train} and exactly ONE class=service entry of that kind
  (`lrgDlgServiceKindOfLayer` / the entry's index kind) -> `do=pick` mode `kind`; two -> nothing (the model asks); never
  crime/follower. Test: Hulda root + "show me your wares" -> the OfferServices entry; + "I need a bed" -> RentRoom; two
  barter entries -> nothing. Cost: one compare per entry. This is the single cheapest thing that makes "services by
  talking" true in the owner's own words rather than the engine's.
- **S2 - Double-pick guard test.** Test 25 should also assert the LLM reply's T-key for the same NPC is dropped when
  `last_exec` is a `do=pick` with this cid (the fast pick already landed); otherwise the stale-gen re-match runs a
  `want=1` against the NEXT layer with the old `ask=`.
- **S3 - Assent shapes.** (a) single-entry release accepts ok / okay / alright / fine / right / go on / carry on (on a
  one-entry layer "ok" can only mean "go on"; keep them OUT of the bare-yes park release). (b)
  `lrgDlgLayerIsOwnConfirmation` (:2838) yes-shape += "i'm ready | i am ready | let's do it | i'm sure", no-shape +=
  "actually | i'm not sure | i need to think | not yet": the `MQ102JoinLegionYes/No` layer ("I'm ready to take the
  oath" / "Actually, I'm not sure...") is the engine's own confirmation but matches neither regex today, so "yes" there
  needs an LLM turn (d67 calls it an own-confirmation layer; it is not, yet).
- **S4 - Re-arm the auto-advance after a question turn.** He asks "who are the Greybeards?" during the breath, the auto
  is cancelled, the model answers, and the one-entry list then waits for an assent he may never phrase. On the next
  turn on the SAME single unscripted layer, when the gate found no T-key and the utterance was not a refusal, emit the
  `adv=` pick again (after her answer). Cost: one rule; 0 game change.
- **S5 - `ClassifyCrit` and the E-press on a guard.** Engine-opened on a guard is crit 2 today (an approaching guard =
  an arrest). With engine sessions driven, every E on a guard with 0 bounty (ILQE's DB01 route) is "lethal" and the
  corner note says "choose by hand". Grade crit 2 when `IsGuard AND (crime gold > 0 OR a DGCrime*/arrest topic is on the
  list)`; the server's arrest-class rail stays the backstop. Test: DB01.guard.report with `origin=engine` -> pick.
- **S6 - "Forget everything it learned" and `clicks_ok`.** CalForget resets `route` game-side but `clicks_ok` lives on
  the server's `*install*` row, so after a Forget the server emits scripted picks on an unproven route. Either CalForget
  sends `ev=calib reset=1` (server clears `clicks_ok`) or the stage rail reads route-proven from `ev=open cal=`.
- **S7 - The owner page names the sentence and the log line per step**, and gains (2b) "E on Hulda while the bard
  sings" (R1's proof) and (5b) "Irileth at the gate" (R2's proof); and one plain sentence on latency: "the first time
  you talk business her list can take up to eight seconds to appear; while it is on screen you may also click it or
  press Tab" - so he does not repeat himself into a second open request.
- **S8 - The general click anchor.** R11 fixes the auto case; consider the same end-of-line anchor for every pick on a
  layer that just changed, so the glue never interrupts her previous line (today's 0.4 s settle was tuned for hidden
  sessions where nobody watched her being cut off).
- **S9 - Leave-guard prompt wording.** "Leaving this list makes her walk off for good; only a [leave] key backs out
  cleanly" names a key that does not exist on such layers (the guard applies only when NO back entry is listed). Say:
  "no line here backs out cleanly; leaving is his to do by hand, and it ends things with her - tell him so". Also note
  `lrgVoicedWhy`'s 'leave-guard' mapping never fires (do=show answers OK, not Error): the prompt line is the carrier.
- **S10 - Stage rail keyed on the first VERIFIED click, not the first `ok=1`**: fine as is, but log `clicks_ok` on the
  `ev=result` line so the owner's evening report can quote it.
- **S11 - Negotiation feel in v1.0.** Every "I want more" is a flat refusal by locked line. Acceptable and honest; but
  let the model be told (in the same line) that she may SAY what the reward is when a real reward entry is on the list
  ("What about my reward?" is a click away) - so the refusal steers him to the real line rather than ending the topic.
- **S12 - Latency claim in S12's table.** "first-contact click 3-8 s" is right; 5.x should not imply instant.

---

## 3. What the spec gets right (so round 2 does not relitigate it)

The visible menu; the four passive rows and route by doing (the log already shows `fam:1 st:1 cm:3 rm:3` learned from
E-press sessions on this install - the gate goes green on load); the stage rail's idea; the pre-LLM open on D2; walk-
away as a leave guard; explicit release; bare yes on a named park; single-entry release; auto-advance for unscripted
singles; two candidates must agree; a scripted entry needs a shared word; the reward truth clause; the bounded bonus
through `do=award give=` in gate B; the harness over the real index; the removal list. None of these should move.

## 4. Facts checked for this review
- Index: C2Horn hub `08649A` = 7 entries, 4 scripted [X]; `OfferServicesTopic` scripted=1 [X]; `RentRoomTopic`
  scripted 1/0 by plugin [X]; "tell me about whiterun" 0 rows [X]; `MQ102JoinLegionYes` unscripted, `JoinLegionNo`
  scripted+goodbye [X]; `MQ102ALegionOath1-3` unscripted walk-away, `Oath4` scripted+goodbye [X];
  `ICQEGuardMaybeTopic` plain, `DB01_Bribe_Yes` bribe cost token [X].
- Log: Hulda `origin=engine scene=1 visible=Scene` x2; `cal k=... fam:1 ... st:1`; `q=ACFWhiterunVampires,WITavern,
  BQ01,DialogueWhiterun`; 0 `clicked pos`; the 02:53:52 calib pick closed `why=asked clicked=0`.
- Code: `Arm()` `inScene = GetCurrentScene() != None` (:1772); `OpenBlockedReason` iSceneGate 1 = journal test (:1342);
  `LRG_Profile` `scene=` (:447); `lrgDlgBusinessMarker` (:2987); `lrgDlgAnswerWant` scene rail (:2317); settle gate
  (:2585-2604); `lrgDlgParkOrRelease` (:2780); `lrgPromptWords` stop list (:188); `scenes.ambient` globs (config:516).
