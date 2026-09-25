# P2 - Dialogue Menu SWF: the exact UI paths to READ and CLICK topics from Papyrus

Phase 2 research, LoreRim Glue. Scope: the Papyrus-only "headless real dialogue" route. Everything here was checked against the
`dialoguemenu.swf` that actually wins in this install (CHIM's), not against memory or generic vanilla sources.

Conventions: **[V]** verified in a source I opened (file:line or URL). **[I]** inference. **NOT FOUND** = searched, stated where.
Web sources were read through a fetch tool that relays text through a small model; code was requested verbatim, but diff against
the URL before copying an identifier into shipped code. Nothing in the game has been run: every UI path is still UNTESTED IN GAME.
What changed is that the paths are no longer guesses - they are copied from the bytecode of the winning SWF and from native
plugins in this install that already use them.

Companion files (mine, same folder):
- `p2_swf_strings.py` - read-only SWF parser + AS2 disassembler / pseudo-decompiler (no third-party modules).
  Run: `py research\p2_swf_strings.py research "chim=F:\Modlists\LoreRim\mods\CHIM\Interface\dialoguemenu.swf" "norden16x9=...Norden UI 16x9\Interface\dialoguemenu.swf" "norden21x9=..."`
- `p2_swf_identifiers.json` - identifier sets, exports, instance placements, class member functions, GameDelegate callbacks, diffs.
- `p2_swf_disasm_chim.txt`, `p2_swf_disasm_norden16x9.txt` - per-SWF listing: pseudo-code + raw AVM1 disassembly (raw is authoritative).
  Line numbers quoted below as `chim.txt:N` refer to `p2_swf_disasm_chim.txt`.

---

## 0. Bottom line (what changes the build)

1. **The read path in the old report was wrong.** E6 guessed `TopicList.EntriesA.N.text` "path is a guess". Verified: the array is
   `EntriesA` (plain member) with a getter property `entryList` on top; entries are `{text, topicIsNew, topicIndex}`; the list clip is
   `_root.DialogueMenu_mc.TopicListHolder.List_mc`, aliased by the constructor as `_root.DialogueMenu_mc.TopicList`. Both SWFs
   (CHIM, Norden) are identical in this respect.
2. **There is a clean, engine-index based selection API: `TopicList.SetSelectedTopic(topicIndex)`**, and in CHIM's SWF a clean,
   un-gated click function: **`DialogueMenu_mc.topicClicked()`** (sets `timerBool=false`, calls `GameDelegate.call("TopicClicked",[selectedEntry.topicIndex])`).
   A scripted click therefore never has to pass through CHIM's player-TTS gate. `onSelectionClick(false)` is the universal
   fallback that works on every SWF variant (on CHIM's it goes through the gate and CHIM's DLL releases it).
3. **`eMenuState` is the "NPC speaking vs list ready" signal** (0 greeting, 1 list shown, 2 topic clicked / NPC answering,
   3 list animating in). Two native plugins in this install already read/write exactly `_root.DialogueMenu_mc.eMenuState`
   (Improved Alternate Conversation Camera, Smart Talk) - strong evidence the path resolves in the live movie.
   **IACC (enabled, `bHideDialogueMenu=1`) rewrites `eMenuState` to 2 every frame while the NPC talks** - the glue's state machine
   must treat 0 and 2 alike and must not fight it.
4. **A hidden menu is still a live menu: stray player input clicks topics.** `onMouseDown` is a global Mouse listener; any left
   click while the list is shown clicks the *currently selected* entry, E/Enter does the same. Headless mode needs an input guard.
   Two race-free guards exist in the SWF itself: park `eMenuState` at 3 while idle, and raise the static
   `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` so `bAllowProgress` never becomes true.
5. **Hiding the whole `DialogueMenu_mc` also hides the NPC subtitles** (the Dialogue Menu draws them itself:
   `ShowDialogueText -> SubtitleText.SetText`). Offer two hide modes: FULL (`DialogueMenu_mc._visible=false`) and TOPICS-ONLY
   (move `TopicListHolder` and `ExitButton` off-screen; do NOT use `TopicListHolder._visible`, the SWF and IACC both rewrite it).
6. **SKSE facts that bite:** `UI.Get*` is strictly typed (a Number read with `GetBool`/`GetString` returns false/""), `GetInt/SetInt/InvokeInt`
   go through `UInt32` (never pass or expect -1: use `GetFloat/SetFloat/InvokeFloat`), `Get*/Set*` run synchronously on the Papyrus
   thread, `Invoke*` is queued to the UI thread (ordered, executed next UI tick), `UI.Invoke(menu,target)` actually passes one
   argument `false`.
7. Array-index paths (`EntriesA.3.text`) are the one thing still unproven (Scaleform docs only document `GetVariableArray`).
   A proven fallback exists: step `iSelectedIndex` with `UI.SetFloat` and read through the getter `selectedEntry.text`
   (the `...selectedEntry.formId` getter pattern is used by three shipped mods in this install).
8. Optional escalation that is still "no DLL": inject a tiny helper SWF into the Dialogue Menu movie at runtime
   (`createEmptyMovieClip` + `loadMovie`, the way Norden's SWF itself loads `deardiary_dm/...swf`). It would push the topic list to
   Papyrus as a mod event (no polling, no array paths). Needs an AS2 build tool -> owner decision. Only needed if the probe shows
   reads are flaky.

---

## 1. What was analysed

| Label | File | Size | SWF | Stage | sha256 (file) |
|---|---|---|---|---|---|
| chim | `F:\Modlists\LoreRim\mods\CHIM\Interface\dialoguemenu.swf` | 24,908 | CWS v10, 16 frames, 30 fps | 1280x720 | `f078b69c9f8ae66f4d986775dcad05591b2e048ed6dd210103f841acba3eb093` |
| norden16x9 | `...\mods\Norden UI 16x9\Interface\dialoguemenu.swf` | 27,123 | CWS v10, 16 frames, 60 fps | 2560x720 | `109871a66e40388b9e4ad50978cb6c2fa899ad58911692a716ecad1f341562a1` |
| norden21x9 | `...\mods\Norden UI 21x9\Interface\dialoguemenu.swf` | 27,123 | - | - | byte-identical to norden16x9 |

**[V]** Only these three loose `dialoguemenu.swf` exist (recursive search of `mods\` for `dialoguemenu*`; `Stock Game\Data\Interface` and
`overwrite` have none; the vanilla one lives in a BSA and loses to any loose file). Priority **[V]** `profiles\Ultra\modlist.txt`:
line 8 `+CHIM`, line 148 `+Norden UI 16x9`, line 30 `-Norden UI 21x9` (disabled), line 3843 `+Smart Talk (Dialogue Menu Enhancer)`,
line 3710 `+Improved Alternate Conversation Camera` (file lists highest priority first) -> **CHIM's SWF wins.**
(Line numbers moved by one against older reports because `+LoreRim Glue` is now line 2.)

CHIM's SWF is the *vanilla SE skin* plus a DBVO-style click gate (meta.ini:30 FOMOD choice "Vanilla SE/AE Skyrim UI"). Norden's is
Dear Diary Dark Mode based (loads `deardiary_dm/config.txt`, `deardiary_dm/dialoguemenu/dialoguemenu_BG.swf` etc., 13 entry clips,
number-key selection). Visible side effect already in the install: the dialogue menu has lost Norden's skin. Irrelevant once hidden,
relevant for the un-hide fallback (it will look vanilla).

---

## 2. SWF anatomy (VERIFIED from bytecode; identical in CHIM and Norden unless noted)

### 2.1 Instance tree (PlaceObject names, `p2_swf_identifiers.json` -> `placements`)

```
_root                                        frame label "startFadeOut" @2; frame 16 script: DialogueMenu_mc.onFadeOutCompletion()
 └ DialogueMenu_mc          sprite 35, linkage "DialogueMenuObj" -> Object.registerClass("DialogueMenuObj", DialogueMenu)   chim.txt:17150
     ├ ExitButton           sprite 24 "Button" (Components.CrossPlatformButtons)
     ├ TopicListHolder      sprite 32; labels moveDown@2 moveUp@7 topicClicked@12 fadeListIn@23 slideListIn@34
     │    ├ PanelCopy_mc, ListPanel, TextCopy_mc(.textField), ScrollIndicators(.Up .Down)
     │    └ List_mc         sprite 20, linkage "TopicList" -> registerClass("TopicList", DialogueCenteredList)               chim.txt:17165
     │         ├ border
     │         └ Entry0..Entry7 (CHIM) / Entry0..Entry12 (Norden), each with .textField (+ .numField in Norden)
     ├ SubtitleText         EditText (NPC subtitle while in dialogue)
     └ SpeakerName          EditText
AS alias set in the DialogueMenu constructor:  this.TopicList = this.TopicListHolder.List_mc                                  chim.txt:289
```
Sprite 35 (`DialogueMenu_mc`) has exactly 1 frame in both SWFs (`sprites` in the JSON) -> `TopicListHolder`, `ExitButton`, `SubtitleText`, `SpeakerName`
are never moved by a timeline; a script-set `_x/_y` sticks. Sprite 32 (`TopicListHolder`) has 48 frames - it animates its CHILDREN and carries the
frame scripts that set `_parent.menuState`. The root has 16 frames (fade-out only).
Class chain of the list: `DialogueCenteredList` extends `Shared.CenteredScrollingList` extends `Shared.BSScrollingList` extends MovieClip.
`_root` vs `_level0`: DSN used `_level0.DialogueMenu_mc...`, CHIM/IACC/Smart Talk use `_root.DialogueMenu_mc...`; SKSE's UI.psc
requires the `_root` or `_global` prefix (UI.psc:41-43). Use `_root`.

### 2.2 Engine -> SWF callbacks (GameDelegate.addCallBack, `InitExtensions`, chim.txt:296-325)

| Engine event | AS handler | What it does |
|---|---|---|
| `PopulateDialogueList` | `PopulateDialogueLists()` | args = triples `(text, topicIsNew, topicIndex)` + last arg = topicIndex to pre-select or -1. `TopicList.ClearList()`; pushes `{text, topicIsNew, topicIndex}` into `TopicList.entryList`; `SetSelectedTopic(last)` if != -1; `InvalidateData()`. chim.txt:381-399 |
| `ShowDialogueList` | `DoShowDialogueList(abNewList, abHideExitButton)` | if state is TOPIC_CLICKED, or SHOW_GREETING with entries: `ShowDialogueList()` -> `TopicListHolder._visible=true`, plays fadeListIn/slideListIn, state=TRANSITIONING; the holder's timeline sets `_parent.menuState = TOPIC_LIST_SHOWN` at the end (frames 33 / 48). Always sets `ExitButton._visible = !abHideExitButton`. **CHIM only:** the whole body is skipped while `timerBool == true`. chim.txt:400-426, 171-230 |
| `ShowDialogueText` / `HideDialogueText` | same names | `SubtitleText.SetText(text)` / `SetText(" ")` |
| `SetSpeakerName` | same | `SpeakerName.SetText(name)` |
| `NotifyVoiceReady` | `OnVoiceReady()` | `StartProgressTimer()`: `bAllowProgress=false`, after `DialogueMenu.ALLOW_PROGRESS_DELAY` (750 ms) `bAllowProgress=true` |
| `Cancel` | `onCancelPress()` | state SHOW_GREETING -> `SkipText()`; else `StartHideMenu()` |
| `StartHideMenu` | same | hides subtitle/name/exit, `_parent.gotoAndPlay("startFadeOut")`, `GameDelegate.call("CloseMenu", [])` |
| `AdjustForPALSD` | same | cosmetic |

SWF -> engine calls (complete list): `TopicClicked [topicIndex]`, `SkipText []`, `CloseMenu []`, `FadeDone []`.
`GameDelegate.call(method, params)` does `params.unshift(method, uid); ExternalInterface.call.apply(null, params)` (class gfx.io.GameDelegate
in the same SWF) -> the engine receives `("TopicClicked", <uid>, topicIndex)`. `topicIndex` is the number the ENGINE handed out in
`PopulateDialogueList`; the SWF never invents it. **[I]** it is the position in `MenuTopicManager::dialogueList` (Smart Talk re-sorts
that list natively, `Sorting::ApplyDialogueSorting(RE::MenuTopicManager*)` string in SmartTalk.dll - so never assume
topicIndex == array position; always click by the entry's own `topicIndex`).

### 2.3 Menu state machine (statics on `_global.DialogueMenu`, chim.txt:521-527)

`SHOW_GREETING=0`, `TOPIC_LIST_SHOWN=1`, `TOPIC_CLICKED=2`, `TRANSITIONING=3`, `ALLOW_PROGRESS_DELAY=750`, `iMouseDownExecutionCount=0`.
Instance members: `eMenuState` (Number, also exposed as property `menuState` with `__get__menuState/__set__menuState`), `bFadedIn`,
`bAllowProgress`, `iAllowProgressTimerID`, CHIM only: `timerBool` (prototype default false), `timer`.

Normal cycle: open -> 0 (greeting plays; the list may already be populated) -> engine `ShowDialogueList` -> 3 -> (animation) -> 1
-> click -> 2 (NPC answers, all response lines play on their own) -> engine `PopulateDialogueList` + `ShowDialogueList` -> 3 -> 1 ...
Goodbye INFO / walk away -> engine `StartHideMenu` -> menu closes (`OnMenuClose`).

### 2.4 Player-input paths inside the SWF (why a hidden menu still reacts)

- `onItemSelect(event)` chim.txt:427 (raw 333F-342F): `if (bAllowProgress && event.keyboardOrMouse != 0) { if (state==1) onSelectionClick(event && event.mouseClick); else if (state==2 || state==0) SkipText(); bAllowProgress=false; }` - **does nothing in state 3.**
- `onMouseDown()` chim.txt:452: Mouse listener (`Mouse.addListener(this)`), every second call runs `onItemSelect({mouseClick:true})`. Fires regardless of clip visibility.
- Keyboard/gamepad: `handleInput` -> list `handleInput` -> ENTER -> `onItemPress()` -> dispatches `itemPress` -> `onItemSelect`. TAB -> `onCancelPress()`.
- `onSelectionClick(abMouseClick)` with a truthy arg first calls `TopicList.SetSelectedIndexByMouse(false)` (re-selects whatever is under the cursor). **Scripted calls must pass `false`.** DSN passed `1.0` - harmless on the 2011 SWF it targeted, wrong on these two.

### 2.5 List API (chim.txt:12293-12700, 16405-16560)

- `EntriesA` : Array (plain member). `entryList` : getter/setter property returning `EntriesA`. `selectedEntry` : getter `EntriesA[iSelectedIndex]`.
  `selectedIndex` : getter `iSelectedIndex`, setter calls `doSetSelectedIndex(v)`. Plain members: `iSelectedIndex`, `iHighlightedIndex`,
  `iScrollPosition`, `iMaxScrollPosition` (= entry count - 1 when the list is non-empty; `CalculateMaxScrollPosition`), `iListItemsShown`,
  `iMaxItemsShown` (8 CHIM / 13 Norden), `iPlatform`, `bDisableInput`, `bDisableSelection`, `bListAnimating`.
- `SetSelectedTopic(aiTopicIndex)` (DialogueCenteredList, chim.txt:16548): sets `iSelectedIndex = iScrollPosition = 0`, then for the entry whose
  `topicIndex == aiTopicIndex` sets `iScrollPosition = iSelectedIndex = iHighlightedIndex = its array position`. No redraw, no events.
  **This is the selection call to use: it takes the engine's topicIndex and needs nothing else.**
- `doSetSelectedIndex(aiNewIndex, aiKeyboardOrMouse, abMouseFocus)` (chim.txt:12425): array-position based; sets `iSelectedIndex` unless
  `abMouseFocus === true` or `bDisableSelection`; redraws highlight; dispatches `selectionChange`.
- `UpdateList()` (DialogueCenteredList override, chim.txt:16424): on gamepad (`iPlatform != 0`) or after `RestoreScrollPosition(..., true)` it
  **overwrites `iSelectedIndex` with the centred entry**. So never put an `UpdateList` between selecting and clicking unless scroll
  position == selection (SetSelectedTopic guarantees that).
- `ClearList()`, `InvalidateData()`, `SetSelectedIndexByMouse(abMouseHighlight)`, `RestoreScrollPosition(pos, recenter)`.

---

## 3. What CHIM added (diff of identifier sets and member functions, `p2_swf_identifiers.json` -> `diffs`)

CHIM-only identifiers: `PlayMenuTopic`, `SendModEvent`, `skse`, `initMenu`, `startTopicClickedTimer`, `timerBool`, `timer`, `setTimeout`,
`off`, `join`, `__Packages.test`, and the sanitising characters `" ("  "  / \ : * ? < > | _`. CHIM-only functions: `initMenu`,
`startTopicClickedTimer`, `topicClicked`. Norden-only: the Dear Diary config block (`ParseConfig`, `HIDE_TOPICS`, `TOPICS_FONT_SIZE`, ...),
`topicsFadeIn/topicsFadeOut` (animate `TopicListHolder._alpha`), `numField`, `QuestMarker`, `Entry8..Entry12`, number-key selection in `handleInput`.

CHIM's gate, verified at bytecode level:
```
onSelectionClick(abMouseClick)            chim.txt:480
    this.timerBool = true                                          <- added
    if (abMouseClick) TopicList.SetSelectedIndexByMouse(false)
    if (eMenuState == TOPIC_LIST_SHOWN) eMenuState = TOPIC_CLICKED
    ... scroll/UpdateList, play "topicClicked", show TextCopy ...
    this.initMenu()                                                <- replaces GameDelegate.call("TopicClicked", ...)
initMenu()                                chim.txt:499
    s = List_mc.selectedEntry.text.split(" (")[0]  with ' ' / \ : * ? " < > |  each replaced by '_'
    skse.SendModEvent("PlayMenuTopic", s)
startTopicClickedTimer(voicePackID)       chim.txt:504  (raw 3B02-3C47)
    if (voicePackID == "off") { timerBool = false; GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex]) }
    else { ms = Math.round(WORDS * 60 / 300 * 1000) + 1400 ; this.timer = setTimeout(this, "topicClicked", ms) }     WORDS = text.split(" (")[0].split(" ").length
topicClicked()                            chim.txt:514  (raw 3C52-3CB3)
    timerBool = false; GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex])                       <- NO guard at all
DoShowDialogueList(...)                   chim.txt:400  (raw 31AA..326A): whole body wrapped in  if (this.timerBool == false)
```
Corrections to earlier reports: the timeout is per WORD (200 ms/word + 1.4 s), not per character (chim-dll-esp.md:325); `topicClicked()` is
not "timerBool guarded" (chim-dll-esp.md:327) - it clears the flag and clicks unconditionally.

Norden's / vanilla-SE `onSelectionClick` ends with `GameDelegate.call("TopicClicked", [this.TopicList.selectedEntry.topicIndex])` directly
(norden listing, `r2.onSelectionClick`).

---

## 4. SKSE Papyrus UI: exact semantics (source: https://raw.githubusercontent.com/ianpatt/skse64/master/skse64/PapyrusUI.cpp , Hooks_UI.cpp)

Signatures **[V]** `F:\Modlists\LoreRim\mods\Skyrim Script Extender (SKSE64)\Scripts\Source\UI.psc`:
`IsMenuOpen` :47, `SetBool/SetInt/SetFloat/SetString` :57-60, `GetBool/GetInt/GetFloat/GetString` :71-74, `Invoke` :86 (= `InvokeBool(menu,target,false)`),
`InvokeBool/Int/Float/String` :90-93, `InvokeBoolA/IntA/FloatA/StringA` :98-101, `InvokeForm` :107. Menu name `"Dialogue Menu"` :6.

| Papyrus | Native implementation **[V]** | Consequences |
|---|---|---|
| `Get*` | `view->GetVariable(&fxResult, sourceStr.data)` executed immediately on the calling (Papyrus VM) thread; `GetGFxValue<T>` returns the value **only if the GFx type matches exactly** (Bool / Number / String), else `false / 0 / ""` | No coercion: read `eMenuState` with GetInt/GetFloat, `text` with GetString, `topicIsNew` with GetBool (and GetFloat as a second try). A missing path and a false/0 value are indistinguishable. `GetInt` is `GetT<UInt32>`: `(UInt32)number` - use **GetFloat** for anything that can be -1 (`iSelectedIndex`, `iHighlightedIndex`). |
| `Set*` | `view->SetVariable(targetStr.data, &fxValue, 1)`, immediate, same thread. `1` = `SV_Sticky` ("assignment pending until its target object is created", Autodesk Scaleform docs) | Works for display properties (`_visible`, `_x`, `_alpha`), plain members and AS properties with setters. `SetInt` is `UInt32 -> SetNumber`: **never pass a negative int**, use SetFloat. UI.psc:51 says "Target value must already exist". |
| `Invoke*` | builds `UIInvokeDelegate(menu, target)`, args are GFxValues of ONE type, `uiManager->QueueCommand(cmd)`; later on the UI thread `view->Invoke(target_.c_str(), NULL, args, n)` inside `UIManager::ProcessCommands` | Asynchronous but strictly ordered. No return value. Silently does nothing if the function does not exist or the menu is closed. Mixed-type argument lists are impossible (so `GameDelegate.call("TopicClicked",[i])` cannot be invoked directly; go through an AS function). Array variants are limited by Papyrus arrays (128). |

Path syntax: Scaleform docs: "Nested object names are separated using the dot '.' and are case sensitive." Paths may run through AS members
that reference clips/objects - proven in this install: Smart Talk's ini hooks `_root.DialogueMenu_mc.TopicList.SetEntryText`
(`SmartTalk.ini:77`; `TopicList` is an AS alias, not a timeline instance), SKSE's own example invokes `_global.skse.Log` (UI.psc:83).
Getter properties resolve: `UI.GetInt("InventoryMenu", "_root.Menu_mc.inventoryLists.itemList.selectedEntry.formId")` is used in
`YEET - Store Quest Items\...\yeetQuestAliasScript.psc:35`, `Dynamic Crafting Animations\...\CA_PlayerAliasScript.psc:306`,
`Biggie Traits\...\Traits_ResetMenuScript.psc:37` **[V]**.
**Array element as a path component (`EntriesA.3.text`): NOT FOUND in any .psc of the modlist (grep for `entryList`/`EntriesA`/numeric path
components in `UI.Get*` calls) and not documented by Scaleform (docs only describe `GetVariableArray`).** **[I]** AS2 stores array elements
as members named "0","1",..., and the path walker resolves components with GetMember, so it should work. Probe P3 settles it; P4 is the fallback.

Timing: use `Utility.WaitMenuMode()` for every wait inside a session (Utility.psc:38; CHIM does exactly this while the Dialogue Menu is open,
`AIAgentAIMind.psc:1438-1443`).

Visibility/advance: Scaleform advances invisible clips by default ("If this extension property is set to "true" then invisible movie clips
are not added into the Advance list" - Autodesk docs on `_global.noInvisibleAdvance`; https://help.autodesk.com/cloudhelp/ENU/Scaleform-Help/scaleform_help/best_practices/actionscript_optimize/advance.html).
Neither SWF contains the identifier `noInvisibleAdvance` **[V]** -> timelines (the list-in animation that sets state 1) keep running under `_visible=false` **[I, probe P6]**.

---

## 5. Other plugins in THIS install that drive the same paths (evidence + hazards)

Found by scanning every `mods\*\SKSE\Plugins\*.dll` for the identifiers (8 hits; relevant three):

| Plugin | Strings found **[V]** | What it does | Consequence for the glue |
|---|---|---|---|
| `CHIM\SKSE\Plugins\AIAgent.dll` | `_root.DialogueMenu_mc.startTopicClickedTimer` @0x2ed678, `PlayMenuTopic` @0x30ecc0 followed by `Player TTS for Traditional Dialogue is disabled` / `... could not start`, `[PlayerMenuTTS] Releasing held topic for '{}' ({})`, `player TTS did not start within 7 seconds`, `player TTS finished`, `QueuePlayerMenuTopicTimerOff`, `PumpPlayerMenuDelayedSelectionCapture`, `[PlayerMenuTTS] Timed out waiting for selected dialogue topic; replaying original click`, user-event names `accept select click topic submit activate` | native sink for the SWF's `PlayMenuTopic` mod event; releases the held click by invoking `startTopicClickedTimer` | see section 6 |
| `Improved Alternate Conversation Camera\...\AlternateConversationCamera.dll` | `_root.DialogueMenu_mc.TopicListHolder`, `_root.DialogueMenu_mc.eMenuState`, `SetHideDialogueMenu` | Source of the original ACC (https://raw.githubusercontent.com/NasiRawon/AlternateConversationCamera/master/Menus.cpp , `DialMenuNextFrame_Hook`): every frame, if `bHideDialogueMenu` and zoom active: when state==0 **or the NPC is talking** (`mtm->unk70 && !mtm->unkB9`) it does `SetVariable("_root.DialogueMenu_mc.eMenuState", 2)` and hides `TopicListHolder` via DisplayInfo; when state==1 it shows it. Winning ini (`LoreRim - MCM and INI Settings\SKSE\Plugins\AlternateConversationCamera.ini:77`) `bHideDialogueMenu=1`, `bSwitchTarget=0`. (The installed fork may differ in detail; the strings are the same.) | (a) state 0 is unobservable for long - treat 0 and 2 identically ("NPC speaking"). (b) Do not hide via `TopicListHolder._visible` - IACC owns it. (c) A guard value of 3 survives: IACC only writes when state is 0, or while the NPC talks. |
| `Smart Talk (Dialogue Menu Enhancer)\...\SmartTalk.dll` | `_root.DialogueMenu_mc.eMenuState`, `.bAllowProgress`, `.bFadedIn`, `.onCancelPress`, `.SkipText`, `.timerBool`, `.timer`, `.topicClicked`, `_root.DialogueMenu_mc.TopicList.SetEntryText` | Source (https://raw.githubusercontent.com/Seb263/SkyrimSE_SmartTalk/master/src/Features/Input.hpp): on player skip input it reads `eMenuState`, does `SetVariable("_root.DialogueMenu_mc.bAllowProgress", true)`, then `InvokeNoReturn("_root.DialogueMenu_mc.SkipText")` or `...onCancelPress`. `bDBVOIntegration=1` (ini:107) explains the `timerBool/timer/topicClicked` strings (not in GitHub master; installed 1.0.5 has them) | Input-driven only: inert unless the player presses keys. It can defeat a `bAllowProgress=false` guard (it forces it true), but not the `eMenuState=3` guard. `iPapyrusHandle=3` documents the skip-too-early fragment hazard - the glue must never call `SkipText`. |

These three are also the best available proof that `_root.DialogueMenu_mc.<member>` Get/Set/Invoke works against this SWF family in the live game.

IACC detail worth knowing: its Nexus page documents a past bug "dialog topics not being displayed correctly when bHideDialogueMenu=1" - i.e. exactly this
per-frame `eMenuState` rewriting going wrong. IACC exposes Papyrus natives on script `IACC` (string table of `Scripts\IACC.pex`: `SetHideDialogueMenu`,
`GetHideDialogueMenu`, type `Bool`, param name `value`; no `.psc` is shipped, parameter list not decoded). Option if P1 shows the state sequence is unusable:
switch that setting off for the duration of a headless session. Costs a soft dependency and a stub `.psc`; prefer tolerating IACC.

Other DLL hits of the scan (`entryList` only: InventoryInjector, MCM-Unlocked, po3_PhotoMode, DragonbornsBestiary, CompareEquipmentNG) concern other menus.

---

## 6. CHIM's click gate and a PROGRAMMATIC click

Facts **[V]**: the Papyrus bridge is dead code (`AIAgentPapyrusFunctions.psc:1097-1100` unregisters `PlayMenuTopic` and
`AIAgent_PlayerMenuTTSFinished`: "Traditional dialogue Player TTS is handled in native code. Re-registering the Papyrus bridge here can
resume the held topic twice."). The legacy handler (`:1170-1184`) shows the intended logic: if
`AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue") <= 0` -> `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")`
immediately; else `startPlayerMenuDialogueTTS(strArg)` and release when TTS ends. The DLL strings mirror that (reason strings
"...is disabled", "...could not start", "player TTS finished", "player TTS did not start within 7 seconds").
Proof by necessity: with CHIM's SWF every click in the game is held by `onSelectionClick`; if the native did not release it when the
setting is off, vanilla dialogue would be dead for every CHIM user.

What happens to a scripted click:

| Route | `_player_tts_traditional_dialogue` OFF | ON |
|---|---|---|
| A. `onSelectionClick(false)` (goes through the gate) | SWF sends `PlayMenuTopic` -> DLL sink -> queued task -> `startTopicClickedTimer("off")` -> `TopicClicked`. **[I]** about one frame of latency. | CHIM asks the server for player TTS (`player_menu_tts_play`), speaks the VANILLA PROMPT in the player's voice, then releases; worst case 7 s timeout + TTS length. The player has already said the line in their own words -> double speech + delay. While held, `DoShowDialogueList` is ignored. |
| B. `SetSelectedTopic(i)` + `__set__menuState(2)` + `topicClicked()` (bypass) | `TopicClicked` fires on the next UI tick. No mod event, so CHIM's native is never involved. | Same - the setting is irrelevant. |

Do NOT combine A with your own `startTopicClickedTimer("off")`: two releases = two `TopicClicked` calls, the second one possibly
landing on the *next* list (this is the bug CHIM's comment warns about).

**What the glue must do:** use route B when CHIM's SWF is active, route A otherwise. Deterministic rule without SWF fingerprinting:
route B first; if 1.0 s later the state is still 2 *and* `SubtitleText.text` is still blank *and* the list is unchanged, the function did
not exist (non-CHIM SWF) -> fall back once to `__set__menuState(1)` + `onSelectionClick(false)`. Passive confirmation of "CHIM SWF active":
register for mod event `PlayMenuTopic` (Form.psc:209) - it fires on every *player* click with CHIM's SWF and never with another.
**[I]** CHIM's second native mechanism ("delayed selection capture ... replaying original click", user events `accept/click/...`) is
input-event driven and should stay dormant for AS-level invokes; probe P8 checks AIAgent.log for `[PlayerMenuTTS]` lines.
Side effect to tell the server team: CHIM's `TESTopicInfoEvent` sink still logs the clicked prompt as `Player: <vanilla prompt>`
(chim-dll-esp.md section 5), so the context gets both the player's own words and the vanilla prompt.

---

## 7. FINAL CANDIDATE PATH LIST

`M = "Dialogue Menu"`, `D = "_root.DialogueMenu_mc"`, `L = D + ".TopicList"` (fallback spelling for every `L` path: `D + ".TopicListHolder.List_mc"`).
Confidence = that the path/func resolves and behaves as described in the live game. "SWF" column: C = CHIM only, * = both SWFs.

### 7.1 Reads

| # | Call | Meaning | SWF | Conf. | If it fails |
|---|---|---|---|---|---|
| R1 | `UI.GetInt(M, D+".eMenuState")` | 0/2 NPC speaking, 3 list animating (list already populated), 1 list ready | * | 95% (IACC + Smart Talk read the same path natively) | `UI.GetFloat`; property spelling `D+".menuState"` |
| R2 | `UI.GetFloat(M, L+".iMaxScrollPosition") + 1` | entry count (valid when >= 1 entry) | * | 85% | R2b |
| R2b | `UI.GetInt(M, L+".EntriesA.length")` | entry count | * | 70% | `L+".entryList.length"`; or iterate R3 until text == "" |
| R3 | `UI.GetString(M, L+".EntriesA."+i+".text")`, `UI.GetInt(..."."+i+".topicIndex")`, `UI.GetBool(..."."+i+".topicIsNew")` | the i-th topic | * | 70% (array index as path component unproven) | same through `L+".entryList."+i`; then R4 |
| R4 | `UI.SetFloat(M, L+".iSelectedIndex", i)` then `UI.GetString(M, L+".selectedEntry.text")`, `GetInt(...selectedEntry.topicIndex)`, `GetBool(...selectedEntry.topicIsNew)`; restore `iSelectedIndex` afterwards | same data via the getter (pattern proven by 3 shipped mods) | * | 90% | R5 |
| R5 | `UI.GetString(M, L+".Entry"+k+".textField.text")` + `UI.GetFloat(M, L+".Entry"+k+".itemIndex")` | visible clips only (8), text only | * | 90% | - |
| R6 | `UI.GetString(M, D+".SubtitleText.text")`, `UI.GetString(M, D+".SpeakerName.text")` | current NPC line (" " when none) / speaker name | * | 85% | `.htmlText` |
| R7 | `UI.GetBool(M, D+".timerBool")` | CHIM gate armed (a click is being held) | C | 90% | - |
| R8 | `UI.GetBool(M, D+".bAllowProgress")`, `UI.GetBool(M, D+".bFadedIn")` | input-progress flag / menu not closing | * | 90% | - |
| R9 | `UI.GetFloat(M, L+".iSelectedIndex")` | current selection (-1 none) | * | 90% (DSN read exactly this) | - |

`topicIsNew`: the engine may push a Bool or a Number; read with GetBool first, GetFloat second, log both in the probe.
The `text` already has engine substitutions applied and carries the author's suffixes - "(Persuade)", "(Intimidate)", "(N gold)" - which is
how the LLM can recognise speech-check options; success or failure is then decided by the engine when the topic is clicked.

### 7.2 Select + click

| # | Call (all queued, in this order) | SWF | Conf. | Fallback |
|---|---|---|---|---|
| C1 | `UI.InvokeInt(M, L+".SetSelectedTopic", topicIndex)` | * | 90% | `UI.InvokeFloat`; or `UI.InvokeInt(M, L+".doSetSelectedIndex", arrayPos)` (DSN's call); or `UI.SetFloat(M, L+".iSelectedIndex", arrayPos)` |
| C2 | `UI.InvokeInt(M, D+".__set__menuState", 2)` | * | 80% | `UI.SetInt(M, D+".eMenuState", 2)` (immediate, not ordered with the queue - do it BEFORE C1) |
| C3 | `UI.Invoke(M, D+".topicClicked")` | C | 85% (Smart Talk invokes this name natively) | route A: `UI.InvokeInt(M, D+".__set__menuState", 1)` + `UI.InvokeBool(M, D+".onSelectionClick", false)` (* both SWFs; on CHIM's it arms the gate, the DLL releases it) |
| C4 | emergency release of a stuck gate: `UI.InvokeString(M, D+".startTopicClickedTimer", "off")` only if `timerBool` has been true for > 9 s | C | 95% (CHIM's own call, psc:1174) | - |

Verify-after-click (1 s budget): state stays 2 and then `SubtitleText.text` becomes non-blank, or the menu closes, or the list changes.

### 7.3 Hide, guard, close, un-hide

| # | Call | Effect | Conf. | Notes |
|---|---|---|---|---|
| H1 | `UI.SetBool(M, D+"._visible", false)` | FULL hide incl. subtitles, exit button, speaker name | 90% | the SWF never writes this property; must be re-applied on every menu open (new movie each time **[I]**) |
| H2 | `UI.SetFloat(M, D+".TopicListHolder._x", 5000.0)` + `UI.SetFloat(M, D+".ExitButton._x", 5000.0)` (+ optional `UI.SetBool(M, D+".SpeakerName._visible", false)`) | TOPICS-ONLY hide, subtitles stay | 80% | do not use `TopicListHolder._visible` (SWF sets it true on every list, IACC toggles it) nor `_alpha` (Norden animates it). Off-screen clips get no mouse hits. |
| H3 | `UI.SetBool("Cursor Menu", "_root.mc_Cursor._visible", false)` | hides the mouse cursor | 75% | instance name verified in `Norden UI 16x9\Interface\cursormenu.swf` (only loose provider). Shared by all menus: restore on close AND whenever another menu opens (barter/training). |
| G1 | `UI.SetInt(M, "_global.DialogueMenu.ALLOW_PROGRESS_DELAY", 100000000)` + `UI.SetBool(M, D+".bAllowProgress", false)` | player input can neither click nor skip (`onItemSelect`, `SkipText` both need `bAllowProgress`) | 80% | Smart Talk can force `bAllowProgress` true on player input -> keep G2 as well |
| G2 | after reading a ready list: `UI.InvokeInt(M, D+".__set__menuState", 3)` | `onItemSelect` is a no-op in state 3; the engine's `ShowDialogueList` is also ignored in state 3, which is harmless because C2 always sets 2 before a click | 85% | residual window = one poll interval between state 1 and the guard |
| X1 | `UI.Invoke(M, D+".StartHideMenu")` | engine-clean close: `GameDelegate.call("CloseMenu")`, same path as Tab, walk-away handled by the engine | 90% | `onCancelPress` only skips text in state 0 - use StartHideMenu |
| U1 | un-hide (watchdog / emergency hotkey): `SetBool D._visible true`; `SetFloat` the two `_x` back (store originals at hide time); `SetInt _global.DialogueMenu.ALLOW_PROGRESS_DELAY 750`; `SetBool D.bAllowProgress true`; if state==3 and entries exist -> `InvokeInt D.__set__menuState 1` and `SetBool D.TopicListHolder._visible true`; restore cursor | hands the real menu back | 85% | looks vanilla (CHIM skin) |

Never call: `SkipText` (fragment hazard), `startTopicClickedTimer` right after `onSelectionClick` (double click), anything with a negative
value through `*Int`.

First-frame flash: Papyrus learns about the open menu a few frames late (`OnMenuOpen` is an async event; for glue-opened sessions poll
`UI.IsMenuOpen` right after `Activate`). What can flash is small: `InitExtensions` starts with `TopicListHolder._visible = false` (chim.txt:315) and the
list is only shown after the greeting, so at worst the speaker name, the first subtitle and the exit button are visible for a moment. A Papyrus-only
build cannot do better; this is the one place where a native `MenuOpenCloseEvent` sink would be strictly superior.

Readiness must not depend on `eMenuState` alone: because IACC forces 2 while it thinks the NPC talks, use
`ready = state in {1,3}  OR  (list differs from the list at click time AND SubtitleText.text blank for >= 0.5 s)`, plus the watchdog.

---

## 8. Session algorithm the paths imply (for the builder)

```
OnMenuOpen("Dialogue Menu")  (RegisterForMenu, Form.psc:189; same handler for glue-opened and engine-opened sessions)
  if headless disabled or emergency flag -> leave the menu alone
  hide (H1 or H2 [+H3]); guard G1
  loop every 0.1 s with Utility.WaitMenuMode while UI.IsMenuOpen(M):
     s = R1
     if s == 1 or s == 3:                       # list populated (3 = still animating; do not wait for 1 if P6 shows hidden clips stall)
         n = R2 ; read entries R3 (or R4)       # only when (n, texts) differ from the last list sent, or after a click
         G2 (park state at 3)
         send list to server -> LLM picks topicIndex | "none" | "leave"
         pick:  C1, C2, C3 ; verify ; on failure route A once ; on second failure U1 (un-hide)
         leave: X1
     if R7 (timerBool) true for > 9 s: C4
     watchdog: no progress for N s, or any read returns the "missing" pattern twice -> U1
OnMenuClose -> restore cursor, clear session
```
List text can be read EARLY: `PopulateDialogueList` may arrive while the greeting still plays (the SWF explicitly handles
"SHOW_GREETING with entries"). **[I]** Read entries as soon as `n > 0`, send them to the server in parallel with the greeting, but click
only once the state has reached 3 or 1.

---

## 9. Prior art, quoted

- **Dragonborn Speaks Naturally** (native, menu open) https://raw.githubusercontent.com/YihaoPeng/DragonbornSpeaksNaturally/master/dsn_plugin/dsn_plugin/Hooks.cpp :
  reads prompts in its GFx Invoke hook (`if (strcmp(command, "PopulateDialogueList") == 0) { numTopics = (argc - 2) / 3; ... for (int j = 1; j < argc - 1; j = j + 3)`),
  selects with
  ```cpp
  dialogueMenu->GetVariable(&topicIndexVal, "_level0.DialogueMenu_mc.TopicList.iSelectedIndex");
  dialogueMenu->Invoke("_level0.DialogueMenu_mc.TopicList.SetSelectedTopic", NULL, "%d", desiredTopicIndex);
  dialogueMenu->Invoke("_level0.DialogueMenu_mc.TopicList.doSetSelectedIndex", NULL, "%d", desiredTopicIndex);
  dialogueMenu->Invoke("_level0.DialogueMenu_mc.TopicList.UpdateList", NULL, NULL, 0);
  dialogueMenu->Invoke("_level0.DialogueMenu_mc.onSelectionClick", NULL, "%d", 1.0);
  ```
  Same members as in CHIM's SWF (`iSelectedIndex`, `SetSelectedTopic`, `doSetSelectedIndex`, `UpdateList`, `onSelectionClick`). Two things not to copy:
  the truthy `onSelectionClick` argument (section 2.4) and passing a list position to `SetSelectedTopic` (it expects topicIndex).
- **Papyrus-only topic SELECTION: NOT FOUND** (web search for `UI.Invoke` + `onSelectionClick`/`doSetSelectedIndex`/`SetSelectedTopic`; grep of all
  `.psc` under `mods\` for `UI.*("Dialogue Menu"` returns only CHIM's three `startTopicClickedTimer` lines).
- **Papyrus driving the Dialogue Menu at all:** CHIM's legacy bridge `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")`
  (`AIAgentPapyrusFunctions.psc:1174,1182,1193`) - proves `UI.InvokeString` reaches this SWF's functions from Papyrus (it shipped enabled in earlier CHIM versions).
  DBVO is the origin of the pattern (Nexus 182554: its patcher "replaced the original GameDelegate.call("TopicClicked"...) call in the onSelectionClick() function").
- **Vanilla source semantics** (Mardoxx decompile, 2011 SWF) https://raw.githubusercontent.com/Mardoxx/skyrimui/master/src/dialoguemenu/DialogueMenu.as - same callbacks,
  same state constants, same `PopulateDialogueLists` triple layout; older `onItemSelect`/`onSelectionClick()` without the `mouseClick` argument. Use the local
  listings, not this file, for anything that matters.
- Smart Talk and ACC snippets: section 5.

---

## 10. The in-game PROBE (ship this first)

Goal: one hotkey press produces ~25 short log lines that settle every "Conf." above. Log through the existing `LRG_Main.LogC(cid, msg, npcName)`
(server log `lorerim_glue.log`; Papyrus logging is OFF in LoreRim; 300-char limit; `; | @` are stripped) and mirror to
`MiscUtil.PrintConsole` (PapyrusUtil `MiscUtil.psc:69`). Prefix every line `P<n>`. Default OFF, MCM/ini toggle, never in a tight loop.

Setup: `RegisterForKey(probeKey)` (Form.psc:164), `RegisterForMenu("Dialogue Menu")`, `RegisterForModEvent("PlayMenuTopic", "OnProbePlayMenuTopic")`.
On key: `ref = Game.GetCurrentCrosshairRef()` (Game.psc:449) as Actor; if the menu is not open: `ref.Activate(Game.GetPlayer())`
(ObjectReference.psc:143); wait up to 3 s (`WaitMenuMode(0.05)`) for `UI.IsMenuOpen(M)`. If the player is already in dialogue, probe that session.
A second hotkey (or the same key with Shift) advances to the click phase so the tester can look at the screen first.

| Step | Reads / invokes | Outcome -> what it proves |
|---|---|---|
| P0 | time from `Activate` to `IsMenuOpen`; `Game.GetDialogueTarget()` | Activate opens a session on this NPC (E2); latency budget for hiding before the first frames |
| P1 | every 50 ms for the whole probe: `GetInt D.eMenuState`, `GetFloat D.eMenuState`, `GetInt D.menuState`; log only changes with ms timestamps | R1 works; real state sequence WITH IACC + Smart Talk + CHIM active (expect 0->2 forced by IACC, then 3, then 1); property-getter path works |
| P2 | `GetFloat L.iMaxScrollPosition`, `GetInt L.EntriesA.length`, `GetInt L.entryList.length`, `GetFloat L.iSelectedIndex`, `GetFloat L.iMaxItemsShown`, `GetFloat L.iPlatform`; same six via `D.TopicListHolder.List_mc` | which count works; AS-alias path vs instance path; expect iMaxItemsShown=8 (=CHIM SWF; 13 would mean Norden's SWF won) |
| P3 | for i in 0..n-1: `GetString L.EntriesA.i.text`, `GetInt ...topicIndex`, `GetFloat ...topicIndex`, `GetBool ...topicIsNew`, `GetFloat ...topicIsNew`; repeat i=0 through `L.entryList.0.text` | array-index paths work (R3); GFx types of the three fields; whether topicIndex == i (Smart Talk sorting) |
| P4 | save `iSelectedIndex`; for i: `SetFloat L.iSelectedIndex i` -> `GetString L.selectedEntry.text`, `GetInt L.selectedEntry.topicIndex`; restore | fallback R4 works; Set on a plain member is immediate |
| P5 | `GetString L.Entry0.textField.text` .. `Entry7`, `GetFloat L.EntryK.itemIndex`; `GetString D.SubtitleText.text`, `GetString D.SpeakerName.text` (also during P9 while the NPC talks) | R5, R6; whether subtitles are readable -> CHIM-independent NPC-line capture |
| P6 | `SetBool D._visible false`; wait 1.5 s; read P1/P2 again; tester reports what is still on screen (subtitle? cursor? HUD subtitle?) ; then repeat the whole probe once with H2 instead (`SetFloat D.TopicListHolder._x 5000`, `SetFloat D.ExitButton._x 5000`, read the `_x` values back with GetFloat) | H1/H2 work; timelines advance while invisible (state reaches 1); whether the HUD shows subtitles when the dialogue menu's own field is hidden; cursor behaviour (E3) |
| P7 | guard: `SetInt _global.DialogueMenu.ALLOW_PROGRESS_DELAY 100000000`, read it back with GetFloat; `SetBool D.bAllowProgress false`; `InvokeInt D.__set__menuState 3`; read state after 0.2 s; tester clicks the mouse and presses E three times; read state, `timerBool`, subtitle | `_global` static path settable; `__set__` function invocable; guard really blocks stray clicks (nothing may happen; with Smart Talk a skip must not happen either because nothing is playing) |
| P8 | click, route B: pick the LAST entry (least likely to be the default selection): `InvokeInt L.SetSelectedTopic topicIndex`, `InvokeInt D.__set__menuState 2`, `Invoke D.topicClicked`; then 50 ms samples for 10 s of state, `timerBool`, `SubtitleText.text`, entry count + entry 0 text; note whether `OnProbePlayMenuTopic` fired; afterwards grep `AIAgent.log` for `[PlayerMenuTTS]` | the crux: a scripted click makes the NPC answer with the REAL line, fragments run (tester checks the quest/journal where applicable), the next layer list arrives (state 2 -> 3 -> 1, new entries); no CHIM gate involvement (no mod event, no `[PlayerMenuTTS]` log) |
| P9 | same as P8 on the next layer but route A: `InvokeInt D.__set__menuState 1`, `InvokeBool D.onSelectionClick false`; sample `timerBool` every 50 ms | gate arms (`timerBool` true), `PlayMenuTopic` received with the sanitised text, CHIM's DLL releases it; measured hold time with `_player_tts_traditional_dialogue` OFF. Run once more with the setting ON to measure the delay and hear the double speech |
| P10 | negative control: `Invoke D.glueNoSuchFunction`; `GetString D.noSuchMember`; `GetInt L.EntriesA.99.topicIndex` | missing function/path = silent no-op / default value, no CTD, nothing in SKSE log |
| P11 | close: `Invoke D.StartHideMenu`; time to `OnMenuClose`; then re-open the menu by hand and check it is visible and clickable again (hide and guard must NOT persist across opens); read `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` on the new open | X1 works; per-open movie lifetime; whether the static guard leaks into the next session (if it does, U1 must reset it on every open) |
| P12 | injection feasibility (only logs): `InvokeStringA M "_root.createEmptyMovieClip" ["lrgProbe","9731"]`, then `GetString M "_root.lrgProbe._name"` | "lrgProbe" -> a helper SWF could be loaded into the Dialogue Menu at runtime (section 0 item 8); "" -> not possible from Papyrus |
| P13 | un-hide U1 mid-session on a third hotkey | the emergency path really hands back a working vanilla menu |

Test matrix: (1) ordinary NPC with several topics, (2) NPC with a persuade/bribe/intimidate option (prompt suffix visible in `text`?), (3) a merchant
(service topic -> what does the state do when BarterMenu opens/closes, E12), (4) an engine-opened session (guard/ForceGreet): `OnMenuOpen` path only,
(5) gamepad plugged in (`iPlatform != 0` changes `UpdateList` selection behaviour).

Pass criteria for the Papyrus-only route: P1, (P3 or P4), P6 (one of the two hide modes), P7, P8 and P11 all green. If P8 fails but P9 works, ship route A and
require `_player_tts_traditional_dialogue` OFF. If both fail, the Papyrus-only route is dead and the owner must be asked about native tooling.

---

## 11. Status of the open experiments in `dialogue-engine.md`, and open questions

| Experiment | Status after this round |
|---|---|
| E3 (hide before first frame, cursor) | Paths established (H1/H2/H3); first-frame flash bounded (section 7.3); still needs P6 in game |
| E5 (DSN path against CHIM's SWF; is the `onSelectionClick` arg needed?) | Answered from bytecode: the arg must be FALSE/absent on this SWF (truthy = re-select under mouse); `onSelectionClick` arms CHIM's gate; `topicClicked()` is the un-gated click; `SetSelectedTopic(topicIndex)` is the right selector. P8/P9 confirm in game |
| E6 (entry data readable?) | Path corrected: `...TopicList.EntriesA.N.{text,topicIndex,topicIsNew}` (alias `entryList`); fallback via `selectedEntry`; P3/P4 confirm |
| E4, E12 | not in scope here, but the probe matrix (merchant case) and P1 sampling give the data |

Open questions (also in the structured output):
1. Do array-index path components resolve in `GetVariable` (P3)? If not, R4 costs n synchronous Set+Get pairs per list - fine for <= 20 topics.
2. Do timelines advance under `_visible=false` in Skyrim's Scaleform build as the Autodesk docs say (P6)? If not, use H2 or treat state 3 as ready.
3. Does the engine accept `TopicClicked` when no human-click animation/state preceded it (P8)? Expected yes: the engine only receives the index.
4. Does the installed IACC fork (1.2.0) still force `eMenuState` exactly like the original source (P1 sampling)?
5. Does `_global.DialogueMenu.ALLOW_PROGRESS_DELAY` persist across menu opens (P11)? It must be restored by U1 either way.
6. With `_player_tts_traditional_dialogue` ON, does CHIM's input-level "delayed selection capture" stay dormant for AS-level invokes (P8 + AIAgent.log)?
7. Owner decision: if polling reads prove flaky, is an injected helper SWF (needs an AS2 compiler such as MTASC/FFDec, no C++) acceptable? P12 tells whether it is even possible.
8. Does hiding `DialogueMenu_mc` remove the only subtitle display during dialogue, or does the HUD subtitle take over (P6 observation)?

---

## Verification (adversarial pass)

Independent re-check of the 14 load-bearing claims. Method: my own SWF tag walker (not the report's script) over both SWFs, hand-decoding of two
action records from the decompressed bytes, `sha256sum`, fresh `find`/grep over `mods\`, string + xref scan and `objdump -d` (WSL, read-only) of
`AIAgent.dll`, string scan of all 344 `mods\*\SKSE\Plugins\*.dll`, re-fetch of every cited URL. **[V]** = I re-opened the source. **[I]** = my inference.
Nothing was run in game; every UI path is still UNTESTED IN GAME.

### A. Verdict per claim (13 confirmed, 1 partly wrong)

| # | Claim | Verdict |
|---|---|---|
| 1 | CHIM's SWF wins; Norden 16x9 == 21x9; no other provider | **CONFIRMED [V]** `find mods -iname "dialoguemenu*"` = 3 swf (+ Norden's `deardiary_dm\dialoguemenu\*_BG/_up/_down.swf` helpers), none in `Stock Game\Data\Interface` / `overwrite`, no `.gfx`; sha256 re-computed = f078b69c... / 109871a6... (x2); modlist.txt :8 `+CHIM`, :148 `+Norden UI 16x9`, :30 `-Norden UI 21x9`, :3710 IACC, :3843 Smart Talk. |
| 2 | Instance tree, sprite 35 = 1 frame, sprite 32 = 48 frames | **CONFIRMED [V]** by an independent parser: root `DialogueMenu_mc`(char 35) -> `ExitButton`(24) `TopicListHolder`(32) `SubtitleText`(33, EditText) `SpeakerName`(34, EditText); 32 -> `PanelCopy_mc ListPanel TextCopy_mc List_mc ScrollIndicators(Down,Up)`; 20 -> `border`, `Entry0..7` (CHIM) / `Entry0..12` (Norden); exports `DialogueMenuObj`, `TopicList`; labels moveDown@2 moveUp@7 topicClicked@12 fadeListIn@23 slideListIn@34. My tag offsets equal the listing's (`DoInitAction 36` at 8302 etc.), so the listing's addresses are body offsets and trustworthy. |
| 3 | Entries `{text, topicIsNew, topicIndex}`, `entryList` getter for `EntriesA`, `selectedEntry` getter | **CONFIRMED [V]** chim.txt:381-399, 12482-12490; Mardoxx source re-fetched, identical `PopulateDialogueLists`. |
| 4 | `SetSelectedTopic(topicIndex)`; gamepad `UpdateList` overwrites `iSelectedIndex` | **CONFIRMED [V]**, with a hazard the report does not state: see C2 below (silent fall-back to entry 0). |
| 5 | CHIM gate, 200 ms/word + 1400 ms, `topicClicked()` unguarded, `DoShowDialogueList` skipped while `timerBool` | **CONFIRMED [V]**. Hand-decoded from raw bytes at body 0x3C52: `8e 08 00 00 00 00 02 29 00 56 00` (DefineFunction2, 0 params, codeSize 86) -> `Push reg1, c[0x5b]"timerBool", false; SetMember; ... c[0x75]"selectedEntry" ... c[0x56]"topicIndex" ... InitArray; c[0x8a]"TopicClicked"; ... c[0x65]"call"; CallMethod`. Second hand-decoded record (body 0x1619, sprite 32 frame 33): `_parent.menuState = DialogueMenu.TOPIC_LIST_SHOWN; this.List_mc.listAnimating=false; stop()`. Both match the listing byte for byte. |
| 6 | truthy `onSelectionClick` arg re-selects under the mouse; DSN passed 1.0; `UI.Invoke` passes `false` | **CONFIRMED [V]** (UI.psc:86-88; DSN Hooks.cpp re-fetched). Nit: on CHIM's SWF the first statement is `timerBool = true`, the mouse re-select is second. DSN also closes with `Invoke("_level0.DialogueMenu_mc.StartHideMenu")` on its "goodbye" phrase = prior art for X1. |
| 7 | Every click held until the DLL releases it; bypass sends no mod event | **CONFIRMED and STRENGTHENED [V, disassembly]** - see B1. |
| 8 | eMenuState constants, frame scripts 33/48, `onItemSelect` no-op in state 3, `bAllowProgress` | **CONFIRMED [V]** raw 333F-342F, 3CF6-3D67, frame scripts at body 5657 / 6868. |
| 9 | Hidden menu still takes input | **CONFIRMED [V]** for the code (`Mouse.addListener(this)` chim.txt:297; `onMouseDown` fires `onItemSelect({mouseClick:true})` on odd calls). That the engine keeps feeding mouse events to a movie whose clip is `_visible=false` is [I] (reasonable). Guard G2 has a flaw: see C3. |
| 10 | IACC + Smart Talk drive the same paths | **CONFIRMED [V]** DLL strings at the quoted offsets; ACC `Menus.cpp` and Smart Talk `Input.hpp` re-fetched and match; winning ini `LoreRim - MCM and INI Settings\...\AlternateConversationCamera.ini` :77 `bHideDialogueMenu=1`, :35 `bSwitchTarget=0` (also :14 `bForceFirstPerson=1`); SmartTalk.ini :77/:107/:116. Nuance: in the ACC source ALL eMenuState writes sit behind `if (!g_zoom) {...return}` - they only happen while the conversation zoom is active. All-DLL scan: only these three DLLs contain `DialogueMenu_mc`; none contains `PopulateDialogueList` (DSN is not installed). |
| 11 | SKSE UI semantics | **CONFIRMED [V]** (PapyrusUI.cpp re-fetched: `GetGFxValue<UInt32>` = `kType_Number ? (UInt32)GetNumber() : 0`, `view->SetVariable(target,&fx,1)`, `UIInvokeDelegate` + `QueueCommand`, `view->Invoke(target_.c_str(), NULL, value, args.size())`). **Material omission: see C1 (frame sync).** |
| 12 | `noInvisibleAdvance` absent | **CONFIRMED [V]** byte search of both decompressed SWFs = 0 hits; Autodesk page re-fetched. CommonLibSSE-NG `BSScaleformManager::LoadMovieEx` contains no `SetVariable` at all (only `_root.InitExtensions` invoke), so the engine does not switch it on either [V for the re-implementation, I for the real binary]. Still a probe item (P6). |
| 13 | `StartHideMenu` = clean close; `mc_Cursor` | **CONFIRMED [V]** (`PlaceObject2 depth 1 name 'mc_Cursor'` + an `onMouseMove` listener moving it). Nit: `Norden UI 21x9\Interface\cursormenu.swf` also exists (mod disabled), so "only loose provider" -> "only ENABLED loose provider". |
| 14 | No Papyrus-only topic selector; only CHIM's legacy call drives the Dialogue Menu from Papyrus; LogC | **PARTLY WRONG.** "No Papyrus topic SELECTOR exists" stands [V]. But the grep `UI.*("Dialogue Menu"` was too narrow (menu names held in variables): see B2/B3 - other Papyrus scripts in this list DO drive the Dialogue Menu movie. `LRG_Main.LogC` exists but is now at `LRG_Main.psc:222` (`Function LogC(string asCid, string asMsg, string asNpcName)`), not 183-207 (the file is being edited by the other team). |

Further names spot-checked against bytecode / sources, all correct: `__get__menuState/__set__menuState` + `addProperty("menuState",..)` (raw 3D78-3DCA), `StartProgressTimer`, `SetAllowProgress`, `iAllowProgressTimerID`, `iMouseDownExecutionCount`, `SetSelectedIndexByMouse`, `RestoreScrollPosition`, `CalculateMaxScrollPosition` (Centered override: count of filter matches - 1, min 0 -> R2 is right incl. its ">= 1 entry" caveat), `itemIndex`/`clipIndex`, `GameDelegate.call` = `params.unshift(methodName, uid)` + `ExternalInterface.call.apply`, `Form.psc:164/189/209`, `Game.psc:446/449`, `ObjectReference.psc:143`, `Utility.psc:38`, `MiscUtil.psc:69`, `AIAgentPapyrusFunctions.psc:1097-1100, 1170-1195`, `AIAgentAIMind.psc:1438-1443`, CHIM `meta.ini` (version 3.3.2.0; FOMOD step "CHIM Player Dialogue Menu" -> "Vanilla SE/AE Skyrim UI").

### B. New evidence that upgrades or changes the report

**B1. CHIM's native side, from disassembly of `AIAgent.dll` (build path string `D:\wt\chim-332-main-voice-hotfix\Plugin\...`) [V unless marked]:**
- The `PlayMenuTopic` sink (code rva 0x1db582) compares the mod-event name, then branches on a config byte (`cmp byte [0x1803db361],0`): zero -> builds the reason string "Player TTS for Traditional Dialogue is disabled" and releases; non-zero -> calls the TTS start (0x1800fd2c0), and releases with "...could not start" when it returns <= 0. The release lambda `QueuePlayerMenuTopicTimerOff` (rva 0xdb189) is the only code that references `_root.DialogueMenu_mc.startTopicClickedTimer`. This is exactly the legacy Papyrus logic -> section 6 route A / setting OFF is now verified at native level, not just "proof by necessity".
- CHIM patches two vtable slots of one menu class (install code rva 0x1fe484: slot +0x20 -> 0x18020af00, original kept at 0x1803dc048; slot +0x28 -> 0x18020b160, original at 0x1803dc028). In CommonLib's `IMenu` layout these are `ProcessMessage` (index 4) and `AdvanceMovie` (index 5). The `ProcessMessage` hook only looks at `UIMessage.type == 6` (Scaleform event; GFx event type 2/3 = mouse down/up, button 0) and `type == 7` (user event; strings `accept select click topic submit activate` at 0x30c918-0x30c940), and can swallow them (returns 0 without calling the original). The string `TopicClicked` at 0x30ee58 is referenced once (rva 0x20afac) inside that hook, where it is built into a local and immediately cleared (dead code). [I] the class is `DialogueMenu` (strings: "VR runtime detected; skipping flat Skyrim native screenshot/dialogue hooks").
  -> **CHIM does NOT hook the `TopicClicked` FxDelegate callback; its second mechanism is purely input-message level.** `UI.Invoke*` never creates a `UIMessage`, so neither route A nor route B can enter it. Open question 6 is answered as far as static analysis can: dormant for scripted clicks. (P8's AIAgent.log check stays as the in-game confirmation.)
- Live value per sibling report `p2-chim-interplay.md` section 7: `_player_tts_traditional_dialogue` = 0.

**B2. SWF injection from Papyrus is a SHIPPED, ENABLED pattern in this install - P12 is close to a formality [V]:**
`UI.InvokeStringA(menu, "_root.createEmptyMovieClip", [name, depth])` + `UI.InvokeString(menu, "_root.<name>.loadMovie", "<file>.swf")` inside `OnMenuOpen` is used by
`Requiem - Lite\Scripts\Source\RequiemLite_Config.psc:24-29` (modlist :179, Journal Menu, and it receives mod events back from the injected SWF: `RegisterForModEvent("RequiemLite_UpdateOptions", ...)`),
`Auto Name Enchantments\...\AutoNameEnchantments_Script.psc:18-23`, `Better Help Menu`, `Legendary Map`, `Spell Organizer - Show in UI`, `The Dragonborn's Bestiary - Show In UI`, `Player Name Randomizer - Show in UI`, `Skyrim Character Sheet - Show in UI` (all `+` in modlist.txt).
**One script injects into the Dialogue Menu itself:** `[Optional] Arachnaphobia Mod\Source\Scripts\SFE_SubtitlesScript.psc:29-36` (modlist :55, disabled, but shipped with `Interface\dialogmenu_inject.swf`): on every `OnMenuOpen` it does `UI.InvokeStringA("Dialogue Menu", "_root.createEmptyMovieClip", ["SFE_Sub","-8008"])`, `UI.InvokeString("Dialogue Menu", "_root.SFE_Sub.loadMovie", "dialogmenu_inject.swf")`, `Utility.Wait(0.1)`, `UI.InvokeInt("Dialogue Menu", "_root.SFE_Sub.Menu_mc.setSize", n)`.
Consequences: (a) the helper-SWF route (section 0 item 8) is technically low-risk; the open part is only the AS2 build tool = owner decision. (b) These scripts re-inject on EVERY open -> supports "new movie per open, hide/guard never persist" (H1 note, P11). (c) `Utility.Wait` works while the Dialogue Menu is open (the menu does not pause the game); `WaitMenuMode` remains the safe choice.

**B3. LoreRim's own dialogue fragments close the Dialogue Menu themselves and open another menu [V]:**
`[LoreRim] Respec\...\LoreRimRespec_DialogScript.psc:9,21` (a TIF fragment): `UI.InvokeString("HUD Menu", "_global.skse.CloseMenu", "Dialogue Menu")`, `Utility.Wait(0.3)`, then its own menu;
`[LoreRim] TM\...\Transmog_Script.psc:22`: `PO3_SKSEFunctions.HideMenu("Dialogue Menu")` (`PO3_SKSEFunctions.psc:1108`) then `UI.OpenCustomMenu("Transmog_movie")`.
Consequences: (a) two SWF-independent close calls exist as fallbacks for X1. (b) "menu closed right after my click and a different menu opened" is a SUCCESS pattern; the session must end cleanly and **H3 (hidden cursor) must be undone at once**, otherwise the player sits in a custom menu without a cursor. Add both dialogues to the test matrix.

**B4. Speech-check success is observable from Papyrus, and a LoreRim mod depends on real Dialogue Menu open/close [V]:**
`Beneficial Speech Checks - Experience\...\BeneficialSpeechChecks_Script.psc:26-45` (modlist :1305): `RegisterForMenu("Dialogue Menu")`; `OnMenuOpen` stores `Game.QueryStat("Persuasions")` / `Game.QueryStat("Intimidations")`, `OnMenuClose` compares and grants XP/buffs (`Game.psc:189 int Function QueryStat(string asStat)`). A headless REAL session keeps this mod working (it would silently break under any re-implemented dialogue). The glue can use the same counters (plus `"Bribes"`, name per CK wiki, not used by any script in the list) sampled before the click and after the response to tell the server "persuade/intimidate/bribe SUCCEEDED" without parsing anything. [I] the counters are incremented by the vanilla favor fragments, i.e. only when the success INFO ran - Requiem/LoreRim edits that bypass those fragments would not count; treat as a positive signal only.

### C. Corrections that change what gets built

**C1. `UI.Get*` / `UI.Set*` / `UI.IsMenuOpen` are frame-synced natives; only `Invoke*` are NoWait [V].** PapyrusUI.cpp `RegisterFuncs` sets `kFunctionFlag_NoWait` on InvokeBool/Int/Float/String(+A), InvokeForm, IsTextInputEnabled, Open/CloseCustomMenu - not on Get*/Set*/IsMenuOpen. Delayed natives sync with the frame rate (CK wiki "Non-delayed Native Function"; locally: `LoreRim - MCM and INI Settings\SKSE\Plugins\PapyrusTweaks.ini:62-63` "Speeds up native calls by desyncing them from framerate", `bSpeedUpNativeCalls = false`, and class `UI` is in `sScriptClassExclusions` :68 anyway). [I] about one call per frame per stack. So "synchronous on the Papyrus thread" in section 0 item 6 / section 4 should read "executed at the VM's frame-sync point, ~1 frame each". Budget: R3 for 12 topics x 3 fields = 36 frames (0.6 s at 60 fps, 1.2 s at 30); R4 is (1 Set + 3 Get) x n; P1's triple read cannot sample faster than 3 frames; every H/G call costs a frame before the menu is hidden. "R4 ... fine for <= 20 topics" is too optimistic (about 1.3 s at 60 fps). Build rules: read `text` + `topicIndex` only (skip `topicIsNew` unless the server uses it), start reading as soon as the list is populated (during the greeting), never re-read an unchanged list (compare count + first/last text), apply hide before anything else. This is also the strongest argument for the injected helper SWF (one mod event per list, B2). Upside: frame-synced Get/Set are serialised with the movie's Advance, so they are race-free against the SWF.

**C2. `SetSelectedTopic` silently selects entry 0 when the topicIndex is not in the list [V chim.txt:16548-16560: `iSelectedIndex = 0; iScrollPosition = 0;` then the match loop].** A stale index (list re-populated between read and click: Say Once, service menu closed, quest stage changed) would click the FIRST topic = wrong branch, silently. Build rule: verify before clicking. Tightest sequence given C1 [I]: `UI.SetFloat(L.iSelectedIndex, pos)` (immediate) -> `UI.GetInt(L.selectedEntry.topicIndex)` and `UI.GetString(L.selectedEntry.text)` must equal what the LLM chose (immediate, proven getter pattern) -> `UI.SetInt(D.eMenuState, 2)` (immediate; the very path IACC writes natively) -> `UI.Invoke(D.topicClicked)` (the only queued call). `topicClicked()` reads `selectedEntry` only, so `iScrollPosition` need not match on route B; for route A `onSelectionClick` recentres on `selectedIndex` itself (chim.txt L_3777). If C1 (`SetSelectedTopic`) is kept, wait one `WaitMenuMode` tick and do the same verification read before C3.

**C3. Guard G2 does not hold if it is applied while the list is still animating [V].** Natural state 3 ends with the `TopicListHolder` frame script (frames 33/48) executing `_parent.menuState = TOPIC_LIST_SHOWN`, which overwrites a park at 3 roughly 0.33-0.47 s later (10 / 14 frames at 30 fps). Section 8 enters the branch on `s == 1 or s == 3` and parks once. Build rule: re-assert G2 on EVERY poll that observes state 1 (not only when the list changed), and apply G2 BEFORE an R4 read loop (R4 moves `iSelectedIndex` for many frames; a stray click in that window would click whatever entry the loop is standing on). TAB / engine `Cancel` are not covered by any guard (`handleInput` TAB -> `onCancelPress` -> `StartHideMenu` outside state 0) - that is acceptable as the player's "walk away" key, but document it.

**C4. Do not auto-retry a click through the other route on a timeout heuristic [I, design risk].** Section 6's rule ("state still 2, subtitle blank, list unchanged after 1 s -> function missing -> fall back to `onSelectionClick(false)`") cannot tell "function did not exist" from "INFO with an empty response whose fragment already ran and returned the same list". A second click would run the fragment twice (double bribe payment, double SetStage). Use a deterministic fingerprint once per menu open instead: `UI.GetFloat(L.iMaxItemsShown)` = 8 -> CHIM's SWF (route B), 13 -> Norden's (route A) - these are the only two candidates in this install [V entry-clip counts]; or observe `PlayMenuTopic` once. On an unverifiable click: un-hide (U1), never re-click. With the live setting `_player_tts_traditional_dialogue = 0`, route A is also safe on CHIM's SWF (B1), so route A is a valid universal default and route B only becomes necessary when that setting is ON.

**C5. Emergency hotkey wiring [V code, I behaviour].** The existing `LRG_Main.OnKeyDown` starts with `if Utility.IsInMenuMode() || UI.IsTextInputEnabled() -> return` and `OpenVanillaDialogue()` does `npc.Activate(player)`. OStim/OBody in this list write `Utility.IsInMenuMode() || ... || UI.IsMenuOpen("Dialogue Menu")` (`OUtils.psc:353`), which implies `IsInMenuMode()` is false in dialogue, but it is not proven. Add to the probe: log `Utility.IsInMenuMode()` once inside a session. During a hidden session the emergency key must run U1 (un-hide), not `Activate`.

### D. Smaller additions
- Smart Talk (source `Features/Quest.hpp` [V via fetch]) replaces `TopicList.SetEntryText` at menu open with a native handler (keeps the original as `SetEntryTextLegacy`) and colours quest-related entries via `textField.textColor` (`cQuestEntryColor = 0xFFD966`, SmartTalk.ini:65; `iDialogueSortMode = 0` :58 = game order, `bSkipOnInteraction = 0` :104). It adds NO property to the entry objects, so `EntriesA` reads are unaffected; a "quest-related" hint is only available for the visible clips: `UI.GetFloat(M, L+".Entry"+k+".textField.textColor") == 16767334` [I, optional].
- Alternative FULL hide if P6 shows stalled timelines under `_visible=false`: `UI.SetFloat(M, D+"._alpha", 0)` (clip stays "visible" for Advance; mouse hit-testing stays active, so keep G2) [I]. `Ultimate Immersion Toggle` in this list hides whole movies with `UI.SetInt(<menu>, "_root._alpha", 0)` [V `Luca_HideUIConfigMenu.psc:43-66`].
- `(UInt32)` of a negative double is what makes `GetInt` unsafe; the advice "GetFloat/SetFloat for anything that can be -1" is right.

### E. Confidence
Bytecode / file / ini / DLL-string facts: high (re-derived independently). SKSE semantics: high (source), frame-cost per call: medium-high. Everything about live behaviour (array-index paths, invisible advance, engine accepting a scripted `TopicClicked`, IACC 1.2.0 fork logic, subtitles when hidden) remains unproven until the probe runs - the report says so itself and its probe covers them; add the C1-C5 items to it.


---

## Verification (adversarial pass) - second independent run

A first adversarial section already exists above (sections A-E). This run re-derived everything again from scratch and ALSO audited that first
section, because its B/C items are now part of what the builder will read. Method: a second, separately written SWF tag walker + AVM1
disassembler (temp script, not `p2_swf_strings.py`) over CHIM's and Norden's `dialoguemenu.swf` and Norden's `cursormenu.swf`; two records
decoded by hand from the raw bytes; `sha256sum`; full-depth `find` for `dialoguemenu*` / `dialogmenu*` / `cursormenu*`; string scan + RIP-relative
xref scan of `AIAgent.dll`, `objdump -d` of one function (WSL, read-only); string scan of `SmartTalk.dll` / `AlternateConversationCamera.dll`;
re-fetch of PapyrusUI.cpp (RegisterFuncs printed verbatim), Hooks_UI.cpp, ACC `Menus.cpp`, Smart Talk `Input.hpp`, DSN `Hooks.cpp`, the Autodesk
advance page; re-open of every cited `.psc` / `.ini` line. **[V]** = re-opened by me, **[I]** = inference. Nothing was run in game.

### F. Verdict on the 14 load-bearing claims: 13 confirmed, 1 partly wrong (same one as the first pass)

| # | Verdict (this run) |
|---|---|
| 1 | **CONFIRMED [V]** 3 loose `dialoguemenu.swf` only (+ Norden's `deardiary_dm\dialoguemenu\*_BG/_up/_down.swf`, + disabled Arachnaphobia `dialogmenu_inject.swf`); none in `Stock Game\Data` / `overwrite`; sha256 f078b69c... / 109871a6... x2; modlist.txt :8 / :148 / :30 / :3710 / :3843. |
| 2 | **CONFIRMED [V]** my parser: root -> `DialogueMenu_mc`(35) -> `ExitButton`(24) `TopicListHolder`(32) `SubtitleText`(33) `SpeakerName`(34); 32 -> `PanelCopy_mc ListPanel TextCopy_mc List_mc ScrollIndicators`; 20 -> `border`, `Entry0..7` / `Entry0..12`; sprite 35 = 1 frame, 32 = 48 frames, 24 = 40 frames; exports `TopicList`(20), `DialogueMenuObj`(35); constructor `this.TopicList = this.TopicListHolder.List_mc`. |
| 3 | **CONFIRMED [V]** `PopulateDialogueLists` builds `{text, topicIsNew, topicIndex}` from `arguments` and pushes to `TopicList.entryList`; hand-decoded `__get__selectedEntry` (bytes `8e 08 00 00 00 00 02 29 00 12 00 / 96 04 00 04 01 08 04 / 4e / 96 04 00 04 01 08 0f / 4e 4e 3e` = `return this.EntriesA[this.iSelectedIndex]`, pool[4]=`EntriesA`, pool[15]=`iSelectedIndex`). |
| 4 | **CONFIRMED [V]** `SetSelectedTopic` compares with loose `Equals2` (so an `InvokeInt` Number matches); the gamepad / `bRecenterSelection` branch of `UpdateList` writes `iSelectedIndex` and `iHighlightedIndex`. First pass C2 (silent fall-back to entry 0) is real. |
| 5 | **CONFIRMED [V]** hand-decoded head of `DoShowDialogueList` (file body 0x3179: `8e 25 00 .. d1 00 / 96 02 00 04 01 / 96 02 00 08 5b / 4e / 96 02 00 05 00 / 49 12 / 9d 02 00 ba 00`) = `if (!(this.timerBool == false)) jump +0xBA` = exactly the end of the 209-byte body. Timer = `round(words*60/300*1000)+1400`. `topicClicked()` has no guard. |
| 6 | **CONFIRMED [V]** UI.psc:86-88; DSN `Hooks.cpp` re-fetched (`"%d", 1.0`). |
| 7 | **CONFIRMED [V]** strings at the quoted offsets. New: the literal `off` (file 0x2ed674) has exactly one code xref (rva 0xdb163), 0x26 bytes before the only xref of `_root.DialogueMenu_mc.startTopicClickedTimer` (rva 0xdb189) -> the native release ALWAYS passes `"off"` (immediate `TopicClicked`, never the word-count timer). `PlayMenuTopic` xref rva 0x1db582. `objdump` of 0x18020af8d-0x18020afd7 reproduces the first pass: the `TopicClicked` string is built into a stack `std::string` and cleared at once (dead code); the hook then calls the saved original (`[0x1803dc048]`) or returns 0. |
| 8 | **CONFIRMED [V]** constants, frame scripts 33/48, `onItemSelect`, 750 ms. **Omission:** frame 22 of `TopicListHolder` (end of the `topicClicked` animation, frames 12-22) ALSO runs `_parent.menuState = DialogueMenu.TOPIC_CLICKED` - see G4. |
| 9 | **CONFIRMED [V]** code paths. Extra detail: `SetSelectedIndexByMouse` uses `Mouse.getTopMostEntity()` and entry clips use `onRollOver`/`onPress`; under H1/H2 nothing is under the cursor, so a stray mouse click really does click the CURRENT `iSelectedIndex`. `handleInput` is gated by `bFadedIn`, forwards UP/DOWN only in state 1, TAB -> `onCancelPress`. Guard G1 has a race the report does not mention: see G1 below. |
| 10 | **CONFIRMED [V]** DLL strings at the quoted offsets; ACC source: all writes are behind `if (!g_zoom) {..return}`; the write condition is `state==0 OR (mtm->unk70 && !mtm->unkB9)`; IACC re-applies the whole `DisplayInfo` it just read, so H2's `_x=5000` is read back and rewritten unchanged (no fight) [I]. Precision: `SmartTalk.ini` exists ONLY in `mods\Smart Talk (Dialogue Menu Enhancer)\SKSE\Plugins\` (not in `LoreRim - MCM and INI Settings`); lines 77/107/116 are right. Smart Talk also logs `DialogueMenu hooked at virtual table index 0x4` - the same `ProcessMessage` slot CHIM patches (two hooks chained on one slot; both input-level, irrelevant for `UI.Invoke*`). |
| 11 | **CONFIRMED [V]** at native level. The wording "synchronous ... on the Papyrus thread" is misleading at Papyrus level: `RegisterFuncs` (printed verbatim this run) sets `kFunctionFlag_NoWait` ONLY on `InvokeBool/Int/Float/String`, the four `Invoke*A`, `InvokeForm`, `IsTextInputEnabled`, `OpenCustomMenu`, `CloseCustomMenu`. `IsMenuOpen`, `Get*`, `Set*` are ordinary delayed natives = first pass C1 stands. `PapyrusTweaks.ini` (only copy: `LoreRim - MCM and INI Settings`, :63 `bSpeedUpNativeCalls = false`, :68 exclusions start with `UI`) [V]. |
| 12 | **CONFIRMED [V]** 0 hits for `noInvisibleAdvance` in both decompressed SWFs; the root frame script sets only `_global.gfxExtensions = true`; Autodesk page re-fetched (the default "invisible clips are advanced" is implied there, not stated = [I]; probe P6 stays mandatory). |
| 13 | **CONFIRMED [V]** `StartHideMenu` -> `CloseMenu`; `mc_Cursor` = the single named placement in `Norden UI 16x9\Interface\cursormenu.swf` (169-frame sprite + `onMouseMove` listener). Nit as before: a second copy exists in the disabled `Norden UI 21x9`. DSN uses `StartHideMenu` for its "goodbye" phrase = native prior art for X1. |
| 14 | **PARTLY WRONG [V]** "no Papyrus topic selector" stands. But a grep for `UI.Invoke/Set/Get`, `HideMenu`, `CloseMenu` with `"Dialogue Menu"` also finds `[LoreRim] TM\...\Transmog_Script.psc:22` and `[LoreRim] Respec\...\LoreRimRespec_DialogScript.psc:9` (both `+`, modlist :305 / :301); `LRG_Main.LogC` is at `LRG_Main.psc:222`, `OnKeyDown` :183-184 (the file is being edited by the other team). |

First-pass items re-checked and **confirmed [V]**: B1 (xref addresses identical, dead `TopicClicked` string), B2 (`RequiemLite_Config.psc:24-29`, `SFE_SubtitlesScript.psc:28-36`, plus 8 more `*_inject.swf` loaders found by grep), B3, B4 (`BeneficialSpeechChecks_Script.psc:26-45`, `Game.psc:189`), C1, C2, C3 (frame scripts), C5 (`LRG_Main.psc:183-204`, `:602`), D (`Luca_HideUIConfigMenu.psc:26-30`, `SmartTalk.ini:58/65/104`).

Further names spot-checked this run, all correct: `StartProgressTimer` / `SetAllowProgress` / `iAllowProgressTimerID` (`clearInterval` + `setInterval(this,"SetAllowProgress",DialogueMenu.ALLOW_PROGRESS_DELAY)`), `__get__menuState` / `__set__menuState` + `addProperty("menuState",...)`, `ASSetPropFlags(prototype,null,1)` (hides from enumeration only), `bRecenterSelection`, `iNumTopHalfEntries`, `getTopMostEntity`, `itemIndex`, Norden-only `ParseConfig` / `HIDE_TOPICS` / `TOPICS_FONT_SIZE` / `topicsFadeIn` / `topicsFadeOut` / `numField` / `QuestMarker`, the raw strings `deardiary_dm/dialoguemenu/dialoguemenu_BG.swf` + `loadMovie` (they sit in clip actions, so a tag walker that skips clip actions does not list them - the report is right), `AIAgentFunctions.psc:52 int function get_conf_i(String code)`, `:138 startPlayerMenuDialogueTTS`, `Utility.psc:17/28/38/65`, `Game.psc:189/446/449`, `Form.psc:164/189/193/196/209`, `ObjectReference.psc:143`, `UI.psc:47/116`, `PO3_SKSEFunctions.psc:1108/1114`, CHIM `meta.ini` version 3.3.2.0.

### G. New findings that change what gets built

**G1. Guard G1 is racy as written; one extra call fixes it [V bytecode, I behaviour].** `StartProgressTimer()` = `bAllowProgress=false; clearInterval(iAllowProgressTimerID); iAllowProgressTimerID = setInterval(this,"SetAllowProgress", DialogueMenu.ALLOW_PROGRESS_DELAY)`. The delay is read when the timer is ARMED. The greeting's `NotifyVoiceReady` normally arms a 750 ms timer before Papyrus has even seen `OnMenuOpen`; G1 then sets the static to 100000000 and `bAllowProgress=false`, and up to 750 ms later the already-armed timer sets `bAllowProgress=true` again. Fix: G1 = `UI.SetInt(M, "_global.DialogueMenu.ALLOW_PROGRESS_DELAY", 100000000)` then `UI.Invoke(M, D+".StartProgressTimer")` (clears the pending interval, sets false, re-arms with the huge delay; the `false` argument that `UI.Invoke` passes is ignored). Every later `NotifyVoiceReady` re-arms with the huge value by itself. Add to P7: read `bAllowProgress` again 1 s after the guard.

**G2. A click must be preceded by a VERIFIED state 2, and state 3 must not count as "ready" while a click is pending [V bytecode, I design].** If the state is still the glue's own park value 3 when `TopicClicked` is sent, the engine's following `ShowDialogueList` is dropped (`DoShowDialogueList` acts only in state 2, or in 0 with entries), the state stays 3 during the NPC's answer, and section 8's poll (`s == 1 or s == 3` -> read -> send) would offer the OLD list to the LLM while the NPC is still talking -> a second click on a stale list (fragment runs twice). Rule: `UI.SetInt(M, D+".eMenuState", 2)` (frame-synced; the very path IACC writes natively), read it back with `GetInt`, and only then queue the click; read-back != 2 -> no click, un-hide. After a click accept "ready" only on a CHANGED list or on a natural 3 -> 1 sequence.

**G3. Reconciling the report's C1-C3 order with the first pass's C2 sequence.** The first pass's "SetFloat iSelectedIndex -> GetInt/GetString verify -> SetInt state 2 -> Invoke topicClicked" spans at least 4 frames. It is only safe while the state is parked at 3: `handleInput` forwards UP/DOWN only in state 1 and `onItemSelect` is inert in 3, so nothing can move `iSelectedIndex` in between (mouse roll-over is dead under H1/H2, see claim 9). So: park (G2 of section 7.3) FIRST, then select + verify, set state 2 LAST, and queue the click immediately after the read-back. If route A is used with a gamepad plugged in, also `SetFloat iScrollPosition` to the same position (route A calls `UpdateList`, which on `iPlatform != 0` re-derives `iSelectedIndex` from the centred entry).

**G4. Frame 22 of `TopicListHolder` sets `_parent.menuState = TOPIC_CLICKED` [V, `DoAction` of sprite 32 frame 22, 108 bytes].** Only route A plays that animation (`gotoAndPlay("topicClicked")`, frames 12-22 = 0.33 s at 30 fps). Any state the glue writes within 0.33 s after a route-A click (a park at 3, or U1's `__set__menuState(1)`) is overwritten with 2. Section 2.3 should list three timeline writers: f22 -> 2, f33 -> 1, f48 -> 1. Route B plays no animation, so nothing rewrites the state behind the glue's back - one more reason to prefer it when CHIM's SWF is active.

**G5. Route choice can be fully deterministic from Papyrus [V names].** `AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue")` (`AIAgentFunctions.psc:52`; CHIM itself calls it at `AIAgentPapyrusFunctions.psc:1173`) gives the live setting; `UI.GetFloat(M, L+".iMaxItemsShown")` = 8 / 13 tells CHIM's SWF from Norden's. Table: setting <= 0 -> route A works on any SWF (on CHIM's SWF the DLL releases with `"off"` at once, claim 7); setting > 0 AND 8 entries -> route B; setting > 0 AND 13 -> route A (that SWF has no gate). Never retry through the other route (first pass C4 - agreed). Extra reason to avoid route A while the setting is ON: Smart Talk (`bDBVOIntegration=1`, ini:107) knows `timerBool` / `timer` / `topicClicked`; a player skip key during the hold can make it call `topicClicked()`, and CHIM's later `"off"` release would send a second `TopicClicked` [I].

**G6. Do not let click verification or readiness depend on `SubtitleText` alone [V setting, I engine behaviour].** `profiles\Ultra\skyrimprefs.ini:128-129` `[Interface] bDialogueSubtitles=1`, `bGeneralSubtitles=1` today, but it is a player-facing toggle (Settings > Display). [I] with dialogue subtitles off the engine never calls `ShowDialogueText`, so "SubtitleText.text becomes non-blank" (7.2) and "blank for >= 0.5 s" (7.3) would both misfire. Read `Utility.GetINIBool("bDialogueSubtitles:Interface")` (`Utility.psc:65`) at session start; if false, use only list-change / menu-close / state sequence. INFOs with an empty response text have the same effect even with subtitles on.

**G7. Native production proof for open question 3.** DSN (`Hook_Loop`, re-fetched) drives `SetSelectedTopic` / `doSetSelectedIndex` / `UpdateList` / `onSelectionClick` purely through `GFxMovieView::Invoke` from its own loop, with no input event and no state check before the click, and closes with `StartHideMenu`. SKSE's `UIInvokeDelegate::Run` ends in the same `view->Invoke(...)`. So "the engine accepts a `TopicClicked` that no human click preceded" is as good as settled for the vanilla-SE SWF family; what P8 still has to prove is only the Papyrus queue + CHIM's `topicClicked()` variant.

**G8. `Actor.Activate(player)` as a dialogue opener is a shipped pattern in this list [V].** `The Welkynar Knight - Quest\Source\Scripts\ksws04MainQuestScript.psc:256-261` (modlist :876): `While GetStage() == 500` / `If !UI.IsMenuOpen("Dialogue Menu")` / `ksws04ThalmorActor.Activate(playerRef)` / `Utility.Wait(1)`. Supports P0/E2, and shows the retry-until-open loop the glue should copy (an activation can be refused while the NPC is busy).

**G9. Everything keyed on `UI.IsMenuOpen("Dialogue Menu")` sees the WHOLE headless conversation as "in dialogue" [V grep, 13 scripts].** Relevant ones: CHIM `AIAgentAIMind.psc:1438` (waits up to 4 s for the menu to close before continuing - hand to the CHIM-interplay owner), `OUtils.psc` (OStim hotkeys blocked), `OBodyNGScript.psc`, `Luca_HideUIConfigMenu.psc:7` (HUD toggle), `Switch Camera During Dialogue\CameraSwitchScript.psc:15`, `[LoreRim] Thieving XP\ThievingXP_Script.psc:38`, the glue's own `LRG_Main.psc:602`, and (positive) Beneficial Speech Checks. None breaks the design; the builder should know that a multi-minute hidden session is a multi-minute "Dialogue Menu open" for all of them.

### H. Still unproven (unchanged, all covered by the probe)
Array-index path components (`EntriesA.3.text`): two more web searches, still no production example; [I] GFx resolves path components with `GetMember`, and AS2 arrays answer numeric member names, so it should work - P3 decides, R4 is the fallback. Invisible-advance (P6), IACC 1.2.0 fork behaviour (P1), `_global` static lifetime per open (P11), `Utility.IsInMenuMode()` inside dialogue (first pass C5).

### I. Confidence
File / bytecode / ini / DLL-string / SKSE-source facts: high (two independent parsers and two hand-decoded records agree with the report's listing). G1-G5 are read straight from bytecode; their in-game effect is [I] until P7-P9 run. Add to the probe: G1 read-back of `bAllowProgress`, G2 read-back of state 2 before the click, `get_conf_i` + `iMaxItemsShown` logging at P2, `GetINIBool("bDialogueSubtitles:Interface")` at P0.
