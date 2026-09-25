# pt19 - MENULESS QUESTING v1.0, designed through the PERFORMANCE AND SIMPLICITY lens

2026-09-24. Read-only panel round; nothing in the glue was edited. Lens: the fewest moving parts that still
deliver the brief; a Papyrus budget per turn and per snapshot; prompt size; latency; calibration rows that can
go; code that can be deleted; robust to save reloads and version skew. Currency: septims.

Sources, in order of authority: `research/pt19-design-survey.md` (the map, sections 0-9), `PROTOCOL.md` 10.13-10.26,
`V05_EXPANSION_PLAN.md` E1-E8, `OWNER_ADDENDA.md`, `research/dialogue-engine.md` (the verdict), `research/p2-quest-
branching-speech.md`, `research/pt18-quest.md`, `research/pt18-calibration.md`; code where a specific question needed
it (`lrg_dialogue.php`, `lrg_factions.php`, `lrg_speech.php`, `LRG_Dialogue.psc`, `LRG_DlgUI.psc`, `LRG_DlgProbe.psc`,
`LRG_Profile.psc`, `LRG_Main.psc`; CHIM's `AIAgentAIMind.psc`, `AIAgent.dll` strings, `core_action_seed.sql`); the live
index census (`C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\quest_rows.tsv`, 512 rows of the 16 quests, recounted
with `stats.py` in the same folder); and the live log `/var/www/html/HerikaServer/log/lorerim_glue.log` (3,612 lines
to 03:05 today). Marks: **[X]** index, **[C]** code (file:line), **[L]** log, **[R]** research, **[I]** inference.

---

## 0. The verdict in ten lines

1. The click primitives do not need a hidden menu. `SelectAndVerify()` writes `iSelectedIndex` and verifies the live
   text; `Click()` sets `eMenuState`, re-verifies and fires ONE `Invoke` (`LRG_DlgUI.psc:614-712`) **[C]**. Nothing in
   that pair reads the holder's `_x`, the guard or the cursor.
2. Everything that makes menuless questing complex today exists to keep the vanilla menu INVISIBLE while the LLM
   thinks: the hide, the input guard, the cursor hide, the emergency key, the auto-clear, the 45 s watchdog, PENDING,
   the "waiting for your answer" note, 5 of the 10 calibration rows (`hide guard rb apd reopen`), the active pass,
   the automatic calibration session, the manual presses, the hand-back / resume machinery (~2,400 lines of Papyrus).
3. The log has **zero real clicks in game** (0 `clicked pos`, 0 `WOULD CLICK`; 27 emits, 15 of them `do=pick` and all
   15 from the offline probe of 2026-09-22 or the click-free first calibration open) **[L]**. The whole click path is
   unproven. The design that gets it proven fastest is the one with the fewest parts in front of the click.
4. Reading works when the list is there (Hulda, root list n=10, read 3 s after arming, `dlg topics ... n=10 sent=10`
   02:53:52) **[L]**; two `read failed` lines (n=1, n=4, mode 3) sit 1 s before the player's own Tab **[L]**.
5. Speech with the vanilla menu OPEN works: 91 of 359 dialogue turns were built with `list=pending` **[L]**, every one
   of them a dry-run session, and a dry-run session runs VISIBLE (`Arm()`: hide only when `!goManual && !sDryRun`,
   `LRG_Dialogue.psc:1830`) **[C]**. The owner has been talking over a visible menu for three days.
6. So v1.0 is **the visible menu, voice-driven**: the vanilla list stays on screen (as it does today in dry run), the
   glue reads it, the server maps words to an entry, the game clicks it. The player can click by hand at any moment.
   No hide, no guard, no emergency key, no watchdog, no hand-back. "Menuless" means *he does not have to click*.
7. With a visible menu the calibration gate needs `counting`, `reading`, `menu layout`, `Smart Talk settings` (all
   passive, learned on the first menu of any kind) and the `click route`, which is learned by the first real click on
   a cost-free plain entry. Ten rows become four plus one measured by doing. The dry run becomes an ordinary toggle.
8. The map-table road opens the honest way: `iSceneGate = 1` already exists (`OpenBlockedReason` :1339-1342 - open on
   a scene actor unless a journal quest owns him) **[C]**; vanilla lets you talk to Tullius at the table; the AP join
   line is then an ordinary click. `ExtCmdLRG_QuestEntry` (PROTOCOL 10.26, ~190 lines PHP + ~210 Papyrus, untested,
   D1-only, licensed/unlicensed, the six-quest cap) is deleted. That returns the glue to the research verdict:
   *never re-implement the engine; the real session click is the execution primitive* **[R dialogue-engine]**.
9. The two-step stays, with two fixes: a bare "yes" releases a parked item she named (today `< 2 tokens` never
   releases, `lrgDlgParkOrRelease` :2815-2818 **[C]** - the oath chain cannot be sworn by voice), and a SINGLE-entry
   commit layer releases on the player's first non-refusal, non-question utterance without a park.
10. Budget: <= 3 natives per 0.1 s poll while a menu is open (5.7 today), <= 48 UI reads per layer (72), facts line
    <= 0.6 s (measured p50 0.82 s / p90 1.23 s / max 1.48 s **[L]**), dialogue prompt <= 2,500 chars (~3,200), first-
    contact click <= 3 s after speech with NO second paid LLM turn (11-14 s today), server fast path <= 10 ms (2-7).

---

## 1. Principles (the rules every proposal below is checked against)

P1. The engine executes; the glue only selects. No `SetStage`, no `Say`, no fragment replay (dialogue-engine.md verdict).
P2. The menu the player can see is the safe state. Nothing hides it; nothing takes his inputs; nothing needs a key.
P3. One decision per turn, one command per decision, one delivery that is idempotent (`x` ring). Additive wire only.
P4. Never silent, never empty, never false - unchanged rails (10.21, 10.23, 10.25) and every new refusal has words.
P5. A rail that has never fired in game is a cost, not a safety. Delete it, or make it fire in the first evening.
P6. Index first, words second; the game verifies the live text before every click (`txt=` prefix, `topicIndex`).
P7. Consequence class decides confirmation: plain clicks, checks click, commits ask once, lethal never by voice.
P8. Facts the model is told come from the game (result rows, facts line, snapshot), never from the index alone.
P9. Papyrus: no waits on the loop, one native per fact, cache what never changes (forms, MCM knobs).
P10. Every removal is a version-skew no-op: stop sending / ignore unknown; a deleted command keeps a quiet OK stub.

---

## 2. What the evidence says (only what the design leans on)

### 2.1 From the live log (3,612 lines, 2026-09-22 to 2026-09-24 03:05) **[L]**
| fact | number |
|---|---|
| dialogue turns built | 359: `list=none` 257, `list=pending` 91, `list=root` 11 |
| sessions armed | every in-game one `origin=engine`, or a calibration `origin=glue`; `hid=0` on all; `dryrun=1` on all |
| session closes | 28: `why=external` 24 (the player's Tab / the engine), `asked` 2, `handoff` 2; `pending=1` once |
| real clicks | 0 (`clicked pos` 0, `WOULD CLICK` 0) |
| emits | 27: `do=pick` 15 (all offline probe or the click-free calibration open), `award` 6, `open` 3, `show` 2, `noop` 1 |
| hand-backs | 2: `lethal` (a guard, correct), `read-failed` (Elrindir 02:52:51, n=0 one second after arming) |
| `read failed n=<n> mode=3` | 2 (n=1 on the lethal guard; n=4 on Matlara, closed by the player 1 s later) |
| facts line cost `facts ... ms=` | n=70, min 334, **p50 823, p90 1,226, max 1,480 ms** |
| never-empty re-asks | 3 |
| `ev=lat` lines | 0 (bLatencyLog off) - the voice gap is still unmeasured |

### 2.2 From the census of the 16 quests (512 rows) **[X]**, recounted
| shape | rows | what it means for the driver |
|---|---|---|
| top-level | 175 (34 %) | reachable from a session the glue can open on a free-standing NPC |
| top-level, plain (unscripted, crit 0, no check) | 104 | click straight |
| top-level, scripted | 65 | mostly `commit` today (scripted && goodbye) |
| sub (reached by link / forcegreet / blocking) | 337 (66 %) | inside a session the ENGINE opened, or one layer down |
| sub, scripted + goodbye | 77 | the stage movers; `commit` |
| crit 1 (walk-away / twat) | 65 (MQ106 14, TG00 10, CW01B 10, MS05 9) | two-step |
| real checks | 12 (4 persuade, 2 bribe + 6 variants); MQ101-MQ106 have none | the check path is a guild-only concern |
| priced | 9 (Nirya's spell 30, Delphine's attic, ILQE bribe tokens) | `pay` |
| invisible-continue | 61 | never shown; the engine plays them |
| norm <= 2 words | 11 (CW00A 4) | the slot matcher's territory; none of them a stage mover |

### 2.3 From the code, the costs **[C]**
- `Step()` per 0.1 s poll while a menu is open (`LRG_Dialogue.psc:1887-2027`): `AllowProgress` + `GuardPoke` when
  guarded/hidden (2), every third poll `Guard(true)` + `ReassertHide` (2/3), `Utility.IsInMenuMode` (1), `HarvestLine`
  (1 `Subtitle` read), `EntryCount` (1 in LISTENING/READING/PENDING), `CalPoll` every fifth poll until `timer` is
  learned. A hidden session ~5.7 natives per poll = 57/s; a visible one ~3 = 30/s.
- `ReadList()` per changed layer (`:2069-2160`): head `min(n, 16)` x (`EntryText` + `EntryTopicIndex`) + tail up to
  40 `EntryText` = up to **72 delayed natives** (about one frame each) + `GetCurrentScene` + `FreezeCapture` + `CalLayer`.
- `SendFacts()` (`:3663-3720`): one po3 quest sweep, three csv builders over <= 6 quests, `QalCsv` (po3 `GetRefAliases`
  + per alias `GetOwningQuest`/`IsActive`/`GetName`), two `GetFormFromFile` + `Quest.GetQuest` every call, five Speech
  globals, `WillIntimidateSucceed`, `PerkCsv`, `CrimeWire`. Measured 0.8-1.5 s. Throttled 60 s per NPC.
- `BuildSnapshot()` (`LRG_Profile.psc:405-598`) every ~20 s: ~25 actor natives + the witness scan + `PlayerFacts` +
  **13 MCM reads** (`SettingBool/Int` for `chk bias ql qi qig qx sv svx lf tg paidok pm` + `bMenuless/bDlgDryRun`).
- Server: `prompt_ready` 58 ms median of a 7.7 s turn (10.17d); `lrg_dlg`/`lrg_topics` 2-7 ms (10.17d).
- `GiveGoldTo` is CHIM's DLL command (`dispatch: plugin_command`, `core_action_seed.sql:35`); the DLL strings say
  "Calling transfer: {} -> {}, Gold, Amount={}" and "Could not find gold form!" - a TRANSFER from her inventory, not a
  spawn; what it does when she holds fewer coins than asked is not readable from disk **[C, limit]**.
- `CmdAward` clamps `take < 0` to 0 (`LRG_Dialogue.psc:1402-1407`): the glue can move gold player -> NPC only.
- `settings.ini` today: `iSceneGate = 0`, `iHideMode = 0`, `iEngineOpen = 0`, `bDlgDryRun = 1`, `iKeyVanillaMenu = 199`.

---

## 3. The v1.0 shape: the visible menu, voice-driven

### 3.1 What a turn looks like
1. The player speaks. CHIM sends `inputtext`. The server builds the turn as today (`lrgDlgPrepareTurn`).
2. **No list known and a business marker in his words** -> the server queues `do=open` on the D2 route BEFORE the LLM
   runs (M5). The game opens her real menu (`Activate`), VISIBLY, reads the list, sends `lrg_topics want=1`.
3. **want=1 (no LLM, ~0.5 s)**: the server matches his words (and the model's `ask=` when it exists) against the live
   list under the rails of section 4. A plain / service / back hit -> `do=pick` echoed in the same reply -> the game
   verifies the live text and clicks. The engine plays her real line and either closes the menu (goodbye) or pushes
   the next layer, which is read and sent again. Nothing is invented; the model's reply for that turn is muted when
   a pick is emitted (the existing `lrgDlgTransformer` mute).
4. **No fast-path hit** -> the menu simply stays on screen (it is the vanilla menu; the player may click it himself)
   and the model answers in words with the `<business>` keys. A T-key on the reply -> `do=pick` -> the live session
   clicks. A `[commits]` key -> her question is the reply; his "yes" (M4) -> the click.
5. **The session was closed meanwhile** (he pressed Tab, the engine ended it, she walked) -> the pick lands on no live
   session -> `StartOpen` -> the new list -> `TryResolvePick` by `txt=` prefix (`:2440-2470`) **[C]** -> click. One
   greeting replays; that is vanilla behaviour when you talk to somebody again.
6. **A menu the ENGINE opened** (forcegreet, blocking branch): the same handler, visible as it is today, and voice
   picks are accepted on it (M1; in a scene under S1). This is the survey's "7 of 16 quests" gap closed without
   `iEngineOpen`.

### 3.2 What disappears from in front of the click
The hide (`Hide/ReassertHide/Unhide`), the guard (`Guard/GuardPoke/GuardReset` per poll), the cursor hide, the
emergency key and its auto-clear, PENDING's park + note + `fSilenceTimeout` watchdog, `HandBack`'s ten reasons
(the driver "stops driving" instead: `ClearRequest(false)`, log, `ev=closed` when the menu closes), `MaybeResume`
+ `ev=resume`, `ev=unhide`, the assisted-after-two-losses degradation (`losses`), the calibration rows `hide guard rb
apd reopen`, the active pass A1-A5, the manual presses 1/1b/2, the automatic calibration session (E1f), the
`bDlgDryRunHold` / `bCalibOverride` / MCM-Helper-write auto-clear, `iBranchInput` / `x1`, `bRewalk` / `iRewalkDepth`,
`bResumeAfterChoice`, `bHandBackNote`, the second paid `lrg_dlgtalk` turn, and `ExtCmdLRG_QuestEntry` with its
effects rows and licence. Section 11 lists every file and function.

### 3.3 What stays exactly as built
`lrg_topics` / `lrg_dlg` / `lrg_dlgtalk` (never requested, kept for skew) / `ExtCmdLRG_SelectTopic` wire and its fixed
key order; the index and `lrgPromptLookup`; `lrgDlgRank` and the T-keys; `lrgDlgDecorateEntries` classes; the rail
ladder minus its hidden-menu branches; the slot matcher and every service rule (10.15); the speech-check machinery
and `do=award` (10.5); the truth gate and locked facts (10.17); never silent / never empty / never false; the faction
rows, roles, `lrgFacRoad` and the Helgen words (10.22, 10.25); the outcome pipeline and `<what_just_happened>`; the
root cache; the freeze rule B1; `XSeen`/`XPush`; `CapturePre`/`SettleAndReport`/`ev=result`; `ev=facts` (trimmed).

---

## 4. Confirmation policy for quest-critical lines (when the glue clicks at once, asks once, or refuses)

Grades come from the index row (`crit`, `walkaway`, `scripted`, `goodbye`, `kind`, `variant`, `cost`), the game
session (`crit` 0|2, `IsGuard`, bounty), and `lrgDlgClass()` / `lrgDlgIsCommit()` (`lrg_dialogue.php:1322-1413`)
unchanged. The stage rail (M2) sits above everything until the first verified click on this install.

| class / grade | LLM turn (T-key or words) | fast path (want=1, no LLM) | her words |
|---|---|---|---|
| `hidden` (placeholder, invisible-continue, empty) | never | never | - (never shown) |
| `meta` (`(skip quest)`, `(fail quest)`) | never; the menu is his | never | "that is not something I settle by talking - choose it yourself" |
| session `crit 2` / arrest-class / resist-arrest row | never (do=show) | never | "choose that yourself" + the corner note; `PayBounty` stays on CHIM's table, `ForgiveCrime` never |
| `commit` (walk-away, `crit 1`, scripted && goodbye, `commit_tags`, cost >= 100 or >= 25 % purse) | **ask once**: parked, her naming question IS the reply; released by a bare yes/aye/do it (M4) or any >= 2-token non-refusal that names it; a vanilla yes/no layer releases at once; 60 s expiry; a refusal un-parks | never | her own question; on refusal "as you like" |
| `commit` on a **single-entry layer** (oath lines, "So what do you need me to do?", the MS11 evidence line) | **click on the first non-refusal, non-question utterance** after the layer was shown - no park (M4). A question -> words; "no / not sure / wait" -> nothing, the layer waits | idem, when his words are the reply to her question | none needed - the engine's own question was the ask |
| `check` (persuade / intimidate / bribe) | **click at once** when the attempt is clear: >= 4 words on voice, kind from the INDEX first (`lrgDlgLabel`), tag second; scoff-first parks a doomed attempt once (`wis=0` intimidate, or the FAILURE variant shown and scripted/goodbye); bribe below her price or unaffordable refused with the price; retry suppressed until `sp/pg/wis` moved | click at once under the same rails | "say it properly, then" / "you cannot pay that" / "you tried that already" |
| `pay` below the commit threshold | click at once after the afford rail (`pg`, then the game's `GetGoldAmount`) | click on a same-session continuation (>= 0.70 / 0.25) | "not enough gold" when the purse moved |
| `service` slot list (destinations, skills, rooms, follower names) | exact slot, longest wins; two -> ask; none -> nothing; price question -> nothing | idem | "which one?" / the fare |
| `plain` / `back` | click at once on a T-key of THIS turn's offer, or a words match >= 0.55 with margin >= 0.15; two fit -> ask | click at once | "which do you mean?" |
| **stage rail** (M2): no verified click yet on this install | only `plain`/`back`, cost 0, not scripted, not goodbye, not a check | idem | "let me have you pick that one on the menu the first time" (the corner note says why) |

Rules kept from p2 5.3 / 6.2: never resolve ambiguity by list order; never auto-pick on silence; never auto-cancel a
layer (a cancel plays the walk-away topic); `(Remain silent)` only on an explicit "I say nothing"; typed input never
skips the two-step (`confirm.typed_skips` stays false); the STT read-back for a commit is her naming question.

Why single-entry commits do not need a park: the engine's own structure says there is no choice - the only
alternative is walking away, which a cancel would ALSO trigger (the walk-away topic). p2 6.2 asks the glue to "WAIT
for the player" on a scripted single entry; his next non-refusal utterance IS the wait's end. Today the park then
demands a SECOND, different utterance (`lrgDlgParkOrRelease` :2802-2818): three steps where the engine has one.
Blood on the Ice stays safe: the accusation is a harmless top-level click, Jorleif asks "I assume you have proof?",
and only an answer that is neither a refusal nor a question clicks the evidence line - exactly what the mouse does.

---

## 5. Matcher and model-item policy

- **Index first.** A live list is fingerprinted, then per-entry exact norm, then token patterns (`lrgPromptLookup`,
  10.8); the class, kind, variant, cost, crit, scripted, goodbye and walk-away come from the row; the visible tag is
  the fallback for kind only (54 tag-only rows).
- **The model's own key is trusted when** it names a key of THIS turn's offer (pos + norm re-checked, else "dropped
  stale") and the entry is `plain` / `service` / `back` under every rail. For `check` and `pay` the key is the
  attempt or the purchase and the class rails decide; for `commit` it is step one of the two-step. A key on a hidden
  or meta entry is dropped with words. The model never chooses `do=award`, `do=open` or any calibration verb.
- **The model's words** (no key) go through the pool = entries + tail minus hidden, in this order: follower verbs
  (exact) -> the faction join line (exact glob + join word) -> the slot matcher on a price list (exact, longest wins,
  and it BLOCKS similarity there) -> `lrgDlgMatchText` (exact 1.0, containment 0.85, word-F1 0.35 + 0.65 F1,
  `similar_text` x 0.8; floor 0.55, margin 0.15, `lrg_dialogue.php:2186-2218`). No pool -> the open (pre-LLM in v1.0).
- **Paraphrases** are the LLM's job on a root list (it sees the verbatim entries and picks the key); on a closed layer
  the fast path matches his own sentence, and the `ask=` words the model produced on the open. The `txt=` prefix (40
  chars) and `topicIndex` are re-verified on the live list a frame before the click; a moved list is re-read (twice),
  then "that moment has passed".
- **Ambiguity** (margin < 0.15, two slots, two join lines) is always a question in her words, never a guess.
- **STT** (`inputtext_s`): the 0.55 floor, the 4-word attempt minimum and the two-step on commits are the guards;
  a one-token utterance never clicks anything except a bare yes on a parked item she named (M4).
- **Short entries** (<= 2 words, 12.3 % of the index; 11 rows in the 16 quests) are slot lists: exact naming only.
- Nothing new is proposed for the matcher itself: with the visible menu a miss costs nothing (he sees the list and
  may click), so tuning thresholds down for recall is the wrong direction; keep them.

---

## 6. Negotiation policy (rewards, septims, "a different reward")

**The engine's reward is fixed.** A quest's reward is set by its fragments; the glue can neither add to it nor swap
it. Where the designers built a reward negotiation into the tree it is a real entry and the driver uses it: MQ104
`MQ104BalgruufOutroA3` "I killed the dragon. I think I deserve a reward" (plain) and `MQ102BalgruufIntroA1` "What
about my reward?" (scripted, goodbye -> commit), TG00's `IntroMQ203C1` persuade variants, MG01's Faralda persuade,
MS05's verse persuades **[X]** - checks and picks like any other.

**v1.0 (must, M7): never an invented reward.** On a *quest turn* with this NPC (a journal quest of hers in `q`, or a
`last_result` younger than 180 s), a `reward` locked line rides `<locked_facts>`: "the reward for this is what the world
gives and nothing else: you cannot add septims, an item or a favour to it, and you cannot change it; if he bargains,
say plainly what you can and cannot do". And the truth gate (`lrgDlgTruthCheck`, `:2872+`) gains one clause: on a quest
turn, a `GiveGoldTo` / `TakeGoldFromPlayer` line with a septim amount no live entry or confirmed fact carries is
dropped (log both strings; her words still play). Cost: ~12 lines of PHP, no wire, no game change. Test: `test_gates`
new case (a quest turn, reply "I'll add 200 septims" + GiveGoldTo -> action dropped, sentence kept; a barter turn with a
`price` fact -> untouched). This is the owner's "never false (no invented prices)" applied to rewards.

**v1.0 should (S2): a bounded bonus in septims, real and small.** Decided YES, because the machinery exists and the
truth of it is game-verified; decided SMALL, because a big one is the glue inventing an economy. All of:
1. the player ASKS (`lrgIntentAmount`, or the words more / extra / bonus / sweeten / "for my trouble" on a quest turn);
2. she is that quest's giver (`qgiver=1`, or a `qal` alias of hers on a journal quest in `q`);
3. a REAL persuade check through the existing free-conversation machinery (`lrgDlgCheck`, kind persuade, stakes
   `reward` -> band from her stance + `bias`, threshold = the live Speech global of that band, amulet passes); a fail
   is remembered 86,400 s (no nagging), a pass grants Speech XP exactly as today (`do=award xp=1`);
4. the amount `N = min(the named amount or one day of HER wage tier, her purse from the snapshot gold=, one day of
   her wage tier)` - tiers as configured (common 40, performer 64, trade 100, fighter 132, craft/magic 200, court 500
   per day, `paid_intimacy.wage_tiers`) - and once per quest per NPC (memory key `reward|<quest>`);
5. the transfer is the glue's own `do=award` with a new additive key **`give=N`**: the game checks
   `npc.GetItemCount(gold) >= N` (else "she does not carry that much" - voiced), then `npc.RemoveItem(gold, N, true,
   player)`; the funcret says "exactly N septims, from her own purse"; `<what_just_happened>` tells the model as fact.
   Not CHIM's `GiveGoldTo`: its DLL short-purse behaviour is unreadable from disk (2.3) and it cannot be gated by the
   check.
6. Words: the locked line then reads "he may talk you into a small extra from your own purse - the game decides
   whether he did; promise nothing before it says so".
When a different reward (an item, a title, a favour) is asked: impossible, and she says so in her own voice through
the `reward` line; the never-false judge is NOT extended (a new claim class would be a fourth layer for a case the
truth gate already makes harmless - words without money). Priority `should`, after M1-M4 are green in game, because
nothing in it can be tested before `ev=result` and `do=award` have run once on this install.

---

## 7. Coverage of the main questline and the guild openings (which lines need which path)

Legend: **P** plain pick / **C** commit two-step (**C1** single-entry release) / **K** check / **$** pay / **E** the
engine opened it (forcegreet / blocking; visible; voice picks under M1, in a scene under S1) / **A** ambient open
(M3) / **F** faction hand-off (exact line) / **-** nothing to click (scene dialogue without a prompt). Winners from the
index **[X]**, scene placement **[I]** as in the survey.

| quest | the advancing beats | path in v1.0 | notes |
|---|---|---|---|
| MQ101 Unbound | Helgen | **-** | scene dialogue with no player prompt; AP owns the start on this save. Cannot be done by voice; nothing to design |
| MQ102 Before the Storm | Alvor/Gerdur "Hadvar said you could help" (TL S) / Irileth forcegreet "I need to speak to the jarl" -> A1/B1 (S G) / Balgruuf "I need to talk to you about Helgen" -> B1/B2/B3 (S G) / reward (S G O) | E+C1 / E+C / E(scene)+C / C | Balgruuf's throne scene is a blocking branch: S1. The three Helgen statements are `commit` by the scripted-goodbye rule; her question then his yes |
| MQ103 Bleak Falls Barrow | Balgruuf blocking (DBFB winner) / Farengar "So what do you need me to do?" (single S) / turn-in "I have the stone tablet" (TL S) / "Oh, do you mean this old stone?" | E(scene) / C1 / P or C | the assignment is the textbook single-entry release |
| MQ104 Dragon Rising | Irileth "What are your orders?" (blocking) / A1 "I'll come along" (S) / A2 (S G) / guards after the kill / Balgruuf "The dragon is dead" (TL) -> Outro B2/C1 (crit1 W) / "What about my reward?" | E / C1 or C / E / P then C / C | two walk-away rows = two asks; the reward line is real |
| MQ105 Way of the Voice | Arngeir "I am answering your summons" (forcegreet, one variant crit1) -> A1/A2 (S GO) -> B4 "I'm ready to learn" (S G) / horn blocking (GORE winner, crit1) / "I have the Horn" (TL) | E+C / C1 / E+C / P | courtyard scenes: S1 |
| MQ106 Horn of Jurgen Windcaller | Delphine "I'd like to rent the attic room" (TL, price token) / secret-room chain (14 crit1) / Kynesgrove "What should we do?" (7 variants) | $ / E(scene)+C or C1 / E | the attic line: `pay` below 100 -> click at once (the cost is the live "(N gold)"); the chain: M4 makes most of it one word each |
| C00 Take Up Arms | Kodlak "I would like to join the Companions" (TL S G, CaM winner) -> DontWorry/TeachMe (S G) / Vilkas spar / Eorlund "Vilkas sent me with his sword" (crit1 W) -> HappyToHelp (S G) / Aela "I have your shield" (TL S) / weapon choice (5 x S G) | **F**+C / C1 / E / E+C / C / P / C | the ONE clean free-standing join: the faction hand-off names the exact line, the two-step asks once, the engine does the rest |
| MG01 First Lessons | "Where can I learn more about magic?" (26 NPCs) / Faralda "May I enter the College?" -> persuade (ISD success variant) / "I'll take your test" (S) / Nirya's spell (cost 30) / Mirabelle tour (S G) / Tolfdir class (crit1 W) | P / P then **K** or C1 / $ / C / E(scene)+C | Faralda is free-standing: the first guild opening the click path can prove end to end |
| TG00 A Chance Arrangement | Brynjolf's approach (forcegreet, every layer crit1) / C1 persuade / "I'm ready. Let's get this started" (TL S G) | E+C or K / P then C | ten commits in a row: M4 (bare yes) is what makes it bearable |
| DB01 Innocence Lost | Aventus (forcegreet scene) / Grelod threat (S G) / ILQE guard route "Grelod is abusing the children" (TL) -> persuade / bribe (token cost) / thane | E(scene)+C / C / P then **K** | a guard with bounty 0 is not arrest-class (`bounty > 0` required, `lrgDlgArrestClass`) - unverified in game, first test V7 |
| DB02 With Friends Like These | captives "Who are you?" / "Would someone pay?" -> tag-only intimidate / persuade (always succeed) | E(scene)+P then K | the shack is engine-opened on waking; Astrid's own layers are not in the census (gap) |
| MS05 Tending the Flames | Viarmo "I'm looking to apply" (sub, off his hello) -> "What do you need me to do?" (S) / "I found King Olaf's Verse" (TL S) / verse layers (crit1 x9, two persuade variants) / court, induction | P / C1 / C / C or **K** / E | nine commits: bare-yes releases |
| CW00A / CW01A Legion | Tullius at the map table: the AP join line (S G, needs MQ101 complete) / Rikke "About that test" (blocking) -> "Consider that fort already yours" (S G) / oath chain (3 x crit1 single entries) -> "Long live the Emperor" (S G) | **A**+F+C / E+C / C1 x3 then C | opens only after Helgen; until then words + the Helgen line (kept). The quest-entry command is not needed |
| CW00B / CW01B Stormcloaks | Ulfric forcegreet / Galmar blocking -> "That's why I'm here. I want to join." (crit1) / oath chain (4 x crit1) | E+C / C1 x4 | no effects row was ever written; with M3 none is needed |

Honest limits: (a) beats that are scene dialogue with no player prompt (Helgen, the Greybeards' lesson lines, the
dragon-mound scene until it pauses) cannot be clicked because there is nothing to click; (b) an engine-opened menu on
a scene actor is driven only under S1, which ships after M1 is proven - until then those menus are visible and his to
click, exactly as today; (c) a cancel (Tab, "leave" with no back-out entry) plays the walk-away topic - by design, the
glue never cancels a layer for him; (d) the Speech-check outcome is the engine's; the glue reports it, never predicts
it to the player.

---

## 8. Calibration and dry-run policy

**Rows that go** (`LRG_DlgProbe.CalMissing`, 3233-3283 **[C]**): `hide` (A1 hide round trip - nothing is hidden),
`guard` (input guard - no guard), `rb` (state read-back - `Click()` performs that read-back live and returns -1 when it
fails, `LRG_DlgUI.psc:686-689`), `apd` + `reopen` (vanilla menu after a hide - no hide; the pristine `apd=` read stays
as a passive R1 measurement, logged, not a gate), `timer` as a GATE (kept as a measurement: `fLineSettle` falls back to
the subtitle path when `subsOn`, and to 0.8 s otherwise).
**Rows that stay**, all passive and learned on the first menu of any kind (`CalArm` / `CalLayer` / `CalReadProbe`):
`cm` counting, `rm` reading, `fam` layout, `st` Smart Talk settings.
**Route by doing**: `route` is written by the first click whose RESPONDING signal arrives (`StepResponding` signals 1-5)
- the deterministic table (`fam 1 -> B`) picks the route, the click proves it, and a click that returns `cr <= 0` or no
signal within 9 s flips the route for the next attempt (the existing 1/1b logic, moved from the press to the click).
**The stage rail** (M2): until `route` is proven (`clicks_ok >= 1` on the server's `*install*` row, from `ev=result
ok=1`), only `plain`/`back`, cost 0, unscripted, non-goodbye entries are clicked; everything else is voiced "choose it
on the menu the first time". The first live click is therefore on an entry whose worst outcome is one harmless line,
on a menu the player can see. That is the safest first click available without a DLL.
**The dry run** (`bDlgDryRun`): an ordinary owner toggle, default OFF, never forced, never auto-cleared; its refusal
stays voiced ("the menuless dry run is on - Menuless questing page"). `bDlgDryRunHold`, `bCalibOverride`,
`bCalibActive`, `bCalibAuto`, `iCalibRuns`, `iCalibAutoRuns`, `bAutoTimings`, `bProbe`, `iProbePress`,
`iKeyVanillaMenu` are removed; the Calibration page becomes a status line and "Forget what it learned".
**Persistence**: the install file `Data/SKSE/Plugins/LoreRimGlue_calibration.ini` stays (it is what fixed "0 of 10
every load"); it carries `cm rm fam st route` + measurements; `runs/armed/auto/gopen` go.
**The gate**: `CalGreen() = cm && rm && fam && st`; red -> the driver still reads and forwards (words work) and the
server voices "still learning the menu - the next conversation of any kind measures it" (it really does: every menu
open, E-pressed or glue-opened, runs `CalArm`/`CalLayer`).

---

## 9. Failure modes and their voiced reasons (every one is words, never silence)

| failure | where it is caught | what she says (`lrgVoicedWhy` / `SayReason` mapping) | what the log says |
|---|---|---|---|
| no list could be read (mode 3 empty twice, count -1 for 3 s) | `ReadList` / `StepListening` | "I did not catch what we could talk about - choose it on the menu yourself" | `read failed n= mode= try=` |
| the entry moved / the list changed before the click | `SelectAndVerify` -> stale x2 | "that moment has passed - say it again" | `stale: pos=` |
| the click did not take (`ClickResult` -1..-4) | `StepClicking` | "that did not take - choose it on the menu" | `click aborted result=` |
| nothing happened after the click (no signal in 20 s / 9 s route A) | `StepResponding` | "nothing came of that" (+ the route flips for next time) | `result ok=0 why=unverified` |
| not enough gold (server `afford`, then the game `GetGoldAmount`) | both | "you cannot pay that" / "not enough gold" | `gate: ... afford` |
| her voice not ready (`VkRestore`) | `StepClicking` | "give me a moment" | `her voice is not ready` |
| two entries fit / two slots / two join lines | server | her question: "which do you mean - X or Y?" | `mode=ask` |
| a consequential entry from intent mode | `lrgDlgAnswerWant` | her naming question (the two-step's step one) | `intent mode never executes it` |
| a commit parked and he refuses | `lrgDlgParkOrRelease` | "as you like" | `the parked selection is dropped` |
| lethal / arrest / resist arrest | server + `Arm` | "choose that yourself" + corner note | `stopped driving why=lethal` |
| stage rail (no verified click yet on this install) | server | "let me have you pick that one on the menu the first time" | `gate: stage rail` |
| the menuless dry run is on | game | "the menuless dry run is on (Menuless questing page)" | `WOULD CLICK` |
| the developer dry run is on | game | names the switch and the page (10.21) | `DRY RUN` |
| the open was refused (combat, a real quest scene, OStim, asleep, sneaking, mounted, too far) | `OpenBlockedReason` | the closed-list sentence ("not in a fight", "come closer", "not while you are sneaking about") | `open refused: <reason>` |
| the session died mid-turn (Tab, the engine, she walked off) | `CmdSelectTopic` not live | the pick re-opens (one greeting replays); if the open is refused: "we were interrupted - ask me again" | `closed why=external` then `open` |
| the utterance was blank / the model returned no words | never-empty | one floor line, then the re-ask rule | `never-empty` |
| a false enlistment / reward claim | never-false / the truth gate | the sentence is muted or the action dropped; her floor line | `never-false` / `truth gate` |
| a service menu opened over the session (barter, training) | `ServiceMenuOpen` -> SUSPENDED | nothing to say - the window is the answer | `closed why=handoff` |
| the index does not know the entry (a new mod) | `lrgDlgDecorateEntries` unindexed | plain entries still click on exact/similar words; commits need naming | `unindexed=` |

---

## 10. Performance budget (numbers; "today" from 2.3, "v1.0" is the target the tests assert where they can)

| item | today | v1.0 | how |
|---|---|---|---|
| Papyrus per poll while a menu is open | ~5.7 natives / 0.1 s (57/s) hidden; ~3 visible | **<= 3 per poll; poll 0.25 s outside CLICKING/RESPONDING** (<= 12/s idle, <= 30/s active) | M1 removes guard/hide; S4 slows the idle poll |
| Papyrus per changed layer | up to 72 UI reads + 3 | **<= 48** (head 16 x 2 + tail 16) | S4: `iTailMax` 40 -> 16 (`entries.keys` is 12 anyway) |
| facts line (60 s / NPC) | p50 0.82 s, p90 1.23 s, max 1.48 s | **p50 <= 0.5 s, max <= 0.8 s** | S4: cache the three forms in members; `QalCsv` every third line; drop `mqq`/`mq101` (keep `mq101c` for `lrgFacRoad`) |
| snapshot (~20 s) | ~25 natives + witness scan + 13 MCM reads | **-13 natives**: MCM knobs read once per `ReadSettings` (5 s cache) and stamped from a member string | S4 |
| commands per turn | up to 4 (pick + award + hint + release) | unchanged; QuestEntry gone | M3 |
| messages game -> server per turn | topics + result (+ line per subtitle) | unchanged; `ev=unhide`/`ev=resume`/`ev=calib` (except one per changed row) gone | M1/M2 |
| server, fast path (`lrg_topics`/`lrg_dlg`) | 2-7 ms measured | **<= 10 ms** | unchanged; `test_latency` 6 |
| server, LLM turn build | `prompt_ready` 58 ms median (all of CHIM's) | **<= 100 ms**, our share <= 30 ms | unchanged code paths |
| dialogue prompt on a business turn | ~3,200 chars (`<business>` ~1,450 + `<real_business>` ~450 + locked <= 600 + result/quest ~700) | **<= 2,500 chars**; locked <= 600 | S3: rules cut to four lines, `(+N more)` without the words, `<real_business>` dropped when `<business>` is present |
| empty replies | 10 % action-first vs 1 % character-first (10.23) | **<= 3 %** | S3: `reorder_json_scope = business` (config exists) |
| first-contact business click | 11-14 s (open after the LLM, then the second paid turn) | **<= 3 s after speech end** (D2 open pre-LLM ~1 s + read ~1 s + want=1 0.5 s + click), and never a second paid turn | M5 |
| LLM-turn pick | 6.5 s model + up to 4 s decide timeout parking + click | 6.5 s model + <= 1 s click; nothing waits on a timeout | M1 (the menu is simply open) |
| calibration before the first click | 10 rows, two of them click-only, one owner button | 4 passive rows on the first menu of any kind; route by the first click | M2 |
| game code | LRG_Dialogue 4,557 + DlgProbe 3,440 + DlgUI 1,289 lines | **~ -2,400 lines** (Probe -> ~700; Dialogue -> ~3,300) | section 11 |
| server code | lrg_dialogue 4,886 + lrg_factions 1,351 | **~ -600 lines** | section 11 |

What the budget does NOT touch: the model (85-95 % of a turn, 10.17d) - owner-side; TTS on the game GPU; STT.

---

## 11. What to REMOVE (file, function, why; every one a skew no-op)

GAME (`glue/game/LoreRimGlue/Source/Scripts`):
- `LRG_Dialogue.psc`: in `Arm()` the `Guard(true)/Hide/HideCursor` block (1828-1845) and the `assisted`/`unknown swf`
  visible reasons (keep `module off`, `smart talk skip`, `asked for`, `lethal`, `scene` until S1); in `Step()` the
  guard upkeep block (1888-1902), the `reqEmergency` / calibration blocks; `EnterPendingOrManual`/`StepPending`'s park,
  note and `sSilence` watchdog (PENDING = LISTENING with a decision outstanding); `HandBack()` -> `StopDriving(why)`
  (log + `ClearRequest`; no unhide, no note, no `ev=unhide`); `MaybeResume` + `ev=resume`; `HandleEmergencyKey`;
  `Finish()`'s `talkNeeded` (`requestMessageForActor("again", "lrg_dlgtalk")`); `calSess`/`openCalib`/`reqCalibPick`/
  `reqCalibLeave`/`ST_CALIB`; the `calib=1` branch of `CmdSelectTopic` (1215-1262); the `assisted`-after-`losses` rule;
  `ReadCalibration` (e0)/(e) auto-clear and forcing; `iBranchInput`/`x1` reads; `bRewalk` counters; `bResumeAfterChoice`,
  `bHandBackNote`, `iHideMode`, `bHideCursor`, `iEngineOpen`, `iKeyVanillaMenu` readers.
- `LRG_DlgProbe.psc`: everything but `Maintenance` (file restore), `CalArm`, `CalLayer`, `CalReadProbe`, `CalNoteReadCost`,
  `CalClose`, `CalSet/CalGet/CalG`, `CalMissing/CalGreen/CalAnswered/CalStatusText/CalWire/CalLogSummary`, `CalForget`
  and the file sync: i.e. delete `RunStep`/`Press*` (541-1359), `CalX1*`/`CalSpeech`/`CalPending`/`CalParkPoll`
  (2032-2172), the active pass (2276-2666), `CalPressClick*`/`CalPressReopen`/`CalReopenProof` (1791-1807, 2667-2909),
  `CalServiceNpc`/`CalAuto*` (2946-3232), `CalArmBudget` (3425+). ~2,700 lines -> ~700.
- `LRG_DlgUI.psc`: keep the primitives; delete nothing this round (`Hide/Unhide/Guard/Park` become unused; a later
  opt-in hide may want them; they cost nothing unused).
- `LRG_Main.psc`: `CmdQuestEntry` + `Qe*` (1799-2010) -> a 3-line stub answering `OK: Noted.` (skew: an old server
  that still sends it gets a quiet OK); `HandleCommand`'s calib note branch; the emergency key registration.
- `LRG_Profile.psc`: the per-snapshot MCM reads (S4, cached).
- MCM `config.json` / `settings.ini`: remove `bDlgDryRunHold iHideMode bHideCursor iEngineOpen iBranchInput bRewalk
  iRewalkDepth fSilenceTimeout bHandBackNote bResumeAfterChoice bProbe iProbePress bCalibPassive bCalibActive iCalibRuns
  bAutoTimings bCalibOverride bCalibAuto iCalibAutoRuns iKeyVanillaMenu bIaccToggle`; set `iSceneGate = 1`,
  `bDlgDryRun = 0`; keep `fDecideTimeout` (the fast-path wait), `fLineSettle`, `fOpenDistance`, `iClickRoute`,
  `iReadMode`, `iCountMode`, `iMaxEntries`, `iTailMax` (default 16), `bIntentOpen`, `bAllowNullVoice`,
  `bActivateDefaultOnly`, `bQuestColour` (off; the colour rank stays dormant), `iCritical`, `bCrimeManual`, `bServiceShortcut`.
  `tools/test_mcm_wiring.php` (every id read somewhere) is the gate for this list.

SERVER (`glue/server/lorerim_glue/lib`):
- `lrg_dialogue.php`: `lrgDlgCalibCandidate`, `lrgDlgCalibPick`, the `calib=1` branch of `lrgDlgAnswerWant`; the
  `assisted`/`losses` bookkeeping in `lrgDlgOnClosed` and its `do=show` rail; `lrgDlgHandBackForeseen`'s `bi`/`x1`
  branches; `ev=resume`/`ev=unhide` handlers (log unknown, `handled`); `lrgDlgInitiativeCandidate` + `<she_may_raise>`
  (off by default, a prompt block nobody turned on); the `rewalk` continuation depth (`rw`/`rwd`); config keys
  `auto_advance`, `assist`, `calib.auto`, `quests.initiative`, `match.cont_depth`, `session.silence_seconds`,
  `session.lost_seconds` (the lost list becomes the root cache).
- `lrg_factions.php`: `lrgFacQuestPlan`, `lrgFacQuestParam`, `lrgFacQuestNet`, `lrgFacEffectFor` (1208-1351) and the
  `effects` cells of the legion row; keep `lrgFacRoad` (the Helgen words) and the `closed` cells.
- `lrg_actions.php`: `lrgQuestEntryResult`, `LRG_ACT_QUESTENTRY`, the `GlueQuestEntry` catalog row (rows are
  reinstalled when missing; a row that exists and is inactive is harmless - remove it from `LRG_CATALOG_ROWS` and let
  the owner's DB keep the stale inactive row).
- Wire keys: `res=` and `stat=` on `ExtCmdLRG_SelectTopic` (read by nobody / off by default) stop being sent; `ev=calib
  k=` shrinks to the five rows; `ev=facts` drops `mqq`, `mq101` (keep `mq101c`).
- Tests/flows: delete `d64_auto_calib`, `d65_quest_entry`, `test_gates` 37, `test_dialogue` 13 (l) and 15; update
  `d50_wire05`, `d53_mcm`, `d21_contact`, `d26_commit`, `d40_quests`; add `d66_visible` and `d67_reward`.
- Docs: PROTOCOL 10.13 (rows), 10.26 (retired, one paragraph), 10.16 W5/W6 retired; README keys table.

Not removed, and why: the `(+N more)` bucket (the closed layer's "N further answers - ask which" is a never-false
rail), `TakeGoldFromPlayer` in `hide_gold` (inactive in CHIM but harmless), `bQuestColour`/`col` (dormant and cheap),
`lrgDlgServiceSlot` and every service rule (the one measured near-miss in the log is the teleport it prevents),
`lrgFacRoad`/`closed` (the truthful Helgen words cost a few string compares).

---

## 12. Proposals (name / problem / change / files / test / cost / priority)

**M1 - Visible-menu voice driving** (must)
- Problem: every part in front of the first click exists to keep the menu hidden; 0 clicks in game; PENDING freezes the
  player behind a hidden menu for up to 45 s on every LLM-turn pick (fDecideTimeout 4 s < 6.5 s model).
- Change: `Arm()` never hides or guards; a session the glue opened, or the engine opened outside a scene, is
  `driving` while visible; `CmdSelectTopic`'s "live but not driving" branch (1150-1170) accepts `pick` on such a
  session; `EnterPendingOrManual` -> stay LISTENING with the decision outstanding, no park, no note, no watchdog;
  `HandBack` -> `StopDriving(why)`; `MenulessLive() = bMenuless && !bDlgDryRun`; `Finish()` never asks for the
  `lrg_dlgtalk` turn (see M5). Server: `lrgDlgAnswerWant`/`lrgDlgGateItem` drop the `assisted`/`bi`/`x1` rails; the
  `ev=open` `hid=` value is 0 and documented as such.
- Files: `LRG_Dialogue.psc` (Arm, Step, EnterPendingOrManual, StepPending, HandBack, CmdSelectTopic, Finish,
  ReadCalibration), `lrg_dialogue.php` (lrgDlgOnClosed, lrgDlgAnswerWant, lrgDlgGateItem, lrgDlgHandBackForeseen),
  `PROTOCOL.md` 10.4/10.13.
- Test: flow `d66_visible` (ev=open hid=0 -> want=1 pick -> ev=result ok=1; an LLM-turn pick on the same open session;
  a pick after `ev=closed why=external` re-opens and resolves by `txt=`); `test_dialogue` 13 (the rails); in game: T1
  = Hulda "tell me about Whiterun" clicks the plain entry with the menu on screen (`clicked pos=` in the log).
- Cost: -2.7 natives per poll; -1 corner note; ~-900 Papyrus lines; 0 server ms.

**M2 - Calibration shrinks to four passive rows; route by the first click; the stage rail; dry run = a toggle** (must)
- Problem: 10 rows, 2 click-only, one button; per-save store until pt18; auto-clear needs the key bound and MCM Helper
  live; the automatic calibration session opens innkeepers' menus by itself; none of it has run in game.
- Change: `CalMissing` = `cm rm fam st`; `route` written by `StepResponding`'s first signal (or flipped on `cr <= 0` /
  9 s silence); server `*install*` row gains `clicks_ok` from `ev=result ok=1`; rail in `lrgDlgGateItem` +
  `lrgDlgAnswerWant`: `clicks_ok == 0` -> only plain/back, cost 0, unscripted, non-goodbye, else voiced; `bDlgDryRun`
  default 0, never forced; delete the active pass, presses, auto session (section 11).
- Files: `LRG_DlgProbe.psc`, `LRG_Dialogue.psc` (ReadCalibration, StepResponding, CmdSelectTopic), `lrg_dialogue.php`
  (lrgDlgOnResult, the rail, `lrgDlgLearningText`), `lrg_actions.php` (`lrgVoicedWhy` "first time" wording),
  MCM `config.json`/`settings.ini`, `test_mcm_wiring.php`.
- Test: `test_dialogue` new section "stage rail" (clicks_ok 0: a scripted entry -> voiced, a plain one -> pick;
  clicks_ok 1: both pick); `d50_wire05` (ev=calib k= with five rows accepted; an old `k=` with ten rows still parsed);
  in game: the second conversation of the evening clicks a scripted entry.
- Cost: -2,700 Papyrus lines; -1 MCM page of controls; the first click is on a harmless line on a visible menu.

**M3 - Open on ambient scene actors (`iSceneGate = 1`) and delete the click-free quest entry** (must)
- Problem: the map table is unreachable by open on both sides, so PROTOCOL 10.26 re-implements one join line with
  `SetStage` (the S2 path the research warned against), D1-only, licensed/unlicensed, six-quest cap, no Stormcloak
  row, untested; every other faction stays words-only.
- Change: `settings.ini` `iSceneGate = 1` (the existing `OpenBlockedReason` branch: open unless a journal quest owns
  him); `lrgDlgMaybeOpen` drops the `ambient` refusal (the game still refuses a real quest scene: `HasActiveJournalQuest`);
  `do=open` gains an additive `amb=1` for the log; the server's `ambient=1` turn keeps facts and words ON and now opens;
  delete `lrgFacQuestPlan/Param/Net/EffectFor`, the `effects` cells, `lrgQuestEntryResult`, `CmdQuestEntry` (stub).
- Files: `LRG_Dialogue.psc` (OpenBlockedReason unchanged; CmdSelectTopic log), `LRG_Main.psc` (stub), `lrg_dialogue.php`
  (lrgDlgMaybeOpen, lrgDlgBusinessMarker), `lrg_factions.php`, `lrg_actions.php`, `settings.ini`, PROTOCOL 10.24/10.26.
- Test: `test_dialogue` 13 (l) replaced by "an ambient recruiter turn with a join ask emits do=open;amb=1"; `d65`
  replaced by `d65_ambient_open` (Tullius after Helgen: open -> list carries the AP line -> lrgFacArbitrateWant exact
  line -> commit -> her question -> yes -> pick); `test_prompt_index` 5c keeps the AP line assertion; in game:
  Rikke/Tullius before Helgen = the Helgen line (unchanged), after Helgen = the real click. Verify first that
  `HasActiveJournalQuest(Tullius)` is false at the table (CW00A shows no objective before stage 20 on TCIY, pt18-quest 2b).
- Cost: -190 PHP lines, -210 Papyrus lines, -1 catalog row, -1 command; +1 `Activate` on a scene actor (vanilla does it).

**M4 - The two-step releases on a bare yes; single-entry commits release without a park** (must)
- Problem: `lrgDlgParkOrRelease` refuses `< 2 tokens` (2815-2818): "yes" never confirms; every single-entry commit
  (oath 1-3 x2 factions, MQ103's assignment, MS11's evidence) needs park + a second different utterance = three steps.
- Change: release when `lrgIntentYes()` (the paid-offer yes/deal recogniser) hits and the parked entry was named in her
  last message (`$st['last_said']` contains >= 2 of its meaning words), else the current rule; new
  `lrgDlgSingleEntryRelease($t, $e)`: layer closed, exactly one visible entry, class commit/plain, the utterance is
  speech, not a back-out, not a question (`?` or a question word first), and either an assent or >= 1 meaning word in
  common -> `release` without a park; `lrgDlgAnswerWant`'s "consequential never from intent mode" gets the same
  single-entry exception. Config `confirm.single_entry = true`, `confirm.bare_yes = true`.
- Files: `lrg_dialogue.php` (lrgDlgParkOrRelease, lrgDlgGateItem, lrgDlgAnswerWant), `lrg_config.default.json`.
- Test: `test_dialogue` new section: park -> "yes" releases; park -> "yes" with an unnamed entry stays parked; the
  CW01A oath chain as four single-entry layers with "I swear" / "I do" / "..." / "long live the Emperor" -> four picks,
  "wait, what does that mean?" -> words, "no" -> nothing; MS11: "I believe the killer is Wuunferth" (plain) -> Jorleif's
  question -> "not sure" -> nothing, "yes, we found his amulet" -> pick; `d26_commit` extended.
- Cost: ~60 PHP lines; 0 game; one fewer LLM turn per commit in practice.

**M5 - First contact without the second paid turn: the open goes out pre-LLM on the D2 route** (must)
- Problem: the open for awareness is emitted only in the post-LLM gate, so first contact = LLM turn -> open -> read ->
  want=1 -> (no match) -> close -> `lrg_dlgtalk` "again" = a second paid turn (11-14 s).
- Change: in `lrgDlgPrepareTurn`, when `list=none`, `io`, not a refused open (`lrgFacRefusesOpen`), and
  `lrgDlgBusinessMarker($t, utterance) !== ''` -> `lrgDlgQueue(npc, do=open;ask=<utterance words>)` (D2 only, ~1 s
  poll) and `$st['open_pending'] = cid`; `lrgDlgGateItem` skips its own open while `open_pending` is this cid; the
  want=1 answer that follows uses the utterance (and the model's `ask=` if the reply already landed); the model's reply
  is muted when a pick was emitted (existing). `Finish()` never requests `lrg_dlgtalk`; the server keeps the handler.
- Files: `lrg_dialogue.php` (lrgDlgPrepareTurn, lrgDlgGateItem, lrgDlgMaybeOpen), `LRG_Dialogue.psc` (Finish).
- Test: `d21_contact` rewritten (queue row before `call_llm`; no dlgtalk request; the want=1 pick; the LLM reply's
  SelectTopic for the same NPC is not emitted twice); `test_latency` asserts the pre-LLM path adds <= 5 ms.
- Cost: +1 D2 row per first contact; -1 LLM turn (5-7 s) on every unmatched first contact; -1 Papyrus request.

**M6 - The voiced-reason table is complete on the click path** (must)
- Problem: the new refusals (stage rail, single-entry wait, re-open after an external close, route flip) have no
  words; "read failed" and "click aborted" map to the generic "it just would not work right now".
- Change: `lrgVoicedWhy` gains the six mappings of section 9; `SayReason` (LRG_Main) the game-side three; every
  `ReportOnce("Error: ...")` string in `LRG_Dialogue.psc` is on the closed list (`test_gates` 35 parses the .psc).
- Files: `lrg_actions.php`, `LRG_Main.psc`, `LRG_Dialogue.psc`.
- Test: `test_gates` 24/35 additions (each reason -> a plain sentence, no machinery word); `d29` unchanged.
- Cost: strings only.

**M7 - Never an invented reward** (must) - section 6: the `reward` locked line + the truth-gate clause.
- Files: `lrg_dialogue.php` (lrgDlgLockedFacts classes, lrgDlgTruthCheck, `truth.classes` += reward).
- Test: `test_gates` (a quest turn, "I'll add 200 septims" + GiveGoldTo -> dropped, logged; a barter turn with a price
  fact -> untouched; the line is inside the 600-char cap with a faction line present - assert order and length).
- Cost: ~12 PHP lines; 0 game; the locked block stays <= 600 chars (the reward line is ~150).

**S1 - Voice picks on engine-opened menus of scene speakers** (should, after M1 is green in game)
- Problem: MQ102-MQ105, TG00, DB01, DB02, CW00B's advancing lines are blocking branches / forcegreets on actors in a
  scene; `Arm()` marks them `visible=scene` and refuses picks.
- Change: `vis = "scene"` no longer refuses when `origin == engine` (the engine paused the scene for the player); the
  server's `scene` rail in `lrgDlgAnswerWant` allows plain/back/single-entry, and commits through the two-step; the
  glue still never OPENS on a real quest-scene actor (iSceneGate 1 + journal check).
- Files: `LRG_Dialogue.psc` (Arm), `lrg_dialogue.php` (lrgDlgAnswerWant, lrgDlgGateItem).
- Test: `d66_visible` step "origin=engine scene=1": plain pick allowed, commit parked; in game: MQ102 Irileth at the
  door - "I have news from Helgen" by voice.
- Cost: 0 natives; one rail line.

**S2 - Bounded reward bonus in septims** (should) - section 6, `do=award give=N`.
- Files: `lrg_speech.php` (stakes `reward`, the intent), `lrg_dialogue.php` (lrgDlgAwardLine `give=`), `LRG_Dialogue.psc`
  (CmdAward: `give=` branch, `GetItemCount` + `RemoveItem` to the player), PROTOCOL 10.5.
- Test: `test_dialogue` new section (asks with/without a giver, pass/fail, the caps, once per quest); `d67_reward` flow;
  `test_gates` (the voiced OK "exactly N septims from her purse"; the short-purse refusal); in game: after a quest reward.
- Cost: ~120 PHP lines, ~25 Papyrus lines; 2 natives per award.

**S3 - Prompt diet and the reorder scope** (should)
- Problem: ~3,200 chars per business turn; action-first order gives 10 % empty replies vs 1 %.
- Change: `<business>` rules to four lines; `(+N more)` without the word bucket; `<real_business>` suppressed when
  `<business>` is present; `dialogue.reorder_json_scope = business` in the shipped config.
- Files: `lrg_dialogue.php` (lrgDlgBusinessBlock, lrgDlgStaticGuidance, lrgDlgJsonTemplate), `lrg_config.default.json`.
- Test: `test_dialogue` 13 (j) asserts <= 2,500 chars for the 12-key turn and <= 600 locked; flow 31 unchanged.
- Cost: -700 chars (~-175 tokens) per business turn; fewer never-empty re-asks (1.5-2 s each).

**S4 - Papyrus diet** (should)
- Change: `Step()` interval 0.25 s outside CLICKING/RESPONDING; `iTailMax` default 16; `SendFacts` caches
  `gvq`/`qHelgen` in members and runs `QalCsv` every third line; `BuildSnapshot` reads the 13 MCM knobs from
  `LRG_Dialogue.ReadSettings`'s 5 s cache (one accessor returning the stamped string).
- Files: `LRG_Main.psc` (timer), `LRG_Dialogue.psc` (Step, SendFacts, ReadList), `LRG_Profile.psc`.
- Test: none offline (Papyrus); in game the `facts ... ms=` p50 <= 500 over an evening; `d20_wire` still parses the
  tail at 16.
- Cost: -60 % polls idle, -33 % UI reads per layer, -0.3 s per facts line, -13 natives per snapshot.

**L1 - The hidden menu as an opt-in polish** (later): once clicks are proven, `iHideMode > 0` re-enables
`Hide/Unhide` (primitives kept) with the `hide`/`apd`/`reopen` rows back as its own gate. Not before.
**L2 - Fragment analysis in the index builder** (later): parse the `TIF__*.pex` named in VMAD for `SetStage` /
`CompleteQuest` / `StartCombat` / `RemoveItem` so "scripted" splits into quest-moving and cosmetic; the
scripted-goodbye commit rule then applies to the first only (fewer two-steps on cosmetic exits). Smart Talk does it
natively (`SmartTalk.ini:29`), so it is practical.

---

## 13. The owner's first evening with v1.0 (the order that proves the most with the fewest words)

1. Any conversation by E (or by voice) -> `cal=4` in the log after one menu (cm rm fam st).
2. Hulda / any innkeeper, free-standing: "tell me about Whiterun" -> the menu opens on screen, the plain entry is
   clicked, her real line plays, `clicked pos=` + `result ok=1` in the log, `route` written, `clicks_ok=1`.
3. Same NPC: "I'd like a room" -> the hub click, her "Of course.", the duration layer, "one night" -> the priced click
   (below 100: no ask) - `svc=inn` and the real price on the wire.
4. Faralda on the bridge (MG01): "may I enter the College?" -> the chain; the persuade variant as a check; the test.
5. Kodlak: "I would like to join the Companions" -> the exact line -> his question -> "yes" -> the join.
6. A guard with a bounty: the menu stays his, the note says so, `stopped driving why=lethal`.
7. (after S1) Irileth at the Dragonsreach door by voice. (after M3, after Helgen) Tullius at the table.

---

## 14. What cannot be done, plainly

- Helgen and every other scene beat with no player prompt: there is nothing to click; the engine plays it.
- A different reward: the fragments fix it; the glue says so and adds at most S2's small real bonus.
- A cancel without a back-out entry plays the walk-away topic: the glue never cancels a layer on his behalf.
- The model's 6.5 s and TTS: not ours; the design only makes sure nothing waits on them (no timeouts on the click path).
- Predicting a check's outcome: only the engine knows (compound conditions, PNAM re-ordering - p2 C4/B6).
- Exact INFO identity without a DLL: text + `topicIndex` re-verified live is the best available and it is enough.
- The one thing M1 cannot test offline: whether `Click()` really fires on THIS install. That is step 2 above, on a
  harmless line, on a menu the player can see. If it does not, the menu is still his and the log says which route.
