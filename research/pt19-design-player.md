# pt19 - MENULESS QUESTING v1.0, designed from the PLAYER'S seat (designer notes, read-only)

2026-09-24. Lens: **player experience and flexibility** - the owner talks naturally, paraphrases, negotiates a
reward in septims within reason, never touches the menu for the main questline and the guild openings, and what
he hears is always true and never silent. Nothing in the glue was edited. Sources, in order: `research/pt19-design-survey.md`
(the map; section numbers below refer to it), `glue/PROTOCOL.md` 10.13-10.26, `glue/V05_EXPANSION_PLAN.md` E1-E8,
`glue/OWNER_ADDENDA.md`, `research/dialogue-engine.md` 1-3 + verdict, `research/p2-quest-branching-speech.md`,
`research/pt18-quest.md`, `research/pt18-calibration.md`; code only where a question needed it (cited as file:function);
the live index (`prompt_index.ndjson`, 37,561 rows / 5,718 layers, hash e756e311) through three read-only scripts in
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\` (`layers16.py`, `chains16b.py`, `gaps16.py`, `db02.py`).
Marks: **[X]** live index, **[C]** code, **[P]** PROTOCOL, **[R]** research, **[I]** inference. Currency: septims.

---

## 0. The promise, and the five principles it is built on

The promise to the owner, in his words: *"read the dialogue options beforehand and integrate it into CHIM"* - he
talks, the game's own dialogue happens. Everything below is judged by five principles, in priority order:

1. **The engine executes; the glue only chooses.** No `Say`/`SayTo`, no `SetStage` except the one decoded
   click-free entry (10.26), no invented effect. The real click is the execution primitive (dialogue-engine verdict).
2. **Never silent, never false.** Every refusal is voiced with the real reason (10.21); every claim of an outcome
   comes from the game (10.17, 10.25). A bonus in septims is real gold that really moved, or it is not promised.
3. **Ask once, only when the click can hurt.** A confirmation exists to stop a WRONG irreversible click, not to
   make the player say everything twice. The shipped two-step fires on 867 `crit1` rows and on every cosmetic
   script; in Delphine's secret room that is fourteen "do you mean it?"s **[X]**. v1.0 grades by what the
   fragment DOES and by how explicit the player already was.
4. **Visible before hidden.** A voice-driven click on a menu the player can SEE is safe on day one and needs
   half the calibration; hiding the menu is the polish, gated separately. The first live clicks happen where the
   owner can watch them.
5. **Remove before adding.** Every proposal names what it retires. The automatic calibration session, the
   walk-away-as-commit grade, the always-park, the rewalk counters and the per-session "lost forever" rule go.

---

## 1. What the player lives through today, beat by beat (the honest baseline)

From the survey (1.1-1.7, 7) and the code read for this note:

| Beat | Today | The owner's likely report |
|---|---|---|
| "I want to join the Companions" at Kodlak (free-standing, TL, S G) | LLM turn answers in words + `do=open` -> hidden session -> `want=1` hits the exact join line but it is `commit` -> never executed from intent mode -> PENDING (corner note) -> he repeats himself -> LLM turn picks T1 `[commits]` -> PARKED, her question -> he says "yes" (one token, refused) -> "yes I do" -> click. **Three to four utterances.** | "she keeps asking me if I'm sure" |
| Delphine's secret room (MQ106, 14 `crit1` rows, C2Horn layer 7 entries / 4 scripted) | every question with a cosmetic script or a walk-away flag parks first | "she asks if I mean it when I ask why she took the horn" |
| The Legion oath (CW01A: Yes -> Oath1 -> Oath2 -> Oath3 -> Oath4, four single-entry layers) **[X chains16b]** | each single-entry layer waits PENDING 45 s or for a T1 pick; Oath1-3 are `crit1` = commit = park; the oath takes ~8 player turns | "the oath got stuck" |
| Irileth at the door, Balgruuf's court, Farengar's assignment, Aventus, Brynjolf, Astrid, Galmar (7 of 16 quests: engine-opened forcegreets / blocking branches) | `iEngineOpen=0`: ASSISTED, the vanilla menu shows, the glue only reads; a voice pick answers `Error: that is not on the table right now` | "the menu came up and she ignored what I said" |
| The first live click ever | needs 10 calibration rows green, the emergency key bound, MCM Helper writing, or the override; the automatic calibration session needs an idle innkeeper turn, `gopen>0`, a safe entry | "it says still learning 0 of 10 every night" |
| "I killed the dragon, I deserve more than this" | words only; the model may invent a purse, or `GiveGoldTo` may mint gold from an NPC who carries none (`AIAgentAIMind.ConfirmMoveInventoryItem`: `source.RemoveItem` then `target.AddItem(amount)` - AddItem adds the full amount whatever RemoveItem found) | a false promise, or free gold |

---

## 2. CONFIRMATION POLICY - when the glue clicks straight away, asks one question, or refuses

Three grades replace the shipped two (`commit` / not). The grade is computed per entry in `lrgDlgDecorateEntries`
and read by `lrgDlgGateItem` and `lrgDlgAnswerWant`; the wire and the game do not change for this.

### 2.1 Grade A - CLICK NOW (no question)

An entry is clicked on the player's first statement when ALL hold:
- class `plain` or `service` (or `pay` with `afford=1` and cost below the confirm floor), `kind=''`;
- **its fragment has no world effect** - new index field `fx` (2.4) is `none`, i.e. no VMAD, or a fragment whose
  string table names none of `SetStage SetObjectiveCompleted SetObjectiveFailed CompleteQuest FailAllObjectives
  Stop Start StartCombat Kill RemoveItem AddItem SendStoryEvent ModCrimeGold SetBribed SetIntimidated`;
- OR it is a **single-entry layer** (n=1) whose entry has `fx=none` (auto-advance, section 7);
- the session is not LETHAL / arrest-class; the layer is not a price list without a named slot (10.15).

Walk-away (`W`) and `twat` **no longer make an entry a commit**. The ENAM 0x0080 flag means "backing out of the
NEXT list plays the walk-away topic" (dialogue-engine 6, limit 3): it guards LEAVING, not clicking. It becomes a
**leave guard** on the layer it opens (2.5). This alone takes the walk-away grade off all 10 of TG00's intro lines, all 14 of
MQ106's `crit1` rows, the seven oath lines of CW01A/CW01B and Balgruuf's "The Greybeards?" **[X]**; what stays a
commit among them is then decided by `fx` alone (Delphine's two walk-outs A3/C5 stay, as `twat` targets).

### 2.2 Grade B - ASK ONCE, unless he was already explicit

An entry is a **real commit** when ANY holds (first hit, `lrgDlgIsCommit` rewritten):
- override `never_auto`; a `meta_tags` / `commit_tags` tag ((attack), (brawl), (go to jail), (remain silent),
  romance switches, (skip quest), (fail quest));
- `fx` in {stage, quest, combat, item, crime} AND (`goodbye`, or a closed layer where >= 2 siblings carry an
  effect - mutually exclusive outcomes);
- a walk-OUT line: the row IS a `twat` target of a sibling (Delphine's "I don't have time for this", Brynjolf's
  "No, never mind", Rikke's "I'm not sure about this" - the index knows them: `twat` values are topic EditorIDs);
- cost >= `confirm.min_gold` (100) or >= 25 % of the purse; a follower `dismiss` / `home` verb;
- unindexed: a `new` entry on a closed layer, or a `choice_words` hit (unchanged - the index is the authority,
  words the fallback).

For a real commit the two-step of `lrgDlgParkOrRelease` stays - BUT with p2 5.3's rule restored: **the player's
explicit statement IS step one**. Release without a park when, on a player-speech turn, the player's OWN
utterance (never the model's item) scores >= `explicit.min_score` 0.70 with margin >= `explicit.min_margin` 0.25
against the visible layer (`lrgDlgMatchText`) for THIS entry, or is an exact faction join line / exact slot,
AND the entry is not: lethal, arrest-class, `meta`, `never_auto`, a scoff-first failure variant, a follower
`dismiss`/`home`, or above the gold floor. Those always ask. Examples on the real rows **[X]**:

| He says | Layer | Result |
|---|---|---|
| "I want to join the Companions" | Kodlak TL (`fx=stage`, G) | exact faction line -> **click now**; Kodlak's own reply "Would you now? Let me have a look at you" is the game's confirmation |
| "I'd like the sword" | Eorlund's 5-way weapon choice (all `fx=item`, G) | 0.85 containment, margin > 0.25 -> **click now** |
| "Olaf was Numinex, a dragon in human form" | MS05 verse layer (persuade success variant) | check rails apply (>= 4 words, retry suppression); the row's `fx` is cosmetic -> plain check click |
| "we found his amulet in Hjerim" | MS11's single scripted entry after "I assume you have proof?" (`fx=stage`, G) | single-entry effect row: **waits for words**, then executes on his assent - his SECOND statement, never a timeout (section 7) |
| "kill him" (STT, one token) to Kematu | MS08 layer 3 (two `fx=stage` G siblings) | one token never releases; she asks; "yes, I'll do it" releases |
| "I'll pay the fine" to a guard with a bounty | arrest-class | refused at every setting - the menu is handed back visible (unchanged) |

Second change to the release: a **single-token assent** (`yes aye yeah yep deal agreed do it`) releases a live
park when the parking turn's message was non-empty (she really asked) and the park is younger than
`confirm.park_seconds`; `no / never mind / not now / stop` still un-park. The 2-token rule stays for every other
word. STT risk accepted: "yes" is the best-recognised word there is, and the park expires in 60 s.

### 2.3 Grade C - REFUSE and hand back visible (unchanged, listed for completeness)

`crit==2` rows (the 12 `DGCrimeResistArrest` rows), a guard with a bounty, the "I know you..." family, any
resist-arrest entry, a scene that starts inside a session, `iBranchInput=2`, the calibration's `x1==2`. The
foreseen-hand-back clause makes her SAY "choose this one yourself" (E3). No change; the corner note stays.

### 2.4 The index field that makes 2.1 and 2.2 honest: `fx`

`tools/build_prompt_index.py` already records `scripted` (VMAD present, 14,838 rows). It gains `fx`: the
fragment script named in the INFO's VMAD (`TIF__xxxxxxxx` or a mod name) is located (loose `Scripts\*.pex`
by MO2 priority, then the plugin's BSA - exactly what `research/pt18-quest.md` 2a did by hand with `pex.py` for
`tif__000d5136.pex`), its string table is read, and `fx` = the first matching class of
`stage` (SetStage / SetObjectiveCompleted / SetObjectiveFailed / SetObjectiveDisplayed / CompleteQuest / FailAllObjectives) ·
`quest` (Start / Stop / SetActive / SendStoryEvent / StartScene) · `combat` (StartCombat / Kill / AddToFaction / SetAlly / SetEnemy) ·
`item` (AddItem / RemoveItem / EquipItem) · `crime` (ModCrimeGold / SetBribed / SetIntimidated / SendAssaultAlarm) · `cosmetic`
(a script with none of them: Delphine's "asked" variables, SayOnce bookkeeping) · `none` (no VMAD) · `unknown`
(pex not found - graded as `stage`, the safe side). Smart Talk does the same statically (`SmartTalk.ini`
`iPapyrusFilterMode=2`), which is the proof it is practical **[R p2 7.4]**. Cost: one-off at build time - ~15k
small files, measured budget +20-30 s on the 33.4 s build. `LRG_PROMPT_INDEX_VERSION` stays 1 (an added key;
readers default `fx='unknown'` when absent, which is today's behaviour).

Measured on the 16 quests **[X layers16]**: of the 39 closed layers with >= 2 scripted siblings that the
sibling rule grades commit today, at least 11 are conversation questions whose scripts are cosmetic
(MQ106 C2Horn / A3 / A4, MQ102 Helgen A0/A1, MQ105 Intro, MG01 Faralda's eight "what do you seek" answers).
The exact split is what `test_prompt_index.php` 5d will print once `fx` exists.

### 2.5 The leave guard (what `W` really means)

Layer state `guard=1` when the row that opened it was `W`-flagged or names a `twat` (session `path`, already
kept, <= 8). While `guard=1`: `do=leave` with `pos=-1` (the engine cancel that plays the walk-away topic) is
never emitted from intent mode; a model `leave` maps to a listed back-out entry (class `back`) if one exists,
else it PARKS as a commit ("you're walking out on her?" is the message) and releases on a second, different
utterance; silence still ends in the 30 s watchdog hand-back (the menu visible - the player decides).
`BeginLeave()` needs nothing new: the server simply never sends `pos=-1` on a guarded layer.

---

## 3. MATCHER AND MODEL-ITEM POLICY - paraphrases, ambiguity, trust

Who decides what, in order (unchanged order, three changes marked NEW):

1. **The model's T-key** on THIS turn's offer (pos + norm verified, `gen` verified game-side) is trusted for
   Grade A entries. It is the paraphrase engine: it sees the entries verbatim and the player's words; the
   `<business>` rule "one key ONLY when his last words clearly do that thing" stays. NEW: for Grade B the T-key
   is necessary but not sufficient - the explicitness test (2.2) reads the PLAYER's words, so the model cannot
   commit the player on its own initiative; without explicitness it parks as today.
2. **The model's free-text item** goes through the arbitration ladder unchanged: follower verbs (exact) ->
   faction (exact line / topic + join word) -> slots (exact, longest wins, negation, price question) ->
   `lrgDlgMatchText` 0.55 / 0.15, plain/service only. NEW: on a **single-entry layer** the free-text item is
   accepted when it scores >= 0.5 against the one entry (there is nothing to disambiguate) - it is the words
   test of section 7, not a new matcher.
3. **The fast path `want=1`** (no LLM) stays at 0.55 / 0.15 over `ask=` and the utterance, plain/service
   (+pay on continuation), and NEW may execute a Grade B entry ONLY through the explicitness test on the
   player's OWN utterance (never `ask=`), which is what makes Kodlak one utterance. Everything consequential
   that is not explicit stays "never from intent mode".
4. **Ambiguity is a question, never a guess** (unchanged): two keys fit -> the model asks; two slots -> `ask`;
   matcher margin < 0.15 -> words. NEW wording in `<business>`: the model is told which two it is torn
   between only when the server saw two candidates within 0.10 of each other (`lrgDlgMatchText` already
   returns the margin) - it saves a turn of "which do you mean?".
5. **STT**: `inputtext_s` keeps the >= 4-word rule for checks, the >= 2-token rule for releases (except the
   assent list, 2.2). Typed input is not special-cased any more (`confirm.typed_skips` is unread - removed).
6. **When the list is stale**: the game's `gen` check and the 40-char `txt=` prefix stay the only content
   checks; a mismatch re-reads twice then "that moment has passed" (voiced as "I lost the thread - say it
   again", 9).

What is deliberately NOT built: a synonym table, a second judge call, trigram indexes. The model is the
paraphraser; the matcher only guards the fast path and the model's free text.

---

## 4. NEGOTIATION POLICY - "a different reward, a reward in septims, within reason"

### 4.1 Facts that bound it

- The engine's reward is fixed by the quest fragment; the glue never changes it (principle 1). In the 16 quests the
  reward lines are real entries: MQ102 "What else can I help you with?" (S G O, Balgruuf's "small token"),
  MQ104 "What about my reward?" (S G: Axe of Whiterun / thane), MQ104 "I killed the dragon. I think I deserve a
  reward" (plain question), MQ103 "So what about my reward?" (redirect to the jarl), C00 Eorlund's weapon
  CHOICE (5 scripted siblings - a real negotiation the game itself offers), MS05's poem (the gold depends on the
  verses), DB01 Aventus's heirloom plate, TG00 Brynjolf's job **[X]**.
- CHIM can move gold three ways and all three are dangerous here: `GiveGoldTo` (`MoveInventoryItem` ->
  `requestMoveInventoryItemConfirmation` -> `ConfirmMoveInventoryItem`: `source.RemoveItem(gold, n)` then
  `target.AddItem(gold, n)` - AddItem is unconditional, so an NPC with 20 septims "gives" 200), `SpawnGold`,
  `GiveItemTo` / `SpawnItem` (`functions.php` action names) **[C]**. The glue's `hide_gold` hides
  `GiveGoldTo`/`TakeGoldFromPlayer` only while a priced entry is listed.
- The glue already has a verified gold mover: `do=award` -> `LRG_Dialogue.CmdAward` (`take=` moves player ->
  NPC after `GetItemCount` check; `xp=1` = `AdvanceSkill("Speechcraft", SpeechSkillMult x Speech)`) **[C]**.
- The NPC's pocket gold is already on the snapshot (`LRG_Profile.psc:457` `;gold=<npc.GetGoldAmount()>`) **[C]**.
- Wage tiers (`paid_intimacy.wage_tiers`, day = 8 h): common 40, performer 64, trade 100, fighter 132,
  craft/magic 200, court 500 septims a day (addendum 12 e-f) **[C lrg_config.default.json:168]**.

### 4.2 The decision: DO IT, through the glue's own verified path, never through CHIM's minting actions

**A bonus in septims is real when, and only when, all of these hold** (server, `lib/lrg_speech.php`, a new
`lrgDlgRewardTalk()` beside `lrgDlgCheck`):

1. **A reward window is open**: within `checks.reward.window_seconds` (180) after (a) a driven click whose
   index row is a reward line (topic matching `*Reward*`, or `resp` containing `reward|take this|token of|for
   your trouble|here, take`), or (b) a `<what_just_happened>` "The matter moved on" for a quest this NPC is an
   alias of (`qal`/`qgiver`), or (c) `ev=result` reporting gold INTO the purse. Outside the window a request for
   more is ordinary talk - she may banter, and the never-false judge (4.4) keeps her from promising.
2. **He asked**: `lrgDlgCheckKind` finds `persuade` (or `deceive`) with the new stakes class
   `reward` (words: `more, extra, bonus, on top, sweeten, deserve more, worth more, better reward, in septims,
   in gold, in coin, instead, pay me`), >= 4 words on voice; a named amount is parsed by `lrgDlgNamedAmount`.
3. **A real Speech check decides**, exactly the free-check machinery: band = `reward` (2) + stance
   (jarl/court +2 -> Very Hard, merchant/trade +1 -> Hard, commoner 0 -> Average, tavern folk -1 -> Easy)
   + `bias`; threshold read LIVE from `sg=`; the amulet passes; memory per NPC (86,400 s) so the same trick does
   not work twice; a failed intimidate-flavoured ask is still just persuade (no hostility: `checks.hostility` off).
4. **The cap is truthful**: `cap = min(npc_gold (snapshot, <= 20 s old), days x her day wage, checks.reward.max_gold 500)`,
   `days` by the band passed: Easy 1, Average 2, Hard 3, Very Hard 4. Named amount A: `N = min(A, cap)`;
   no amount: `N = round5(cap / 2)`. `npc_gold == 0` -> no check is even run: she says she carries no coin
   (directive), and NOTHING is promised for later (there is no later-payment mechanism, so it would be false).
5. **The gold moves through `do=award`** with a new additive key `give=<N>` after `z=1`: `CmdAward` moves
   `min(N, npc.GetGoldAmount())` from her inventory to the player's (`npc.RemoveItem(goldForm, n, true, player)`),
   grants XP as a persuade success, answers `OK: gave <n> septims` (the funcret carries the ACTUAL number). A
   short-fall (her purse changed since the snapshot) is logged and told on the next turn ("she found she had
   only 20") - rare, honest.
6. **While the window is open** `hide_gold` is unconditional and extended: `GiveGoldTo, SpawnGold, SpawnItem,
   GiveItemTo, TakeGoldFromPlayer` are off the table, and `lrgDlgTruthCheck` drops any surviving gold/item action
   (belt and braces). Gold moves once, through the verified path, or not at all.

**What she says.** Pre-LLM, the check has run (as every free check does), so the directive tells the model the
outcome as fact - `<reward_talk>` (<= 350 chars, inside the window only):
- pass: "He asked for more and won you over: you give him exactly N septims on top - the game moves it; say so in
  one line, name N, promise nothing else."
- fail: "He asked for more and did not move you; the game moved nothing. Refuse in your own voice; do not soften
  it later unless something about him changes."
- no coin: "You carry no coin to give. Say so plainly. Never promise to pay later."
- a DIFFERENT reward (an item, land, a title, a favour): "What you give for this is fixed by what you have already
  given. You cannot hand over anything else or promise it; say so in your own words." - and the item actions
  stay hidden. Honest limit: item rewards are NOT in v1.0. The one real "different reward" the game offers -
  Eorlund's weapon choice - IS negotiable, through the real entries, and Grade B explicit release makes "I'd
  like the sword" one utterance.
- Jarls: the tier is court (500/day) but Balgruuf's pocket is what he has on him - typically nothing or a few
  septims **[I]**; the directive then says "no coin". Truthful and a little funny, which is fine.

Ground truth next turn: `<what_just_happened>` gains "You gave him N septims on top of his reward" from the
funcret's `gave=` (the existing gold-delta line already says "N septims changed hands").

### 4.3 What is refused, and why it is said out loud

| He says | She says (rules, never lines) | Because |
|---|---|---|
| "I want double" to Balgruuf right after the axe | the pass/fail outcome, N capped by his pocket | the check + the cap |
| "give me a house instead" | fixed reward, nothing else to give | no item path in v1.0 |
| "pay me later" | never a later payment | no mechanism = would be false |
| "more" to a guard / Vigilant / jarl below Very Hard | fail (immune list) | `checks.immune` |
| "more" a second time | "she has heard this from him before" | memory |

### 4.4 Never-false, extended by one class

`lib/lrg_replies.php` never-false judges faction turns only. v1.0 adds a `reward` class on reward-window turns:
a sentence that PROMISES gold/items/land/titles ("I'll give you", "you shall have", "take these", "a house in")
without a verified `give=` in the same turn is muted (mode `mute`, the pick survives) and floored ("What I gave
is what I have to give."). Cost: a handful of regexes on <= 180 s of turns per reward.

---

## 5. COVERAGE - the main questline and the guild openings, line by line

Paths: **A** click now (Grade A) · **B** ask once / explicit release (Grade B) · **K** engine check (rails) ·
**1** single-entry rule (section 7) · **V** visible-driven engine-opened session (section 6, NEW) · **H**
hidden glue-opened session (today's path) · **E** click-free quest entry (10.26) · **S** service/priced ·
**X** cannot be done by voice (says so). "Speaker in a scene" is the survey's [I] where marked.

| Quest | Beat (index row) | Path | Note |
|---|---|---|---|
| MQ101 Unbound | everything | **X** | scene dialogue with no player prompt; on this save AP owns the start. Nothing to drive. |
| MQ102 Before the Storm | Riverwood: Alvor/Gerdur help hub (TL S), Hadvar/Ralof exit (sub S) | **V** + A/1 | forcegreet at the exit scene [I] |
| | Irileth at the door: `IrilethForcegreetTopic` -> A1/B1 (S G) / B2 (I) -> A2 (S) | **V** + A | forcegreet; A1/B1/B2 `fx` expected cosmetic-or-stage: B1 "A dragon has destroyed Helgen" is Grade A if `fx=stage`+G? -> **B** explicit ("a dragon destroyed Helgen" scores 0.85) - one utterance either way |
| | Balgruuf: `BalgruufIntroTopic` (TL, 5 stage variants) -> HelgenB1/B2/B3 (S G) | **V** + A/B-explicit | the court scene [I]; the three Helgen statements are natural to say verbatim |
| | `BalgruufReward` "What else can I help you with?" (S G O, single entry after the intro) | **1** effect-wait | he says anything that assents -> click; then the reward window (4.2) opens |
| MQ103 Bleak Falls Barrow | `BalgruufBlockingTopic` "I need to talk to you" (5 variants, DBFB) | **V** + A | blocking branch, mid-scene |
| | Farengar intro (sub, 2, one EMPTY response) -> A1/A2/A3 (I) -> `IntroB1` "So what do you need me to do?" (S, single) | **V** + **1** | the assignment: single effect entry waits for his words ("what do you need?" scores > 0.5) |
| | `FarengarRetrieveBookTopic` "I have the stone tablet you wanted" (TL S) + `IntroHaveStone` (S, single) | **H** + A / 1 | turn-in; mid-scene with Delphine [I] -> V |
| | Alchemy/Enchanting/Magic flavour (I) | A | skill-gated; the engine shows them or not |
| MQ104 Dragon Rising | `IrilethBlockingTopic` "What are your orders?" (5 variants) | **V** + A | gate and tower, blocking |
| | `IrilethIntroA1` "I'll come along" / A2 "I'd rather scout ahead" (S, 2 siblings) | **V** + B-explicit | both `fx` expected `stage`; "I'll come with you" 0.7+ -> click now |
| | `MQ104ADragonbornA1` -> B1/B2 (G) guards after the kill | V + A | scene |
| | `BDragonDeadTopic` "The dragon is dead" (TL plain) | H/V + A | |
| | Outro A1-A3 (questions) -> B1 (S) -> B2 (`crit1 W`) -> C1 "The Greybeards?" (`crit1 W`, single) -> D1 (S G, single) | A, then **1** x2 | W no longer parks; C1 single unscripted auto-advances; D1 effect waits for words |
| | `BalgruufIntroA1` "What about my reward?" (S G) | A/B + reward window | Grade B only if `fx=stage`+G: explicit "what about my reward" is 1.0 -> click now |
| MQ105 The Way of the Voice | `ArngeirIntroTopic` (TL, 4 variants incl. `crit1 W`) | **V** + A | summons forcegreet |
| | IntroA1/A2 (S GO), B1-B3A1 (questions), B4 "I'm ready to learn" (S G) | A / B-explicit | courtyard scenes [I] -> V |
| | `HornBlockingTopic` "I'm ready for more training" (S, GORE.esp winner, `crit1 OW`) -> HornA2 (S, single) | V + A + 1 | |
| | `ReturnHornTopic` "I have the Horn of Jurgen Windcaller" (TL S G) | H/V + B-explicit | says the line -> click |
| MQ106 Horn of Jurgen Windcaller | `RentRoomTopic` "I'd like to rent the attic room" (TL S, cost -1 token) | **H** + **S** | priced by the live text; `afford`; below the 100 floor -> Grade A pay |
| | `DelphineIntroTopic` "What do you want?" (4 variants) -> A0 (S G, single) | H + A + 1 | she walks you to the room (scene) [I] -> V for the room |
| | secret room: A1-A4, B1/B2 (S I), B3 (`crit1 W`), Exclusive A1/A2 (I), C1-C5, DragonbornA1-A3, EndTopic, EndA1-A3 | **V** + **A** (was 14 x B) | `W` = leave guard; the two real commits stay B: ExclusiveA3 / C5 walk-outs (`twat` targets, S G) |
| | `IntroEndA1` "I know that mound..." (S) / EndA2 "Let's go kill a dragon" (G) / EndA3 "Hold on" (G) | B-explicit / A | |
| | Kynesgrove: Iddra (I, B1 S G single), `DragonAtttackTopic` (7 stage variants) | V + 1 / A | scenes |
| C00 Take Up Arms | `KodlakJoinUpStartTopic` "I would like to join the Companions" (TL S G, free-standing) | **H** + **B-explicit = click now** | the ONE clean join; his "I want to join" is the exact faction line |
| | Kodlak's layer: DontWorry / TeachMe / Offended (S G x3) | B-explicit | "I can handle myself" 1.0 |
| | Vilkas train (TL) / Skip; Eorlund intro (sub S `crit1 W`) -> HappyToHelp / WaitASec / hierarchy questions (`crit1 W`) | V/H + A | W no longer parks |
| | `AelaIhaveYourShieldTopic` (TL S G) | H + A/B-explicit | |
| | Eorlund weapon choice (5 x S G, `fx=item`) | **B-explicit** | "the sword" -> click now; "a weapon" -> she asks which |
| | `CRNoWorkBranchTopic` "Can I join the Companions?" (redirect variants) | A + faction redirect | |
| MG01 First Lessons | `InitialBranchTopic` (26 NPC variants) | A | |
| | Faralda (free-standing): "May I enter the College?" -> 8 "what do you seek" (S I, cosmetic) -> `EntryBranchIntro` -> `EntryPersuade` (ISD success variant) / `EntryTakeTest` (S) | **H** + A + **K** | the persuade: >= 4 words, scoff-first if the FAILURE variant is shown |
| | `DontKnowSpellTopic` (10 spells), Nirya `SellSpell` cost 30 (S G x5) | A / **S** pay | 30 < 100 floor -> Grade A pay if afford |
| | Mirabelle Stage30 (TL S) -> TakeTour (S G) / Skip / NotYet | A / B-explicit | |
| | Tolfdir's class (TL `crit1 W` x2 -> Response1-3 S G / Ward responses) | **V** + A | a SCENE blocking branch |
| TG00 A Chance Arrangement | Brynjolf's approach: IntroBranch01-06 (all `crit1 W`, unscripted) | **V** + **A** + **1** x3 | forcegreet; the chain 02a->03a->04->06 is single-entry unscripted: auto-advance with the grace, or his words |
| | Branch07 "Break the law? Are you kidding?" (S G) / 08 "No, never mind" (G, `twat` target) / 09 (S) | B (walk-outs) / A | |
| | `IntroMQ203C1` persuade success variant | **K** | |
| | `ReadyToStartBranchTopic` "I'm ready. Let's get this started" (TL S G) | H + B-explicit | |
| | Outro01-04 (S G) | B-explicit | |
| DB01 Innocence Lost | Aventus (Black Sacrament forcegreet scene [I]): QuestBranch (TL S) -> Response1/2 -> Response4 "contract" (S, single) | **V** + A + 1 | |
| | Payment / LiveAlone / YouSure (TL plain) | A | |
| | Grelod threaten (S G x2), prank/push/attack/take-you-there (S G) | B (fx=combat/stage) | one question each, explicit release |
| | ILQE guard route: `ICQEGuardMaybeTopic` (TL) -> Persuade_Success/Fail, Bribe_Yes/No (cost -1 = `bamt`), Thane (S G), NeverMind | **K** persuade / **K** bribe / B | a guard with bounty 0 is NOT arrest-class (survey gap 9.6 - to be confirmed in the log on this route) |
| DB02 With Friends Like These | captives: WhoAreYou (TL x3), PayToKill (TL x3) -> Intimidate/Persuade (S, tag-only, always succeed) | **V** + A | Astrid's shack forcegreet [I]; her rows are indexed under quest `DarkBrotherhood` (`DBAstridSleepGreetBranchTopic` "Who are you?" `crit1 W`, `DBAstridPlayerWhereTopic`, `DBAstridAdmirerTopic`) **[X db02]** - the survey's "not indexed" gap is a census-key artefact, not an index gap |
| MS05 Tending the Flames | Viarmo application (sub, from his greeting) -> Application2 (S O, single) -> Task2 (S, single) | H/V + A + 1 x2 | |
| | `GivePoemTopic` (TL S, 4 variants) -> Inspection (single) -> NewPoemIdea (S, single) | A + 1 | |
| | verse layers: Verse2Evil (persuade success/failure) / Incompetent / Underhanded (S `crit1 W`); Verse4* | **K** + B-explicit | 3-4 scripted siblings = real choices (the reward depends on them); saying the verse verbatim-ish is explicit |
| | `PoemFinalVerse` "Is that it?" (S G, single) | 1 effect-wait | |
| | Tullius coat (TL -> 2 -> 3 S), `ReturnWithCoat` (S G), court `AtCourtBranchTopic` (S G, Elisif's scene) | A / B-explicit / **V** | |
| CW00A / CW01A Legion | Tullius at the map table (ambient scene) | **E** only | `CW00TulliusGreetTalkRikkateAP` -> CW00A 10 by the click-free entry, after MQ101 is complete on this save (10.26); the open is refused on both sides |
| | Rikke "About that test..." -> WhatTest / HandleAnything / Alone (`crit1 W`) / `AcceptQuest` "Consider that fort already yours" (S G = stage 20 -> CW01A 1) | **E** (later) / X today | ambient map table: no session; the stage-20 entry needs its own `effects` row (alive-count branch) - LATER (P16) |
| | `JoinLegionBranchTopic` (TL 4 variants) -> Yes -> Oath1-3 (single, W, unscripted) -> Oath4 (S G, single) | H (Tullius after Helgen, if he ever stands free) / **1** x3 auto + 1 effect-wait | the oath flows: her line, his repeat (or 2.5 s), next line; Oath4 waits for "Long live the Emperor" / his assent |
| CW00B / CW01B Stormcloaks | Ulfric forcegreet (I chain), Galmar blocking "About that test" -> SignMeUp (S `crit1 W`) / questions / `AcceptQuest` (S G) | **V** + A / B-explicit | no `effects` row: words + real clicks only; Windhelm's palace is not an ambient scene, so V applies |
| | `MQ102GalmarJoinTopic` -> GreetReady -> StormcloakOath1-3b (single W unscripted) -> Oath4 (S) | V/H + 1 x4 auto + 1 effect-wait | |

**Cannot be done by voice, and is said so:** MQ101 (no prompts); the map-table pair beyond the one decoded
effect (Tullius stage 10 only; Rikke words-only this round - she says "that road is not open to you yet" or
gives her lines without a stage); LETHAL arrests (visible menu, corner note, one spoken line); anything inside a
running scene that the engine did not pause on a menu; item rewards. Everything else in the table has a path.

---

## 6. ENGINE-OPENED SESSIONS - the visible-driven tier (the biggest single gain)

Seven of the sixteen quests advance inside sessions the ENGINE opens (forcegreets, blocking branches,
scene-paused menus). At `iEngineOpen=0` they run `assisted`: `Arm()` sets `vis="assisted"` before the hide
(`LRG_Dialogue.psc:1810`), the driver reads and forwards (`StepManual`), and a server pick is refused with
`Error: that is not on the table right now` (`CmdSelectTopic` `live && !IsDriving()` branch, :1148-1170) **[C]**.
The player sees the vanilla menu and his words do nothing. That is the single behaviour the owner will report first.

**v1.0: `iEngineOpen` 0 = assisted (read only) · 1 = VISIBLE-DRIVEN (new default) · 2 = hidden (later).**
Visible-driven means: no `Guard`, no `Hide`, no `HideCursor`, no PENDING; the vanilla menu stays on screen;
the player may click with the mouse OR say it; a server pick runs the ordinary click body (`StepClicking`
without the hide preconditions: `readySeen` from `MenuState()==1`, line-settle, never over CHIM's TTS,
`FreezeMoved`, `SelectAndVerify` (the 40-char prefix + topicIndex), `XPush`, `Click(route)`, `ClickResult`,
RESPONDING's six signals). A player mouse click while a pick is in flight = a signature change -> `stale` ->
re-read (already handled). The `ev=open` / `lrg_topics` gain `drv=visible|hidden|none` (W15) so the server's
`<business>` says "the list is on screen in front of him; he may also choose by hand" instead of "she is waiting
for an answer", and never emits the PENDING wording.

Why this is safe on day one: the click body is the same code the calibration's manual button 1 and the hidden
sessions run; the only new thing is running it while `isHidden=false` - which `StepManual` explicitly avoids
today only for `readMode==4` (a write of `iSelectedIndex`, the D2 fix), so read mode 3 is required (the passive
`rm` row). The scene rule stays: `inScene` (`GetCurrentScene() != None`) still wins over engine-open in `Arm()`
- so Irileth's forcegreet INSIDE the court scene, Aventus's sacrament scene and Delphine walking you to the room
are visible-driven only if the speaker is not in a scene at that moment. That is the honest limit of v1.0 (P15
relaxes it after evidence): where the fact sheet marks "[I] scene", the menu shows, the glue reads it, and the
voice pick clicks it ONLY when `GetCurrentScene()` is None for the speaker. Measured in game on the first
evening: the arming line already logs `scene=` per session, so the log says which forcegreets are scene-bound.

Not built: hidden engine sessions before X1 (does the session survive the player's speech) has >= 3 samples on
ENGINE-opened sessions (`CALIB X1` lines exist for that), and D-17 (scene speakers never PENDING) stays.

---

## 7. SINGLE-ENTRY LAYERS AND CHAINS (the oath, the assignment, the continuers)

The layer table has no n=1 layers; they come from `links` **[X chains16b]**: 4,800 single-entry next layers in
the load order (1,513 with a script, 758 script+goodbye, 57 unscripted walk-away = the oaths), 58 inside the 16
quests. Today every one waits for the player or a T1 pick, and the `crit1` ones park. p2 6.2 asked for exactly
this table; `auto_advance.grace_seconds` (2.5) is declared and read by nobody.

| Single entry | Rule | Mechanism |
|---|---|---|
| `fx=none` (unscripted; the oath lines, TG00's chain, "The Greybeards?", "Okay, what's next?") | **auto-advance** after her line ends + `auto_advance.grace_seconds` 2.5 s, UNLESS the player starts speaking; his words then decide: assent / overlap >= 0.5 -> click at once; back-out -> hold; unrelated -> the model answers, the entry stays | server `want=1`: n==1 && class plain && fx none && kind '' -> `do=pick;kind=auto`. Game `StepClicking`: `reqKind=="auto"` waits line-settle + grace, cancelled by `NoteSpeech(0)` (the X1 hook already forwards `CHIM_SpeechStarted` for the player) -> ST_DECIDING again; the next utterance goes through the ordinary path |
| `fx` cosmetic | same as unscripted | |
| `fx` with an effect (Oath4 "Long live the Emperor" -> the enlistment; MQ103 "So what do you need me to do?" -> the assignment; MS11's evidence line -> jails a man; `BalgruufReward`) | **wait for words, no park**: the single entry IS the layer's own confirmation (the NPC's preceding line is the ask); the player's assent / >= 0.5 overlap executes at once; a back-out holds; silence -> 30 s watchdog hand-back (visible) | `lrgDlgLayerIsOwnConfirmation` extended: n==1 -> `release` when the utterance is an assent or scores >= 0.5 against the entry; else `parked` (no new park record: the layer itself is the park). `<business>` state: "NPC is waiting for you to say something like: '<entry>'" |
| `kind` != '' (a check as the only entry) | check rails as today, no auto | |
| `cost` > 0 | Grade A/B pay rules, no auto | |
| unindexed | wait for words (as effect) | |

Blood on the Ice stays safe: the top-level accusation is Grade A; the single evidence line has `fx=stage`+G,
waits, and executes on his SECOND statement ("yes, we found his amulet") - two statements, never a timeout.
The Legion oath becomes: "I'm ready to take the oath" (A) -> "Repeat after me" -> Oath1 auto or his repeat ->
Oath2 -> Oath3 -> Oath4 waits for "Long live the Emperor" / "I swear" -> "Welcome to the Imperial Legion". One
conversation, no menu, no "do you mean it?".

Cost: `kind=auto` is one string compare per poll on the click path and one float compare for the grace; zero
new UI reads; zero LLM calls (it REMOVES one ~6.5 s LLM turn per single-entry layer, ~58 turns across the 16
quests).

---

## 8. CALIBRATION AND DRY-RUN POLICY - fewer rows before the first click, a safer first click

The gate today needs 10 rows (`CalMissing`), and the two that need a real click (`guard`, `route`) are why the
automatic calibration session exists (pt18-calibration 7b): the glue opens an innkeeper's menu on an idle turn,
hides it, asks the owner to mash keys for 3 s, clicks the last safe entry. It is the survey's hotspot 1 and it has
never run in game.

**v1.0 splits the gate in two and lets the first real conversation do the measuring:**

| Gate | Rows | Unlocks | How the rows arrive |
|---|---|---|---|
| **CalGreenClick** | `cm`, `rm` (read mode 3), `st`, `fam`, `route` | the menuless dry run clears for VISIBLE clicking (`drv=visible` for glue-opened AND engine-opened sessions) | `cm rm st fam` passively from ANY menu open (his E-press, a courier, the first `do=open`); `route` from the FIRST voice pick on a visible menu: `CalRouteProof` - a click whose signature moves proves the route (exactly what `CalReopenProof` does for `reopen`); the deterministic `fam -> route` table (fam 1 -> B, fam 2 -> A) is the first try |
| **CalGreenHide** | + `hide`, `guard`, `rb`, `apd`, `reopen`, `timer` | hiding (`iHideMode` H2) for glue-opened sessions, later engine ones | passive `apd timer`, active A1/A2 (opt-in, 200 ms contract), `reopen` from `CalReopenProof`, `guard` from button 1 (the mash test - the ONE press that stays manual) |

Consequences: the automatic calibration session (server `lrgDlgCalibCandidate` / `lrgDlgCalibPick`, game
`ST_CALIB` / `CalAutoWanted` / `CalAutoRun`, `bCalibAuto`, `iCalibAutoRuns`, the `calib=1` key) is **removed**
(R1): its only job was `guard` + `route`, and `route` now comes free from play while `guard` matters only for
hiding. The owner's first evening: talk to an innkeeper ("what have you got?") -> the glue opens her menu
visibly, reads it, clicks the trade line in front of him (`route` proven), and from that conversation on every
plain line is a voice click on a visible menu. Hiding turns on by itself once the hide rows are green (the
existing auto-clear, `ReadCalibration` (e0), split into two markers) - and the emergency key ships bound to
Home (199) as pt18 recommended, with the "bind it" branch kept for owners who rebind.

The dry run's voice: one text, not three - `DlgDryReason` becomes "I can see what you mean on the list, but I am
still learning how to choose it here - choose it yourself this once" (the `still learning (N of 10)` counter stays
in the corner note and the log only). The developer dry run (`bDryRun`) is untouched and still named by page.

Can rows be dropped outright? `timer` (line-settle 0.8 s vs 0.3 s) is a behaviour row, not a safety row - it
moves to measurements with `x1`. `rb` (state read-back) is needed only when hiding (Park/ShowList). `apd`+`reopen`
prove the vanilla menu survives a HIDE - meaningless for visible sessions. So the click gate is 5 rows, the hide
gate 10, and nothing safety-relevant is dropped.

---

## 9. FAILURE MODES AND THEIR VOICED REASONS

Every row is a real path in `CmdSelectTopic` / `StepClicking` / `HandBack` / the server gate; the "she says"
column is the RULE `lrgVoicedWhy` maps to (10.21), never an authored line. New mappings marked NEW.

| Failure | Where | Player sees / hears |
|---|---|---|
| no match on the fast path, no T-key | server `want=1` / gate | her ordinary reply (she spoke on the open turn); visible: the menu; hidden: the corner note "<npc> is waiting for your answer - say it, or press Home for the menu" (NEW wording), 30 s (was 45) then hand-back |
| the entry moved / list changed (`stale` x2, `that moment has passed`) | game | NEW: "I lost the thread - say that again" (was "it just would not work right now") |
| the model picked a key not on this turn's list (`dropped stale`) | server | her own words play; nothing clicked; log |
| priced entry, cannot pay (`not enough gold`, server `afford` / game `reqCost`) | both | "you cannot pay N" is a locked fact -> she says it; never a click |
| a bribe below her price / a repeat of a failed check | `lrgDlgCheckRails` | the rule: "he offered less than she will take" / "she has heard this before" |
| scoff-first (doomed failure variant) | gate | her warning line is the message; a second try clicks |
| LETHAL / arrest / resist | gate + `Arm()` | menu handed back visible + "choose this one yourself" (foreseen clause) + corner note |
| a real commit, not explicit | park | her question IS the message |
| a walk-out on a guarded layer | leave guard | "you're walking out on her?" - park; a back-out entry is clicked instead when listed |
| her voice not ready (`VkRestore`) | game | NEW: "give me a moment" (transition reason: "can be asked again in a moment") |
| combat / scene started inside the session | game hand-back | the menu is visible; next turn `<what_just_happened>`: "the menu was given back because a fight/scene began" (NEW: the `handback why=` reaches ground truth once) |
| the developer dry run | game | named by page (unchanged) |
| the menuless dry run (click gate red) | game | "still learning - choose it yourself this once" (one text) |
| a reward bonus she cannot pay (short-fall) | `CmdAward give=` | funcret `gave=<actual>`; next turn: "she had only N" |
| the quest entry refused (road closed, already, wrong actor) | `CmdQuestEntry` | 10.26's five sentences, unchanged |
| an empty / false reply | `lrg_replies.php` | re-ask, floor line (unchanged); NEW `reward` class |

Nothing in this table is a silent info-only turn: the funcret rows have follow-up ON (catalog v11) and the glue
decides silence before the lock only for the closed list (successes, the carrier, the 8 s gap).

---

## 10. PERFORMANCE BUDGET (numbers, per turn / snapshot / session)

Baseline (V05 20.1, measured on the owner's logs): a spoken turn 7.73 s total, 6.54 s the model, 0.61 s
everything before it, `prompt_ready` 58 ms; `want=1` and every `ev=` 2-7 ms; a D1 fast-path click ~0.5 s after the
list; a first-contact turn 11-14 s when the session closes unclicked (the paid "again" turn).

| Item | Today | v1.0 budget | Why it holds |
|---|---|---|---|
| LLM calls per player utterance | 1 (+1 re-ask on ~5 %, +1 "again" on unclicked first contact) | **1**, no new call anywhere; -1 per single-entry auto-advance (58 layers in the 16 quests); -1 to -2 per explicit commit (no park turn) | P3/P4 remove turns; P5 rides the pre-LLM check |
| Glue prompt injection on a business turn | `<business>` <= 12 keys ~1,100 chars + rules ~700; `<locked_facts>` <= 600; `<real_business>` <= 70 words; `<what_just_happened>` <= 5 lines | **<= 3,700 chars (~900 tokens)** incl. the new `<reward_talk>` <= 350 chars (window only) and the 2-candidate hint <= 120 chars | `prompt_ready` stays < 100 ms (58 ms measured; the model is 85-95 %) |
| Server per turn | gate < 5 ms; `lrgDlgMatchText` over <= 52 entries | + one extra `lrgDlgMatchText` on commits (explicitness) < 1 ms; + `fx` reads from the row (no query) | |
| Index build | 33.4 s | **<= 65 s** (+`fx`: ~15k pex string tables) | one-off per load-order change |
| Papyrus per session poll (0.1 s) | 2-3 `UI.Get*` (EntryCount, MenuState, Subtitle / timer) | **unchanged**; `kind=auto` adds 1 string compare + 1 float compare | no new natives |
| Papyrus per layer | ReadList <= 16 x 3 reads + <= 40 tail texts (~0.3 s at the measured 41 ms / 12 reads) | unchanged | |
| Papyrus per click | SelectAndVerify + Click + ClickResult (3 natives) + CapturePre (7) | unchanged for visible sessions (Guard/Hide/HideCursor skipped: -3) | |
| Snapshot (`lrg_npcstate`, ~20 s) | 14+ MCM carriers + scene/ostim/hold/fol/fac/gold | **unchanged** (`gold=` already there) | |
| Wire | `lrg_topics` e= <= 1,600 chars + part 2 | + `drv=` (8 chars) on `ev=open`/`lrg_topics`; `give=`/`gave=` on award; `kind=auto` | additive, both sides ignore unknown keys |
| Player-felt latency targets | | plain pick on a known list: click <= 8 s after he stops talking (6.5 model + 0.4 settle + click); fast path: <= 1.5 s after the open; auto-advance: her line + 2.5 s; explicit commit: one turn | |
| Calibration | 10 rows + an auto session per innkeeper | 5 rows for clicking, from play; 0 automatic sessions | |

---

## 11. WHAT TO REMOVE (each replaced by something above, or by nothing)

- **R1 The automatic calibration session (E1f)**: `lrgDlgCalibCandidate`, `lrgDlgCalibPick`, the `calib=1` key,
  `ST_CALIB`, `CalAutoWanted/CalAutoRun/CalAutoClickWanted`, `bCalibAuto`, `iCalibAutoRuns`, `gopen`, flow d64.
  Replaced by the split gate + `CalRouteProof` (P6). It never ran in game; nothing is lost.
- **R2 `walkaway` / `crit>=1` as commit reasons** in `lrgDlgIsCommit` -> the leave guard (P2).
- **R3 The unconditional park** on the first selection -> explicit release (P3).
- **R4 `bRewalk`, `iRewalkDepth`, `dRewalks/dRewalkAborts`, `kind=rewalk`**: off, unmeasured, unused since 0.5.0.
- **R5 `losses` forever** ("two lost layers = assisted closed layers for the rest of the game session"): with
  visible-driven sessions a "lost" layer is a menu the player can see; the counter becomes a 900 s window and
  resets on any successful driven click (P10).
- **R6 `confirm.typed_skips`** (unread), **`auto_advance.continuer_words`** (unread; the `fx=none` rule covers
  continuers) - deleted from `lrgDlgDefaults`.
- **R7 `<she_may_raise>` initiative** stays off and out of v1.0's surface (code kept, no work).
- **R8 The conditional `hide_gold`** -> unconditional inside a reward window (P5).
- **R9 Three dry-run texts** -> one (P6).
- **R10 `res=`** keeps its slot (fixed key order) and is sent empty; PROTOCOL 10.4 already says nobody reads it.

---

## 12. PROPOSALS (each: name / problem / change / files / test / cost / priority)

**P1 - Visible-driven engine-opened sessions (`iEngineOpen=1` default).** *Problem:* 7 of 16 quests advance in
forcegreets/blocking branches that run assisted; a voice pick is refused. *Change:* `Arm()` maps
`!glueOpened && sEngineOpen==1` to `drv="visible"` (no Guard/Hide/HideCursor, `IsDriving()` true for picks);
`StepManual` runs the click body on `reqPickReady`; `EnterPendingOrManual` never PENDS a visible session (the
menu is his); `SendOpen`/`SendTopics` carry `drv=`; server `lrgDlgOnTopics` stores `sess.drv`,
`lrgDlgBusinessBlock` words for the visible state, `lrgDlgAnswerWant` unchanged. MCM `iEngineOpen` range 0-2,
help rewritten. *Files:* `LRG_Dialogue.psc` (Arm, CmdSelectTopic :1148, StepManual, EnterPendingOrManual,
SendOpen, SendTopics), `LRG_MCM`/`config.json`/`settings.ini`, `lrg_dialogue.php` (lrgDlgOnTopics,
lrgDlgBusinessBlock, the `case 'open'` branch of lrgDlgOnEvent :880), `PROTOCOL.md` 10.1/10.2 (W15). *Test:* flow `d66_visible_drive` (an
engine-opened list -> T-key -> `do=pick` -> the game's click body path asserted in the fixture's state machine;
scene=1 -> read only); `test_dialogue` 16 (the visible wording, no PENDING note); in game: Irileth at the
door - say "I have news from Helgen" -> the log shows `clicked pos=.. drv=visible`. *Cost:* Papyrus 0 new
natives (-3 per click); server < 1 ms; wire +1 key. *Priority:* **must**.

**P2 - Grade by fragment effect (`fx`) and turn `W` into a leave guard.** *Problem:* cosmetic scripts and
walk-away flags park 14 lines in one Delphine conversation; the oath parks 3 times. *Change:*
`build_prompt_index.py` gains `fx` (2.4); `lrgDlgDecorateEntries` carries `fx`; `lrgDlgIsCommit` per 2.2;
`lrgDlgClass` unchanged; session `guard` flag from `path` in `lrgDlgOnTopics`; `lrgDlgGateItem` `leave` branch
honours the guard (2.5); `<business>` labels `[commits]` only on real commits, `[walking out]` on twat targets.
*Files:* `tools/build_prompt_index.py`, `lrg_prompt_index.php` (row field), `lrg_dialogue.php` (decorate,
IsCommit, GateItem, BusinessBlock, defaults `commit.effects`), `test_prompt_index.php` 5d, `PROTOCOL.md`
10.8. *Test:* `test_prompt_index` 5d asserts `fx` on the 16 quests' scripted rows (Oath4 `stage`, Kodlak
`stage`, Eorlund x5 `item`, C2Horn's four `cosmetic`/`none`, MS11 evidence `stage`); `test_dialogue` 16: the
C2Horn layer yields 1 commit (C5), the oath layers 0, MS08's layer 3 two; flow `d69_walkaway_leave`: leave on a
guarded layer -> park, back-out entry preferred. *Cost:* build +20-30 s once; runtime 0. *Priority:* **must**.

**P3 - Explicit statement = step one; single-token assent releases.** *Problem:* every commit costs two extra
utterances; "yes" is refused. *Change:* in `lrgDlgGateItem` before the park: `lrgDlgExplicit($t,$e)` =
speech turn && utterance match >= 0.70/0.25 for THIS entry (or exact faction/slot) && not in the always-ask
set -> emit; `lrgDlgParkOrRelease`: the assent list releases when `parked.said` (the parking turn's message was
non-empty - stored from the post-gate) ; `lrgDlgAnswerWant`: the same explicitness test on the player's own
utterance for Grade B (not `ask=`). *Files:* `lrg_dialogue.php` (GateItem, ParkOrRelease, AnswerWant, defaults
`explicit`), `PROTOCOL.md` 10.4 note. *Test:* `test_dialogue` 17 (Kodlak one utterance; "yes" after her
question; "yes" with no question stays parked; STT one-token "kill" never; lethal never; 100+ gold always asks);
flow `d63` (existing two-step) amended. *Cost:* < 1 ms. *Priority:* **must**.

**P4 - Single-entry layers: auto-advance the effect-free, wait-for-words the rest.** *Problem:* 58 single-entry
layers in the 16 quests each cost a PENDING wait or an LLM turn; the oath needs ~8 turns. *Change:* server
`want=1` n==1 rule -> `do=pick;kind=auto`; `lrgDlgLayerIsOwnConfirmation` n==1 branch; `<business>` "waiting
for you to say something like"; game `StepClicking` `kind=="auto"`: grace after line-settle (`fAutoAdvance`
2.5 s, floor), cancel on player speech via the existing `NoteSpeech(0)` forward -> ST_DECIDING; `ev=result`
gains `auto=1` for the log. *Files:* `lrg_dialogue.php` (AnswerWant, LayerIsOwnConfirmation, BusinessBlock,
defaults), `LRG_Dialogue.psc` (StepClicking, NoteSpeech, SendResult), `config.json`/`settings.ini`
(`fAutoAdvance:Dialogue`), `PROTOCOL.md` 10.4 (kind=auto), 10.2 (auto=). *Test:* flow `d67_single_entry`: the
CW01A chain from `MQ102JoinLegionYes` through Oath4 with the real rows (3 auto picks, Oath4 waits, "I swear"
releases); MQ103's assignment waits then clicks on "what do you need"; MS11-shape (single `fx=stage` G)
never auto, executes on the second statement; a back-out holds. *Cost:* 0 UI reads; -1 LLM turn per layer.
*Priority:* **must**.

**P5 - Negotiation: the reward window, the real check, `give=` through `CmdAward`.** *Problem:* a bonus is
either a false promise or minted gold. *Change:* per 4.2-4.4: `lrgDlgRewardTalk` (window, stakes `reward`,
cap), `<reward_talk>` block, `do=award;give=N` (+`gave=` on the OK), `CmdAward` NPC->player move capped by
`GetGoldAmount()`, unconditional extended `hide_gold` in the window, `lrgDlgTruthCheck` drops stray gold/item
actions, `lrg_replies.php` `reward` never-false class, `<what_just_happened>` line. *Files:* `lrg_speech.php`,
`lrg_dialogue.php` (PrepareTurn hook, PostProcess, TruthCheck, GroundTruth, ApplyOffer hide), `lrg_replies.php`,
`lrg_actions.php` (lrgVoicedWhy short-fall), `LRG_Dialogue.psc` (CmdAward), `lrg_config.default.json`
(`checks.stakes.reward`, `checks.reward`), MCM `bRewardTalk:Checks`, `iRewardCapDays:Checks`, `PROTOCOL.md`
10.5. *Test:* `test_dialogue` 19 (window open/closed, pass/fail by live `sg`, cap = min(pocket, days x wage,
500) on a commoner / merchant / jarl with pocket 0, named amount clamp, no-coin directive, item ask refused,
hidden actions); `test_gates` 38 (the award line shape, `gave=` read back, short-fall voiced); flow
`d68_negotiation` end to end after MQ104's reward click. *Cost:* < 2 ms per reward-window turn; one prompt
block <= 350 chars in the window; 0 LLM calls. *Priority:* **must** (the owner named it).

**P6 - Split the calibration gate; the first live click is visible; drop the automatic session.** *Problem:*
10 rows, two need a click, the auto session never ran; the owner sees "0 of 10" nightly. *Change:*
`LRG_DlgProbe.CalGreenClick()` / `CalMissingClick()` (5 rows) and `CalGreenHide()` (10); `CalRouteProof` on any
click that moves the signature on a visible menu (first try = the `fam` table); `ReadCalibration` (e0) two
markers (`autoclr_click`, `autoclr_hide`); `Arm()` hides only on `CalGreenHide`; `DlgDryReason` one text;
`iKeyVanillaMenu` default 199 in all four places (settings.ini, LRG_Main :1079, LRG_Dialogue :642, config.json);
remove R1 on both sides. *Files:* `LRG_DlgProbe.psc`, `LRG_DlgUI.psc` (store keys), `LRG_Dialogue.psc`,
`LRG_Main.psc`, MCM files, `lrg_dialogue.php` (calib branches removed, `lrgDlgLearningText`), `PROTOCOL.md`
10.13. *Test:* `test_mcm_wiring` (dead controls removed, new ids read), `test_dialogue` 15 rewritten (no
calib pick), flow d64 deleted, d50 wire test updated; in game: first innkeeper conversation clicks visibly with
`cal` at 5, the arming line logs `gate=click`. *Cost:* fewer natives per session (no hide until green). *Priority:* **must**.

**P7 - Hand-backs reach the next turn's ground truth; shorter silence.** *Problem:* a watchdog/combat/scene
hand-back is a corner note and a log line; the model never learns why the menu went back. *Change:*
the `case 'unhide'` branch of `lrgDlgOnEvent` (:910, the one that logs `handback npc=`) stores `handback{why,at}`;
`lrgDlgGroundTruth` adds one line once ("the menu was given back to
him because ..."); `fSilenceTimeout` default 30; the PENDING note names the key. *Files:* `lrg_dialogue.php`,
`LRG_Dialogue.psc` (EnterPendingOrManual note, ReadSettings default), `settings.ini`. *Test:* `test_dialogue`
16 (the line, told once); flow d66 step. *Cost:* 0. *Priority:* **should**.

**P8 - The two-candidate hint in `<business>`.** *Problem:* the model asks "which do you mean?" from scratch.
*Change:* when `lrgDlgMatchText` over the offer finds two entries within 0.10, the block names the two keys
("T2 or T5 fit what he said - ask which"). *Files:* `lrg_dialogue.php` (Rank/BusinessBlock). *Test:*
`test_dialogue` 17. *Cost:* <= 120 chars. *Priority:* **should**.

**P9 - Losses window (R5).** *Change:* `losses` decays after 900 s and resets on a successful driven click
(`lrgDlgOnResult ok=1`). *Files:* `lrg_dialogue.php` (OnClosed, OnResult, ListFor). *Test:* `test_dialogue`
16. *Cost:* 0. *Priority:* **should**.

**P10 - The questline fixture.** *Problem:* the owner cannot prove coverage by playing every quest. *Change:* flow
`d70_questline` walks every beat of section 5 with the REAL index rows (layer fingerprints from the ndjson) and
asserts the path each takes (click-now / ask-once / hand-back / words / auto / wait) and the voiced text class;
prints a coverage table. *Files:* `tools/flows/scenarios/d70_questline.php`, `tools/test_prompt_index.php`
5d. *Test:* itself. *Cost:* build time only. *Priority:* **should**.

**P11 - Hidden engine-opened sessions (`iEngineOpen=2`).** *Change:* after `CalGreenHide` AND `x1==1` from >= 3
engine-opened sessions, `Arm()` hides engine sessions too; scene rule unchanged. *Files:* `LRG_Dialogue.psc`,
`LRG_DlgProbe.psc`. *Test:* in game only (X1 lines), flow d66 variant. *Cost:* 0 new. *Priority:* **later**.

**P12 - Scene-paused forcegreets driven visibly (D-17 relaxation).** *Change:* `Arm()`: `inScene` && engine-opened
&& the scene's owning quest has no timer phase running -> visible-driven with a 20 s silence and a hand-back on
any scene phase change (`ReadList` G4 already detects a scene starting). *Files:* `LRG_Dialogue.psc`. *Test:* in
game (Irileth at the door, Aventus) with the arming line's `scene=` and `handback why=scene` counts. *Cost:*
0. *Priority:* **later** (needs evidence; the fact sheet's [I] marks are exactly the samples).

**P13 - Rikke's stage 20 by voice and the Stormcloak rows.** *Change:* `effects['Legate Rikke']` (CW00A 20, conds
`isid 000132A1`, `stage_done 10`, `alive FortHraggstadLocation/CWFortMonster >= 1`, confirm true) after Tullius's
entry has run in game once; Galmar's `TIF__000E1AE8` decoded the same way. *Files:* `lrg_factions.php`,
`LRG_Main.psc` (the `alive` cond). *Test:* `test_dialogue` 13, flow d65 extension. *Priority:* **later**.

---

## 13. HONEST LIMITS (what v1.0 does not do, and says so)

- Lines inside scripted scenes with no player prompt (Helgen, the Greybeards' courtyard rites) are not dialogue
  and cannot be driven; nothing is promised there.
- Forcegreets while the speaker is inside a running scene stay assisted until P12 has in-game evidence; the
  visible menu plus her one line "choose this one yourself" is the fallback, and the glue still reads the list so
  her words are right.
- The map-table pair: Tullius's one decoded effect only; Rikke words-only; the Stormcloaks words + real clicks.
- Item / land / title rewards: refused truthfully.
- Hidden menus for engine-opened sessions: after measurement (P11).
- The paid "again" turn on a first contact that closes unclicked (11-14 s) stays; it is the price of opening
  for awareness and is now visible on `ev=lat first=1`.
- STT blanks (17 % once) are CHIM's; the never-empty floor covers the reply, not the input.

## 14. What the owner will notice on the first evening after v1.0

1. He says "what have you got?" to an innkeeper: her menu opens where he can see it, the trade line gets
   picked in front of him, the barter window opens. No settings, no buttons.
2. Kodlak: "I want to join the Companions" - one sentence, the real line, his real reply.
3. Delphine's room: he asks his questions; nothing asks "do you mean it?" except when he says he is leaving.
4. The oath: her line, his repeat (or a breath), her next line; "Long live the Emperor" ends it.
5. Irileth at the door: the menu shows, he says "I have news from Helgen", it happens.
6. "I deserve more than this": Balgruuf either pays what he has on him after a real Speech check, or says he
   carries no coin - and never promises a house.
7. When something cannot be done, she says why, and the menu is his.
