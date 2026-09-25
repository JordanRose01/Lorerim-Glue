# pt12 verify - the stuck OnInit, new quest form 0x802 (script 505): CONFIRMED and INSTALLED

LoreRim Glue, GAME lane. Written 2026-09-23 01:10. Role: VERIFIER + INSTALLER of
`research/pt12-stuck-oninit-fix.md`.

**Verdict: the diagnosis is right, moving the quest to a new form is the right fix, and 505 is
installed.** It was hand-copied into `F:\Modlists\LoreRim\mods\LoreRim Glue` at **01:07:10**. MO2 was
open (pid 41132) and SkyrimSE was not running, checked right before the copy. All 24 files were
SHA-256 verified, and the installed ESP dumps exactly like the build. No profile file was touched,
and no file of any other mod was touched. I found no blocking defect. Section 4 lists two
leftover risks and three small notes. None of them stops the fix from working, and each one has a
symptom you can see in the log.

---

## 1. The evidence, re-read first hand

Source: `Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` (10,904 lines,
23 Sep 00:14-00:19).

* **929-930 (00:16:40, the load):** `Function LRG_Main..LogC in stack frame 3 in stack 2353 differs
  from the in-game resource files - using version from save`, and the same for `LRG_Main..Maintenance`
  in stack 78350. Both stacks came **out of the save**, and the VM keeps running their old bytecode.
* **3024-3033 (dump summary, 00:17:43):** `LRG_Dialogue.OnInit 1`, `LRG_PlayerAlias.OnPlayerLoadGame 3`,
  `LRG_Main.OnChimTextReceived 6 / OnChimSpeechStarted 5 / OnChimSpeechStopped 2`,
  `LRG_Main.OnCrosshairRefChange 185`, `LRG_Dialogue.OnCrosshairRefChange 187`.
* **6506 - stack 2353:** `Type: Initialization Event`, `State: Waiting on other stack for return`,
  `Return register: "Jordan"`. Its frames, top to bottom: `Actor.GetDisplayName` (native, IP 0) <-
  `[LRG_MainQuest (FECF6800)].LRG_Main.LogC()` `<savegame>` (asMsg `dlg self-test v400 ...`) <-
  `LRG_Dialogue.SelfTest` l.444 <- `LRG_Dialogue.Maintenance` l.391 <- `LRG_Dialogue.OnInit` l.315.
  **This is the OnInit initialisation stack of the old form's LRG_Dialogue. The old form is
  `FECF6800` (object 0x800 of the ESL).**
* **6630 - stack 78350:** `Waiting on other stack for call`, return register `[LRG_Dialogue <LRG_MainQuest
  (FECF6800)>]`. Its frames: `LRG_Main.Maintenance()` `<savegame>`, IP 4298, `[::temp0]: 2347`,
  `[dlg]: LRG_Dialogue`, `[prb]: None` <- `LRG_PlayerAlias.OnPlayerLoadGame` l.7.
* **6555 / 6617 - stacks 80262, 85813:** two more `OnPlayerLoadGame` calls on the old alias. Both are
  parked at the same instruction and waiting to call `LRG_Main.Maintenance`. One of them is this
  session's load, which is where 504's `Maintenance` was queued. It never ran.
* **10642-10832 (00:18:45, 62 s later):** stacks 2353, 80262, 85813 and 78350 are unchanged, down to
  every IP and temporary value. The only difference between the two dumps is an unrelated CHIM
  stack. Not a single `[LRG]` trace line appears anywhere in the log.
* **Dating it:** `2347` is the `sessionTag` that pt11's save reader found in **both** Save14 and
  Save15 (`pt11-dead-scripts-verify.md` 1.3, line 81). That puts the pair at the 22 Sep ~17:17 load.
  The last `GAME` line the server ever received is the 17:17:47 `dlg self-test v400`, the very
  message in 2353's `LogC` frame. The only saves in `profiles\Ultra\saves` are Save1 and Save3
  (21 Sep, before the pair), Save14 and Save15 (22 Sep, both carry it) and two 21 Sep Requiem
  saves. Nothing was saved in playtests 11 or 12.

**How the lock works, and how much of it I can prove.** The dump cannot settle the exact locking
rule. It does show that a stack in `Waiting on other stack for call` does *not* keep its own
object's lock: three alias stacks sit inside the same `LRG_PlayerAlias` function together. The
fixer's rule explains the rest: a script is closed to other stacks until its `OnInit` returns (the
CK wiki's note on `OnInit`), so 78350 holds `LRG_Main` while its call into the unfinished
`LRG_Dialogue` waits. The fix does not depend on the exact rule. Three things are certain:
1. the pair is permanent: it has been carried through two saves and three sessions, and it is
   unchanged between two dumps;
2. it is made of an `OnInit` that never finished on the old `LRG_Dialogue` and a stack parked in the
   old `LRG_Main`;
3. every event of the old quest queues behind it.

Ordinary two-way calls between `LRG_Main` and `LRG_OStim` have existed since v0.2 and never hung in
playtests 1-9. The ingredient that turned a call into a permanent hang is the `OnInit`.

## 2. Is a new form id the right remedy? Yes - there is nothing cheaper that is as certain

The frozen frames run their **saved** bytecode on the **old objects**. So no `.pex` change can reach
them, and as long as form 0x800 exists, the quest's `LRG_Main` is the object that is locked. Moving
the quest gives it fresh script instances that the frozen stacks cannot hold. Alternatives I
considered:

| alternative | why not |
|---|---|
| ReSaver: terminate stacks 2353 / 78350 in Save15 | Owner-side and per save. The old `LRG_Dialogue` would be left with an `OnInit` that never completed, and it is uncertain whether the VM ever opens it again. Not equally certain. |
| Load Save3 (21 Sep) | Certain, but loses two days of play. |
| Keep 0x800 and rename the scripts | `LRG_Main` itself is the locked object, so it would need a new name too. Every reference would change (`LRG.GetMain`, MCM, CHIM bridge). Far more churn and no more certainty. |

The new form costs three bytes in the ESP. No world state is lost: Save15 has no conversation
hold, outro hold, glue-stripped clothes, active scene or listener lock. I spot-checked the fixer's
save dump (`%TEMP%\lrg_pt12_save15.txt`: `convHeld/convActive/outroActive/listenerForced/
startedByGlue = false`, `cloNpc/outroActor/convActor = None`, `baseValid = false`).

## 3. Checks (all done by me, on the files that are now installed)

| check | result |
|---|---|
| `esp_dump.py` build ESP | TES4 flags `0x200` (ESL), HEDR 1.70, 3 records, next id **0x803**, master Skyrim.esm. `QUST 01000802 LRG_MainQuest`: VMAD `LRG_Main, LRG_OStim, LRG_Dialogue, LRG_DlgProbe` (0 props), alias object `01000802` alias 0 `LRG_PlayerAlias`, 108/108 bytes read, ALFR 0x14, DNAM `0x0111` (start-game-enabled, run once, as before). `QUST 01000801 LRG_MCMQuest` unchanged. |
| byte diff against the installed 504 ESP | exactly 3 bytes: offset 38 (HEDR 02->03), 185 (record form id 00->02), 301 (VMAD alias form id 00->02) |
| `make_esp.py` re-run into `%TEMP%\lrg_vrf12\espgen` | ESP and SEQ byte-identical to the build |
| SEQ | `02 08 00 01 01 08 00 01` = 0x01000802, 0x01000801 |
| old id in the ESP | no `00 08 00 01` anywhere in the file |
| grep of the whole `glue\` folder (text) | only the historical plans (`PHASE2_DESIGN.md:117,1084`, `V04_BUILD_PLAN.md:166,511,608,1471,1515`, `V05_EXPANSION_PLAN.md:64`) and the guard `make_esp.py:24 RETIRED_IDS = (0x800,)`. Not in any `.psc`, the MCM config (all 7 `formId` are `0x801`), tools or server. `LRG.psc:9` = `GetFormFromFile(0x00000802, ...)` |
| binary scan of the 24 installed files | 0 hits for LE `0x01000800`, pex int `0x800`, `FECF6800`, `0x00000800`. `LRG.pex` holds int 0x802 |
| stale copies | nothing named `LRG*` or `LoreRimGlue*` in MO2 `overwrite\`, and no other mod ships `LoreRimGlue.esp/.seq` or `LRG*.pex` |
| `compile.ps1` (re-run by me) | `OK - ... compiler reported 0 errors, 0 warnings` (2 rounds, 22 auto-stubs, 10 .pex) |
| string lengths (own big-endian .pex reader, `%TEMP%\lrg_vrf12\pexdis.py`) | longest docstring 290 (LRG_MCM/LRG_Dialogue), longest string 312 (LRG_OStim, not a docstring). All under the 300 / 500 limits. Every file parsed to its last byte. |
| every `OnInit`, complete bytecode | `LRG_Main`: `assign initBoot True ; RegisterForSingleUpdate(self, 0.5)`. `LRG_OStim`, `LRG_Dialogue`, `LRG_DlgProbe`: one instruction, `RegisterForModEvent(self, "LRG_Boot", "OnLrgBoot")`. No other glue script has an `OnInit` (`LRG_PlayerAlias`, `LRG_MCM`; the three Hidden scripts are Global-only). The sources say the same. |
| `LogC` | instructions 0-9 are only `strcat/assign/cmp_eq/not/jmpf/jmp`. **Instruction 10 is `Debug.Trace`**, the first call of any kind. No `GetDisplayName` anywhere in `LogC`; the name comes from `playerName` (default `"Player"`). |
| `LRG.GetMain` | `callstatic Game.GetFormFromFile 2050 "LoreRimGlue.esp"` (2050 = 0x802) |
| does `LRG_Main` call a module before it confirmed? | The only casts to a module type are in `GetOStim/GetDialogue/GetProbe` (gated by `ModReady`) and in `ModAttached`, which is a cast only. All 35 module call sites in `LRG_Main` take their object from those gated accessors. `LRG_Main` has no module-typed variable. Boot functions call no module: `BootTick` = `GetPlayer().GetDisplayName`, `GetCurrentCrosshairRef`, `MCM.IsInstalled`, `GetCurrentRealTime`; `BootWatch/BootAsk/BootConfirm/ModPending/RequestTick/Maintenance/OnInit` = time or `ModEvent` natives only. |
| `OnLrgBoot` | `(String asWho, Int aiSess)` in all three modules, matching `PushString + PushInt`. `LRG_Main` has no `OnLrgBoot`. Each module ignores names other than its own, runs `Maintenance()`, sets `booted`, then calls `m.BootConfirm(i, aiSess)`. `LRG_Dialogue` runs `SelfTest()` after confirming. |
| constants | `CurrentVersion 505`, `BOOT_LAST 11`, `BOOT_AGAIN 10`, `BOOT_STALL_SECS 20.0`, `BOOT_EVENT "LRG_Boot"`; `hbOn`/`bootNotify` default True |

**Can the boot queue and the watchdog stall themselves?** Not on a module, and not on an `OnInit`:
* Every `BootTick` arms the next tick before it runs its step. Steps 5-7 only send a mod event.
* Step 8 re-ticks every 0.25 s only while `ModPending(0)`, which is capped at 25 s after the ask.
* Step 10 waits for `bootAt + 25` s and re-registers itself if something wakes it early.
* `BootWatch` reads only `LRG_Main`'s own members and asks for its own deadline tick.
  `RequestTick` keeps the earliest time.
* The one other timer user on the form, `LRG_DlgProbe`'s `RegisterForSingleUpdate(0.5)`, can only make
  a tick earlier, and every early wake re-arms itself.
* No `UnregisterForUpdate` exists anywhere. `UnregisterForAllModEvents` is only in
  `LRG_Main.Maintenance`, before `LRG_Boot` is registered again and before the modules are asked,
  so the modules re-register their own events on each boot.
* The cycle that froze the saves needs an `OnInit` that calls out. No `OnInit` calls anything but a
  native on itself, so that cycle cannot form again.

## 4. Findings (none blocking)

* **R1 - a hang inside LRG_Main's own stack is not watched (leftover risk, noted by the fixer).**
  The watchdog runs inside `LRG_Main`, so it can name a module that hangs but not `LRG_Main` itself.
  Boot step 9 (`FollowerSweep`, which calls Simple Follower Framework's `DialogueFollowerScript`) and
  the follower repair tick call another mod's script from `LRG_Main`'s stack. If that script were
  ever frozen, `LRG_Main` would park the same way. This is not expected. **Symptom:** the 505 line
  and `maintenance done, version 505` appear, but the 25 s line `... boot steps 11 ... modules ...`
  never does. Worth a later pass that sends FollowerSweep through a mod event as well.
* **R2 - old instances might stay bound (not expected).** The fix assumes, as ReSaver's "unattached
  instances" suggest, that the VM leaves script instances of a form that no longer exists unbound.
  If it kept them bound to `FECF6800`, SKSE registrations stored in the save would keep sending
  crosshair, CHIM and key events to the frozen old objects. They would pile up as suspended stacks,
  about 370 per 3 minutes, as in this log. That does **not** affect the new quest, but it would
  bloat saves. **Symptom:** a new `Suspended stack count is over our warning threshold` dump full of
  `(FECF6800) ... (requested call)`. **Remedy:** a ReSaver clean of the old `LRG_*` instances and
  stacks (owner-side, optional).
* **N1 - the first load may boot twice.** `OnInit` (new quest) -> first `OnUpdate` -> `Maintenance`, and
  possibly `OnPlayerLoadGame` -> `Maintenance` as well. That means two corner notes, two `v505 loaded`
  lines and two session numbers. Confirmations from the first session are dropped by session tag and
  the modules are asked again. Harmless.
* **N2 - `booted` is kept in the save.** On later loads the module handlers run with the state from the
  save in the ~2 s before the module's own boot, exactly as they did with `attached` before 505.
  `LRG_Main` still never calls a module before it confirms. This is not a hang risk; the guard only
  fully protects the first boot of a new instance.
* **N3 - hashes differ from the fixer's report.** The compiler writes its string table in a different
  order on every run, so a rebuild is never byte-identical. My `compile.ps1` run replaced the fixer's
  `.pex` (sizes identical; the fixer's copies are in `%TEMP%\lrg_vrf12\fixer_pex\`). The installed
  `.pex` are **my** build of the same sources, and every bytecode check above was run on them. The
  ESP and SEQ hashes match the fixer's (`82a20db3...`, `a881f50a...`).
* The fixer's risk that a wrong mod-event scope would show up only on a second load (fix report
  section 7) is practically nil. SKSE's `RegisterForModEvent` and `UnregisterForAllModEvents` have
  the same scope, so the boot event is delivered under either reading.

## 5. The install

* **Pre-flight:** SkyrimSE not running, MO2 running (pid 41132, since 22 Sep 19:29). No profile file
  touched and no other mod touched.
* **Backup of the installed 504:** `%LOCALAPPDATA%\Temp\lrg_vrf12\backup-504-20260923-010656\`
  (24 files, compared byte for byte with the installed copies before the overwrite; list in
  `backup-504-20260923-010656.sha256`).
* **Copied** (same names, replaced in place, no new files, so MO2 needs no refresh):
  `LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`, `Scripts\LRG*.pex` (10), `Source\Scripts\LRG*.psc` (10),
  `MCM\Config\LoreRimGlue\config.json` + `settings.ini` (unchanged content).
* **SHA-256, source = installed, 24/24:**

```
82a20db39da4dbf4be6118f86dc5bd3d3b0b0dc36e120cba30ba27cd545e5b56  LoreRimGlue.esp
a881f50a2b2b30a3b3afc63f7f420ce480c20f4789aa6097a30d6b2229462c06  Seq/LoreRimGlue.seq
e8e30f14763128ef1c1fed57f7fd6917e4023a481590b654ba5d65ebb683575a  Scripts/LRG.pex
175faa089f6f04e09f13c5a405c629e2dc0c5967d26b416b7f87a1a122b43267  Scripts/LRG_Dialogue.pex
e8d731cf0420ddb9d05d3fdce537b94cf901bb3b8fe8e013de9a2be4fd6f3fa4  Scripts/LRG_DlgProbe.pex
6fa1c07e4a83f5e919898abbb0f068b7c1622011f4629ef6073ff80337a2f00a  Scripts/LRG_DlgUI.pex
b05c3443dd88e4e7f57af71621112a4caf3dd4ae3215d7f90249f5c9c72d11d0  Scripts/LRG_Followers.pex
7a83f39ef8eb6f11501f1b706c6a9073899d8441d6642e73440a23e78456b437  Scripts/LRG_MCM.pex
3d7d6fdd12e6dce014b6450afb6fa11c7a1b040b4c0b09bb41891485daaa70e0  Scripts/LRG_Main.pex
0fc8d26a00fcdc80189c3f8845db5846f0937e96dc5bee21ca87d4949ae73198  Scripts/LRG_OStim.pex
8b413772b7edee9411447d876f31fbf8ae025cbd1a10850ae8101adfdc0b4c75  Scripts/LRG_PlayerAlias.pex
eda4a07f0d318b31cc37e89d5d4cd826259524b36c6bb416e2be875003db298d  Scripts/LRG_Profile.pex
9c789cc6e1728c3de379f03bab803a53f35582d46ee516fa6db74fb608893670  Source/Scripts/LRG.psc
0f9c4710b71ac3c44a0c5cae40f90adb96a9a866dac2d41da8847080cd8f39a6  Source/Scripts/LRG_Dialogue.psc
5ae8cfdbb2d0503273d2db1aa05d49576ce93f1e6451c32fe480d7db9aa2b5a3  Source/Scripts/LRG_DlgProbe.psc
5ca5283b014145619c49874d906fdd3c6501600ecf4cef75b25b04d8b606b7bf  Source/Scripts/LRG_DlgUI.psc
49b968a39c8de763fa8f853ef24b9609a35d4a894b88e31bfdf20943be33b6d5  Source/Scripts/LRG_Followers.psc
35c1eca43a8ea1aa940d4c62eb38982a33a2d43095d883856a43b1ea6b7de288  Source/Scripts/LRG_MCM.psc
6e69bd491c60fb571043a86b3e4a3c1100d1dca1c0b0c44e39adc816a352a895  Source/Scripts/LRG_Main.psc
a61f41f1b75e2c379f2d7cdf49b7b5a46de265632d92826a4a5d530143db8258  Source/Scripts/LRG_OStim.psc
dad24ca7fb9062d037f6c6173cce939c4455a76d20703abc1b65b7faaaad158e  Source/Scripts/LRG_PlayerAlias.psc
889e6e28ed98fb7fc22da3416e5c2b56974905c19bc20cb339f2926a88cc60b9  Source/Scripts/LRG_Profile.psc
3e4b80570c536645a55d70f14115496149ee0100daead9d55960f1c9dc788bcf  MCM/Config/LoreRimGlue/config.json
f41c7e15f1c335e44617a6746fce2a49716b0ca4f4776baf32ed40662beb52e5  MCM/Config/LoreRimGlue/settings.ini
```

* **After the copy:** `esp_dump.py` of the installed ESP matches the build dump line for line
  (`01000802` with the four scripts + alias, `01000801` unchanged, next id 0x803, ESL flag). The
  bytecode checks of section 3 were re-run on the installed `Scripts\` with the same results.
* **Rollback, only if 505 itself makes the load fail:** copy the backup folder back over the mod
  folder. On Save15 that brings back the frozen 504 state, so it is only a way back, not a fix.

## 6. Owner steps

1. **Nothing to do in MO2.** The same file names were replaced in place, the plugin name and load
   order are unchanged, and `LoreRimGlue.esp` stays ticked and ESL-flagged. Start the game through
   SKSE as usual.
2. **Load Save15.** It is fine now: the frozen stacks belong to the old quest id (`...6800`) and
   cannot touch the new one (`...6802`). Save1/Save3 are not needed.
3. **Corner note:** within a few seconds after the loading screen,
   **`LoreRim Glue v505 loaded - session NNNN`**. On this first load you may get it **twice with two
   different numbers**; that is the new quest starting plus the normal load hook, and it is harmless.
   On later loads, once.
4. **Papyrus.0.log - warnings about the OLD form that you should expect and ignore.** Anything that
   names **`(FECF6800)`** is the old, now-deleted quest:
   * `Function LRG_Main..LogC in stack frame 3 in stack 2353 differs from the in-game resource files
     - using version from save`, the same for `LRG_Main..Maintenance` in stack 78350, and now probably
     also for `LRG_Dialogue..SelfTest / ..Maintenance / ..OnInit` in stack 2353 (they changed in 505).
     The two frozen stacks are still inside Save15 and are being loaded back in. They can only touch
     the old objects.
   * Possibly `Unable to bind script LRG_... to ... (FECF6800) ...`, or errors from those old stacks,
     all naming `FECF6800` or `<savegame>` frames. These are the old instances being left behind.
5. **Papyrus.0.log - the lines that prove 505 is alive** (the same in `Logs\Script\User\LoreRimGlue.0.log`):
   * `[LRG] LoreRim Glue v505 loaded - save 505, session NNNN`
   * about a second later `[LRG] maintenance done, version 505, save 505, session NNNN`
   * soon after, `[LRG] dlg self-test ...` (the dialogue module confirmed its boot)
   * **about 25 s after the load: `[LRG] maintenance done, version 505, save 505, session NNNN, boot
     steps 11, aborted 0, modules ok/ok/ok, stalled 0`** - the one line that says everything came up.
   * Then look at a CHIM NPC and talk: the server's `lorerim_glue.log` should show `GAME` lines again
     and `lrg_npcstate` traffic. The first `GAME maintenance done` may be lost to CHIM's load-time
     queue clear; the 25 s one should arrive.
6. **Once it works, make a NEW save** (keep Save15 as a fallback). The old instances stay in the save
   as dead weight. A ReSaver clean-up is optional, and only worth doing if item 4-style `FECF6800`
   stack dumps keep coming back during play (R2).
7. **If it still fails, send back:**
   * `Papyrus.0.log` from that session. **Copy it right after quitting**, because the next launch
     rotates it.
   * `Logs\Script\User\LoreRimGlue.0.log`.
   * The save you loaded, the rough clock time, and whether the corner note appeared (and how many
     times).
   * A screenshot of the console after typing `sqv LRG_MainQuest` (read-only; it shows the live quest's
     form id, which should be `FECF6802`, and its script variables).
   * Any line containing `BOOT STALL` (a module did not come up: named), `BOOT STEP ABORTED` (a boot
     step hit a Papyrus error: named), or any stack dump that mentions **`(FECF6802)`**. A dump that
     mentions the new id is a real failure; `(FECF6800)` is not.
   * If you see `v505 loaded` but never the 25 s line, that points at R1, and the stack dump in the
     log will show where `LRG_Main` is parked.
