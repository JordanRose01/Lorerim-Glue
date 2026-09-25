# Playtest 8 - v0.4.0 "menuless questing (dry run)" + three intimacy fixes

Installed 2026-09-22, re-installed the same day after a fix pass. Server deployed, mod files copied,
nothing tested in game yet.

**If you already read this file before 02:30 on the 22nd, re-read section 3.** Sixteen defects were
fixed after the first install and two of them were in the probe instructions themselves: the one
command section 3.3 gave you matched nothing, and the probe key was dead in the session you armed it
in. Both are fixed. Section 11 lists everything that changed.

---

## 1. What this build is

**Menuless questing ships INERT.** The whole machine is built - the driver, the UI layer, the offline
prompt index of every dialogue line in your load order, the speech checks, the quest-tree awareness -
but two switches keep it from touching anything:

* `bMenuless = 0` - the glue never opens a conversation and every menu stays the normal, visible,
  clickable one you have today.
* `bDlgDryRun = 1` - even with the first switch on, the click step only writes `WOULD CLICK` in the log.

So **if you just play, nothing changes.** Quest dialogue works exactly as it did yesterday. The
evening below is what turns the machine from "built" into "known to work".

**Three intimacy changes ARE live** and need no probe: paying for it, being left in OStim's camera
after a scene, and closed doors making a room private. Section 7.

---

## 2. Before you start - five settings that are NOT ours

The glue writes none of these. Two of them decide whether menuless questing can ever leave dry run.

| Where | Set it to | Why |
|---|---|---|
| **CHIM MCM** | **AI Quest Progression OFF** | two engines would set the same quest stage twice. The glue checks this and stands down with a log line if it is on. |
| **CHIM MCM** | Player TTS for Traditional Dialogue **OFF**, Vanilla Dialogue capture **ON**, NPC Scene Safety **ON** | these are already right on your install - just do not change them. |
| **IACC** (Alternate Conversation Camera, its MCM or `AlternateConversationCamera.ini`) | **`bForceFirstPerson = 0` and `bHideDialogueMenu = 0`**, permanently - or its own "Disable in First + Third Person" | this is decision **D2**. Without it every hidden session snaps you into first person, locks the camera on her face and zooms. The alternative - letting the glue flip IACC on and off per conversation - writes IACC's ini on every call and can strand the camera, so we do not do it. `bIaccToggle` exists in our MCM and is **off**; leave it off. |
| **Smart Talk** (`SmartTalk.ini`) | **`bSkipImmediateOnInput = 0` and `bHoldToSkip = 0`** | this is a hard precondition, not a preference. While either is `1` (they are `1` today) a stray mouse or keyboard press can skip a quest line out from under the glue, and `bMenuless` may not leave dry run. Also nice: `bDBVOIntegration = 0`. **Never touch `iPapyrusHandle`** (leave it at 3). |
| **Helmet Toggle 2** | `HT_EnableDialogue` **off**, but only once menuless is really on | it re-checks your helmet every time a dialogue menu opens and closes, so your helmet would pop off and back on every single turn. |
| **Fuz Ro D-oh** | `WordsPerSecondSilence` 3 or 4 (it is 2) | unvoiced lines freeze you for up to 10 seconds each. |

---

## 3. The probe evening

The probe is a set of test presses that print measurements into the server log. It answers the
questions the design could not answer from reading files - how the dialogue menu really behaves on
YOUR install. **Nothing the probe does can change a quest**, with one exception called out below.

### 3.1 Arm it

MCM > LoreRim Glue > **Menuless questing** page, bottom:

1. **Arm the probe** -> ON (`bProbe`)
2. **Which probe press** (`iProbePress`) -> leave at **0** for now (0 = the key cycles the five
   main presses in order)
3. **Hotkeys** section on the same page: **Run the probe press** (`iKeyProbe`) -> pick a free key.
4. **Keys** page: **Open vanilla dialogue (emergency)** (`iKeyVanillaMenu`) -> **pick a free key and
   do it now.** It is unbound out of the box. This is your escape hatch (section 5).
5. **Keys** page: **Dump topics** (`iKeyDumpTopics`) -> pick a free key, used in 4.1.
6. Make a save game you are happy to reload, in case a press leaves something odd.

**The key works straight away** - no reload needed. Arming the probe, or changing any hotkey,
re-registers it on the spot. You will see `probe armed, key <n>` in the log; if that line is missing,
the key is not bound and nothing will happen when you press it.

### 3.2 The order of presses

This order matters - later presses depend on what earlier ones measured. Set `iProbePress` to the
number, walk up to the NPC, press your probe key, then set the next number. Everything lands in
`lorerim_glue.log` as lines starting `P<number>`.

| # | `iProbePress` | Who to stand in front of | What to do |
|---|---|---|---|
| 1 | **10** | any NPC, **before** you have talked to her this session | press once, standing in front of her, no conversation open |
| 2 | **11** | the same NPC, **after** one normal CHIM exchange | say something to her, wait for her answer, then press |
| 3 | **12** | any NPC | press once; it opens and closes a conversation a few times by itself |
| 4 | **0**, press **1** of the cycle | an **innkeeper** (someone with a long topic list) | press; a visible conversation opens and the list is read |
| 5 | **13** | same innkeeper, conversation open | press and then have a normal exchange; it samples the menu's internal state the whole time |
| 6 | **14** | same innkeeper | press once (timing only) |
| 7 | **0**, press **2** of the cycle | same innkeeper | press; the menu is hidden and guarded. **Press your emergency key afterwards to get it back.** |
| 8 | **15** | same innkeeper | press; compares the two ways of hiding |
| 9 | **17** | anybody, mid-response | press, then **hold the left mouse button down** while she talks. This is the Smart Talk test. |
| 10 | **18** and **19** | anywhere a courier or a blocking greeting can interrupt you | press, then let the interruption happen |
| 11 | **21** | any NPC | press once |
| 12 | **20** | any NPC with a list | press once - it deliberately tries a WRONG entry and must refuse |
| 13 | **0**, presses **3** and **4** of the cycle | an NPC with a **harmless** list (an innkeeper, not a guard, not a quest giver mid-quest) | press 3, then press 4 - these really click, one entry each |
| 14 | **0**, press **5** of the cycle | same NPC | closes the conversation |
| 15 | **22** | **the most important press.** Immediately after 14: re-open the conversation **by hand** (E), then press | it reads whether the previous hide left anything poisoned behind |
| 16 | **23** | a harmless closed choice layer only - **never a guard, never an arrest** | **two presses.** The first one may close the menu (the corner note says so); re-open by hand, then press again |
| 17 | **16** | any NPC with a list | one press, harmless. It is the same hide as cycle press 2 but on the other menu layout, so it is only meaningful if you ever swap the dialogue-interface mod - skip it otherwise |
| 18 | **24**, **25**, **26**, **27** | any NPC | one press each, any order |
| 19 | **28** | a **merchant**, with the barter window open | press while trading |
| 20 | **29** | any NPC, then **stand still and do nothing for two minutes** | press and wait |
| 21 | **30** | an NPC with a quest topic in her list | press once |

If you want to redo the read calibration from scratch, `iProbePress = 31` resets it.

**If a press ever leaves the menu hidden or stuck:** press your emergency key, or set
`iProbePress = 32` and press the probe key - either one gives the menu straight back. Every press
that hides the menu is supposed to give it back by itself; these are the backstop.

**Two things about the cycle (`iProbePress = 0`):** it starts again at press 1 after every game load,
so do rows 13 and 14 in one sitting; and rows 4, 7, 13 and 14 above are cycle presses 1, 2, 3+4 and 5
in that order.

**One measurement now comes for free.** Every time a dialogue menu opens, the log line
`arming sid=... apd=<n>` carries the menu's own `ALLOW_PROGRESS_DELAY`. If you ever see
`apd=100000000` on a menu you opened yourself, right after a probe press that hid one, that is the
answer to the question press 22 exists for - send that line too.

**Also worth doing once, read-only (never press 3, 4 or 23 on these):** a guard who force-greets you,
an NPC standing in a market-banter scene, a follower, and one run with a gamepad plugged in.

### 3.3 What to send back

The whole of `lorerim_glue.log` is easiest, but if you want to trim it, these are the lines that
matter. Inside WSL:

```
grep -E "PROBE P[0-9]+[a-z]?" /var/www/html/HerikaServer/log/lorerim_glue.log > probe.txt
```

or on Windows, the same thing by eye: **every line containing `GAME PROBE`**. The word `PROBE` sits
well into the line, after the timestamp and the `[cid=...]`, like this:

```
2026-09-22 00:43:34 -04:00 [cid=g2690-15] GAME PROBE P2 ...
```

The press names you should see are `P0`, `P0b`, `P1`, `P2`, `P3`, `P3t`, `P4`, `P5`, `P6`, `P6f`,
`P7`, `P7s`, `P7p`, `P8`, `P8v`, `P9`, `P10`, `P11`, `P11b`, `P12`, `P13`, `P14`, `P15`, `P16`, `P17`.

Plus: anything that looks like an error, and whether **the menu ever stayed stuck** - that is the one
result that would stop the feature.

### 3.4 Three things the probe cannot measure (do not be alarmed by them)

* `P11b walkaway_line=-1` - there is no way from Papyrus to hear whether the engine played a
  walk-away line. You have to tell us: did she say anything when the conversation was force-closed?
* `P11 click_mouse / click_e / click_enter` - the probe gives you a 3 second window and asks you to
  press; it infers the answer from whether the list moved.
* `P14 arrived_ms=-1` - the arrival time is in the ordinary `command ExtCmdLRG_SelectTopic` line that
  the glue already writes, carrying the same `x` number the press printed. Match them by eye.

---

## 4. The dry-run conversation test (no probe needed)

This is the real thing, with the brakes on. It needs the probe's results first for the read settings,
but you can try it any time - it cannot click.

### 4.1 Topic dump (test T2)

Press your **Dump topics** key while looking at an NPC. The glue opens a **visible** conversation, reads
her topic list, sends it and leaves the menu to you. Do it on ten different NPCs and compare what the
log read against what the menu shows on screen. Two lines to look for:

```
dump: opening a visible menu with <name>
topics npc=<name> ... origin=dump ... n=<how many she has> sent=<how many were read in full> ... unindexed=<how many we could not identify>
```

The count should match the menu, and `unindexed` should be small (under about 1 in 20).

### 4.2 Dry-run conversation (test T3)

1. MCM > Menuless questing > **Quest talk without the menu** (`bMenuless`) -> **ON**
2. Leave **Dry run (nothing is clicked)** (`bDlgDryRun`) -> **ON**. Both on together is the point.
3. Walk up to a quest NPC and **talk to her by voice, normally** - "what do you need doing", "I'll
   take the job", "tell me about the bandits".
4. Watch the log for lines that read `WOULD CLICK`. There are **two shapes** and you will mostly see
   the second one, because `iEngineOpen = 0` means a conversation *you* opened with E is "assisted":

```
WOULD CLICK cid=... pos=3 i=7 kind=plain route=1 text=I'll help you.
WOULD CLICK (assisted, nothing driven) pos=3 txt=... text=I'll help you.
```

So grep for **`WOULD CLICK`** and nothing more - a search for `WOULD CLICK cid=` loses half of it.

That line is the whole test: **is the entry the glue picked the entry you would have clicked?** The
conversation stays visible and yours the whole time, and the glue clicks nothing at all.

**One line per conversation.** After the first `WOULD CLICK` the glue steps back and the rest of that
conversation is yours, so "ten or so turns" means ten separate conversations, not ten replies.

**One thing costs a real LLM call.** The first time the glue meets a topic list it does not know, it
opens a conversation just to read it and then asks the model once more with the list in hand - one
extra reply, and a pause of roughly 11-14 seconds. Everything else the feature sends (topic lists,
subtitles, results) is answered without an LLM call and costs you nothing. If you would rather not
pay for that, turn **May open a conversation to find out** (`bIntentOpen`) off.

Send back the `WOULD CLICK` lines together with what you actually said. If they match on ten or so
conversations, the next build can take dry run off.

**Turn `bMenuless` back OFF when you finish**, so a later session cannot surprise you.

---

## 5. The emergency key

**Bind it before you start** (Keys page > "Open vanilla dialogue (emergency)"). It always works:

* if the glue is holding a hidden conversation, it hands the menu straight back to you, visible and
  clickable, and says "the menu is yours" in the corner;
* **if a PROBE press left a menu hidden, it gives that one back too** - it no longer matters who hid
  it, or whether the glue thinks it has a conversation open at all. (Press 32 does the same thing
  from the MCM, if you would rather not bind a key.)
* if there is no conversation, it just talks to whoever you are looking at, the normal way;
* if the glue got itself into a confused state, pressing it resets that state and forces the next
  conversation to be visible.

There is also **Leave the conversation** (`iKeyLeave`, Menuless questing page) - the polite ending,
the same one the NPC's own "never mind" takes. Optional.

---

## 6. New MCM settings and their defaults

### Menuless questing (new page) - the ones that matter

| Setting | Default | Meaning |
|---|---|---|
| Quest talk without the menu (`bMenuless`) | **OFF** | the master switch. Off = today's game. |
| Dry run (`bDlgDryRun`) | **ON** | nothing is ever clicked; the log says what would have been. |
| Conversations the game starts (`iEngineOpen`) | **0 = leave them alone** | couriers, force-greets, your own E press stay a normal visible menu. |
| Guards, arrests and bounties (`iCritical`) | **0 = hand the menu back** | anything lethal is always yours to click. |
| NPCs inside a quest scene (`iSceneGate`) | **0 = never** | never touches somebody mid-scene. |
| Choices inside a conversation (`iBranchInput`) | **0** | branching choices stay assisted until the probe says otherwise. |
| Walk back to a lost choice (`bRewalk`) | **OFF** | decision D7: off until you ask for it. |
| Send quest talk to the server (`bDlgWire`) | **ON** | leave it on. It is the rollback switch: if you ever put the old server back, turn this OFF first, or every conversation would be answered as a paid reply with the raw data read aloud. Off = the feature sends nothing at all and costs nothing; intimacy and everything else keep working. |
| Real checks outside quest dialogue (`bFreeChecks`) | **ON** | persuade / threaten / bribe / lie in ordinary CHIM talk are decided by the game's real rules, not by the model. Now really honoured on the server too - with it off, no check is run and nothing is narrated. |
| Difficulty bias (`iCheckBias`) | 0 | move it if the free checks feel too easy or too hard. |
| Count them in your statistics (`bCheckStats`) | **OFF** | unverified whether it double-counts; leave off. |
| A failed threat can turn ugly (`bCheckHostility`) | **OFF** | on = a botched intimidation can make somebody hostile. |
| NPCs know the business between you (`bQuestAware`) | **ON** | she knows the current objective of quests the two of you share. Never a future stage, never a spoiler. |
| Arm the probe (`bProbe`) | **OFF** | section 3. |

Read settings (`iReadMode`, `iCountMode`, `iMaxEntries`, `iTailMax`, `iClickRoute`, `iHideMode`) all
default to 0/1 and **are meant to be set from the probe's results** - leave them alone until then.

### Intimacy page - new

| Setting | Default |
|---|---|
| Gold can be part of the arrangement (`bPaidIntimacy`) | **ON** |
| Price multiplier (`fPriceMultiplier`) | **1.0x** (0.25x - 4x) |
| A closed door makes a room private (`bPrivacyDoors`) | **ON** |
| Heard-through-the-room distance (`fHearRadius`) | 600 |
| How near a door counts as this room's door (`fDoorRadius`) | 250 |

### Scene talk page - new

| Setting | Default |
|---|---|
| Keep talking to her after a scene for (`fListenerHold`) | **20 s** |
| Let go after her goodbye (`fListenerGrace`) | 2.5 s |
| Give me my camera back after a scene (`bFixControlsAfterScene`) | **ON** |

**Acceptance test, two lines to look for after your first game load.**

1. Search for `read OFF`. You should find **nothing**. If a line appears naming settings, one of the
   default lines did not reach the game and that feature is silently off. It covers the four
   intimacy settings only (`bPrivacyDoors`, `bPaidIntimacy`, `bFixControlsAfterScene`,
   `fListenerHold`).
2. For menuless questing, read this line instead - it prints its settings rather than complaining:

```
dlg self-test v400 menuless=0 dryrun=1 wire=1 engineopen=0 crit=0 scenegate=0 hide=1 ...
```

   `dryrun=1` and `menuless=0` are what a correct install looks like. **`dryrun=0` on a fresh
   install means a default line did not arrive and the glue could click for real** - turn
   `bMenuless` off and say so before you play.

**Where a speech check is written.** Every free-conversation persuade / threat / bribe / lie gets one
line, so you can see why it went the way it did:

```
check npc=Ysolda where=free kind=intimidate diff=0/SpeechVeryEasy=10 sp=40(live) wis=1 pg=420 N=0
      stakes=small stance=0 bias=0 mem=miss res=pass why=... aff=0 xp=1 gold=0
```

`where=free` means an ordinary conversation (not a quest menu), and `res=` is the **game's** verdict,
decided from your real Speech skill, your perks and this load order's own difficulty numbers - never
the model's opinion.

---

## 7. The three intimacy changes

### 7.1 "Everyone has a price"

**How it works, all by talking.** You offer gold ("I'll give you two hundred septims"), or you ask
("how much", "name your price"). She answers in her own voice, with her own figure, and haggles. She
accepts by starting the scene herself - the server never starts one and never speaks for her. The
gold leaves your purse at the moment the scene really begins, goes into her inventory, and **nothing
comes back if you stop early**.

**What it costs.** Prices are built from an honest day's wage in Skyrim - 3 to 5 gold an hour, so
about 35 gold a day - and then from who she is:

| Who | Band | Roughly |
|---|---|---|
| beggars, the destitute | half a day to 2 days | ~20 - 70 gold |
| tavern folk, poor commoners | 1 - 4 days | ~35 - 140 gold |
| modest commoners, merchants | 4 - 15 days | ~140 - 525 gold |
| comfortable folk | 15 - 60 days | ~525 - 2,100 gold |
| court, housecarls, the wealthy | 60 - 300 days | ~2,100 - 10,500 gold |
| jarls and nobles | 300 - 3,000 days | ~10,500 - 105,000 gold |

and then from her head, not her purse: how much money moves her at all, whether she already wants
you (interested = **free**, or a token figure if she wants one anyway), curious = the bottom of her
band, indifferent = the top, married and keeping it secret = **triple**, your renown brings it down,
a repeat arrangement is a little cheaper. **Some people are never for sale at any price** - a
Vigilant of Stendarr is never quoted a figure at all, and neither is anybody a price could not fix
(she is in a fight, a quest scene is running, a child is in the room, she keeps her marriage).

The rail: **no number is ever named for somebody who is not for sale.** If she is only indifferent
and coin really is the only thing in the way, she may name her price even while she is saying no -
that is the "everyone has a price" part working.

**MCM:** `bPaidIntimacy` (on) and `fPriceMultiplier` (1.0x) on the Intimacy page. Raise the
multiplier if gold is easy in your game, lower it if nobody is affordable. Off = money is never part
of it, at all.

**In the log:** a line starting `price npc=...` on every turn that mentions coin, with her band, her
stance, the figure, what you offered and the decision (`accepted`, `below_floor`, `unaffordable`,
`not_for_sale`, `free`...). The game writes `paid <n> gold to <npc>` when the coin really moves.

### 7.2 Left in OStim's camera after a scene

Two separate fixes, both on:

* **Who you are talking to.** When a scene ends there is nobody under your crosshair for a moment, so
  CHIM answered as the Narrator and she never heard a word of what you said. She now stays the one
  you are talking to for **20 seconds** after the scene, longer while either of you keeps talking, and
  is let go at once if you walk off, look at somebody else, a fight starts or a new scene begins.
  Watch for the log line `WARN the player's line went to the Narrator ...` - **it should stop
  appearing.**
* **Your camera and controls.** If a scene ever leaves you in the free camera, or without movement or
  look controls, the game puts them back a moment after it ends - only when they really are still
  gone, never during a quest scene or a menu, and it writes
  `controls repaired after the scene (...)` every time it fires.

### 7.3 Closed doors

A room with its door shut is now private, even in a packed inn. Someone who **cannot see either of
you** no longer counts as a witness just for being nearby, as long as every door within 250 units is
closed. **Anyone who can actually see you always counts**, door or no door - an open doorway, a
shared room, a balcony above.

`fHearRadius` (600) is how close somebody has to be to count when no door is shut. `fDoorRadius`
(250) is how far away a door still counts as "this room's door". The `gate` line in the log now
carries `wit=<n>/<n> door=shut|open|-`, so a refusal that says "someone is watching" can be argued
with from the log.

---

## 8. What is NOT live, and why

| Thing | Why |
|---|---|
| **Menuless questing itself** | ships with `bMenuless = 0` and `bDlgDryRun = 1`. It cannot click anything until the probe evening is green and you turn it on. |
| **Conversations the game starts, guards and arrests, scene actors** (`iEngineOpen`, `iCritical`, `iSceneGate`) | all 0. Turn them on **one at a time**, later, each with its counter read afterwards. |
| **Branching choices by voice** (`iBranchInput`) | 0 until the probe answers whether a conversation survives you speaking. |
| **Walking back to a lost choice** (`bRewalk`) | off - it replays her earlier lines, which is a real cost. |
| **Counting free checks in your statistics** (`bCheckStats`) | off - nobody has verified it does not double-count. |
| **`bQuestColour`** | off - the colour variable it needs may not exist in either menu file here. |
| **MO2's version label** | still says **0.1.0** in MO2's left pane, because MO2 was open and the profile files were not touched. Cosmetic only. Close MO2 and run `tools\install_mo2.ps1` once to refresh it. |
| **A helper SWF / an SKSE plugin** | nothing was installed and nothing needs to be. The probe's press `24` measures whether it is even possible, as a fallback if the plain route turns out too slow. |

---

## 9. Two upstream bugs found on the way (decision D8 - report only, changed nothing)

Neither is ours and neither is caused by the glue. They are in LoreRim's own data and they happen
today, with or without this mod.

1. **Little Lessons** skips its `+10` Speech restore whenever `SpeechVeryHard >= 100`, which any
   LoreRim Speech-scaling trait guarantees. The effect: **every dialogue menu you open with a married
   NPC permanently lowers all five Speech difficulty globals** - persuasion gets easier over time,
   forever. The glue now measures it: the log line `closed npc=... drift=...` is the net change
   across a conversation. A non-zero `drift` is this bug, not us.
2. **Four orc-follower check topics** (Borgakh and Ghorbash, persuade and intimidate) have the
   success line sorted *after* an unconditional failure line, which probably makes those specific
   checks unpassable in this load order.

We changed nothing in either mod and will not without you asking.

---

## 10. One-line summary of what was verified before this was installed

Papyrus: all nine scripts compile, 0 errors, 0 warnings. ESP: valid, four scripts on the main quest.
Server: 61 files lint clean; 112 + 296 + 190 + 14 + 74 unit checks pass; all **60** offline
conversation scenarios pass with **924** checks and no warnings. The prompt index was rebuilt from
this load order - **37,561 dialogue lines and 5,718 choice layers**, one plugin unreadable (Sanguine
Symphony, a compression format we do not support and do not need) - and is **loaded into the database
and live** (`source=db`, and this time the database half was checked end to end, which it had not
been). Nothing has been run in the game - that is your evening.

---

## 11. What changed after the first install (same day, fix pass)

Sixteen defects were found by a review of the shipped build and fixed. Nothing about how you play
changed; what changed is that several things which silently did nothing now work. The ones you can
see:

| What was wrong | Now |
|---|---|
| The probe key was **dead in the session you armed it in** - nothing re-registered it, and changing any hotkey wiped it. | Arming the probe or changing a hotkey registers it on the spot. Section 3.1. |
| The one command section 3.3 gave you for collecting probe results **matched nothing**. | Fixed, and tested against a real log line. Section 3.3. |
| The emergency key could not give back a menu the **probe** had hidden, nor one left hidden by a failed restore. | It gives back any hidden menu now, whoever hid it. Section 5. |
| An OStim scene could be started **while a conversation was open**, where OStim's own hotkeys are dead. | Refused, with "a conversation is in progress". |
| Paying an innkeeper for a room, or a smith for a sword, was read as **propositioning them**. | Ordinary buying and selling is no longer read as an offer for sex. |
| Two of your own three speech-check examples (haggling with a merchant, lying to a guard) **produced no check at all**. | Both do now, and haggling has its own kind. |
| `bFreeChecks`, `bCheckHostility`, `iCheckBias`, `iQuestLines`, `iBranchInput`, `bRewalk`, `iRewalkDepth`, `bIntentOpen` - **eight MCM controls changed nothing**, because the game never told the server about them. | All eight reach the server. With `bFreeChecks` off, no check is run and none is narrated. |
| A LETHAL line (an arrest, "I'll come quietly") could be graded as **ordinary business** inside a known choice layer. | The strictest reading of an ambiguous line always wins. |
| Every message carrying a Speech perk or the Amulet of Articulation **crashed the request** on the server. | Fixed. It would have hit you the moment you owned one Speech perk. |
| The safety rails on the model's own choices (two-step confirmation, lethal hand-back, cannot-afford) **were never reached** because of a name mismatch. | They are reached. |
| Probe press **32** was described in the MCM but the slider stopped at 31. | The slider goes to 32. |

Two more, not visible but worth knowing: the glue could move the highlighted entry on a menu you
could still click (so your own E-press would have fired the wrong line), and the measurement that
probe press 22 exists for was being overwritten before you could read it - it is now on every
`arming` line as `apd=`.

**MO2 still shows version 0.1.0** for this mod, for the same reason as before: MO2 was open, so only
our own files were copied and `meta.ini` was left alone. Close MO2 and run `tools\install_mo2.ps1`
once if you want the label to read 0.4.0. Cosmetic only - the mod itself is current.

---

## 12. Conversation hold (new, v0.4.1) - "the AI is quick to walk away from you"

Your addendum 8. The investigation is in `research\pt8-walkaway.md`; this is what was built and what
you should do with it.

**Why she walked off.** CHIM has no hold for talking to the player at all. Every sentence it speaks
turns her head (`SetLookAt`) and nothing else - her own schedule keeps running the whole time, so AI
Overhaul and Jobs Overhaul simply take her back. CHIM's one "stay near me" feature is switched off
unless **you are sitting down**. And the model is offered an action called **EndConversation**, which
resets her packages and hands her straight to her shift; it fired **eight times** in your session,
six of them inside the two windows you named.

### What the hold does

While a CHIM conversation with an NPC is live - any line from her or from you within the window
below, and every further line pushes the window out - she **stops where she is and turns to face
you**. She can still talk, gesture, look around and play her idles; only her feet are held. It is the
same mechanism as the goodbye hold after a scene, which released cleanly three times in your last
session. Her AI package is **not touched** by default, so there is no priority fight with AI Overhaul
or Jobs Overhaul.

**She is let go** - one function, every path - when: nobody speaks for the window (never cutting
across her own sentence), you walk past the distance below, you **sneak** away past half of it,
either of you enters combat, she dies / is disabled / unloads / you go through a door into another
room, a scene starts, a real dialogue menu opens, an arrest begins, a quest takes her package,
another NPC starts talking to you, you press the stop hotkey (End) or the vanilla-dialogue key, you
switch the glue off or flip the kill switch, you **load a game**, or - the last resort - three
windows plus thirty seconds pass.

**She is never held in the first place** if she is: hostile or in combat, in a scripted scene (a bard
mid-song, a quest argument), on a quest-owned package, a guard with business with you or anyone
mid-arrest, a follower or teammate, a child, already sitting / asleep / mounted / swimming / flying /
bleeding out, out of range, or if a menu, an OStim scene or a quest conversation is running.

**And on the server:** while the game says it is holding *this* NPC, `EndConversation` is not put on
the table at all, so the model cannot choose to leave a conversation you are still having. That is
the per-NPC, only-while-held version of the web-UI toggle below.

### The MCM keys - new page **Conversation**, between *Initiative* and *Scene talk*

| Setting | Default | Meaning |
|---|---|---|
| She stays with you while you are talking (`bConvHold`) | **ON** | the master switch for the hold. |
| Let her go if nobody speaks for (`fConvHoldWindow`) | **45 s** (0-180) | the conversation is over when neither of you has spoken for this long. **0 = the whole feature off.** |
| Let her go if you walk this far away (`fConvHoldFar`) | **900** (300-4000) | walking away ends the conversation. Sneaking releases her at half of it. |
| Also suspend her daily routine (`bConvHoldPackage`) | **OFF** | only if she visibly fidgets - see below. |

All four have a line in `settings.ini`, because a missing key reads as 0/FALSE and would switch the
feature off (or, for the distance, release her instantly - that one falls back to its default in code
as well).

### The log lines (`bDebugLog` on, in `log/lorerim_glue.log` prefixed `GAME`)

```
conv hold Lisette on reason=a line window=45
conv hold Lisette refresh reason=a line held=12.4          <- at most one per 10 s
conv hold Lisette release reason=nobody spoke held=47.2
conv hold Lisette release reason=you walked away held=31.0
conv hold Lisette release reason=somebody else is talking held=18.0
conv hold Lisette release reason=the game was loaded held=0.0
conv hold Lisette skip reason=a scene                      <- at most one per NPC per 30 s
conv hold Lisette skip reason=a quest package
conv hold Lisette keep reason=CHIM ended the conversation held=18.2
```

Two you should not be alarmed by:

- **`release reason=self-heal`** is the last-resort bound, at three windows plus thirty seconds (165 s
  at the defaults). A genuinely long, continuous conversation reaches it legitimately and the hold
  simply starts again on her next line. It is only a defect if you see it with nobody talking.
- **`skip reason=a scene` / `skip reason=a quest package`** is the hold refusing to touch somebody the
  game is already driving. Lisette singing in the Winking Skeever is exactly this case - **she will
  not be held while she is on stage**, by design. That is the one in your logs, and the web-UI
  settings below are what covers it.

Two new facts also ride along on every snapshot now: `dist=` (how far she was from you) and `hold=`
(whether the hold had her). `dist=` is the number that was missing when we tried to reconstruct your
session; the next walk-away report is answerable from the log alone.

### Still do these three in CHIM's web UI - they are the biggest single win and need no build

1. **Disable the `EndConversation` action.** Action Editor
   (`http://localhost/HerikaServer/ui/function_editor.php`, also in the Prisma CHIM Settings hub) ->
   find the row **`EndConversation`** ("ends the conversation and becomes unavailable to talk for a
   short time") -> tick it to **Disabled** -> **Save all changes**. It is `enabled` on your box right
   now. This is the only walk-away cause with hard log evidence, and it is the one the glue can only
   suppress for an NPC it is actually holding.
2. **`END_CONVERSATION_COOLDOWN` -> 0.** Global Settings, currently **60**. Even if she does end a
   conversation, you can talk to her again at once instead of being stonewalled for a minute.
3. **`RECHAT_MODE` -> `conversational`** (or `tight`). Currently `random`, which re-rolls per chain
   and is what lets a reply wander off to another NPC or to the Narrator.

Two smaller ones from the investigation if you want them: **NPCs Sandbox Near Player** (MCM -> CHIM ->
Behavior) is already ON but does nothing unless you are **sitting**, so sitting down to talk still
helps; and leave **NPCs Walk To Target** OFF and **NPC Scene Safety** ON.

### What to watch for in the next session

- **Her fidgeting** - turning on the spot or sliding while held. That is her package running
  underneath her held feet. If it shows, turn `bConvHoldPackage` on; it suspends the routine too. It
  compiles and is guarded, but it has had no in-game exercise at all, so treat it as the experiment.
- **Her stalling after CHIM tells her to move** (`ComeCloser` fired 18 times and `FollowPlayer` 10
  times in one session). The release for that is built but **dormant**: on CHIM 3.3.2 the DLL does not
  route its own actions through any event a mod can listen to. If you see her refuse to close the
  distance, say so - the fix has the same shape as the `EndConversation` one, on the server.
- **A force-greet arriving a second late.** A greeting that is a bare package with no owning quest
  can be delayed by up to one tick (1 s) before the hold gets out of the way.

**MO2 still shows version 0.1.0** for this mod: MO2 was open, so only our own files were copied and
`meta.ini` was left alone. Close MO2 and run `tools\install_mo2.ps1` once if you want the label to
read 0.4.1. Cosmetic only - the mod itself is current.
