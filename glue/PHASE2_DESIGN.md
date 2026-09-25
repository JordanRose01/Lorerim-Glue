# LoreRim Glue — PHASE 2 DESIGN: menuless questing on the Papyrus-only route (build 0.3.0, **revision 2**)

Date 2026-09-21. Author: Phase 2 architect. Status: **DESIGN ONLY** — nothing in this file has been built or run in game.
**Revision 2** folds in the two adversarial critiques (`research/p2-critique-engine.md`, `research/p2-critique-compat.md`): 4 critical, 17 major and 12 minor objections. Every objection is either fixed below or answered in **§10 Rejected objections**. The build order is **§11**.
Binding for the builders **GAME-UI, GAME-DRIVER, DIALOGUE (server), INDEX, FLOWTESTS** and the **INTEGRATOR**. If your code and this file disagree, this file wins; put the conflict in your `forIntegrator` notes, never "fix" another builder's side.

**Evidence tags.** **[V\*]** = I opened the primary source again in this round and quote it (file:line or bytecode offset). **[V]** = verified in a research report's primary source, not re-opened here. **[I]** = inference. **[U]** = untested in game; a numbered probe decides it.
Research: `research/dialogue-engine.md` (foundation), `p2-dialogue-swf-ui.md` (**SWF**), `p2-chim-interplay.md` (**CHIM**), `p2-lorerim-dialogue-stack.md` (**STACK**), `p2-quest-branching-speech.md` (**BRANCH**), `p2-requiem.md` (**REQ**), `p2-prior-art.md` (**PRIOR**) — **including both adversarial verification runs of each**, whose corrections are folded in below. Agreed commitments: `REVIEW_40_RESPONSE.md` §A–C. Phase 1 contract: `glue/PROTOCOL.md` v2.

**DECIDED AND NOT REOPENED.** The glue never calls TIF fragments and never calls `SetStage`. It runs the engine's REAL dialogue session, hides the menu, reads the list the ENGINE built, lets the LLM pick an entry, and clicks it through the path the menu itself uses. The engine picks the INFO, plays the line, runs the fragments. No custom SKSE DLL; no C++ tooling without asking the owner (`REVIEW_40` item 2).

---

## 0. What a builder must read first

### 0.0 REVISION 2 — the twelve changes that alter what a builder writes

Read this table, then §1.2, §1.3 and §1.12 before writing a line of `LRG_DlgUI.psc`. Six of these change what the probe must measure, which is why they land before GAME-UI starts.

| R | Change | Sections | From |
|---|---|---|---|
| **R1** | The input guard is released on **every path that still has an open menu**, and `ALLOW_PROGRESS_DELAY` is reset to 750 at the **top of every ARMING**, including the dry-run branch | §1.2 `Guard`/`GuardReset`, §1.3 ARMING + CLOSING, §1.10 | ENG-O1 (critical) |
| **R2** | `isActorTalking` takes a **display name** and only sees CHIM's own TTS. The first click of a session is gated on an **engine-side** line-settle test instead | §1.2, §1.3 CLICKING, §2.3 | ENG-O2 (critical) |
| **R3** | An engine-opened session is **classified before it is hidden**, and one knob `iEngineOpen` replaces the unimplementable `iPlayerActivate` | §1.3 ARMING, §1.11, §2.1 | ENG-O3, ENG-O10 |
| **R4** | `EntryCount()` is **mode-calibrated** (`iCountMode` A/B/C) exactly like `EntryText`, and `no-count` is reported apart from `no-entries` | §1.2, §1.3 LISTENING, §1.12 P2 | ENG-O4 |
| **R5** | Read **mode 5 is READ-ONLY** (dump and probe): it indexes screen rows, the click path indexes array positions | §1.2, §1.7, §1.12 pass criteria | ENG-O5 |
| **R6** | Hard rule 9: every display property is `GetFloat`/`SetFloat` into a Papyrus **float**, validated before it is ever restored | §1.2, §1.4, §1.10 | ENG-O6, ENG-O15 |
| **R7** | A click is never declared `unverified` before the **settle poll** has run, and there are now **six** verification signals, none of them requiring subtitles | §1.3 RESPONDING, §1.2 | ENG-O7, CMP-M3 |
| **R8** | `crit` has **two grades**: LETHAL (hand the menu back) and COSTLY (stay menuless, never auto-close). The game-side test applies to **every** session, and `fDecideTimeout` never closes one | §2.7, §1.3 DECIDING/CLOSING, §3.3 | CMP-C2, CMP-M5 |
| **R9** | A speaker in a **scene** never enters PENDING and never triggers `lrg_dlgtalk` — CHIM's `_restrict_onscene` does **not** cover player speech | §1.3, §1.5, §2.3, §5.2 C15 | CMP-C1 (critical) |
| **R10** | New matching mode **same-session continuation** (a layer discovered inside the session the server is already executing), so renting a room finishes in one session | §4.4, §2.3 | CMP-C4 (critical) |
| **R11** | Smart Talk's `bSkipImmediateOnInput` / `bHoldToSkip` are a **machine-checked precondition** (PapyrusUtil reads the ini), plus a one-native `GuardPoke()` on every poll | §1.6, §1.11, §5.3 | ENG-O12, CMP-M1 |
| **R12** | The whole list is always counted and sent (`n`, with a `part=2` tail), and `CmdSelectTopic` **never blocks** | §1.7, §3.1, §3.2 | CMP-M4, CMP-M6 |

### 0.1 Deltas against the previous draft of this file (if you already read build 0.3.0-draft, read only this table)

| # | Change | Why |
|---|---|---|
| D-1 | Entry count is **mode-calibrated** (`iCountMode`): A `UI.GetInt(M, L+".EntriesA.length")` · B `…".entryList.length"` · C `UI.GetFloat(M, L+".iMaxScrollPosition") + 1` cross-checked against a non-empty `EntryText(0)` | `ClearList` uses `this.EntriesA.length` [V\* `p2_swf_disasm_chim.txt:12349-12351`], which proves the array has a length, **not** that `GetVariable` walks to it; `SWF §7.1` rates A at 70 % and C at 85 %, and `SWF §H` found no production example of an array-index path component. Mode C alone cannot tell one entry from none (`CalculateMaxScrollPosition` yields 0 for both), hence the cross-check. **R4** |
| D-2 | Selection primitive is a **`UI.SetInt` write to `iSelectedIndex`**, not `InvokeInt SetSelectedTopic` | `__get__selectedEntry(){ return this.EntriesA[this.iSelectedIndex] }` and both click functions send `selectedEntry.topicIndex` [V\* bytecode]. `Set*`/`Get*` are ordinary **delayed** natives (frame-synced, ~1 frame each) while only `Invoke*` is `kFunctionFlag_NoWait`, so the identity verification must be the **last** frame-synced pair before the queued `Invoke` — that **shrinks** STACK N1's window to one `Invoke` queue hop; it does not remove it (**R-reorder in §1.7**, ENG-O11) |
| D-3 | Input guard G1 rebuilt: `SetInt ALLOW_PROGRESS_DELAY` **then `UI.Invoke StartProgressTimer`** | `StartProgressTimer` reads the delay when the timer is *armed*; the greeting arms a 750 ms one before Papyrus sees `OnMenuOpen`, which would re-enable input (**SWF G1**) [V\* body] |
| D-4 | **Dual SWF family support is mandatory**, with a click-free family probe | CHIM's gate SWF is an *optional* upstream component (`AIAgent/Optional/TraditionalDialoguePlayerTTS/`); a CHIM update can silently make Norden's SWF win, where route B is a no-op (**PRIOR R-A1**) |
| D-5 | Nothing in the driver may depend on a subtitle or a speech event | `bDialogueSubtitles` is a player-facing toggle (**SWF G6**) and **429** player INFOs are genuinely silent (**BRANCH D2**) |
| D-6 | Session budget rule: **one open per business turn**, never one per click; the five Speech globals are read before open and after close and drift is reported | Little Lessons' `+10` restore is skipped whenever `SpeechVeryHard >= 100`, which a LoreRim trait multiplier guarantees → every open permanently lowers all five thresholds (**REQ R2-A1**) |
| D-7 | New optional signal: Smart Talk's quest colour on the laid-out entry clips | `SetEntryText` writes `textField.textColor` 16777215/6316128 from `topicIsNew` [V\* bytecode]; Smart Talk overwrites it with `cQuestEntryColor = 0xFFD966` for quest-advancing entries [V\* `SmartTalk.ini:65`] — probe-gated, see 1.12 P17 |
| D-8 | Failed intimidations are labelled **"answered with a brawl"** | 15 intimidate topics show the same sentence tagged `(Brawl)` as the failure variant (**BRANCH B2**) |
| D-9 | Glue plumbing during a session must use `logMessageForActor` only | `requestMessageForActor` reaches `HTTPManager::stream`, is eligibility-gated (silently dropped for sleeping/combat/restrained/cooldown/scene NPCs) and runs `InterruptNPC` on the target (**CHIM W.3-1**) |
| D-10 | Open question closed: CHIM push-to-talk is **Left Ctrl (DX 29)** | `AIAgent.log` `[Voicerec.cpp:422] Using mapped key code: 29 -> 17` (**CHIM W.3-4**). No collision with E/Tab/Space/WASD/mouse |
| D-11 | IACC's per-session setters must never be called **inside** a session | The open/close handler tests the disable flags *before* the open/close test, so flipping them mid-session strands IACC: camera and lock-on are never restored (**STACK N2**) |
| D-12 | `ExtCmdLRG_SelectTopic` must be passed through **before** Phase 1's turn/SHARMAT check | `lrg_actions.php:495` drops every `ExtCmdLRG_*` line when `LRG_TURN` is unset or SHARMAT is present [V\*] |
| D-13 | **DEMOTED to harmless hardening.** The emergency-hotkey integrator edit bypasses **only** the `Utility.IsInMenuMode()` half of `LRG_Main.psc:184`, and only for `keyVanillaMenu`/`keyLeave` while `dlg.IsSessionOpen()`. `UI.IsTextInputEnabled()` keeps its early return | The premise is already settled the other way: the Dialogue Menu does not pause the game and therefore does not enter menu mode (CK wiki via the BellCube mirror, `p2-chim-interplay.md §1.1`), which is exactly why CHIM's own `SafeProcess()` push-to-talk works during a session (`AIAgentPapyrusFunctions.psc:1228-1251`). Bypassing `IsTextInputEnabled` too would let one letter typed into CHIM's Prisma chatbox hand the menu back. P0 keeps the one-line print as confirmation (ENG-O13, CMP-m4) |
| D-14 | `PopulateDialogueLists` ends with `SetSelectedTopic(arguments[last])` unless it is `-1` | [V\* bytecode] The engine **pre-selects** an entry on every new list, so a stray click always has a target. This is the reason the guard is armed *before* anything else |
| D-15 | **`iPlayerActivate` is deleted.** Papyrus cannot tell an E-press from a ForceGreet — `OnMenuOpen` carries only the menu name and `Game.GetDialogueTarget()` gives the speaker, not the cause. One knob `iEngineOpen` governs every session the glue did not open | ENG-O10. Default `iEngineOpen = 0` = **assisted**: the menu stays visible, the list is still read, harvested and sent, nothing is clicked. `1` = menuless, and then only *after* the crit classification of **R3** |
| D-16 | **Two crit grades.** LETHAL = hand the menu back (`iCritical = 0`); COSTLY = stay menuless but never auto-close, never time out, never force-close | CMP-C2 + CMP-M5. 1,201 INFOs / 401 topics carry a walk-away in the winning state, and MQ302's `"I'm listening."` is one of them — one grade would open the vanilla menu on the best quest moment in the game |
| D-17 | **Scene speakers are never invited to speak.** Any session whose speaker has `GetCurrentScene() != None` goes to MANUAL instead of PENDING, and never emits `lrg_dlgtalk`; the server shows no `<business>` block for a scene NPC | CMP-C1. CHIM's `_restrict_onscene` gates **automatic** events only; the five player-input types are classified non-automatic, so player speech always reaches `QueueInterruptNPC`, which arms the cleaner that `Stop()`/`Start()`s the scene or the owning quest (`AIAgentAIMind.psc:1886-1904`, the author's own comment: `; Try to restart scene, can break quests` [V\*]) |
| D-18 | **Same-session continuation** is a third matching mode, distinct from intent mode, and it may execute `service` and `pay` | CMP-C4. The rent-a-room flow is a `service` hub whose seven priced `pay` durations are only discovered *inside* the session; without this mode the commonest service interaction in LoreRim shows the menu every time |
| D-19 | **The bounded re-walk moves from Phase 2b into Phase 2**, gated on X1 = "dies", and is a replay of steps **this very session already walked** — not an index-only guess | CMP-C3. Only 1,106 of 7,962 multi-entry layers re-offer their own parent topic [V\* `p2_speech_tags.json deep.multi_entry_layers`], so 86 % of lost choice layers cannot be recovered by re-opening and re-matching `txt` |
| D-20 | **`CmdSelectTopic` never blocks.** It validates, stamps and returns inside one Papyrus frame; the loop reports the outcome | CMP-M6. Commands execute only on `ManagerMainQueue::threadFunction`, which is also the `[CLEANER]` voice-restore thread; a `CmdSelectTopic` that waited 3 s + 8 s would stall every CHIM command and every voice restore for the whole agent set |
| D-21 | **Smart Talk's skip settings are a machine-checked precondition**, read with `MiscUtil.ReadFromFile` [V\* `MiscUtil.psc:58`] | ENG-O12 / CMP-M1. `bSkipImmediateOnInput = 1` and `bHoldToSkip = 1` are live [V\* `SmartTalk.ini:92,95`]; Smart Talk writes `bAllowProgress = true` **natively** before the `bFadedIn` test, so G1 cannot stop a keyboard or mouse skip. An ini line in the manual is not a guard |
| D-22 | **Nothing is truncated before it is ranked.** `n` is always counted and sent; entries past `iMaxEntries` go out as a `part=2` tail of texts only | CMP-M4. Ranking happens server-side over what it received, and the biggest top-level owners in this load order are follower/job frameworks with 123–332 topics — a Missives topic at position 19 would be invisible to the LLM and to the re-match |

### 0.2 The fourteen facts that shape everything

| # | Fact | Source | Consequence |
|---|---|---|---|
| **F1** | CHIM's DLL runs `InterruptNPC` = `PauseCurrentDialogue()` + `Actor::EndDialogue()` + NullVoiceType on the addressed NPC for EVERY depth-0 player utterance, **at dispatch time, before the LLM answers**. Skips exist only for rechat, `combatbark\|`, a payload ≥ 10 chars containing `suggestion`, a name-equality test, and the `[AUTO_ELIGIBILITY]` re-check | CHIM 2.1, V.2-C, W.2-C [V disassembly + live log] | A hidden session cannot be held open across a conversation. Sessions are SHORT. What `EndDialogue` does to an open menu decides voice-driven deep branching → owner experiment **X1** (2.6) |
| **F2** | The same function writes `NullVoiceType` (`AIAgent.esp` `xx01D70E`, `VTYP`, `EDID NullVoiceType`) into the NPC's runtime base voice type; a `[CLEANER]` sweep restores it ~78 s later (CHIM's own comment: "about 90 seconds after last speech", `AIAgentAIMind.psc:1876`) | CHIM V.3-1, W.2 [V disassembly, plugin decode, log] | A session opened seconds after the player spoke (= always) would play the real voiced line SILENT and evaluate `GetIsVoiceType` conditions wrongly. The driver restores the voice type itself before `Activate` and re-checks before every click (1.5). **X0** sizes it |
| **F3** | The live LLM schema order is `character, listener, message, mood, action, target, item, amount`; 52 of 54 observed outputs put `message` before `action`; the action wire line leaves the server only after ALL TTS of the reply | CHIM W.1-9, 2.4 [V] | On business turns the schema is re-ordered through the real `HOOKS['JSON_TEMPLATE']` hook and the spoken text is dropped through `TRANSFORMER_FUNCTION`; the server must ALSO tolerate a non-empty `message` instead of trusting the model (4.6) |
| **F4** | CHIM's `dialoguemenu.swf` wins the conflict. `onSelectionClick` never sends `TopicClicked`: it sets `timerBool = true` and raises mod event `PlayMenuTopic`; `AIAgent.dll` answers with `startTopicClickedTimer("off")`. While `timerBool` is true, **every engine `ShowDialogueList` is dropped**, not deferred | SWF 3/6, STACK R2-C1, PRIOR R-C1 [V bytecode + public CHIM `Plugin/Papyrus.cpp`] | Route B (un-gated `topicClicked()`) on CHIM's SWF; route A on the gate-less family. Never both, never a self-release after route A, never a retry through the other route (1.7) |
| **F5** | Hiding `DialogueMenu_mc` hides the NPC subtitle too (`SubtitleText` is its child); `TopicListHolder._visible` is rewritten by the SWF and, every frame, by IACC (`bHideDialogueMenu=1`); IACC also forces `eMenuState = 2` while the NPC talks | SWF 0.5, STACK 0.1/0.2 [V] | Hide per element by moving it off-screen with `_x`; readiness is `eMenuState == 1`; state 0 is never observably reached (1.4, 1.6) |
| **F6** | A hidden menu still takes input. `onMouseDown` → `onItemSelect({mouseClick:true})` on odd counts; `onItemSelect` needs only `bAllowProgress` and clicks the current selection in state 1 or calls `SkipText()` in state 0/2; TAB → `onCancelPress` → `StartHideMenu` | [V\* bytecode, `onItemSelect`/`onMouseDown`/`onCancelPress` bodies] | Guard = `bAllowProgress=false` (kills both select and skip) + `bDisableInput=true` (kills list keyboard nav) + park `eMenuState=3`, all re-asserted every poll (1.6) |
| **F7** | `SetSelectedTopic(i)` sets `iSelectedIndex = 0; iScrollPosition = 0` and only then scans `EntriesA` for a matching `topicIndex` — an unknown index silently leaves entry **0** selected | [V\* bytecode 16548–16562] | Never trust a bare `SetSelectedTopic`. Select by array position with `SetInt iSelectedIndex`, read back `selectedEntry.topicIndex` **and** `.text`, abort on mismatch (1.7) |
| **F8** | Speech-check thresholds are runtime state. The **live** movers are the LoreRim trait multipliers (×0.5/×2/×3) and Little Lessons' −10 inside `OnMenuOpen`; ~10 of 477 check INFOs are compound (weapon skill OR the check) | REQ C1/R2.2, BRANCH C4 [V] | Zero formulas and zero constants in the glue. Pre-checks are hints only, read live inside the session. Retry-suppression and scoff-first are OFF for compound or unindexed entries (4.5). *Footnote (CMP-m5): Silver Tongue's one-time ×0.7 re-roll is **inert in this load order** — two of its five global FormIDs resolve to CELL records and the other three do not exist, so the fragment's `GetFormFromFile(...) as GlobalVariable` is `None` and it throws five "Cannot call SetValue() on a None object" per persuasion (**REQ V.2-C2**, a complete header walk of 869,687 records). Speechcraft Randomization's copy re-rolls only `if BeingCleared == Persuasion`, which the player never owns (**REQ R2-A5**). Do not cite it as a live mover* |
| **F9** | The shown prompt is the RNAM of the INFO the engine pre-selected; **137** speech topics show more than one distinct player line, **108** have fully disjoint check/non-check lines; 45 persuade-condition INFOs carry no tag and 17 carry the wrong one | REQ R2.1-10, BRANCH C2 [V data; the engine rule is [U] **E14/E-R3**] | An offline PROMPT INDEX built from the owner's load order classifies entries; the text tag is only the fallback (4.2) |
| **F10** | 1,324 of 4,075 single-entry layers run a script; 689 are script + Goodbye ("commit and close") | BRANCH 0.4 [V] | No blanket auto-advance — this amends `REVIEW_40` item 7 (2.5) |
| **F11** | 18 winner-state INFOs point `TWAT` at `DGCrimeResistArrest` (12 vanilla incl. `DGCrimeForcegreetTopic` ×4, plus 6 City Bag Checks INFOs that carry **no** crime-gold condition at all); **1,201 INFOs / 401 topics** carry a walk-away in the winning state — including MQ302's `"I'm listening."` | REQ C4/A2/R2.1-13 [V data; "script close = resist arrest" is **[I] E-R7**] | Two grades (**D-16**, 2.7). **LETHAL** = `twat → DGCrimeResistArrest` or the game-side guard test → the menu is handed back while `iCritical = 0` (~18 INFOs). **COSTLY** = any other `twat` (the other ~383 topics) → **stays menuless**, but is never closed, never timed out, never force-closed. One grade would hand the vanilla menu back on 401 topics, which is the outcome the feature exists to prevent |
| **F12** | `Rite of Arcana`'s ghost effect registers `Dialogue Menu` and its `OnMenuOpen` is the single instruction `UI.InvokeString("HUD Menu","_global.skse.CloseMenu",menuName)`; LoreRim Respec and Transmog fragments close the menu and open another UI | STACK C2/R2-C2, SWF 14 [V pex string table] | "Closed right after open, nothing executed" = **REFUSED**, no retry loop. "Closed after my click and another menu opened" = **SUCCESS hand-off**; restore the cursor at once (1.9) |
| **F13** | Every open costs: a greeting line, an IACC camera snap, a Follower Stats cloak spell when the speaker is not under the crosshair, Considerate Followers muting teammates, a TDM lock-out — and, with a married NPC under a trait multiplier, a **permanent −10 on all five Speech globals** | STACK 4, REQ R2-A1/R2-A3 [V source; drift is [I]] | One open per business turn, walk every layer inside that one session, close through the engine (D-6) |
| **F14** | 13+ installed scripts key on `UI.IsMenuOpen("Dialogue Menu")`. While a hidden session is open, OStim's hotkeys (`OUtils.MenuOpen()`), OBody, Hot Key Skill, Simplest Horses, Thieving XP and Phase 1's own initiative tick go silent, while `Switch Camera During Dialogue`'s keys become live; CHIM defers its own notifications up to 4 s (`AIAgentAIMind.psc:1438`) | SWF G9, REQ R2-A2 [V grep + pex scan] | Sessions stay short; the glue never uses CHIM notifications as session feedback; the MCM help says which hotkeys sleep during a conversation |

### 0.3 Owner experiments that need NO glue code (run before the driver is finished, in this order)

**X1 runs FIRST.** It is not a config knob — it is a **design gate**: its answer decides whether Phase 2 ships the bounded re-walk (D-19) at all, and therefore whether "branching choices are handled appropriately" means *by voice* or *by handing the menu back for layer 2 onwards* (2.6).

| id | What | Decides |
|---|---|---|
| **X1** | Vanilla menu open on an NPC — at the top level, then at a choice layer. Speak to that NPC on push-to-talk (Left Ctrl). Does the menu close? Does walk-away fire? Is it still clickable? Also read `[LISTENER-RESOLVE]` in `AIAgent.log` | **Design gate** (2.6): `iBranchInput` default, and whether the bounded re-walk of D-19 has to be built |
| **X0** | Talk to an NPC through CHIM, then open the vanilla menu within 30 s. Is the greeting voiced? Does `getavinfo`/console show the voice type? | Size of the voice-keeper problem (F2) |
| **X3** ×2 | With CHIM running, use one vanilla topic on (a) an NPC CHIM never addressed and (b) an NPC just addressed. Then `SELECT` from `eventlog` (`type='chat' AND data LIKE '(Context%'`) and `speech` (`topic LIKE 'traditional_%'`) | Whether CHIM's transcript capture works at all, and the exact row shape. **The capture is UNPROVEN, not broken** (CHIM W.2-A) |
| **X2** | Talk over a playing quest line. Does the stage still advance? | Whether `PauseCurrentDialogue` can eat an End fragment |
| **X4** | Does the Prisma chatbox / `UITextEntryMenu` open over an open Dialogue Menu and do keystrokes leak into the topic list? | Whether typed input is usable during a pending layer |
| **X5** | Send one `logMessageForActor` of a custom type from the console/test script and check whether a `\|command\|` line in the HTTP reply is executed; grep `AIAgent.log` for `Setting dialogue busy for  <npc>` and `[AUTO_ELIGIBILITY]` | Delivery route D1 vs D2 (2.4) |

---

## 1. Game side: the `LRG_Dialogue` session driver

### 1.1 Scripts, attachment, and the exact ESP change

Three NEW sources in `game/LoreRimGlue/Source/Scripts/` (no existing Phase 1 file is touched):

| File | Scriptname | Owner | Ships |
|---|---|---|---|
| `LRG_DlgUI.psc` | `Scriptname LRG_DlgUI Hidden` — global functions only. **Every UI path string and every `UI.*` call in the whole project lives here and nowhere else.** This is also the DLL seam (§8) | GAME-UI | first |
| `LRG_DlgProbe.psc` | `Scriptname LRG_DlgProbe extends Quest` — the first-playtest probe (1.12) | GAME-UI | **before the driver** |
| `LRG_Dialogue.psc` | `Scriptname LRG_Dialogue extends Quest` — the session state machine | GAME-DRIVER | after the probe passes |

**Exact change in `tools/make_esp.py`, line 44** [V\* re-opened]:

```python
# old  (tools/make_esp.py:44)
quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim'], 'PlayerAlias', ['LRG_PlayerAlias'])
# new
quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe'], 'PlayerAlias', ['LRG_PlayerAlias'])
```

Nothing else in that file changes. `script_block()` already writes N names with 0 properties (`make_esp.py:22-26`) [V\*]; `HEDR` stays `(1.70, 3, 0x802)` (`:48`); no new form, no new master, `Seq/LoreRimGlue.seq` unchanged (`:55`). Verify with `tools/esp_dump.py`: the `VMAD` of `0x01000800` must list **four** script names.

Consequences the builders must respect:
- **(a) Zero CK properties.** Every form comes from `Game.GetFormFromFile` / `Game.GetFormEx` (`Game.psc:105` / `:443`) [V\*].
- **(b) Attachment on an existing save** is **[I]**: a script added to the VMAD of a quest that already runs is attached on load and gets `OnInit`. If `(self as Quest) as LRG_Dialogue` is ever `None`, `LRG_Main.Maintenance()` logs `LRG_Dialogue not attached - use a new game or a save made before the glue was installed` and the module stays off. Never crash, never retry.

**Same-form rules** [V\* `LRG_Main.psc:280-306`]. All four scripts sit on ONE quest form, so every registration is shared:
- `RegisterForSingleUpdate` belongs to `LRG_Main` alone. Ask for a tick with `LRG_Main.RequestTick(float)`; never register an update yourself.
- `OnKeyDown`, `OnMenuOpen/Close`, `OnUpdate`, `OnCrosshairRefChange`, `OnTrackedStatsEvent` reach **every** script on the form. Handle only your own keys/menus and return immediately otherwise.
- `LRG_Main.Maintenance()` calls `UnregisterForAllModEvents()` (`:104`) and `RegisterKeys()` calls `UnregisterForAllKeys()` (`:168`) [V\*]. `LRG_Dialogue.Maintenance()` and `LRG_DlgProbe.Maintenance()` are therefore called by `LRG_Main.Maintenance()` **after** its own registrations (integrator edit, 7.2), and both re-register everything they need each time.
- Mod-event callbacks use unique names (`OnLrgDlg…`) so they cannot collide with Phase 1's.

### 1.2 `LRG_DlgUI` — the only place UI strings exist (these signatures are binding)

Constants, all [V\* in `p2_swf_disasm_chim.txt`]:
`M = "Dialogue Menu"` · `D = "_root.DialogueMenu_mc"` · `L = D + ".TopicList"` (AS member assigned in the constructor: `this.TopicList = this.TopicListHolder.List_mc`) · fallback spelling `L2 = D + ".TopicListHolder.List_mc"` (timeline instance names) · `G = "_global.DialogueMenu"`.
Menu-state constants read straight from the prototype [V\* `r1.SHOW_GREETING = 0 / TOPIC_LIST_SHOWN = 1 / TOPIC_CLICKED = 2 / TRANSITIONING = 3`, `ALLOW_PROGRESS_DELAY = 750`].

```papyrus
Scriptname LRG_DlgUI Hidden
; ---- existence / readiness -------------------------------------------------
bool   Function IsOpen() Global                    ; UI.IsMenuOpen(M)                        [V* UI.psc:47]
int    Function MenuState() Global                 ; UI.GetInt(M, D+".eMenuState")
       ; 0/2 = speaking, 1 = ready, 3 = **ANIMATING OR PARKED BY US** - no rule may read meaning into a 3 (R2/CMP-M2)
int    Function EntryCount() Global                ; MODE-CALIBRATED, remembered in iCountMode (D-1, R4)
       ; A: UI.GetInt(M, L+".EntriesA.length")
       ; B: UI.GetInt(M, L+".entryList.length")
       ; C: (int)UI.GetFloat(M, L+".iMaxScrollPosition") + 1, ACCEPTED ONLY when EntryText(0, iReadMode) != ""
       ;    (CalculateMaxScrollPosition yields 0 for one entry AND for none, so C alone cannot tell them apart)
       ; returns -1 = NO MODE WORKS (distinct from 0 = no list yet). LISTENING reports why=no-count, not why=no-entries
int    Function SwfFamily() Global                 ; 2 = gate-less (Norden/Dear Diary), 1 = CHIM gate, 0 = unknown
       ; UI.GetString(M, G+".HIDE_TOPICS") != ""  -> 2        [V* HIDE_TOPICS: CHIM 0 hits, Norden 6 hits]
       ; else UI.GetInt(M, L+".iMaxItemsShown")   8 -> 1, 13 -> 2, other -> 0   [V* counted in the list constructor,
       ;      p2_swf_disasm_chim.txt:12308-12340, so the value is valid at ARMING, before the first populate]
int    Function MaxItemsShown() Global             ; UI.GetInt(M, L+".iMaxItemsShown")   ; laid-out clip count, 8 / 13
int    Function Platform() Global                  ; UI.GetInt(M, L+".iPlatform")        ; **1 by default** [V* :12306]
; ---- reading ---------------------------------------------------------------
string Function EntryText(int aiPos, int aiMode) Global
       ; mode 3: UI.GetString(M, L+".EntriesA."+aiPos+".text")                    ; ARRAY POSITION - clickable
       ; mode 4: UI.SetInt(M, L+".iSelectedIndex", aiPos) then UI.GetString(M, L+".selectedEntry.text")
       ;                                                                          ; ARRAY POSITION - clickable
       ; mode 5: UI.GetString(M, L+".Entry"+aiPos+".textField.text")              ; SCREEN ROW - **READ-ONLY** (R5)
int    Function EntryTopicIndex(int aiPos, int aiMode) Global   ; same three modes, ...".topicIndex", UI.GetInt
bool   Function EntryIsNew(int aiPos, int aiMode) Global        ; UI.GetBool ...".topicIsNew"
int    Function EntryColour(int aiRow) Global      ; UI.GetInt(M, L+".Entry"+aiRow+".textField.textColor")   ; 1.12 P17
int    Function EntryRowItem(int aiRow) Global     ; UI.GetInt(M, L+".Entry"+aiRow+".itemIndex")
       ; the clip's OWN array position, written by UpdateList as `r2.itemIndex = r5` beside `EntriesA[r5].clipIndex = r4`
       ; [V* :16429-16445]. Row k != position k on any scrolled or long list (iMaxItemsShown is 8 on CHIM's SWF).
       ; Used ONLY by the dump and by P17 - the driver never clicks a row (R5)
string Function Subtitle() Global                  ; UI.GetString(M, D+".SubtitleText.text"); " " or "" = none
bool   Function SubtitlesOn() Global               ; Utility.GetINIBool("bDialogueSubtitles:Interface")   [V* Utility.psc:65]
int    Function ProgressTimerId() Global           ; UI.GetInt(M, D+".iAllowProgressTimerID")
       ; re-assigned by clearInterval/setInterval on EVERY StartProgressTimer [V* :369-375], which the engine drives per
       ; response line via NotifyVoiceReady -> OnVoiceReady [I]. A CHANGED value = "a line started" (R2, R7).
       ; P1 samples it per line; if it moves once per SESSION rather than per line, the subtitle test is the fallback
bool   Function GateArmed() Global                 ; UI.GetBool(M, D+".timerBool")   ; family 1 only
; ---- selecting and clicking ------------------------------------------------
bool   Function SelectAndVerify(int aiPos, int aiTopicIndex, string asTextPrefix, bool abAlsoScroll) Global
       ; UI.SetInt(M, L+".iSelectedIndex", aiPos)
       ; abAlsoScroll -> UI.SetInt(M, L+".iScrollPosition", aiPos)        ; route A only, see 1.7
       ; verify  UI.GetInt(M, L+".selectedEntry.topicIndex") == aiTopicIndex
       ;   AND   StringUtil.Find(UI.GetString(M, L+".selectedEntry.text"), asTextPrefix) == 0
       ; false = STALE: the caller must NOT click
Function Click(int aiRoute) Global
       ; route 2 (B, family 1): UI.SetInt(M, D+".eMenuState", 2) -> read back == 2 -> UI.Invoke(M, D+".topicClicked")
       ; route 1 (A, family 2): UI.SetInt(M, D+".eMenuState", 1) -> read back == 1 -> UI.InvokeBool(M, D+".onSelectionClick", false)
; ---- hiding / guarding / closing -------------------------------------------
bool   Function Hide(int aiMode) Global            ; 1 = topics-only (default), 2 = full (probe only)
       ; stores the ORIGINALS as Papyrus FLOATS via UI.GetFloat (rule 9) and VALIDATES them: finite, not NaN,
       ; abs() < 10000. An unusable read leaves storedXok = false and Hide() returns false -> the caller does NOT hide
Function ReassertHide() Global                     ; re-read the hidden _x values, rewrite any that were lost
Function HideCursor(bool abHide) Global            ; UI.SetBool("Cursor Menu", "_root.mc_Cursor._visible", !abHide)  [V name, U effect]
Function Guard(bool abOn) Global
       ; ON : UI.SetInt(M, G+".ALLOW_PROGRESS_DELAY", 100000000)
       ;      UI.Invoke(M, D+".StartProgressTimer")      ; clears the pending 750 ms interval, sets bAllowProgress=false, re-arms huge
       ;      UI.SetBool(M, L+".bDisableInput", true)
       ; OFF: GuardReset() ; UI.SetBool(M, L+".bDisableInput", false) ; UI.SetBool(M, D+".bAllowProgress", true)
Function GuardReset() Global                       ; UI.SetInt(M, G+".ALLOW_PROGRESS_DELAY", 750)
       ;      + UI.Invoke(M, D+".StartProgressTimer")  ; re-arm with the sane value so the NEXT line re-enables input
       ; **Called at the TOP of every ARMING, on every branch, before anything else** (R1) - see 1.6
Function GuardPoke() Global                        ; UI.SetBool(M, D+".bAllowProgress", false)   ; ONE native, every poll
       ; the counter to Smart Talk's native write; the full Guard(true) re-arm stays on the slow cadence (R11)
Function Park() Global                             ; UI.SetInt(M, D+".eMenuState", 3)   ; only while MenuState() == 1
Function ShowList() Global                         ; UI.InvokeBool(M, D+".ShowDialogueList", false)
       ; sets TopicListHolder._visible = true, plays fadeListIn, and the frame-33 script sets menuState = TOPIC_LIST_SHOWN
       ; by itself [V* :415-426 + STACK R2.1-3]. This is the RELIABLE un-park / re-show; a manual eMenuState = 1 is
       ; only ever the SECOND attempt (R6 / ENG-O14)
Function Unhide() Global                           ; U1, see 1.10
Function CloseClean() Global                       ; UI.Invoke(M, D+".StartHideMenu")   -> GameDelegate.call("CloseMenu")
Function CloseForce(int aiStep) Global             ; **PROBE-ONLY until E-R7 is answered by P11b** (2.7, CMP-C2)
                                                   ; 1: UI.InvokeString("HUD Menu", "_global.skse.CloseMenu", M)
                                                   ; 2: PO3_SKSEFunctions.HideMenu(M)          [V* PO3_SKSEFunctions.psc:1108]
; ---- preconditions ---------------------------------------------------------
bool   Function SmartTalkSafe() Global             ; reads Data/SKSE/Plugins/SmartTalk.ini through PapyrusUtil
       ; MiscUtil.FileExists / MiscUtil.ReadFromFile [V* MiscUtil.psc:54,58] - read-only, root-relative, MO2's VFS resolves it.
       ; false when bSkipImmediateOnInput != 0, bHoldToSkip != 0 or bSkipOnInteraction != 0 (R11, D-21)
```

**`UI.Set*` only reaches a menu that is currently open.** Each menu owns its GFx movie, and `_global` belongs to that movie; when the Dialogue Menu closes there is nothing to write to. Every repair in this file is therefore scheduled either **while the menu is still open** (CLOSING, `Unhide()`) or at the **top of the next ARMING** (`GuardReset()`), never from `Maintenance()` and never from an `OnMenuClose` handler (see §10, rejection 1).

**Why the selection primitive is a `Set` and not an `Invoke`** [V\* bytecode]:

```
r2.__get__selectedEntry = function () { return this.EntriesA[this.iSelectedIndex] }
r2.topicClicked          = function () { this.timerBool = false
                                         gfx.io.GameDelegate.call("TopicClicked", [this.TopicList.selectedEntry.topicIndex]) }
r2.startTopicClickedTimer= function (voicePackID) { if (voicePackID == "off") { this.timerBool = false
                                         gfx.io.GameDelegate.call("TopicClicked", [this.TopicList.selectedEntry.topicIndex]) } ... }
```

`selectedEntry` is a plain `addProperty` getter over `EntriesA[iSelectedIndex]`, and both click functions read nothing else. Writing `iSelectedIndex` with `UI.SetInt` therefore fully determines what is clicked.

**The residual window is real and must be stated** (ENG-O11). `Set*`/`Get*` are ordinary **delayed** natives — frame-synced, roughly one frame each — while only `Invoke*` carries `kFunctionFlag_NoWait` and is queued (SWF C1/claim 11, re-derived in **STACK R2-C4** and **R2.1 claim 11**). They do **not** all happen "inside one Papyrus call". Worse, the list can move with no player input at all: `PopulateDialogueLists` ends with `TopicList.InvalidateData()` [V\* `p2_swf_disasm_chim.txt:381-399`], `CenteredScrollingList.InvalidateData` ends with `this.UpdateList()` [V\* `:14519-14527`], and `UpdateList` overwrites `iSelectedIndex` **and** `iHighlightedIndex` whenever `bRecenterSelection` is set or `iPlatform != 0` [V\* `:16455-16460`] — and `iPlatform` is **1** by default [V\* `:12306`]. So a timed quest event that repopulates the list between the identity check and the queued click hands `topicClicked()` the engine's own pre-selected entry (D-14).

The mitigation is ordering, not a claim: **both verifications are the last frame-synced calls before the queued `Invoke`** (§1.7). What remains is one `Invoke` queue hop, and P2 must log `iPlatform` so its size is known.

**Hard rules for `LRG_DlgUI`** — a review must reject any violation:
1. Never `SkipText`, never `onCancelPress`, never `SetSelectedIndexByMouse`.
2. Never call `startTopicClickedTimer` after `onSelectionClick` — that is a double `TopicClicked` (confirmed from both sides: the SWF function is unguarded [V\*] and `AIAgent.dll`'s `QueuePlayerMenuTopicTimerOff` never tests `timerBool`, **PRIOR R-C1**).
3. Never pass a truthy argument to `onSelectionClick`. `UI.Invoke` is a wrapper that always passes `false` [V\* `UI.psc:86-88`]; `UI.InvokeBool(…, false)` is the explicit form.
4. Never use `_visible` or `_alpha` to hide the topic list: the SWF's `InitExtensions` and IACC both rewrite them. Use `_x`.
5. Never a negative value through `UI.SetInt` on a UInt32-typed member.
6. Pick the getter by the ActionScript type — `GetString` for `text`, `GetInt` for `topicIndex`/`eMenuState`/`length`/`iSelectedIndex`, `GetBool` for `topicIsNew`/`timerBool`/`bAllowProgress`. A mistyped getter returns a false `0`/`""`/`false` (**PRIOR R-A2**). A `topicIndex` of **0 is legitimate**: validate a read by a positive `EntryCount()` plus a non-empty `text`, never by a non-zero index.
7. Every wait inside a session is `Utility.WaitMenuMode`.
8. `UI.Set*` targets must already exist [V\* `UI.psc:51` "Target value must already exist"] — all targets above do. A `Set*` also reaches nothing at all when the menu is closed.
9. **NEW (R6).** Every **display property** — `_x`, `_y`, `_alpha`, `_height`, `_width` — is read with `UI.GetFloat`, written with `UI.SetFloat`, and stored in a Papyrus **float**. `UI.GetInt` is `GetT<UInt32>` = `(UInt32)number` [V\* `p2-dialogue-swf-ui.md §4`], so a holder sitting at a negative `_x` — which Norden's SWF produces directly, `TopicListHolder._x = (_x - 400) + TOPICS_X` [V\* `p2_swf_disasm_norden16x9.txt:382`] — reads back as ~4.29e9 and the "restore" puts the topic list 4,294,966,976 pixels off-screen. That is the emergency hotkey producing the exact state it exists to prevent.
10. **NEW (R6).** A stored original is only usable if it was read successfully: finite, not NaN, `abs() < 10000`, and tagged with the `fam` that was live when it was read. `Unhide()` **refuses** to restore an unusable value: it falls back to `ShowList()` and reports `why=no-stored-x`.
11. **NEW (R5).** Mode 5 is **read-only**. No function that feeds a click may take a screen row. `EntryText(pos, 5)`, `EntryColour(row)` and `EntryRowItem(row)` exist for the dump and the probe; `SelectAndVerify` and `Click` only ever see array positions from mode 3 or mode 4.

### 1.3 The session state machine

One loop owns the UI. `OnMenuOpen("Dialogue Menu")` starts it (guard `loopRunning`; a second open while it runs only sets a flag). **Commands and keys never touch the UI themselves** — they set request variables the loop consumes on its next poll. The single exception: if `now - loopBeat > 2.0` (the loop's stack was lost) the emergency key calls `LRG_DlgUI.Unhide()` directly.

```
IDLE  --cmd do=open|pick, no live session-->  OPENING --menu open--> ARMING --> LISTENING --entries>0--> READING --> DECIDING
IDLE  --OnMenuOpen we did not cause (E-press, ForceGreet, guard, courier, blocking greeting, scene)--> ARMING (origin=engine)
DECIDING --local prefix hit or reply do=pick--> CLICKING --> RESPONDING --state back to 1--> READING ... (next layer, same session)
DECIDING --reply do=wait / no reply--> PENDING (parked in a window, slow poll) --cmd do=pick--> CLICKING
DECIDING|PENDING --do=leave / leave key--> CLOSING --OnMenuClose--> IDLE
any  --another menu on top--> SUSPENDED --it closed, ours still open--> READING ;  --ours closed--> IDLE (hand-off)
any  --emergency key / watchdog / do=show / LETHAL crit / speaker in a scene--> MANUAL
                                    (menu visible; driver reads lists and lines, never clicks, never closes)
any  --OnMenuClose nobody asked for--> IDLE, ev=closed why=external   (pending=1 -> the server marks the layer LOST)
```

**The cause of an open cannot be read from Papyrus** (D-15). `OnMenuOpen` carries only the menu name, and `Game.GetDialogueTarget()` gives the speaker, not the cause — an E-press, a ForceGreet, a guard, a courier, a blocking greeting and a scene all arrive identically. There is therefore exactly **one** origin for all of them, `origin=engine`, governed by **`iEngineOpen`**:

| `iEngineOpen` | ARMING does | Effect |
|---|---|---|
| **0 (default, first playtest)** | nothing at all: no `Guard`, no `Hide`, no `HideCursor` → **MANUAL** | Every session the glue did not open stays a normal, visible, clickable vanilla menu. The list is still read, harvested and sent (`origin=engine`), lines still flow as `ev=line`. Costs nothing but the click |
| **1** | run the crit classification **first** (below), then — only for a non-LETHAL, non-scene speaker — `GuardReset` → `Guard(true)` → `Hide` → `HideCursor(true)` | ForceGreets, couriers and blocking greetings are answered by voice |

**`iEngineOpen = 1` classifies before it hides** (R3). The game-side test is three frame-synced natives and is cheaper than `Hide()`'s two `Set`s: `speaker.IsGuard()` [V\* `Actor.psc:379`], `speaker.GetCrimeFaction()` (**None-guarded**) and `.GetCrimeGold()` [V\* `Actor.psc:178`], plus `speaker.GetCurrentScene() != None` [V\* `ObjectReference.psc:245`]. Only after that classification may anything be hidden. Hiding first would leave a guard ForceGreeting over a bounty, or one of the six City Bag Checks INFOs, behind an invisible menu for as long as the server takes to answer — up to `fSilenceTimeout` — with 13+ scripts' hotkeys asleep (F14). A LETHAL session also **skips `Guard(true)` entirely**: with the guard armed and the menu handed back, the player cannot click "I submit. Take me to jail." at all until `Guard(false)` has run.

| State | What the driver does | Leaves when | Timeout → |
|---|---|---|---|
| **OPENING** | gates of 1.5; restore the voice type; `sid = "d"+sessionTag+"-"+n`; `glueOpened = true`; read and remember the five Speech globals (D-6); `npc.Activate(player)`; poll `IsOpen()` every 0.1 s and **re-Activate at most twice, 1 s apart** (an activation can be refused while the NPC is busy — the pattern is production-proven: `The Welkynar Knight - Quest\Source\Scripts\ksws04MainQuestScript.psc:256-261` loops `If !UI.IsMenuOpen("Dialogue Menu")` / `Activate(playerRef)` / `Wait(1)`, **SWF G8**) | menu open **and** `Game.GetDialogueTarget() == npc` [V\* `Game.psc:446`] | 3 s → `Error: they cannot talk right now`. Wrong speaker → MANUAL |
| **ARMING** | **Step 0, unconditionally, on EVERY branch including dry-run / `bMenuless=0` / `forceVisible`: `LRG_DlgUI.GuardReset()`** (R1). Then `SwfFamily()`, `MaxItemsShown()`, `Platform()`, `SubtitlesOn()`, speaker = `Game.GetDialogueTarget() as Actor`, and the **game-side crit + scene classification** (2.7). Only then, and only when the session is glue-opened *or* `iEngineOpen = 1`, and only when `crit != LETHAL` and the speaker is not in a scene: `Guard(true)` → `Hide(iHideMode)` → `HideCursor(true)`. `Hide()` returning false (rule 10) aborts the hide and goes MANUAL. Send `lrg_dlg ev=open`. Dry-run, `bMenuless=0`, `forceVisible`, `SmartTalkSafe() == false`, LETHAL crit, a scene speaker, or `iEngineOpen = 0` on an engine open → MANUAL with the menu visible | always | — |
| **LISTENING** | poll 0.1 s: `IsOpen`, `MenuState`, `EntryCount`, **`GuardPoke()` (one native, EVERY poll — R11)**; `Subtitle` only when `SubtitlesOn()` (a new non-blank string → `lrg_dlg ev=line`). The full `Guard(true)` re-arm and `ReassertHide()` stay on every third poll | `EntryCount() > 0` | menu closed **≤ 1.0 s** after open with nothing executed → `ev=refused`, IDLE, **NO retry** (F12; the 1.0 s floor comes from the SWF's own 0.33–0.47 s fade timings, **STACK N3**). `EntryCount() == -1` for 3 s → MANUAL, `why=no-count` — **distinct from** 20 s with `EntryCount() == 0` and no line → MANUAL, `why=no-entries` (R4). Without the split, a dead read path is indistinguishable from a silent NPC, including inside the probe |
| **READING** | `n = EntryCount()`, always. Read `text` + `topicIndex` for positions `0 .. min(n, iMaxEntries)-1` with the calibrated `iReadMode` (1.7, array positions only — rule 11). When `n > iMaxEntries`, a **tail pass** reads `text` only for `iMaxEntries .. min(n, 40)-1` (one native per entry) and goes out as `part=2` (R12, §3.1). Layer signature = `n + first text + last text`. `gen` bumps on a changed signature **or** after any click. An unchanged signature is never re-SENT without a click in between | the list is read | two failed reads → MANUAL |
| **DECIDING** | (a) a command is in flight with our `sid` and a current `gen` → CLICKING by `pos`. (b) a command with a foreign/zero `sid` carrying `txt`: exactly ONE entry satisfies `StringUtil.Find(text, txt) == 0` → CLICKING, and send `lrg_topics want=0`. (c) otherwise send `lrg_topics want=1` and wait | reply or local hit | `fDecideTimeout` (4 s) with a command in flight → `Error: that is not on the table right now`. **`fDecideTimeout` NEVER closes a session** (R8 / CMP-C2): its only two outcomes are PENDING (glue-opened, not LETHAL, speaker not in a scene) or MANUAL. Without a command → the same two outcomes |
| **PENDING** | **Not entered at all when the speaker has `GetCurrentScene() != None` — that goes to MANUAL (R9, D-17).** Every 0.5 s: `GuardPoke()`, re-assert guard + hide, keep forwarding new subtitle lines. **`Park()` only inside the window between "layer read" and "click decided or abandoned"** (R2 / CMP-M2); outside it the state is left alone and G1+G2 do the work. While parked, any change in `EntryCount()` or in the layer signature means the engine pushed a list: **unpark at once** (`ShowList()`), re-read, and re-park only after the new layer has been read. One HUD hint (`Debug.Notification`, `<npc> is waiting for your answer`) | `do=pick` / `do=leave` / `do=show` | `fSilenceTimeout` (45 s) with no command → MANUAL + one notification. **Never auto-pick, never auto-cancel** |
| **CLICKING** | Not earlier than **0.6 s after menu open** (F8: Little Lessons and the trait system write the globals inside `OnMenuOpen`). **First click of a session: the line-settle gate of §1.6 must pass** (R2). The CHIM-TTS gate is `AIAgentFunctions.isActorTalking(npc.GetDisplayName()) == 0`, max 8 s — a **display name**, not an `Actor` [V\* `AIAgentFunctions.psc:33`] — and it only sees CHIM's own TTS, never the engine's dialogue audio. Re-check the voice type. **Freeze rule (B1): if anything moved the player's gold or Speechcraft since the list was read, go back to READING instead of clicking.** Capture PRE facts for kinds `persuade, intimidate, bribe, pay` or any priced text: `Game.QueryStat("Persuasions"/"Bribes"/"Intimidations")` [V\* `Game.psc:189`], player gold, `IsBribed()`, `IsIntimidated()`, `GetBribeAmount()`, `WillIntimidateSucceed()` [V\* `Actor.psc:352/397/175/704`], `player.GetActorValue("Speechcraft")`, and `ProgressTimerId()`. Affordability re-check for `cost>0` → `Error: not enough gold`. Dry-run → `LogC(cid, "WOULD CLICK …")`, report `Error: dry-run mode, nothing was clicked`, MANUAL. Then the §1.7 ordering, ending with exactly one queued `Invoke`; `SelectAndVerify` false = `stale` (back to READING once; a second failure ends with `Error: that moment has passed`); push `x` onto the executed ring | the click is queued | — |
| **RESPONDING** | poll 0.1 s. **Six signals, any ONE of which verifies the click** (R7): (1) the menu closed; (2) another menu opened; (3) `EntryCount()` collapsed to 0 — the engine always calls `ClearList()` before repopulating [V\* `:12349-12351`], so a click that reached the engine always produces a zero-length window; (4) `ProgressTimerId()` changed; (5) `AIAgentFunctions.isActorTalking(npc.GetDisplayName())` went 0 → 1; (6) subtitles on and a new non-blank subtitle. The state leaving our written value is **not** a signal on its own: IACC re-writes `eMenuState = 2` every frame while the NPC talks, so on route B nothing else *can* be read. Window **≥ 4 s** and soft (SWF floors are 0.33–0.47 s and Fuz Ro D-oh holds an unvoiced line 1–10 s). Nothing after 4 s → for `plain`/`service` keep waiting; for `persuade, intimidate, bribe, pay, commit, meta` → **run the settle poll first** (below) and only then, if no PRE fact moved either, MANUAL with `ok=0;why=unverified`. **`ok=0` is never reported when a delta was seen, and the menu is never un-hidden before the settle poll has run.** **NEVER a second click.** Route A only: if `GateArmed()` is still true after 9 s, un-hide — do **not** self-release | any of the six signals, then `MenuState() == 1` or the menu closed | no signal for 20 s → MANUAL. Absolute 90 s → MANUAL |
| **(settle)** | for check/pay kinds poll the PRE facts up to **4 s** until one moves (`QueryStat`, gold, `IsBribed`, `IsIntimidated`); send `lrg_dlg ev=result`; menu still open → READING (next layer of the SAME session). Any movement is **positive verification** and overrides an otherwise-unverified click | | |
| **CLOSING** | Entered **only** on an explicit `do=leave`, the leave key, or a finished root list — **never on a timeout** (R8). `crit` **LETHAL** → never close: MANUAL instead. `crit` **COSTLY** → close only on an explicit spoken intent to leave, and **prefer the back-out entry** (a real click) over any script close, because E-R7 is unanswered. Then, in order: **`Guard(false)` while the menu is still open** (R1), then `CloseClean()`; still open after 2 s → MANUAL. **`CloseForce(1)`/`CloseForce(2)` are probe-only until P11b answers E-R7** (2.7) — the normal escalation ends at MANUAL, not at a force close | `OnMenuClose` | — |
| **SUSPENDED** | entered when `Utility.IsInMenuMode()` turns true or one of `BarterMenu, Training Menu, GiftMenu, ContainerMenu, MessageBoxMenu, Crafting Menu, Book Menu, CustomMenu, Journal Menu` opens (all registered with `RegisterForMenu` [V\* `Form.psc:189`]): `HideCursor(false)` at once; all watchdogs paused. The trigger is sound for the same reason D-13 is demoted: dialogue is not menu mode, so `IsInMenuMode()` turns true exactly when a genuinely pausing menu opens on top. On leaving, the engine's returning `ShowDialogueList` may have been dropped while we were parked — call `ShowList()` before READING | that menu closed | none while it is open |
| **IDLE (on close)** | the menu is already gone, so **no `UI.Set*` here can reach anything** — the guard release belongs to CLOSING and `Unhide()`, and the belt-and-braces is `GuardReset()` at the top of the next ARMING (R1, §10 rejection 1). Read the five Speech globals again and report `drift` if they moved (D-6); `HideCursor(false)`; `glueOpened = false`; send `lrg_dlg ev=closed` with `why` ∈ `goodbye \| asked \| handoff \| external \| refused` | | |

`OnMenuClose` fires for every listener because the session is real — Beneficial Speech Checks, Little Lessons, Follower Stats, IACC and TDM all depend on it (REQ R2.1-11, STACK R2.1-9). That this still holds under an off-screen hide is **[U] E-R2**, tested in probe P11.

### 1.4 Hiding

Default `iHideMode = 1`, **TOPICS-ONLY**: read `D+".TopicListHolder._x"`, `._y` and `D+".ExitButton._x"` with **`UI.GetFloat`** into Papyrus **floats** (rule 9), validate them (rule 10: finite, not NaN, `abs() < 10000`), record the `fam` they were read under, then `UI.SetFloat` the two `_x` to `5000.0`; optionally `UI.SetBool(M, D+".SpeakerName._visible", false)`. `SubtitleText` is deliberately untouched, so an unvoiced (Fuz Ro D-oh) line stays readable and the player is never staring at a frozen screen with no explanation. **An unusable read means `Hide()` returns false and nothing is hidden** — the session runs assisted rather than risking a menu that cannot be given back.

**Family 2 is not a theoretical branch** (ENG-O15). Norden's `InitExtensions` does `TopicListHolder.Lock("L"|"R")` and then writes `_x` **relatively** — `TopicListHolder._x = (_x ± 400) + DialogueMenu.TOPICS_X`, `._y = _y - TOPICS_Y` [V\* `p2_swf_disasm_norden16x9.txt:379-386`] — where `TOPICS_X`/`TOPICS_Y` come from an **asynchronous** `LoadVars` of `deardiary_dm/config.txt` [V\* `:335-358`] and can still be `NaN` on an early open. It also animates `TopicListHolder._alpha` in `topicsFadeIn/Out` [V\* `:450-467`], lays out **13** entry clips rather than 8, and runs at 60 fps, which **halves every timeline floor in §1.6**. If D0 is answered "hide CHIM's SWF", or a CHIM update drops its optional SWF, family 2 becomes the live path on day one. Therefore: the floors of §1.6 are **per-family** (30 fps CHIM, 60 fps Norden), and **P6 and P11 are run once with CHIM's SWF hidden, before D0 is answered** (§1.12).

- The list is already invisible at open — `InitExtensions` sets `TopicListHolder._visible = false` [V SWF R2.1-2] — so what can flash for one or two frames is the exit button, the speaker name and the first subtitle.
- IACC re-applies the whole `DisplayInfo` block it read in the same frame, so a Papyrus `_x` write is carried forward [I]. **STACK R2-C4 withdrew the "it cannot interleave" argument**, so this is *likely*, not proven: `ReassertHide()` re-reads the hidden `_x` in the normal poll and rewrites it if it was lost. Cheap, and it also covers the movie being rebuilt.
- The movie is new on every open, so hide and guard are re-applied in every ARMING and never persist [V SWF B2] — confirm in P11.
- Mode 2 (**FULL**, `UI.SetBool(M, D+"._visible", false)`) exists only for the probe's H1-vs-H2 comparison. It loses subtitles (F5).
- `StartHideMenu` itself sets `SubtitleText._visible = false`, `ExitButton._visible = false`, `SpeakerName.SetText(" ")` and `bFadedIn = false` [V\* bytecode] — so `Unhide()` must restore those three too, not only `_x`.

### 1.5 Gates before `Activate`, and the voice keeper

**Refuse to open** (the bracketed text is the exact `funcret` reason, all of them new entries on PROTOCOL §1.6's closed list):

| Condition | Reason |
|---|---|
| module off / kill switch | `the feature is switched off` |
| `ok != 1` in the param | `not authorised by the server gate` |
| NPC `None`, dead, not 3D-loaded, or `ref` ≠ `npc.GetFormID()` | `the actor could not be found` |
| either side in combat | `combat` |
| `npc.GetCurrentScene() != None` [V\* `ObjectReference.psc:245`] **while `iSceneGate = 0`** (the first-playtest default). **This refusal is expected to fire often** — the city `Dialogue…` quests own market banter scenes, bards mid-song and inn patrons, so a large share of town NPCs will be in a scene at any moment, and every business turn for them falls back to `lrg_dlgtalk`. `iSceneGate = 1` refuses only when the speaker is one of the scene's actors together with the player, or when `PO3_SKSEFunctions.GetActiveAssociatedQuests(npc)` [V\* `:812`] (already gathered for the `q` key) intersects an active journal quest; otherwise it opens. **`iSceneGate = 1` does not relax D-17**: whatever opens the session, a scene speaker never enters PENDING, never gets a `<business>` block and never triggers `lrg_dlgtalk`, so the player is never *invited* to speak at a scene actor. Both settings **count** their refusals into the MCM diagnostics so T2/T3 decide with data | `a quest scene is running` |
| `AIAgentFunctions.getAgentByName(npc) == None` [V\* `AIAgentFunctions.psc:62`] — **refuse, do not open** (CMP-m3). CHIM auto-adds agents in the background, and `setDrivenByAIReal` (agent activation with salutation) itself calls `Actor::EndDialogue()` + the null-voice write + `PrepareForDialog` (**CHIM** `p2-chim-interplay.md:580`), so a session opened on an NPC CHIM is about to adopt dies for no visible reason. One clean refusal beats a dead session reported as a generic `external` loss | `they cannot talk right now` |
| `LRG_OStim.IsSceneActiveOrStarting()` or the NPC is in an OStim thread | `a scene is already running` |
| NPC asleep / unconscious / bleeding out; player sneaking, mounted, Vampire Lord or werewolf; any menu already open (`Utility.IsInMenuMode()`, or `IsOpen()` with a different speaker) | `they cannot talk right now` |
| `distance > min(fOpenDistance, 0.85 * Game.GetGameSettingFloat("fAIInDialogueModeWithPlayerDistance"))` — live value 240 (AI Overhaul) → **200** [V\* `Game.psc:108`; REQ C6] | `too far apart` |
| voice type unknown and `bAllowNullVoice = 0` | `her voice is not ready` |

The sneaking and beast-form refusals exist because **[I] E-R6**: Papyrus `Activate` is believed to bypass activate-perk entry points (Dynamic Activation Key, Immersive Interactions, Sacrilege, Growl), so sneaking could open the pickpocket container instead of a conversation. `ObjectReference.Activate(ObjectReference akActivator, bool abDefaultProcessingOnly = false)` [V\* `ObjectReference.psc:143`] — the driver uses the default `Activate(player)`, which is the production-proven form here (`AR_QuestScript.psc:1092/1126`, `FP_PlayerAliasScript.psc:325+`, `ksws04MainQuestScript.psc:258`). MCM `bActivateDefaultOnly` switches to `Activate(player, true)`; probe P0b tries both and reports which one opens a session cleanly.

**Voice keeper** (lives in `LRG_Dialogue`). APIs [V\* `ActorBase.psc:104-105`, `Actor.psc:272`]:
`nullVT = Game.GetFormFromFile(0x01D70E, "AIAgent.esp") as VoiceType`. CHIM's DLL writes `actor->GetActorBase()` — the runtime base — which is **[I]** `Actor.GetLeveledActorBase()` in Papyrus; probe P0 logs the `GetFormEditorID` of the voice on **both** bases so the target is settled once.

- A 128-slot ring (`Form[] vkRef`, `VoiceType[] vkVoice`, saved with the quest) records an NPC's voice whenever it is neither `None` nor `nullVT`: on `OnCrosshairRefChange`, on any `OnUpdate` ≥ 30 s after the last sweep (`AIAgentFunctions.findAllNearbyAgents()`, max 24 actors), and at ARMING of every engine-opened session.
- Second layer: every `lrg_topics` and `lrg_dlg ev=open` carries `vt=<formid hex>` when known; the server stores it per NPC and echoes `vt=` in every command, resolved with `Game.GetFormEx`.
- Before `Activate` and before **every** click: `if base.GetVoiceType() == nullVT : base.SetVoiceType(original)`.
- No original known → wait up to 3 s for a non-null value, then refuse (`bAllowNullVoice`, default 0, lets a silent session through for testing).
- One self-test line per ARMING: `voice of <npc> at open = <PO3_SKSEFunctions.GetFormEditorID>` [V\* `PO3_SKSEFunctions.psc:486`].

### 1.6 Input guard and readiness

The guard exists because the engine **pre-selects an entry on every new list** — `PopulateDialogueLists` ends with `SetSelectedTopic(arguments[arguments.length-1])` unless that value is `-1` [V\* bytecode] — so any stray input has a live target from the first frame.

Four layers. **G0 and G1b run on every single poll (two natives); G1, G2 and G3 on the slow cadence or in their own window.**

| Layer | Call | Blocks |
|---|---|---|
| **G0** | `GuardReset()` = `SetInt ALLOW_PROGRESS_DELAY 750` + `Invoke StartProgressTimer`, at the **top of every ARMING, on every branch** | The leak of ENG-O1. `ALLOW_PROGRESS_DELAY` is a **class static** on `_global.DialogueMenu` [V\* `p2_swf_disasm_chim.txt:521`], the delay is read when the timer is **armed** [V\* `:369-375`], and every later `NotifyVoiceReady → OnVoiceReady` re-arms with whatever it finds [V\* `:366-368`]. `SetAllowProgress` is the **only** writer of `bAllowProgress = true` [V\* `:377-380`]. If the poisoned `100000000` survived one session, the player's next **vanilla** dialogue would be permanently unclickable by mouse, E and Enter — only TAB would close it. Whether `_global` survives a close is exactly P11's open question; G0 makes the answer not matter |
| **G1** | `SetInt ALLOW_PROGRESS_DELAY 100000000` **then** `Invoke StartProgressTimer` | `onItemSelect` and `SkipText` both begin `if (!this.bAllowProgress) return` [V\* `:427-451`]. `StartProgressTimer` also kills the 750 ms interval the greeting already armed |
| **G1b** | `GuardPoke()` = **one** `SetBool D+".bAllowProgress" false`, **every poll in every state** | Smart Talk's native skip. It writes `bAllowProgress = true` **natively** at `0x180070d56-0x180070d72`, **before** the `bFadedIn` test, and does not swallow the input (**STACK V.2-C3 / R2.1 claim 7**); `bFadedIn` is true for the whole session (only `StartHideMenu` clears it, [V\* `:464-471`]). With `bHoldToSkip = 1` and `iAutoSkipInterval = 150` [V\* `SmartTalk.ini:95,101`] a held mouse button re-raises the flag every 150 ms, so one raise **will** coincide with the list returning to state 1 — and `PopulateDialogueLists` always pre-selects an entry (D-14). Re-asserting only every third poll (0.3 s) is not fast enough |
| **G2** | `SetBool L+".bDisableInput" true` | `handleInput` returns at once on `bDisableInput`; the list's roll-over, press, wheel and nav paths all test it [V\* bytecode, 7 read sites]. Stronger than it looks: nothing in the SWF ever resets it, and a press on an entry clip dies in `onItemPress`, not only off-screen |
| **G3** | `Park()` = `SetInt eMenuState 3`, **only inside the window between "layer read" and "click decided or abandoned"** | `onItemSelect` only calls `onSelectionClick` in state 1; in state 3 it does nothing [V\* bytecode]. G3 is also the **only** thing that blocks Smart Talk's native skip, which acts in state 0/2 — which is why the owner setting of D-21 is the real fix, not this |

**G3's park is a window, not a mode** (R2 / CMP-M2). `ShowDialogueList` sets `eMenuState = TRANSITIONING` **itself** [V\* `:415-426`], so a 3 means "the engine is animating **or** we parked it" and nothing may read meaning into it. And `DoShowDialogueList` acts only from `TOPIC_CLICKED` or from `SHOW_GREETING` with `entryList.length > 0`, with the whole body inside `if (this.timerBool == false)` [V\* `:400-414`] — so while we hold the state at 3, a list the engine pushes is **dropped, not deferred**. Holding that park for the up-to-45 s of PENDING would silently swallow a courier ForceGreet: its `ShowDialogueList` never arrives, `ExitButton._visible` is never updated, the signature never changes, and the driver waits out `fSilenceTimeout` and un-hides a menu whose list the SWF never showed — while the courier package has already been consumed. Hence: park only in the click window; outside it rely on G1b + G2; and **while parked, any change in `EntryCount()` or in the layer signature means an engine push — unpark with `ShowList()` at once, re-read, re-park only after the new layer is read.**

**The line-settle gate** (R2). §2.3's promise that "the greeting always plays to its end" cannot be kept by `isActorTalking`: it is `int function isActorTalking(String npc) Global Native` [V\* `AIAgentFunctions.psc:33`] — a **display name**, so `isActorTalking(npc)` does not even compile — and every production use waits for **CHIM's own TTS**, never for engine dialogue audio (`AIAgentAIMind.psc:3134-3155`, CHIM's own comment "Smart wait: if the driver starts speaking, wait until speech ends before fast travel", plus `:3196/:3249/:3289/:3317/:3364`). Since `InterruptNPC` already ran at dispatch (F1), CHIM is **not** speaking, `isActorTalking` returns 0 immediately, and the driver would click 0.6 s after open — cutting a one-time quest greeting mid-line behind a hidden menu, spending the line, truncating the glue's own `ev=line` ground truth and risking the greeting's End fragment. So the **first click of a session** additionally requires:
- `MenuState() == 1` observed at least once, **and**
- a **line settle**: subtitles on → `Subtitle()` unchanged for `fLineSettle` (0.4 s); subtitles off → `ProgressTimerId()` unchanged for `fLineSettle`.

P1 samples `ProgressTimerId()` per line and P8 samples it after the click. If it turns out to change once per *session* rather than per line, the fallback is `MenuState() == 1` plus the subtitle test alone. `isActorTalking(npc.GetDisplayName())` stays, demoted to what it actually is: a **CHIM-TTS gate**, so the glue never clicks over CHIM's own voice.

Not covered by any guard, on purpose:
- **TAB / the engine's Cancel** → `onCancelPress` → `StartHideMenu` [V\* bytecode]. That is the player's own walk-away and is kept.
- The CHIM push-to-talk key is **Left Ctrl** (**D-10**), which collides with nothing here. The MCM help still states the rule for anyone who rebinds it: not E, TAB, Enter, Space, W/A/S/D, the arrows or a mouse button.
- **Smart Talk's native skip is a precondition, not a guard** (D-21, R11). `LRG_Dialogue.Maintenance()` calls `LRG_DlgUI.SmartTalkSafe()`, which reads `Data/SKSE/Plugins/SmartTalk.ini` through `MiscUtil.FileExists` / `MiscUtil.ReadFromFile` [V\* `MiscUtil.psc:54,58`] — read-only, root-relative, resolved by MO2's VFS. If `bSkipImmediateOnInput`, `bHoldToSkip` or `bSkipOnInteraction` is not `0`, **`bMenuless` cannot leave dry-run**, and one notification names the offending setting. Live values are `1`, `1`, `0` [V\* `SmartTalk.ini:92,95,104`]. The MCM help says explicitly: **do not touch `iPapyrusHandle` (live 3, `:116`)** — that is the setting that forces ≥ 500 ms before a fragment-bearing line can be skipped at all. If the ini cannot be read, fall back to an MCM checkbox the owner ticks, defaulting to "not safe".

**Readiness is a state, never a delay.** `ready = MenuState() == 1`. CHIM's SWF runs at 30 fps and Norden's at 60 [V PRIOR R], so **every floor below is per-family and halves on family 2**; the fixed timeline distances (`topicClicked` 12→22 = 0.33 s, `fadeListIn` 23→33 = 0.33 s, `slideListIn` 34→48 = 0.47 s, `startFadeOut` 2→16 = 0.47 s, **STACK N3**) are used only as **floors** for watchdog thresholds, never as waits.

**State 3 is not "ready" while a click is pending.** If `TopicClicked` is sent while the state is still our park value 3, the engine's following `ShowDialogueList` is dropped, and the driver would re-offer the OLD list while the NPC is still talking, then click a stale entry and run a fragment twice (**SWF G2**). Hence: park at 3 → select → verify → set the click state → **read it back** → verify identity → click (the exact order is §1.7). After a click, "ready" is accepted only on a *changed* list signature or on a natural `3 → 1` transition.

### 1.7 Click routes, and how the route is chosen

`route = iClickRoute` (0 = auto). Auto is fully deterministic from two reads, and is decided **once per session** in ARMING:

| `get_conf_i("_player_tts_traditional_dialogue")` [V\* `AIAgentFunctions.psc:52`] | `SwfFamily()` | Route | Why |
|---|---|---|---|
| ≤ 0 | 1 (CHIM) | **B** | No animation runs, so nothing rewrites the state behind us (**SWF G4**: frame 22 of `TopicListHolder` sets `menuState = TOPIC_CLICKED`, and only route A plays that animation) |
| ≤ 0 | 2 (gate-less) | **A** | That SWF has no `startTopicClickedTimer` and no `timerBool` [V\* 0 hits in the Norden disassembly] |
| > 0 | 1 | **B** | Route A would arm CHIM's gate, re-voice the prompt and hold the click 7 s+ |
| > 0 | 2 | **A** | No gate to arm |
| any | 0 (unknown) | **MANUAL + warning** | Never guess |

**Never retry a click through the other route.** One click per decision; an unverifiable click is never repeated (**SWF C4**).

Route mechanics. **The identity verification is the LAST pair of frame-synced calls before the single queued `Invoke`** (ENG-O11). This exact order is binding:

```
route B (family 1)                          route A (family 2)
1  SetInt   L.iSelectedIndex   = pos        1  SetInt   L.iSelectedIndex  = pos
                                            2  SetInt   L.iScrollPosition = pos
2  SetInt   D.eMenuState       = 2          3  SetInt   D.eMenuState      = 1
3  GetInt   D.eMenuState       == 2 ?       4  GetInt   D.eMenuState      == 1 ?
4  GetString L.selectedEntry.text  prefix?  5  GetString L.selectedEntry.text  prefix?
5  GetInt   L.selectedEntry.topicIndex ==?  6  GetInt   L.selectedEntry.topicIndex ==?
6  Invoke   D.topicClicked                  7  InvokeBool D.onSelectionClick, false
```

Any failed check at step 3–5 (3–6 on route A) aborts to `stale` and goes back to READING — **nothing is clicked**.

- **Route B** — `topicClicked()` sets `timerBool = false` itself and calls `GameDelegate.call("TopicClicked",[selectedEntry.topicIndex])` with no guard of any kind [V\* `:514-518`]. `startTopicClickedTimer("off")` has a byte-identical body; `topicClicked` is preferred purely because `startTopicClickedTimer` is the function `AIAgent.dll` itself invokes.
- **Route A** — setting the scroll position to the same value matters: `onSelectionClick` does `if (TopicList.scrollPosition != TopicList.selectedIndex) { RestoreScrollPosition(selectedIndex, true); UpdateList() }` [V\* bytecode], and `UpdateList` overwrites `iSelectedIndex`/`iHighlightedIndex` when `bRecenterSelection` is set **or** `iPlatform != 0` [V\* `:16455-16460`] — and `iPlatform` defaults to **1** [V\* `:12306`]. Leaving state at 1 is correct: `onSelectionClick` performs the `1 → 2` transition itself.
- **`iPlatform != 0` makes every repopulate re-centre the selection**, which is the residual window of D-2 and also the reason route A's `UpdateList` path needs the scroll write. **P2 must log it** — the research's P2 had it and the design's P2 had dropped it.
- A scripted click cannot enter CHIM's input-level replay machinery: `UI.Invoke*` creates no `UIMessage`, and `IsPlayerMenuSelectionUserEvent` / `DispatchPlayerMenuDelayedSelection` sit behind the input hook and the `PlayerTtsTraditionalDialogueEnabled` flag (**PRIOR R-C1**). Probe P9 confirms it by grepping `AIAgent.log` for `[PlayerMenuTTS]` after a route-B click with the setting off **and** on.

**Read-mode calibration** (once per game session, remembered in `iReadMode`): try mode 3 (`EntriesA.<i>.text`) on position 0; if it returns `""` while the list is non-empty, try mode 4 (`iSelectedIndex` + `selectedEntry.text`). Mode 3 is expected to work: a published mod reads `Ui.GetString(_CON, "_global.Console.ConsoleInstance.Commands." + cmds_i)` on a plain AS2 `Array` and `Ui.GetInt(..., "...Commands.length")` (`NL_CMD - A Console Command Framework\scripts\source\nl_cmd.psc:71,79`, **PRIOR R-C2**), and four installed mods read `selectedEntry` getters through `UI.GetInt` (**CHIM W.3-3**). Mode 4 must only be used while the state is parked at 3, because it walks `iSelectedIndex`.

**Mode 5 is not a third clickable mode** (R5 / ENG-O5). `EntryText(pos, 5)` indexes **screen rows** while the click path indexes **array positions**: `UpdateList` writes `r2.itemIndex = r5` onto the clip beside `EntriesA[r5].clipIndex = r4` [V\* `:16429-16445`], and CHIM's SWF lays out only **8** clips [V\* `:12308-12340`]. On any scrolled or long list — an innkeeper with 11 topics is ordinary — row *k* is not position *k*, so `SelectAndVerify`'s text/`topicIndex` check would fail on every layer, the driver would report `stale`, re-read, fail again and end with "that moment has passed". Nothing wrong gets clicked, but the feature dead-ends instead of degrading. Therefore **"only mode 5 works" is a P3/P4 FAILURE that goes to decision D1**, and the §1.12 pass criterion is `(P3 or P4)`, not `(P3 or P4 or P5)`.

**Entry-count calibration** (`iCountMode`, R4). Modes A / B / C of §1.2, calibrated once on the first non-empty list and cross-checked as specified. `EntryCount()` is the only read the whole driver cannot do without: if it silently returns 0 forever, LISTENING never exits, every session times out into MANUAL after 20 s, and the failure looks exactly like "this NPC has no topics" — including inside the probe. That is why it now has three modes, a `-1` "no mode works" return and its own `why=no-count`.

**Cost control, with the real budget** (R12 / CMP-M4). Each `Get`/`Set` is one delayed native.
- **Never truncate before ranking.** `n = EntryCount()` is always read and always sent, whatever `iMaxEntries` is. All ranking happens server-side over what it received, so an entry at position 17 that was never read is invisible to the LLM, to the intent matcher and to the `txt` re-match — and the biggest top-level owners in this load order are exactly the noisy ones (`ANDR_AJO_Quest` 332 topics, `ACFDialogueWhiterun` 195, `WTDialogueIdle` 125, `ACFMTSRiftenDialogue` 123, **BRANCH :255**).
- **Head:** `text` + `topicIndex` for positions `0 .. min(n, iMaxEntries)-1` — 2 natives per entry.
- **Tail:** when `n > iMaxEntries`, `text` only for `iMaxEntries .. min(n, 40)-1` — 1 native per entry — sent as a second `lrg_topics` with `part=2`. The tail needs array-position reads, so it exists in `iReadMode` 3 and 4 only.
- **Budget:** `16 × 2 + 24 × 1 = 56` `Get`s per layer, worst case. `iMaxEntries` (default 16, hard max 24) is set by **P3t**'s measured per-call cost, not guessed.
- Read `topicIsNew` and the row colour only in the dump and the probe; never re-read an unchanged list; start reading as soon as the count is positive, which the SWF's `SHOW_GREETING`-with-entries branch suggests can be during the greeting [I, timed in P2].

### 1.8 What the driver must never do

No `setLocked` (it mutes the agent — Phase 1 finding). No `stopAllDialogue`. No `SkipText`. No CHIM notification API as session feedback (`ShowDebugNotification` defers up to 4 s while the menu is open, `AIAgentAIMind.psc:1438`). No IACC or Smart Talk setter unless the owner enables `bIaccToggle`, and then **never inside a session** (**D-11**): set the flags only while `UI.IsMenuOpen("Dialogue Menu")` is false and restore them only after `OnMenuClose`; engine-opened sessions therefore always keep IACC's camera. No write to any other mod's settings file, ever.

### 1.9 Hand-offs, refusals and losses

| Observation | Classification | Action |
|---|---|---|
| Closed ≤ 1.0 s after open, nothing executed | `refused` | Tell the server once. **No retry** — Rite of Arcana's ghost effect closes every menu on open (F12), and a retry loop would spin forever |
| Closed after our click **and** another menu is open | `handoff` = SUCCESS | The conversation moved to a window (Respec, Transmog, barter). Restore the cursor immediately |
| Closed with `pending=1`, nobody asked | `external` | Normal and recoverable: CHIM's `EndDialogue` (F1), the engine's aware-player timer, distance, combat. The server marks the layer LOST (2.6). Never an error dialog |
| Closed right after a response, we did not ask | `goodbye` | The INFO carried a Goodbye flag. Expected |
| We asked | `asked` | — |

### 1.10 Emergency hotkey, leave key, dry-run, topic dump

- **Emergency (`iKeyVanillaMenu`, already exists in `settings.ini [Keys]`).** Session open → MANUAL for the rest of that session (`Unhide()`), one notification. No session → one-shot `forceVisible = true`, then today's `LRG_Main.OpenVanillaDialogue()` (`LRG_Main.psc:199-208` [V\*]), and the next ARMING skips hiding. **Integrator edit D-13 is hardening, not a fix, and its scope is narrow**: keep `if UI.IsTextInputEnabled() : return` **first and unchanged** — one letter typed into CHIM's Prisma chatbox, `UITextEntryMenu` or the console must never hand the menu back or leave the conversation — and bypass only the `Utility.IsInMenuMode()` half, only for `keyVanillaMenu` and `keyLeave`, and only while `dlg.IsSessionOpen()`. Dialogue is **not** menu mode (CK wiki via the BellCube mirror, `p2-chim-interplay.md §1.1`; the same fact is why CHIM's `SafeProcess()` lets push-to-talk work during a session, `AIAgentPapyrusFunctions.psc:1228-1251`), so the early return does not kill the escape key today. `OnKeyDown` does reach Papyrus while the Dialogue Menu is open — production proof: `Switch Camera During Dialogue\CameraSwitchScript.psc:15` acts only while it is open (STACK 2.3). **P0 keeps its one-line print** of `Utility.IsInMenuMode()` from inside a session: a `true` reading would mean something turned the Dialogue Menu into a pausing menu, which would also kill every CHIM hotkey.
- **`Unhide()` (U1)** — the single recovery primitive, in this order:
  1. `Guard(false)` (which includes `GuardReset()`), `HideCursor(false)`.
  2. Restore `TopicListHolder._x/._y` and `ExitButton._x` with **`UI.SetFloat`** from the validated stored floats (rules 9 + 10). **If a stored value was never read successfully, do not write anything** — skip to step 4 and report `why=no-stored-x`.
  3. `SetBool D+"._visible" true`; `SetBool D+".SubtitleText._visible" true`; `SetBool D+".SpeakerName._visible" true` (`StartHideMenu` clears all three itself [V\* `:464-471`]).
  4. **If `EntryCount() > 0` and `MenuState() != 1`: `ShowList()`** (R6 / ENG-O14). Repairing only the `state == 3` case is not enough: after a service menu, or while IACC has forced `eMenuState = 2`, the engine's returning `ShowDialogueList` was **dropped** while we held the park, so `TopicListHolder._visible` was never set true and the player would get a menu with a speaker name, a subtitle and no topics. `ShowDialogueList(false, undefined)` sets `_visible = true`, plays `fadeListIn`, and the frame-33 script sets `menuState = TOPIC_LIST_SHOWN` by itself [V\* `:415-426` + **STACK R2.1-3**]. A manual `SetInt eMenuState 1` is only the **second** attempt, 0.5 s later, if the state is still not 1.
  5. `lrg_dlg ev=unhide` with `why`.
- **Leave key (`iKeyLeave`, new).** PENDING/LISTENING → CLOSING; on a critical session → MANUAL instead. Same path the LLM's `do=leave` takes.
- **Dry-run (`bDlgDryRun`, default ON for the first playtest).** Everything runs, the menu stays VISIBLE, and the final step logs `WOULD CLICK cid=… pos=… i=… text=…`; the player clicks by hand. Lists, lines and results still flow to the server, so prompts, ranking and gates can be judged with zero risk to a save.
- **Topic dump (`iKeyDumpTopics`, already exists; currently a placeholder notification at `LRG_Main.psc:195` [V\*]).** Crosshair NPC or the live session, menu **visible**: read the list with every read mode, log `DUMP n=…` plus one `DUMP pos=… ti=… new=… col=… text=…` line per entry, and send `lrg_topics origin=dump`. The server writes `data/topic_dump_<npc>_<ts>.json` with the index match per entry (topic key, quest, kind, flags, `unindexed`). This is the parity tool: the dump must equal what the player sees, entry for entry.

### 1.11 MCM (page "Menuless questing"; INTEGRATOR adds to `config.json` + `settings.ini`; these defaults are binding)

New `[Dialogue]` section: `bMenuless` 0 (the owner turns it on after the probe) · `bDlgDryRun` 1 · `iHideMode` 1 · `bHideCursor` 1 · **`iEngineOpen` 0** (replaces `iPlayerActivate`, D-15: 0 = every session the glue did not open stays a visible assisted menu, lists still harvested; 1 = menuless after classification) · `iCritical` 0 (0 = **LETHAL** sessions handed back visible; COSTLY sessions stay menuless either way, D-16) · **`iSceneGate` 0** (0 = never open on a scene actor; 1 = open unless the player is in the scene or a journal quest owns it — D-17 applies at both settings) · `iBranchInput` 0 (auto / 1 voice / 2 assisted) · **`bRewalk` 0** (D-19, only meaningful once X1 = "dies"; `rewalk.max_depth` 2) · `fSilenceTimeout` 45 · `fDecideTimeout` 4 · **`fLineSettle` 0.4** · `fOpenDistance` 200 · `iClickRoute` 0 · `iReadMode` 0 · **`iCountMode` 0** · `iMaxEntries` 16 · **`bIntentOpen` 1** (D-8 / ENG-O8) · `bAllowNullVoice` 0 · `bActivateDefaultOnly` 0 · `bIaccToggle` 0 · `bProbe` 0 · `bQuestColour` 0 (D-7, off until P17 passes).
New `[Keys]` entries: `iKeyProbe`, `iKeyLeave`.

**MCM diagnostics counters** (per game session, shown on the page and sent with `ev=facts`): `opens_without_match` (D-8 / ENG-O8 — the real cost of intent-mode opens), `scene_refusals` (ENG-O9 — so T2/T3 can judge `iSceneGate` on data rather than on argument), `layers_lost`, `layers_rewalked`, `layers_assisted` (CMP-C3), `count_mode_failures`, `guard_pokes_that_found_true` (how often Smart Talk actually raised the flag).

**MCM help text must say** (F14, D-17, D-21): which hotkeys sleep during a conversation; that a scene NPC answers in her own words rather than taking up business; and that Smart Talk's two skip settings must be `0` or the module stays in dry-run.

A self-test block prints at every `Maintenance()`: the four CHIM settings through `AIAgentFunctions.get_conf_i` — `_player_tts_traditional_dialogue` (want 0), `_capture_background_chat` (want 1), `_restrict_onscene` (want 1, **and the help text states that this does NOT cover player speech** — D-17), `_openmic_enabled` — plus `_player_auto_include_radius_m` (CMP-m3: it decides how aggressively CHIM auto-adopts nearby NPCs, which is what the `getAgentByName` refusal of §1.5 is guarding against), `Utility.GetINIBool("bDialogueSubtitles:Interface")`, `SmartTalkSafe()` **with the three offending values named**, the SWF family, `iMaxItemsShown`, `iPlatform`, and the five live Speech globals. Print `get_conf_i`'s raw return once so its 0/1 convention is on record.

### 1.12 FIRST-PLAYTEST PROBE (`LRG_DlgProbe` — ships before the driver)

**Goal: one evening produces server-log evidence for every [U] in this document.** Output only through `LRG_Main.LogC(cid, msg, npcName)` [V\* `LRG_Main.psc:222`] — Papyrus logging is off in LoreRim — every line beginning `P<n> `, ≤ 300 chars, never inside a tight loop.

`bProbe=1` + `iKeyProbe`: press 1 = read phase on the crosshair NPC (opens a VISIBLE session if none is open); press 2 = hide + guard phase; press 3 = click phase (LAST entry, chosen route); press 4 = next layer with the other route; press 5 = close.

| Step | What it logs | Pass criterion → what it decides |
|---|---|---|
| **P0** | `Utility.IsInMenuMode()` inside the session; `GetINIBool("bDialogueSubtitles:Interface")`; `get_conf_i` for the four CHIM keys; voice EditorID on **both** `GetActorBase`-equivalents before and after a CHIM exchange (X0) | which base CHIM nulls → voice-keeper target; whether the emergency key needs D-13 |
| **P0b** | `Activate(player)` vs `Activate(player, true)`, three tries each, time to `IsOpen()` | `bActivateDefaultOnly` default; E-R6 |
| **P1** | `eMenuState` samples with ms stamps through one whole exchange, IACC + Smart Talk active. **Also sample `iAllowProgressTimerID` on every sample** (R2) | the sequence reaches 1; state 0 never/rarely seen → the readiness rule; IACC 1.2.0 fork behaviour. **Does the timer id change once per LINE or once per SESSION?** → the line-settle gate (§1.6) and RESPONDING signal 4, or their subtitle fallback |
| **P2** | when the list first becomes non-empty relative to the greeting; **all three count modes on the same list** (`EntriesA.length`, `entryList.length`, `iMaxScrollPosition`+1) plus `iSelectedIndex`; `iMaxItemsShown`; **`iPlatform`**; `_global.DialogueMenu.HIDE_TOPICS` | early reading for latency (2.2); the family probe; **`iCountMode`** (R4) — a red result on all three promotes G0's defensive reset to mandatory and sends `iMaxEntries` to D1; **`iPlatform != 0`** sizes D-2's residual window and route A's `UpdateList` path (R-reorder, §1.7) |
| **P3/P4/P5** | the three read modes on the same list; GFx types of `topicIndex` / `topicIsNew`; is `topicIndex == pos`? **P5 additionally logs `Entry<k>.itemIndex` beside `Entry<k>.textField.text` for rows 0..7 on a list of ≥ 10 entries**, to demonstrate row ≠ position | **P3 or P4** returns every text → `iReadMode`. Mode 5 is read-only (R5): **"only P5 works" is a FAILURE → decision D1** |
| **P3t** | wall time for reading 12 entries in each mode, **and for a 24-entry tail pass of texts only** | frame cost per call → `iMaxEntries` and the tail cap (R12) |
| **P6** | H1 (full) vs H2 (topics-only): subtitle still shown? cursor? does the state still reach 1 while hidden? first-frame flash? **Log `TopicListHolder._x` and `._y` read with BOTH `GetInt` and `GetFloat`, before hiding** | H2 keeps subtitles and readiness → the default hide mode. **A negative or NaN `_x` proves rule 9** (R6); the two readings differing proves the `GetInt` = `(UInt32)` trap |
| **P6f** | **NEW — family 2.** Run P6 and P11 once with CHIM's `Interface\dialoguemenu.swf` hidden in MO2, so Norden's SWF wins. **Before D0 is answered** | whether the hide, the stored originals and the un-hide work on the family that a single CHIM update could make live overnight (ENG-O15). `TOPICS_X`/`TOPICS_Y` may still be `NaN` on an early open |
| **P7** | guard: three clicks, three E, three Space, three wheel-ticks while parked; TAB once; **read `bAllowProgress` back 1 s after arming G1** | nothing selected, nothing skipped, TAB still closes → G1/G2/G3 are sufficient (**SWF G1**) |
| **P7s** | **NEW — Smart Talk.** With `bSkipImmediateOnInput = 1` (live), hold the left mouse button through a response while `GuardPoke()` runs every poll; count how often a read of `bAllowProgress` finds `true` | whether G1b alone can hold the line, or D-21's hard precondition is the only answer (R11). Run the same pass with the setting at `0` |
| **P7p** | **NEW — park.** While parked in PENDING, make the engine push a list (a courier ForceGreet, or a second NPC's blocking greeting). Log `EntryCount()`, the signature, `ExitButton._visible` and whether the list arrives **after** `ShowList()` unparks | whether an engine push during a park is dropped or deferred → the park-window rule of §1.6 (R2 / CMP-M2) |
| **P8/P9** | route B, then route A on the next layer; `timerBool` samples; **`iAllowProgressTimerID` and `EntryCount()` sampled every 0.1 s for 5 s after the click**; was `OnLrgDlgPlayMenuTopic` raised?; afterwards grep `AIAgent.log` for `[PlayerMenuTTS]` | exactly ONE response per click and the next layer arrives → the route table (1.7). **Does `EntryCount()` collapse to 0 after a click, and does the timer id move?** → RESPONDING signals 3 and 4, which is what removes the correctness dependency on subtitles (R7) |
| **P8v** | `SelectAndVerify` with a deliberately wrong `topicIndex` | returns false and nothing is clicked → F7 defence works |
| **P10** | `SetInt eMenuState 2` then `GetInt` read-back, ten times | the read-back gate of **SWF G2** is reliable |
| **P11** | `CloseClean()` → time to `OnMenuClose`; re-open by hand **without any glue arming at all**: is it visible and clickable by mouse, E and Enter? **read `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` on the re-open, BEFORE anything writes it**; `_x` restored; do `OnMenuOpen/OnMenuClose` listeners still fire (E-R2)? | hide/guard do not leak; **`_global` lifetime per open**. A red P11 (the poisoned `100000000` survives) promotes G0's `GuardReset()` from defensive to the single thing standing between the owner and permanently unclickable vanilla dialogue (R1) |
| **P11b** | on a harmless **non-critical closed layer only**: `CloseForce(1)` then `CloseForce(2)` — does a walk-away line play? (E-R7) | which close primitives are safe. **Never on a guard, never during an arrest** |
| **P12** | `UI.InvokeStringA(M, "_root.createEmptyMovieClip", ["lrgProbe","9731"])` then `UI.GetString(M, "_root.lrgProbe._name")` [V\* `UI.psc:101`] | is helper-SWF injection even possible → decision D1's plan C |
| **P13** | 12 × `LRG_Main.ReplaceChar` on real prompt texts, timed. **Also: build a full 16-entry + tail `lrg_topics` payload, log its final length in characters, and confirm from the server log that it arrived intact** | wire-cleaning cost per layer; the byte cap of §3.1 (CMP-m6). Today's longest logged request target is 2,366 chars across 7,951 GETs, so this message would be the largest the install has ever sent |
| **P14** | delivery: `lrg_topics want=1;origin=probe` three times; the server answers a harmless `ExtCmdLRG_SelectTopic@ok=1;do=noop;x=…` (a) inside the HTTP reply of `logMessageForActor`, (b) through `responselog`, (c) — probe only — in the reply of a `requestMessageForActor` whose text contains `suggestion`. Log the arrival time of each `x`; grep `AIAgent.log` for `Setting dialogue busy for  <npc>` and `[AUTO_ELIGIBILITY]` after (c) | which delivery route works and how fast (2.4); proof of **CHIM W.3-1** in the field |
| **P15** | merchant: click the barter entry; log states while `BarterMenu` is open and after it closes (E12) | the SUSPENDED logic |
| **P16** | 30 / 60 / 120 s idle in PENDING | the engine's aware-player auto-close (**PRIOR P6**) |
| **P17** | for rows 0..7: `Entry<k>.itemIndex` and `Entry<k>.textField.textColor` on a layer with a known quest entry | is 16767334 (`0xFFD966`) ever seen? → `bQuestColour` (D-7). Caveat: `sQuestColorVariable` points at `_root.DialogueMenu_mc.TopicList.iQuestColor`, which exists in **neither** SWF [V\* 0 hits in both disassemblies], and `bOverrideUISettings = 0` [V\* `SmartTalk.ini:49`], so the feature may simply be inert here |

**Matrix:** an ordinary NPC; **an NPC with more than `iMaxItemsShown` topics** (an innkeeper, or any follower carrying a command framework — needed for P5's row-vs-position demonstration and for the tail pass); an NPC with a persuade and a bribe entry (is the text token-substituted? does the shown variant flip when Speech crosses the live global — E14?); a merchant; a guard ForceGreet (**read-only: never click, never close**); **an NPC inside a market-banter scene** (read-only: proves D-17's refusal path and feeds `scene_refusals`); a follower; one run with a gamepad plugged in; and **one run with CHIM's SWF hidden** (P6f).

**Pass for the Papyrus-only route:** P1, P2 (at least one count mode), **(P3 or P4)**, P6-H2, P7, P7p, (P8 or P9), P10, P11. If P8 fails but P9 works → route A everywhere and `_player_tts_traditional_dialogue` must stay 0. **If only P5 works, or no count mode works → stop and go to decision D1** (R4, R5).

---

## 2. Conversation flow

### 2.1 The chosen flow: **C+ = LLM-first, session-to-execute, per-NPC list cache. Never a speculative snapshot**

Rejected, with reasons:
- **Snapshot at conversation start** — there is no pre-dispatch hook to hang it on; it burns Say-Once greetings and greeting fragments for nothing; it races the player's speech; and every open costs a greeting line, an IACC camera snap, a Follower Stats cloak, a goodbye bark and (F13) a possible permanent Speech-global drift.
- **One persistent session for the whole conversation** — impossible: F1 ends it on the player's first word, and it would freeze the player behind an invisible menu for minutes.

The chosen flow:
1. The player speaks. The server offers exactly ONE action, `ExtCmdLRG_SelectTopic` (LLM-facing name `TakeUpBusiness`). If a fresh cached list exists for this NPC the prompt carries keys `T1..T12`; otherwise the model may name the business in its own words (**intent mode**). **An intent-mode `do=open` IS an open-for-awareness and it can burn a Say-Once greeting** (D-8 / ENG-O8), so it is gated: `do=open` is emitted only when **(i)** the NPC has no usable cached root, **(ii)** `bIntentOpen = 1`, and **(iii)** the server can name a business marker in the utterance (an index hit, a quest in `q`, a service word, or a `pg_trgm` hit against this NPC's stale cache). Otherwise the turn is answered with `lrg_dlgtalk` and the list is harvested from a session that was going to happen anyway. The MCM counter `opens_without_match` makes the real rate visible, so T3 decides the default rather than this paragraph.
2. On a business turn nothing generated is spoken (4.6). The command leaves the server as soon as the action has streamed.
3. The game opens the session, finds the entry (local prefix match, or a server match), clicks. **The engine's real line is the NPC's answer.**
4. After each response the next layer is read and sent, inside the same session. Back at a root list → the refreshed root is cached and the session is closed through the engine. A closed choice layer → the session waits (PENDING) and the layer is injected into the next turn. A Goodbye INFO → the engine has already closed it.
5. Lists are harvested for free from **every** session, whatever `iEngineOpen` is set to: glue-opened, and engine-opened — ForceGreet, guards, couriers, blocking greetings, scene player-dialogue **and the player's own E-press, which Papyrus cannot tell apart from any of them** (D-15). Harvesting costs nothing but the reads, so it happens even in the assisted default where nothing is clicked. "Hailing" an NPC once is the cheapest way to make her business known before the first word.

### 2.2 Latency budget

Measured inputs: STT ≈ 1 s [I], LLM 2–3 s, TTS 3–10 s, a fast glue message 8–70 ms (CHIM 4.4), and the command queue ticks at ≈ 0.5 s (`ManagerMainQueue::threadFunction` is the sole executor, **CHIM W.3-1**). The SWF costs ≥ 0.35–0.5 s before `eMenuState == 1` and ≈ 0.5 s to close (**STACK N3**).

| Step | Cached keys | First contact (intent mode) | **D1 dead (D2 only)** |
|---|---|---|---|
| speech end → command in game (STT + pre-LLM + LLM + one queue tick, **no TTS**) | 3.5–5.0 s | 3.5–5.0 s | **+0–5 s** |
| gates + voice restore + `Activate` → menu open | 0.2–0.6 s | 0.2–0.6 s | — |
| ARMING (`GuardReset` + classify + 3 guard/hide calls) + list populated | 0.3–0.7 s | 0.3–0.7 s | — |
| read n entries × 2 fields (+ tail × 1) | 0.2–0.8 s | 0.2–1.1 s | — |
| match | local prefix, 0 s | `lrg_topics want=1` round trip 0.1–0.4 s lexical (runs in parallel with the greeting) | **+0–5 s** |
| greeting line (the click waits for the line-settle gate; never before 0.6 s after open) | 1.5–4 s | 1.5–4 s | — |
| **speech end → the NPC's real line starts** | **≈ 6–9 s** | **≈ 6.5–10 s** | **≈ 11–14 s** |

A normal CHIM turn on this machine is 5–11 s, so a business turn is no slower than small talk — **as long as D1 lives**. The 0.1–0.4 s round trip and the 0.5 s queue figure both come from `ManagerMainQueue::threadFunction`; the **D2 route (`responselog`) is consumed by the game's SERVER QUEUE poll, whose `request` rows sit at 5 s spacing in the live DB** (**CHIM** `p2-chim-interplay.md:286`, `:466`, versus `:690`). So **a P14a failure makes every second-pass business turn 11–14 s** (CMP-m2, §2.4) — the owner should see that number before approving the architecture, because it is the difference between "as fast as small talk" and "noticeably slower".

A no-match first contact costs a greeting, a goodbye bark and one ordinary CHIM turn (`lrg_dlgtalk`, 2.4) — rare once lists are cached, and now gated by `bIntentOpen` (2.1 step 1).

### 2.3 How each special case is handled

| Case | Handling |
|---|---|
| **Say-Once greetings, greeting fragments** | **The truthful rule** (R2 / D-8, replacing "never consumed for awareness", which §2.2 itself contradicted): every open costs a greeting, and an intent-mode `do=open` is an open-for-awareness that **can** burn a Say-Once one. That cost is priced, gated (2.1 step 1: no usable cache **and** `bIntentOpen = 1` **and** a named business marker) and counted (`opens_without_match`) — not denied. What the glue guarantees instead is mechanical: **it never calls `SkipText`, never calls `onCancelPress`, and never clicks before the list has been shown and the line has settled** (the gate of §1.6). Beyond that, a click during a greeting is exactly as safe as a human's — Smart Talk's own documentation warns only that skipping *too early* can stop a fragment (`SmartTalk.ini:109-116` [V\*]), and the glue never skips. 2,061 INFOs are Say-Once [V\* `p2_speech_tags.json info_flag_stats.say_once`]. Whether CHIM captured the line is X3; the glue's own `ev=line` is the fallback either way |
| **ForceGreet, blocking greetings, couriers** | `OnMenuOpen` without `glueOpened` → the same handler with `origin=engine`; the speaker comes from `Game.GetDialogueTarget()` and, as a cross-check, `ObjectReference.IsInDialogueWithPlayer()` [V\* `:426`] — the two Papyrus-only primitives this mod list already relies on (**REQ R2-A3**), never crosshair logic. The NPC's real line is the "ask". Layer 0 of such a session is treated as a CLOSED layer unless the index says it is a root list. The usual reply is `do=wait`; a continuer-like or index-unscripted single entry auto-advances (2.5). A courier normally has one unscripted entry or none and runs by itself; the items arrive through the real fragment |
| **Blocking topics** | Nothing to do: the engine's list already contains only the blocking branch. All entries are sent under the closed-layer rule, never bucketed |
| **Scenes with player dialogue** | **`_restrict_onscene` does NOT protect this case — the opposite is true** (R9 / D-17, CMP-C1, replacing the previous claim). The `AUTO_ELIGIBILITY` gate that consumes `_restrict_onscene` runs only for **automatic** events; `HTTPManager::stream` classifies the five player-input types as **non-automatic** (`xor al,1` at `0x1635cf`), so **player speech always reaches `QueueInterruptNPC`** (**CHIM** `p2-chim-interplay.md:676-681`, `:498-504`). `InterruptNPC` sets the agent's was-on-scene byte when `GetCurrentScene() != null` (`mov [rsi+0x6f],1` at `0x52b28`, `:471`), which arms the cleaner; ~78–90 s later `EndDialogueClearScene` runs `currentScene.Stop(); Utility.wait(1); currentScene.Start();` — or, the scene having ended, the same on the quest owning the NPC's package — under CHIM's own comment `; Try to restart scene, can break quests` [V\* `AIAgentAIMind.psc:1886-1904`; `Quest.psc:129-132`]. **Hard rule:** a session whose `speaker.GetCurrentScene() != None` **never enters PENDING and never triggers `lrg_dlgtalk`** — it goes straight to MANUAL, the menu is handed back and the player clicks. Reading and harvesting continue unchanged. **Server side:** while the NPC's snapshot says scene, `TakeUpBusiness` is not offered and no `<business>` block is shown, so the model cannot invite the player to speak. Without this rule the design would actively steer the player into the failure — MQ302 Season Unending is exactly the shape: seated in the blocking scene, layer 0 read, `do=wait`, the player speaks to name a hold, and ninety seconds later Ulfric's scene or the quest owning his package is `Stop()`/`Start()`ed. Skyrim has no scene player-dialogue *action* (30,938 actions are Dialogue/Package/Timer, **BRANCH 0.9**), so such a choice always arrives as an engine-opened session. See §5.2 **C15** and flow test **30b** |
| **Followers** | Same flow. Ranking pushes follower-framework command menus and filler quests down (4.3). CHIM's own follower actions stay available for NPCs already recruited; "join me" for an NPC who has a real recruit topic goes through that topic. An E-press on a follower can open up to ~1 s late (Immersive Interactions' animation) — the handler is driven by `OnMenuOpen`, never by the key |
| **Merchants / services vs CHIM's own actions** | The REAL entry wins whenever the known list contains it. For that turn the server hides, **by `code_name`**: `RentRoom`, `HireCarriage`, `HireFerry`, `Brawl`, `Training`, `OpenInventory` (LLM name `Trade_Items`; there is no `TradeItems` code — **CHIM W.1-12**), `OpenInventory2`, and `GiveGoldTo` / `TakeGoldFromPlayer` while a priced or bribe entry is listed. With no list known, CHIM's actions stay (no quest logic is involved). **Renting is two steps here:** Xtended Stay's hub `RentRoomStartTopic "I'd like to rent a room."` (no price tag; it *does* speak, via the shared response "Of course." — **BRANCH D1** corrects the earlier "silent hub" claim) → a closed layer of 7 priced duration entries, whose own response is a blank INFO and therefore genuinely silent. The hub is class `service`, the durations are class `pay`. **The durations are discovered INSIDE the session, after the hub click, so the LLM never saw them — matching them is neither key matching nor intent matching, and §4.4 would have forbidden executing them** (CMP-C4). They are executed under the new **same-session continuation** mode (§4.4, D-18): if the utterance that started the turn already named the value ("rent me a room for three nights"), the layer completes in **one** session and gold moves exactly once. If it did not, the NPC asks how many nights and the answer arrives as a normal pending layer. Without D-18 the layer parks, the player speaks, CHIM interrupts, the session dies, the layer is LOST, `txt` is not in the fresh root list because it is one layer down, and renting a room — the commonest service interaction in LoreRim — shows the vanilla menu every single time. The same shape hits every priced sub-layer and every "how many?" follow-up. After Barter / Training / Gift closes, the engine returns to the list: SUSPENDED → `ShowList()` → READING |
| **Guards, arrest, bag checks** | **LETHAL** grade (2.7, D-16) — `IsGuard()` **and** (engine-opened **or** crime gold > 0), which catches both the bag-check ForceGreet with no bounty and a glue-opened session on a guard with one. Handed back visible by default (`iCritical = 0`), and never guarded, so the player can actually click "I submit. Take me to jail." With `iCritical = 1` every entry is commit-class, "resist" needs explicit naming plus a read-back, and the watchdog never closes. `ForgiveCrime`, `PayBounty` and `ArrestPlayer` are hidden for the turn while a crime session or crime list is known; `ForgiveCrime` is hidden **always** while menuless is on if the owner agrees (decision D4) — a guard should let the player go only when the engine selected the real `DGCrimePersuade` / `DGCrimeBribe` INFO. `PayBounty` is safe to keep in general: it checks `GetItemCount(gold) >= bounty` before `PlayerPayCrimeGold` (`AIAgentAIMind.psc:3279`, **REQ R2-C2**) |
| **Rumours / "What's the word?"** | Offered only when the player explicitly asks for news; otherwise the LLM's own chatter is richer |
| **Placeholder / empty prompts** | Whole-prompt placeholders (`(Invisible Continue)`, `(forcegreet)`, EditorID-looking text) or empty text: a single such entry auto-advances; several are hidden from the LLM and labelled `(continue)` |
| **Goodbye** | Judged intent `leave` → a back-out entry if one is listed ("Never mind.", "I need more time…"), else the engine close. CHIM's `EndConversation` is hidden while a session is open or a layer is pending |

### 2.4 Two-pass delivery (server → game) with no LLM call

`lrg_topics want=1` is answered by the glue's own handler in `preprocessing.php`, before the MAIN lock. The server ALWAYS answers on both routes; the game de-duplicates by `x` (an executed ring of 8).

- **D1 — same HTTP reply.** `echo "<npc>|command|ExtCmdLRG_SelectTopic@<param>\r\n"` then `terminate()`. Precedent [V\* `processor/comm.php:1021`]: the `togglemodel` branch does exactly `echo "{$GLOBALS["HERIKA_NAME"]}|command|ToggleModel@$newModel\r\n";` and `togglemodel` is sent with both `logMessage` and `logMessageForActor` (`AIAgentPapyrusFunctions.psc:441,444`). **[U] P14a.**
- **D2 — a `responselog` row**, echoed on the DLL's `request` poll. Insert shape copied verbatim from production [V\* `lib/rolemaster_helpers.php:790-800`]:
  ```php
  $GLOBALS["db"]->insert('responselog', ['localts' => time(), 'sent' => 0,
      'actor' => $npc, 'text' => "", 'action' => "command|ExtCmdLRG_SelectTopic@{$param}", 'tag' => ""]);
  ```
  The poll echoes `"{$responseData["actor"]}|{$responseData["action"]}|{$responseData["text"]}\r\n"` [V\* `processor/comm.php:367-372`], so with an empty `text` the wire line ends in a trailing `|`. **Every param therefore ends with the throw-away key `z=1`.** `DataDequeue(time()+1)` claims rows with `sent=0` atomically (`FOR UPDATE SKIP LOCKED`) [V\* `lib/data_functions.php:874-905`], so a row written with `localts = time()` is picked up on the next poll. **[U] P14b.** **D2 is not the 0.5 s route** (CMP-m2): it is consumed by the game's SERVER QUEUE poll, whose `request` rows sit at **5 s spacing** in the live DB (**CHIM** `:286`, `:466`) — not by `ManagerMainQueue::threadFunction`'s 0.5 s command cadence (`:690`). **A P14a failure therefore makes a business turn 11–14 s, not 6–9 s** (§2.2, third column), and the owner should see that before approving the architecture.
- **D3 — probe only.** `requestMessageForActor` with the word `suggestion` in the payload (a binary-verified skip of the interrupt block, but an undocumented quirk).

**`lrg_topics` and `lrg_dlg` are ALWAYS sent with `AIAgentFunctions.logMessageForActor`.** `requestMessageForActor` formats `"{}|{}|{}|(Context location: {}){}"` and calls `HTTPManager::stream`, where every non-player request type is classified "automatic" and can be dropped silently (`[AUTO_ELIGIBILITY] Suppressing automatic event for {} (reason={})` — `cooldown, hostile, combat, restrained, unconscious, sleeping, scene, invalid_actor`) and, if eligible, runs `QueueInterruptNPC` on the target (**CHIM W.3-1**). Using it on the session NPC would end the glue's own session and null her voice.

If **both** delivery routes turn out dead, the design still works: cached keys plus a local prefix match need no reply at all, and first contact degrades to "harvest, close, and let the NPC answer in her own words with the list now known".

**`lrg_dlgtalk`** is the one LLM-triggering message of this module: `AIAgentFunctions.requestMessageForActor("again", "lrg_dlgtalk", npc)`, sent **only when no session is open**. It means "answer the player's last words yourself; your business list is now known". It is used after: intent mode found no match; the match was not a `plain`/`service` entry (intent mode never executes checks, payments or commits, because the model never saw the list and therefore judged nothing); or an `Error:` the NPC should react to. Admission happens in `preprocessing.php` exactly as `lrg_initiative` does, and `prerequest.php` switches functions back on for it — necessary because `main.php:1078` disables actions for every type the core does not know (this is precisely what Phase 1's `lrgPrerequest()` already exists for, `prerequest.php:1-13` [V\*]).

### 2.5 Auto-advance (this amends `REVIEW_40` item 7)

A single visible entry is clicked without the player **only** when:
- its text is continuer-like (`...`, "Go on.", "And?", or a whole-prompt placeholder) — immediately; or
- the index says the INFO is **unscripted** — after `auto_advance.grace_seconds` (2.5 s, counted from the end of the line, cancelled if a player utterance arrives).

Scripted, script + Goodbye, an entry that follows an NPC question, or anything **not in the index** → wait for the player, with the guidance line "the conversation is waiting for {player} to say something like: '<entry>'". F10 is the reason: 1,324 of 4,075 single-entry layers run a script and 689 commit and close. Blood on the Ice must never jail Wuunferth because the player mentioned a suspicion.

### 2.6 Closed choice layers and voice input (gated by X1)

The situation: a layer is pending, the session is open and parked, the player speaks — and CHIM runs `EndDialogue` on that NPC (F1). `iBranchInput = 0 (auto)` resolves at runtime:

- **Session survives** (no `ev=closed why=external` follows the utterance) → **voice**. The LLM returns a key of the pending layer and the game clicks in the live session. The server checks that the responding `HERIKA_NAME` is the list owner both before OFFERING and before EXECUTING, because CHIM's router has no notion of "the NPC I am in dialogue with" and can route a spoken answer to the Narrator when the crosshair is empty (**CHIM V.3-7**). The block tells the NPC nothing about this; the MCM help tells the player: say the NPC's name, or keep her under the crosshair.
- **Session dies** → the server marks the layer **LOST** (kept 120 s). A pick of a LOST-layer key is emitted with the dead `sid`; the game re-opens and looks for `txt` in the fresh list. Found → click. Not found → **assisted**: `do=show` hands the menu back visible for that layer with one notification; and once two layers have been lost in a game session, that NPC's closed layers switch to assisted up front.

**The re-match rarely works, and the numbers say so** (CMP-C3, replacing "many choice layers re-appear immediately"). Of **7,962** multi-entry (choice) layers, only **1,106** re-offer their own parent topic — a hub loop. The other **86 %** sit one or more layers below the root, so a re-opened session shows the **root** list, the `txt` re-match fails, and the design falls straight to "assisted" = the vanilla menu. **2,154** of those layers have ≥ 2 scripted entries (a candidate real choice) and **192** single-entry layers are "NPC line ends with '?' AND the entry is scripted" [V\* `research/p2_speech_tags.json deep.multi_entry_layers` / `deep.single_entry_layers`].

**Expected menuless coverage, by X1 outcome:**

| X1 | Layer 0 (root) | Layer ≥ 1 (closed choice) | What the owner gets |
|---|---|---|---|
| **survives** | menuless | menuless by voice in the live session | the headline feature, end to end |
| **dies**, `bRewalk = 0` | menuless | ~14 % recovered by re-match, **~86 % assisted** | "branching choices are handled appropriately" means *the menu is shown for layer 2 onwards* |
| **dies**, `bRewalk = 1` (D-19) | menuless | re-match first, then the bounded re-walk; assisted only when the walk aborts | most branches menuless, at the cost of replayed lines |

- **The bounded re-walk moves into Phase 2** (D-19), off by default, meaningful only when X1 = "dies". It is **a replay of steps this very session already walked**, not an index-only guess — that distinction is what keeps §4.2's invariant ("a wrong index can never cause a wrong effect") intact. A recorded step may be re-clicked only when **all** hold: the step was clicked in **this** session; its own `ev=result` showed **no** stat delta, **no** gold movement, **no** flag flip, **no** new `questlog` row and **no** menu close; the index says `scripted = false`; it is not Say-Once, not Goodbye and carries no `twat`; and the layer signature recorded at that step matches what the re-walk now sees — otherwise the walk **aborts to assisted**. `rewalk.max_depth` 2. MCM counters `layers_lost` / `layers_rewalked` / `layers_assisted` report what actually happens.
- Critical layers: **LETHAL** is always assisted while `iCritical = 0`; **COSTLY** stays menuless (D-16, 2.7).

**X1 is a design gate, not a config value** (§0.3): it runs first in T0, and a "dies" answer is what makes D-19 and decision **D7** live questions rather than hypotheticals. If X1 shows "dies", a seamless deep branch is the one thing only a later DLL can give (§8: a session shield around CHIM's `EndDialogue`).

### 2.7 Critical sessions

**Two grades** (D-16, R8), because one grade covers 401 topics and would hand the vanilla menu back on the best quest moment in the game — MQ302's `"I'm listening."` INFO is Walk-Away flagged (**BRANCH :206**) and is this design's own showcase branching example.

| Grade | Set by | Behaviour |
|---|---|---|
| **LETHAL** (`crit=2`) | **Game side, on EVERY session, glue-opened included** (CMP-C2): `speaker.IsGuard()` [V\* `Actor.psc:379`] **AND** (the session is engine-opened **OR** `speaker.GetCrimeFaction()` is not `None` and `.GetCrimeGold() > 0` [V\* `Actor.psc:178`]). · **Server side, from data:** `twat` resolves to `DGCrimeResistArrest` (`skyrim.esm:0267DC`), or an override file rule. ~18 winner-state INFOs | Handed back **visible** while `iCritical = 0`. Never hidden, never guarded (§1.3 ARMING), never closed, never timed out, never force-closed. `do=show` on any doubt |
| **COSTLY** (`crit=1`) | Any other `twat` in the index on an entry of the layer or on its parent INFO — the other ~383 topics of F11 | **Stays MENULESS.** Never auto-closed, never timed out, never force-closed. `do=leave` requires an **explicit spoken intent to leave** — not silence, not a timeout — and the `<business>` block labels the back-out entry `[leaving now ends this]` |

**Why `AND (engine-opened OR bounty)` and not a flat `OR`** (see §10, rejection 2). The game-side test must catch the two dangerous shapes: a guard **ForceGreet** with no bounty (the six City Bag Checks INFOs carry no crime-gold condition at all, F11), and a session the **glue itself opened** on a guard while the player has a bounty ("see if he'll look the other way"). It must **not** fire on every conversation in the province: with a LoreRim attack bounty outstanding, a flat `IsGuard() OR GetCrimeGold() > 0` would make talking to Belethor critical, and the feature would be off for most of the mid-game.

**`fDecideTimeout` never closes a session** (CMP-C2, §1.3 DECIDING). Its only outcomes are PENDING or MANUAL. And until **E-R7** is answered, **`CloseForce(1)` and `CloseForce(2)` are probe-only**: §1.3 CLOSING escalates `CloseClean()` → MANUAL, never to a force close. E-R7 ("does a script-side close trigger the walk-away / resist-arrest path?") is tested in **P11b on a harmless non-critical closed layer only**, with both primitives (`_global.skse.CloseMenu` and PO3 `HideMenu`) — never on a guard, never during an arrest.

---

## 3. Wire protocol additions

Same rules as `PROTOCOL.md` §0 [V\*]: `k=v;k=v`; a value never contains `; | @ "` or a newline; both sides ignore unknown keys; **a missing key always means the strict/neutral reading**.
Versions: `LRG_Main.CurrentVersion` **300** · `LRG_DLG_ACTIONS_VERSION` 1 · `LRG_SCHEMA_VERSION` 3 · `LRG_PROMPT_INDEX_VERSION` 1 · `LRG_VERSION` `0.3.0`.

### 3.1 Game → server (state messages)

Transport: `AIAgentFunctions.logMessageForActor(payload, type, npcName)` via `LRG_Main.SendNpcMessage` [V\* `LRG_Main.psc:320-325`]. Both new types join `external_fast_commands` (merged into `$fast_commands` at `main.php:233-234` [V\*]), are handled and terminated in `preprocessing.php`, and never enter the event log.

**`lrg_topics`** — one engine-built list.

`v=1` · `sid` · `gen` · `layer` (clicks so far in this session) · `origin` = `glue|engine|dump|probe` · `ref` (speaker reference FormID, hex) · `npc` · `st` (menu state) · **`n`** (entries in the list — **always the full `EntryCount()`, never the cap**) · `sent` (entries in this message) · **`part`** (1 = head with all fields, 2 = tail of texts only; absent = 1) · `cid` · `want` 0/1 · `ask` (intent words echoed from the command) · **`crit` 0 \| 1 COSTLY \| 2 LETHAL** · `scene` 0/1 (D-17) · `fam` (SWF family) · `sub` (subtitles on, 0/1) · `pg` (player gold; only when a text carries a gold tag) · `bamt` (`GetBribeAmount()`; same condition) · `vt` · `q` (csv of ≤ 6 quest EditorIDs from `PO3_SKSEFunctions.GetActiveAssociatedQuests(npc)` [V\* `:812`] + `Quest.GetID()`) · **`e` LAST**: entries joined by `~~`, each `<pos>~<topicIndex>~<new 0|1|->~<col or ->~<text clipped to 140>`. In a `part=2` message each entry is `<pos>~-~-~-~<text>`.

The game replaces only `|` → `/` and `~` → `-` inside a text; the server takes everything after `;e=` raw, exactly as `lrg_log` does with `msg` [V\* `lrg_actions.php:176-178`].

**Payload budget** (CMP-m6). CHIM sends every message as a base64 `DATA=` GET query — a live decode of `/var/log/apache2/other_vhosts_access.log` shows `GET //HerikaServer/streamv2.php?DATA=aW5wdXR0ZXh0fDIz…` → `inputtext|…|Jordan:\r\nHello there, Brast…`, and the DLL's format string is `GET /{0}?DATA={1} HTTP/1.1` (`chim-dll-esp.md:165`, DLL@`0x305518`). 16 entries × 140 chars inflates by ~4/3 before the per-message context blob, which would make this the largest thing the install has ever sent by two to three times: across 7,951 logged GETs the longest request target is **2,366** chars, p99 **1,209**, p50 **285**. Apache is safe (`LimitRequestLine 65535`, `000-default.conf:30`); the **DLL-side formatting buffer is unverified**. Therefore `e=` is capped **by bytes as well as by entry count** — suggested **1,600 raw chars** — and the remainder spills into the `part=2` message. **P13 logs the final payload length and whether the server received it intact.**

**`lrg_dlg`** — session events, keyed by `ev`:

| `ev` | extra keys |
|---|---|
| `open` | `sid, origin, ref, npc, crit, fam, vt, dist, sub, sg` (the five Speech globals, csv) |
| `line` | `sid, gen, ref, npc`, **`t` LAST** = the subtitle string (≤ 300). **De-duplication is keyed on the pair `(gen, string)`, not on the string alone** (CMP-m8): shared `DNAM` responses repeat constantly — the rent hub's own answer is the shared "Of course." — so two layers of one session can legitimately produce the same NPC line, and keying on the string alone would drop the second and make `<what_just_happened>` quote the wrong one. After `DNAM` resolution 43,941 of 44,370 player INFOs have response text, i.e. shared responses are the norm (**BRANCH C5/D1**). This is the glue's own ground truth; CHIM's `traditional_npc_speech` capture is used when present but never depended on (X3) |
| `result` | `sid, gen, cid, x, ref, npc, pos, i, kind` (echo)`, ok` 1/0`, why`(`stale, unverified, dry-run, not enough gold, …`)`, closed` 0/1`, other` (the menu that opened)`, dP, dB, dI` (stat deltas)`, gold` (delta)`, bribed, intim` (0/1 = flag flipped)`, bamt, wis` (`WillIntimidateSucceed` before)`, sp` (Speechcraft AV)`, dur` (s)`, **`txt` LAST** = the clicked text |
| `closed` | `sid, ref, npc, why` = `goodbye\|asked\|handoff\|external\|refused`, `pending` 0/1, `layer`, `sg`, `drift` (net change of the five globals) |
| `unhide` | `sid, ref, npc, why` = `key\|watchdog\|critical\|show\|unverified\|read-failed\|wrong-speaker\|no-route` |
| `facts` | `ref, npc, q, vt, dlg` (menuless on 0/1) — sent on crosshair change for CHIM agents, throttled 60 s per NPC. **Phase 1's `lrg_npcstate` gains no key in this round** |

**`lrg_dlgtalk`** — `requestMessageForActor("again", "lrg_dlgtalk", npc)`; LLM-triggering; admitted only when the server holds a player utterance for that NPC ≤ 30 s old **and** no session is open.

**Results** use Phase 1's `funcret` path unchanged (`LRG_Main.ReportResult`, `:336-347` [V\*]). New reasons for PROTOCOL §1.6's closed list: `they cannot talk right now` · `her voice is not ready` · `that is not on the table right now` · `that moment has passed` · `not enough gold` · `dry-run mode, nothing was clicked` · `a conversation is in progress`. New success strings: `The matter is raised.` · `The conversation is left.` · `The menu is shown.`

### 3.2 Server → game: the single action `ExtCmdLRG_SelectTopic`

Wire line `<npc>|command|ExtCmdLRG_SelectTopic@<param>`; param with a fixed key order (tests match substrings):

```
ok=1;cid=<cid>;npc=<name>;ref=<refid hex>;do=<pick|open|leave|show|noop>;sid=<sid or 0>;gen=<gen or 0>;
pos=<array position or -1>;i=<topicIndex or -1>;txt=<safe prefix or empty>;
kind=<plain|service|persuade|intimidate|bribe|pay|commit|meta|silent|back>;cost=<gold or 0>;
ask=<intent words or empty>;vt=<hex or 0>;x=<exec id>;z=1
```

- `txt` = the longest prefix (12–40 chars) of the entry text free of `; = @ | " ~`; empty when shorter than 12 (then only server matching can find the entry).
- **The game drops without executing** (reason goes into the `funcret`): `ok != 1`; `x` already in the executed ring (silently, no `funcret` text — that is the second delivery route arriving); `ref` ≠ the live speaker or ≠ `getAgentByName(npc).GetFormID()`; `sid` is the live session but `gen` is older than the live `gen` → one `stale` re-match through `lrg_topics want=1`; the command is older than 20 s when it starts executing; a session with another speaker is open.
- `do=pick` with a live `sid` → click `pos` after `SelectAndVerify(pos, i, txt, route==A)`. `do=pick` with `sid=0` or a dead `sid` → open, find by `txt`, else `want=1`. `do=open` → open, read, `want=1;ask=…`. `do=leave` → the back-out entry when `pos >= 0`, else CLOSING. `do=show` → MANUAL. `do=noop` → logged only (probe).
- **Speaker binding is by reference FormID end to end**; the display name only addresses the CHIM agent (`REVIEW_40` item 17).
- **Result duty, restated so it is implementable** (D-20 / CMP-M6). The previous wording ("every exit path of `CmdSelectTopic` ends with exactly one `ReportResult`") contradicted §3.1's own reason list: `not enough gold`, `that moment has passed` and `dry-run mode, nothing was clicked` are all decided by the **loop**, long after `CmdSelectTopic` returned. The binding rule is:
  > **`CmdSelectTopic` validates, stamps the request and returns within one Papyrus frame. It contains no `Utility.Wait*` of any kind. It emits `ReportResult` only for the synchronous refusals of §1.5. Every other outcome is reported by the loop, exactly once per `x`, and `AIAgentFunctions.commandEndedForActor` is called exactly once per `x` in total.**

  Blocking is the worse branch, not a neutral one: `LRG.DispatchExternalCommand` is invoked from the DLL, commands execute **only** from `ManagerMainQueue::threadFunction` (the sole caller of the command executor `0x9e3f0` is `0x2064d5`, **CHIM** `:690`), and that same thread runs the `[CLEANER]` voice-type restore (`:532`, writes at `0x20a1c6`). A `CmdSelectTopic` that waited 3 s for the menu and then 8 s for a speech gate would stall **every** other CHIM command and **every** voice restore for the whole agent set. A silently dropped duplicate `x` still calls `commandEndedForActor` once. The detailed outcome travels separately in `lrg_dlg ev=result` after the response. Flow test **21b** asserts it.

**The LLM-facing `item`** is one key `T1..T12` from this turn's `<business>` block, or `leave`, or — only when no block was shown, or none of its keys fits — the business in 3–8 plain words ("ask for work", "rent a room", "about the missing dog").

Catalog row, installed by `lrgDlgEnsureActions()` with its **own** marker `data/.dlg_actions_v1` (never inside Phase 1's `LRG_GLUE_ACTIONS` list): `code_name` `ExtCmdLRG_SelectTopic`, `action_name` `TakeUpBusiness`, `parameters_json` = an object with a required string `item`, metadata exactly in Phase 1's shape [V\* `lrg_actions.php:56-68`] — `dispatch: plugin_command`, `source: LoreRimGlue`, `bridge_script: LRG`, `bridge_entrypoint: DispatchExternalCommand`, `requirements.request_types_any` = the four player-speech types **plus `lrg_dlgtalk`**, `confirmation.default_policy: automatic`, `suppress_placeholder_infoaction: true`, `followup.enabled: false`.

### 3.3 Server state

`migrations/003_lrg_dialogue.sql` creates table `lrg_dialogue` in the same shape as `lrg_memory` (`npc_name` PK, `payload` jsonb, `updated_at`), and every read is shaped `… FROM lrg_dialogue WHERE npc_name=<escapeLiteral(name)>` so the flow tests' in-memory fake can serve them (PROTOCOL §5 rule [V\*]).

Payload keys:
`ref` · `vt` · `q[]` · `root` = `{sid, gen, at, loc, qsig, entries[]}` (the last ROOT list; `qsig` = `max(questlog.rowid)` at harvest; invalid when `qsig` changed, the location changed, or `at` is older than `cache.max_age_seconds` = 1800) · `session` = `{sid, origin, crit, state: open|pending|lost|closed, layer, gen, at, entries[], asked_line}` · `offer` = `{turn_cid, keys: {T1: entryRef, …}, at}` (what THIS turn's prompt showed — the post-gate accepts nothing else) · `parked` = `{norm, text, kind, at, expires, req}` · `attempts` = `{<norm>: {kind, result: pass|fail|unknown, at, sp, gold, wis, bamt}}` · `last_exec` = `{x, cid, at, text, kind, entry, told}` · `last_result` (the composed ground truth waiting for the next turn) · `utter` = `{text, at, type}`.

Entry record: `{pos, i, new, col, text, norm, key, quest, journal, kind, variant: success|failure|na, flags: {goodbye, invis, walkaway, sayonce}, scripted, compound, twat, **crit: 0|1 COSTLY|2 LETHAL**, cost, class, **tail: true when it arrived in a part=2 message (text only)**}`, with `class ∈ plain | service | check | pay | commit | meta | silent | back | hidden`.

`session` additionally carries **`scene`** (D-17: no `<business>` block, no `TakeUpBusiness`, no PENDING) and **`path[]`** — the ordered record of every step clicked in this session, each `{pos, i, text, norm, scripted, sayonce, goodbye, twat, sig, result_clean}`, where `result_clean` is true only when that step's `ev=result` showed no stat delta, no gold movement, no flag flip, no new `questlog` row and no menu close. `path[]` is what the bounded re-walk of D-19 replays; nothing outside it is ever re-clicked.

De-duplication: `x = substr(md5(uniqid()), 0, 10)`; one `x` per emitted decision, and **both** delivery routes carry the same `x`.

---

## 4. LLM side

### 4.1 Who decides what

| Decision | Decided by |
|---|---|
| which topics exist, with which text, for this NPC right now | the **ENGINE** (the live list) |
| what an entry *is* (check kind, variant, scripted, commit, walk-away, quest) | the **INDEX** (built from the owner's load order); text tags are the fallback; the override file is last |
| whether the player's words raise an entry, and which one | the **LLM** (a single call — the conversation model itself) |
| whether a persuade / intimidate / bribe was really ATTEMPTED | the **LLM**, inside hard server rules (4.5) |
| whether it SUCCEEDS | the **ENGINE** (real conditions, Requiem and LoreRim data included) |
| whether the pick may run now (offered, fresh, affordable, confirmed, not suppressed) | the **SERVER** post-gate, then the **GAME** again |

### 4.2 The offline prompt index (owner: INDEX)

`tools/build_prompt_index.py` — a **new** file derived from `research/p2_speech_tags.py --dump`; the research script itself stays untouched. It runs inside WSL against `/mnt/f/Modlists/LoreRim` (~15 s) and writes no plugin.

Required fixes over the research scanner, each one traced to a verifier correction:

| Fix | Source |
|---|---|
| Parse `NAM1` for **every** DIAL category and resolve `DNAM` shared responses; store all response lines. Without this, 21 % of player INFOs look "silent" and the hub of the rent flow looks unspoken | **BRANCH D1/D2**: after the fix, 43,941 of 44,370 player INFOs have response text and only **429** are truly silent |
| Filter on DIAL subtype — `SNAM == CUST` plus the favour subtypes — before calling anything a speech check | **REQ R2-C1**: otherwise 558 GDO guard-HELLO INFOs are indexed as persuasion options |
| Store the **speaker-condition set per INFO**, so "the failure variant for THIS npc" is computed per speaker | **BRANCH C1** |
| Store the full condition list per check INFO plus a `compound` flag | **BRANCH C4**: ~10 of 477 check INFOs accept a weapon skill instead of the check |
| Store `twat` per INFO | **REQ A2** (critical sessions, 2.7) |
| Turn token prompts (`<Alias=…>`, `<Global=…>`, `<BribeCost>`, `<CrimeGold>`; 576 of them) into match patterns | **BRANCH 2.x** |
| Read `SKSE/Plugins/DynamicStringDistributor/**/*.json` for `DIAL FULL` / `INFO RNAM` / `INFO NAM1` entries; apply them, or **fail loudly** if any appear | **REQ R2-A4**: today all 192 files are `ACTI RNAM` / `FLOR RNAM` / `PERK EPFD` / `GMST DATA` — zero dialogue entries — so plugin text == displayed text *for now* |
| Model PNAM re-ordering and flag the four affected topics | **BRANCH B6**: in `DialogueDushnikhYalGhorbash*` / `DialogueMorKhazgurBorgakh*` the success INFO is sorted after an unconditional failure INFO, so those checks may be unpassable in this load order. Report to the owner as a possible LoreRim data bug; change nothing |
| List the 82 localized Creation Club plugins as `unreadable` (22 CC speech topics stay engine-only) | decision **D6** |
| Price parsing regex: `\(([^()]*?)(\d[\d,\.]*)\s*(gold\|septims?)\b[^()]*\)\s*\.?\s*$` (group 1 = the verb) | **BRANCH C3**: the old regex missed priced forms and would have matched `(3 days left)` |

Output: `data/prompt_index.ndjson`. Loader `php lib/lrg_prompt_index.php load` fills Postgres (`migrations/004_lrg_prompt_index.sql`): `lrg_prompt(norm, pattern, topic_key, info_key, quest, journal, toplevel, kind, variant, flags, scripted, compound, twat, links, resp)` with a `pg_trgm` index on `norm`, plus `lrg_prompt_layer(parent_info, fingerprint)`. Rebuilt by `deploy_server.ps1`, and whenever the hash of `plugins.txt` + `modlist.txt` changes — checked at most once a minute on the fast `lrg_dlg ev=facts` path and **never inside an LLM request**, the same discipline Phase 1 uses for the scene index [V\* `preprocessing.php:19-21`].

Binding API (`lib/lrg_prompt_index.php`):
```php
lrgPromptLookup(array $texts, array $npcFacts = []): array  // per text: best record or null ('unindexed')
                       // order: layer fingerprint (subset-tolerant) -> exact norm -> token pattern -> none
lrgPromptLayerKind(array $records): string                  // root | closed | unknown
lrgPromptIndexStatus(): array
// test seam: $GLOBALS['LRG_TEST_INDEX']
```
**The index is ADVISORY.** Execution is always "click the live entry". A wrong index can cost one confirmation too many or too few; it can never cause a wrong effect.

### 4.3 What the NPC is told

**Static block, `character_bottom`, only while menuless is on for this NPC (≤ 70 words):**

```
<real_business>
Jobs, quests, payments, favours, access, secrets, services and arrests are settled by the world,
not by talk. You can only grant, accept, refuse or conclude such a thing with the action
TakeUpBusiness. Until then stay in character: deflect, haggle, ask.
Never say whether a persuasion, a threat or a bribe worked. The world decides; you are told afterwards.
</real_business>
```

**Volatile block, `prompt_bottom`, only when a list is known (root cache, pending layer, or LOST layer):**

```
<business for="{npc}" state="{things {player} can raise | {npc} is waiting for an answer}">
T1 {verbatim entry text, tag removed}
T2 [persuasion attempt] {text}
T3 [threat - refusing means a brawl] {text}
T4 [bribe: costs 137 gold; {player} has 412] {text}      | [...; {player} cannot pay]
T5 [commits - cannot be undone] {text}                   | [commits - {player} was asked and must now confirm]
T6 [says nothing] (Remain silent)
T7 [leave] {back-out text}
(+{N} more: small talk, rumours, follower orders)
Use TakeUpBusiness with item = one key ONLY when {player}'s last words clearly do that thing.
Two keys fit, or unsure: do not act - ask which they mean, in character.
With the action leave message empty: your real answer follows by itself.
A [commits] key: the first time, ask plainly whether they mean it, naming the choice, and use the
action in the same reply; it only happens after they confirm.
</business>
```

Rules behind that block:
- Entries are shown **VERBATIM** — they are the player's own line, so names, places and objectives stay intact by construction and nothing is summarised by a model. Only the leading tag is replaced by a label taken from the **INDEX kind**, because the text tag is wrong for roughly 20 entries and missing on 45 persuade INFOs (**BRANCH C2**).
- The `[threat - refusing means a brawl]` wording is used whenever the index shows the topic's failure variant ends in `(Brawl)` (**BRANCH B2**, 15 topics). Without it the model would think the player has to challenge someone to a fist fight; in fact the threat itself is the matching utterance.
- The quest name is added in braces only when `questlog` already has a row for that quest — no spoilers.
- **No thresholds, no skill numbers, no response previews, no stage numbers**, and nothing from CHIM's `skyrim_quest_definitions`.
- A **closed layer** shows ALL entries (cap 12, engine order, never bucketed). A **root list** shows a ranked top 8 plus the `(+N more)` line: +4 a quest in the journal, +3 `new`, +2 check / priced / action tag, +2 service, + lexical similarity to the utterance (`pg_trgm`), −3 filler quests and follower-framework menus, −2 questions shared by more than 20 topics. If `bQuestColour` passed P17, +3 for a row whose colour is `cQuestEntryColor`. **Ranking runs over `head ∪ tail`, never over the head alone** (R12).
- **While `sent < n`, the NPC may not deny that the matter exists.** The wordings "that's not on the table right now", "there's nothing like that between us" and every paraphrase are **forbidden** whenever any entry of the layer was not sent; the guidance instead asks the player to be more specific, and the server requests the tail before answering. A follower carrying Simple Follower Framework and Swiftly Order Squad command menus, Andrealphus job prompts and one Missives board topic would otherwise put that Missives topic at position 19, never read it, and have the NPC truthfully say the job does not exist — making the quest unreachable by voice for as long as the noise is there (CMP-M4).
- Class `hidden` (placeholders, META options such as `(skip quest)` unless an override allows them) never reaches the LLM.

**Quest awareness (`prompt_bottom`, ≤ 3 lines).** `q` from the game is joined to `questlog.id_quest`, newest row per quest, only quests with a displayed objective; bookkeeping quests are dropped and leftover tags rewritten (`<Alias=QuestGiver>` → "the quest giver" or the NPC's own name; `<Global=…>` → "some"):
`<shared_business>You and {player} have unfinished business: "{objective}".</shared_business>`
**Current objective only; never a future stage** (`REVIEW_40` item 18).

**Ground truth (`prompt_bottom`, once, on the next turn).** `<what_just_happened>` is composed from `ev=result` + `ev=line` (or CHIM's `traditional_npc_speech` rows newer than the click) + new `questlog` rows. Examples of the *shape* (not fixed strings): "{player}'s persuasion FAILED — you were not convinced and answered: '{line}'. You will not change your mind unless something about {player} changes. Do not soften this." / "You accepted a bribe: exactly {N} gold left {player}'s purse." / "Quest updated: '{objective}'." These are stated as facts; the model may elaborate but never contradict.

### 4.4 Matching rules

**Three modes, and only three.**

| Mode | What it matches | May execute |
|---|---|---|
| **Key mode** | the LLM returned a `T`-key from **this** turn's `offer.keys` | every class, under §4.5 |
| **Intent mode** | no block was shown, or no key fitted: the model named the business in words, matched server-side against a list the model **never saw** | `plain` and `service` **only** |
| **Same-session continuation** (NEW, D-18 / CMP-C4) | a layer discovered **inside the session the server is already executing**, answered from the utterance that **started that turn** | `service` and `pay` **only**, under the conditions below |

- **Single pass.** The LLM returns a key; the post-gate maps it through `offer.keys` and accepts nothing else. A key from an older turn is `stale`.
- **Intent mode / re-match** (server, no LLM): exact or containment on the normalised text → token overlap with stop words removed → `pg_trgm similarity()` → an optional Fast-connector side call **only** when the top two are within the margin (`match.side_call`, default off). Accept at score ≥ 0.55 with a margin ≥ 0.15.
- Class `check | pay | commit | meta` is **NEVER** executed from intent mode (2.4).
- **Same-session continuation** exists because the design's own service example needs it: the rent hub is class `service` and its seven priced durations are class `pay`, discovered only after the hub click, so matching them is by definition matching against something the LLM never saw. Its conditions are **all** required, and they are stricter than intent mode's: the utterance **names the value the layer asks for**; score ≥ **0.70** with a margin ≥ **0.25**; `pg >= N` for a priced entry; **depth 1 only** (one continuation per session); the layer carries **no `twat`**; class is `service` or `pay` and **never** `check`, `commit` or `meta`; and the **exact N and the exact entry text are echoed in `ev=result`** so `<what_just_happened>` states what really moved. Flow test **29** asserts that hub + "three nights" in one utterance completes in ONE session and moves gold exactly once.
- Ambiguity is never resolved by list order: the NPC asks.
- `topicIsNew` is a property of the INFO, not of the topic (**BRANCH A5**), so "not new = safe" is never applied to check or variant topics.
- **Freeze rule (B1).** `MenuTopicManager::Dialogue` binds each list entry to one `parentTopicInfo` at build time, so the shown variant reflects the evaluation made when the list was built (CommonLibSSE-NG `MenuTopicManager.h`, **BRANCH B1**). The server must therefore never let a CHIM action move gold or a skill between "list read" and "click", and the game re-reads the list if anything did (1.3, CLICKING).

### 4.5 Two-step confirmation and the speech-check protocol

All of this is **post-gate** logic on the server; `class` comes from the index and the heuristics are the last resort.

**Irreversible (`commit`) heuristics, first hit wins:**
override file → index: scripted + Goodbye; ≥ 2 scripted entries in one closed layer; a scripted single entry after an NPC question; a walk-away armed; tags `(attack)`, `(brawl)`, `(go to jail)`, scripted `(remain silent)`, relationship switches `(start romance)` / `(end romance)` / `[family]`, META → every crime-session entry → a priced entry with `cost >= confirm.min_gold` (100) or ≥ 25 % of the player's gold → **last**, a UI-only fallback for unindexed text: a closed layer of ≥ 2 entries that are all `new`, or one of the choice words (kill, spare, join, side with, accept, refuse, give, keep, free, arrest, betray, promise, marry, attack, pay, surrender, yes/no). That word list alone has 50 % recall and 28 % false alarms (**BRANCH 5.2**), which is exactly why it is last.
A vanilla "Are you sure?" sub-layer (an NPC question with two opposite entries) **is** the confirmation: never stack a second one on top.

**Confirmation state machine.** The first selection of a commit-class key is NOT emitted; it sets `parked = {norm, at, expires = at + 60, req}` and **the message of that turn is not muted** — it carries the NPC's question (4.6). A later selection of the same `norm` from a DIFFERENT request, while parked, and with a player utterance that is not a single token, is emitted. Any other key, or 60 s, clears the park. Voice input (`inputtext_s`) always takes both steps for commit-class; typed input that names the choice may skip it (`confirm.typed_skips`, default false). `(Remain silent)` is selected only on an explicit "I say nothing" — silence by timeout never selects anything. CHIM's own `confirmcommand` channel stays as a second net for META entries only (its `metadata.custom_config.confirmation_required` is per ROW, so it cannot be used for ordinary picks).

**Persuade / intimidate.** The label tells the model it is an *attempt*. The rule: use the key only when the player gave a reason, appeal or claim aimed at this matter (persuade), or an explicit or clearly implied threat aimed at this NPC (intimidate). Server rules on top:
- voice input needs ≥ `attempt.min_words` (4) words;
- one click per attempt;
- **retry suppression** — a failed `norm` for this NPC is re-clickable only when something relevant changed (`sp`, `wis`, gold, or the entry's shown variant flipped); otherwise the key is shown as `[already refused - refuse again, more curtly]` and a selection is dropped;
- **scoff-first** (config, default on) — when `wis=0` was observed, or the shown text is the index's FAILURE variant AND that failure INFO is scripted or Goodbye (which includes every `(Brawl)` variant), the first selection parks like a commit ("think hard about your next words") and the second executes.
- **Both rules are OFF for `compound` or unindexed entries.** Otherwise the glue would talk the player out of attempts the engine would pass — LoreRim's Dialogue Patch lets Ghorbash and Borgakh accept One-Handed / Two-Handed ≥ 75 instead of the check (**BRANCH C4**).

**Bribe / pay.** Parse the price N from the text with the corrected regex (4.2) and cross-check it against `bamt`; `(N days left)` is not a price. Player named an amount A ≥ N and `pg >= N` → execute, and the feedback says exactly N moved. A < N → no click; the NPC haggles upward (decision D5: name N, or only say "more"). No amount named → the NPC names the price (a fact from the list) and the player agrees → execute; that exchange **is** the two-step, for free. `pg < N` → never click; the label says the player cannot pay. `GiveGoldTo` / `TakeGoldFromPlayer` are hidden on such turns so gold moves exactly once, through the engine.

**Outcome classification** — the order matters:
1. **Stat delta first**: `dP` / `dB` / `dI` from `Game.QueryStat`. Note `FavorDialogueScript.ArrestPersuade()` does `IncrementStat("Persuasions")` **and then** `pTarget.SetBribed()` (**BRANCH B3**), so a flipped bribe flag alone never means "bribe succeeded"; `Intimidations` is not counted during a brawl; `Bribe()` does nothing without gold.
2. **Gold delta == `bamt`**.
3. **Flags** (`IsBribed` / `IsIntimidated`).
4. **The NPC's real line** matched against the index's success/failure response.
5. **The shown variant**.
"No delta" is **not** proof of failure (about 31 % of persuade successes never call `FavorDialogueScript`): fall through to the line match, or report `unknown` — and an `unknown` result is told as "you answered: {line}" with no verdict attached.

A free server-side cross-check exists: CHIM's `OnTrackedStatsEvent` writes tracked stat names into `conf_opts` via `logMessage(stat@value, "setconf")` (`AIAgentPapyrusFunctions.psc:1209-1226`; live rows already exist for `Locations Discovered` and `Most Gold Carried`, **BRANCH B4**). If `Persuasions` / `Bribes` / `Intimidations` rows start appearing after a headless check, that is independent confirmation that the engine counted it. Whether a Papyrus-side increment raises the same event is **[U]**, folded into E13.

### 4.6 Keeping the model from narrating an outcome

Five layers, four of them mechanical:

1. **Order.** On every turn where `ExtCmdLRG_SelectTopic` is offered, a `$GLOBALS["HOOKS"]["JSON_TEMPLATE"][]` callable rebuilds `$GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]` (and `required`) and `$GLOBALS["responseTemplate"]` with `action, target, item` **ahead of** `message`. The hook is real and is called by `chimApplyJsonTemplateHooks()` at the end of `chimRefreshJsonResponseState()`, *after* `setStructuredOutputTemplate()` [V\* `functions/json_response.php:43-51, 61-86`], with four production users (`spawncharacter.php:115`, `instruction.php:270`, `suggestion.php:139`, `smart_impersonation.php:53`) [V\*]. Config `dialogue.reorder_json` (default true) — **decision D3**, because it changes CHIM's output order on every player-speech turn.
   **Re-ordering alone is not enough** (CMP-m1). CHIM's schema is `"strict" => true` with `item` in `required`, and `item`'s own description hard-codes a long list of `BaseID:ItemName` / gold-amount / destination rules ending **"Leave item blank for SpawnGold and SpawnNPC and CreateNewNPC and DirectorCommand."** [V\* `functions/json_response.php:500-502`, `:496-497` for `target`, `:518-527` for `required`/`strict`]. The model is therefore told, inside the strict schema itself, to leave `item` blank for anything it does not recognise — while the prompt block asks it to put a `T`-key there, and Phase 2 needs **twelve** distinct keys, a heavier ask than Phase 1's small verb sets. So on SelectTopic turns the **same** `JSON_TEMPLATE` hook also appends one clause to each description: to `item`, *"…OR, when action is TakeUpBusiness, one key from the `<business>` block, e.g. T3, or 3–8 plain words naming the matter"*; to `target`, *"…the NPC you are speaking to when action is TakeUpBusiness"*. Both are asserted by flow test **32**.
2. **Mute.** `context_pre.php` sets `$GLOBALS["TRANSFORMER_FUNCTION"]`, chaining any transformer `prompt.includes.php:68` may already have installed [V\*]. The transformer runs on every sentence before TTS, and **a result shorter than 2 characters skips the sentence entirely** [V\* `lib/chat_helper_functions.php:1387-1389` and the `strlen($responseTextUnmooded) < 2 … continue;` guard at `:1416`]. The closure reads `$GLOBALS["LAST_LLM_RESPONSE"]["action"]`, which the connector sets inside its streaming decode before returning the text [V\* `connector/openrouterjson.php:1038`]; when the action is `TakeUpBusiness` **and the pick will actually be EMITTED this turn**, it returns `''` → no TTS, no subtitle, and the command leaves at once. It passes the text through when the pick is going to be parked or dropped, because that is the NPC's question or deflection and must be heard. Without the re-order the action is unknown while the first sentence streams — then the game-side `isActorTalking == 0` wait is the net.
3. **Tolerate.** The server must accept a non-empty `message` on a SelectTopic turn and simply skip its TTS, rather than trusting the model to leave it empty (**CHIM W.2-D**).
4. **Rules.** The two sentences of `<real_business>`; and on turns where a check entry is listed but not selected, the NPC may resist or scoff but never concede.
5. **After the fact.** `<what_just_happened>` is labelled as fact; an `unknown` outcome is never turned into a verdict.

**Duplicate player line.** CHIM logs the clicked prompt as the player's own speech, after the player already spoke in their own words. `dedupe_player_prompt = relabel` (default; alternatives `keep`, `drop`): in `preprocessing.php` a `chat` row that starts `(Context`, names the player, and contains `last_exec.text` within 8 s has its text rewritten to `(in effect: "<prompt>")`. **The NPC row is never touched** — it is the ground truth.

---

## 5. Requiem + LoreRim compatibility

### 5.1 Free, because the engine runs the real session

Winning conditions, INFO order and fragments of all 8,818 overridden vanilla INFOs and 77,452 new ones; `GetIntimidateSuccess` with Reqtificator NPC stats, Silver Tongue ×3 and the six other intimidation multipliers; `GetBribeSuccess` / `GetBribeAmount` with the rewritten `fBribe*` settings; persuade against the live, possibly trait-scaled globals or a mod's own literal; `SetBribed` / `SetIntimidated`; XP at Requiem's rates; `IncrementStat` (so Beneficial Speech Checks pays out); Say-Once; favour state; story events; `REQ_TrainingSessions` through the real Training Menu; Requiem's economy edits; Minor Arcana's level gates.

Requiem itself registers for **no** Dialogue Menu event (0 occurrences of `Dialogue Menu` in `Requiem - No Messages.bsa`, which is uncompressed and therefore scannable) and the Reqtificator output contains no DIAL/INFO/QUST, so **no re-scan of the index is needed after a Reqtificator run** (**REQ R2.1-2/3**). Caveat: `Requiem - Creation Club.bsa` is compressed and was not scanned.

### 5.2 Needs handling — each row is a build rule

| # | From the data | Rule |
|---|---|---|
| **C1** | Thresholds are runtime state. The **live** movers are the trait multipliers (×0.5/×2/×3) and Little Lessons' −10 on open. Silver Tongue's ×0.7 re-roll is **inert in this load order** and merely throws five "Cannot call SetValue() on a None object" per persuasion (F8 footnote, **REQ V.2-C2 / R2-A5**) — do not build on it and do not cite it as a mover | No constant, no formula, no prediction shown to the player. Hints (`wis`, `bamt`, `sp`) are read **inside** the open session, ≥ 0.6 s after open, and used only for retry suppression and scoff-first, and only on non-compound indexed entries. The zero-formulas rule is unchanged |
| **C2** | Little Lessons subtracts 10 on open and restores it on close **only** `If vtActive && SpeechVeryHard.GetValue() < 100`; a trait multiplier makes that condition false forever (**REQ R2-A1**) | **One open per business turn**, never one per click. Read the five globals at open and at close, report `drift` in `ev=closed`, and surface a warning in the MCM diagnostics after the third drift. Patch nothing |
| **C3** | Beneficial Speech Checks diffs `Persuasions` / `Bribes` / `Intimidations` in `OnMenuClose`; Requiem keys on the Training Menu | Every session ends with a real engine close. Settle up to 1.5 s after a check before closing |
| **C4** | RNAM variants; untagged and mis-tagged checks | Index first, tag as fallback; the matcher tries every RNAM variant of a topic |
| **C5** | CHIM's own shortcuts bypass Requiem: `ForgiveCrime` (`SetCrimeGold(0)`), `OpenInventory`/`OpenInventory2` (`ShowBarterMenu` on anyone), `Follow`/`FollowPlayer`, `Training`, `RentRoom` with a configured price, `HireCarriage` fast travel, and the quest engine's `set_stage` on the keyword "persuade" | Per-turn hiding by `code_name` when the real entry is known (2.3); `ForgiveCrime` hidden always in menuless mode (**D4**). `ScriptProxy` `SetBribed`/`SetIntimidated`/`SetCrimeGold` are **latent, not live** — no live action's `script_proxy_program` contains cmd_id 39/40/518/519 (**REQ R2-C2**) — so ship a health check that flags one if a future CHIM update adds it, rather than disabling anything. If `chimQuestEngineFeatureEnabled()` is ever true, the menuless module **stands down** and says so once |
| **C6** | 18 winner-state INFOs walk away into `DGCrimeResistArrest`, 6 of them with no crime-gold condition | Critical sessions from the index (2.7) |
| **C7** | `fAIInDialogueModeWithPlayerDistance` = 240 | Open only within 200 units, read live. A dropped session is `external`, never a retry loop |
| **C8** | Papyrus `Activate` probably skips activate-perk entry points | Refuse to open as Vampire Lord / werewolf / while sneaking; `bActivateDefaultOnly` and probe P0b for the rest |
| **C9** | Fragments close or replace the menu (Respec, Transmog); service menus open on top | The hand-off and SUSPENDED states; restore the cursor immediately |
| **C10** | Dynamic String Distributor can rewrite dialogue strings at runtime — today it rewrites none | The index builder scans its JSON and fails loudly on a `DIAL`/`INFO` entry; the entry text is always read live anyway |
| **C11** | Rite of Arcana's ghost effect closes every Dialogue Menu on open | `refused`, no retry (F12) |
| **C12** | Per-open side effects: Follower Stats' HUD panel and its `FollowerStats_DialogCatcherCloakAb` cloak whenever the speaker is not under the crosshair; Considerate Followers muting teammates; TDM blocking target lock; Helmet Toggle; ~20 DLLs hiding widgets | Short sessions, and prefer to open while the player is actually facing the NPC. Test a CHIM follower interjection during a session |
| **C13** | 13+ scripts treat `UI.IsMenuOpen("Dialogue Menu")` as "in dialogue", so OStim's and several other mods' hotkeys sleep for the whole hidden session, while Switch Camera During Dialogue's keys wake up (**SWF G9**, **REQ R2-A2**) | Keep sessions short; document it in the MCM help; never park a session across the player's idle time except in PENDING, which has its own 45 s limit |
| **C14** | If the owner ever enables IACC's Switch Target (`bSwitchTarget`, currently 0), IACC rewrites `eMenuState` in **both** directions and the state-1 readiness gate stops being trustworthy (**STACK R2-C5**) | The self-test reads that ini value and warns; the driver goes MANUAL if it is on |
| **C15** | **CHIM's `_restrict_onscene` does not cover player speech.** The `AUTO_ELIGIBILITY` gate runs only for automatic events; the five player-input types are classified non-automatic, so every player utterance about a scene actor reaches `QueueInterruptNPC`, sets the was-on-scene byte and arms the cleaner that `Stop()`/`Start()`s the scene or the quest owning the NPC's package — CHIM's own comment: `; Try to restart scene, can break quests` [V\* `AIAgentAIMind.psc:1886-1904`; `Quest.psc:129-132`; **CHIM** `:471`, `:676-681`, `:498-504`] | **D-17** (R9): a scene speaker never enters PENDING, never gets a `<business>` block, never triggers `lrg_dlgtalk` → MANUAL. `iSceneGate` governs only whether the glue *opens* on one; it never relaxes this. MCM help text states it in plain words. Flow test **30b** |
| **C16** | **Smart Talk's native skip defeats G1 for the keyboard as well as the mouse.** It writes `bAllowProgress = true` natively before the `bFadedIn` test and does not swallow the input (**STACK V.2-C3 / R2.1-7**); live `bSkipImmediateOnInput = 1`, `bHoldToSkip = 1`, `iAutoSkipInterval = 150` [V\* `SmartTalk.ini:92,95,101`]. E and Space are the most-pressed keys in the game | **D-21** (R11): `SmartTalkSafe()` is a machine-checked precondition read with `MiscUtil.ReadFromFile`, `bMenuless` cannot leave dry-run while any of the three settings is non-zero, and `GuardPoke()` runs every poll. **Never change `iPapyrusHandle` (live 3)** — it is what forces ≥ 500 ms before a fragment-bearing line can be skipped |

### 5.3 Owner-side settings (documentation only — the glue writes none of them)

| Mod | Setting | Why |
|---|---|---|
| **CHIM MCM** | Player TTS for Traditional Dialogue **OFF** (live: 0); Vanilla Dialogue capture **ON**; NPC Scene Safety **ON** (live: 1); AI Quest Progression **OFF** (live: false) | double speech and 7 s click holds; ground truth; scene actors; double stage setting |
| **Smart Talk** (`SmartTalk.ini`) | **`bSkipImmediateOnInput = 0` and `bHoldToSkip = 0` are NOT documentation — they are a machine-checked precondition** (D-21, C16): live `1`/`1` at `:92`/`:95`, and while either is non-zero `bMenuless` cannot leave dry-run. Also `bSkipOnInteraction = 0` (live: 0, `:104`), `bDBVOIntegration = 0` (live: 1, `:107`); keep `iDialogueSortMode = 0` (live: 0, `:58`); **never change `iPapyrusHandle` (live 3, `:116`)**; optionally `[DialoguePause] bEnabled = 0` (live: 1, `:121`) | the only way to stop a stray **mouse or keyboard** press from skipping a quest line — G1 cannot, because Smart Talk writes `bAllowProgress = true` natively before the `bFadedIn` test; nobody but the glue should ever release a click; `iPapyrusHandle` is what forces ≥ 500 ms before a fragment-bearing line can be skipped |
| **Helmet Toggle 2** | `HT_EnableDialogue` **off** while menuless is on | it re-evaluates the player's helmet on every Dialogue Menu **open** and again on **close**, gated by its own MCM global (`HT_PlayerAlias.psc:86-105`, registration at `:55`, `:302-309`). With one real session per business turn the player's helmet visibly pops off and back on **every single turn of every conversation** — a constant, unexplained artefact of menuless mode (CMP-m7). Listed in T12's hazards |
| **IACC** (MCM / `AlternateConversationCamera.ini`) | **Decision D2**: either `bForceFirstPerson = 0` and `bHideDialogueMenu = 0` permanently, or "Disable in First + Third Person" | otherwise every hidden session snaps to first person, locks the camera on the NPC and zooms — and the per-session toggle is the dangerous alternative (**D-11**) |
| **Fuz Ro D-oh** | `WordsPerSecondSilence` 3–4 (live: 2) | unvoiced lines hold the frozen player 1–10 s each (readme: "Duration = (Word Count / Words per Second) + 1, at a maximum of 10 seconds") |
| **Follower Stats** | Display = Never (the hotkey still works) | the panel plus a cloak spell on every session |
| **Subtitles** | Dialogue subtitles ON (live: `skyrimprefs.ini:128`) — **readability only, no longer a correctness dependency** | unvoiced lines are only readable through `SubtitleText`, and `ev=line` ground truth is richer with them on. But after **R7** the driver verifies a click from five subtitle-free signals (`EntryCount()` collapse, `ProgressTimerId()`, `isActorTalking` 0→1, menu closed, another menu opened) and the line-settle gate has a `ProgressTimerId()` fallback, so turning them off costs information, not correctness — which is what D-5 always demanded and the previous 1.5 s rule quietly broke. 429 player INFOs are genuinely silent (**BRANCH D2**) |

### 5.4 Optional data-only override file — never a plugin edit

`server/lorerim_glue/config/lrg_dialogue_overrides.default.json` (shipped, small) plus an optional user file `lrg_dialogue_overrides.json` (whole sections replace, exactly like `lrg_config.json`). Globs use Phase 1's `lrgGlob` [V\* `lrg_core.php:333`]; first match wins; evaluated **after** the index.

```json
{ "version": 1,
  "npcs":     [ { "match": { "name": "Delphine*", "ref": "0x00013478" },
                  "mode": "vanilla | assisted | menuless", "note": "" } ],
  "quests":   [ { "match": { "quest": "MQ302*" }, "mode": "assisted",
                  "confirm": "always | never | auto" } ],
  "entries":  [ { "match": { "text": "I submit. Take me to jail*", "quest": "DGCrime*", "plugin": "*" },
                  "class": "plain|service|check|pay|commit|meta|silent|back|hidden",
                  "kind": "persuade|intimidate|bribe", "label": "[go to jail]",
                  "never_auto": true, "critical": false } ],
  "services": [ { "match": { "text": "I'd like to rent a room*" }, "hide_chim_actions": ["RentRoom"] } ],
  "critical": [ { "match": { "twat": "DGCrimeResistArrest" } } ] }
```

`mode=vanilla` = the glue never hides that NPC's menu and offers no action for her. `mode=assisted` = lists are read and the LLM is aware, but the menu stays visible and the player clicks.

---

## 6. Coexistence with Phase 1 in ONE conversation (quest talk → romance → back)

1. **One conversation, two orthogonal turn records.** Phase 1's `$GLOBALS['LRG_TURN']` (mode `silent|closed|public|private|follow|scene`) is untouched. Phase 2 adds `$GLOBALS['LRG_DLG_TURN'] = {npc, cid, on, list: root|pending|lost|none, offer{}, parked, crit, mute, result_block}`. Both are built in the same `functions.php` hook call and both blocks may be injected into one prompt (each is short). The post-gates run in registration order: **Phase 1 first** (it must pass `ExtCmdLRG_SelectTopic` through untouched — integrator edit **D-12**), Phase 2 second.
2. **Shared snapshot.** Phase 2 reads Phase 1's `lrg_npc_state` (adult, combat, `scene`, `ostim`, `pgold`, `loc`) and adds its own facts through `lrg_dlg ev=facts`. **No key is added to `lrg_npcstate` in this round.** Freshness is Phase 1's rule (`snapshot_max_age_seconds` 90). NPCs stay keyed by display name on the server; execution is bound by `ref`.
3. **Shared gates (server) — computed from Phase 2's OWN state, never from `$GLOBALS['LRG_TURN']`** (CMP-M7). Under SHARMAT, `functions.php` takes the `if` branch, so `lrgPrepareTurn()` is never called, `$GLOBALS['LRG_TURN']` is never built and Phase 1's post-gate is never registered [V\* `glue/server/lorerim_glue/functions.php:9-24`, re-opened]. Expressing the gates in Phase 1's terms would make them silently evaluate to "not blocked" exactly where `ext/aiagent_nsfw` is running its own scenes. So: kill switch or `bEnabled` off → both silent. **Scene / OStim / adult state comes from `lrg_npc_state` and Phase 2's own `ev=facts`** — `scene=1` (which also carries D-17's engine-scene flag) or `ostim=1` → `ExtCmdLRG_SelectTopic` is not offered and no `<business>` block is shown, whether or not `LRG_TURN` exists. `session.state ∈ {open, pending}` for this NPC → `ExtCmdLRG_StartIntimacy`, `ExtCmdLRG_Clothing` and `ExtCmdLRG_Invite` are hidden for the turn and the intimacy guidance gets the plain reason "in the middle of a matter". `lrg_initiative` and `lrg_scenetalk` ticks never carry the business action, and **no initiative tick is admitted while a session is open, a layer is pending, or a layer is LOST**.
4. **Shared gates (game) — likewise independent of Phase 1's turn.** The game-side rule is **"`LRG_OStim.IsSceneActiveOrStarting()` OR any OStim thread on this NPC, checked through OStim's own API"**, never through Phase 1's turn record. `LRG_OStim.StartBlockedReason` and the outside-scene clothing path refuse with `a conversation is in progress` while `LRG_Dialogue.IsSessionOpen()` (integrator edit). Phase 1's initiative tick already skips while the Dialogue Menu is open — the statement is `if Utility.IsInMenuMode() || UI.IsTextInputEnabled() || UI.IsMenuOpen("Dialogue Menu")` in the initiative tick of `LRG_Main.psc` (**line hint `:608`; anchor on the statement text, §7.2**) — which now means it skips for the whole hidden session, by design (F14).
5. **SHARMAT.** If `ext/aiagent_nsfw` is installed, `LRG_SHARMAT_PRESENT` is defined [V\* `globals.php:11-13`] and Phase 1's intimacy module goes idle. **The dialogue module keeps working, and its gates keep working** because of item 3: its hook calls sit outside the SHARMAT branch, D-12's pass-through is placed before Phase 1's SHARMAT check, and nothing it needs is read out of `LRG_TURN`. Flow test **33** asserts the scene gate **with `LRG_SHARMAT_PRESENT` defined**.
6. **Quest-critical NPCs.** An NPC who is in a quest Scene, has a pending or LOST layer, is in a critical session, or whose last `ev=result` is ≤ 10 s old, is never offered intimacy actions. An OStim scene never runs while a dialogue session is open and vice versa — so a scene can never cut a line whose End fragment sets a stage.
7. **Romance options inside dialogue trees** (`(start romance)`, `(end romance)`, `[family]` in follower mods) are commit-class and never auto-selected. They belong to that mod's own state machine and are independent of the glue's consent model.
8. **Memory.** Business exchanges enter CHIM's event stream as the *real* lines — through CHIM's own capture, or through the glue's `logEvent` fallback in CHIM's `chat` shape `(Context location: L)Name: line` when no `(Context` row arrived within 3 s of an `ev=line`. Intimacy keeps Phase 1's two neutral lines. **Nothing explicit ever appears in a business block, and no business detail ever appears in an intimacy block.**
9. **Information for the Phase 1 team (their file, not mine):** `LRG_Main.psc:619` and `LRG_OStim.psc:2055/2097` use `requestMessageForActor`, which is eligibility-gated and runs `InterruptNPC` on the target (**CHIM W.3-1**). That is why an initiative tick can vanish with no trace for a sleeping, fighting or scene-bound NPC.

---

## 7. File plan, ownership, and tests

### 7.1 New files — no owner edits anything outside this table

| Owner | Files |
|---|---|
| **GAME-UI** | `game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc`, `LRG_DlgProbe.psc` |
| **GAME-DRIVER** | `game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc`. **Binding public surface:** `Function Maintenance()` · `bool Function CmdSelectTopic(string asNpcName, string asCommand, string asParam)` · `bool Function HandleEmergencyKey()` (true = a session was open and was handled) · `Function HandleLeaveKey()` · `Function DumpTopics()` · `bool Function IsSessionOpen()` · `Actor Function SessionSpeaker()` |
| **DIALOGUE** (server) | `server/lorerim_glue/lib/lrg_dialogue.php`, `migrations/003_lrg_dialogue.sql`, `config/lrg_dialogue.default.json` (its **own** config file via `lrgDlgConfig()`; the shared `lrg_config.default.json` is not touched), `config/lrg_dialogue_overrides.default.json`. **Test-facing API — FLOWTESTS uses nothing else:** `lrgDlgHandleGameMessage(array $gameRequest): string` (`handled\|pass`; echoes D1 itself) · `lrgDlgPrepareTurn(): void` · `lrgDlgPrerequest(): void` · `lrgDlgJsonTemplate(): void` · `lrgDlgStaticGuidance(array $t): string` · `lrgDlgVolatileGuidance(array $t): string` · `lrgDlgTransformer(string $s): string` · `lrgDlgPostProcessActions(array $lines): array` · `lrgDlgFilterChat(array &$gameRequest): void` · `lrgDlgGet(string $npc): array` / `lrgDlgSet(string $npc, array $patch): void` · `lrgDlgQueue(string $npc, string $param): void` (the D2 insert, isolated so tests can capture it) · `lrgDlgEnsureActions(): void`. Every time read goes through `lrgNow()`; every DB access through the `$GLOBALS['db']` wrappers |
| **INDEX** | `tools/build_prompt_index.py`, `server/lorerim_glue/lib/lrg_prompt_index.php`, `migrations/004_lrg_prompt_index.sql`, `tools/test_prompt_index.php` |
| **FLOWTESTS** | `tools/flows/dlg_adapter.php`, `tools/flows/scenarios/20_*.php` … `36_*.php` (new files only; the Phase 1 harness is used, never edited) |

### 7.2 Edits to shared files — RESERVED FOR THE INTEGRATOR, listed exhaustively

**ANCHOR RULE (R-anchors / ENG-O16 + CMP-M8).** Every edit below is expressed as **"the statement that reads `<verbatim code>` inside function `<name>`"**. Line numbers are **hints only**, and several have already drifted while this file was being written: `LRG_Main.psc` is now **751** lines and its initiative-tick statement, cited as `:602` in the previous draft, is at **`:608`**; `lrg_actions.php` was modified one minute after that draft and its three cited lines are all wrong — the `ExtCmdLRG_` pass-through anchor is at **`:539`** (was `:491`), the turn/SHARMAT drop at **`:543`** (was `:495`) and `lrgRecordResult` at **`:250`** (was `:235`) [all V\*, re-opened this round]. An integrator who applies a line-numbered patch after the Phase 1 team's next commit edits the wrong statement, **silently** — and for the D-12 pass-through that silently re-enables the very drop D-12 exists to prevent.

**Every edit carries a verification grep**, and **`tools/deploy_server.ps1` gains a pre-flight that fails, naming the anchor, when an anchor is missing or matches more than once.**

1. **`tools/make_esp.py`** — the script list (1.1). Anchor: the statement containing **`quest(main_q, 'LRG_MainQuest'`** (hint `:44`); add `'LRG_Dialogue', 'LRG_DlgProbe'` to its script-name list. Verify: `grep -c "LRG_DlgProbe" tools/make_esp.py` == 1. **`tools/compile.ps1`** — no new import is needed: SKSE, PO3 and CHIM sources are already imported (`:52-56`) [V\*]. Add an `IACC` declaration stub under `tools/stubs/` **only** if decision D2 is "per-session toggle".
2. **`LRG_Main.psc`** — five edits, each anchored on its statement:
   - add `LRG_Dialogue Function GetDialogue()` (`(self as Quest) as LRG_Dialogue`), mirroring the body of `GetOStim()`;
   - in `Maintenance()`, **immediately after the statement that calls `ost.Maintenance()`**, call `GetDialogue().Maintenance()` and the probe's;
   - in `HandleCommand`, add a branch `ExtCmdLRG_SelectTopic -> dlg.CmdSelectTopic(...)` (the existing 5 s `cmdKey` de-dup already swallows the second delivery route when it arrives quickly, and `LRG_Dialogue`'s `x` ring catches one that arrives later);
   - in `OnKeyDown`, route `keyVanillaMenu -> if !dlg || !dlg.HandleEmergencyKey() -> OpenVanillaDialogue()`, replace the `keyDumpTopics` placeholder body with `dlg.DumpTopics()`, and add `keyLeave`;
   - **D-13, narrowed (see D-13 / §1.10).** Anchor: the statement that reads **`if Utility.IsInMenuMode() || UI.IsTextInputEnabled()`** inside `OnKeyDown` (hint `:184`). Split it so that `UI.IsTextInputEnabled()` still returns early **unconditionally**, and only the `Utility.IsInMenuMode()` half is bypassed, and only for `keyVanillaMenu`/`keyLeave` while `dlg.IsSessionOpen()`. **Do not** touch the visually identical statement in the initiative tick (hint `:608`) — it reads `if Utility.IsInMenuMode() || UI.IsTextInputEnabled() || UI.IsMenuOpen("Dialogue Menu")` and must keep its behaviour (§6.4). Verify: `grep -n "IsInMenuMode" LRG_Main.psc` must show exactly the two statements, one edited and one not.
   - Bump `CurrentVersion` to 300. Anchor: the property declaration containing **`CurrentVersion = 200`** (hint `:11`).
3. **`LRG_OStim.psc`** — `StartBlockedReason` and the outside-scene clothing gate answer `a conversation is in progress` while `dlg.IsSessionOpen()`.
4. **`MCM/Config/LoreRimGlue/config.json` + `settings.ini`** — the page and keys of 1.11 (a new `"pageDisplayName": "Menuless questing"` entry plus a `[Dialogue]` section and two `[Keys]` entries).
5. **Server hooks — one call each:**
   - `globals.php` — add `'lrg_topics', 'lrg_dlg'` to the **`external_fast_commands`** merge (anchor on that identifier, hint `:8`).
   - `preprocessing.php` — **insert before** the block whose condition contains **`strncmp($lrgType, 'lrg_', 4) === 0`** (hint `:14`):
     ```php
     if ($lrgType === 'lrg_topics' || $lrgType === 'lrg_dlg' || $lrgType === 'lrg_dlgtalk') {
         require_once __DIR__ . '/lib/lrg_dialogue.php';
         if (lrgDlgHandleGameMessage($gameRequest) === 'handled') { terminate(); }
     }
     ```
     This keeps the dialogue module independent of Phase 1 and alive under SHARMAT. Also route `chat` rows to `lrgDlgFilterChat` (4.6).
   - `functions.php` — **after the closing brace of the whole `if (defined('LRG_SHARMAT_PRESENT')) { … } else { … }` block** (hint `:9-24`), and therefore on **both** branches: `lrgDlgPrepareTurn();`, register the Phase 2 post-gate **after** Phase 1's in `action_post_process_fnct_ex`, and register the `JSON_TEMPLATE` hook. Verify: the inserted statements are at brace depth 0 of the file, not inside either branch — that is what keeps §6.3/6.5 true under SHARMAT.
   - `prerequest.php` — add `lrgDlgPrerequest();` after the existing `lrgPrerequest();` statement (hint `:13`).
   - `prompts.php` — `$GLOBALS['PROMPTS']['lrg_dlgtalk']` with the cue "answer the player's last words yourself in one or two short sentences, or take up one of the listed matters".
   - `context_pre.php` — inject the three blocks with `chimRegisterPromptInjection` (same pattern as `:13-17`) and set the chained transformer.
   - **`lib/lrg_actions.php` — the D-12 pass-through.** Anchor: **immediately after the line containing `stripos($code, 'ExtCmdLRG_') !== 0`** (hint `:539`), insert:
     ```php
     if (strcasecmp($code, 'ExtCmdLRG_SelectTopic') === 0) { $out[] = $line; continue; } // Phase 2 gate handles it
     ```
     It must land **before** the line containing **`defined('LRG_SHARMAT_PRESENT')`** inside the same loop (hint `:543`), which drops every `ExtCmdLRG_*` line when `LRG_TURN` is unset, the actor differs, or SHARMAT is present (**D-12**). Without it the line dies as "unknown glue action" (the `lrgLog("gate: dropped unknown glue action …")` statement, hint `:615`). Verify: the inserted line's index is greater than the `stripos` anchor's and **less than** the `LRG_SHARMAT_PRESENT` anchor's.
   - **`lib/lrg_actions.php` — `lrgRecordResult`.** Anchor: the statement that reads **`if (stripos($raw, 'command@ExtCmdLRG_') !== 0 || !lrgEnabled()) { return; }`** inside `function lrgRecordResult` (hint `:253`). Extend it to also ignore `command@ExtCmdLRG_SelectTopic`, so Phase 1 does not store the dialogue result in `lrg_memory.last_result` and tell the NPC about it a second time (Phase 2 composes `<what_just_happened>` itself).
   - `lib/lrg_core.php` — constants `LRG_ACT_TOPIC`, `LRG_SCHEMA_VERSION` 3, `LRG_VERSION` `0.3.0`; `manifest.json` 0.3.0.
6. **`tools/deploy_server.ps1`** — build and load the prompt index after copying, **and run the anchor pre-flight**: for every anchor string named in this section, fail the deploy naming the anchor when it is missing or matches more than once. **`PROTOCOL.md`** — merge §3 of this file as v3. `README.md` and `install_mo2.ps1` are unchanged.

### 7.3 Offline flow tests (no CHIM, no LLM, no game)

Phase 1's harness pattern is reused verbatim (PROTOCOL §7.3): a fake `$GLOBALS['db']`, `LRG_TEST_NOW`, a fixture index through `LRG_TEST_INDEX`.

| # | Scenario |
|---|---|
| 20 | Wire: `lrg_topics` parsing including `;` and `=` inside texts, the entry cap, `e` last, `~~`/`~` splitting |
| 20b | Payload: 24 entries of 140 chars → the head is capped at 1,600 raw bytes, the remainder arrives as `part=2`, `n` is the full count in both, and the server re-assembles head ∪ tail before ranking (R12, CMP-m6) |
| 21 | First contact: no list → no block; an intent item → `do=open;ask=…` **only when `bIntentOpen=1` and a business marker was named** (otherwise `lrg_dlgtalk`, and `opens_without_match` does not move); a `want=1` reply picks the matching `plain` entry on **both** delivery routes with the same `x`, and the game executes it once |
| 21b | **Result duty** (D-20): two deliveries of the same `x` produce **exactly one** `commandEndedForActor`; `CmdSelectTopic` returns synchronously and emits a `funcret` only for a §1.5 refusal; a `not enough gold` outcome is reported by the loop, not by `CmdSelectTopic` |
| 22 | Intent match lands on a `check` entry → **not** executed; the list is cached; `lrg_dlgtalk` is admitted |
| 23 | Cached root → keys; `T3` → `do=pick;sid=0;txt=…`; a key from an older turn → dropped `stale` |
| 24 | Staleness: a `qsig` change, a location change and age each invalidate the cache |
| 25 | Ranking: a closed layer sends all entries, cap 12, never bucketed; a root list sends top 8 + the more-line; filler quests rank down; quest-coloured rows rank up when `bQuestColour` is on; **ranking runs over head ∪ tail, and while `sent < n` the "nothing like that" wordings are forbidden** (R12) |
| 26 | Commit: the first selection parks and is **not** muted; a second from a new request executes; another key clears the park; 60 s expiry; a single-token voice utterance is refused |
| 27 | Persuade: the label comes from the INDEX kind although the text says `(Intimidate)`; the min-words rule; a failure result makes `<what_just_happened>` say FAILED and quote the line; retry is suppressed until `sp` changes; suppression is OFF for `compound` |
| 28 | Bribe: no amount → the price is told, nothing executed; agreed → executed with `cost`; `pg < N` → never; `A < N` → never; gold actions hidden on that turn |
| 29 | Services: a real barter/rent entry hides `OpenInventory` / `RentRoom` by code_name; with no list CHIM's actions stay; the rent hub then the duration layer. **Extended (D-18): hub + "three nights" in ONE utterance completes in ONE session under same-session continuation and moves gold exactly once; the same utterance against a `check`-class sub-layer executes nothing; score 0.68 or margin 0.20 executes nothing; a second continuation in the same session is refused (depth 1)** |
| 30 | Critical: a **LETHAL** `twat` entry → `do=show`, never `leave`, never a close; a **COSTLY** `twat` entry stays menuless but is never auto-closed, never timed out and its back-out entry is labelled `[leaving now ends this]`; `fDecideTimeout` never closes either; `ForgiveCrime` hidden (R8) |
| 30b | **Scene** (R9 / D-17): speaker in a scene → `do=show`, **no PENDING, no `lrg_dlgtalk`, no `<business>` block, `TakeUpBusiness` not offered** — with and without `LRG_SHARMAT_PRESENT` defined |
| 31 | LOST layer: `closed why=external pending=1` → keys still offered; the pick carries the dead `sid`; after two losses the NPC switches to assisted. **With `bRewalk=1`: a `path[]` step with `result_clean=false`, or a Say-Once / Goodbye / `twat` step, or a changed layer signature aborts the walk to assisted; `rewalk.max_depth` 2 is enforced; the counters move** (D-19) |
| 32 | Mute: the transformer returns `''` only when the pick is emitted; the schema order puts `action` before `message`; **`item`'s and `target`'s descriptions carry the appended TakeUpBusiness clauses** (CMP-m1); chaining preserves an existing transformer; a non-empty `message` on an emitted turn is tolerated and its TTS skipped |
| 33 | Coexistence: `scene`/`ostim` read from **`lrg_npc_state` + `ev=facts`, not from `LRG_TURN`** → no business action and no block; an open session → no intimacy actions; **SHARMAT → dialogue still works AND the scene gate still fires with `LRG_SHARMAT_PRESENT` defined** (CMP-M7); Phase 1's gate passes the line through; Phase 2's gate drops a hallucinated one |
| 34 | Speaker binding: a reply from another `HERIKA_NAME` (the Narrator) → nothing offered, nothing executed |
| 35 | Quest engine ON → the module stands down with one notice |
| 36 | Freeze rule: a gold change between list-read and click forces a re-read instead of a click |
| — | `tools/test_prompt_index.php`: MG03 Caller's-book success/failure prompts map to variants; MS11 Jorleif's accusation is scripted + Goodbye; DGCrime entries carry `twat`; the Xtended Stay hub has no price tag but **does** resolve a DNAM response; the 7 duration entries resolve to a blank response; token patterns match "How about Riften?"; the four PNAM-inverted orc topics are flagged |

### 7.4 In-game test plan

Always in this order: **probe → dry-run → live.** Every line carries `cid`, and the same id appears in the game log and the server log.

| # | Test | Pass |
|---|---|---|
| **T0** | **X1 FIRST** (design gate, §0.3 / 2.6), then X0, X3 ×2, X2, X4 — no glue code at all | results written into the playtest notes. X1 decides whether the bounded re-walk (D-19) has to be built at all, and therefore what "branching choices are handled appropriately" will mean; the rest set the voice keeper and the ground-truth source |
| **T1** | Probe P0–P17 on the matrix of 1.12 | the pass criteria of 1.12; `iReadMode`, route and delivery route decided |
| **T2** | Topic-dump parity on 10 NPCs (innkeeper, Jarl, follower, merchant, guard, a Forgotten City NPC, a Missives board giver…) | the dump equals the visible menu entry for entry; `unindexed` < 5 % |
| **T3** | Dry-run conversation in Whiterun: Carlotta Valentia's favour (talking Mikael round — a small quest whose layer offers speech-check options) | the log shows the right `WOULD CLICK` for the words spoken; nothing is clicked |
| **T4** | **Simple Whiterun quest, live, menu never shown** (Adrianne Avenicci's sword delivery, or the Carlotta favour) | stages and journal match a vanilla run (compare `sqs <quest>` per step) |
| **T5** | **Branching quest**: In My Time of Need (questions at medium confidence; the two commits need naming plus confirmation; "kill" by STT gets the read-back). Plus the Blood on the Ice accusation **in dry-run** (a single scripted entry must WAIT) | the branch taken is the branch spoken; no auto-commit |
| **T6** | **Persuade** with Speech below and above the live threshold (MG03 Caller's books, or Idolaf); a **bribe** with gold just below and just above the price (E15); an **intimidation** that cannot work (E18); and Ghorbash or Borgakh for the PNAM question (E21) | the engine decides; `<what_just_happened>` matches the real line; the NPC never announces an outcome early; Beneficial Speech Checks pays out at session close |
| **T7** | **LoreRim quest mod**: one Missives / Quest Expansion job and one Forgotten City layer with a back-out entry | alias-token prompts match; the back-out entry is used for "I need more time" |
| **T8** | **Romance ↔ quest talk in one conversation** (Serana or a follower): business → flirt → business; then start a scene with an idle quest NPC | no intimacy action while a session is open or pending; no session during a scene; the quest stage is unchanged after the scene |
| **T9** | **Emergency hotkey** in every state (LISTENING, PENDING, RESPONDING, SUSPENDED) and with no session at all | a working, visible, clickable vanilla menu every time; nothing clicked twice |
| **T10** | Engine-opened sessions: a courier, a ForceGreet (Thalmor patrol or MQ), a guard arrest with `iCritical=0` | the courier runs by itself; the ForceGreet is answered by voice; the arrest is handed back visible with no accidental resist |
| **T11** | Services: rent a room (hub + duration at the LoreRim price), a carriage (CFTO), barter, training under Requiem's caps | real prices, real menus, gold moves exactly once |
| **T12** | Hazards: click / E / Space / wheel **and a HELD mouse button** during a hidden response; Rite of Arcana's ghost state; the Respec dialogue; 120 s idle in PENDING; a CHIM follower speaking during a session; **an engine push (courier ForceGreet) while a layer is parked**; **Helmet Toggle's `HT_EnableDialogue` left ON, to see the helmet pop** (§5.3); **the emergency key after a full clean session, then a plain vanilla E-press on a shopkeeper — must be clickable by mouse, E and Enter** (R1) | nothing skipped or clicked; `refused`; a clean hand-off with a working cursor; clean loss handling; the pushed list arrives after unparking; vanilla dialogue is never left unclickable |
| **T13** | **Drift check** (C2): 20 consecutive business turns with a married NPC while a Speech-scaling trait is active; read the five globals before and after | `drift` is reported; the total drift is no worse than one open per turn would give a human player |

---

## 8. What a later SKSE DLL would add, and the seam

The Papyrus route is deliberately built so that a DLL is an *upgrade*, never a rewrite. Every UI operation already funnels through `LRG_DlgUI`, whose functions each begin with a version test and route to a native twin with the **same signature**:

```papyrus
if SKSE.GetPluginVersion("LoreRimGlueNative") >= 0
    return LRGNative.EntryText(aiPos)
endif
```

| Gain | Today's workaround | Seam |
|---|---|---|
| FormID identity of every entry (`Dialogue::parentTopic`, `parentTopicInfo`, `parentQuest`, `neverSaid` — all present in `MenuTopicManager.h`, **BRANCH B1**) | text → index (93.1 % of prompts unique, 99.1 % of layers) | optional entry fields `tid, iid, qid` in `lrg_topics`; the server already prefers ids over text when present |
| One-call list read; an event-driven "list changed" | N frame-synced `Get`s and a 0.1 s poll | `LRG_DlgUI.EntryText` / `EntryCount` native twins |
| Exact INFO tracking (`TESTopicInfoEvent` begin/end with FormIDs) | subtitle capture + stat/gold deltas + line matching | `lrg_dlg ev=info;iid=…;phase=begin\|end` feeds the same result composer |
| First-frame hiding and real cursor suppression | a 1–2 frame flash of the exit button and speaker name | `LRG_DlgUI.Hide()` native twin driven from a `MenuOpenCloseEvent` sink |
| No-session awareness (a read-only walker over running quests' top-level branches, with quest context) | harvest from sessions that happen anyway; the E-press "hail" | `lrg_topics origin=walker`, marked approximate, never executed without a live list |
| A native click through the engine's own node, with the stale check on the main thread | `SelectAndVerify` | `LRG_DlgUI.Click()` native twin |
| **Session shield** — keep a pending choice layer alive while CHIM runs `EndDialogue` (if X1 = "dies"), or re-open silently with no greeting | assisted mode for deep branches | nothing changes in the protocol; `iBranchInput` simply stays on `auto` |

**The middle step that is still "no DLL"**: a tiny helper SWF injected at menu open (`createEmptyMovieClip` + `loadMovie`) that returns the whole list as one string and pushes "list ready" as a mod event. The pattern ships in this very mod list — `RequiemLite_Config.psc:28-29` and `SFE_SubtitlesScript.psc:28-36`, the latter injecting into this exact menu (though that mod is disabled) [V REQ R2.1-15, PRIOR R-A5]. It needs an AS2 build tool → **decision D1**, and it plugs into `LRG_DlgUI.EntryText` / `EntryCount` without the driver noticing. Given **PRIOR R-C2** (numeric GFx paths are proven from Papyrus in a published mod), this has dropped to **plan C**: do not put it, or the DLL question, to the owner until probe P3 has actually failed.

---

## 9. Decisions the owner must make

| id | Decision |
|---|---|
| **D0 — NEW.** | CHIM's `dialoguemenu.swf` is an *optional* upstream component (`AIAgent/Optional/TraditionalDialoguePlayerTTS/`), installed here in the base mod folder. With Player-TTS-for-traditional-dialogue off (it is), **hiding that one file in MO2 removes the click gate, the whole double-release class of bugs and the DBVO interaction with Smart Talk in one step** — and Norden UI's SWF would then win. It also means a CHIM update could silently swap the winning SWF either way, which is why the driver supports both families regardless. Hide it, or keep it? Nothing has been touched. |
| **D1** | If the probe shows no Papyrus read mode works, or reads are too slow: allow a helper SWF (needs an AS2 compiler such as MTASC or FFDec — no C++), or move to the SKSE DLL question. Nothing gets installed without asking. **Expected not to be needed** (PRIOR R-C2). |
| **D2** | **IACC**: soften it permanently in its MCM/ini (recommended: `bForceFirstPerson = 0`, `bHideDialogueMenu = 0`), or let the glue toggle `IACC.SetDisableInFirstPerson/ThirdPerson` around each session. The setters write `AlternateConversationCamera.ini` on every call, and toggling them **inside** a session strands IACC with the camera never restored (**D-11**), so the permanent setting is strongly preferred. |
| **D3** | **JSON order**: may the glue put `action` before `message` on player-speech turns? It is needed for the mute; it changes CHIM's output order, not its content. |
| **D4** | **CHIM shortcuts**: hide `ForgiveCrime` for good while menuless is on, and hide `RentRoom / HireCarriage / HireFerry / Brawl / Training / OpenInventory / OpenInventory2 / GiveGoldTo` whenever the real entry is known? (Recommended yes to both — it changes CHIM behaviour outside quest talk.) `ScriptProxy` needs only a health check, not a change (**REQ R2-C2**). |
| **D5** | **Taste**: does bribe haggling name the price or only say "more"? Scoff-first on doomed intimidations (default on)? Grace delay for unscripted single entries (2.5 s)? Silence timeout before the menu is handed back (45 s)? **`iEngineOpen`** — do ForceGreets, couriers, blocking greetings **and the player's own E-press** (which Papyrus cannot tell apart, D-15) stay a visible assisted menu, or become menuless? `iCritical` — are LETHAL sessions handed back visible (default) or driven by voice once X1/E-R7 pass? **`iSceneGate`** — never open on a scene actor (default), or open unless the player is in the scene or a journal quest owns it? |
| **D6** | **Index coverage**: accept engine-only handling for the 82 localized Creation Club plugins (22 speech topics with no indexed text), or have the index builder parse their LZ4 BSAs (needs an LZ4 module inside WSL)? |
| **D7** | **After X1** (now backed by numbers, 2.6): if sessions die when the player speaks, only **14 %** of lost choice layers can be recovered by re-opening and re-matching, so "assisted" means **the vanilla menu on ~86 % of branching moments**. Accept that until a DLL exists, or approve the **bounded re-walk of D-19** — off by default, a replay of steps this very session already walked, aborting to assisted on any mismatch, `max_depth` 2? It replays earlier NPC lines, which is the cost. |
| **D8 — NEW.** | **Report or ignore two upstream data bugs found on the way.** (a) Little Lessons' `+10` restore is skipped whenever `SpeechVeryHard >= 100`, which any LoreRim Speech-scaling trait guarantees — every Dialogue Menu open with a married NPC permanently lowers all five Speech globals, today, with or without the glue (**REQ R2-A1**). (b) In four orc-follower check topics the success INFO is sorted after an unconditional failure INFO by PNAM, which may make those checks unpassable in this load order (**BRANCH B6**). Both are LoreRim/mod data, not glue; the glue changes nothing either way. |

---

---

## 10. Rejected objections

Everything else in the two critiques is fixed above. These five clauses are **not** adopted, each for one reason. Nothing here weakens the objection it came from — in every case the objection's *hazard* is accepted and fixed elsewhere; only the prescribed mechanism is replaced.

| # | Objection clause | Rejected because |
|---|---|---|
| **1** | ENG-O1 change (1) "add `LRG_DlgUI.Guard(false)` to … **'IDLE (on close)'**" and change (3) "same one-line repair in **`LRG_Main.Maintenance()`**" | **Neither location can reach the menu.** `UI.Set*` writes into a menu's own GFx movie, and both `OnMenuClose` and `Maintenance()` run when the Dialogue Menu is gone, so the writes land nowhere. The hazard is real and is fixed by the two placements that *do* work: `Guard(false)` in **CLOSING** and in `Unhide()` (menu still open), and `GuardReset()` at the **top of every ARMING** (R1, G0), which is also the only thing that can repair a leak left by an *external* close. `LRG_Main` is additionally the wrong owner: §1.2 rule "every `UI.*` call lives in `LRG_DlgUI`" and §7.1 forbid the Phase 1 team's file gaining UI logic |
| **2** | CMP-C2 "Change AND to OR: `IsGuard()` **OR** `GetCrimeFaction().GetCrimeGold() > 0` OR …" | **A flat OR makes most of the mid-game critical.** `GetCrimeGold() > 0` without `IsGuard()` fires on *every* conversation while the player carries a LoreRim attack bounty — Belethor included — and the feature would be off exactly when the player is most likely to use it. The two shapes the clause exists to catch are kept, precisely: `IsGuard() AND (origin == engine OR crime gold > 0)` covers both the bag-check ForceGreet with **no** bounty and the glue-opened session on a guard **with** one (2.7). The rest of CMP-C2 — apply the test to every session, never close on `fDecideTimeout`, `CloseForce` probe-only — is adopted in full |
| **3** | CMP-C2 "OR **the server has not answered yet**" as a crit condition | **That is a timeout policy, not a property of the session.** Treating "no answer yet" as critical would make every first-contact session LETHAL and hand the menu back before the server had a chance to reply. The underlying worry — a session closed while its nature is still unknown — is removed outright by the adopted rule that **`fDecideTimeout` never closes anything**; its only outcomes are PENDING and MANUAL |
| **4** | ENG-O15 "Set `iMaxEntries` from `iMaxItemsShown` when the read mode is **row-based**" | **Superseded by R5.** Mode 5 is now read-only, so no click path is row-based and there is no read mode for which this rule could fire. `iMaxEntries` is set by P3t's measured per-call cost, and the full count `n` is sent regardless (R12). The rest of ENG-O15 — validate the stored `_x`/`_y`, record `fam` with them, fall back to `ShowList()`, per-family floors, the P6f family-2 run before D0 — is adopted |
| **5** | CMP-C3 "a path entry may be re-clicked **only when `scripted = false`** AND not Say-Once AND not Goodbye AND no `twat`" (as the complete gate) | **An index-only gate breaks §4.2's invariant** that "a wrong index can never cause a wrong effect". The index is advisory and built offline from 77,452 new INFOs; if it wrongly calls an entry unscripted, the re-walk runs a fragment the player never chose — a new failure mode the design does not otherwise have. Adopted **with a runtime gate added**: a step is re-clickable only when it was clicked **in this same session** and its own `ev=result` showed no stat delta, no gold movement, no flag flip, no new `questlog` row and no menu close (`path[].result_clean`, §3.3), *in addition to* every index condition the objection lists (D-19, 2.6). This makes the re-walk a replay of observed-harmless steps rather than an index guess |

---

## 11. Build order

**Nothing is built until `X1` has an answer** (§0.3), and **`LRG_DlgProbe` ships before `LRG_Dialogue`**. The probe is not a phase of the build — it is step 1 of it.

### Step 0 — the first-playtest PROBE (owner + GAME-UI, one evening)

The probe is the whole of §1.12 and it answers every `[U]` this design rests on. Build order inside it, because later presses depend on earlier ones:

| Order | Probe | Why it is first / what it unblocks |
|---|---|---|
| 1 | **X1** (no code) | design gate: does a session survive the player speaking? Decides D-19, D7 and `iBranchInput` — and therefore whether branching is a voice feature or an assisted one (2.6) |
| 2 | **P0, P0b** | voice-keeper target, `bActivateDefaultOnly`, and the `Utility.IsInMenuMode()` print that confirms D-13 is only hardening |
| 3 | **P2** | `iCountMode` (**R4**) **and `iPlatform`** (**D-2 residual window**). If no count mode works, stop — decision **D1** |
| 4 | **P3 / P4 / P5 / P3t** | `iReadMode` and `iMaxEntries`. **"Only P5 works" is a failure → D1** (**R5**); P5's `itemIndex` log is what demonstrates row ≠ position |
| 5 | **P1** | the readiness rule **and whether `iAllowProgressTimerID` moves per line or per session** — which decides the line-settle gate (**R2**) and RESPONDING signal 4 (**R7**) |
| 6 | **P6, P6f** | the hide mode, the `GetInt`-vs-`GetFloat` `_x` demonstration (**R6**), and one family-2 run **before D0 is answered** |
| 7 | **P7, P7s, P7p** | the guard; whether `GuardPoke()` can hold Smart Talk (**R11**); and whether an engine push during a park is dropped (**R2 / park window**) |
| 8 | **P10, P8, P9, P8v** | the read-back gate, then the routes, with `EntryCount()`-collapse and timer-id sampling for **R7** |
| 9 | **P11** | **the single most important press in the set for R1**: re-open by hand and read `ALLOW_PROGRESS_DELAY` before anything writes it. A red result means `GuardReset()` is the only thing standing between the owner and permanently unclickable vanilla dialogue |
| 10 | **P11b** (harmless closed layer only), **P12–P17** | E-R7, and the remaining optional signals |

**Gate out of step 0:** P1, P2, (P3 **or** P4), P6-H2, P7, P7p, (P8 **or** P9), P10, P11. Anything red goes to **D1** or to the owner, never into the driver.

### Steps 1–7

| Step | Owner | What | Gate to the next step |
|---|---|---|---|
| **1** | GAME-UI | `LRG_DlgUI.psc` — every signature of §1.2 including `GuardReset`, `GuardPoke`, `ShowList`, `ProgressTimerId`, `SmartTalkSafe`, the calibrated `EntryCount`, and hard rules 9–11 | compiles; the probe's presses now run through it unchanged |
| **2** | INDEX | `tools/build_prompt_index.py` + `lrg_prompt_index.php` (§4.2), with `twat`, `crit` grade and the `scripted` flag the re-walk depends on | `tools/test_prompt_index.php` green; `unindexed` < 5 % on T2's ten NPCs |
| **3** | DIALOGUE | `lrg_dialogue.php` + migrations: the wire of §3, the three matching modes of §4.4, the two crit grades, the scene gate of D-17, the SHARMAT-independent gates of §6.3 | flow tests 20–36 green, including **20b, 21b, 29-extended, 30b, 33** |
| **4** | GAME-DRIVER | `LRG_Dialogue.psc` — the state machine of §1.3 with `iEngineOpen = 0`, `iSceneGate = 0`, `bMenuless = 0`, `bDlgDryRun = 1`. `CmdSelectTopic` returns in one frame (**D-20**) | T2 topic-dump parity; T3 dry-run conversation shows the right `WOULD CLICK` and clicks nothing |
| **5** | INTEGRATOR | the anchored edits of §7.2 + the MCM page of §1.11 + the `deploy_server.ps1` anchor pre-flight | the pre-flight passes; `esp_dump.py` shows four script names on `0x01000800` |
| **6** | owner | **live**, in this order: T4 (simple quest, menu never shown) → T6 (persuade / bribe / intimidate) → T5 (branching) → T10, T11 → T8, T9, T12, T13 | stages and journal match a vanilla run; `drift` reported; the emergency key gives a working menu in every state |
| **7** | owner | only now: `iEngineOpen = 1`, then `iSceneGate = 1`, then `iCritical = 1` — **one at a time, each with its MCM counter read afterwards** | each knob is judged on its counter (`scene_refusals`, `opens_without_match`, `layers_*`), not on argument |

**Two hard preconditions before `bMenuless` may leave dry-run at all:** `SmartTalkSafe()` returns true (D-21), and P11 has been read.

---

### Appendix: what is still unproven, and who resolves it

| Open | Resolved by |
|---|---|
| Do numeric GFx array-index paths resolve from Papyrus on **this** menu, and does **any** count mode work? | P2 / P3 (expected yes — PRIOR R-C2; a red result is decision D1) |
| Does `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` survive a menu close? | **P11** — a red result makes `GuardReset()` (R1) load-bearing rather than defensive |
| Does `iAllowProgressTimerID` move once per line or once per session? | **P1** — it decides the line-settle gate (R2) and RESPONDING signal 4 (R7) |
| Is an engine-pushed list dropped or deferred while we hold the park at 3? | **P7p** (R2 / CMP-M2) |
| Does the hide, the stored `_x` and the un-hide work on SWF family 2? | **P6f**, run before D0 |
| Do timelines advance while the clip is off-screen, so the state still reaches 1? | P6 |
| Does the engine accept a `TopicClicked` with no click animation (route B), and do fragments and the next layer behave exactly as after a human click? | P8 (native prior art says yes — DSN drives `SetSelectedTopic` / `onSelectionClick` purely through `GFxMovieView::Invoke`, **SWF G7**) |
| Does `EndDialogue` kill an open session? | **X1** — this alone decides `iBranchInput` |
| Does CHIM's `traditional_*` capture work at all? | **X3** ×2; the driver's own `ev=line` is the fallback either way |
| Which delivery route (D1/D2) actually works, and how fast? | P14 / X5 |
| Does a script-side close of a crime session fire the resist-arrest path? | P11b on a harmless layer + E-R7 |
| Does the engine's aware-player timer close a hidden idle session? | P16 |
| Are `OnMenuOpen`/`OnMenuClose` listeners still served under the hide? | P11 (E-R2) |
| Is Smart Talk's quest colour readable here at all? | P17 (and it may simply be inert — `iQuestColor` exists in neither SWF) |
| Exact `GetBribeSuccess` semantics (does it include affordability?) | E15 — the CK wiki is HTTP 403 for every fetcher, so it must be tested in game |
