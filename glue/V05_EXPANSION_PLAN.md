# LoreRim Glue v0.5.0 — EXPANSION PLAN for menuless questing (three parallel lanes)

Owner's words that started this round: **"improve and expand upon menuless questing"** (after
`OWNER_ADDENDA` item 4, "i really want to focus on the expansion and improvements of menuless
questing now").

**State this plan starts from.** v0.4.1 shipped **inert**: `bMenuless = 0`, `bDlgDryRun = 1`, and
nothing has been tried in game. The driver, the probe, the server module and the 37,561-row prompt
index are all installed and compile/test green, but every `[U]` in `PHASE2_DESIGN.md` is still open,
and the way the owner is supposed to close them is `PLAYTEST8_NOTES.md` §3 — **a 21-row evening of
probe presses**. That evening is the single biggest obstacle between this feature and being used.
This round's headline is therefore E1: **the driver calibrates itself from ordinary play, and the
owner's evening shrinks to two presses.**

**Versions this round (binding).** `manifest.json` `version` → **0.5.0** · `LRG_Main.CurrentVersion`
→ **500** · `PROTOCOL.md` → **v0.5** · `LRG_DLG_STATE_VERSION` → **2** (readers must tolerate 1) ·
`LRG_DLG_SCHEMA_VERSION` → **2** (migration `007`) · `LRG_DLG_ACTIONS_VERSION` stays **1** ·
`LRG_PROMPT_INDEX_VERSION` stays **1** (the ndjson row shape does not change; only the Postgres
schema it is loaded into does).

**PROTOCOL v0.5 is additive only, and both directions are asserted by a test** (§14, `d50_wire05`):
a 0.5 server works with game scripts 401, and game scripts 500 work with a 0.4 server. Every addition
below carries its own "old version behaviour" line, and none of them is load-bearing in the other
half.

> ### READ THIS BEFORE STARTING A LANE — the plan is in two parts
>
> **Part I (§0–§17)** is the round as first scoped: **E1** self-calibrating driver, **E2** quest
> awareness v2, **E3** assisted-mode polish, **E4** hardening, **E5** quest-mod coverage.
>
> **Part II (§18–§26)** answers the owner's **later** message (`OWNER_ADDENDA` **item 9**,
> 2026-09-22 ~03:30) and therefore **wins wherever the two disagree**: **E6** service dialogue
> (bartering, training, inns, carriages/ferries, guards/crime — with what CHIM *already* does laid
> out in §18.1), **E7** fact-locking and condition truthfulness, **E8** latency (measured from the
> owner's own logs: §20).
>
> Part II uses the same lanes and the same ownership table. **Lane A has no Part II work at all**
> (§22) — deliberate, because `LRG_DlgUI.psc` / `LRG_DlgProbe.psc` are the riskiest files and already
> carry all of E1. Part II's wire additions continue §2's numbering at **W10** (§21.1), its MCM keys
> are in §21.2, its config in §21.3, its log lines in §21.4, its tests in §23, and **§24 is the
> single, complete version of the owner's evening** — it supersedes §15 by adding to it, and the
> press count still does not change: **two**.

---

## 0. How to read this, and the rules of a silent parallel build

### 0.1 The three lanes and the exact files each one owns

| Lane | Owns (nobody else opens these files) |
|---|---|
| **A — GAME-UI** | `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc`, `…/LRG_DlgProbe.psc` |
| **B — GAME-DRIVER + INTEGRATOR** | `…/LRG_Dialogue.psc`, `…/LRG_Main.psc`, `…/LRG_Profile.psc`, `…/LRG_MCM.psc`\*, `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json`, `…/settings.ini`, `glue/tools/make_esp.py`, `glue/tools/stubs/MCM_ConfigBase.psc`\* |
| **C — SERVER + INDEX** | everything under `glue/server/lorerim_glue/`, `glue/tools/build_prompt_index.py`, `glue/tools/test_*.php`, `glue/tools/flows/**`, `glue/tools/deploy_server.ps1`, `glue/tools/install_mo2.ps1`, `glue/PROTOCOL.md` |

\* **Ownership clarification, flagged as such.** `LRG_MCM.psc` and its compile-only stub
`tools/stubs/MCM_ConfigBase.psc` were in no lane's list in `V04_BUILD_PLAN` §0.1. They are the MCM
menu's script and its stub, so they go with lane B, which already owns `config.json` /
`settings.ini`. Nothing else moves.

`glue/tools/compile.ps1`, `glue/tools/esp_dump.py` and `glue/V05_EXPANSION_PLAN.md` (this file) are
**read-only for every lane** this round. **`make_esp.py` is not edited**: no script is added to the
ESP, `LRG_MCM` is already on `LRG_MCMQuest 0x801` and the four driver scripts are already on
`LRG_MainQuest 0x800` (`make_esp.py:49-50`). Because `make_esp.py` does not change, **`esp_dump.py`
does not need to be re-run**, and the `Seq` file is untouched.

### 0.2 The rules that make a silent parallel build safe

1. **One-way dependency A ← B.** `LRG_Dialogue` (B) calls into `LRG_DlgProbe` (A) through
   `LRG_Main.GetProbe()`. `LRG_DlgProbe` must **never** name `LRG_Dialogue` — it compiles without it
   today and must keep doing so. `LRG_DlgUI` names nothing.
2. **Every `UI.*` call and every GFx path string still lives in `LRG_DlgUI` alone.** This round adds
   **no new UI primitive**; every read and write the calibration needs already exists there (§1.3).
3. **ONE loop owns the UI.** The calibration never opens its own session, never registers a second
   `OnMenuOpen` handler that touches the menu, and never polls on its own. It is a set of functions
   the driver's existing loop calls at defined points.
4. **Additive wire only.** New keys on existing messages, one new `ev` value, one new `do` verb. Both
   sides already ignore unknown keys and unknown `ev`s (`lrg_dialogue.php:541`).
5. **Anchored edits only** in shared files; `deploy_server.ps1`'s anchor pre-flight gains the new
   anchors (§13.6).

### 0.3 The hard rails, unchanged, and the two new ones this round adds

Unchanged (a review rejects any violation):

* the driver never calls a TIF fragment, never `SetStage` / `SetObjectiveCompleted` /
  `CompleteQuest`, never `SetBribed` / `SetIntimidated` / `SetCrimeGold`, never fakes a dialogue
  effect — the engine executes by selecting the real entry;
* two-step confirmation for consequential entries;
* speech checks: the **LLM judges the attempt, the ENGINE decides the outcome**;
* the emergency key always gives a working, visible, clickable menu, in any state;
* the round ships **DRY-RUN by default**.

New this round, and they bind lane A:

* **CAL-1. Nothing auto-calibrating may CLICK an entry.** Passive calibration writes nothing to the
  menu at all. Active calibration may write display properties and `eMenuState`, and may move
  `iSelectedIndex` and put it straight back — it may **never** call `LRG_DlgUI.Click()`,
  `CloseClean()` or `CloseForce()`. The two real click tests stay manual presses (§6.4).
* **CAL-2. Anything that hides the menu during calibration gives it back within 200 ms**, measured
  and logged (`ms=`), and runs **only** while the owner has turned calibration on
  (`bCalibActive:Calib`) and the run budget is not spent. A measured round trip over 200 ms disables
  every further active test for that game session and logs a `CALIB WARNING`.

---

## 1. Facts that bind all three lanes

### 1.1 Press numbers vs P-names — the mapping, because the round's prompt mixes them

`PLAYTEST8_NOTES.md` §3.2 drives the probe by `iProbePress` **press numbers**; `PHASE2_DESIGN` §1.12
names the measurements **P0…P17**. They are not the same numbering and the plan's own prose slips
between them. The mapping that matters here (`LRG_DlgProbe.StepName`, `:125-185`):

| Press | P-name | Note |
|---|---|---|
| 3 / 4 (cycle) | **P8 / P9** | the real click tests — manual, always |
| **17** | **P7s** | Smart Talk hold (the owner holds the left mouse button) |
| **23** | **P11b** | `CloseForce` on a harmless **closed layer** |
| 30 | P17 | row colours / `itemIndex` — **read-only, so it becomes passive** |
| 25 | P13 | wire cost + payload length — **passive**, measured on the payload the driver already builds |
| 13 | P1 | state + timer-id sampling — **passive** |

So "the closed-layer test P23" in the round's prompt is **press 23 = P11b**, and "Smart Talk hold
P17" is **press 17 = P7s**. Every table below uses the P-name and gives the press number in
brackets. The MCM help text for `iProbePress` is corrected accordingly (§13.9, defect
`pt8-verify-experience` #7).

### 1.2 Shipped-code truths this plan is built on (do not re-derive them)

* `LRG_DlgUI` already keeps its calibration in **StorageUtil** under `LRG.du.*` because a Papyrus
  **global** function cannot see a script variable (`LRG_DlgUI.psc:22-42`). The new calibration store
  uses the same mechanism under `LRG.cal.*`.
* `LRG_Dialogue.Arm()` already reads the **pristine** `ALLOW_PROGRESS_DELAY` before `GuardReset()`
  and logs it as `apd=` on the arming line (`:1086`). That is P11's passive half and it is **already
  shipping** — the plan only has to store and grade it.
* `LRG_Dialogue.StepPending()` already detects an engine push during a park (`:1562-1573`). That is
  P7p's answer and it becomes a passive calibration input.
* `LRG_Dialogue.Step()` already counts `dPokesTrue` (how often Smart Talk raised `bAllowProgress`)
  (`:1239-1242`).
* `LRG_Main.HandleCommand()` already reads `note=` off **any** `ExtCmdLRG_` command and raises a
  `Debug.Notification` (`:532-535`) — shipped dormant, "when it is admitted the server half is a
  one-line change". **E2(d) is that one line.**
* `LRG_Main.CONV_MOVE_ACTIONS` (`:26`) is the list of CHIM actions that move or re-task an NPC, and
  `NoteChimAction()` is **dormant for CHIM's own catalog actions** — the DLL raises
  `CHIM_CommandReceived` only on the `ExtCmd` branch (`:1144-1150`). **E4(b) exists because of
  exactly this.**
* `lrgDlgEnsureSchema()` already probes the table as well as the marker file
  (`lrg_dialogue.php:262`), so verify report **C9's first half is done**; what is left is putting the
  index tables somewhere a playthrough switch cannot drop them.
* `install_mo2.ps1:53-75` already writes `meta.ini`'s `version=` from `manifest.json`. The round's
  prompt lists that as work; it is **already done** — lane C verifies it and moves on.

### 1.3 Papyrus API — every call this plan relies on, verified in the installed sources

Everything in `V04_BUILD_PLAN` §1.3 still holds. **New calls this round uses, and nothing else:**

| Call | Verified at | Used by |
|---|---|---|
| `PO3_SKSEFunctions.GetRefAliases(ObjectReference) → Alias[]` | `powerofthree's Papyrus Extender/Source/scripts/PO3_SKSEFunctions.psc:848` | E2(e) `qal=` |
| `Alias.GetOwningQuest() → Quest` | `Skyrim Script Extender (SKSE64)/Scripts/Source/Alias.psc` (line 4, vanilla) | E2(e) |
| `Alias.GetName() → string` | same file, SKSE64 additions block ("; return the name of the alias") | E2(e) |
| `Alias.GetID() → int` | same block | E2(e), tie-break only |

`compile.ps1:67-72` already imports the SKSE64 and PO3 source trees, so the `Alias` type and both
getters resolve with **no new stub and no new import**. `Alias` is a `Hidden` script, so it needs no
`Scriptname` change anywhere.

**Everything the calibration reads or writes on the menu already exists in `LRG_DlgUI`:**
`IsOpen MenuState CountRaw CountRawOn TextProbeOn EntryCount SwfFamily MaxItemsShown Platform
EntryText EntryTopicIndex EntryIsNew EntryColour EntryRowItem Subtitle SubtitlesOn ProgressTimerId
GateArmed SelectAndVerify StateWriteBack Hide ReassertHide HideCursor Guard GuardReset GuardPoke Park
ShowList ForceListState Unhide LastUnhide IsHidden SelectedIndex SelectedTopicIndex SelectedText
HideTopicsVar AllowProgress AllowProgressDelay DisableInput HolderX HolderXInt HolderY ExitButtonX
ExitButtonShown SubtitleShown SpeakerNameShown CursorShown TextInputOn OtherMenuOpen CreateProbeClip
ProbeClipName SmartTalkSafe SmartTalkOffender SmartTalkSetting ReadMode SetReadMode ReadModeAuto
CalibrateReadMode CountMode SetCountMode ResetCalibration`.
**Lane A adds no UI function this round** — only the `LRG.cal.*` store accessors (§11.1).

### 1.4 What would need a DLL — and the confirmation that nothing here does

| Capability | Needs a DLL / an AS2 compiler? | This round |
|---|---|---|
| Knowing an NPC's business with **no session at all** (a read-only walker over running quests' branches) | **Yes** (design §8, `V04_BUILD_PLAN` §11) | **Not built.** E2(b) uses the harvested per-NPC cache first, and only falls back to the index's **per-quest** prompts, explicitly marked approximate and worded as "I might have something about…" |
| **Session shield** — keeping a pending choice layer alive while CHIM runs `EndDialogue` | **Yes** | **Not built.** E3 is the assisted-mode polish instead: a clean, announced hand-back and a re-entry with no re-greet |
| First-frame hiding / real cursor suppression | **Yes** | Not built; the 1–2 frame flash of the exit button stays, and the passive calibration now *measures* it (`flash=`) |
| Exact INFO identity (`parentTopicInfo` FormID), one-call list read, `TESTopicInfoEvent` | **Yes** | Not built; text → index stays, and E5 measures how good it really is per plugin |
| Everything in **E1, E2, E3, E4, E5** as specified below | **No** | Papyrus (the API table of §1.3 + `V04_BUILD_PLAN` §1.3), PHP 8 in the WSL distro, and the `python3` that distro already has. **No C++, no SKSE plugin, no SWF compiler, no install of anything.** |

**Confirmed: nothing in this plan needs a DLL.** Where a DLL would be the better answer (deep
branches if X1 comes back "dies"), the plan measures the problem and hands the owner an honest
assisted path rather than pretending.

---

## 2. Wire v0.5 — every addition

Ground rules unchanged (`PROTOCOL.md` §0): `k=v;k=v`, a value never contains `; | @ "` or a newline,
both sides ignore unknown keys, **a missing key always means the strict/neutral reading**, and the
LAST key of a message may be raw.

### 2.1 Game → server

| # | Message | Key / value | Format | When | Old-version behaviour |
|---|---|---|---|---|---|
| **W1** | `lrg_dlg` | **`ev=calib`** (new `ev` value) | `ev=calib;v=1;ref=<hex8>;npc=<name>;src=<passive\|active\|manual>;gate=<0\|1>;runs=<n>;miss=<csv\|->;k=<raw>` — `k=` is **LAST** and raw: `rm:3,cm:1,lp:1,fam:1,route:2,apd:750,timer:1,hide:1,guard:1,park:1,rb:1,st:1,x1:1,reopen:1,items:8,plat:1,ms3:41,ms4:78,tail:93,flash:2,inj:0,col:0` — pairs are `name:int`, separated by `,`, no spaces, **never** `;` | at the end of a session in which at least one key changed; throttled to **one per 30 s** per game session; plus once from `Maintenance()` when the store is non-empty | A 0.4 server logs `lrg_dlg with unknown ev=calib - ignored (additive wire: this is not an error)` (`lrg_dialogue.php:541`) and returns `handled`. **Nothing breaks and nothing is charged.** |
| **W2** | `lrg_dlg ev=open` | **`cal=`** | `;cal=rm<rm>cm<cm>rt<route>g<0\|1>` e.g. `cal=rm3cm1rt2g1` | every `ev=open`, one extra key | unknown key, ignored |
| **W3** | `lrg_dlg ev=facts` | **`qal=`** | `;qal=<QuestEditorID>:<AliasName>,…` — at most **6** pairs, alias name `CleanForWire`d and clipped to 24 chars, `-` when none | every `ev=facts` (already throttled 60 s per NPC) | unknown key, ignored |
| **W4** | `lrg_dlg ev=facts` | **`qgiver=`** | `;qgiver=<0\|1>` — 1 when any alias name of this NPC matches `*QuestGiver*` / `*Giver*` (case-insensitive) | same | unknown key, ignored |
| **W5** | `lrg_dlg ev=unhide` | **`layer=` `n=` `kind=` `resume=`** (added to the existing message rather than a new `ev`) | `;layer=<int>;n=<int>;kind=<root\|closed\|unknown>;resume=<0\|1>` | every hand-back | unknown keys, ignored |
| **W6** | `lrg_dlg` | **`ev=resume`** (new `ev` value) | `ev=resume;sid=;ref=;npc=;layer=<int>;after=<ms>` | when the driver goes back to driving a session it had handed back, after the player's own click | as W1: unknown `ev`, logged, handled |
| **W7** | `lrg_npcstate` | **`qi=`** | `;qi=<0\|1>` — `bQuestInitiative:Quests`, written next to the existing `ql=` in `LRG_Profile.BuildSnapshot` | every snapshot | unknown key; the server keeps its own `quests.initiative.enabled` default |

**Why `qi` rides the snapshot and not `ev=facts`:** exactly the reason `chk` / `bias` / `ql` do
(`lrg_dialogue.php:188-224`) — an initiative clause has to be decidable on an ordinary CHIM turn
where no dialogue message was ever sent. `lrgDlgMcmFromSnapshot()` gains `qi` in the same
`foreach` that already clamps `bias` and `ql`.

### 2.2 Server → game (the single action `ExtCmdLRG_SelectTopic`, unchanged)

| # | Addition | Param | When | Old-version behaviour |
|---|---|---|---|---|
| **W8** | new verb **`do=release`** | `ok=1;cid=…;npc=…;ref=…;do=release;sid=0;gen=0;pos=-1;i=-1;txt=;kind=hold;cost=0;res=;xp=0;stat=;take=0;ask=;vt=…;x=…;z=1` (the key order of PROTOCOL 10.4 is kept byte for byte) | emitted **before** a CHIM movement action in the same reply, when the snapshot says this NPC is held (`hold=1`, fresh) and the player's own words asked her to move — see E4(b) | game script **401** falls through `CmdSelectTopic`'s final `else` → one `funcret` `Error: unknown command` and a corner note. Harmless; the server must not depend on it, and it does not (§9.2 has the fallback) |
| **W9** | **`note=`** on `do=noop` | `…;do=noop;note=<short sentence, ≤ 120 chars, no `;` `\|` `@` `"`>` | the quest hint of E2(d), at most **once per session per NPC** | **already handled by game 401** (`LRG_Main.psc:532-535`): the note is shown before dispatch, then `do=noop` answers `Noted.` |

**`res=` (verify report C11)** stays on the wire and is documented in PROTOCOL 10.4 as
**diagnostic-only, read by the server's own log and by nobody in the game**. Dropping it would be a
breaking change to a fixed key order for no gain.

---

## 3. MCM — every key this round adds

A **new page "Calibration"** is inserted **after** "Menuless questing" (MCM Helper renders pages in
array order). Additions to the existing "Menuless questing" page are appended inside their existing
sections so no existing control moves.

**The `settings.ini` rule of this project applies to every one of them:** a missing key reads as
`0` / `FALSE`, so **every** key below gets a line in `settings.ini`, the zeroes included, and the
in-code default equals the line.

### 3.1 New page "Calibration" (section `:Calib`)

| id | Page/section | type | range / options | default | text | help (the owner's own words, no jargon) |
|---|---|---|---|---|---|---|
| `bCalibPassive:Calib` | Calibration / "Learning by itself" | toggle | — | **1** | Learn from ordinary conversations | While this is on, every time a dialogue menu opens — one you opened yourself with E, a courier, a guard, anything — the glue quietly measures how the menu behaves on your install and writes the answers down. It only ever READS. It never hides the menu, never presses anything and never changes what you see. This is what replaces the long evening of test presses. |
| `bCalibActive:Calib` | Calibration / "Learning by itself" | toggle | — | **0** | Calibrate on the next few conversations | Three measurements cannot be taken by watching alone: whether hiding the menu and giving it back really works here, whether the menu accepts being told what state it is in, and the second way of reading the topic list. Switch this on and simply have a few ordinary conversations. Each one runs ONE of those tests, gives the menu back within a fifth of a second, and puts a short note in the corner when it does. It switches itself off again after the number of conversations below. |
| `iCalibRuns:Calib` | Calibration / "Learning by itself" | slider | 1–10 step 1 | **3** | How many conversations to use | The switch above turns itself off after this many. Three is enough unless something failed. |
| `bAutoTimings:Calib` | Calibration / "Learning by itself" | toggle | — | **1** | Let it adjust the waiting times | The glue may lengthen "Quiet time before the first click" and "How long it waits for the server" when what it measured says they are too short on your machine. It can only ever make them LONGER than what you set — your own numbers stay the floor. |
| `sCalibStatus:Calib` | Calibration / "Where it stands" | **text (read-only)** | — | — | Calibration status | *(dynamic — see §11.4; shows e.g. `green - 11 of 11 answered (reading 3, counting 1, route B, timer per line)` or `not yet - missing: hide round trip, click route`)* |
| `bCalibOverride:Calib` | Calibration / "Where it stands" | toggle | — | **0** | Let me leave dry run anyway | Normally the glue refuses to stop being a dry run until everything above is answered — that is the whole safety net. Switch this on only if you have read the log yourself and want to go ahead regardless. |

### 3.2 New page "Calibration", section "The three things it cannot do for you"

Buttons. **Primary route:** MCM Helper `"type": "button"` with
`"action": {"type": "CallFunction", "form": "LoreRimGlue.esp|0x801", "function": "<name>"}` —
`0x801` is `LRG_MCMQuest` (`make_esp.py:43`), which carries `LRG_MCM`, the mod's own config script,
which is the only script MCM Helper's `CallFunction` can reach. **Guaranteed fallback, kept:** every
one of these is also reachable from the `iProbePress` slider, whose numbers do not change. Lane B
proves the buttons work in game before the fallback text is removed.

| id / function | text | help |
|---|---|---|
| `CalPressClick` | **1. The click test** *(run this on an innkeeper)* | Stand in front of somebody harmless with a long list and press this. The menu is hidden and locked for three seconds — mash the mouse, E, Space and the wheel during that time, nothing should happen — and then ONE topic is really chosen for you, the last one on her list. Then you get the menu back. This is the one test that cannot be done by watching, because it has to press something for real. |
| `CalPressReopen` | **2. The menu-still-works test** *(straight after 1)* | Close that conversation, walk up to anybody and open a normal conversation with E, then press this. It checks that nothing the test left behind can make your ordinary dialogue unclickable, and gives you three seconds to click a topic with the mouse so it can see that it worked. |
| `CalPressSmartTalk` | Optional: the held-mouse test | Only needed if the status line says Smart Talk is in the way. Press this in the middle of one of her lines and HOLD the left mouse button down. |
| `CalPressForceClose` | Optional: the force-close test | Only on a harmless question with two or three answers — never a guard, never an arrest. It closes the conversation two different ways and asks you whether she said a parting line. Nothing in the feature uses this; it only settles a question the design left open. |
| `CalForget` | Forget everything it learned | Starts the measuring again from nothing. |

### 3.3 Additions to the existing page "Menuless questing"

| id | Section | type | range | default | text | help |
|---|---|---|---|---|---|---|
| `bQuestInitiative:Quests` | The quest tree | toggle | — | **0** | She may bring it up herself | When she has something on her list you have not asked about — a job, a favour, something the two of you left unfinished — she may raise it herself, in her own words, if the moment suits. She never reads it out and never acts on it: you still have to say yes. Off by default, because it changes how conversations start. |
| `iQuestInitiativeGap:Quests` | The quest tree | slider | 60–1800 step 60 | **600** | Not more often than | Seconds before the same person may bring something up again. She never raises the same matter twice. |
| `bQuestHint:Quests` | The quest tree | toggle | — | **1** | Tell me when she has something | A short note in the corner when the conversation the glue just read really does contain something quest-related, so you know there is something worth asking about. |
| `bQuestSummary:Quests` | The quest tree | toggle | — | **1** | She can say what you can ask her | "What can I ask you?" / "Anything I should know?" — she answers in character from what the glue has actually seen of her list. If it has never seen one, she is vaguer on purpose and says so in her own way. |
| `bQuestNext:Quests` | The quest tree | toggle | — | **1** | She can tell you where you left off | "What should I do next?" / "Where was I?" — answered from your own journal, current objective only, never a later step, never a stage number. The Narrator answers it when nobody is in front of you. |
| `bHandBackNote:Dialogue` | Choices one layer deeper | toggle | — | **1** | Say when I have to choose by hand | Some choices cannot be made by talking — an arrest, a real branch on some installs. When that happens the menu comes back cleanly and a note in the corner says "choose by hand" instead of leaving you guessing. |
| `bResumeAfterChoice:Dialogue` | Choices one layer deeper | toggle | — | **1** | Carry on without the menu afterwards | Once you have clicked that one choice yourself, the conversation goes back to being menuless for the rest of it — without greeting you again. |

### 3.4 Corrections to existing MCM entries (no id changes)

* `iReadMode:Dialogue` help gains: "0 also means *use what the glue measured for itself*."
* `iCountMode:Dialogue` help: same sentence.
* `iMaxEntries:Dialogue`: **range becomes 0–24** and the help gains "0 = as many as your machine can
  afford, measured while you play. Anything else is your own number and wins."
* `iProbePress:Dialogue` help: press numbers relabelled correctly — **15 = P6 full-hide comparison,
  16 = P6f the other menu layout, 17 = P7s the held-mouse test, 23 = P11b the force-close** — and the
  note that 16 is only meaningful if you ever swap the dialogue-interface mod (verify report
  `pt8-verify-experience` #7).
* `bProbe:Dialogue` help gains one sentence: "the presses that click and close for real are
  numbers 3, 4, 5 and 23 — the calibration on the Calibration page never clicks anything by itself."
  (verify report `pt8-verify-game` MINOR-9 answered by scope, not by gating.)
* `bDlgDryRun:Dialogue` help gains: "The glue will refuse to leave dry run until the Calibration page
  says green — that refusal is the safety net, and the Calibration page has a switch to override it."
* `bFreeChecks:Checks` help gains one sentence saying **where** a check is logged:
  `check npc=… kind=… diff=… roll=… result=…` in `lorerim_glue.log` (verify report
  `pt8-verify-experience` #10).

---

## 4. Server config keys (`lrg_config.json`, `dialogue` block — defaults in `lrgDlgDefaults()`)

```
'calib'   => ['accept' => true, 'log' => true],
'quests'  => [ … existing … ,
    'initiative' => ['enabled' => false, 'gap_seconds' => 600, 'per_topic_once' => true,
                     'max_per_session' => 1, 'min_entry_words' => 3],
    'summary'    => ['enabled' => true, 'max' => 8, 'approximate_max' => 6],
    'next'       => ['enabled' => true, 'lines' => 3],
    'hint'       => ['enabled' => true, 'per_session' => 1],
],
'ask_list_phrases' => ['what can i ask you', 'what can i ask', 'anything i should know',
    'anything you need', 'what do you need doing', 'do you have work', 'got any work',
    'what have you got for me', 'is there anything', 'what is there to do'],
'ask_next_phrases' => ['what should i do next', 'where was i', 'what was i doing',
    'what am i supposed to do', 'remind me what', 'where do i go now', 'what now'],
'hold_hides_movement' => true,
'hold_release_on_move' => true,
'hold_move_actions' => ['ComeCloser','FollowPlayer','Follow','MakeFollower','MoveTo','TravelTo',
    'TravelToRaw','LeadTheWayTo','ReturnBackHome','Sandbox','GoToSleep','TakeASeat','Relax',
    'WaitHere','HireCarriage','HireFerry'],
'move_request_words' => ['come here','come closer','follow me','with me','over here','lead the way',
    'take me to','walk with me','sit down','wait here','stay here','go home'],
'assist' => ['note' => true, 'resume' => true, 'line' => true],
'index'  => ['schema' => 'lrg_index'],
```

`hold_move_actions` is deliberately the **same list, same spellings** as `LRG_Main.CONV_MOVE_ACTIONS`
(`LRG_Main.psc:26`). A flow test asserts the two lists are equal (§14, `d52_holdmove`), because a
silent divergence is exactly how this class of bug comes back.

---

## 5. Log lines — exact formats

All of these go through `LRG_Main.LogC()` (game) or `lrgDlgLog()` (server) and therefore appear in
`log/lorerim_glue.log` prefixed `GAME` / `dlg`. Every line is ≤ 300 characters (`LogC` truncates) and
contains no `;`.

**Game side (lane A writes the `CALIB` family through `LRG_Main.LogC`, lane B writes the rest):**

```
CALIB set <key>=<value> src=<passive|active|manual> was=<old> n=<samples> npc=<name>
CALIB probe <Pname> <k=v …> ms=<int> npc=<name>
CALIB active <Aname> ms=<int> ok=<0|1> gave_back=<0|1> x=<float> st=<int> n=<int> npc=<name>
CALIB WARNING <text>
CALIB SUMMARY gate=<0|1> answered=<n>/<n> rm=<n> cm=<n> lp=<n> fam=<n> route=<n> apd=<n> timer=<n> hide=<n> guard=<n> park=<n> rb=<n> st=<n> x1=<n> reopen=<n>
CALIB GATE missing=<csv of human names, or none>
CALIB X1 menu=<survived|died|unknown> sid=<sid> after_player_speech_ms=<int> layer_alive=<0|1> n_before=<int> n_after=<int> npc=<name>
dlg dry-run FORCED: calibration is not green - missing <csv> (Calibration page has an override)
dlg dry-run released: the calibration is green
handback sid=<sid> why=<reason> layer=<int> n=<int> kind=<root|closed> note=<0|1> resume_armed=<0|1> npc=<name>
resumed sid=<sid> layer=<int> after=<ms> npc=<name>
rewalk step=<int>/<int> pos=<int> clean=<0|1> abort=<reason|-> npc=<name>
```

**Server side:**

```
dlg calib npc=<name> src=<…> gate=<0|1> runs=<n> miss=<csv> k=<raw>
dlg initiative npc=<name> entry="<40 chars>" quest=<id> gap=<n>s said=<n> - offered to the model
dlg initiative npc=<name> dropped: <reason>
dlg ask npc=<name> kind=<list|next> source=<cache|index-approx|journal> rows=<n>
dlg hint npc=<name> quest=<id> note="<text>" (once per session)
dlg handback npc=<name> why=<…> layer=<n> kind=<…> resume=<0|1>
dlg hold npc=<name> movement <Action> <hidden|released> (the game is holding her)
dlg index schema=<lrg_index|public> rows=<n> layers=<n>
```

---

## 6. E1 — THE SELF-CALIBRATING DRIVER *(highest priority)*

**What it replaces.** `PLAYTEST8_NOTES.md` §3.2: 21 rows, three of which require standing in front of
a specific kind of NPC, one of which requires waiting two minutes doing nothing, and all of which
require the owner to set a slider between each press. **After this round: play normally; then two
presses.**

### 6.1 (a) PASSIVE calibration — during ordinary play, with menuless OFF

**Trigger.** Every `OnMenuOpen("Dialogue Menu")` that reaches `LRG_Dialogue.RunSession()` — which is
*every* dialogue open, including the player's own E-press, because `LRG_Dialogue` registers for the
menu unconditionally in `Maintenance()` (`:300-301`) and `Arm()` runs on every open whatever
`bMenuless` is (`:1077-1233`). With `bMenuless = 0` the session goes to `ST_MANUAL` and
`StepManual()` still reads and forwards the list — **the passive calibration rides that existing
path and costs nothing extra when there is nothing left to answer.**

**Absolute rule.** Passive calibration calls only functions that perform `UI.Get*` and never
`UI.Set*` / `UI.Invoke*`. Concretely it may call: `MenuState CountRaw CountRawOn TextProbeOn
EntryCount SwfFamily MaxItemsShown Platform EntryText(pos, 3) EntryText(row, 5) EntryTopicIndex(pos,
3) EntryIsNew(pos, 3) EntryColour EntryRowItem Subtitle SubtitlesOn ProgressTimerId GateArmed
AllowProgress AllowProgressDelay DisableInput SelectedIndex SelectedTopicIndex SelectedText
HideTopicsVar HolderX HolderXInt HolderY ExitButtonX ExitButtonShown SubtitleShown SpeakerNameShown
CursorShown SmartTalkSafe SmartTalkOffender OtherMenuOpen`. **`EntryText(pos, 4)` is excluded** — mode
4 writes `iSelectedIndex` (`LRG_DlgUI.psc:256`), which is precisely the D2 fix pass of v0.4 and must
not come back. Mode 4 is an **active** test (§6.3 A4).

**The hooks lane B adds to `LRG_Dialogue`, and where (lane A implements the bodies):**

| Hook | Called from | Cost |
|---|---|---|
| `p.CalArm(apd0, fam, maxItems, platform, isHidden, glueOpened)` | `Arm()`, immediately after the existing `apd0` read and `GuardReset()` (`:1086-1087`) — so `apd0` is still the pristine value | 0 extra UI reads (every argument is already in hand) |
| `p.CalPoll(dlgState, n, timerId, sub)` | `Step()`, at the end, with `timerId = -1` and `sub = ""` when the driver did not read them this poll | ≤ 1 extra delayed native, and only on every 5th poll |
| `p.CalLayer(nTotal, eHead, readMode)` | `ReadList()`, only on the branch where the signature changed (`:1425-1432`) | 3 count reads + ≤ 4 mode-5 row reads + ≤ 4 colour reads, **once per layer** |
| `p.CalPending(nBefore, nAfter, sigChanged)` | `StepPending()` on the `pushed` branch (`:1567`) | 0 |
| `p.CalSpeech(who, now)` | `LRG_Main.OnChimSpeechStarted/Stopped` → new `LRG_Dialogue.NoteSpeech(who, now)` → forwards | 0 |
| `p.CalClose(why, layer, pendingLayer, anyClick)` | `Finish()` (`:1995-2002`) | 0 |
| `p.CalPayload(chars, part2, tailChars)` | `SendTopics()`, after the payload string is built | 0 (P13 for free) |

**What each passive measurement answers, and the calibration key it writes:**

| Probe | Measured passively from | Key | Values |
|---|---|---|---|
| **P2** counting | `CalLayer` calls `CountRawOn(1/2/3, ListPath())` on both list spellings, exactly the loop `EntryCount()` already runs (`:172-193`) | `cm`, `lp` | `cm` 1/2/3, `-1` none works; `lp` 1/2 |
| **P3** reading | `CalLayer` reads `EntryText(0..min(3,n), 3)` + `EntryTopicIndex` + `EntryIsNew`; non-empty on every position = mode 3 works | `rm` | 3 (mode 3 proven), 0 (not yet), `-3` (mode 3 empty while a text probe sees a list → mode 4 must be tested actively) |
| **P5** rows | `CalLayer` reads `EntryText(row, 5)` and `EntryRowItem(row)` for rows 0..3 on a list of ≥ 10 | `row` | 1 = row ≠ position demonstrated, 2 = row == position on every sample so far |
| **P3t** timing | wall time around the driver's own `ReadList()` loop and around the tail loop | `ms3`, `tail` | ms per 12 reads / per 24-entry tail |
| **P13** wire cost | `CalPayload` | `pay` | payload characters of the largest layer seen |
| **P1** progress timer | `CalPoll` samples `ProgressTimerId()` (every 5th poll) and `Subtitle()`; counts distinct ids per session and how many of them coincide with a subtitle change | `timer` | 1 = moves **per line**, 2 = moves **per session only**, 0 = not yet |
| **P11 passive half** | `CalArm`'s `apd0` — already shipping as `apd=` | `apd` | 750 clean, 100000000 poisoned, any other value recorded verbatim |
| **P0 / P0b (UI presence)** | `CalArm` stores `fam`, `items`, `plat`, `HideTopicsVar() != ""`; and — honestly — the **glue-initiated** opens only (`DoOpen()`'s `tries` and the ms to `IsOpen()`, the dump key's `Activate`) | `fam`, `items`, `plat`, `actms`, `actry` | as read |
| **P17** colours | `CalLayer`'s colour reads; records whether `16767334` (`0xFFD966`) is ever seen | `col` | 1 = seen, 2 = never seen over ≥ 20 layers, 0 = not yet |
| **P7p** park | `CalPending` — the driver already detects an engine push while parked | `park` | 1 = the pushed list was **dropped** (count/signature only changed after `ShowList()`), 2 = deferred, 0 = never observed |
| **P7 partial** | `dPokesTrue` and, while `guarded`, whether `SelectedIndex()` or `Subtitle()` moved with nothing driving | `poke` | count; **not** a gate key — the gate's `guard` key needs the manual press |
| **Smart Talk** | `SmartTalkSafe()` at `Arm()` | `st` | 1 safe, 0 not (with `SmartTalkOffender()` named in the log) |

**Honest correction to the round's prompt:** it lists "state sampling (P13)". P13 is the *wire cost
and payload length* press; the *state sampling* press is **P1**. Both are covered above, P1 as
`timer` and P13 as `pay`.

**P0b is not fully passive and the plan says so.** The probe's P0b opens and closes a conversation
six times to compare `Activate(player)` with `Activate(player, true)` — that cannot happen behind the
owner's back. What *is* passive is the half that matters: whenever the glue itself opens a session
(the dump key, `do=open`), `DoOpen()`'s existing retry loop (`:997-1012`) records which form was used
and how long it took. If `actry > 1` on three opens in a row, the calibration writes a `CALIB
WARNING` naming `bActivateDefaultOnly` and the owner can flip one toggle. The A/B press stays
available as `iProbePress = 12` and is **not** in the gate set.

### 6.2 (b) PASSIVE X1 — does the session survive the player speaking?

This is the design's own gate (`PHASE2_DESIGN` §0.3 / §2.6) and it decides whether branching is a
voice feature or an assisted one. It needs **no** press at all.

**Mechanism.** `LRG_Main` already receives `CHIM_SpeechStarted` / `CHIM_SpeechStopped` for the
**player's own voiced line** (`:614-639`, `npc == Game.GetPlayer()` branch) and `CHIM_TextReceived`
for an NPC line. Lane B adds one forward in each of those three handlers:

```
LRG_Dialogue live = GetDialogue()
if live
    live.NoteSpeech(0, now)      ; 0 = player, 1 = npc
endif
```

`LRG_Dialogue.NoteSpeech()` records the moment **only while a session is open**, then hands it to
`p.CalSpeech(who, now)`. From there lane A's calibration:

1. stamps `x1_at = now`, `x1_n = EntryCount()`, `x1_sig = <the driver's own sig>`, `x1_layer = layer`,
   and whether the current layer is a **choice layer** (`n > 1` and the driver's `sess.kind` is not
   root — passed in from `CalArm`/`CalLayer`);
2. watches the next **6 s** of the driver's own polls (no extra reads: `CalPoll` already receives
   `n`): did the menu close (`CalClose` arrives), and did the choice layer survive
   (`EntryCount() > 0` and the signature unchanged)?
3. writes `x1` = **1 survives** / **2 dies** / **0 unknown**, plus running counts `x1s` / `x1d` /
   `x1u`, and logs one `CALIB X1` line per observation.

**CHIM's listener log.** The round's prompt asks to record "what CHIM's listener log said". Papyrus
cannot read `AIAgent.log`. What the game *can* record, and does, is the CHIM-side facts it already
has: whether `AIAgentFunctions.isActorTalking(npcName)` was non-zero at the moment of the player's
line, and whether the player's line was routed to **this** NPC at all (the `CHIM_TextReceived`
`asNpcName` that follows, compared with `npcName`). Those two go on the `CALIB X1` line as
`talking=<0|1>` and `routed=<self|other|none>`. The server half adds the one thing only it can see:
on `ev=calib` with `x1` set, `lrgDlgLog()` prints the last `dlgtalk` admission/drop for that NPC. If
the owner also wants the raw CHIM listener lines, the notes tell them the one grep
(`grep -E "InterruptNPC|EndDialogue|AUTO_ELIGIBILITY" AIAgent.log`) — it is a **documentation** item,
not a code one, and the plan does not pretend otherwise.

**Threshold for the key to count as answered:** `x1s + x1d >= 3` **and** one of them ≥ 3× the other.
Otherwise `x1 = 0` and the MCM status says "still watching (2 survived, 1 died)". `x1` is **not** in
the dry-run gate — it changes *behaviour* (`iBranchInput` auto, `bRewalk` meaningfulness), not
*safety*.

**What `x1` drives once answered:** `iBranchInput = 0 (auto)` resolves to **by voice** on `x1 = 1`
and to **show me the choice** on `x1 = 2`; E3's hand-back note and pre-emptive line are armed on
`x1 = 2`; the server's own copy (from `ev=calib`) decides whether the `<business>` block gets the
"you cannot settle this by talking" clause.

### 6.3 (c) OPT-IN ACTIVE calibration

**Armed by `bCalibActive:Calib` only.** Budget `iCalibRuns` menu opens; the toggle writes itself back
to `0` when the budget is spent (lane B, from `LRG_Dialogue` through `MCM.SetModSettingBool` — if
MCM Helper refuses a write, the budget is enforced in the store and the toggle is merely stale, which
the status text says).

**At most ONE active test per menu open**, run from `Arm()` after `SendOpen()` and before the state
is set, so the driver is not yet in `LISTENING` and no decision can be in flight. Order (cheapest and
safest first):

| # | Test | Probe | What it does | Writes | Hide? |
|---|---|---|---|---|---|
| **A2** | read-back gate | **P10** [21] | `StateWriteBack(2)` × 3, then `StateWriteBack(st0)`; if `st0 == 1` and the state is not 1, `ShowList()` | `rb` 1 reliable / 2 unreliable | no |
| **A3** | guard leak | **P11** (write half) | `GuardReset()`, then `AllowProgressDelay()` must read `750` | `apdr` 1 / 0 | no |
| **A5** | injection | **P12** [24] | `CreateProbeClip()` + `ProbeClipName()` | `inj` 1 / 0 | no |
| **A1** | **hide round trip** | **P6-H2** [2, 15] | `HolderX/Y`, `ExitButtonX`, `SubtitleShown` before → `Hide(1)` → `flash` frames to `_x > 4000` → read `SubtitleShown`, `ExitButtonShown`, `CursorShown`, `MenuState` → **`Unhide()`** → `LastUnhide()`, `HolderX` restored, `EntryCount() > 0` | `hide` 1 ok / 2 unhide refused / 3 stored value unusable; `flash`; `hidems` | **yes, ≤ 200 ms** |
| **A4** | read mode 4 | **P4** | only when `rm == -3` (mode 3 came back empty). `Hide(1)` → `Park()` if state 1 → `sel0 = SelectedIndex()` → `EntryText(0, 4)` → `SelectAndVerify(sel0, -1, "", false)` → `ShowList()` → `Unhide()` | `rm` 4 / 0 | **yes, ≤ 200 ms** |

**The 200 ms contract, enforced in code.** `A1` and `A4` take `t0 = Utility.GetCurrentRealTime()`
immediately before `Hide()` and `t1` immediately after `Unhide()` returns. They contain **no**
`Utility.Wait*` of any kind — every call in the sequence is a delayed native (≈ one frame each), and
the longest sequence (A1) is 14 of them ≈ 240 ms at 60 fps worst case, ≈ 160 ms typical.
Therefore:

* the sequence is written so the **restore is unconditional**: `Unhide()` is called on every exit
  path, including the one where `Hide()` returned `false`;
* `ms = Ms(t0, t1)` is logged on every run;
* `ms > 200` → `CALIB WARNING active hide took <ms> ms - active calibration is off for this session`,
  `CalActiveDisable()` for the rest of the game session, and the key is left unanswered rather than
  claimed;
* immediately after `Unhide()`, `LastUnhide()` is read: a `2` (no usable stored value) is a **red**
  `hide` result and also disables further active tests;
* a corner note is raised on entry: `Debug.Notification("LoreRim Glue: calibrating - one moment")`
  and on exit `Debug.Notification("LoreRim Glue: menu back")`.

**A1 and A4 never run** when: the session is `crit == 2` (LETHAL), `inScene`, `origin == engine` while
`iEngineOpen == 0` and the speaker is a guard, a service menu is open (`Utility.IsInMenuMode()`), the
driver is already hidden (menuless live), or `forceVisible` is set. Every refusal is logged as
`CALIB active <name> skipped=<reason>`.

### 6.4 (d) What stays MANUAL, why, and its one-press flow

| Test | Why it cannot be automatic | Press |
|---|---|---|
| **P7 (input guard) + P8/P9 (a real click)** | CAL-1 forbids an automatic click, and the guard can only be judged while a human really mashes the mouse/E/Space/wheel | **Button "1. The click test"** = `LRG_DlgProbe.CalPressClick()` |
| **P11 (the re-open half)** | Only the owner can open a menu **by hand** with no glue arming, and only the owner can click a topic with the mouse | **Button "2. The menu-still-works test"** = `CalPressReopen()` |
| **P7s [17] Smart Talk hold** | The owner must physically hold the left mouse button through one of her lines | Optional button — **and it is moot once `SmartTalk.ini` is `0/0/0`**, which is a hard precondition anyway (D-21). It is only offered when `SmartTalkSafe()` is false |
| **P11b [23] force-close on a closed layer** | Needs a harmless closed layer and the owner's ear for a walk-away line | Optional button. **Not in the gate set** — the driver's escalation ends at MANUAL and never calls `CloseForce` (`:1940`), so E-R7 does not block anything |
| **P6f [16] the other SWF family** | Needs the owner to hide CHIM's `dialoguemenu.swf` in MO2 | Documentation only; only meaningful if the interface mod is ever swapped |
| **P0b [12] activation A/B** | Opens and closes six conversations | Optional; the passive half (§6.1) covers the case that matters |

**Press 1 — `CalPressClick()`, one press, ~10 s, on an innkeeper (never a guard, never a quest NPC
mid-quest).** Exactly the sequence, in order:

1. refuse unless a menu is open with `EntryCount() > 0`, the speaker is not a guard, is not in a
   scene, and `CrimeGold() == 0` — the same `ClickAllowed()` matrix the probe already enforces in
   code (`LRG_DlgProbe.psc:1092-1103`);
2. `Guard(true)`, `Hide(1)`, `HideCursor(true)`; `Debug.Notification("mash the mouse, E, Space and
   the wheel for three seconds")`;
3. 3 s of `GuardPoke()` at 0.1 s while sampling `SelectedIndex()`, `Subtitle()`, `ProgressTimerId()`,
   `AllowProgress()` — **this is P7**, and it writes `guard` 1 (nothing moved) / 2 (something moved)
   plus `poke_true`;
4. `route = ChooseRoute(false)` (the deterministic table: `fam 1 → B`, `fam 2 → A`, unknown → refuse
   and write `route = 0`); read the **last** entry's text and `topicIndex`; `SelectAndVerify(pos, ti,
   prefix, route == 1)`; **one** `Click(route)`; `ClickResult()`;
5. 5 s of sampling `EntryCount()`, `ProgressTimerId()`, `Subtitle()`, `GateArmed()` — **this is P8/P9**
   — writing `route` (1 or 2, proven) and, as a free by-product, confirming P1's `timer` answer and
   `rb`;
6. `Unhide()`, `HideCursor(false)`, corner note, `CALIB probe P7 …` + `CALIB probe P8 …` lines.

**If the chosen route comes back red**, the status text says so and offers the second press
("try the other click"), which is the same function with `abOther = true`. That is the only reason a
third press is ever needed.

**Press 2 — `CalPressReopen()`, one press, ~5 s.** `readOnly = true` throughout:
`AllowProgressDelay()` (must read **750** — this is R1, the one the design calls load-bearing),
`HolderX()` (must be on screen), `IsHidden()`, `MenuState()`, `EntryCount()`, then a 3 s window with
`Debug.Notification("click a topic with the mouse now")` that watches the signature and the timer id.
Writes `apd`, `reopen` 1 / 0.

### 6.5 (e) The driver reads the calibration, and refuses to leave dry-run until it is green

**Resolution order for every calibrated setting, in `LRG_Dialogue.ReadSettings()`:**

| Setting | Explicit MCM value | `auto` | Sentinel |
|---|---|---|---|
| `iReadMode` | 3 or 4 → forced, unchanged | `0` → `p.CalGet("rm", 0)`; if that is 0, today's live `CalibrateRead()` still runs | `0` (already means auto) |
| `iCountMode` | 1–3 → forced | `0` → `p.CalGet("cm", 0)` → `LRG_DlgUI.SetCountMode()` | `0` |
| `iClickRoute` | 1 or 2 → forced | `0` → `p.CalGet("route", 0)`, then today's `fam` table | `0` |
| `iMaxEntries` | 1–24 → the owner's number wins | **`0` → from `ms3`**: `cap = clamp(4, 24, 900 / max(1, ms_per_read))`, i.e. keep one layer's read under ~0.9 s | new `0` (range becomes 0–24) |
| `fLineSettle` | the MCM value is the **floor** | with `bAutoTimings`: `timer == 2` (per session) → `max(mcm, 0.8)`; `timer == 1` → `max(mcm, 0.3)` | — |
| `fDecideTimeout` | floor | with `bAutoTimings`: `max(mcm, 1.5 + payload_ms/1000)` | — |
| `iBranchInput` | 1 or 2 → forced | `0` → `x1 == 1` → by voice; `x1 == 2` → show me the choice; `x1 == 0` → today's behaviour | `0` |
| `iHideMode` | unchanged | **no auto** — H2 (topics only) is the only safe mode and is already the default; calibration only proves the round trip (`hide`) | — |

**The gate set** is `PHASE2_DESIGN` §11 step 0's "Gate out of step 0" — *P1, P2 (at least one count
mode), (P3 or P4), P6-H2, P7, P7p, (P8 or P9), P10, P11* — plus §11's two hard preconditions
(`SmartTalkSafe()`, and P11 read). Mapped to keys:

| Gate row | Key(s) | Green when | Human name in `CALIB GATE missing=` |
|---|---|---|---|
| P2 | `cm` | 1, 2 or 3 | `counting` |
| P3 or P4 | `rm` | 3 or 4 | `reading` |
| P1 | `timer` | 1 or 2 | `progress timer` |
| P6-H2 | `hide` | 1 | `hide round trip` |
| P7 | `guard` | 1 | `input guard` |
| P7p | `park` | 1 or 2 | `parked push` |
| P8 or P9 | `route` | 1 or 2 | `click route` |
| P10 | `rb` | 1 | `state read-back` |
| P11 | `apd` **and** `reopen` | `apd == 750` and `reopen == 1` | `vanilla menu after a hide` |
| precondition | `st` | 1 | `Smart Talk settings` |
| precondition | `fam` | 1 or 2 | `menu layout` |

**11 keys.** `x1`, `col`, `row`, `inj`, `pay`, `ms3`, `tail`, `actms` are **measurements, not gates** —
they change behaviour, never safety.

**The refusal**, in `ReadSettings()` immediately after `sDryRun` is read:

```
sCalOverride = m.SettingBool("bCalibOverride:Calib", false)
if !sDryRun && !sCalOverride && !CalGreen()
    sDryRun = true            ; forced
    if !calForced
        calForced = true
        m.LogC("", "dlg dry-run FORCED: calibration is not green - missing " + CalMissing() \
            + " (Calibration page has an override)", "")
        Debug.Notification("LoreRim Glue: still a dry run - see the Calibration page")
    endif
elseif calForced
    calForced = false
    m.LogC("", "dlg dry-run released: the calibration is green", "")
endif
```

`CalGreen()` / `CalMissing()` are one call into `LRG_DlgProbe` each and are cached for 5 s alongside
the rest of `ReadSettings()`. **`bMenuless` itself is never forced** — with `bMenuless = 1` and a red
gate the session simply runs as it does today (visible, harvested, nothing clicked), which is
already the safe state.

`SelfTest()` gains one line at the end: the `CALIB SUMMARY` line, so the whole picture is in the log
on every load and on every topic-dump press (which already calls `LogDiagnostics()` → `SelfTest()`).

### 6.6 (f) The owner's new short evening

See §15. In one sentence: **play three or four ordinary conversations, switch "Calibrate on the next
few conversations" on and have three more, then two presses.**

---

## 7. E2 — QUEST AWARENESS v2

### 7.1 (a) NPC-initiated quest talk

**Source of truth: the per-NPC list cache that already exists.** `lrgDlgOnTopics()` writes
`$st['root']` on every root list (`lrg_dialogue.php:425-428`), harvested from sessions that happen
anyway — the E-press hail, a courier, a guard, a dump. **No speculative session is ever opened for
this** (`PHASE2_DESIGN` §2.1): `do=open` is untouched and `lrgDlgMaybeOpen()`'s gates are unchanged.

**Candidate entry** (`lrgDlgInitiativeCandidate(array $t): ?array`), all required:

* the entry is in the cached root or the live pending list and `class` is `plain` or `service` —
  **never** `check`, `pay`, `commit`, `meta`, `silent`, `back`, `hidden`;
* `crit == 0`, `commit == 0`, `walkaway == 0`, `twat == ''`;
* **and** it is quest-bearing: (`quest != ''` **and** `journal == 1` **and** the quest is in this
  NPC's `q`) **or** (`new == 1` **and** `indexed == 1` **and** `toplevel == 1`);
* at least `quests.initiative.min_entry_words` (3) meaning-carrying words, so "Yes." is never raised.

**Admission** (all required, cheapest first):

1. `lrgDlgMcm('qi', quests.initiative.enabled ? 1 : 0, $npc)` is 1 — the MCM toggle, carried on the
   snapshot (W7);
2. the turn is an ordinary player-speech turn (`$t['speech']`) or `lrg_dlgtalk`, `$t['on']` is true;
3. no session is open (`$t['open'] == 0`) and `$t['scene'] == 0` and `$t['ostim'] == 0`;
4. **idle or on-topic**: either the player's utterance shares no meaning-carrying word with any
   offered key (idle — she is not interrupting something), **or** it shares ≥ 1 word with *this*
   entry's `norm` (on-topic);
5. `lrgNow() - ($st['init_at'] ?? 0) >= gap_seconds` (600);
6. this entry's `norm` is not in `$st['init_said']` (a capped list of 20, `per_topic_once`);
7. `$st['init_session'] < max_per_session` for the current game session tag (the snapshot's `sess=`).

**Effect** — one block appended by `lrgDlgVolatileGuidance()`, **after** the `<business>` block so it
can name a key:

```
<she_may_raise key="T3">
You have something to put to {player} that he has not asked about: "{entry text, verbatim}".
If this moment suits, raise it yourself, in your own words - do not read it out, do not list it,
and do not use {ACTION} yet. If he takes it up, use {ACTION} with T3 on your next turn.
If the moment does not suit, say nothing about it.
</she_may_raise>
```

`init_said[norm] = now`, `init_at = now`, `init_session++` are written **when the block is emitted**,
not when she speaks — so a turn where she chose not to raise it still consumes the slot and she
cannot nag. Execution is unchanged: **match → confirm → select**, through the existing gate.

**Cost: zero extra LLM calls.** The block rides a turn that was going to happen.

### 7.2 (b) "What can I ask you?" / "Anything I should know?"

**Recogniser** (lane C, in `lrg_dialogue.php`, *not* in `lrg_intent.php` which is another lane's
file): `lrgDlgAskKind(string $utter): string` → `list` | `next` | `''`, from
`ask_list_phrases` / `ask_next_phrases` normalised with `lrgPromptNorm()` and matched as whole
phrases with a small edit tolerance (containment after normalisation). Covered by
`tools/test_phrases.php` in the style already used there.

**`list` → `<what_you_can_ask>`, two sources, and the difference is stated to the model:**

*Source 1, the cache (preferred):* the ranked root entries the glue really saw, `class != hidden`,
cap `quests.summary.max` (8), **verbatim**, in engine order:

```
<what_you_can_ask certain="1">
{player} asked what he can bring up with you. These are really on the table between you:
- {entry 1 text}
- {entry 2 text}
Answer in character, in your own words - do not read the list out as a list, mention the two or
three that matter most to you, and let the rest wait.
</what_you_can_ask>
```

*Source 2, the index, when the cache is empty (`root` missing or stale):* `lrgPromptByQuest($q,
$limit)` — a new, narrow lookup in `lrg_prompt_index.php`:

```php
function lrgPromptByQuest(array $quests, int $limit = 6): array
// SELECT txt, quest, topic, kind FROM <schema>.lrg_prompt
//  WHERE quest IN (…) AND toplevel = 1 AND kind = '' AND crit = 0 AND shared <= 20
//  ORDER BY journal DESC, shared ASC LIMIT <limit>
```

needing one new index (migration 007): `CREATE INDEX IF NOT EXISTS lrg_prompt_quest_idx ON
<schema>.lrg_prompt (quest) WHERE quest <> '';`

```
<what_you_can_ask certain="0">
{player} asked what he can bring up with you. You are NOT certain what is open between you -
these are only matters you MIGHT have, from the quests you are part of:
- {prompt 1}
- {prompt 2}
Speak like somebody who is not sure: "I might have something about X", "come and ask me properly".
Never quote these word for word as if they were on offer, and never use {ACTION} from this list.
</what_you_can_ask>
```

The `certain="0"` form **cannot execute anything**: `lrgDlgGateItem()` already refuses an intent-mode
match against an empty pool by going to `lrgDlgMaybeOpen()`, whose gates are untouched, and the block
explicitly forbids the action.

### 7.3 (c) "What should I do next?" / "Where was I?"

`next` → `<your_quests>`, from CHIM's own `questlog` through the existing `lrgDlgQuestRows()` +
`lrgDlgCleanObjective()` (`lrg_speech.php:539-588`) — the same machinery `<shared_business>` uses, so
the alias-tag cleanup and the "current objective only, never a later stage, never a stage number"
rule come for free.

* **Spoken by the NPC** when `$q` is non-empty: only the quests **she is part of**.
* **Spoken by the Narrator** when the responding `HERIKA_NAME` is the Narrator or `$q` is empty: the
  player's active quests from `questlog`, newest row per quest, `bookkeeping_patterns` dropped, cap
  `quests.next.lines` (3).
* **No menu is opened and nothing is clicked.** This is a prompt block only.

```
<your_quests>
{player} asked where he stands. What his journal says right now:
- "{objective 1}"
- "{objective 2}"
Say it the way you would say it - what you know of it, what you would do. Never mention a stage,
a number or a step he has not been shown.
</your_quests>
```

### 7.4 (d) The corner hint

When a session's harvested list contains at least one **quest-bearing** entry (the E2(a) candidate
test, minus the initiative cooldowns), `quests.hint.enabled` is on and the game's `bQuestHint` is on,
the server emits, once per session per NPC:

```
<npc>|command|ExtCmdLRG_SelectTopic@ok=1;cid=…;npc=…;ref=…;do=noop;sid=<sid>;gen=<gen>;pos=-1;i=-1;
txt=;kind=hint;cost=0;res=;xp=0;stat=;take=0;ask=;vt=…;x=…;z=1;note=<text>
```

`note=` is the **already-shipping dormant key** (`LRG_Main.psc:532-535`), so this is the one-line
server change that comment predicted. Wording, ≤ 120 chars, no forbidden characters:
`"<Name> has something worth asking about"` — or, when the quest is known to the journal,
`"<Name> has something about <quest name>"`.

`bQuestHint` is carried game-side: the game shows the note only when its own toggle is on — lane B
adds the toggle test inside `HandleCommand`'s existing `cornerNote` branch, so an old server that
somehow sends a note cannot spam a player who turned it off.

### 7.5 (e) Her part in active quests, told to the LLM

**Game side (lane B, `LRG_Dialogue.QalCsv(Actor)`), using the API verified in §1.3:**

```
Alias[] al = PO3_SKSEFunctions.GetRefAliases(akNpc)
; for each alias, at most 6:
;   Quest q = al[i].GetOwningQuest()
;   skip when q == None, q.GetID() == "" or !q.IsActive()
;   name = al[i].GetName()          ; SKSE64 addition, verified
;   out += q.GetID() + ":" + Clip(CleanForWire(name), 24)
```

sent as `qal=` (W3) and `qgiver=` (W4) on `ev=facts`.

**Server side**, one clause inside the existing `<shared_business>` block (never a second injection):

* `qgiver=1` → `"You are the one who set {player} on this."`
* otherwise, for the first alias whose quest is in `$q`:
  `"In this, you are {alias name, de-camel-cased}."`

Both are **facts the NPC would know about herself**, never a spoiler about the quest, and they are
dropped entirely when the quest has no `questlog` row (the existing no-spoiler rule of
`lrgDlgQuestKnown()`).

---

## 8. E3 — ASSISTED-MODE POLISH

X1 may say sessions die. When a choice layer has to be handed back visible, the hand-back must be a
deliberate, announced, recoverable step — not a silent `GoManual()`.

### 8.1 The clean hand-back

Lane B adds `LRG_Dialogue.HandBack(string asWhy, bool abMayResume)`, which every hand-back path now
calls instead of `GoManual()` directly (the paths: `lethal`, `branch_show`, `assisted`, `x1-dead`,
`read-failed`, `hide-refused`, `unverified`, `key`, `watchdog`, `no-stored-x`):

1. **freeze** — `reqPickReady = false`, `ClearRequest(false)`, so nothing in flight can click after
   the menu is the player's;
2. **guard reset first, then unhide** — `LRG_DlgUI.Guard(false)` (which includes `GuardReset()`),
   then the existing `DoUnhide(asWhy)` with its `ShowList()` → `ForceListState()` escalation and its
   honest "press TAB" warning if the list is still off screen;
3. **corner note** when `bHandBackNote:Dialogue` is on:
   `Debug.Notification("LoreRim Glue: choose this one by hand")`;
4. **log** `handback sid=… why=… layer=… n=… kind=… note=… resume_armed=…`;
5. **wire** — the existing `ev=unhide` gains `layer= n= kind= resume=` (W5). One message, not two.
6. `manualWhy = asWhy`, `manualResume = abMayResume`, `dlgState = ST_MANUAL`.

### 8.2 The one short in-character line — and the honest limit

The round's prompt asks for "the NPC says one short in-character line". **There are two cases and the
plan treats them differently rather than faking one of them:**

* **Foreseen hand-back (the common one).** The server already knows *before* the LLM runs that this
  turn will hand back: `assisted` (two layers lost with this NPC), `iBranchInput = 2`, `crit = 2`, or
  `x1 = 2` from `ev=calib` with a closed layer live. `lrgDlgBusinessBlock()` then appends **one
  clause** and the model's own message *is* the line:

  ```
  You cannot settle this one by talking. Tell {player} plainly, in one short line, to choose for
  himself - and say nothing else this turn.
  ```

  The mute never fires on such a turn: `lrgDlgWillEmit()` already returns false for LETHAL and for a
  `meta` class, and `do=show` is emitted by the gate afterwards, so the line is spoken.

* **Unforeseen hand-back mid-session (X1 = dies, the layer was already parked).** There is **no LLM
  turn in flight** and one cannot be requested: `requestMessageForActor` runs `QueueInterruptNPC` on
  the target (`PHASE2_DESIGN` §2.4), which would end the very session whose menu we are handing back.
  So **no speech is produced**, and the plan says so plainly. What the player gets is the corner note
  plus the visible menu — which is the promise the feature actually makes. `assist.line` (config,
  default true) governs the foreseen case only.

### 8.3 Resume without a re-greet

`StepManual()` gains a resume branch, guarded by **all** of:

`manualResume` · `bResumeAfterChoice:Dialogue` · `sMenuless` · `!sDryRun` · `!inScene` · `crit != 2`
· `smartSafe` · the calibration gate green · `LRG_DlgUI.IsOpen()` · the layer **signature changed**
since the hand-back (i.e. the player really clicked something) · the new layer is not itself a
hand-back case (`crit != 2`, and not a closed layer while `iBranchInput == 2` or `x1 == 2`).

Then: `LRG_DlgUI.Guard(true)`, `LRG_DlgUI.Hide(sHideMode)` (assisted if it refuses — never a session
without a working restore), `HideCursor` per setting, `dlgState = ST_READING`, `sig = ""`,
`dResumes += 1`, log `resumed sid=… layer=… after=<ms>`, send **W6** `ev=resume`.

**No `Activate` is called, so there is no second greeting** — the session was never closed. That is
the whole point of doing it here rather than re-opening.

### 8.4 The bounded re-walk (D-19) — still off, now measured

`bRewalk` stays **0** by default and its behaviour is unchanged. What it gains is the counters the
design promised and nobody ever incremented:

* game side: `dRewalks`, `dRewalkAborts`, exposed as `DiagValue(9)` / `DiagValue(10)` and printed by
  `LogDiagnostics()`;
* server side: `$st['rewalks']`, `$st['rewalk_aborts']` incremented in the (existing, unchanged)
  continuation/re-walk path, and `layers_rewalked=` in `dlg diagnostics` stops saying
  `server-side` and prints the real number;
* one log line per replayed step: `rewalk step=<i>/<n> pos=<p> clean=<0|1> abort=<reason|->`.

`dLayersAssisted` is now incremented **only** by `HandBack()`, so it finally means what its MCM help
says.

---

## 9. E4 — HARDENING from the verify leftovers

### 9.1 The prompt index gets its own Postgres schema (verify report C9)

**The problem, confirmed live by the verifier:** CHIM's playthrough switch runs
`DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public` and clones a saved profile schema back
in — and `chim_profile_default` contains **no** Phase 2 tables. The 37,561-row index is load-order
data, not playthrough data, and it must not live somewhere a playthrough switch can drop it (or
clone 30 MB of it into every saved profile).

**Migration `007_lrg_prompt_schema.sql` (lane C), idempotent:**

```sql
CREATE SCHEMA IF NOT EXISTS lrg_index;
-- move an existing public copy rather than rebuilding 37k rows
DO $$ BEGIN
  IF to_regclass('public.lrg_prompt') IS NOT NULL AND to_regclass('lrg_index.lrg_prompt') IS NULL
  THEN EXECUTE 'ALTER TABLE public.lrg_prompt SET SCHEMA lrg_index'; END IF;
  IF to_regclass('public.lrg_prompt_layer') IS NOT NULL AND to_regclass('lrg_index.lrg_prompt_layer') IS NULL
  THEN EXECUTE 'ALTER TABLE public.lrg_prompt_layer SET SCHEMA lrg_index'; END IF;
END $$;
-- then the CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS bodies of 006, schema-qualified,
-- plus the new lrg_prompt_quest_idx of E2(b)
```

**Loader and lookups (lane C, `lrg_prompt_index.php`):** one helper
`lrgPromptSchema(): string` (config `dialogue.index.schema`, default `lrg_index`) and every
statement qualified through it — `lrgPromptRowsFor`, `lrgPromptPatterns`, `lrgPromptLayerCandidates`,
`lrgPromptByQuest`, `lrgPromptIndexStatus`, `lrgPromptLoad`. A **one-release fallback**: when a
qualified `SELECT` throws `undefined_table` and `public.lrg_prompt` exists, log once and read
`public` for the rest of the request, so an install that has not run the deploy yet still works.

`lrg_dialogue`, `lrg_memory`, `lrg_npc_state`, `lrg_romance`, `lrg_scene_state`, `lrg_turn`
**stay in `public`** — they *are* playthrough data and are cloned on purpose.

`lrgDlgEnsureSchema()` runs `007` alongside `005` / `006` and bumps
`LRG_DLG_SCHEMA_VERSION` to 2 (a new marker file, so the migration runs once on upgrade).

**Deploy pre-flight (`deploy_server.ps1`, after the index load step at `:113`):**

```
php lib/lrg_prompt_index.php status        # must print source=db and rows>0
psql … -c "select count(*) from lrg_index.lrg_prompt"   # must be > 0, else FAIL the deploy loudly
```

and a new anchor in the pre-flight list: `@{ f='lib\lrg_prompt_index.php'; t='function lrgPromptSchema'; w='the index schema helper (E4/C9)' }`.

### 9.2 The movement-command stall while a conversation hold is live

**The stall.** `bConvHold` holds the NPC with `SetDontMove(true)`. CHIM may then be told
`ComeCloser` / `FollowPlayer` / … — and `NoteChimAction()`, the game-side release, is **dormant for
CHIM's own catalog actions** because the DLL raises `CHIM_CommandReceived` only on the `ExtCmd`
branch (`LRG_Main.psc:1144-1150`). She cannot move, the command never completes, and the player sees
her refuse to follow. This is the `ComeCloser` / `FollowPlayer` risk from
`research/pt8-walkaway.md`.

**The fix, two halves, in `lrgDlgHideEndConversationOnHold()`'s sibling
`lrgDlgHoldMovementPolicy()`** — same placement (brace depth 0 in `functions.php`, **last**, after
both lanes have built their offer, so it only ever removes), same freshness rule
(`hold == '1'` and `_age <= hold_max_age_seconds`):

1. **The player did NOT ask her to move** (no `move_request_words` hit in this turn's utterance) →
   `lrgHideActions(hold_move_actions)`. The model cannot decide on its own to walk her off
   mid-conversation, which is the same reasoning that already withholds `EndConversation`.
2. **The player DID ask her to move** → the actions stay offered, and the Phase 2 post-gate
   (`lrgDlgPostProcessActions`) watches for one of them in the reply. When it sees one, it **prepends**
   the release line (W8) to `$out` so the game releases the hold in the same command batch, *before*
   CHIM's own movement action executes:

   ```
   $out[] = lrgDlgEmit($npc, ['do' => 'release', 'kind' => 'hold'], $cid, 'holdmove', null);
   $out[] = $line;   // CHIM's movement action, untouched
   ```

3. **Game side (lane B)**, in `CmdSelectTopic()` immediately after the `award` branch:

   ```
   if verb == "release"
       ; gated by the hold's own switch, NOT by bMenuless - do=release has nothing to do with the menu
       Main().ReleaseConvHold("the server admitted a movement command")
       XPush(x)
       m.ReportResult(asNpcName, asCommand, asParam, "Noted.")
       return true
   endif
   ```

   `ReleaseConvHold()` is already idempotent, safe at any time, from any path, and clears every field
   before touching the actor (`LRG_Main.psc:1093-1137`).

**Fallback when the server cannot reach a 401 game script:** half 1 alone is correct and safe — the
movement actions are simply not offered while she is held, and the hold releases itself on its own
window. `hold_release_on_move = false` in the config forces that behaviour permanently.

### 9.3 A test that every MCM id is read somewhere (the seven-dead-controls class)

New `tools/test_mcm_wiring.php` (lane C, runs on the same PHP as the other tests, reads lane B's
files without writing them):

1. parse `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` for every `id` (`key:Section`);
2. **assert every id has a line in `settings.ini`** under the matching `[Section]` — this alone
   catches the "a missing ini key reads as 0/FALSE" scar that bit `fOutroHold`, `fConvHoldFar` and
   `iQuestLines`;
3. **assert every id is read somewhere**, in this order:
   a. its exact `"key:Section"` string appears in at least one `.psc` under
      `glue/game/LoreRimGlue/Source/Scripts/` — the normal case;
   b. or the id is listed in the test's own **server-knob map**
      (`['bFreeChecks:Checks' => 'chk', 'bCheckHostility:Checks' => 'chk', 'iCheckBias:Checks' =>
      'bias', 'iQuestLines:Quests' => 'ql', 'bIntentOpen:Dialogue' => 'io', 'iBranchInput:Dialogue'
      => 'bi', 'bRewalk:Dialogue' => 'rw', 'iRewalkDepth:Dialogue' => 'rwd',
      'bPaidIntimacy:Intimacy' => 'paidok', 'fPriceMultiplier:Intimacy' => 'pm',
      'bQuestInitiative:Quests' => 'qi']`) **and** that wire key is (i) written by a `.psc` and
      (ii) read by a `.php` under `glue/server/lorerim_glue/`;
   c. or the id is in the test's explicit `GAME_ONLY` allow-list with a one-line reason
      (e.g. `bIaccToggle:Dialogue` = "on record only, decision D2").
4. print the id, the section, and which of a/b/c satisfied it; exit non-zero naming every id that
   satisfied none.

Run from `run_flows.php` as scenario `d53_mcm` as well, so the release gate covers it.

### 9.4 The remaining cheap leftovers

| From | Item | Lane | Action |
|---|---|---|---|
| server C11 | `res=` sent, read by nobody | C | Keep the key (the param order is fixed); PROTOCOL 10.4 gains "**diagnostic only** — the game ignores it" |
| server C8 | `test_prompt_index.php` reports 67, the build report says 71 | C | Re-state the real number in the release notes; the test prints its own count |
| game MINOR-9 | `bDlgDryRun` does not gate the probe | A + B | Unchanged **by design** (the probe is a tool, not a feature), but the MCM help now names the four presses that click or close for real, and the **new calibration never clicks** (CAL-1) |
| experience #7 | press 16 missing from the order, MCM mislabels 15/16 | B | Corrected in the MCM help (§3.4) and in the new evening (§15) |
| experience #10 | nothing says where a speech check is logged | C + B | The `check …` log line is named in `bFreeChecks`'s help and in the evening notes |
| experience #12 | the unmentioned cost of the very first business turn | — | Named in §15 ("the first time she meets a list she does not know, one extra paid reply and an 11–14 s pause; `bIntentOpen` off removes it") |
| experience, unverified | does TAB close a fully hidden menu? | A | Now answered **for free**: press 1 (`CalPressClick`) logs `tab_closed=` from its own guard window, and the emergency key is the guaranteed route either way |
| build plan | `meta.ini` version via the installer | C | **Already done** (`install_mo2.ps1:53-75`) — verify and record, no change |
| game MAJOR-4 | OStim commands during a live dialogue session | — | **Already handled**: `LRG_OStim.psc:2630-2634` consults `LRG_Dialogue.IsSessionOpen()`. Verify only |

---

## 10. E5 — LOREIM QUEST-MOD COVERAGE

### 10.1 The per-plugin coverage report

`tools/build_prompt_index.py` gains `--coverage` and `--coverage-out <path.csv>` (lane C). One row
per plugin that carries any DIAL record:

```
plugin, mod_folder, localized, readable, dial, info, player_prompts, winning_prompts,
indexed_rows, layers, patterns, checks_persuade, checks_intimidate, checks_bribe, other_kinds,
no_prompt_text, unresolved_string, lost_to_override, unreadable_reason, covered_pct
```

`covered_pct = indexed_rows / max(1, winning_prompts)`.

**The four separate reasons a prompt is not in the index must be separate columns** — collapsing them
is how a coverage report lies:

1. `unreadable_reason` — the plugin is a **localized Creation Club** plugin whose strings live in an
   LZ4 BSA (decision **D6**, 82 plugins, 22 speech topics): engine-only, on purpose;
2. `no_prompt_text` — the INFO genuinely has no player prompt (a response-only INFO);
3. `unresolved_string` — a `<strid>` did not resolve (should be 0; a non-zero here is a real bug);
4. `lost_to_override` — the record exists but another plugin wins it; **it is covered, by that other
   plugin**, and must never be counted as a gap.

The header already carries `unreadable`, `unresolved_strings`, `localized` and the whole `stats`
block (`build_prompt_index.py:959-989`), so the per-plugin split is a second accumulator over the
same pass — **no second scan, no extra run time.**

`--coverage` also prints the **top 20 by winning prompts** and a one-line total, and
`lrgPromptIndexStatus()` gains `coverage_rows` so `php lib/lrg_prompt_index.php status` can say
whether a coverage run has ever been made.

### 10.2 The ten largest quest / dialogue mods in this load order, named

From `research/p2_speech_tags.json` `per_plugin_dialogue` (the research scanner's per-plugin census —
this is the **pre-index** count, which is exactly why the `--coverage` run above has to produce the
authoritative per-plugin coverage). Vanilla masters excluded; 432 plugins carry DIAL, **335** carry at
least one player prompt, 25,302 player topics carry a prompt across the winner set, and the shipped
index holds **37,561 rows / 5,718 layers**.

| # | Plugin | Mod folder | player prompts | DIAL | INFO | Why it matters here |
|---|---|---|---|---|---|---|
| 1 | `Unofficial Skyrim Special Edition Patch.esp` | Unofficial Skyrim Special Edition Patch | **1,755** | 3,510 | 5,972 | the single largest source of *overridden* vanilla dialogue — if coverage is wrong here it is wrong everywhere |
| 2 | `Katana.esp` | Katana - Journey in the Shadows | 1,021 | 1,964 | 7,621 | a full quest mod; 7,621 INFOs behind 1,021 prompts = heavy layering |
| 3 | `Inigo.esp` | INIGO | 856 | 2,049 | 7,520 | follower + command framework: the R12 tail case |
| 4 | `Andrealphus Jobs Overhaul - MASTER.esp` | Andrealletius' Jobs Overhaul (AJO) | 844 | 848 | 1,366 | 830 topics repeating generic prompts → **0 %** prompt-alone uniqueness; the layer tier carries it, and it is on `filler_patterns` |
| 5 | `GORE.esp` | Gore - A Companion Mod | 735 | 1,202 | 2,890 | companion quest content |
| 6 | `HLIORemi.esp` | Remiel - Custom Voiced Follower | 691 | 3,252 | 5,669 | 3,252 DIAL records — the largest topic count of any follower here |
| 7 | `Vigilant.esm` | VIGILANT (English) | 663 | 1,011 | 1,224 | a large standalone quest line, readable (not localized) |
| 8 | `Journey to Baan Malur.esp` | Journey to Baan Malur and Morrowind | 514 | 567 | 1,003 | new-land quest content |
| 9 | `FDE Jenassa.esp` | Follower Dialogue Expansion - Jenassa | 502 | 556 | 1,053 | the FDE family (Aela 356, Illia 291 …) as a class |
| 10 | `Meridia.esp` | Meridia's Order ESMIFIED | 497 | 970 | 2,033 | daedric quest line |

**Named just below the ten, because the in-game tests already point at them:** `Lucien.esp` (488),
`ForgottenCity.esp` (445 — T7's back-out entries), `moretosaywhiterun.esp` (378),
`Wyrmstooth.esp` (373), `FDE Aela.esp` (356), `Missives.esp` (345 — the CMP-M4 "position 19" case),
`SeranaDialogueExpansion.esp` (300).

**The gate for this round:** the `--coverage` run must show `covered_pct >= 0.95` on every one of the
ten, with `unresolved_string == 0`. Anything below that is a named gap in the lane's report.

### 10.3 The ten-NPC sample `test_prompt_index.php --db` must hit

New `tests` fixture `tools/fixtures/lrg_quest_npcs.json` (lane C) plus assertions under `--db`. Each
row: NPC, the quest EditorID(s), the winning plugin, and the exact claim asserted.

| # | NPC | Quest / topic key | Winner | Asserted |
|---|---|---|---|---|
| 1 | **Nelacar** | `DA01NelacarIntimidate` `00024663`, `DA01NelacarBribe` `000243BC` | Skyrim.esm | `kind=intimidate` (fn 655) and `kind=bribe` (fn 654) — both byte-verified by `pt8-verify-server` |
| 2 | **Borgakh the Steel Heart** | `DialogueMorKhazgurBorgakhPersuade` `0006F7D2` | **LoreRim - Dialogue Patch.esp** | `kind=persuade`, `compound=1`, `amulet=1`, both `SpeechAverage` and `SpeechVeryHard` listed — **a Requiem/LoreRim-changed record, not vanilla** |
| 3 | **Ghorbash the Iron Hand** | `DialogueDushnikhYalGhorbash*` | LoreRim - Dialogue Patch.esp | the **PNAM inversion** pair: `unreachable=1` on the success INFO (decision D8(b), report only) |
| 4 | **The Caller** | `MG03CallerBookPersuade` `0001A0B3` | **Immersive Speech Dialogues.esp** | `kind=persuade variant=success scripted=1 amulet=1`, `SpeechAverage`, `ENAM 0x2001` Goodbye |
| 5 | **Brynjolf** | `TG00*` | Skyrim.esm / USSEP | a **root** list: `toplevel=1` on ≥ 3 prompts, `journal=1` |
| 6 | **A Whiterun gate guard** | `DGIntimidate*` / the `(Intimidate)` tag rows | Skyrim.esm / USSEP | one of the **54 tag-only rows** (`kind=''` in the index) is rescued to `check` by `lrgDlgKindFromTag()`; and a `DGCrime*` row resolves `crit=2` **after** `lrgPromptEscalate()` |
| 7 | **Adrianne Avenicci** | `MS11*` / the Whiterun freeform sword delivery | Skyrim.esm / USSEP | T4's simple live quest: every prompt of the layer resolves, `unindexed = 0` |
| 8 | **Inigo** | `InigofollowerDialogue` | Inigo.esp | a long list: `shared` is computed, `filler_patterns` do **not** swallow it, and the layer tier resolves a repeated prompt |
| 9 | **An AJO job giver** | `ANDR_AJO_Quest*` | Andrealphus Jobs Overhaul - MASTER.esp | prompt-alone identification fails (0 % unique) and **the layer fingerprint resolves it** — the direct test of verify report C4 |
| 10 | **A Missives board giver** | `Missives*` | Missives.esp | an entry beyond `iMaxEntries` is still in `tail` and still rankable (CMP-M4) |
| *opt.* | *a Forgotten City NPC* | `ForgottenCity*` | ForgottenCity.esp | a **back-out** entry classifies as `class=back` (T7) |

Each assertion is written so it **names the row it could not find** on failure, because a fixture that
only says "expected 10, got 9" is useless when the load order changes.

### 10.4 Fixing the gaps the report exposes

**Cheap, do them this round:**

* the missing `quest` index (`lrg_prompt_quest_idx`) — already in migration 007 for E2(b);
* any plugin where `unresolved_string > 0` — that is a string-table bug in the reader, not a data
  gap, and it is fixed at the source;
* any check-kind the census finds that is **not** in `check_kinds` — the builder must never assume
  the vanilla three (owner addendum 5a); the census output already exists, the coverage report just
  makes a new kind impossible to miss;
* a `covered_pct` below 0.95 caused by the **DIAL subtype filter** rejecting a subtype that really
  does carry player prompts (`check_subtype_rejected` in the header names them).

**List, do not fix:**

* the **82 localized Creation Club plugins** (22 speech topics) — decision **D6** stands: engine-only.
  Parsing their LZ4 BSAs needs an LZ4 module inside WSL and the owner has not been asked;
* `Requiem - Creation Club.bsa` is **compressed and was never scanned** (`PHASE2_DESIGN` §5.1
  caveat) — same class, same answer;
* any plugin the coverage run marks `readable = false` for a new reason: report it, change nothing.

---

## 11. LANE A — GAME-UI (`LRG_DlgUI.psc`, `LRG_DlgProbe.psc`)

### 11.1 `LRG_DlgUI.psc` — the only change is a second store

Add, next to the existing `SInt` / `SSetInt` family, a parallel set under the key prefix
`"LRG.cal."` so the calibration cannot collide with the UI script's own `LRG.du.*` state:

```papyrus
int   Function CalInt(string asKey, int aiDefault) Global
Function CalSetInt(string asKey, int aiValue) Global
float Function CalFloat(string asKey, float afDefault) Global
Function CalSetFloat(string asKey, float afValue) Global
string Function CalStr(string asKey, string asDefault) Global
Function CalSetStr(string asKey, string asValue) Global
Function CalClear() Global          ; every key this project writes, named explicitly
```

Docstrings under 300 characters; the long explanation goes in the existing `;/ … /;` block at the top
of the file. **No UI function is added, changed or removed** — every signature stays frozen for the
DLL seam.

`CalClear()` lists its keys explicitly (`rm cm lp fam route apd apdr timer hide guard park rb st x1
x1s x1d x1u reopen items plat row col inj pay ms3 ms4 tail flash hidems actms actry poke runs`)
rather than looping, because StorageUtil has no prefix enumeration.

### 11.2 `LRG_DlgProbe.psc` — the calibration engine

New public surface (this is the contract lane B builds against; it must not change after the lanes
split):

```papyrus
; --- the driver's hooks -------------------------------------------------
Function CalArm(int aiApd, int aiFam, int aiItems, int aiPlat, bool abHidden, bool abGlueOpened)
Function CalPoll(int aiState, int aiCount, int aiTimerId, string asSubtitle)
Function CalLayer(int aiTotal, int aiHead, int aiReadMode)
Function CalPending(int aiBefore, int aiAfter, bool abSigChanged)
Function CalSpeech(int aiWho, float afAt)        ; 0 = player, 1 = npc
Function CalClose(string asWhy, int aiLayer, bool abPending, bool abAnyClick)
Function CalPayload(int aiChars, bool abPart2, int aiTailChars)
; --- the active pass ----------------------------------------------------
bool Function CalActiveWanted()                  ; armed, budget left, and this session is eligible
Function CalActiveRun()                          ; runs ONE test, always gives the menu back
Function CalActiveDisable(string asWhy)
; --- readers ------------------------------------------------------------
int Function CalGet(string asKey, int aiDefault)
bool Function CalGreen()
string Function CalMissing()                     ; "" when green
string Function CalStatusText()                  ; the MCM's read-only line, <= 200 chars
string Function CalWire()                        ; the raw body of ev=calib's k=
Function CalLogSummary()
Function CalForget()
; --- the manual presses -------------------------------------------------
Function CalPressClick(bool abOtherRoute)
Function CalPressReopen()
```

`RunStep()` gains presses **33** (`CalPressClick(false)`), **34** (`CalPressClick(true)`), **35**
(`CalPressReopen()`), **36** (`CalForget()`), with `StepName()` labels, so the `iProbePress` slider
remains a guaranteed route if MCM Helper's `CallFunction` turns out not to reach `LRG_MCM`. The
slider's MCM range becomes **0–36**.

**Rules lane A must keep:** no `LRG_Dialogue` reference anywhere in this file; no UI call except
through `LRG_DlgUI`; `readOnly` stays true for every passive path and for `CalPressReopen`; the three
helpers that can perturb a session (`DoClick`, `DoCloseClean`, `DoCloseForce`) keep their refusal
matrix, and `CalPressClick` goes through `DoClick` like every other click.

### 11.3 Lane A gate

* `tools/compile.ps1` prints **OK** (no `.pex` string over 500 chars, zero errors, zero warnings).
* A dry table in the lane's report: every calibration key, which probe writes it, passive or active,
  and its green condition.
* A hand-traced walk-through proving `CalActiveRun()` calls `Unhide()` on **every** exit path,
  including `Hide()` returning false and the menu closing mid-test.
* `grep -c "UI\." LRG_DlgProbe.psc` = **0**.

### 11.4 The MCM status text (lane A produces it, lane B displays it)

`CalStatusText()` returns one of:

```
green - 11 of 11 answered (reading 3, counting 1, route B, timer per line, hide ok)
not yet - 8 of 11: missing hide round trip, click route, vanilla menu after a hide
blocked - Smart Talk bSkipImmediateOnInput must be 0 in Data/SKSE/Plugins/SmartTalk.ini
watching - sessions survived the player speaking 2 of 3 times (needs 3)
```

Lane B binds it in `LRG_MCM` through a plain `string Property CalStatus Auto` refreshed in
`Event OnConfigOpen()` (and in `OnSettingChange`), because a property with only a getter is not a
shape MCM Helper is documented to accept. `tools/stubs/MCM_ConfigBase.psc` gains
`Event OnConfigOpen()` so the compile still succeeds without the real parent.

---

## 12. LANE B — GAME-DRIVER + INTEGRATOR

**Files:** `LRG_Dialogue.psc`, `LRG_Main.psc`, `LRG_Profile.psc`, `LRG_MCM.psc`,
`MCM/Config/LoreRimGlue/config.json`, `MCM/Config/LoreRimGlue/settings.ini`,
`tools/stubs/MCM_ConfigBase.psc`. **`make_esp.py` is not edited.**

| # | Task | File | Detail |
|---|---|---|---|
| B1 | `CurrentVersion` 401 → **500**, header comment updated | `LRG_Main.psc` | `:20` |
| B2 | Forward speech to the driver for X1 | `LRG_Main.psc` | one `live.NoteSpeech(…)` call in each of `OnChimSpeechStarted`, `OnChimSpeechStopped`, `OnChimTextReceived` (`:614`, `:641`, `:667`). Cheapest test first: `if live && live.IsSessionOpen()` |
| B3 | Gate the corner note on `bQuestHint` | `LRG_Main.psc` | inside the existing `cornerNote` branch (`:532-535`) — the note is shown only when `SettingBool("bQuestHint:Quests", true)` |
| B4 | `do=release` | `LRG_Dialogue.psc` | new branch in `CmdSelectTopic()` right after the `award` branch (§9.2 step 3). Gated by the hold's own switch, **not** by `bMenuless`; dry-run does **not** block it (releasing a hold is always safe) |
| B5 | The seven calibration hooks | `LRG_Dialogue.psc` | §6.1's table — each is one line, each is `if p` guarded, each passes values the driver already has |
| B6 | `auto` resolution + the dry-run refusal | `LRG_Dialogue.psc` | §6.5 — inside `ReadSettings()`, plus the `CALIB SUMMARY` line in `SelfTest()` |
| B7 | `HandBack()` + resume + `ev=resume` | `LRG_Dialogue.psc` | §8.1–8.3; every existing `GoManual(<hand-back reason>)` call site routed through it. `GoManual()` itself stays, for the non-hand-back reasons |
| B8 | `ev=unhide` gains `layer= n= kind= resume=` | `LRG_Dialogue.psc` | `SendUnhide()` |
| B9 | `ev=calib` sender + `cal=` on `ev=open` | `LRG_Dialogue.psc` | `SendCalib(src)` — built from `p.CalWire()`, `k=` LAST and raw, gated on `sWire` like every other sender |
| B10 | `qal=` / `qgiver=` on `ev=facts` | `LRG_Dialogue.psc` | new `QalCsv(Actor)` using `PO3_SKSEFunctions.GetRefAliases` + `Alias.GetOwningQuest/GetName` (§1.3). Bounded: ≤ 6 aliases, ≤ 24 chars each, `CleanForWire`d |
| B11 | `qi=` on the snapshot | `LRG_Profile.psc` | one line beside the existing `ql=` (`:482-486`), with the same "a missing key reads as 0" note |
| B12 | Two new diagnostics counters | `LRG_Dialogue.psc` | `dRewalks` / `dRewalkAborts` → `DiagValue(9)` / `(10)`; `dResumes` folded into the `LogDiagnostics()` line; `dLayersAssisted` now incremented only by `HandBack()` |
| B13 | MCM page + all new keys | `config.json`, `settings.ini` | §3 in full. **Every new key gets a `settings.ini` line, zeroes included** |
| B14 | MCM buttons + status property | `LRG_MCM.psc`, stub | `OnConfigOpen()` refreshes `CalStatus`; five `CallFunction` targets forwarding to `LRG_DlgProbe`; `OnSettingChange` also re-runs `p.Maintenance()` for `bCalibActive:Calib` and `bProbe:Dialogue` |
| B15 | `iMaxEntries` range 0–24, `iProbePress` range 0–36, help corrections | `config.json` | §3.4 |

**Lane B gate:** `compile.ps1` prints **OK**; `tools/test_mcm_wiring.php` passes (§9.3); a table in
the lane's report of every new MCM id with its `settings.ini` line and the script or wire key that
reads it; and a hand-traced proof that the emergency key still gives back a menu in **every** state,
hand-back and resume included.

---

## 13. LANE C — SERVER + INDEX

**Files:** everything under `glue/server/lorerim_glue/`, `tools/build_prompt_index.py`,
`tools/test_*.php`, `tools/flows/**`, `tools/deploy_server.ps1`, `tools/install_mo2.ps1`,
`glue/PROTOCOL.md`.

| # | Task | File | Detail |
|---|---|---|---|
| C1 | `manifest.json` `version` → **0.5.0** | `manifest.json` | |
| C2 | Accept `ev=calib`, `ev=resume`; store, log | `lib/lrg_dialogue.php` | two new `case`s in `lrgDlgOnEvent()`. `ev=calib` also writes `lrgDlgPut('*install*', ['calib' => …])`, because calibration is a property of the install, not of an NPC; the per-NPC row keeps a copy of `gate`/`x1` for the prompt decisions |
| C3 | Read `cal=`, `qal=`, `qgiver=`, `qi=` | `lib/lrg_dialogue.php` | `lrgDlgFactsFrom()` for `qal`/`qgiver`; `lrgDlgMcmFromSnapshot()` for `qi`; `cal=` parsed into `$st['cal']` |
| C4 | E2(a) initiative | `lib/lrg_dialogue.php` | `lrgDlgInitiativeCandidate()` + the `<she_may_raise>` block in `lrgDlgVolatileGuidance()` + the three state keys (`init_at`, `init_said`, `init_session`) |
| C5 | E2(b)(c) recogniser + two blocks | `lib/lrg_dialogue.php`, `lib/lrg_speech.php` | `lrgDlgAskKind()`, `<what_you_can_ask>` (both certainties), `<your_quests>`; `lrgDlgQuestRows()` reused unchanged |
| C6 | E2(b) index lookup by quest | `lib/lrg_prompt_index.php` | `lrgPromptByQuest()`, test-seam aware like every other lookup |
| C7 | E2(d) the hint | `lib/lrg_dialogue.php` | `do=noop;note=…`, once per session per NPC, config `quests.hint` |
| C8 | E2(e) alias clause | `lib/lrg_speech.php` | inside the existing `<shared_business>` composer, never a second injection |
| C9 | E3 the foreseen hand-back line | `lib/lrg_dialogue.php` | one clause in `lrgDlgBusinessBlock()` when `assisted` / `bi == 2` / `crit == 2` / `cal.x1 == 2` |
| C10 | E4(a) migration 007, schema helper, loader, fallback | `migrations/007_lrg_prompt_schema.sql`, `lib/lrg_prompt_index.php`, `lib/lrg_dialogue.php` | §9.1; `LRG_DLG_SCHEMA_VERSION` → 2 |
| C11 | E4(b) hold / movement policy | `lib/lrg_dialogue.php`, `functions.php` | `lrgDlgHoldMovementPolicy()` at brace depth 0, **after** `lrgDlgHideEndConversationOnHold()`; the post-gate prepend of `do=release` |
| C12 | E4(c) the MCM-wiring test | `tools/test_mcm_wiring.php`, `tools/flows/scenarios/d53_mcm.php` | §9.3 |
| C13 | E5 coverage | `tools/build_prompt_index.py` | `--coverage`, `--coverage-out`; four separate gap columns |
| C14 | E5 the ten-NPC fixture | `tools/fixtures/lrg_quest_npcs.json`, `tools/test_prompt_index.php` | asserted under `--db`, each failure names the missing row |
| C15 | Deploy pre-flight | `tools/deploy_server.ps1` | the `lrg_index` row-count check + the new anchor; **fail loudly**, do not warn |
| C16 | `install_mo2.ps1` | — | **verify only**, `meta.ini` version is already wired |
| C17 | `PROTOCOL.md` → **v0.5** | `PROTOCOL.md` | §2's table verbatim; 10.4 gains "`res=` is diagnostic only"; a new 10.13 "the calibration wire"; 10.14 "the hold movement policy" |
| C18 | New flow scenarios | `tools/flows/scenarios/` | §14 |

**Lane C gate:** `php tools/test_gates.php`, `test_intent.php`, `test_phrases.php`,
`test_scene_index.php`, `test_dialogue.php`, `test_prompt_index.php` **and** `--db`,
`test_mcm_wiring.php` all exit 0; `php tools/flows/run_flows.php --strict` green on all **61 + 6**
scenarios; `deploy_server.ps1` anchor pre-flight passes with one match per anchor; and the coverage
report for the ten mods of §10.2 pasted into the lane's report.

---

## 14. Tests

**New offline flow scenarios (lane C, `tools/flows/scenarios/`):**

| id | Asserts |
|---|---|
| `d50_wire05` | **Both directions of additivity.** (a) a 0.5 server fed `ev=calib`, `ev=resume`, `cal=`, `qal=`, `qgiver=`, `qi=` handles them; (b) the same server fed a **script-401** payload with none of those keys behaves exactly as 0.4.1 did (byte-compare the emitted command and the prompt blocks); (c) a **0.4 server stub** fed the 0.5 payloads logs "unknown ev … this is not an error" and emits nothing |
| `d51_initiative` | The candidate test refuses `check`/`pay`/`commit`/`meta`/`crit`/`walkaway`; the cooldown, the once-per-topic list and the per-session cap all hold; the block is emitted **at most once**; a second turn within `gap_seconds` emits nothing; execution still goes through match → confirm → select |
| `d52_holdmove` | `hold_move_actions` is **equal** to `LRG_Main.CONV_MOVE_ACTIONS` (parsed out of the `.psc`); with `hold=1` fresh and no movement words, the movement actions are hidden; with movement words, they are **not** hidden and a `do=release` line is emitted **before** the movement line; with `hold` absent or stale, nothing changes |
| `d53_mcm` | `test_mcm_wiring.php`'s assertions as a scenario, so the release gate covers them |
| `d54_ask` | `lrgDlgAskKind()` on 20 phrasings + 10 near-misses; `<what_you_can_ask certain="1">` uses the cache verbatim; with an empty cache it uses `lrgPromptByQuest` and emits `certain="0"` with the "never quote these as if offered" clause; `<your_quests>` never prints a stage number and never a non-displayed objective |
| `d55_handback` | Every hand-back reason produces `ev=unhide` with `layer/n/kind/resume`; the foreseen-hand-back clause appears for `assisted`, `bi=2`, `crit=2` and `cal.x1=2` and for nothing else; `lrgDlgWillEmit()` still returns false on those turns so the line is spoken |

**Existing suites that must stay green:** `test_gates.php`, `test_intent.php`, `test_phrases.php`,
`test_scene_index.php`, `test_dialogue.php`, `test_prompt_index.php` (+ `--db`),
`run_flows.php --strict` (61 scenarios). Read each file's own header for how to run it; the WSL
staging rule of the round's environment applies unchanged (stage to `$env:TEMP\lrg_test`, LF line
endings, `wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/.../<script>.sh`).

**In-game tests (owner), mapped to `PHASE2_DESIGN` §7.4:** T1 is **replaced** by §15's evening. T2
(topic-dump parity) and T3 (dry-run conversation) are unchanged and are what the evening ends with.
T4–T13 are unchanged and still come after the gate goes green.

---

## 15. The owner's evening AFTER this round

> **Play normally for a few conversations. Then two presses.**

| Step | What the owner does | How long | What it answers |
|---|---|---|---|
| 0 | Bind **"Open vanilla dialogue (emergency)"** on the Keys page if it is not bound. *(Unchanged, and still the one thing to do first.)* | 10 s | the escape hatch |
| 1 | Set `SmartTalk.ini`'s `bSkipImmediateOnInput`, `bHoldToSkip`, `bSkipOnInteraction` to **0**. Never touch `iPapyrusHandle` (leave it at 3). | 1 min | the D-21 precondition; the Calibration page says `blocked` until this is done |
| 2 | **Play.** Talk to people the normal way, with E, for three or four conversations — an innkeeper with a long list among them. Nothing is hidden, nothing is clicked, nothing looks different. | as long as it takes | counting, reading, the progress timer, the menu layout, row-vs-position, read timing, payload size, the pristine `ALLOW_PROGRESS_DELAY`, quest colours, the parked push, **and X1** |
| 3 | Calibration page → **"Calibrate on the next few conversations"** ON. Have three more ordinary conversations. Each shows *"calibrating - one moment"* and *"menu back"* in the corner. | 2 min | the state read-back, the guard-leak reset, injection, and **the hide round trip** |
| 4 | **Press 1 — "The click test"**, standing in front of an innkeeper. Mash the mouse, E, Space and the wheel for the three seconds it asks for. It then chooses her last topic for real, once. | 15 s | the input guard **and** the click route |
| 5 | **Press 2 — "The menu-still-works test"**. Close that conversation, open a normal one with E on anybody, press, and click a topic with the mouse inside the three seconds. | 15 s | **R1** — that ordinary vanilla dialogue is still clickable |
| 6 | Read the Calibration page's status line. **Green** → turn **Dry run** off when ready. **Not yet** → it names exactly what is missing and which optional press answers it. | 10 s | |
| 7 | Then the unchanged T2 / T3: a few topic dumps, then `bMenuless` ON with dry run still ON, and send back the `WOULD CLICK` lines with what you actually said. | one session | the real acceptance test |

**Optional presses, only if the status line asks for them:** "the other click" (if the chosen route
came back red), "the held-mouse test" (only if Smart Talk cannot be set to 0/0/0), "the force-close
test" (settles a design question; nothing in the feature uses it).

**Two things to say plainly in the notes:**

* **The first business turn with an NPC whose list the glue has never seen costs one extra paid
  reply and a pause of roughly 11–14 s.** Everything else the feature sends is answered without an
  LLM call. `bIntentOpen` off removes that cost at the price of her being vaguer on first contact.
* **A speech check is logged as** `check npc=… kind=… diff=… roll=… result=…` in
  `lorerim_glue.log` — if nothing appears, the phrase was not recognised as an attempt, which is a
  recogniser gap and not a broken feature.

Counted against the old evening: **21 slider-and-press rows → 2 presses**, and the three presses that
used to need a specific NPC, a two-minute wait or a mid-response mouse-hold are gone.

---

## 16. Risks, and what each lane must watch

| # | Risk | Who | Mitigation, in the plan |
|---|---|---|---|
| R1 | **An active test leaves the menu hidden.** The one failure the whole feature cannot survive. | A | `Unhide()` on every exit path; ≤ 200 ms measured and logged; a single overrun disables the active pass for the session; `LastUnhide() == 2` is red; the emergency key and press 32 both still give the menu back; the active pass never runs on a LETHAL or scene session |
| R2 | **Passive calibration adds frame cost to every conversation.** | A + B | Per-poll cost is ≤ 1 extra delayed native and only every 5th poll; the per-layer census is ~11 reads **once per layer**; every collector short-circuits the moment its key is answered, so a calibrated install pays nothing |
| R3 | **`CalGreen()` locks the owner out of the feature.** | B | `bCalibOverride:Calib` exists, is documented in the MCM help and in the notes, and the forced state is logged once with the missing keys named |
| R4 | **`do=release` reaches a 401 script** and produces an error notification. | C | It is additive-safe (one `Error: unknown command` funcret) and the server does not depend on it: `hold_hides_movement` alone is correct. `hold_release_on_move = false` disables the verb entirely |
| R5 | **The movement hide makes her refuse to follow.** | C | Half 2 exists precisely for that: when the player's own words ask her to move, the actions stay offered and the hold is released first |
| R6 | **NPC initiative turns into nagging.** | C | Off by default; once per topic ever; one per session; a 10-minute gap; only `plain` / `service` entries; the slot is consumed when the block is **emitted**, not when she speaks |
| R7 | **The approximate "what can I ask you" list leaks quests she should not know about.** | C | Only quests the game already said she is part of (`q`, from `GetActiveAssociatedQuests`); `toplevel = 1`, `crit = 0`, `shared <= 20`; the block forbids quoting and forbids the action; the existing `lrgDlgQuestKnown()` no-spoiler rule still gates every quest **name** |
| R8 | **Moving the index tables breaks a live install mid-session.** | C | `ALTER TABLE … SET SCHEMA` (a move, not a rebuild), a one-release `public` fallback on `undefined_table`, and a deploy pre-flight that fails loudly rather than letting the server run unlabelled |
| R9 | **X1 answers "dies" and deep branches stay assisted.** | — | Accepted and priced (design §2.6: ~86 % of branching moments). E3 makes that path clean instead of pretending otherwise. Only a DLL fixes it (§1.4), and nothing here tries to |
| R10 | **MCM Helper's `CallFunction` does not reach `LRG_MCM`.** | B | The `iProbePress` slider keeps every press reachable (numbers 33–36 added); the buttons are removed from the notes if lane B cannot prove them in game |
| R11 | **A lane edits another lane's file.** | all | The ownership table of §0.1, the `deploy_server.ps1` anchor pre-flight, and the `d52_holdmove` test that compares a PHP list against a Papyrus constant |

---

## 17. Build order and gates

| Step | Lane | What | Gate to the next step |
|---|---|---|---|
| 1 | **A** | `LRG_DlgUI` cal store; `LRG_DlgProbe` passive collectors + readers (`CalGet/CalGreen/CalMissing/CalStatusText/CalWire`) | `compile.ps1` **OK**; the key table in the lane report; zero `UI.` in `LRG_DlgProbe` |
| 2 | **C** | migration 007 + schema helper + loader + deploy pre-flight; `ev=calib` / `ev=resume` intake; PROTOCOL v0.5 | `test_prompt_index.php --db` green against `lrg_index`; the deploy's row-count check passes |
| 3 | **B** | the seven hooks, `auto` resolution, the dry-run refusal, `CurrentVersion 500`, the MCM page | `compile.ps1` **OK**; `test_mcm_wiring.php` green |
| 4 | **A** | the active pass (A1–A5) and the two manual presses | the hand-traced "gives the menu back on every path" proof |
| 5 | **C** | E2 (a–e), E3's server half, E4(b), E5's coverage + fixture | `run_flows.php --strict` green on 61 + 6 |
| 6 | **B** | `HandBack()` + resume + `do=release` + `qal=` + `qi=` | the emergency-key walk-through in every state including hand-back and resume |
| 7 | owner | **the evening of §15**, then unchanged T2 / T3 | the Calibration page says green, and the `WOULD CLICK` lines match what was said |
| 8 | owner | only then: dry run off → T4 → T6 → T5 → T10, T11 → T8, T9, T12, T13; then `iEngineOpen`, `iSceneGate`, `iCritical` **one at a time, each judged on its counter** | unchanged from `PHASE2_DESIGN` §11 steps 6–7 |

**"Done" for this round** = every lane gate green, the calibration reaches green on the owner's own
install inside one short evening, and `bMenuless` / `bDlgDryRun` still ship at **0 / 1** so nothing
changes for anybody who does not go looking.

---
---

# PART II — the owner's v0.5 addenda (item 9): services, latency, fact-locking

> Part I (§0–§17) was written against the round's headline, *"improve and expand upon menuless
> questing"*. Part II is written against the owner's own words of **2026-09-22 ~03:30**
> (`OWNER_ADDENDA` item 9), which arrived after it and therefore **win** wherever the two disagree:
>
> *"specifically the expanded scope of bartering, training, inns, carriages, guard / crime dialogue
> (if this isnt alr implemented in CHIM) all the while being compatible with lorerim. also
> importantnly we need to improve upon latency - the timeliness of the response from the npcs voice
> in game (also i have grok set to most of the LLMs right now and i might end up changing that
> depending on what you suggest - maybe swithc over to deepseekv4 flash or see what u have to say).
> also important: consider the core's fact-locking and condition truthfulness."*
>
> Three new lanes' worth of work: **E6 service dialogue**, **E7 fact-locking and condition
> truthfulness**, **E8 latency**. They use the same three lanes, the same ownership table (§0.1) and
> the same rails (§0.3). Nothing in Part II needs a DLL either (§18.8).

---

## 18. E6 — SERVICE DIALOGUE as a first-class menuless kind

### 18.1 First, the owner's parenthesis: *"if this isnt alr implemented in CHIM"*

**It is — as shortcut actions — and that is exactly the problem.** Read from the installed
`HerikaServer` catalog (`lib/core/action_catalog.php`, `data/core_action_seed.sql`), here is every
service action CHIM ships, with the preconditions CHIM itself enforces:

| CHIM action | Display name | CHIM's own `requirements` (verified) | What it does | Cost |
|---|---|---|---|---|
| `OpenInventory` | `Trade_Items` | **none at all** | opens the barter/trade window | — |
| `OpenInventory2` | `Accept_Gift` | none | gift window | — |
| `RentRoom` | `Rent_Room` | `npc_factions_any: [0005091B]` (innkeeper), not dead/unconscious/sleeping | rents a room | flat `cost_gold`, **default 10** |
| `HireCarriage` | `Hire_Carriage` | `npc_name_in_action_config_list: allowed_npc_names`, not dead/…/combat | fast-travels the player | flat `cost_gold`, **default 20** |
| `HireFerry` | `Hire_Ferry` | same shape, `allowed_npc_names` | fast-travels the player | flat, **default 50** |
| `Training` | `Training` | `requires_training_service` → `npc_extended['class']['teaches']` non-empty (`action_catalog.php:1851`) | opens the training menu (`dispatch: rolecommand`) | engine's |
| `GiveGoldTo` | `Give_Gold_To` | none | moves gold, `target` + `item` = amount | — |
| `Brawl` | `Brawl` | none | starts a fist fight | — |
| `AddBounty` | `Add_Bounty` | `npc_factions_any: [00086EEE, 00028848, 00028849]` (guard factions) | adds a bounty, `target` = crime type enum | — |
| `PayBounty` | `Pay_Bounty` | same guard factions | pays the bounty, confiscates stolen goods | — |
| `ArrestPlayer` | `Arrest_#PLAYER_NAME#` | same | submit-or-resist prompt | — |
| `ForgiveCrime` | `Forgive_Crime` | same | clears the bounty outright | — |

So **all five families the owner names already exist in CHIM**. The glue's job is not to re-implement
them; it is to stop them from being the *only* way, because on **this** load order each one is wrong
in a specific, checkable way:

| # | What CHIM's shortcut does | What LoreRim actually has | Evidence |
|---|---|---|---|
| S1 | `HireCarriage`'s destination `enum` is **19 hard-coded vanilla destinations**, and `allowed_npc_names` defaults to **8 vanilla drivers** (Bjorlam, Alfarinn, Kibell, Sigaar, Thaer, Engar, Gunjar, Markus) | **CFTO.esp** (Carriages & Ferries Travel Overhaul) is in the load order with its own `KmodFastTravelCarriage*` / `KmodFastTravelFerry*` topics and destinations CHIM has never heard of — e.g. `Solitude Lighthouse.` — plus `Better Carriage Destinations.esp`, `Skyking Carriages - BBSA.esp`, `WaitCarriageInns.esp`, `CarriageAndStableDialogues.esp` | index query: `plugin='CFTO.esp'` returns `KmodFastTravelFerrySolitude`, `…Morthal`, `…Windhelm`, `Solitude Lighthouse.`, and free-local variants `KmodFastTravelCarriageFreeLocal*` |
| S2 | charges its **own flat number** (10 / 20 / 50 gold) | the real price is a global the modlist sets: `<Global=KmodFerryCost>` (CFTO), `<Global=BYOHHPCostCarriage>` (HearthFires), `<Global=DLC1FerryCostLarge>` (Dawnguard), `<Global=VSVGLO_CarriagePrice>` (CC Farming) | **774 index rows** carry a `<Global=…>` token in their text |
| S3 | `RentRoom` is a flat 10 gold and one generic "room rented" | innkeeper prices differ, and `Xtended Stay.esp` changes what renting means | 18 index rows on `rent … room` / `room for the night`; `Xtended Stay.esp` has 7 room/bed rows |
| S4 | `ForgiveCrime` **clears the bounty with no check at all** — the LLM simply decides | the real dialogue makes you *earn* it: the index holds **366 persuade, 135 intimidate, 86 bribe** entries, and the guard's own `(Persuade)` / `(Bribe N gold)` lines are among them | `kind` census on `lrg_prompt` |
| S5 | `Training` opens the menu, which is right — but nothing tells her *which* skills she teaches or what Requiem charges | the real approach lines exist per skill (`Can you train me in Alchemy?`), and `Requiem.esp` + `Simply Smart Training.esp` set the rules the menu then enforces | 189 index rows matching `train`; `Requiem.esp` contributes 3 |
| S6 | every shortcut **ends the conversation** (`HireCarriage`/`HireFerry` followups say so in their own prompt text) | a real entry keeps the session, so the player can haggle, back out, or ask something else | seed rows' `followup.prompt` |

**The rule, unchanged from decision D4 and already half-built:** when a session's list contains the
real entry, the glue executes the real entry and **hides CHIM's shortcut**; CHIM's shortcut stays
only when no session is possible. `lrg_dialogue.php:72-74` already ships this list —
`hide_chim = [RentRoom, HireCarriage, HireFerry, Brawl, Training, OpenInventory, OpenInventory2]`,
`hide_gold = [GiveGoldTo, TakeGoldFromPlayer]`, `hide_always = [ForgiveCrime]` — and
`d29_services.php` already asserts all of it, including that **`PayBounty` is deliberately NOT
hidden** because it checks the player's gold before `PlayerPayCrimeGold`. E6 is therefore an
**expansion of shipped scaffolding**, not a new subsystem.

### 18.2 What is already shipped for services (do not rebuild it)

* `'service'` is already one of the nine `LRG_DLG_CLASSES` (`lrg_dialogue.php:49`).
* `lrgDlgIsService()` (`:839`) classifies on `service_words` (`:103`): rent, room, carriage, ferry,
  ride, trade, buy, sell, wares, shop, train, training, barter, stable, horse, bed, food, drink,
  invest.
* `rank.service = 2` (`:85`) already lifts service entries in the offered list.
* The **want=1 fast path** and **same-session continuation** already allow `service` and `pay` and
  **never** `check` / `commit` / `meta` (`:1515`, `:1542`).
* Prices already ride the wire: `d29` asserts `;cost=34;` for *"Three nights. (34 gold)"*, and
  `lrgPromptCost()` parses the number out of the **live** entry text.
* `crit.lethal_twat` already contains `DGCrimeResistArrest` (`:70`).

### 18.3 The one genuinely hard problem E6 must solve: **one-word entries**

A destination layer does not look like a sentence. CFTO's entries are literally:

```
Morthal. (<Global=KmodFerryCost> gold)      ->  live:  "Morthal. (35 gold)"
Solitude. (<Global=KmodFerryCost> gold)
Windhelm. (<Global=KmodFerryCost> gold)
Solitude Lighthouse. (<Global=KmodFerryCost> gold)
```

**4,620 of the 37,561 index rows (12.3 %) normalise to two words or fewer.** The generic matcher
(`match.min_score 0.55`, `match.min_margin 0.15`, similarity weight 6) was tuned on sentences. On a
list of one-word place names it is both *unreliable* (a one-token overlap is the whole string) and
*dangerous* (`Solitude.` vs `Solitude Lighthouse.` differ by one token, and picking the wrong one
spends the player's gold and teleports him to the wrong hold).

**Therefore: a dedicated slot matcher that runs BEFORE the similarity matcher and, when it does not
fire, blocks the similarity matcher from executing that layer at all.**

```
lrgDlgServiceSlot(array $entries, string $utter, string $kind): ?array
```

| Rule | Detail |
|---|---|
| **When it runs** | the live layer is `closed`, **and** ≥ 3 entries normalise to ≤ 3 words, **and** ≥ 2 of them carry a `cost` — i.e. "this is a price list", decided from the live list, never from the index alone |
| **How it matches** | exact normalised token-sequence containment of a **known slot name** in the utterance. Slot names come from the layer itself (the entry text minus the price and the trailing full stop), never from a hard-coded list |
| **Longest wins** | candidates are sorted by token length descending, so `solitude lighthouse` is tested before `solitude` — this is the S1 correctness rule and it gets its own test |
| **Ambiguity is a refusal** | two slots match and neither contains the other → execute nothing, and she asks which one in her own words (`ask=` the two names). Never a guess |
| **Negation / question guard** | the existing utterance guards apply unchanged (`don't take me to…`, `how much to Morthal?` is a price question, not an order) |
| **Price question ≠ order** | `how much`, `what does it cost`, `what's the fare`, `how much for` → answer with the facts, execute **nothing** |
| **Blocks the fallback** | when the layer is a price list and no slot matched, `lrgDlgMatch()` is **not** consulted for execution. The old behaviour (a 0.55 similarity hit on `Morthal.`) is precisely the bug this prevents |
| **Training** | the same function with `kind='train'`; slot names are skill names discovered by the index builder's service census (§18.6), not the vanilla 18 hard-coded — Requiem/LoreRim rename skills |

**Config (`services.slot`):** `min_entries: 3`, `max_words: 3`, `min_priced: 2`,
`require_named: true`, `ambiguous: 'ask'`.

### 18.4 Per-kind specification

Common to all five: intent recogniser → (real entry in the live list? → **match → confirm →
select**) → else (session possible? → open one) → else (**CHIM's shortcut, if the fallback toggle is
on**). The driver still never calls a fragment; the engine executes by selecting the entry.

| Kind | Intent patterns (config `services.<kind>.phrases`, added to, never replacing, `service_words`) | Real-entry path | CHIM fallback | D4 double-fire prevention | Special rule |
|---|---|---|---|---|---|
| **barter** | *what have you got for sale, let me see your wares, show me your goods, I want to sell, I'd like to trade, do you buy…, anything to trade* | the merchant's own trade entry, class `service`; the **barter window is the effect**, so this is a one-shot, no sub-layer | `OpenInventory` | already in `hide_chim`; hidden the moment a list is known for this NPC | **Prices inside the barter window are the engine's.** She may talk about them but the glue never quotes a per-item price (E7) |
| **inn** | *a room for the night, rent a room, how much for a room, do you have a bed, somewhere to sleep, what's the news, any rumours, something to eat/drink* | hub entry → often a **closed duration layer** with prices — this is the `d29` shape already tested | `RentRoom` | as above | rumours / food are ordinary `plain` entries and never need the slot matcher |
| **carriage / ferry** | *take me to X, I'd like to hire your carriage/boat, can you get me to X, how much to X, ride to X* | hub (`I'd like to hire your carriage.`) → **closed destination layer** → the slot matcher of §18.3 | `HireCarriage` / `HireFerry` | as above; additionally the fallback is **refused** when the destination the player named is not in CHIM's 19/6-item enum (S1) — better to say "I don't run that route" than to teleport him somewhere else | the **free local** variants (`KmodFastTravelCarriageFreeLocal*`) have `cost = 0`: `min_priced` counts them as slots but they never trigger the gold confirmation |
| **training** | *can you train me in X, teach me X, I want to learn X, train me, what can you teach* | the per-skill approach entry → the engine opens the training menu, which is where Requiem's limits and prices are enforced | `Training` (itself already gated by CHIM on `class.teaches`) | as above | the glue **never** states a training price or a level cap; the menu does (E7) |
| **guard / crime** | *I'd like to pay my fine, I'll pay the bounty, I surrender, I'll go quietly, I didn't do anything, let me go, here's for your trouble (bribe), do you know who I am* | the guard's real arrest branch, **class `commit` in almost every case** | `PayBounty` (kept), `AddBounty`/`ArrestPlayer` (engine-side), `ForgiveCrime` **always hidden** | `hide_always` | **§18.5** |

### 18.5 Crime: the LETHAL rule, spelled out

An arrest session is the one place where a wrong click cannot be undone by talking.

1. **Detection.** A session is *arrest-class* when any of: the live list contains an entry whose
   index `twat` is in `crit.lethal_twat` (today `DGCrimeResistArrest`; E6 adds
   `DGCrimeYield`, `DGCrimeResist`, `DGCrimePayFine`, `DGCrimeJail`, `DGCrimeBribe`,
   `DGCrimePersuade` — all discovered by the builder's `--services` census and written into the
   config, **never typed by hand**); or the NPC `guard=1` (wire W10) **and** `bounty>0`; or the
   greeting matched the index's *"I know you…"* family.
2. **What happens.** `iCritical = 0` (the shipped default, decision D5): the driver performs a clean
   **hand-back** — E3's `HandBack()`, reason `lethal` — the menu comes back visible, the corner note
   says *"choose by hand"*, she says one short line, and nothing is selected by voice. With
   `iCritical = 1` the two-step confirmation applies and `pay the fine` / `go to jail` become
   selectable; `resist arrest` is **never** voice-selectable at any setting.
3. **`PayBounty` stays offered** (it checks gold first) — unchanged, asserted by `d29`.
4. **`ForgiveCrime` stays hidden** whenever menuless is on — unchanged. A pardon must be earned
   through the guard's real `(Persuade)` / `(Bribe)` entry, which is what the speech-check
   machinery of v0.4 already does (`lrg_speech.php`), or it does not happen.
5. **The bounty number is a fact, not a guess.** Wire W10 carries the real
   `Faction.GetCrimeGold()`. She may name it; she may not invent it (E7).

### 18.6 LoreRim compatibility: how the mods are *found*, not guessed

`tools/build_prompt_index.py` gains **`--services`**, a census that runs at index-build time and
writes `data/service_catalog.json` (loaded by the server, never queried live):

| Census output | How it is derived | Used by |
|---|---|---|
| `destinations[]` | every INFO whose winning override's topic EditorID matches `*FastTravel*`, `*Carriage*`, `*Ferry*`, `*Boat*`, or whose text is `<Place>.` + an optional price token, on a **closed** layer with ≥ 3 siblings of the same shape | §18.3 slot names; the "is this a price list" test |
| `skills[]` | the object of every `train me in X` / `teach me X` / `learn X` entry, taken from the entry text itself | training slots |
| `price_globals[]` | every distinct `<Global=…>` token and the plugin that owns it (**774 rows today**) | E7's price rule; the coverage report |
| `crime_topics[]` | topic EditorIDs under `DGCrime*` and any mod-added sibling on the same layer | §18.5 detection |
| `service_plugins[]` | the plugin of every row in the four groups above, with counts | the E5 coverage report (§10) gains a *services* column |

**The mods this census must be shown to have found on this install** (named here so a lane can check
its own output against a known answer — these came from the live index and `plugins.txt`):

| Mod | Why it matters | Evidence today |
|---|---|---|
| `CFTO.esp` | the carriage/ferry system actually in use; its own topics and destinations | 7 carriage rows; `KmodFastTravelFerry*`, `KmodFerryCost` |
| `Better Carriage Destinations.esp` | adds destinations CHIM's enum cannot name | 1 row |
| `WaitCarriageInns.esp`, `CarriageAndStableDialogues.esp`, `Skyking Carriages - BBSA.esp` | where carriages stand and what they say | load order |
| `Requiem.esp` | training rules, prices, crime | 3 training rows; the whole economy |
| `Simply Smart Training.esp` | changes training | load order |
| `Xtended Stay.esp` | what renting a room means | 7 room/bed rows |
| `Guard Dialogue Overhaul.esp`, `Extended Guard Dialogue.esp`, `moretosaycityguards.esp`, `suspiciouscityguards.esp`, `guardencounters.esp` | the guard lines the player will actually meet | load order; `guardencounters.esp` has a carriage row |
| `LoreRim - Dialogue Patch.esp` | the modlist's own dialogue overrides — **8 crime rows** and the carriage hub line *"I'd like to hire your carriage."* | index |
| `zeroBountyHostilityFix.esp`, `No Crime Teleport Voice Consistency Fix.esp` | crime behaviour | load order |
| `COINMerchantExchange.esp`, `Quantity Trade - Ysolda.esp`, `Riverwood Trader Is A Mess.esp` | merchant/barter changes | load order |
| `HearthFires.esm`, `Dawnguard.esm`, `CC Farming - Tweaks and Enhancements.esp` | more carriage/ferry price globals | `BYOHHPCostCarriage`, `DLC1FerryCostLarge`, `VSVGLO_CarriagePrice` |

**The census is the authority; this table is the expected answer.** If a lane's `--services` output
does not contain `CFTO.esp`, the census is wrong, not the table.

### 18.7 In-game tests on real LoreRim NPCs (owner, part of the ordinary-play evening)

| # | NPC kind | Concretely | Passes when |
|---|---|---|---|
| V1 | innkeeper | any hold capital's inn: *"I need a room for the night."* | dry-run prints `WOULD CLICK` on the real room entry with the **real** price, not 10 |
| V2 | carriage driver | a CFTO driver: *"Take me to Solitude."*, then *"Take me to the Solitude Lighthouse."* | the second picks the **Lighthouse** row (the longest-wins rule), each with its real fare |
| V3 | carriage driver | *"How much to Morthal?"* | she names the fare and **nothing is clicked** |
| V4 | trainer | a Requiem trainer: *"Can you train me in Alchemy?"* | the real approach entry, the engine's training menu, and **no price claimed by her** |
| V5 | merchant | *"What have you got for sale?"* | the real trade entry; `OpenInventory` absent from that turn's offered actions |
| V6 | guard with a bounty | walk up with a bounty | arrest-class detected, **menu handed back visible**, corner note, one short line, `handback … why=lethal` in the log |
| V7 | guard, no bounty | small talk | nothing service-related fires; `ForgiveCrime` absent |

### 18.8 Does any of E6 need a DLL? **No.**

Everything here is: one new server function (`lrgDlgServiceSlot`), config, an offline census in the
existing Python builder, three new wire keys read from **already-verified** Papyrus natives, and
tests. No SKSE plugin, no SWF work, no new UI primitive — **lane A adds nothing for E6**.

---

## 19. E7 — FACT-LOCKING AND CONDITION TRUTHFULNESS

### 19.1 What CHIM core actually has — the honest inventory

The owner asked us to "consider the core's fact-locking and condition truthfulness". Here is what is
really in the installed `HerikaServer`, and what each thing is *not*.

| CHIM mechanism | Where | What it really does | What it is **not** |
|---|---|---|---|
| **`lock_profile`** | `lib/core/npc_master.class.php:1030-1031` → `$GLOBALS['LOCK_PROFILE']`; UI at `ui/core/npc_master.php:581`; `AUTO_LOCK_PROFILE` is **`true`** on this install (`general_settings`) | protects an NPC's **authored profile text** from being overwritten by automatic profile regeneration / history updates | **not** a set of facts the LLM is forbidden to contradict. It is write-protection on the profile row, not read-time truth enforcement |
| **`relationships_locked`** | `ext/relationship_system/relationship_llm.php:638`, `:1625`; `ui/api/chim_npc_manager.php:240` | the relationship LLM **skips** `saveRelationships` / `applyChanges` for that NPC | same: protects owner edits, does not constrain the reply |
| **Action catalog `requirements`** | `lib/core/action_catalog.php:1145-1232` (built-ins), `:1834` `herikaActionCatalogRequirementsMatch()`, `:1988` `…RowMatchesRequirements()`, applied for real at **`functions/functions.php:2745`** — `herikaLoadEnabledActionCodesForMode($isNpcMode, true)` | **this is the condition-truthfulness mechanism.** An action whose preconditions are false is never put into the function schema, so the model cannot choose it | evaluated from **static-ish context** (faction, name lists, class, activity, request type) — not from our per-turn dialogue state |
| **Prompt injection slots** | `lib/prompt_injections.php`; rendered at `main.php:2553` (`character_bottom`) and `main.php:2564` (`prompt_bottom`) | ordered, de-duplicated, priority-sorted text injected into two fixed slots | not a fact store, not validated |
| **Actor-profile enrichers** | `chimRegisterActorProfileEnricher()` / `chimBuildActorProfileEnrichmentText()` | per-actor sentence fragments joined with `. ` | — |
| **"do not invent" instructions** | `relationship_manager.php:1048` (*"Never invent a type"*), `chim_quest_engine.php:2096` (*"Never invent beat IDs"*), `json_response.php:342/450`, `main.php:1326` | prompt-level instructions | **there is no post-hoc verifier of the model's claims anywhere in core.** Truthfulness is enforced by *not offering* a false action, never by checking the words afterwards |

**The requirement vocabulary CHIM can evaluate** (so a lane knows exactly what is expressible):
`npc_factions_any`, `npc_factions_all`, `npc_names_any`, `npc_name_in_config_list`,
`npc_name_in_action_config_list`, `requires_rolemaster`, `requires_training_service`,
`request_types_any`, `request_types_none`, `hide_in_rechat`, `show_only_in_rechat`, and an
`activity` sub-block: `current_action`, `current_action_in`, `current_action_not_in`, `use_type`,
`use_type_in`, `use_type_not_in`, `require_available`, `require_fresh`, plus boolean activity flags
such as `is_weapon_drawn`.

**And the seam that makes this usable by us:** the glue already installs its own rows through
`herikaActionCatalogUpsertCustomRow($row)` (`lrg_actions.php:45-139`), and that row carries the same
`metadata` column the built-ins use. **Anything we put in `metadata.requirements` is evaluated by
CHIM's own machinery on the same code path as `RentRoom`.** Nothing new has to be invented.

### 19.2 What the glue does with it — three additions, in order of value

#### (a) Our catalog rows declare their preconditions through CHIM's `requirements`

For each glue row, the requirements that are *honestly expressible* in CHIM's vocabulary:

| Glue row | `metadata.requirements` added | Why it is honest |
|---|---|---|
| `ExtCmdLRG_SelectTopic` (`TakeUpBusiness`) | `activity.current_action_not_in: [dead, unconscious, sleeping, combat, attacking]` | a dialogue menu cannot be driven with a corpse or mid-combat, and CHIM knows the activity |
| the crime-adjacent glue rows, where any | `npc_factions_any: [00086EEE, 00028848, 00028849]` — **the same three ids CHIM uses**, read from the catalog, never retyped from memory | identical to `PayBounty`'s own gate |
| the intimacy rows (Phase 1) | `activity.current_action_not_in: [dead, unconscious, sleeping, combat, attacking]` | unchanged behaviour, now enforced twice |

**Stated plainly, because it matters:** CHIM's requirement vocabulary **cannot** express *"a dialogue
session with this NPC is open and its list is known"* — that is per-turn state living in our own
Postgres rows. **Our own per-turn filter therefore remains the primary gate and does not move.**
CHIM's `requirements` are a second, independent gate that catches the cases our filter never sees
(a rechat turn, a narrator turn, a stale cache). Belt and braces, and the plan says so rather than
pretending CHIM can do it alone.

**Test:** `d59_requirements` asserts that every row the glue upserts has a `requirements` key, that
the three faction ids are read from CHIM's own built-in table rather than literal-typed in our
source, and that `herikaActionCatalogRowMatchesRequirements()` returns **false** for our row when
`activity.current_action = 'combat'`.

#### (b) A LOCKED FACTS block — CHIM's own injection slot, our content

New injection, registered in `context_pre.php` beside the two that already exist:

```php
chimRegisterPromptInjection('character_bottom', 'lorerim_glue_locked_facts', $lrgLocked, 205);
```

(`205` sits between the existing `lorerim_glue_boundaries` at 200 and
`lorerim_glue_business_rules` at 210, so the facts are read before the business rules that refer to
them.) Rendered content, at most **600 characters**, only when non-empty:

```
<locked_facts>
These are facts the game has confirmed this moment. They are true. You may say them.
Anything not listed here you do NOT know - say you are not sure rather than name a number,
a place, a price, a bounty or a quest step.
- her price for a room tonight: 12 septims
- the fare she quoted to Morthal: 35 septims
- your bounty in Whiterun: 40 septims
- the persuasion you just tried: FAILED
- your current task: "Speak to the Jarl of Whiterun"
</locked_facts>
```

**Fact classes, and where each one's truth comes from — nothing else may enter this block:**

| Class | Source of truth | Freshness |
|---|---|---|
| `price` | `cost=` parsed from a **live** entry the game sent this session (`lrgPromptCost`) | that session only |
| `service` | a slot name present in the live layer (§18.3) | that session only |
| `bounty` | wire **W10** `bounty=` ← `Faction.GetCrimeGold()` | `hold_max_age_seconds` (45 s) |
| `gold` | the snapshot's existing `pg=` | snapshot age |
| `check` | the outcome the **engine** produced (v0.4 speech-check machinery) | that turn |
| `quest` | the active-objective rows already built by `lrgDlgQuestRows()` (E2c) | `ev=facts` throttle, 60 s |
| `guard` | W10 `guard=` ← `Actor.IsGuard()` | as bounty |

**Never in the block:** anything from the index alone (the index is a *template*, e.g.
`<Global=KmodFerryCost>`, not a fact), anything from a stale cache, anything the LLM said earlier,
and any price for an item inside the barter window (the engine owns those).

#### (c) The truth gate — what happens when she says something unconfirmed

Be honest about the limit: **we cannot rewrite her sentence**, and trying to would produce worse
dialogue than the problem it fixes. What we *can* do is refuse to let an unconfirmed claim have
consequences, and correct it on the next turn.

| Step | Behaviour |
|---|---|
| 1 | The existing post-LLM gate gains a `lrgDlgTruthCheck()` pass. It looks **only** at the reply's *numbers and slot names* — a septim amount, a destination name, a bounty figure — and only when that turn carried a `price` / `service` / `bounty` fact class |
| 2 | Claim matches a locked fact, or no claim → nothing happens (the overwhelmingly common case) |
| 3 | Claim contradicts a locked fact **and the same turn carries an action that would act on it** (a `do=pick` on a priced entry, a gold transfer) → **the action is dropped**, `funcret` explains, and the player sees the corner note. Her words still play: she was wrong out loud, which is in character, and nothing was spent |
| 4 | Claim contradicts a locked fact with **no** action → logged only, and the next turn's block gains one line: `she named a price the game has not confirmed - correct yourself naturally if it comes up` |
| 5 | `bTruthGate:Truth` off → step 3 becomes step 4 (log only). Default **on** |

**Deliberately not built:** an LLM-judged truth check (a second paid call per turn — the opposite of
E8), and any attempt to edit her words.

### 19.3 What we do **not** touch

No CHIM core file is modified — `lock_profile`, `relationships_locked` and the catalog's built-in
requirements are read and honoured, never written. The glue keeps honouring
`relationships_locked` exactly as it does today (`lrg_core.php:666-693`). `AUTO_LOCK_PROFILE` is the
owner's setting and stays his.

---

## 20. E8 — LATENCY: *"the timeliness of the response from the npc's voice in game"*

> `OWNER_ADDENDA` item 9b says the measuring round is separate. The owner's own message of the same
> hour puts latency second of three. **This section is therefore the measurement, the diagnosis and
> the recommendation — all three already done, from his own install — plus the small amount of
> building that is genuinely ours.** Nothing here changes a CHIM setting; §20.6 is a list the owner
> applies himself.

### 20.1 The measurement already exists, and it has been read

CHIM ships `lib/request_performance.php`: `chimRequestPerformanceMark($phase)` writes a `[PERF]`
JSON line through `Logger::trace`, with phases marked in `main.php` at
`request_parsed` (`:151`), `lock_ready` (`:250`), `profile_ready` (`:2030`),
`context_history_ready` (`:2074`), `memory_ready` (`:2112`), `oghma_ready` (`:2327`),
`prompt_ready` (`:2764`), `llm_complete` (`:2818`).

**There are 8,680 `[PERF]` lines in the current logs, 86 of them with a real LLM call.** Measured on
the owner's own machine:

| Request type | n | median total | median **LLM** | median **everything before the LLM** | worst total |
|---|---|---|---|---|---|
| `inputtext` (a normal spoken turn) | 70 | **7.73 s** | **6.54 s** | **0.61 s** | 53.6 s |
| `lrg_scenetalk` | 10 | 7.92 s | 7.79 s | 0.26 s | 22.8 s |
| `lrg_initiative` | 3 | 7.28 s | 7.05 s | 0.25 s | 10.7 s |
| `narrator_inputtext` | 3 | 2.67 s | 2.46 s | 0.24 s | 7.9 s |

Pre-LLM phase medians on LLM turns: `oghma_ready` **200 ms**, `profile_ready` **141 ms**,
`memory_ready` **97 ms**, `prompt_ready` **58 ms**, `context_history_ready` **22 ms**.

### 20.2 The diagnosis, stated plainly

**85–95 % of the wait is the LLM call.** Everything the server does before it — profile, memory,
Oghma lore, and *our own prompt blocks* — totals about **0.6 s**. `prompt_ready` is 58 ms median,
**127 ms at its worst**: the glue's business rules, quest tree, locked facts and boundaries are
**not** the problem and shrinking them would win tens of milliseconds against a 6.5-second call.

Two real anomalies worth fixing, both visible in the same data:

1. **Semaphore stalls.** `lock_ready` is 0.53 ms median and 0.77 ms at p90 — but **31 requests waited
   over a second, 16 over three seconds, and one waited 12.9 s**. One captured turn shows
   `lock_ready 6002 ms` in front of a 10 s LLM call for a 16.8 s total. These are queued requests
   behind another in-flight one (`lib/semaphore_manager.class.php`). Every request the glue sends
   that does **not** need to be serialised with a conversation turn is a candidate to stop queueing.
2. **The 53.6 s outlier** on `inputtext`. One connector timeout of that size is worth more to the
   owner than any prompt tuning; §20.6 item 4.

### 20.3 What is genuinely ours to fix (lane C, small)

| # | Item | Today | After |
|---|---|---|---|
| L1 | **The first-contact extra paid reply.** `bIntentOpen` makes the first business turn with an unseen NPC cost a whole extra LLM round trip (§15 already warns: ~11–14 s) | one extra paid reply | unchanged by default, but the warning moves from the notes into the **MCM help** of `bIntentOpen`, and the new `LAT` line makes it visible when it happens |
| L2 | **Two-step confirmation costs a second round trip** on consequential entries | correct and stays | the confirmation prompt is **pre-composed** in the same reply where possible (it already is for `want=1`); the plan adds no new confirmation to the service kinds beyond the priced ones |
| L3 | **Our extra requests.** `ev=calib` (W1), `ev=facts`, `ev=lat` (W14) are `lrg_*` request types that never reach an LLM — measured at **2–7 ms total** each | already cheap | they must **stay** off the LLM path: a test asserts no new `ev` reaches `main.php`'s LLM branch, and `ev=calib` keeps its 30-second throttle |
| L4 | **Queueing.** Our non-conversational messages should not sit behind a conversation turn | unmeasured | the `LAT` line records `lock_ready` per request type so the owner can see whether our traffic is ever the thing that waited |

### 20.4 The end-to-end number nobody has: `ev=lat` (wire **W14**)

`[PERF]` stops at `llm_complete`. It does **not** include TTS synthesis, the trip back to the game,
or the moment her voice actually starts — which is the thing the owner actually asked about
(*"the timeliness of the response from the npc's voice in game"*).

The game already has both ends of that clock:
`LRG_Main.OnChimTextReceived` (`:667`) and `LRG_Main.OnChimSpeechStarted` (`:614`).

| Measured | From | To |
|---|---|---|
| `ask` | the player's speech ending (CHIM's own speech-stopped event, `:641`) | the request leaving |
| `reply` | request leaving | `OnChimTextReceived` |
| `voice` | `OnChimTextReceived` | `OnChimSpeechStarted` — **this is the TTS gap, and it is invisible today** |
| `total` | player stopped talking | her voice started |

Sent as `ev=lat` (never LLM-bearing), logged on both sides, and shown as a corner note only when
`total > iLatencyWarnMs`. **This is the first number that includes PocketTTS.**

### 20.5 The model question — measured against his own configuration

The owner's connectors, read from `core_llm_connector`, and his assignments from `general_settings`:

| id | Label | Model | Driver | `reasoning_model` | Reasoning suppressed? | Assigned to |
|---|---|---|---|---|---|---|
| **10** | **Grok 4.3** | `x-ai/grok-4.3` | openrouterjson | **1** | **yes** — `{"reasoning":{"effort":"none"}}`, `extra_parameters_enabled: true` | **`CORE_CONNECTOR_DIRECTOR`** (the in-game reply), `…_PROFILES`, `…_SCENECLASSIFIER` |
| **1** | **DeepSeek V4 Flash** | `deepseek/deepseek-v4-flash` | openrouterjson | **1** | **no** — `metadata` is `{}` | `…_BGL`, `…_PLAYER`, `RELLLM_CONNECTOR`, `GLOBAL_STT/ITT` |
| 4 | DeepSeek V4 Pro | `deepseek/deepseek-v4-pro` | openrouterjson | 0 | n/a | `…_MEDIUMTERM`, `…_SUMMARY` |
| 2, 3, 5, 6, 7 | Gemini 2.5 Flash Lite, GLM 5.2, Mistral Small 3.2 24B, Ministral 8B, Gemma 3N E4B | — | openrouterjson | mixed | — | unassigned |

Everything goes through **OpenRouter**, `max_tokens 750`, `enforce_json 1`, `json_schema 1`.
Grok runs at `temperature 0.75`; DeepSeek V4 Flash at `0.6`.

**The recommendation, and the trap.** DeepSeek V4 Flash is a reasonable move — it is a smaller, fast
model, it is already installed, and it is *already proving itself* on this machine for background
life, the player rewrite and the relationship LLM. **But it has `reasoning_model = 1` and an empty
`metadata`, while Grok 4.3 carries `{"reasoning":{"effort":"none"}}`.** Switching
`CORE_CONNECTOR_DIRECTOR` from 10 to 1 as-is risks a model that *thinks before every line of
dialogue* — which would make the wait **longer**, not shorter, and would look like the glue's fault.

> **Say this to the owner in one sentence:** if you switch the Director to DeepSeek V4 Flash, first
> copy Grok's reasoning setting onto it — `extra_parameters_enabled` on, `{"reasoning":{"effort":
> "none"}}` — otherwise you may be paying for thinking you cannot hear.

**How the choice gets made with numbers instead of opinions** — `tools/bench_llm.php` (lane C,
offline, no game):

* replays **N real captured prompts** (taken from the existing log, scrubbed) against a list of
  connector ids, `--n 12 --connectors 10,1,2,3`;
* reports per connector: **time to first token**, **total**, **tokens out**, **strict-JSON action
  compliance** (does the reply parse, and does the action survive our post-gate?), **cost per turn**
  from OpenRouter's own usage figures;
* runs each connector's prompts **interleaved**, not in blocks, so OpenRouter-side variance does not
  decide the winner;
* prints a table and writes `research/llm_bench.json`. It **never** changes a setting.

**The one-line switch when he decides:** `CORE_CONNECTOR_DIRECTOR` in `general_settings` (10 → 1).
Nothing in the glue is tied to a model.

### 20.6 The owner's own settings, with the measured cost of each

**We change none of these** (`OWNER_ADDENDA` 9b: *"Nothing in CHIM's config is changed by us without
the owner"*). Listed in the order of measured value:

| # | Setting | Measured today | Worth |
|---|---|---|---|
| 1 | `CORE_CONNECTOR_DIRECTOR` | Grok 4.3, 6.54 s median | **the whole game** — §20.5 |
| 2 | Reasoning suppression on whichever Director he picks | Grok: on. V4 Flash: **off** | seconds, and the reason a switch could backfire |
| 3 | `CONTEXT_HISTORY` = 50 | inside the 6.54 s, not separately visible | fewer turns = fewer input tokens = faster first token; try 30 |
| 4 | Connector timeout / retry | one 53.6 s turn observed | caps the worst case |
| 5 | `OGHMA_AMOUNT` = 1 with `RACIAL_OGHMA` + `LOCATION_OGHMA` both on | `oghma_ready` 200 ms median, **717 ms worst** | small but free: the amount is already minimal |
| 6 | `FEATURES@MEMORY_EMBEDDING` on, TXT2VEC on | `memory_ready` 97 ms median, 437 ms worst | small; keep — it buys memory |
| 7 | **TTS**: `pockettts` at `127.0.0.1:8086` (in-distro audio.cpp) **and** `omnivoice` at `127.0.0.1:8021` | **not measured anywhere today** | §20.4's `voice` number is what decides this. PocketTTS on the game GPU is the known suspect; OmniVoice is already installed as an alternative |

### 20.7 Does E8 need a DLL? No.

`ev=lat` uses three CHIM events `LRG_Main` already handles. `bench_llm.php` is PHP against the
connector rows. Everything else is reading logs that are already being written.

---

## 21. Wire, MCM, config and log additions for E6 / E7 / E8

### 21.1 Wire — continuing §2's numbering (all additive, all "unknown key → ignored")

| # | Message | Key / value | Format | When | Old-version behaviour |
|---|---|---|---|---|---|
| **W10** | `lrg_dlg ev=facts` | `guard=` `bounty=` `cf=` | `;guard=<0\|1>;bounty=<int>;cf=<hex8\|->` — `guard` from `Actor.IsGuard()`, `cf` from `Actor.GetCrimeFaction()`, `bounty` from `Faction.GetCrimeGold()` on that faction; `bounty=0` and `cf=-` when there is no crime faction | every `ev=facts` (already throttled 60 s per NPC) | unknown keys ignored; the server reads `guard`/`bounty` as absent and simply has no bounty fact (E7's block omits the line) |
| **W11** | `lrg_npcstate` | `sv=` `lf=` | `;sv=<0\|1>;lf=<0\|1>` — `bServiceDialogue:Services` and `bLockedFacts:Truth`, written beside the existing `ql=` / `qi=` | every snapshot | unknown keys; the server keeps its config defaults (`services.enabled`, `truth.locked_facts`) |
| **W12** | `ExtCmdLRG_SelectTopic` | `svc=` | appended **after** `z=1` as `;svc=<inn\|carriage\|ferry\|train\|barter\|crime\|->` | every emitted command | game **401** parses `k=v` pairs and ignores an unknown trailing key. Diagnostic + the corner note's wording only; **nothing branches on it** |
| **W13** | `lrg_dlg ev=open` | `svck=` | `;svck=<kind\|->` — the service kind the **driver** guessed from the live layer shape (the "price list" test of §18.3 is cheap and the driver already has the entries) | every `ev=open` on a closed layer | unknown key; the server re-derives it and logs a mismatch as `svck drift` |
| **W14** | `lrg_dlg` | **`ev=lat`** (new `ev`) | `ev=lat;v=1;ref=<hex8>;npc=<name>;ask=<ms>;reply=<ms>;voice=<ms>;total=<ms>;sid=<sid\|0>;first=<0\|1>` — `first=1` when this was the first business turn with this NPC (the L1 cost) | at `OnChimSpeechStarted`, at most **one per reply**, and only when `bLatencyLog:Diagnostics` is on | a 0.4 server logs `unknown ev=lat - ignored (additive wire: this is not an error)` (`lrg_dialogue.php:541`) and returns `handled` |

**`ev=lat` must never reach an LLM.** It joins `ev=calib` in the same guard, and `d61_latency`
asserts it.

### 21.2 MCM — new page "Services and truth" (section suffixes `:Services`, `:Truth`), plus two on Diagnostics

Same rule as §3: **every key gets a `settings.ini` line, zeroes included**, and the in-code default
equals the line.

| id | Page / section | type | range | default | text | help |
|---|---|---|---|---|---|---|
| `bServiceDialogue:Services` | Services and truth / Services | toggle | — | **1** | Handle rooms, rides, training and trade by talking | When she really has the dialogue for it, asking out loud — "a room for the night", "take me to Morthal", "can you train me in Alchemy" — uses her *real* entry, with the real price and the real rules of this modlist, instead of CHIM's built-in shortcut. |
| `bServiceShortcut:Services` | Services and truth / Services | toggle | — | **1** | Fall back to CHIM's own shortcuts | When no conversation can be read at all, CHIM's own Rent Room / Hire Carriage / Training actions stay available as a rough stand-in. Switch off if you would rather she simply said she cannot help. |
| `bCarriageByName:Services` | Services and truth / Services | toggle | — | **1** | Let me name the destination | "Take me to the Solitude Lighthouse" picks that exact place off her list. If two places could be meant, she asks instead of guessing. |
| `bNamePrices:Services` | Services and truth / Services | toggle | — | **1** | She may say prices out loud | Only ever a price her list actually showed. She will never invent one. |
| `bCrimeManual:Services` | Services and truth / Services | toggle | — | **1** | Always hand me the menu for an arrest | An arrest, a fine or a jail term is the one thing that cannot be undone by talking. With this on, the menu comes back and you choose by hand. Turning it off does nothing unless you have also allowed consequential choices on the Menuless page. |
| `bLockedFacts:Truth` | Services and truth / What she is allowed to claim | toggle | — | **1** | Only let her state what the game confirmed | Prices, fares, your bounty, your gold, the outcome of a persuasion and your current task are handed to her as facts. Anything else she is told to be honest about not knowing, rather than making up a number. |
| `bTruthGate:Truth` | Services and truth / What she is allowed to claim | toggle | — | **1** | Do not let a wrong number cost me gold | If she names a price the game has not confirmed *and* the same reply tries to act on it, the action is dropped — she said something wrong out loud, which happens, but nothing was spent. |
| `bLatencyLog:Diagnostics` | Diagnostics | toggle | — | **1** | Time how long she takes to answer | Writes one line per reply: how long you waited for the words, and how long again before her voice started. Costs nothing and sends nothing to the AI. |
| `iLatencyWarnMs:Diagnostics` | Diagnostics | slider | 2000–20000 step 500 | **9000** | Warn me when a reply takes longer than | Milliseconds. A note in the corner, so a slow model or a stalled voice is visible rather than mysterious. |

**One correction to an existing entry:** `bIntentOpen:Dialogue` help gains the sentence
*"The first time the glue meets somebody it has never read, this costs one extra AI reply and about
ten seconds. After that it is free."* (L1, promoted out of the notes.)

### 21.3 Server config keys (`lrg_config.json`, `dialogue` block, defaults in `lrgDlgDefaults()`)

```
'services' => [
    'enabled'  => true,
    'shortcut_fallback' => true,
    'slot' => ['min_entries' => 3, 'max_words' => 3, 'min_priced' => 2,
               'require_named' => true, 'ambiguous' => 'ask'],
    'catalog' => 'data/service_catalog.json',      // written by build_prompt_index.py --services
    'kinds' => [
        'inn'      => ['enabled' => true, 'hide' => ['RentRoom']],
        'carriage' => ['enabled' => true, 'hide' => ['HireCarriage'], 'refuse_fallback_offlist' => true],
        'ferry'    => ['enabled' => true, 'hide' => ['HireFerry'],    'refuse_fallback_offlist' => true],
        'train'    => ['enabled' => true, 'hide' => ['Training'],     'never_quote_price' => true],
        'barter'   => ['enabled' => true, 'hide' => ['OpenInventory', 'OpenInventory2']],
        'crime'    => ['enabled' => true, 'hide' => ['ForgiveCrime'], 'lethal' => true],
    ],
    'price_words' => ['how much', 'what does it cost', 'what is the fare', "what's the fare",
                      'how much for', 'what do you charge', 'your price'],
],
'truth' => [
    'locked_facts' => true, 'gate' => true, 'max_chars' => 600, 'max_lines' => 6,
    'classes' => ['price', 'service', 'bounty', 'gold', 'check', 'quest', 'guard'],
    'bounty_max_age_seconds' => 45,
],
'latency' => ['accept' => true, 'log' => true, 'warn_ms' => 9000, 'keep' => 200],
```

`services.kinds.*.hide` must remain a **subset** of the existing top-level `hide_chim` /
`hide_always` lists — a flow test asserts it, so the two cannot drift.

### 21.4 Log lines — exact formats (continuing §5)

**Game side (lane B):**
```
LAT npc=<name> ask=<ms> reply=<ms> voice=<ms> total=<ms> first=<0|1> sid=<sid>
LAT SLOW npc=<name> total=<ms> over=<iLatencyWarnMs>
SVC npc=<name> kind=<inn|carriage|ferry|train|barter|crime> layer=<int> priced=<n>/<n>
```
**Server side (lane C):**
```
dlg svc npc=<name> kind=<…> slot=<name|-> pos=<int> cost=<int> src=<entry|shortcut|none> why=<…>
dlg svc npc=<name> shortcut <Action> kept - no session possible
dlg svc npc=<name> AMBIGUOUS <name> vs <name> - asked instead of guessing
dlg lock npc=<name> facts=<n> chars=<n> classes=<csv>
dlg truth npc=<name> claim=<price|destination|bounty|quest> said="<40 chars>" fact="<40 chars>" action=<dropped|logged>
dlg lat npc=<name> total=<ms> reply=<ms> voice=<ms> first=<0|1>
dlg bench connector=<id> model=<name> ttft=<ms> total=<ms> json=<ok|bad> cost=<usd>
```

### 21.5 Where E6 and E4(b) both reach for the same action — resolve it now, not in testing

`LRG_Main.CONV_MOVE_ACTIONS` (`LRG_Main.psc:26`) is a pipe-delimited string property, and it
**already contains `HireCarriage` and `HireFerry`**:

```
"|ComeCloser|FollowPlayer|Follow|MakeFollower|MoveTo|TravelTo|TravelToRaw|LeadTheWayTo|
  ReturnBackHome|Sandbox|GoToSleep|TakeASeat|Relax|WaitHere|HireCarriage|HireFerry|"
```

So two independent rules written by two different lanes can hide the same two actions on the same
turn: **E4(b)**'s hold policy (§9.2 — she must not wander off while a conversation hold is live) and
**E6**'s D4 rule (§18.4 — the real entry is known, so the shortcut steps aside).

| Question | Answer, binding |
|---|---|
| Is the union a problem? | **No.** Hidden is hidden, and both reasons are correct. There is no state where one wants it hidden and the other wants it visible |
| Then what is the bug risk? | **The log lying about why**, and `bServiceShortcut:Services` un-hiding something the *hold* hid — which would let the model teleport the player mid-conversation, the exact `pt8-walkaway` failure E4(b) exists to stop |
| The rule | `bServiceShortcut` may only return an action that **E6 alone** hid. The hold policy is evaluated **last** and is never overridden by a service toggle |
| Order in code | `lrgDlgHideEndConversationOnHold()` → `lrgDlgHoldMovementPolicy()` (E4b) → service hiding (E6) → `bServiceShortcut` restore, which subtracts the hold policy's set before restoring |
| The log | `dlg svc … src=shortcut` must carry `why=` naming **one** of `no-session`, `kind-off`, and never appear at all for an action the hold policy hid; that case logs `dlg hold npc=… movement HireCarriage hidden` instead |
| Test | `d56_services` adds: with `hold=1` fresh **and** `bServiceShortcut=1` **and** no list known, `HireCarriage` is **still hidden**, and the reason in the log is the hold, not the service |

`d52_holdmove` (Part I) already asserts `hold_move_actions` equals `CONV_MOVE_ACTIONS`; this row
extends that guarantee to the service path rather than adding a second source of truth.

---

## 22. Lane assignment for E6 / E7 / E8

**Lane A adds nothing.** Deliberately: `LRG_DlgUI.psc` and `LRG_DlgProbe.psc` are the highest-risk
files in the project and they already carry all of E1. The "is this a price list" test (W13 `svck=`)
is done by **lane B** in `LRG_Dialogue`, from entry text the driver already holds, through existing
`LRG_DlgUI` readers (`EntryText`, `EntryCount`). **No new UI primitive, no new probe, no signature
change** — the DLL seam of `V04_BUILD_PLAN` §11 stays frozen.

### 22.1 Lane B — additional tasks (game driver + integrator)

| # | Task | File | Detail |
|---|---|---|---|
| B16 | `guard=` / `bounty=` / `cf=` on `ev=facts` | `LRG_Dialogue.psc` | `Actor.IsGuard()`, `Actor.GetCrimeFaction()`, `Faction.GetCrimeGold()` — all three verified native (§22.3). Guard the whole block with `if cf` so a factionless NPC costs one call |
| B17 | `svck=` on `ev=open` | `LRG_Dialogue.psc` | the price-list test: `n >= services.slot.min_entries` entries, `>= min_priced` with a parsed cost, `<= max_words` words each. Pure arithmetic on strings the driver already has |
| B18 | `ev=lat` | `LRG_Main.psc` | three timestamps via `Utility.GetCurrentRealTime()` stored on the existing speech-event handlers (`:614`, `:641`, `:667`); one send at `OnChimSpeechStarted`; `LAT` / `LAT SLOW` lines; the corner note reuses the shipped `note=` path |
| B19 | `sv=` / `lf=` on the snapshot | `LRG_Profile.psc` | one line beside `ql=` / `qi=` |
| B20 | The arrest hand-back | `LRG_Dialogue.psc` | `bCrimeManual:Services` joins the conditions that route to E3's `HandBack()` with `why=lethal`; **no new hand-back code** |
| B21 | MCM page + keys | `config.json`, `settings.ini` | §21.2 in full, `settings.ini` lines included |

**Added to lane B's gate:** `compile.ps1` prints **OK**; the `ev=lat` path is proven to send **at
most one** message per reply (a hand-traced walk through the three events, including the case where
the text arrives but the voice never starts).

### 22.2 Lane C — additional tasks (server + index)

| # | Task | File | Detail |
|---|---|---|---|
| C19 | `lrgDlgServiceSlot()` | `lib/lrg_dialogue.php` | §18.3 in full: longest-wins, ambiguity → ask, price questions execute nothing, blocks the similarity fallback on a price list |
| C20 | Per-kind intent + hide wiring | `lib/lrg_dialogue.php` | §18.4; `services.kinds.*.hide` asserted to be a subset of the shipped `hide_chim` / `hide_always` |
| C21 | `--services` census | `tools/build_prompt_index.py` | §18.6 → `data/service_catalog.json`; the E5 coverage report (§10.1) gains a **services** column |
| C22 | Crime detection + LETHAL | `lib/lrg_dialogue.php` | `crit.lethal_twat` extended **from the census**, never hand-typed; `guard=1 && bounty>0`; the `I know you…` family |
| C23 | `requirements` on our catalog rows | `lib/lrg_actions.php` | §19.2(a); the three guard faction ids read from CHIM's own `herikaActionCatalogGetBuiltinRequirements('PayBounty')`, not literal-typed |
| C24 | The locked-facts injection | `context_pre.php`, `lib/lrg_dialogue.php` | `chimRegisterPromptInjection('character_bottom', 'lorerim_glue_locked_facts', …, 205)`; ≤ 600 chars; the seven fact classes and their freshness rules |
| C25 | `lrgDlgTruthCheck()` | `lib/lrg_dialogue.php` | §19.2(c), inside the **existing** post-LLM gate — not a new gate |
| C26 | `ev=lat` intake + `ev=lat` never reaching an LLM | `lib/lrg_dialogue.php` | one new `case`; ring buffer of `latency.keep` rows; `dlg lat` line |
| C27 | `bench_llm.php` | `tools/bench_llm.php` | §20.5; read-only against `core_llm_connector`; writes `research/llm_bench.json`; **changes no setting** |
| C28 | `PROTOCOL.md` | `PROTOCOL.md` | §21.1 verbatim as **10.15 services**, **10.16 locked facts**, **10.17 latency** |
| C29 | New flow scenarios + `test_services.php` | `tools/flows/scenarios/`, `tools/` | §23 |

**Added to lane C's gate:** `--services` output contains **`CFTO.esp`** and at least one
`Solitude Lighthouse`-shaped destination; `run_flows.php --strict` green on **61 + 6 + 6**.

### 22.3 Papyrus API for Part II — every call, verified in the installed sources

| Call | Verified at | Used by |
|---|---|---|
| `Actor.IsGuard() → bool` | `Skyrim Script Extender (SKSE64)/Scripts/Source/Actor.psc` — `bool Function IsGuard() native` | W10 |
| `Actor.GetCrimeFaction() → Faction` | same file — `Faction Function GetCrimeFaction() native` | W10 |
| `Faction.GetCrimeGold() → int` | `…/Source/Faction.psc` — `int Function GetCrimeGold() native` (also `GetCrimeGoldViolent`, `GetCrimeGoldNonViolent`) | W10 |
| `Actor.GetGoldAmount() → int` | `…/Source/Actor.psc` — `int Function GetGoldAmount() native` | already used for `pg=`; the locked `gold` fact |
| `Actor.GetLevel() → int`, `Actor.GetActorValue(string) → float` | same file | already in use (speech checks) |
| `Game.AdvanceSkill(string, float)` | `…/Source/Game.psc` — `native global` | already in use (speech XP) |
| `Utility.GetCurrentRealTime() → float` | already used throughout `LRG_Dialogue` for every timing | W14 |

**No new stub and no new import**: `compile.ps1:67-72` already pulls in the SKSE64 tree, and
`Faction` is a vanilla script type.

### 22.4 What would need a DLL in Part II — and the confirmation that nothing here does

| Wanted | DLL? | This round |
|---|---|---|
| Reading a vendor's real per-item prices without opening the barter window | **Yes** (the engine's pricing is not exposed to Papyrus) | **Not built.** E7 forbids quoting item prices; the window owns them |
| Knowing a trainer's taught skills and Requiem's per-level cap without a session | **Yes** | **Not built.** CHIM's `class.teaches` answers "does she train at all"; the real entry answers the rest |
| Reading the resolved value of a `<Global=…>` token without the menu showing it | **Yes** | **Not built.** The live entry text already carries the resolved number — this is why prices come from the session and never from the index |
| Measuring TTS synthesis inside the TTS process | no — but it needs the TTS server's own logs | `ev=lat`'s `voice=` measures it from the outside, which is what the player experiences |
| Everything in **E6, E7, E8** as specified | **No** | Papyrus (§22.3), PHP 8, the distro's `python3`. **No C++, no SKSE plugin, no SWF compiler, no install.** |

**Confirmed: nothing in Part II needs a DLL either.**

---

## 23. Tests for E6 / E7 / E8

| id | Lane | Asserts |
|---|---|---|
| `d56_services` | C | Each of the five kinds: the intent fires, the **real** entry is chosen, the matching CHIM shortcut is hidden that turn, and with no list at all the shortcut survives (extends `d29`, does not replace it). `services.kinds.*.hide ⊆ hide_chim ∪ hide_always`. `refuse_fallback_offlist`: a destination not in CHIM's 19-item enum produces *no* `HireCarriage` |
| `d57_slots` | C | **The one-word problem.** `Solitude.` vs `Solitude Lighthouse.` → longest wins, both directions; `Morthal.` with `min_priced` satisfied; an ambiguous pair → `ask`, nothing executed; a price question (`how much to Morthal`) → facts, **no** `do=pick`; **a price list with no slot match executes nothing even at similarity 0.9** |
| `d58_crime` | C | Arrest-class detection by each of the three routes; `why=lethal` hand-back at `iCritical = 0`; `resist arrest` unselectable at **every** setting; `PayBounty` still offered; `ForgiveCrime` never offered; `bounty=` absent → no bounty fact and no claim allowed |
| `d59_requirements` | C | Every upserted glue row carries `metadata.requirements`; the guard faction ids are **read from CHIM's built-ins**, not literals; `herikaActionCatalogRowMatchesRequirements()` is false for our row with `activity.current_action = combat` |
| `d60_truth` | C | The locked block: ≤ 600 chars, ≤ 6 lines, only the seven classes, **never** an index template (`<Global=…>` must not appear); a stale bounty (> 45 s) is omitted; the gate drops the action on a contradicted price **with** an action and only logs **without** one; `bTruthGate` off → always log-only |
| `d61_latency` | C | `ev=lat` is handled, never reaches the LLM branch, and is stored in the ring buffer; `ev=calib` likewise; the `dlg lat` line's field order; a 0.4-server stub logs "unknown ev … not an error" |
| `test_services.php` | C | Offline over the real `service_catalog.json`: the census found `CFTO.esp`; ≥ 1 multi-word destination; ≥ 6 distinct skill names; ≥ 1 `DGCrime*` topic; every `price_globals` entry has an owning plugin |
| `test_prompt_index.php --db` | C | **extended**: the ten-NPC fixture of §10.3 gains a carriage driver, an innkeeper, a trainer and a guard, each asserted to resolve at least one entry of its kind |

**Existing suites stay green, unchanged:** `test_gates.php`, `test_intent.php`, `test_phrases.php`,
`test_scene_index.php`, `test_dialogue.php`, `test_prompt_index.php` (+ `--db`),
`test_mcm_wiring.php`, `run_flows.php --strict`. The WSL staging rule is unchanged: stage to
`$env:TEMP\lrg_test`, LF endings, `wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/.../<script>.sh`.

---

## 24. The owner's evening AFTER this round — the whole of it

> **This supersedes §15 by adding to it. The press count does not change: still two.**
> Everything E6, E7 and E8 need is either ordinary play or reading a page.

| Step | What the owner does | How long | What it answers |
|---|---|---|---|
| 0 | Bind **"Open vanilla dialogue (emergency)"** on the Keys page. | 10 s | the escape hatch |
| 1 | `SmartTalk.ini`: `bSkipImmediateOnInput`, `bHoldToSkip`, `bSkipOnInteraction` → **0**. Leave `iPapyrusHandle` at 3. | 1 min | the D-21 precondition |
| 2 | **Play normally.** Three or four ordinary conversations with **E**, an innkeeper among them. Nothing is hidden, nothing is clicked. | as long as it takes | all of E1's passive calibration, **and** X1 |
| 3 | **Play normally, but visit four people**: an innkeeper (*"a room for the night"*), a CFTO carriage driver (*"take me to Solitude"*, then *"how much to Morthal?"*), a trainer (*"can you train me in Alchemy?"*), a merchant (*"what have you got for sale?"*). Dry run is still on, so nothing happens — the log prints what **would** have happened. | 10 min | **E6 V1–V5**, the slot matcher, real prices |
| 4 | Calibration page → **"Calibrate on the next few conversations"** ON; three more ordinary conversations. | 2 min | the hide round trip, state read-back, guard reset, injection |
| 5 | **Press 1 — "The click test"**, in front of an innkeeper. Mash mouse/E/Space/wheel for three seconds. | 15 s | the input guard and the click route |
| 6 | **Press 2 — "The menu-still-works test"**. Normal conversation, press, click a topic by hand. | 15 s | **R1** — vanilla dialogue is still clickable |
| 7 | Read the **Calibration** page. Green → dry run off when ready. Not yet → it names what is missing. | 10 s | the gate |
| 8 | Walk past a guard while carrying a bounty. **Expect the menu to come back by itself** with *"choose by hand"*. | 1 min | **E6 V6**, the LETHAL rule |
| 9 | Read the **Diagnostics** page's timing line, or `lorerim_glue.log`'s `LAT` lines, after a dozen replies. | 1 min | **E8** — the first number that includes her voice |
| 10 | *(optional, no game)* Run `php tools/bench_llm.php --n 12 --connectors 10,1,2,3` and read the table. | 3 min | **the Grok vs DeepSeek V4 Flash answer, with numbers** |
| 11 | Then the unchanged T2 / T3, and only then dry run off → T4 → T6 → T5 → T10, T11 → T8, T9, T12, T13. | one session | the real acceptance tests |

**Three things to say plainly in the notes:**

1. **Services already exist in CHIM as shortcuts** — rooms, carriages, ferries, training, trade,
   bounties, pardons. What changes is that when she *really has the dialogue for it*, the real entry
   is used, with LoreRim's real price and rules, and CHIM's shortcut steps aside. Where no
   conversation can be read, the shortcut still works exactly as before.
2. **Nobody had measured the voice until now.** The server's own numbers stop when the text is ready;
   `LAT` measures on to the moment her voice starts. Expect the LLM to be 85–95 % of the wait: on
   this install a normal spoken turn is **~7.7 s total, ~6.5 s of it the model, ~0.6 s everything
   the server does**, our own prompt work being **58 ms** of that.
3. **If you switch the Director to DeepSeek V4 Flash, copy Grok's reasoning setting across first**
   (`extra_parameters_enabled` on, `{"reasoning":{"effort":"none"}}`). V4 Flash is marked as a
   reasoning model and has no suppression set, so switching it in as-is could make her *slower*.

---

## 25. Risks added by Part II

| # | Risk | Lane | Mitigation |
|---|---|---|---|
| R12 | **A destination is matched wrong and the player is teleported across Skyrim, out of gold.** The worst bug in E6. | C | The slot matcher is exact-containment with longest-wins, never similarity; ambiguity **asks**; a price list with no slot match executes **nothing**; `d57_slots` tests both orders of the `Solitude` / `Solitude Lighthouse` pair; the priced-entry confirmation still applies above `confirm.min_gold` |
| R13 | **A service entry is hidden from CHIM but the real one is never found**, so the player can do nothing. | C | `hide_chim` only fires when a list is **known** (shipped rule, `d29`); `bServiceShortcut:Services` returns the shortcuts wholesale; the corner note names the reason |
| R14 | **The locked-facts block grows and slows the prompt.** | C | ≤ 600 chars, ≤ 6 lines, seven classes only, all freshness-bounded. Measured against `prompt_ready`'s 58 ms median — a regression test fails if the block exceeds its cap |
| R15 | **The truth gate drops a legitimate action** because a number was phrased oddly. | C | It fires only on a turn that carried a `price`/`service`/`bounty` fact **and** an action on it; `bTruthGate` off is one toggle; every drop is logged with both strings so a false positive is visible |
| R16 | **The arrest hand-back does not fire and a voice line sends the player to jail.** | B + C | Three independent detection routes, `resist arrest` unselectable at every setting, `iCritical` ships at 0, and E3's `HandBack()` is the single code path |
| R17 | **`ev=lat` doubles the message rate.** | B | One message per *reply*, only with the toggle on, never LLM-bearing, measured at 2–7 ms server-side; `d61_latency` asserts the once-per-reply rule |
| R18 | **The owner switches to DeepSeek V4 Flash and it gets slower.** | — | §20.5's warning, repeated in the notes and in `bench_llm.php`'s own output header; the bench measures before he switches |
| R19 | **The `--services` census misses the mod that matters.** | C | The lane gate names `CFTO.esp` and a multi-word destination as a required finding, and §18.6's table is the expected answer to check the census against |
| R20 | **CHIM's `requirements` are mistaken for a complete gate.** | C | §19.2(a) states in the plan, in the code comment and in `d59_requirements` that our per-turn filter is primary and CHIM's requirements are the second net |

---

## 26. Build order for Part II

Part II's steps interleave with §17's rather than following them, because lane A is not involved:

| Step | Lane | What | Gate |
|---|---|---|---|
| 2b | **C** | `--services` census; `service_catalog.json`; `test_services.php` | the census names `CFTO.esp` and a multi-word destination |
| 3b | **B** | W10 / W13 / W11 keys; the price-list test | `compile.ps1` **OK** |
| 5b | **C** | `lrgDlgServiceSlot()`, per-kind wiring, crime + LETHAL, `requirements`, locked facts, truth gate | `d56`–`d60` green; `run_flows --strict` on 61 + 6 + 6 |
| 6b | **B** | `ev=lat`, the MCM page, the arrest hand-back | `compile.ps1` **OK**; the once-per-reply walk-through |
| 6c | **C** | `ev=lat` intake, `bench_llm.php`, PROTOCOL 10.15–10.17 | `d61` green; the bench runs read-only against the connector rows |
| 7b | owner | §24 steps 3, 8, 9, 10 — all inside the same evening | the `WOULD CLICK` lines name real prices; `LAT` lines appear; the bench table exists |

**"Done" for Part II** = every lane gate green, the four service kinds visibly choosing real entries
in dry run, an arrest handing the menu back by itself, a `LAT` line for every reply, a bench table in
the owner's hands — and `bMenuless` / `bDlgDryRun` still shipping at **0 / 1**.

---

# PART III - v1.0 ADDENDUM: menuless questing v1.0, the visible menu driven by voice (2026-09-25)

## 27. What v1.0 changes in this plan

The build spec is `research/pt19-menuless-v1-spec.md` revision 2; the wire and code contract is `PROTOCOL.md`
section 10.29 (the v1.0 wire table is at the end of 10.4); the owner's page is `OWNER_MENULESS_V1.md`; the lane notes
are `research/pt19c-A.md` to `research/pt19c-F.md`. Where this plan and v1.0 disagree, v1.0 wins. The reason in one
line: every in-game session to date ran with the menu VISIBLE, 91 of 359 turns were spoken over an open menu and the
log holds zero real clicks, so the hidden stack this plan built (hide, guard, cursor, PENDING, the watchdog, the
emergency key, the automatic calibration) was unproven and was the source of the nightly "still learning 0 of 10".

### 27.1 Superseded by v1.0

| this plan | v1.0 |
|---|---|
| §6 E1, the self-calibrating driver: ten rows, the active pass A1-A5, the manual presses, the probe hotkey, the MCM buttons, the automatic session on an innkeeper (pt18 E1f) | four PASSIVE rows (`cm rm fam st`) learned from the first menu of any kind; the click route is proven by the first real click (`CALIB set route src=live`); no presses, no buttons, no automatic session; the Calibration page is the status line and "Forget everything it learned" (PROTOCOL 10.13, 10.29 section 3) |
| the dry run as the safety gate: `bDlgDryRun` ships 1 and clears itself once the Calibration page is green | `bDlgDryRun` ships 0 and never switches itself; the safety gate is the STAGE RAIL: until one driven click is verified on this install (`clicks_ok`), only an indexed, plain, cost-free line is clicked |
| §8 E3, assisted mode: the foreseen hand-back, `ev=resume`, re-walk (`bi` / `rw` / `rwd`), `bHandBackNote`, `bResumeAfterChoice` | nothing is handed back because nothing is hidden: a session the driver does not drive is READ-only and her words say "choose it on the list yourself - I cannot pick for you here" in the same turn; `ev=resume`, `ev=unhide` and the re-walk are retired |
| §9 E4, hardening: the guard, `fSilenceTimeout`, the PENDING park with its corner note, the emergency vanilla-menu key | the list simply stays on screen (HELD); the emergency key and `iKeyVanillaMenu` are removed |
| §7 E2 (d), `<she_may_raise>` and W7 `qi=` (`bQuestInitiative`, `iQuestInitiativeGap`) | retired; the rest of E2 (`qal=`, `qgiver=`, `<what_you_can_ask>`, `<your_quests>`, `note=`) stands |
| §18 E6's arrest hand-back (`iCritical`, `bCrimeManual`) | an arrest is never picked by voice at any setting; both controls are retired for a text row, "Guards, arrests and bounties: always yours to click" |
| "the walk-away flag makes a line a commit" (the two-step of §6 / §8) | a walk-away flag guards LEAVING a list, never clicking (the leave guard); commits ask once unless his own sentence said the line plainly (explicit), a bare "yes" answers her naming question, and a single-entry layer releases on his answer (PROTOCOL 10.29 section 5) |
| the "again" turn: `lrg_dlgtalk` after a glue open | never requested: the pre-LLM open (five narrow clauses) brings the list up BEFORE the model runs, and her one bridging line settles nothing |
| §26 "Done": `bMenuless` / `bDlgDryRun` ship **0 / 1** | v1.0 ships **1 / 0**, `bAutoAdvance` 1, `iSceneGate` 1, `iTailMax` 16, `bDriveSceneMenus` 1, `iKeyPushToTalk` 29 |

### 27.2 Kept from this plan

E6 service dialogue (plus the pre-LLM open by KIND and the kind pick on a root list), E7 fact locking and the truth gate
(plus the `reward` class and `hide_reward`), E8 latency and `ev=lat`, the D1 + D2 double delivery - with two
single-route exceptions: the pre-LLM `do=open` rides D2 ONLY and `ExtCmdLRG_QuestEntry` D1 only; D2 is claimed on every
DLL poll (observed 0.1-0.5 s after D1), the old "5 s" was a sampled log row, and D2's latency mid-request is a
first-evening check (PROTOCOL 10.6, 10.29 section 13) - the prompt index and its own schema, the price-list slot matcher
(plus the days list and the chained sentence), `lrgFacRoad` and the Helgen words, the X1 measurement line, and
`LRG_DlgUI`'s hide primitives (compiled, unused, for a possible opt-in later).

### 27.3 New in v1.0 (each in PROTOCOL 10.29)

The journal-scene test on both sides (`sj`, `sq`, `sqj`) and the scene rail with its kill switch
`dialogue.session.drive_scene`; `drv=` and the read-only clause; `StopDriving` and `ev=stopped`; the HELD state; the
three releases (explicit, bare yes, single entry) and the commit override file `config/lrg_dialogue_overrides.default.json`;
the line-end grace (`adv=`, `rearm=1`, `bAutoAdvance`) and the talk key (`iKeyPushToTalk`); the stage rail and
`clicks_ok`; voiced reasons for the failures (in the same turn only for what the server foresees; a game refusal of a
pick is a corner note, a loop failure is told on his next turn - CHIM never voices a SelectTopic result, PROTOCOL 1.6
[v1.0]); the reward line and gate B's bounded bonus (`give=`, OFF);
`tools/test_questline.php`, the questline coverage harness that walks the main quest and the guild openings over the
real index.

### 27.4 Release gates (spec section 2.4)

Gate A = v1.0: every suite green twice on the final tree, `tools/test_questline.php` green over the live index
(including `--first-evening`), `tools/compile.ps1` clean, `tools/test_mcm_wiring.php` green - then deploy and install
with backups. Gate B = v1.0.1, after one green evening in game (the evidence lines are listed in PROTOCOL 10.29 section
13): the reward bonus on, the Papyrus diet, and 10.26's deletion decided on evidence. The integration status of the
tree at the time of writing, with every red item and its owner, is in `research/pt19c-F.md`.
