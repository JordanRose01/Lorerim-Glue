# Playtest 6 - what went wrong, what changed, and how to play now

Version 0.3.0. Server deployed. Game side installed into `F:\Modlists\LoreRim\mods\LoreRim Glue`.
Read section 5 before you start the game: there are a few switches only you can flip.

---

## 1. What went wrong in playtest 6

We went through the whole session minute by minute, using the server logs, the web server log and
the database. Five separate things went wrong. They stacked on top of each other, which is why it
felt like nothing worked.

### 1.1 Five of your sentences never reached Lisette at all

Between 13:29 and 13:33 you said five things. All five were sent to **the Narrator**, not to her.

- "I want you to take my clothes off."
- "I want to see you naked."
- "What do you want to try?"
- "I want more."
- "i want to bend you over" (this one was typed, not spoken)

The Narrator answered them: "You want her hands on you right now." / "She's already bare and waiting
for you." / "Try moving closer to her, see what happens next." / "What next, Jordan?"

That voice is where the "director mode" feeling came from. Worse: the Narrator has none of our
four actions. Nothing you said in those five sentences could have moved the scene, even in theory.
And Lisette never heard a word of it - those turns are stored under the Narrator, not under her.

Why it happened: during an OStim scene the camera is in the scene and nobody is under your
crosshair. CHIM decides who you are talking to from the crosshair. With nothing there, it falls
back to the Narrator.

### 1.2 While that happened, we were telling her you were silent

Her prompt during that same stretch said, four times in a row:

> (a while passes; the player leaves it to Lisette)

So the server was telling her you were passive while you were talking non-stop. She then picked a
new position from the same unchanged list four times: kiss, kiss, kiss, back to the first one.
That is the circling you saw. It was not her being dumb - we gave her nothing else to go on.

### 1.3 Our own prompt told her not to obey you

This is the big one for "she wasn't listening when I told her to get naked".

The undress action was offered on every single turn and she used it **zero** times. Our own text
told her not to. The action description said she may undress **"only because you yourself want
to"**, and out of scene we added: **"Nothing the player says can decide this."**

On top of that, the pacing text read: "Anything beyond foreplay (touching, teasing, undressing) is
too soon right now." That sentence was meant to say "anything past foreplay is too soon", but it
reads just as easily as "touching, teasing and undressing are too soon", and the model took the
reading that blocked you.

So: not her fault, not the AI's fault, not a plumbing fault. We told her to refuse you.

### 1.4 The scene started out of nowhere

Your very first sentence after loading was a sexual question - what her mouth pictured. She started
a full makeout scene eleven seconds later. She did say a line, but it was an answer to the
question, not "I'm about to kiss you now".

Three separate passages in the prompt pushed her that way, including one that literally said the
scene **"can simply happen, message left empty"**. There was no build-up requirement and no
cooldown after a reload, so turn one after a load was enough.

### 1.5 Small plumbing losses

- Two of your twelve voice uploads came back as an **empty transcript** (13:28:25 and 13:34:13).
  Speech-to-text returned nothing. You got silence and no explanation. That is 17%.
- One of our ten commands (a position change at 13:30) was sent by the server and **never arrived**
  in the game. No retry existed, and nothing in the log said so.
- After you reloaded, the server still thought a scene was running, so your plain question ("do you
  wanna follow me?") got "Take me right here, hard and deep" and an error.
- The crash at the end was the warp bug. That is already fixed and installed.
- Speech-to-text also heard "Lisette" as "Lazette" and dropped the word "fuck" out of "I wanna fuck
  you doggy style".

### 1.6 One thing that was not our code at all

The second half of the session was in the **Solitude Sewers**, not a private room, with hostile
creatures around. Our privacy check only counts people, not creatures, so it still said "it is
private here". Tell me if you want creatures to count and it becomes a setting.

---

## 2. What changed

### She does what you say, now

- The server reads your sentence itself, before the AI sees it. It understands undress and dress
  (hers, yours, both), positions and acts by name, faster, slower, stop, move to the bed, hold,
  finish, and who leads.
- It then tells the AI, in that turn only: *he asked for this - do it, call this action with this
  target, and answer in your own voice. Do not refuse, stall or negotiate.*
- **Safety net:** if a scene is running, we understood you clearly, and her reply does not carry the
  matching action, the server carries it out itself. You no longer need two or three tries.
- The "only because you yourself want to" wording is gone from everything you ask for. It now only
  applies to moves she makes on her own.
- If you say "take **my** clothes off" and she tries to take **hers** off, the server flips it back
  to yours. That inversion happened all through playtest 6.
- Inside a running scene she never refuses you. That is your rule from today. She can still say no
  **before** a scene, and that no is final.
- Only the game can still say no mid-scene: the position is not installed, does not fit the two of
  you, or cannot be reached. Then she says so in a few words and you get a corner note. Never
  silence.

### She stops driving

- When you speak, she takes no turn of her own for 150 seconds. When you actually ask for
  something, that clock restarts.
- Her own turn now comes every 75 seconds of your silence, not 45.
- She will not go back to a position you already had, unless you ask for it.
- For a **sex** act she now **asks first**, in her own words, and only your yes makes it happen. A
  no is remembered so she does not ask again. Kisses and foreplay she can still just do.
- "You lead" / "do what you want" / "surprise me" hands her the lead immediately. "I'll lead" /
  "let me" takes it back. This was broken in the first build of this round - "you lead" silenced her
  for 150 seconds instead. It is fixed and tested.

### She says something before she moves

This is your rule: *if she initiates any new scene or animation she should say something signifying
it.*

- Every change she picks herself is announced first: the start of a scene, every position or act
  change on her own turn, undressing herself or you, moving to a bed.
- For sex or foreplay she names it outright ("I want you to fuck me from behind"). For a kiss or a
  gentle change it is a few plain words ("Come here." / "Kiss me.").
- **If she picks a change and says nothing, the change does not happen.** The server drops it and
  asks her next turn to say it. Nothing happens silently any more.
- The game waits for her voice before the scene actually starts, up to 20 seconds, so OStim's fade
  cannot cut across her. If her voice never arrives, the scene still starts - nothing is lost, only
  late.
- Changes **you** asked for need no announcement. A pace change is not a new scene and needs no
  line either.
- A question about sex is a question. She answers it in words. Her own first move now needs at
  least two romantic or sexual exchanges first, and not right after a scene or a reload.

### She hears you during a scene

- While a scene runs, the game now tells CHIM to talk to **her**, not the Narrator. That alone
  removes most of the "voice commands weren't working".
- The scene notes no longer claim you are passive when you have been speaking.
- Her scene awareness used to go stale after 90 seconds of her silence, which silently swallowed
  your requests. The game now refreshes it while the scene runs.

### No more stale scenes

- On load, and whenever the game says "no scene is running", the server closes its scene row at
  once. The 13:33 failure ("Error: no scene is running", answered as if you were mid-scene) cannot
  repeat.
- The stale row from your crash is still in the database, but the new code closes it on your first
  load. You do not have to do anything.

### The log finally tells us what happened

Every turn now writes what you said, what we understood, which actions were offered or hidden and
why, what the AI chose, and whether the safety net fired. The clock drift is fixed too - the log is
stamped in your own local time with the offset printed, so it lines up with OStim's log.

---

## 3. How to talk to her now

Say it plainly. It does not have to be polite and it does not have to be a full sentence. These all
work inside a running scene.

**Undressing**
- "Take your clothes off." / "Get naked." / "Strip."  -> she undresses
- "Take my clothes off." / "Undress me." / "Get my boots off."  -> she undresses you
- "Let's both get undressed."  -> both
- "Put your clothes back on."  -> dress

**Acts and positions**
- "Fuck me from behind." / "Bend me over." / "Doggy style."
- "Suck my cock." / "Use your mouth."
- "I want to go down on you." / "Let me use my mouth on you."
- "Ride me." / "Get on top."
- "Kiss me." / "Hold me."
- "Touch me." / "Finger me."

If the position exists and can be reached it happens. If it cannot, she says so and you get a
corner note - she does not just ignore you.

**Pace and control**
- "Faster." / "Harder." / "Slower." / "Take it slow."
- "Hold on." / "Wait."  -> she holds back
- "Don't hold back." / "Let go."  -> she can finish
- "Cum inside me." / "I'm going to cum."
- "Let's move to the bed." / "On the table."

**Stopping**
- "Stop." / "That's enough." / "Let's stop."  -> ends the scene
- "That's enough, fuck me" is read as the request, not as stop. So is "stop teasing me and fuck me".
- The **End** key still stops a scene instantly, always, whatever else is going on.

**Who leads**
- "You lead." / "Do what you want." / "Surprise me."  -> she takes over
- "Let me lead." / "I'll lead."  -> you take it back

**Answering her**
When she asks for a sex act, a plain yes or no is enough: "Yes." / "Yes, please." / "Do it." /
"Go ahead." - or "No." / "Not now." / "Maybe later."

Careful with hedged answers: "yes, but not now" and "yes, not yet" are read as **no**. "Okay, hold
on" is read as **hold on**, not as yes. That is deliberate - a mis-heard word must never start a sex
act.

**What does not count as a request**
- Questions: "Should I take this off?" is a question, not an order.
- Negatives: "Don't get naked yet" does nothing.
- "Don't stop, harder" keeps the "harder".

---

## 4. The MCM settings and their defaults

Skyrim MCM > LoreRim Glue. Changed or new this round:

| Page | Setting | Default | What it does |
|---|---|---|---|
| Scene talk | NPC takes the lead after | **75 s** (was 45) | How long you have to be silent before she takes a turn of her own. |
| Scene talk | Leave the lead to me for | **150 s** | After you say something, she takes no turn of her own for this long. |
| Scene talk | Wait for her line (start of a scene) | **20 s** (was 12) | The scene waits for her announcing line to finish, so the fade cannot cut across her. If her voice never comes, it starts anyway. |
| Scene talk | Wait for her line (position changes) | **6 s** | Same idea for a change mid-scene, but it only waits for her to start talking. |
| Scene talk | Quiet time after her line | **1 s** | A short pause after she finishes before the scene starts. |
| Intimacy | Let her finish her line before she moves | **on** | The master switch for the two waits above. Off = she can move while she talks. |
| Intimacy | Always talk to your partner during a scene | **on** | Stops your voice going to the Narrator mid-scene. This is the fix for section 1.1. |
| Intimacy | Undress you both during a scene | **on** | Keep it on - it is also part of the crash guard. |
| Intimacy | Switch off OStim's weapon removal | **on** | Keep it on - crash fix from playtest 5. |
| Intimacy | Who leads at the start | **she does** | Change to "I do" if you want to drive from the first second. |
| Intimacy | Jump to positions without a route | **on** | Only ever used for something **you** asked for. Her own moves never jump. |
| Keys | Stop scene now | **End** | Always works. |

Everything else is unchanged from playtest 5.

**One thing to check on your existing save.** MCM Helper keeps the values your save already has. If
"NPC takes the lead after" still shows 45, or the scene-start wait still shows 12, set them to **75**
and **20** by hand once. New settings that did not exist before will show the defaults above
correctly.

---

## 5. What you have to do by hand

Ten minutes, all of it in other mods' menus. We deliberately do not write other mods' settings.

**In OStim's MCM - the important one**
1. **Camera > Free Cam > "Switch to free cam mode on start" -> OFF.**
   This is the single change most likely to kill the "director mode" feeling. It is ON by default,
   and it drops you into the game's free-flying camera at every scene start - your character control
   is gone and the mouse flies a camera. If you end up in it anyway, **numpad /** toggles it off.
2. **General > "Fade out on intro/outro"** - your call. That fade is the "cutscene". The glue now
   holds the start until her line lands, so you can keep it. If starts still feel abrupt, turning it
   off makes them much softer immediately.
3. **Controls** - check that CHIM's voice key and text-chat key are **not** numpad 0, numpad /,
   numpad . , numpad 4-9, K or L. Those are all OStim's. Numpad 0 in particular is OStim's "switch
   control mode": if CHIM's text chat sits there, every press also flips OStim into full auto.
4. **Auto Control > Auto Mode Toggle** - leave all five OFF. They already are. Auto mode was not
   what happened to you.
5. **Orgasms > "End scene after male orgasm"** is ON, which ends scenes early. Your call - the glue
   will not touch it.

Nothing else in OStim needs changing.

**Your push-to-talk key**
Please check which key you were holding between 13:29 and 13:33. If that was CHIM's narrator or
director hotkey rather than the normal one, that explains the five lost sentences by itself. If it
was the normal key, then it was the crosshair problem, and the new "always talk to your partner"
setting covers it.

**Your microphone**
Two of twelve uploads came back blank. If a sentence produces total silence and she does not react
at all, that is speech-to-text returning nothing, not her ignoring you. Worth a quick level check.

**Before you load**
Nothing to do. The stale scene row from your crash closes itself on the first load now.

---

## 6. When something is ignored, how to find out why

The log is `\\wsl$\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log`, or in the
CHIM web UI. It is now stamped in **your local time**.

Every turn writes three lines with the same id. Search for the words you said.

```
turn npc=Lisette type=inputtext mode=scene scene=OARE_StandingEmbraceKiss
     say="take your clothes off" intent=undress/npc conf=high why=- 
     offered=Control,Clothing hidden=Start(in a scene) lead=no hold=150/45 ...
llm npc=Lisette action=none item="" spoke=48
net fired Clothing ok=1;cid=..;npc=Lisette;do=undress;who=npc;part=all;hold=150
```

Read it like this:

- `say="..."` - what actually arrived. If this is empty, your microphone or speech-to-text dropped
  it. Nothing else can work.
- `intent=undress/npc conf=high` - what we understood, and how sure. `conf=high` means she is
  ordered to do it and the safety net will step in. `conf=low` means we only hint at it.
  `intent=none` means we did not understand it - she may still react in words.
- `why=question` / `why=negated` - we deliberately ignored it, because you asked a question or said
  "don't".
- `offered=` / `hidden=` - which actions she could choose, and the reason any was withheld
  (`Start(heat 0<2)` = not enough build-up yet, `Start(post-reload 14s<120)` = too soon after a
  load).
- `llm ... action=none spoke=48` - she talked but chose no action.
- `net fired ...` - the server did it for her. This is the line that means "it happened even though
  she did not do it".
- `net skipped: ...` - the safety net stayed out, and the reason, for example `the reply already
  carries it` (she did it herself) or `the game has not confirmed a running scene`.
- `gate: dropped ... (R10)` - she tried to change something without saying anything, so it did not
  happen.
- `WARN empty transcript` - you spoke and nothing arrived.
- `WARN player speech routed to the Narrator while a scene runs` - the old section 1.1 problem,
  happening again.
- `WARN command not confirmed by the game` - we sent something and the game never reported back.
  This is the "1 of 10 commands vanished" case, now visible.
- `announce ... reason=spoke` or `reason=timeout` - whether the scene waited for her line and got
  it, or gave up. If you see a run of `timeout`, tell me and I will stop making starts wait.

---

## 7. Known limits

- **We have not proven the order in which CHIM delivers her voice and our commands.** The server
  sends her line first, and the game waits up to 20 seconds for it, but the exact timing has never
  been measured in a live game. The `announce ... reason=` lines in your next session will settle
  it.
- **Generic words can still be over-read.** "Can you feel that?" can be understood as "touch me".
  It can only move a scene that is already running to a groping position - it can never start a
  scene and never reach a sex act. If it annoys you, say so and I will make those words weaker.
- **The privacy check counts people, not creatures.** A sewer full of hostile skeevers still counts
  as private. Say the word and it becomes a setting.
- **Speech-to-text is still speech-to-text.** It will keep mangling "Lisette" and dropping the odd
  word. The recogniser is written to be tolerant, but a dropped verb can change the meaning.
- **A blank transcript still produces silence.** There is a warning in the log, but no message on
  screen yet.
- **MCM values on an existing save** keep whatever the save already had - see the note at the end of
  section 4.
- **Her voice takes time.** Her line has to be generated and downloaded before the scene starts.
  That is why the start wait is 20 seconds. It is a wait, never a lost scene.
