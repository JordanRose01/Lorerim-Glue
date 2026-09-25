# LoreRim Glue 0.1.1 - notes after playtest 1 (2026-09-21)

## ONE THING YOU MUST DO FIRST
The server half is already deployed. The game half is built but **not yet copied into MO2, because Mod Organizer was running**. Close Skyrim and Mod Organizer, then run this one command, then start MO2 again:

```
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\tools\install_mo2.ps1"
```
Until then the game still runs the old 0.1.0 scripts (and would crash the same way). No Nemesis run, no sorting needed.
If MO2's Overwrite contains `MCM\Settings\LoreRimGlue.ini`, the new defaults do not apply by themselves: in the MCM set Scene talk -> Explicitness = Explicit and Intimacy -> Position change reach = 5.

## What crashed
Everything the glue does worked: snapshot -> server gate -> Lisette agreed -> command reached the game -> OStim logged "starting scene". One second later the game crashed **inside OStim.dll**, in the part that puts away the actors' weapons when a scene thread is built (`unequipWeaponry` -> the game's `UnequipItem`), while it was processing Lisette. OStim handed the game an invalid object pointer (value 0x1). That code only runs when the actor is holding something (right hand, left hand or ammo). The crash log does not say which item it was, and OStim 7.5.1 has no switch to skip that step.

## What the workaround does, and how sure we are
Before the glue asks OStim to start, it now puts away everything both of you hold - weapons, shield, torch, spells, ammunition - using the game's normal script calls, checks that the hands really are empty, and writes what it found into the log. OStim's crashing code then has nothing to do. Afterwards (scene end, failed start, timeout, game load) everything is given back. If the hands cannot be emptied, the scene is refused instead of risking a crash (MCM Intimacy -> "Refuse to start if hands cannot be emptied", on).
Honest confidence: **about 70 %**. It avoids the crashing call; it does not prove why OStim crashed. If Lisette's mod gives her an odd item (a bard instrument, for instance) the log will now show it.

## What to test, in this order
1. **OStim alone, armed.** With Lisette (same pair), both of you with a weapon drawn/equipped, start a scene from **OStim's own key/menu**, not by voice. If this crashes too, the bug is OStim + that NPC, not the glue - tell me, and try once more after unequipping her weapon/instrument by hand.
2. **OStim alone with another NPC** (armed). Tells us whether it is Lisette-specific.
3. **The glue path.** Dry-run OFF, talk to Lisette in private until she agrees. Expect a 1-4 s pause (hands being emptied) and then the scene. Afterwards look in the log for `GAME prep` lines.
4. **Undress by voice, outside a scene:** in private, with an NPC who would also agree to intimacy, ask her to undress. She undresses only if she agrees in her own reply; asked in public or of a near-stranger it is not even offered. Say "get dressed" -> she dresses; walking away also re-dresses her.
5. **In a scene - verbal control:** "faster", "slower", "hold it there", "let's stop" (always ends at once; the stop hotkey too). Ask for a position **in plain words** (the scene notes give her real examples from your installed pack). If there is an animated route it transitions; if not, the screen fades and it jumps there (MCM "Jump to positions without a route").
6. **Progression:** the scene starts gentle (embrace / kiss). Ask straight away for the furthest thing: she should say it is too soon and offer the next step instead. Asking for the *next* step works at once. Left alone, she opens further steps at her own pace (shy/strict NPCs slowly, open ones quickly).
7. **Do nothing for ~45 s:** she should take the lead herself - a line, a new position, a change of pace, or clothes - according to her personality. MCM Scene talk -> "NPC takes the lead after".
8. **Dialogue:** lines in a scene should now be short, plain, physical, and crude where the character would be - no poetry. Outside a scene nothing explicit should ever appear; check an ordinary chat right after a scene.
9. **Long conversation:** `reasons=no_fresh_snapshot` should be gone from the log (snapshots refresh every 20 s and are trusted for 90 s).

## New features and how to trigger them by voice
| You say (roughly) | What happens |
|---|---|
| ask for intimacy, in private, to someone close enough | `BeginIntimacy` - only if all hard gates pass AND she agrees; always starts with a gentle scene |
| "take your clothes off" / "let's both undress" / "get dressed" | `ChangeClothing` - she decides; in a scene always possible, outside only under the same gates as intimacy |
| name a position in ordinary words, or "something else" | `ChangeIntimacy` - server finds the matching installed scene, game navigates or fades+jumps |
| "faster", "slower", "stay like this", "carry on", "stop" | pace / hold / release / stop |
| "you decide" / say nothing | she leads: chooses scenes herself, step by step, from her personality and CHIM bio |
A refusal is final for that turn, "stop" always ends the scene, the kill switch overrides everything, mature content exists only inside the scene context and only for confirmed adults.

## Where the log is
`HerikaServer/log/lorerim_glue.log` (inside the DwemerAI4Skyrim3 distro: `/var/www/html/HerikaServer/log/lorerim_glue.log`). It now also carries the **game side**: lines marked `GAME` (commands received, results, `prep` = what was put away, lead requests, thread end). Same `cid=` on both sides. Item types in `GAME prep` lines: t41 weapon, t42 ammo, t26 armour/shield, t31 torch, t22 spell. First request after this deploy should log `action catalog: rows installed (v6)` and one scene-index rebuild.

## What was checked
Papyrus compile OK (6 scripts); 80/80 offline gate tests; scene-index checks against your real installed packs passed; both sides of the wire protocol compared (command names, parameter keys, message types, lead tick). Two integration fixes: undress-outside-a-scene now carries the same witness limits as BeginIntimacy (it was silently stricter), and a snapshot is forced at every scene start so scenes started from OStim's menu also get scene awareness.

## Not done / not proven
- Nothing new has been tested in game.
- Root cause of the OStim crash is unproven (see above).
- The "she takes the lead" turn relies on a CHIM hook that was verified by reading the server code and offline tests, not live. If she only talks and never acts on those turns, send me the `turn ... LEAD` lines from the log.
- A fade+jump target is not re-checked for furniture in game (the server filters it).
- Gentle wind-down after "stop", beds/furniture starts, cold exemption for Survival Mode: still not built.
