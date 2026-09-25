# P2 CRITIQUE — ENGINE AND UI REALITY (adversarial pass on `glue/PHASE2_DESIGN.md` build 0.3.0)

Role: adversarial design critic, lens = **engine and UI reality**. Read-only round; nothing was run in game, nothing outside this
file was written.

Method. I read `glue/PHASE2_DESIGN.md` in full, then re-opened the primary sources it leans on instead of trusting the reports:
the decompiled AS2 of the winning SWF (`research/p2_swf_disasm_chim.txt`, 17k lines) and of the fallback SWF
(`p2_swf_disasm_norden16x9.txt`), CHIM's Papyrus sources under `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts`, SKSE's
`UI.psc` / `ObjectReference.psc` / `Actor.psc`, PapyrusUtil's `MiscUtil.psc`, the live `glue/game/.../LRG_Main.psc`, and the
adversarial sections of `p2-dialogue-swf-ui.md`, `p2-lorerim-dialogue-stack.md`, `p2-prior-art.md`, `p2-chim-interplay.md`.

Conventions: **[V\*]** = I re-opened the primary source in this round and quote it (file:line); **[V]** = verified in a report's
primary source, not re-opened; **[I]** = my inference.

## Verdict: **REVISE FIRST**

The mechanism survives the attack. "Open the real session, hide it, read the engine's list, click it through the menu's own
path" is sound, and the bytecode backs every path name the design uses. What does not survive is a set of **specific
sequences**: one that can leave the player's *vanilla* dialogue permanently unclickable (O1), one whose stated guarantee
("the greeting always plays to its end") is implemented with a function that cannot see the engine's speech at all (O2),
one that hides an arrest before it knows it is an arrest (O3), one single-point read with no fallback that silently reduces
the whole feature to "nothing ever happens" (O4), and one that would restore a broken menu on the emergency key (O6).
O1–O6 are cheap to fix and four of them change what probe P0–P17 must measure — which is why they must land **before**
GAME-UI writes `LRG_DlgUI.psc`, not after.

Counts: 2 critical, 9 major, 5 minor.

---

## Objections

### O1 — CRITICAL — §1.3 (rows CLOSING / "IDLE (on close)"), §1.2 `Guard`, §1.10 U1
**The input guard is never released on the normal close path. A poisoned `_global` static can make ordinary vanilla dialogue
permanently unclickable.**

Scenario. The player runs one successful business turn with Adrianne. The session closes cleanly (`why=goodbye`), IDLE
restores the cursor, reads the five Speech globals and reports `drift`. Two minutes later the player walks up to Belethor
and presses E — with menuless off, or in dry-run, or after the emergency key. The greeting plays, the topic list appears,
and **neither the mouse, nor E, nor Enter selects anything, ever**; only TAB closes the menu. The player concludes the
install is broken and the glue gets blamed for vanilla dialogue.

Mechanism [V\*]. `Guard(true)` writes the **class static** `_global.DialogueMenu.ALLOW_PROGRESS_DELAY = 100000000`
(default `750`, `p2_swf_disasm_chim.txt:521`). The only code that ever sets `bAllowProgress = true` is `SetAllowProgress`
(`:377-380`), and it is scheduled by `StartProgressTimer` = `bAllowProgress=false; clearInterval(iAllowProgressTimerID);
iAllowProgressTimerID = setInterval(this,"SetAllowProgress", DialogueMenu.ALLOW_PROGRESS_DELAY)` (`:369-375`) — the delay is
read **when the timer is armed**, and every later `NotifyVoiceReady → OnVoiceReady → StartProgressTimer` (`:366-368`) re-arms
with whatever the static holds. Without `bAllowProgress`, `onItemSelect` (`:427-446`) and `SkipText` (`:447-451`) are both
dead. `Guard(false)` exists in §1.2 but is called from exactly one place in the design: `Unhide()`/U1 (§1.10), i.e. only on
MANUAL. The CLOSING and IDLE rows of §1.3 do not call it. Whether `_global` survives a menu close is **explicitly unproven**
(`p2-dialogue-swf-ui.md` §11 open question 5 / P11: "whether the static guard leaks into the next session (if it does, U1
must reset it on every open)"). The design bets the player's vanilla dialogue on that unproven lifetime.

Change (all four).
1. Add `LRG_DlgUI.Guard(false)` to the CLOSING row **and** to "IDLE (on close)", unconditionally, before `ev=closed`.
2. Write `ALLOW_PROGRESS_DELAY = 750` at the **top of every ARMING**, before the family probe — including the dry-run /
   `bMenuless=0` / `forceVisible` branch, which today skips the guard block entirely and is therefore the branch a poisoned
   static would be discovered in.
3. Do the same one-line repair in `LRG_Main.Maintenance()` (it costs one native and fixes a save whose loop stack was lost).
4. Keep P11's read of the static on the *re-open*, and make a red P11 promote (2) from defensive to mandatory.

### O2 — CRITICAL — §1.3 (CLICKING), §2.3 (row "Say-Once greetings"), §1.12 (P-matrix)
**`AIAgentFunctions.isActorTalking` cannot see the engine's dialogue audio, so the design's "the greeting always plays to its
end — never skipped" guarantee is not implemented. The call does not even type-check.**

Scenario. The player says "I'll take the job" to an NPC whose quest greeting is Say Once with a start-quest fragment. CHIM's
`InterruptNPC` already ran at dispatch (F1), so CHIM is **not** speaking. The command reaches the game, the glue opens the
session, waits its 0.6 s floor, asks `isActorTalking(npc)` → 0 immediately, and clicks **while the engine is still playing
the one-time greeting**. The greeting is cut, the glue's own `ev=line` ground truth is truncated, CHIM's capture of it is
unproven anyway (X3), and the one-time line is spent with the player having heard half of it from behind a hidden menu.

Evidence [V\*]. `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentFunctions.psc:33`:
`int function isActorTalking(String npc) Global Native` — it takes a **display name**, not an `Actor`, so `isActorTalking(npc)`
as written in the CLICKING row does not compile. Every production use waits for **CHIM's own TTS**:
`AIAgentAIMind.psc:3134-3155` (carriage driver), `:3196`, `:3249-3364` (guards), with CHIM's own comment
*"Smart wait: if the driver starts speaking, wait until speech ends before fast travel. Fallback timeout prevents getting
stuck if speech state is never reported."* Nothing in it touches `MenuTopicManager` or the engine's dialogue voice.
Design §2.3 claims the opposite guarantee and cites `SmartTalk.ini:109-116` (skipping too early can stop a fragment).

Note on fairness: clicking during a greeting is *reachable in vanilla* (the engine sends `ShowDialogueList` in
`SHOW_GREETING` with entries, `p2_swf_disasm_chim.txt:400-414`, so a human can click while the greeting plays). The
objection is not "this is impossible", it is "the design states a guarantee and implements it with a no-op".

Change.
1. Fix the call: `AIAgentFunctions.isActorTalking(npc.GetDisplayName()) == 0`, and demote it to what it is — a
   **CHIM-TTS** gate (still useful: never click while CHIM audio plays).
2. Add a real engine-side gate for the **first click of a session**: `MenuState() == 1` must have been observed at least
   once, **and** a line-settle test — with subtitles on, `Subtitle()` unchanged for `fLineSettle` (0.4 s); with subtitles off,
   `UI.GetInt(M, D+".iAllowProgressTimerID")` unchanged for `fLineSettle`. That member is a plain Number re-assigned by
   `clearInterval`/`setInterval` on every `StartProgressTimer` call [V\* `:369-375`], and the engine drives that through
   `NotifyVoiceReady → OnVoiceReady` per response line [I]; a changing id would therefore be a **voice- and
   subtitle-independent "a new line just started" signal** — the one primitive the design is missing. It costs one probe
   line to settle: sample it in P1 alongside `eMenuState` and after the click in P8. If P1 shows it changing once per
   session rather than once per line, fall back to (2) = `MenuState() == 1` plus the subtitle test alone.
3. Rewrite §2.3 to the truth: the glue never calls `SkipText`, and it does not click before the list has been shown and the
   line has settled; beyond that a click during a greeting is exactly as safe as a human's.

### O3 — MAJOR — §1.3 (ARMING), §2.7, §1.11 (`iCritical`)
**ARMING hides and guards *before* it classifies the session, so an arrest or a bag check spends up to 45 s hidden.**

Scenario. A guard ForceGreets the player over a 40-gold bounty. `OnMenuOpen` fires with no `glueOpened`; ARMING's **first**
three acts are `Guard(true)` → `Hide()` → `HideCursor(true)`. Only afterwards does it compute `crit`, and the *primary*
crit test is the server's (`twat`) — a `lrg_topics` round trip. The player now sees a guard talking at him, an invisible
menu he cannot answer, and hotkeys that are asleep because 13+ scripts think "Dialogue Menu is open" (F14). Best case the
server answers in 0.4 s and `do=show` un-hides; worst case the reply is late or lost and PENDING's `fSilenceTimeout` (45 s)
runs while the guard waits. For the six City Bag Checks INFOs the game-side test cannot help at all — the design says so
itself (F11: they carry no crime-gold condition).

Evidence. Design §1.3 ARMING ("FIRST, in this exact order: `Guard(true)` → `Hide(iHideMode)` → `HideCursor(true)`. Only then
… `crit` = 2.7 test"); §2.7 ("**This is the primary test**"); §1.11 `iCritical` 0 = "critical sessions handed back visible".

Change.
1. For `origin=engine`, run the **game-side** crit test *before* the hide: `speaker.IsGuard()` [V\* `Actor.psc:379`] and
   `speaker.GetCrimeFaction().GetCrimeGold() > 0` [V\* `Actor.psc:178`] are three frame-synced natives (~50 ms) — cheaper
   than the two Sets of `Hide()`.
2. Default engine-opened sessions to **visible until classified**: new `iEngineOpenHide` (0 = classify first, default; 1 =
   hide at once). With 0, ARMING arms only the guard, leaves the menu visible, reads the first list, and hides only after a
   non-critical classification arrives. A ForceGreet showing its menu for ~0.4 s is what the player expects anyway.
3. A crit session must also skip `Guard(true)`: with the guard armed and the menu handed back, the player cannot click
   "I submit. Take me to jail." at all until `Guard(false)` has run.

### O4 — MAJOR — §1.2 `EntryCount()`, §1.3 (LISTENING), D-1
**`EntryCount()` is the one read in the whole driver with no calibration and no fallback, and it is built on the single path
the research never found a production example of.**

Scenario. `UI.GetInt(M, "…TopicList.EntriesA.length")` returns 0 on this GFx build (a numeric/array member the path walker
does not resolve, or a type mismatch). `EntryCount()` is 0 forever, LISTENING never exits, every session times out after
20 s into MANUAL, and the feature is dead in a way that is **indistinguishable from "this NPC has no topics"** — including
in the probe, because P2 only asks "when does `EntriesA.length` first become > 0".

Evidence. Design D-1 replaced the arithmetic on `iMaxScrollPosition` with `EntriesA.length` on the strength of *AS-internal*
use (`ClearList` = `this.EntriesA.splice(0, this.EntriesA.length)`, `p2_swf_disasm_chim.txt:12349-12351`) — that shows the
array has a `length`, not that `GetVariable` walks to it. The research rates R2 (`iMaxScrollPosition + 1`) **85 %** and R2b
(`EntriesA.length`) **70 %** (`p2-dialogue-swf-ui.md` §7.1) and closes with "Array-index path components: two more web
searches, still no production example" (§H). Every *other* read in §1.2 has three calibrated modes; this one has none.

Change.
1. Make `EntryCount()` mode-calibrated exactly like `EntryText`, remembered in a new `iCountMode`: mode A
   `EntriesA.length`; mode B `entryList.length`; mode C `iMaxScrollPosition + 1` **cross-checked against a non-empty
   `EntryText(0)`** (necessary because `CalculateMaxScrollPosition` yields 0 both for one entry and for none).
2. P2 must log all three on the same list, plus `iSelectedIndex` and `iPlatform` (see O11).
3. LISTENING's timeout must distinguish `why=no-count` (no mode produced a number while `EntryText(0)` is non-empty) from
   `why=no-entries`, so one evening of testing tells the owner which of the two happened.

### O5 — MAJOR — §1.2 (`EntryText` mode 5, `EntryRowItem`), §1.3 (CLICKING)
**Read mode 5 indexes screen rows while the click path indexes array positions; the two are not the same number.**

Scenario. Modes 3 and 4 fail, the driver calibrates to mode 5. An innkeeper has 11 topics; CHIM's SWF lays out 8 clips
(`iMaxItemsShown = 8`, counted in the list constructor, `p2_swf_disasm_chim.txt:12308-12340`). `EntryText(pos,5)` reads
`Entry<pos>.textField.text` — a laid-out clip — while `SelectAndVerify(pos, …)` writes `iSelectedIndex = pos`, an **array
position**. On a scrolled or long list row k ≠ position k, so the verification read of `selectedEntry.text` fails, the driver
reports `stale`, re-reads, fails again and ends every layer with "that moment has passed". Nothing wrong is clicked (the
F7 defence holds), but the feature dead-ends instead of degrading.

Evidence. Design §1.2 (`EntryText` mode 5 = `…Entry"+aiPos+".textField.text`, described as "screen row, scroll-dependent";
`EntryRowItem(int aiRow)` exists but no state in §1.3 ever calls it); the clip's own mapping is `itemIndex`, written in
`UpdateList` (`p2_swf_disasm_chim.txt:16429-16445`: `r2.itemIndex = r4` next to `EntriesA[r4].clipIndex = …`).

Change. Either (a) in mode 5 the driver's `pos` is **always** `EntryRowItem(row)`, `n` comes from `EntryCount()` while
`sent ≤ iMaxItemsShown`, and entries outside the laid-out rows are never offered; or (b) — recommended — declare mode 5
**read-only** (topic dump and probe), and treat "only mode 5 works" as a P3/P4 failure that goes to decision D1. §1.12's
pass criterion "(P3 **or** P4 **or** P5)" must be tightened accordingly.

### O6 — MAJOR — §1.2 (hard rules 1–8), §1.4, §1.10 (U1)
**The hard rules pick a getter for every AS type except the floats the recovery path is built on, and `GetInt` is `(UInt32)`.**

Scenario. `Hide()` stores `TopicListHolder._x` with `UI.GetInt`. If the holder sits at a negative `_x` in this stage
(1280×720 for CHIM's SWF; the vanilla list is laid out from the safe rect), the stored "original" comes back as ~4.29e9.
The player then presses the emergency key: `Unhide()` restores `_x = 4294966976`, i.e. the topic list is handed back
**still off-screen**. The player now has a visible speaker name, a subtitle, no topics and no cursor target — the exact
state the emergency hotkey exists to prevent, produced by the emergency hotkey.

Evidence. Design §1.2 rules 1–8: rule 6 assigns GetString/GetInt/GetBool by AS type but never mentions display properties;
rule 5 only forbids *writing* a negative through `SetInt`. Research: "`GetInt` is `GetT<UInt32>`: `(UInt32)number` — use
**GetFloat** for anything that can be -1" and H2 is written with `UI.SetFloat` (`p2-dialogue-swf-ui.md` §4, §7.3).

Change. Add rule 9: *every display property (`_x`, `_y`, `_alpha`) is read with `GetFloat`, written with `SetFloat`, and
stored in a Papyrus `float`.* `Unhide()` must refuse to restore a stored value it never read successfully (fall back to the
SWF's own re-show, see O12) and report `why=no-stored-x`. P6 must log both `_x` values *before* hiding.

### O7 — MAJOR — §1.3 (RESPONDING), D-5, §5.2 C13
**The 1.5 s "unverified" rule contradicts D-5 and fires on exactly the clicks that matter most — after the engine has
already taken the gold.**

Scenario. The player has dialogue subtitles off (a player-facing toggle the design itself honours through `SubtitlesOn()`).
He bribes a guard; the response is a 4 s voiced line. Within 1.5 s: the menu has not closed, no other menu opened, and the
state still reads the 2 the driver wrote — and IACC re-writes 2 every frame while the NPC talks, so it *cannot* read
anything else. The driver declares `ok=0;why=unverified`, un-hides mid-line and stops driving the session, while the bribe
succeeded and the gold is gone. The server then composes `<what_just_happened>` from a failed result.

Evidence. Design §1.3 RESPONDING ("Nothing → … for `persuade, intimidate, bribe, pay, commit, meta` → MANUAL with
`ok=0;why=unverified`"); D-5 ("Nothing in the driver may depend on a subtitle or a speech event"); IACC forces
`eMenuState = 2` while the NPC talks (`p2-dialogue-swf-ui.md` §5, claim 10; STACK §2.2, re-derived in R2.1 claim 5).

Change.
1. Raise the check-kind window to **≥ 4 s** (the SWF's own floors are 0.33–0.47 s and Fuz Ro D-oh holds an unvoiced line
   1–10 s, §5.3) and treat it as a *soft* signal.
2. Before declaring `unverified`, run the settle poll that CLICKING already captured PRE facts for (`Game.QueryStat`, gold,
   `IsBribed`, `IsIntimidated`). **Any** movement is positive verification; `ok=0` must never be reported when a delta was
   seen.
3. Add `iAllowProgressTimerID` as the primary "a response started" signal (O2), with the subtitle as a secondary.
4. `unverified` must never un-hide *before* the settle poll has run — otherwise the un-hide itself races the fragment
   (STACK V.3-A2: the bookkeeping happens asynchronously in the fragment).

### O8 — MAJOR — §2.1 step 1, §2.2, §2.3 (row "Say-Once greetings")
**Intent-mode `do=open` *is* an open-for-awareness, and it can burn a Say-Once greeting; §2.3 promises it never happens.**

Scenario. First contact with a quest giver, no cached list. The player says "I hear you've been having trouble." The model
returns `TakeUpBusiness item="ask about the trouble"`. The glue opens a session, the NPC's Say-Once quest greeting plays
behind a hidden menu **and its fragment runs**, no entry matches the intent words, the session closes and the turn degrades
to `lrg_dlgtalk`. The one-time line is spent, the player heard it from inside a hidden menu, and whether CHIM captured it
is unproven (X3).

Evidence. Design §2.3: "**Say-Once greetings, greeting fragments** | Never consumed for awareness. A session is opened only
to execute" — versus §2.1 step 1 (intent mode with no list) and §2.2's own admission: "A no-match first contact costs a
greeting, a goodbye bark and one ordinary CHIM turn". F13 prices every open.

Change. State the cost in §2.3 instead of denying it, and gate it: emit `do=open` only when (i) the NPC has no usable
cached root, (ii) the new `bIntentOpen` (default 1) is on, and (iii) the server can name a business marker in the
utterance. Otherwise answer with `lrg_dlgtalk` and harvest the list from a session that was going to happen anyway (the
E-press "hail"). Add `opens_without_match` to the MCM diagnostics so T3 produces the real rate.

### O9 — MAJOR — §1.5 (refusal table)
**`npc.GetCurrentScene() != None` refuses a large fraction of town NPCs; the engine itself allows the player through.**

Scenario. The player faces Carlotta while the market's ambient banter scene is running (the city `Dialogue…` quests own
those scenes), or a bard mid-song, or two inn patrons. Every business turn for those NPCs is refused with "a quest scene is
running" and falls back to `lrg_dlgtalk` — i.e. the headline feature is off exactly where the quest givers stand. A human
pressing E interrupts the scene without ceremony, and **CHIM already ships code to do the same** (`AIAgentPapyrusFunctions.psc:1313-1330`
stops the bard quest, `AIAgentAIMind.psc:1886-1906` stops and restarts the scene, with the author's own "can break quests").

Evidence. Design §1.5 row `npc.GetCurrentScene() != None` [V\* `ObjectReference.psc:245`]; §2.3 already handles scene
sessions when the *engine* opens them, so the asymmetry is a policy, not a safety property.

Change. Keep the refusal only where it buys something: refuse when `crit`, when the player is one of the scene's actors, or
when `PO3_SKSEFunctions.GetActiveAssociatedQuests(npc)` (already gathered for the `q` key) intersects an active journal
quest; otherwise open. Ship it as `iSceneGate` (0 = refuse always — the safe first-playtest default; 1 = refuse only quest
scenes) and **count the refusals** in the MCM diagnostics so T2/T3 decide it with data. At minimum §1.5 must say this
refusal is expected to fire often.

### O10 — MAJOR — §1.3 (state diagram), §1.11 (`iPlayerActivate`)
**`iPlayerActivate = 0` cannot be implemented: Papyrus cannot tell an E-press from a ForceGreet.**

Scenario. The owner leaves `iPlayerActivate = 0` because he wants his own E-presses to stay a normal, visible menu. He
presses E on a merchant. `OnMenuOpen` fires without `glueOpened`, the state machine routes it to `origin=engine` → ARMING →
`Guard` + `Hide` + `HideCursor`. His menu vanishes. The setting he used to prevent exactly this has no implementable
meaning: `OnMenuOpen` carries only the menu name, and `Game.GetDialogueTarget()` gives the speaker, not the cause.

Evidence. Design §1.3: "IDLE --OnMenuOpen we did not cause (**E-press**, ForceGreet, guard, courier, blocking greeting,
scene)--> ARMING (origin=engine)"; §1.11 "`iPlayerActivate` 0 (0 = an E-press stays a visible vanilla menu…)"; §2.1 step 5
"and — with `iPlayerActivate = 1` — the player's own E-press".

Change. Either (a) drop `iPlayerActivate` and make **all** engine-opened sessions visible/assisted (this is O3's default and
costs nothing: their lists are still harvested), or (b) implement the distinction: `LRG_Main.OnKeyDown` stamps
`lastActivateAt` for the activate key the owner declares in the MCM, and ARMING calls an open within 1.0 s of that stamp
`origin=player`. (b) is fragile against Dynamic Activation Key, Simple Activate and Immersive Interactions' ~1 s animation
delay — which the design's own §2.3 follower row already warns about — so (a) is recommended.

### O11 — MAJOR — §1.2 (D-2 rationale), §1.7 (route mechanics), §1.12 P2
**D-2's claim that the write/read-back removes the stale-selection hazard "entirely" is wrong on both sides, and the engine
can move the selection with no player input at all.**

Two errors.
1. SKSE: `Get*`/`Set*` are ordinary **delayed** natives (frame-synced, ~1 frame each); only `Invoke*` carries
   `kFunctionFlag_NoWait`. The design quotes this correctly in the same paragraph and then concludes "the write and the
   read-back happen inside the same Papyrus call" (`p2-dialogue-swf-ui.md` C1, re-derived in R2-C4 and R2.1 claim 11).
   Today's order — `SetInt iSelectedIndex` → [`SetInt iScrollPosition`] → `GetInt topicIndex` → `GetString text` →
   `SetInt eMenuState` → `GetInt eMenuState` → queued `Invoke` — puts **two writes and one read between the identity check
   and the click**, i.e. ~3 frames plus the queue tick.
2. SWF: the engine's `PopulateDialogueLists` ends with `TopicList.InvalidateData()` (`p2_swf_disasm_chim.txt:381-399`),
   `CenteredScrollingList.InvalidateData` ends with `this.UpdateList()` (`:14519-14527`), and `DialogueCenteredList.UpdateList`
   **overwrites** `iSelectedIndex` and `iHighlightedIndex` whenever `bRecenterSelection` is set or `iPlatform != 0`
   (`:16455-16460`) — and `iPlatform` is **1** by default in the list constructor (`:12306`). So a repopulate moves the
   selection behind the driver's back with no stray input involved.

Scenario. A timed quest event repopulates the list in the 3-frame window; the verified entry is gone; `selectedEntry` is now
the engine's pre-selected entry (D-14) and the queued `topicClicked()` sends **its** `topicIndex`. A wrong INFO runs, with
its fragment, unverifiably.

Change. (1) Delete "entirely" and state the residual window honestly. (2) Reorder the click so both verifications are the
**last** frame-synced calls before the queued `Invoke`: `SetInt iSelectedIndex` → (route A: `SetInt iScrollPosition`) →
`SetInt eMenuState` → `GetString selectedEntry.text` → `GetInt selectedEntry.topicIndex` → `Invoke topicClicked`, with the
`eMenuState` read-back folded in before the text read. (3) P2 must log `iPlatform` — the research's P2 had it, the design's
P2 dropped it, and `iPlatform != 0` makes every repopulate recentre the selection, which also affects route A's
`UpdateList` path.

### O12 — MAJOR — §5.3 (Smart Talk row), §1.6 ("Not covered by any guard")
**Smart Talk's native skip defeats the guard for the keyboard too, not only the mouse — and the only defence is an owner
ini setting the glue never checks.**

Scenario. A hidden session is open and the NPC is delivering a quest line. The player presses **E** (the most-pressed key in
the game) to interact with something, or Space to jump. Smart Talk writes `bAllowProgress = true` natively and invokes
`SkipText` → `GameDelegate.call("SkipText")` → the engine cuts the line, exactly the hazard Smart Talk's own ini documents
(`iPapyrusHandle=3`, "skipping too early can stop a fragment"). G1/G2/G3 cannot stop a native invoke.

Evidence. STACK V.2-C3 and R2.1 claim 7: the installed Smart Talk 1.0.5 writes `bAllowProgress = true` at
0x180070d56-0x180070d72 **before** the `bFadedIn` test and does not swallow the input; `bFadedIn` is true for the whole
session, so the keyboard path is live. Live settings: `SmartTalk.ini:92 bSkipImmediateOnInput=1`, `:95 bHoldToSkip=1`.
Design §1.6 says only "A mouse click during a response can still skip the line".

Change. Make it a **machine-checked precondition** instead of a line in the manual. PapyrusUtil is installed:
`MiscUtil.psc:54 bool function FileExists(string fileName) global native` and `:58 string function ReadFromFile(string
fileName) global native` (read-only, root-relative — MO2's VFS resolves `Data/SKSE/Plugins/SmartTalk.ini`). In
`LRG_Dialogue.Maintenance()`, read that file; if `bSkipImmediateOnInput` is not 0, refuse to arm menuless (or force
`bDlgDryRun = 1`) with one notification naming the setting; same for `bHoldToSkip`. This writes nothing and removes the
single hazard the design admits it cannot guard.

### O13 — MINOR — §1.10, §7.2 item 2 (D-13)
**The emergency-key edit as specified also bypasses `UI.IsTextInputEnabled()`, and its premise is already settled the other
way.**

`LRG_Main.psc:184` is `if Utility.IsInMenuMode() || UI.IsTextInputEnabled()` → `return` [V\* re-opened; the line number
still matches]. D-13 says "let the emergency and leave keys run before the `Utility.IsInMenuMode()` early return at :184".
Taken literally, both keys become live while the player is typing in CHIM's Prisma chatbox, `UITextEntryMenu` or the
console — one letter hands the menu back or leaves the conversation. And the premise is already answered: dialogue is
**not** menu mode (CK wiki via the BellCube mirror, quoted in `p2-chim-interplay.md` §1.1: *"Dialogue, because the game
isn't paused, will not put the game into 'menu mode'"*), and nothing in the Ultra profile makes the Dialogue Menu pausing
(no Skyrim Souls RE, no "Hold on a sec").

Change. Keep `if UI.IsTextInputEnabled(): return` first; bypass **only** the `Utility.IsInMenuMode()` half, and only for
`keyVanillaMenu` / `keyLeave` while `dlg.IsSessionOpen()`. Keep P0's log line — a `true` reading would mean something in the
profile turned the Dialogue Menu into a pausing menu, which would also kill every CHIM hotkey (`SafeProcess`,
`AIAgentPapyrusFunctions.psc:1228-1251`), and that is worth knowing.

### O14 — MINOR — §1.10 (U1), §1.3 (SUSPENDED)
**U1 repairs only `eMenuState == 3`; after a service menu or an IACC write it hands back a menu with no list.**

Scenario. The player clicks a barter entry, trades, closes the barter menu. While it was open the driver kept the state
parked at 3, so the engine's returning `ShowDialogueList` was **dropped** — `DoShowDialogueList` acts only from state 2 or
from 0 with entries (`p2_swf_disasm_chim.txt:400-414`), and `TopicListHolder._visible` was never set true. Headless still
works (the driver reads `EntriesA` directly), but if the player now presses the emergency key while IACC has written state 2,
U1's repair (`state == 3 && EntryCount() > 0` → set 1 + `_visible`) does not fire and he gets a menu with no topics.

Change. Replace U1's hand-written state repair with the SWF's own entry point: after restoring `_x`, if `EntryCount() > 0`
and the state is not 1, call `UI.InvokeBool(M, D+".ShowDialogueList", false)` — `ShowDialogueList(abSlideAnim=false,
abCopyVisible=undefined)` sets `TopicListHolder._visible = true`, plays `fadeListIn` and the frame-33 script sets
`menuState = TOPIC_LIST_SHOWN` by itself (`:415-426`, frame scripts per STACK R2.1 claim 3). Keep the manual `eMenuState = 1`
only as a second attempt.

### O15 — MINOR — D-4, §1.7, §1.2 (`SwfFamily`)
**Family-2 support is declared mandatory but is specified only for the click route; Norden's SWF moves the very property the
hide is built on.**

If D0 is answered "hide CHIM's SWF", or a CHIM update drops its *optional* `dialoguemenu.swf`
(`AIAgent/Optional/TraditionalDialoguePlayerTTS/`, PRIOR R-A1), family 2 becomes the live path on day one. There:
`InitExtensions` does `TopicListHolder.Lock("L"|"R")` and then **relative** writes
`TopicListHolder._x = (_x ± 400) + DialogueMenu.TOPICS_X`, `._y = _y - TOPICS_Y`
(`p2_swf_disasm_norden16x9.txt:379-386`), where `TOPICS_X/Y` come from an **asynchronous** `LoadVars` of
`deardiary_dm/config.txt` (`:335-358`) — so on an early open they can still be `NaN`; `topicsFadeIn/Out` animate
`TopicListHolder._alpha` (`:450-467`); there are 13 entry clips, not 8; and the movie runs at 60 fps, so every timeline
floor in §1.6 halves. The design's family handling covers the click route and `iMaxItemsShown` only.

Change. In `Hide()`, validate the stored `_x`/`_y` (`abs(v) < 10000` and not NaN) and record `fam` with them; `Unhide()`
falls back to O14's `ShowDialogueList` path when the stored value is not usable. Set `iMaxEntries` from
`iMaxItemsShown` when the read mode is row-based (O5). State in §1.6 that the floors are per-family (30 fps CHIM, 60 fps
Norden). Add one family-2 line to the P-matrix: run P6 and P11 once with CHIM's SWF hidden, *before* D0 is answered.

### O16 — MINOR — §7.2 (integrator edits)
**The integrator's edits are anchored to line numbers in a file another team is editing right now; one has already drifted.**

`glue/game/LoreRimGlue/Source/Scripts/LRG_Main.psc` is now **751 lines**. `:183`/`:184`/`:199`/`:222` still match the
design's citations [V\*], but the initiative-tick line cited as `:602` is now **:608**
(`if Utility.IsInMenuMode() || UI.IsTextInputEnabled() || UI.IsMenuOpen("Dialogue Menu")`) [V\*], and
`CurrentVersion` is at `:11` with the value still 200. An integrator applying a line-anchored patch after the Phase 1
team's next commit edits the wrong statement, silently.

Change. Re-express every edit in §7.2 as *"the statement that reads `<verbatim code>` inside function `<name>`"*, with a
verification grep per edit, and do the same for `tools/make_esp.py:44`. Cheap, and it is the difference between a
mechanical integration and a debugging session.

---

## What I attacked and found sound (so the builders do not re-litigate it)

- **Route B is correct and unguarded.** `topicClicked()` = `timerBool = false; GameDelegate.call("TopicClicked",
  [TopicList.selectedEntry.topicIndex])`, no guard of any kind (`p2_swf_disasm_chim.txt:514-518`); `startTopicClickedTimer("off")`
  has the identical body (`:504-513`). `selectedEntry` is a plain getter over `EntriesA[iSelectedIndex]` (`:12482-12484`),
  so writing `iSelectedIndex` does determine what is clicked — subject to O11's window.
- **D-14 is right and load-bearing.** `PopulateDialogueLists` ends with `SetSelectedTopic(arguments[last])` unless it is -1
  (`:381-399`), and `SetSelectedTopic` writes `iSelectedIndex = 0; iScrollPosition = 0` *before* searching (`:16548-16562`).
  Arming the guard first is the right call.
- **G2/`bDisableInput` is stronger than the design claims.** `bDisableInput` is written only in the list constructor
  (`:12297`) and by `__set__disableInput` (`:12505`) — nothing in the SWF resets it — and it gates `handleInput` (`:12355`),
  `onMouseWheel` (`:12389`, `:16533`), `onItemPress` (`:12647`) and the entry clips' `onRollOver`/`onPress` (`:12318`,
  `:12328`). A press on an entry clip therefore dies in `onItemPress`, not only off-screen.
- **G3 is right about `DoShowDialogueList`.** Body wrapped in `if (this.timerBool == false)`, and the show runs only from
  `TOPIC_CLICKED`, or `SHOW_GREETING` with `entryList.length > 0` (`:400-414`).
- **The hide-by-`_x` choice is right for family 1.** `Lock()` is one-shot (`:10817-10856`, no resize listener), sprite 35 has
  one frame, and nothing in CHIM's SWF writes `TopicListHolder._x` — the only positional write is
  `ExitButton._x -= 50` in `InitExtensions` (`:312`), which runs before Papyrus sees the menu. `AdjustForPALSD` shifts
  `DialogueMenu_mc._x` by -35 (`:326-328`) and is harmless.
- **The family probe works.** `HIDE_TOPICS` is a class static with the string default `"false"` on Norden
  (`p2_swf_disasm_norden16x9.txt:678`, set from config at `:341`) and absent on CHIM's; the `iMaxItemsShown` fallback is
  valid at ARMING because the value is **counted in the list constructor** (`p2_swf_disasm_chim.txt:12308-12340`), not on
  first populate.
- **`Utility.IsInMenuMode()` as a SUSPENDED trigger is safe** — it is false in dialogue [V-doc] and turns true exactly when a
  pausing menu opens on top, which is what the state wants.
- **`Cursor Menu` is a real menu name** [V\* `UI.psc:10`], as used by `Luca_HideUIConfigMenu.psc:7`.

## Residual risk this round could not remove

Everything O1–O16 touches is still **untested in game**. The two that would most change the design if they came back red
are: `EntriesA.length` / numeric path resolution (O4 → decision D1) and `_global` lifetime across menu opens (O1). Both are
one probe press away, and both should be read *before* GAME-DRIVER starts.
