# pt9 go-live — GAME SIDE adversarial pass (v0.5.1)

Assumption for the whole pass: the owner has set `bMenuless=1`, `bDlgDryRun=0`, the calibration is
green (`CalMissing()` == "", so `counting / reading / progress timer / hide round trip / input guard /
click route / state read-back / vanilla-menu-after-a-hide / Smart Talk / menu layout` are all
answered), and `bCalibActive` is back off. Everything below is traced to code; where a step depends on
a value nobody has measured on this install, that is said out loud.

Paths are relative to
`glue/game/LoreRimGlue/Source/Scripts/` and `glue/server/lorerim_glue/`.

## What held up

* **No indirect quest write anywhere.** `grep -n "SetStage|SetObjectiveCompleted|CompleteQuest|Fragment|
  SetBribed|SetIntimidated|SetCrimeGold|StartConversation|ForceGreet"` over all ten `.psc` files returns
  only the two comment lines in `LRG_Dialogue.psc:8-9`. The only game-state writes on the whole dialogue
  path are `Game.AdvanceSkill`, `Game.IncrementStat`, `player.RemoveItem` (all in `CmdAward`,
  `LRG_Dialogue.psc:1104-1127`) and the UI natives in `LRG_DlgUI`. The hard rail holds.
* **The click cannot fire twice from one verified selection.** `Click()` clears `sat` before the single
  queued `Invoke` (`LRG_DlgUI.psc:573`), and `Click()` refuses on a selection older than 2 s
  (`LRG_DlgUI.psc:540-545`). `XPush(reqX)` runs *before* `Click()` (`LRG_Dialogue.psc:2150`), so a
  second delivery of the same `x` is dropped in `CmdSelectTopic` (`LRG_Dialogue.psc:865-867`).
* **A bare selection cannot click.** With `i<0` and `txt==""`, `SelectAndVerify` never writes `sat`
  (`LRG_DlgUI.psc:515-522`) and `Click()` returns `-3`. The probe's own bare write
  (`LRG_DlgProbe.psc:2528`) is therefore genuinely inert.
* **Route is never guessed.** `ChooseRoute()` returns 0 on an unknown family and `Arm()` then goes
  MANUAL (`LRG_Dialogue.psc:1436-1437`).
* **Service destinations.** The server's price-list rule replaces (not supplements) the similarity
  matcher on the fast path (`lib/lrg_dialogue.php:2074-2098`), and the game's own `SvcScan` is
  diagnostic only (`LRG_Dialogue.psc:1739-1783`) — nothing in the driver branches on it.

## Findings

### G1 — CRITICAL — the emergency key pressed while the glue is opening a conversation leaves a hidden, guarded, MANUAL menu

**Scenario.** The server sends `do=open` / `do=pick` for an NPC the player is not yet talking to.
`StartOpen()` sets `dlgState = ST_OPENING` and hands the `Activate` to the pump
(`LRG_Dialogue.psc:1006-1008`). `DoOpen()` then spends up to 3 s on the voice keeper
(`LRG_Dialogue.psc:1194-1198`) plus up to 3 × 1 s on the `Activate` retry loop
(`LRG_Dialogue.psc:1207-1220`). The player, who did not ask for this conversation, presses the
emergency dialogue key inside that window.

**Evidence.** `HandleEmergencyKey` (`LRG_Dialogue.psc:752-788`): `IsSessionOpen()` is **true**
(`dlgState == ST_OPENING`, `loopBeat` refreshed by `DoOpen`'s waits), but `loopRunning` is **false**
— `RunSession()` has not started, `DoOpen` runs on the pump's stack. So control falls past the
`reqEmergency` branch at 779-782 into the "the loop's stack was lost" branch at **784-786**:
`DoUnhide("key")` then `dlgState = ST_MANUAL`.

`DoUnhide` (`LRG_Dialogue.psc:2650-2684`) contains two `Utility.WaitMenuMode(0.5)` calls (2667, 2672),
so it is still running on the key stack when `DoOpen`'s `Activate` succeeds. `OnMenuOpen` →
`RunSession` → `Arm()` then hides and guards the new menu (`LRG_Dialogue.psc:1441-1458`) and sets
`ST_LISTENING` (1496). `HandleEmergencyKey` never set `forceVisible`, and `Arm()` clears it at 1439
anyway — the "asked for" escape at 1424-1425 is not reached.

Order of the writes: the key stack finishes last, so it (a) reports
`WARNING unhide could not bring the topic list back on screen` + `press TAB to close this menu`
(`LRG_Dialogue.psc:2678-2682`, `HolderX()` is 5000 because `Arm` just hid it) and (b) writes
`dlgState = ST_MANUAL` **over** `ST_LISTENING`. `isHidden`/`guarded` were cleared at 2655-2657 and then
set true again by `Arm`, so the session is now `MANUAL` **and** hidden **and** guarded.
`StepManual` (`LRG_Dialogue.psc:2343-2375`) never unhides.

**Result.** The player pressed the escape hatch and got: an empty guarded dialogue menu
(`bDisableInput=true`, `ALLOW_PROGRESS_DELAY=100000000`), a "press TAB" notification, and no topic
list. A *second* press recovers (`reqEmergency` → `HandBack` → `GoManual` → `NeedsUnhide` →
`DoUnhide`), and TAB gets out — but TAB never reaches `Unhide()`, so `LRG.du.hid` stays 1 (see G8).

**Smallest fix.** First thing in `HandleEmergencyKey`, before the `IsSessionOpen()` test:

```
if openWanted || dlgState == ST_OPENING
    openWanted = false
    openNpc = None
    dlgState = ST_IDLE
    forceVisible = true
    ClearRequest(false)
    return false        ; LRG_Main opens the plain vanilla menu
endif
```

and, defensively, set `forceVisible = true` on *every* branch of `HandleEmergencyKey`, not only the
two idle ones (762-776).

---

### G2 — MAJOR — `reqEmergency` outlives its session and aborts the next one

**Scenario.** The conversation ends on its own (she says goodbye, or the player TABs out) a fraction of
a second after a click. `Finish()` runs; because `dlgState == ST_RESPONDING` it calls
`SettleAndReport(true)`, which for a check/pay kind waits up to **4 s** in
`Utility.WaitMenuMode(0.25)` (`LRG_Dialogue.psc:2256-2262`), refreshing `loopBeat` each time. Inside
that window the player presses the emergency key.

**Evidence.** `IsSessionOpen()` is still true (state is not `ST_IDLE` until
`LRG_Dialogue.psc:2493`), `loopRunning` is still true, so `HandleEmergencyKey` takes 779-782:
`reqEmergency = true`, `return true` — and because it returned true, `LRG_Main.OnKeyDown:377-379` does
**not** open the vanilla menu either. The loop never polls again (its `go` test at 1288 already failed),
so nothing consumes the flag. `Finish()` then calls `ClearRequest(**false**)`
(`LRG_Dialogue.psc:2433`), and `ClearRequest` only clears `reqEmergency` / `reqDump` / `reqWant` /
`openWanted` under `abHard` (`LRG_Dialogue.psc:3680-3686`). `Arm()` (1294-1345) does not clear them
either.

**Result.** The press does nothing at all, and `reqEmergency` stays latched. The **next** glue-driven
session — minutes later, a different NPC — is handed back on its very first `Step()`
(`LRG_Dialogue.psc:1529-1532` → `HandBack("key", false)`), with the "choose this one by hand"
notification and an `ev=unhide why=key` the server has no reason to expect. `reqDump` latches the same
way and fires a full `DoDump` into the next session if it lands inside its 30 s window (1533-1540).

**Smallest fix.** Call `ClearRequest(true)` from `Finish()` instead of `ClearRequest(false)` (the hard
flags have no meaning across sessions), or clear the four flags explicitly in `Arm()`.

---

### G3 — MAJOR — any entry past `iMaxEntries` is structurally unclickable, and the server picks them

**Scenario.** A jarl, a quest giver mid-chain, or an innkeeper with a long rumours list has more than
16 topics. The model names one of the later ones (or the gate returns a `T`-key pointing at one).

**Evidence.** `ReadList` caps the head at `sMaxEntries` (default 16,
`LRG_Dialogue.psc:1672-1690`) and reads positions `cap..tailMax` into `tText`
(`LRG_Dialogue.psc:1702-1713`). `BuildTail` sends them with their **true array positions**,
`(eHead + t)`, and topicIndex `-` (`LRG_Dialogue.psc:2898-2912`). The server parses `-` as `i = -1`
(`lib/lrg_dialogue.php:691`) and merges them into `session.entries` by position
(`lib/lrg_dialogue.php:638-648`).

Both server emit paths then draw from head **and** tail: the intent-mode pool at
`lib/lrg_dialogue.php:2327-2328`, and the `T`-keys at `lib/lrg_dialogue.php:1509-1518` (built from
`lrgDlgRank`'s score-ordered head, which spans the whole list). The emitted `pos` is the game's array
position (`lib/lrg_dialogue.php:2457-2461`).

Back in the game, `TryResolvePick` branch (a) requires `reqPos < eHead`
(`LRG_Dialogue.psc:1965`) and branch (b) searches only `eText[0..eHead)`
(`LRG_Dialogue.psc:1974-1980`). Both fail. `StepClicking` would refuse it anyway
(`LRG_Dialogue.psc:2052-2058`).

**Result.** The decision dies on `fDecideTimeout`, the player gets
`Error: that is not on the table right now`, and `EnterPendingOrManual` puts a **glue-opened, hidden**
session into `ST_PENDING` (`LRG_Dialogue.psc:1991-2005`) where it sits for `fSilenceTimeout`
(45 s by default) before the watchdog hands it back (`LRG_Dialogue.psc:2039-2042`). Forty-five
seconds of a frozen, blank dialogue menu, repeatable on every long-list NPC. The player's only quick
way out is the emergency key.

**Smallest fix.** `SelectAndVerify` writes `iSelectedIndex` and works for *any* valid array position
— only the driver's own bookkeeping is the limit. In `TryResolvePick`, add before the fallback:

```
if reqPos >= eHead && reqPos < nTotal && reqTxt != "" && (readMode == 3 || readMode == 4)
    if StringUtil.Find(LRG_DlgUI.EntryText(reqPos, readMode), reqTxt) == 0
        reqIdx = LRG_DlgUI.EntryTopicIndex(reqPos, readMode)
        return true
    endif
endif
```

(one extra UI read, only on that path). Belt: have the server drop a pick whose entry carries
`tail == 1` and whose `pos >= sent`.

---

### G4 — MAJOR — `inScene` is frozen at ARMING, so a scene started by our own click is driven anyway

**Scenario.** The driver clicks a perfectly ordinary quest entry ("Lead the way", "I'll follow you",
any LoreRim/Requiem walk-and-talk). Its INFO starts a quest scene on the speaker. The engine pushes the
next layer; the driver reads it, sends it, and the server picks again.

**Evidence.** `inScene` has exactly one writer, `Arm()`
(`LRG_Dialogue.psc:1392`: `inScene = speaker.GetCurrentScene() != None`) plus the reset in
`ClearSession` (3697). Every consumer reads that ARM-time value: the D-17 PENDING refusal
(`LRG_Dialogue.psc:1994`), the resume arming (2544) and the resume itself (2582), `ActiveCalibSafe`
(1505), `ev=open`/`lrg_topics` `scene=` (2812, 2928), and the `lrg_dlgtalk` gate (2471). `crit` and
`speaker` are likewise written only at 1391 / 1351-1353. Nothing in `Step()` (1511-1605) re-reads any
of them.

**Result.** The design's promise — "a scene speaker never enters PENDING", "never open on a scene
actor" (`OpenBlockedReason:1027-1034`) — is enforced only against scenes that already existed when the
menu opened. A scene the glue's own click creates is invisible to it, `scene=0` keeps going out on the
wire, and the server keeps ranking and clicking into a scripted sequence.

**Smallest fix.** In `ReadList`, on the branch where the signature really changed
(`LRG_Dialogue.psc:1718-1735`), re-read it once per layer:

```
if !inScene && speaker != None && speaker.GetCurrentScene() != None
    inScene = true
    HandBack("scene", false)
    return true
endif
```

One native per layer, not per poll.

---

### G5 — MAJOR — a second command silently drops the first one's funcret

**Scenario.** Two decisions for the same NPC are in flight — the D2 responselog echo of an earlier
`x` arriving on the DLL's 5 s poll while a fresh `want=1` fast-path `x` is already stamped, or a
`do=show` followed by a `do=pick`. Both pass `XSeen` (the ring is only written at click time,
`LRG_Dialogue.psc:2150`).

**Evidence.** `CmdSelectTopic` stamps the new request over the old one at
`LRG_Dialogue.psc:920-941` and sets `reqReported = false` at 937 — with **no** `ReportOnce` for the
request being overwritten. Contrast `HandBack`, which does exactly that and explains why:
"a decision that was in flight … is closed HERE, because `ClearRequest()` below would otherwise drop
it silently and CHIM would wait for a result that never comes" (`LRG_Dialogue.psc:2530-2536`).

**Result.** The first `x` never gets `funcret` / `commandEndedForActor`
(`LRG_Main.psc:544-555`). On the CHIM side that command stays pending for its own timeout; on the
server side `last_exec.told` for that `x` is never resolved.

**Smallest fix.** Immediately before the stamp block at `LRG_Dialogue.psc:919`:

```
if reqX != "" && !reqReported
    ReportOnce("Error: that moment has passed")
endif
```

---

### G6 — MAJOR (both toggles required) — row vs position in the colour the server scores on

**Scenario.** `bQuestColour:Dialogue = 1` on the game side and `quest_colour` on in the server config;
the NPC's list is longer than the laid-out row count (8 on CHIM's SWF) or is scrolled.

**Evidence.** `BuildEntries` sends `col = "" + LRG_DlgUI.EntryColour(i)` where `i` is an **array
position** (`LRG_Dialogue.psc:2866-2867`). `EntryColour(aiRow)` reads
`…TopicList.Entry<row>.textField.textColor` and its own docstring says it takes a **screen row**
(`LRG_DlgUI.psc:431-439`); `EntryRowItem()` exists for exactly this mapping
(`LRG_DlgUI.psc:441-449`, "Row k != position k on any scrolled or long list") and the topic **dump**
uses it correctly (`LRG_Dialogue.psc:2756-2765`). `BuildEntries` does not.

The server then scores on it: `if quest_colour && (int)$e['col'] === 16767334 { $s += 3; }`
(`lib/lrg_dialogue.php:1438`) — a +3 against a quest weight of 4 and a similarity weight of 6
(`lib/lrg_dialogue.php:1426-1434`).

**Result.** Smart Talk's quest colour is attributed to the wrong entry, the ranker prefers it, and the
glue clicks a *different* quest topic than the coloured one — text-verified, so it clicks exactly what
the server named, which is the wrong entry. This is a wrong-entry-clicked path, not a lost-input one;
it is only inert because both toggles ship off.

**Smallest fix.** `col` is worth sending only when row == position. Either guard it:
`if sQuestColour && nTotal <= maxItems && i < maxItems` — or map it properly by scanning
`EntryRowItem(r) == i` for `r < maxItems`. Do not leave it as-is and turn the toggle on.

---

### G7 — MAJOR — a conversation that opens while the previous loop is still in `Finish()` is never armed

**Scenario.** A courier / ForceGreet fires, or the player re-activates the same NPC, within a few
seconds of a driven click.

**Evidence.** `OnMenuOpen` returns silently when `loopRunning && (now - loopBeat) < 5.0`
(`LRG_Dialogue.psc:1254-1258`). `Finish()` keeps `loopRunning` true until its very last line
(`LRG_Dialogue.psc:1291`) and refreshes `loopBeat` throughout: `SettleAndReport`'s 4 s settle loop
(2256-2262) and `DoUnhide`'s two 0.5 s waits (2667, 2672).

**Result.** For that conversation there is no `Arm()`, therefore:
* no `GuardReset()` — and step 0 of ARMING is the *only* repair for a poisoned
  `ALLOW_PROGRESS_DELAY` (`LRG_Dialogue.psc:1303-1304`, `LRG_DlgUI.psc:694-704`, rule R1);
* no hide, no `lrg_topics`, no `ev=open` — the feature is silently absent;
* `IsSessionOpen()` still returns **true** for the session that is finishing
  (`LRG_Dialogue.psc:719-730`), so for those seconds `LRG_OStim.DialogueBusy`
  (`LRG_OStim.psc:2624-2635`), `ConvRefuseReason` (`LRG_Main.psc:1166-1169`) and `RepairFollower`
  (`LRG_Main.psc:1684-1687`) all refuse work on behalf of a session that no longer exists.

**Smallest fix.** A `bool finishing` set at the top of `Finish()` and cleared at the bottom; in
`OnMenuOpen`, treat `finishing` like a lost stack and re-arm:

```
if loopRunning && !finishing && now >= loopBeat && (now - loopBeat) < 5.0
    return
endif
```

---

### G8 — MINOR — `LRG.du.hid` / `hok` / `hfam` are never reset, so a lost hide latches for the whole save

**Scenario.** The loop's stack is dumped, or the game is quit / crashes, while the list is at
`_x = 5000`. (`HardReset` only calls `Unhide()` when the menu is still open,
`LRG_Dialogue.psc:2696-2698`.)

**Evidence.** The only load-time reset is `LRG_DlgUI.ResetCalibration()`
(called from `LRG_Dialogue.psc:369`), which clears `cm`, `rm`, `lp` and nothing else
(`LRG_DlgUI.psc:888-893`). `IsHidden()` reads the latched `hid` (`LRG_DlgUI.psc:801-804`).

**Result.** `NeedsUnhide()` (`LRG_Dialogue.psc:2507-2521`) returns true on *every* open menu for the
rest of the save, so every MANUAL transition and every `Finish()` performs a full `DoUnhide` — two
`WaitMenuMode(0.5)`, `Guard(false)`, `ShowList()`, an `ev=unhide` — on a perfectly healthy vanilla
menu. `CalActiveWanted()` refuses every active test for ever with `driver-hidden`
(`LRG_DlgProbe.psc:2260-2263`). G1's TAB exit produces exactly this state.

**Smallest fix.** Add `SSetInt("hid", 0)`, `SSetInt("hok", 0)`, `SSetInt("hfam", -1)` to
`ResetCalibration()` (it already runs once per load and the stored floats are re-read on every
`Hide()`).

---

### G9 — MINOR — a middle-only change to a layer does not bump `gen` and is never re-sent

**Evidence.** `newSig = aiCount + "|" + eText[0] + "|" + eText[eHead - 1]`
(`LRG_Dialogue.psc:1714`). A layer whose count and whose first and last entries are unchanged but
whose middle differs (a persuade entry swapped for another after the freeze, a topic consumed and
replaced) leaves `sig` equal, so `gen` is not incremented, `sentThisGen` stays true and
`SendTopics` is never called (1716-1720).

**Result.** The server keeps stale positions for the rest of that layer. A pick then fails branch (a)
(`eText[reqPos]` no longer starts with `reqTxt`) and branch (b) (the text is gone) at
`LRG_Dialogue.psc:1965-1988`, and dies on the decide timeout. Never a *wrong* click —
`SelectAndVerify` (`LRG_DlgUI.psc:485-524`) verifies the live text and topicIndex — but a lost turn
the server cannot diagnose, because it was never told the list moved.

**Smallest fix.** Fold the identities into the signature, e.g.
`newSig = aiCount + "|" + eText[0] + "|" + eText[eHead-1] + "|" + (eIdx[0] + eIdx[eHead/2] + eIdx[eHead-1])`
— no extra UI read, the indices are already in hand.

---

### G10 — MINOR — the whole SUSPENDED path hangs on `mmBase`, which is an unmeasured assumption and is not a gate row

**Evidence.** `mmBase = Utility.IsInMenuMode()` is taken once, at ARMING
(`LRG_Dialogue.psc:1346`). The suspension test is `!mmBase && Utility.IsInMenuMode()`
(`LRG_Dialogue.psc:1567`) and so is the "a menu opened on top = hand-off = success" signal
(`LRG_Dialogue.psc:2211-2215`). The project's own note says nobody has measured what
`IsInMenuMode()` returns from inside a Dialogue Menu on this install
(`LRG_DlgProbe.psc:2276-2279`), and `CalMissing()` has no row for it
(`LRG_DlgProbe.psc:2832-2882`) — so a green calibration says nothing about it.

**Result if it reads true.** A Barter / Training / Gift window over a driven session is never seen.
`StepReading` finds `EntryCount() == 0`, drops to `ST_LISTENING`, and the 20 s watchdog
(`LRG_Dialogue.psc:1628-1630`) hands the menu back — unhiding the topic list underneath the player's
open trade window and firing `ev=unhide why=watchdog`.

**Smallest fix.** `OtherMenuName()` already enumerates the nine windows by name
(`LRG_Dialogue.psc:2293-2316`). Drive the suspension off `LRG_DlgUI.OtherMenuOpen("BarterMenu") || …`
for the three service windows instead of off `IsInMenuMode()`, which costs three natives only when the
entry count has just gone to zero.

---

### G11 — MINOR — combat, mount and arrest are gated only at OPEN, never during a session

**Evidence.** Every one of those tests lives in `OpenBlockedReason`
(`LRG_Dialogue.psc:1011-1056`), whose only caller is `StartOpen`
(`LRG_Dialogue.psc:998`). `Step()` (1511-1605) has no combat, mount or bounty test, and `crit` is
never re-classified (`ClassifyCrit` is called once, 1391).

**Result.** A bandit ambush or a guard's arrest ForceGreet that lands after the session armed leaves
the hidden, guarded menu being driven through it. OStim is not exposed (its own start is blocked by
`DialogueBusy`, `LRG_OStim.psc:2624-2635`), and the conversation hold releases itself
(`LRG_Main.psc:1420-1434`) — the dialogue driver is the one that does not look again.

**Smallest fix.** One test on the PENDING and DECIDING polls:

```
if Game.GetPlayer().IsInCombat() || (speaker != None && speaker.IsInCombat())
    HandBack("combat", false)
    return
endif
```

---

### G12 — MINOR — `smartSafe` is read once per game load

`smartSafe = LRG_DlgUI.SmartTalkSafe()` runs only in `Maintenance()`
(`LRG_Dialogue.psc:377`) and decides, at 1425-1426, whether every session in the whole game session
goes visible. The owner who fixes `Data/SKSE/Plugins/SmartTalk.ini` on the Calibration page's advice
gets no effect until a reload, while `CalStatusText()` (`LRG_DlgProbe.psc:2917-2923`) reads the file
live and will already say green. Fix: refresh it inside `ReadSettings()`'s existing 5 s cache.

## Ranking for go-live

Fix **G1, G2, G3, G5** before the flag goes live: they are the ones a single evening of play will hit,
and G1 defeats the emergency key, which is the last line of defence for everything else.
**G4** and **G7** next. **G6** is the only wrong-entry path found and it is currently switched off —
do not turn `bQuestColour` on until it is mapped. The rest are cleanup.
