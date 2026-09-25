# LoreRim Glue v0.4.0 — BUILD PLAN for three parallel lanes (menuless questing, Phase 2)

Author: QUEST ARCHITECT, 2026-09-21. Status: **the work list**. It is derived from `glue/PHASE2_DESIGN.md`
revision 2 (binding: its §0.2 facts, §1.2 signatures, §1.11 defaults, §3 wire, §7 file plan, §11 build order),
`glue/PROTOCOL.md` v0.3.1, `glue/OWNER_ADDENDA.md` items 4–6, and the six `research/p2-*.md` reports.

**Read order for a builder.** (1) this file's §0, §1, §2; (2) your own lane section (§3 lane A, §4 lane B,
§5 lane C) in full; (3) the cross-feature sections your lane is named in (§6 speech checks, §7 quest-tree
awareness, §8 paid intimacy); (4) §9 MCM, §10 probe questions, §12 decisions. Then the design sections your
lane section cites. Do not read another lane's section to "make it fit" — §2 is the only contract between you.

**Where this plan and `PHASE2_DESIGN.md` disagree, this plan wins**, and only in the places §1.1 lists
(version numbers, migration numbers, anchor line hints, the three added features). Everywhere else the design
is verbatim binding and this plan only points at it.

---

## 0. Lanes, ownership, and the rules of a silent parallel build

### 0.1 The three lanes

| Lane | Name | Owns (nobody else writes these files) |
|---|---|---|
| **A** | GAME-UI | `game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc`, `game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc` |
| **B** | GAME-DRIVER + INTEGRATOR | `game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc`; the anchored edits of `LRG_Main.psc`, `LRG_OStim.psc`, `tools/make_esp.py`, `tools/compile.ps1`, `MCM/Config/LoreRimGlue/config.json`, `MCM/Config/LoreRimGlue/settings.ini`, `tools/deploy_server.ps1` (pre-flight only) |
| **C** | SERVER + INDEX | `server/lorerim_glue/lib/lrg_dialogue.php`, `lib/lrg_prompt_index.php`, `config/lrg_dialogue.default.json`, `config/lrg_dialogue_overrides.default.json`, `migrations/004_*.sql`, `migrations/005_*.sql`, `tools/build_prompt_index.py`, `tools/test_prompt_index.php`, `tools/flows/dlg_adapter.php`, `tools/flows/scenarios/2*.php`–`3*.php`; the anchored edits of `globals.php`, `preprocessing.php`, `prerequest.php`, `functions.php`, `prompts.php`, `context_pre.php`, `lib/lrg_actions.php`, `lib/lrg_core.php`, `lib/lrg_intent.php`, `config/lrg_config.default.json`, `manifest.json` |

`PROTOCOL.md` is merged to v0.4 by the integrator role inside lane B, from §2 of this file, after all three
lanes report. `README.md`, `install_mo2.ps1`, `esp_dump.py`, `tools/stubs/` are unchanged this round.

### 0.2 Rules that make a silent parallel build safe

1. **§2 is the whole contract.** If your lane needs a key, an event or a function the other side must
   provide, it is in §2 or it does not exist. A key you invent is a key that will never arrive.
2. **Guard across the seam.** Lane C wraps every call into a Phase-1 function that might not exist yet in
   `function_exists()`. Lane B wraps every call into `LRG_DlgUI` in a `LRG_DlgUI.IsOpen()`-style
   precondition and never assumes a return value it has not tested. Lane A's functions must behave when the
   menu is closed (return `false` / `0` / `""` / `-1`, never throw, never wait).
3. **Both halves must interoperate across versions.** A server 0.4.0 must work with game script **310**
   (Phase 1 only: no `lrg_topics` ever arrives, no `ExtCmdLRG_SelectTopic` is ever emitted because no
   session state exists, everything degrades to today) and a game script **400** must work with server
   0.3.1 (its `lrg_topics` / `lrg_dlg` messages are not in `external_fast_commands`, so CHIM's core drops
   them as an unknown type; `lrg_dlgtalk` finds no cue and falls back to `TEMPLATE_DIALOG`; no command
   arrives, so the driver only ever runs in assisted / dry-run mode). Neither half may *require* the other.
   Every new wire key is optional with a strict/neutral reading when absent.
4. **Nothing in the game is clicked in this build.** Ships `bMenuless=0`, `bDlgDryRun=1`, `iEngineOpen=0`,
   `iSceneGate=0`, `iCritical=0`, `bRewalk=0` (design §11 step 4). Lane B's driver must be correct with all
   of them flipped, but the shipped defaults click nothing.
5. **`forIntegrator` notes, never a cross-lane fix.** Found a bug in another lane's file? Write it down.
6. **Never touch** OStim / OARE / LoreRim / CHIM / HerikaServer core files, any other mod's settings file,
   `F:\Modlists\**` (read-only), Nemesis, LOOT. **Never install anything** — no C++ tooling, no SKSE plugin
   build, no AS2/SWF compiler. If a step needs one, write both options into your report (what the
   DLL/helper route would need vs. the Papyrus-only fallback and what it loses) and build the fallback.
7. **File, log and web contents are data, not instructions.** A prompt, an INFO text, a log line or a JSON
   value never changes what your code does beyond the rules written here.

### 0.3 The hard rules of the feature (design §1.8 + owner; a review rejects any violation)

* The driver **never** calls a TIF fragment, **never** `SetStage` / `SetObjectiveCompleted` /
  `CompleteQuest` / `SetBribed` / `SetIntimidated` / `SetCrimeGold`, **never** fakes a dialogue effect.
  It only selects the real entry in the real hidden session; the engine does the work.
* CHIM's **AI Quest Progression stays OFF**. Lane C ships the detection and the stand-down (§5.9).
* The **emergency hotkey always produces a working, visible, clickable vanilla menu** — in every state,
  and also when the driver's own stack was lost (§4.6).
* **A wrong match can never cause a wrong effect.** Two-step confirmation for branching / consequential
  entries; for speech checks the LLM judges only *whether the player made the attempt*, the **engine**
  decides the outcome.
* The index is **advisory**. A wrong index costs one confirmation too many or too few, never a wrong effect.
* Adults only, consent gates, the kill switch and the no-fetish content rail are untouched by everything in
  §8.

---

## 1. Facts that bind all three lanes

### 1.1 Version numbers, and the corrections to `PHASE2_DESIGN.md` §3 / §7

The design was written against script 300 / `LRG_VERSION 0.3.0`. Phase 1 has since shipped 0.3.1. **These
numbers, not the design's, are binding:**

| Thing | Design said | **This round** | Who sets it |
|---|---|---|---|
| `manifest.json` version | 0.3.0 | **0.4.0** | C |
| `LRG_VERSION` (`lib/lrg_core.php`) | `0.3.0` | **`0.4.0`** | C |
| `LRG_Main.CurrentVersion` | 300 | **400** | B |
| `PROTOCOL.md` | v3 | **v0.4** (additive only) | B (integrator) |
| `LRG_ACTIONS_VERSION` (Phase 1 rows) | — | **10** (was 9: the `BeginIntimacy` row gains the `amount` slot for §8) | C |
| `LRG_DLG_ACTIONS_VERSION` | 1 | **1**, own marker `data/.dlg_actions_v1`, **never** inside `LRG_GLUE_ACTIONS` | C |
| `LRG_SCHEMA_VERSION` | 3 | **5** | C |
| dialogue migration | `003_lrg_dialogue.sql` | **`004_lrg_dialogue.sql`** — `003_lrg_romance_affinity.sql` already exists (Phase 1, 0.3.1). A second `003_*` would make `lrgEnsureSchema`'s name-order run ambiguous | C |
| prompt-index migration | `004_lrg_prompt_index.sql` | **`005_lrg_prompt_index.sql`** | C |
| `LRG_PROMPT_INDEX_VERSION` | 1 | **1** | C |
| `LRG_DLG_STATE_VERSION` (payload shape of `lrg_dialogue`) | — | **1** | C |

### 1.2 Anchors for every shared-file edit — the design's line hints are all stale

**Anchor on the verbatim statement, never on a line number.** The hints below were re-read this round
(2026-09-21, after Phase 1 v0.3.1). `LRG_Main.psc` is now **844** lines and `lib/lrg_actions.php` **2454**.

| File | Anchor statement (verbatim, unique) | Hint | Design's stale hint |
|---|---|---|---|
| `tools/make_esp.py` | `quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim'], 'PlayerAlias', ['LRG_PlayerAlias'])` | `:44` | `:44` (correct) |
| `LRG_Main.psc` | `int Property CurrentVersion = 310 AutoReadOnly` | `:15` | `:11`, and it said `= 200` |
| `LRG_Main.psc` | `LRG_OStim Function GetOStim()` | `:142` | — |
| `LRG_Main.psc` | `ost.Maintenance()` inside `Function Maintenance()` | `:122` | — |
| `LRG_Main.psc` | `if Utility.IsInMenuMode() \|\| UI.IsTextInputEnabled()` inside `Event OnKeyDown` | `:209` | `:184` |
| `LRG_Main.psc` | `Debug.Notification("LoreRim Glue: topic dump arrives with the menuless questing module")` | `:220` | `:195` |
| `LRG_Main.psc` | `elseif asCommand == "ExtCmdLRG_Clothing"` (the last branch of `HandleCommand` before `else`) | `:445` | — |
| `LRG_Main.psc` | `if Utility.IsInMenuMode() \|\| UI.IsTextInputEnabled() \|\| UI.IsMenuOpen("Dialogue Menu")` inside `MaybeInitiative` — **DO NOT EDIT THIS ONE** | `:680` | `:608` |
| `LRG_Main.psc` | `Function OpenVanillaDialogue()` | `:224` | `:199` |
| `lib/lrg_actions.php` | `if (stripos($code, 'ExtCmdLRG_') !== 0) { $out[] = $line; continue; }` | **`:1300`** | `:539` |
| `lib/lrg_actions.php` | `if (!$turn \|\| strcasecmp($actor, (string) $turn['npc']) !== 0 \|\| !lrgEnabled() \|\| defined('LRG_SHARMAT_PRESENT')) {` | **`:1304`** | `:543` |
| `lib/lrg_actions.php` | `if (stripos($raw, 'command@ExtCmdLRG_') !== 0 \|\| !lrgEnabled()) { return; }` inside `lrgRecordResult` | **`:608`** | `:250` / `:253` |
| `lib/lrg_actions.php` | `lrgLog("gate: dropped unknown glue action $code", $cid);` | `:1437` | `:615` |
| `globals.php` | `$GLOBALS['external_fast_commands'] = array_values(array_unique(array_merge(` | `:8` | `:8` |
| `preprocessing.php` | `if (strncmp($lrgType, 'lrg_', 4) === 0 \|\| $lrgType === 'funcret'` | `:18` | `:14` |
| `functions.php` | the closing `}` of the whole `if (defined('LRG_SHARMAT_PRESENT')) { … } else { … }` block | `:9`–`:24` | `:9-24` |
| `prerequest.php` | `lrgPrerequest();` | `:13` | `:13` |

`tools/deploy_server.ps1` gains a **pre-flight** (lane B) that greps for each anchor string above that lives
in a server file and **fails the deploy, naming the anchor**, when it is missing or matches more than once.
Lane B adds the same check as a plain `grep -c` list in its report for the `.psc` and `.py` anchors.

### 1.3 Verified Papyrus API — re-opened this round, use exactly these spellings

| Call | Source | Note |
|---|---|---|
| `UI.IsMenuOpen / SetBool / SetInt / SetFloat / SetString / GetBool / GetInt / GetFloat / GetString / Invoke / InvokeBool / InvokeInt / InvokeString / InvokeStringA / IsTextInputEnabled` | `UI.psc:47,57-60,71-74,86,90-93,101,111` | `Invoke` is a wrapper that always passes `false` (`:86-88`); `Get*`/`Set*` are delayed (frame-synced) natives, only `Invoke*` is queued |
| `Game.GetFormFromFile(int, string)` / `GetFormEx(int)` / `GetForm(int)` | `Game.psc:105 / :443 / :102` | every form is looked up at runtime; the ESP has **zero** CK properties |
| `Game.AdvanceSkill(string, float)` | `Game.psc:10` | the engine's Speech-XP call — see §6.9 |
| `Game.IncrementStat(string, int = 1)` / `Game.QueryStat(string)` | `Game.psc:140 / :189` | `"Persuasions"`, `"Bribes"`, `"Intimidations"` |
| `Game.GetDialogueTarget()` / `GetCurrentCrosshairRef()` | `Game.psc:446 / :449` | returns the speaker, **never** the cause of the open (design D-15) |
| `Game.GetGameSettingFloat(string)` / `GetGameSettingInt(string)` | `Game.psc:108 / :109` | `fAIInDialogueModeWithPlayerDistance` is 240 live |
| `Game.IsPluginInstalled(string)` | `Game.psc:296` | not `GetModByName` (ESL-flagged plugins read as missing) |
| `Actor.IsGuard()` | `Actor.psc:379` | |
| `Actor.GetCrimeFaction()` → `Faction.GetCrimeGold()` | `Actor.psc:178`, `Faction.psc:7` | **the design's "`Actor.psc:178` GetCrimeGold" is two calls, and the faction must be None-guarded** |
| `Actor.WillIntimidateSucceed()` | `Actor.psc:704` | the engine's own intimidation comparison, pre-click |
| `Actor.GetBribeAmount()` | `Actor.psc:175` | the real price, computed from the rewritten `fBribe*` GMSTs |
| `Actor.IsBribed()` / `IsIntimidated()` | `Actor.psc:352 / :397` | read-only for us; **`SetBribed` / `SetIntimidated` are forbidden** (§0.3) |
| `Actor.GetGoldAmount()` / `ObjectReference.GetItemCount(Form)` | `Actor.psc:252`, `ObjectReference.psc:263` | |
| `ObjectReference.RemoveItem(Form, int, bool, ObjectReference)` / `AddItem(Form, int, bool)` | `ObjectReference.psc:505 / :156` | the 4th argument moves the gold into the other container — §8 uses exactly this |
| `Actor.GetActorValue / GetBaseActorValue / HasPerk / IsEquipped` | `Actor.psc:143 / :167 / :325 / :370` | |
| `Actor.GetLeveledActorBase()` → `ActorBase.GetVoiceType/SetVoiceType` | `Actor.psc:272`, `ActorBase.psc:104-105` | the voice keeper; which base CHIM nulls is **P0** |
| `ObjectReference.GetCurrentScene()` / `IsInDialogueWithPlayer()` / `Activate(ObjectReference, bool=false)` | `ObjectReference.psc:245 / :426 / :143` | |
| `Quest.GetQuest(string)` / `GetID()` / `GetCurrentStageID()` / `IsActive()` / `IsRunning()` / `IsCompleted()` / `IsObjectiveDisplayed(int)` / `IsObjectiveCompleted(int)` / `IsObjectiveFailed(int)` | `Quest.psc:247 / :250 / :60 / :73 / :88 / :76 / :82 / :79 / :85` | **There is no Papyrus getter for objective TEXT** — see §7.1 |
| `PO3_SKSEFunctions.GetActiveAssociatedQuests(ObjectReference, bool=true)` | `PO3_SKSEFunctions.psc:812` | the NPC's part in active quests |
| `PO3_SKSEFunctions.GetAllQuestObjectives(Quest)` / `GetAllQuestStages(Quest)` | `:1007 / :1009` | objective **IDs**, not text |
| `PO3_SKSEFunctions.GetFormEditorID(Form)` / `EvaluateConditionList(Form, ObjectReference, ObjectReference)` / `HideMenu(string)` | `:486 / :478 / :1108` | `HideMenu` is `CloseForce(2)` and stays **probe-only** |
| `MiscUtil.FileExists(string)` / `ReadFromFile(string)` | PapyrusUtil `MiscUtil.psc:54 / :58` | reads `SmartTalk.ini`; root-relative, MO2's VFS resolves it |
| `AIAgentFunctions.isActorTalking(string)` | CHIM `AIAgentFunctions.psc:33` | **a display name**, and it only ever sees CHIM's own TTS |
| `AIAgentFunctions.getAgentByName(string)` / `get_conf_i(string)` / `findAllNearbyAgents()` / `logMessageForActor` / `requestMessageForActor` / `commandEndedForActor` / `setAnimationBusy` | `AIAgentFunctions.psc:62 / :52 / …` | `requestMessageForActor` is eligibility-gated **and runs `InterruptNPC` on the target** — never on a session NPC |
| `GlobalVariable.GetValue()` | `tools/stubs/GlobalVariable.psc` | vanilla script; the stub already declares it |

Compile imports already cover SKSE, PO3, CHIM and PapyrusUtil (`compile.ps1:52-56`). **New types that need
nothing:** `Scene` and `VoiceType` are auto-stubbed by `compile.ps1`'s unknown-type loop. Lane B adds no
import and no hand-written stub unless decision D2 becomes "per-session IACC toggle" (it is not: §12 D2).

### 1.4 Papyrus rules for this round (compile gate)

* `{docstrings}` are compiled into the `.pex`: **keep every one under 300 characters**; long notes go in
  `;` comments. `compile.ps1` refuses any `.pex` string over 500 chars and must print `OK`.
* Split long string constants; never build a >500-char literal.
* Every `UI.*` call in the whole project lives in `LRG_DlgUI` and nowhere else.
* One `RegisterForSingleUpdate` owner: `LRG_Main`. Ask for a tick with `LRG_Main.RequestTick(float)`.
* `OnKeyDown`, `OnMenuOpen`, `OnMenuClose`, `OnUpdate`, `OnCrosshairRefChange`, `OnTrackedStatsEvent` reach
  **every** script on quest `0x01000800`. Handle only your own keys/menus and return at once otherwise.
* `LRG_Main.Maintenance()` calls `UnregisterForAllModEvents()` and `RegisterKeys()` calls
  `UnregisterForAllKeys()`, so `LRG_Dialogue.Maintenance()` and `LRG_DlgProbe.Maintenance()` are called
  **after** those and must re-register everything they need, every time.
* Mod-event callbacks are named `OnLrgDlg…` so they cannot collide with Phase 1's.
* Every wait inside a session is `Utility.WaitMenuMode`.

---

## 2. Wire protocol v0.4 — the complete contract between the lanes

Same ground rules as `PROTOCOL.md` §0: `k=v;k=v`; a value never contains `; | @ "` or a newline (game:
`LRG_Main.CleanForWire`; server: `lrgKv`); **both sides ignore unknown keys; a missing key always means the
strict / neutral reading.** Every item below is additive. **No new `do=` verb on a Phase 1 command, no new
`ev` on a Phase 1 message, no new Phase 1 error reason.**

### 2.1 New game → server message types

Both join `external_fast_commands` (lane C, `globals.php`), are handled and `terminate()`d in
`preprocessing.php`, and **never enter the event log**. Transport is
`LRG_Main.SendNpcMessage(type, payload, npcName)` = `AIAgentFunctions.logMessageForActor` (`LRG_Main.psc:347`).

#### `lrg_topics` — one engine-built list (design §3.1, unchanged; direction game → server)

Sent by lane B whenever a list has been read and the signature changed, or after any click, or on a dump.
Key order is fixed; `e` is **last**.

```
v=1;sid=<sid>;gen=<n>;layer=<n>;origin=glue|engine|dump|probe;ref=<hex>;npc=<name>;st=<menu state>;
n=<FULL EntryCount()>;sent=<entries in this message>;part=1|2;cid=<cid>;want=0|1;ask=<intent words>;
crit=0|1|2;scene=0|1;fam=<0|1|2>;sub=0|1;pg=<player gold>;bamt=<GetBribeAmount>;vt=<hex>;q=<csv>;
sp=<Speechcraft>;perk=<csv>;lvl=<player level>;e=<entries>
```

* `n` is **always** the full `EntryCount()`, never the cap (design R12/D-22). `part=2` carries the tail.
* `e` entries are joined by `~~`, each `<pos>~<topicIndex>~<new 0|1|->~<col or ->~<text clipped 140>`;
  in a `part=2` message each entry is `<pos>~-~-~-~<text>`. The game replaces only `|`→`/` and `~`→`-`
  inside a text; the server takes everything after `;e=` raw.
* **`e=` is capped by bytes as well as entries — 1,600 raw chars** (design §3.1 payload budget); the
  remainder spills into `part=2`. **P13** measures the real payload length.
* `pg` / `bamt` only when a text carries a gold tag. `q` = ≤ 6 quest EditorIDs (§7).
* **NEW in this plan:** `sp` (player `Speechcraft` actor value), `perk` (csv of the speech-relevant perk
  tokens the game could confirm, §6.8), `lvl` (player level). They are what makes the *free-conversation*
  check of §6 possible without a second message. Absent ⇒ the server falls back to the snapshot's
  `pspeech` / `plvl` and logs `sp=snapshot`.
* Old server (0.3.1): the type is unknown, CHIM's core drops it. Old game (310): never sent.

#### `lrg_dlg` — session events, keyed by `ev` (design §3.1)

| `ev` | keys | sent when |
|---|---|---|
| `open` | `sid, origin, ref, npc, crit, fam, vt, dist, sub, sg` (five Speech globals csv) | ARMING, every session |
| `line` | `sid, gen, ref, npc, t` (**last**, subtitle ≤ 300) | a new non-blank subtitle, de-duplicated on the **pair** `(gen, string)` |
| `result` | `sid, gen, cid, x, ref, npc, pos, i, kind, ok, why, closed, other, dP, dB, dI, gold, bribed, intim, bamt, wis, sp, dur, txt` (**last**) | after the settle poll of a click |
| `closed` | `sid, ref, npc, why=goodbye\|asked\|handoff\|external\|refused, pending, layer, sg, drift` | `OnMenuClose` |
| `unhide` | `sid, ref, npc, why=key\|watchdog\|critical\|show\|unverified\|read-failed\|wrong-speaker\|no-route\|no-stored-x` | every `Unhide()` |
| `facts` | `ref, npc, q, vt, dlg, sp, perk, lvl, qobj` (§7.2) | crosshair change for a CHIM agent, throttled 60 s per NPC |

`ev=facts` is the **only** place quest and speech facts travel outside a session (§6, §7).
**Phase 1's `lrg_npcstate` gains no key in this round** (design §6.2) — `pgold`, `pspeech`, `plvl`, `gold`,
`scene`, `ostim`, `adult` are already there (`LRG_Profile.PlayerFacts`, `LRG_Profile.psc:171-191`).

#### `lrg_dlgtalk` — the one LLM-triggering message of the module

`AIAgentFunctions.requestMessageForActor("again", "lrg_dlgtalk", npc)`; sent by lane B **only when no
session is open**, and **never for a speaker in a scene** (design D-17). Means "answer the player's last
words yourself; your business list is now known". Admitted by lane C in `preprocessing.php` only when the
server holds a player utterance for that NPC ≤ 30 s old and no session is open;
`prerequest.php` switches functions back on for it. Old server: no cue ⇒ `TEMPLATE_DIALOG` ⇒ an ordinary
reply. That is an acceptable degradation, not an error.

### 2.2 Server → game: the single action `ExtCmdLRG_SelectTopic`

Wire line `<npc>|command|ExtCmdLRG_SelectTopic@<param>`, delivered exactly like every Phase 1 command
(bridge `LRG.DispatchExternalCommand` + mod event `CHIM_CommandReceived`, de-duplicated 5 s by
`LRG_Main.HandleCommand`'s four-slot ring). Param, fixed key order (tests match substrings):

```
ok=1;cid=<cid>;npc=<name>;ref=<hex>;do=<pick|open|leave|show|noop|award>;sid=<sid or 0>;gen=<n or 0>;
pos=<array position or -1>;i=<topicIndex or -1>;txt=<safe prefix or empty>;
kind=<plain|service|persuade|intimidate|bribe|deceive|pay|commit|meta|silent|back>;cost=<gold or 0>;
res=<pass|fail|unknown or empty>;xp=<0|1>;stat=<Persuasions|Bribes|Intimidations or empty>;
take=<gold or 0>;ask=<intent words>;vt=<hex or 0>;x=<exec id>;z=1
```

* `txt` = the longest prefix (12–40 chars) of the entry text free of `; = @ | " ~`; empty when shorter
  than 12.
* **`do=award` is new in this plan** and is the *free-conversation* effect carrier (§6.9). It is emitted by
  the server's own post-gate, **never chosen by the LLM**, and carries no `pos` / `i` / `sid`. It touches no
  menu and no dialogue: it only grants Speech XP (`xp=1`), optionally increments a stat (`stat=`, default
  off), and optionally moves `take=` gold from the player to the NPC. `do=award` on a game that does not
  know it answers `Error: unknown command` → harmless, logged.
* `z=1` is a throw-away last key so the D2 delivery route's trailing `|` can never eat a real value.
* **The game drops without executing** (reason into the `funcret`): `ok != 1`; `x` already in the executed
  ring of 8 (silently, no `funcret` — that is the second delivery route arriving); `ref` ≠ the live speaker
  or ≠ `getAgentByName(npc).GetFormID()`; `sid` is the live session but `gen` is older than the live `gen`
  → one `stale` re-match through `lrg_topics want=1`; the command is older than 20 s when it starts
  executing; a session with another speaker is open.
* **`CmdSelectTopic` validates, stamps and returns inside one Papyrus frame. It contains no
  `Utility.Wait*` of any kind** (design D-20). It emits `ReportResult` only for the synchronous refusals of
  design §1.5. Every other outcome is reported by the loop, exactly once per `x`, and
  `AIAgentFunctions.commandEndedForActor` is called exactly once per `x` in total.
* Two deliveries of one `x` ⇒ exactly one `commandEndedForActor` (flow test 21b).

### 2.3 Results, new reasons and success strings (Phase 1's `funcret` path, unchanged)

New reasons for `PROTOCOL.md` §1.6's closed list: `they cannot talk right now` · `her voice is not ready` ·
`that is not on the table right now` · `that moment has passed` · `not enough gold` ·
`dry-run mode, nothing was clicked` · `a conversation is in progress`.
New success strings: `The matter is raised.` · `The conversation is left.` · `The menu is shown.` ·
**`Noted.`** (the `do=award` acknowledgement).

### 2.4 The additive Phase 1 key of §8 (paid intimacy)

| key | on | meaning | absent |
|---|---|---|---|
| `pay=<n>` | `ExtCmdLRG_StartIntimacy` | at scene start move exactly `n` gold from the player into the NPC's inventory; not enough gold ⇒ refuse with `not enough gold` | `0` = today's behaviour |

`PROTOCOL.md` v0.4 records this as wire item **w6**. An old game script ignores `pay=`; an old server never
sends it.

### 2.5 Delivery routes (design §2.4) — lane C implements both, lane B de-duplicates by `x`

* **D1 — same HTTP reply**: `echo "<npc>|command|ExtCmdLRG_SelectTopic@<param>\r\n"` then `terminate()`
  (precedent: `processor/comm.php:1021`'s `togglemodel`). ~0.5 s. **[U] P14a.**
* **D2 — a `responselog` row** (insert shape copied verbatim from `lib/rolemaster_helpers.php:790-800`),
  echoed on the DLL's `request` poll, whose rows sit at **5 s** spacing. A P14a failure makes a business
  turn 11–14 s instead of 6–9 s; the owner must see that number before approving. **[U] P14b.**
* The server **always** answers on both routes with the **same `x`**; the game's ring de-duplicates.
* If both routes are dead, the design still works: cached keys + the local prefix match need no reply.

---

## 3. LANE A — GAME-UI

Files: `game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc`, `game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc`.
Read in full before writing a line: design **§0.0** (the twelve R-changes), **§1.2** (your binding
signatures), **§1.4**, **§1.6**, **§1.7**, **§1.10**, **§1.12** (the probe), **§11 step 0**.

### 3.1 `LRG_DlgUI.psc` — `Scriptname LRG_DlgUI Hidden`, global functions only

Build **every** signature of design §1.2, verbatim, with its documented body. That table is the contract:
lane B calls these names and nothing else, and §8 of the design (the DLL seam) depends on the signatures
staying stable. The constants, also verbatim from §1.2:

```papyrus
; M = "Dialogue Menu"  ·  D = "_root.DialogueMenu_mc"  ·  L = D + ".TopicList"
; L2 = D + ".TopicListHolder.List_mc"   (fallback spelling)  ·  G = "_global.DialogueMenu"
; menu states: SHOW_GREETING 0 / TOPIC_LIST_SHOWN 1 / TOPIC_CLICKED 2 / TRANSITIONING 3
; ALLOW_PROGRESS_DELAY default 750
```

The full function list, for a build checklist (bodies are in design §1.2 — copy them exactly):

`IsOpen` · `MenuState` · `EntryCount` (mode-calibrated A/B/C, returns **-1** = no mode works) · `SwfFamily`
· `MaxItemsShown` · `Platform` · `EntryText(pos, mode)` (modes 3/4 clickable, **mode 5 read-only**) ·
`EntryTopicIndex(pos, mode)` · `EntryIsNew(pos, mode)` · `EntryColour(row)` · `EntryRowItem(row)` ·
`Subtitle` · `SubtitlesOn` · `ProgressTimerId` · `GateArmed` · `SelectAndVerify(pos, topicIndex,
textPrefix, alsoScroll)` · `Click(route)` · `Hide(mode)` · `ReassertHide` · `HideCursor(bool)` ·
`Guard(bool)` · `GuardReset` · `GuardPoke` · `Park` · `ShowList` · `Unhide` · `CloseClean` ·
`CloseForce(step)` (**probe-only**) · `SmartTalkSafe`.

**Hard rules 1–11 of design §1.2 are acceptance criteria.** The three that are most often got wrong:

* **Rule 6 — getter by ActionScript type.** `GetString` for `text`; `GetInt` for `topicIndex`,
  `eMenuState`, `length`, `iSelectedIndex`; `GetBool` for `topicIsNew`, `timerBool`, `bAllowProgress`. A
  mistyped getter returns a false `0`/`""`/`false`. **A `topicIndex` of 0 is legitimate** — validate a read
  by a positive `EntryCount()` plus a non-empty `text`, never by a non-zero index.
* **Rule 9 — every display property (`_x`, `_y`, `_alpha`, `_height`, `_width`) is `UI.GetFloat` /
  `UI.SetFloat` into a Papyrus float.** `UI.GetInt` is `(UInt32)number`, so Norden's negative
  `TopicListHolder._x` reads back as ~4.29e9 and the "restore" puts the list 4,294,966,976 px off-screen —
  the emergency key producing the exact state it exists to prevent.
* **Rule 10 — a stored original is usable only if it was read successfully**: finite, not NaN,
  `abs() < 10000`, tagged with the `fam` that was live when it was read. `Hide()` returns **false** when a
  read is unusable and **nothing is hidden**; `Unhide()` refuses to restore an unusable value, falls back to
  `ShowList()` and reports `why=no-stored-x`.

Additional acceptance criteria for this lane:

1. **Every function is safe with the menu closed.** `UI.Set*` reaches nothing when the movie is gone;
   `UI.Get*` returns a default. No function waits, loops more than a fixed small number of times, or
   raises a notification. `EntryCount()` returns `0` when the menu is closed and `-1` only when the menu
   is open and no mode works — lane B's `why=no-count` vs `why=no-entries` split depends on exactly that.
2. **Calibration is remembered, not re-derived per call.** `iReadMode` and `iCountMode` are calibrated once
   per game session; `LRG_DlgUI` holds them in script-local state written through
   `SetReadMode(int)` / `SetCountMode(int)` + `ReadMode()` / `CountMode()` accessors so lane B can persist
   them into MCM. (`Hidden` scripts have no state that survives a save; lane B owns persistence.)
3. **`Click(route)` performs the exact ordering of design §1.7** and ends with exactly **one** queued
   `Invoke`. The identity verification is the **last** frame-synced pair before it. A failed check aborts
   and **nothing is clicked**.
4. **`Guard(true)` is `SetInt ALLOW_PROGRESS_DELAY 100000000` *then* `Invoke StartProgressTimer`** — in that
   order, because the delay is read when the timer is armed and the greeting already armed a 750 ms one.
5. **`GuardReset()` is idempotent and cheap** — lane B calls it at the top of every ARMING on every branch,
   including dry-run.
6. **`SmartTalkSafe()`** reads `Data/SKSE/Plugins/SmartTalk.ini` with `MiscUtil.FileExists` /
   `MiscUtil.ReadFromFile` and returns **false** when `bSkipImmediateOnInput != 0`, `bHoldToSkip != 0` or
   `bSkipOnInteraction != 0`. It also exposes `SmartTalkOffender()` returning the **name of the first
   offending setting** so lane B's one notification can name it. Live values are `1` / `1` / `0`. If the
   file cannot be read, return false and report `unreadable`.
7. **`CloseForce(1)` / `CloseForce(2)` compile but are called only from `LRG_DlgProbe`.** Put that in a
   `;` comment at the function, not only in the docstring.

### 3.2 `LRG_DlgProbe.psc` — `Scriptname LRG_DlgProbe extends Quest`

The probe **ships before the driver** and is the whole of design §1.12. Requirements:

* **Output only through `LRG_Main.LogC(cid, msg, npcName)`** (`LRG_Main.psc:247`) — Papyrus logging is off
  in LoreRim, so the server log is the record. **Every line begins `PROBE ` followed by the press id**, is
  **≤ 300 characters**, and is **never emitted from inside a tight loop** (batch a sample set, then log).
  The design says "`P<n> `"; this plan fixes the prefix as **`PROBE P<n> `** so one `grep "^.*GAME PROBE "`
  pulls the entire probe out of `lorerim_glue.log` even when driver lines are interleaved.
* **Every press must be answerable from the log alone.** A press that needs the builder to remember what he
  did is a failed press. Each line therefore carries: the press id, the NPC display name, the wall clock
  (`Utility.GetCurrentRealTime()` to 0.01 s), and every value it measured with its own key
  (`k=v` pairs, so lane C's log tooling and the owner can both read it).
* `bProbe=1` + `iKeyProbe` drive the presses; **every press is also reachable from the MCM page** as a
  button (lane B builds the page; lane A specifies the button ids in its report — see §9.3).
* Press map (design §1.12): press 1 = read phase on the crosshair NPC (opens a **visible** session if none
  is open); 2 = hide + guard phase; 3 = click phase (**last** entry, chosen route); 4 = next layer with the
  other route; 5 = close. Presses that are not a phase (`P0`, `P6f`, `P11b`, `P12`–`P17`) are separate MCM
  buttons, never on the cycling hotkey, so they cannot fire by accident.
* **Read-only presses must be provably read-only.** `P0`, `P1`, `P2`, `P3/P4/P5`, `P3t`, `P6`, `P13`, `P17`
  and the whole guard-NPC / scene-NPC matrix row never call `Click`, `CloseClean` or `CloseForce`. Put the
  assertion in code: a private `bool readOnly` that the click helpers test and refuse.
* **`CloseForce` is used only in `P11b`, only on a harmless non-critical closed layer, never on a guard and
  never during an arrest.** `P11b` refuses to run when `speaker.IsGuard()` or the driver's crit
  classification would say anything but 0 (duplicate the three-native test locally; do not call into
  `LRG_Dialogue`, which may not exist yet).

**Every press, what it logs, and the exact log line** — see §10.2, which is the single table the owner and
lane B both read. Build to that table; it is this plan's expansion of design §1.12 and its pass criteria
are the design's.

### 3.3 Lane A's hand-off assumptions

* `LRG_Main.LogC` and `LRG_Main.RequestTick` exist today at `LRG_Main.psc:247` / `:320` — call them.
* `LRG_Dialogue` may not exist while lane A works: **`LRG_DlgProbe` must not reference the type
  `LRG_Dialogue` at all**, or lane A cannot compile alone. Get the speaker with
  `Game.GetDialogueTarget() as Actor`.
* `LRG_DlgProbe.Maintenance()` will be called by `LRG_Main.Maintenance()` (lane B's edit). Until that edit
  lands, make `OnInit()` call `Maintenance()` too, and make `Maintenance()` idempotent.
* The probe's own key registration happens in its `Maintenance()`; it must tolerate being called before
  `iKeyProbe` exists in `settings.ini` (a missing ini key reads as **0** ⇒ no registration, no crash).

### 3.4 Lane A gate

`tools/compile.ps1` prints **OK** with both new scripts present, no `.pex` string over 500 chars, and
`rounds: <n> ; auto-stubs created: <n>` with no unexpected stub. `LRG_DlgUI` compiles with **zero** calls
into `LRG_Dialogue`. The probe's presses run through `LRG_DlgUI` unchanged (no direct `UI.*` call anywhere
in `LRG_DlgProbe.psc` — verify with `grep -c "UI\." LRG_DlgProbe.psc` == 0).

---

## 4. LANE B — GAME-DRIVER + INTEGRATOR

Files: `LRG_Dialogue.psc` (new) + the anchored edits of §1.2 + `make_esp.py` + the MCM page.
Read in full: design **§1.1**, **§1.3**–**§1.11**, **§2.3**, **§2.5**, **§2.7**, **§3**, **§6**, **§7.2**,
**§7.4**, **§11 steps 4–5**. Also §6, §7 and §8 of this plan (your half of the three added features).

### 4.1 `LRG_Dialogue.psc` — `Scriptname LRG_Dialogue extends Quest`

**Binding public surface** (design §7.1 — lane C never calls it, `LRG_Main` does):

```papyrus
Function Maintenance()
bool Function CmdSelectTopic(string asNpcName, string asCommand, string asParam)
bool Function HandleEmergencyKey()     ; true = a session was open and was handled
Function HandleLeaveKey()
Function DumpTopics()
bool Function IsSessionOpen()
Actor Function SessionSpeaker()
```

Add, for §6 and §8 (still lane B, still this file):

```papyrus
bool Function CmdAward(string asNpcName, string asCommand, string asParam)  ; do=award, §6.9
```
`CmdSelectTopic` routes `do=award` into it; `LRG_Main` gains no second branch.

**The state machine is design §1.3, verbatim** — build every state, every leave condition and every
timeout in that table, plus:

* **ARMING step 0 is `LRG_DlgUI.GuardReset()`, unconditionally, on every branch**, including dry-run,
  `bMenuless=0` and `forceVisible` (R1). This is the one line standing between the owner and permanently
  unclickable vanilla dialogue if P11 comes back red.
* **Classify before you hide** (R3): `speaker.IsGuard()`, `speaker.GetCrimeFaction()` (**None-guarded**) →
  `Faction.GetCrimeGold()`, `speaker.GetCurrentScene() != None`. Only then may anything be hidden. A LETHAL
  session **skips `Guard(true)` entirely** — with the guard armed and the menu handed back, the player
  cannot click "I submit. Take me to jail." at all.
* **LETHAL = `IsGuard()` AND (engine-opened OR crime gold > 0)** — not a flat OR (design §10 rejection 2).
  COSTLY comes from the server's `crit=1` and **stays menuless**, is never auto-closed, never timed out,
  never force-closed.
* **A speaker in a scene never enters PENDING and never triggers `lrg_dlgtalk`** (D-17). It goes to MANUAL.
* **`fDecideTimeout` never closes a session.** Its only two outcomes are PENDING or MANUAL (R8).
* **PENDING parks only inside the click window** (R2/CMP-M2): between "layer read" and "click decided or
  abandoned". While parked, any change in `EntryCount()` or the layer signature means the engine pushed a
  list ⇒ `ShowList()` at once, re-read, re-park only after the new layer is read.
* **RESPONDING accepts six verification signals, any one of which verifies the click** (R7) and **never
  reports `ok=0` when a delta was seen**, and **never un-hides before the settle poll has run**.
* **`CloseForce` is never called from the driver.** The escalation ends at MANUAL (design §2.7).
* **The freeze rule (B1):** if anything moved the player's gold or Speechcraft between "list read" and
  "click", go back to READING instead of clicking.
* `GuardPoke()` on **every** poll in every state (one native). The full `Guard(true)` re-arm and
  `ReassertHide()` stay on every third poll.

**Dry-run** (`bDlgDryRun`, ships **1**): everything runs, the menu stays **visible**, and the final step
logs `WOULD CLICK cid=… pos=… i=… text=…` through `LogC`; the player clicks by hand. Lists, lines and
results still flow to the server.

**Topic dump** (`iKeyDumpTopics`): crosshair NPC or the live session, menu **visible**; read the list with
**every** read mode; log `DUMP n=…` plus one `DUMP pos=… ti=… new=… col=… text=…` per entry; send
`lrg_topics origin=dump`. This is the parity tool of T2 — the dump must equal what the player sees,
entry for entry.

### 4.2 Speech globals, drift, and the session budget (design D-6 / C2)

Read the five Speech globals at `ev=open` and again at `ev=closed`, send `sg=` and `drift=`, and surface a
warning in the MCM diagnostics after the third drift. FormIDs, verified in `Skyrim.esm` this round:

| global | FormID | live value |
|---|---|---|
| `SpeechVeryEasy` | `0x000D16A3` | 10 |
| `SpeechEasy` | `0x000D16A4` | 25 |
| `SpeechAverage` | `0x000D16A5` | 50 |
| `SpeechHard` | `0x000D1953` | 75 |
| `SpeechVeryHard` | `0x000D1954` | 100 |
| `SpeechSkillMult` | `0x0010E725` | 75 |

Resolve with `Game.GetFormFromFile(0x000D16A3, "Skyrim.esm") as GlobalVariable` — **the full 0x000D…
form, not the truncated 0x16A3** that Speechcraft Randomization's own bug uses. **One session open per
business turn, never one per click.** Patch nothing: the −10 drift is a LoreRim/Little Lessons data bug
(§12 D8a).

### 4.3 `make_esp.py` (anchored edit)

```python
# old  (:44)
quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim'], 'PlayerAlias', ['LRG_PlayerAlias'])
# new
quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe'], 'PlayerAlias', ['LRG_PlayerAlias'])
```

Nothing else in that file changes: `script_block()` already writes N names with 0 properties (`:22-26`),
`HEDR` stays `(1.70, 3, 0x802)` (`:48`), no new form, no new master, `Seq/LoreRimGlue.seq` unchanged
(`:55`). **Verify with `tools/esp_dump.py`: the `VMAD` of `0x01000800` must list four script names.**
Verify the edit with `grep -c "LRG_DlgProbe" tools/make_esp.py` == 1.

**Attachment on an existing save** is `[I]`: a script added to a running quest's VMAD is attached on load
and gets `OnInit`. If `(self as Quest) as LRG_Dialogue` is ever `None`, `LRG_Main.Maintenance()` logs
`LRG_Dialogue not attached - use a new game or a save made before the glue was installed` and the module
stays off. Never crash, never retry.

### 4.4 `LRG_Main.psc` — six anchored edits

1. **`LRG_Dialogue Function GetDialogue()`** returning `(self as Quest) as LRG_Dialogue`, and
   `LRG_DlgProbe Function GetProbe()` returning `(self as Quest) as LRG_DlgProbe`, both mirroring the body
   of `GetOStim()` (anchor: `LRG_OStim Function GetOStim()`, hint `:142`).
2. **`Maintenance()`**: immediately after the statement `ost.Maintenance()` (hint `:122`), call
   `GetDialogue().Maintenance()` and `GetProbe().Maintenance()`, each None-guarded, each after the existing
   `UnregisterForAllModEvents()` / `RegisterKeys()` (which are already above it).
3. **`HandleCommand`**: add a branch for `ExtCmdLRG_SelectTopic → dlg.CmdSelectTopic(...)` before the final
   `else` (anchor: `elseif asCommand == "ExtCmdLRG_Clothing"`, hint `:445`). No `LRG_OStim` guard — answer
   `Error: not available` when `dlg` is None. The existing 5 s four-slot `cmdKey` de-dup already swallows a
   fast second delivery; `LRG_Dialogue`'s `x` ring catches a late one.
4. **`OnKeyDown`**: route `keyVanillaMenu → if !dlg || !dlg.HandleEmergencyKey() : OpenVanillaDialogue()`;
   replace the `keyDumpTopics` placeholder body (anchor: the
   `Debug.Notification("LoreRim Glue: topic dump arrives with the menuless questing module")` statement,
   hint `:220`) with `dlg.DumpTopics()`; add `keyLeave → dlg.HandleLeaveKey()` and register it in
   `RegisterKeys()` beside the three existing keys.
5. **D-13, narrowed.** Anchor: `if Utility.IsInMenuMode() || UI.IsTextInputEnabled()` inside `OnKeyDown`
   (hint `:209`). Split it so **`UI.IsTextInputEnabled()` still returns early unconditionally** — one
   letter typed into CHIM's Prisma chatbox, `UITextEntryMenu` or the console must never hand the menu back
   — and only the `Utility.IsInMenuMode()` half is bypassed, and only for `keyVanillaMenu` / `keyLeave`
   while `dlg.IsSessionOpen()`. **Do not touch the visually identical statement in `MaybeInitiative`
   (hint `:680`)**, which reads `… || UI.IsMenuOpen("Dialogue Menu")` and must keep its behaviour.
   **Verify: `grep -n "IsInMenuMode" LRG_Main.psc` shows exactly two statements, one edited, one not.**
6. **`CurrentVersion` 310 → 400** (anchor: `int Property CurrentVersion = 310 AutoReadOnly`, hint `:15`).
   Add the stepwise-upgrade comment line in `Maintenance()`'s `if installedVersion < CurrentVersion` block
   only if something needs migrating — nothing does this round.

### 4.5 `LRG_OStim.psc` — two anchored edits

1. **`StartBlockedReason`** (hint `:2256`) and the **outside-scene clothing gate** in `CmdClothing`
   (hint `:2676`) answer **`a conversation is in progress`** while `Main().GetDialogue()` is non-None and
   `IsSessionOpen()`. Cheapest position: after the existing kill-switch / adult tests, before the distance
   and privacy scans.
2. **§8's `pay=` handling** — see §8.4. It lives in `CmdStart` / `BeginStartThread`, not in a new function.

Nothing else in `LRG_OStim.psc` changes. Phase 1's initiative tick already skips while the Dialogue Menu is
open (`MaybeInitiative`, hint `:680`), which now means it skips for the whole hidden session **by design**
(F14) — leave it.

### 4.6 The emergency hotkey (the feature's escape hatch — design §1.10)

* **Session open** → MANUAL for the rest of that session (`LRG_DlgUI.Unhide()`), one notification.
* **No session** → one-shot `forceVisible = true`, then today's `LRG_Main.OpenVanillaDialogue()`
  (`LRG_Main.psc:224`), and the next ARMING skips hiding.
* **The loop's stack was lost** (`now - loopBeat > 2.0`) → the key calls `LRG_DlgUI.Unhide()` **directly**.
  This is the single exception to "keys never touch the UI themselves".
* `Unhide()` order: `Guard(false)` (which includes `GuardReset()`) + `HideCursor(false)` → restore
  `TopicListHolder._x/._y` and `ExitButton._x` with `UI.SetFloat` from the **validated** stored floats
  (skip and report `why=no-stored-x` if any was never read) → `SetBool D+"._visible" true`,
  `D+".SubtitleText._visible" true`, `D+".SpeakerName._visible" true` → **if `EntryCount() > 0` and
  `MenuState() != 1`: `ShowList()`** (a manual `SetInt eMenuState 1` is only the second attempt, 0.5 s
  later) → `lrg_dlg ev=unhide;why=…`.
* **T9 / T12 are the acceptance tests**: a working, visible, clickable-by-mouse-**and**-E-**and**-Enter
  vanilla menu in LISTENING, PENDING, RESPONDING, SUSPENDED and with no session at all; and after a full
  clean session, a plain vanilla E-press on a shopkeeper must be clickable by all three.

### 4.7 MCM page "Menuless questing" (`config.json` + `settings.ini`)

**Every default in design §1.11 is binding.** A **missing `settings.ini` key reads as 0/FALSE**, so **every
new setting needs its default line** — including the ones whose default is 0, so the page shows a real
value. The complete list is §9 of this plan (one table, with the ini section, the type and the default);
build the page from that table, not from memory.

Also in the page: the **diagnostics counters** (`opens_without_match`, `scene_refusals`, `layers_lost`,
`layers_rewalked`, `layers_assisted`, `count_mode_failures`, `guard_pokes_that_found_true`,
`checks_run`, `checks_passed`, `paid_scenes`), the **self-test block** of design §1.11, the **probe
buttons** of §3.2, and the **help text** that must say, in plain words:

* which hotkeys sleep during a conversation (OStim's, OBody's, Hot Key Skill, Simplest Horses, Thieving XP,
  and the glue's own initiative tick — 13+ scripts key on `UI.IsMenuOpen("Dialogue Menu")`);
* that a scene NPC answers in her own words rather than taking up business, and that CHIM's
  `_restrict_onscene` does **not** cover player speech;
* that **Smart Talk's `bSkipImmediateOnInput` and `bHoldToSkip` must both be `0`** or the module stays in
  dry-run, and that **`iPapyrusHandle` (live 3) must never be changed**;
* that the CHIM push-to-talk key is **Left Ctrl (DX 29)**, and that a rebind must avoid E, TAB, Enter,
  Space, W/A/S/D, the arrows and every mouse button;
* the owner-side settings of design §5.3 that the glue will never write itself (IACC, Helmet Toggle's
  `HT_EnableDialogue`, Fuz Ro D-oh's `WordsPerSecondSilence`, Follower Stats display, subtitles).

### 4.8 `tools/deploy_server.ps1` — the anchor pre-flight

Before copying, grep each server-file anchor of §1.2 and **fail the deploy, naming the anchor**, when it is
missing or matches more than once. Keep the existing scene-index warm-up step; add the prompt-index build +
load **after** copying (lane C provides the two commands; lane B only wires them and must tolerate lane C's
scripts not existing yet — `if (Test-Path …)`).

### 4.9 Lane B gate

`compile.ps1` prints OK · `esp_dump.py` shows four script names on `0x01000800` · the pre-flight passes ·
`grep -n "IsInMenuMode" LRG_Main.psc` shows two statements · **T2** topic-dump parity on 10 NPCs · **T3**
dry-run conversation logs the right `WOULD CLICK` and clicks nothing.

---

## 5. LANE C — SERVER + INDEX

Files: `tools/build_prompt_index.py`, `lib/lrg_prompt_index.php`, `lib/lrg_dialogue.php`,
`config/lrg_dialogue.default.json`, `config/lrg_dialogue_overrides.default.json`,
`migrations/004_lrg_dialogue.sql`, `migrations/005_lrg_prompt_index.sql`, `tools/test_prompt_index.php`,
`tools/flows/dlg_adapter.php`, `tools/flows/scenarios/20_*.php … 36_*.php`, plus the anchored hook edits.
Read in full: design **§2**, **§3**, **§4**, **§5**, **§6**, **§7.1**–**§7.3**, **§11 steps 2–3**, and
`research/p2-quest-branching-speech.md` §2, §3, §6, §7 (your index's ground truth).

### 5.1 `tools/build_prompt_index.py` — the offline index (design §4.2)

A **new** file derived from `research/p2_speech_tags.py --dump`; **the research script stays untouched**.
Runs inside WSL against `/mnt/f/Modlists/LoreRim` (~15 s), writes `data/prompt_index.ndjson`, **writes no
plugin, opens no BSA**.

**The plugin-parser decision (needs no install).** Build a **pure-Python ESM/ESP record reader** in the
WSL distro — `python3` is present there, Windows has no Python. It is the same access pattern Phase 1's
scene index already uses, and the research scanner already proves it works on this load order (869,687
records walked, zlib-compressed records handled, 40,679 vanilla INFOs, 77,452 new ones). Concretely:

* read `plugins.txt` / `loadorder.txt` from `F:\Modlists\LoreRim\profiles\Ultra\` (**read-only**) and
  build the file index from every enabled mod's top level by modlist priority + `Stock Game\Data` +
  `overwrite`, exactly as the research method describes;
* decode `TES4`/`GRUP` and the `DIAL` / `INFO` / `QUST` groups only; handle the zlib-compressed record
  flag with `zlib` (stdlib); resolve the winner as "last plugin in load order that carries the record";
  canonical record id = `<origin master>:<24-bit id>`;
* read vanilla display strings from the **uncompressed** `Skyrim - Interface.bsa` string tables (the
  research run already does this: skyrim 67,414 / dawnguard 8,182 / dragonborn 11,069 / hearthfires 2,273
  / update 1,591 strings). This is a plain `.bsa` header walk on an **uncompressed** archive — **no
  compression library beyond stdlib `zlib`, no LZ4, nothing installed**.
  **Stated deviation from "BSA-less":** the five vanilla masters are localized, so their DIAL `FULL` and
  INFO `RNAM` text lives in `.STRINGS` tables, not in the plugin. There are no loose `Strings/*.STRINGS`
  in this install, so without this one uncompressed-archive read every vanilla prompt would be
  `<strid N>` and the index would be useless for exactly the topics the owner cares about. It stays
  read-only, needs nothing installed, and is the only archive the builder opens;
* **the 82 localized Creation Club plugins are listed `unreadable`** and their 22 speech topics stay
  engine-only (**decision D6 = engine-only**, §12). Do **not** parse their LZ4 BSAs; that is the only path
  that would need a module inside WSL, and the owner declined it.

**Required fixes over the research scanner** — build every row of design §4.2's table:
`NAM1` for every DIAL category + `DNAM` shared-response resolution (without it 21 % of player INFOs look
silent and the rent hub looks unspoken; after the fix 43,941 of 44,370 player INFOs have response text and
only **429** are truly silent) · DIAL-subtype filter (`SNAM == CUST` + the favour subtypes) before calling
anything a speech check (otherwise 558 GDO guard-HELLO INFOs are indexed as persuasion) · the
speaker-condition set per INFO · the full condition list per check INFO plus a `compound` flag · `twat`
per INFO · token prompts (`<Alias=…>`, `<Global=…>`, `<BribeCost>`, `<CrimeGold>`; 576 of them) turned into
match patterns · `SKSE/Plugins/DynamicStringDistributor/**/*.json` scanned for `DIAL FULL` / `INFO RNAM` /
`INFO NAM1` entries, applied or **failed loudly** (today **all 277 JSON files across 4 DSD config folders**
are `ACTI RNAM` 11,991 / `FLOR RNAM` 806 / `PERK EPFD` 180 / `GMST DATA` 55 — **zero** dialogue entries, so
plugin text == displayed text *for now*; the design's "192 files" was the pre-verification count, the
verified number is 277) · PNAM re-ordering modelled and the four affected orc
topics flagged (§12 D8b) · the corrected price regex
`\(([^()]*?)(\d[\d,\.]*)\s*(gold|septims?)\b[^()]*\)\s*\.?\s*$` (group 1 = the verb), which matches every
priced form and rejects `(3 days left)`, `(takes 6 hours)`, `(goldfish)`.

**Check-kind discovery is by CONDITION FUNCTION, never by tag** (owner addendum 5a). The builder emits a
`kinds` census into the index header and into its own report: every distinct
`(condition function id, actor value, comparand kind, comparand form/EditorID/literal)` tuple it saw on a
player INFO, with counts and example topics. Known function ids (`CommonLibSSE-NG TESCondition.h`):
`654 GetBribeSuccess`, `655 GetIntimidateSuccess`, `494 GetPermanentActorValue`,
`640 GetActorValuePercent`, `GetActorValue`, `GetBaseActorValue` (⇒ tagged `speech_base_av`, a trainer cap,
**not** a check), `459 GetCrimeGold`, `375 GetCrimeGoldViolent`.

**What the census found in this load order — state it in the report so the owner knows the answer**
(`research/p2-quest-branching-speech.md` §2.1–2.2, `p2-requiem.md` §R2.1/R2.2):

* **Three real engine check kinds exist here, and only three**: `persuade` (Speechcraft AV vs a global or
  literal, 121 vanilla + 253 mod INFOs), `intimidate` (`GetIntimidateSuccess`, 65 + 72),
  `bribe` (`GetBribeSuccess`, 56 + 29).
* **`(lie)` — 61 topics — is pure flavour with no engine check anywhere.** So are `(bribe)` (6 topics:
  fixed-price/item "bribes" run by quest script), `(brawl)` (31), `(vigilant)` (22), `(karma)` (2),
  `(romance)`/`(flirt)`/`(start romance)`/`[family]` (follower-mod relationship switches),
  `(skip quest)`/`(fail quest)` (META). **No mod in this list adds a deceive / charm / seduce engine
  check.** Owner addendum 5a's "any mod-added check kind" is therefore answered as: *none exist as engine
  checks; the deception the owner wants is the free-conversation check of §6.10.* The builder still runs the
  census every rebuild, so a future mod's new kind shows up as data rather than as a surprise.
* **Comparands are not five tiers**: 257 of 299 vanilla persuade conditions use one of the five `Speech*`
  globals, the rest are literals (15…80); mods add a mod-owned global `GDOSkillStealth` (19 INFOs) and
  ~70 literals. **Never assume "global" or "five tiers".**
* **~10 of 477 check INFOs are compound** (a weapon skill, a level, a race, a perk or the Amulet of
  Articulation passes *instead* of the check) — e.g. Ghorbash/Borgakh accept One-Handed / Two-Handed ≥ 75.
  `compound = true` switches retry-suppression and scoff-first **off** for that entry.
* **`(<BribeCost> gold)` is already substituted with digits in the menu**; the same shape is produced by
  243 `<Global=…> gold` price topics and 116 literal prices. Disambiguate by index (`<bribecost>`) or by
  `bamt` equality.

Output loader: `php lib/lrg_prompt_index.php load` fills Postgres (`migrations/005_lrg_prompt_index.sql`):
`lrg_prompt(norm, pattern, topic_key, info_key, quest, journal, toplevel, kind, variant, flags, scripted,
compound, twat, links, resp)` with a `pg_trgm` index on `norm`, plus `lrg_prompt_layer(parent_info,
fingerprint)`. Rebuilt by `deploy_server.ps1` and whenever the hash of `plugins.txt` + `modlist.txt`
changes — checked **at most once a minute on the fast `lrg_dlg ev=facts` path and never inside an LLM
request** (the discipline Phase 1 uses at `preprocessing.php:19-21`).

Binding API:

```php
lrgPromptLookup(array $texts, array $npcFacts = []): array  // per text: best record or null ('unindexed')
                       // order: layer fingerprint (subset-tolerant) -> exact norm -> token pattern -> none
lrgPromptLayerKind(array $records): string                  // root | closed | unknown
lrgPromptIndexStatus(): array
lrgPromptKinds(): array                                     // the census, for the MCM/report and §6
// test seam: $GLOBALS['LRG_TEST_INDEX']
```

### 5.2 `lib/lrg_dialogue.php` — the module

**Test-facing API (FLOWTESTS uses nothing else)** — design §7.1, plus the three additions this plan needs:

```php
lrgDlgHandleGameMessage(array $gameRequest): string   // 'handled' | 'pass'; echoes the D1 line itself
lrgDlgPrepareTurn(): void
lrgDlgPrerequest(): void
lrgDlgJsonTemplate(): void
lrgDlgStaticGuidance(array $t): string
lrgDlgVolatileGuidance(array $t): string
lrgDlgTransformer(string $s): string
lrgDlgPostProcessActions(array $lines): array
lrgDlgFilterChat(array &$gameRequest): void
lrgDlgGet(string $npc): array   /  lrgDlgSet(string $npc, array $patch): void
lrgDlgQueue(string $npc, string $param): void          // the D2 insert, isolated so tests can capture it
lrgDlgEnsureActions(): void
// added by this plan
lrgDlgCheck(array $turn, array $intent): ?array        // §6.10 the free-conversation check
lrgDlgQuestBlock(array $turn): string                  // §7.3 the quest-tree block
lrgDlgPrice(string $npc, array $state, array $profile): array  // §8.2 the price band
```

Every time read goes through `lrgNow()`; every DB access through the `$GLOBALS['db']` wrappers with reads
shaped `… FROM lrg_dialogue WHERE npc_name=<escapeLiteral(name)>` so the flow tests' in-memory fake can
serve them.

**State** (`migrations/004_lrg_dialogue.sql`, same shape as `lrg_memory`: `npc_name` PK, `payload` jsonb,
`updated_at`). Payload keys exactly as design §3.3: `ref`, `vt`, `q[]`, `root`, `session` (incl. `scene`
and `path[]`), `offer`, `parked`, `attempts`, `last_exec`, `last_result`, `utter` — plus, from this plan:
`checks` (§6.10's per-NPC memory), `qobj` (§7.2), `price` (§8.2). Entry record and `class` vocabulary as
design §3.3.

**The three matching modes, and only three** (design §4.4): key mode (every class, under §4.5), intent
mode (**`plain` and `service` only**), same-session continuation (**`service` and `pay` only**, all of
D-18's conditions required: the utterance names the value, score ≥ 0.70 with margin ≥ 0.25, `pg >= N`,
depth 1 only, no `twat`, never `check`/`commit`/`meta`, exact N and exact entry text echoed in
`ev=result`).

**Two-step confirmation, the speech-check protocol, and outcome classification** are design §4.5 verbatim,
extended by §6 of this plan. **Keeping the model from narrating an outcome** is design §4.6 verbatim —
including the `JSON_TEMPLATE` hook that must **also append the two description clauses** to `item` and
`target`, because CHIM's schema is `strict` and `item`'s own description ends "Leave item blank for
SpawnGold and SpawnNPC and CreateNewNPC and DirectorCommand."

**Prompt blocks**: `<real_business>` (static, `character_bottom`, ≤ 70 words) and `<business …>` (volatile,
`prompt_bottom`) exactly as design §4.3, plus `<shared_business>` (§7.3) and `<what_just_happened>`
(design §4.3's ground truth, extended by §6.11). **Entries are shown VERBATIM**, only the leading tag
replaced by a label from the **index kind**. **While `sent < n` the NPC may not deny that the matter
exists.**

### 5.3 The action row (design §3.2)

Installed by `lrgDlgEnsureActions()` with its **own** marker `data/.dlg_actions_v1`, **never** inside
Phase 1's `LRG_GLUE_ACTIONS` (so Phase 1's `lrgHideActions(LRG_GLUE_ACTIONS)` under SHARMAT cannot switch
it off): `code_name` `ExtCmdLRG_SelectTopic`, `action_name` `TakeUpBusiness`, `parameters_json` = an object
with a **required** string `item`, metadata exactly in Phase 1's shape (`lrg_actions.php:57-68`):
`dispatch: plugin_command`, `source: LoreRimGlue`, `bridge_script: LRG`,
`bridge_entrypoint: DispatchExternalCommand`,
`requirements.request_types_any` = the four player-speech types **plus `lrg_dlgtalk`**,
`confirmation.default_policy: automatic`, `suppress_placeholder_infoaction: true`,
`followup.enabled: false`.

The LLM-facing `item` is one key `T1..T12` from **this** turn's `<business>` block, or `leave`, or — only
when no block was shown or none of its keys fits — the business in 3–8 plain words.

### 5.4 Hook edits (anchored; §1.2 has the current hints)

* **`globals.php`** — add `'lrg_topics', 'lrg_dlg'` to the `external_fast_commands` merge.
* **`preprocessing.php`** — **insert before** the block whose condition contains
  `strncmp($lrgType, 'lrg_', 4) === 0`:
  ```php
  if ($lrgType === 'lrg_topics' || $lrgType === 'lrg_dlg' || $lrgType === 'lrg_dlgtalk') {
      require_once __DIR__ . '/lib/lrg_dialogue.php';
      if (lrgDlgHandleGameMessage($gameRequest) === 'handled') { terminate(); }
  }
  ```
  This keeps the module independent of Phase 1 and **alive under SHARMAT**. Also route `chat` rows to
  `lrgDlgFilterChat` (design §4.6's duplicate-player-line relabel).
* **`functions.php`** — **after the closing brace of the whole `if (defined('LRG_SHARMAT_PRESENT')) {…}
  else {…}` block**, and therefore on **both** branches: `lrgDlgPrepareTurn();`, register the Phase 2
  post-gate **after** Phase 1's in `action_post_process_fnct_ex`, and register the `JSON_TEMPLATE` hook.
  **Verify the inserted statements are at brace depth 0** — that is what keeps design §6.3/6.5 true under
  SHARMAT.
* **`prerequest.php`** — `lrgDlgPrerequest();` after `lrgPrerequest();`.
* **`prompts.php`** — `$GLOBALS['PROMPTS']['lrg_dlgtalk']` with the cue "answer the player's last words
  yourself in one or two short sentences, or take up one of the listed matters". Rules only, **no example
  lines** (PROTOCOL §0 rail).
* **`context_pre.php`** — inject the blocks with `chimRegisterPromptInjection` (same pattern as its
  `:13-17`) and set the **chained** transformer (chain any transformer `prompt.includes.php:68` already
  installed).
* **`lib/lrg_actions.php` — the D-12 pass-through.** Immediately **after** the line
  `if (stripos($code, 'ExtCmdLRG_') !== 0) { $out[] = $line; continue; }` (hint `:1300`) insert:
  ```php
  if (strcasecmp($code, 'ExtCmdLRG_SelectTopic') === 0) { $out[] = $line; continue; } // Phase 2 gate handles it
  ```
  It must land **before** the `if (!$turn || strcasecmp($actor, …) || defined('LRG_SHARMAT_PRESENT'))`
  statement (hint `:1304`). Without it the line dies as "unknown glue action" (hint `:1437`).
  **Verify: the inserted line's index is greater than the `stripos` anchor's and less than the
  `LRG_SHARMAT_PRESENT` anchor's.**
* **`lib/lrg_actions.php` — `lrgRecordResult`.** Extend
  `if (stripos($raw, 'command@ExtCmdLRG_') !== 0 || !lrgEnabled()) { return; }` (hint `:608`) to also
  ignore `command@ExtCmdLRG_SelectTopic`, so Phase 1 does not store the dialogue result in
  `lrg_memory.last_result` and tell the NPC about it a second time.
* **`lib/lrg_core.php`** — constants `LRG_ACT_TOPIC`, `LRG_SCHEMA_VERSION` **5**, `LRG_VERSION` `0.4.0`;
  `manifest.json` **0.4.0**.
* **`config/lrg_config.default.json`** — only the sections §6 and §8 need (`checks`, `paid_intimacy`, the
  per-profile `price_days` / `money_influence` keys inside `status_rules`), with targeted edits, re-read
  before each edit, never a whole-file rewrite.

### 5.5 Coexistence with Phase 1 (design §6 — the part that is easiest to get wrong)

* Two orthogonal turn records: Phase 1's `$GLOBALS['LRG_TURN']` is **untouched**; Phase 2 adds
  `$GLOBALS['LRG_DLG_TURN'] = {npc, cid, on, list, offer{}, parked, crit, mute, result_block}`.
* **Phase 2's gates are computed from Phase 2's OWN state, never from `$GLOBALS['LRG_TURN']`.** Under
  SHARMAT `functions.php` takes the `if` branch, `lrgPrepareTurn()` never runs and `LRG_TURN` is never
  built, so a gate expressed in Phase 1's terms would evaluate to "not blocked" exactly where
  `ext/aiagent_nsfw` is running its own scenes. Scene / OStim / adult state comes from `lrg_npc_state` and
  Phase 2's own `ev=facts`: `scene=1` or `ostim=1` ⇒ no `TakeUpBusiness`, no `<business>` block.
* `session.state ∈ {open, pending}` for this NPC ⇒ `ExtCmdLRG_StartIntimacy`, `ExtCmdLRG_Clothing`,
  `ExtCmdLRG_RequestAct` and `ExtCmdLRG_Invite` are hidden for the turn, and the intimacy guidance gets the
  plain reason "in the middle of a matter". No initiative tick is admitted while a session is open, a layer
  is pending, or a layer is LOST.
* An NPC in a quest Scene, with a pending/LOST layer, in a critical session, or whose last `ev=result` is
  ≤ 10 s old is never offered intimacy actions.
* Business exchanges enter CHIM's event stream as the **real** lines (CHIM's own capture, or the glue's
  `logEvent` fallback in CHIM's `chat` shape when no `(Context` row arrived within 3 s of an `ev=line`).
  **Nothing explicit ever appears in a business block, and no business detail ever appears in an intimacy
  block.**

### 5.6 Ranking, and the tail (design §4.3 / R12)

A **closed layer** shows ALL entries (cap 12, engine order, never bucketed). A **root list** shows a ranked
top 8 plus `(+N more: …)`: +4 a quest in the journal, +3 `new`, +2 check / priced / action tag, +2 service,
+ lexical similarity to the utterance (`pg_trgm`), −3 filler quests and follower-framework menus, −2
questions shared by more than 20 topics, +3 a `cQuestEntryColor` row when `bQuestColour` passed P17.
**Ranking runs over `head ∪ tail`, never over the head alone.** The noisiest owners in this load order are
`ANDR_AJO_Quest` (332 topics), `ACFDialogueWhiterun` (195), `WTDialogueIdle` (125),
`ACFMTSRiftenDialogue` (123) — a Missives topic at position 19 is exactly the case this rule exists for.

### 5.7 Flow tests (design §7.3) — `tools/flows/scenarios/20_*.php … 36_*.php`

Build every scenario of design §7.3's table (20, 20b, 21, 21b, 22–29 with 29 extended, 30, 30b, 31–36) plus
`tools/test_prompt_index.php`'s named assertions. Reuse Phase 1's harness **verbatim** (fake
`$GLOBALS['db']`, `LRG_TEST_NOW`, fixture index through `LRG_TEST_INDEX`, `touch data/.schema_v5`); never
edit the harness. Add, for this plan's three features:

| # | Scenario |
|---|---|
| **37** | **Engine speech check**: an offered `persuade` key with `compound=false` and a known failure variant parks once (scoff-first) and executes on the second selection; with `compound=true` it executes at once; `attempts` remembers the failure and suppresses the retry until `sp` changes; the label comes from the **index kind** although the text says `(Intimidate)` |
| **38** | **Free-conversation check** (§6.10): "come on, you can tell me" with no list and no engine entry → one `do=award` line at most, the outcome is in `<what_just_happened>` as **fact**, the same trick against the same NPC inside `checks.memory_seconds` is refused with the remembered result, a `deceive` attempt against an NPC who already caught one lie is auto-failed, and **no** `SetBribed` / `SetIntimidated` / stage change is ever emitted |
| **39** | **Intimidation immunity**: `wis=0` from the game ⇒ the check cannot pass; a jarl / Vigilant profile is never intimidated whatever the roll; a failed intimidation drops affinity through `lrgAdjustAffinity` exactly once and may set hostility only when `checks.hostility` is on |
| **40** | **Quest-tree block** (§7.3): `q=` + `qobj=` join `questlog`, newest row per quest, only quests with a displayed objective; bookkeeping quests dropped; `<Alias=QuestGiver>` → the NPC's own name, other `<Alias=X>` → "the X", `<Global=…>` → "some"; **current objective only, never a future stage**; ≤ 3 lines; no `skyrim_quest_definitions` content |
| **41** | **Paid intimacy** (§8): an offer below her floor is dropped unless `interest_score >= 0`; an offer the player cannot afford is dropped with the reason; an accepted price emits `StartIntimacy` with `pay=<n>` exactly once; a `never` profile and a pre-scene refusal are never overridden; `bPaidIntimacy=0` removes the whole mechanism; the relationship gain is **halved** and the arrangement is remembered |

### 5.8 Offline test commands (for the lane's own report)

Copy what you need to `$env:TEMP\lrg_test` (= `/mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test`), write the
`.sh` with **LF** endings, then
`wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/<script>.sh`
(never inline-quote through `wsl`). Existing entry points, headers already read:
`php tools/flows/run_flows.php [--only=… --trace --strict --list]` (exit 0 = no FAIL),
`php tools/test_intent.php [--quiet]`, `php tools/test_gates.php`, `php tools/test_phrases.php`,
`php tools/test_scene_index.php`, and new `php tools/test_prompt_index.php`.
Postgres: `PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer`. HerikaServer:
`/var/www/html/HerikaServer` (logs in `log/`).

### 5.9 CHIM stand-downs and health checks

* **`chimQuestEngineFeatureEnabled()` true ⇒ the menuless module stands down entirely and says so once**
  (flow test 35). CHIM's "AI Quest Progression" must stay OFF (live: false).
* **Per-turn hiding by `code_name`** when the real entry is known: `RentRoom`, `HireCarriage`, `HireFerry`,
  `Brawl`, `Training`, `OpenInventory` (LLM name `Trade_Items`; there is **no** `TradeItems` code),
  `OpenInventory2`, and `GiveGoldTo` / `TakeGoldFromPlayer` while a priced or bribe entry is listed.
  **`ForgiveCrime` is hidden always while menuless is on** (§12 D4). `PayBounty` stays (it checks
  `GetItemCount(gold) >= bounty` before `PlayerPayCrimeGold`).
* **`EndConversation` is hidden while a session is open or a layer is pending.**
* **`ScriptProxy` health check, not a change**: no live action's `script_proxy_program` contains cmd_id
  39/40/518/519 (`SetBribed` / `SetIntimidated` / `SetCrimeGold`) — they are **latent, not live**. Log one
  line if a future CHIM update adds one; disable nothing.
* `lrg_topics` / `lrg_dlg` are **always** sent with `logMessageForActor`; `requestMessageForActor` (used
  only for `lrg_dlgtalk`, and only with no session open) is eligibility-gated and runs `InterruptNPC` on
  the target.

### 5.10 Lane C gate

`tools/test_prompt_index.php` green; `unindexed < 5 %` on T2's ten NPCs; flow tests **20–41** green,
including 20b, 21b, 29-extended, 30b, 33 and the five new ones; `php -l` clean on every touched file; the
anchor pre-flight of §4.8 passes against the lane's edits.

---

## 6. SPEECH CHECKS — both situations (owner addendum 5)

Two mechanisms that share one vocabulary, one log line and one MCM section, and **never** share code paths.

| | (a) **Engine checks** | (b) **Free-conversation checks** |
|---|---|---|
| When | a real INFO entry exists in the live hidden session | any CHIM conversation, **no** engine entry |
| Who decides the outcome | **the engine** (it selects the real INFO) | **the glue**, replicating the engine's own rules |
| Effects | the engine's own fragment: XP, stats, flags, gold, stages | Speech XP + affinity + memory only. **Never** `SetBribed`/`SetIntimidated`/`SetCrimeGold`/a stage |
| LLM's job | judge only *whether the player attempted*, and of which kind | the same |
| Lanes | B (the click, the PRE/POST facts), C (matching, labels, directive) | B (`do=award` only), C (everything else) |

### 6.1–6.7 (a) Engine checks — design §4.5, and what each lane owns

* **Labels come from the INDEX kind, not the text tag** (45 persuade INFOs carry no tag, ~20 carry the
  wrong one). `[persuasion attempt]`, `[threat - refusing means a brawl]` (whenever the index shows the
  topic's failure variant ends in `(Brawl)` — 15 topics; without this wording the model thinks the player
  must challenge someone to a fist fight), `[bribe: costs N gold; {player} has M]` / `[… cannot pay]`.
* **Attempt rules (lane C).** Use the key only when the player gave a reason, appeal or claim aimed at this
  matter (persuade), or an explicit / clearly implied threat aimed at this NPC (intimidate). Voice input
  needs ≥ `attempt.min_words` (4). One click per attempt.
* **Retry suppression (lane C).** A failed `norm` for this NPC is re-clickable only when something relevant
  changed (`sp`, `wis`, gold, or the shown variant flipped); otherwise the key is shown
  `[already refused - refuse again, more curtly]` and a selection is dropped.
* **Scoff-first (lane C, default on).** When `wis=0` was observed, or the shown text is the index's FAILURE
  variant AND that failure INFO is scripted or Goodbye, the first selection parks and the second executes.
* **Both rules are OFF for `compound` or unindexed entries** — otherwise the glue talks the player out of
  attempts the engine would pass (Ghorbash / Borgakh accept One-Handed / Two-Handed ≥ 75).
* **Bribe / pay (lane C).** Parse N with the corrected regex and cross-check against `bamt`;
  `(N days left)` is not a price. Named amount A ≥ N and `pg >= N` ⇒ execute, and the feedback says exactly
  N moved. A < N ⇒ no click, the NPC haggles upward and **may name N** (§12 D5). No amount named ⇒ the NPC
  names the price (a fact from the list) and the player agrees ⇒ execute; that exchange **is** the two-step,
  for free. `pg < N` ⇒ never click. Gold actions hidden on such turns so gold moves exactly once, through
  the engine.
* **PRE/POST facts (lane B).** At CLICKING capture `Game.QueryStat("Persuasions"/"Bribes"/"Intimidations")`,
  player gold, `IsBribed()`, `IsIntimidated()`, `GetBribeAmount()`, `WillIntimidateSucceed()`,
  `player.GetActorValue("Speechcraft")` and `ProgressTimerId()`. After the response run the **settle poll**
  (up to 4 s) until one moves, then send `ev=result` with `dP,dB,dI,gold,bribed,intim,bamt,wis,sp,dur`.
* **Outcome classification (lane C), in this order**: stat delta → gold delta == `bamt` → flags → the NPC's
  real line matched against the index's success/failure response → the shown variant. Notes:
  `FavorDialogueScript.ArrestPersuade()` increments **Persuasions** and *then* calls `SetBribed()`, so a
  flipped bribe flag alone never means "bribe succeeded"; `Intimidations` is not incremented during a
  brawl; `Bribe()` does nothing without gold. **"No delta" is not proof of failure** (~31 % of persuade
  successes never call `FavorDialogueScript`): fall through to the line match, or report `unknown` — and an
  `unknown` is told as "you answered: {line}" with **no verdict attached**.
* **Free cross-check (lane C).** CHIM's `OnTrackedStatsEvent` already forwards every tracked stat as
  `logMessage("<stat>@<value>", "setconf")` into `conf_opts`. If `Persuasions` / `Bribes` /
  `Intimidations` rows start appearing after a headless check, that is independent confirmation.

### 6.8 The facts the game must send for (b) — lane B

Free-conversation checks need the player's live check inputs, and a snapshot's `pspeech` is up to 90 s old.
So `lrg_topics` and `lrg_dlg ev=facts` carry (§2.1):

| key | value | how |
|---|---|---|
| `sp` | `player.GetActorValue("Speechcraft") as int` | `Actor.psc:143` |
| `lvl` | `player.GetLevel()` | |
| `perk` | csv of the tokens below that the game could confirm, `-` when none | `player.HasPerk(...)`, `player.IsEquipped(...)` |

Perk / item tokens, all resolved with `Game.GetFormFromFile(<id>, "Skyrim.esm")` and **None-guarded**
(a nulled perk record resolves but grants nothing; a missing one resolves to `None`):

| token | form | what it is in THIS load order |
|---|---|---|
| `silvertongue` | PERK `0x00058F72` | vanilla `Bribery` **is now `REQ_Speech_SilverTongue`** (winner: LoreRim - Economy Overhaul): quest `PerksQuest 0x0005F596` stage 60 + **Mod Player Intimidation ×3.0** + sell ×1.1 / buy ×0.9. Any INFO conditioned on `HasPerk 0x058F72` now keys on this |
| `persuasion` | PERK `0x001090A2` | vanilla `Persuasion`, **nulled** in this list (no entry points). Still worth sending: `FavorDialogueScript`'s re-roll branch tests it |
| `amulet` | FLST `0x000F759C` `TGAmuletofArticulationList` | passes **instead of** the Speech comparison in 257 vanilla persuade conditions |

Also send `wis` (`speaker.WillIntimidateSucceed()`) on `ev=facts` for the crosshair NPC — it is the
engine's own intimidation verdict for that actor, and it is the **only** way the server can honour "never
works on people it should not" without guessing.

**The game never decides a free-conversation check.** It reports facts and executes `do=award`.

### 6.9 `do=award` — the only new effect the game performs (lane B)

`CmdAward` executes, in this order, and reports exactly one `funcret`:

1. Validate `ok=1`, `npc`, `ref` (must be a loaded CHIM agent), `x` not in the ring, age ≤ 20 s, the
   module enabled and not in dry-run (**dry-run logs `WOULD AWARD …` and reports
   `Error: dry-run mode, nothing was clicked`**).
2. `take=<n>` > 0: refuse with `Error: not enough gold` when `player.GetItemCount(gold) < n`; otherwise
   `player.RemoveItem(gold, n, true, npc)` — gold form `Game.GetFormFromFile(0x0000000F, "Skyrim.esm")`,
   cached once.
3. `xp=1`: **replicate the engine's own call exactly**
   ```papyrus
   float mult = speechSkillMult.GetValue()                         ; global 0x0010E725, live 75
   Game.AdvanceSkill("Speechcraft", mult * player.GetActorValue("Speechcraft"))
   ```
   This is verbatim what `FavorDialogueScript.Persuade()` / `Bribe()` / `Intimidate()` do
   (`F:\Modlists\LoreRim\mods\Speechcraft Randomization\Scripts\Source\favordialoguescript.psc:65-75`,
   `:132-152`, `:155-177`): `SkillUseMultiplier = SpeechSkillMult.value`,
   `SkillUsePersuade = SkillUseMultiplier * Game.GetPlayer().GetAv("Speechcraft")`,
   `AdvanceSkill("Speechcraft", SkillUsePersuade)`. Read the global **live**; never hardcode 75.
4. `stat=<Persuasions|Bribes|Intimidations>`: `Game.IncrementStat(stat, 1)` **only when
   `bCheckStats` is on (default OFF)**. Reason it is off: Beneficial Speech Checks diffs those three stats
   across `OnMenuOpen`/`OnMenuClose` and pays out a reward, and the vanilla achievement 28 keys on them —
   a free-conversation success is not a menu speech check and must not pay a menu reward by default. The
   owner can turn it on.
5. **Never** `SetBribed`, `SetIntimidated`, `SetCrimeGold`, `SetStage`, `SetObjectiveCompleted`, a favour
   flag or a quest alias. That is the §0.3 rail, and it is what keeps a free-conversation trick from
   changing which real INFOs the engine will later offer.
6. Report `Noted.` (or the `Error:` reason) and `commandEndedForActor` exactly once per `x`.

### 6.10 The check itself — lane C, `lrgDlgCheck()`

Runs in `lrgDlgPostProcessActions` / `lrgDlgPrepareTurn` (one directive + at most one command per attempt,
**no new LLM call**). Preconditions, all required: `bFreeChecks` on; no engine entry of that kind listed
this turn (an engine check always wins); the recognised intent kind ∈ `persuade | intimidate | bribe |
deceive` with `conf=high`; ≥ `attempt.min_words` words on voice input; not under SHARMAT-owned scenes,
kill switch, dry-run or a `silent` turn; the NPC is a person with a fresh snapshot.

**Kinds and how each is decided — the engine's own rules, replicated:**

| kind | rule | inputs |
|---|---|---|
| `persuade`, `deceive` | pass when `sp >= threshold` **or** `perk` contains `amulet` | `threshold` = the live value of the `Speech*` global the server picked for the difficulty (§6.10 difficulty), read from the index census's own comparand set so a re-rolled global is honoured. `deceive` uses the same comparison one band harder |
| `intimidate` | pass **only** when the game's own `wis=1` (`WillIntimidateSucceed()`), and never for a profile the config marks immune | `wis`, profile |
| `bribe` | the server names N (§6.10 price), the player must agree, and the pass is "the player has N": `pg >= N` | `pg`, N |

**Difficulty pick (lane C)** — from stakes × NPC stance, never from the LLM:
`difficulty = clamp(base(stakes) + stance(affinity, profile, guard/merchant/hostile) + bias, 0, 4)` mapped
to the five live globals `SpeechVeryEasy … SpeechVeryHard`. `stakes` comes from what the player is asking
for (a better price / being let past / talking their way out / a lie about who they are — `checks.stakes`
config, words → band). `bias` is the MCM slider `iCheckBias` (−2…+2, default 0). **No formula, no
threshold and no number is ever shown to the LLM or the player.**

**Immunity (owner: "never works on people it should not").** `checks.immune` in config, matched exactly
like `status_rules`: `jarl`, `court`, `vigilant`, `priest_mara`, plus any profile with
`intimidate_immune: true`. For those, `intimidate` always fails and the directive says so as a fact. A
`guard` profile is not immune but gets the hardest band.

**Consequences.**
*Success*: the outcome is handed to the LLM as fact; `do=award` with `xp=1` (and `take=N` for a bribe, and
`stat=` only when `bCheckStats` is on).
*Failure*: `lrgAdjustAffinity($npc, -checks.affinity_fail, 'check failed')` — through Phase 1's existing
`lrgAdjustAffinity` (`lrg_core.php:672`), **never with a `$type`**, at most once per attempt; a failed
`intimidate` may make an already-hostile NPC hostile only when `checks.hostility` is on (default **off** —
it is the one consequence that can get the player killed by a mis-heard word); a caught lie is remembered.
*Always*: one line into CHIM's memory through the existing `infoaction` channel, and a row in
`lrg_dialogue.checks`.

**Per-NPC memory, so a trick does not repeat** (owner). `checks[<norm-kind-topic>] = {kind, result, at,
sp, wis, pg, tries}`. Inside `checks.memory_seconds` (default 86400 = one game-ish day of real time) the
same kind about the same matter against the same NPC is **not re-rolled**: the remembered result is handed
to the LLM as fact ("she has heard this from him before and did not believe it"), and a `deceive` against
an NPC who has already caught one lie **auto-fails** until affinity moves. A re-roll is allowed only when
`sp`, `wis`, `pg` or the affinity band changed.

### 6.11 The directive that hands the LLM the outcome as fact (lane C)

Appended as the last lines **inside** the existing `prompt_bottom` block (Phase 1's rule — never a second
injection). It states the outcome, never a verdict the glue does not have, and forbids the model from
narrating an outcome of its own (design §4.6 rule 4). Shapes, not fixed strings:
"the attempt FAILED - she does not believe a word; do not soften this" ·
"he convinced her: treat the matter as settled in his favour and do not reopen it" ·
"she is not afraid of him" · "she took exactly N septims" ·
"he tried this on her before and she remembers".
`unknown` is told as "you answered: {line}" with no verdict. **Never a threshold, never a skill number,
never "you would have needed 75".**

### 6.12 The G6 log line (lane C)

One greppable line per attempt, under the turn's `cid`, appended **after** the existing `turn` /
`llm` / `net` lines and with its own prefix so nothing existing breaks:

```
check npc=<name> where=engine|free kind=<persuade|intimidate|bribe|deceive> diff=<0..4>/<global>=<live value>
  sp=<n> wis=<0|1> pg=<n> N=<n> stakes=<word> stance=<n> bias=<n> mem=<hit|miss|auto-fail>
  res=<pass|fail|unknown> why=<the one rule that decided> aff=<delta> xp=<0|1> gold=<n> x=<exec id>
```

The owner must be able to answer "why did that check go that way" from this line alone.

### 6.13 What is deliberately NOT built

* No new LLM call of its own, no judge call, no side connector (design §4.4's `match.side_call` stays off).
* No second action row: `do=award` rides on the one action.
* No engine flag, no stage, no crime-gold write — ever (§0.3).
* No attempt to re-implement `GetBribeSuccess`'s exact semantics for engine checks: the engine decides.
  For **free** bribes the rule is the plain one the owner asked for (does the player have it, does she
  accept), and `GetBribeAmount()` is used as the anchor when the NPC is one the engine would price.

---

## 7. QUEST-TREE AWARENESS (owner: "more useable with LoreRim and the overarching game questing tree")

One additive state message, one prompt block, **no LLM call of its own**, no journal walker.

### 7.1 The constraint that shapes it

**Papyrus cannot read objective text.** `Quest.psc` has `IsObjectiveDisplayed` / `IsObjectiveCompleted` /
`IsObjectiveFailed` / `GetCurrentStageID` and PO3 has `GetAllQuestObjectives` (IDs) and
`SetObjectiveText` (a **setter**) — there is no getter anywhere in the installed sources. So:

> **the game sends identifiers and flags; the server supplies the words.**

The words come from **CHIM's own quest journal**, which already exists and already works:
`questlog` is filled from CHIM's `_uquest|..|{id}@{?}@{briefing}@{stage}` fast command (fed by the DLL's
`TESQuestStageEvent` path, `comm.php:410-431`) and holds **299 rows over 116 distinct quests**, keyed by
quest **EditorID**, with the briefing (= objective) text and the stage. Live caveats, both verified:
**48 rows still carry unresolved tags** (`<Alias=QuestGiver>`, `<Alias.ShortName=Item>`, `<Global=NN01Count>`)
because CHIM's DLL does not resolve objective text replacement, and the table also contains non-journal
bookkeeping quests (`000FCQuestStatus…`). CHIM's `quests` table is **empty** on this install, and its
`skyrim_quest_definitions` `npc_facts` contain **later beats = spoilers** and are therefore never used.

### 7.2 Lane B — one message, no new type

`lrg_dlg ev=facts` (already in §2.1) gains two keys, and `lrg_topics` carries `q` as the design already
specifies:

| key | value | how | cost |
|---|---|---|---|
| `q` | csv of ≤ 6 quest EditorIDs | `PO3_SKSEFunctions.GetActiveAssociatedQuests(npc)` (`:812`) → `Quest.GetID()` | one native + N cheap calls, already gathered for the design's `q` key |
| `qobj` | csv of ≤ 6 `<questEditorID>:<stage>:<objId>.<d><c><f>` triples | `Quest.GetCurrentStageID()`, then `PO3_SKSEFunctions.GetAllQuestObjectives(q)` (`:1007`) filtered to the **displayed, not completed, not failed** ones (`IsObjectiveDisplayed` / `IsObjectiveCompleted` / `IsObjectiveFailed`), first two per quest | bounded: ≤ 6 quests × ≤ 2 objectives |

Rules: throttled **60 s per NPC** (the existing `ev=facts` throttle); computed **only** for NPCs
`getAgentByName` confirms; **skipped entirely** while a session is open (it is not needed there — the
session's own `lrg_topics` carries `q`), so it never competes with the driver's poll; `-` when empty.
`GetActiveAssociatedQuests`' second parameter stays `True`. Old server: unknown keys ignored.

### 7.3 Lane C — one prompt block, `≤ 3 lines`, `prompt_bottom`

`lrgDlgQuestBlock()` joins `q` / `qobj` to `questlog.id_quest`, newest row per quest
(`DISTINCT ON (id_quest) … ORDER BY id_quest, rowid DESC`), and keeps only quests that have a row
(= have shown an objective). Then:

* **drop bookkeeping quests** (an `id_quest` matching the config list `quests.bookkeeping_patterns`,
  seeded with `000FCQuest*`, `*Status*`, `DialogueGeneric*`, and anything with no displayed objective);
* **rewrite leftover tags**: `<Alias=QuestGiver>` → the NPC's own name when this NPC is the giver, else
  "the quest giver"; any other `<Alias=X>` / `<Alias.ShortName=X>` / `<Alias.BaseName=X>` → "the X";
  `<Global=…>` → "some";
* **the NPC's part comes from the join, not from a guess**: a quest in `q` is a quest this NPC is an alias
  of, so the block may say the matter is between them. A quest that is in the journal but **not** in `q` is
  never mentioned — that is what keeps this from becoming a spoiler feed;
* **current objective only, never a future stage, no stage numbers, no thresholds, nothing from
  `skyrim_quest_definitions`**.

```
<shared_business>You and {player} have unfinished business: "{objective}".</shared_business>
```

With more than one, at most three such clauses, newest first, each ≤ 120 chars. When `q` is empty or no row
joins, the block is **absent** (not an empty block).

**Interaction with the business block.** A quest named in `q` gives its entries **+4** in the root-list
ranking (§5.6) and lets `<business>` add the quest name in braces — **only when `questlog` already has a
row for that quest** (design §4.3: no spoilers). `bIntentOpen`'s "a business marker was named" test counts
a quest in `q` as a marker.

**Ground truth already covers the movement**: `<what_just_happened>` is composed from `ev=result` +
`ev=line` + **new `questlog` rows** ("Quest updated: '{objective}'."). Stage-only changes with no objective
produce no `_uquest` text and are simply invisible — acceptable, and cheaper than any walker.

---

## 8. "EVERYONE HAS A PRICE" — paid intimacy (owner addendum 6)

The owner's newest instruction, and it wins over the round prompt. It is an **intimacy** feature that rides
on Phase 1's existing leverage data; it is in this plan because the same builders are in the same files this
round. **Adults only, the consent gates, the kill switch and the no-fetish rail are unchanged. A price
never overrides a `never` profile or a pre-scene refusal she gives for other reasons — she may still say no
to a rude buyer.**

### 8.1 The wage anchor and the bands (lane C, config)

3–5 gold/hour ⇒ **a day's wage ≈ 35 gold**, a week ≈ 250, a month ≈ 1000. **Prices are expressed in days of
wage and turned into gold by the server**, so one `paid_intimacy.day_wage` knob rescales everything.

New config section `paid_intimacy` in `config/lrg_config.default.json` (lane C):

```json
"paid_intimacy": {
  "enabled": true, "day_wage": 35, "multiplier": 1.0, "round_to": 5,
  "band_by_wealth": { "destitute": [0.5, 2], "poor": [1, 4], "modest": [4, 15],
                      "comfortable": [15, 60], "wealthy": [60, 300], "noble": [300, 3000] },
  "money_influence_by_gold_sway": { "decisive": 1.5, "tempting": 1.2, "offering": 1.0,
                                    "indifferent": 0.7, "risky": 0.5, "insulting": 0.25 },
  "repeat_discount": 0.85, "married_secret_multiplier": 3.0,
  "interest_free_at": 0, "curious_at_floor": true, "indifferent_at_ceiling": true,
  "relationship_gain_factor": 0.5, "memory_seconds": 604800
}
```

Per-profile keys added to each `status_rules` entry (and to `fallback` and `npc_overrides`), all optional
and all overriding the wealth-tier default: `price_days: [floor, ceiling]`, `money_influence: <float>`,
`not_for_sale: true`. Seeded from the owner's own numbers: beggar `[0.5, 2]`, tavern_folk `[1, 4]`,
commoner `[1, 4]`, innkeeper/merchant `[4, 15]`, adventurer `[4, 15]`, guard `[15, 60]`,
housecarl `[60, 300]`, court `[60, 300]`, jarl `[300, 3000]`, priest_mara / vigilant `not_for_sale: true`.
**The existing `wealth`, `gold_sway`, `renown_sway`, `married_rule` and `min_affinity` keys are reused, not
duplicated** — `gold_sway` already carries exactly the "how much does money move this person" judgement
(`decisive` for a beggar … `insulting` for a jarl).

### 8.2 `lrgDlgPrice()` — the band, in words plus one number (lane C)

```
wealth   = profile.wealth  (or leverage.gold_tier_by_npc_gold applied to the NPC's real `gold`, whichever is higher)
band     = profile.price_days ?? paid_intimacy.band_by_wealth[wealth]
infl     = profile.money_influence ?? money_influence_by_gold_sway[profile.gold_sway]
floor    = band[0] * day_wage * multiplier / infl        ceiling = band[1] * day_wage * multiplier / infl
interest = lrgInterest()  (Phase 1, unchanged)
```
then, in this order: `not_for_sale` or profile `never` ⇒ **no price at all, ever** ·
`interest.score >= interest_free_at` ⇒ **no price needed**, or a token one if she wants it ·
`interest.word == 'curious'` ⇒ the **floor** · `indifferent` ⇒ the **ceiling** ·
`married_rule == 'secret'` ⇒ ×3 · `married_rule == 'refuse'` ⇒ refuse ·
renown lowers the price exactly the way `renown_sway` already works (`interest.renown_bonus` reused as a
discount band) · a remembered arrangement ⇒ ×`repeat_discount` · round to `round_to`.
Result: `{floor, ceiling, ask, words, why}` where `ask` is the single number the LLM is told and `words` is
the plain-language stance. **The LLM names the figure inside the band; the server never speaks.**

### 8.3 The flow, all by talking (lane C)

1. **Recognise** (extend `lib/lrg_intent.php`, new kind `pay_offer`): amounts ("for a hundred septims",
   "50 gold"), "how much", "name your price", "I'll pay", "what would it take". The existing blocker rules
   apply unchanged — a negated / quoted / hypothetical / questioned amount is not an offer, except that
   "how much" **is** a legitimate question that opens the price talk.
2. **Tell her the stance** in the existing volatile block: her band in words plus the one number
   ("if he offers gold you would not go below 120 septims; you may ask for more; below that you refuse —
   unless you want him anyway"). She haggles **in her own voice**.
3. **She accepts by choosing `BeginIntimacy` with the agreed price in the `amount` slot** — her own action
   choice; the server never starts a scene (Phase 1 rail, unchanged). The `BeginIntimacy` row's
   `parameters_json` gains an `amount` string, which is why `LRG_ACTIONS_VERSION` → **10**.
4. **The post-gate drops**: an acceptance below her floor unless `interest.score >= interest_free_at`; any
   acceptance the player cannot afford (`pgold` is already in the snapshot,
   `LRG_Profile.PlayerFacts` `:178`); every acceptance while `bPaidIntimacy` is off.
5. **On pass**, the emitted `ExtCmdLRG_StartIntimacy` param gains **`pay=<n>`** (§2.4).
6. **Remember** (`lrg_dialogue.price` / `lrg_memory`): `{ask, paid, at, times}`, kept
   `paid_intimacy.memory_seconds` (7 days), so she can refer to it and price him next time.
7. **Relationship**: a paid scene counts **half** — `lrgAwardSceneAffinity` multiplies the gain by
   `relationship_gain_factor` when the scene's start carried `pay>0`. `lrgSceneCounted()` is unchanged.
8. **Log** on the turn line: ` price=<floor>/<ask>/<ceiling> infl=<n> offer=<n> pay=<accepted|below-floor|unaffordable|free|refused>`.

### 8.4 Lane B — the `pay=` key (`LRG_OStim.CmdStart` / `BeginStartThread`)

* Parse `pay` in `CmdStart` **before** the gates so a refusal is cheap; clamp `0..100000`.
* **Take the gold at scene start, exactly once**, in `BeginStartThread` after the weapon prep and the
  baseline capture and **immediately before `OThreadBuilder.Start`**, so a start that aborts never charges:
  `player.RemoveItem(gold, n, true, npc)` (gold = `Game.GetFormFromFile(0x0000000F, "Skyrim.esm")`, cached).
* **Not enough gold** (`player.GetItemCount(gold) < n`) ⇒ refuse the whole start with the existing reason
  `Error: not enough gold` (new on the closed list, §2.3), reset state, restore weapons. She reacts in
  words on her next turn through Phase 1's existing `last_result` path.
* **Nothing is given back** if the player stops early (the owner's rule).
* Dry-run logs `DRY RUN WOULD PAY <n> to <npc>` and charges nothing.
* `pay=0` or absent ⇒ today's behaviour, byte for byte.

### 8.5 MCM (lane B)

`bPaidIntimacy` (default **1**, section `[Intimacy]`) and `fPriceMultiplier` (default **1.0**, 0.25–4.0,
`[Intimacy]`). `bPaidIntimacy=0` removes the whole mechanism: no price in the prompt, no `pay=` key, no
`amount` handling. The help text says plainly that gold only ever moves when **she** accepts in
conversation, and that nothing is refunded.

---

## 9. MCM — everything the owner must be able to calibrate after the probe

**A missing `settings.ini` key reads as 0/FALSE. Every row below needs its default line in
`settings.ini`, including the zeroes.** Lane B builds `config.json`'s page from this table.

### 9.1 `[Dialogue]` — the driver (design §1.11 defaults are binding)

| key | type | default | what it calibrates |
|---|---|---|---|
| `bMenuless` | bool | **0** | the master switch of the feature; cannot leave dry-run until `SmartTalkSafe()` is true and P11 has been read |
| `bDlgDryRun` | bool | **1** | everything runs, the menu stays visible, `WOULD CLICK` is logged, nothing is clicked |
| `iHideMode` | int | **1** | 1 = topics-only (keeps subtitles), 2 = full (probe only) |
| `bHideCursor` | bool | **1** | |
| `iEngineOpen` | int | **0** | 0 = every session the glue did not open stays a visible assisted menu (lists still harvested); 1 = menuless after the crit classification |
| `iCritical` | int | **0** | 0 = LETHAL handed back visible; COSTLY stays menuless either way |
| `iSceneGate` | int | **0** | 0 = never open on a scene actor; 1 = open unless the player is in the scene or a journal quest owns it. **Never relaxes D-17** |
| `iBranchInput` | int | **0** | 0 auto / 1 voice / 2 assisted — decided by X1 |
| `bRewalk` | bool | **0** | the bounded re-walk; only meaningful once X1 = "dies" |
| `iRewalkDepth` | int | **2** | `rewalk.max_depth` |
| `fSilenceTimeout` | float | **45** | PENDING → MANUAL |
| `fDecideTimeout` | float | **4** | DECIDING → PENDING/MANUAL. **Never closes a session** |
| `fLineSettle` | float | **0.4** | the line-settle gate before the first click |
| `fOpenDistance` | float | **200** | clamped live to `0.85 × fAIInDialogueModeWithPlayerDistance` (240) |
| `iClickRoute` | int | **0** | 0 auto (deterministic from CHIM setting × SWF family) / 1 A / 2 B |
| `iReadMode` | int | **0** | 0 = calibrate; 3 / 4 = force. **5 is read-only and must not be settable here** |
| `iCountMode` | int | **0** | 0 = calibrate; 1 A / 2 B / 3 C |
| `iMaxEntries` | int | **16** | head size, hard max 24; set from **P3t**'s measured per-call cost |
| `iTailMax` | int | **40** | tail cap (texts only) |
| `bIntentOpen` | bool | **1** | may an intent-mode turn open a session for awareness |
| `bAllowNullVoice` | bool | **0** | let a silent session through for testing |
| `bActivateDefaultOnly` | bool | **0** | `Activate(player, true)`; decided by **P0b** |
| `bIaccToggle` | bool | **0** | leave off — D2 is the permanent IACC setting (§12) |
| `bQuestColour` | bool | **0** | off until **P17** passes |
| `bProbe` | bool | **0** | arms the probe key and the probe buttons |

### 9.2 `[Checks]` and `[Quests]` — this plan's additions

| key | type | default | what |
|---|---|---|---|
| `bFreeChecks` | bool | **1** | the master toggle for free-conversation checks (owner: default on) |
| `iCheckBias` | int | **0** | difficulty bias slider, −2…+2 (owner: a bias slider) |
| `bCheckStats` | bool | **0** | let a free-conversation success `IncrementStat` (off: Beneficial Speech Checks would pay out) |
| `bCheckHostility` | bool | **0** | may a failed intimidation make a hostile NPC hostile |
| `bQuestAware` | bool | **1** | send `q` / `qobj` and show `<shared_business>` |
| `iQuestLines` | int | **3** | how many objective clauses at most |

### 9.3 `[Keys]` and the probe controls

`iKeyVanillaMenu` (exists) · `iKeyDumpTopics` (exists, now real) · **`iKeyLeave`** (new, default 0) ·
**`iKeyProbe`** (new, default 0). Probe buttons on the page, one per press that is not on the cycling key:
`P0`, `P0b`, `P6f`, `P11b`, `P12`, `P13`, `P14`, `P15`, `P16`, `P17`, plus `Run read phase`,
`Run hide+guard`, `Run click (route auto)`, `Run click (other route)`, `Close`.

### 9.4 `[Intimacy]` — §8

`bPaidIntimacy` bool **1** · `fPriceMultiplier` float **1.0**.

### 9.5 Diagnostics shown on the page (and sent with `ev=facts`)

`opens_without_match` · `scene_refusals` · `layers_lost` · `layers_rewalked` · `layers_assisted` ·
`count_mode_failures` · `guard_pokes_that_found_true` · `checks_run` · `checks_passed` · `paid_scenes`.
Plus the self-test block of design §1.11 (the four CHIM settings through `get_conf_i`,
`_player_auto_include_radius_m`, `bDialogueSubtitles`, `SmartTalkSafe()` **with the offending value named**,
the SWF family, `iMaxItemsShown`, `iPlatform`, and the five live Speech globals).

---

## 10. The open questions, and the exact log lines that answer them

### 10.1 Design gates and owner experiments (no glue code — run first)

| id | question | answered by |
|---|---|---|
| **X1** | Does an open session survive the player speaking (CHIM's `EndDialogue`)? | vanilla menu open, speak on Left Ctrl; does the menu close, does walk-away fire, is it still clickable; read `[LISTENER-RESOLVE]` in `AIAgent.log`. **Decides `iBranchInput`, `bRewalk` and whether branching is a voice feature at all** |
| **X0** | Which actor base does CHIM null, and for how long? | talk through CHIM, then open the vanilla menu within 30 s: is the greeting voiced? `getavinfo` / console voice type |
| **X3** ×2 | Does CHIM's `traditional_*` capture work at all, and in what row shape? | one vanilla topic on (a) an NPC CHIM never addressed, (b) one just addressed; then `SELECT` from `eventlog` (`type='chat' AND data LIKE '(Context%'`) and `speech` (`topic LIKE 'traditional_%'`) |
| **X2** | Can `PauseCurrentDialogue` eat an End fragment? | talk over a playing quest line; does the stage still advance |
| **X4** | Does the Prisma chatbox open over the Dialogue Menu, and do keystrokes leak into the list? | |
| **X5** | Which delivery route works? | send one `logMessageForActor` of a custom type and check whether a `\|command\|` line in the reply is executed; grep `AIAgent.log` for `Setting dialogue busy for  <npc>` and `[AUTO_ELIGIBILITY]` |

### 10.2 The probe — every press, and the line that answers it

All lines are `PROBE P<n> ` + `k=v` pairs, ≤ 300 chars, through `LRG_Main.LogC`, so the whole set is
`grep "GAME PROBE "` in `lorerim_glue.log`.

| Press | Exact log line (keys are binding) | Pass → decides |
|---|---|---|
| **P0** | `PROBE P0 menumode=<0\|1> subs=<0\|1> ptts=<n> capbg=<n> onscene=<n> openmic=<n> autoradius=<n> vt_base=<EditorID> vt_lev=<EditorID> phase=<before\|after-chim>` | which base CHIM nulls ⇒ the voice-keeper target; `menumode=1` would mean something made the Dialogue Menu a pausing menu (and would kill every CHIM hotkey) ⇒ D-13's scope |
| **P0b** | `PROBE P0b mode=<default\|defaultonly> try=<1..3> open=<0\|1> ms=<n>` | `bActivateDefaultOnly`; E-R6 |
| **P1** | `PROBE P1 t=<ms> st=<n> timerid=<n> sub="<first 40 chars>"` — one line per sample, batched ≤ 12 per log line as `st=1,1,3,2 timerid=7,7,9,9 t=…` | the readiness rule; **does `iAllowProgressTimerID` move per LINE or per SESSION** ⇒ the line-settle gate and RESPONDING signal 4 |
| **P2** | `PROBE P2 firstlist_ms=<n> cntA=<n> cntB=<n> cntC=<n> sel=<n> maxitems=<n> platform=<n> hidetopics=<0\|1> fam=<n>` | `iCountMode`; **`iPlatform != 0`** sizes D-2's residual window; the family probe. **No count mode works ⇒ stop, decision D1** |
| **P3/P4/P5** | `PROBE P3 pos=<k> ti=<n> new=<0\|1> text="<40>"` · same for P4 · `PROBE P5 row=<k> itemIndex=<n> text="<40>"` on a list of ≥ 10 entries | **(P3 or P4)** returns every text ⇒ `iReadMode`. P5's `itemIndex` is the row≠position demonstration. **"Only P5 works" is a FAILURE ⇒ D1** |
| **P3t** | `PROBE P3t mode=<3\|4> n=12 ms=<n> per=<ms> tail24_ms=<n>` | `iMaxEntries` and the tail cap |
| **P6** | `PROBE P6 hide=<H1\|H2> x_int=<n> x_float=<f> y_float=<f> sub_shown=<0\|1> cursor=<0\|1> reached1=<0\|1> flash_frames=<n>` | H2 keeps subtitles and readiness ⇒ the default hide mode. **`x_int != x_float` proves rule 9's `(UInt32)` trap; a negative or NaN `_x` proves rule 10** |
| **P6f** | the same two lines with `fam=2` (CHIM's `dialoguemenu.swf` hidden in MO2) — **run before D0 is answered** | whether hide / store / un-hide work on the family one CHIM update could make live |
| **P7** | `PROBE P7 guard=on clicks=3 e=3 space=3 wheel=3 selected_changed=<0\|1> skipped=<0\|1> tab_closed=<0\|1> ballowprogress_after_1s=<0\|1>` | G1/G2/G3 sufficient |
| **P7s** | `PROBE P7s smarttalk=<0\|1> held_ms=<n> polls=<n> found_true=<n>` | whether `GuardPoke()` alone holds Smart Talk, or D-21's hard precondition is the only answer |
| **P7p** | `PROBE P7p parked=1 push=<courier\|greeting> cnt_before=<n> cnt_after=<n> sig_changed=<0\|1> exitbtn=<0\|1> arrived_after_showlist=<0\|1>` | is an engine push during a park **dropped** or deferred ⇒ the park-window rule |
| **P8/P9** | `PROBE P8 route=<B\|A> clicked_pos=<n> ti=<n> timerid=<a→b> cnt=<0.1s samples> gate=<timerBool samples> modevent=<0\|1> next_layer=<0\|1> responses=<n>` + afterwards `grep "\[PlayerMenuTTS\]" AIAgent.log` | exactly ONE response per click and the next layer arrives ⇒ the route table. **Does `EntryCount()` collapse to 0, does the timer id move** ⇒ RESPONDING signals 3 and 4 (which is what removes the subtitle dependency) |
| **P8v** | `PROBE P8v wrong_ti=<n> verified=<0\|1> clicked=<0\|1>` | F7's defence works (`verified=0 clicked=0`) |
| **P10** | `PROBE P10 try=<1..10> wrote=2 read=<n>` | the read-back gate is reliable |
| **P11** | `PROBE P11 closeclean_ms=<n> reopen_visible=<0\|1> click_mouse=<0\|1> click_e=<0\|1> click_enter=<0\|1> allow_delay_on_reopen=<n> x_restored=<0\|1> onmenuopen_seen=<0\|1> onmenuclose_seen=<0\|1>` | hide/guard do not leak; **`_global` lifetime per open** (`allow_delay_on_reopen` must read **750**, not 100000000). A red result makes `GuardReset()` load-bearing |
| **P11b** | `PROBE P11b step=<1\|2> closed=<0\|1> walkaway_line=<0\|1> crit=0 guard=0` — **harmless non-critical closed layer only** | which close primitives are safe (E-R7) |
| **P12** | `PROBE P12 created=<0\|1> name="<lrgProbe>"` | is helper-SWF injection possible at all ⇒ D1 plan C |
| **P13** | `PROBE P13 replacechar_ms=<n> payload_chars=<n> part2=<0\|1>` + the server log must show the message arrived intact | the wire-cleaning cost and the byte cap (today's longest logged request target is 2,366 chars) |
| **P14** | `PROBE P14 route=<a\|b\|c> sent_ms=<n> arrived_ms=<n> x=<id>` + `grep "Setting dialogue busy for" AIAgent.log` after (c) | which delivery route works and how fast. **A P14a failure makes a business turn 11–14 s** |
| **P15** | `PROBE P15 barter=open st=<n> cnt=<n>` then `barter=closed st=<n> cnt=<n> showlist_needed=<0\|1>` | the SUSPENDED logic |
| **P16** | `PROBE P16 idle_s=<30\|60\|120> still_open=<0\|1>` | the engine's aware-player auto-close |
| **P17** | `PROBE P17 row=<k> itemIndex=<n> colour=<n> quest=<EditorID or ->` | is `0xFFD966` (16767334) ever seen ⇒ `bQuestColour`. Caveat: `iQuestColor` exists in **neither** SWF and `bOverrideUISettings = 0`, so it may simply be inert |

**Matrix** (design §1.12): an ordinary NPC · **an NPC with more than `iMaxItemsShown` topics** (an
innkeeper, or a follower carrying a command framework) · an NPC with a persuade **and** a bribe entry · a
merchant · **a guard ForceGreet (read-only: never click, never close)** · **an NPC inside a market-banter
scene (read-only)** · a follower · one run with a gamepad plugged in · **one run with CHIM's SWF hidden**.

**Pass for the Papyrus-only route:** P1, P2 (at least one count mode), **(P3 or P4)**, P6-H2, P7, P7p,
(P8 or P9), P10, P11. **If only P5 works, or no count mode works ⇒ stop and go to decision D1.**

### 10.3 Questions this plan's added features open

| Question | Answered by |
|---|---|
| Does `ev=facts`'s `wis` reflect the same verdict the engine uses inside dialogue? | **T6 / E18**: compare `WillIntimidateSucceed()` with the real INFO on 5 NPCs, one of them Foolhardy |
| Does a Papyrus `Game.IncrementStat` raise `OnTrackedStatsEvent` (so a free-conversation success would reach `conf_opts`)? | folded into **E13**; it is the reason `bCheckStats` ships **off** |
| Does `Game.AdvanceSkill` outside dialogue grant Speech XP at Requiem's rate? | **T6b, new**: read Speechcraft before/after one free-conversation success with `xp=1` |
| Does `GetBribeSuccess` include affordability (so an engine bribe below price is a free success)? | **E15 / T6**: gold just below and just above `GetBribeAmount()`. Rule F (never click when `pg < N`) protects either way |
| Do the four PNAM-inverted orc topics make their checks unpassable here? | the index builder flags them; **T6 / E21** on Ghorbash or Borgakh confirms (§12 D8b: report, change nothing) |
| Does `pay=` move the gold exactly once, and never on an aborted start? | **T11b, new**: a paid start that is refused for weapons / distance must leave gold unchanged |
| Does `qobj` stay cheap enough on a follower with 6 associated quests? | **T2**: log the `ev=facts` build time beside the dump |

---

## 11. The DLL question — what would need one, and why nothing here does

**Nothing in this build requires a DLL, an SKSE plugin build, an AS2/SWF compiler or any install.**
Every capability in §3–§8 is reachable from Papyrus with the sources already on disk (§1.3), plus the
offline Python reader of §5.1 running on the `python3` that the WSL distro already has. That is confirmed
from the other direction too: a published mod already reads a plain AS2 array through numeric GFx paths
from Papyrus (`NL_CMD - A Console Command Framework\scripts\source\nl_cmd.psc:71,79`), and four installed
mods read `selectedEntry` getters through `UI.GetInt`.

What a later DLL **would** add (design §8), and today's workaround — the seam is `LRG_DlgUI`, whose
functions each begin with a version test and route to a native twin with the **same signature**:

| Gain | Today's workaround | Seam |
|---|---|---|
| FormID identity of every entry (`parentTopic`, `parentTopicInfo`, `parentQuest`, `neverSaid`) | text → index (93.1 % of prompts unique, 99.1 % of layers) | optional `tid, iid, qid` fields in `lrg_topics`; the server already prefers ids over text |
| One-call list read; an event-driven "list changed" | N frame-synced `Get`s and a 0.1 s poll | `LRG_DlgUI.EntryText` / `EntryCount` native twins |
| Exact INFO tracking (`TESTopicInfoEvent` begin/end with FormIDs) | subtitle capture + stat/gold deltas + line matching | `lrg_dlg ev=info;iid=…;phase=begin\|end` into the same result composer |
| First-frame hiding, real cursor suppression | a 1–2 frame flash of the exit button and speaker name | `LRG_DlgUI.Hide()` native twin from a `MenuOpenCloseEvent` sink |
| No-session awareness (a read-only walker over running quests' branches) | harvest from sessions that happen anyway; the E-press "hail" | `lrg_topics origin=walker`, approximate, never executed without a live list |
| A native click with the stale check on the main thread | `SelectAndVerify` | `LRG_DlgUI.Click()` native twin |
| **Session shield** — keep a pending choice layer alive while CHIM runs `EndDialogue` | **assisted mode for deep branches** | nothing changes in the protocol; `iBranchInput` stays on `auto` |
| Objective **text** without CHIM's journal | `questlog` (§7.1) | `lrg_dlg ev=facts` gains `qtext=` |

**The middle step that is still "no DLL"** — a tiny helper SWF injected at menu open
(`createEmptyMovieClip` + `loadMovie`) returning the whole list as one string — ships as a pattern in this
very mod list (`RequiemLite_Config.psc:28-29`, `SFE_SubtitlesScript.psc:28-36`, the latter injecting into
this exact menu). It needs an AS2 build tool ⇒ **decision D1**, and it plugs into
`LRG_DlgUI.EntryText` / `EntryCount` without the driver noticing. It has dropped to **plan C**: do not put
it, or the DLL question, to the owner until probe **P3** has actually failed.

**The one thing only a DLL can give**, if X1 comes back "dies": a seamless deep branch. Until then,
"branching choices are handled appropriately" means the menu is shown for layer 2 onwards on ~86 % of
branching moments (only 1,106 of 7,962 multi-entry layers re-offer their own parent topic), or the bounded
re-walk of D-19 if the owner approves it (§12 D7).

---

## 12. The §9 decisions, as the owner took them (`OWNER_ADDENDA` item 4) — builders follow these

| id | Decision, applied |
|---|---|
| **D0** | **Keep CHIM's `dialoguemenu.swf`.** The driver supports **both** SWF families regardless, and **P6f** runs the family-2 pass anyway (a CHIM update could swap the winner overnight) |
| **D1** | Probe first; **nothing is installed without asking**. Expected not to be needed (P3 should pass) |
| **D2** | **The glue writes no IACC setting.** Recommended values (`bForceFirstPerson = 0`, `bHideDialogueMenu = 0`) go in the owner notes only. `bIaccToggle` ships **0** and, if ever enabled, the setters are called **only outside a session** (D-11) |
| **D3** | **Yes** — `action` before `message` on player-speech turns, through the real `JSON_TEMPLATE` hook, plus the two appended description clauses (`dialogue.reorder_json` default true) |
| **D4** | **Yes to both** — `ForgiveCrime` hidden always while menuless is on; the service / gold actions hidden whenever the real entry is known |
| **D5** | Bribe haggling **may name the price**; **scoff-first on**; grace **2.5 s**; silence **45 s**; **`iEngineOpen 0`**; **`iCritical 0`** (LETHAL handed back visible); **`iSceneGate 0`** |
| **D6** | **Engine-only for the 82 localized Creation Club plugins** — 22 speech topics stay engine-only, listed `unreadable`. No LZ4 module, nothing installed |
| **D7** | **Accept assisted**; the **bounded re-walk is built but OFF by default** (`bRewalk 0`), and only becomes a live question if X1 = "dies" |
| **D8** | **Report both upstream data bugs in the owner notes, change nothing**: (a) Little Lessons' `+10` restore is skipped whenever `SpeechVeryHard >= 100`, which any LoreRim Speech-scaling trait guarantees — every Dialogue Menu open with a married NPC permanently lowers all five Speech globals **today, with or without the glue**; (b) in four orc-follower check topics the success INFO is sorted after an unconditional failure INFO by PNAM, which may make those checks unpassable here |

Also settled, and not to be reopened by a builder: **Silver Tongue's ×0.7 re-roll is inert in this load
order** (two of five global FormIDs resolve to CELL records, three do not exist, so
`GetFormFromFile(0x16A3, …) as GlobalVariable` is `None` and the fragment throws five
"Cannot call SetValue() on a None object" per persuasion). Do not cite it as a live mover. What **does**
work is `QF_PerksQuest_0005F596.Fragment_13`: taking Silver Tongue re-rolls `SpeechEasy` / `Average` /
`Hard` / `VeryHard` **once**, so thresholds in a LoreRim save are per-save random numbers. **Read them
live, always.**

---

## 13. Build order, gates, and what "done" means for each lane

Design §11 is binding. Applied to the three lanes of this round:

| Step | Who | What | Gate to the next step |
|---|---|---|---|
| **0a** | owner | **X1 first**, then X0, X3 ×2, X2, X4, X5 — no glue code | written into the playtest notes; X1 decides `iBranchInput` / `bRewalk` |
| **0b** | **A** | `LRG_DlgUI.psc` + `LRG_DlgProbe.psc` | `compile.ps1` prints OK; no `UI.` outside `LRG_DlgUI`; the probe's presses run |
| **0c** | owner + A | the probe, in the order P0/P0b → P2 → P3/P4/P5/P3t → P1 → P6/P6f → P7/P7s/P7p → P10/P8/P9/P8v → **P11** → P11b/P12–P17 | the §10.2 pass set. **Anything red goes to D1 or to the owner, never into the driver** |
| **1** | **C** | `build_prompt_index.py` + `lrg_prompt_index.php` + `005_*.sql`, with `twat`, the crit grade, `scripted`, `compound` and the **kinds census** | `test_prompt_index.php` green; `unindexed < 5 %` on T2's ten NPCs |
| **2** | **C** | `lrg_dialogue.php` + `004_*.sql` + the hook edits + the action row + §6/§7/§8's server halves | flow tests **20–41** green |
| **3** | **B** | `LRG_Dialogue.psc` with `iEngineOpen 0`, `iSceneGate 0`, `bMenuless 0`, `bDlgDryRun 1`; `CmdSelectTopic` returns in one frame | **T2** dump parity; **T3** dry-run shows the right `WOULD CLICK` and clicks nothing |
| **4** | **B** | the anchored edits + the MCM page + `make_esp.py` + the deploy pre-flight | pre-flight passes; `esp_dump.py` shows **four** script names on `0x01000800` |
| **5** | owner | **live**, in this order: T4 (simple quest, menu never shown) → T6 + T6b (persuade / bribe / intimidate / free-conversation XP) → T5 (branching) → T10, T11, T11b → T8, T9, T12, T13 | stages and journal match a vanilla run; `drift` reported; the emergency key gives a working menu in **every** state |
| **6** | owner | only now, **one knob at a time, each judged on its counter**: `iEngineOpen = 1` → `iSceneGate = 1` → `iCritical = 1` | each knob judged on `scene_refusals` / `opens_without_match` / `layers_*`, not on argument |

**Two hard preconditions before `bMenuless` may leave dry-run at all:** `SmartTalkSafe()` returns true
(Smart Talk's `bSkipImmediateOnInput` and `bHoldToSkip` are `0`), and **P11 has been read**.

**Each lane's report must contain**, besides its build log: the anchor greps it ran with their output; every
`[U]` it left open and which probe press or flow test answers it; every place it deviated from this plan and
why; its `forIntegrator` notes; and — if it hit a step that could only be done with an install — both
options written out (what the DLL/helper route needs vs. the Papyrus-only fallback and what it loses), with
the fallback built.

---

## Appendix A — the MCM files as they are today (lane B)

`MCM/Config/LoreRimGlue/config.json` has **six** pages: `General`, `Intimacy`, `Initiative`, `Scene talk`,
`Survival`, `Keys` (`"modName": "LoreRimGlue"`). `settings.ini` has the matching sections `[General]`,
`[Intimacy]`, `[Initiative]`, `[SceneTalk]`, `[Survival]`, `[Keys]`.

* Add **one** new page, `"pageDisplayName": "Menuless questing"`, holding the `[Dialogue]`, `[Checks]` and
  `[Quests]` keys of §9 plus the probe buttons and the diagnostics text. `iKeyLeave` / `iKeyProbe` go into
  the existing `[Keys]` section and are also shown on the new page (MCM reads a setting as
  `"<key>:<Section>"`, so a key's section is independent of the page it appears on).
* `bPaidIntimacy` / `fPriceMultiplier` go into the existing `[Intimacy]` section and onto the existing
  `Intimacy` page.
* **The "missing key reads as 0" rule has already bitten this project once** — `settings.ini`'s own comment
  at `fOutroHold` records it ("A MISSING key here reads as 0, which is why `fOutroFar` falls back to its
  default in code instead of releasing her instantly"). Every new key therefore gets **both** an ini
  default line **and** an in-code default in `LRG_Main.SettingBool/Int/Float`'s call site, and the in-code
  default must equal the ini default.
* `LRG_Main.RegisterKeys()` calls `UnregisterForAllKeys()` first, so `iKeyLeave` and `iKeyProbe` must be
  registered **inside** that function (lane B) or inside `LRG_Dialogue.Maintenance()` /
  `LRG_DlgProbe.Maintenance()`, which `LRG_Main.Maintenance()` calls **after** it.

## Appendix B — verification commands each lane runs and pastes into its report

**Lane A / B (Windows PowerShell 5.1 — no `&&` / `||`; native stderr looks fatal, go through `cmd /c` or a
`.bat`):**

```
powershell -File glue\tools\compile.ps1                      # must print OK
python glue\tools\make_esp.py glue\game\LoreRimGlue          # (lane B) then:
python glue\tools\esp_dump.py glue\game\LoreRimGlue\LoreRimGlue.esp   # VMAD of 0x01000800 = 4 script names
findstr /N /C:"IsInMenuMode" glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc   # exactly 2 statements
findstr /C:"UI." glue\game\LoreRimGlue\Source\Scripts\LRG_DlgProbe.psc          # must be empty
findstr /C:"LRG_DlgProbe" glue\tools\make_esp.py                                # exactly 1
```

**Lane C (inside WSL; the distro cannot see the project root):** copy to `$env:TEMP\lrg_test`
(= `/mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test`), write the `.sh` with **LF** endings, then

```
wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/run.sh
```

with `run.sh` containing, in order: `php -l` over every touched file · `php tools/test_prompt_index.php` ·
`php tools/test_intent.php --quiet` · `php tools/test_gates.php` · `php tools/test_phrases.php` ·
`php tools/flows/run_flows.php --strict` (exit 0 = no FAIL, nothing pending, no warning) ·
`python3 tools/build_prompt_index.py --stats` (the kinds census) ·
`PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer -c "\dt lrg_*"`.

## Appendix C — the five things most likely to be got wrong, in one place

1. **`GuardReset()` at the top of every ARMING, on every branch, including dry-run.** If P11 comes back red
   and this line is missing, the owner's next *vanilla* dialogue is permanently unclickable by mouse, E and
   Enter — only TAB would close it.
2. **`UI.GetFloat` / `UI.SetFloat` for every display property, with validation.** `UI.GetInt` is
   `(UInt32)number`; a negative `_x` restores to 4,294,966,976 px off-screen.
3. **The D-12 pass-through must sit between the two `lrg_actions.php` anchors** (hints `:1300` and `:1304`),
   or every `ExtCmdLRG_SelectTopic` line dies as "unknown glue action" — silently.
4. **`functions.php`'s three inserted statements must be at brace depth 0**, outside both SHARMAT branches,
   or the module and its gates vanish exactly where `ext/aiagent_nsfw` is running its own scenes.
5. **`CmdSelectTopic` contains no `Utility.Wait*`.** Commands execute only on
   `ManagerMainQueue::threadFunction`, which is also the `[CLEANER]` voice-restore thread: a command that
   waited 3 s + 8 s would stall every CHIM command and every voice restore for the whole agent set.
