# Playtest 5 - `ThreadActor::removeWeapons` / `unequipWeaponry`: complete call-site map and the fix

Upstream read: `VersuchDrei/OStimNG`, branch `main`, `skse/src/` - `Core/Thread.cpp`,
`Core/ThreadActor.cpp` + `.h`, `Graph/Node.cpp`, `Graph/Action/Action.cpp`, `GameAPI/GameActor.cpp` +
`.h`, `MCM/MCMTable.cpp` + `.h`, `Papyrus/PapyrusThreadActor.h`.
Installed side: `OStim.esp` GLOB records (read-only dump), `Scripts/Source/OSexIntegrationMain.psc`,
`OSexIntegrationMCM.psc`, `Interface/Translations/OStim_ENGLISH.txt`,
`SKSE/Plugins/OStim/actions/*.json`.

## 1a. Every call site of `removeWeapons()` / `unequipWeaponry()`

`ThreadActor::removeWeapons()` (ThreadActor.cpp:593) is the only caller of
`GameActor::unequipWeaponry()`:

```cpp
void ThreadActor::removeWeapons() {
    if (weaponsRemoved) { return; }
    weaponry = actor.getWeaponry();
    actor.unequipWeaponry();
    weaponsRemoved = true;
}
```

`weaponsRemoved` makes it a once-per-actor call until `addWeapons()` resets the flag, so a scene can
reach the native once per actor.

| # | Call site | Exact guard | Frequency |
|---|-----------|-------------|-----------|
| 1 | `ThreadActor` constructor, ThreadActor.cpp:57 | `if (MCM::MCMTable::removeWeaponsAtStart())` -> global **0xDAB** | once per actor, ~1 s after `OThreadBuilder.Start` (crashes 1-3) |
| 2 | `Thread::ChangeNode`, Thread.cpp:266-269 | `position < node->actors.size() && !node->actors[position].noStrip` **and** `MCM::MCMTable::undressMidScene()` (**0xDAC**) **and** `node->doFullStrip(position)` -> `actor.undress(); actor.removeWeapons();` | **every node change** (crash 4) |
| 3 | `Thread::ChangeNode`, Thread.cpp:270-277 | same `noStrip` check, else-branch: `MCM::MCMTable::partialUndressing()` (**0xDAD**) **and** `slotMask = node->getStrippingMask(position) != 0` **and** `(slotMask & MCMTable::removeWeaponsWithSlot()) != 0`, where `removeWeaponsWithSlot() = 1 << (settings[0xDAE] - 30)` (installed value 32 -> bit for slot 32 = **body**, i.e. almost every stripping node) | every node change |
| 4 | `Thread::ProcessEvent`, Thread.cpp:869 | animation annotation `OStimRemoveWeapons` - **no setting gates this** | only if an animation carries that annotation |
| 5 | Papyrus `OActor.RemoveWeapons` (PapyrusThreadActor.h:192) | none - whatever calls it | only if another mod calls it |

Two things that are *not* guards, and that misled us:

* `ThreadFlag::NO_UNDRESSING` (what `OThreadBuilder.NoUndressing` sets) is checked **inside**
  `ThreadActor::undress()` / `undressPartialInternal()` only. In call site 2 the flag makes
  `undress()` return immediately and `removeWeapons()` on the very next line still runs. The glue
  sets that flag on most scenes, so it was *always* the weapon call that crashed, never the
  undressing.
* `OStimUsePapyrusUndressing` (0xDB0, `OStim.log`: "papyrus undressing is disabled") only redirects
  the *clothes* to `OUndress.psc`. Weapons never go through Papyrus.

Call site 4 was checked against the installed content: `grep -rl "OStimRemoveWeapons"` over
*OStim Standalone*, *OStim Community Resource*, *Open Animations Romance and Erotica* and
*Nemesis Output - OStim* finds the string only in `OSexIntegrationMain.pex/.psc` (the global's name),
`OStim.esp`, `Nemesis_Engine/mod/ostim/0_master/#0106.txt` and
`SKSE/Plugins/OStim/list of annotations.txt` - in **no `.hkx`**. The same grep does find
`OStimUndress`/`OStimClimax` inside dozens of `.hkx` files, so the method works. With the installed
animations, call site 4 is unreachable.

## 1b. What `unequipWeaponry` actually does, and why it fires on empty hands

```cpp
void GameActor::unequipWeaponry() const {
    RE::TESForm* rightHand = form->GetEquippedObject(false);
    RE::TESForm* leftHand  = form->GetEquippedObject(true);
    RE::TESAmmo* ammo      = form->GetCurrentAmmo();
    if (rightHand) { UnequipItem(nullptr, 0, form, rightHand, false, false); }
    if (leftHand)  { UnequipItem(nullptr, 0, form, leftHand,  false, false); }
    if (ammo)      { UnequipItem(nullptr, 0, form, ammo,      false, false); }
}
```

`UnequipItem` is the Papyrus native `Actor.UnequipItem` reached through
`REL::Relocation{RELOCATION_ID(53950, 54774)}` (GameActor.h:114) with `vm = nullptr` and
`stackID = 0` - the crash log's `papyrus::Actor::UnequipItem`.

Facts:

* It does **not** use a list cached in the constructor: `weaponry` is only *written* by
  `removeWeapons`. Each call re-reads the actor.
* It reads the engine's equipped-object cache (`Actor::GetEquippedObject` ->
  `currentProcess->GetEquippedRightHand()/LeftHand`, plus `GetCurrentAmmo()`), which is **not** the
  inventory and **not** what `Actor.GetEquippedWeapon()`/`GetEquippedShield()` report; it also
  returns non-weapon forms (spells, torches, lights, staffs) that Papyrus hand checks skip.
* Nothing in it is null-safe beyond `if (ptr)` - a stale or non-bound-object pointer in that cache is
  handed straight to the native, which is consistent with the log (`rdi=0x1`, `r10=0`: not a real
  form pointer).

What I can prove: the native call only happens when that cache is non-empty, and the glue's Papyrus
checks (`GetEquippedObject(0/1)`, `GetEquippedAmmo`) can read empty while it is not. What I cannot
prove from source: which of the three arguments was the bad one for Lisette, because the cache
contents are runtime state. The glue's own log line
`prep Lisette rounds=3 R=80254/t41 L=None ammo=None empty=1` says the glue *did* take a weapon
(type 41 = WEAP) off her and then saw three clean rounds - so Papyrus-visible hands were empty and
OStim still found something. This is why "empty the hands first" is not a fix and the call itself has
to be made unreachable.

Note that the glue's own `Actor.UnequipItem` calls on the same NPC (same native, same actor) never
crashed - so the native and the actor are fine; the argument OStim passes is not.

## 1c. Scene end

* `ThreadActor::addWeapons()` (ThreadActor.cpp:639) and the `freeFast()` path (line 461) both start
  with `if (!weaponsRemoved) return;` / `if (weaponsRemoved)`. If `removeWeapons` never runs,
  `weaponsRemoved` stays `false` and `equipWeaponry` (`equipItemEx` -> `EquipItem` native) is never
  called. **Blocking the removal makes the end of the scene safe by construction.**
* The one remaining end-of-scene path is `free()` with `animateRedress()` on (0xDAF, "Use animated
  Redress", installed default 0): it hands `{weaponry.rightHand, weaponry.leftHand, weaponry.ammo}`
  to Papyrus `OUndress.AnimateRedress`. With nothing removed those three are `None`, and Papyrus
  `EquipItem(None)` is a logged error, not a crash.

## 2. Gates mapped to the installed `OStim.esp`

GLOB records dumped read-only from
`F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework\OStim.esp`
(137 GLOBs; values below are the *plugin defaults*, the owner's saved values live in the save):

| Form id | EditorID | MCMTable getter | OStim MCM (page **Undressing**) | Default |
|---------|----------|-----------------|--------------------------------|---------|
| 0x000DAA | `OStimUndressAtStart` | `undressAtStart()` | "Fully undress at Start" | 0 |
| 0x000DAB | `OStimRemoveWeaponsAtStart` | `removeWeaponsAtStart()` | "Remove Weapons at Start" | **1** |
| 0x000DAC | `OStimUndressMidScene` | `undressMidScene()` | "Fully undress mid Scene" | **1** |
| 0x000DAD | `OStimPartialUndressing` | `partialUndressing()` | "Partial Undressing" | **1** |
| 0x000DAE | `OStimRemoveWeaponsWithSlot` | `removeWeaponsWithSlot()` | "Remove Weapon with Slot" | 32 |
| 0x000DAF | `OStimAnimateRedress` | `animateRedress()` | "Use animated Redress" | 0 |
| 0x000DB0 | `OStimUsePapyrusUndressing` | `Util::Globals::usePapyrusUndressing()` | (debug) | 0 |

The names match `OSexIntegrationMain.psc` properties (`OStimUndressMidScene`,
`OStimPartialUndressing`, ...) and the English MCM strings above come from
`Interface/Translations/OStim_ENGLISH.txt`.

## 3. What the glue now does

All of it sits behind the existing master switch **`bFixOStimWeaponCrash:Intimacy`** (default on).
In `LRG_OStim.psc`:

* `HoldOStimGates(cid)` - called in `CmdStart` right after the 0xDAB handling and before
  `OThreadBuilder.Start`: remembers 0xDAC and 0xDAD, sets `gateHeld = true` *before* writing, then
  sets both to 0. Logs `OStim mid-scene undressing paused: UndressMidScene 1->0, PartialUndressing 1->0 ...`.
* `ReleaseOStimGates(why)` - writes the remembered values back. Called from the top of
  `RestoreWeapons()` (which every exit path already goes through: refused start, OStim refusal,
  builder failure, immediate `Start` failure, the 20 s start timeout in `Tick`, `FinishThread`, and
  `Maintenance` after a game load with no scene running), and once more at the top of
  `FinishThread`, and in `Maintenance` when the running thread after a load is not ours. It ignores
  the MCM switch on purpose - anything the glue lowered comes back up even if the owner flips the
  switch mid-scene. 0xDAB is deliberately *not* restored (documented in the function and in the MCM
  help text): it crashes at every start.
* `GlueUndressNode(sceneId, why)` - the replacement for OStim's mid-scene undressing, called from
  `OnOStimThreadStart` (first node) and `OnOStimSceneChanged` (later nodes, transitions skipped).
  For each thread position it asks `OMetadata.FindAnyActionForActorCSV` /
  `FindAnyActionForTargetCSV` with the action types whose role carries `"fullStrip": true` in the
  installed `SKSE\Plugins\OStim\actions\*.json` (16 actor-side, 35 target-side; lists in
  `FullStripActor()` / `FullStripTarget()`), which is exactly `Node::doFullStrip(position)`
  (`Graph/Node.cpp:100` -> `Action::doFullStrip` -> `roles.get(role)->fullStrip`). A match undresses
  that actor through `SceneUndress(actor, "all")` -> `OActor.Undress` - the same path `CmdClothing`
  uses, so OStim owns the clothes and redresses them at the end. One bit per position
  (`glueStripped`) keeps it to once per actor per scene.
* Owner preferences respected: nothing is undressed when the thread was built with
  `NoUndressing` (`startNoUndress`, i.e. the server did not ask for undressing - OStim would not
  have undressed either), when the remembered 0xDAC was already 0 ("Fully undress mid Scene" off in
  OStim's MCM), or when the new MCM toggle `bGlueUndressMidScene:Intimacy` is off.
* Not copied: OStim's **partial** stripping (call site 3). Papyrus cannot read a node's stripping
  mask (`OMetadata` exposes no equivalent of `getStrippingMask`), and that path was the second way
  into the crashing native. Effect: in a scene the glue starts, a node that would only have pulled
  the body slot aside now leaves the clothes on unless the node also full-strips. The spoken
  "undress"/"take it off" verbs are unaffected.

Inventory removal (taking the dagger out of the NPC and giving it back) was **rejected**: the native
is fed from the engine's equipped-object cache, not from the inventory, so an empty inventory does
not guarantee `GetEquippedObject()` returns null - it would add save-game risk (items in limbo on a
CTD) without closing the hole.

MCM (`MCM\Config\LoreRimGlue\config.json` + `settings.ini`):

* `bFixOStimWeaponCrash:Intimacy` - help text rewritten: it now describes all three OStim options.
* **new** `bGlueUndressMidScene:Intimacy`, "Undress you both during a scene", default **1**, with the
  matching default line in `settings.ini` (a missing key would read as *false* through MCM Helper).

`compile.ps1` printed `OK - ... 0 errors, 0 warnings`. `install_mo2.ps1` refused: **Mod Organizer is
running** - not worked around; the owner runs it after closing MO2/Skyrim.

## 4. Residual risk / confidence

* **High confidence** that no code path in OStim 7.5.1 can reach `unequipWeaponry` during a
  glue-started scene while 0xDAB/0xDAC/0xDAD are 0 and no installed animation carries the
  `OStimRemoveWeapons` annotation (call sites 1-4 all covered; call site 5 needs a third-party mod).
* **High confidence** the end of the scene cannot call `equipWeaponry` (`weaponsRemoved` stays false).
* **Medium confidence** on the exact DLL-level cause of the bad pointer - unproven, and it does not
  change the fix.
* Scenes started from **OStim's own menu** (not by the glue) are *not* covered: the gates are only
  lowered around a glue start. The owner has to set the three options by hand in OStim's MCM for
  those - see `PLAYTEST4_NOTES.md`.
* Version-bound: the 0xDAA-0xDAF ids and the `fullStrip` action lists come from the installed 7.5.1.
  An OStim update or an action addon can add actions the list does not know (they would simply not be
  undressed for) - the crash guard itself does not depend on the lists.
