# P2 CRITIQUE — LoreRim + Requiem + CHIM compatibility, and the owner's words

Adversarial review of `glue/PHASE2_DESIGN.md` (build 0.3.0, 2026-09-21 09:39).
Role: design critic. Read-only everywhere; this file is the only thing written.
Lens: the real load order, CHIM's real constraints, and the owner's four demands — branching handled
appropriately, persuade/intimidate/bribe working with the LLM, fully generic and data-driven, never breaking quests.

**VERDICT: REVISE FIRST.** Four critical objections, eight major, eight minor. None of them attacks the
decided mechanism (headless real session, engine picks the INFO) — that mechanism is sound and its
Papyrus/SWF/plugin citations survived every spot-check I ran. What fails is the **edges**: where the design
hands work to CHIM, to Smart Talk, to a timeout, or to a rule written in another section.

## 0. What I re-verified (so the objections are anchored, not opinions)

| Claim in the design | Result |
|---|---|
| CHIM's `dialoguemenu.swf` wins | **CONFIRMED.** Only three providers exist (`CHIM`, `Norden UI 16x9`, `Norden UI 21x9`); `profiles\Ultra\modlist.txt:8 +CHIM`, `:148 +Norden UI 16x9`, `:30 -Norden UI 21x9`. Highest-first ⇒ CHIM |
| Route B is a no-op on family 2 | **CONFIRMED.** Norden's SWF has no `r2.topicClicked = function`; `topicClicked` there is only a frame label (`gotoAndPlay("topicClicked")`, `p2_swf_disasm_norden16x9.txt:656`) and the `GameDelegate.call("TopicClicked", …)` sits inside `onSelectionClick` (`:662`) |
| Family probe via `HIDE_TOPICS` | **CONFIRMED.** `r1.HIDE_TOPICS = "false"` exists in Norden (`:669`), 0 hits in CHIM. Note it is the *string* `"false"`, so `!= ""` is the right test |
| `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` is writable | **CONFIRMED.** `r1.ALLOW_PROGRESS_DELAY = 750` on the class object; `ASSetPropFlags` is applied only to `…prototype` with flag 1 (DontEnum), not read-only (`p2_swf_disasm_chim.txt:521, 526`) |
| `EntriesA` / `selectedEntry` / `entryList` | **CONFIRMED.** `this.EntriesA = new Array()` (`:12295`), `__get__selectedEntry` = `return this.EntriesA[this.iSelectedIndex]` (`:12483`), `__get__entryList` = `return this.EntriesA` (`:12486`), `ClearList` = `EntriesA.splice(0, EntriesA.length)` (`:12350`) |
| PO3 / SKSE signatures | **CONFIRMED at the exact cited lines.** `PO3_SKSEFunctions.psc:478 EvaluateConditionList`, `:486 GetFormEditorID`, `:812 GetActiveAssociatedQuests`, `:1108 HideMenu`; `Actor.psc:175 GetBribeAmount`, `:352 IsBribed`, `:379 IsGuard`, `:397 IsIntimidated`, `:704 WillIntimidateSucceed`; `Game.psc:108/189/446`; `ObjectReference.psc:245/426` |
| Smart Talk live values | **CONFIRMED** (`SmartTalk.ini` re-opened): `:49 bOverrideUISettings = 0`, `:58 iDialogueSortMode = 0`, `:65 cQuestEntryColor = 0xFFD966`, `:92 bSkipImmediateOnInput = 1`, `:95 bHoldToSkip = 1`, `:107 bDBVOIntegration = 1`, `:121 [DialoguePause] bEnabled = 1`. `SmartTalk_CustomUI.ini` is entirely commented out, so no UI mod overrides these |
| `logMessageForActor` is not eligibility-gated | **CONFIRMED.** `Papyrus::logMessageForActor` (0xe8a60) → `HTTPManager::log` / `HTTPLogWithForcedActor`, "no interrupt code on that path"; the AUTO_ELIGIBILITY gate lives in `HTTPManager::stream` (`p2-chim-interplay.md:676-681`) |
| Transport shape | **NEW.** `DATA=` is **base64** (`GET //HerikaServer/streamv2.php?DATA=aW5wdXR0ZXh0fDIz…` decodes to `inputtext|…|Jordan:\r\nHello there, Brast…`). Across 7,951 logged GETs the longest request target is **2,366** chars (p99 1,209), `/var/log/apache2/other_vhosts_access.log`. Apache allows 65535 (`etc/apache2/sites-available/000-default.conf:30 LimitRequestLine 65535`) |

Two design facts I could not break and that should be treated as settled: `Utility.IsInMenuMode()` is **false**
during a dialogue session (dialogue does not pause the game — CK wiki via `p2-chim-interplay.md:66-72`), and
`SmartTalk [DialoguePause]` is a 200–800 ms gap between lines, not a game pause (`SmartTalk.ini:119-127`).

---

## 1. CRITICAL

### C1 — §2.3 "Scenes with player dialogue" steers the player straight into CHIM's quest-restarting cleaner

**Problem.** The design's scene handling rests on one sentence: *"CHIM's `_restrict_onscene = 1` keeps the other
scene actors quiet."* That setting does not cover the case the design creates. `_restrict_onscene` is consumed by
the `[AUTO_ELIGIBILITY]` gate, which only runs for **automatic** events; `HTTPManager::stream` classifies the five
player-input types (`inputtext`, `inputtext_s`, `ginputtext`, `ginputtext_s`, `narrator_inputtext`) as
non-automatic and stores the negation as the automatic flag (`xor al,1` at 0x1635cf), so a player utterance
**always** reaches `QueueInterruptNPC` → `InterruptNPC` (`p2-chim-interplay.md:676-681`, 498-504).
`InterruptNPC` sets the agent's "was on scene" byte when the actor has a current scene
(`mov [rsi+0x6f],1` at 0x52b28, `p2-chim-interplay.md:471`). Roughly 78–90 s later the `[CLEANER]` sweep in
`ManagerMainQueue::threadFunction` runs `EndDialogueClearScene`, which I re-opened verbatim:

```papyrus
; AIAgentAIMind.psc:1886-1904
	; Try to restart scene, can break quests
	if (npc.GetCurrentScene())
			Scene currentScene=npc.GetCurrentScene();
			currentScene.Stop();
			Utility.wait(1);
			currentScene.Start();
	else
		if (npc.GetCurrentPackage())
			if (npc.GetCurrentPackage().GetOwningQuest())
				Quest questScene=npc.GetCurrentPackage().GetOwningQuest();
				questScene.Stop();
				Utility.wait(1);
				questScene.Start();
```

The `else` branch is the likely one, because scenes are short and the cleaner is late: **CHIM stops and restarts
the quest that owns the NPC's current package.** `Quest.Stop()` shuts the quest down and `Start()` restarts it
from scratch (`Quest.psc:129-132`). The author's own comment is "can break quests".

**In-game scenario.** MQ302, Season Unending. The player is seated in the blocking scene; the engine opens the
session; the glue reads layer 0 (`"I'm listening."`) exactly as §2.3 says it should, and the layer is answered
`do=wait` → PENDING. To choose a hold the player must **speak**. That utterance runs `InterruptNPC` on Ulfric,
who is in a scene. Ninety seconds later — by then the player is somewhere else — CHIM stops and restarts
Ulfric's scene, or the quest owning his package. Same shape for any in-scene player choice, and LoreRim has
17 "Quest Expansion" mods full of them.

**Exact change.**
1. §2.3 must delete the claim that `_restrict_onscene` covers this and state the opposite, with the evidence above.
2. Hard rule in §1.3: an engine-opened session whose `speaker.GetCurrentScene() != None` **never enters PENDING
   and never triggers `lrg_dlgtalk`**. It goes to MANUAL for that layer: the menu is handed back and the player
   clicks. Reading and harvesting stays; waiting for speech does not.
3. Server side: while `lrg_npc_state.scene = 1` (or the game's `ev=open` carried a scene flag), `TakeUpBusiness`
   is not offered and no `<business>` block is shown for that NPC, so the LLM cannot invite the player to speak.
4. One MCM help line and one flow test (add to 7.3, e.g. `30b`): "speaker in a scene → `do=show`, no PENDING, no
   `lrg_dlgtalk`".
5. Add to §5.2 as row C15 with the CHIM citation, so this is a documented CHIM hazard the glue *avoids amplifying*
   rather than an unknown.

### C2 — §2.7 + §1.3 CLOSING: a glue-opened session on a guard is not "critical", and the timeout closes it

**Problem.** The game-side critical test is written as *"the speaker `IsGuard()` **and**
`speaker.GetCrimeFaction().GetCrimeGold() > 0`, **on an engine-opened session**"* (§2.7). Both restrictions are
wrong for the dangerous case:
- the `and` misses the 6 City Bag Checks INFOs that carry `TWAT → DGCrimeResistArrest` with **no crime-gold
  condition at all** (`p2-requiem.md` R2-A2 / V.2-C4);
- "engine-opened" misses the session the **glue itself** opens on a guard when the player says "see if the guard
  will let it go" — which is exactly the interaction the owner asked for.

And §1.3's DECIDING row then says: on `fDecideTimeout` (4 s) with a command in flight, *"then CLOSING if we opened
it and `crit=0`"*. With `crit` forced to 0 by the above, a slow server round trip closes a crime session by script.
Whether a script close fires the walk-away is the design's own unresolved **E-R7**, and the walk-away here is
`DGCrimeResistArrest` on 12 vanilla INFOs including `DGCrimeForcegreetTopic` ×4 (`p2-requiem.md` V.2-C4).

**In-game scenario.** 500-gold bounty (LoreRim's attack bounty). The player says "tell him I'll pay him to look
the other way." The glue opens the session on the guard. The server's `want=1` answer is 4.2 s late (the design's
own latency table puts a round trip at 0.1–0.4 s, but D2 rides a ~5 s poll — see m2). `crit=0`, so CLOSING →
`CloseClean()` → the walk-away fires → the hold turns hostile, under Requiem lethality, with the menu never shown.

**Exact change.**
1. Game-side critical test applies to **every** session, glue-opened included.
2. Change `and` to `or`: `IsGuard()` **or** `GetCrimeFaction().GetCrimeGold() > 0` **or** the server said `crit=1`
   **or** the server has not answered yet and the index has not cleared the layer.
3. `fDecideTimeout` never closes. Its only outcomes are PENDING (glue-opened, non-critical) and MANUAL. The only
   close paths are: the engine closed it, a Goodbye INFO, an explicit `do=leave`, or the leave key.
4. Until E-R7 is answered, `CloseForce(1)`/`CloseForce(2)` are probe-only (P11b already says this; §1.3 CLOSING
   contradicts it by listing both as the normal escalation). Make §1.3 match §2.7.

### C3 — §2.6 branching: the fallback numbers are wrong, and there is no menuless path if X1 says "dies"

**Problem.** §2.6 rests on *"ForceGreet packages and blocking branches re-fire, and many choice layers re-appear
immediately"*. The load-order data says otherwise. From `p2_speech_tags.json` (`deep.multi_entry_layers`,
re-read this round):

```
multi-entry layers .................................... 7962
layer re-offers its own parent topic (hub loop) ....... 1106
>=2 entries scripted (candidate real choice) .......... 2154
>=2 entries scripted+goodbye (candidate irreversible) .. 951
```

and `deep.single_entry_layers` "visible single entry: NPC line ends with '?' AND entry is scripted" = **192**.
So of 7,962 closed layers only 1,106 (14 %) are hub loops that can plausibly re-appear from a fresh root list.
For the other 86 %, a re-opened session shows the **root** list, `txt` is not found, and the design falls to
"assisted" — the vanilla menu. On top of that, 2,061 INFOs are Say-Once (`info_flag_stats.say_once`), so anything
consumed on the way down cannot be re-walked later.

The mechanism forcing this is F1, which the design accepts: CHIM interrupts on every depth-0 player utterance, and
answering a pending layer requires the player to speak. If X1 comes back "dies", **menuless questing is menuless
for the first layer only**, and the owner's explicit requirement "branching choices are handled appropriately" is
met by showing a menu. §9 D7 puts that decision after the fact; §2.6 puts the only real remedy (re-walk) in
"Phase 2b". That is the wrong order: the remedy is what makes the feature the feature.

**Exact change.**
1. Replace the "many choice layers re-appear immediately" sentence with the numbers above, and state the expected
   menuless coverage under each X1 outcome, in one table. The owner is choosing between architectures and needs
   the number.
2. Promote the bounded re-walk into **Phase 2**, gated on X1 = "dies", with rules the index already supports:
   a path entry may be re-clicked only when it is `scripted = false` **and** not Say-Once **and** not Goodbye
   **and** carries no `twat`; the recorded layer signature of every step must match on the way down, and any
   mismatch aborts to assisted; `rewalk.max_depth` 2. Everything needed is already in the entry record of §3.3
   (`flags.sayonce`, `scripted`, `twat`) — only the rule is missing.
3. Add a counter to the MCM diagnostics: layers LOST, layers re-walked, layers handed back assisted, per game
   session. Without it neither the owner nor the next round can tell whether the feature works.
4. Run X1 **before** anything else in T0, and treat "dies" as a design gate, not a config value.

### C4 — §4.4 forbids exactly the continuation that §2.3's own service example needs

**Problem.** §4.4: *"Class `check | pay | commit | meta` is **NEVER** executed from intent mode."*
§2.3's rent row: the Xtended Stay hub `"I'd like to rent a room."` is class `service`, and the seven duration
entries behind it are class `pay`. Those duration entries are **discovered inside the session, after the hub
click** — the LLM never saw them, so any match against them is intent-mode matching, which §4.4 forbids. The
layer therefore parks; the player speaks "three nights"; CHIM interrupts; the session dies; the layer is LOST;
`txt` is not in the fresh root list (it is one layer down) → assisted → the vanilla menu opens.

**In-game scenario.** "I'd like a room for three nights." The most common service interaction in the game shows
the menu every time. The same shape hits every priced sub-layer, every "how many?" follow-up, and the prisoner's
fine in *In My Time of Need* (`p2-quest-branching-speech.md:203`).

**Exact change.** Define a third matching mode, distinct from intent mode, in §4.4:

> **Same-session continuation.** A layer discovered *inside the session the server is already executing*, in
> answer to the utterance that started that turn. It may execute class `service` and `pay` when: the utterance
> names the value the layer asks for (a duration, a count, a price, a named option); the match scores ≥ 0.70 with
> a margin ≥ 0.25 (stricter than intent mode); `pg >= N`; and the exact N and the exact entry text are echoed in
> `ev=result` so `<what_just_happened>` states what was bought. Never `check`, `commit` or `meta`. Never when the
> layer carries a `twat`. One continuation per session (depth 1).

Add a flow test (7.3, extend 29): "hub + 'three nights' in one utterance completes in ONE session and moves gold
exactly once; 'a room please' with no duration parks and the NPC asks."

---

## 2. MAJOR

### M1 — §1.6: Smart Talk defeats G1, and the guard is re-asserted too slowly to catch it

**Problem.** The design concedes that a mouse click during a response can still skip a line (Smart Talk writes
`bAllowProgress = true` natively before calling `SkipText`, `p2-lorerim-dialogue-stack.md:92, 280`) and calls the
owner knob "the only fix". It then re-asserts the guard **"on every third poll"** in LISTENING and every 0.5 s in
PENDING. That leaves a 0.3–0.5 s window in which `bAllowProgress` is `true` and the SWF's own `onItemSelect` is
live again — and `PopulateDialogueLists` ends with `SetSelectedTopic(arguments[last])` (the design's own D-14), so
there is always a pre-selected target.

`bHoldToSkip = 1` with `iAutoSkipInterval = 150` (`SmartTalk.ini:95, 101`) means a **held** mouse button re-raises
`bAllowProgress` every 150 ms. One of those raises will coincide with the list returning to state 1.

**In-game scenario.** Hidden session on Jorleif. The response is long and unvoiced; the player, seeing nothing,
holds the left mouse button. Smart Talk skips the line and keeps setting `bAllowProgress = true`; the list returns;
the next click reaches `onItemSelect` → `onSelectionClick(true)` on the engine's pre-selected last entry —
`"We have evidence of necromancy, and found his amulet."` — Goodbye + script. Wuunferth is jailed and an innocent
man dies, with no menu ever on screen. (`p2-quest-branching-speech.md:209`.)

**Exact change.**
1. `LRG_DlgUI` gains `Function GuardPoke() Global` = one synchronous `UI.SetBool(M, D+".bAllowProgress", false)`,
   called on **every** poll in every state (one delayed native, not three).
   The full `Guard(true)` re-arm (`ALLOW_PROGRESS_DELAY` + `StartProgressTimer`) stays on the slow cadence.
2. `bSkipImmediateOnInput = 0` **and** `bHoldToSkip = 0` become a **hard precondition**, not documentation:
   the `Maintenance()` self-test reads `SKSE\Plugins\SmartTalk.ini` (PapyrusUtil `MiscUtil.ReadFromFile` if the
   owner has it; otherwise an MCM checkbox the owner ticks after editing) and `bMenuless` cannot leave dry-run
   while either is 1. §5.3 currently says "documentation only — the glue writes none of them"; reading is not
   writing, and the failure mode is a broken quest.
3. **Remove `iPapyrusHandle` from any advice.** Its live value 3 ("forced pause of at least 500 ms before the
   affected dialogues can be skipped", `SmartTalk.ini:109-116`) is the one Smart Talk feature protecting fragments;
   the design never mentions it and a reader editing that file could turn it to 0.
4. Add `bSkipOnInteraction` (live 0, `:104`) to the self-test: at 1 it skips the line when a session is opened,
   which would eat greetings the design relies on.

### M2 — §1.6 G3: parking at 3 is also the engine's TRANSITIONING, and it swallows engine-pushed lists

**Problem.** `ShowDialogueList` sets `this.eMenuState = DialogueMenu.TRANSITIONING` itself
(`p2_swf_disasm_chim.txt:414-426`), so **3 means two different things** and the driver cannot tell "I parked it"
from "the engine is animating". Worse, `DoShowDialogueList` acts only from `TOPIC_CLICKED` or `SHOW_GREETING`
(`:400-412`), so while the driver holds the state at 3 **any list the engine tries to push is dropped, not
deferred** — the same failure class the design correctly identifies for the click path (SWF G2) but does not apply
to PENDING, where the park is held for up to 45 s.

**In-game scenario.** Hidden PENDING layer with an innkeeper, parked at 3. A courier ForceGreet fires. The engine's
`ShowDialogueList` is dropped; `ExitButton._visible` is never updated; the driver sees an unchanged signature,
waits out `fSilenceTimeout`, then MANUALs — and un-hides a menu whose list the SWF never showed. The courier's
package has already been consumed.

**Exact change.**
1. Park at 3 **only** in the window between "layer read" and "click decided or abandoned", never for the duration
   of PENDING. Outside that window rely on G1 (`bAllowProgress=false`, poked every poll — M1) and G2.
2. While parked, treat any change in `EntryCount()` or in the layer signature as an engine push: unpark
   immediately (restore the observed pre-park value), re-read, and re-park only after the new layer is read.
3. §1.2: `MenuState()`'s comment must say 3 = "animating **or** parked by us" and no rule may read meaning into it.
4. P7 must gain a step: with the session parked, cause an engine list push (a follower ForceGreet or a second
   `Activate`) and log whether the list arrives after unparking. Today P7 only tests input.
5. Note the trade-off explicitly in §1.6: G3 is the **only** thing that blocks Smart Talk's native skip (it acts
   only in state 0 or 2, `p2-lorerim-dialogue-stack.md:92`), which is why M1's owner-knob precondition is the real
   fix and G3 must not be asked to do that job for 45 s at a time.

### M3 — §1.3 RESPONDING: click verification depends on subtitles, which are a player toggle

**Problem.** RESPONDING verifies the click landed by: menu closed, another menu opened, the state left our written
value, or "(subtitles on) a non-blank subtitle". With route B the driver itself wrote `eMenuState = 2` and the
engine has no reason to move it during the response, so on a silent response with `bDialogueSubtitles = 0` **none
of the four signals fires** — and for `persuade, intimidate, bribe, pay, commit, meta` the rule is
"MANUAL with `ok=0;why=unverified`". The vanilla menu opens in the middle of a quest line, after the click already
executed. 429 player INFOs are genuinely silent (BRANCH D2) and the design's own D-5 forbids depending on
subtitles at all — §1.3 contradicts D-5.

**Exact change.** Add the two subtitle-free signals that already exist and cost one native each:
- `EntryCount()` collapsing to 0 — the engine calls `ClearList()` = `EntriesA.splice(0, EntriesA.length)`
  (`p2_swf_disasm_chim.txt:12350`) before repopulating, so a click that reached the engine always produces a
  0-length window;
- `AIAgentFunctions.isActorTalking(npc)` going 0 → 1 (`AIAgentFunctions.psc:33`), already used pre-click.

Either one, or the four existing signals, counts as verified. Keep `unverified` only when none fires for the full
20 s. This also removes §5.3's dependency on the owner keeping subtitles on for correctness (it remains a
readability recommendation for Fuz lines).

### M4 — §1.7 `iMaxEntries`: the list is truncated before it is ranked, so quest topics can be invisible

**Problem.** READING reads positions `0 .. min(n, iMaxEntries)-1` (default 16) and `lrg_topics` carries `sent ≤ 16`.
All ranking (§4.3: +4 journal quest, −3 filler quests and follower-framework menus) happens **on the server, over
what it received**. An entry at position 17 in engine order is invisible to the LLM, to the server's intent
matcher, and to the `txt` re-match after a LOST layer. The design's own ranking exists precisely because LoreRim
root lists are noisy — `ANDR_AJO_Quest` 332 top-level topics, `ACFDialogueWhiterun` 195, `WTDialogueIdle` 125,
plus follower frameworks and Katana/Inigo/Lucien/Remi trees (`p2-quest-branching-speech.md:255, 282`). CHIM's SWF
shows 8 rows at a time, so long scrolling lists are the normal case, not the exception.

**In-game scenario.** A follower with Simple Follower Framework + Swiftly Order Squad command menus, Andrealphus
job prompts and a Missives board topic. The player says "about that job on the board" — the Missives topic sits at
position 19, is never read, the server finds no match, and the NPC answers "there's nothing like that between us".
The quest is unreachable by voice for as long as the noise is there.

**Exact change.**
1. Always read and send `n = EntryCount()`, and when `n > iMaxEntries` do a **tail pass**: positions
   `iMaxEntries .. min(n, 40)-1` with `text` only (one native per entry), sent as a second `lrg_topics` with a new
   key `part=2`. Two full-field reads for the top 16, one read each for the tail.
2. Forbid the "not on the table right now" / "nothing like that" wording whenever `sent < n`; the guidance line
   must instead be "ask them to be more specific" and the server must re-request a tail pass.
3. Put the honest per-layer native budget in §1.7 (top 16 × 2 + tail × 1 ≤ 56 `Get`s) and let P3t decide the cap.

### M5 — §2.7: "critical" as written covers ~401 topics, so the menu comes back on the best quest moments

**Problem.** The server-side critical test is *"any entry of the fingerprinted layer — or its parent INFO — carries
a walk-away topic in the index (`twat`)"*, with `DGCrimeResistArrest` named only as "the lethal case". The winning
load order has **1,201 INFOs across 401 topics with a walk-away** (`p2-requiem.md` C4) — `info_flag_stats` in the
independent scan gives `walk_away 952 / has_TWAT_walkaway_topic 904`. With `iCritical = 0` (the binding default,
§1.11) every one of those layers is "handed back visible". That includes MQ302's `"I'm listening."` INFO, which is
Walk-Away flagged (`p2-quest-branching-speech.md:206`) — the design's own showcase branch. The single most
dramatic quest negotiation in the game opens a vanilla menu.

**Exact change.** Two grades, both in §2.7 and §3.3's entry record:
- **LETHAL** — `twat` resolves to `DGCrimeResistArrest`, or the game-side crime test of C2 fires. Handed back
  visible while `iCritical = 0`. ~18 INFOs.
- **COSTLY** — any other `twat`. Stays **menuless**, but: never auto-closed, never timed out, never force-closed;
  `do=leave` requires an explicit spoken intent to leave (not silence, not a timeout); the `<business>` block
  labels the back-out entry `[leaving now ends this]`.

This is the single change that buys back the most menuless coverage for the least risk, and it matches the
owner's words better than the current all-or-nothing rule.

### M6 — §3.2 "result duty" contradicts §1.3, and a blocking `CmdSelectTopic` would stall CHIM's only queue thread

**Problem.** §3.2 says *"every exit path of `CmdSelectTopic` ends with exactly one `LRG_Main.ReportResult(...)`"*.
§3.1's closed list of new `funcret` reasons includes `not enough gold`, `that moment has passed` and
`dry-run mode, nothing was clicked` — all of which §1.3 decides **inside the loop**, long after `CmdSelectTopic`
returned. Either `CmdSelectTopic` blocks until the click, or "exactly one" is violated and
`AIAgentFunctions.commandEndedForActor` is called twice for one command (`LRG_Main.psc:338-345`, re-opened).

Blocking is the worse branch: `LRG.DispatchExternalCommand` is invoked from the DLL, and commands are executed
only from `ManagerMainQueue::threadFunction`, the **sole** executor (`p2-chim-interplay.md:690`) — which is also
the thread that runs the `[CLEANER]` voice-type restore (`0x20a1c6`, `:532`). A `CmdSelectTopic` that waits 3 s for
the menu, then 8 s for `isActorTalking == 0`, stalls every other CHIM command and the voice restore for the whole
NPC set.

**Exact change.** One sentence in §1.3 and one in §3.2:
> `CmdSelectTopic` validates, stamps the request, and returns within one Papyrus frame. It contains no
> `Utility.Wait*`. It emits `ReportResult` only for the synchronous refusals of §1.5 (module off, wrong `ref`,
> stale `x`, session with another speaker). Every other outcome — including `not enough gold`, `stale`,
> `dry-run` and success — is reported by the loop, exactly once per `x`, and `commandEndedForActor` is called
> exactly once per `x` in total.

Add flow test 21b: two deliveries of the same `x` produce exactly one `commandEndedForActor`.

### M7 — §6.5: under SHARMAT the shared gates silently evaluate to "not blocked"

**Problem.** §6.3's shared gates are written in Phase 1's terms ("Phase 1 mode `scene` with this NPC, or
`ostim=1`"). Under SHARMAT, `functions.php:9-13` takes the `if` branch and `lrgPrepareTurn()` is **never called**,
so `$GLOBALS['LRG_TURN']` is never built and Phase 1's post-gate is never registered (`functions.php:16-23` is in
the `else`). §6.5 nonetheless claims the dialogue module "keeps working" — it does, but with its scene and
intimacy gates absent. `ext/aiagent_nsfw` runs its own scenes, which Phase 2 would then know nothing about.

**In-game scenario.** SHARMAT scene running with Serana. The player speaks. Phase 2 sees no `LRG_TURN`, offers
`TakeUpBusiness`, and the glue opens a dialogue session on an NPC mid-scene. (The game-side
`LRG_OStim.IsSceneActiveOrStarting()` gate may or may not cover a SHARMAT-started thread — Phase 1's server module
is idle, and the design does not say whether `LRG_OStim`'s game-side tracking still runs.)

**Exact change.** Phase 2 reads scene/ostim/adult state from `lrg_npc_state` and from its own `ev=facts` directly,
**never** from `$GLOBALS['LRG_TURN']`; §6.3 is rewritten in those terms. §6.4's game-side gate is restated as
"`LRG_OStim.IsSceneActiveOrStarting()` **or** any OStim thread on the NPC, checked through OStim's own API, not
through Phase 1's turn". Flow test 33 must assert the scene gate with `LRG_SHARMAT_PRESENT` defined.

### M8 — §7.2's integrator edits are pinned to line numbers in a file another team is editing right now

**Problem.** §7.2 is presented as the exhaustive list of shared-file edits and cites `lrg_actions.php:491`,
`:495`, `:555`, `:235`, `LRG_Main.psc:356/361-364/183/195/11`. `lrg_actions.php` was modified at 09:40, one
minute after the design was written, and the anchors have already moved: the `stripos($code, 'ExtCmdLRG_')` guard
the design calls `:491` is at **`:539`**; the `LRG_TURN` / SHARMAT drop it calls `:495` is at **`:543`**;
`lrgRecordResult` the design calls `:235` is at **`:250`**. The Phase 1 team is extending Phase 1 during this
round, by the owner's own statement.

**Exact change.** Every entry in §7.2 becomes `anchor string → rule`, with the line number as a hint only
(e.g. *"immediately after the line containing `stripos($code, 'ExtCmdLRG_') !== 0`"*). `tools/deploy_server.ps1`
gains a pre-flight that fails when an anchor is absent or matches more than once, naming the anchor. Same for the
`make_esp.py:44` edit (anchor on `quest(main_q, 'LRG_MainQuest'`).

---

## 3. MINOR

### m1 — §4.6 layer 1: the JSON hook reorders but leaves CHIM's `item` description telling the model to leave it blank

`functions/json_response.php:500-502` (re-opened) hard-codes the `item` description: a long list of
`BaseID:ItemName` / gold-amount / destination rules ending *"Leave item blank for SpawnGold and SpawnNPC and
CreateNewNPC and DirectorCommand."*; `target` is the same shape at `:496-497`. The schema is `"strict" => true`
with `item` in `required` (`:518-527`). The design's hook only reorders `action, target, item` ahead of `message`.
**Change:** on SelectTopic turns the same hook appends one clause to `item`'s description
("…OR, when action is TakeUpBusiness, one key from the `<business>` block, e.g. `T3`, or 3–8 plain words naming
the matter") and to `target`'s ("…the NPC you are speaking to when action is TakeUpBusiness"). Add it to flow
test 32's assertions.

### m2 — §2.2 latency: the D2 route rides a ~5 s poll, not the 0.5 s queue tick

The table's "round trip 0.1–0.4 s" and the 0.5 s figure both come from `ManagerMainQueue::threadFunction`
(`p2-chim-interplay.md:690`). The `responselog` route is consumed by the game's **server queue poll**, whose
`request` rows sit at **5 s** spacing in the live DB (`p2-chim-interplay.md:286, 466`).
**Change:** give §2.2 a third column "D1 dead (D2 only)" with +0–5 s on the match row, and say in §2.4 that a P14a
failure makes a business turn 11–14 s, which the owner should see before approving.

### m3 — §1.5: no gate for "this NPC is not a CHIM agent yet"

`setDrivenByAIReal` (agent activation with salutation) also calls `Actor::EndDialogue()` + NullVoiceType +
`PrepareForDialog` (`p2-chim-interplay.md:580`), and agents are auto-added in the background
(`Auto-adding <npc>`). A session opened on an NPC CHIM is about to adopt dies for no visible reason.
**Change:** add a refusal row — `AIAgentFunctions.getAgentByName(npc) == None` → `they cannot talk right now`
(or open anyway and classify the loss as `external`, but say which); and print
`_player_auto_include_radius_m` in the self-test.

### m4 — D-13 is already answered; drop it from the mandatory list

"if dialogue counts as menu mode the escape key is dead" is settled: the Dialogue Menu does not pause the game and
therefore does not put the game into menu mode (CK wiki, quoted in `p2-chim-interplay.md:66-72`; CHIM's own
`SafeProcess` relies on exactly this, `AIAgentPapyrusFunctions.psc:1228-1251`). `LRG_Main.psc:184`'s early return
does **not** kill the emergency key in a session. The same fact makes §1.3's `Utility.IsInMenuMode()` SUSPENDED
trigger sound (it fires only for genuinely pausing menus on top).
**Change:** demote D-13 to "harmless hardening", keep P0's one-line print as confirmation, and say in §1.3 that
the SUSPENDED trigger is sound for this reason — otherwise a reader will wonder why the same uncertainty is fatal
in one place and ignored in another.

### m5 — F8 / §5.2 C1 overstate Silver Tongue

The ×0.7 re-roll is **inert** in this load order: two of the five global FormIDs resolve to `CELL` records and the
rest do not exist, so `GetFormFromFile(...) as GlobalVariable` is `None` and the fragment throws five
"Cannot call SetValue() on a None object" errors per persuasion (`p2-requiem.md` V.2-C2, R2-A5).
**Change:** F8 and C1 keep the trait multipliers (×0.5/×2/×3) and Little Lessons (−10 on open) as the live
threshold movers and drop Silver Tongue's re-roll to a footnote. Nothing in the build changes — the "zero
formulas, zero constants" rule stands — but the scoff-first rationale currently cites a mechanism that never runs.

### m6 — no payload budget for `lrg_topics`

`DATA=` is base64 (verified by decoding a live request line), so 16 entries × 140 chars inflates ~4/3 before the
per-message context blob is added. Across 7,951 logged requests CHIM's own longest GET target is 2,366 chars
(p99 1,209): the glue's list message would be the largest thing this install has ever sent, by 2–3×. Apache is
safe (`LimitRequestLine 65535`), but the DLL-side formatting buffer is unverified.
**Change:** cap the `e=` field by **bytes** (suggest 1,600 raw chars) as well as by entry count, drop the tail
into `part=2` (M4) when the cap bites, and add one probe line to P13: log the final payload length and whether the
server received it intact.

### m7 — Helmet Toggle is missing from §5.3

`Helmet Toggle 2\source\scripts\HT_PlayerAlias.psc:86-105` calls `ManageHelmet(PlayerRef, False)` on every
Dialogue Menu open and re-evaluates on close, gated by `HT_EnableDialogue` (`:55`, `:302-309`)
(`p2-requiem.md` V.3-A3). One session per business turn means the player's helmet visibly pops off and back on
every single turn of every conversation.
**Change:** add a row to §5.3 (`HT_EnableDialogue` off while menuless is on) and a line to T12.

### m8 — `ev=line` dedupe drops repeated lines, and shared DNAM responses repeat constantly

§3.1 sends "one message per distinct non-blank string". Shared responses are the norm — the rent hub's answer is
the shared `"Of course."` (`p2-quest-branching-speech.md` C5/D1) and 21 % of player INFOs only look silent until
DNAM is resolved. Two layers of one session can legitimately produce the same line, and the second is dropped, so
`<what_just_happened>` quotes the wrong one.
**Change:** key the dedupe on `(gen, string)`, not on the string alone.

---

## 4. What I attacked and could not break

Stated so the builders do not re-litigate settled ground:

- **The mechanism.** Running the engine's real session and clicking an entry the engine built is the only approach
  in which Requiem's `GetIntimidateSuccess` / `GetBribeSuccess` / `GetBribeAmount`, the trait multipliers, the
  621 injected INFOs, `SetBribed`/`SetIntimidated`, Say-Once, favour state and every fragment stay the engine's
  job. §5.1 is correct and `p2-requiem.md` 3.1 backs each item.
- **Route selection.** Route B really is a no-op on Norden's SWF (verified above), and route A on CHIM's SWF really
  does arm the gate via `initMenu()` → `skse.SendModEvent("PlayMenuTopic", …)` (`p2_swf_disasm_chim.txt:498-503`).
  The dual-family support of D-4 is justified.
- **`SelectAndVerify`.** `SetSelectedTopic` really does zero `iSelectedIndex`/`iScrollPosition` before searching,
  so an unknown index leaves entry 0 selected; writing `iSelectedIndex` and reading back `selectedEntry.topicIndex`
  and `.text` is the right primitive, and `selectedEntry` is a plain getter over `EntriesA[iSelectedIndex]`.
- **Transport choice.** `logMessageForActor` genuinely avoids the interrupt path; `requestMessageForActor` genuinely
  does not. D-9 is right and matters.
- **Index design.** Advisory-only, execution always "click the live entry", text-first with tags as fallback, DSD
  scanned and failing loudly — all correct for a load order with 8,818 overridden and 77,452 new INFOs and no
  plugin edits.
- **API citations.** Every Papyrus/PO3/SKSE line number I spot-checked was exact.

## 5. Summary of the requested changes, in build order

1. Scene rule (C1) — one refusal, one server gate, one MCM line, one flow test.
2. Critical-session rule (C2, M5) — `or` instead of `and`, applies to all sessions, two grades, no close on timeout.
3. Branching (C3) — publish the coverage numbers, promote the index-verified re-walk into Phase 2, add counters.
4. Same-session continuation (C4) — a third matching mode with its own stricter thresholds.
5. Guard cadence + Smart Talk precondition (M1) and the park rule (M2).
6. Subtitle-free click verification (M3) and the tail read (M4).
7. Result duty and non-blocking `CmdSelectTopic` (M6).
8. SHARMAT-independent gates (M7) and anchor-based integrator edits (M8).
9. The eight minors, all one-liners except m6.

None of these is a re-architecture. Items 1, 2 and 7 are the ones that stand between this design and
"never breaking quests".
