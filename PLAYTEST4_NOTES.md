# Playtest 4 notes - LoreRim Glue v0.2.0 (21 Sep 2026)

Two things came out of playtest 3: the game crashed when the scene started, and the NPC stripped
down to a bra and panties instead of being nude. They have completely different causes.

**Status of this build:** the scripts compiled clean (0 errors, 0 warnings) and the mod is installed
into MO2 (`F:\Modlists\LoreRim\mods\LoreRim Glue`, profile files backed up as
`*.bak-20260921-112611`). Nothing in LoreRim, CHIM or OStim was modified. The server side was not
touched, so nothing needs redeploying.

---

## 1. The crash

### What really happened

The glue was not the thing that crashed. OStim was.

When a scene starts, OStim builds an internal "actor" for each participant, and the first thing it
does there is take the weapons off both of you - it calls the game's own unequip function on the
right hand, the left hand and the ammo. That call is what died.

Two details made this look confusing in playtest 3:

* OStim does that **about a second after** the glue asks for the scene, not during the request. So
  the check v0.1.1 did just before asking ("both hands are empty") proved nothing - it was true at
  the time and no longer true a second later.
* The glue took her Iron Dagger off **without blocking her from taking it back out**. Lisette's AI
  re-drew it inside that one-second window, so OStim found a weapon after all and tripped over it.

OStim skips any hand it finds empty, so an actor who is genuinely holding nothing never reaches the
crashing code.

### The one setting that closes it completely

**MCM > OStim > Undressing page > turn OFF "Remove Weapons at Start".**

That toggle is the only gate on the crashing call. With it off, OStim never runs it at all - not for
glue scenes and not for scenes you start yourself from OStim's own menu (which the glue cannot
protect, because OStim builds those threads itself).

There is an interlock on that page: **"Remove Weapons at Start" is greyed out while "Fully undress
at Start" is ON.** So the order is:

1. "Fully undress at Start" -> OFF
2. "Remove Weapons at Start" -> OFF
3. "Fully undress at Start" -> back ON  (this does not switch the weapons option back on)

### What changed in the glue

* Before a scene it now empties **both** hands properly: weapon, shield, torch, scroll, equipped
  spells, and arrows/bolts - and it sets the "don't put this back" flag, so the AI cannot re-draw.
* It only sets that flag on items it has written down, and it gives every one of them back
  afterwards (scene end, refused start, failed start, timeout, stop, and on loading a save). So
  nothing can stay permanently stuck to someone's back.
* **Settle loop:** after the first sweep it checks every 0.25 s and takes off anything that
  reappears (an NPC pulling a second weapon out of her pack). Both of you must read "empty" three
  checks in a row before it asks OStim to start. It gives up after about 4 seconds.
* **Guard loop:** for 2.5 s after the start request - exactly the window in which OStim builds its
  actors - it keeps re-clearing both of you.
* If someone keeps re-arming for the full 4 seconds, the scene is **refused** with "the weapons
  could not be put away" rather than risking the crash. That is the existing MCM switch
  *LoreRim Glue > Intimacy > "Refuse to start if hands cannot be emptied"* (on by default; turn it
  off and it starts anyway with a warning in the log).
* The glue now **reads** OStim's "Remove Weapons at Start" setting and writes one line in the log
  saying whether it is on or off. It never writes OStim settings.

Cost: starting a scene is up to ~4 s slower, and the command holds on for ~2.5 s after the start.
The 20 s start deadline is unchanged.

### Confidence

* That turning the MCM switch off removes the crash: as close to certain as it gets. The crashing
  call has exactly one gate and that is it.
* That the re-drawn dagger was the trigger: fairly confident, not proven. The dagger demonstrably
  came back into her hand inside the window, and the fix removes that. What is *not* proven is why
  an ordinary Iron Dagger killed the call in the first place, so if the log shows both actors truly
  empty and it still crashes, the cause is somewhere else in OStim and the MCM switch is the answer.

### Test it in two steps

**Step 1 - OStim on its own (no glue).** Same NPC, same place, start a scene the way you normally
would through OStim itself. If it crashes there too, the glue was never involved: flip the MCM
switch (above) and re-test.

**Step 2 - through the glue.** Ask her in conversation as before.

Read the log afterwards:

```
wsl -d DwemerAI4Skyrim3 -- tail -n 60 /var/www/html/HerikaServer/log/lorerim_glue.log
```

New lines to look for:

| Line | Meaning |
|---|---|
| `GAME OStim 'Remove Weapons at Start' is OFF: ...` | your MCM change stuck. If it still says **ON**, it did not. |
| `GAME prep player rounds=2 R=none L=none ammo=none empty=1` | you were empty-handed; `rounds` is how long it took |
| `GAME prep Lisette rounds=2 R=... L=... ammo=... empty=1` | what was taken off her; `again=...` lists extra items she reached for |
| `GAME guard: 1 item(s) had to be taken off again while OStim was starting` | she re-armed during the start window and was caught. Only appears if it happened. |
| `GAME start refused reason=the weapons could not be put away` | she kept re-arming for 4 s; nothing was started |

`empty=1` on both lines **and no guard line** means both actors were demonstrably empty. If it
crashes anyway after that, it is not about re-arming - use the MCM switch and tell me.

---

## 2. The underwear

### The cause

Nothing was left on her. The bra and panties **are part of the naked body mesh** that LoreRim ships.

LoreRim's prebuilt female body
(`F:\Modlists\LoreRim\mods\LoreRim - BodySlide Output\meshes\actors\character\character assets\femalebody_0.nif` / `_1.nif`)
was built from a "NeverNude" set - the body file contains the body plus seven underwear pieces
(bra, straps, panty and its cloth flaps), painted with `textures\cptnjtk\dsunderwear\underwear.dds`
from the mod *Dark Souls Undressed - NeverNude*. So when everything is unequipped, what you see is
underwear-shaped geometry with no item behind it.

This is a deliberate LoreRim choice, not a bug and not a glue problem: in the same mod the
first-person body and Astrid's body are fully nude - only the third-person body is NeverNude.

The glue did its job correctly (the log line `GAME undress done who=... part=All inscene=0` is
there, all body slots were cleared), so **no glue code change was needed or made for this**. The
skin texture is already a nude one (BnP - Female Skin), so only the mesh has to be rebuilt.

### The fix

Rebuild the third-person female body from a normal nude CBBE set in BodySlide and let it win over
LoreRim's prebuilt one. Exact steps are in section 3 (4-7). I could not do this for you: running
BodySlide and MO2 is outside what I am allowed to touch here.

### What it means for everyone else

* **It is global.** Every adult female in the game - including your own character - is nude under
  her clothes from then on, everywhere, not just in glue scenes. That is what was asked for, but it
  cannot be limited to the mod.
* **11 named NPCs keep their underwear** unless you also do step 8: Serana, Elisif, Valerica,
  Toccata, Vivace, Chaconne, Minazuki, Succubus-San and the Bijin NPCs / Warmaidens / Wives have
  their own NeverNude body files in their own folders.
* **Males are already fine** - LoreRim never built any male bodies, so there is no painted-on
  underwear on men. But with neither SOS Full nor TNG installed, a stripped man has a smooth,
  featureless crotch. Fixing that means installing a new mod (TNG or SOS Full, on a HIMBO-style
  body); I installed nothing and this is not needed for the glue to work.
* The glue's rails are untouched: adults only (failing closed), the NPC must be willing, stop always
  stops, kill switch still works.

---

## 3. What you have to do by hand

Nothing here needs me. Do them in this order.

1. **In game, MCM > OStim > Undressing:** set "Fully undress at Start" **OFF**, then "Remove Weapons
   at Start" **OFF**, then "Fully undress at Start" **back ON**. Save the game afterwards.
   (This is the crash fix that also covers scenes you start from OStim's own menu.)
2. **Optional, same visit:** MCM > LoreRim Glue > Intimacy - "Refuse to start if hands cannot be
   emptied" should stay **on**. Leave it unless you are deliberately testing.
3. **Playtest the crash** with the two-step test in section 1 before doing anything else. If it is
   fixed, you can stop here and do the body later.
4. **Build a nude body.** Close Skyrim. In MO2, run **BodySlide** from the executables dropdown
   (top right). In the *Outfit/Body* box pick **"CBBE 3BBB Body Amazing"** (from CBBE 3BA (3BBB)),
   preset **"- Zeroed Sliders -"** (or *CBBE Vanilla*), tick **Build Morphs**, then click **Build**.
   Never pick anything called *NeverNude*, *Underwear*, *Amazing Underwear* or *DSU ...*.
   Conservative alternative that keeps exactly the current shape: outfit **"CBBE Body"** from
   Caliente's CBBE.
5. **Collect the three files.** BodySlide writes into MO2's **Overwrite**. Right-click Overwrite ->
   *Open in Explorer* and take only:
   `meshes\actors\character\character assets\femalebody_0.nif`, `femalebody_1.nif`, `femalebody.tri`.
   **Do not** use "Create Mod" on the whole Overwrite - it currently also holds `Scripts`, `skse`,
   `textures`, `ShaderCache`, `KiLoader`, `Root` and `backup`, which must stay where they are.
6. **Make it a mod.** Create `F:\Modlists\LoreRim\mods\LoreRim Glue - Nude Female Body\` and move
   the three files into it keeping the same folder path
   (`...\LoreRim Glue - Nude Female Body\meshes\actors\character\character assets\`).
   Back in MO2 press **F5**, tick the new mod in the left pane, and make sure it sits **below**
   "LoreRim - BodySlide Output" (lower in the left pane = it wins). Let MO2 write the profile itself;
   do not edit `modlist.txt` by hand.
7. **Check in game:** any adult female, clothes off - no bra, no panties. Your own character too.
8. **Optional, the 11 special NPCs.** Inside that same new mod, copy the three files into
   `meshes\actors\character\<Name>\` for each of:
   `Bijin NPCs`, `Bijin Warmaidens`, `Bijin Wives`, `Chaconne`, `Elisif`, `Minazuki`, `Serana`,
   `Succubus-San`, `Toccata`, `Valerica`, `Vivace`. Do **not** delete those folders or leave one
   without a body file - a missing file makes that NPC invisible from the neck down.
9. **Do not** run Nemesis or LOOT - neither change needs them.

---

## 4. Nude body (females) and males

**Steps 4, 5, 6 and 8 above are cancelled - that work is done, and you do not have to open BodySlide
at all.** You said the body should be part of the same mod, so it is: the nude body now ships inside
**LoreRim Glue** itself.

### What was done

LoreRim had already built a proper **nude CBBE 3BA body** with its own preset - it just used it for
Astrid instead of for everyone (`femalebodyastrid_0/1.nif`, shapes `3BA` + `3BA_Vagina` + `3BA_Anus`).
Copies of exactly those two files now sit in the glue mod as `femalebody_0.nif` / `femalebody_1.nif`,
and in the eleven special-NPC folders as well (`Bijin NPCs`, `Bijin Warmaidens`, `Bijin Wives`,
`Chaconne`, `Elisif`, `Minazuki`, `Serana`, `Succubus-San`, `Toccata`, `Valerica`, `Vivace`).
24 files, all copies - **no LoreRim file was touched, moved or deleted**, and nothing was rebuilt.

Because `LoreRim Glue` is at the bottom of MO2's left pane it wins over `LoreRim - BodySlide Output`,
so MO2 will now show a conflict flag on both mods. That is correct and expected.

Checked before copying, so you do not have to trust it blindly: both weights have the same vertex
counts (a real low/high weight pair), every 3BA physics bone the body uses exists in your skeleton,
the skin and genital textures both come from **BnP - Female Skin** (so they match in tone), and the
morph file the body points at (`femalebodyastrid.tri`) contains the same three shape names - which is
what makes **OBody keep working**. OBody needs no change at all; it never touched the underwear
because it morphs bodies, it cannot add or remove parts.

### Install it

Close Mod Organizer (it was running, so the installer refused and wrote nothing), then run this one
line and start MO2 again:

```
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\tools\install_mo2.ps1"
```

It backs up your profile files first, never sorts, and is safe to re-run. (It also puts in the
crash-fixed scripts if they are not in yet.) **Do not** run Nemesis, LOOT or BodySlide.

### It is global - know this before you install

Every adult female in the game - NPCs, followers, your own character - is nude under her clothes from
now on, in and out of any glue scene, whenever anything empties the body slot. **Nothing changes while
she is clothed:** armour and clothing meshes are separate files and were not touched, and OBody's
clothed refit is unaffected.

### How to check in game

1. Any adult female with her clothes off: a nude 3BA body, **no bra, no panties** - including your own
   character, Serana, Elisif, the Bijin NPCs and the rest of the eleven.
2. **Bodies still differ between NPCs** - OBody is still handing out its random presets on top of the
   new body, so shapes vary exactly as before. If every woman suddenly looks identical, something is
   wrong with OBody, not with the body mesh.
3. **No seams** at the neck, wrists or ankles, and no colour break at the crotch. This is the one
   thing that cannot be proven outside the game; look at a bright, close, well-lit body.
4. Breasts/butt still move (CBPC physics) - the old third-person body had no physics bones at all, so
   this should be *better* than before, not worse.

### How to undo it

Either of these, both reversible, neither touches LoreRim:

* re-run the installer with the switch: same command as above with **` -NoNudeBody`** on the end - it
  installs everything except the body and deletes the already-installed `meshes` folder of our mod; or
* in MO2, open `LoreRim Glue` (double-click) -> *Conflicts*/*Filetree*, right-click the **`meshes`**
  folder and **Hide** it.

Unticking the whole `LoreRim Glue` mod also removes the body, but it removes the glue with it.

### Males - The New Gentleman (TNG)

No file work was done for males and none is possible without a download. Facts first: OStim 7.5.1
already looks for TNG by itself (`OStim.log` prints `TNG is not installed`), so **nothing in the glue
needs changing** if you add it. One correction to what we assumed earlier - **your male body is not
HIMBO**: the male body that wins is from *Males Of Skyrim by zzjay*; LoreRim only has HIMBO *armour*
refits. Whatever TNG option you pick must suit that body.

I could **not** read TNG's Nexus description (the page returns 403 to my fetch tool, and so does the
Schaken-Mods mirror), so I will not invent its installer options:

* **Download:** *The New Gentleman* (TNG) for Skyrim SE, main file. Optionally *The New Gentleman - SoS
  Keyword Patch*. Not the *SFW Edition*, not the *CHS* page.
* **Installer options:** read them on the page yourself. You are fitting a **vanilla-proportioned
  high-poly male body (zzjay's Males of Skyrim)**, not HIMBO, and TNG will likely install its own male
  body over it - that is fine, but expect zzjay's `malebody_*` to be overridden.
* **MO2:** drag the mod to the **bottom of the left pane** (it never collides with our files - ours are
  `femalebody_*`, TNG's are male). Put its plugin **at the end** of the right pane, after
  `LoreRimGlue.esp`. **Do not sort, do not run LOOT.**
* **Nemesis:** almost certainly not needed (TNG is an SKSE plugin - that much its source repo does
  confirm), but I could not verify it from the mod page. If the page or its installer says otherwise,
  believe the page.

## Crash 4 (mid-scene) and the complete fix

### What was wrong

The crash that killed scenes 1-3 at the *start* and the one that killed playtest 5 *two minutes in*
are the same crash: OStim's own weapon removal calls the game's `UnequipItem` on something that is
not a real object any more, and the game dies. Turning off **Remove Weapons at Start** (which the
glue now does for you) only closes the door at the start of a scene. OStim opens the same door a
second time, **every time the scene changes position**: when a position would undress somebody, OStim
undresses them *and takes their weapons off in the same breath*. That second call is not covered by
"Remove Weapons at Start", and it is not covered by the glue telling OStim "do not undress anybody"
either - I checked the source line by line: that instruction stops the undressing and the weapon call
right next to it still runs. That is exactly what happened when your scene moved to the first sexual
position.

Also worth knowing: emptying the hands beforehand cannot help. OStim does not look at the hands the
way Papyrus does - it reads an internal "what is this actor holding" cache, which can still contain
something when the hands are provably empty (playtest 4: three clean checks in a row, still a crash).
So the only real fix is to make sure OStim never runs that code at all while a scene of ours is
running.

### What the glue does now

While the crash fix is on (Intimacy page, "Switch off OStim's weapon removal (crash fix)", on by
default), a scene started by talking to somebody now does this:

1. Before the scene starts it turns **three** OStim options off instead of one:
   * *Remove Weapons at Start* - stays off afterwards (it crashes at every start).
   * *Fully undress mid Scene* - **put back exactly as you had it** when the scene ends.
   * *Partial Undressing* - **put back exactly as you had it** when the scene ends.
2. It puts both your weapons away itself before the scene and gives them back afterwards (unchanged).
3. Because OStim's mid-scene undressing is paused, **the glue does that job instead**: when the scene
   reaches a position that OStim would have undressed somebody for, the glue undresses them through
   OStim itself, so the clothes come back at the end the same way they always did. It follows your
   own OStim setting - if you have *Fully undress mid Scene* off in OStim's MCM, the glue does not
   undress anybody either.
4. The two borrowed options are given back on every possible ending: normal end, "stop", a refused
   start, OStim refusing the scene, the start timing out, and after loading a save.

One thing is deliberately not copied: OStim's *partial* stripping (the little "pull the dress aside"
cases). Papyrus cannot read which slot a position wants stripped, and that was the second way into
the crash. In practice: in positions that only strip partially, clothes now stay on until you or she
says something ("take it off", "undress her").

New switch on the **Intimacy** page: **"Undress you both during a scene"** (on by default). Off means
the glue does not undress anybody by itself - only what you two ask for.

### What you may want to set by hand in OStim's MCM

Scenes that you start from **OStim's own menu** (or that another mod starts) are not protected - the
glue only borrows those options around scenes it starts itself. If you ever start scenes that way,
open **MCM > OStim > Undressing** and turn these off:

* **Remove Weapons at Start** - off. (This is the one that crashed at the start.)
* **Fully undress mid Scene** - off. (The one that crashed in playtest 5.)
* **Partial Undressing** - off. (Same code, other branch - it triggers whenever a position strips the
  body slot, which is most of them.)

Note: *Remove Weapons at Start* is greyed out while *Fully undress at Start* is on. If you cannot
click it, turn *Fully undress at Start* off first, turn *Remove Weapons at Start* off, then put
*Fully undress at Start* back the way you want it.

Leaving these off costs you nothing in glue-started scenes - the glue does the undressing and the
weapons itself.

### Reading the new log lines

In the glue's game log you will now see, in this order:

* `OStim 'Remove Weapons at Start' was ON - turned OFF by the glue (crash protection)` - as before.
* `OStim mid-scene undressing paused: UndressMidScene 1->0, PartialUndressing 1->0 (crash protection;
  the glue undresses instead)` - the new part. The numbers before the arrows are *your* settings, and
  they are what gets written back.
* `glue undressed 2 actor(s) at scene <id> (scene start | node change)` - the glue doing OStim's job.
* `OStim mid-scene undressing restored (UndressMidScene=1 PartialUndressing=1): the scene ended` -
  your settings are back. You should see this once per scene. If a scene ever ends without it, say so
  and check OStim's MCM: those two options may be left off.
* `OStim undressing gates not found (OStim.esp 0xDAC/0xDAD) - MID-SCENE CRASH GUARD IS OFF` - should
  never appear; it would mean OStim.esp is not loaded as expected.

### Installing this build

`compile.ps1` printed OK. The installer refused because **MO2 was running** (you are mid-playtest).
Close Mod Organizer and Skyrim, then run:

```
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\tools\install_mo2.ps1"
```

### How sure am I

Sure that no remaining code path in OStim 7.5.1 can reach the crashing call during a scene the glue
starts: I mapped every caller in the source and checked the one that no setting controls (an
animation annotation) against every installed animation file - none of them carries it. Sure that the
end of a scene is safe too: OStim only re-equips weapons it removed itself, and it no longer removes
any. Not sure - and it does not matter for the fix - exactly *which* of the three things OStim passes
to the game (right hand, left hand, ammunition) was the broken pointer for Lisette; that is runtime
state I cannot read from source. Untested in game: this build has not been played yet.
