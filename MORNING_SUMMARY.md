# Morning summary - 22 September 2026

Read this page, then do A, then B, then play C. It should take about twenty minutes of settings and
about twenty minutes of playing.

**The one thing to know before anything else: nothing was tested in Skyrim last night.** Everything
below is offline work - compilers, tests, hand-traced proofs, and your own logs from yesterday. The
first real evidence for any of it comes out of your evening, not out of my night.

Two things ship switched off on purpose and stay off until you say otherwise: quest talk without the
menu (`bMenuless = 0`) and the click step (`bDlgDryRun = 1`). If you just play, quest dialogue works
exactly as it did yesterday.

---

## 0. Read these first, in this order. Read them - do not skim.

| Read | Why |
|---|---|
| `PLAYTEST8_NOTES.md` | Everything that went live two nights ago and is still live. Section 2 is the five settings that are not mine and that I cannot set for you. Section 7 is paying for sex, the after-scene camera, and closed doors. Section 12 is the conversation hold. |
| `PLAYTEST9_NOTES.md` | Last night's round. Section 1(e) is the follower part and it is live the moment you load. Section 3 is your evening. Section 7 is the whole latency answer. |
| `research\pt8-body-fix.md` section 6 | Owner steps for the jiggle physics. |
| `research\pt9-body-variety.md` section 7 | Owner steps for the body shapes. The OBody reset is required or none of it reaches anyone. |
| `research\pt8-walkaway.md` section 0 | Why people walk away from you mid-conversation, and the CHIM web-interface settings that stop it. |
| `research\pt9-followers.md` section 9 and `research\pt9-followers-build.md` section 7 | Follower owner steps. The single most important switch of the whole night is in there. |
| `research\pt9-latency.md`, `research\pt9-tts-cpu.md`, `research\pt9-latency-reconcile.md` | Where your wait really goes, the measured numbers, and the exact web-interface wording. |
| `research\pt9-ship-v051.md` and `research\pt9-ship-v051-final.md` | What was shipped, what was verified, and what was deliberately not done. |

### Five facts from my side that are not in those files

1. **Nether's Follower Framework is not installed.** Your follower framework is **Simple Follower
   Framework**. Anything anywhere that assumes NFF does not apply to your game. This matters: it is
   the reason CHIM's "join my party" is broken here (section A1).
2. **CHIM's services in the WSL distro were found stopped overnight.** That is normal - your launcher
   starts them. Start the launcher before you open the web interface, or the page will not load.
3. **CBPC logging is still switched on inside the glue.** I turned it on so the jiggle could be
   verified instead of guessed. It costs performance and writes a very large file. Say the word and I
   will set it back to 0.
4. **There is a leftover scratch database called `lrg_t`** on your Postgres. I made it so a 37,561-row
   migration could be tested without touching your live one. Nothing uses it. I did not drop it
   without asking.
5. **Mod Organizer's left pane still says version `0.1.0`** next to LoreRim Glue. The mod files are
   current. That label is written only by the installer, the installer refuses to run while MO2 is
   open, and MO2 has been open since Sunday afternoon. It is cosmetic.

---

## A. Do these in the CHIM web interface

Start your CHIM launcher first. The address is **`http://localhost:8081`** - note the **8081**.

`PLAYTEST8_NOTES.md` and `pt8-walkaway.md` print these links without a port number. **That was
wrong.** The newer report (`pt9-tts-cpu.md`, section 5) checked the server's own configuration: the
port is 8081. Use the newer one.

### A1. Switch OFF `MakeFollower` - shown as `Join_<your name>_Party`

Page: **Actions** (the Action Editor,
`http://localhost:8081/HerikaServer/ui/function_editor.php`).

**This is the bug of the night.** That action was written for Nether's Follower Framework, which you
do not have. Here all it can do is drop the person into the game's follower faction and stop. No
companion slot, no framework, no way to dismiss her. Worse: the game's dismiss line, *"it's time for
us to part ways"*, then goes to **whoever your real follower is**.

The glue now hides it where it can see it cannot work, and repairs the damage if it happens anyway.
This switch is the only way it can never happen at all.

### A2. Switch OFF `Follow`

Same page. It follows another actor at priority 100, with no owner and no end condition. Nothing in
the glue uses it.

### A3. Leave `FollowPlayer` - shown as `Follow_<your name>` - ON

The glue uses it, and it is the one CHIM ends cleanly by itself. `Wait_Here` is already off; leave it
off.

### A4. Switch `EndConversation` to Disabled

Same page. It is enabled today. This is the one walk-away cause with hard evidence in your own logs -
it fired eight times in one session, six of them inside the two windows you complained about. The
model can choose to end a conversation you are still having. The glue can only suppress it for an NPC
it is actively holding; this switch stops it everywhere.

### A5. `END_CONVERSATION_COOLDOWN` -> 0

Page: **Global Settings**. It is 60 today. Even if she does end a conversation, you can talk to her
again at once instead of being stonewalled for a minute.

### A6. `RECHAT_MODE` -> `conversational`

Page: **Global Settings**. It is `random` today, which re-rolls per exchange and is what lets a reply
wander off to another NPC or to the Narrator. `tight` also works if you want it stricter.

### A7. Drop three prompt sections

Page: **Global Settings**, card **Context Selections**, sub-heading **Top-Level Sections**. Untick
**`<nearby_items>`**, **`<points_of_interest>`** and **`<group_descriptions>`**. The checkboxes
really do show the angle brackets. Leave the other eight ticked. Save.

Worth 0.1 to 0.4 seconds on crowded turns.

### A8. Connector 7, `Gemma 3N E4B` - delete it or repoint it

Page: **LLM Connectors**,
`http://localhost:8081/HerikaServer/ui/core/llm_connectors.php`.

Verified: nothing points at it, and that model no longer exists at OpenRouter. It costs you nothing
today. It would fail loudly the day something selected it. Either delete the row, or set its
**Model** to something real.

### A9. The model itself - a decision, not a step

See section E3. Do not switch it blind.

---

## B. Do these in the game's menus

### B0. Before you launch: press F5 in Mod Organizer

The body presets live in a brand-new top-level folder inside the LoreRim Glue mod. MO2 caches each
mod's file tree, and it was open while the files were copied in. Press **F5** (or the
circular-arrows button above the left pane), confirm `LoreRim Glue` is still ticked at the bottom of
the left pane, then launch. Closing and reopening MO2 does the same job.

If you skip this, OBody may not see the three new presets and the named rules quietly fall back to
one stock name each.

### B1. OBody NG - "Reset all distributed presets". This one is required.

MCM -> **OBody NG** (it has one page) -> **"Reset all distributed presets"**.

Every woman you have already met is carrying an old roll, and none of the new rules can reach her
until this is done.

1. Do it **in a cell with as few people as possible** - a player home is ideal.
2. Accept the confirmation.
3. The second message is an instruction, not a formality: **save, fully exit Skyrim, start it again,
   reload.** The reset does not take effect otherwise.

It also clears any preset you had picked by hand with the O key, so re-pick those afterwards.

### B2. Your own body is never distributed to

Press OBody's **Presets list key** (default **O**) with **nothing** in the crosshair to pick your own.
All three new presets are in that list, and so is every blacklisted one.

### B3. LoreRim Glue -> Keys -> "Open vanilla dialogue (emergency)" - bind a key now

It ships **unbound**. This is your escape hatch: it hands you a working, visible, clickable dialogue
menu in every state, whoever hid it - the glue, or a test press. Bind it before you do anything in
section C.

Also on that page: **"Stop scene now"** is already **End**. **"Dump topics"** is unbound; bind it if
you want to dump an NPC's topic list and the diagnostic counters on demand.

### B4. LoreRim Glue -> Scene talk - the OStim free-camera fix

Two switches, both already on. Just confirm they are:

* **"Give me my camera back after a scene"** - if a scene ever leaves you in OStim's free camera, or
  without movement or look controls, the game puts them back a moment after it ends. It only fires
  when they really are still gone, and it writes a line in the log every time.
* **"Keep talking to her after a scene for"** - 20 seconds. When a scene ends there is nobody under
  your crosshair for a moment, so CHIM used to answer as the Narrator and she never heard a word.
  She now stays the one you are talking to.

### B5. LoreRim Glue -> Calibration - read the status line, then go to section C

It says either "green - 10 of 10 answered" or "not yet - 7 of 10: missing ...". That line is the
gate on ever turning the menuless feature on.

### B6. LoreRim Glue -> Followers - leave it alone

The page is new and has five controls. The defaults are the ones you want. The only choice worth a
thought is **"How to fix her"**: leave it on **"Make her real if there is room"**.

### B7. CHIM -> Behavior - only if you play with a real follower tonight

Switch **"NPCs Sandbox Near Player"** and **"NPCs Walk To Target"** **off** while you are testing with
a real follower. They compete with her follow package for no benefit.

**These two reports disagree and the newer one wins in this case.** `pt8-walkaway.md` says leave
"NPCs Sandbox Near Player" **on** (it is on by default, and it does nothing at all unless you are
sitting down, so sitting down to talk still helps). `pt9-followers-build.md` is newer and is about the
follower case specifically: with a real follower present, turn it off. Both are right in their own
situation.

### B8. CHIM -> Keys, the K2 hotkey - do not press it at a follower

"Make the NPC in your crosshair wait here" writes a wait state that the follower framework does not
know about. She stands still while every follower mod still thinks she is walking with you.

### B9. "force default voice" - leave it off

It is a workaround for a mod (RDO) you do not have, and it kills custom-voiced follower audio -
Inigo, Lucien, Auri, Serana and the rest.

### B10. The settings that are not mine - unchanged from two nights ago

`PLAYTEST8_NOTES.md` section 2 lists five of them: CHIM's **AI Quest Progression OFF**, IACC's
`bForceFirstPerson = 0` and `bHideDialogueMenu = 0`, Smart Talk's `bSkipImmediateOnInput = 0` and
`bHoldToSkip = 0`, Helmet Toggle 2's `HT_EnableDialogue`, and Fuz Ro D-oh's words-per-second.
Nothing last night changed any of them. **If you already set them for playtest 8, you are done.**
Never touch Smart Talk's `iPapyrusHandle` - leave it at 3.

---

## C. Your evening - about twenty minutes

Play normally. Talk to people. That is most of it.

### Step 1 - three or four ordinary conversations. Nothing to do.

An innkeeper, a merchant, a guard, anyone with a decent topic list. The glue measures how your
dialogue menu behaves while you talk. You should not see or feel anything at all.

### Step 2 - turn on the active pass, then have a few more conversations

MCM -> **Calibration** -> tick **"Calibrate on the next few conversations"** (`bCalibActive`).

On separate conversations it hides the topic list for about a fifth of a second and gives it straight
back, with a short note in the corner. **It never clicks anything.** It refuses to run on a guard, on
anyone you have a bounty with, during a scene, or with a shop or training window open.

Budget is five conversations. When it is spent, the status line says so. To run another set: **untick
the switch, then tick it again.**

### Step 3 - the two presses. This is the only key-pressing left.

Two answers need a human. Both are buttons on the **Calibration** page. The buttons **arm** the test
for your next conversation - press the button, close the menu, then go and talk to somebody.

**Press 1 - the click test.** Button: **"1. The click test (arm it here, then talk to an
innkeeper)"**. Arm it, then walk up to an **innkeeper** and start the conversation. It hides the list
and asks you to **mash the mouse, E, Space and the wheel for three seconds** - do that properly, as
if you were trying to force your way through. It then clicks one topic for real and gives you the
menu back. If the log says the click did not go through, run **"1b. The click test, the other way
round"**.

**Press 2 - the menu-still-works test.** Button: **"2. The menu-still-works test (straight after
1)"**. Arm it, then open a conversation with anybody, by hand, the normal way. **It gives you three
seconds - click any topic with the mouse while it watches, or it cannot pass.** Just watching answers
nothing and the row stays red. This is the one that catches the worst failure the whole feature has,
so please do not skip it.

**If the buttons do nothing on your install:** Menuless questing page -> set a key under **"Run the
probe press"** (it ships unbound) -> then **"Which probe press"** = **33** (click test), **34** (the
other way round), **35** (menu still works), **36** (forget everything). Those four work with the
probe switched off.

**If you are ever left with no topic list:** press your emergency key. It always gives it back. Probe
press **32** does the same.

### Step 4 - read one line

MCM -> Calibration. If it says **green - 10 of 10 answered**, you are done. If it names something
still missing, **tell me what it names.** That is a real answer and it is worth more than any guess I
can make from here.

### Do not turn the feature on yet

When the status reads green: set `bMenuless = 1` and **leave `bDlgDryRun = 1` for one whole evening**.
The log will say `WOULD CLICK` where it would have clicked, and you click by hand. Only after that
evening do we take the dry run off.

---

## D. What was built while you slept

One line each.

| Version | What |
|---|---|
| **v0.4.0** | Menuless questing built but inert. Paying for sex ("everyone has a price"). The after-scene camera and listener fix. A room with a closed door counts as private in a busy inn. |
| **v0.4.0, fix pass** | Sixteen defects found by reviewing the shipped build and fixed the same day - including eight MCM controls that changed nothing at all, and the probe key being dead in the session you armed it in. |
| **v0.4.1** | The conversation hold: while you are talking to her she stops, faces you and stays, and is released cleanly on about a dozen conditions. |
| **v0.5.0** (06:30) | The glue calibrates itself while you play, instead of a 21-press probe evening. The dry run is locked shut until the calibration is green. Services (rooms, rides, training, trade, guards) go through her real dialogue. Locked facts and a truth gate. One latency line per reply. |
| **v0.5.1** (07:30) | Followers: she is told who travels with you, "join my party" is hidden where it cannot work, a half-recruited follower is repaired, the conversation hold no longer freezes a companion, and follower commands route to her real dialogue entry (that half is behind `bMenuless`). |
| **v0.5.1, fix pass** (08:15) | Four major and eight minor defects. Two matter: the whole Simple Follower Framework integration was looking up the wrong names and was **dead for the entire round**, and the "undo" repair used to delete an NPC's own recruit line from the save permanently. Both fixed and verified inside the installed files. |
| **The latency reconciliation** | Two changes were taken back out because they narrowed what she is allowed to choose. The round's headline saving was corrected from ~133 seconds per session to **~33 seconds**, which is the honest replayed figure. |
| **The `ml=` handshake** | One code change after the build lanes finished: the game now tells the server that menuless questing is switched off. Without it you would have had no renting a room, no hiring a carriage, no training and no merchant inventory by voice all evening. |
| **Bodies (playtest 8)** | Jiggle physics restored (the winning config had breasts at one tenth motion) and the random body-shape pool curated. |
| **Bodies (playtest 9)** | Curvy bodies put back deliberately instead of accidentally: three new presets, 36 named women assigned by FormID, two factions, one race rule. |

Version markers, if you ever need them: `manifest.json` **0.5.1**, `LRG_VERSION` **0.5.1**, the game
script version **501**, **10** compiled scripts.

What was green before it was installed (refreshed at 08:15): compiler **0 errors, 0 warnings**; PHP
lint over **74 files, 0 errors**; gates **319**, intent 190, phrases 14, scene index 607 scenes,
dialogue 132, prompt index 67 plus **112 against the live database**, MCM wiring **113 controls**
(every one with a settings line and a reader), services 35, latency 26; and **74 of 74** offline
conversation scenarios with **1,161 checks and 0 warnings**. The server is deployed, the index is
warm (37,561 prompts / 5,718 layers), and there are no glue errors in any CHIM log.

### The go-live review (09:30) - the last pass before you flip the switch

Twenty-four reported defects were traced one at a time against the real code. **All twenty-four held
up, and all twenty-four are fixed and installed.** Nothing was written off as "cannot happen".

The plain-words version is in `PLAYTEST9_NOTES.md`, section 11. The four that would have reached you
first:

* **The emergency key could leave you stuck.** Press it in the second or two while the glue is opening
  a conversation and you got a hidden, guarded menu that nothing would ever bring back - the one key
  that is supposed to always work was the thing that broke it. The key now cancels the open and hands
  you the plain menu. Two other ways the same key could do nothing at all are fixed with it.
* **A hold mentioned in passing was read as an order.** "My brother lives in Morthal", "I've just come
  from Riften", even a character named after a city, all resolved to a paid carriage ride. She now
  needs to be *asked* - "take me to", "a ride to" - or told the name on its own.
* **A refusal could confirm the thing you refused.** The two-step confirmation released on any second
  selection, so "No, forget it, I have changed my mind" committed the purchase. Worse, a background
  poll where you had said nothing at all could commit it. Both are closed, and a refusal now cancels
  the parked item instead of leaving it armed.
* **The fast path had no arrest rail.** The slow path hands you the visible menu at an arrest; the fast
  one did not, so "I submit, take me to the cells" could jail you by voice with no menu shown. It now
  takes exactly the same two rails.

Also fixed: a long topic list (a jarl, a mid-chain quest giver, an innkeeper with rumours) had every
entry past the sixteenth structurally unclickable, which cost up to 45 seconds of frozen hidden menu
each time; a quest scene started by the glue's *own* click was driven anyway; combat starting
mid-conversation was never noticed; 7.7% of decisions shipped with no text to verify the click
against; the truth gate cancelled a ride she had already agreed to if she happened to mention money;
"Follow me" and "Wait here" did nothing while their CHIM fallbacks were hidden; one passing sentence
granted Speech XP for ever and a repeated bribe stopped costing gold; and `bLockedFacts = 0` silently
switched the truth gate off with it.

Green after the fix pass: compiler **0 errors, 0 warnings**; PHP lint **74 files, 0 errors** (and 14
again on the deployed copy); gates **319**, intent 190, phrases 14, scene index 607 scenes, dialogue
**174**, prompt index 67 plus **112 against the live database**, MCM wiring, services 35, latency 26;
**74 of 74** scenarios with **1,179 checks and 0 warnings** (18 new checks written for these fixes).
Server re-deployed - 28 anchors, index warm, no glue errors in any CHIM log. Mod Organizer was open,
so the installer refused again and only our own files were copied: **24 compared by SHA-256, 12
copied and re-hashed, 0 mismatches.**

---

## E. Still open, untested, or yours to decide

### E1. Move the voice synthesiser off your graphics card. Your call.

**This is the whole latency story.** PocketTTS is not slow. With the game closed it makes a normal
line of NPC speech in **0.089 seconds** on the GPU - 33 times faster than real time. The same service,
same model, same settings, during your play session took **3.41 seconds at the median and 97.6
seconds at its worst**, and the two worst calls of the evening were a 28-character line and a
22-character line. Length has nothing to do with it. That is a synthesiser being starved of the card
while Skyrim renders on it.

The change, with Skyrim and the CHIM server stopped:

```
wsl -d DwemerAI4Skyrim3 -- bash -c 'cp /home/dwemer/audio.cpp/server.json /home/dwemer/audio.cpp/server.json.bak'
# then edit /home/dwemer/audio.cpp/server.json:   "backend": "cpu",   "threads": 8
```

Then start CHIM and Skyrim normally. **Revert = restore the `.bak`** (`"backend": "cuda"`,
`"threads": 1`).

**Threads 8, not 12 or 16** - measured on your own processor: 4 threads 0.703 s, **8 threads 0.615
s**, 12 threads 0.618 s, 16 threads 0.627 s. Past 8 buys nothing and 8 leaves 24 of your 32 logical
cores for Skyrim.

**Honest accounting.** The cost is measured: 0.089 s -> **0.615 s** at the median with the game
closed. The benefit - losing the 3.41 s median and the multi-second tail - is **inferred** from your
own session's log, because I could not reproduce Skyrim's pressure on the card without Skyrim
running. Expected net if that reading is right: about **2.8 seconds off the median, 5.5 off the p90,
and the tail gone**. It is the robust choice, not the fastest one: staying on the card and capping
Skyrim's framerate has a much better ceiling.

The ten-minute test that settles it is in `PLAYTEST9_NOTES.md` section 7. Pass = median under about
1 second, p90 under about 2, nothing over 5.

**Where the two reports disagree:** `pt9-latency.md` (earlier) says `threads: 12` and "start
`audiofilterd`". `pt9-tts-cpu.md` (later, and it measured both) says **threads 8**, and that
`audiofilterd` **does not exist on your system** - see E2. Use the later one.

### E2. The quarter-second of silence at the front of every line. This one is a CHIM core file.

Every file the synthesiser returns starts with **0.25 to 0.31 seconds of silence**, on both backends,
so E1 does not fix it. CHIM is supposed to trim it with a helper called `audiofilterd`.

**That helper does not exist on your system.** Not stopped, not disabled - never shipped. Your CHIM
contains the client half and nothing else: no program, no package, no socket, no service. So every
call throws an error, falls back to `ffmpeg`, and `ffmpeg` is handed an empty filter - a plain
re-encode with the silence intact. Any instruction of the form "start `audiofilterd`" would be made
up, and the earlier report's version of this step was exactly that.

Three honest options, and **the middle one is a CHIM core file, so it is your decision**:

1. **Do nothing.** It costs about a quarter of a second of dead air per line and two log lines per
   call, and it survives every CHIM update. Perfectly defensible.
2. **One line in `/var/www/html/HerikaServer/tts/tts-pockettts.php`** (around line 448), where the
   trim filter is currently set to an empty string with the real filter commented out directly above
   it. Measured cost about 55 ms. **CHIM owns that file** - an update will overwrite it and you would
   re-apply by hand. I have not touched it and will not without you saying so.
3. Wait for a CHIM release that ships the helper.

### E3. The model. Decide it on cost and behaviour, not on speed.

**The language model is not your bottleneck.** Measured on your own connector: 1.86 s for a whole
reply, 1.47 s to the first spoken sentence. Fixing the voice synthesiser is worth two to four seconds.
Switching models is worth at most about one.

The setting that actually changes the voice you wait for is the profile's **Standard LLM**:
**Profiles** -> `http://localhost:8081/HerikaServer/ui/core/core_profiles.php` -> edit **`Default
Profile`** -> the **Standard** slot. It is `Grok 4.3` (connector 10) today.

**An earlier draft of the notes named `CORE_CONNECTOR_DIRECTOR`. That was wrong** - that is the
Director Mode slot on the Global Settings page, a different feature that happens also to be set to
Grok. Changing it would have done nothing to your reply times. The newer reports have it right.

**Do not swap straight to DeepSeek V4 Flash.** Its connector row (id 1) is marked as a reasoning model
with no parameters at all, so as configured it may think before every line and come out **slower**
than what you have. And when six models were measured head to head, it was the slowest - not because
the model is slow, but because OpenRouter sent it to two different resellers inside six requests.

To fix its row first: **LLM Connectors** -> row `DeepSeek V4 Flash` -> the **`Include Body Parameters
(YAML)`** editor near the bottom -> `reasoning:` / `  effort: none` -> tick the toggle beside it ->
leave **Disable Streaming** unticked -> optionally pin the **`Provider`** field (the free-text box
under **`Model`**) to stop the routing wandering -> Save.

What the numbers can and cannot say: **6 of 6 versus 4 of 6** on strict-JSON compliance, and **$1.76
versus $8.39 per 1,000 turns** - those are real differences. **0.79 s versus 0.92 s is not** - six
samples cannot separate them. `tools/bench_llm.php` measures your own connector rows head to head and
changes nothing; `php tools/bench_llm.php --list` prints them side by side for free. A real run costs
a few cents of real tokens, which is why I did not run it for you.

And one thing no benchmark can tell you: how a model behaves on **your** content. A model that
softens or refuses one explicit line costs a whole turn plus a retry - far more than the tenth of a
second it wins. If you try one, decide it in a single scene and revert the same minute if she goes
coy.

Two slots you never wait on, on Global Settings, are also Grok and are pure cost: **Director Mode**
and **Profile Tasks**. (**Scene Classifier** is a third but is switched off.)

### E4. The two CHIM actions - only you can flip them

`MakeFollower` and `Follow` (section A1 and A2), and `EndConversation` (A4). The glue can hide and
repair; it cannot switch them off. These are the highest-value minutes of the whole list.

### E5. The scratch database `lrg_t`

Still there, nothing uses it. `dropdb -h localhost -U dwemer lrg_t` removes it. I left it rather than
drop a database on your machine unasked.

### E6. Nothing of last night has been seen in Skyrim

The follower work in particular has **never had a real follower present in any playtest**. It is the
least-proven part of the round and it is the part that is live immediately. There are eight follower
test scenarios listed in `pt9-followers-build.md` section 8; all eight are unknown. The one to run
first is the half-recruit repair, now that the repair no longer destroys anything.

Two notes if you want to exercise it tonight: your follower slots are Speech divided by 25, plus one -
early game that is **one slot**. To get more, raise Speech, or temporarily set
`bFollowerOptionSelector=0` with `iMaxFollowers=2` in
`mods\Simple Follower Framework\SKSE\Plugins\SimpleFollowerFramework.ini`. That mod has no menu; the
ini is the whole control surface.

### E7. The Calibration buttons are untested in game

They now arm the test rather than trying to fire it on the spot, which is the part that was provably
broken. Whether MCM Helper reaches them at all on your install is still unknown. The probe-press
route (33 to 36) is the fallback.

### E8. Menuless questing is inert, and one quest switch ships off

`bMenuless = 0` and `bDlgDryRun = 1`. Also `bQuestInitiative` (she brings a quest matter up herself)
is off, because it changes how conversations start. Turn it on only after the calibration is green
and you have had a normal evening with the other three quest switches, which are all on.

### E9. CBPC logging is still on in the glue

Say the word. It is one value.

### E10. MO2's version label

Close Mod Organizer and run `tools\install_mo2.ps1` once. It is safe to re-run and it sets the label
to 0.5.1. Cosmetic only.

### E11. Three leftover backup files ship inside the mod

`LRG_Main.psc.bak-docstrings`, `LRG_OStim.psc.bak-docstrings`, `LRG_Profile.psc.bak-docstrings` -
164 KB of inert text the game never reads. They were another lane's files, so I did not delete them.
Say if you want them gone before the next full install.

### E12. Four upstream problems found on the way. I changed nothing in anyone else's mod.

1. **Little Lessons** skips a Speech restore whenever the hardest Speech difficulty is 100 or more,
   which a LoreRim Speech trait guarantees. Effect: every dialogue menu you open with a married NPC
   permanently makes persuasion easier, forever. The glue measures it; a non-zero `drift` in the log
   is this, not us.
2. **Four orc-follower check topics** (Borgakh and Ghorbash, persuade and intimidate) have the
   success line sorted after an unconditional failure line, which probably makes those checks
   unpassable here.
3. **Nine topics in this load order** have an inverted flag. Upstream data; reported, changed nothing.
4. One plugin (**Sanguine Symphony**) uses a compression format the index builder does not read. It is
   not needed.

### E13. Body dials, if the result is not quite right

All one-line changes on my side: curvy too often at random, not often enough, one specific woman
wrong, the new presets too much or too little, or drop the child-race blacklist.
`research\pt9-body-variety.md` section 7, step O5 lists them. If a scene ever stutters at the instant
it starts, that is a different switch and I will set it back - just tell me.

### E14. Something odd in CHIM's own configuration

`/var/www/html/HerikaServer/conf/conf.php` is **0 bytes**, with a full-size backup beside it from
Sunday morning, and CHIM's log timezone shifted at the same moment. Current CHIM reads its
configuration from the database, so this may be harmless - but an empty file is not a state anyone
chose. I changed nothing. Worth a look by whoever owns that path.

---

## F. What to send me back

The log is `HerikaServer/log/lorerim_glue.log`. The whole file is easiest. If you want to trim it,
these are the lines that matter.

**From the calibration (section C):**

```
CALIB set ...
CALIB active ...
CALIB SUMMARY
```

Plus **the exact wording of the Calibration status line** if it is not green. That sentence names what
is missing and it is the most useful thing you can send.

**One line to check after your first load, and it should find nothing:**

```
read OFF
```

If a line appears naming settings, one of the default lines did not reach the game and that feature is
silently off.

**One line that prints its own settings instead of complaining:**

```
dlg self-test
```

`dryrun=1` and `menuless=0` is what a correct install looks like. **`dryrun=0` on a fresh install
means a default line did not arrive and the glue could click for real** - switch `bMenuless` off and
tell me before you play. The same line carries `chim tts=`, which says whether the latency lines
below can exist at all.

**The reply timings:**

```
dlg lat
```

`total=` is your whole wait, `reply=` is how long until her words existed, `voice=` is the gap from
words to first audio. `voice=` is the number that should fall if you do E1. **Caveat: the measurement
starts when CHIM finishes speaking your own line. With CHIM's player speech turned off there is no
zero to measure from and no such line appears at all** - that is not an error.

**The conversation hold, and followers:**

```
conv hold
FOLLOWER GHOST
fol=
```

`release reason=self-heal` and `skip reason=a scene` are both normal - the notes explain both.

**If you turn the menuless feature on later:** grep for exactly `WOULD CLICK` and nothing more (a
search for `WOULD CLICK cid=` loses half of it), and tell me what you actually said in those
conversations.

**If you do the voice-synthesiser change (E1):** clear or move the old `soundcache/*.txt` first, play
one scene with about 20 spoken lines, then run the one-line command in `PLAYTEST9_NOTES.md` section 7
and send me the `n= median= p90= max=` it prints.

**For the bodies:** `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE\OBody.log`. After
the reset the counts must read exactly **`Female presets: 9, Male presets: 1`** and **`Blacklisted:
Female presets: 12, Male Presets: 0`**. If you ever see a preset applied whose name starts with
`CBBE Chubby`, `CBBE Oppai`, `CBBE Fetish`, `Warmaiden`, `State of mind`, `[Dint999]` or `TNG Default`,
send me that line.

**And three things no log can tell me:**

* did she still walk away from you mid-conversation, after section A;
* did the topic list ever stay stuck - that is the one result that would stop the whole feature;
* did anything read as cartoonish, too curvy or not curvy enough.

---

## Owner follow-ups (22 Sep, day)

Written 16:40. Three things you asked about are now done and checked. This section is self-contained:
what changed, what you will notice, the voice recommendation, and how to undo each item.

### 1. The quarter-second of silence at the front of every line - FIXED, and it is ours, not CHIM's

You said (E2, decision 2): *"i want our mod to change that line instead of changing it thru chim."*
That is exactly what was built. **Not one byte of CHIM was edited.**

Here is what was actually wrong. CHIM ships half of its own audio filter: for every line your NPCs
speak, it hands the audio to a little background program called `audiofilterd` and asks it to cut the
leading silence. **That program was never included in your distro.** So every single line threw an
error, fell back to a re-encode with no filter at all, and kept its dead air. Your log has 62 of
`Audio processing failed for PocketTTS response` for exactly that reason.

The missing program now lives **inside our mod** and answers on the socket CHIM already calls. CHIM
does not know the difference.

It is also smarter than what CHIM asked for. CHIM asks for a flat 250 ms cut. I measured all 138
voice files on your machine: **110 of them (80%) have leading silence - median 417 ms, worst 769 ms -
and the other 28 start speaking immediately.** A flat 250 ms would have left up to half a second of
dead air on most lines *and* eaten the first word of the other 28. Ours finds where the speech
actually starts and keeps 40 ms in front of it so the first consonant never sounds clipped.

**Proven live, through CHIM's own voice-test page, before and after:**

| | before | after |
|---|---|---|
| a line with 407 ms of silence | played with **407 ms** of dead air | played with **43 ms** |
| a line with 101 ms | **101 ms** | **40 ms** |
| a line that starts instantly | untouched | untouched (correctly) |
| `Audio processing failed` in the log | **+1 per line** | **0 across four more lines** |
| time spent in the audio step | median 0.055 s | **median 0.005 s**, no ffmpeg at all |

**What you will notice:** every line she speaks starts about a third of a second sooner. It is not a
faster reply - the words arrive at the same moment - it is the pause between "her turn" and "her
voice" closing up. On a back-and-forth conversation it is the difference between stilted and not.
Nothing else changes: same voice, same words, same volume.

**Undo, in order of bluntness:**

1. Put this in `ext/lorerim_glue/config/lrg_config.json` (create it if it is not there):
   `{"tts":{"audiofilterd":{"enabled":false}}}` - the next line CHIM speaks asks the program to stop
   and never starts it again. CHIM goes straight back to today's behaviour.
2. Right now, without editing anything:
   `wsl -d DwemerAI4Skyrim3 -- php /var/www/html/HerikaServer/ext/lorerim_glue/tts/lrg_audiofilterd.php --stop`
   (it comes back within ~10 s unless you also did step 1).
3. `--status` instead of `--stop` tells you its pid, version and how many lines it has handled.
4. Deleting our server plugin removes the feature completely - nothing was installed anywhere else.

**One thing to know.** Because CHIM's filter now *works*, CHIM's *other* audio effects stop being
applied - they were only ever reaching your ears through the broken fallback path. Three of them
exist: the experimental per-NPC random pitch (`TTS_RANDOM_PITCH`, which CHIM's own config marks
"WIP DO NOT USE" and which is **off** on your box), the `drunk` and `high` speech-speed moods, and
CHIM's book-reading voice. **None of them has fired in any of your play sessions** - I checked all
138 voice files and every one recorded an empty filter. So nothing is lost today. But if you ever
turn random pitch on, or use CHIM's book reader, and it sounds unaffected, that is why - and undo
step 1 above gets it back.

### 2. The four settings that were not mine to change - three are now files in our mod

These are the ones PLAYTEST8_NOTES section 2 asked you to edit by hand. You no longer have to: they
are ordinary files inside `LoreRim Glue`, which sits at the top of your load order and wins.

| setting | was | now |
|---|---|---|
| Smart Talk `bSkipImmediateOnInput` | 1 | **0** |
| Smart Talk `bHoldToSkip` | 1 | **0** |
| Smart Talk `bDBVOIntegration` | 1 | **0** |
| IACC `bForceFirstPerson` | **1** | **0** |
| IACC `bHideDialogueMenu` | 1 | **0** |
| Fuz Ro D'oh `WordsPerSecondSilence` | 2 | **3** |
| Helmet Toggle `iEnableDialogue` | 0 | 0 - **no file shipped**, it was already correct |

I verified each file line by line against the one that was winning before: **exactly those keys
differ and nothing else.** Smart Talk 95 body lines in and 95 out, IACC 33 and 33, Fuz 3 and 3;
nothing lost, nothing added. `iPapyrusHandle` is still 3 - that is the guard that stops quest lines
repeating and it was deliberately left alone.

The catch that was worth catching: **the IACC file your game was loading was not IACC's.** LoreRim's
own settings mod ships its own copy at a higher priority, and *that* one turns `bForceFirstPerson`
**on**. Reading the mod author's copy would have said "already 0, nothing to do" and the camera snap
would have stayed. Our copy is a copy of LoreRim's, so all thirty-odd values LoreRim deliberately
tuned (zoom, FOV, letterbox, camera speed) carry over untouched.

**What you will notice:**

* **The camera.** Conversations no longer yank you into first person and lock onto her face. They
  stay in whatever view you were already in, and the topic list no longer blinks out while she talks.
  If you preferred the cinematic feel, say so - one line in our copy (`bForceThirdPerson=1`) gives a
  forced over-the-shoulder shot without the first-person snap.
* **Unvoiced lines are about a third shorter.** Any AI line that has no audio yet.
* **The dialogue menu stops skipping itself.** A stray click or keypress during a line no longer
  jumps it. That habit is gone on purpose - it is what let a quest line vanish out from under the
  glue.
* **Nothing else.** Smart Talk's icons and colours, IACC's zoom and FOV, Helmet Toggle, and LoreRim's
  own tuning of all three are untouched.

I also confirmed **nothing outside our own mod was written**: no other mod folder changed today, and
no profile file carries a 22 Sep timestamp at all.

**Undo:** delete the one file from `F:\Modlists\LoreRim\mods\LoreRim Glue\SKSE\Plugins\` -
`SmartTalk.ini`, `AlternateConversationCamera.ini` or `Fuz Ro D'oh.ini`. The previous winner takes
over again on the next load. No reinstall.

**One caveat:** if you change anything in IACC's own MCM in game, that write now lands in *our* copy
of the file. That is fine and it sticks - it just means our file may drift from what this note says.

**The one thing I cannot check from here:** that the game reads the new values. The cheap in-game
check is the glue's **Calibration** status line no longer saying `blocked - Smart Talk
bSkipImmediateOnInput must be 0`. That is the single most checkable result of the whole item.

### 3. The voice - E1 and E3

**First, a correction: you are not on XTTS and never have been.** You said you wanted to stop using
it. There is nothing to stop. What is speaking for your NPCs is **PocketTTS-100M** running on the
`audio.cpp` C++ engine on port 8086. The XTTS folder exists as downloaded source with no environment
and nothing listening on its port. So "stop using XTTS" is already true.

**Second: PocketTTS is not slow.** I measured it again today, live, game not running: **0.079-0.110 s
per line, median 0.100 s** - about 37 times faster than realtime. Your multi-second waits are not the
synthesiser being slow, they are the synthesiser being starved of graphics card while Skyrim renders.
The same short line took 0.12 s at one point in a play session and 97.6 s at another.

That splits your question into two separate problems, and they pull in opposite directions:

**The wait → move it off the graphics card. Do this one first; it is ten minutes.**
This is E1. It puts the voice on your CPU, which is mostly idle while you play. Measured: **0.615 s
per line, worst case 1.4 s**, instead of the 3.4 s median and 97.6 s worst case your play logs
actually show. Your 7950X has 32 logical cores; this takes 8 and leaves 24 for Skyrim. It does
**not** improve the voice quality - it fixes only the wait. Be honest with yourself about which of
the two is bothering you more.

Exact steps, with Skyrim closed and the CHIM server stopped from the launcher:

```
wsl -d DwemerAI4Skyrim3 -- bash -c "cp /home/dwemer/audio.cpp/server.json /home/dwemer/audio.cpp/server.json.bak"
wsl -d DwemerAI4Skyrim3 -- bash -c "sed -i 's/\"backend\": \"cuda\"/\"backend\": \"cpu\"/; s/\"threads\": 1/\"threads\": 8/' /home/dwemer/audio.cpp/server.json && cat /home/dwemer/audio.cpp/server.json"
```

Then **Stop and Start the distro from the CHIM launcher** (there is no other supported restart - no
systemd in this distro), and check before launching the game:

```
wsl -d DwemerAI4Skyrim3 -- bash -c "curl -s http://127.0.0.1:8086/health; echo"
```

It must print `"backend":"cpu"`. If it still says `cuda`, the restart did not pick the file up - stop
and start the launcher again. **I confirmed today that this has not been done yet: your `server.json`
still reads `backend: cuda, threads: 1`.**

**Undo:** `cp /home/dwemer/audio.cpp/server.json.bak /home/dwemer/audio.cpp/server.json`, restart from
the launcher, confirm `/health` says `cuda`.

**The quality → Chatterbox, on the engine you already have. This is the NSFW answer.**
PocketTTS is a 100-million-parameter model - the smallest real voice in the distro. That is why it
sounds the way it does. But `audio.cpp` is a general engine, not a PocketTTS program: it already
implements **ten other voice families**, and CHIM points at them by changing **one text box in the
web UI** (the *Model* field on the TTS Connectors page). Your entire NPC→voice mapping - all 30
voicetypes, the race/gender fallbacks, every per-NPC assignment - carries over with **zero edits**,
because they all work the same way, from a reference WAV.

**Chatterbox (0.5B) is the one I would pick**, for reasons that are about your use case specifically:

* it is **local**, so there is **no acceptable-use policy at all** and no consent clause;
* it clones from the game's own voice samples, exactly as PocketTTS does now;
* at ~2-3 GB it is the largest model with a realistic chance of sharing your 16 GB card with LoreRim;
* MIT licensed, and it adds an emotion-exaggeration control, which is the single most useful knob for
  the dialogue you are writing.

**The catch, stated plainly: it puts the voice back on your graphics card**, which is what caused the
3.4 s waits in the first place. So Chatterbox and the CPU move are alternatives, not a sequence. Do
the CPU move tonight; treat Chatterbox as a separate experiment for a quiet evening, and measure
before and after.

**Every cloud option is out, and not for the reason you would expect.** Not the explicit content -
the **cloning**. Your whole voice design is "this NPC speaks in the voice Bethesda's actor recorded".
ElevenLabs, Cartesia and Inworld all require the consent of the person whose voice it is before you
may clone it. You do not have that and cannot get it, so uploading `femalenord.wav` is a policy
violation regardless of what you then make it say. OpenAI and Deepgram cannot clone at all. And
**Azure bans your use case twice over** - I read their Code of Conduct today (v4.0, dated 1 May 2026):
the "Sexually explicit content" section prohibits erotic and sexually explicit content *and*
applications, and usage restriction **13** separately bans chatbots that are erotic or romantic.
Locally, none of that exists - the audio never leaves your machine.

**On the model (E3):** keeping Grok 4.3 is a reasonable call and nothing above touches it. The voice
and the language model are separate problems; none of this changes which model writes her words.

### 4. What you do not need to do

Nothing here needs a Nemesis run, a LOOT run, BodySlide, or a new save. The voice fix is entirely
server-side and needs no game restart at all. The three INI files take effect on your next load.

If you want to confirm the voice fix yourself with one line, after playing a scene:

```
wsl -d DwemerAI4Skyrim3 -- bash -c "grep -c 'Audio processing failed for PocketTTS response' /var/www/html/HerikaServer/log/chim.log"
```

It reads **62** right now. If it is still 62 after a play session, the fix is doing its job on every
line. If it is climbing, it is not - and nothing is broken either way, because CHIM's old path still
produces the audio.
