# Playtest 7 - Outro audit (R3)

Read-only audit of what happens at the end of a scene, server and game, and the exact flow + texts
proposed for R3 ("a real outro"). Evidence is from today's playtest 7 (local times, -04:00) and from
the code as installed at 17:00.

Sources used: `glue/server/lorerim_glue/**`, `glue/game/LoreRimGlue/Source/Scripts/**`,
`glue/PROTOCOL.md`, `glue/OWNER_ADDENDA.md`, deployed copy at
`/var/www/html/HerikaServer/ext/lorerim_glue` (identical to the project), HerikaServer logs
(`lorerim_glue.log`, `context_sent_to_llm.log`, `output_from_llm.log`), Postgres
(`lrg_scene_state`, `lrg_memory`, `eventlog`, `core_action`), CHIM Papyrus sources at
`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts`.

---

## 0. The headline

**There is no outro turn. None. The scene end triggers no LLM call at all.**

What the owner hears as "they say something short and just leave" is simply the **last in-scene
scene-talk line**, fired by the *climax* event a few seconds before OStim stops, under a cue that
explicitly demands **"one short, blunt sentence - 120 characters or less"**. After the scene ends
the glue tells the server, releases the NPC, and goes quiet. Her AI package resumes and she walks
off. Nothing was ever asked of her.

Proof, first scene (17:14:08):

| local | what |
|---|---|
| 17:14:03 | `scene Climax` -> `turn npc=Lisette type=lrg_scenetalk mode=scene` |
| 17:14:04 | LLM reply #262: `"Fuck, keep pounding my ass like that."` |
| 17:14:08 | `GAME listener released (the scene ended)` / `scene end` / `GAME thread end` |
| 17:14:49 | next glue line at all = a **save reload** (`GAME maintenance done, version 300`) |

`context_sent_to_llm.log` has request blocks at `23:14:04+02:00` and then **nothing until
`23:16:25`**. No request was made for a closing line.

Proof, second scene (17:19:30):

| local | what |
|---|---|
| 17:19:26/27 | `scene Climax` x2 -> `turn npc=Lisette type=lrg_scenetalk mode=scene` |
| 17:19:28 | LLM reply #277: `"Fuck, Jordan, my cunt's still twitching."` |
| 17:19:30 | `GAME listener released (the scene ended)` / `GAME thread end` / `scene end` |
| 17:19:45 | next LLM call = the **player** saying "go again" |

Context blocks: `23:19:27` then `23:19:45`. Again, nothing in between.

So the two "outros" the owner saw were `"Fuck, keep pounding my ass like that."` and
`"Fuck, Jordan, my cunt's still twitching."` - i.e. exactly the "wow nice dick" complaint, and they
are mid-act lines, not goodbyes.

---

## 1. Server: the end of a scene today

### 1.1 The message that arrives

`LRG_OStim.FinishThread` (LRG_OStim.psc:2953) sends one fast state message and nothing else:

```
ev=end;npc=<name>;cid=<startCid>;scene=<lastScene>;byglue=<0/1>;sess=<tag>
```

`PROTOCOL.md` 1.2 confirms: *"`ev=end` carries only `ev, npc, cid, scene, byglue`"*. **No duration,
no climax counts, no reason, no acts.** Everything the outro would need has to come from the last
stored payload (`$prev`) or be added to this message.

It lands in `preprocessing.php` (main.php:193, before the LLM semaphore), is routed by
`lrgHandleGameMessage` -> `lrgHandleSceneMessage` (lrg_actions.php:305), and the request is then
**`terminate()`d**. A fast state message can never produce a reply - by construction the `ev=end`
path *cannot* make her say anything.

### 1.2 What `lrgHandleSceneMessage` does on `end`

`lrg_actions.php:324-339`:

* clears `invite`, `lead_idle`, `proposal`, `say_first`, `player_request_at`, `player_spoke_at`,
  `hold_sent_at`; sets `last_scene_end_at`; **resets `heat` to null**;
* `lrgRomanceBump($npc, 'scenes')` (our own `lrg_romance` counter - this already exists);
* writes **one** `infoaction` event, the one-scene memory summary (see 1.4);
* `lrgStoreScene` closes the row (`active=0`).

That is the whole end-of-scene handling. There is no request type, no directive, no follow-up.

### 1.3 There is no request type that could carry an outro

`prompts.php` defines exactly two glue-triggered request types: `lrg_scenetalk` and
`lrg_initiative`. `lrg_scenetalk`'s precondition is hard:

```php
$lrgScene = lrgGetActiveScene();
$lrgOk = $lrgScene !== null && strcasecmp($lrgScene['_npc'], $lrgName) === 0
      && $lrgSnap !== null && $lrgSnap['_age'] <= snapshot_max_age_seconds (90)
      && $lrgSnap['adult']==='1' && $lrgSnap['witkid']==='0' && $lrgSnap['on']==='1';
```

After `ev=end` the row is `active=0`, so `lrgGetActiveScene()` returns `null` and **any
`lrg_scenetalk` fired after the end would get no cue at all** - `processor/request.php:171-175`
logs "Request cue is empty!" and falls back to `TEMPLATE_DIALOG`, an ordinary neutral reply. This is
the single most important constraint for building R3.

A second trap on the same path: the snapshot must be **< 90 s old**. At 17:16:24 a turn already came
out as `mode=silent reasons=no_fresh_snapshot` - **no glue guidance injected at all**. An outro turn
fired ~2 s after a scene will often hit this unless the game forces a snapshot first.

### 1.4 The one-scene memory summary

`lrgSceneSummaryLine` (lrg_actions.php:415) composes ONE sentence and pushes it through
`logEvent(['infoaction', ...])`, which is log-only on the server but does enter CHIM's context,
memory and diary.

It was **actually delivered** - the context at `23:19:45` (the "go again" turn) contains:

```
# Lisette and Jordan's intimate time together comes to an end (about a minute; Lisette led).
```

...and that is **the only glue scene line in the whole 2.1 MB context log**. Two problems:

1. It arrives **late** - only on the next turn something *else* triggers. For the first scene it
   never arrived at all (the 17:14:49 reload wiped the window before any turn used it).
2. It is **a stub**: no acts, no "they finished", although they had visited 4 scenes and there were
   two `ev=climax` pushes. `grep "draw close"` = 0 hits (the scene-**start** line never fired
   either) and `grep "are now:"` = 0 hits (landing notes, G5.9, **never fired once today**).

### 1.5 ROOT CAUSE of the empty summary - the `ev` case mismatch (new finding, high impact)

The server compares `$ev` case-sensitively against lowercase literals. In production the wire
carries **capitalised** values. Counted over the whole `lorerim_glue.log`:

```
26  ] scene Change
 5  ] scene Start
 4  ] scene Climax
 4  ] scene end          <- the only lowercase one
35  leader=NPC           <- every single one; the game variable is "npc"
 0  "scene change npc="  (lowercase) anywhere
```

The game source is lowercase (`MarkDirty("change")`, `PushState("climax")`, `string leader = "npc"`)
and the installed `LRG_OStim.pex` string table still holds `change` / `ev=end;npc=..` lowercase, and
the server does no case folding (`lrgParseKv` is verbatim; `lrgLog` is verbatim; deployed file
identical). The capitalisation therefore happens in CHIM's DLL between
`AIAgentFunctions.logMessageForActor` and `$gameRequest[3]` - note the pattern: values built at
runtime from a **variable** (`"ev=" + asEvent`, `";leader=" + shownLeader`) are transformed, while
values inside a **single string literal** (`"ev=end;npc=..."` in `FinishThread`) are not.

Consequences, all confirmed by the logs:

| code | test | fires? |
|---|---|---|
| `lrg_actions.php:321` | `if ($ev === 'start')` mem reset | never |
| `lrg_actions.php:331-335` | start narrative "draw close and share an intimate moment" | never (0 hits) |
| `lrg_actions.php:341` | `if ($ev === 'change')` -> `lrgLandingNote` (G5.9) | never (0 hits) |
| `lrg_actions.php:356-372` | `lrgTrackVisited` -> `_visited`, `_acts_done` | never; both always `[]` |
| `lrg_core.php:242` | `_climaxes` += 1 on `ev==='climax'` | never; always 0 |
| `lrg_core.php:236` | `$newScene` via `ev==='start'` | falls back to the `cid` test (still works) |

So **the summary can never contain acts or "they finished"**, and the outro would have nothing to
react to. `SELECT payload FROM lrg_scene_state` right now confirms `"_visited": [], "_acts_done": [],
"_climaxes": 0`.

**Fix (one line, safe whatever the cause):** fold the case on arrival in `lrgHandleSceneMessage`
(`$ev = strtolower(trim((string)($kv['ev'] ?? ''))); $kv['ev'] = $ev;`) and likewise
`$kv['leader'] = strtolower(...)`, before `lrgTrackVisited` / `lrgStoreScene` / `lrgTrackProgression`
see them. `lrgSceneSummaryLine`'s `$leader === 'player'` test is broken by the same bug today (it
always says "<npc> led"), and `lrgLeaderIs*`-style checks elsewhere should be audited for the same.

> This is not strictly my lane but it is load-bearing for R3: without it the outro has no facts.

### 1.6 What the server DOES know at the end (usable without new wire keys)

`PROTOCOL.md` 1.2: every non-end push carries `ncl` / `pcl` (`OActor.GetTimesClimaxed` for NPC and
player), `acts` (`OMetadata.GetActionTypes`), `tags`, `furn`, `leader`, `wd`, `und`, `undp`,
`speed`, `scene`. The **last push before `ev=end`** is still in `$prev` when the end arrives, so:

* **who came** -> `ncl` / `pcl` from `$prev` (exact, does not depend on `_climaxes`);
* **what they did** -> `_visited` + `_acts_done` once 1.5 is fixed, plus `$prev['acts']`/`tags` as a
  floor even today;
* **where** -> `$prev['furn']` + the snapshot's `loc` / `ltype` / `cellown` / `home` / `nhome`;
* **who led** -> `$prev['leader']` (after the case fix);
* **how long** -> only `_tier_since` today (time at the *top tier*, which is why it said "about a
  minute" for a 2.5-minute scene). Needs a real `_started_at` stamped at `ev=start`.

Missing and worth adding to `ev=end` (additive, old server ignores it): `why=`, `dur=`, `ncl=`,
`pcl=`, `outro=1`.

### 1.7 Why the line comes out as a one-liner - every cap, quoted

1. **The climax scene-talk cue** (`prompts.php`, the one that produced both "outros"). Verbatim
   from `context_sent_to_llm.log` at `23:14:04`:
   > `(Lisette says one short, blunt sentence - 120 characters or less - about what is happening right now, the way a real person talks in the middle of it, or only a breath or a sound; no description, no poetry)`

   and at `23:19:27`:
   > `(Lisette reacts out loud to what they feel right now: one short sentence of plain words in their own voice, 120 characters or less, nothing flowery)`

2. **The scene notes** (`<intimate_scene_now>`), last line, verbatim:
   > `... Only about what is really happening now. ONE short sentence, at most 120 characters, often less; silence is fine. Never repeat a line.`

3. **`FORCE_MAX_TOKENS`** - `prompts.php` sets 140 tokens for a talk turn (`scene_talk.max_tokens`),
   and `lrgApplyTurnRuntime` (lrg_actions.php:1015) sets 140/200 for every scene, follow, public,
   private and initiative turn. A 2-4 sentence outro does not fit in 140 tokens with ~45 tokens of
   JSON fields around it.

4. **The next turn after a scene is an ordinary `private` turn**, and `lrgVolatileGuidance` injects
   `lrgOneSentence()` there too (lrg_actions.php:1716):
   > `ONE short sentence, at most 120 characters`

   Observed verbatim in the 23:19:45 context: `Whenever it comes to that, or Lisette acts: ONE short
   sentence, at most 120 characters.`

5. **Nothing tells her the scene is over.** At the moment she speaks her last line the context still
   says `Lisette and the player are in an intimate scene RIGHT NOW`. She is being asked for a
   mid-sex reaction, so she gives one.

**Other things in the current prompts / config that push her towards a quick exit:**

* `heat` is reset to null at `ev=end` and `start.post_scene_cooldown_seconds` (300) blocks
  `BeginIntimacy` - at 17:19:45 "go again" came back `hidden=Start(heat 0<2)`. So the turn right
  after a scene *also* gets the note
  > `A question about this is a question: <npc> answers it in words. <npc> does not start anything physical this turn.`
  i.e. the first post-scene turn is framed as "do nothing", which reads as "wrap up".
* **CHIM's own `End_Conversation` action is on the offer list on that turn** (visible verbatim in
  the 23:19:45 action enum). It was picked 5 times today (twice by Lisette at 12:10/12:11, once by
  Braste), and `eventlog` row 21888 shows its effect: `Lisette leaves the conversation`. The outro
  directive must explicitly forbid it, or she can literally choose to walk off.
* `MaybeSceneTalk` returns early when `windDown` is set, so a wind-down ending is *even quieter*
  than a normal one.

---

## 2. Game: what happens to the NPC at the end, and how to hold her

### 2.1 `FinishThread` (LRG_OStim.psc:2953-2995), in order

```
finishing = true
ReleaseOStimGates("the scene ended")      ; OStim's undress options restored
startNoUndress = false
sceneEndedAt = now                        ; later commands are stale
DropPendingVerb("no scene is running")    ; a parked say-first command is answered
ReleaseListener("the scene ended")        ; -> AIAgentFunctions.setDrivenByAI()  == routing back to the crosshair
SendNpcMessage(MSG_SCENE, "ev=end;...")   ; the only thing the server hears
LogC(cid, asWhy + " last scene=" + ...)
startCid = ""
ResetState()                              ; -> setAnimationBusy(0, partnerName); partnerName = ""
Utility.Wait(1.5)                         ; let OStim redress
  RestoreWeapons()
  RedressRemembered("the scene ended")
  if byglue && baseValid && bRedressAfter: Utility.Wait(2.5); RedressBaseline(cid)
finishing = false
```

`ResetState()` (LRG_OStim.psc:335) additionally calls `ReleaseListener` again, clears `partner` /
`partnerName`, `leadHoldUntil`, `partnerSpeakStart/Stop`, `lastSceneId`, `goneSince`, and drops any
parked verb.

### 2.2 Why she walks off immediately

Three releases happen back to back and **nothing replaces them**:

1. `AIAgentFunctions.setAnimationBusy(0, name)` - CHIM stops treating her as animated;
2. `AIAgentFunctions.setDrivenByAI()` (via `ReleaseListener`) - the player is no longer talking to
   her, so what the player says next routes by crosshair (often the Narrator);
3. OStim itself releases the actor when the thread stops.

From that instant her normal AI package (sandbox / travel back to the Winking Skeever) is in charge
again. **The glue never calls `SetDontMove`, `SetRestrained`, `KeepOffsetFromActor`,
`AddPackageOverride` or `EvaluatePackage` anywhere** (grep over all six `.psc` files: zero hits), so
there is nothing holding her.

Worse: the `Utility.Wait(1.5)` + `RestoreWeapons` + `RedressRemembered` (+ up to 2.5 s more +
`RedressBaseline`) all run **after** she is free - she can be walking away naked for a second or two
while the glue is still dressing her.

`ncl`/`pcl` note: `sceneEndedAt` is set, but no timer, no hold, no outro request.

### 2.3 What CHIM already does for us (so we do not rebuild it)

From `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts`:

* `AIAgentFunctions.psc:31-32` - `setAnimationBusy(int, String npc)` and
  `setLocked(int locked, String npc)` with the comment *"1 locks agent for talking, 0 releases."*
  `setLocked` is the closest CHIM-native lever, but it is a **native black box** (no Papyrus source),
  it is about CHIM's dialogue routing, and nothing in CHIM's own scripts calls it - I could not
  verify that it stops movement. **Do not build the hold on it.** It is safe as an *extra*.
* `AIAgentAIMind.psc:1735-1745` (`StartDialogue`/`FakeDialogueWith`) already does
  `npc.SetLookAt(listener)` **and** `listener.SetLookAt(npc)` **and** `GetIntoConversation(...)`
  every time she speaks, plus an optional "NPCs Walk To Target" (>400 units) behaviour. So **facing
  is already solved for free** for as long as she is speaking.
* `AIAgentAIMind.psc:1861/1878` (`EndDialogueClear`) - CHIM calls `npc.ClearLookAt()` only ~90 s
  after her last line, so the look-at outlives the outro. We must **not** call `ClearLookAt`
  ourselves or we would fight CHIM.
* `AIAgentScriptProxy.psc:677-712, 786-789` exposes `SetLookAt (52)`, `SetHeadTracking (54)`,
  `SetDontMove (55)`, `KeepOffsetFromActor (56)`, `SetRestrained (64)` - proof these are all
  available and that CHIM itself uses `SetDontMove` (`AIAgentAIMind.psc:3663, 3840`).
* `AIAgentFollowPackage` / `WaitPackage` / `DoNothing` forms exist, driven by
  `ActorUtil.AddPackageOverride` - **avoid**: a package override is written into the save and
  survives our mod, which is the classic "NPC stuck forever" bug.

### 2.4 Comparison of the hold options (Papyrus only)

| option | holds her? | failure mode if never released | verdict |
|---|---|---|---|
| **`SetDontMove(true)`** | yes, hard - she stays on the spot, can still turn, talk, play idles | actor flag, persists in the save; she is frozen until someone clears it | **primary**, because it is a single boolean with an exact inverse and touches no packages |
| `SetRestrained(true)` | yes, softer - AI stops driving her | same persistence; also suppresses combat reactions, which is bad in the Solitude Sewers | fallback only |
| `KeepOffsetFromActor(player, ..., afFollowRadius)` | soft - she stays *near*, still wanders | she follows the player forever | poor for a 25 s hold; good as a "bOutroDontMove = 0" soft mode |
| `AddPackageOverride(WaitPackage)` + `EvaluatePackage()` | yes | override persists in the save, outlives the mod | **reject** |
| `setLocked(1, name)` (CHIM) | unverified | unknown | belt-and-braces extra, never the mechanism |
| `SetLookAt(player)` / `SetHeadTracking` | no (facing only) | look-at persists until `ClearLookAt` | not needed - CHIM does it while she speaks |
| `Utility.Wait(n)` in `FinishThread` | no - a wait does not stop her AI | blocks the glue's own tick | **reject** |

**Chosen: `SetDontMove(true)` + let CHIM's own `SetLookAt` handle facing, released through exactly
one function.** `EvaluatePackage()` after `SetDontMove(false)` so her package picks up cleanly.

### 2.5 How the game already learns her line was spoken (reuse, do not rebuild)

The v0.3 say-first machinery is exactly what the outro needs:

* `LRG_Main.Maintenance` registers `CHIM_SpeechStarted` / `CHIM_SpeechStopped`
  (LRG_Main.psc:107-110); CHIM raises them **once per sentence** (`AIAgentAIMind.EndDialogue`).
* `LRG_Main.OnChimSpeechStarted/Stopped` forward to `LRG_OStim.NotePartnerSpeech(npc, started, now)`
  (LRG_OStim.psc:415), which writes `partnerSpeakStart` / `partnerSpeakStop`.
* `AnnounceState(name, now, since, needEnd)` (LRG_OStim.psc:498) already implements
  "started **after** the command (with a 3 s look-back), then stopped, then a quiet settle in which
  nothing started again, and `isActorTalking == 0`" - i.e. **"her whole line is out"**. It is the
  `wait=end` logic that worked today (`announce wait=end waited=6.8 reason=spoke`).
* `Secs()` and `LogWait()` give the log line format.

So the outro release condition is literally `AnnounceState(outroName, now, outroAskedAt, true) == 1`
with a *multi-sentence* twist: the outro is 2-4 sentences and CHIM fires start/stop **per sentence**,
so the settle must be long enough to bridge the gap between sentences. `fSayFirstSettle` (1.0 s) is
too short for that - the outro needs its own `fOutroSettle`, default **2.5 s**, and the release must
also require `AIAgentFunctions.isActorTalking(name) == 0`.

Caveat found today: `WARN empty transcript (npc=Lisette type=inputtext)` at 17:19:09 - CHIM speech
events are not perfectly reliable, which is exactly why the timeout must exist.

### 2.6 Every exit path that must release her (a stuck NPC is a critical defect)

The hold must be released by **one** function (`ReleaseOutroHold(reason)`) called from all of:

1. **her line is done** - `AnnounceState(...) == 1` after the settle (the normal path);
2. **timeout** - `now > outroUntil` (`fOutroHold`, default 25 s);
3. **game load** - `LRG_Main.Maintenance()` runs on every load and calls `ost.Maintenance()`;
   release **unconditionally and first**, because `SetDontMove` persists in the save;
4. **real-time clock restart** - `Utility.GetCurrentRealTime()` restarts at 0 per game launch;
   the existing guard pattern (`now < outroUntil - budget - 5`) must force a release;
5. **combat** - `outroActor.IsInCombat()` or `Game.GetPlayer().IsInCombat()` (Solitude Sewers had 12
   hostile Elytra Nymphs / Gnarls in `actors_nearby` today - this *will* happen);
6. **the player walks away** - `outroActor.GetDistance(player) > fOutroFar` (default 600);
7. **she is gone** - `outroActor == None`, `!Is3DLoaded()`, `IsDead()`, `IsDisabled()`,
   `IsUnconscious()`, cell unload, fast travel (covered by 6 + `Is3DLoaded`);
8. **a new scene** - `OThread.IsRunning(0)`, `starting`, `pendingStart`, or `CmdStart` /
   `BeginStartThread` for anyone;
9. **kill switch / master switches** - `!Main().IsEnabled()`, `!Main().IsIntimacyEnabled()`,
   `bKillSwitch`, `bDryRun`, or `bOutro` switched off mid-hold (MCM apply);
10. **the stop hotkey** - `StopScene()` and the `iKeyStopScene` handler;
11. **`ResetState()`** - unconditional release at the top (it is the generic "forget everything"
    path and is also reached from the start-timeout branch of `Tick()`);
12. **`FinishThread` re-entry** - a second end while a hold is live;
13. **`OnWatchEnded` / CHIM dropping her as an agent** - cheap extra safety;
14. **her own death / the player's death**.

Plus a **self-heal**: at the top of every `Tick()`, if `outroActive` is true but `outroActor == None`
or the hold has been live for more than `2 * fOutroHold`, release and log it. And
`Maintenance()` should release even when `outroActive` is false but `outroActor != None` (a hold
recorded in an older save by a crashed session).

---

## 3. Proposal for R3

### 3.1 Flow (game)

**A. In `FinishThread`, before anything is released:**

```
outroActor  = partner            ; own variables, NOT partner/partnerName (ResetState clears those)
outroName   = partnerName
outroCid    = cid
outroWhy    = asWhy              ; "thread end" | "stop" | "thread gone without an end event" | ...
outroActive = false
```

Start the hold only when **all** of: `bOutro` on, master switches on, the scene really ran
(`now - sceneStartedAt >= fOutroMinScene`, default 45 s, which also keeps a cancelled start quiet -
the same `wasOpen` idea the server already has), `outroActor` is alive / loaded / not in combat,
no new scene is starting, `asWhy` is not the kill switch.

> `sceneStartedAt` does **not** exist yet - only `sceneEndedAt` (LRG_OStim.psc:110) does. Add
> `float sceneStartedAt` set in `OnOStimThreadStart` and `AdoptRunningThread`, cleared in
> `ResetState`, and guarded against the real-time clock restart like every other timestamp. It also
> feeds the new `dur=` key on `ev=end` and the server's `_started_at`.

```
outroActive = true
outroUntil  = now + fOutroHold            ; 25 s
outroAskedAt = 0.0
if bOutroDontMove : outroActor.SetDontMove(true)  / else KeepOffsetFromActor(player,...,200)
; do NOT ReleaseListener yet - keep setDrivenByAIA(partner,false) so the player's reply
;   still goes to her and not to the Narrator
```

**B. Order inside `FinishThread` changes to:**

1. `ReleaseOStimGates`, `DropPendingVerb`, capture the outro fields, **begin the hold**;
2. `Main().MaybeSnapshot(outroActor, true)` - **forced**, so the server's 90 s freshness test cannot
   put the outro turn into `mode=silent` (this is the 17:16:24 `no_fresh_snapshot` trap);
3. send `ev=end` with the new additive keys: `why=<finished|stopped|interrupted>`, `dur=<seconds>`,
   `ncl=`, `pcl=`, `outro=1`;
4. `ResetState()` - but `ReleaseListener` inside it becomes conditional on `!outroActive`, and
   `ResetState` must not clear the `outro*` fields;
5. `AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", outroName)`;
   `outroAskedAt = now`; log `outro requested`;
6. redress exactly as today (`Utility.Wait(1.5)` ...);
7. `Main().RequestTick(1.0)`.

Reusing `lrg_scenetalk` with the text `outro` keeps the wire additive **both ways**: a server 300
sees an ordinary scene-talk request for a closed scene, finds no cue, and falls back to
`TEMPLATE_DIALOG` - i.e. today's behaviour, no regression.

**C. `TickOutro(now)`, called first in `Tick()`:** evaluate the release list in 2.6, then

```
ReleaseOutroHold(reason):
    if outroActor : outroActor.SetDontMove(false) ; outroActor.EvaluatePackage()
                    (ClearKeepOffsetFromActor if the soft mode was used)
    ReleaseListener("the outro is over")
    LogC(outroCid, "outro released (" + reason + ") held=" + Secs(now - outroStart), outroName)
    outroActive = false ; outroActor = None ; outroName = "" ; outroUntil = 0.0
```

Never call `ClearLookAt` - that is CHIM's (`EndDialogueClear`, ~90 s later).

### 3.2 Flow (server)

1. **Case fix first** (1.5) - without it there are no acts and no climaxes to talk about.
2. At `ev=end`, after the summary is written, store an **outro ticket** in `lrg_memory`:
   `outro = { at, cid, scene, why, dur, ncl, pcl, acts[], scenes[], furn, leader, loc, ltype,
   cellown, home, nhome, first_time }` (`first_time` from `lrg_romance.scenes == 1`).
3. Extend the `lrg_scenetalk` precondition in `prompts.php`: when the request text is `outro`, accept
   a **just-closed** row for this NPC instead of an active one - a valid outro ticket younger than
   `outro.window_seconds` (default 60). Keep every safety test unchanged (adult, no child witness,
   `on=1`, master switch, kill switch, `LRG_SHARMAT_PRESENT`).
4. Give the outro its own budget: `scene_talk.max_tokens.outro` default **300**, and a
   `outro.max_chars` of **420** (~4 plain sentences). `lrgApplyTurnRuntime` must not stamp the
   140/200 cap over it.
5. `lrgPrepareTurn`: a new mode `outro` that offers **no glue actions at all** (she only talks), and
   `lrgVolatileGuidance` emits the `<after_intimacy>` block below instead of `<this_moment>` -
   critically **without** `lrgOneSentence()`.
6. Consume the ticket after one use, so an outro can never fire twice.
7. The one-scene memory summary (1.4) stays exactly as it is - one `infoaction` line per scene.
   The outro's own spoken line enters the conversation normally; nothing extra is written.

### 3.3 The scene facts the server passes (rendered into the directive)

| fact | from |
|---|---|
| what they did | `_visited` scene ids -> `lrgDescribeScene` labels, de-duplicated, max 4; plus `_acts_done` families -> `lrgActLabel` |
| how it started / who led | `$prev['leader']` (after the case fix) + `byglue` |
| who came | `ncl` > 0 -> she did; `pcl` > 0 -> he did; both -> both; neither -> neither |
| how long | `now - _started_at` -> "a few minutes" / "a long while" (never a raw number) |
| where | `$prev['furn']` -> `lrgFurnitureLabel`; snapshot `loc`, `ltype`, `cellown`, `home`, `nhome` |
| how it ended | `why=`: `finished` (a climax and a normal thread end) / `stopped` (the player said stop / the hotkey) / `interrupted` (thread gone, combat, reload) |
| history | `lrg_romance.scenes` -> first time with him, or "not the first time" |

### 3.4 The directive text (blunt, plain, no poetry)

Injected at `prompt_bottom` (last), replacing `<this_moment>` for this one turn. `{...}` are
server-rendered.

```
<after_intimacy>
It is over. {NPC} and the player are pulling their clothes back on.
What the two of them just did: {acts/positions, plain words}. {who came}. {how it ended}. {where}. {first time / not the first time}.

{NPC} says goodbye now, out loud, in {NPC}'s own voice: two to four plain sentences, at most {max_chars} characters in total.
1. Say something specific about what they just did - name it, do not talk around it. Not a summary, a reaction.
2. Say plainly what the player is to {NPC} after this.
3. Say what {NPC} does now and why, taken from {NPC}'s own life - work, duties, the time of day, where they both are. Be concrete: a place, a task, a person waiting.
{stay_clause}

No poetry, no metaphors, no romance-novel wording, no narrating actions, no stage directions. Crude words are fine and expected.
Do not ask the player to stay, do not propose anything new, do not start anything physical, and choose NO action this turn - in particular never End_Conversation.
</after_intimacy>
```

`{stay_clause}`, chosen by the server from the snapshot, exactly one of:

* default: *"If {NPC} has no reason to stay, say where {NPC} is going and why."*
* her own place (`cellown=npc` or `home=1`) at night, or the player is her spouse (`fac` marriage),
  or she is a follower/`folok`: *"{NPC} has every reason to stay here - this is {NPC}'s own place / {NPC} travels with the player. Say that {NPC} is staying, and what {NPC} does next right here."*
* the player's own house (`cellown=player`): *"This is the player's home. {NPC} says whether {NPC} stays a while or goes, and why."*

The cue in `prompts.php` for the outro request (the LAST text in the prompt, so it matters most):

```
($NPC has just finished having sex with the player and is getting dressed. $NPC says goodbye:
 two to four plain spoken sentences following the notes above - what they did, what the player is
 to $NPC now, and what $NPC does next and why. Not one line. No poetry, no narration, no action.)
```

`player_request` for that turn: `(they are getting dressed)`.

**Everything to strip from this one turn:** `lrgOneSentence()`, the `ONE short sentence, at most 120
characters` clause of `<intimate_scene_now>` (the scene block must not be emitted at all - the scene
is over), the 140/200 token cap, and the `A question about this is a question...` note.

### 3.5 Worked example (what Lisette should have said at 17:19:30)

Facts the server would have passed: *standing embrace and kiss, then standing holding sex; she came
twice, he came; it ended when they finished; on the floor of the Solitude Sewers; not the first time
today; she leads.* Snapshot: `ltype=wild`, `cellown=none`, occupation "resident bard at the Winking
Skeever", world time 3:32 PM.

> "Gods, standing up against these cold stones - my legs are still shaking, Jordan. You've got me
> twice now and I'm not sorry about either. But I've a set to play at the Skeever tonight and I can't
> turn up smelling like a sewer and walking like this. Come find me after the last song if you want
> more."

Four sentences, 305 characters, blunt, specific, and it ends with a concrete in-world reason. That is
the shape R3 asks for.

### 3.6 MCM settings (remember: a MISSING ini key reads as FALSE/0)

`MCM/Config/LoreRimGlue/settings.ini` - new `[Outro]` section, every key with a default line, and a
matching control in `config.json`:

```ini
[Outro]
bOutro = 1            ; master: off = exactly today's behaviour
fOutroHold = 25       ; max seconds she is held after the scene   (clamp 0..60)
fOutroSettle = 2.5    ; quiet seconds after her last sentence before release (clamp 0..6)
fOutroMinScene = 45   ; a scene shorter than this gets no outro   (clamp 0..600)
fOutroFar = 600       ; release if the player walks further away  (clamp 200..4000)
bOutroDontMove = 1    ; 1 = SetDontMove (hard hold), 0 = soft KeepOffsetFromActor
```

Server `config/lrg_config.default.json`:

```json
"outro": { "enabled": true, "window_seconds": 60, "min_scene_seconds": 45,
           "max_chars": 420, "sentences": "2 to 4", "forbid_end_conversation": true },
"scene_talk": { "max_tokens": { "outro": 300 } }
```

### 3.7 Test hooks

* `tools/flows/run_flows.php`: a flow that drives `ev=start` -> `ev=change` x2 -> `ev=climax` ->
  `ev=end` **with capitalised ev values** (the production case) and asserts that the summary names
  the acts and "they finished", that an outro ticket is written, and that a following
  `lrg_scenetalk` with text `outro` produces the `<after_intimacy>` block with no 120-char clause.
* `test_gates.php`: an outro turn must offer zero glue actions; the ticket must expire after
  `window_seconds`; a scene shorter than `min_scene_seconds` must write no ticket.

---

## 4. Answers to the three questions, in one line each

1. **Server** - `ev=end` is a *fast* message that is `terminate()`d; it writes one `infoaction`
   summary and closes the row. **No turn is triggered, no directive exists.** The "outro" the owner
   hears is the climax scene-talk line under the cue
   *"one short, blunt sentence - 120 characters or less"* (17:14:04 `"Fuck, keep pounding my ass like
   that."`; 17:19:28 `"Fuck, Jordan, my cunt's still twitching."`). The summary is a stub
   (`"...comes to an end (about a minute; Lisette led)."`) because the wire's `ev` values arrive
   capitalised and every `=== 'start' | 'change' | 'climax'` test in the server fails.
2. **Game** - `FinishThread` releases her three ways (`setAnimationBusy(0)`, `setDrivenByAI()`,
   OStim's own release) and holds her zero ways; the glue calls no movement function anywhere. The
   safest Papyrus-only hold is `SetDontMove(true)` released through a single `ReleaseOutroHold`
   reached from all 14 exit paths in 2.6, with CHIM's own `SetLookAt` doing the facing and the
   existing `AnnounceState(..., needEnd=true)` say-first machinery detecting that her line is out.
3. **R3** - hold first, force a snapshot, send `ev=end` with `why/dur/ncl/pcl`, fire
   `requestMessageForActor("outro","lrg_scenetalk",name)`, answer it on the server with an
   `<after_intimacy>` directive that drops every one-sentence cap and forbids `End_Conversation`,
   then release her the moment her last sentence has settled or at `fOutroHold`.

---

## 5. Defects found, ranked

| # | severity | defect | evidence | fix |
|---|---|---|---|---|
| O1 | critical | no turn of any kind at scene end; the "outro" is the climax line | no LLM context between 23:14:04 and 23:16:25, and between 23:19:27 and 23:19:45 | 3.1 / 3.2 |
| O2 | critical | nothing holds the NPC; she is released three ways at once and her package resumes | `FinishThread` + `ResetState`; zero `SetDontMove`/`SetRestrained`/`KeepOffset` calls in all six `.psc` | 2.4 / 2.6 |
| O3 | high | `ev` arrives capitalised -> `_acts_done`, `_climaxes`, landing notes, the start line and the `leader` test all dead | 26 `scene Change`, 5 `Start`, 4 `Climax`, 35 `leader=NPC`, 0 hits for "draw close" / "are now:"; `_acts_done: []`, `_climaxes: 0` in the DB | `strtolower` on arrival (1.5) |
| O4 | high | four separate 120-char / 140-token caps apply to every line around a scene end | `prompts.php` cue, `<intimate_scene_now>` last line, `lrgOneSentence()`, `FORCE_MAX_TOKENS` | 3.2 step 4-5 |
| O5 | high | `lrg_scenetalk` needs an ACTIVE scene row, so an outro request fired after `ev=end` gets no cue at all | `prompts.php` precondition vs `lrgGetActiveScene()` | 3.2 step 3 |
| O6 | medium | the post-scene turn can land in `mode=silent reasons=no_fresh_snapshot` (no guidance at all) | 17:16:24 turn line | forced snapshot, 3.1 step B2 |
| O7 | medium | CHIM's `End_Conversation` is offered on the post-scene turn and makes her walk off | action enum in the 23:19:45 context; 5 picks today; `eventlog` 21888 `Lisette leaves the conversation` | forbid it in the directive |
| O8 | medium | the summary arrives only on the next externally triggered turn; the first scene's summary never reached her at all | only 1 occurrence of "comes to an end" in a 2.1 MB context log | the outro turn consumes it immediately |
| O9 | low | `_tier_since` is used as the scene duration, so a 2.5-minute scene reported "about a minute" | summary text vs 17:17:17-17:19:30 | stamp `_started_at` at `ev=start` |
| O10 | low | redress (`Wait(1.5)` + up to `Wait(2.5)`) runs after she is already free to walk | `FinishThread` tail | covered by the hold |
| O11 | low | `MaybeSceneTalk` returns early on `windDown`, so a wind-down end is even quieter | LRG_OStim.psc:3289 | the outro is independent of `windDown` |
