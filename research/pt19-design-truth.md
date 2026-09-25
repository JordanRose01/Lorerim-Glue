# pt19 - MENULESS QUESTING v1.0, designed under the lens ENGINE TRUTH AND SAFETY

2026-09-24. Read-only design (nothing in the glue was edited). Sources: `glue/PROTOCOL.md` 10.13-10.26 **[P]**,
`research/pt19-design-survey.md` (the map; its census in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\`) **[S]**,
the live index `/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson` (37,561 rows, hash e756e311; a
second census in `...\design\truth_census.py`, numbers below marked **[X]**), the code where a decision needed it
(**[C]** file:function), `research/dialogue-engine.md`, `research/p2-quest-branching-speech.md`, `research/pt18-quest.md`,
`research/pt18-calibration.md` **[R]**. Currency: septims. Inference is marked **[I]**.

The lens, in one sentence: **a line runs only as the engine's own INFO, selected on the engine's own live list, under the
engine's own conditions; the glue never invents an outcome, and every refusal is a voiced reason.** Everything below is
either a rail that can be proved by an offline test, or an honest statement of what cannot be done.

---

## 0. Seven principles (each one is testable)

1. **The click is the only execution primitive for dialogue.** No `Say`/`SayTo`, no `SetStage` from the server, no
   fragment replay **[R dialogue-engine verdict]**. The single exception is PROTOCOL 10.26's scripted-entry table: a row
   whose WHOLE fragment is `GetOwningQuest().SetStage(n)` (decoded, not inferred), applied by the game after re-checking
   every engine condition on the live forms, and only where the driver cannot click. Today that table has ONE row
   (Tullius -> CW00A 10) and it stays that size until another row is decoded the same way.
2. **The index never executes anything.** It labels (kind, variant, scripted, goodbye, walkaway, crit, cost). Execution
   always resolves against the list live NOW (`TryResolvePick`: pos + topicIndex for this gen, or a UNIQUE 40-char text
   prefix) **[C LRG_Dialogue.psc:2440]**. A wrong index can cost one confirmation too many or too few - never a wrong INFO.
3. **The engine's conditions decide.** A closed greeting (AP's MQQuickstart 7.0), a skill gate (CollegeEntry), a
   Requiem recruitment gate - all are honoured by construction, because the entry is simply not on the list. The glue
   says so (locked facts, voiced refusals) and never works around it.
4. **No outcome is narrated before the game reports it.** Speech checks: the engine picks success/failure, `ev=result`
   carries the verdict, `<what_just_happened>` states it next turn; an `unknown` verdict is stated with NO verdict
   **[C lrg_dialogue.php:2120]**. Quest entry: the licence is given only when every condition is on the facts line;
   otherwise the OK is VOICED after the game confirmed it (10.26). Never both.
5. **Gold moves once, through the engine, or through a game-verified transfer the player can see.** Priced/bribe entries
   hide CHIM's `GiveGoldTo`/`TakeGoldFromPlayer` (`hide_gold`); the truth gate drops any money action on a number the
   game has not confirmed **[C :4776]**. The one glue-owned transfer (`do=award`) is re-checked by the game
   (`CmdAward`: purse first, then `RemoveItem`) **[C LRG_Dialogue.psc:1369-1445]**.
6. **Never silent, never empty, never false** are three separate rails with three separate tests (10.21, 10.23, 10.25).
   This design closes the one silent hole left: a server-side gate refusal the player never hears (section 8, P4).
7. **Prefer removing a mechanism to adding one.** Section 10 lists what goes.

---

## 1. The execution model as it stands (facts the design builds on, not re-derived)

One player sentence -> ONE turn record -> the model picks a key or words -> a fixed rail ladder -> at most ONE
`ExtCmdLRG_SelectTopic` -> the game resolves it against the live list and clicks -> the ENGINE does the rest
**[S 0, 1.1-1.5]**. The three paths that can move a quest:

| path | when | executor | truth guarantee |
|---|---|---|---|
| A / B: a click on a live entry (glue-opened session, hidden) | the NPC stands free, no scene | `StepClicking` -> `SelectAndVerify` -> `Click(route)` | identity-verified (pos + topicIndex + txt prefix), purse re-checked a frame before, `FreezeMoved` re-reads on any gold/Speech change |
| C: the scripted-entry table (10.26) | the driver cannot click (ambient scene actor, ml=0, entry not listed) | `LRG_Main.CmdQuestEntry` -> `Quest.SetStage(n)` after re-checking isid / qdone / notdone / max / qnd on live forms | the same call the fragment makes; idempotent; refusals voiced |
| D: direct barter | no real entry can answer a barter request | CHIM's own `OpenInventory` (= the vanilla barter window) | no quest logic touched |

Everything else is words with a reason.

### 1.1 The census this design is sized on **[X]**

Over the 16 quests of the fact sheet (512 rows): `plain` 247 (unscripted, unpriced, no check, no walk-away),
`scripted-plain` 104 (a fragment, but neither Goodbye nor walk-away), `commit: scripted+goodbye` 82, `commit: walk-away /
crit1` 63, `pay` 7, `check` 9. Single-entry layers reached by one link: unscripted 45, scripted 16, scripted+goodbye 9.
Globally: 21,515 plain / 7,653 scripted-plain / 5,755 scripted+goodbye / 894 walk-away / 1,139 priced / 587 checks /
9 lethal / 5,727 hidden; single-link targets 3,218 unscripted / 723 scripted / 789 scripted+goodbye.

So on the main questline **roughly half of the rows are harmless questions** that should click without ceremony, a
fifth are scripted statements that move a stage but end nothing, and a quarter are commits that deserve exactly one
question - and today the shipped two-step asks that question on ALL commits including the three consecutive oath
lines of CW01A and the fourteen walk-away layers of MQ106 **[S 8.8]**. Section 2 fixes the shape without weakening the
rail.

---

## 2. Confirmation policy for quest-critical lines

Three verdicts and nothing else: **CLICK NOW**, **ONE QUESTION**, **NOT BY VOICE** (`do=show` or nothing, always with a
reason). The class comes from `lrgDlgClass`/`lrgDlgIsCommit` **[C :1322-1410]** exactly as shipped; only the two-step's
RELEASE rule and the `leave` verb change.

### 2.1 CLICK NOW (no confirmation)

| class | condition to click | why it is safe |
|---|---|---|
| `plain` (indexed, unscripted) | a T-key from THIS turn's offer (pos + norm), or a words match >= 0.55 with margin >= 0.15 | a question or a statement with no fragment; the worst case is a harmless canned line |
| `scripted-plain` (indexed, fragment, not Goodbye, not walk-away) | as above, **and** on the similarity path at least one shared meaning-carrying word (F1 > 0); `similar_text` alone never executes a scripted entry (**P13**) | it moves a stage the player is asking to move (Farengar's "So what do you need me to do?"); a fragment without Goodbye leaves the conversation open, so nothing is lost that talking cannot recover |
| `service` | the slot matcher (exact, longest wins) on a price list; the similarity matcher only on a sentence layer | unchanged (10.15) |
| `pay` below `confirm.min_gold` 100 and below 25 % of the purse | `afford` from the LIVE `pg`, freeze rule B1, the game re-checks `reqCost` a frame before | the engine takes exactly the listed price |
| **single-entry layer, indexed unscripted, not Goodbye** | **auto-advance after a grace of 2.5 s** unless the player starts speaking (**P3** - the declared-but-never-built `auto_advance`) | 45 such rows in the 16 quests ("What does that mean?", "How did you figure all this out?"); a canned continuation, no fragment, the talk stays open |
| continuer text ("...", "Go on.", "And?") on a single-entry layer | as above, no grace | the p2 research's 110 continuers |
| a check kind (`persuade` / `intimidate` / `bribe`) | the check rails: >= 4 words on voice, affordable, named amount not below the price, not a suppressed retry; then the ENGINE decides | the click IS the attempt; the outcome is the engine's |

### 2.2 ONE QUESTION (the two-step, `lrgDlgParkOrRelease`)

Applies to `commit` (indexed scripted+goodbye; closed layer with >= 2 scripted siblings; walk-away; crit 1; a
`commit_tags` tag; cost >= 100 or >= 25 % of the purse; UI-only: new on a closed layer, `choice_words`), `dismiss` /
`home` follower verbs, and scoff-first on a doomed check. First selection PARKED; her question IS the message; release
needs a different request, a player speech turn, different words, no refusal, >= 2 tokens **[C :2774]** - unchanged.

**Two releases are added, both stronger evidence than the shipped release, so the rail is not weakened (P2):**

1. **Said the line.** The player's own words match the parked entry's text at >= 0.70 (`confirm.said_line_score`) and
   carry >= 4 tokens: the statement is the engine's own line in the player's mouth. "I would like to join the
   Companions" -> Kodlak's `C00KodlakJoinUpStartTopic` clicks on the first turn; "Upon my honor I do swear undying
   loyalty to the Emperor" -> `MQ102ALegionOath1` clicks at once, and so do oath 2, 3 and 4 as the engine offers them.
   Nothing here executes a line the player did not say.
2. **The engine asked, the player answered.** A single-entry commit layer whose parent line (the NPC's last real line
   in `lines[]`) ends with `?`, and the player's words are an assent (`yes / aye / I will / I'm ready / do it / that's
   right` + at least one more token) -> release. This is the p2 research's own rule 5.3 ("a bare yes counts when the
   parked entry was quoted back by the NPC in the previous line, or when it is the layer's only commit entry"). The
   Wuunferth shape (a deliberate top-level accusation, then "I assume you have proof?" -> the single scripted+goodbye
   entry) releases on "yes, we found his amulet" - which is what a human would click - and NOT on silence, not on
   "hmm", not on the same sentence arriving on a poll.

A vanilla "Are you sure?" layer (two entries, one yes-shaped, one no-shaped) releases at once, as today.

### 2.3 NOT BY VOICE

Unchanged rails, first hit wins **[C :2641-2718]**: resist arrest (`do=show`, every setting) -> follower `never_topics`
(nothing) -> arrest-class session (`do=show`) -> freeze B1 (nothing, re-read) -> `bi=2` closed (`do=show`) -> crit 2
(`do=show`) -> `meta` (nothing) -> cannot afford (nothing) -> check rails (nothing). Intent mode and `want=1` never
execute a consequential entry.

**One addition (P1): `leave` never walks away.** Today `leave` with no back-out entry cancels the session (`pos=-1` ->
`CloseClean`), and on a layer whose rows carry a walk-away topic (`twat`) the ENGINE then plays the walk-away INFO -
a consequence the driver never reads (the survey's gap 9.2; `grep -i walkaway LRG_Dialogue.psc` is empty). Rule: on a
layer where ANY entry has `walkaway=1` or a `twat`, `leave` clicks the `class=back` entry if one is listed, else emits
`do=show` (the menu is his; he may walk away himself). 894 walk-away rows in the index, 63 in the 16 quests.

**One removal: the assisted-after-two-losses downgrade** (`losses` -> `assisted` closed layers for the rest of the game
session **[C :1134-1153, :2679]**) goes. It is a hidden, permanent state the player cannot see and would report as
"she stopped answering by voice"; a lost layer is already handed back visibly by the watchdog. `losses` stays as a
log counter (P9).

---

## 3. Matcher and model-item policy

### 3.1 How a paraphrase becomes an entry (as shipped, kept)

- A **T-key** is trusted only when it names THIS turn's offer entry by pos AND norm (`dropped stale` otherwise)
  **[C :2567-2575]**. The model sees the entry text verbatim (minus the tag) and the label (`[persuasion attempt]`,
  `[commits - cannot be undone]`, `[costs N gold]`), plus "one key ONLY when his last words clearly do that thing;
  two fit -> ask" **[C :2050-2060]**.
- **Words** go through, in order: follower verbs (exact containment) -> enlistment (exact topic/line) -> price-list
  slots (exact, longest wins, ambiguity asks, negation and price questions execute nothing) -> similarity
  (`exact 1.0 / containment 0.85 / 0.35 + 0.65 * word-F1 / similar_text * 0.8`, floor 0.55, margin 0.15), and the
  similarity path may execute `plain` / `service` ONLY **[C :2186-2215, :2624-2637]**.
- **Fast path** (`want=1`): the best of the model's `ask=` and the player's utterance **[C :2290-2294]**.

### 3.2 What changes

| rule | why | where |
|---|---|---|
| **P8 - the two candidates must agree.** When `ask=` and the utterance both clear 0.55 but on DIFFERENT entries, nothing is clicked (log `ambiguous: model=<i> player=<j>`) | today the model's paraphrase can out-score the player's own words and click an entry he did not name; the lens forbids a click the player's words do not support | `lrgDlgAnswerWant` |
| **P13 - a scripted entry needs a shared word.** On the similarity path an indexed `scripted` entry executes only with word-F1 > 0 | `similar_text * 0.8` on short strings reaches 0.55 with no meaning overlap; that is fine for a question, not for a fragment | `lrgDlgGateItem`, `lrgDlgAnswerWant` |
| **P2 - said-the-line** (2.2) | the strongest evidence available: the engine's own sentence | `lrgDlgParkOrRelease` |
| the pick log line gains `overlap=<F1>` | so a model mis-pick is greppable | `lrgDlgEmit` log only |

### 3.3 When the model's own choice is trusted

- **Fully** for `plain` and `scripted-plain` T-keys: the text was in front of it verbatim, and a wrong harmless
  question costs one canned line.
- **As step one** for commits: the T-key parks; the player's next words release.
- **Never** for lethal, arrest, resist, meta, an unaffordable price, a check with fewer than 4 words, a follower
  `never_topics` entry, an entry from `<what_you_can_ask certain="0">` (the index-only list "cannot execute anything"
  by construction: no pool -> `lrgDlgMaybeOpen`, and the block forbids the action).
- **Never on the fast path for a consequential entry** - only a T-key can, after the park.
- Ambiguity is always a question in her words, never list order, never the first match.

---

## 4. Negotiation policy

The owner's brief: the player "can take their own approach ... negotiating an additional reward, a different reward, a
reward in septims ... within reason of the ai". Under this lens:

### 4.1 What is true and what is not

- **The engine's reward is fixed.** A quest reward is the fragment's `AddItem` / gold on the reward INFO (e.g.
  `MQ102BalgruufReward`, `C00EorlundBranchTopic` sword/dagger/greatsword choice). The glue cannot give a different item,
  a bigger reward or a different quest outcome without inventing it. **Decision: a different reward is refused in
  words, truthfully** - she says what the game gave and that she has nothing else to give (4.3).
- **Where the engine offers a persuade variant** (MG01 Faralda, TG00 C1, MS05 Verse2Evil/Verse4Dragon, DB01 ILQE), the
  negotiation IS that entry: the click, the engine's verdict, the real line. Nothing to add.
- **A bonus in septims can be paid for real** - but only as a transfer the GAME verifies from HER OWN purse, never as
  CHIM's unverified `GiveGoldTo` (the DLL's `plugin_command`; whether it checks the NPC's purse is not verifiable
  offline **[WSL core_action_seed.sql:35]**). The snapshot already carries her gold (`gold=` **[C LRG_Profile.psc:457]**).

### 4.2 The bounded bonus (P6) - decision: YES, under all of these

1. **Evidence of a reward just given**: `ev=result` with a gold delta to the player, or `lrgDlgNewQuestRows` reporting
   the objective moved on, within 180 s, and the NPC is the giver (`qgiver=1`, or an alias of that quest in `qal`).
2. **The player asked for more** in his own words (`lrgDlgCheckKind` = `barter`, or a named amount plus
   more / extra / bonus / on top).
3. **A real free-conversation check passes** - the existing `lrgDlgCheck` with kind `barter` (persuade-type: her stance +
   the stakes + `iCheckBias` -> one of the five Speech globals read LIVE) **[C lrg_speech.php:120-270]**. A fail is a
   fail ("He did not talk you down") and is remembered 86,400 s.
4. **The cap** = min(the amount he named or she offered, ONE DAY of her own wage tier (`lrgDlgWage`: common 40,
   performer 64, trade 100, fighter 132, craft/magic 200, court 500 **[C lrg_speech.php:95]**), 50 % of the reward
   that just moved, her purse from the snapshot). Once per quest per NPC (memory key `bonus|<quest>`). A jarl can tip
   500; a farmhand 40. Requiem's own economy is untouched: the septims come out of her inventory.
5. **She names the number before it moves**: the number is a `bonus` locked fact this turn so the truth gate lets her
   say it, and the transfer is `do=award;give=<n>` (new additive key on the existing verb). Game `CmdAward`: `give`
   moves only when `npc.GetItemCount(gold) >= give`, else `Error: <she> has no such coin on her` (voiced through 10.21);
   `RemoveItem(gold, give, true, player)`. `GiveGoldTo` stays hidden on that turn (`hide_gold`).
6. **Never**: while an engine priced/bribe entry is listed (the engine owns money then), on a rechat/poll turn, inside a
   scene, for a companion (her purse is the player's), under the developer dry run.

### 4.3 What she says when a different reward is impossible

A `reward` locked fact is written from the same evidence as 4.2.1: "the game gave: N septims" / "the game gave: the
matter moved on to '<objective>'". Rule line: *you cannot give anything else - no other item, no other sum, no promise
of one; say plainly what was given and that it is all you have to give.* The never-false judge (10.25) already refuses
invented next steps for faction rows; the `reward` class extends the same "state, not story" wording to rewards. If no
reward evidence exists at all, she says she cannot pay what she has not been shown to owe - the truth gate drops any
money action on an unconfirmed number regardless.

---

## 5. Coverage of the main questline and the guild openings (from the fact sheet **[S 7]** and the census **[X]**)

Reading rule: **G** = a session the GLUE opens on a free-standing NPC (hidden, driven); **E** = a session the ENGINE opens
(forcegreet, blocking branch, a scene paused on the player) - today visible/assisted at `iEngineOpen=0`; with **P5**
(section 6) these become "voice on the visible menu"; **T** = the scripted-entry table (10.26); **W** = words only.

| quest | beat | rows / class | path | v1.0 verdict |
|---|---|---|---|---|
| MQ101 Unbound | Helgen | scene dialogue, 2 rows | - | **cannot** (no player prompts; AP owns the start) |
| MQ102 | Alvor/Gerdur help (TL, S) | scripted-plain | E (forcegreet scene) -> P5 | voice on the visible menu |
| | Irileth at the gate (forcegreet) -> IntroA1/B1 (S G) | commit | E -> P5, one question or said-the-line ("I have news from Helgen about the dragon attack" >= 0.70 -> click) | works with P5 |
| | Balgruuf court: IntroTopic (TL) -> HelgenB1/B2/B3 (S G) | plain -> commit | E (throne scene) -> P5; the three statements are said-the-line candidates | works with P5 |
| | Reward (S G O) | commit, single entry after his question | 2.2 rule 2 (assent) | click on "yes" |
| MQ103 | Balgruuf blocking (TL, DBFB) | plain | E -> P5 | works with P5 |
| | Farengar intro chain: A1-A3 (I) -> B1 "So what do you need me to do?" (S, single) | scripted-plain, single scripted | G: waits for words; a match with a shared word clicks (P13); NO auto-advance (scripted) | click now |
| | turn-in RetrieveBook (TL, S) / HaveStone (S, single) | scripted-plain | G, click now | works |
| MQ104 | Irileth blocking, tower lines | plain / commit | E -> P5 | works with P5 |
| | Balgruuf outro: OutroB2, C1 (crit 1 W), D1 (S G), IntroA1 "What about my reward?" (S G) | commit | G after the summons scene: one question each, or said-the-line | works; 2 questions at most |
| MQ105 | Arngeir intro (forcegreet, #2 crit 1), Horn blocking (crit 1), ReturnHorn (TL S G) | plain / commit | E -> P5; ReturnHorn G: said-the-line | works with P5 |
| MQ106 | Delphine chain: 14 crit-1 walk-away rows, ExclusiveA3/C5 (S G) | commit | E (secret room scene) -> P5: one question per commit unless said-the-line; `leave` never cancels on these layers (P1) | works with P5; 3-6 questions over the chain |
| | RentRoom "I'd like to rent the attic room." (cost token) | pay (< 100) | G, afford, click now | works |
| | Kynesgrove (scene) | plain / commit | E -> P5 | works with P5 |
| C00 Companions | Kodlak "I would like to join the Companions." (TL S G) | commit | G: said-the-line -> click now; else one question. THE ONE clean menuless join | works |
| | Vilkas test, shield delivery (scenes) | plain | E -> P5 | works with P5 |
| | Eorlund intro (crit 1) -> HappyToHelp (S G); weapon choice (S G x5) | commit | one question; "a sword, please" -> containment 0.85 -> said-the-line click | works |
| MG01 College | Faralda: HereResponse (S I) -> EntryPersuade (check, ISD variant) / TakeTest (S, 2 variants) | scripted-plain / check | G: click now; the check through the engine (failure variant + scripted -> scoff-first parks once) | works; Requiem/CollegeEntry gate shown by the engine |
| | Nirya spell (cost 30) | pay | afford, click now | works |
| | Mirabelle (TL S) / Tolfdir class (scene, crit 1) | scripted-plain / commit | G / E -> P5 | works with P5 |
| TG00 Thieves | Brynjolf forcegreet, 10 crit-1 layers, C1 persuade, ReadyToStart (TL S G) | commit / check | E -> P5; one question per commit unless said-the-line; the persuade through the engine | works with P5 |
| DB01 | Aventus (scene) TL S; Grelod threaten (S G); ILQE guard persuade/bribe (guard, bounty 0 -> not arrest class) | scripted-plain / commit / check | E -> P5 / G | works; the guard route needs `bounty=0` on W10 to be read as non-lethal (untested in game, S gap 9.6) |
| DB02 | captives (forcegreet on waking) TL plain; Intimidate/Persuade tag-only (always succeed) | plain / check (tag) | E -> P5; check rails (>= 4 words) then the engine | works with P5 |
| MS05 Bards | Viarmo application (linked from his hello) -> Task2 (S); verse layers (crit 1 W, two persuade variants); court / induction (scenes) | scripted-plain / commit / check | G / E -> P5 | works; 3-4 questions over the poem |
| CW00A / CW01A Legion | Tullius, Rikke at the map table (ambient scene) | - | **T** (Tullius -> CW00A 10, after Helgen only) + W; Rikke's stage 20 W until decoded | as 10.26; oath chain: said-the-line (P2) |
| CW00B / CW01B Stormcloaks | Ulfric forcegreet, Galmar blocking, oath chain | commit | E -> P5; no effects row -> W at the table if they sit in an ambient scene [I] | partly |

**Honest totals.** With P5 measured green: every beat above except Helgen is reachable by voice; 7 of 16 quests depend
on P5 (their advancing lines sit in engine-opened sessions **[S 7]**). Without P5 those beats are the vanilla menu,
clicked by hand, harvested by the glue.

---

## 6. Engine-opened sessions: voice on the VISIBLE menu (P5)

**Problem.** At the shipped `iEngineOpen=0` every forcegreet / blocking branch / scene-paused menu runs
`visible=assisted` **[C LRG_Dialogue.psc:1810]**; `CmdSelectTopic` answers a pick in a session the glue is not driving
with `WOULD CLICK (assisted)` + an error **[S 1.4]**. Driving them HIDDEN (`iEngineOpen=1`) was decided off (D5) and
never exercised; hiding a menu the engine opened inside a scene, parking it for 45 s and guarding input is exactly the
class of state the lens distrusts.

**Design.** A third value, `iEngineOpen=2` (the new default): the menu stays VISIBLE, nothing is hidden, guarded or
parked, the player can click at any moment - and the glue may click on his behalf when his words name an entry.

- Game: `StepManual` sends `want=1` (not 0) when `origin=engine && crit != 2 && sEngineOpen == 2`; `CmdSelectTopic`
  accepts `pick` / `leave` in `ST_MANUAL` under the same condition -> `reqPickReady`; `StepManual` -> `TryResolvePick`
  -> `ST_CLICKING` (the existing body: `readySeen` from `MenuState()==1`, line-settle, never over CHIM's TTS, `VkRestore`,
  `FreezeMoved`, `reqCost`, `CapturePre`, `SelectAndVerify`, `Click`, RESPONDING) with `isHidden=false`; after the
  result, back to `ST_MANUAL` (never PENDING, never a watchdog note - the menu is his). The dry run and the calibration
  gate apply exactly as to any click.
- Server: a session with `origin=engine` and `vis` in (assisted, scene) is "voice-visible": the gate and `want=1` run
  as for a driven session; the foreseen hand-back clause is not added for `assisted`; `do=show` on such a session is a
  no-op (already visible).
- Gate to switch it on: the passive calibration row **`x1 == 1`** ("the session survives the player speaking"
  **[P 10.13; V05 6.2]**) - measured from any session, engine-opened ones included. `x1 == 2` or unknown -> assisted as
  today. So the feature switches itself off on an install where voice input kills the menu.
- Scenes: allowed. The menu the engine opened IS the scene's own step; a voice click is byte-identical to the player's
  click. What stays forbidden is unchanged: never OPEN on a scene actor, never PENDING/hide a scene speaker, a scene
  that STARTS inside a driven session hands back (G4).
- Race: the player and the glue click in the same frame -> `SelectAndVerify` verifies identity a frame before the
  Invoke; a second `TopicClicked` on an emptied list aborts (`ClickResult <= 0` -> `ShowList`, nothing clicked).

**Cost:** MANUAL polls stay at 0.5 s (1-2 natives); one click body per pick. No new wire key: `origin=engine` already
rides `ev=open`; `want=1` already exists. One MCM default flips (0 -> 2); the values 0 and 1 keep their meaning.

**What this does not do:** a hidden, driven engine session (`iEngineOpen=1`) stays unexercised and off. If x1 is red on
this install, the seven scene-bound quests stay click-by-hand and the design says so rather than pretending.

---

## 7. Calibration and dry-run policy (P7)

### 7.1 Which rows really protect the click

| row | protects | verdict |
|---|---|---|
| `cm` counting, `rm` reading | the list is read correctly -> the txt/topicIndex identity check has real data | **gate** (passive, any E-press conversation) |
| `hide` round trip | the menu can be restored -> no soft-lock | **gate** (active A1, any open) |
| `rb` state read-back | `ShowList` recovery after an aborted click | **gate** (active A2) |
| `apd` + `reopen` | the vanilla menu is pristine after a hide (R1) | **gate** (passive + `CalReopenProof` on any click on a pristine menu) |
| `st` Smart Talk, `fam` layout | preconditions | **gate** (passive) |
| `guard` input guard | the player's own E/mouse cannot click a hidden entry | **gate**, but learned WITHOUT a click: the mash window of `CalAutoRun` **[C LRG_DlgProbe.psc:3138-3168]** runs on the FIRST automatic open (today it needs `gopen > 0` because it is fused to the click) |
| `route` click route | the click reaches the engine | **measurement, not a gate**: a wrong route clicks NOTHING (`ClickResult <= 0` -> `ShowList`, re-read) - it is safe by construction and is proven by the first live click that RESPONDING verifies |
| `timer` progress timer | one of six verification signals | **measurement**: subtitles are the other signal; `fLineSettle` auto-timing falls back to the MCM floor |

**Result: 8 gate rows, none of which needs an automatic CLICK.** The automatic calibration session keeps its open and
its mash window (guard, passive rows, active A1/A2) and loses its click: `CalAutoClickWanted` returns false, the P8
half of `CalAutoRun` is removed, `CalMissing` drops "progress timer" and "click route". `CalAnswered` becomes "N of 8".

### 7.2 The first live click is made safer (probation)

While `route == 0` (nothing clicked on this install yet), the server emits `do=pick` ONLY for an entry that is
`class=plain`, `scripted=0`, `cost=0`, `kind=''`, not Goodbye, not walk-away (the same vetting `lrgDlgCalibPick` applies
**[C :2913]**); everything else is `do=show` with the foreseen clause ("choose this one yourself"). The first verified
click writes `route` (`CalSet("route", route, "live")`) from `StepResponding`, and probation ends. A harmless question
the player actually asked is a strictly safer first click than a calibration click on a shopkeeper's last line - and it
happens in the first conversation, not after an automatic session.

### 7.3 The dry run

- The DEVELOPER dry run (`bDryRun`) blocks everything and is voiced by name - unchanged.
- The MENULESS dry run (`bDlgDryRun`) ships ON, is forced while the gate is red, auto-clears once when green AND
  `iKeyVanillaMenu` is bound (the in-code default is already Home 199 **[C LRG_Dialogue.psc:627-720]**) AND MCM Helper
  writes; released in memory otherwise - unchanged. With 7.1 the gate goes green from ordinary play plus one automatic
  open (no click), so the owner presses nothing.
- Emergency key: Home gives the menu back at any moment; End stops a scene - unchanged.

---

## 8. Failure modes and their voiced reasons

### 8.1 What is already voiced (kept; words verbatim from the code)

| where | reason | the player hears / sees |
|---|---|---|
| `CmdSelectTopic` | module off | "the feature is switched off" (voiced) |
| | another speaker's session live | "a conversation is in progress" |
| | pick outside the live list, decide timeout | "that is not on the table right now" |
| | an older unreported x, stale twice, click aborted twice | "that moment has passed" |
| `StepClicking` | voice keeper | "her voice is not ready" |
| | purse | "not enough gold" |
| | dry run / red gate | "still learning the dialogue menu (N of 8)" / "the menuless dry run is on" + corner note + resumable hand-back |
| `OpenBlockedReason` | scene / combat / distance / asleep / menu | "a quest scene is running", "combat", "too far apart", "they cannot talk right now", "a conversation is in progress" |
| `StepPending` | 45 s silence | corner note "no answer came - the menu is yours" + hand-back |
| `HandBack` | lethal / arrest / branch_show / unverified / read-failed / key / watchdog | corner note "choose this one by hand"; server `handback ... why=` |
| `CmdQuestEntry` | road closed / already / past / other side / not really him | her words ("that road is not open to you yet ...") + the server's plain mapping (10.26) |
| `CmdAward` | purse / dry run | "not enough gold" / the dry-run text |
| server, next turn | any game-reported failure not voiced (8 s gap) | "what she last tried did not happen (...)" note; `<what_just_happened>` "Nothing came of it: <why>" |

### 8.2 The silent hole, and its fix (P4)

A server-side gate refusal - `cannot afford`, `named amount below the price`, `fewer than 4 words for a check`, `retry
suppression`, `freeze rule` - today returns `null`: the model's line plays, nothing happens, one log line **[C :2699-2709,
:2727-2756]**. The player asked for a concrete thing and nobody said why it did not happen. Owner addendum 11 calls that a
defect in every case.

Fix: a new additive verb **`do=refuse;why=<afford|amount|words|retry|frozen>`** with the existing `note=`. Game
`CmdSelectTopic`: `refuse` -> `XPush(x)`, `ReportResult("Error: <her words>")` from a fixed map (afford: "you cannot pay
what that costs - <cost> septims"; amount: "<named> is not what it costs"; words: "say it properly, in a whole sentence";
retry: "she has refused that already and nothing has changed"; frozen: "a moment - things just changed"), which
10.21's funcret path voices as one short in-character line (ONE extra LLM+TTS turn, only on a refusal). Server-side the
same refusal writes `last_result {ok:0, why, at}` so `<what_just_happened>` carries it next turn if the voice gap skips
it. Ambiguity is NOT a refusal: the prompt already makes her ask which.

### 8.3 Failure modes that remain and what the player sees

| mode | what happens | honest limit |
|---|---|---|
| STT blank (17 % once) | no turn; nothing | the mic, not the glue |
| the model returns no words | never-empty re-ask (~5 % of turns), then a floor line | +1.5-2 s on those turns |
| the model claims an enlistment / orders | never-false rejects / mutes the sentence, floor line | faction rows only; other rows are UNKNOWN and logged |
| the model names a price nobody confirmed | the truth gate drops the money action; her words play | she was wrong out loud, in character |
| the click reaches the engine but no signal within 20 s (4 s for a check) | `SendResult ok=0 why=unverified` -> hand-back, "The matter is raised." | 429 silent player INFOs exist [R p2 D2]; the menu-state signal covers most |
| a scene starts inside a driven session | hand-back (G4) | by design |
| the index is stale for a mod (rebuild on `plugins.txt` change) | UI-only fallback: new entries on closed layers are commits, no auto-advance | conservative; `unindexed` log |
| Dynamic String Distributor renames a prompt at runtime | text matching fails, identity check still holds | none today touch DIAL/INFO [R p2 A4] |

---

## 9. Performance budget (numbers; the glue is 58 ms of a 7.7 s turn - the model is the wait)

| lane | measure | budget | source |
|---|---|---|---|
| Papyrus, hidden session loop (0.1 s) | natives per poll | **<= 8**: 2 guard upkeep (`AllowProgress`, `GuardPoke`) + 2 every 3rd poll (`Guard`, `ReassertHide`) + 1 `Subtitle` + 1-2 state reads + 1 `IsInMenuMode`; `CalPoll` +1 every 5th poll only while `timer` is unanswered | **[C :1887-2025]** |
| Papyrus, MANUAL / PENDING (0.5 s) | natives per poll | <= 3 (`EntryCount`, `EntryText(0)`) | **[C :2509-2551, :2866-2898]** |
| Papyrus, one layer read | reads | <= 16 x 2 (head text + topicIndex) + 40 tail + signature = **<= 73**, once per gen | **[C :2069-2150]** |
| Papyrus, the click body | | 0.6 s floor after open, `fLineSettle` 0.4 s, `VkRestore` <= 3 s worst case, no `Utility.Wait` except `SettleAndReport` (<= 16 x `WaitMenuMode(0.25)`, check/pay only) | **[C :2553-2710, :2762-2793]** |
| Papyrus, snapshot | | unchanged (~20 s cadence); P6 reads nothing new (`gold=` is already sent) | |
| server, pre-LLM | ms | `lrgDlgPrepareTurn` **<= 60 ms** (measured 58 incl. Phase 1); P2 adds one `lrgDlgMatchText` on a commit pick (< 0.2 ms); P8/P13 add compares only | **[P 10.17 d]** |
| server, fast path | | `ev=*` 2-7 ms; `want=1` answered pre-lock, D1 in the same reply (~0.5 s round trip) | **[P 10.13, 10.17]** |
| prompt injections on a business turn | chars | `<business>` <= 12 lines (~1,300) + rules (~600), `<real_business>` <= 70 words (~450), `<locked_facts>` <= 600, `<what_just_happened>` <= 5 lines (~450), `<her_list>` <= 400: **<= 3,800 chars worst case (~950 tokens), ~2,200 typical** | **[C :2018-2082, P 10.17]** |
| LLM calls | per player turn | **1**, +1 on ~5 % (never-empty re-ask), +1 per voiced refusal (P4, failures only), **0** on the fast path; the first-contact "again" turn is removed (P9: saves 6-9 s on every no-match first contact) | |
| time to the click | s after the player stops speaking | known list + T-key: model ~6.5 + click body <= 1.5 = **<= 8**; first contact: model 6.5 + open <= 1 + read 0.3 + `want=1` 0.5 + settle <= 1 = **<= 10** | **[P 10.6]** |
| wire | bytes | `lrg_topics` `e=` <= 1,600 + part 2; no new game->server key; server->game adds `adv=`, `give=`, `do=refuse` (all additive after `z=1`) | |

---

## 10. What to REMOVE (and why each is safe to remove)

1. **The automatic calibration CLICK** (`CalAutoClickWanted`, the P8 half of `CalAutoRun`, `CalAutoOther`/`autob`): the
   riskiest automatic act in the project, replaced by the probation click on a line the player asked for (7.2).
2. **`timer` and `route` from the calibration GATE** (`CalMissing`): measurements, not safety (7.1).
3. **The assisted-after-two-losses downgrade** (`losses` -> `$t['assisted']`, the `assisted` rail in `lrgDlgGateItem`,
   `lrgDlgHandBackForeseen`'s assisted branch): hidden permanent state; the watchdog already hands lost layers back.
4. **The first-contact `lrg_dlgtalk` "again" turn** (`Finish` `talkNeeded`; server `lrgDlgOnTalk` admits it): the model
   already answered the same words in the turn that opened the session; the PENDING list is what the NEXT player turn
   sees anyway. Server config `session.talk_again=false` drops it pre-lock (no LLM), no game change needed.
5. **`res=` on the SelectTopic wire**: read by nobody game-side [P 10.4]. Keep the key order (an older script parses
   by position); emit it empty.
6. **`bRewalk` / `iRewalkDepth` and the rewalk counters** (`dRewalks`, `dRewalkAborts`, `kind=rewalk`): off since
   design, never labelled, replays clicks - the opposite of the lens.
7. **`script_proxy_watch`** (latent, never live) and **`quest_colour`** (P17 never passed; CHIM's SWF defines no
   `iQuestColor` [R p2 B7]): dead config.
8. **`auto_advance` / `continuer_words` as dead config**: not removed - BUILT narrowly (P3), because unscripted
   single-entry layers are the one place a wait is pure friction.

Not removed although tempting: the double delivery D1+D2 (the ring handles it and it is what makes the fast path
~0.5 s); the `choice_words` UI fallback (50 % recall, but it only ever makes an UNINDEXED entry stricter); the
two-step itself (P2 reshapes its release, never its park).

---

## 11. Proposals (every one names the file, the wire, the test and the cost)

| # | name | problem | change | files | test | cost | priority |
|---|---|---|---|---|---|---|---|
| P1 | Leave never walks away | `leave` with no back-out entry cancels the session; on a walk-away layer the engine then plays the walk-away INFO - a consequence the driver never reads (survey gap 9.2) | `lrgDlgGateItem` leave branch: if no `class=back` entry and any entry has `walkaway=1` or a `twat` -> `do=show` instead of `pos=-1`; a back entry is still clicked | `server/lorerim_glue/lib/lrg_dialogue.php` (`lrgDlgGateItem`) | `tools/test_dialogue.php` new: TG00 intro layer (twat `TG00BrynjolfWalkAwayTopic`) + leave -> `do=show`; MQ103 innkeeper layer + leave -> `do=leave;pos=-1`; a layer with "Never mind." -> that entry | 0 Papyrus; one loop over the entries | must |
| P2 | Said the line = confirmed | every commit parks, including the 3-4 consecutive single-entry oath lines and MQ106's 14 walk-away rows; the confirming question is asked when the player has just spoken the engine's own sentence | `lrgDlgParkOrRelease`: before parking, release when (a) `lrgDlgMatchText(utter, [e]) >= confirm.said_line_score` (0.70) and >= 4 tokens, or (b) a single-entry commit layer whose parent line (last of `lines[]`) ends with `?` and the utterance is an assent of >= 2 tokens; log `release=said-line|asked-answered` | `lrg_dialogue.php` (`lrgDlgParkOrRelease`, `lrgDlgDefaults` `confirm.said_line_score`, `confirm.assent_words`) | `test_dialogue` new section: the CW01A oath chain replayed line by line (each clicks on the spoken oath), Delphine "I just came here for the horn" on a 3-entry layer parks then releases, Wuunferth single entry releases on "yes we found his amulet" and NOT on "hmm" / silence / the same sentence on a poll; flow `d66_oath` | one `lrgDlgMatchText` on a commit pick (< 0.2 ms) | must |
| P3 | Auto-advance only what the index calls harmless | `auto_advance.grace_seconds` and `continuer_words` exist in `lrgDlgDefaults()` and nothing reads them; every single-entry layer waits 45 s or for a T1 | `lrgDlgAnswerWant`: on a closed layer with ONE visible entry that is indexed, `scripted=0`, `kind=''`, `cost=0`, `crit=0`, not goodbye/walk-away (or a continuer text) -> `do=pick;...;adv=2500` (new additive key, ms) without a match; game `CmdSelectTopic` reads `adv=`, `StepClicking` waits `adv` ms after line-settle and abandons the pick if `NoteSpeech(0)` (the player started speaking) arrived meanwhile (`reqAdvCancel`) | `lrg_dialogue.php` (`lrgDlgAnswerWant`, `lrgDlgAutoAdvanceOk`), `game/.../LRG_Dialogue.psc` (`CmdSelectTopic`, `StepClicking`, `NoteSpeech`), `LRG_Main.psc` version 511 | `test_dialogue`: MS05 "What does that mean?" (unscripted single) -> `adv=`; MQ103 "So what do you need me to do?" (scripted single) -> nothing; an unscripted Goodbye single -> nothing; continuer "Go on." -> `adv=0`; `tools/compile.ps1`; flow `d67_advance` | game: one float compare per CLICKING poll; server 0 | must |
| P4 | Never silent on a server-side refusal | the gate's `null` on afford / amount / words / retry / freeze leaves the player with a line and no reason (8.2) | new verb `do=refuse;why=<code>;note=` emitted where those nulls are today; game `CmdSelectTopic` verb `refuse` -> `XPush`, `ReportResult("Error: <her words>")` -> 10.21 voices it; `lrgVoicedWhy` maps the five codes; server writes `last_result{ok:0, why}` for the next-turn note | `lrg_dialogue.php` (`lrgDlgRefuse`, call sites in `lrgDlgGateItem`, `lrgDlgAnswerWant`, `lrgDlgCheckRails`), `lib/lrg_actions.php` (`lrgVoicedWhy`), `LRG_Dialogue.psc` (`CmdSelectTopic`) | `test_gates`: each code voiced with plain words, the 8 s gap -> next-turn note; `test_dialogue`: an unaffordable room -> `do=refuse;why=afford`; flow `d68_refuse` through the real hook files | one CHIM funcret turn (LLM+TTS) per refusal only | must |
| P5 | Voice on the visible menu (engine-opened sessions) | 7 of 16 quests advance inside forcegreets / blocking branches / scene-paused menus that run assisted; hidden driving of them was decided off and is unmeasured | `iEngineOpen=2` (new default): `StepManual` sends `want=1` for `origin=engine && crit!=2`; `CmdSelectTopic` accepts `pick`/`leave` in MANUAL under that condition; `StepManual` -> `TryResolvePick` -> CLICKING visible (no hide, guard, park, PENDING); server treats such sessions as drivable, no foreseen-assisted clause, `do=show` a no-op; enabled only while calibration `x1 == 1` | `LRG_Dialogue.psc` (`ReadSettings`, `Arm` vis, `StepManual`, `CmdSelectTopic`), `MCM/Config/LoreRimGlue/config.json` + `settings.ini` (iEngineOpen help/default), `lrg_dialogue.php` (`lrgDlgListFor`/`lrgDlgHandBackForeseen`/`lrgDlgAnswerWant` origin handling) | offline: flow replaying the four engine-opened sessions of 2026-09-23 (log 2523/2626/2853/3056) with `want=1` answered and a pick accepted; `test_mcm_wiring`; in game (owner): Irileth at the gate, say "I have news from Helgen about the dragon attack" -> clicked on the visible menu, `clicked pos=` in the log with `origin=engine` | MANUAL polls unchanged (0.5 s, 1-2 natives); one click body per pick | must |
| P6 | The bounded bonus in septims | the owner wants negotiation "within reason"; CHIM's `GiveGoldTo` is unverified and the engine's reward is fixed | 4.2: evidence of a reward (gold delta / objective moved, giver), a `barter` free check that passes, cap = min(named, her day wage, 50 % of the reward, her purse), once per quest; `do=award;give=<n>` (additive) -> `CmdAward` moves from HER inventory only if she has it, else "has no such coin on her" (voiced); locked-fact classes `reward` and `bonus`; different rewards refused in words | `lib/lrg_speech.php` (`lrgDlgBonus`, `lrgDlgCheck` kind barter), `lrg_dialogue.php` (`do=award give=`, `truth.classes` + `reward`/`bonus`, `lrgDlgLockedBlock`), `LRG_Dialogue.psc` (`CmdAward` give branch) | `test_dialogue`: Balgruuf after `ev=result gold=+500` + "can you spare a little more, say a hundred" -> check band, pass -> `give=100` capped at min(100, 500 day wage, 250, purse); fail -> no give, "did not talk you down"; no reward evidence -> nothing + `reward` fact absent; `test_gates`: the voiced "no such coin"; flow `d69_bonus` | 0 LLM; one `RemoveItem` | should |
| P7 | Calibration without an automatic click; probation | two gate rows need a real click body; the automatic click on a shopkeeper's line is the riskiest act in the project | 7.1-7.2: `CalMissing` drops timer/route (8 rows); `CalAutoClickWanted` false; `CalAutoRun` keeps the mash window (guard) and drops the click; `StepResponding` verified click writes `route` (`CalSet("route", route, "live")`); server probation rail: while `cal.route == 0` only plain/unscripted/unpriced/no-kind picks, else `do=show` | `LRG_DlgProbe.psc` (`CalMissing`, `CalAnswered`, `CalAutoClickWanted`, `CalAutoRun`, `CalStatusText`), `LRG_Dialogue.psc` (`StepResponding`/`SettleAndReport`, `HandBack` "N of 8"), `lrg_dialogue.php` (`lrgDlgProbation` in `lrgDlgGateItem`/`lrgDlgAnswerWant`, `lrgDlgLearningText`), `config.json` help texts | `test_mcm_wiring`; `test_dialogue` s15 updated (no click pick on a calib session; probation shows a commit, clicks a plain); flow `d64_auto_calib` updated; `test_prompt_index` unchanged | removes a 3 s + 5 s click body; 0 per turn | must |
| P8 | The two candidates must agree | on `want=1` the model's `ask=` can out-score the player's words on a different entry | `lrgDlgAnswerWant`: when both clear 0.55 on different indices -> no click, log `ambiguous model=<i> player=<j>`; the same in `lrgDlgBusinessMarker` is not needed (it only opens) | `lrg_dialogue.php` (`lrgDlgAnswerWant`) | `test_dialogue`: ask="rent a room", utter="what's the news" on an inn root -> nothing; both on the same entry -> pick | 0 | must |
| P9 | Drop the loss downgrade and the "again" turn | hidden permanent assisted state; a redundant paid LLM turn on every no-match first contact (6-9 s) | `lrgDlgOnClosed` keeps `losses` for the log; `$t['assisted']` no longer derived; `session.talk_again=false` -> `lrgDlgOnTalk` returns `handled` pre-lock; `Finish` unchanged (the request is dropped server-side) | `lrg_dialogue.php` (`lrgDlgPrepareTurn`, `lrgDlgGateItem`, `lrgDlgHandBackForeseen`, `lrgDlgOnTalk`, defaults) | `test_dialogue`: two losses -> still a pick on a closed layer; `test_gates`: `lrg_dlgtalk` dropped with no LLM; flow `13`/`16` updated | saves one LLM turn per no-match first contact | should |
| P10 | Dead weight | `res=`, rewalk, `script_proxy_watch`, `quest_colour`, `bRewalk`/`iRewalkDepth` | remove per section 10 (5-7); keep `res=` as an empty positional key | `lrg_dialogue.php`, `LRG_Dialogue.psc` (`dRewalks`, `kind=rewalk`), `config.json` (two controls), `test_mcm_wiring` | `test_mcm_wiring` (every id read somewhere); flow harness `fxDlgOrderOk` unchanged | negative | should |
| P13 | A scripted entry needs a shared word | `similar_text*0.8` clears 0.55 on short strings with no meaning overlap; fine for a question, not for a fragment | in the similarity branch of `lrgDlgGateItem` and `lrgDlgAnswerWant`: an indexed `scripted=1` entry executes only when word-F1 > 0 | `lrg_dialogue.php` | `test_dialogue`: "uh what now" vs "So what do you need me to do?" -> nothing; "what do you need me to do" -> pick | 0 | must |
| P14 | Count the scene-bound beats | the index has no per-row "reachable only inside a scene" flag (survey gap 9.5); the next design round needs the real number | `ev=calib k=` gains `eo:<n>,eos:<n>` (engine-opened sessions, of which in-scene) from `CalClose`; the server logs them on the CALIB line | `LRG_DlgProbe.psc` (`CalClose`), `lrg_dialogue.php` (`lrgDlgOnCalib` log) | `test_dialogue` calib parse; `test_gates` W1 shape | 0 | later |

Script version: P3, P4, P5, P6, P7 change `.psc` files -> `LRG_Main.psc:36` CurrentVersion 510 -> 511, one bump for
the bundle. Server catalog version stays 11 (no new catalog row: `refuse` rides the SelectTopic row, `give=` the award
carrier).

---

## 12. What cannot be done (stated plainly)

1. **Helgen / the Alternate Perspective start** - scene dialogue with no player prompt; MQ101 has 2 rows (the keep door).
   Not menuless, and no design makes it so.
2. **Hidden driving of engine-opened sessions** - not in v1.0. P5 gives voice on the VISIBLE menu, gated on `x1 == 1`;
   if voice input kills the menu on this install, those beats are clicked by hand, and the owner is told so in the
   log line (`visible=assisted`) and the corner note.
3. **The map table** - Tullius and Rikke are ambient-scene actors: no open, ever (both sides refuse). Only the
   scripted-entry table moves CW00A, only after Helgen (MQ101 900), only for Tullius; Rikke's stage 20 and every
   Stormcloak line are words until decoded the same way.
4. **A different reward** - items, quest variants, "double it": impossible without inventing. A bounded septim tip from
   her own purse after a real check is the whole of what is honest (P6).
5. **Conditions the engine keeps closed** - a skill gate, a bounty, `MQQuickstart`, Say-Once: the entry is not listed,
   she says why in words, nothing is bypassed.
6. **Empty-response INFOs (429) and no-signal clicks** - verification by menu state and timers, 4-20 s; a genuinely
   silent click reports `unverified` and hands back rather than claiming success.
7. **Mods outside the index** - the UI-only fallback is conservative (new closed-layer entries are commits; no
   auto-advance); `unindexed` in the log is the to-do list for the builder.
8. **The lethal class** - an arrest, a fine, jail, resist: the visible menu at every setting; `ForgiveCrime` never.

---

## 13. Test plan (offline first; the owner plays last)

- Offline, every proposal above names its `tools/test_dialogue.php` / `test_gates.php` section and flow scenario; the
  bundle must keep `test_dialogue`, `test_gates`, `test_mcm_wiring`, `test_prompt_index` and all flows green on the WSL
  copy (`C:\Users\Jordan\AppData\Local\Temp\lrg_test\`), `compile.ps1` clean (no .pex string > 500 chars, no None
  cast to a typed array).
- Replays that must pass before a single evening is spent: the CW01A oath chain (P2), the four engine-opened sessions
  of 2026-09-23 (P5), Tullius 23:19:29 (10.26 unchanged: the Helgen line), the innkeeper calibration session with NO
  click (P7), an unaffordable room (P4).
- The owner's evening, in order: (1) one ordinary E-press conversation with an innkeeper (passive rows), (2) the
  automatic open measures the guard (mash window, no click; corner note), (3) "still learning (N of 8)" turns green
  and the dry run clears with Home bound, (4) Riverwood: "Do you have any supplies I could take?" to Alvor - the first
  live click, on a harmless line, ends probation, (5) Whiterun gate: Irileth by voice on the visible menu, (6)
  Kodlak: "I would like to join the Companions" clicks on the first turn. He reports symptoms; every symptom has a
  log line named in this note.
