# Playtest 7 - what went wrong, what is fixed, what to do next

LoreRim Glue v0.3.1 · server deployed 2026-09-21 20:31 · game scripts version 310 · manifest 0.3.1

---

## 1. The big one first: the 18 minutes where nothing worked

From 17:26 to 17:44 every single turn with Lisette logged this:

```
mode=closed  reasons=not_close_enough  aff=0  interest=curious(-10)
```

Closed means the glue hid every action from the AI. No sex action was on the table at all.
So "give me a blowjob", "give me a hug", "let me eat your pussy", "lets do 69", "i want to fuck
your asshole" and "do reverse cowgirl" could not have worked no matter how they were phrased.
Everything you complained about was said inside that dead window.

**Why it went dead.** Between 17:19:54 and 17:26:41 Lisette's relationship entry with you vanished
from CHIM's own database. Her `extended_data.relationships` is literally `[]` right now - I checked
it tonight, it is still empty. Before 17:20 she read aff=10, after it she read aff=0.

**Who deleted it.** Not us. The glue never wrote to CHIM's relationship store before tonight. It was
CHIM's own save-load path: `restoreNPC` in `npc_master.class.php` rolls relationship data back to the
state of whatever save you load. You loaded saves at 17:25:30, 17:28:39, 17:32:52 and 17:35:36. The
first one is the one that did it. CHIM has a setting for exactly this, `NEVER_CLEAR_RELATIONSHIP_DATA`,
and it is **off** on your install (`restoreNPC` honours it at `npc_master.class.php:1406-1410`).

**It will happen again if you do not turn that setting on.** See section 7.

**What we did about it in code.** Three things:

1. The glue now keeps its own copy of her affinity (`lrg_romance.last_affinity`). If CHIM's number is
   missing or has dropped to 0 while we last saw it higher, the gate uses ours and writes a line in the
   log saying so (`affinity rescued for <name>: CHIM missing, our last known 18 (used 18)`).
2. The glue now counts scenes itself. Once you have been together at all she gets a permanent +15 on
   the interest score, +5 per further scene, capped at +30. A reload cannot take that away.
3. A one-time repair of Lisette's number, armed and waiting (section 6).

She can still say no before a scene. That has not changed and never will. What is gone is her
forgetting you entirely because you loaded a save.

---

## 2. The other four failures from that night

| # | what happened | fixed |
|---|---|---|
| F1 | "now get down on your knees and suck my deck" in a scene → recognised, then thrown away as "not reachable right now" → absolutely nothing happened, no words, no note | Unreachable no longer lowers confidence. If there is no animated route she warps into the position instead. It now sends `do=goto;scene=OARE_SittingFellatio;warp=1`. |
| F2 | "take my clothes off and then let's book a missionary" → only the undressing happened | Compound requests now send **two** commands, in the order you said them. Verified: `do=undress;who=player` then `do=goto;scene=OARE_Missionary`. |
| F3 | the AI sent `RequestAct item="vaginal"` and the server resolved it to a *fingering* scene | Act families and positions are now first-class. `vaginal` → a real intercourse scene. `oralvulva` → cunnilingus, not a blowjob. Bare family names no longer get dropped. |
| F4 | out of a scene, alone with her, willing: "take off your clothes" and "bend over" → the AI chose no action at all | The turn now tells her plainly: *this is the moment, choose ChangeClothing with item "undress"* / *choose BeginIntimacy*. Before, it said "whether she does it is her own choice" next to a note telling her to choose nothing if she hesitated. |
| F8 | after sex she said one short line and walked off | Real outro, section 4. |

---

## 3. What you can say now

Player is male, NPC female, which is the pair everything below was checked against. All of it was run
through the real recogniser tonight; the arrow shows the actual command that goes to the game.

### Acts

| you say | what happens |
|---|---|
| give me a blowjob / suck my cock / blow me / suck me off / deepthroat | her mouth on you → `OARE_SittingFellatio` |
| let me eat your pussy / go down on you / lick your cunt / eat you out | your mouth on her → `OARE_KneelingCL` |
| eat my pussy (from a male player) | anatomically impossible; she keeps who does it and it becomes her mouth on you |
| give me a hug / hold me / cuddle with me / embrace me | `OARE_StandingHug` |
| kiss me / make out / french kiss me | a kissing scene |
| jerk me off / use your hand / stroke my cock / handjob | `OARE_AceStandingHandjob` |
| finger me / use your fingers / play with my pussy | a fingering scene |
| fuck my cock with your tits / titjob / between your tits | `OARE_MountedTitfuck` (the word "tits" now beats the word "fuck") |
| grab my ass / squeeze my butt | a butt-grope scene, not a breast one - groping is split now |
| play with your tits / squeeze those | a breast-grope scene |
| touch yourself / finger yourself / masturbate | a masturbation scene |
| sit on my face / ride my face | installed, but only when you say the words |
| with your feet / footjob | installed, but only when you say the words |
| spank me / smack my ass | one scene installed |
| rub your thighs on me / thighjob | one scene installed |
| suck my nipples / my nipples | installed |

### Positions (say them with or without a verb)

| you say | position |
|---|---|
| missionary / lay down and let me fuck you | missionary |
| ride me / let me be on top / cowgirl / get on top | cowgirl |
| do reverse cowgirl / turn around and ride me | reverse cowgirl |
| doggy style / from behind / bend over / on all fours | doggy |
| lets do it spooning / spoon me while we fuck | spooning |
| fuck me standing up / do it standing | standing |
| pick me up / carry me / lift me up | carrying |
| sit on my lap / climb onto my lap | sitting sex |
| let me kneel / on your knees | kneeling |

Note: "spoon me" or "cuddle with me" **on its own** is an embrace, not sex. Add a sex word
("lets do it spooning", "fuck me spooning") and you get the sex position. That is deliberate.

### Clothes

| you say | what happens |
|---|---|
| take off your clothes / get naked / I want to see you naked | she undresses |
| take my clothes off / undress me | she undresses you |
| let's both get naked | both |
| get dressed | she dresses again |

### Two things in one breath

| you say | what happens |
|---|---|
| take my clothes off and then lets do missionary | undress you, **then** missionary - both, in that order |
| first kiss me then take your clothes off | if you are already kissing, the kiss half is skipped as "already doing that" and the undress half runs |
| get undressed and get on the bed | only the undress half. Furniture as a *second* half is still not split out. Say the bed separately. |

### Starting from nothing

Out of a scene, in private, when she is willing: say the act you want and she can start **directly in
it**. No forced kissing lead-in any more.

| you say | what happens |
|---|---|
| i want you to ride me | starts in cowgirl |
| give me a blowjob | starts kneeling, her mouth on you |
| lets do doggy style | starts in standing rear sex |
| come kiss me | starts in a kiss |
| go again / another round / round two | counts as asking to start again |

The server **never** starts a scene by itself. She has to choose the action. She can still refuse
before a scene and that refusal stands. Inside a running scene she never refuses you.

---

## 4. The outro

When a scene ends she now gives a proper goodbye: two to four sentences, capped at 420 characters
(everything else she says around a scene is capped at 120). It covers what the two of you actually
did, how she feels about you now, and where she is going next with a real reason from her own life -
her job, her class, the time of day, where you are. If she lives there, or is your spouse or follower,
she says she is staying instead.

She does not walk off mid-sentence. The game holds her facing you until she has finished.

**MCM: Mod Configuration → LoreRim Glue → Scene control → Afterwards**

| setting | default | what it does |
|---|---|---|
| Keep her with you afterwards for | 25 s | how long her *voice* has to arrive. While she is actually speaking the wait keeps extending in 5 s steps, so a long goodbye is never cut off. 0 switches the whole outro off. |
| Quiet time after her goodbye | 2.5 s | how long after her last sentence before she is free |
| Only after a scene of at least | 45 s | a scene shorter than this gets no goodbye |
| Let her go if you walk this far away | 1500 | she is released instantly if you walk off |

She is also released instantly by: combat, a save load, the kill switch (End key), a new scene, going
through a door, or her being knocked out. A hard ceiling in the script (about twice the budget plus
30 s) releases her no matter what. **A stuck NPC should be impossible. If you ever see one, press End.**

If her voice regularly arrives late, raise "Keep her with you afterwards for" to 40-45.

---

## 5. How the relationship grows now

Sex raises her affinity, through CHIM's own relationship system, the same way any other CHIM
relationship change happens. The relationship *type* is never touched - CHIM reserves the romantic ones.

| rule | value |
|---|---|
| first completed scene with an NPC | +8 |
| every completed scene after that | +4 |
| scene must have run at least | 60 s |
| scene must have ended | normally, or because you said "stop" |
| at most one gain per NPC per | 30 minutes of real time |
| never above CHIM's own cap | 100 |

A crash, a reload, or a cancelled start is worth nothing and is not counted as a scene you had.
A scene of 45-59 seconds gives you a goodbye but no affinity. That is on purpose.

Separately, the glue counts your history itself and puts it on the **interest** score, not on affinity:
+15 once you have been together at all, +5 per further scene, capped at +30. That is the part a save
reload cannot delete.

**Where to tune it.** Windows path to your own override file:

```
\\wsl$\DwemerAI4Skyrim3\var\www\html\HerikaServer\ext\lorerim_glue\config\lrg_config.json
```

Put only what you want to change in it. Everything else falls back to `lrg_config.default.json` in the
same folder - **do not edit that one, a deploy overwrites it.** The keys:

```json
{
  "relationship": { "scene_gain": 4, "first_scene_gain": 8, "gain_window_seconds": 1800, "min_scene_seconds": 60 },
  "leverage":     { "history_bonus": 15, "history_bonus_per_extra": 5, "history_bonus_max": 30 },
  "outro":        { "max_chars": 420, "min_scene_seconds": 45 }
}
```

Your file currently contains one thing - a min_affinity override of -5 for Lisette, added at 17:58
tonight while she was broken. **Now that the real fix is in, take it out** unless you want her
permanently easier than everyone else in her profile.

---

## 6. Lisette's repair - armed, not yet fired

The glue will restore her affinity **once**, from inside a live game turn, and then mark itself done so
it can never run again.

- Before (checked tonight, 20:30): `relationships: []` - no entry at all, aff reads 0.
- Target: **26**.
- It will not run from a command line. It has to happen inside a real turn so the write carries the
  game's own timestamp, otherwise the next reload rolls it straight back.

**Why 26 and not 30.** 18 was CHIM's last good value for her (written 17:19:58, history row 279). The
10 the old log showed was the courting bonus, not CHIM's number. On top of 18 the shipped rule would
have granted exactly one scene gain that evening (+8 for the first scene; the second was three minutes
later, inside the 30-minute window), so 18 + 8 = 26. Thirty assumed two gains.

**If you want 30 instead, change it before your next turn with her**, in the same override file:

```json
{ "relationship": { "repair": { "affinity": 30 } } }
```

then re-run `glue\tools\deploy_server.ps1`. After it has fired, set `"enabled": false` in the repair
block so it is out of the way.

**Do this after your next save load, not before.** The NPC-manager UI save at 17:53 wrote the empty
`relationships: []` into her history, so a reload of an older save can restore that empty state again.
If that happens after the repair has fired, the one shot is spent - but the glue's own last-known value
(26) will still open the gate, and the log will say so.

Confirm it fired:

```
\\wsl$\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log
```
look for `relationship repair: Lisette -> Player set to 26 (was no entry).`

---

## 7. What you have to do by hand

1. **Close Mod Organizer and re-run the installer.** MO2 was open tonight, so I could only copy our own
   files straight into `F:\Modlists\LoreRim\mods\LoreRim Glue` (6 `.pex`, 6 `.psc`, `config.json`,
   `settings.ini` - all 14 hash-verified). The mod is already enabled in the Ultra profile and
   `LoreRimGlue.esp` is already in plugins.txt and loadorder.txt, so the game side **is** complete and
   playable right now. The only thing still stale is `meta.ini`, which still shows version 0.1.0 in
   MO2's left pane. Running `glue\tools\install_mo2.ps1` with MO2 closed fixes that. Cosmetic.
2. **Turn on `NEVER_CLEAR_RELATIONSHIP_DATA` in CHIM's own config**, unless you want the wipe to
   happen again on every reload of an older save. It is a setting, not a code change. CHIM web UI →
   config. It disables CHIM's relationship timeline rollback, so NPCs may remember a relationship from
   a later save than the one you loaded. That is the trade.
3. **Decide the repair number** - 26 (shipped) or 30 (section 6).
4. **Take the Lisette min_affinity -5 override out** of `lrg_config.json` (section 5).
5. **Nemesis does not need to run.** Nothing animation-related changed.
6. If you want anal or 69, **install an animation pack** - section 8.

---

## 8. Known limits - things this modlist simply cannot do

Checked tonight against your three installed packs (OStim Standalone, OStim Community Resource,
Open Animations Romance and Erotica - 607 scenes).

**Not installed at all.** She recognises these, says so plainly in her own voice, and never substitutes
something else:

| act | status |
|---|---|
| anal (any position) | **zero** scenes. Needs an anal animation pack. |
| 69 | **zero** scenes. No scene declares both a penis-oral and a vulva-oral action. |
| rimming / rimjob | none |
| massage | none |

**Positions with no animation.** These fall back to the closest thing that exists rather than refusing:

| you say | what you actually get |
|---|---|
| against the wall | the nearest standing position. There is no against-the-wall sex animation in these packs at all. |
| on the table / on the chair / on the bed, as part of a sex request | a position change, not a furniture move. Say "let's move to the bed" as its own sentence and the furniture move works. |
| on my lap | `OARE_SittingSex`, the closest sitting position |

**Deliberately behind a door.** Footjob (a handful of scenes) and facesitting (2 scenes) are installed
but taste-listed: she will never offer them herself, only do them when you say the word. That matches
the no-fetish rule. Say if you want them fully open or fully off.

**Other gaps.** A furniture move as the *second* half of a compound request is not split out
("get undressed and get on the bed" only undresses). "get on the bedroll" is not recognised at all -
bedroll is not in the furniture vocabulary.

**The corner note is still missing.** When she says she cannot do something, you hear it but you do not
get a `Debug.Notification` in the corner. The game side is built; the server has no way to send the
text yet. She always says it out loud, so nothing is ever silent - it just is not also written on screen.

---

## 9. Reading the log when a request does nothing

```
\\wsl$\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log
```

Every turn writes three lines. Find your sentence in `say="..."` - it is now logged on **every** turn,
including closed and silent ones, which it was not in playtest 7.

```
turn npc=Lisette type=inputtext mode=scene scene=OARE_Doggystyle say="give me a blowjob"
     intent=act/oralpenis:npc conf=high ... offered=... hidden=...
gate npc=Lisette mode=scene offer_start=yes reasons=- aff=26 interest=interested(0) profile=tavern_folk
llm  npc=Lisette action=SceneControl item="..." spoke=140
```

Read it like this:

| what you see | what it means |
|---|---|
| `intent=none/-` | the words were not recognised at all. Say it differently, or tell me the sentence. |
| `conf=low` | recognised but not certain; she is told what you *may* have meant and decides herself |
| `mode=closed` + `closed="..."` | she is not close enough / someone is watching / a quest scene is running. The reason is now spelled out in plain words at the end of the line. |
| `mode=silent` | a child nearby or adult mode off. Nothing happens by design. |
| `reasons=not_close_enough aff=0` | **the playtest-7 bug.** If you see aff=0 on someone you have been with, her CHIM entry was wiped again - see section 1. |
| `net fired SceneControl do=goto;scene=...` | the AI ignored your request, so the server carried it out itself |
| `net skipped: ...` | the safety net decided not to act, and says why |
| `gate: dropped SceneControl - the player asked for X` | the AI answered with the wrong scene and it was thrown away |
| `relationship: Lisette -> Player +8 (first scene, ...)` | affinity gain |
| `relationship: no gain for X - the scene ran 20s (< 60s)` | too short to count |
| `scene not counted for X - it ended as interrupted` | a crash or reload, so it does not count as a scene you had |
| `outro ticket for X: 200s, finished, acts=...` | her goodbye is coming |
| `affinity rescued for X: CHIM missing, our last known 26 (used 26)` | CHIM's number vanished and ours was used instead |

---

## 10. Release record

| gate | result |
|---|---|
| `compile.ps1` | **OK** - 6 .pex, 0 errors, 0 warnings, no over-long .pex string |
| `php -l`, all 53 server + test files | 0 failures |
| `test_scene_index.php` | ALL CHECKS PASSED (607 scenes) |
| `test_gates.php` | 238 passed, 0 failed |
| `test_intent.php` | 142 passed, 0 failed |
| `test_phrases.php` | 13 passed, 0 failed; 90.8% of 654 phrase rows hit (floor 82%), every MUST group 100% |
| `flows/run_flows.php --strict` | 32 scenarios, 672 checks, 0 failed, 0 pending, 0 warnings |
| server deploy | done 20:31, 607-scene index rebuilt, no plugin errors in the glue log or apache error log |
| game install | 14 files copied and hash-verified into the MO2 mod folder (MO2 was open, so the installer itself was not run) |
