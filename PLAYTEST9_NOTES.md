# Playtest 9 - v0.5.1 "the driver learns its own install" + services, truth, latency and followers

Installed 2026-09-22. Server deployed, game files copied, everything offline-green.
**Still a dry run.** Nothing clicks anything yet. That is on purpose and section 6 says how it ends.

**The follower work (v0.5.1) is NOT a dry run** - it is live the moment you load. Read section 1e
before you play; it contains the one switch that matters and it is in the CHIM web interface, not
in our MCM.

---

## 1. What changed since playtest 8

Four things, in the order you will notice them.

**(a) The glue now calibrates itself while you play.** v0.4 shipped with a 21-row probe evening -
you pressing a key 21 times and reading a log. That is gone. The driver now watches ordinary
conversations and works out, on its own, how *this* install's dialogue menu behaves: how to count
the topics, how to read them, how the progress timer moves, whether a hidden list comes back,
whether the vanilla menu still works after a hide. **Ten answers** are what the safety net needs.
It costs nothing - almost every measurement is a number the driver already had in its hand.

Two of the ten cannot be learned by watching, and one is opt-in. See section 3.

**(b) The dry-run switch is now locked shut until the calibration is green.** You can turn
`bDlgDryRun` off in the MCM; if the calibration is not finished the glue turns it straight back on,
says so in the corner, and writes one line to the log naming what is still missing. There is an
override (`bCalibOverride`) if you ever want past it. This is the main safety change of the round:
the feature can no longer go live on an install it has not measured.

**(c) Services are first-class** - what you asked for in the addendum. Bartering, training, inns,
carriages and ferries, and guard/crime talk go through the NPC's **real dialogue entry**
whenever she has one, so you get LoreRim's real prices and Requiem's real training rules, not
CHIM's flat 10/20/50 gold shortcuts. When the real entry is known, CHIM's own shortcut is hidden
for that turn so the two can never fire twice. The service census found **296 carriage/ferry rows
from CFTO.esp** and 26 multi-word destinations (Solitude Lighthouse, Baan Malur and the rest), so
"take me to X" resolves against your actual load order, not vanilla's 19 places.

**This only starts once `bMenuless` is on and the dry run is off.** While the module ships inert,
the game tells the server so and the server leaves CHIM's own Rent Room / Hire Carriage / Training /
inventory shortcuts completely alone - they behave exactly as they did in playtest 8. (That handshake
is new this morning and is the one code change made after the build lanes finished; see section 10.)

Arrests stay exactly as strict as before: an arrest hands you the visible menu, and "resist arrest"
is unselectable at every setting.

**(d) Three smaller things:** she can be given **locked facts** (a price or a check outcome the game
confirmed) that she is not allowed to contradict; a **truth gate** drops an ACTION carrying a number
the game never confirmed (her words still play, only the action is dropped); and every reply writes
**one latency line** saying how long you waited for her text and how long again for her voice -
see the caveat in section 7.

### (e) v0.5.1 - followers. Read this bit before you load.

This is the one part of the round that is **live immediately**, not behind the dry run.

**The one that matters - and it is not in our MCM.** Open the CHIM web interface
(`http://localhost:8081`, start your CHIM launcher first) → **Actions**, and switch **OFF**:

| action | shown as | why |
|---|---|---|
| `MakeFollower` | `Join_<your name>_Party` | **This is the bug.** It was written for Nether's Follower Framework, which this modlist does not have. Here it drops the person into the game's follower faction and stops - no companion slot, no framework, no way to dismiss her. Worse: the game's dismiss line, *"it's time for us to part ways"*, then goes to **whoever your real follower is**. |
| `Follow` | `Follow` | Follows another actor at priority 100 with no owner and no end condition. Nothing in the glue uses it. |

Leave **`FollowPlayer`** (`Follow_<your name>`) **ON** - the glue uses it, and it is the one CHIM
ends cleanly by itself. `WaitHere` is already off; leave it off.

The glue now hides both of those wherever it can see they cannot work, and repairs the damage if it
happens anyway - but those two switches are the only way it can never happen at all.

**New MCM page: LoreRim Glue → Followers** (5 controls). The defaults are right and you should not
need to touch them. In order:

* *She knows who travels with you* - tells the AI, in words, who is your companion, which framework
  owns her, whether she is following or waiting, and how many slots are left. It also makes the glue
  count your own companions separately from strangers, so a companion standing there stops reading as
  "a stranger is watching".
* *Follower commands use her real dialogue* - "come with me", "wait here", "you're dismissed",
  "let's trade", "this is your home now" go through the framework's own dialogue entry when she has
  one. **This half only wakes up once `bMenuless` is on and the dry run is off** (section 6).
* *Never hold someone who is already walking with you* - the conversation hold no longer freezes a
  companion that CHIM told to follow you. That freeze-on-the-spot fidget was a real symptom.
* *Fix a half-recruited follower* + *How to fix her* - leave the mode on **"Make her real if there is
  room"**. It checks when you talk to her and once per load; nothing happens to anyone who was
  recruited properly.

**Two CHIM settings to leave alone while a real follower is with you:**

* **CHIM → Keys, K2** ("make the NPC in your crosshair wait here") - do not press it at a follower.
  It writes a wait state the follower framework does not know about.
* **"force default voice"** - leave it **off**. It is an RDO workaround, RDO is not installed here,
  and it kills custom-voiced follower audio (Inigo, Lucien, Auri, Serana and the rest).

**Simple Follower Framework is your framework, not NFF.** `SimpleFollowerFramework.ini` has
`bFollowerOptionSelector=2` and `iSpeechLevelsPerSlot=25`, so your slots are Speech/25 + 1 - early
game that is **one** slot. If you want to exercise the follower paths this evening, either raise
Speech or temporarily set `bFollowerOptionSelector=0` with `iMaxFollowers=2`. SFF has no MCM; that
ini is the whole control surface.

**None of the follower work has ever been seen in Skyrim** - no playtest so far has had a real
follower present. It is the least-proven part of the round.

---

## 2. Before you start - the settings that are not ours

**Unchanged from PLAYTEST8 section 2.** The same five (Smart Talk's three keys at 0, and the two
CHIM/IACC values). Nothing this round changes them and nothing needs re-doing. If you already set
them for playtest 8 you are done; if not, go read that section.

---

## 3. The new evening - about twenty minutes

Play normally. Talk to people. That is most of it.

### Step 1 - three or four ordinary conversations (nothing to do)

Just play. An innkeeper, a merchant, a guard, anyone with a decent topic list. The passive
calibration runs inside those conversations and you should not see or feel anything at all.

**Where to look afterwards** - in `HerikaServer/log/lorerim_glue.log`:

```
CALIB set cm=2 src=passive ...
CALIB set rm=3 src=passive ...
CALIB set timer=1 src=passive ...
```

Each `CALIB set` line is one answer landing. The MCM's **Calibration** page shows the same thing in
one sentence - "not yet - 7 of 10: missing ..." - and names what is left.

### Step 2 - turn on the active pass, then a few more conversations

MCM -> **Calibration** -> tick **`bCalibActive`**. This is the only switch in the round that can
touch what you see: on separate conversations it hides the topic list for about a fifth of a second
and gives it straight back, with "calibrating - one moment" then "menu back" in the corner. It never
clicks anything.

It runs **the two the safety net needs first** (the state read-back, then the hide round trip), then
three optional ones. Budget is **five conversations** (`iCalibRuns`). It refuses to run on a guard,
on anyone you have a bounty with, during a scene, or with a barter / gift / training window open.

Look for:

```
CALIB active A2-readback ms=... ok=1 ...
CALIB active A1-hide ms=88 ok=1 gave_back=1 ...
```

When the budget is spent the status line says so - "active pass used 5 of 5, untick and tick it
again for more". That is also how you run another set: **untick `bCalibActive`, then tick it again.**
Leaving it ticked does nothing once the budget is gone.

If a hide ever takes longer than 200 ms the pass switches itself off for the rest of that play
session and says so - but the answer it measured is **kept**, which is a change from the build: an
overrun no longer throws away a hide that demonstrably came back.

### Step 3 - the two manual presses (this is the only key-pressing left)

Two answers need a human: one needs you to mash keys at a menu, one needs you to click a topic by
hand. Both are on the **Calibration** page as buttons.

The buttons now **arm** the test for your next conversation rather than trying to run it on the spot
(inside the MCM there is no dialogue menu open, which is why they could not work before). Press the
button, close the MCM, then go and talk to somebody - the test runs by itself on that conversation.

If a button does nothing on your install, the slider **`iProbePress`** on the *Menuless questing*
page does the same four presses: **33** click test, **34** click test the other way round,
**35** menu-still-works, **36** forget everything. For that route you must set a key under
**`iKeyProbe`** ("Run the probe press", Keys page) - it ships unbound. You do **not** need to arm
`bProbe`; those four presses work with the probe off.

**Press 1 - the click test.** Arm it, then walk up to an **innkeeper** (someone with a long topic
list) and open the conversation. It hides the list and asks you to **mash the mouse, E, Space and
the wheel for three seconds** - do that, properly, as if you were trying to force your way through.
It then clicks one topic for real and gives you the menu back. If the log says the click did not go
through, run it once more with the *other route* button (or `iProbePress = 34`).

**Press 2 - the menu-still-works test.** Arm it, then **open a conversation with anyone, by hand,
the normal way.** It checks that nothing the click test left behind can make your ordinary dialogue
unclickable. **It gives you three seconds - click any topic with the mouse while it watches, or it
cannot pass.** Just watching answers nothing and the row stays red. This is the one that catches the
worst possible failure mode of the whole feature, so do not skip it.

If anything ever leaves you with no visible topic list: **your emergency dialogue key gives it
back**, in every state, always. `iProbePress = 32` does the same. To wipe the calibration and start
over: `iProbePress = 36`.

### Step 4 - read one line

MCM -> Calibration. When it says **green - 10 of 10 answered**, you are done. If it names something
still missing after all of the above, tell me what it names - that is the real answer and it is worth
more than any guess I could make from here.

---

## 4. The quest talk you asked about, and its switches

All four are on the **Menuless questing** page unless noted.

| What | Setting | Ships | What it does |
|---|---|---|---|
| **"What can I ask you?"** | `bQuestSummary` (Quests) | **on** | She answers **in words**, in character. If the glue has really read her topic list this conversation she answers from that, exactly - these are really on the table. If it has not, she says so out loud ("I might have something about...") rather than pretending. She never reads it out as a list and never mentions a stage or a number. |
| **"What should I do next?"** | `bQuestNext` (Quests) | **on** | She reads back **your journal's current objective**, in her own voice. Current objective only, at most three, never a stage number. |
| **The corner hint** | `bQuestHint` (Quests) | **on** | A one-line note in the corner - "Lisette has something about ..." - when she is sitting on business you have not taken up. Deliberately shy: **once per session per NPC**, and only on a turn where nothing else happened and where she did not raise it herself. It is a nudge, never a replacement for talking. |
| **She brings it up herself** | `bQuestInitiative` (Quests) | **OFF** | The only one that ships off, because it changes how conversations start. With it on she may raise a quest matter unprompted. Guarded hard: once per topic ever, once per session, and a gap of `iQuestInitiativeGap` seconds (default 600). She will **never** open with a speech check, a payment, a commitment or anything that makes her walk away. |

Turn `bQuestInitiative` on only after the calibration is green and you have had a normal evening
with the other three.

---

## 5. The assisted hand-back

Some choices genuinely cannot be made by talking - a menu the glue cannot read, a lethal-class
entry, a branch this install cannot answer by voice. Before, that just meant the menu appeared and
you were on your own.

Now: the menu comes back **with a note saying so** ("choose this one by hand"), you click that one
choice, and the conversation **goes straight back to being menuless without greeting you again**.

Two switches, both on the Menuless questing page, both on by default:

* `bHandBackNote = 1` - the note. Off means the menu still comes back, silently.
* `bResumeAfterChoice = 1` - the resume. Off means it stays manual until the next conversation.

A missing line in the ini reads as **0**, so leave both lines in place even if you never touch them.

**None of this is live yet** - it is all behind `bMenuless`, which ships off. It is here so you know
what to expect on the evening you switch it on.

---

## 6. What is still not live, and why

* **`bMenuless = 0` and `bDlgDryRun = 1`.** Nothing is clicked and no conversation is opened by the
  glue. Every dialogue is still a normal visible menu. This is the shipped state and it is correct:
  the round added the calibration precisely so that going live is a measurement, not a leap. When
  the Calibration page reads green, set `bMenuless = 1` and leave `bDlgDryRun = 1` for one evening -
  the log will say **WOULD CLICK** where it would have clicked and you click by hand. Only then turn
  the dry run off.
* **`bQuestInitiative = 0`** - see section 4.
* **The follower *verbs* are behind the same switch.** "Come with me" / "wait here" / "you're
  dismissed" only route to her real dialogue entry once `bMenuless` is on and the dry run is off -
  they are menuless questing. Everything else on the Followers page (section 1e) is live now: the
  follower facts the AI is told, the `<companion_status>` block, the hidden "join my party", the
  conversation-hold skip and the half-recruit repair.
* **The MCM Calibration buttons are still untested in game.** They now arm rather than fire, which
  is the part that was provably broken; whether MCM Helper reaches them at all on your install is
  still an open question. The `iProbePress` route (33-36, with a key set for `iKeyProbe`) is the
  fallback and it no longer needs `bProbe`.
* **Nothing at all was tested in game.** Everything below is offline: compiler, tests, hand-traced
  proofs. Every real answer in this round comes out of your evening, not out of my run.

---

## 7. Your latency question - where the wait really is

Every link in the chain was measured on your own machine. The short version, and it is not the
answer either of us expected:

| link | today | what it could be |
|---|---|---|
| push-to-talk -> text (Parakeet STT) | 0.13 s | leave it alone, it is not the problem |
| the server before the LLM | 0.61 s, of which our prompt blocks are **58 ms** | nothing to win here |
| the LLM, first finished sentence | ~1.5 s (the whole reply ~6.5 s) | ~1 s at best, and it costs a refusal risk |
| **PocketTTS, one sentence** | **3.41 s median, 6.9 s p90, 97.6 s worst** | **0.09 s of actual work** |
| leading silence in every wav | ~0.28 s | 0 s, with a one-line core edit |

**The TTS line is the whole story, and the reason is not what the first pass said.** PocketTTS is
not slow. On your box, with the game closed, it synthesises a normal NPC line in **0.089 s on the
GPU** - 33x faster than realtime. The same service, same model, same config, during your play
session took **3.41 s at the median and 97.6 s at its worst**, and the two worst calls in the whole
evening were a 28-character line and a 22-character line. Length has nothing to do with it. That is
not a slow synthesiser; that is a synthesiser **starved of the GPU while Skyrim renders on it**.

So the fix is to stop the two of them sharing one card:

> **Set PocketTTS to the CPU.** Edit `/home/dwemer/audio.cpp/server.json`:
> `"backend": "cpu"`, `"threads": 8`. There is **no web-UI field for this** - it is the one change
> in this section that is not on a page. Do it with Skyrim and the CHIM server stopped:
> ```
> wsl -d DwemerAI4Skyrim3 -- bash -c 'cp /home/dwemer/audio.cpp/server.json /home/dwemer/audio.cpp/server.json.bak'
> # then edit server.json: "backend": "cpu",  "threads": 8
> ```
> then start CHIM and Skyrim the normal way. **Revert = restore the `.bak`** (`"backend": "cuda"`,
> `"threads": 1`) and restart.

**Threads 8, not 12 or 16.** Measured on your CPU: 4 threads 0.703 s median, **8 threads 0.615 s**,
12 threads 0.618 s, 16 threads 0.627 s. Past 8 it buys nothing measurable and it leaves 24 of your
32 logical cores for Skyrim.

**What it costs and what it buys, honestly.** Measured cost of the move, game not running:
0.089 s -> **0.615 s** median, 0.172 s -> **1.447 s** p90. That is the part that is measured.
The benefit - losing the 3.41 s median and the multi-second tail - is *inferred* from your own
session's bimodal log, because I could not reproduce Skyrim's GPU pressure without Skyrim running.
Expected net if that reading is right: about **-2.8 s at the median, -5.5 s at p90, and the tail
gone**. It is the robust choice, not the fast one: the GPU's ceiling is far better if you would
rather cap Skyrim's framerate and stay on CUDA.

**The ten-minute test that settles it.** Play one scene you know produces long replies (~20 spoken
lines, at least one over 120 characters). Quit, then:

```
wsl -d DwemerAI4Skyrim3 -- bash -c "grep -h 'total call time' /var/www/html/HerikaServer/soundcache/*.txt | sed 's/.*total call time://' | sort -n | awk '{a[NR]=\$1} END {print \"n=\" NR, \" median=\" a[int(NR/2)], \" p90=\" a[int(NR*0.9)], \" max=\" a[NR]}'"
```

Clear or move the old `soundcache/*.txt` first so you are reading only the new scene.
**Pass** = median under ~1.0 s, p90 under ~2.0 s, nothing over 5 s. **Fail** = still seconds; then
the GPU was not the cause, revert from the `.bak`, and the next suspect is how many CHIM processes
call TTS at once. If Skyrim's frametimes get worse, drop `threads` to 4 first (14% slower TTS).

### The ~0.28 s of silence at the front of every line - your call, and it is a core file

Every wav PocketTTS returns starts with **0.25-0.31 s of silence** (measured on both backends, so
moving to the CPU does not fix it). CHIM is supposed to trim it with a daemon called `audiofilterd`.

**That daemon does not exist on your system.** Not stopped, not disabled - never shipped. Your CHIM
3.3.2 snapshot contains the *client* half (`tts/audiofilterd_client.php`) and nothing else: no
binary, no package, no socket, no service script, and nothing in the HerikaServer git history that
ever added one. So every single TTS call throws `Audio processing failed for PocketTTS response`
(that is the ~248 error lines an evening), falls back to `ffmpeg`, and ffmpeg is handed an **empty**
filter string - a straight re-encode with the silence intact. Any instruction of the form "start
audiofilterd" would be made up.

There are exactly three honest options, and **the middle one is a CHIM core edit, so it is your
decision, not mine**:

1. **Do nothing.** It costs ~0.28 s of dead air per line and two log lines per call, and it survives
   every CHIM update. Perfectly defensible.
2. **One line in a CHIM core file.** In `/var/www/html/HerikaServer/tts/tts-pockettts.php` (~line
   448) the audio.cpp branch sets `$FFMPEG_FILTER='';` with the trim commented out directly above
   it. Setting it to
   `-af "silenceremove=start_periods=1:start_silence=0.05:start_threshold=-40dB"`
   makes ffmpeg do what the daemon would have done, at a measured cost of ~55 ms. **CHIM owns that
   file** - a CHIM update will overwrite it and you would re-apply by hand. I have not touched it and
   will not without you saying so.
3. Wait for a CHIM release that ships the daemon.

### How to read the latency line the glue now writes

Every reply writes one line to `lorerim_glue.log`:

```
dlg lat npc=Lisette total=7810ms reply=6390ms voice=1420ms first=1
dlg lat SLOW npc=... total=12400ms over=9000 ...
```

* `total=` your whole wait, from the end of your own spoken line to the start of hers.
* `reply=` how long until her **words existed** (STT + server + LLM).
* `voice=` the **TTS gap** - words to first audio. This is the number that should fall when you move
  PocketTTS to the CPU, and it is the one CHIM's own marks never measured.
* `first=1` marks the one **extra paid reply** that reading an NPC's dialogue list for the first
  time costs you (the `bIntentOpen` turn) - so you can see what that option is worth in seconds,
  not just in principle.
* `SLOW` appears when `total` crosses `iLatencyWarnMs`. `bLatencyLog` and `iLatencyWarnMs` are on
  the Diagnostics page. It costs 2-7 ms server side and is never sent to the AI.

> **Caveat, and it matters:** the measurement starts when CHIM finishes voicing **your own** line.
> With CHIM's player TTS / re-speech turned **off** there is no zero to measure from and **no `LAT`
> line appears at all** - not an error, just nothing. The self-test line
> `dlg self-test chim tts=` in the log says which you have. If you want these numbers, that switch
> has to be on.

### On the model switch - Grok vs DeepSeek V4 Flash

Your connectors, read from your own database. The page is **LLM Connectors**,
`http://localhost:8081/HerikaServer/ui/core/llm_connectors.php` (note the **:8081** - CHIM's UI is
not on port 80 here):

* row **`Grok 4.3`** (id 10) - reasoning explicitly set to `effort: none`.
* row **`DeepSeek V4 Flash`** (id 1) - `reasoning_model = 1` with **no** extra parameters at all.

So the straight swap is a real risk: V4 Flash as configured may **think before every line** and come
out *slower* than the Grok you have now. And when it was measured head to head it *was* the slowest
of six models through your connector - not because the model is slow, but because OpenRouter routed
it to two different resellers inside six requests. Do not switch blind. To fix its row first: in its
edit form, **`Include Body Parameters (YAML)`** (the editor near the bottom) ->
`reasoning:` / `  effort: none`, tick the toggle beside it, leave **Disable Streaming** unticked,
and optionally pin the **`Provider`** field (the free-text box under **`Model`**) to stop the
routing wandering.

**The setting that actually changes the voice you are waiting for** is the **profile's Standard
LLM**: **Profiles** -> `http://localhost:8081/HerikaServer/ui/core/core_profiles.php` -> edit
**`Default Profile`** -> the **🕹️ Standard** slot. In the database that is
`core_profiles.llm_primary_id`, and it is `10` today. (An earlier draft of these notes named
`CORE_CONNECTOR_DIRECTOR`. That is the **Director Mode 🎬** slot on the Global Settings page - a
different feature. It happens to be `10` as well, which is how the two got confused; changing it
would have done nothing to your reply latency.)

The other slots on that profile, as the UI labels them: **⚡ Fast LLM** = DeepSeek V4 Flash (already),
**💪 Powerful LLM** = GLM 5.2, **🧪 Experimental LLM** = DeepSeek V4 Pro, formatter = Ministral 8B.

**Two Grok-priced slots you never wait on**, on the Global Settings page: **Director Mode 🎬** and
**Profile Tasks 👥** are both Grok 4.3. (**Scene Classifier 🎭** is a third but is switched off.)
Neither is on the voice path, so neither affects your wait - they affect the bill. Grok is $8.39 per
1,000 turns against $1.76 for the fastest model measured.

`tools/bench_llm.php` measures your own connector rows head to head - time to first token, total,
p90, strict-JSON compliance and cost per real turn - and changes nothing.
`php tools/bench_llm.php --list` prints the rows side by side and marks which one is the reply.
The real run costs a few cents of real tokens, which is why I did not run it for you.

**Whatever the table says, one thing it cannot tell you:** how a model behaves on *your* content.
The benchmark prompt is deliberately clean. A Google or OpenAI model that softens or refuses one
explicit line costs a whole turn plus a retry - far more than the tenth of a second it wins on
speed. If you try one, decide it in a single scene and revert the same minute if she goes coy.

### Three more settings, with the exact page and the exact wording

1. **Drop three prompt sections.** **Global Settings** ->
   `http://localhost:8081/HerikaServer/ui/global_settings.php` -> card **Context Selections** ->
   **Top-Level Sections**. Untick **`<nearby_items>`**, **`<points_of_interest>`** and
   **`<group_descriptions>`** (the checkboxes really do show the angle brackets). Leave the other
   eight ticked. Worth -0.1 to -0.4 s on crowded turns.
2. **Connector 7 `Gemma 3N E4B` is a landmine, not a cost.** Verified: **nothing points at it**, and
   the model no longer exists on OpenRouter. On the **LLM Connectors** page, either delete the row or
   repoint its **Model** to something real. It costs you nothing today and would fail loudly the day
   something selected it.
3. **`CONTEXT_HISTORY` is 75, not 50.** There are two rows with that name; only the profile one is
   read. **Profiles** -> **`Default Profile`** -> metadata editor -> section **Context** ->
   **`CONTEXT_HISTORY`**. The `conf_opts` row that says 50 is dead code and changes nothing.

### What the glue's own latency work is worth - corrected

The first write-up of this claimed the new pacing rails remove **~133 s** of blocking work per
nine-hour session. That number was the cost of *every* unsolicited turn she took, i.e. what you
would save if the rails dropped all of them. They do not. Replayed tick by tick against your real
session, with the rails' actual conditions: **~33 s**, about 5% of server time on your turns - but
it lands where it hurts, removing two of the three worst pile-ups of the evening. Worth shipping;
not worth claiming four times over.

Two prompt trims that shipped with it have been **taken back out**, because they were narrowings of
what *she* can choose dressed up as removals of redundancy: one dropped her whole act vocabulary on
exactly the turn you had just asked for something, and the other left three acts - including the
wind-down - with no positions at all. Both were worth about a tenth of a second. Nothing you can
ask for was ever affected either way. Net effect on prompt size after the correction: **zero**; the
seconds this round buys are the rails above and, far more, the TTS change.

**One honest finding about fact-locking while I was in there:** CHIM's `lock_profile` and
`relationships_locked` are *write protection on your authored profile* - they are not "facts the AI
may not contradict", and CHIM core has **no** verifier of the AI's claims anywhere. The real
mechanism is the action catalog's `requirements`, which the glue now uses: our rows declare their
own preconditions through CHIM's own machinery, so CHIM hides them when they are false. Our truth
gate sits on top of that. Note this also means the glue's five action rows are **reinstalled once**
on the first request after this deploy, to pick the new preconditions up.

---

## 8. What I verified before installing this

*(Numbers refreshed 2026-09-22 08:15 after the v0.5.1 fix pass. The figures this section carried
before were the 06:30 v0.5.0 ones and every one of them was out of date.)*

* `compile.ps1` - **OK**, all **10** scripts (`LRG_Followers.pex` is the new one), 0 errors,
  0 warnings.
* ESP unchanged this round; re-validated anyway with `esp_dump.py` - valid.
* `php -l` over **74 files** - 0 errors, and 0 again over the 14 `.php` files of the deployed copy.
* Every test green: gates **319**, intent 190, phrases 14 (91.9% hit rate, floor 82%), scene index
  (607 scenes), dialogue **132**, prompt index 67 + **112 against the live database**, MCM wiring
  (**113 controls**, every one with an ini line and a reader), services **35**, latency **26**.
* `run_flows.php --strict`: **74 scenarios, 74 passed, 1161 checks, 0 warnings.**
* Server deployed at 08:15: **28 anchors**, index in its **own schema** (`lrg_index`, 37,561 prompts /
  5,718 layers, `source=db`), service catalog rebuilt, scene index warm (607 scenes). **No
  lorerim_glue errors in any HerikaServer log.**
* Game files: **24 compared by SHA-256, 13 copied, 0 mismatches** (see section 9).

---

## 9. Three things for you to do

1. **Mod Organizer was open**, so the installer refused to touch your profile - correctly. I copied
   **only our own files** into `F:\Modlists\LoreRim\mods\LoreRim Glue`: **24 files compared by
   SHA-256, 13 copied and re-hashed after the copy, 0 mismatches** - all **10** `.pex` and the three
   `.psc` sources that changed in the fix pass (`LRG_Followers`, `LRG_Main`, `LRG_Profile`); the ESP,
   the seq file, the other seven `.psc` and the two MCM files were already byte-identical. **No
   profile file, no other mod, nothing under `SKSE\` or `meshes\`.**
   `LoreRim Glue` is already ticked in your load order from playtest 8, so there is nothing to
   enable. MO2's left pane still shows **`0.1.0`** next to the mod - `meta.ini`'s version line is
   written by the installer, which could not run, and it has never been refreshed since the very
   first install. Cosmetic; one run of `tools\install_mo2.ps1` with MO2 closed sets it to `0.5.1`.
2. **A leftover scratch database.** A scratch Postgres database called **`lrg_t`** was created
   during the build so the 37,561-row migration could be tested without touching your live one. It
   is still there and nothing uses it. `dropdb -h localhost -U dwemer lrg_t` removes it - I left it
   alone rather than drop a database on your machine unasked.
3. **If you ever roll the server back to 0.4.1, move the index back first.** The prompt index now
   lives in its own Postgres schema (`lrg_index`) instead of `public`, so 0.4.1 code would look for
   `public.lrg_prompt`, not find it, and run **unindexed** - which is quieter and slower, not
   broken, but confusing. Before reverting the files, run:
   `ALTER TABLE lrg_index.lrg_prompt SET SCHEMA public;` and the same for `lrg_prompt_layer`.
   Your playthrough tables (`lrg_dialogue`, `lrg_memory`, `lrg_npc_state`, `lrg_romance`,
   `lrg_scene_state`, `lrg_turn`) never moved and are untouched.

*(The three cents of tokens spent by the model benchmark during the build stands from yesterday's
note. That file has since been **renamed to `tools/bench_llm_models.php`** and now **defaults to a
dry run**: it sends nothing and spends nothing unless you pass `--live`. Under its old name,
`test_latency_models.php`, it was one careless `for f in tools/test_*.php` away from spending your
money during a release pass.)*

---

## 10. The one code change made after the build lanes finished

`LRG_Profile.psc` now sends one more character on the snapshot: `ml=` - "the driver can really run a
hidden session right now" (`bMenuless` **and** not `bDlgDryRun`). The server half was already
written and waiting for it.

Without it the server had no way to know the module was inert, so on any NPC whose menu it had read
in the last 30 minutes it would have hidden CHIM's own **Rent Room / Hire Carriage / Hire Ferry /
Training / open-inventory** actions and offered its own instead - which the game would then have
refused, because the feature is switched off. In practice: no renting a room, no hiring a carriage,
no training and no merchant inventory **by voice** for the whole evening. That is now fixed and is
covered by three flow checks (`ml=0`, `ml=1`, and an older game script that sends neither).

Two smaller corrections rode along: `tools/bench_llm.php` names the right setting (section 7), and
the MCM wiring gate now asserts the `ml` wire key so it cannot quietly die again.

---

## 11. Go-live review - the last pass before the switch is flipped

Before you turn `bMenuless` on and `bDlgDryRun` off, the whole menuless-questing path was reviewed
once more, line by line, against twenty-four reported defects. **Every one of them held up under
tracing, and every one is now fixed, tested and installed.** Nothing was dismissed.

This section is what was found, in plain words. There is nothing here for you to do - it is a record,
so that if something does go wrong tonight you can tell whether it is one of these or something new.

### 11a. The four that would have reached you first

**1. The emergency key could leave you stuck - the one key that must never fail.**
There is a window of a few seconds while the glue is opening a conversation with someone: it is
waiting for her voice to be ready and retrying the activation. Press the emergency key inside that
window and the driver took a path meant for a crashed session: it tried to un-hide a menu that did not
exist yet, and while it was waiting, the real conversation opened *behind* it, hid itself and armed.
The key press then landed on top and wrote "this menu is the player's" over a menu that was hidden and
guarded - and nothing in the driver ever un-hides a menu in that state. You would have been looking at
an empty screen with a "press TAB" notification, and TAB does not clear the flag either, so the *next*
conversation would have started confused as well.

Now the key cancels the open outright: no session is started, the conversation that arrives is
visible, and you get the ordinary menu. Two smaller ways the same key could quietly do nothing were
fixed with it - a press during the last second of a conversation used to be swallowed and then fire on
a completely different NPC minutes later, giving you an unexplained "choose this one by hand".

**2. Mentioning a hold was read as ordering a ride there.**
The destination matcher checks whether the words you said contain the name of a place on her list. It
never checked whether you were *asking for anything*. So "My brother lives in Morthal", "I've just
come from Riften", "Is the road to Dawnstar safe?", and even a character introducing himself as
Riften, all resolved to "take me there" - and on a carriage list with the right shape that spends gold
and teleports you. On the vanilla list this was accidentally deferred by an unrelated safety park,
which the very next turn then released; on three of the real Kmod carriage layers it would have fired
on the first turn with no confirmation at all.

She now needs to be actually asked. Either the sentence is a request she recognises ("take me to",
"a ride to", "rent a room", "train me in"), or the whole thing is just the name ("Morthal."). Anything
else and she asks which you mean instead of acting.

**3. A refusal could confirm the very thing you refused.**
Consequential choices are two-step: the first selection parks and she asks you to confirm. The release
only ever checked that the second selection came from a different request - it never checked that you
had agreed, or that you had spoken at all. So "Take me to Solitude" followed by "No, forget it, I have
changed my mind entirely" paid the fifty septims and moved you. And on a background poll - where you
had said nothing whatsoever - the model re-offering the same item by itself was enough to commit it.

Now: no confirmation without a real spoken turn of yours since the park; the same sentence arriving
twice is not a second answer; and words that refuse ("no", "forget it", "never mind", "changed my
mind", "on second thought") cancel the parked item rather than leaving it armed for the next turn to
release.

**4. The fast path had no arrest rail.**
There are two routes a decision can take: the slow one through the language model, and a fast one that
skips it when your words match an entry outright. The slow one hands you the visible menu the moment
it detects an arrest. The fast one did not - it had the "lethal" test only, and that test depends on a
grade the game only assigns when it is certain. A hold enforcer added by a mod, or a guard whose crime
faction reports no bounty, arrives ungraded. On that layer, "I submit, take me to the cells" went to
jail by voice with no menu ever shown.

The fast path now takes the same two rails the slow one has, word for word: resist-arrest is never
selectable at any setting, and an arrest session hands the menu back.

### 11b. The rest

* **A long list had everything past the sixteenth entry structurally unclickable.** A jarl, a quest
  giver mid-chain, an innkeeper with a full rumours list - the driver reads the first sixteen in
  detail and forwards the rest by text, and the server happily ranks and picks one of the rest. The
  click then could not be resolved, the decision died on a timeout, and the hidden menu sat frozen for
  up to 45 seconds before the watchdog gave it back. Repeatable on every long-list NPC. Those entries
  are now clickable; the driver verifies the entry's live text before touching it exactly as it does
  for the first sixteen.
* **A quest scene started by the glue's own click was driven anyway.** "Lead the way", "I'll follow
  you" - any walk-and-talk. The driver checks for a scene once, when the conversation opens, and never
  again, so a scene its own click created was invisible to it and it carried on clicking inside a
  scripted sequence. It now re-checks once per layer and hands the menu back.
* **Combat starting mid-conversation was never noticed.** A bandit ambush or a guard's arrest greeting
  after the conversation had armed: the driver kept clicking through the fight. It now checks while it
  waits for an answer.
* **7.7% of decisions shipped with no text to check the click against.** The server trims very short
  entry texts to nothing before sending them, which left the game with position alone to decide - on
  exactly the entries where that costs money: "Morthal.", "Riften.", "Dawnstar.", "Falkreath." Fixed.
* **The truth gate cancelled rides she had already agreed to.** It dropped any action on a reply
  containing a number next to a money word, anywhere. "Aye. I lost 300 gold at dice in Riften last
  winter. Climb up." - and the ride silently did not happen while you watched her agree out loud. It
  now needs the number to actually be quoted as a price.
* **`bLockedFacts = 0` silently switched the truth gate off too**, which is the reverse of what both
  help texts promise. The two switches are now genuinely independent.
* **She was told her own list was one destination long.** On a turn where a destination matched, the
  "facts you may state as true" block named only that one - under a header saying these are confirmed
  facts - so she would deny the rest of her own list. It now names the whole layer.
* **"Follow me." and "Wait here." did nothing at all.** They lost a name lookup to an unrelated Civil
  War topic and then failed a two-word minimum, while CHIM's own Follow / WaitHere shortcuts had
  already been hidden for the turn on the strength of the commands that *did* resolve. The two
  most-used follower commands in the game, with their fallback removed. Fixed.
* **One passing sentence granted Speech XP for ever**, and a repeated bribe kept passing while taking
  no gold - the same guard bribed free from the second attempt onward. A remembered result now keeps
  its verdict for the conversation and pays nothing.
* **A spelled-out bribe was recognised but priced at zero**, so "Here is two hundred gold, look the
  other way" never moved anything and the "he offered less than she wants" rule was skipped entirely.
* **Two narrower ones:** short quest lists ("feim / fus / yol", "heavy / light / medium") were being
  treated as price lists, which switched off paraphrase matching on 2.4% of layers; and an action this
  module had hidden for the turn was still forwarded if the model wrote it out anyway.
* **A hide lost to a crash latched for the rest of the save**, making every later ordinary menu take a
  full un-hide it did not need and refusing every calibration test. It is now cleared on load.
* **A conversation that opened while the previous one was closing got no setup at all** - no guard
  reset, no hide, nothing sent to the server - while the rest of the mod still believed a session was
  live. A courier or a forced greeting a second or two after a click was enough. It is now armed
  properly on a stack of its own.
* Three more small ones: a middle-only change to a list was never re-sent, so the server ranked
  against positions that had moved; the "another window opened on top" detection hung on a value
  nobody has measured on this install, so a trade window over a driven conversation could be missed
  entirely and un-hidden underneath you mid-trade; and `smartSafe` was read once per load, so fixing
  `SmartTalk.ini` did nothing until a restart while the Calibration page already showed green.

### 11c. What is green after all that

Compiler **0 errors, 0 warnings**, 10 scripts. PHP lint **74 files, 0 errors**, and 14 again over the
deployed copy. Every test green: gates **319**, intent 190, phrases 14, scene index 607 scenes,
dialogue **174** (42 new checks), prompt index 67 plus **112 against the live database**, MCM wiring,
services 35, latency 26. **74 of 74** conversation scenarios, **1,179 checks, 0 warnings** - 18 of
those checks are new and exist only to hold these fixes in place.

Server re-deployed: 28 anchors, index in its own schema (37,561 prompts / 5,718 layers), service
catalog rebuilt, scene index warm (607 scenes), **no lorerim_glue errors in any HerikaServer log**.

Mod Organizer was open again, so the installer refused again - correctly - and only our own files were
copied into `F:\Modlists\LoreRim\mods\LoreRim Glue`: **24 compared by SHA-256, 12 copied and re-hashed
after the copy, 0 mismatches** (all 10 `.pex`, plus `LRG_Dialogue.psc` and `LRG_DlgUI.psc`). No
profile file, no other mod, nothing under `SKSE\` or `meshes\`.

**Nothing about the shipped state changed.** `bMenuless` is still 0 and `bDlgDryRun` is still 1. The
feature is still inert and section 6 still says how it ends.
