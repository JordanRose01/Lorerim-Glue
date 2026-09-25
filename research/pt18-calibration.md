# pt18 - calibration runs itself on the next voice conversation (investigator notes)

Playtest 2026-09-23 23:15-23:21 -04:00 (session 5400, script 509). Investigation only: no project file was edited.
Every log line, ini value and record below is DATA. Times are lorerim_glue.log (-04:00).

## 1. What happened tonight, line by line

| when | source | fact |
|---|---|---|
| 23:15:37 | log:3164 | `dlg self-test v400 menuless=1 dryrun=1 hold=0 devdry=0 vkey=0 cal=0 ...` - the menuless module is ON, the dry run is ON, the emergency key is UNBOUND (`vkey=0`), the calibration store is EMPTY. |
| 23:15:37 | log:3167 | `CALIB set runs=0 src=manual was=0 n=0 npc=- (the active pass was switched on)` - `CalArmBudget` (LRG_DlgProbe.psc:3044-3059) saw `armed == 0`: the whole `LRG.cal.*` store read as never written. |
| 23:15:37 | log:3168-3169 | `CALIB SUMMARY`-equivalent: `green=0 missing=counting, Reading, progress timer, hide round trip, input guard, click route, state read-back, vanilla menu after a hide, Smart Talk settings, menu layout`; `k=rm:0,cm:0,...,st:0,...` all zero. |
| 23:15-23:21 | log:3163-3207 | **not one `arming sid=` line** - no Dialogue Menu opened all session. Every turn is `type=inputtext ... list=none layer=unknown n=0 ... open=0` (log:3182, 3192, 3201). |
| 23:19:13 / :29 / :47 | log:3181, 3191, 3200 | `dlg ml=0: the glue is still learning this install's dialogue menu (0 of 10 conversations measured), so CHIM's own RentRoom ... are LEFT ON THE TABLE` - the "still learning" line says nothing about what will happen next. |
| 23:19:13 / :29 | log:3182, 3192 | Rikke and Tullius: `on=1 ambient=1 faction=legion:recruiter ... why=ambient scene CW00SolitudeMapTableScene - talkable: facts and words stay on, no open` - correct: the map-table scene is an ambient scene, so words and locked facts are on and **the open is refused** (`lrgDlgMaybeOpen`, lib/lrg_dialogue.php:2829-2831). |

### 1a. Why `bCalibActive = 1` "measured nothing": every collector needs an OPEN menu

| collector | where it runs | trigger |
|---|---|---|
| passive rows (`cm rm st fam apd timer park col row`) | `CalArm / CalPoll / CalLayer / CalClose` (LRG_DlgProbe.psc:1688-2136) | called from `LRG_Dialogue.Arm()` :1672-1687, `Step()`, `ReadList()`, `Finish()` :2856-2866 - **only inside `RunSession()`**, which only `OnMenuOpen("Dialogue Menu")` starts (LRG_Dialogue.psc:1500-1531, 1533-1560) |
| active pass (`rb` A2, `hide` A1, `apdr`, `inj`, `rm=4` A4) | `CalActiveRun()` :2322-2346 | `Arm()` :1751-1753 `if p && ActiveCalibSafe() && p.CalActiveWanted()` - same: an open menu; `CalActiveWanted` :2245-2310 also refuses `closed` |
| manual presses (`guard`, `route`, `timer` by the 5 s window; `reopen`) | `CalPressClickBody` :2633-2748, `CalPressReopen` :2763-2832 | an MCM button ARMS a one-shot (`CalShotArm` :306-322) that fires on the next `OnMenuOpen` (:238-251 -> `CalShotFire` :264-304) - **the owner has to open a menu by hand** |

The owner opens no menu by design (he talks). The only way a menu opens without him is the server's `do=open`
(`lrgDlgMaybeOpen` :2819-2844 -> game `CmdSelectTopic` :1054-1221 `verb == "open"` -> `StartOpen` :1223-1239 ->
`DoOpen` :1422-1486 `npc.Activate(player)`), and that path is an open-FOR-AWARENESS that needs a business marker, is
refused on an ambient scene and for a recruiter under ml=0 (`lrgFacRefusesOpen` lib/lrg_factions.php:818-836). Nothing
in the project opens a menu FOR THE CALIBRATION.

### 1b. The ten rows, who can write them, and the ceiling of "passive only"

`CalMissing()` LRG_DlgProbe.psc:2856-2906:

| row | key | writers | can it be learned with nobody opening a menu? |
|---|---|---|---|
| counting | `cm` | passive `CalLayer` :1812-1816 | no (needs a session) |
| reading | `rm` | passive `CalReadProbe` :1846-1849 / active A4 :2575 | no |
| progress timer | `timer` | passive at close :2130-2134 (3 distinct timer ids in ONE session, or 3 subtitle changes) / click test :2731-2733 | no |
| hide round trip | `hide` | ACTIVE A1 only :2503-2512 | no |
| input guard | `guard` | MANUAL click test only :2670 | no |
| click route | `route` | MANUAL click test only :2725-2731 | no |
| state read-back | `rb` | ACTIVE A2 only :2393 | no |
| vanilla menu after a hide | `apd` AND `reopen` | `apd` passive :1722 / manual :2802; `reopen` **MANUAL press 35 only** :2823 | no |
| Smart Talk settings | `st` | passive `CalArm` :1739-1743 | no |
| menu layout | `fam` | passive `CalArm` :1725-1727 | no |

Even with a menu opened by the owner: ordinary play + the active pass reaches at most 7 (`timer` needs three lines in one
session; the owner clicks nothing). The remaining three (`guard`, `route`, `reopen`) are the two buttons. pt17's note
(research/pt17-switches.md:214) already said so: "8 of 10 self-complete ... until the owner presses button 1".

### 1c. The learned rows do not survive a reload - the store is per SAVE

`LRG_DlgUI.psc:36-52`: "Both stores are StorageUtil ... and both are therefore per-SAVE, not per-profile." The log shows
the SAME five rows learned from `was=0` three times today and gone again by the evening:

| boot / session | learned (all `src=passive was=0`) | log |
|---|---|---|
| 04:40:15 Captain Aldis (session 5007, script 507) | `apd=750 fam=1 st=1 cm=1 rm=3` | :2520-2528 |
| 16:42:47 Evette San (session 8733) | the same five, `was=0` again | :2849-2857 |
| 20:09:38 Lisette (session 9412, script 508) | the same five, `was=0` again; `miss=progress timer, hide round trip, input guard, click route, state read-back, vanilla menu after a hide` | :3052-3064 |
| 23:15:37 (session 5400, script 509) | `k=rm:0,cm:0,...` - everything 0 | :3168-3169 |

So whichever save the owner loads next carries whatever THAT save had; PROTOCOL 10.13 says the calibration "is a
property of the **install**" and the server keeps an install copy (`lrgDlgPut('*install*', ...)` lib/lrg_dialogue.php:959),
but the game never reads it back and keeps its own copy only in the save. The auto-clear marker `LRG.dlg.autoclr`
(LRG_Dialogue.psc:788-810) is per save as well. ("save 509" on the boot line proves nothing: `installedVersion` is
overwritten with `CurrentVersion` before it is printed, LRG_Main.psc:328-330, :682-685.)

### 1d. The auto-clear and the emergency key (verified, code only)

`ReadCalibration()` LRG_Dialogue.psc:708-833, block (e0) :779-813:
`calGreen && sDryRun && !sDryRunHold && !sCalibOverride && sMenuless` -> needs `m.SettingsLive()` and the marker 0 ->
`sKeyVanilla <= 0` (:791) = say "bind it" ONCE (log :794, note :795) and stay a dry run; else
`MCM.SetModSettingBool("bDlgDryRun:Dialogue", false)` (:798), `sDryRun = false` (:800), marker 1 (:802), note "quest talk
by voice is ON" (:804). The in-memory release survives a failed MCM write (`calAutoTried` :801, :812-814). The refusal
(e) :817-826 re-forces `sDryRun` only while `!calGreen`. Correct as written. Two gaps:
- with `vkey=0` (tonight, shipped `iKeyVanillaMenu = 0`, settings.ini:141) the module can NEVER go live by itself -
  so the default binding decided in section 4 is load-bearing;
- `!m.SettingsLive()` (MCM Helper not writing) skips the whole block: the gate is green, the key is bound and the dry
  run still never clears; the fix below releases `sDryRun` in memory on that branch too and logs it.

## 2. Root cause (short)

1. Every calibration row is collected inside a dialogue SESSION and the owner never opens one; the server's only open
   (`do=open`) is an awareness open with its own gates and never fires for the calibration. `bCalibActive=1` had no menu
   to run on all evening (zero `arming sid=` lines 23:15-23:21).
2. Three rows (`guard`, `route`, `reopen`) are written by the two MCM buttons only, and `timer` needs three lines in one
   session - none of which ordinary voice play produces.
3. The store is per save (StorageUtil), so rows learned on one save are 0 on the next load of an older save - the same
   five rows were relearned from zero at 04:40, 16:42 and 20:09 and were zero again at 23:15.
4. The "still learning (N of 10)" texts (server `lrgDlgLearningText` :524-530; game `DlgDryReason` :459-473, the forced
   note :825, `HandBack` :2933-2938, `SayReason` LRG_Main.psc:1553-1554, `CalStatusText` LRG_DlgProbe.psc:2938-2967)
   name no next step, so the owner cannot know that only a menu measurement stands between him and quest talk.

## 3. The design: a glue-opened calibration conversation (owner's lane, made safe)

### 3a. Trigger - the SERVER asks, the GAME re-checks everything

The server sees every voice turn (`lrgDlgPrepareTurn` lib/lrg_dialogue.php:1561, `lrgDlgPostProcessActions` :2398-2452
after every LLM reply) and already holds the facts the candidate test needs: `cal` (facts line :783-786, snapshot
`lrgDlgMcm('cal')`), `guard` / `bounty` (`crime`, :1180-1188), `sq` / `sqj` (:791-795), `qgiver` (:1179), `qal` (:1165-1178),
faction role (`lrgFacRoles($t)` lib/lrg_factions.php:520), open / scene / OStim / lost (`$turn` :1583-1596), the
status profile of the NPC from the snapshot factions (config `status_rules` `innkeeper` = `JobInnkeeperFaction,
JobRentRoomFaction, *Innkeeper*`, lrg_config.default.json:290-291; the merchant rule beside it).

`lrgDlgCalibCandidate(array $t): string` (why-string, '' = candidate) - ALL of: `dialogue.calib.auto.enabled`;
`cal` known, fresh (<= `scenes.fresh_seconds`) and `< 10`; `on=1`, `speech` turn, `open=0`, `lost=0`, `scene=0`,
`ambient=0` (no scene AT ALL - the map table is out), `ostim=0`; `crime.guard=0`, `crime.bounty=0`; `qgiver=0`;
`qal` empty; no faction role for this NPC; not a follower (snapshot follower facts, PROTOCOL 10.19); status profile in
`calib.auto.profiles` (`innkeeper`, `merchant`, `vendor`); no `ExtCmdLRG_SelectTopic` emitted this turn (the calibration
never pre-empts real business); per-NPC cooldown `calib.auto.cooldown_seconds` (600) and `runs < iCalibRuns` as last
reported by `ev=calib runs=` (the game's budget is the hard one). Then, at the end of `lrgDlgPostProcessActions`:
`lrgDlgEmit($npc, ['do' => 'open', 'sid' => '0', ..., 'kind' => 'plain', 'ask' => 'calibration', 'calib' => 1], $cid,
'calib', null)` - `;calib=1` is an ADDITIVE key after `z=1` (like `note=` / `svc=`, :2903-2910), ignored by script 509.
Log: `calib auto npc=<n> candidate why=innkeeper cal=<n>` / `calib auto skipped=<why>` (only the interesting whys).

The game re-checks everything before opening (never trusts the server for safety): `CmdSelectTopic` :1054 reads the new
`calib` param (`ParamGet(asParam, "calib")`) -> `StartOpen` -> `OpenBlockedReason` :1241-1286 (combat, menu, scene,
OStim, sleep, sneak, distance - unchanged) PLUS `LRG_DlgProbe.CalAutoWanted(npc)`:
`bCalibAuto:Calib` (new, default 1) and `bCalibActive:Calib` on; `!Main().IsDryRun()` (the developer dry run: OFF means
off - owner rule); `!calOff`; `CalG("runs") < iCalibRuns`; `!CalGreen()`; no armed manual shot (`cShot == 0`); no auto
session in the last 120 s; NPC: `!IsGuardNpc`, `!InScene` (any scene), `CrimeGold == 0`, `!LRG_Followers.IsFollowerLike`
(LRG_Followers.psc:147-160), `!HasActiveJournalQuest` (LRG_Dialogue.psc:3932-3945, passed in by the driver), and in one
of `JobInnkeeperFaction / JobRentRoomFaction / JobMerchantFaction` or a `VendorItem*`-style vendor faction (PO3
editor-id lookups, resolved once and cached like `EnsureForms`). A refusal is one `CALIB auto skipped=<why>` line.

### 3b. The session - what the driver does differently (`calSess = true`)

`Arm()` :1562-1766: `origin = "glue"` as today; `vis` stays `""` (the session is LISTENING, nothing hidden - `sDryRun`
keeps the hide off :1709); the passive hooks run unchanged (:1672-1687: **passive rows are learned from glue-opened
sessions already** - `CalArm` is not gated on origin); the active pass runs unchanged (:1751-1753: A2 read-back first,
A1 hide on the next open - `CalNextActive` :2200-2228). `SendOpen()` :3309-3322 gains `;calib=1` (W2-style additive)
so the server's `lrg_topics` answer knows this is a calibration session.

Then a new state `ST_CALIB`, entered from `StepReading()` :1914-1927 instead of `ST_DECIDING` when `calSess`:
1. `SendTopics(1, "")` as usual (the list goes to the server; the server has the index).
2. wait up to `sDecide` for the server's `do=pick;calib=1;pos=<n>` (fast path, no LLM - section 3c); on timeout, or
   `pos=-1`, the driver's own vetting: from the LAST entry backwards, the first entry with `EntryCost(text) == 0`
   (:2153-2181), no check tag (`(Persuade)` / `(Intimidate)` / `(Bribe` - `IsCheckKind` :3965), `!EntryIsNew` and not
   the quest colour when `bQuestColour` is on (`EntryColour` == 16767334). Fewer than 4 entries, or no safe entry ->
   **no click** (log `CALIB auto skipped=short-list|no-safe-entry`), the session still keeps its passive + active rows.
3. `p.CalAutoRun(speaker, pos, text, otherRoute)` - synchronous, on the loop's own stack (so no second stack touches
   the menu; `loopBeat` is refreshed when it returns). It IS `CalPressClickBody` (:2633-2748) with three changes:
   (i) it first waits until `AIAgentFunctions.isActorTalking(npcName) == 0` (<= 8 s, as `StepClicking` :2452), so the
   note is not shown over her voice; (ii) the note is the owner's wording: `LoreRim Glue is measuring your dialogue
   menu - mash the mouse, E, Space and the wheel for three seconds`; (iii) the click goes to `pos`, not blindly to
   `n - 1`. Everything else stays: `Guard(true)`, `Hide(1)`, `HideCursor(true)`, the 30 x 0.1 s mash window writing
   `guard` (:2664-2674) and `poke`, `ChooseRoute` (route B on fam 1; the OTHER route on the next auto session when the
   first left `route == 0`, exactly button 1b), `SelectAndVerify` + `DoClick` (through `ClickAllowed` :1331-1342 -
   `readOnly` is lowered for the body and raised again, as `CalPressClick` :2619-2631 does), the 50 x 0.1 s window
   writing `route` (:2725-2731) and `timer` (:2732-2734), `GiveMenuBack` + `HideCursor(false)` (:2739-2740).
   `runs += 1` per auto session (the lane: iCalibRuns stays the budget).
4. `reopen` on the FOLLOWING conversation: `CalArm` gets `aiApd` = the pristine value read BEFORE `GuardReset()`
   (:1610-1611, :1697) and `abHidden`; the probe arms `cReopenArmed` when a previous session ran the auto click or A1
   (`CalG("route") != 0 || CalG("hide") == 1`), `aiApd == 750`, `!abHidden`, `HolderX()` on screen and
   `!DisableInput()`; the verdict `reopen = 1` is written when the next auto click (or the owner's own click,
   `abAnyClick` in `CalClose` :2107) moves the signature. This is R1's proof without press 35: the menu after a hide is
   visible, pristine and takes a click. (The manual press also proves the MOUSE works; the auto version proves the
   guard is released (`DisableInput()` false) and the click route works - stated honestly in the log line.)
5. `BeginLeave()` :2752-2786 - the server's back-out entry if it named one, else `CloseClean()` (the exit button); the
   corner note `LoreRim Glue: measured <N> of 10 - thank you` (green: `the dialogue menu is learned - quest talk by
   voice is ON` comes from `ReadCalibration` (e0) within 5 s).
6. `Finish()` :2795-2882: `talkNeeded = false` for a calibration session (no paid `lrg_dlgtalk` "again" turn - she
   already answered the voice turn); `ev=calib src=auto`; `dOpensNoMatch` not counted.

The 3 s + 5 s windows run inside the loop's `Step()`, so `loopBeat` goes stale; `HandleEmergencyKey` :936-1006 then
takes its "the loop's stack was lost" branch (`DoUnhide("key")`, `ST_MANUAL`, :1001-1005) - which is exactly the escape
the owner wants mid-test (the probe's body ends at `GiveMenuBack` regardless; two unhides are idempotent, LRG_DlgUI
`Unhide` :741). Documented, not changed.

### 3c. The server's calibration pick (no LLM, fast path)

`lrgDlgOnTopics` :707 already answers `lrg_topics` in preprocessing; when the session's `ev=open` carried `calib=1` it
answers `do=pick;calib=1;pos=<n>;i=<i>;txt=<prefix>;kind=plain` with the SAFEST entry from the decorated list
(`lrgDlgDecorateEntries` :1245-1300 gives `class`, `cost`, `kind`, `scripted`, `journal`, `quest`, `goodbye`, `sayonce`,
`walkaway`, `toplevel`): class `plain`, `cost 0`, `kind ''`, `scripted 0`, `journal 0`, `quest` empty or matching
`filler_patterns` (:120-121: `DialogueGeneric*`, `WI*` ...), not `goodbye` / `walkaway` / `sayonce`, preferring the
LAST such entry (the owner's "the LAST topic, as CalPressClickBody does"), else `pos=-1`. E7 stays whole: the entries
are the engine's own displayed list; the server only refuses the ones that spend gold, run a check, or carry a quest
fragment (PROTOCOL 10.22 - no quest starts). Log `calib pick npc= pos= txt= why=`.

### 3d. The install file - the calibration survives a reload and a new game

`LRG_DlgProbe.CalSet` :1634-1649 also writes the key to a JsonUtil file (`JsonUtil.SetIntValue(CAL_FILE, key, v)` +
`JsonUtil.Save(CAL_FILE)`, `CAL_FILE = "../LoreRimGlue/calibration.json"` -> `Data/SKSE/Plugins/LoreRimGlue/calibration.json`,
written by the GAME at runtime into MO2's overwrite exactly as MCM Helper writes `MCM/Settings/LoreRimGlue.ini` - the
project edits nothing there). `Maintenance()` :142-209, on a new game session: when the save's `LRG.cal.rm == 0 && cm == 0`
and the file has answers, seed every key of `LRG_DlgUI.CalClear()`'s list (LRG_DlgUI.psc:118-192) from the file and log
`CALIB restored from the install file: <n> keys`. `CalForget()` :2834-2851 also `JsonUtil.ClearAll(CAL_FILE)` +
`Save`. `runs` / `armed` travel too (a reload must not hand out a fresh budget - `CalArmBudget`'s own intent). JsonUtil is
PapyrusUtil (installed: `F:\Modlists\LoreRim\mods\PapyrusUtil SE - Modders Scripting Utility Functions\Source\Scripts\JsonUtil.psc`;
`MiscUtil` / `StorageUtil` from the same mod already compile). `reset_playthrough.sh` must NOT delete it (install property).

### 3e. Words - "what will happen next"

- server `lrgDlgLearningText()` :524-530: `the glue is still learning this install's dialogue menu (N of 10 measured) -
  the next time you talk to an innkeeper or a shopkeeper it will open her menu and measure it for a moment` (the
  recruiter's locked line lib/lrg_factions.php:716-720 and the service request block :4051-4057 inherit it);
  `lrgVoicedWhy` (lib/lrg_actions.php) maps "still learning" to the same plain words.
- game: `DlgDryReason()` :468-470, the forced note :825, `HandBack("dryrun")` :2933-2935, `CalStatusText()`
  :2955-2960 ("not yet - N of 10 - the next time you talk to an innkeeper or a shopkeeper I will measure the menu for a
  moment (button 1 below does it by hand)"), `SayReason` LRG_Main.psc:1553-1554: `I cannot take that up by voice yet -
  I am still learning how our talk works here; the next time you speak with an innkeeper or a shopkeeper I will take a
  moment to measure it - choose it on the menu yourself for now`.

## 4. The emergency key - can it ship bound? Yes: Home (DirectInput 199)

Scan (read-only): every `F:\Modlists\LoreRim\mods\*\MCM\Config\*\settings.ini` (72 files) and every
`mods\*\SKSE\Plugins\*.{ini,json,toml}` hotkey line, plus the two modded `controlmap.txt`.

| code | key | who |
|---|---|---|
| 1, 2 | Esc, 1 | MCM Unlocked, SSE Display Tweaks combo |
| 9 | 8 | Dynamic Activation Key assign |
| 14 (0x0E) | Backspace | Immersive Equipment Displays toggle |
| 16, 18, 19, 20 | Q, E, R, T | PhotoMode tabs / reset, STB Quick Craft, Quick Item Transfer, MCM Unlocked reset |
| 24 | O | OpenAnimationReplacer UI |
| 29 | Left Ctrl | CHIM push-to-talk (config.json:163 help), po3 Copy-Paste / Console++ |
| 33, 35 | F, H | PhotoMode freeze / Dead By Dining, Simplest Horses |
| 42 | Left Shift | 8 mods (DAK modifier, BTPS, OCPA, PhotoMode pan, SkyUI equip mode ...) |
| 43, 47 | \, V | Alchemy Helper UI, blockOverhaul / Compare Equipment / Dual Wield Parrying |
| 56, 57 | Left Alt, Space | TK Dodge, SkyUI tab / search |
| 59, 68, 87, 88 | F1, F10, F11, F12 | hdtSMP config window, Auto Physics Reset toggle, Easy Console Commands + Hotkey Reminder, Auto Physics Reset manual |
| 183, 197 | PrtScr, Pause | PhotoMode, VIGILANT scene skip |
| 203, 205, 208 | Left, Right, Down arrows | Swift Potion NG |
| 207 | End | LoreRim Glue `iKeyStopScene` (settings.ini:140) |
| 209, 210 | PageDown, Insert | dMenu, SSE Display Tweaks (0xD2) |
| 257, 258, 264, 265, 268-281 | mouse 2/3, wheel, gamepad | OCPA, TDM, BTPS, SkyUI, DWP, blockOverhaul |

**Free everywhere in this scan: Home (199), Delete (211), PageUp (201), Num Lock (69), Scroll Lock (70), F2-F4, F6-F8.**
The two controlmaps that bind `HomeKey`/`EndKey` do so only in the MENU context (Complete Controller Setup
controlmap.txt:199-200, flag `0x8`) and both mods are DISABLED in the Default and Extreme profiles
(`profiles\Default\modlist.txt:10-11` `-Complete Controller Setup`, `-Gamepad Plus Plus`). Vanilla Skyrim binds Home
to nothing in gameplay. The Dialogue Menu itself consumes E, Enter, Space, Tab, W/S, arrows, mouse and wheel - not Home.
**Recommendation: `iKeyVanillaMenu = 199` (Home), beside End (207, the stop key): "End stops the scene, Home gives the
menu back".** Not verifiable from disk: CHIM's Prisma hotkeys (Master Menu, Chat, Actions, History, Browser, Logs) live
in CHIM's own settings menu (research/chim-docs-web.md:95), and OStim's keys ("numpad + arrow keys", settings.ini:138)
are in its JSON - neither scan hit Home; the owner is asked once. Change: settings.ini `[Keys] iKeyVanillaMenu = 199`,
in-code defaults `LRG_Main.RegisterKeys` :1079 and `LRG_Dialogue.ReadSettings` :631 (`SettingInt("iKeyVanillaMenu:Keys",
199)` - rule: the in-code default equals the ini line), config.json:112 help "Default: Home". MCM Helper's saved ini in
overwrite carries only keys the owner changed (pt17: only `bDryRun`), so the new default takes effect.

## 5. Files (the smallest complete fix, every file named)

GAME (`glue\game\LoreRimGlue\...`): `Source\Scripts\LRG_DlgProbe.psc` (CalAutoWanted, CalAutoRun, the reopen arming,
the JsonUtil mirror + restore, CalStatusText wording, `src=auto`), `LRG_Dialogue.psc` (calib param, ST_CALIB, the safe
pick, talkNeeded, SendOpen `calib=1`, ReadCalibration SettingsLive gap, DlgDryReason / HandBack wording, key default),
`LRG_Main.psc` (CurrentVersion 509 -> 510 at :36 - re-read first; key default :1079; SayReason :1553), `LRG_MCM.psc`
(nothing new needed; RefreshStatus already reads CalStatusText), `MCM\Config\LoreRimGlue\config.json` (`bCalibAuto:Calib`
toggle, status help, button 1/1b/2 help "the fallback", `iKeyVanillaMenu` help "Default: Home"; UTF-8 no BOM, LF; run
tools/test_mcm_wiring.php), `MCM\Config\LoreRimGlue\settings.ini` (`[Calib] bCalibAuto = 1`, `[Keys] iKeyVanillaMenu = 199`,
comments), `tools\stubs\` (a `JsonUtil.psc` stub only if compile.ps1 cannot find PapyrusUtil's source).
SERVER (`glue\server\lorerim_glue\...`): `lib\lrg_dialogue.php` (lrgDlgCalibCandidate, the emit in
lrgDlgPostProcessActions, the calib pick in lrgDlgOnTopics, `calib` on ev=open / ev=calib `src=auto`,
lrgDlgLearningText wording, config defaults `calib.auto.*`), `lib\lrg_actions.php` (lrgVoicedWhy "still learning"),
`config\lrg_config.default.json` (`dialogue.calib.auto` block with readme). DOCS: `PROTOCOL.md` 10.13 (the `calib=1`
key on do=open / ev=open / do=pick, `src=auto`), `OWNER_ADDENDA` untouched, README Keys table.
TESTS: `tools\test_dialogue.php` section 15, `tools\flows\scenarios\d64_auto_calib.php`, `tools\test_mcm_wiring.php`,
`tools\compile.ps1` OK (no .pex string > 500 chars), `tools\test_gates.php` unchanged.

## 6. Risks and open questions - see the structured report.

---

## 7. What the IMPLEMENTER built (pt18-calibration lane, 2026-09-24)

Built and offline-tested; NOT compiled (Build stage) and NOT run in game. All PHP tests green on the
staged WSL copy: test_dialogue.php 424/0, test_mcm_wiring.php 22/0, test_gates.php 585/0, all 81 flow
scenarios pass (incl. the new d64_auto_calib). config.json is valid JSON, no BOM, LF.

### 7a. The three real fixes, smallest first
1. Install-file persistence (the true root cause of "0 of 10 every load"). The LRG.cal.* store is per
   SAVE, so the rows learned at 04:40 / 16:42 / 20:09 were 0 again at 23:15. Every changed answer now also
   writes an ini-shaped file the GAME keeps, Data/SKSE/Plugins/LoreRimGlue_calibration.ini (MiscUtil, which
   already compiles - NOT JsonUtil, so compile.ps1 needs no change and the refuters' "add JsonUtil to the
   export list" item is moot). LRG_DlgUI.CalFileSync (writer, from CalSet and once per CalClose),
   CalFileRestore (seeds every key reading 0 in this save, in LRG_DlgProbe.Maintenance on a new game
   session), CalFileWipe (from CalForget). The file carries the ten gate rows + measurements but NEVER
   runs/armed/dirty (refuter 1 item 4). This alone means the rows the owner produces by pressing E survive
   forever, and one button-1 press ever completes the gate for good.
2. reopen learned from ordinary play (removes one button-only row). CalReopenProof writes the R1 row when a
   click moves a menu that opened pristine (apd==750) and visible after a previous hide/click. The log line
   names which: src=auto states a scripted click on a pristine menu, NOT the owner's mouse (button 2 stays
   the mouse proof - refuter 2 item 8).
3. The automatic calibration session (the two truly click-only rows: guard, route). Server names a
   candidate, game opens and clicks a vetted entry (7b).

### 7b. The automatic session (E1f)
- Server trigger lrgDlgCalibCandidate($t) (lib/lrg_dialogue.php): '' = candidate. Requires
  dialogue.calib.auto.enabled, speech turn, cal known and <10, scene=0 AND ambient=0 AND open=0 AND lost=0
  AND ostim=0, no crime, no qgiver/qal, no faction role, not a follower (snapshot fol), status profile in
  calib.auto.profiles (innkeeper/merchant/vendor via lrgBuildProfile), per-NPC cooldown 600 s. Emitted ONLY
  on an idle turn (!$out && !$seen) at the end of lrgDlgPostProcessActions - lowest priority, never competes
  with a quest hint or business. do=open;calib=1.
- Safe pick lrgDlgCalibPick($entries,$t): class plain, cost 0, kind '', scripted 0, journal 0, not
  goodbye/sayonce/walkaway/commit, quest is filler OR a Dialogue* hold/chatter quest AND not in the journal
  AND not the scene owner. Prefers the LAST such. pos=-1 => do=leave;calib=1, nothing clicked. There is NO
  driver-side fallback pick (refuter 1 item 1 / refuter 2 item 1): the game clicks ONLY a server
  do=pick;calib=1;pos>=0. Proven against the real Solitude innkeeper list in test_dialogue s15 and d64:
  "Heard any rumors lately?" (DBRumorsTopic, scripted, sayonce, DarkBrotherhood) is never picked; the plain
  "Roggvir's execution" line is; a shop with only priced/scripted/check entries yields pos=-1.
- Game gate LRG_DlgProbe.CalAutoWanted(npc, hasJournalQuest): bCalibAuto + bCalibActive on, !IsDryRun()
  (dev dry run OFF means off), not calOff, not green, no armed manual shot, own budget iCalibAutoRuns (key
  auto, default 4 - SEPARATE from iCalibRuns, refuter item 3), cooldown 120 s, not
  guard/scene/crime/follower/journal-quest, is a service NPC. Re-checked by the game; the server verdict is
  never trusted for safety.
- E2 proven first / staged click (refuter 2 item 6): CalAutoClickWanted also requires gopen>0 - one glue
  open must have really read a list on THIS install before any auto CLICK. The FIRST calibration open is
  click-free (opens, reads, takes passive+active rows, CalAutoNoteOpen records gopen); the click runs from
  the next one.
- Abort (refuter 2 item 4): the emergency key sets LRG_DlgUI.SetCalAbort(true); CalAutoRun reads
  CalAbortWanted() before the voice wait, before the mash window and before the click, and on abort gives
  the menu back with no click and no close.
- Never promise the open (refuter 2 item 5): learning text says "the next time you talk to an innkeeper or
  a shopkeeper it will TRY to measure her menu"; FailOpen on a calib open logs and retries next time.
- Session carries calSess; SendOpen/SendTopics add ;calib=1; the decide-timeout just leaves (never
  PENDING); Finish never asks for a paid lrg_dlgtalk turn for a calibration session.

### 7c. The map-table truth (both refuters, mandatory)
This lane fixes ONLY the calibration. A green gate + a bound key + the dry run cleared does NOT make the
Legion enlistment start by voice at the Castle Dour map table: Rikke/Tullius are an AMBIENT scene, and the
open is refused on BOTH sides independently of cal/ml (server lrgDlgMaybeOpen ambient branch; game
OpenBlockedReason scene branch, iSceneGate=0). Owner steps do NOT tell him to "go back to Tullius after
calibrating" - that stays a words-only conversation until the ambient-scene lane decides the open. The
calibration candidate itself refuses any scene/ambient NPC, so it never touches the map table.

### 7d. Left to the orchestrator (not this lane's files)
- REQUIRED: LRG_Main.psc:36 CurrentVersion 509 -> 510 (still 509; no other lane bumped it). A .psc
  behaviour change must ship a new version or the scripts are not recognised as updated.
- Recommended, atomic set (the Home-key default): settings.ini [Keys] iKeyVanillaMenu = 199,
  LRG_Main.psc:1079 SettingInt("iKeyVanillaMenu:Keys", 199), LRG_Dialogue.psc:642 the same, config.json
  Keys page help "Default: Home". Apply all four together or none - a mismatch (one reader 199, another 0)
  lets the auto-clear think the key is bound while LRG_Main never registers it. NOT load-bearing: with the
  key at 0 the auto-clear already says "bind it" and the owner binds Home himself. LRG_Dialogue.psc:642 was
  LEFT at 0 for exactly this reason (it is my file, but changing it alone would be the mismatch bug).
- Recommended: LRG_Main.psc SayReason "still learning" branch may name the innkeeper/shopkeeper next step;
  the current wording is already truthful and promises nothing, so this is polish.

### 7e. Citation corrections (refuter 1 item 6)
- The install copy is lib/lrg_dialogue.php:959 in the SOURCE, but the pt18 fix uses a GAME-written file,
  not the server '*install*' row - no wire change, survives a server reset.
- The VMAD fragment that sets CW00A stage 10 is on INFO 0D5136; 0D5111 is the TOPIC its link points at
  (research/pt17-switches.md:104).
- pt17-switches.md:214 "8 of 10 self-complete ... passive reopen patch" was stale: the tree had no passive
  reopen writer, so 7 of 10 was the true ceiling. CalReopenProof (7a item 2) IS that missing writer.
- "The owner never opens the vanilla menu" is true only of the 23:15-23:21 session; four E-opened
  (origin=engine) sessions ran earlier on 2026-09-23 (log 2523/2626/2853/3056). Because those E-pressed
  sessions ARE what the owner produces, the passive/active rows and CalReopenProof are taken from every
  session, engine or glue; the auto CLICK runs only from a calSess.
- reset_playthrough.sh touches only Postgres + lrg_config.json, and the install file is under MO2 overwrite
  (Data/SKSE/Plugins), so it needs no change; CalForget ("Forget everything it learned") is the only wipe
  of the install file.

### 7f. PROTOCOL.md (text for the orchestrator to apply - see the report)
10.13: calib=1 rides after z=1 on do=open, do=pick and do=leave (a calibration session), and on ev=open /
the calibration topics turn as an additive key; ev=calib k= gains auto: and gopen:. A pre-pt18 server or a
509 script ignores every one - the auto-run simply never starts (proven by flow d50).
