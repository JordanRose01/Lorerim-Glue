# Independent verification: crash 4 (mid-scene removeWeapons) fix — 2026-09-21

Verifier: read-only pass. Everything below was re-derived from primary sources (upstream OStimNG
source pulled fresh, installed OStim.esp parsed byte-wise, installed animation/action/scene files
scanned, the glue's own .psc read line by line, compile.ps1 run). No fixer notes or research reports
were consulted.

**Verdict: SHIP** (four minor defects, none of them a crash path; fixes listed).

---

## 1. Is the caller list complete, and is each caller gated as claimed?

Source: `https://codeload.github.com/VersuchDrei/OStimNG/zip/refs/heads/main`, extracted to
`C:\Users\Jordan\AppData\Local\Temp\claude\ostimng_repo\OStimNG-main`. Exhaustive grep for
`removeWeapons|unequipWeaponry|addWeapons|equipWeaponry` over the whole `skse` tree.

`ThreadActor::removeWeapons()` (ThreadActor.cpp:593, `weaponry = actor.getWeaponry(); actor.unequipWeaponry();`)
has exactly **five** callers in the plugin — the fixer's list is complete:

| # | Call site | Gate (verified in source) |
|---|-----------|---------------------------|
| 1 | `ThreadActor::ThreadActor` — ThreadActor.cpp:57-58 | `MCM::MCMTable::removeWeaponsAtStart()` = GLOB **0xDAB** |
| 2 | `Thread::ChangeNode` full-strip branch — Thread.cpp:266-269 | `!m_currentNode->actors[position].noStrip` **&&** `undressMidScene()` (**0xDAC**) **&&** `m_currentNode->doFullStrip(position)` |
| 3 | `Thread::ChangeNode` partial branch — Thread.cpp:270-276 | `!noStrip` **&&** `partialUndressing()` (**0xDAD**) **&&** `slotMask != 0` **&&** `slotMask & removeWeaponsWithSlot()` where `removeWeaponsWithSlot() = 1 << (0xDAE - 30)`, MCMTable.cpp:32-35; installed 0xDAE = 32 → bit 4 = body slot |
| 4 | animation annotation `OStimRemoveWeapons` — Thread.cpp:869-870 | **ungated** |
| 5 | Papyrus `OActor.RemoveWeapons` — PapyrusThreadActor.h:192-197 | **ungated** |

Confirmed as well:

* **The NO_UNDRESSING gap is real.** `ThreadFlag::NO_UNDRESSING` (ThreadFlag.h:10, set by
  `PapyrusThreadBuilder.h:136-142` = `OThreadBuilder.NoUndressing`) is tested in exactly two places:
  `ThreadActor::undress()` (ThreadActor.cpp:547) and `ThreadActor::undressPartialInternal()`
  (ThreadActor.cpp:93). `Thread.cpp:268 actor.undress(); 269 actor.removeWeapons();` — the flag stops
  line 268 and line 269 still runs. The glue's "no undressing" never protected anything. Confirmed.
* **`unequipWeaponry` re-reads the engine cache, it does not use a stored list.** GameActor.cpp:208-222:
  `form->GetEquippedObject(false/true)` and `form->GetCurrentAmmo()`, then `UnequipItem(...)` per hit,
  with no validation. Papyrus-visible empty hands therefore do not protect the call. Confirmed.
* **Scene end is safe with removal blocked.** `addWeapons()` early-returns on `!weaponsRemoved`
  (ThreadActor.cpp:639-642); `freeFast()`'s synchronous re-equip block is inside `if (weaponsRemoved)`
  (ThreadActor.cpp:460-464); `free()` reaches `addWeapons()` only in the non-animate branch
  (ThreadActor.cpp:368). The `animateRedress` branch (ThreadActor.cpp:337-349) passes
  `{weaponry.rightHand, weaponry.leftHand, weaponry.ammo}` to `OUndress.AnimateRedress`; those are
  `nullptr` by default (GameWeaponry.h:5-7) → Papyrus None, i.e. a script error, not a crash. Confirmed.
* **No other path reaches the native in a glue scene.** `undressAtStart()` (0xDAA) leads to `undress()`
  only (ThreadActor.cpp:73-75), never to weapons. Adding an actor mid-scene constructs a new ThreadActor
  → caller 1 → still gated by 0xDAB (which the glue leaves at 0). Furniture change, auto mode, redress,
  scene end: no `removeWeapons` call anywhere in them (grep is exhaustive).
* **Version drift check:** installed OStim is 7.5.1 (`meta.ini version=7.5.1.0`), the source read is
  `main`. The crash-log line numbers line up with this source (`GameActor.cpp:222` = end of
  `unequipWeaponry`, `ThreadActor.cpp:600` = inside `removeWeapons`, `Thread.cpp:270` = the ChangeNode
  branch), so the source matches the installed binary closely enough to rely on.

### The two ungated callers, against installed content

* **Annotation (caller 4).** Binary-safe grep (`grep -a`) over every installed `.hkx`:
  * OStim Standalone (974 hkx): `OStimRemoveWeapons` **0**, `OStimUndress` 6, `OStimClimax` 1
  * OStim Community Resource (92): 0 / 5 / 0
  * OARE (1571): 0 / 7 / 1
  * LoreRim - Nemesis Output (64): `OStimRemoveWeapons` **1 file** — `meshes/actors/character/behaviors/0_master.hkx`
  The one hit is the **behaviour graph**, where Nemesis registers the event *name*; no animation clip
  carries the annotation. Method validated by the `OStimUndress`/`OStimClimax` hits in real clips.
  Conclusion holds; the phrasing "in no .hkx" does not (see defect 3).
* **`OActor.RemoveWeapons` (caller 5).** grep for `RemoveWeapons` over **every** `.pex` in
  `F:\Modlists\LoreRim\mods` returns only `OActor.pex`, `OSexIntegrationMain.pex`,
  `OSexIntegrationMCM.pex` (OStim's own, declaration/MCM only) and `LoreRim Glue\Scripts\LRG_OStim.pex`
  (log text — the glue never calls it). No third-party caller is installed.

## 2. The GLOB ids in the installed OStim.esp, and live reads

Parsed `F:\Modlists\LoreRim\mods\OStim Standalone - ...\OStim.esp` directly (read-only python, GRUP
walk): **137 GLOB records**. All seven ids used by the code exist with the expected EditorIDs:

```
0x000DAA OStimUndressAtStart          short  0.0
0x000DAB OStimRemoveWeaponsAtStart     short  1.0
0x000DAC OStimUndressMidScene          short  1.0
0x000DAD OStimPartialUndressing        short  1.0
0x000DAE OStimRemoveWeaponsWithSlot    long  32.0
0x000DAF OStimAnimateRedress           short  0.0
0x000DB0 OStimUsePapyrusUndressing     short  0.0
```

(Those are the plugin defaults; runtime values come from the save.) They match `MCM/MCMTable.h:99-104`
and `MCMTable.h:253-258` one for one, and `OSexIntegrationMain.psc:991`/`1033` binds the same globals
to the MCM properties.

**Reads are live, not cached.** `MCMSetting::asBool()` is `return globalVariable->value != 0`
(MCM/MCMSetting.cpp) against a `RE::TESGlobal*` resolved once at setup. Writing the global from Papyrus
therefore takes effect on the very next `ChangeNode`. The glue's approach is sound.
`OStimUsePapyrusUndressing = 0` also explains the "papyrus undressing is disabled" log line.

MCM display detail in the notes is correct: `OSexIntegrationMCM.psc:2246-2250` sets
`OPTION_FLAG_DISABLED` on *Remove Weapons at Start* while `Main.AlwaysUndressAtAnimStart` is on.

## 3. The Papyrus (LRG_OStim.psc)

* **Hold/release symmetry.** `HoldOStimGates` (line 666) sets `gateHeld = true` *before* the two
  `SetValue(0.0)` calls, remembers `gatePrevMid`/`gatePrevPart`, and no-ops when already held.
  `ReleaseOStimGates` (line 691) is idempotent, ignores the MCM switch, and writes back only when
  `gatePrev* >= 0`. No None dereference anywhere: both globals are null-checked in Hold (line 678)
  and in Release (line 705/708); `GlueUndressNode` null-checks `actors[i]`.
* **Exit paths.** `ReleaseOStimGates` sits at the top of `RestoreWeapons` (line 1000), which is called
  from: refused start (560), no-furniture-no-scene refusal (576), builder refusal (587), Start failure
  (621), start timeout in `Tick` (2211), `FinishThread` (2172) — and `FinishThread` releases explicitly
  first (2158). Plus `Maintenance`: load with no scene (229) and load with a foreign scene running
  (223). Load with **our** scene running deliberately keeps the gates down — correct. All exits covered
  except the timing edge in defect 2.
* **Settings left changed after a mid-scene CTD:** only `OStimRemoveWeaponsAtStart` (0xDAB), which the
  glue sets to 0 on every start and never restores — intentional and documented in the MCM help and the
  notes. `0xDAC`/`0xDAD` survive a CTD only if the owner *saved* during the scene and then loads that
  save, and `Maintenance` puts them back on that load. Nothing else is written.
* **Undressing still happens.** `GlueUndressNode` runs at thread start (2037) and on every non-transition
  scene change (2107), and undresses through `SceneUndress(actor,"all")` → `OActor.Undress` →
  `ThreadActor::undress()`, i.e. OStim owns the clothes and redresses them. The `startNoUndress`
  early-return is correct, not a cop-out: with `NO_UNDRESSING` set, `OActor.Undress` early-returns in
  OStim too (ThreadActor.cpp:547), so OStim would not have undressed anybody either.
* **Parity of the fullStrip test.** `Node::doFullStrip(position)` = any action with a role at that
  position whose role attributes carry `fullStrip` (Action.cpp:7-13, roles = actor/target/performer).
  `OMetadata.FindAnyActionForActor/TargetCSV` (native, present in installed `OMetadata.psc:474/637`)
  map to `Node::findAnyActionForActor/Target`, which match `roles.actor/target == position` with alias
  resolution (Node.cpp:207-221, Action.cpp:23-24). Scanning the installed `actions\*.json`: **no**
  action has `performer.fullStrip`, so actor+target is sufficient. The glue's lists are otherwise
  complete for OStim Standalone but miss OStim Community Resource — defect 1.
* **Partial stripping really cannot be mirrored:** the installed `OMetadata.psc` exposes no stripping /
  slot-mask function at all (grep for `strip` finds nothing). The design note is true.
* **Compile:** `compile.ps1` → `compiled: LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex,
  LRG_PlayerAlias.pex, LRG_Profile.pex` … `OK … 0 errors, 0 warnings`. Confirmed by running it.
* **MCM.** `config.json` parses (PowerShell `ConvertFrom-Json` OK). `bGlueUndressMidScene:Intimacy`
  is present on the Intimacy page with `sourceType: ModSettingBool`, and `settings.ini` carries
  `bGlueUndressMidScene = 1` under `[Intimacy]` — the missing-key-reads-false hazard is closed. There is
  currently **no** `MCM\Settings\LoreRimGlue.ini` anywhere in the MO2 tree (overwrite, profiles, mod
  folder), so the defaults in `settings.ini` will apply on first load of this build.
* **Install state:** not installed, as stated — the live mod still carries the 12:42 build
  (`config.json` there has no `bGlueUndressMidScene`; `LRG_OStim.pex` 73058 bytes vs 80694 built).

## 4. Defects

### Minor 1 — the fullStrip lists cover OStim Standalone only, not OStim Community Resource
`FullStripActor()` (line 720, 16 types) and `FullStripTarget()` (line 725, 35 types) exactly equal the
`fullStrip:true` sets of `OStim Standalone\SKSE\Plugins\OStim\actions\*.json`. The installed
`OStim Community Resource\SKSE\Plugins\OStim\actions` adds 113−86 = 27 more action files, of which
these carry `fullStrip`:
* actor: `3pp_boobjob, 3pp_buttjob, ejaculation_on_butt, ejaculation_on_chest, ejaculation_on_face, ejaculation_on_hands, ejaculation_on_vulva`
* target: `3pp_boobjob, 3pp_buttjob, 3pp_cunnilingus, 3pp_femalemasturbation, 3pp_gropingtesticles, 3pp_kissfellatio1, 3pp_kissfellatio2, 3pp_lickingpenis, 3pp_vaginalfingering, ejaculation_on_butt, ejaculation_on_chest, ejaculation_on_vulva`

So "mirrors OStim's doFullStrip exactly" is not exact. **Practical impact today: none** — I scanned all
607 scene JSONs (384 with actions) of OStim SA + OCR + OARE and no installed scene uses any of those
action types, so the glue misses zero positions in the installed content. It becomes visible only if an
OCR-based scene pack is added. Smallest fix: append the names above to the two CSV strings.

### Minor 2 — the start-timeout path can raise the gates while OStim is still starting
`Tick` (2207-2214) fires 20 s after `OThreadBuilder.Start` and calls `RestoreWeapons()` → 
`ReleaseOStimGates`. If the `ostim_thread_start` event is merely late (long fade, heavy save), the
thread then runs with 0xDAC/0xDAD back at 1 and the crash-4 path is open again for that scene (the
thread is adopted at 2032 with `startedByGlue = false`, so nothing re-lowers them). Smallest fix: wrap
the release in `RestoreWeapons` as `if !OThread.IsRunning(0)` — every other caller of `RestoreWeapons`
already runs only when no thread is running (FinishThread 2171, Maintenance 227) or before one exists
(CmdStart), so the guard costs nothing and closes the hole.

### Minor 3 — two statements in the notes/claims are imprecise
1. "checked … against every installed animation file — none of them carries it" is true for animation
   clips, but the string *is* present in `LoreRim - Nemesis Output\meshes\actors\character\behaviors\0_master.hkx`
   (the behaviour graph, where the event name is registered). Worth one clause so a later reader who
   greps does not think the check was wrong.
2. The claim that `OStimUndress`/`OStimClimax` "appear in dozens of .hkx" overstates: 18 clips carry
   `OStimUndress`, 2 carry `OStimClimax`. The method validation still stands.
Smallest fix: one sentence in PLAYTEST4_NOTES.md ("the event name exists in the behaviour file
0_master.hkx, as it must; no animation carries the annotation").

### Minor 4 — the gates are global, so a parallel NPC-NPC scene loses its mid-scene undressing too
0xDAC/0xDAD are process-wide OStim settings, not per-thread. While a glue scene runs, any *other*
OStim thread (an NPC-NPC scene started by another mod or by OStim's NPC-scene key) also stops undressing
mid-scene — and also stops removing weapons, which is the safer half. The notes only say scenes started
from OStim's own menu "are not protected"; they do not mention this side effect. Smallest fix: one line
in the notes.

## 5. Answers to the four questions

1. **Call sites complete and correctly gated?** Yes — five callers, gates exactly as claimed, and no
   remaining path (node change, undress, redress, scene end, actor added mid-scene, furniture change,
   auto mode) reaches `unequipWeaponry` in a glue-started scene once 0xDAB/0xDAC/0xDAD are 0, except the
   timing edge in defect 2.
2. **GLOB ids / live reads?** All seven exist in the installed OStim.esp with the claimed EditorIDs
   (listed above, dumped independently), and `MCMSetting::asBool()` reads the global live on every call.
3. **Papyrus?** Values remembered once, restored on every exit path (end, lost end event, stop, start
   timeout, refusal, both game-load branches), idempotent, no None dereference, undressing preserved and
   still owned by OStim. The only setting deliberately left changed is 0xDAB (documented). compile.ps1
   prints OK, 0 errors / 0 warnings.
4. **Notes true?** Substantially yes, including the greyed-out *Remove Weapons at Start* detail
   (OSexIntegrationMCM.psc:2246-2250) and the log-line list. Two imprecise sentences — defect 3.

## Verdict

**SHIP.** No defect found re-opens the crash in a glue-started scene under the installed content; the
four items are behavioural/documentation polish plus one narrow timing guard. The build is still
uninstalled (MO2 running) and untested in game — the first playtest should confirm the
`OStim mid-scene undressing paused: …` line at start and the matching `… restored (…)` line at end.
