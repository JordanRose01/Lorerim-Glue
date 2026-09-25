# Playtest pass 2 - game side (Papyrus)

Files changed: `glue/game/LoreRimGlue/Source/Scripts/LRG_Main.psc`, `LRG_OStim.psc`, `MCM/Config/LoreRimGlue/config.json`, `settings.ini`. `compile.ps1` untouched (OActor was already in the API list). Compile: **OK**, all 6 .pex rebuilt. Nothing tested in game.

## 1. The crash

Evidence
- Upstream `GameActor::unequipWeaponry` (OStimNG main): reads `GetEquippedObject(false)` (right), `GetEquippedObject(true)` (left), `GetCurrentAmmo()` and, for each non-null one, calls the game's Papyrus native `UnequipItem(nullptr /*vm*/, 0 /*stack*/, actor, item, false, false)` directly through a `REL::Relocation` (ID 54774 on AE). So it touches exactly: right hand, left hand, ammo. No spells/shield special-casing - whatever form sits in a hand slot is passed.
- Crash log: fault at `papyrus::Actor::UnequipItem+0x4F`, `mov r10,[rdi]` with RDI=0x1, RSI = Lisette. Grepping the log for TESObjectWEAP / TESAmmo / TESObjectLIGH / SpellItem / shield found **no equipped-object form in registers or stack**, so the log does not tell which of the three items it was. R14/RAX is an unidentified `void*`.
- Important: OStim passes a NULL VM into a native that uses the VM for its error paths, and the call only happens when the actor HAS something in a hand or ammo equipped. An actor with empty hands and no ammo never reaches that native.
- OStim's installed scripts expose no per-thread/builder option to skip weapon removal (OThreadBuilder has only NoUndressing/UndressActors/NoFurniture...). The global OStim setting was not touched.
- One web search found no public report of this exact stack.

Workaround implemented (`LRG_OStim.CmdStart` -> `PrepareWeapons`, only when the glue starts the thread)
- `starting=true` is taken first (blocks a second start), then for player and NPC: sheathe if drawn (wait up to 3 s), unequip right hand, then re-read and unequip left hand (a two-hander is gone by then, so it is not stored twice), then ammo via `PO3_SKSEFunctions.GetEquippedAmmo`. Spells -> `UnequipSpell(spell, hand)`, weapons -> `UnequipItemEx(form, 1|2)`, anything else (shield, torch, scroll) -> `UnequipItem(form,false,true)`. All None-guarded.
- Verification loop (up to 4 x 0.3 s): both hands and ammo must read None, retrying with plain `UnequipItem`. If still not clean and MCM `bRefuseIfArmed` (new, default ON) the start is refused with "the weapons could not be put away" instead of risking the crash.
- What was found is logged (`prep <name> R=<formid>/t<type> L=.. ammo=..`) - with the new server-side log this will finally tell us WHAT Lisette was holding.
- Re-equip (`RestoreWeapons`): 1.5 s after `ostim_thread_end`, on the start-timeout path, on every early failure, and in Maintenance if a save was made in between. Items are only re-equipped if still owned.

Confidence: **moderate (roughly 70%)**. It is certain that OStim's native call is skipped when hands+ammo are empty, and that is the only call in the crashing frame. Residual risks: (a) if the actor's equip data itself is corrupt (the bogus value 1 coming out of the game's own equipped-object slot), SKSE's `GetEquippedObject` could trip over the same value - then the crash would move into our preparation step, which is at least diagnostic; (b) an NPC's AI could re-equip between our check and OStim's constructor (window well under a second).

If it still crashes, the owner should: 1) start the same pair (player + Lisette) from OStim's own menu/hotkey - if that crashes too, it is OStim + this NPC/modlist, not the glue; 2) try a different NPC through the glue; 3) look at the `GAME prep ...` lines in lorerim_glue.log for the form id Lisette held (type 41 weapon, 42 ammo, 26 armour/shield, 31 light, 22 spell) and try again after taking that item away from her; 4) as a last resort switch off OStim's own "remove weapons" option in OStim's MCM (owner's decision; the glue never writes OStim settings).

## 2. Game logs without Papyrus logging (protocol C)
`LRG_Main.LogC(cid, msg, npcName)`; `Log(msg)` forwards to it. With bDebugLog on: TraceUser as before AND `logMessageForActor("cid=<cid>;msg=<text>", "lrg_log", npcOrPlayerName)`. `;` `|` `@` are replaced in msg, msg capped at 300 chars, nothing is sent when the master switch/kill switch is off. `ReportResult` now logs every command result too. No logging inside loops. cid may be empty (`cid=;msg=...`).

## 3. Snapshot freshness
Design: `RegisterForSingleUpdate` is per form and both scripts sit on one quest, so **LRG_Main owns the only OnUpdate**. `LRG_Main.RequestTick(delay)` keeps the earliest wanted time; `OnUpdate` calls `LRG_OStim.Tick()` (the old OnUpdate body + scene lead) and `SnapTick()`. LRG_OStim no longer has OnUpdate or registers updates.
- Watch target = the agent that last got a snapshot via crosshair / CHIM_SpeechStarted / CHIM_TextReceived. Refreshed every 20 s while the crosshair is still on it OR a line was exchanged in the last 90 s, and it is alive, loaded and within 1500 units. Otherwise the loop ends by itself.
- `CHIM_TextReceived(npcname, text)` (verified in AIAgentAIMind.psc) triggers a throttled snapshot (existing 10 s per-NPC throttle).

## 4. Clothing (protocol A)
`LRG_OStim.CmdClothing`, dispatched from `HandleCommand` for `ExtCmdLRG_Clothing`. Param `ok=1;cid;do=undress|dress;who=npc|both`.
- In a scene containing that NPC and the player: `OActor.Undress/Redress` (natives verified in installed OActor.psc).
- Outside a scene: undress re-runs the shared gates (`PairBlockedReason` + `PrivacyBlockedReason`, factored out of StartBlockedReason; StartBlockedReason keeps its order). **maxwit / folok are read if present; absent = strictest (0 witnesses, companions block).** Slots body/feet/hands/head/hair via GetWornForm, remembered in script arrays (one NPC + player). "dress" re-equips; always allowed.
- Remembered clothes are put back 1.5 s after a scene ends, and when the snapshot watch ends (player walked away) if no scene is running.
- Adults-only and kill-switch checks apply in both cases; dry-run only logs. Refused while another pair's scene runs or a start is in flight.

## 5. Positions (protocol B)
`goto`: target must have exactly the thread's actor count (2) and not be a transition; reach default 5. Routable -> NavigateTo as before. Not routable -> `OThread.WarpTo(0, target, true)` only if `warp=1` AND MCM `bAllowWarp` (new, default ON); else declined as before. Not checked: furniture type of the warp target (no verified native) - server should only send furniture-less targets when `furn` in lrg_scene is empty/none.

## 6. Scene lead (protocol D)
`Tick()` polls every 5 s while a scene with a partner runs. Fires `requestMessageForActor("lead","lrg_scenetalk",partner)` when `now-lastActivity >= fSceneLeadInterval` (new slider 20-180, default 45), bSceneTalk on, intimacy enabled, no navigation in flight, not a transition, NPC not talking. lastActivity resets on scene/speed change, climax, any command, thread start/adopt, and CHIM_SpeechStarted / CHIM_TextReceived for the partner.

## 7. MCM
settings.ini: iExplicitness=2, iNavigationReach=5, bAllowWarp=1, bRefuseIfArmed=1, fSceneLeadInterval=45. config.json: the three new controls + updated help for the debug log and reach. Script fallback for `x=` is now 2. Note: a user who already changed settings has `MCM\Settings\LoreRimGlue.ini` in Overwrite, which overrides these defaults.

## Protocol E
`lrg_scene` carries `und=<0|1>` (best effort: start with undress=1, or ChangeClothing).
