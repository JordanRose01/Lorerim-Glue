# pt9 — the "settings that are not ours" moved into the glue mod

**Lane:** INI overrides inside `LoreRim Glue`. The owner should never have to open another mod's
folder, and PLAYTEST8_NOTES.md section 2 asked him to edit four of them by hand. Three are now files
in our own mod; the fourth turned out to need nothing. Nothing in any other mod's folder was touched,
no profile file was edited, and MO2 was left running.

Status: **installed** (files copied + hash-verified — MO2 was open, see §6). Not yet exercised in game.

---

## 1. What changed, in one table

| Setting | Was (the value the game actually loaded) | Now | Where our copy lives |
|---|---|---|---|
| Smart Talk `bSkipImmediateOnInput` | 1 | **0** | `LoreRim Glue\SKSE\Plugins\SmartTalk.ini` |
| Smart Talk `bHoldToSkip` | 1 | **0** | same file |
| Smart Talk `bDBVOIntegration` | 1 | **0** | same file |
| Smart Talk `iPapyrusHandle` | 3 | **3 (untouched, deliberately)** | — |
| Smart Talk `bSkipOnInteraction` | 0 | 0 (already correct) | — |
| IACC `bForceFirstPerson` | **1** | **0** | `LoreRim Glue\SKSE\Plugins\AlternateConversationCamera.ini` |
| IACC `bHideDialogueMenu` | 1 | **0** | same file |
| Fuz Ro D'oh `WordsPerSecondSilence` | 2 | **3** | `LoreRim Glue\SKSE\Plugins\Fuz Ro D'oh.ini` |
| Helmet Toggle 2 `iEnableDialogue` | 0 | **0 — no file shipped** | see §5 |

Each file is a **complete copy** of the file that was winning before, with only those lines changed
and a `;` header naming the source mod, the source file's size and modlist line, the changed keys and
how to undo it.

---

## 2. The one finding that mattered: the IACC file in force was not IACC's

`AlternateConversationCamera.ini` exists twice in the load order:

| provider | modlist.txt line | size | `bForceFirstPerson` | `bHideDialogueMenu` |
|---|---|---|---|---|
| `LoreRim - MCM and INI Settings` | **145** | 3502 B | **1** | 1 |
| `Improved Alternate Conversation Camera` (the mod itself) | 3712 | 3509 B | **0** | 1 |

MO2's `modlist.txt` is highest-priority-first, so **line 145 wins**: the file the game loads is
LoreRim's, and LoreRim turns `bForceFirstPerson` **on**. Reading the mod author's own copy — the
obvious thing to do — would have reported "`bForceFirstPerson` is already 0, nothing to do", and the
first-person snap in every hidden conversation would have stayed. Our override is therefore a copy of
**LoreRim's** file, not the author's, so the other 30-odd values LoreRim deliberately changed
(`fCameraSpeed=750`, `bLetterBox=0`, `u3rdZoom=100`, FOV 90/90, `bSwitchTarget=0`, …) are carried
over unchanged.

The same check for the other two:

* `SmartTalk.ini` — **one** copy in the whole list (`Smart Talk (Dialogue Menu Enhancer)`, line 3845).
* `Fuz Ro D'oh.ini` — the Fuz Ro D-oh mod itself (line 3682) ships **no ini at all**; the only copy is
  `LoreRim - MCM and INI Settings` (line 145). Ours is a copy of that.

---

## 3. How each plugin finds its ini, and who else can write it

All three are `Data\SKSE\Plugins\<name>.ini`, read with ordinary file APIs against the game's Data
folder, which is exactly what MO2's USVFS virtualises. No BSA can serve a file at this path. So loose
priority is the whole story, and a higher-priority copy wins outright.

| mod | how the ini is located | can anything write it at runtime? |
|---|---|---|
| Smart Talk | DLL string `Data/SKSE/Plugins/SmartTalk.ini` (also `SmartTalk_CustomUI.ini`, `SmartTalk_QuestCache.json`) — plain relative path, VFS-resolved | The DLL exposes Papyrus natives `SetIniSettingsValueBool/Int/Float/String` and a `SettingsManager::SetValue` that saves the file. **Smart Talk has no MCM anywhere in this load order** and nothing calls those natives, so in practice nothing writes it. |
| IACC | DLL holds the UTF-16 string `\AlternateConversationCamera.ini` and imports `GetPrivateProfileIntW` / `GetPrivateProfileStringW` / **`WritePrivateProfileStringW`** | **Yes.** `IACCMCM.pex` is a plain SkyUI MCM whose every getter/setter is a native in `IACC.pex` → the DLL (`GetHideDialogueMenu` / `SetHideDialogueMenu`, …). Changing a toggle in IACC's MCM calls the native, which writes the ini. USVFS redirects a write to an existing virtual file into the **winning** mod folder, so from now on that write lands in *our* file. Consequence, stated in the file's own header and in OVERRIDES.md: the owner can still change IACC in its MCM and it will stick, but our shipped copy can drift from what we wrote. |
| Fuz Ro D'oh | DLL string `Data\SKSE\Plugins\Fuz Ro D'oh.ini`, shadeMe's `SME::INI::INIManager`, imports `GetPrivateProfileStringA` / `WritePrivateProfileStringA` / **`WritePrivateProfileSectionA`** | Possibly, on its own initiative (INIManager re-writes missing settings). `WritePrivateProfileSectionA` replaces a whole section, so our header comment is placed **above** `[General]`, where a section rewrite cannot reach it. |

**Which wins, MCM or ini?**

* **Smart Talk:** no MCM. The ini is the only control. ✅ our file is authoritative.
* **IACC:** the MCM only ever *reads* through the DLL (`Get*`), it has no saved copy of its own — the
  values live in the DLL, loaded from the ini at startup. So the ini is authoritative at every game
  load, and the MCM shows whatever the ini said. It can overwrite our file later, but it cannot
  silently restore a stale value at load.
* **Fuz Ro D'oh:** no MCM. The ini is the only control.
* **Helmet Toggle 2:** MCM Helper — the *opposite* case, and the reason it gets no file (§5).

---

## 4. Why these three values

* **`bSkipImmediateOnInput` / `bHoldToSkip` = 0** is a *machine-checked precondition*, not taste.
  `LRG_DlgUI.SmartTalkOffender()` reads `Data/SKSE/Plugins/SmartTalk.ini` with
  `MiscUtil.ReadFromFile` and returns the name of the first of `bSkipImmediateOnInput`,
  `bHoldToSkip`, `bSkipOnInteraction` that is above 0; `SmartTalkSafe()` is that string being empty,
  and `bMenuless` may not leave dry run while it is false (design D-21). With these two now 0 and
  `bSkipOnInteraction` already 0, **`SmartTalkSafe()` should return true for the first time** — the
  glue's Calibration page should stop saying `blocked`. That is the single most checkable result of
  this lane.
  One parser detail that had to be respected: `LRG_DlgUI.IniInt()` only counts a key that sits at the
  **start of a line** (it checks the preceding character is `\n` or CR), so the key names inside our
  header comment are inert. Every header line starts with `;` — verified.
* **`bDBVOIntegration` = 0** removes Smart Talk's second skip path (the one for player lines under
  DBVO). Same reasoning, lower stakes.
* **`iPapyrusHandle` left at 3** — this is the guard that stops a dialogue being skipped before its
  Papyrus fragment ran. Touching it is what makes quest lines repeat.
* **`bForceFirstPerson` / `bHideDialogueMenu` = 0** is decision **D2**. The alternative — letting the
  glue call `IACC.SetDisableInFirstPerson/ThirdPerson` around each conversation — writes this ini on
  every call and, if flipped *inside* a session, strands IACC with the camera never restored (D-11).
  So the glue writes no IACC setting at all: `bIaccToggle = 0` in our own `MCM\Config\LoreRimGlue\
  settings.ini`, confirmed still 0.
  **Side effect worth knowing:** LoreRim's file has `bForceThirdPerson=0`, so with `bForceFirstPerson`
  now 0 as well, IACC forces **no** point of view — a conversation simply stays in whichever view you
  were already in. That is the least invasive reading of "stop snapping me into first person"; if the
  owner would rather always be pulled into third person for conversations, the one-line change is
  `bForceThirdPerson=1` in our copy. `bSwitchTarget` stays 0 (design C14: with it on, IACC rewrites
  the menu state in both directions and the glue's readiness gate is no longer trustworthy).
* **`WordsPerSecondSilence` 2 → 3** — this number divides an unvoiced line's word count into seconds
  of forced silence. At 2 a long unvoiced line holds the player up to ten seconds; 3 makes every such
  line a third shorter. Every AI line that has no audio yet goes through it.

---

## 5. Helmet Toggle 2 — decided NOT to ship a file

The note asked for `HT_EnableDialogue` off, "but only once menuless is really on". The evidence says
there is nothing to ship:

1. The MCM control is `iEnableDialogue:Main`, and it is **already 0** in both places on disk —
   `Helmet Toggle 2\MCM\Config\Helmet Toggle 2\config.json` (`"defaultValue": 0`) and
   `…\MCM\Config\Helmet Toggle 2\settings.ini` (`iEnableDialogue=0`).
2. The modlist's own preset, `LoreRim - MCM and INI Settings\MCM\Settings\Helmet Toggle 2.ini`,
   carries 14 keys and `iEnableDialogue` is not among them — the default stands.
3. `HT_MCM.psc`'s `OnConfigInit()` literally calls `HT_EnableDialogue.SetValue(0)`, commented
   "Reset here because it only registers on game load anyway."
4. Most importantly, **an ini is the wrong lever here.** This is an MCM Helper setting: the live value
   is the Papyrus global `HT_EnableDialogue`, which lives in the **save**. A file we place can only
   influence what is read at initialisation, so on an existing save the MCM is the only reliable
   switch. Shipping a copy of the modlist author's saved-settings file would additionally have frozen
   his other 13 Helmet Toggle keys inside our mod for no gain.

**If it ever does misbehave** (helmet popping off and back on at every conversation turn once
menuless is live), the fix is MCM → Helmet Toggle 2 → the Dialogue toggle → off. Not a file.

---

## 6. Install — and why it was the fallback route

`tools\install_mo2.ps1` was run first, as the rule requires. It refused:

```
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

Skyrim was **not** running (checked separately: 0 `SkyrimSE` processes), and the refusal was the MO2
check only — so the fallback applies: our own changed files were copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue\` at the same relative paths and hash-verified.

| file | bytes | SHA-256 (source == installed) |
|---|---|---|
| `SKSE\Plugins\SmartTalk.ini` | 6881 | `0FE637CE876C5155EE1FE2602129DC85ED18D925D6D914B768EEF8FB22DE3B16` |
| `SKSE\Plugins\AlternateConversationCamera.ini` | 5511 | `ADF69E038608925A7128AADF3C7F816F89F622C6C0ABE5AEC9D01192D58F3EFC` |
| `SKSE\Plugins\Fuz Ro D'oh.ini` | 1192 | `9BC2EF3336E0F6B057A7AF66F0F228EC253BE4C086852656EA7B6644CFA5DBF2` |
| `OVERRIDES.md` | 20778 | `4B9D36082F5EC8DA25016EAE61B31B6B0F36913A8AF17F857A69A2802E5BF12C` |

No profile file was written; `meta.ini`'s version label was not refreshed, so MO2's left pane still
shows the old version number for this mod — cosmetic, same as every playtest since pt8. All three
files land under the existing `SKSE\Plugins` folder, so `install_mo2.ps1`'s `-NoNudeBody` logic needed
no change (that switch excludes `meshes\` only, and these must apply either way).

---

## 7. Verification

* **Content.** Each installed file was diffed line-by-line against its source: SmartTalk 3 body lines
  differ (92, 95, 107), IACC 2 (14, 77), Fuz 1 (2) — and no others. Line endings CRLF, ASCII, no BOM,
  matching the sources. Every header line begins with `;`.
* **Priority.** Every enabled mod in `profiles\Ultra\modlist.txt` was probed for each of the three
  relative paths:

  | path | providers, highest priority first |
  |---|---|
  | `SKSE\Plugins\SmartTalk.ini` | **line 3 LoreRim Glue**, line 3845 Smart Talk |
  | `SKSE\Plugins\AlternateConversationCamera.ini` | **line 3 LoreRim Glue**, line 145 LoreRim - MCM and INI Settings, line 3712 Improved Alternate Conversation Camera |
  | `SKSE\Plugins\Fuz Ro D'oh.ini` | **line 3 LoreRim Glue**, line 145 LoreRim - MCM and INI Settings |

  The `overwrite` folder provides none of the three (and has no `MCM` tree at all). The only enabled
  mod above us, `The New Gentleman` (line 2), ships no `.ini` except MO2's own `meta.ini`, which is
  metadata and never reaches the VFS. So **LoreRim Glue is the winner for all three paths.**
* **Not verified (cannot be, from disk):** that the game actually reads the new values. The cheap
  in-game check is the glue's Calibration status line no longer saying `blocked - Smart Talk
  bSkipImmediateOnInput must be 0`.

---

## 8. What the owner will notice, and how to undo any of it

**Notice:**

* **Conversation camera changes.** This is the visible one. Conversations no longer yank the camera
  into first person and lock it on the NPC's face; they stay in whatever view you are already in, and
  the topic list no longer blinks out while she talks. If you preferred the old cinematic feel, say
  so — `bForceThirdPerson=1` in our copy gives a forced over-the-shoulder shot without the
  first-person snap.
* **Unvoiced lines are shorter.** Roughly a third less dead time on any line with no audio.
* **The dialogue menu stops skipping itself.** A stray click or keypress during a line no longer jumps
  it. If you were used to click-to-skip, that habit is gone on purpose — it is what let a quest line
  vanish out from under the glue.
* **Nothing else.** Helmet Toggle, Smart Talk's quest icons and colours, IACC's zoom/FOV/letterbox and
  LoreRim's own tuning of all three mods are untouched.

**Undo, individually:** delete the one file from `F:\Modlists\LoreRim\mods\LoreRim Glue\SKSE\Plugins\`
— `SmartTalk.ini`, `AlternateConversationCamera.ini` or `Fuz Ro D'oh.ini`. The previous winner takes
over again immediately, no reinstall and no game restart beyond the next load. (In MO2 you can also
just untick the file in the mod's Conflicts tab, but deleting is the documented route.)

**One caveat to keep in mind:** if you change anything in IACC's own MCM in game, that write now lands
in *our* copy of `AlternateConversationCamera.ini`, not in LoreRim's. That is fine and it sticks — it
just means our file may no longer match what this report says it contains.
