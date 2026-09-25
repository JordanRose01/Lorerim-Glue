# pt19 - MENULESS QUESTING v1.0 - THE BUILD SPEC (synthesis of the panel round)

2026-09-24. Synthesised from the winning design (`research/pt19-design-perf.md`, the visible menu voice-driven) with
the grafts both judges asked for from `research/pt19-design-player.md` and `research/pt19-design-truth.md`, on the
map in `research/pt19-design-survey.md`. Nothing in the glue was edited in the panel round; this document is what the
build lanes execute. Currency: septims. Versions this spec starts from: server 0.5.7+pt18 (catalog v11), game script
510, prompt index v1 (37,561 rows, hash e756e311). Versions it produces: server **0.5.8**, script **511**, catalog
v11 unchanged, index v1 unchanged.

Marks: **[P]** PROTOCOL section, **[C]** code (file:function or file:line as of pt18), **[X]** the live index,
**[L]** the live log, **[J]** a judge's correction, **[O]** the owner's brief, **[rev1: game|ai|game+ai]** an item
revised in revision 1 on that director's required change, **[rev2: game|ai|game+ai]** an item revised in revision 2,
**[M]** a number from the real matcher (`C:\Users\Jordan\AppData\Local\Temp\lrg_test\signoff\rev2_verify.php`, the
pt18 `lib/` copied there and run under php 8.2 in WSL against the live index).

### REVISION 2 (2026-09-24, synthesiser) - what changed and why

Both directors refused revision 1 on six (game, `research/pt19-oversight-game-r2.md`) and five (ai,
`research/pt19-oversight-ai-r2.md`) REQUIRED changes; neither moved the shape. All eleven are folded in below and each
revised item carries `[rev2: ...]`. Every matcher claim in this revision was re-run through the REAL `lrgDlgMatchText`,
`lrgPromptWords`, `lrgDlgIsBackOut`, `lrgDlgPhraseHit` / `lrgDlgHitFollowedBy` and `lrgDlgServiceKind` of the pt18 tree
(`rev2_verify.php`, sections A-F; the index rows quoted were read from the live ndjson, 37,561 rows, hash e756e311).
Nothing in the glue was edited. Where revision 1's note and this one differ, this one rules.

R by R:
- **game R1 - the scene test.** S1.1, S1.3, S2.2, S8, S10, S12, Lanes A/C/D, 3.6, 3.7, 5.3 (2b)/(5b). `sj` = the
  scene's OWNING quest has an unfinished journal objective (`GetCurrentScene().GetOwningQuest()` through
  `EscortHasJournal` - the `sq`/`sqj` code `SendFacts` already runs), on both sides; `scene=` keeps today's meaning;
  `ev=open` gains `sq= sqj=`; the server's `sj` is the game's, OR `scene=1 && lrgDlgQuestKnown(sq)` (TG00's approach);
  ambient = fresh facts AND `sqj=0` AND (a glob OR `!lrgDlgQuestKnown(sq)`) - the globs become a shortcut, not a
  requirement. `HasActiveJournalQuest` leaves the driver and `OpenBlockedReason`.
- **game R2 - the pre-LLM open's condition and the kind phrases.** S2.1, S2.2, S5, S9, Lanes A/B, tests 25/26/29,
  3.7, 5.3. The condition is "no OPEN session for this NPC"; a fresh cached root is allowed and IS the root clause.
  `services.kinds.barter.phrases` += `what have you got`, `what do you have`, `show me your wares`, `anything for sale`
  (with `not_after` += `against`, `to say`, `planned`, `in mind`, `for me`, `there`, `left`, `to do`, `to lose`, `to
  hide`, `to eat`, `to drink`); `inn.phrases` += `need a bed`, `need a room`, `like a room`, `want a room`, `get a room`,
  `a bed for`, `a room please`, `a room for me`. Verified **[M]** through the real phrase loop: every service sentence of
  tests 25/26/29 and 3.7 returns its kind ("what have you got?" / "What do you have?" / "anything for sale" barter;
  "I'd like a room" / "I need a bed" / "can I get a room" inn) and the guards hold ("what have you got against the
  Stormcloaks" / "...there" / "...planned" / "...to eat", "what do you have in mind" / "...for me", "can I buy you a
  drink", "I need a drink", "uh some beer" all `''`); each new phrase maps to exactly one kind.
- **game R3 - the page's step order.** 5.3 (1)-(2), 5.2, 3.7 `FE.hulda.plain`, Lane F. Step 1 is an E-press on Hulda
  (the four rows AND her list cached for half an hour); step 2 is the plain line within that half hour; "if you wait
  longer than half an hour, press E on her again first".
- **game R4 - "new utterance".** S4.3, S4.5, 3.4 step 2, tests 19/21/23, `d66`/`d67`. On the fast path: `utter.at >
  last_exec.at` (this NPC, any session) AND (`now - utter.at <= confirm.utter_window` 30 s OR `open_pending` / `ask=`
  carries this utterance's cid); the `session.at` clause is STRUCK; the harness advances the clock 5 s between the
  sentence and the list and presents one 300 s-old utterance per quest.
- **game R5 - the grace anchor.** S4.6, S4.10, S12, Lane C, 5.5, open decision 2. CONTINUOUS blank subtitle AFTER a
  line was seen (`sawLine`); any non-blank read restarts it; `ProgressTimerId` is not an anchor; subtitles off -> no
  auto-advance; the click logs `adv anchor: line seen at= blank since=`.
- **game R6 - an assent is a LEADING phrase; a fresh-start line.** S4.4, S4.5, S9, tests 20/21, the six fixture rows,
  3.7 `FE.riverwood.plain`, 5.3 (8). First 1-3 normalised tokens equal a phrase of `confirm.assent_words`, followed by
  nothing or by more words, `lrgDlgIsBackOut` (and a leading no/nope/stop/never) checked FIRST **[M]**: "yes, I'll do
  it" / "yes, what now" / "yes, I'll take the contract" / "yes, that's it" / "alright, what's the task you need" / "yes,
  I'll help" are assents, "yes, but not now" and "I swear, but not yet" are refusals, and no assent phrase is a back-out
  phrase. The fresh-start line is Sven's "Do you know any old ballads about dragons?" (`DialogueRiverwood_Revised`,
  top-level, scripted=0, nconds=1 **[X]**), Hilde's "Anything interesting going on in town?" the fallback.
- **ai R1 - the scripted content test.** S4.5 (six steps), S4.8, S4.10, S9, Lane A, tests 21/21b, the fixture rows.
  Verified **[M]** on the real formula (the table in S4.5): the Legion oath refuses "long live Ulfric" and "long live
  the Stormcloaks" (0.721, precision 0.67), "death to the emperor" and "the emperor is a fool" (0.567), "hail the
  Stormcloaks, the true sons of Skyrim" (0.316) and "yes, long live Ulfric" (a foreign word after the assent);
  Farengar's assignment refuses "I need a drink" and "I need to think" (0.783 but a statement against a question) and
  "uh what now" (0.328, by content - ai S3); and every one of the spec's own paraphrases still resolves on the path the
  table names.
- **ai R2 - the stop list.** S4.5, S9, Lane A, tests 21/25, `DB02.captive.who`. Two lists, one function
  `lrgPromptWords($norm, bool $strict = false)`: SCORING keeps the shipped list (census over the 21,148 distinct norms:
  70 zero-word / 1,418 one-word, unchanged; "tell me who you are" on `who are you` stays 0.783 **[M]**); the STRICT list
  (+ why how who where when which can could would should does mean now then wait uh + they them these those there here
  we us he she him his her its our their; 198 / 2,154 under it) decides only where a single shared word or a precision
  term decides, and the scorer never sees it. Revision 1's widening is withdrawn.
- **ai R3 - the marker's clauses.** S2.1, S2.2, S5, S12, Lanes A/B, tests 25/29, 3.7, 5.2. Five clauses by the
  functions that exist, cheapest first, each logged by name: a join ask; a service kind (the extended lists); >= 0.5
  against her cached root; a VERBATIM top-level index row with >= 2 strict meaning words (step 2 fires here even cold:
  `nice inn you have here do you get many visitors` is ONE top-level row of `ACFDialogueWhiterun`, which is NOT in
  Hulda's `q` **[X]**); >= 0.55 with >= 2 shared strict words against her JOURNAL-quest rows (fetched once per session,
  cached 1800 s). Never a bare q membership, never a whole-index hit, never a shared-word query `lrgPromptLookup` does
  not have.
- **ai R4 - the re-armed auto-advance.** S4.6, S8, S12, Lanes A/C, test 22, 5.2. The re-armed emit carries `rearm=1`;
  the game anchors a `rearm=1` pick on the END of CHIM speech (`isActorTalking == 0` held 1.0 s with no `NoteSpeech(1)`,
  `LRG_Main:4223`'s logic) and the first pick on the engine line (R5); +1 native per 0.1 s poll only while a `rearm=1`
  pick waits.
- **ai R5 - `ok` = `okay`.** S4.4, S4.5, S9, test 20. Both spellings on the ONE shared list (`confirm.single_entry_extra`
  keeps only the continuers `go on`, `carry on`); the compare runs over `lrgPromptNorm` tokens, so "OK." and "Okay," are
  the same **[M]**.

Where the two directors' asks meet, the reconciliation:
1. **Step 2 cold** (game R2c/R3: "no pre-LLM open cold, so E-first is proven necessary" vs ai R3: "a verbatim top-level
   row opens cold"): BOTH. The top-level clause opens step 2 cold; the page still puts the E-press first because step 1
   must be an E-press anyway (the four rows) and it makes the root clause a second carrier; 3.7 asserts cold ->
   `marker=toplevel`, warm -> `marker=root`, and "uh some beer" -> none in both states.
2. **The service clause** (game R2b: kind phrases, "bare `service_words` are not admitted" vs ai R3 clause 2:
   `lrgDlgIsService`): the kind phrases, with the union of both lists minus game's bare `a room`; the word list stays on
   the post-LLM wide marker. ai's "where can I get a drink" is NOT a pre-LLM open: it is Jon Battle-Born's
   `DialogueWhiterun` row, journal=0 **[X]**, and the journal-rows clause is journal-only for exactly this reason - a
   shared town quest's row opens a list that does not carry the line.
3. **Assent-led utterances on scripted singles** (game R6: any tail, back-out first vs ai R1 rule 2: a shared meaning
   word): the leading phrase everywhere; on a COMMIT single the tail must carry no FOREIGN strict meaning word - the ai's
   precision idea applied where it matters. "yes, I'll do it" on Viarmo's non-commit single releases (game's row); "yes,
   long live Ulfric" on the oath does not (ai's case); "yes, but not now" is a refusal (both) **[M]**.
4. **ai R1's re-labelled fixture rows vs the index.** `MQ104BalgruufOutroD1`, `MQ102BalgruufReward` and
   `MS05PoemFinalVerse` are `goodbye=1` **[X]** and therefore COMMIT singles under S4.1; their loose paraphrases ("tell
   me what the Greybeards want", "is there anything else I can do", "is that all of it") are `park -> yes`, not LLM-path
   picks. `MQ102BStormcloakOath4` is `goodbye=0` **[X]**, so the Stormcloak oath's last line is a commit only through a
   new `commit: true` entry override (Lane B ships `config/lrg_dialogue_overrides.default.json` with both Oath4 rows;
   Lane A reads it in one line of `lrgDlgIsCommit`). The fixture's `DB01AventusQuestResponse4` does not exist: the row
   is `DB01AventusResponse4Topic` "Contract?" (skyrim.esm:01F339, scripted=1, goodbye=0).
5. **The grace anchor** (game R5: the engine subtitle vs ai R4: CHIM's speech end): split by `rearm=1`; a `NoteSpeech(1)`
   younger than 1.0 s restarts either count at 0 natives.

Suggestions adopted (each one rule or one line): game S1 (the re-arm example is "what do you think of them"), S2 (Lane
E's generic single-entry sweep over `MQ*|C0*|TG*|DB*|MG*|CW*|MS*` layer lines), S3 (step 7 wording), S4 (Irileth: "when
her list appears"), S6 (`sq= sqj=` on the arming line), S7 (S8's `scene=` row); ai S1 (the stage-rail line once per
prompt), S2 (`lrgDlgIsBackOut` += the breath's hesitations), S3 ("uh what now" is refused by content), S4 (the
`FE.hulda.plain` topic name), S5 (`named` by `?` yields to the two-candidate hint), S6 (re-arm when no pick was emitted),
S7 (the two `<business>` truths), S9 (the scoring gap said out loud), S10 (cold/warm latency rows). Not adopted: game
S5 (a pre-LLM single-entry release) - decided on the first evening's log, as the director himself says.

### REVISION 1 (2026-09-24, synthesiser) - what changed and why

(Revision 2 rules where the two differ: the scene test, the stop list, the single-entry content test, the new-utterance
guard, the grace anchor and the pre-LLM marker are as revision 2 states them below.)

Both directors refused round 1 (`research/pt19-oversight-game-r1.md`, `research/pt19-oversight-ai-r1.md`); neither
moved the shape (the visible menu, four passive rows, route by doing, the stage rail, the three releases, auto-advance,
the leave guard, never an invented reward, the harness over the real index). Every REQUIRED change of both is folded
in below; each revised item carries its mark. The lanes stay six with the same ownership, plus two files nobody owned
(`lib/lrg_prompt_index.php`'s stop list and `tools/test_latency.php` section 6 go to Lane A) and one new key
(`iKeyPushToTalk`), split D (the id) / C (the reader) like every other key. Nothing in the glue was edited.

Where the two reviews touch the same rule, this is the reconciliation:
- **Scene sessions (game R1/R2/R3, ai R8c).** "Scene" means a JOURNAL-QUEST scene on both sides (`sj=1`). Scene-
  paused engine menus are driven in gate A once the route is proven by a real click (`route src=live` game-side,
  `clicks_ok >= 1` server-side); before that the session is read-only (`drv=0`) and her line says so pre-LLM through
  `lrgDlgHandBackForeseen`, never through a funcret error turn. ai R8(c)'s `session.drive_scene` key stays as the
  server kill switch, default TRUE (the proof is measured, not scheduled); `--stage=B` remains for the reward only.
  The scene rail admits the same classes as a free-standing session, checks included (the fixture's sc/check beats
  are inside the game director's own "every sc beat green"); its one extra rule is `clicks_ok >= 1`.
- **Single-entry release (game R6, ai R1/R2/R7).** The question test compares SHAPES: a question-shaped utterance is
  refused only when the entry is not a question. The content test is per `scripted`: unscripted = an assent word OR
  one shared meaning word (with the widened stop list) OR score >= 0.65; scripted = an assent word OR score >= 0.65,
  never the one-word rule; an entry with no meaning words (`is that it`) = exact/containment >= 0.85. Every release
  on the fast path needs a NEW utterance. Fact checked in code: `the greybeards` carries one meaning word
  (`greybeards`) under `lrgPromptWords` as it stands (`lrg_prompt_index.php:186-197`: len > 1 and not a stop word),
  so "who are the Greybeards" releases by the shared word as game R6 asks; `is that it` carries none, as ai R1 says.
  The 0.65 floor is ai R1's own number ("what comes next" 0.68 against `thank you what's next`).
- **Explicit token floor (game R7, ai S1).** `tokens >= min(4, entry tokens)` on path (a); the slot path is explicit
  at >= 2 tokens; "I brought your shield, Aela" (0.675) becomes a park in the fixture rather than lowering a floor.
- **Assent words (ai R2 required, game S3 suggested).** One shared list (ai R2's); "ok / go on / carry on" are
  accepted by the single-entry release only, never by the bare-yes park release (game S3's own caveat).
- **Test numbering.** `test_gates` 40 = the plain-sentence refusals (gate A, ai R10); the gate-B award test is 41.

Suggestions adopted (each one rule or one line): game S1 (service kind -> root entry), S2 (double-pick test), S3
(own-confirmation regexes), S4 (re-arm the auto after a question), S5 (guard E-press crit), S6 (Forget clears
`clicks_ok`), S7 (owner page per-step lines + the latency sentence), S9 (leave-guard wording), S10 (log `clicks_ok`),
S11 (steer to the real reward line), S12; ai S1 (fixture park), S2 (hide_reward in the tracked hide list), S3 (reward
wording), S4 (state wording on a cached root), S5 (label words), S6 (ms test), S7 (hint scope), S8 (5.4 line), S9
(`--words` mode). Declined: game S8 (the end-of-line anchor for EVERY pick - when the player has already said his
answer, interrupting her line is what a vanilla click does too; the auto case, which nobody asked for, is the one that
must wait; revisit on the first evening's log).

---

## 0. The verdict in twelve lines

1. **v1.0 is the vanilla dialogue list, on screen, driven by voice.** The glue opens (or the engine opens) the real
   menu, the list stays visible, the player may click it by hand at any moment, and when his words name an entry the
   glue clicks it for him. "Menuless" means *he never has to click*. Nothing is hidden, guarded, parked or timed out
   behind a hidden menu; no emergency key is needed because the menu is never taken away.
2. Why: every in-game session to date ran visible (`Arm()` hides only when `!goManual && !sDryRun`
   **[C LRG_Dialogue.psc:1817]**), 91 of 359 turns were spoken over an open menu, and the log holds **zero real clicks**
   **[L]**. The hidden stack (hide / guard / cursor / PENDING / 45 s watchdog / emergency key / MCM-Helper auto-clear /
   6 of the 10 calibration rows / the automatic calibration session) is both unproven and the source of the owner's
   nightly "still learning 0 of 10". It goes.
3. Calibration shrinks to **four passive rows** learned from the first menu of any kind (`cm rm fam st`); the click
   route is proven by the first real click; until one click is verified on this install a **stage rail** lets only
   harmless, indexed plain lines be clicked, and any other pick is voiced as "ask me something simple first"
   **[rev1: game]**. The menuless dry run becomes an ordinary toggle, default OFF.
4. First contact never pays a second LLM turn: the open for awareness is queued **before** the model runs - on a
   NARROW marker (his words name a service, a faction, a verbatim top-level prompt of this load order, her own list or
   a line of her journal quests), never on "she is in some quest" **[rev1: game] [rev2: game R2; ai R3]**; on that turn
   the model is told the list is coming and says one bridging line **[rev1: ai]**.
5. Engine-opened menus (forcegreets, blocking branches) are driven the same way in v1.0. "Scene" means a scene whose
   OWNING quest has an unfinished journal objective (Hulda under the bard's song or in her tavern patter is not one;
   Irileth at the gate is) **[rev2: game R1]**; inside such a scene the list is driven as soon as the
   route is proven by one real click on this install - the same evening - and before that she says, pre-LLM, "choose
   it on the list yourself", never "that is not on the table" **[rev1: game+ai]**.
6. Confirmation is graded by consequence, not by walk-away flags: a walk-away flag guards LEAVING a list, never
   clicking, so it stops being a commit reason and becomes a leave guard. Commits still ask once - but the player's
   own explicit sentence IS the confirmation, a bare "yes" confirms what she just asked, and a single-entry layer
   (the oath lines, "So what do you need me to do?") never asks at all.
7. Effect-free single continuation lines advance by themselves after 2.5 s of continuous blank subtitle once her line
   was SEEN (never on the empty read before it starts; the progress timer is not an anchor), or - when he asked her
   something in the breath - 2.5 s after her CHIM answer ends, unless the player presses his talk key; effect-bearing
   ones wait for his words. Nothing else is ever clicked by a timeout **[rev1: game+ai] [rev2: game R5; ai R4]**.
8. Four narrowings of the matcher that cost nothing: the model's paraphrase and the player's own words must agree
   on the fast path; a scripted entry needs a shared meaning word on the similarity path; a price list is exact only;
   a scripted single releases only on the same shape with the entry's words and no foreign one **[rev2: ai R1]**.
9. Rewards: the engine's reward is fixed and she says so. v1.0 never invents one (a `reward` locked line on quest
   turns, and CHIM's five gold/item actions hidden there). v1.0.1 pays a **small, real** bonus in septims from her
   own purse after a real Speech check, through the glue's verified `do=award give=` - never through CHIM's
   `GiveGoldTo`, which mints gold from a short purse (`AIAgentAIMind.ConfirmMoveInventoryItem`: `RemoveItem` then an
   unconditional `AddItem`) **[J]**.
10. The map table opens the honest way (`iSceneGate = 1`; on an ambient actor the open needs a join ask, a service
    word or her own list, never "she is in some quest"; Tullius's say-once greet is guarded by the road rule)
    **[rev1: game R5]**; PROTOCOL 10.26's click-free entry STAYS as the fallback until the open is proven in game **[J]**.
11. Coverage is proven offline before the owner plays: `tools/test_questline.php` walks the real index rows of the
    main quest and the guild openings with three or more paraphrases per advancing line (section 3).
12. Two release gates: **A** (v1.0, this build) and **B** (v1.0.1, after one green evening): B switches on the
    bonus, applies the Papyrus diet, and deletes what A only stopped calling. Scene driving is no longer a gate-B
    flip: its proof is the first click in the log, which the install measures itself **[rev1: game]**.

---

## 1. THE SPEC

### S1. The visible menu, voice-driven (perf M1)

**S1.1 Sessions and who drives them. [rev1: game R1, R2, R3; ai R8c]** A "session" is one open vanilla Dialogue Menu
on one speaker. The glue DRIVES a session (reads the list, forwards it, clicks on the server's decision) when ALL
hold: `bMenuless` on; the speaker is not `crit 2` (lethal rule, see the guard note below); Smart Talk is safe; and
either the GLUE opened it, or the ENGINE opened it and the speaker is not inside a JOURNAL-QUEST scene, or the engine
opened it inside a journal-quest scene and `bDriveSceneMenus = 1` (settings.ini, **ships 1**, no MCM control) AND
`CalGet("route") > 0` with `src = live` (the route proven by a real click on this install). A session the glue does
not drive is READ (list forwarded, lines harvested) exactly as today's `ST_MANUAL`, and the server is told so.
- **"Scene" is a scene whose OWNING quest has an unfinished journal objective, on both sides. [rev2: game R1, S6]**
  `Arm()` computes `sj = speaker.GetCurrentScene() != None AND LRG_Main.EscortHasJournal(scene.GetOwningQuest())` -
  the test `SendFacts` already runs for `sq=`/`sqj=` (`LRG_Dialogue.psc:3698-3710`; `EscortHasJournal`
  `LRG_Main.psc:2805`: an objective displayed, not completed, not failed). It reuses the last snapshot's `sq`/`sqj`
  when they are < 30 s old (0 new natives) and runs the same code otherwise. `OpenBlockedReason` at `iSceneGate = 1`
  uses the SAME test in place of `HasActiveJournalQuest` (:1345), which leaves the driver: it is PO3's alias sweep
  filtered by `Quest.IsActive()` (:4131-4143) - an alias-of-a-tracked-quest test that fires on every innkeeper,
  merchant and quest-giver who is an alias of a tracked quest while standing in patter (Hulda is a BQ01 alias **[L]**,
  Delphine an MQ106 alias at her bar) and catches nothing the precise test misses (Irileth at the gate, Balgruuf's
  court, Arngeir, the shack and the sacrament all run under a quest with a displayed objective, `sqj=1`). Never bare
  `GetCurrentScene() != None` (:1772) either: Hulda's `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest` and
  `DialogueGenericScene04` **[L]**, the map table and `WI*` are scenes without a journal objective, so an E-press on
  Hulda while the bard sings is driven like a free-standing NPC. `ev=open` carries `sj=1` (additive, after `z=1`)
  only then, plus `sq=<owning quest id> sqj=0|1` after it (the basis, so a wrong classification is one grep; game
  S6); the existing `scene=` slot KEEPS today's meaning (any scene, :1772), so an old server sees what it sees today.
  The arming log line prints `scene= sq= sqj= sj= drv=`. `LRG_Profile`'s snapshot `scene=`/`sq=`/`sqj=` are untouched.
- **`drv=1|0` on `ev=open`** (additive): 1 when the driver drives this session, 0 for `lethal`, `scene` (before the
  route proof), `module off`, `smart talk skip`, `unknown swf family`. The server never emits a pick to a `drv=0`
  session (S1.3), so the read-only branch below is a version-skew backstop, not a path the owner hears.
- `Arm()` never calls `Guard(true)`, `Hide()` or `HideCursor()`. The visible-reason ladder keeps `module off`,
  `smart talk skip`, `asked for`, `lethal`, `scene` (a journal scene before `route src=live`), `unknown swf family`;
  `assisted` and `hide refused` are gone. `isHidden` is always false; `ev=open` carries `hid=0`.
- `IsDriving()` is true in LISTENING / READING / DECIDING / CLICKING / RESPONDING; the `live && !IsDriving()` branch
  of `CmdSelectTopic` (:1148-1170) fires only for a pick that reaches an undriven live session and answers
  `Error: choose that one on the list yourself` (new string, on `test_gates` 35's closed list; `lrgVoicedWhy` maps
  it). The old `Error: that is not on the table right now` is kept ONLY for a pick whose `pos`/`txt` is not on the
  live list. The owner never hears "not on the table" for a line on his screen.
- **The guard E-press (game S5).** `ClassifyCrit` grades `crit 2` when `IsGuard AND (crime gold > 0 OR a DGCrime*/
  arrest topic is on the list)`; a 0-bounty guard opened by E (ILQE's DB01 route) is crit 0 and driven. The server's
  arrest-class rail stays the backstop.
- PENDING is gone: `EnterPendingOrManual()` becomes "stay LISTENING with no decision outstanding" (no `Park()`, no
  corner note, no `fSilenceTimeout`). The list is simply on screen; the next player turn sees it as `list=pending`
  (server state unchanged) and the model or the fast path decides.
- `HandBack(why, resume)` becomes `StopDriving(why)`: log line `stopped driving why=<lethal|combat|scene|read-failed|
  unverified|handoff|dryrun>`, `ClearRequest(false)`, state -> MANUAL; no unhide, no `ev=unhide`, no `ev=resume`, no
  corner note except for `lethal` (unchanged: "choose this one by hand").
- `MaybeResume()` and `HandleEmergencyKey()` are removed; the Home key is unbound and its MCM control removed.
- A player mouse click while a server pick is in flight changes the signature -> `stale` -> re-read (existing).
- The 900 s session cap, `ReadList`, `SendTopics`, `TryResolvePick`, `StepClicking`'s click body, `StepResponding`'s
  six signals, `SettleAndReport`, `SendResult`, `Finish`'s `ev=closed` are unchanged except as S3/S4 say.

**S1.2 The poll.** `Step()` runs at 0.1 s in CLICKING / RESPONDING and 0.25 s elsewhere; per poll at most 3 natives
outside the click window (`EntryCount`, `Subtitle`/`ProgressTimerId`, `IsInMenuMode`); the guard upkeep block
(:1888-1902) is deleted.

**S1.3 Server side. [rev1: game R2, R3; ai R3, R8c, S4]** `lrgDlgGateItem` and `lrgDlgAnswerWant` drop the
`assisted`, `bi=2` and `x1` rails; `lrgDlgOnClosed` keeps `losses` as a log counter and no longer derives
`$t['assisted']`; the `ev=unhide` / `ev=resume` handlers stay (log, `handled`) for version skew.
- **`sj` on the server. [rev2: game R1]** `lrgDlgOnEvent` sets `sess.sj` = the game's `sj` when `ev=open` carries it,
  OR 1 when `scene=1` and `lrgDlgQuestKnown(sq)` (the index has journal rows for the scene's owning quest: TG00's
  approach has no objective yet, `sqj=0`, but 31 journal rows **[X]**, so Brynjolf's pitch is a scene session
  server-side although the game says `sj=0`); an OLD `ev=open` without `sj` falls back to `scene=1 && !ambient`.
  `ambient` (`lrgDlgSceneAmbient`, :549-561) changes ONE thing: the glob test becomes an allow-list SHORTCUT, not a
  requirement - a scene is ambient when the facts are fresh AND `sqj == 0` AND (it matches a `scenes.ambient` glob OR
  `!lrgDlgQuestKnown(sq)`). The index has 0 rows for `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest`,
  `WITavern` and `DialogueGenericScene04` and 22 / 31 journal rows for MQ102 / TG00 **[X]**, so `lrgDlgQuestKnown` is
  exactly "a quest scene"; the globs were a guess the log contradicts (the only ambient verdict ever logged is the
  Solitude map table; Hulda's sessions armed `scene=1` under a scene no glob names **[L]**). The read-only clause, the
  scene rail and the ambient open all read this `sj` / `ambient`. Cost: one hash lookup per session.
- **Read-only sessions.** `lrgDlgHandBackForeseen` returns true for `crit 2`, arrest, AND for a session that is
  `drv=0`, AND for `sj=1` while `clicks_ok == 0` or `session.drive_scene == false` (config, default **true**; the
  kill switch). On such a session the pre-LLM `<business>` carries the clause `choose it on the list yourself - I
  cannot pick for you here` so her words say it in the SAME turn; the gate and `want=1` emit nothing (log
  `gate: read-only session why=<vis|scene-unproven|drive_scene off>`); no funcret error turn is ever paid. The
  session is still read: the list is forwarded and the model sees the entries as facts, not as T-keys (the T-key
  block is replaced by the plain list on a read-only session so nothing is offered that cannot be clicked).
- **The scene rail** (`sj=1` and `clicks_ok >= 1` and `session.drive_scene`): the same classes and rails as a
  free-standing session - plain / back / service / pay / check under their own rails, a commit only through the
  explicit, bare-yes or single-entry release (else it parks), auto-advance on an unscripted single. The glue still
  never OPENS on a journal-scene actor (`OpenBlockedReason`, now on S1.1's owning-quest test). The one extra rule is
  `clicks_ok >= 1`.
- **One decision function.** `lrgDlgDecide(array $t, string $item, array $st): array{do, mode, why}` is the
  side-effect-free twin the gate calls (then applies the park write / the emit) and `lrgDlgWillEmit($t, $item)`
  returns `decide.do === 'pick' || (decide.do === 'leave' && a back entry)`. Consequently the TTS mute
  (`lrgDlgTransformer`) and the never-empty let-through (`lrgNeWillEmitPick`, `lrg_replies.php:210`) agree with the
  gate on every input: TRUE for the explicit / bare-yes / single-entry releases and for every plain / service / pay /
  check pick under its rails; FALSE for the stage rail, the leave guard (`do=show`, her line plays), a read-only
  session, a two-candidate ambiguity, an unreleased commit. Silence is impossible by construction: a refused pick is
  never muted.
- `<business state="...">` says `the list is on screen in front of <player>; things he can raise` ONLY while
  `session.state` is open; on a cached root list (`list=root`, session closed) it says `things <player> could raise
  with <npc> (her list is not open now)`; on an open closed layer `<npc> is waiting for an answer - the list is on
  screen`. The PENDING wording is gone.

### S2. Opening and first contact (perf M5 + M3 with the judges' corrections)

**S2.1 Pre-LLM open (M5). [rev1: game R4, S2; ai R6, S6] [rev2: game R2, R3; ai R3, S7, S10]** In `lrgDlgPrepareTurn`,
on a player speech turn with NO OPEN SESSION for this NPC (`session.state` closed; a fresh cached root, `list=root`, is
allowed and is clause 3's input - revision 1's `list=none` is gone because a fresh cached root IS `list=root` as the
server derives them, :1503-1532, so the two were mutually exclusive and step 2 could never fire warm), `io` on
(bIntentOpen), `lrgFacRefusesOpen` false and `lrgDlgBusinessMarker($t, $utter, narrow: true) !== ''`: queue
`do=open;sid=0;gen=0;pos=-1;i=-1;txt=;kind=plain;ask=<utterance words <= 60>` through `lrgDlgQueue` (D2, echoed on the
DLL's 5 s poll) and set `st['open_pending'] = cid`.
- **The NARROW marker** (pre-LLM only): FIVE clauses, each by a function that exists, tried cheapest first, the first
  hit logged by name (`open marker=join|kind|root|toplevel|qrows row=<info_key>`):
  1. a faction join ask (`lrgFacMarker !== ''`);
  2. a service kind in his words (`lrgDlgServiceKind($utter) !== ''`) - the PHRASE lists (`services.kinds.*.phrases`,
     `lrg_dialogue.php:180-229`), extended in gate A by Lane B so every service sentence the tests and the owner page
     name is a kind hit on its own: `barter.phrases` += `what have you got`, `what do you have`, `show me your wares`,
     `anything for sale`, with `barter.not_after` += `against`, `to say`, `planned`, `in mind`, `for me`, `there`,
     `left`, `to do`, `to lose`, `to hide`, `to eat`, `to drink`; `inn.phrases` += `need a bed`, `need a room`, `like a
     room`, `want a room`, `get a room`, `a bed for`, `a room please`, `a room for me`. Verified through the real
     `lrgDlgPhraseHit` / `lrgDlgHitFollowedBy` loop **[M]**: "what have you got?", "What do you have?", "anything for
     sale" -> barter; "I'd like a room", "I need a bed", "can I get a room" -> inn; "what have you got against the
     Stormcloaks" / "...there" / "...planned" / "...to eat", "what do you have in mind" / "...to say for yourself" /
     "...for me", "can I buy you a drink", "I need a drink", "uh some beer" -> `''`; each new phrase maps to exactly one
     kind. NOT the bare `service_words` (`lrgDlgIsService`, :363 / :1381 - one token, `buy` / `ride` / `drink`, is too
     wide for a visible open; it stays on the post-LLM wide marker) and NOT a bare `a room` (same reason);
  3. `lrgDlgMatchText($utter, her cached root) >= 0.5` (unchanged from rev1; on her root "what have you got" is a
     containment hit at 0.85, "I'd like a room" 0.87, "nice inn you have here" 0.85, "uh some beer" 0.27 **[M]**);
  4. a VERBATIM top-level prompt of this load order: `lrgPromptLookup([$utter])` (the exact-norm query the marker
     runs today) returns a row with `toplevel = 1` whose norm carries >= 2 STRICT meaning words (S4.5's list) - a
     sentence that IS a real opening prompt is business, whoever she is. The owner page's step 2 fires here even
     cold: "Nice inn you have here. Do you get many visitors?" is ONE row, `moretosaywhiterun.esp:000940`, quest
     `ACFDialogueWhiterun`, 5 strict words - and that quest is NOT in Hulda's `q` (the "More to Say" quests condition
     by GetIsID, not by alias, so no q-scoped clause can ever see it) **[X]**. The word floor keeps `who are you` (60
     rows, 30 top-level) and `what do you mean` (0 strict words) out **[X, M]**; `open.toplevel_marker` (true) switches
     the clause off for a diagnosis;
  5. `lrgDlgMatchText($utter, her JOURNAL-quest rows) >= 0.55` with >= 2 shared strict meaning words, over a per-NPC
     row set fetched ONCE per session (`quest IN (her q) AND journal = 1`, top-level first, capped at `open.qrows_cap`
     300; a new read-only `lrgPromptRowsForQuests()` in `lrg_prompt_index.php`, Lane A) and cached in state like
     `root` (1800 s). This is what revision 1's "an index hit sharing >= 2 meaning words with her q rows" has to mean
     if it is to run in 5 ms: `lrgPromptLookup` is an exact-norm / fingerprint / pattern lookup with no shared-word
     query (`lrg_prompt_index.php:365-395`). JOURNAL rows only: a shared town quest's row (`DialogueWhiterun`,
     journal=0, 97 top-level rows across many NPCs **[X]**) would open a list that does not carry the line ("where can
     I get a drink" is Jon Battle-Born's row); a journal row of a quest she is an alias of is hers. "I'd like the attic
     room" to Delphine (MQ106 in her `q`) hits `i'd like to rent the attic room` at 0.907 with 3 shared words **[M]**;
     `open.qrows_marker` (true) switches it off.
  NEVER the bare "a quest this NPC is in" (`lrgDlgBusinessMarker` :3024 today - Hulda's `q=ACFWhiterunVampires,
  WITavern,BQ01,DialogueWhiterun` fires it on "uh some beer"), never a whole-index hit. The post-LLM open on the
  MODEL's item (`lrgDlgMaybeOpen`) keeps the wide marker as today. Visible, a false open is the dialogue camera and a
  movement lock, so the raw sentence must not open on its own quest membership; the residual false open of clauses
  4 / 5 (a verbatim top-level or journal row that is another NPC's) leaves the REAL list on screen with nothing picked
  - he Tabs out or says something else - and the log names the clause and the row.
- `lrgDlgGateItem`'s own `lrgDlgMaybeOpen` is skipped while `open_pending == cid` (no double open). While
  `open_pending == cid`: `lrgDlgServiceDirect` is held back for that turn and a model-chosen `OpenInventory` /
  `Rent_Room` / `Hire_Carriage` shortcut is dropped from the reply (the real entry is about to win: "what have you
  got?" must not yield both the engine's trade click at ~6 s and CHIM's `OpenInventory` at ~7 s). When
  `open_refused` is set (S2.3) the next turn re-allows the direct-barter net.
- The list that follows arrives on `lrg_topics want=1` and is answered from the utterance (and `ask=` if the reply
  already landed). The LLM reply's T-key for the same NPC is dropped when `last_exec` is a `do=pick` with this cid
  (the fast pick already landed; otherwise the stale-gen re-match would run a `want=1` against the NEXT layer with
  the old `ask=`).
- **She does not answer twice.** The mute is best effort only: `lrgDlgTransformer` reads `last_exec` FRESH (bypassing
  `$GLOBALS['LRG_DLG_STATE']`'s per-process cache, `lrg_dialogue.php:628-643`, for that one key, at most one read per
  sentence) and mutes the message when it is a `do=pick` with the same cid. Because the first sentence streams
  ~2-3 s into the request and the pick lands 3-8 s after speech end, the mute usually loses; so the `open_pending`
  turn ALSO carries one pre-LLM directive in `<business>` (or the volatile block when no list is known), <= 220
  chars: `The list of what <player> can raise is being brought up now. Say ONE short line that does not settle the
  matter - an acknowledgement or a question back; if the list carries what he asked, his real answer follows from
  it.` Never empty holds; never false is not strained (she claims nothing). The owner page says "she says a word,
  then her real line plays". On that turn the directive REPLACES the "the list is on screen" state wording - there is
  no list yet (ai S7) **[rev2: ai]**.
- `Finish()` never calls `requestMessageForActor("again", MSG_DLGTALK)`; the server sets `session.talk_again =
  false` and `lrgDlgOnTalk` returns `handled` pre-lock (skew safety). Budget: first-contact click 3-8 s after speech
  end (D2 poll 5 s + open <= 1 s + read <= 0.3 s + want=1 0.5 s + settle) **[J: not <= 3 s]**; never a second paid
  turn. The pre-LLM path's cost is MEASURED in `tools/test_latency.php` section 6 (ms; clause 4 is the Postgres
  lookup the marker already runs, clause 5 costs one round trip per session for its row set, the rest is in-memory),
  target <= 5 ms warm with the q-row cache primed; the cold pass is asserted separately, < 30 ms (ai S10) **[rev2]**.

**S2.2 Ambient scene actors (M3, narrowed). [rev1: game R5] [rev2: game R1, R2; ai R3]** `settings.ini` `iSceneGate = 1`
(the existing `OpenBlockedReason` branch, now: open on a scene actor unless the scene's OWNING quest has an unfinished
journal objective - S1.1's test, in place of `HasActiveJournalQuest`). Server: `lrgDlgMaybeOpen` and the pre-LLM open no
longer refuse an `ambient=1` turn, and `ambient` is S1.3's definition (fresh facts, `sqj=0`, a glob OR a quest the index
does not know as a journal quest) - so Hulda's `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest` and
`DialogueGenericScene04` **[L]**, which match no glob, are ambient, and the owner's first-evening steps stop being luck.
On an ambient actor the open fires on the SAME five clauses as S2.1 (a join ask, a service kind, her cached root, a
verbatim top-level prompt, her journal-quest rows) and refuses the bare quest-in-`q` and whole-index markers. The
E-press session on such an actor arms `sj=0` and is driven. Tullius's say-once greet (0D5145 `OW`) is protected by the
ROAD rule, not by the marker:
`lrgFacRefusesOpen` (`lrg_factions.php:991`) refuses any open on an NPC whose greet row carries `flags.sayonce`
while her faction road is closed (already true for Tullius/Rikke through the Legion row's `closed` cell; Lane B
generalises it to any faction row with a say-once greet, one loop). `do=open` gains `amb=1` after `z=1` (log only).
The game's open on a scene actor is one `Activate`, what vanilla does.

**S2.3 The click-free entry stays (10.26) as the fallback.** `lrgFacQuestPlan` changes ONE condition: "the driver
cannot click" is `ml=0`, OR the entry is not on her cached list, OR (`ambient=1` AND the open is not possible: `io`
off, or the game refused the last `do=open` for this NPC within 120 s). The refusal is learnt from the `do=open`
funcret (`Error: ...` on a SelectTopic whose param carries `do=open`) -> `st['open_refused'] = {why, at}` in the
funcret handler of `lib/lrg_actions.php`. Order on an ambient join-ask turn with the road open: open first; the quest
entry only when the open cannot happen. Everything else in 10.26 (road, licence, voiced OK, `CmdQuestEntry`) is
untouched in gate A. Gate B decides deletion on evidence (section 6).

### S3. Calibration, the first click, the dry run (perf M2 + truth P7's probation name)

**S3.1 Rows.** `CalMissing()` = `cm` (counting), `rm` (reading, mode 3), `fam` (layout), `st` (Smart Talk settings) -
all passive, written by `CalArm` / `CalLayer` / `CalReadProbe` on the first menu of ANY kind (an E-press conversation
counts). `CalGreen() = cm && rm && fam && st`. Kept as **measurements** (never gates): `timer` (via `CalPoll`, one
native every 5th poll until answered), `x1` (via `CalSpeech`/`CalX1Poll`/`CalX1Verdict` - the free "does the session
survive speech" line, kept for the log **[J]**), `apd` (read once at arming), `ms3`, `tail`, `col`, `row`, `actms`.
Deleted: `hide`, `guard`, `rb`, `reopen`, the active pass A1-A5, the manual presses, the probe hotkey/shots, the
automatic calibration session (`CalServiceNpc`, `CalAuto*`, `CalArmBudget`), `CalPending`/`CalParkPoll`, the
`runs/armed/auto/gopen` store keys. The install file `Data/SKSE/Plugins/LoreRimGlue_calibration.ini` stays and now
carries `cm rm fam st route` + measurements.

**S3.2 Route by doing. [rev1: game S6]** `route` starts from the `fam` table (`ChooseRoute()`); the first click whose
RESPONDING signal arrives writes `CalSet("route", route, "live")`; a click that returns `ClickResult <= 0` twice, or
no signal within 9 s on route A, flips the route for the next attempt (the 1/1b logic moved from the press to the
click). `route src=live` is also what lets a journal-scene session be driven (S1.1). "Forget everything it learned"
(`CalForget`) resets `route` game-side AND sends `ev=calib reset=1` (additive) so the server clears `clicks_ok` on
the `*install*` row - otherwise scripted picks would go out on an unproven route after a Forget.

**S3.3 The stage rail (probation). [rev1: game R10, S10; ai R3, S5]** Server `*install*` row gains `clicks_ok`
(count of `ev=result ok=1` from driven clicks; the `ev=result` log line prints `clicks_ok=` so the owner's evening
report can quote it). While `clicks_ok == 0`: `lrgDlgGateItem` and `lrgDlgAnswerWant` emit `do=pick` ONLY for an
entry with `indexed=1`, class `plain` or `back`, `scripted=0`, `cost=0`, `kind=''`, `goodbye=0` (an UNINDEXED entry
reads `scripted=0` by default and would otherwise be the first click on an unknown fragment); the rest are covered by
ONE `<business>` line per prompt - `Until he has asked me something simple I can only pick T1, T4; for anything else
say: I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one`
(~200 chars where eight per-entry labels were ~880; ai S1) **[rev2: ai S1]** - pre-LLM (so her words say it, no extra
turn, and in her own words per 10.21), the corner note says the same once per session, and the gate logs `gate: stage
rail` and refuses the other keys regardless. The line carries no machinery word ("menu", "click", "gate") -
`test_gates` 35's closed-list scan reads it. `lrgDlgWillEmit` is FALSE under the rail (her line plays; nothing is
muted; S1.3). The first live click is therefore a harmless, indexed line the player asked for, on a menu he can see,
and he is told what unlocks the rest. "The first time" is not said: it is false the second time he hears it.

**S3.4 The dry run.** `bDlgDryRun` ships **0**, is never forced and never auto-cleared; `ReadCalibration` (e)/(e0)
and their MCM-Helper write are deleted. While the gate is red, `StepClicking` refuses with `still learning the dialogue
menu - the next conversation of any kind measures it` (log `WOULD CLICK`, no hand-back, the menu stays); while the
owner's toggle is on: `the menuless dry run is on (Menuless questing page)`. `MenulessLive() = bMenuless && !bDlgDryRun`
(unchanged formula; `ml=1` is now the normal state). The developer dry run `bDryRun` is untouched.

### S4. Confirmation policy (perf section 4 + grafts: player P2a/P3, truth P1/P2/P8/P13, auto-advance)

Classes come from `lrgDlgClass` / `lrgDlgIsCommit` **[C lrg_dialogue.php:1322-1413]** with exactly these changes:

**S4.1 Walk-away is not a commit. [rev1: game R8, option A]** `lrgDlgIsCommit` drops `if ($e['walkaway']) return
true;` and `if ($e['crit'] >= 1) return true;`. A row is a commit when: `never_auto` override; indexed `scripted &&
goodbye`; indexed `scripted` on a closed layer with >= 2 scripted siblings THAT ARE NOT HUB QUESTIONS (below); a
`commit_tags` tag; cost >= 100 or >= 25 % of the purse; unindexed fallbacks (`new` on a closed layer, `choice_words`)
- as shipped minus the two lines. `crit 2` stays LETHAL. The `[leaving now ends this]` label for class `back` with
`crit >= 1` stays (it is about leaving). `lrgDlgAnswerWant`'s consequential rail drops `crit >= 1` and `walkaway`
from its test (`commit` / class `commit|meta` / `crit 2` remain).
- **Hub questions are not commits.** A scripted entry whose `links` lead back to the layer it sits on (after her
  answer the same list returns) is a hub question, not a commit, and does not count as a scripted sibling; scripted
  siblings count only when they end the conversation (`goodbye`), walk out (a `twat` target) or branch away (their
  `links` leave the layer). One loop over `links` per entry, computed once per layer. Fact **[X]**: Delphine's C2Horn
  hub (parent `skyrim.esm:08649A`, 7 entries) has FOUR `scripted=1` rows (C3Dragonborn, C4 "in hiding from who",
  C2Horn, C5); under this rule the hub keeps C5 as its ONE commit, Eorlund's five (goodbye) and MS08's kill/spare
  stay commits, and test 17 passes as written. `test_prompt_index` 5d pins the hub's shape (4 scripted, `links`
  superset of the hub) so a load-order change is caught there first.

**S4.2 The leave guard (truth P1). [rev1: game S9]** `lrgDlgGateItem` `leave` branch: if a class `back` entry is
listed it is clicked (unchanged); else if ANY visible entry has `walkaway=1` or `twat !== ''`, emit `do=show` (mode
`leave-guard`, the menu is already visible so the game answers `OK: The menu is shown.`) instead of `do=leave;pos=-1`,
and `<business>` on such a layer carries one line: `No line here backs out cleanly; leaving is his to do by hand, and
it ends things with her - tell him so.` (the old wording named a [leave] key that does not exist on such layers).
`pos=-1` (the engine cancel that plays the walk-away topic) is never sent on a guarded layer. Note: `lrgVoicedWhy`'s
`leave-guard` mapping never fires (`do=show` answers OK, not Error) - the prompt line is the carrier, and
`lrgDlgWillEmit` is FALSE here so her line plays (S1.3).

**S4.3 Explicit release - the player's sentence is step one (player P3 = truth P2). [rev1: game R7; ai R7, S1]**
New `lrgDlgExplicit(array $t, array $e, string $utter): bool`, true when the turn is a player SPEECH turn on a NEW
utterance (below) and either (a) `lrgDlgMatchText($utter, visible entries)` lands on THIS entry with `score >=
confirm.said_line_score` (0.70), `margin >= confirm.said_line_margin` (0.25) and the utterance has `tokens >=
min(confirm.said_line_tokens (4), tokens of the entry's norm)` - so "are we ready" (3-token entry) is explicit at
3 tokens and a 2-token utterance is explicit only against a 2-token entry; an exact `1.0` hit needs 3 tokens at
least; or (b) `lrgFacArbitrate` matched the exact join line; or (c) the slot matcher matched exactly (`mode=pick`)
with >= 2 tokens (negation and price questions are already the slot matcher's own rules) - so "a sword, please" and
"the sword" on Eorlund's five are both explicit, while a one-token STT "sword" parks. Never true for: class `meta`,
a `never_auto` override, `crit 2` / arrest, a scoff-first failure variant, follower `dismiss`/`home`, cost >=
`confirm.min_gold` or >= `confirm.gold_fraction` x purse. In `lrgDlgGateItem`, before `lrgDlgParkOrRelease`: commit
&& explicit -> emit at once (mode `explicit`). In `lrgDlgAnswerWant`: a commit entry may execute from the fast path
ONLY through `lrgDlgExplicit` on the PLAYER's utterance (never on `ask=`). Kodlak's join is one sentence.
- **A fast-path release needs a NEW utterance. [rev2: game R4]** `want=1` arrives on every new layer with `st['utter']`
  still holding the sentence that produced the previous click (MQ103: "do you need any help with the dragons" -> the
  intro click -> the single "So what do you need me to do?" -> `want=1` would release the assignment on the OLD word
  `need`). On the fast path `lrgDlgExplicit` and `lrgDlgSingleEntryRelease` accept the stored utterance only when
  `utter.at > last_exec.at` for this NPC (the last click, any session) AND (`now - utter.at <= confirm.utter_window`,
  30 s, OR `open_pending` / the list's `ask=` carries this utterance's cid - the sentence whose open produced this
  list). Revision 1's parenthetical "(or `utter.at >= session.at` of this gen when nothing was clicked yet)" is
  STRUCK: at first contact the sentence is spoken 3-8 s BEFORE the session exists (S2.1's own budget), so it refused
  the flagship one-sentence join by construction, and the harness could not see it because it ran sentence and list
  on one clock (3.4 step 2 now advances the clock between them). The previous-layer case is covered by
  `last_exec.at`; the 30 s window closes the stale case (a sentence from five minutes ago releasing on an E-press).
  Otherwise the layer waits for his words or auto-advances under S4.6. The gate path has a new cid per player turn
  and needs only the existing speech-turn test. This is the guard pt9 S2 gave the two-step (`lrgDlgParkOrRelease`
  :2816).
- "I brought your shield, Aela" scores 0.675 on the real layer **[M]** and PARKS; the fixture says so rather than
  lowering a floor (ai S1). Floors are never lowered for a fixture line.

**S4.4 Bare yes (perf M4). [rev1: ai R2, R5] [rev2: game R6; ai R5, S5]** `lrgDlgParkOrRelease`: the utterance
releases the park when it is an ASSENT - a LEADING phrase: its first 1-3 normalised tokens equal a phrase of
`confirm.assent_words` (`^\s*(phrase)\b` over `lrgPromptNorm` tokens, the convention `lrgDlgLayerIsOwnConfirmation`
already uses, :2881), followed by nothing or by more words, with `lrgDlgIsBackOut` (and a leading no / nope / stop /
never) checked FIRST - so "yes, I'll do it" after her question releases instead of re-parking (the "she keeps asking
me" report), "yes, but not now" and "I swear, but not yet" un-park **[M]** - AND the parking turn's message named the
entry. ONE list shared with S4.5: yes, aye, yeah, yep, deal, agreed, sure, sure thing, do it, i do, i will, i swear, i
swear it, so be it, i'm ready, i am ready, fine, alright, all right, ok, okay, very well, of course, let's do it, i
accept, i'm in, count me in - `ok` AND `okay` both on it, compared as normalised tokens, so the microphone's spelling
changes nothing ("OK." and "Okay, I will" are the same assent **[M]**; ai R5). The tail is not tested for foreign
words here (unlike a commit single, S4.5 step 1): her question already named the entry, and a "yes" with a clause
after it is still a yes to it. `parked.said` = the model's message on the parking turn, stored by
`lrgDlgPostProcessActions`; **"named"** = >= 1 STRICT meaning word of the entry's norm (S4.5's list) appears in it, OR
`lrgDlgMatchText(said, [entry]) >= confirm.named_score` (0.45), OR her parking message contains `?` (she asked; there
is one park per NPC and it is at most `confirm.park_seconds` (60) old, so a bare assent inside that window answers
it) - EXCEPT when the two-candidate hint (S5) fired on that parking turn: her `?` then asks WHICH, and a bare assent
is not an answer to it (one flag on the park write; ai S5) - OR the parked entry is the layer's only commit. The
model paraphrases ("You'll ride with me to the tower, then?" for `I'll come along with you`), so two verbatim words
would almost never hold on layers with 2+ commits. The [commits] prompt rule (S11) tells her to ask "quoting the
choice in its own words". Refusals still un-park; other single tokens stay parked. `test_gates` asserts no assent
phrase is also a `lrgDlgIsBackOut` phrase (none is **[M]**).

**S4.5 Single-entry release (perf M4, narrowed per the judges). [rev1: game R6; ai R1, R2, R7; game S3] [rev2: game
R4, R6; ai R1, R2, R5, S3, S9]** New `lrgDlgSingleEntryRelease($t, $e, $utter)`: closed layer, exactly one visible
entry, the layer is indexed, class `commit` or `plain`, a player speech utterance NEWER than the last click (S4.3's
revision-2 test), and the steps below IN ORDER. Two word lists serve them (ai R2): the SCORE is `lrgDlgMatchText` as
shipped (the shipped stop list of `lrgPromptWords`, `lrg_prompt_index.php:188` - unchanged, so every number of round 1
stands and the index's reach is untouched), while "meaning word", "shared word", "foreign word" and "precision" are
computed under the STRICT list, `lrgPromptWords($norm, strict: true)` = the shipped list + why how who where when
which can could would should does mean now then wait uh + they them these those there here we us he she him his her
its our their (in code beside the shipped list; match-time only; `test_prompt_index` asserts the hash). Precision =
shared strict words / the utterance's strict meaning words, 1.0 when the utterance has none (no foreign word). A
COMMIT single = `lrgDlgIsCommit` (S4.1: `scripted && goodbye`, or the `commit: true` entry override). Real numbers
**[M]** in the table after the steps.
- **0. Refusal, then shape.** `lrgDlgIsBackOut` (widened with the breath's hesitations, ai S2: `need to think`, `let
  me think`, `give me a (moment|minute|second)`, `hold on`, `hang on`, `one moment`, `not so fast`) or a leading
  no / nope / stop / never -> nothing. A question-shaped utterance (`?` at the end, or a leading what/why/how/who/
  where/when/which/is/are/do/does/can/could/would/should, optionally after so/well/uh/um/and/ok/okay) is refused
  ONLY when the ENTRY is not a question (its `txt` does not end in `?` once trailing tags are stripped and its norm
  does not start with a question word). Most single-entry stage movers ARE questions ("So what do you need me to
  do?", "Oh, do you mean this old stone?", "Is that it?", "What else can I help you with?", "The Greybeards?") and
  their natural paraphrase is a question too; "wait, what does that mean?" against the oath is refused HERE; "uh what
  now" against the assignment is NOT (both are questions) - it is refused by CONTENT below (0.328, no shared word;
  ai S3).
- **1. A leading assent** (game R6; ai R5): the first 1-3 normalised tokens equal a phrase of `confirm.assent_words`
  (unscripted singles also take the continuers `go on`, `carry on`, `auto_advance.continuer_words`). Bare -> release.
  With a tail: on a NON-commit single -> release ("yes, I'll do it", "yes, what now", "alright, what's the task you
  need", "yes, I'll take the contract"); on a COMMIT single -> release only when the tail carries no foreign strict
  meaning word, `W_s(tail) ⊆ W_s(entry)` ("yes, I'll help" on Balgruuf's reward line, "yes, that's it" on "Is that
  it?", "yes, long live the Emperor" release; "yes, long live Ulfric", "yes, kill the Greybeards", "fine, long live
  the emperor and death to Ulfric" do not).
- **2. Score >= `confirm.single_entry_exact` (0.85)** - exact, containment, or F1 >= 0.77 - releases; on a COMMIT
  single also precision >= `confirm.single_entry_precision` (0.75). This is the only content path for an entry with
  NO strict meaning words (`is that it`, `what do i have to do`, `what does that mean`): `lrgDlgMatchText` skips the
  F1 tier when either side has no meaning word (:2240), so such lines are carried by exact / containment /
  similar_text only, by design (ai S9) - Lane A does not "fix" that.
- **3. Unscripted (`scripted=0`):** >= 1 shared strict word OR score >= `confirm.single_entry_score` (0.65) ->
  release.
- **4. Scripted, entry with >= 3 strict meaning words** (the oath lines, HaveStone): shape-equal (both questions or
  both statements) AND score >= 0.65 AND precision >= 0.75 -> release.
- **5. Scripted, entry with 1-2 strict meaning words** (2,154 of the index's 21,148 distinct norms have exactly one
  **[X, M]**; the assignment, Task2, HornA2, App2, D1, Reward): shape-equal AND >= 1 shared strict word -> release; on
  a COMMIT single also precision >= 0.75. "what do you need done" (question against a question, shares `need`)
  releases; "I need a drink" (a statement against a question) does not. "can I come in" and "yes, I need a drink
  first" on a NON-commit single are the admitted probes: the click is the only way forward on that layer and it is
  not a commit.
- **6. Otherwise nothing on the fast path; the model decides** (the matcher guards the fast path, the model reaches):
  on a T-key pick of a scripted single that is NOT a commit the gate applies only step 0 (a question to her is never
  clicked) and trusts the model; on a COMMIT single the T-key pick must pass steps 0-5 or it PARKS and she asks,
  quoting the line (S4.4 then releases it on "yes").
- `...` releases nothing (an empty norm; it is a continuer for S4.6 on unscripted singles only). Release without a
  park, in the gate and on `want=1`. The NPC's preceding line IS the ask. `<business>` state on such a layer: `<npc>
  waits for one answer, something like: "<entry>"`. `lrgDlgWillEmit` is TRUE on a release. Revision 1's widened
  scoring stop list is withdrawn: it doubled the index's zero-meaning-word lines (70 -> 140) and added 448 one-word
  lines, and turned "tell me who you are" on `who are you` from 0.783 into 0.37 while none of round 1's numbers needed
  it (ai R2).
- **Verified rows [M]** (`rev2_verify.php` section A/F over the real functions; test 21 pins every one with its path;
  the index flags come from the live rows):

| entry (strict words; class) | releases on the fast path (step) | nothing on the fast path (what then) |
|---|---|---|
| MQ103 B1 `so what do you need me to do` [need]; plain | verbatim 1.0 (2); "what do you need done" 0.783 (5); "alright, what's the task you need" (1) | "tell me what you need done" (a statement; the model's T-key); "uh what now" 0.328; "I need a drink" / "I need to think" 0.783 (statement); "what now?" 0.311 |
| MS05 Task2 `what do you need me to do` [need]; plain | verbatim; "what do you need" 1.0 by F1 (2); "alright, what do you need" (1) | "tell me what you need done" (T-key); "I need a drink first" |
| MQ103 HaveStone `oh do you mean this old stone` [oh, old, stone]; plain | "do you mean this old stone" 0.907; "you mean this old stone" 0.907; "oh, this old stone?" 0.907 (2); "yes, I have the stone" (1) | "you mean this stone here" 0.721 (replaced in the fixture); "this stone is heavy" 0.567 |
| MQ104 D1 `what do these greybeards want with me` [greybeards, want]; COMMIT (goodbye) | verbatim (2); "what do they want with me" (5: shares `want`, precision 1.0); "yes, what do they want" (1) | "the Greybeards want me dead?" (precision 0.67); "tell me what the Greybeards want" (statement) -> park -> yes; "yes, kill the Greybeards" (foreign `kill`) |
| MQ104 C1 `the greybeards` [greybeards]; unscripted, walk-away | "the Greybeards?" 1.0; "who are the Greybeards" 0.850 (containment); "I hate the Greybeards" 0.850; "go on" | "what do you think of them" 0.123 (the re-arm example); "..." |
| MQ102 Reward `what else can i help you with` [else, help]; COMMIT (goodbye) | "what else can I help with" 1.0; "yes, I'll help" (1); "yes"; "okay" | "is there anything else I can do" 0.721 precision 0.5 -> park -> yes; "can you help me find a room" 0.721 |
| MQ105 HornA2 `thank you what's next` [thank, next]; plain | verbatim 1.0; "what comes next" 0.675 (5); "yes, what now" / "ok, what now" (1); "ok" / "okay" | "thank you for the horn" 0.675 (statement) |
| MS05 App2 `and that's where i come in` [come]; plain | verbatim; "so that's where I come in" 1.0 by F1 (2); "yes, I'll do it" (1); "can I come in" (5, admitted) | "yes, but not now" (refusal) |
| MS05 Final `is that it` []; COMMIT (goodbye) | "is that it" 1.0; "yes, that's it" (1); "yes" | "is that all of it" 0.593 -> park -> yes; "is that a threat" 0.554; "yes, and the verse is done" (foreign) |
| DB01 `contract` [contract]; plain (`DB01AventusResponse4Topic` "Contract?") | "contract?" 1.0; "what contract?" 1.0 (containment); "a contract for what" (2); "yes, I'll take the contract" (1) | "no, I want no part of this" (refusal) |
| CW01A Oath4 `long live the emperor long live the empire` [long, live, emperor, empire]; COMMIT | verbatim 1.0; "long live the Emperor" 0.907; "long live the Empire" 0.907 (2); "the empire will live long" 0.838 precision 0.75 (4); "yes, long live the Emperor" (1); "I swear" / "I swear it" / "ok" / "okay" / "yes" | "long live Ulfric" / "long live the Stormcloaks" 0.721 precision 0.67; "death to the emperor" / "the emperor is a fool" 0.567; "hail the Stormcloaks, the true sons of Skyrim" 0.316; "yes, long live Ulfric"; "fine, long live the emperor and death to Ulfric"; "wait, what does that mean?" (shape); "yes, but not now"; "..." |
| CW01B Oath4 `all hail the stormcloaks the true sons and daughters of skyrim` [all, hail, stormcloaks, true, sons, daughters, skyrim]; COMMIT by override | "all hail the Stormcloaks" 0.850; "hail the Stormcloaks, the true sons of Skyrim" 0.892 precision 1.0 (2); "I swear it" | "all hail the Empire" 0.610; "the Stormcloaks are traitors to Skyrim" 0.610; "yes, all hail the Empire" (foreign `empire`); "long live the Emperor" 0.251 |
| CW01A Oath1 (unscripted, 9 words) | the line 0.870; "I swear" / "I swear it"; "go on" | "what does that mean?" (shape); "..." |
| MS05 Inspection `what does that mean` []; unscripted | verbatim 1.0; "what do you mean by that" 0.675 (3); "go on" | "..." |
| TG00 04 `what do you have in mind` [mind]; unscripted | verbatim | "what do you mean" 0.560; "never mind" (refusal); "uh what now" 0.274 |
| TG00 06 `what do i have to do` []; unscripted | verbatim | "what must I do" 0.471; "no, I won't do that" (refusal) |

**S4.6 Auto-advance (player P4 / truth P3, graft). [rev1: game R11, S4; ai R4] [rev2: game R5, S1; ai R4, S6]** On
`want=1`, a closed layer with ONE visible entry that is indexed, `scripted=0`, `kind=''`, `cost=0`, `crit != 2`,
`goodbye=0` (walk-away flag allowed: the oath lines) emits `do=pick;...;adv=<ms>` (additive key after `z=1`;
`adv=2500` from `auto_advance.grace_ms`, `adv=0` for a continuer text in `auto_advance.continuer_words`: "...", "Go
on.", "And?", "Continue.", "Then what?"). Game: `CmdSelectTopic` stamps `reqAdv` (and `reqRearm` from `rearm=1`,
below); `StepClicking` with `reqAdv > 0` and `bAutoAdvance` on:
- **The engine-line anchor (the first `adv` pick): CONTINUOUS blank subtitle AFTER a line was seen.** `StepClicking`'s
  settle gate ("subtitle / progress timer unchanged for 0.4 s", `LRG_Dialogue.psc:2585-2604`) is satisfied 0.4 s INTO
  a long line; `isActorTalking` sees only CHIM's TTS; `ProgressTimerId()` (`LRG_DlgUI.psc:593-600`) changes when a line
  STARTS (`setInterval` per `StartProgressTimer`) and never signals an end; and "cleared" alone starts the count on
  the empty read BEFORE Tullius's line begins (the voice file loads after the click) - revision 1's anchor would have
  cut him off mid-line, the exact report R11 was about. So: the pick sets `sawLine` on the first non-blank
  `Subtitle()` after the layer appeared; the grace counts ONLY while `sawLine` and the subtitle is blank (`""` or
  `" "`); ANY non-blank read restarts it (multi-response lines and the gap between voice files never fire it);
  `ProgressTimerId` is not used. With `SubtitlesOn()` false the auto-advance is NOT attempted and the pick waits for
  his words (S4.5 on his next turn) - 5.5 says the short breath needs dialogue subtitles on (they are on his install,
  `subs=1` **[L]**). Cost: the `Subtitle()` read the CLICKING poll already makes plus one bool. The click logs `adv
  anchor: line seen at=<t> blank since=<t>` so the first evening's log proves it (offline the harness sees only
  `adv=` on the pick).
- **The CHIM anchor (a `rearm=1` pick): the END of her speech.** A re-armed pick (below) arrives in the LLM reply that
  answers his question: by then the vanilla subtitle cleared long ago, so the engine anchor would click 2.5 s INTO
  her TTS answer (5-10 s long) and Balgruuf's engine line would play over CHIM's - on the owner page's own example.
  The game has the signal for HER lines: CHIM raises `CHIM_SpeechStarted` per sentence for the NPC
  (`NoteSpeechToDriver(1, now)`, `LRG_Main.psc:3442-3471`) and `isActorTalking` reads her TTS; `LRG_Main:4223-4227`
  already waits "isActorTalking == 0 and no new SpeechStarted for the gap". So a `rearm=1` pick counts its 2.5 s from
  `isActorTalking == 0` held for 1.0 s with no `NoteSpeech(1)` in that second (that logic reused), never from the
  subtitle; log `adv wait: her line` then `clicked ... auto=1`. Cost: +1 native (`isActorTalking`) per 0.1 s poll
  ONLY while a `rearm=1` pick waits. On either anchor a `NoteSpeech(1)` younger than 1.0 s restarts the count (0
  natives; the event is already delivered).
- **The cancel has a real signal.** `CHIM_SpeechStarted` for the player is raised by CHIM's Papyrus only when CHIM
  VOICES a line (after STT, and only with player TTS on; `research/chim-papyrus-plumbing.md:36,205`,
  `p2-chim-interplay.md:305,310`) - the push-to-talk press never reaches Papyrus. So `LRG_Main` registers
  `iKeyPushToTalk:Dialogue` (settings.ini + MCM Keys page, default **29** = Left Ctrl, CHIM's mapped key on this
  install per AIAgent.log "Using mapped key code: 29"; 0 = off) like the leave hotkey, and its `OnKeyDown` calls
  `NoteSpeech(0, now)` - one native, no polling. `StepClicking` abandons the pick (back to DECIDING, `adv cancelled
  by speech`) when `speechAt > reqAt`. With the key at 0 the breath is never interrupted and the owner page says so.
- With `bAutoAdvance` off the pick waits for the player's words instead. `ev=result` gains `auto=1` when the click
  was an auto-advance. Scripted single entries never auto.
- **Re-arm after a question turn. [rev2: ai R4, S6; game S1]** "What do you think of them?" during the breath on "The
  Greybeards?" cancels the auto (it is the fixture's own `never` line: 0.123, no shared word **[M]**; a question ABOUT
  the entry - "who are the Greybeards?" - clicks it instead, S4.5, and Balgruuf's real answer IS the answer); the
  model answers; on the next turn on the SAME single unscripted layer, when NO pick was emitted this turn (none found,
  or a T-key the gate refused by shape - either must re-arm or the oath stalls after one question) and the utterance
  was not a refusal, the server emits the `adv=` pick again with `rearm=1` (additive, after `adv=`) so the game takes
  the CHIM anchor (`auto_advance.rearm`, true). One rule server-side; the game reads one flag.

**S4.7 Two candidates must agree (truth P8).** On `want=1`, when `ask=` and the utterance both clear 0.55/0.15 but on
DIFFERENT entries, nothing is clicked; log `want=1 ambiguous model=<i> player=<j>`. One candidate clearing -> that one.

**S4.8 A scripted entry needs a shared word (truth P13). [rev2: ai R1]** `lrgDlgMatchText` returns `f1` alongside
`score`/`margin`, reported as 1.0 on an exact or containment hit (otherwise `f1 > 0` would block `is that it` said
verbatim: its entry has no meaning words, so F1 is structurally 0); on the similarity path (gate intent mode and
`want=1`) an indexed `scripted=1` entry executes only when `f1 > 0`. T-keys are unaffected by THIS test: on a T-key
pick of a scripted single that is not a commit the gate applies only S4.5's step 0 (shape); on a commit single the
T-key pick passes S4.5's content test or parks (S4.5 step 6). The emit log gains `overlap=<f1>`. (`lrgDlgWillEmit`
already returns false in intent mode; no change there.)

**S4.9 Unchanged rails, in order (gate and fast path alike). [rev1: game R3]** hidden -> read-only session (`drv=0`,
or `sj=1` before `clicks_ok >= 1`: nothing emitted, the foreseen clause spoke) -> resist-arrest (`do=show`, any
setting) -> follower `never_topics` -> arrest-class session (`do=show`) -> freeze rule B1 -> `crit 2` (`do=show`
lethal) -> meta -> stage rail -> pay & !afford -> check rails (>= 4 words on voice, bribe affordability from the live
price, named amount below the price, retry suppression) -> scoff-first -> two-step. Intent mode may execute
`plain`/`service` (+`pay` on a same-session continuation), a service entry by KIND (S5), and, new, a commit through
S4.3/S4.5 only.

**S4.10 Grade table (what the player experiences). [rev1: game R2, R7, R10, R11; ai R1, R4]**

| entry | first statement | what she says |
|---|---|---|
| plain / back / service slot / pay < 100 septims and < 25 % purse | clicks now | her real line |
| a service by kind ("show me your wares", "I need a bed") on a root list with one entry of that kind | clicks now | her real line |
| check (persuade / intimidate / bribe) | clicks now under the check rails; scoff-first parks a doomed attempt once | the engine's verdict, told next turn as fact |
| unscripted single continuation | advances by itself 2.5 s after her line was seen and has gone (dialogue subtitles on), or 2.5 s after her CHIM answer ends when he asked her something in the breath, unless he presses his talk key; or on his words [rev2: game R5; ai R4] | her next line |
| scripted single entry (the assignment, the last oath line) | clicks on his first answer that begins with an assent ("yes", "I swear", "okay" - nothing against it, and on an oath nothing foreign after it) or says the line (the same shape, the entry's words, no foreign one); a statement against her question, a question to HER, or a line with a foreign word in it waits - a plain single for the model, an oath for her question [rev2: game R6; ai R1] | her next line |
| commit, player explicit (>= 0.70/0.25 and as many tokens as the line has, up to 4; a slot at >= 2 tokens; or the exact line) | clicks now | her real line |
| commit, not explicit | parks; her question quoting the line; "yes" or a fuller answer clicks | her question, then the line |
| a walk-out line (twat target), cost >= 100 septims, follower dismiss/home, meta | always asks | her question |
| lethal / arrest-class / resist arrest | never by voice; the menu is his | "choose that yourself" |
| a menu inside a scene whose quest has an unfinished journal objective, before this install's first real click [rev2: game R1] | never yet; the list is his | "choose it on the list yourself - I cannot pick for you here" (same turn) |
| stage rail (no verified click on this install yet) and the entry is not an indexed plain line | never yet | "I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one" |

### S5. Matcher and model-item policy

Unchanged from perf section 5 (index first; T-keys of THIS turn's offer only; words through follower verbs -> faction
exact line -> price-list slots -> `lrgDlgMatchText` 0.55/0.15; ambiguity is always her question; no synonym table, no
second judge call), plus S4.3, S4.7, S4.8 and:
- **Service kind -> the root entry (game S1) [rev1: game].** "show me your wares", "let me see your goods", "I need a
  bed" share no meaning word with `What have you got for sale?` / `I'd like to rent a room` and fail 0.55, so the
  menu would open and nothing be picked until a second sentence and an LLM turn. `lrgDlgServiceKind($utter)` is
  already computed - over the phrase lists S2.1 clause 2 extends, so "what have you got", "I'd like a room", "I need
  a bed" and "can I get a room" now return their kind **[M]** [rev2: game R2; ai R3] - and the hide policy already
  maps kind -> the real entry: on `want=1` and in the gate's words path,
  on a ROOT list, kind in {inn, barter, carriage, ferry, train} and exactly ONE `class=service` entry of that kind on
  the list -> `do=pick` mode `kind`; two -> nothing (the model asks); never crime / follower. Under the stage rail
  the kind pick obeys the rail (`OfferServicesTopic` is `scripted=1`, so it waits for `clicks_ok >= 1`). One compare
  per entry.
- **The two-candidate hint (ai S7) [rev1: ai].** Computed once per turn from the utterance over the OFFER only (never
  the tail), at most one hint, skipped on a price list (the slot block already asks): when two entries lie within
  0.10 of each other for the player's words, `<business>` adds `T<a> or T<b> fit what he said - ask which, naming
  both.` (<= 120 chars, player P8).

### S6. Negotiation policy (rewards, septims)

**S6.1 Fixed by the engine, said out loud (gate A, perf M7 + the player's action list). [rev1: game R9, S11; ai S2,
S3]** On a QUEST turn with this NPC (a journal quest of hers in `q`, or a `last_result` younger than 180 s):
- **The `reward` locked line rides ONLY when he bargains**: the utterance carries a `checks.stakes.reward` word
  (more, extra, bonus, on top, sweeten, deserve more, worth more, in septims, in gold, in coin, pay me - Lane B adds
  the list in gate A) OR the reward window is open (S6.2 (1)'s definition, computed read-only in gate A by
  `lrgDlgRewardWindow($npc)` in `lrg_speech.php`). Text (~190 chars, inside the 600 cap, after the faction line):
  `the reward for this is what the world gives and nothing else: you cannot add septims, an item or a favour, and
  you cannot change it; if he bargains, say so and offer nothing else; if a line about the reward is on his list,
  you may tell him to ask about it`. "Say plainly what you can and cannot do" is gone (it invited an invented
  favour); the last clause steers him to the real line ("What about my reward?" is a click away) instead of ending
  the topic. Without a bargaining word the line is ABSENT: `<locked_facts>` is what models surface unprompted, and
  Farengar answering the barrow question with "and I cannot add septims to your reward" is a certain bug report.
- **The hides are unconditional on quest turns**: `hide_reward` = `GiveGoldTo, SpawnGold, SpawnItem, GiveItemTo,
  TakeGoldFromPlayer` are taken off the table, and Lane B registers them in `LRG_DLG_SVC_HIDDEN` so the EXISTING
  "hidden this turn -> dropped" rail in `lrgDlgPostProcessActions` (`lrgDlgHiddenThisTurn`, :2416-2440) drops a
  stray `GiveGoldTo`. `lrgDlgTruthCheck`'s new clause (a surviving `GiveGoldTo` / `TakeGoldFromPlayer` with an
  amount no live entry or confirmed fact carries is dropped) stays as the backstop and logs both strings; her words
  still play.
- Where the game itself offers a reward negotiation it is a real entry and S4 handles it (MQ104 "I think I deserve a
  reward", "What about my reward?", Eorlund's weapon choice, the persuade variants).

**S6.2 The bounded bonus (gate B, perf S2 with the truth cap and the player's plumbing). [rev1: ai R11]** All
required: (1) a reward window is open - within 180 s after a driven click on a row whose topic matches `*Reward*` or
whose `resp` contains reward / take this / token of / for your trouble, or after `<what_just_happened>` reported "the
matter moved on" for a quest she is an alias of (`qal`/`qgiver`), or after `ev=result` reported gold into the purse
(`lrgDlgRewardWindow`, shared with S6.1); (2) the player ASKED in his own words: INSIDE an open window, and only
there, the reward-ask phrases (more, extra, bonus, on top, sweeten, deserve more, worth more, in septims, in gold, in
coin, pay me) make `lrgDlgCheckKind` (`lrg_speech.php:306`) return `persuade` with stakes `reward` - one
window-gated branch, because `checks.stakes.*` only sets the difficulty BAND (`lrgDlgStakes`, :375) and the KIND
comes from `lrgDlgCheckKind`'s regex ladder, which "I deserve more than this, a hundred septims" / "can you sweeten
it" hit nowhere today, so without this branch no check could ever run and test 27 could not pass; outside the window
the same sentences return `''` (a merchant haggle is never turned into a check); >= 4 words on voice; amount via
`lrgDlgNamedAmount`; (3) a REAL check through `lrgDlgCheck` (band = stakes 2 + stance + bias, threshold live from
`sg=`, amulet passes, immune profiles fail, memory 86,400 s per NPC); (4) `N = min(named amount or round5(cap/2), her
purse from the snapshot gold=, one day of her wage tier via lrgDlgWage, checks.reward.max_gold 500)`, once per quest per
NPC (memory `reward|<quest>`); pocket 0 -> no check runs, she says she carries no coin, and never promises later
payment; (5) the transfer is `do=award;...;z=1;give=N` (additive): `CmdAward` moves `min(N, npc.GetItemCount(gold))`
NPC -> player with `npc.RemoveItem(goldForm, n, true, player)`, grants persuade XP, answers `OK: gave <n> septims`
(the funcret carries the ACTUAL number; a short-fall is told next turn); (6) `<reward_talk>` (<= 350 chars, window
only) states the outcome pre-LLM as fact (pass N / fail / no coin / a different reward is fixed - say so, promise
nothing); `<what_just_happened>` adds `you gave him N septims on top`. An item, a house, a title, a favour: refused in
her words, always. Ships in gate B because nothing in it can be tested before `ev=result` and `do=award` have run once
in game.

### S7. Voiced reasons (every failure is words; rule -> `lrgVoicedWhy` / `SayReason`, never an authored line) [rev1: game R3, R10, S9; ai R10]

| failure | where | what she says (rule) | log |
|---|---|---|---|
| a pick on a session the driver does not drive (journal scene before the route proof, lethal, module off) | server, pre-LLM (`lrgDlgHandBackForeseen`) | "choose it on the list yourself - I cannot pick for you here" (same turn, no command sent) | `gate: read-only session why=` |
| a pick that still reaches an undriven live session (skew) | `CmdSelectTopic` | "choose that one on the list yourself" | `Error: choose that one on the list yourself` |
| no list could be read | `ReadList` | "I did not catch what we could talk about - choose it on the menu yourself" | `read failed n= mode= try=` |
| the entry moved before the click (stale x2) | `SelectAndVerify` | "I lost the thread - say that again" | `stale: pos=` |
| the click did not take (`ClickResult` <= 0 x2) | `StepClicking` | "that did not take - choose it on the menu" | `click aborted result=` |
| nothing happened after the click (no signal) | `StepResponding` | "nothing came of that" | `result ok=0 why=unverified` |
| not enough gold | server `afford` / game `GetGoldAmount` | "you cannot pay that" / "not enough gold" | `gate: ... afford` |
| her voice not ready | `VkRestore` | "give me a moment" | `her voice is not ready` |
| two entries fit / two slots / two join lines | server | her question, naming both | `mode=ask` |
| a commit, not explicit | park | her naming question | `PARKED` |
| a commit refused after parking | `lrgDlgParkOrRelease` | "as you like" | `the parked selection is dropped` |
| leave on a walk-away layer with no back-out entry | leave guard (the prompt line is the carrier; `do=show` answers OK) | "no line here backs out cleanly - leaving is yours to do, and it ends things with me" | `leave-guard` |
| lethal / arrest / resist arrest | server + `Arm` | "choose that yourself" + corner note | `stopped driving why=lethal` |
| stage rail | server label pre-LLM | "I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one" | `gate: stage rail` |
| calibration red | game | "still learning the dialogue menu - the next conversation of any kind measures it" | `WOULD CLICK` |
| menuless dry run on | game | "the menuless dry run is on (Menuless questing page)" | `WOULD CLICK` |
| developer dry run on | game | names the switch and the page (10.21) | `DRY RUN` |
| open refused (combat, real quest scene, OStim, asleep, sneaking, mounted, too far) | `OpenBlockedReason` | the closed-list sentence | `open refused: <reason>` |
| the session died mid-turn | `CmdSelectTopic` not live | the pick re-opens (one greeting replays); if refused: "we were interrupted - ask me again" | `closed why=external` then `open` |
| a server-side gate refusal the voice gap skipped (afford / amount / words / retry / frozen) | next turn | `<what_just_happened>`: "Nothing came of it: <plain sentence>" - `lrgDlgNoteRefusal($npc, $code)` stores the PLAIN sentence per code, never the log string (afford "he could not pay what that costs"; amount "he offered less than it costs"; words "he did not say it properly, in a whole sentence"; retry "she has refused that already and nothing has changed"; frozen "things had just changed and she had to look again"), and `lrgDlgGroundTruth` (:2160) reads that | `gate: <why>` (the log keeps the machinery string) |
| she stopped driving (fight / scene began) | next turn | one line in `<what_just_happened>`: "the menu was left to him because a fight/scene began" (told once, 180 s) | `stopped driving why=` |
| a reward short-fall (gate B) | `CmdAward give=` | "she found she had only N" | `award gave=` |
| a false reward / enlistment claim | never-false / truth gate | the sentence is muted or the action dropped | `never-false` / `truth gate` |

### S8. Wire keys (all additive; both sides ignore unknown keys) [rev1: game R1, R3, S6]

| direction | message | key | new / changed | who reads it |
|---|---|---|---|---|
| game -> server | `lrg_dlg ev=open` | `sj=1` after `z=1` (the scene's owning quest has an unfinished journal objective) | NEW; `scene=` is UNCHANGED (any scene, as today) [rev2: game R1, S7] | `lrgDlgOnEvent` -> `sess.sj` (OR `scene=1 && lrgDlgQuestKnown(sq)`; missing `sj` -> `scene && !ambient`); the scene rail, `lrgDlgHandBackForeseen` |
| game -> server | `lrg_dlg ev=open` | `sq=<owning quest id> sqj=0|1` after `sj=` | NEW [rev2: game R1, S6] | `lrgDlgOnEvent` (the `sj` basis; `lrgDlgQuestKnown(sq)`; the arming log line) |
| game -> server | `lrg_dlg ev=open` | `drv=1|0` after `sj=` | NEW | `lrgDlgOnEvent` -> `sess.drv`; the read-only clause; missing = 1 (old game) |
| game -> server | `lrg_dlg ev=calib` | `reset=1` (sent by `CalForget`) | NEW | `lrgDlgOnCalib` clears `clicks_ok` |
| server -> game | `ExtCmdLRG_SelectTopic do=pick` | `adv=<ms>` after `z=1` | NEW | `CmdSelectTopic` -> `reqAdv`; `StepClicking` (the engine-line anchor: blank subtitle after `sawLine`) |
| server -> game | `ExtCmdLRG_SelectTopic do=pick` | `rearm=1` after `adv=` | NEW [rev2: ai R4] | `CmdSelectTopic` -> `reqRearm`; `StepClicking` (the CHIM-speech-end anchor) |
| server -> game | `ExtCmdLRG_SelectTopic do=open` | `amb=1` after `z=1` | NEW | nobody (log) |
| server -> game | `ExtCmdLRG_SelectTopic do=award` | `give=<n>` after `z=1` | NEW (gate B) | `CmdAward` |
| server -> game | `ExtCmdLRG_SelectTopic` | `res=` | sent EMPTY (slot kept for the fixed order) | nobody |
| game -> server | `lrg_dlg ev=result` | `auto=1` | NEW | `lrgDlgOnResult` (log line prints `clicks_ok=`; `clicks_ok`) |
| game -> server | `lrg_dlg ev=open` | `hid=0` always | changed value | `lrgDlgOnEvent` |
| game -> server | `lrg_dlg ev=calib k=` | rows `cm rm fam st route timer x1 apd ms3 tail` | shrunk; an old ten-row `k=` still parses | `lrgDlgOnCalib` |
| game -> server | `lrg_dlg ev=unhide`, `ev=resume` | no longer sent | handlers kept | - |
| game -> server | `lrg_dlgtalk` | no longer requested | handler kept, answers `handled` | - |
| game -> server | funcret of `do=award` | `gave=<n>` in the OK text (gate B) | NEW | funcret handler -> `last_result` |
| game -> server | `lrg_topics` | unchanged | - | - |
| server state | `*install*` row | `clicks_ok` | NEW | the stage rail |

### S9. Server config (`config/lrg_config.default.json`, `dialogue.*`) - defaults that just work [rev1: game R4, R9, S1, S4; ai R2, R5, R8c]

| key | default | meaning |
|---|---|---|
| `confirm.said_line_score` / `said_line_margin` / `said_line_tokens` | 0.70 / 0.25 / 4 (floor = min(4, entry tokens)) | S4.3 |
| `confirm.slot_tokens` | 2 | S4.3 (c) |
| `confirm.assent_words` (ONE list, S4.4 + S4.5; a LEADING phrase, compared over `lrgPromptNorm` tokens) [rev2: game R6; ai R5] | yes, aye, yeah, yep, deal, agreed, sure, sure thing, do it, i do, i will, i swear, i swear it, so be it, i'm ready, i am ready, fine, alright, all right, ok, okay, very well, of course, let's do it, i accept, i'm in, count me in | S4.4, S4.5 |
| `confirm.single_entry_extra` | go on, carry on (`ok` moved to the shared list) [rev2: ai R5] | S4.5, unscripted singles only |
| `confirm.named_score`, `confirm.park_seconds` | 0.45, 60 | S4.4 "named" |
| `confirm.single_entry_score`, `confirm.single_entry_exact`, `confirm.single_entry_precision` | 0.65, 0.85, 0.75 [rev2: ai R1] | S4.5 |
| `confirm.utter_window` | 30 (seconds) [rev2: game R4] | S4.3 |
| the STRICT stop list (in code beside the shipped one: `lrgPromptWords($norm, strict: true)`; not config) | the shipped list + why how who where when which can could would should does mean now then wait uh they them these those there here we us he she him his her its our their [rev2: ai R2] | S4.5, S4.4 "named", S2.1 clauses 4-5 |
| `config/lrg_dialogue_overrides.default.json` (NEW file, Lane B) | `entries`: `MQ102ALegionOath4` and `MQ102BStormcloakOath4` with `commit: true` (one line in `lrgDlgIsCommit` reads it) [rev2: ai R1] | S4.1, S4.5 |
| `confirm.bare_yes`, `confirm.single_entry` | true, true | S4.4, S4.5 |
| `auto_advance.enabled`, `auto_advance.grace_ms`, `auto_advance.continuer_words`, `auto_advance.rearm` | true, 2500, ["...", "go on", "and?", "continue", "then what"], true | S4.6 (replaces the unread `grace_seconds`) |
| `match.scripted_needs_word` | true | S4.8 |
| `match.service_kind_pick` | true | S5 (game S1) |
| `open.narrow_marker` | true | S2.1 (the pre-LLM marker; false = today's wide marker, for a diagnosis only) |
| `open.toplevel_marker`, `open.qrows_marker`, `open.qrows_score`, `open.qrows_cap` | true, true, 0.55, 300 [rev2: ai R3] | S2.1 clauses 4 and 5 |
| `services.kinds.barter.phrases` / `.not_after`, `services.kinds.inn.phrases` | + the exact strings of S2.1 clause 2 [rev2: game R2; ai R3] | S2.1, S5 |
| `scenes.ambient` | the same globs, now an allow-list SHORTCUT (a scene with `sqj=0` whose quest the index does not know is ambient without one) [rev2: game R1] | S1.3, S2.2 |
| `session.talk_again` | false | S2.1 |
| `session.stage_rail` | true | S3.3 |
| `session.drive_scene` | **true** | S1.3 (the scene rail's kill switch; the proof is `clicks_ok >= 1`) |
| `truth.classes` | + `reward` | S6.1 |
| `hide_reward` | GiveGoldTo, SpawnGold, SpawnItem, GiveItemTo, TakeGoldFromPlayer (registered in `LRG_DLG_SVC_HIDDEN`) | S6.1 |
| `checks.stakes.reward` (the word list) | gate A (read for the locked line); the check itself gate B | S6.1, S6.2 |
| `checks.reward.{enabled false, window_seconds 180, max_gold 500}` | gate B | S6.2 |
| `reorder_json_scope` | `business` | S11 |
| removed | `confirm.typed_skips`, `assist.*`, `calib.auto.*`, `quests.initiative.*`, `match.cont_depth`, `session.silence_seconds`, `session.lost_seconds`, `script_proxy_watch`, `quest_colour` | dead |

### S10. MCM controls (exact labels) and `settings.ini`

**Menuless questing page - kept:** `Quest talk without the menu` (bMenuless, ON); `Menuless questing: dry run (nothing
is clicked)` (bDlgDryRun, **OFF**); `Send quest talk to the server` (bDlgWire); `Guards, arrests and bounties`
(iCritical); `NPCs inside a quest scene` (iSceneGate, **1**, help rewritten [rev2: game R1]: "she may be spoken to at a
map table, during a performance or in tavern patter; a scene whose quest has an unfinished objective in your journal
is never interrupted"); `May open a conversation to find out` (bIntentOpen);
`How long it waits for the server` (fDecideTimeout); `Quiet time before the first click` (fLineSettle); `How close she
has to be` (fOpenDistance); the `Reading the list` group (iReadMode, iCountMode, iMaxEntries, iTailMax **16**); `Which
click the menu takes` (iClickRoute); `Leave the conversation` hotkey; `Allow a silent conversation` (bAllowNullVoice);
`Plain activation` (bActivateDefaultOnly); the `Persuade, threaten, bribe and lie in ordinary talk` and `The quest
tree` groups unchanged.
**Menuless questing page - NEW:** `Let her carry on by herself when there is only one thing to say` (bAutoAdvance:
Dialogue, ON).
**Menuless questing page - removed:** `Conversations the game starts` (iEngineOpen), `Choices inside a conversation`
(iBranchInput), `How long she waits for your answer` (fSilenceTimeout), the `Hiding the menu` header, `What is hidden`
(iHideMode), `Hide the cursor as well` (bHideCursor), `Walk back to a lost choice` (bRewalk), `How many steps back`
(iRewalkDepth), `Say when I have to choose by hand` (bHandBackNote), `Carry on without the menu afterwards`
(bResumeAfterChoice), `Run the probe press` hotkey, the `First-playtest probe` header, `Arm the probe` (bProbe), `Which
probe press` (iProbePress), `Touch the dialogue camera mod` (bIaccToggle), `Use the quest colour hint` (bQuestColour).
**Calibration page:** only `Where it stands` / `Calibration status` (the live text, now "4 of 4 learned, route proven
by 1 click" or "learning: N of 4 - talk to anyone once") and `Forget everything it learned`. Removed: every other
control (`Learn from ordinary conversations`, `Calibrate on the next few conversations`, `How many conversations to
use`, `Measure the menu by itself at an innkeeper or a shopkeeper`, `How many automatic measurements to allow`, `Let it
adjust the waiting times`, `Let me leave dry run anyway`, `Keep the menuless dry run even after learning`, buttons 1,
1b, 2, the two optional tests, the help text about slider presses).
**Keys page [rev1: ai R4]:** `Open vanilla dialogue (emergency)` removed; `Dump topics (test tool)` kept; NEW `Your
talk key (CHIM's push-to-talk)` (`iKeyPushToTalk:Dialogue`, default 29 = Left Ctrl, 0 = off; help: "only tells her
you are about to speak, so she waits instead of carrying on by herself").
**`settings.ini` defaults changed/added [rev1: game R2; ai R4]:** `bDlgDryRun = 0`, `iSceneGate = 1`, `iTailMax = 16`,
`bAutoAdvance = 1`, `bDriveSceneMenus = 1` (no MCM control; effective only once `route src=live`), `iKeyPushToTalk =
29`; removed lines: `bDlgDryRunHold iHideMode bHideCursor iEngineOpen iBranchInput bRewalk iRewalkDepth
fSilenceTimeout bHandBackNote bResumeAfterChoice bProbe iProbePress bIaccToggle bQuestColour iKeyVanillaMenu` and
the whole `[Calib]` section. `tools/test_mcm_wiring.php` is the gate: every remaining id has an ini line and a
reader; every removed id has neither; `iKeyPushToTalk:Dialogue` is listed with its control.

### S11. Prompt changes (perf S3 + the grafts) [rev1: ai R5, R6, R8, S3, S4, S5; game R3, R10, S9]

`<business>` rules cut to four standing lines (one key only when his words clearly do it; two fit -> ask; never an
empty message; a [commits] key asks first unless he already said it plainly - **"ask plainly whether they mean it,
quoting the choice in its own words"**, the T-key text is in front of the model and quoting costs nothing) plus the
conditional lines that are never-false rails: the `sent < n` line "you may NOT say that a thing is not on the table"
stays (conditional, as today, `lrgDlgBusinessBlock` :2060-2064); when `<real_business>` is suppressed and any
offered entry has `kind != ''`, `<business>` carries "Never say whether a persuasion, a threat or a bribe worked; you
are told afterwards." (the only place the model is told so); the `open_pending` bridging directive (S2.1, <= 220
chars, that turn only); the read-only clause (S1.3). `(+N more)` without the word bucket; `<real_business>`
suppressed when `<business>` is present; the visible-menu state wording conditioned on `session.state` open (S1.3);
the single-entry wording (S4.5); the leave-guard line (S4.2, new wording); the two-candidate hint (S5, offer only,
once); the stage-rail label (S3.3, no machinery word, "in your own words" per 10.21); the `reward` locked line (S6.1,
only when he bargains or a reward window is open); `<what_just_happened>` gains the stopped-driving line and the
"Nothing came of it: <plain sentence>" line (S7); `dialogue.reorder_json_scope = business`. Two truths kept exact
(ai S7) **[rev2: ai]**: on an `open_pending` turn the bridging directive REPLACES the "the list is on screen" state
wording (there is no list yet); on a read-only session the plain list carries no `(+N more)` bucket wording (it
presumes keys). The stage-rail sentence rides ONCE per prompt as one `<business>` line naming the pickable keys
(S3.3; ai S1), never as eight per-entry labels. Budget: a 12-key business turn <= 2,500 chars WITH the two kept rails
and the bridging directive counted (`test_latency_prompt`); the locked block <= 600.

### S12. Performance budget (targets the tests assert where they can)

| item | today | v1.0 |
|---|---|---|
| Papyrus per poll while a menu is open | ~5.7 natives / 0.1 s | <= 3 per poll; 0.25 s outside CLICKING/RESPONDING |
| Papyrus per changed layer | up to 72 UI reads | <= 48 (head 16 x 2 + tail 16) |
| Papyrus per click | SelectAndVerify + Click + ClickResult + CapturePre | unchanged; -3 (no Guard/Hide/HideCursor) |
| `kind`/`adv` on the click path | - | 1 string + 1 float compare per poll; the `adv` grace reads the `Subtitle()` the poll already reads |
| the push-to-talk key | - | one `OnKeyDown` per press, 0 polling [rev1: ai R4] |
| scene test at arming | `GetCurrentScene()` (1 native) + the PO3 alias sweep in `OpenBlockedReason` | `GetCurrentScene()` + `GetOwningQuest()` + the objective loop (`SendFacts`' own code), or 0 when the snapshot is < 30 s old; -1 PO3 sweep [rev1: game R1] [rev2: game R1] |
| snapshot (~20 s) | ~25 natives + 13 MCM reads | unchanged in gate A (S4 diet is gate B) |
| server fast path (`lrg_topics` / `lrg_dlg`) | 2-7 ms | <= 10 ms (+ one compare per entry for the kind pick, one `links` loop per layer for the hub rule) |
| server pre-LLM (`lrgDlgPrepareTurn`) | 58 ms incl. Phase 1 | <= 100 ms, our share <= 30 ms; the pre-LLM open <= 5 ms warm with the q-row cache primed and < 30 ms cold (one Postgres round trip per session for clause 5), both MEASURED in `test_latency.php` section 6 [rev1: ai S6] [rev2: ai R3, S10] |
| the transformer's fresh `last_exec` read | 0 | <= 1 store read per streamed sentence [rev1: ai R6] |
| LLM calls per player turn | 1 (+1 "again" on unclicked first contact, +1 re-ask ~5 %) | exactly 1, + the re-ask only; 0 on the fast path |
| dialogue prompt on a business turn | ~3,200 chars | <= 2,500; locked <= 600 |
| empty replies | 10 % action-first | <= 3 % (`reorder_json_scope = business`) |
| first-contact click | 11-14 s | 3-8 s after speech end (D2 5 s poll), never a second paid turn |
| LLM-turn pick on a known list | 6.5 s model + up to 4 s decide park + click | 6.5 s model + <= 1 s click |
| auto-advance | never | 2.5 s of blank subtitle after her line was seen (an engine line), or CHIM speech end + 1.0 s quiet + 2.5 s (a `rearm=1` pick); never mid-line; +1 native per 0.1 s poll only while a `rearm=1` pick waits [rev2: game R5; ai R4] |
| explicit commit | 3-4 utterances | 1 utterance |
| a pick on a read-only session | 1 paid funcret turn, a false sentence | 0 turns; the clause rides the same turn [rev1: game R3] |
| calibration before the first click | 10 rows, 2 click-only, 1 button | 4 passive rows from any menu; route from the first click |

---

## 2. THE IMPLEMENTATION PLAN

### 2.1 Ground rules for every lane

- Read before coding: this spec (the sections named in your lane), `glue/PROTOCOL.md` 10.1-10.6, 10.13, 10.15, 10.21,
  10.24-10.26, `research/pt19-design-survey.md` sections 1-3, and the research notes your lane names. Nothing else.
- Offline suites run on the WSL copy: stage `glue/` to `C:\Users\Jordan\AppData\Local\Temp\lrg_test\<round>\glue`
  (`build18_run.sh` is the template: `php -l`, json checks, then `test_gates`, `test_intent`, `test_phrases`,
  `test_dialogue`, `test_prompt_index --file=/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson`,
  `test_mcm_wiring`, `test_services`, `test_scene_index`, `test_latency_prompt`, `test_latency` (section 6, ms),
  `test_questline` (with `--first-evening` and `--words`), `flows/run_flows.php`). Papyrus:
  `tools/compile.ps1` (no .pex string > 500 chars, no None cast to a typed array).
- Additive wire only; every removal is a version-skew no-op (stop sending / ignore unknown; a deleted command or
  event keeps a quiet handler).
- Nobody edits another lane's files. Where a lane must touch a file another lane owns, the plan says which goes
  first and the second lane rebases on the first lane's result.
- Deploy: `tools/deploy_server.ps1` (server, WSL; CHIM's launcher must be running for the index load) and
  `tools/install_mo2.ps1` (game; MO2 closed, hash-verified copy). Backups under `glue\.backup\pt19-*`.
- No LOOT, no Nemesis re-run, Ultra profile only (memory: `lorerim-workflow-rules`).

### 2.2 Lanes (six; strict file ownership)

**Lane A - server gate and two-step. [rev1: game R1-R8, R10b, S1, S2, S4, S6, S10; ai R1-R3, R5-R8, S6] [rev2: game
R1, R2, R4, R6, S1, S7; ai R1-R5, S2, S3, S5, S6, S7, S9, S10]** Owns
`server/lorerim_glue/lib/lrg_dialogue.php`, `lib/lrg_prompt_index.php` (`lrgPromptWords`'s new `strict` flag with the
strict list beside the shipped one, and ONE new read-only `lrgPromptRowsForQuests(array $quests, int $cap)` with its
`LRG_TEST_INDEX` seam - the index format, builder and hash are untouched), `tools/test_dialogue.php`, `tools/test_latency.php` (section 6), and
the EXISTING flow scenarios its behaviour changes break: `tools/flows/scenarios/d21_contact.php`, `d26_commit.php`,
`d40_quests.php`, `d50_wire05.php`, `d53_mcm.php`, `d63_out_of_the_box.php`, `d65_quest_entry.php` (adjust), and
deletes `d64_auto_calib.php`. Depends on nothing (Lane B's second pass and Lane E rebase on it).
Task: implement S1.3 (`sj`/`sq`/`sqj`/`drv` parsing; `sess.sj` = the game's OR `scene=1 && lrgDlgQuestKnown(sq)`,
the old-game fallback `scene && !ambient`; `lrgDlgSceneAmbient` with the glob as a shortcut; the read-only clause in
`lrgDlgHandBackForeseen`; the scene rail with `clicks_ok >= 1` and `session.drive_scene`; `lrgDlgDecide` +
`lrgDlgWillEmit` as its twin; the state wording conditioned on open), S2.1 (the narrow marker as
`lrgDlgBusinessMarker($t, $item, bool $narrow)` with the five clauses in order, each logged by name, the top-level
clause with its >= 2 strict-word floor, the per-NPC journal-row cache through `lrgPromptRowsForQuests()`, the
condition "no OPEN session"; the barter-net hold while `open_pending`; the dropped
`OpenInventory`/`Rent_Room`/`Hire_Carriage` shortcut; the T-key drop after a landed fast pick; the transformer's
fresh `last_exec` read; the bridging directive TEXT), S2.2 (server half: ambient open on the same five clauses; `ambient` per S1.3), S2.3 (the one changed condition is in `lrg_factions.php` - Lane B; A provides the `st['open_refused']`
read helper), S3.2 (`ev=calib reset=1` clears `clicks_ok`), S3.3 (`indexed=1`, the new label text, the `clicks_ok=`
on the result log line), S4.1 (the hub-question loop over `links`), S4.2 (new wording), S4.3 (`min(4, entry
tokens)`, slot >= 2 tokens, the NEW-utterance guard = `last_exec.at` + the 30 s window / the cid), S4.4 (the shared
assent list as a LEADING phrase, `ok` = `okay`, `named` per ai R5 with the hint flag on the park), S4.5 (refusal first
with the widened `lrgDlgIsBackOut`, the shape test, the six-step content test with `lrgPromptWords($norm, strict:
true)` - the shipped list untouched for scoring - `single_entry_score`/`_exact`/`_precision`, the commit-single tail
test, step 6's T-key rule, `f1 = 1.0` on exact/containment), the `commit: true` override read in `lrgDlgIsCommit`
(one line), S4.6 server half (`adv=`, the re-arm on "no pick emitted this turn" with `rearm=1`), S4.7-S4.9, S5 (`f1`; the kind pick; the hint scope), S9's keys and removals, the `res=` empty slot,
`ev=result auto=1` parsing, `clicks_ok` on `*install*`, removal of `lrgDlgCalibCandidate` / `lrgDlgCalibPick` / the
`calib=1` branch / `lrgDlgInitiativeCandidate` + `<she_may_raise>` / the rewalk depth (`rw`, `rwd`) / the assisted
and `x1`/`bi` rails / `lrgDlgOnResume` body (keep a logging stub); `lrgDlgLayerIsOwnConfirmation` (:2838) yes-shape
+= "i'm ready | i am ready | let's do it | i'm sure", no-shape += "actually | i'm not sure | i need to think | not
yet" (the `MQ102JoinLegionYes/No` layer is the engine's own confirmation and matches neither regex today). Keep the
`<business>` block's TEXT to what S1.3/S2.1/S4.2/S4.5 say and leave the S11 diet to Lane B (B edits
`lrgDlgBusinessBlock`, `lrgDlgStaticGuidance`, `lrgDlgJsonTemplate`, `lrgDlgLockedFacts`, `lrgDlgTruthCheck`,
`lrgDlgGroundTruth` AFTER A lands).
Tests: `test_dialogue.php` new sections - 16 "visible sessions" (no assisted/bi/x1 rail; two losses still pick;
`ev=open drv=0` -> a T-key -> NO emit, the clause "choose it on the list yourself" in `<business>`, `lrgDlgWillEmit`
false, log `gate: read-only session`; `sj=1` + `clicks_ok=0` -> the same; `sj=1` + `clicks_ok=1` -> plain pick,
commit parks, single releases, `adv=` rides; `session.drive_scene=false` -> read-only; [rev2: game R1] `ev=open scene=1 sq=DialogueWhiterunBanneredMareScene3
sqj=0` -> `sj=0`, driven; `sq=MQ102 sqj=1` -> `sj=1`, read-only at `clicks_ok=0`, the scene rail at 1; `sq=TG00 sqj=0`
-> server `sj=1` by `lrgDlgQuestKnown`; an old `ev=open` with `scene=1` and no `sq`/`sj` -> `sj` from `scene &&
!ambient`), 17 "walk-away is not a
commit" (MQ106 C2Horn hub layer -> 1 commit (C5) by the hub rule; oath layers 0; MS08 layer 3 two; MQ104 OutroB2
layer still commit by siblings; Eorlund's five commits; [rev2: ai R1] both Oath4 rows are commits -
`MQ102ALegionOath4` by goodbye, `MQ102BStormcloakOath4` by the `commit: true` override - and `MQ104BalgruufOutroD1`,
`MQ102BalgruufReward`, `MS05PoemFinalVerse` are commit singles by goodbye), 18 "leave guard" (TG00 intro layer + leave -> do=show and
the new line; innkeeper layer -> do=leave;pos=-1; "Never mind." -> that entry), 19 "explicit" (Kodlak one utterance
via the exact line; "I can take care of myself" on Kodlak's layer; "a sword, please" AND "the sword" on Eorlund's
five -> explicit via the slot path, STT "sword" -> park; "are we ready" (3 tokens, 1.0) explicit; lethal never;
100+ septims always asks; STT single "kill" never; the same utterance re-presented on the next layer with no new
speech -> nothing; [rev2: game R4] Kodlak first contact with the utterance 6 s BEFORE `session.at` and `open_pending`
set -> explicit; the same utterance 300 s old on a new engine session -> nothing), 20 "bare yes" (her paraphrased
question "You would ride with me to the watchtower, then?" + "yes" -> release; her non-question "The watchtower is a
long way." + "yes" -> parked; "no" un-parks; "i swear" on a park -> release; [rev2: game R6; ai R5, S5] her question +
"yes, I'll do it" -> release (a leading phrase); + "yes, but not now" -> un-parked; + "ok" and + "okay" -> the same
outcome; a park written on a two-candidate-hint turn + "yes" -> stays parked; the block text contains "quoting the
choice in its own words"), 21 "single entry" (every row of S4.5's verified table with its outcome AND its path - fast
/ T-key / park / nothing [rev2: ai R1, R2]; "tell me who you are" on the DB02 layer -> pick at 0.783 (scoring, the
shipped list); "who are the Greybeards" -> single at 0.850 with the strict shared word `greybeards`; "who are you"
against a probe layer `who are the greybeards` -> NOT a shared word under the strict list; "uh what now" refused by
content, not shape; 21b runs the fixture's single beats through `lrgDlgSingleEntryRelease` on the fast path AND
through the gate with the target's T-key - a `never` line with the T-key yields nothing on a commit single and only
the shape refusal on a plain one; MS11 shape executes only on the second statement; MQ103 intro pick -> the B1 layer
with the SAME utterance -> nothing, a new "what do you need done" -> single), 22 "auto-advance" (MS05 "What does that
mean?" (`MS05PoemInspection`, scripted=0 **[X]**) -> adv=2500; continuer -> adv=0; scripted single -> no adv; goodbye
single -> no adv; a question turn on the same layer ("what do you think of them") then a non-refusal ->
`adv=2500;rearm=1` emitted in the SAME reply as her answer; a T-key refused by shape also re-arms [rev2: ai R4, S6;
game S1]), 23 "two candidates agree" (+ the new-utterance guard on `want=1` in its revision-2 form), 24 "stage rail" (clicks_ok 0: scripted -> label + no pick, UNINDEXED -> label, plain indexed -> pick;
clicks_ok 1: all; `ev=calib reset=1` -> clicks_ok 0 again; the label has no closed-list word), 25 "pre-LLM open" [rev2: game
R2; ai R3, S10] (each row names the CLAUSE it fires; Hulda COLD - snapshot `scene=1 sq=DialogueWhiterunBanneredMareScene3
sqj=0`, no cached root: "Nice inn you have here, do you get many visitors?" -> row `marker=toplevel`; "what have you
got?" -> row `marker=kind`; "I'd like a room" / "I need a bed" -> row `marker=kind` (inn); "uh some beer" -> NO row;
"where can I get a drink" -> NO row (Jon's journal=0 row); Hulda WARM (root cached by an E-press): "Nice inn..." ->
`marker=root`; Delphine (q=MQ106) + "nice weather" -> none, + "I'd like the attic room" -> row `marker=qrows` (0.907,
3 shared strict words); Lydia + "what do you think about the war" -> none; an OPEN session -> no pre-LLM open;
`open_pending` blocks the gate's own open; the bridging directive present on that turn only (<= 220 chars) and absent on the
next; the reply muted when the fast pick landed in time and NOT muted when late; the LLM reply's T-key dropped
when `last_exec` is a `do=pick` with this cid; no `OpenInventory` while `open_pending`, appended after
`open_refused`; talk_again drops `lrg_dlgtalk` with no LLM), 26 "ambient open" [rev2: game R1, R2] (Belethor snapshot `scene=1
sq=WISandbox sqj=0` + "what do you sell" -> do=open amb=1 `marker=kind`; Hulda `sq=DialogueWhiterunBanneredMareScene3
sqj=0` (no glob) + "what have you got?" -> do=open amb=1; Helgen `sq=MQ101 sqj=1` -> no open; Rikke + "what do you
sell" -> no open (recruiter, road closed); Tullius after Helgen + join ask -> open; Tullius before Helgen -> none), 28 "WillEmit is the gate's twin"
(for every case of 16-26: `lrgDlgWillEmit === (a pick was emitted)`), 29 "service by kind" [rev2: game R2; ai R3] (Hulda root + "show me
your wares" / "what have you got?" -> OfferServices at clicks_ok 1, the rail line at 0; + "I need a bed" / "I'd like a
room" / "can I get a room" -> RentRoom; + "what have you got against the Stormcloaks" -> nothing; two barter entries
-> nothing; a follower list -> never). `test_latency.php` section 6: the pre-LLM marker path in ms (warm <= 5 ms with
the q-row cache primed; the cold pass < 30 ms asserted separately, ai S10).
Flows: `d21` rewritten (no dlgtalk request; the muted-in-time and the late case; no `OpenInventory` while pending),
`d26` extended (bare yes, explicit), `d65` (quest entry only when the open is refused/impossible), `d50`/`d53` (wire
and MCM ids; `sj`/`drv`/`reset` parse and an old `ev=open` without them still parses), `d64` deleted.
Budget: gate < 5 ms; one extra `lrgDlgMatchText` on a commit pick (< 1 ms); one `links` loop per layer; one
compare per entry for the kind pick; the pre-LLM open <= 5 ms warm (measured); 0 LLM calls; -1 paid turn per
refused pick.

**Lane B - words, truth, factions, speech (server).** Owns `server/lorerim_glue/lib/lrg_factions.php`,
`lib/lrg_speech.php`, `lib/lrg_replies.php`, `lib/lrg_actions.php`, `config/lrg_config.default.json`,
`tools/test_gates.php`, `tools/test_services.php`, and - SECOND PASS, after Lane A has landed - the prompt/truth
functions of `lib/lrg_dialogue.php` named in Lane A (`lrgDlgBusinessBlock` diet, `lrgDlgStaticGuidance`,
`lrgDlgJsonTemplate`, `lrgDlgLockedFacts` reward line, `lrgDlgTruthCheck` clause, `lrgDlgGroundTruth` two new lines,
`lrgDlgApplyOffer` hide_reward). Depends on A for the second pass only.
Task [rev1: game R5, R9, R10a, S9, S11; ai R5, R8ab, R10, R11, S2, S3] [rev2: game R2, R6; ai R1, R3, R5]: S2.1
clause 2's phrase lists and `not_after` strings in `config/lrg_config.default.json` (exactly S2.1's),
`confirm.assent_words` with `ok`, `okay`, `sure thing`, `confirm.single_entry_extra` = `go on`, `carry on`,
`confirm.utter_window` 30, `confirm.single_entry_precision` 0.75, `open.toplevel_marker` / `open.qrows_marker` /
`open.qrows_score` / `open.qrows_cap`, the NEW `config/lrg_dialogue_overrides.default.json` (`entries`:
`MQ102ALegionOath4`, `MQ102BStormcloakOath4` with `commit: true`); S2.2 (`lrgFacRefusesOpen` generalised to
any faction row whose greet carries `flags.sayonce` while the road is closed - one loop), S2.3 (`lrgFacQuestPlan`
condition; the funcret handler in `lrg_actions.php` writes `open_refused`), S6.1 (`lrgDlgRewardWindow($npc)` in
`lrg_speech.php`, read-only in gate A; the `checks.stakes.reward` word list; the `reward` line ONLY when a bargaining
word or an open window, new wording; `hide_reward` registered in `LRG_DLG_SVC_HIDDEN`; the truth clause as the
backstop), S6.2 (2)'s window-gated `persuade`/`reward` branch in `lrgDlgCheckKind` (inert until
`checks.reward.enabled`), S7 (`lrgVoicedWhy` mappings: stale -> "I lost the thread - say that again"; click aborted
-> "that did not take - choose it on the menu"; read failed -> "I did not catch what we could talk about - choose it
on the menu yourself"; the stage-rail sentence of S3.3; learning; the new `Error: choose that one on the list
yourself`; VkRestore "give me a moment"; interrupted "we were interrupted - ask me again"), `lrgDlgNoteRefusal($npc,
$code)` in `lrg_actions.php` storing the PLAIN sentence per code (S7's five) into `last_result{ok:0, why}`, S9 config
keys and removals, S11 (the quoting rule, the kept `sent < n` line, the outcome sentence when `<real_business>` is
suppressed and a `kind` entry is offered, the leave-guard wording, the label words), the `reorder_json_scope`
default. Gate B items (S6.2: `lrgDlgRewardTalk`, the cap, `<reward_talk>`, the `reward` never-false class) are
written behind `checks.reward.enabled = false` and switched on in gate B.
Tests: `test_gates.php` 35 additions (each new reason -> a plain sentence, no machinery word; the closed list parsed
from the .psc, now including `Error: choose that one on the list yourself`; the stage-rail line, the read-only clause
and the `iSceneGate` help text scanned too; no assent PHRASE is a `lrgDlgIsBackOut` phrase under the leading-phrase
form [rev2: game R6]), `test_services` (each new kind phrase maps to exactly one kind; every owner-page and fixture
`mode kind` sentence returns its kind through `lrgDlgServiceKind` - "I need a bed" inn, "show me your wares" / "what
have you got" barter, "what have you got against the Stormcloaks" `''` [rev2: game R2; ai R3]), 38 "never an invented reward" (a quest turn +
"I'll add 200 septims" + GiveGoldTo -> dropped by the hidden-this-turn rail, sentence kept; a barter turn with a
price fact untouched; a quest turn WITHOUT a bargaining word -> the reward line ABSENT; with "I deserve more" ->
present after the faction line inside 600; with an open reward window and no word -> present), 39 "open refused ->
quest entry" (funcret Error on do=open -> `open_refused` -> `lrgFacQuestPlan` queued; no refusal -> `open-first`),
40 "refusals in plain words" (each of the five codes -> a `<what_just_happened>` line with no closed-list word and
no digit), `test_dialogue` 13 (j) prompt size and the two kept rail lines by text (<= 2,500 / 600 - Lane A owns the
file: B adds the assertions in its second pass, A leaves a marked slot). Gate B: `test_dialogue` 27 (the three
reward-ask sentences -> kind persuade / stakes reward INSIDE the window and `''` outside; pass/fail by live sg; cap
on a commoner / merchant / jarl with pocket 0; named amount clamp; item ask refused; the five actions hidden),
`test_gates` 41 (award line with give=, gave= read back, short-fall voiced once).
Budget: <= 12 PHP lines for the truth clause; the reward line ~190 chars only when he bargains; 0 LLM calls.

**Lane C - the game driver.** Owns `game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc`, `LRG_Main.psc`,
`LRG_Profile.psc`. Depends on Lane D's frozen probe API (2.3) - D lands first; C compiles against it.
Task: S1.1-S1.2 (Arm without hide/guard/cursor; visible reasons; `EnterPendingOrManual` -> LISTENING; `HandBack` ->
`StopDriving`; delete `MaybeResume`, `HandleEmergencyKey`, `HandleLeaveKey`'s emergency branch, `Unpark`/`Park` calls,
`DoUnhide`/`NeedsUnhide`/`SendUnhide`/`SendResume`, the guard upkeep in `Step`, the `calib=1` branch and `calSess`/
`openCalib`/`reqCalibPick`/`reqCalibLeave`/`ST_CALIB` in `CmdSelectTopic`/`Step`/`Finish`, the `CalActive*`/`CalAuto*`/
`CalPending` call sites, `bRewalk` counters and `kind=rewalk`, `sBranchInput`/`x1` readers, `sKeyVanilla`, `sHideMode`,
`sHideCursor`, `sEngineOpen`, `sSilence`, `bResumeAfterChoice`, `bHandBackNote`; `Step()` 0.25 s outside CLICKING/
RESPONDING; `iTailMax` default 16); S2.1 (`Finish()` never requests `lrg_dlgtalk`); S3.2 (route proof in
`StepResponding`/`SettleAndReport`; flip on `cr <= 0` x2 / 9 s); S3.4 (`ReadCalibration` (a)-(d) kept minus (d) x1, (e)/(e0)
deleted; `DlgDryReason` two texts as S3.4; no forcing); S4.6 game half (`reqAdv` and `reqRearm`; the engine-line anchor = `sawLine` then
continuous blank `Subtitle()`, any non-blank read restarts it, `ProgressTimerId` never an anchor, not attempted with
`SubtitlesOn()` false; the CHIM anchor for `rearm=1` = `isActorTalking == 0` held 1.0 s with no `NoteSpeech(1)`,
`LRG_Main:4223`'s logic; a `NoteSpeech(1)` restarts either count; log `adv anchor: line seen at= blank since=` and
`adv wait: her line`; `speechAt` from `NoteSpeech(0)`; `bAutoAdvance`; `ev=result auto=1`); **[rev1: game R1, R2, R3, R11, S5; ai
R4] [rev2: game R1, R5, S6; ai R4]** `Arm()` classifies `vis = "scene"` by `GetCurrentScene() != None AND
LRG_Main.EscortHasJournal(scene.GetOwningQuest())` (the `sq`/`sqj` code of `SendFacts` :3698-3710, reusing the last
snapshot's values when < 30 s old; never `HasActiveJournalQuest`, never bare `GetCurrentScene()`), `OpenBlockedReason`
at `iSceneGate = 1` uses the same test (:1345), drives an
engine-opened journal-scene session only when `bDriveSceneMenus == 1 && CalGet("route") > 0 && route src == live`,
sends `sj=1`, `sq=<id> sqj=0|1` and `drv=1|0` on `ev=open` (`scene=` unchanged), and logs `scene= sq= sqj= sj= drv=`
on the arming line; the `live && !IsDriving()` branch of `CmdSelectTopic` answers `Error: choose that one on the
list yourself` for a pick on the live list of an undriven session (the old string only for a pick not on the list);
`ClassifyCrit` grades a guard `crit 2` only with crime gold > 0 or a DGCrime*/arrest topic on the list; S2.2 game
half (nothing: `iSceneGate` already exists); `CmdAward` `give=` branch (gate B, ships inert: `give` > 0 ->
`GetItemCount` check -> `RemoveItem` NPC -> player -> `OK: gave <n> septims`); `LRG_Main`: `CurrentVersion 511`, the
calib corner-note branch and the emergency-key registration removed, `iKeyPushToTalk` registered like the leave
hotkey with `OnKeyDown -> NoteSpeech(0, now)` (0 = not registered), `SayReason` mappings for S7's game-side reasons,
the stage-rail corner note once per session, `CmdQuestEntry` untouched; `LRG_Profile`: nothing in gate A (S4 diet is
gate B; the snapshot's `scene=`/`sq=` keep their meaning).
Tests: `tools/compile.ps1` clean; `test_gates` 35 parses every `ReportOnce("Error: ...")` string of the .psc against
the closed list (Lane B's test; C keeps the strings on it); `test_mcm_wiring` (Lane D's test; C reads only ids D
kept, `iKeyPushToTalk` included); in game: the first evening (section 5.3): step (2b) E on Hulda while the bard
sings -> `clicked pos= origin=engine sj=0` with `sq=BardAudienceQuest sqj=0` (or her tavern scene) on the arming line;
step (5b) Irileth at the gate -> `clicked pos= origin=engine sj=1` with `sq=MQ102 sqj=1`; the four oath lines play
WHOLE, each `result auto=1` timestamp is >= its `ev=line` + the line's length and the click line carries `adv anchor:
line seen at=`; after "what do you think of them?" in a breath: `adv wait: her line` then `clicked ... auto=1` later
than the last `CHIM_SpeechStarted` for Balgruuf plus the sentence; a push-to-talk press during the breath -> `adv
cancelled by speech`. Budget: <= 3 natives per idle poll; per click -3 natives; one string + one float compare per
CLICKING poll; 0 new natives for the scene test (-1 PO3 sweep), the engine anchor or the key; +1 native per 0.1 s poll
only while a `rearm=1` pick waits; -900 lines.

**Lane D - calibration probe and MCM.** Owns `game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc`, `LRG_DlgUI.psc`,
`LRG_MCM.psc`, `game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json`, `settings.ini`, `tools/test_mcm_wiring.php`.
Depends on nothing; lands FIRST (its probe API is the contract Lane C compiles against).
Task: S3.1 (`CalMissing` = cm rm fam st; `CalGreen`; `CalAnswered` "N of 4"; `CalStatusText`; keep `Maintenance`,
`CalArm`, `CalLayer`, `CalReadProbe`, `CalNoteReadCost`, `CalClose`, `CalSet/CalGet/CalG`, `CalWire`, `CalLogSummary`,
`CalForget`, `CalDirty`, `CalNewOpen`, `CalPoll` (timer measurement), `CalSpeech`/`CalX1Poll`/`CalX1Verdict` (x1
measurement), `CalRowProbe`/`CalColourProbe` (measurements), the install-file sync; DELETE `CalShotFire/CalShotArm`,
`StepName/RunStepByName/RunStep`, every `Press*`, `ClickWindow`, `ClickAllowed/DoClick/DoCloseClean/DoCloseForce`,
`CalPending/CalParkPoll`, `CalNextActive/CalActiveName/CalActiveWanted/CalActiveDisable/CalActiveRun/CalLogSkip/
CalActiveLog/CalActiveA1-A5`, `CalPressClick/CalPressClickBody/CalClickRefusal/CalPressReopen/CalReopenProof`,
`CalServiceNpc/CalAutoWanted/CalAutoRefusal/CalAutoClickWanted/CalAutoOther/CalAutoNoteOpen/CalAutoRun`,
`CalArmBudget`, `CalRunsWord`; store keys `runs armed auto gopen hide guard rb reopen` dropped); `LRG_DlgUI`: keep every
primitive (Hide/Unhide/Guard/Park stay unused for a later opt-in), drop the `hide/guard/rb/reopen` store keys;
`LRG_MCM`: keep `CalStatus`/`RefreshStatus`/`CalForget`, delete the five press functions and the `bProbe`/`bCalibActive`
change hooks; S10 exactly (labels, ids, defaults, removals, the new `bAutoAdvance` toggle, the new `iKeyPushToTalk`
key control on the Keys page **[rev1: ai R4]**, `bDriveSceneMenus = 1` ini line without a control **[rev1: game
R2]**, the `iSceneGate` help text naming the real test - "a scene whose quest has an unfinished objective in your
journal" **[rev2: game R1]**); `CalForget` sends `ev=calib reset=1` through `CalWire` **[rev1: game S6]**; `test_mcm_wiring.php` updated
(removed ids must be absent from config.json, settings.ini AND every .psc; `bDriveSceneMenus:Dialogue` listed under
GAME_ONLY with its reason; `iKeyPushToTalk:Dialogue` listed with its control and default 29; the `iSceneGate` help text asserted by substring
**[rev2: game R1]**).
Frozen probe API for Lane C (signatures unchanged): `Maintenance()`, `CalArm(int apd, int fam, int items, int plat,
bool hidden, bool glueOpened)`, `CalLayer(int total, int head, int readMode)`, `CalReadProbe(int total, int head, int
readMode)`, `CalNoteReadCost(int ms, int reads)`, `CalPoll(int state, int count, int timerId, string subtitle)`,
`CalSpeech(int who, float at)`, `CalX1Poll(int count)`, `CalClose(string why, int layer, bool pending, bool anyClick)`,
`CalSet(string key, int value, string src, int samples)`, `CalGet(string key, int default)`, `CalG(string key)`,
`CalMissing()`, `CalGreen()`, `CalAnswered()`, `CalStatusText()`, `CalWire()`, `CalLogSummary()`, `CalForget()`,
`CalDirty(bool clear)`, `CalNewOpen(bool hidden)`.
Tests: `test_mcm_wiring` green (section 6 config-shape checks kept: no `button` type, string `sourceForm`, `scriptName`
on the status text); `d50_wire05` (Lane A's file: A asserts a five-row `k=` parses and an old ten-row one still does);
`compile.ps1` clean on the four scripts. Budget: -2,700 Papyrus lines; one MCM page of controls gone; 0 per turn.

**Lane E - the questline harness and the new flows.** Owns NEW files only: `tools/test_questline.php`,
`tools/fixtures/lrg_questline.json`, `tools/flows/scenarios/d66_visible.php`, `d67_single_entry.php`,
`d68_reward.php` (gate B, PENDING until `checks.reward.enabled`), `d69_walkaway_leave.php`, plus the `5d` section of
`tools/test_prompt_index.php` (single-entry chain shapes and the fixture rows' presence). Depends on Lane A (behaviour)
- E writes the fixture and the tool against this spec first (red), then runs green once A lands; E never edits A's
files (if a flow helper is missing in `dlg_adapter.php`, E adds `tools/flows/questline_adapter.php` instead).
Task [rev1: game R2, R6, R7, R10d, R12; ai R9, S1, S9] [rev2: game R4, R6, S2; ai R1, R2, S4]: section 3 in full, with: closed and single layers built ONLY
from the index's own layer lines (`parent_info` -> `norms`) or the parent row's resolved `links`, never from
hand-listed topics (3.3); a command on the TARGET where `via = words` is a FAIL (3.4); the `sc` beats run twice,
`clicks_ok = 0` -> nothing clicked and the read-only clause present, `clicks_ok = 1` -> their stage-B class, in gate
A (3.4-3.6); `--words` mode (3.1); the first-evening section over Hulda's real rows (3.7); 5d pins every fixture
`parent_info` to a live layer line and the C2Horn hub shape; the fixture rows changed in 3.6 ("who are the
Greybeards" single; "the sword" explicit and STT "sword" park; "I brought your shield, Aela" park; "..." dropped
from Oath4; every `adv` note says the grace counts from line end); [rev2] `fxAdvance(5)` between `fxDlgSay` and the
first `fxDlgTopics` of every beat so `utter.at < session.at` as in game, and one beat per quest presents a 300 s-old
utterance on an E-press session and asserts nothing (game R4); `FE.hulda.open` run twice (cold / warm) with the
clause asserted, `FE.hulda.plain` on the cached-root open, `FE.riverwood.plain` (3.7); the fixture rows re-labelled
by path per S4.5's table (the goodbye singles' loose paraphrases are `park`; "you mean this old stone" replaces "you
mean this stone here"; the Aventus row is `DB01AventusResponse4Topic`; the Oath4 `never` lines gain "long live
Ulfric", "long live the Stormcloaks", "yes, long live Ulfric"); the generic single-entry sweep (game S2): for every
index layer line with exactly one norm whose quest matches `MQ*|C0*|TG*|DB*|MG*|CW*|MS*`, the verbatim line releases
through `lrgDlgSingleEntryRelease` and "uh what now" does not (test time only; the only offline coverage MQ201-MQ305
get before v1.0.1). `d66_visible` asserts the mute on the explicit Kodlak turn and SPEECH on the stage-rail turn,
`sj=0` on an ambient actor -> pick, `sj=1` at `clicks_ok` 0 / 1, and first-contact Kodlak `mode=explicit` with the
utterance 6 s before the session [rev2: game R4]; `d67_single_entry` gains "assignment waits" (the intro pick, then
the B1 layer with the same utterance -> nothing).
Tests: itself (green = every beat has the expected path and every paraphrase resolves to its line, and no `words`
paraphrase clicks anything).
Budget: test time only (<= 60 s over the real index on WSL).

**Lane F - docs, integration, release gates.** Owns `glue/PROTOCOL.md`, `glue/README.md`, `glue/V05_EXPANSION_PLAN.md`
(a v1.0 addendum), the owner page `glue/OWNER_MENULESS_V1.md` (section 5 of this spec, verbatim), `tools/deploy_server.ps1`
/ `tools/install_mo2.ps1` if a path changes, and the integration run. Depends on A-E.
Task [rev1: game R10c-d, S7, S12; ai R6, S8] [rev2: game R1, R3; ai R3, R4]: PROTOCOL 10.4 (`adv=`, `rearm=1`,
`give=`, `amb=`, `sj=`, `sq=`, `sqj=`, `drv=`, `reset=`, `res=` empty, `auto=1`), 10.13 (four rows, route by doing, stage rail, no auto session, no ev=resume/unhide, Forget
clears `clicks_ok`), 10.16 W5/W6 retired, 10.24 (ambient = a scene without a journal objective whose quest the index does not know, or a
glob; the open on S2.1's five clauses), 10.26
(the changed condition; "fallback until proven"), 10.3 (`lrg_dlgtalk` never requested), a new 10.27 "THE VISIBLE
MENU, VOICE-DRIVEN (v1.0)" summarising S1-S4 (journal scene, `drv`, the read-only clause, the three releases, the
line-end grace, the talk key); README keys table and MCM table; the owner page (section 5) - EVERY sentence it tells
the owner to say is verified against the index for the NPC it names (`tools/test_questline.php --first-evening`,
3.7, is the proof: 0 rows = the page is wrong, not the owner; and each step's open path - cold `marker=toplevel`,
warm `marker=root`, step 3 `marker=kind` [rev2: game R3; ai R3]); the staged run on WSL (2.1) twice; `compile.ps1`;
deploy + install with backups; the release-gate checklist (2.4); the memory note update after the owner's first
evening.
Tests: every suite green twice on the final tree; the questline harness green including `--first-evening`;
`compile.ps1` 0 errors. Budget: none.

### 2.3 Order

D (probe API frozen) -> A and C in parallel (A server, C game) -> B's first pass in parallel with A (its own files),
B's second pass after A -> E against A's tree -> F integration. A and C are independent by wire contract (S8); the
integration flow `d66_visible` (E) is the first place both halves meet offline; the owner's first evening is the
second.

### 2.4 Release gates

**Gate A = v1.0 (this build). [rev1: game R2]** Ships: S1 (journal-scene sessions driven behind the route proof),
S2 (with 10.26 kept), S3, S4, S5, S6.1, S7, S8 (minus `give=`), S9, S10, S11, S12. Settings: `bDriveSceneMenus = 1`,
`session.drive_scene = true`, `iKeyPushToTalk = 29`, `checks.reward.enabled = false`. Proof required before install:
every suite green twice, `test_questline.php` green over the live index (the `sc` beats at both `clicks_ok`
values, `--first-evening`), `compile.ps1` clean, `test_mcm_wiring` green.
**Gate B = v1.0.1 (after one green evening: `clicked pos=` + `result ok=1 clicks_ok=1` on a free-standing NPC,
`CALIB set route src=live`, a `clicked pos= origin=engine sj=1` at Irileth whose arming line reads `sq=MQ102 sqj=1`
(game S6 - an alias case cannot satisfy it), a `closed why=goodbye` after a driven click, the four oath lines whole, and the `eo/eos`-style count of engine-opened sessions in the log).** Switches on
`checks.reward.enabled` (S6.2) after `do=award` and `ev=result` have run once, applies the Papyrus diet (perf S4:
`SendFacts` form cache + `QalCsv` every third line + the 13 MCM reads cached out of the snapshot), and DECIDES
10.26's deletion on evidence: delete `lrgFacQuestPlan/Param/Net/EffectFor`, the legion `effects` cells,
`lrgQuestEntryResult`, the catalog row and `CmdQuestEntry` (-> a 3-line quiet OK stub) only if a `do=open amb=1` on
Tullius after Helgen produced `opened sid= origin=glue` and the AP line on the list; otherwise 10.26 stays. Scene
driving is NOT a gate-B flip any more: if the first evening's log shows a wrong click inside a journal scene, the
kill switch is `session.drive_scene = false` (server) - a config line, not a rebuild.

---

## 3. THE QUESTLINE COVERAGE HARNESS - `tools/test_questline.php`

### 3.1 Purpose and contract

Walks the REAL index (`/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson` by default; `--file=`)
for the main quest and the guild openings and asserts, for every advancing player line, WHICH path the glue takes and
that every paraphrase in the fixture resolves to THAT line and no sibling. No game, no CHIM, no LLM, no database: it
runs the server's own functions through the flow harness seams exactly as `tools/flows/run_flows.php` does. Exit 0 =
every beat has its path and every paraphrase resolves. `--quiet`, `--quest=MQ102` (one quest), `--table` (print only
the coverage table), `--stage=B` (evaluate with `checks.reward.enabled = true`; the reward beats only - scene
driving is gate A **[rev1: game R2]**), `--words` (run each paraphrase through `lrgDlgGateItem` as the model's
FREE-TEXT item in intent mode and report resolve / ask / nothing per line - the LLM path feeds the target's T-key,
so without this mode the paraphrase column proves rails, not reach; no LLM **[rev1: ai S9]**), `--first-evening`
(section 3.7 only **[rev1: game R10]**).

### 3.2 Bootstrap

1. `require tools/flows/harness.php`, `adapter.php`, `dlg_adapter.php`; `Fx::$pluginDir = realpath(server/lorerim_glue)`
   (refuse to run inside `/var/www`, as `run_flows.php` does); `fxLoadPlugin()`; `fxDlgLoad()`.
2. Read the ndjson once; keep the header (assert `hash` and `rows` and print them); keep every row whose `quest` is
   in the fixture's quest set OR whose `info_key` is referenced by a fixture beat, and every `_ == layer` line whose
   `norms` intersect those rows' norms (>= 1). Put them in `$GLOBALS['LRG_TEST_INDEX'] = ['rows' => ..., 'layers' => ...]`
   through `fxDlgReset($rows, $layers)` before every beat (the seam `lrgPromptRowsFor` / `lrgPromptLayerCandidates`
   reads them in memory; <= 1,200 rows for the 16 quests, so lookups stay fast).
3. Fixed clock (`fxAdvance`), `PLAYER_NAME = Testplayer`, the snapshot from `fxDlgSnap($over)` with the beat's facts.

### 3.3 The fixture - `tools/fixtures/lrg_questline.json`

```
{ "_": "lrg_questline", "v": 1,
  "quests": ["MQ101","MQ102","MQ102A","MQ102B","MQ103","MQ104","MQ105","MQ106","C00","MG01","TG00","DB01","DB02",
             "MS05","CW00A","CW01A","CW00B","CW01B","DarkBrotherhood"],
  "beats": [ { "id": "MQ102.irileth.news", "quest": "MQ102", "npc": "Irileth",
      "layer": { "kind": "closed", "parent_info": "skyrim.esm:0002B8CC" }   (closed/single: REQUIRED form; the engine's own sibling set)
             | { "kind": "root", "topics": ["OfferServicesTopic","RentRoomTopic", ...] }   (root lists only: a subset of the NPC's real top-level rows),
      "target": { "topic": "MQ102IrilethIntroA1", "info_key": "skyrim.esm:..." | "norm": "i have news from helgen about the dragon attack" },
      "origin": "engine" | "glue", "sj": 0 | 1   (journal-quest scene; the snapshot's ambient scene is "amb": 1),
      "facts": { "pg": 300, "sp": 30, "wis": 1, "guard": 0, "bounty": 0, "mq101c": 0, "clicks_ok": 1, "bamt": 0 },
      "live_text": "I'd like to rent the attic room. (10 gold)"   (optional: the live text when the index cost is a token),
      "expect": "pick|explicit|park|single|auto|check|pay|slot|entry|open|words|scene|show|none",
      "stageB": "pick"   (optional: the expectation with --stage=B when expect is scene),
      "say": [ { "t": "I have news from Helgen about the dragon attack", "via": "explicit" },
               { "t": "news from Helgen about the dragon", "via": "explicit" },
               { "t": "a dragon attacked Helgen and I have to tell the jarl", "via": "park" } ],
      "never": [ "hmm let me think", "no, never mind" ]   (optional; must click nothing)
  } ] }
```
**[rev1: ai R9]** A closed or single layer is named by `parent_info` and built from the index LAYER LINE's `norms`
(the real sibling set, in engine order); a single-entry layer by the parent row's `links` resolved to visible rows
(what `chains16b.py` does). `topics` is allowed ONLY for a root list (no layer line exists) and must then be a
subset of the NPC's real top-level rows; the harness REFUSES a closed beat whose entries differ from the layer line.
Why: the index pairs `MQ102IrilethIntroA1` with `A2` (parent 098D4B) and `B1` with `B2` (parent 0D39AB); a
hand-listed `[A1, B1, B2]` is a menu the engine never shows, and the matcher's margins move with the siblings
("news from Helgen about the dragon": 0.355 on the real layer, 0.266 on the invented one **[M]**). A token cost is
replaced by `live_text`. `via` is what each paraphrase must produce: `pick` (a `do=pick` on the target, fast path or T-key),
`explicit` (a `do=pick` on a commit target with mode `explicit`), `park` (the target parks; then the fixture's assent
"yes" on the next turn releases it - both steps asserted), `single` (single-entry release), `check` (a `do=pick` with
the target's kind), `pay`/`slot` (a `do=pick` with `cost`/`svc`), `words` (no command, and the locked block carries the
fact named in `expect_fact` when given). Every beat has >= 3 `say` entries.

### 3.4 The engine (one beat)

1. `fxDlgReset(rows, layers)`; `fxSendSnapshot(npc, fxDlgSnap(facts))`; `lrgDlgPut('*install*', ['clicks_ok' => facts.clicks_ok])`;
   `fxDlgEvent(npc, 'facts', {...})` when the beat carries `guard/bounty/mq101c/qal/qgiver`.
2. **Fast path**: for each `say`: `fxDlgSay(npc, null, t)` (records the utterance); `fxAdvance(5)` (the list arrives
   AFTER the sentence, as in game - `utter.at < session.at`; game R4) **[rev2]**; `fxDlgTopics(npc, {sid, gen 1,
   layer 1|0, origin, sj, drv, want 1, ask '', pg, bamt}, entries)` -> the D1 echo. Classify: a `do=pick` whose `pos`
   is the target's -> the mode from the emit log line (`intent|slot|faction|explicit|single|continuation`) and `adv=`
   -> `auto`; `do=show` -> `show`; nothing -> look at the state: `parked.norm == target` -> `park`; else `words`.
3. **LLM path**: `fxDlgSay` -> find the target's T-key in `keys` -> `fxDlgLlm(npc, key)` -> the same classification
   (the gate's mode; a PARK log line -> `park`). For `park`, run `fxDlgSay(npc, null, 'yes')` + `fxDlgLlm(npc, key)`
   and assert the release (`do=pick` on the target).
4. A paraphrase RESOLVES when the fast path or the LLM path yields the `via` on the TARGET; it FAILS when it yields a
   command on any other `pos`, or `words` where `via != words`, **or ANY command on the target where `via == words`**
   (the player asked a question and was clicked through - "hmm let me think", the < 4-word check, "fifty septims"
   below the price must never click) **[rev1: game R12]**. A `never` line must yield no command on the fast path; on the LLM
   path (the target's T-key forced) it must yield no command on a COMMIT single, and on a plain single only S4.5
   step 0's shape refusal is asserted - the model's reach on a non-commit single is by design (ai R1) **[rev2]**.
   Every fast-path `say` after the first is a NEW utterance (`fxAdvance` between them) so the S4.3 guard is
   exercised; one beat per quest also re-presents the previous utterance on the next layer and asserts nothing, and
   one beat per quest presents a 300 s-old utterance on an E-press session and asserts nothing (game R4) **[rev2]**.
5. `expect` is the beat's class: `scene` (an `sj=1` engine session) = the harness runs the beat TWICE in gate A: with
   `facts.clicks_ok = 0` it asserts nothing is clicked and the read-only clause is in `<business>`; with
   `clicks_ok = 1` it asserts the class in `stageB` (the slash-right column of 3.6) **[rev1: game R2]**; `entry` =
   the harness runs the pre-LLM turn with `mq101c=1`, `open_refused` set, and asserts one `ExtCmdLRG_QuestEntry` line
   from `lrgFacQuestNet`; `open` = a `do=open;...;amb=1` queue row before any LLM; `none` = the quest has no player
   prompt and the beat lists none. A paraphrase whose real matcher number misses a floor is REPLACED in the fixture
   by one that clears it, or re-classed to `park`/`words` with the number recorded in the fixture; a floor is never
   lowered for a fixture line **[rev1: ai S1]**.
6. Print one table row per beat: `quest | beat | class | path | say ok/total | never ok/total`; at the end per quest
   `beats N, every path present, paraphrases ok/total`; exit 1 on any failure.

### 3.5 Cross-cutting assertions (run once, before the table)

- The stage rail: any scripted or UNINDEXED beat with `clicks_ok = 0` -> no pick and the label present (and no
  closed-list word in it); a plain indexed beat -> pick.
- A LETHAL fixture (guard=1, bounty=200, a `DGCrime*` layer from the index) -> `show` on both paths; a guard with
  bounty=0 opened by the engine (DB01.guard.report, `origin=engine`) -> pick **[rev1: game S5]**.
- `lrgDlgWillEmit === (a pick was emitted)` over every beat and every path (the twin never disagrees with the gate).
- A price list (the vanilla carriage layer from the index) -> exact slot only; "how much to Morthal" -> nothing.
- The leave guard: `leave` on TG00's intro layer -> `do=show`; on Hulda's root list -> `do=leave;pos=-1`.
- The index hash in the header equals `lrgPromptIndexStatus()`'s (a load-order change fails HERE first).

### 3.6 The beat table (the fixture's content; paraphrases are the fixture's, `via` per paraphrase in this order:
first = the engine's own words (pick/explicit), second = a natural paraphrase, third = a loose one)

Legend: E = engine-opened, G = glue-opened; sc = a JOURNAL-QUEST scene at that beat [I] (`sj=1`); for sc beats the
column reads "class at `clicks_ok = 0` / class at `clicks_ok = 1`" - BOTH asserted in gate A **[rev1: game R1, R2]**;
non-sc beats run at `clicks_ok = 1` (the stage rail is a cross-cutting assertion, 3.5). `adv` = the 2.5 s grace
counted from the END of her line (the offline harness sees only `adv=` on the pick) **[rev1: game R11]**.

| beat | npc / origin / sc | target line (index) | class A / B | three paraphrases (via) |
|---|---|---|---|---|
| MQ101.helgen | - | none (2 keep-door rows, scene dialogue) | none | - |
| MQ102A.alvor.help | Alvor E sc | `MQ102RiverwoodHelpTopic` "Do you have any supplies I could take?" (TL S) | scene / pick | "Do you have any supplies I could take?" (pick); "Hadvar said you could help me out" (pick, the sibling variant); "can you spare some supplies for the road" (pick) |
| MQ102.irileth.door | Irileth E sc | `MQ102IrilethForcegreetTopic` "I need to speak to the jarl" | scene / pick | "I need to speak to the jarl" (pick); "let me through, I have to see the jarl" (pick); "I have to talk to Balgruuf" (pick) |
| MQ102.irileth.news | Irileth E sc | `MQ102IrilethIntroA1` "I have news from Helgen about the dragon attack" (S G; siblings B1 S G, B2 I) | scene / explicit | "I have news from Helgen about the dragon attack" (explicit); "news from Helgen about the dragon" (explicit); "a dragon attacked Helgen and I must tell the jarl" (park) |
| MQ102.balgruuf.intro | Balgruuf E sc | `MQ102BalgruufIntroTopic` "I need to talk to you about Helgen" (TL) | scene / pick | "I need to talk to you about Helgen" (pick); "I come with news about Helgen" (pick); "it's about Helgen, jarl" (pick) |
| MQ102.balgruuf.helgen | Balgruuf E sc | `MQ102BalgruufIntroHelgenB1` "The dragon destroyed Helgen and last I saw it was heading this way" (S G; B2/B3 S G) | scene / explicit | (verbatim, explicit); "the dragon destroyed Helgen and it was heading this way" (explicit); "Helgen is gone, a dragon burned it" (park) |
| MQ102.balgruuf.reward | Balgruuf G/E | `MQ102BalgruufReward` "What else can I help you with?" (S G O: a COMMIT single after his line) | single | "what else can I help with" (single, 1.0); "yes, I'll help" (single, assent-led); "is there anything else I can do" (park -> yes: 0.721, precision 0.5 on a goodbye single [M] [rev2: ai R1]) |
| MQ103.balgruuf.blocking | Balgruuf E sc | `MQ103BalgruufBlockingTopic` "I need to talk to you" (DBFB) | scene / pick | "I need to talk to you" (pick); "a word with you, jarl" (pick); "may I speak with you" (pick) |
| MQ103.farengar.intro | Farengar G | `MQ103FarengarIntroTopic` "Have you learned anything about the dragons? Do you need any help?" | pick | (verbatim, pick); "do you need any help with the dragons" (pick); "learned anything about dragons yet" (pick) |
| MQ103.farengar.assignment | Farengar G | `MQ103FarengarIntroB1` "So what do you need me to do?" (S single) | single | "so what do you need me to do" (single, 1.0); "what do you need done" (single, step 5: 0.783, shares `need`); "alright, what's the task you need" (single, assent-led); "tell me what you need done" (pick: the model's T-key - a statement against a question) ; never (fast path): "uh what now" (0.328), "I need a drink", "I need to think" (0.783, statement) [rev2: ai R1] |
| MQ103.farengar.turnin | Farengar G | `MQ103FarengarRetrieveBookTopic` "I have the stone tablet you wanted" (TL S, USSEP) | pick | (verbatim, pick); "I brought the stone tablet you asked for" (pick); "here is your tablet from the barrow" (pick) |
| MQ103.farengar.stone | Farengar G | `MQ103FarengarIntroHaveStone` "Oh, do you mean this old stone?" (S single) | single | "do you mean this old stone" (single, 0.907); "you mean this old stone" (single, 0.907; "you mean this stone here" is 0.721 and replaced [M] [rev2: ai R1]); "yes, I have the stone" (single, assent-led) |
| MQ104.irileth.orders | Irileth E | `MQ104IrilethBlockingTopic` "What are your orders?" | pick | (verbatim, pick); "what are my orders" (pick); "what do you want me to do, Irileth" (pick) |
| MQ104.irileth.along | Irileth E | `MQ104IrilethIntroA1` "I'll come along with you" (S; A2 "I'd rather scout ahead" S G) | explicit | "I'll come along with you" (explicit); "I'll come with you" (explicit); "count me in, I'm coming" (park) |
| MQ104.balgruuf.dead | Balgruuf G/E | `MQ104BDragonDeadTopic` "The dragon is dead." (TL) | pick | "the dragon is dead" (pick); "we killed the dragon" (pick); "the dragon at the tower is slain" (pick) |
| MQ104.balgruuf.reward-ask | Balgruuf G | `MQ104BalgruufOutroA3` "I killed the dragon. I think I deserve a reward" | pick | (verbatim, pick); "I killed the dragon, I deserve a reward" (pick); "what do I get for killing the dragon" (pick) |
| MQ104.balgruuf.power | Balgruuf G | `MQ104BalgruufOutroB1` "When the dragon died I absorbed some kind of power from it" (S; B2 S crit1 W) | explicit | (verbatim, explicit); "when the dragon died I absorbed its power" (explicit); "something happened when it died, I took its power" (park) |
| MQ104.balgruuf.greybeards | Balgruuf G | `MQ104BalgruufOutroC1` "The Greybeards?" (crit1 W, single, unscripted) | auto | (adv=2500 with no words); "the Greybeards?" (single); "who are the Greybeards" (single: containment 0.850, and `greybeards` is a strict shared word [rev1: game R6] [rev2: ai R2]) ; never: "what do you think of them" (0.123; also S4.6's re-arm example, game S1) |
| MQ104.balgruuf.want | Balgruuf G | `MQ104BalgruufOutroD1` "What do these Greybeards want with me?" (S G: a COMMIT single, goodbye=1 **[X]**) | single | "what do these Greybeards want with me" (single, 1.0); "what do they want with me" (single, step 5: shares `want`, precision 1.0); "tell me what the Greybeards want" (park -> yes: a statement on a commit single) ; never: "the Greybeards want me dead?" (precision 0.67 [M]) [rev2: ai R1, R2] |
| MQ104.balgruuf.myreward | Balgruuf G | `MQ104BalgruufIntroA1` "What about my reward?" (S G) | explicit | "what about my reward" (explicit); "and my reward, jarl?" (explicit); "you promised me something" (park) |
| MQ105.arngeir.summons | Arngeir E sc | `MQ105ArngeirIntroTopic` "I am answering your summons" | scene / pick | (verbatim, pick); "I answered your summons" (pick); "you called me here" (pick) |
| MQ105.arngeir.learn | Arngeir E sc | `MQ105ArngeirIntroB4` "I'm ready to learn" (S G) | scene / explicit | "I'm ready to learn" (explicit); "I am ready to learn from you" (explicit); "teach me, I'm ready" (park) |
| MQ105.arngeir.horn | Arngeir E sc | `MQ105ArngeirHornBlockingTopic` "I'm ready for more training" (TL S, GORE) | scene / pick | (verbatim, pick); "I'm ready for more training, master" (pick); "let's continue my training" (pick) |
| MQ105.arngeir.next | Arngeir E sc | `MQ105ArngeirHornA2` "Thank you. What's next?" (S single) | scene / single | "thank you, what's next" (single, 1.0); "what comes next" (single, step 5: 0.675, shares `next`); "yes, what now" (single, assent-led) ; never: "thank you for the horn" (a statement, 0.675) [rev2: game R6; ai R1] |
| MQ105.arngeir.return | Arngeir G/E | `MQ105ArngeirReturnHornTopic` "I have the Horn of Jurgen Windcaller" (TL S G) | explicit | (verbatim, explicit); "I have the horn of Jurgen Windcaller" (explicit); "I brought the horn you sent me for" (park) |
| MQ106.delphine.room | Delphine G | `MQ106RentRoomTopic` "I'd like to rent the attic room." (live "(10 gold)") | pay | "I'd like to rent the attic room" (pay); "the attic room, please" (pay); "can I have the attic room for the night" (pay) |
| MQ106.delphine.horn | Delphine E sc | `MQ106DelphineIntroB3` "I just came here for the horn" (S crit1 W; B1/B2 S I) | scene / explicit | "I just came here for the horn" (explicit); "I only came for the horn" (explicit); "give me the horn, that's all I want" (park) |
| MQ106.delphine.walkout | Delphine E sc | `MQ106DelphineIntroExclusiveA3` "I don't have time for this" (S G walk-out) | scene / park | "I don't have time for this" (park -> yes); "I'm leaving, this is a waste of time" (park); "forget it, I'm done" (park) |
| MQ106.delphine.mound | Delphine G/E | `MQ106DelphineIntroEndA1` "I know that mound, high on the hill east of Kynesgrove" (S; EndA2/A3 unscripted) | pick | (verbatim, pick); "I know that mound east of Kynesgrove" (pick); "the mound near Kynesgrove, I know it" (pick) |
| MQ106.delphine.go | Delphine G/E | `MQ106DelphineIntroEndA2` "Let's go kill a dragon" (G) | pick | "let's go kill a dragon" (pick); "let's go and kill this dragon" (pick); "time to kill a dragon" (pick) |
| C00.kodlak.join | Kodlak G | `C00KodlakJoinUpStartTopic` "I would like to join the Companions." (TL S G, CaM) | explicit | "I would like to join the Companions" (explicit); "I want to join the Companions" (explicit, exact faction line); "can I join the Companions" (explicit) ; never: "what are the Companions" |
| C00.kodlak.handle | Kodlak G | `C00KodlakDontWorryAboutMe` "I can handle myself" (S G; TeachMe S G; Offended S G) | explicit | "I can handle myself" (explicit); "I can take care of myself" (explicit); "don't worry about me" (park) |
| C00.eorlund.sword | Eorlund G | `C00EorlundIntroTopic` "Vilkas sent me with his sword" (S crit1 W) | pick | (verbatim, pick); "Vilkas sent me over with his sword" (pick); "I have Vilkas's sword for you" (pick) |
| C00.aela.shield | Aela G/E | `C00AelaIhaveYourShieldTopic` "I have your shield" (TL S G) | explicit | "I have your shield" (explicit); "I brought your shield, Aela" (park -> yes: 0.675 [M], below 0.70 [rev1: ai S1]); "Eorlund sent this shield" (park) |
| C00.eorlund.weapon | Eorlund G | `C00EorlundBranchTopic` "I was told you would have a weapon for me" (TL) | pick | (verbatim, pick); "I was told you'd have a weapon for me" (pick); "Kodlak said you have a weapon for me" (pick) |
| C00.eorlund.choice | Eorlund G | the sword row of `C00EorlundBranchTopic`'s layer (5 x S G) | explicit | "a sword, please" (explicit, slot); "I'd like the sword" (explicit, slot); "the sword" (explicit, slot at 2 tokens [rev1: game R7]); "sword" (park -> yes: one STT token) ; never: "a weapon" |
| MG01.learn | any G | `MG01InitialBranchTopic` "Where can I learn more about magic?" (TL S O) | pick | (verbatim, pick); "where can I learn magic" (pick); "who teaches magic around here" (pick) |
| MG01.faralda.enter | Faralda G | `MG01Faralda1WhyAreYouHereBranchTopic` "May I enter the College?" (CollegeEntry) | pick | "may I enter the College" (pick); "I'd like to enter the College" (pick); "let me into the College" (pick) |
| MG01.faralda.persuade | Faralda G | `MG01FaraldaEntryPersuade` success variant "I'm the best mage you'll ever see. This little test is an insult." | check | (verbatim, check); "I'm the best mage you'll ever meet, this test is an insult" (check); "this test is beneath me, I'm the best mage there is" (check) ; never: "test insult" (< 4 words) |
| MG01.faralda.test | Faralda G | `MG01FaraldaEntryTakeTest` "I'll take your test, then" (S; sibling persuade) | explicit | "I'll take your test then" (explicit); "fine, I'll take your test" (explicit); "alright, test me" (park) |
| MG01.nirya.spell | Nirya G | `NiryaStage10SellSpell` "Okay, this is for the spell (30 gold)" | pay | "okay, this is for the spell" (pay); "here's the gold for the spell" (pay); "I'll pay for the spell" (pay) |
| MG01.mirabelle | Mirabelle G | `MG01MirabelleStage30BranchTopic` "I was told to come see you" (TL S) | pick | (verbatim, pick); "I was told to see you" (pick); "Faralda sent me to you" (pick) |
| MG01.mirabelle.tour | Mirabelle G | `MG01MirabelleTakeTour` "I'd love to have a look around" (S G; TourSkip S) | explicit | (verbatim, explicit); "I'd love to look around" (explicit); "show me the place" (park) |
| MG01.tolfdir.class | Tolfdir E sc | `MG01TolfdirStage50SceneBranchTopic` "You want my opinion?" (crit1 W) | scene / pick | "you want my opinion" (pick); "my opinion? really?" (pick); "you're asking what I think" (pick) |
| TG00.brynjolf.what | Brynjolf E sc | `TG00BrynjolfIntroBranch01` "I'm sorry, what?" (W unscripted) | scene / pick | "I'm sorry, what" (pick); "sorry, what did you say" (pick); "what do you mean" (pick) |
| TG00.brynjolf.chain | Brynjolf E sc | `TG00BrynjolfIntroBranch02a` -> `03a` -> `04` -> `06` (single unscripted W) | scene / auto | (adv=2500 each); "what do you have in mind" (single); "what do I have to do" (single) |
| TG00.brynjolf.refuse | Brynjolf E sc | `TG00BrynjolfIntroBranch07` "Break the law? Are you kidding?" (S G) | scene / park | "break the law? are you kidding" (park -> yes); "I'm not breaking the law for you" (park); "no, I won't do that" (park) |
| TG00.brynjolf.persuade | Brynjolf E sc | `TG00BrynjolfIntroMQ203C1` success "Won't have a point earning all that gold if the dragons kill you all" | scene / check | (verbatim, check); "no point earning gold if the dragons kill you all" (check); "the dragons will kill you all before you spend it" (check) |
| TG00.brynjolf.ready | Brynjolf G | `TG00BrynjolfReadyToStartBranchTopic` "I'm ready. Let's get this started." (TL S G) | explicit | "I'm ready, let's get this started" (explicit); "I'm ready to start" (explicit); "let's do it" (park) |
| DB01.aventus.ok | Aventus E sc | `DB01AventusQuestBranchTopic` "Are you all right?" (TL S) | scene / pick | "are you all right" (pick); "are you okay, boy" (pick); "is everything all right with you" (pick) |
| DB01.aventus.contract | Aventus E sc | `DB01AventusResponse4Topic` "Contract?" (skyrim.esm:01F339, S single, goodbye=0 **[X]**; revision 1's `DB01AventusQuestResponse4` does not exist) | scene / single | "contract?" (single, 1.0); "what contract?" (single, containment); "yes, I'll take the contract" (single, assent-led) [rev2: game R6; ai R1] |
| DB01.grelod.threat | Grelod G | `DB01GrelodThreatenBranchTopic` "The Dark Brotherhood has come, Grelod" (S G; Threaten2 S G) | explicit | (verbatim, explicit); "the Dark Brotherhood has come for you, Grelod" (explicit); "Aventus sends his regards" (park: the sibling) |
| DB01.guard.report | a guard G (guard=1, bounty=0) | `ICQEGuardMaybeTopic` "Grelod is abusing the children at the orphanage. You must do something!" (TL) | pick (NOT show) | (verbatim, pick); "Grelod is abusing the orphans, you must do something" (pick); "the children at the orphanage are being abused" (pick) |
| DB01.guard.persuade | a guard G | `DB01_Persuade_Success` (persuade) | check | its text (check); a paraphrase (check); a loose one (check) |
| DB01.guard.bribe | a guard G (bamt=200, pg=300) | `DB01_Bribe_Yes` (bribe, cost token) | check | its text (check); "here's two hundred septims, look into it" (check); "fifty septims should do" (words: below the price) |
| DB02.captive.who | a captive E sc | `DB02CaptiveWhoAreYouBranchTopic` "Who are you?" | scene / pick | "who are you" (pick); "who are you, then" (pick, 0.850); "tell me who you are" (pick, 0.783 under the shipped list - 0.37 under revision 1's widened one, which is why scoring keeps the shipped list [M] [rev2: ai R2]) |
| DB02.captive.intimidate | a captive E sc | `DB02Captive1Intimidate` "Answer me, or die!" (tag-only check) | scene / check | "answer me or die" (check); "answer me now or you die" (check); "talk, or I kill you right here" (check) |
| MS05.viarmo.apply | Viarmo G | `MS05ViarmoApplicationTopic` "I'm looking to apply to the College" (Saturalia) | pick | (verbatim, pick); "I want to apply to the Bards College" (pick); "I'd like to join the College" (pick) |
| MS05.viarmo.comein | Viarmo G | `MS05ViarmoApplicationTopic2` "And that's where I come in?" (S O single) | single | "and that's where I come in" (single, 1.0); "so that's where I come in" (single, 1.0 by F1); "yes, I'll do it" (single, assent-led) ; never: "yes, but not now" (a refusal) [rev2: game R6; ai R1] |
| MS05.viarmo.task | Viarmo G | `MS05ViarmoTask2` "What do you need me to do?" (S single) | single | "what do you need me to do" (single, 1.0); "what do you need" (single, 1.0 by F1); "tell me what you need done" (pick: the model's T-key) [rev2: ai R1] |
| MS05.viarmo.poem | Viarmo G | `MS05GivePoemTopic` "I found King Olaf's Verse" (TL S) | pick | (verbatim, pick); "I found King Olaf's verse" (pick); "I have the verse you wanted" (pick) |
| MS05.verse.dragon | Viarmo G | `MS05PoemVerse2Evil` success "Olaf was Numinex, a dragon in human form" (persuade, S crit1 OW) | check | (verbatim, check); "Olaf was Numinex, a dragon in the shape of a man" (check); "Olaf was really the dragon Numinex" (check) |
| MS05.verse.coward | Viarmo G | `MS05PoemVerse2Incompetent` (S crit1 W; >= 2 scripted siblings) | explicit | its text (explicit); a close paraphrase (explicit); a loose one (park) |
| MS05.verse.final | Viarmo G | `MS05PoemFinalVerse` "Is that it?" (S G: a COMMIT single with 0 meaning words) | single | "is that it" (single, exact); "yes, that's it" (single, assent-led); "is that all of it" (park -> yes: 0.593 [M]) [rev2: ai R1] |
| MS05.bard | Viarmo G | `MS05SendToJornTopic` "Does that mean I'm a bard now?" (S G) | explicit | (verbatim, explicit); "so am I a bard now" (explicit); "am I in, then" (park) |
| MS05.court | Viarmo E sc | `MS05ViarmoAtCourtBranchTopic` "Are we ready?" (S G) | scene / explicit | "are we ready" (explicit); "are we ready to begin" (explicit); "shall we start" (park) |
| CW00A.tullius.closed | Tullius (ambient sc, mq101c=0) | none | words (locked fact: Helgen first; no open, no entry) | "I want to join the Legion" (words); "let me enlist" (words); "sign me up for the Legion" (words) |
| CW00A.tullius.open | Tullius (ambient sc, mq101c=1, no list) | - | open (do=open amb=1, pre-LLM) | same three (open) |
| CW00A.tullius.entry | Tullius (ambient sc, mq101c=1, open_refused) | AP `CW00TulliusGreetTalkRikkateAP` -> CW00A 10 | entry | same three (entry) |
| CW00A.tullius.join | Tullius (list carries the AP line) | "I don't want to sit idly by after what I've witnessed. I want to join the Legion" (S G) | explicit | "I want to join the Legion" (explicit, exact faction line); (verbatim, explicit); "I'm here to enlist in the Imperial Legion" (explicit) |
| CW00A.rikke.test | Rikke E sc | `CW00RikkeBlockingTopic` "About that test..." (USSEP) | scene / pick | "about that test" (pick); "about this test of yours" (pick); "what about the test" (pick) |
| CW00A.rikke.accept | Rikke E sc | `CW00RikkeGreetAcceptQuest` "Consider that fort already yours." (S G) | scene / explicit | (verbatim, explicit); "consider the fort already yours" (explicit); "I'll clear the fort" (park) |
| CW01A.oath.ready | Tullius G/E | `CW01AJoinLegionBranchTopic` "I'm ready to take the oath" (TL) | pick | (verbatim, pick); "I'm ready for the oath" (pick); "I'll take the oath now" (pick) |
| CW01A.oath.yes | Tullius | the yes row of the `MQ102JoinLegionYes`/`No` layer (own confirmation) | pick | "yes" (pick); "yes, I'm ready" (pick); "I am" (pick) |
| CW01A.oath.1-3 | Tullius | `MQ102ALegionOath1/2/3` (single unscripted W) | auto | (adv=2500 each); the oath line spoken (single); "I swear it" (single) |
| CW01A.oath.4 | Tullius | `MQ102ALegionOath4` "Long live the Emperor! Long live the Empire!" (S G single, JK) | single | "long live the Emperor, long live the Empire" (single, 1.0); "long live the Emperor" (single, 0.907, precision 1.0); "I swear" (single, assent) ; never: "wait, what does that mean?" (shape), "the emperor is a fool" (0.567), "long live Ulfric" / "long live the Stormcloaks" (0.721, precision 0.67), "death to the emperor", "hail the Stormcloaks, the true sons of Skyrim" (0.316), "yes, long live Ulfric" (a foreign word after the assent), "yes, but not now", "..." [rev1: ai R2] [rev2: ai R1; game R6] |
| CW00B.galmar.test | Galmar E | `CW00BGalmarBlockingTopic` "About that test" | pick | "about that test" (pick); "this test you mentioned" (pick); "tell me about the test" (pick) |
| CW00B.galmar.join | Galmar E | `CW00BGalmarSignMeUp` "That's why I'm here. I want to join." (S crit1 W; scripted siblings) | explicit | (verbatim, explicit); "that's why I'm here, I want to join" (explicit); "sign me up" (park) |
| CW00B.galmar.accept | Galmar E | `CW00BGalmarAcceptQuest` "I'm off to kill that ice wraith. I'll be back soon." (S G) | explicit | (verbatim, explicit); "I'm off to kill the ice wraith" (explicit); "I'll go kill it" (park) |
| CW01B.oath.1-3b | Galmar E | `MQ102BStormcloakOath1/2/3` + `CW01BStormcloakOath3b` (single unscripted W) | auto | (adv=2500 each); the line spoken (single); "I swear" (single) |
| CW01B.oath.4 | Galmar E | `MQ102BStormcloakOath4` "All hail the Stormcloaks..." (S single, goodbye=0 **[X]** - a commit ONLY through the `commit: true` override) | single | "all hail the Stormcloaks" (single, 0.850); "hail the Stormcloaks, the true sons of Skyrim" (single, 0.892, precision 1.0); "I swear it" (single) ; never: "all hail the Empire" (0.610), "yes, all hail the Empire" (foreign `empire`), "the Stormcloaks are traitors to Skyrim" (0.610) [rev2: ai R1] |

Where the table says "its text / a paraphrase", Lane E reads the row from the index and writes the three lines into
the fixture (never into the tool). Beats whose `sc` column is set run twice in gate A (3.4 step 5): at
`clicks_ok = 0` the harness asserts NOTHING is clicked on the `sj=1` engine session and the read-only clause is
present; at `clicks_ok = 1` it asserts the slash-right class **[rev1: game R2]**. The `CW01A.oath.yes` row is the
engine's own confirmation layer only once `lrgDlgLayerIsOwnConfirmation`'s regexes are widened (Lane A; game S3).

### 3.7 The first evening, proven offline (`--first-evening`) [rev1: game R10, S7; ai R6]

Every sentence the owner page (5.3) tells the owner to say is a fixture row here, on the NPC it names, against her
REAL index rows; a line with 0 index rows fails the harness, so the page can never name a runtime-renamed prompt
again ("Tell me about Whiterun." has 0 rows **[X]** - the log's `txt=` was a renamed prompt).

| step | npc / origin / facts | target line (index) | class | paraphrases (via) |
|---|---|---|---|---|
| FE.hulda.plain (5.3 step 2) | Hulda G, `clicks_ok=0`, root cached by step 1 | `ACFDialogueWhiterunHuldaBranchChatTopic` "Nice inn you have here. Do you get many visitors?" (`moretosaywhiterun.esp:000940`, quest `ACFDialogueWhiterun`, TL, scripted=0; ai S4) | pick (under the rail), reached by a pre-LLM open `marker=root` [rev2: game R3; ai R3] | (verbatim, pick); "nice inn you have here" (pick, 0.85); "do you get many visitors here" (pick, 0.87) ; never: "uh some beer" (no open, no pick) |
| FE.hulda.engine (5.3 step 2b) | Hulda E, snapshot `scene=1 sq=DialogueWhiterunBanneredMareScene3 sqj=0` (her real scene **[L]**; no glob names it), `sj=0`, `clicks_ok=0` [rev2: game R1] | the same row, `origin=engine` | pick | "nice inn you have here" (pick); "you get many visitors?" (pick) |
| FE.hulda.trade.rail | Hulda G, `clicks_ok=0` | `OfferServicesTopic` "What have you got for sale?" (scripted=1 **[X]**) | rail (the line, no pick) | "what have you got" (words: the rail line present); "show me your wares" (words) |
| FE.hulda.trade (5.3 step 3) | Hulda G, `clicks_ok=1` | the same row | pick | "what have you got" (pick, mode kind: barter [rev2: game R2]); "show me your wares" (pick, mode kind); "let me see your goods" (pick, mode kind) |
| FE.hulda.room (5.3 step 4) | Hulda G, `clicks_ok=1`, live "(10 gold)" | `RentRoomTopic` "I'd like to rent a room." (scripted 1/0 by plugin **[X]**) | pay | "I'd like a room" (pay, 0.87 or mode kind); "I need a bed" (pay, mode kind: inn, `need a bed` [rev2: game R2; ai R3]); "a room for the night" (pay) |
| FE.hulda.open (5.3 steps 2-3, first contact) - runs TWICE [rev2: game R2c; ai R3] | Hulda, no open session, `amb=1` (snapshot `sq=DialogueWhiterunBanneredMareScene3 sqj=0`); pass 1 COLD (no cached root), pass 2 WARM (root cached) | - | open (do=open amb=1, pre-LLM, the bridging directive present and the state wording absent) | cold: "Nice inn you have here, do you get many visitors?" (open, `marker=toplevel`); "what have you got?" (open, `marker=kind`); "I'd like a room" (open, `marker=kind`); "uh some beer" (NO open); "where can I get a drink" (NO open: Jon's row). warm: "Nice inn you have here, do you get many visitors?" (open, `marker=root`); "what have you got?" (open, `marker=kind` - the kind clause runs before the root one); "uh some beer" (NO open) |
| FE.riverwood.plain (5.3 step 8, a fresh start) [rev2: game R6b] | Sven G in the Sleeping Giant Inn, `clicks_ok=0`, MQ102 stage <= 30 | `DialogueRiverwoodSvenTiberSeptimTopic` "Do you know any old ballads about dragons?" (skyrim.esm:0BB965, quest `DialogueRiverwood_Revised`, TL, scripted=0, nconds=1 **[X]**; Lane E confirms the speaker and the condition with `esp_dump.py`; fallback: Hilde's `ACFRiverwoodConversationsHildeBranchRumorsTopic` "Anything interesting going on in town?") | pick (under the rail) | (verbatim, pick, 1.0); "any old ballads about dragons" (pick, 0.941); "know any ballads about dragons" (pick, 0.941) ; never: "sing me something about dragons" (0.610, margin 0.098 - the model, not the matcher) [M] |
| FE.irileth.gate (5.3 step 5b) | = MQ102.irileth.news at `clicks_ok=1`, `sj=1` (`sq=MQ102 sqj=1`), `origin=engine` | `MQ102IrilethIntroA1` | explicit | "I have news from Helgen about the dragon attack" (explicit) |
| FE.kodlak (5.3 step 6) | = C00.kodlak.join at `clicks_ok=1` | `C00KodlakJoinUpStartTopic` | explicit | "I would like to join the Companions" (explicit) |
| FE.kodlak.rail | Kodlak G, `clicks_ok=0` | the same row (scripted=1) | rail | "I would like to join the Companions" (words: the label present, nothing clicked) |

---

## 4. WHAT IS REMOVED (gate A unless marked B; every one a version-skew no-op)

GAME
- `LRG_Dialogue.psc`: the `Guard/Hide/HideCursor` block in `Arm()` and the `assisted`/`hide refused` reasons; the
  guard upkeep in `Step()`; PENDING (`Park`, the corner note, `fSilenceTimeout`), `StepPending`; `HandBack` ->
  `StopDriving`; `MaybeResume`, `SendResume`, `DoUnhide`, `NeedsUnhide`, `SendUnhide`; `HandleEmergencyKey`;
  `Finish()`'s `talkNeeded`; `calSess`/`openCalib`/`reqCalibPick`/`reqCalibLeave`/`ST_CALIB` and the `calib=1` branch;
  the assisted-after-losses rule; `ReadCalibration` (d) x1 and (e)/(e0); `bRewalk` counters and `kind=rewalk`;
  readers of `iHideMode bHideCursor iEngineOpen iBranchInput fSilenceTimeout bHandBackNote bResumeAfterChoice
  iKeyVanillaMenu bIaccToggle bQuestColour`.
- `LRG_DlgProbe.psc`: everything not in Lane D's kept list (~2,700 lines). `LRG_MCM.psc`: the five press functions.
- `LRG_Main.psc`: the calib corner-note branch of `HandleCommand`; the emergency key registration. (`CmdQuestEntry`
  and `Qe*` STAY - gate B decides.)
- MCM: the controls of S10; `[Calib]` section of settings.ini.
- Calibration rows `hide guard rb reopen` (gate); `timer` and `x1` demoted to measurements; store keys `runs armed
  auto gopen`.
- The automatic calibration session, the active pass, the manual presses, the probe hotkey.
- Gate B: the 13 per-snapshot MCM reads (`LRG_Profile`), `SendFacts`' two `GetFormFromFile` per call and `mqq`/`mq101`
  (keep `mq101c`), and - on evidence - `CmdQuestEntry` + `Qe*` -> a 3-line stub.

SERVER
- `lrg_dialogue.php`: `lrgDlgCalibCandidate`, `lrgDlgCalibPick`, the `calib=1` fast-path branch; the `assisted`/`losses`
  derivation and its `do=show` rail; `lrgDlgHandBackForeseen`'s `assisted`/`bi`/`x1` branches; `lrgDlgInitiativeCandidate`
  and `<she_may_raise>`; the rewalk depth (`rw`/`rwd`); `walkaway` and `crit >= 1` as commit reasons; the unconditional
  first-selection park for explicit statements; the `< 2 tokens never releases` rule for a named assent; the
  first-contact `lrg_dlgtalk` admission (`session.talk_again = false`); **[rev1]** the bare "a quest this NPC is in"
  marker on the PRE-LLM open path; the "the first time, choose this one on the menu yourself" label; the `reward`
  line on every quest turn (now only when he bargains); the `[leave] key` wording; the `Error: that is not on the
  table right now` answer for a pick on the live list of an undriven session (kept for a pick NOT on the list).
- Game **[rev1]**: bare `GetCurrentScene()` as the driver's scene test; the line-settle anchor for an `adv` pick.
  **[rev2]**: `HasActiveJournalQuest` as the driver's and `OpenBlockedReason`'s scene test (the function stays,
  unused by the driver); the progress timer and the bare "subtitle cleared" read as `adv` anchors; the `session.at`
  clause of the new-utterance guard; revision 1's widened SCORING stop list (withdrawn before it was built - scoring
  keeps the shipped list; the strict list lives beside it); the per-entry stage-rail labels (one line per prompt).
- Config keys: `confirm.typed_skips`, `auto_advance.grace_seconds` (-> `grace_ms`), `assist.*`, `calib.auto.*`,
  `quests.initiative.*`, `match.cont_depth`, `session.silence_seconds`, `session.lost_seconds`, `script_proxy_watch`,
  `quest_colour`.
- Wire: `res=` content (slot kept, sent empty); `ev=unhide`, `ev=resume` (no longer sent; handlers kept); `ev=calib
  k=` shrinks; `lrg_dlgtalk` (never requested; handler kept).
- Tests/flows: `d64_auto_calib.php`, `test_dialogue` 15 (the calib pick), `test_gates` 37 (quest entry) is KEPT.
- Gate B, on evidence: `lrgFacQuestPlan/Param/Net/EffectFor`, the legion `effects` cells, `lrgQuestEntryResult`,
  `LRG_ACT_QUESTENTRY`, the `GlueQuestEntry` catalog row; PROTOCOL 10.26 retired to one paragraph.

Not removed, and why: the D1+D2 double delivery (the ring handles it; it is what makes the fast path ~0.5 s); the
`(+N more)` bucket; `TakeGoldFromPlayer` in `hide_gold`; `lrgDlgServiceSlot` and every service rule; `lrgFacRoad` and
the Helgen words; `LRG_DlgUI`'s hide primitives (unused, kept for a later opt-in); the `choice_words` fallback; the
two-step itself (its releases are widened, its park is kept); the X1 measurement line **[J]**.

---

## 5. OWNER-FACING WORDING (for `glue/OWNER_MENULESS_V1.md`; plain words, septims, no jargon)

**5.1 What changed. [rev1: game R10; ai S8]** When you talk to somebody about their business, their real list of
things to say comes up on screen - the same list you would get by pressing E - and stays there. You do not have to
click it. Say what you want in your own words and the glue picks the matching line for you; her real reply plays.
You can still click any line yourself at any moment, and nothing ever takes the list away from you. There is no key
to remember and nothing to set up: after your first conversation with anyone (even by pressing E), it has learned
what it needs. The first line it ever picks for you must be a simple one - a question, a bit of small talk - and
until you have asked one, an answer that would move a quest along is left for you to click, and she tells you so:
"ask me something simple first". From then on it picks anything you name.

**5.2 What you will notice. [rev1: game R2, R8, R10, S7, S12; ai R4, R5, R6]**
- The first time you talk business with anyone, her list can take up to eight seconds to appear; she says a word
  first, then her real line plays. While the list is on screen you may also click it or press Tab. Do not repeat
  yourself - the second sentence would be answered from the list once it is up. One honest exception: the very first
  sentence to someone whose list you have not seen that evening can be answered twice when it is small talk in your
  own words rather than a line she really has (the sentences on this page are her real lines, so they are not)
  [rev2: ai R3].
- "What have you got?" to an innkeeper or a merchant, even one you have never spoken to: her list appears, the trade
  line is picked in front of you, the shop opens (after your first simple question of the evening; before it she asks
  for one). "Show me your wares", "I'd like a room" and "I need a bed" work too [rev2: game R2; ai R3].
- "I want to join the Companions" to Kodlak: one sentence, the real line, his real answer.
- Delphine's room: you ask your questions; nobody asks "do you mean it?" unless you say you are leaving.
- The oath: his line, then the next one by itself a short breath after his words have gone from the screen (or when
  you say it); "Long live the Emperor" ends it. If you press your talk key (Left Ctrl) during that breath, he waits
  for you; if you ask him something in it, he answers, and the next line follows a breath after his answer ends
  [rev2: game R5; ai R4].
- Irileth at the gate, after your first simple question of the evening: wait for her list to show, then say "I have
  news from Helgen about the dragon attack", and it happens (this is the one place where saying it again, once the
  list is up, is right). Before that first click she says "choose it on the list yourself" [rev2: game S4].
- A choice that cannot be undone (an oath, a refusal that ends things, anything over 100 septims): she asks once
  unless you already said it plainly, and she quotes the line she means. "Yes" is enough after her question; so is
  "I swear", "alright", "okay" or "count me in" - and "yes, I'll do it" is a yes, while "yes, but not now" is a no
  [rev2: game R6; ai R5].
- When something cannot be done she says why, in her own words, and the list is yours.

**5.3 Your first evening, in this order. [rev1: game R10, S7]** Each step names the sentence to say and the line it
writes in the log (`Data/SKSE/Plugins/LoreRimGlue/lorerim_glue.log` and the server log).
(1) Hulda in the Bannered Mare: press E on her, look at her list, press Tab - the corner note says the menu was
learned, and she keeps her list in mind for half an hour. Log: `cal k=... fam:1 st:1 cm: rm:` [rev2: game R3].
(2) Hulda again, within that half hour: "Nice inn you have here, do you get many visitors?" - she says a word, her
list comes up, the line is picked in front of you. If you waited longer than half an hour, press E on her again
first. Log: `do=open marker=root` (or `marker=toplevel`), `clicked pos=`, `result ok=1 clicks_ok=1`, `CALIB set route
src=live` [rev2: game R3; ai R3].
(2b) Press E on Hulda while the bard is singing and say "nice inn you have here" - it still works. Log: `clicked
pos= origin=engine sj=0` (the arming line shows `sq=BardAudienceQuest sqj=0`) [rev2: game R1].
(3) Hulda again: "What have you got?" - her list comes up, the trade line is picked, the shop opens. Log: `do=open
marker=kind` then `clicked pos= mode=kind` [rev2: game R2].
(4) Hulda again: "I'd like a room" - the room and the price, no question asked under 100 septims. Log: `do=pick
cost=10`.
(5) Faralda on the College bridge: "May I enter the College?" Log: `clicked pos=`.
(5b) Irileth at the Whiterun gate (after Helgen, with Hadvar's or Ralof's message): when her list appears, say "I
have news from Helgen about the dragon attack". Log: `clicked pos= origin=engine sj=1 mode=explicit` (arming line
`sq=MQ102 sqj=1`) [rev2: game S4, S6].
(6) Kodlak: "I would like to join the Companions." Log: `clicked pos= mode=explicit`.
(7) If you happen to have a bounty, a guard's list stays yours and she says so. Log: `stopped driving why=lethal`
[rev2: game S3].
(8) On a NEW game instead (Riverwood, before Whiterun): Sven in the Sleeping Giant Inn, "Do you know any old ballads
about dragons?" is the simple first line - Alvor and Gerdur are inside the main quest's scene and their lists stay
yours until that first click. Log: `clicked pos=` [rev2: game R6].
Report what she said and what you saw; every one of these writes a line in the log. If step 2 does not pick, stop
there and send the log - everything after it depends on that first click.

**5.4 What it will not do, and says so. [rev1: game R2, R10; ai S8]** Helgen (there is nothing to pick there - the
game plays it). A guard arresting you (the list is yours). On your first conversation of the evening, an answer that
would move a quest along is left for you to click once (or ask something simple first - she says which). Conversations
that begin inside a running quest scene (a court in session, the sacrament ritual): the list shows; as soon as your
first real click of the evening is in the log it drives those by voice too, and before that she tells you to choose
on the list yourself. A different reward: what a quest pays is fixed by the game; she will say so and never promise a
house or a title - and if the list has a line about your reward she will point you to it. Extra septims: in v1.0 she
can only refuse; in v1.0.1 you can talk her into a small extra from her own purse after a real Speech check - never
more than she carries, never more than a day of her wages, once per quest.

**5.5 Settings. [rev1: ai R4]** None needed. If you ever want to see what it would pick without it picking, the
Menuless questing page has "Menuless questing: dry run (nothing is clicked)" - leave it off for play. "Let her carry
on by herself when there is only one thing to say" is the short breath in the oath; leave it on. The Keys page has
"Your talk key (CHIM's push-to-talk)" set to Left Ctrl, the same key CHIM uses; it only tells her you are about to
speak during that breath. If you ever change CHIM's key, change this one too, or set it to 0 and she will simply
carry on after the breath and answer your words on the next line. The short breath needs dialogue subtitles on (they
are on your install); with them off, every such line waits for your voice [rev2: game R5].

---

## 6. OPEN DECISIONS (only the owner can make these; defaults chosen so that nothing needs deciding to play)

1. **The list is on screen.** v1.0 shows the vanilla list while you talk (you never have to click it). Hiding it is
   an opt-in polish for later and is NOT in v1.0. Default: visible. Say so if you would rather wait for a hidden
   version than play v1.0 as it is.
2. **The short breath. [rev1: game R11; ai R4] [rev2: game R5; ai R4]** A line that is the only thing to say (the
   oath lines, "The Greybeards?") advances by itself 2.5 s after her words have gone from the screen (or, if you asked
   her something in the breath, 2.5 s after her answer ends) unless you press your talk key (Left Ctrl) in that
   breath. Default: on, key = Left Ctrl, dialogue subtitles on. Say so if you want every line to wait for your voice, or if your CHIM
   push-to-talk is not Left Ctrl.
3. **Extra septims (v1.0.1).** The cap is what she carries, at most one day of her wages (a farmhand 40, a merchant
   100, a jarl's court 500), once per quest, after a real Speech check. Default: on in v1.0.1 at those numbers.
4. **Conversations that begin inside a running quest scene [rev1: game R2]** are driven by voice from the moment
   your first real click of the evening is in the log (the glue checks this itself); until then she tells you to
   choose on the list. Default: on. Say so if you would rather they always stay yours to click (one server config
   line, `session.drive_scene = false`; no rebuild).
