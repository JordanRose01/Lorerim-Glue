# pt3 - the OStim start crash (2026-09-21)

Owner of the fix: `glue\game\LoreRimGlue\Source\Scripts\LRG_OStim.psc` only. No other file touched.
`compile.ps1` prints **OK - 0 errors, 0 warnings**.

## 1. What actually crashed

Call chain from the newest crash log (identical in both crashes):

```
Thread::addActorInner  ->  ThreadActor ctor (ThreadActor.cpp:61)
                       ->  ThreadActor::removeWeapons (ThreadActor.cpp:600)
                       ->  GameActor::unequipWeaponry (GameActor.cpp:222)
                       ->  GameActor::UnequipItem (GameActor.h:118)  -> papyrus::Actor::UnequipItem
EXCEPTION_ACCESS_VIOLATION, mov r10,[rdi], rdi = 0x1, RSI = Character "Lisette" [0x000198A2]
```

Upstream source (read once, OStimNG `main`):

```cpp
// ThreadActor.cpp:61 (constructor)
if (MCM::MCMTable::removeWeaponsAtStart()) { removeWeapons(); }

// ThreadActor.cpp:600
void ThreadActor::removeWeapons() {
    if (weaponsRemoved) return;
    weaponry = actor.getWeaponry();
    actor.unequipWeaponry();
    weaponsRemoved = true;
}

// GameActor.cpp:222
void GameActor::unequipWeaponry() const {
    RE::TESForm* rightHand = form->GetEquippedObject(false);
    RE::TESForm* leftHand  = form->GetEquippedObject(true);
    RE::TESAmmo* ammo      = form->GetCurrentAmmo();
    if (rightHand) UnequipItem(nullptr, 0, form, rightHand, false, false);
    if (leftHand)  UnequipItem(nullptr, 0, form, leftHand,  false, false);
    if (ammo)      UnequipItem(nullptr, 0, form, ammo,      false, false);
}
```

Two facts follow, and the whole fix rests on them:

* every call is **skipped for a hand that is empty** - so an actor holding nothing cannot be crashed here;
* `removeWeapons()` is called **only from the ThreadActor constructor**, and only when the MCM
  switch is on (it is *not* called from undress / undressPartial / mid-scene paths). It runs some
  frames **after** `OThreadBuilder.Start` returns, which is why a check made before `Start` proves nothing.

Why v0.1.1's workaround did not hold: `PrepareWeapons` unequipped with `abPreventEquip = false`
(`UnequipItemEx(form, slot, false)` / `UnequipItem(form, false, true)`), so nothing stopped
Lisette's AI from drawing the dagger again in the ~1 s between "hands read empty" and OStim's
ThreadActor construction. The session's own log shows exactly that:
`prep Lisette R=80254/t41` (Iron Dagger) ... `start requested tid=0` ... crash in `unequipWeaponry`.

## 2. The OStim switch (tell the owner)

* Global `OStimRemoveWeaponsAtStart` = **0xDAB in OStim.esp** (verified by reading the installed
  plugin's GLOB records: `OStimUndressAtStart` 0xDAA, `OStimRemoveWeaponsAtStart` 0xDAB,
  `OStimFinishedFadeToBlack` 0xECB - the last one matches OStim's own `OSKSE.psc`). That is the
  same id `MCM::MCMTable::removeWeaponsAtStart()` reads (`settings[0xDAB]`).
* MCM: **OStim > Undressing page > "Remove Weapons at Start"** (`$ostim_remove_weapons_start`,
  `OSexIntegrationMCM.psc:2250`). **With that toggle OFF, the crashing native is never called.**
* Interlock to know about: the MCM **greys that toggle out while "Fully undress at Start"
  (`$ostim_undress_start`) is ON** (`OSexIntegrationMCM.psc:2246-2250`). Turning full undress back on
  does *not* re-set the weapons value (`State OID_FullyUndressAtStart` only changes the option flag),
  so the order is: full-undress OFF -> Remove Weapons at Start OFF -> full-undress ON again.
* The glue only **reads** it (`OStimWeaponRemoval()`, `Game.GetFormFromFile(0xDAB, "OStim.esp") as
  GlobalVariable`) and logs one line per start: `OStim 'Remove Weapons at Start' is ON/OFF/could not be read`.
  It never writes an OStim setting.

## 3. What the glue does now (v0.2.0)

`LRG_OStim.psc`, weapon section rewritten:

* `ClearHands(actor, idx)` - one pass: right hand, left hand (read after the right so a two-hander
  is already gone), ammo. Weapons go through `UnequipItem(form, prevent, true)` **and**
  `UnequipItemEx(form, slot, prevent)` (the plain call carries the prevent flag, the Ex call catches
  the copy in that specific hand when the same weapon is held in both); shield / torch / scroll
  through `UnequipItem(form, prevent, true)`; spells through `UnequipSpell` (no prevent flag exists).
  Verified signatures in the installed `Actor.psc`:
  `UnequipItem(Form, bool abPreventEquip = false, bool abSilent = false)`,
  `UnequipItemEx(Form, int equipSlot = 0, bool preventEquip = false)`,
  `EquipItemEx(Form, int equipSlot = 0, bool preventUnequip = false, bool equipSound = true)`.
* `abPreventEquip = true` **only for an item the glue could remember**, so a blocked item is always
  given back (`EquipItem` is the only thing that lifts the flag). New member `prepExtra` (4 slots per
  actor) holds second weapons the actor pulled out later; if it is full the item is unequipped
  *without* the flag rather than left blocked forever.
* `PrepareWeapons(actor, idx)` - sheathe, one `ClearHands`, plus a `GetEquippedShield()` sweep.
* `SettleWeapons(player, npc)` - loop, 0.25 s per round: re-read both actors, take off whatever
  reappeared, require **3 consecutive clean rounds** for BOTH actors, give up after 16 rounds (~4 s).
  Logs one line per actor: `prep <name> rounds=<n> R=.. L=.. ammo=.. again=.. empty=0|1`.
  If it never settles and MCM `bRefuseIfArmed:Intimacy` is on, the start is refused with
  "the weapons could not be put away" (no crash beats a scene); with it off it starts and logs a WARNING.
* `GuardStart(player, npc)` - runs **after** `OThreadBuilder.Start` for up to 2.5 s or until
  `ostim_thread_start` arrives, repeating `ClearHands` every 0.25 s. This is the window in which
  OStim constructs its ThreadActors, i.e. the window in which the game died. Logs if anything had
  to be taken off again.
* `RestoreWeapons()` re-equips right hand / left hand / ammo / extras with
  `EquipItem(form, false, true)` (or `EquipItemEx(form, slot, false, false)` for weapons,
  `EquipSpell` for spells) and skips anything no longer in the inventory. Call sites checked -
  all paths after a preparation are covered: refused start, "no furniture and no scene",
  `OThreadBuilder.Create` refusal, immediate `Start` failure, `Tick` start timeout,
  `FinishThread` (thread end / end event lost / stop), and `Maintenance` on game load when no
  thread is running. `CmdStart` now also calls `RestoreWeapons()` *before* preparing, so nothing
  from an earlier scene can stay blocked.
* No invented native is used to stop an NPC drawing: the prevent-equip flag plus the settle/guard
  loops are the mechanism. Nothing in SKSE / PO3 / OStim on this machine offers a cheaper verified
  "do not draw" switch (`SetRestrained` / `SetDontMove` would fight OStim's own actor handling).

## 4. Scenes started from OStim's own menu

The glue cannot protect those: OStim builds the thread itself, the glue only learns about it in
`ostim_thread_start` (`AdoptRunningThread`), long after the ThreadActor constructor ran. The only
cover for a player-started scene is the MCM switch in section 2.

## 5. Confidence, and what to try next if it still crashes

Confidence that the crash is *this* code path and that an empty pair of hands avoids it: **high**
(the call chain is unambiguous and every unequip in `unequipWeaponry` is guarded by a non-null hand).
Confidence that the glue now reliably keeps the hands empty through the async window: **medium-high**
- the prevent-equip flag is the long-established way to stop an NPC re-arming (and the settle +
guard loops catch anything it misses), but the exact reason the native died on an ordinary Iron
Dagger is not proven (rdi = 0x1 looks like a broken inventory/extra-data pointer on that actor,
i.e. possibly a bad `FDE Lisette.esp` inventory entry or an equip still in flight). If the game
still crashes:

1. Turn **OStim > Undressing > "Remove Weapons at Start" OFF** (section 2) - that removes the code
   path completely, for glue-started *and* menu-started scenes. This is the complete fix and costs
   nothing but weapons staying in hand for scenes the glue does not prepare.
2. Look at the new log lines for the crashing session: `prep ... empty=0`, `again=...` or
   `guard: N item(s)` mean the hands were still being refilled; `empty=1` with no guard line means
   the actor was demonstrably empty and the crash is *not* about re-arming - then it is an OStim /
   actor-data bug and step 1 is the answer.
3. Try a different NPC (not Lisette): if only she crashes, suspect her `FDE Lisette.esp` /
   USSEP-patched inventory rather than the glue.
