# LoreRim Glue 0.2.0 - notes for playtest 3 (2026-09-21)

Both halves are installed already. The server plugin is deployed and answering (schema v2, four action rows v7, scene index rebuilt: 607 scenes), and the MO2 mod was refreshed while Mod Organizer was closed. **You do not have to run anything before playing.** The ESP did not change, so no sorting and no Nemesis.

One thing to do inside the game before the first scene: **MCM -> LoreRim Glue -> Keys -> "Stop scene now" is unbound.** Bind it. It is the one route to ending a scene that does not need the server, the LLM or CHIM's lock to be healthy.

If MO2's Overwrite already contains `MCM\Settings\LoreRimGlue.ini`, the new options below appear at their defaults, but anything you changed before keeps your value.

---

## 1. What changed

**Everything OStim's menu does, you can now say.** Position by name, the `P1..P8` keys she is offered, faster / slower, hold / release, climax, "wind down", moving to a bed or chair, who leads, undressing and dressing either of you or just one part. OStim's own UI is never required.

**She can start things herself.** Every 90 seconds or so, while you are close by and nobody has spoken for a while, the game offers her a turn of her own. Almost all of those turns are thrown away on the server before CHIM even locks a request - an NPC who is not interested costs nothing at all. Whether she acts, and how, is hers to decide, from her personality and her CHIM bio.

**In public she suggests privacy instead of making a move.** Her own place, a room at this inn, somewhere quiet - built from real facts (does she own this cell, does her home location have a name, are you standing in an inn, have you rented a bed). The server remembers that invitation for **30 minutes**. When the two of you are actually alone, the guidance flips: now she follows through.

**Plain speech, not poetry.** Metaphor, simile and romance-novel narration are banned outright. Directness follows her character. Where other people could hear, the language is toned down one level regardless of your slider; alone, your slider is what she gets.

**She names what she picked - usually.** When she chooses a foreplay or sex position she says one short sentence naming it, at your explicitness level. Quiet characters do it less often; a character set to "never" never does. When she picks a kiss, an embrace or a cuddle, she says nothing at all and it simply happens - no line, no TTS.

**It should be faster.** No action costs a second LLM call and a second spoken line any more. Intimate turns ask for one sentence and are capped in tokens. The scene index can no longer be built inside a request.

---

## 2. How to do each thing by voice

Say it in your own words - these are just the shapes she understands. The server maps what she writes back onto one verb.

| You want | Say something like |
|---|---|
| start | ask her for it, in private, when she is close enough to you |
| a named position | "let's try it from behind", "on your back", "sit on my lap" - any position in plain words |
| one of her listed options | she is shown `P1..P8`; asking for "something else" works too |
| faster / slower | "faster", "slower", "slow down", or "speed 2" |
| hold back / carry on | "hold it there", "not yet", "wait" / "carry on", "keep going", "let go" |
| climax | "finish", "climax", "together", "finish inside me" |
| wind down | "let's wind down", "let's cool down" - moves to something gentle, slows, holds a while, then ends |
| stop | "stop", "that's enough", "let's stop" - **ends at once, always** |
| move to furniture | "let's move to the bed", "over to the table", "on the chair" |
| you lead / she leads | use a **name**: "you lead" is deliberately ignored because it means opposite things depending on who says it. Say "`<her name>` leads" or "`<your name>` leads". "auto" hands it to OStim once things have reached sex |
| undress / dress | "take your clothes off", "let's both undress", "take mine off", "get dressed" - and "just your boots", "your gloves", "your helmet" for one part |

Asking for the position you are **already in** now does nothing on purpose - she answers in words instead of sliding you into a near-identical animation.

Asking for something two steps beyond where you have got to is refused in character, and she offers the next step instead. Asking for the *next* step works at once.

**"Pull out" is not offered.** None of your 607 installed scenes defines that transition, so every attempt would fail loudly. The words still map to the verb, so a pack that adds one will just work.

---

## 3. NPC-initiated romance: how it behaves and what tunes it

She is given one **word** for how she feels about you - indifferent, curious, interested, drawn - never a number. It comes from CHIM's relationship affinity measured against *her own* threshold (a Jarl's is high, a tavern regular's is low), plus what your renown means to her specifically, plus your Speech skill. Only "drawn" lets her make the first move; everything below that means she can still say yes, but will not start.

What happens where:

- **In public, willing:** no physical move. She steers toward somewhere private and names the place. The invitation is remembered.
- **In public, with an invitation already standing:** she does not nag. She repeats it only if you ask, and can name a different place.
- **Alone, with an invitation standing:** follow-through. She makes her move - a gentle-tier start, or undressing, or a blunt proposition in words.
- **Alone, drawn, no invitation:** she may simply make a move.
- **Not willing:** nothing, ever. No invitation, no approach.
- **A refusal is final for that conversation.** Insisting, flattery and coin do not produce a yes.

Tuning it:

| Where | Setting | Default |
|---|---|---|
| MCM -> Initiative | NPC may approach you | on |
| MCM -> Initiative | How often she may try | 90 s (min 30) |
| MCM -> Scene talk | NPC takes the lead after | 45 s |
| MCM -> Scene talk | Explicitness: Suggestive / Direct / Explicit | Explicit |
| MCM -> Intimacy | Who leads by default | she leads |
| MCM -> Intimacy | Use furniture, Furniture search radius | on, 1200 |
| MCM -> Intimacy | Get dressed again after a scene | on |
| MCM -> Survival | Ignore cold during intimacy, Warmth restored | on, 10 |
| config `initiative.cooldown_seconds` | how long after her own move before she may try again | 300 s |
| config `initiative.chance_percent` | her odds per try, by pace: slow 15, normal 30, eager 50 | |
| config `invitation.ttl_seconds` | how long an invitation stands | 1800 s |
| config `scene_talk.announce_chance` | how often she names the position she picked, by talk style | quiet 40 … crude 90, never 0 |

The config file is `server/lorerim_glue/config/lrg_config.default.json`; copy it to `lrg_config.json` beside it to override. Lists are replaced wholesale, not merged.

The warmth restored per 5 seconds (Survival page) is a **guess**. If you still freeze during scenes, raise it; if the cold bar never moves at all, that hook is not doing what we think and I want to know.

---

## 4. CHIM settings to change so nothing sanitises the content

Evidence with file and line numbers is in `research/v02-chim-nsfw-audit.md`. The glue changes **nothing** inside CHIM; these are yours to set.

1. **Global settings -> Global Connectors -> Scene Classifier: OFF.** This is the request that 404s after every turn (`google/gemma-3n-e4b-it`). If it were ever repaired it would inject a blanket "the actors should behave in an intimate way" note into every scene, which works against per-NPC consent - exactly the wrong direction.
2. **Relationship editor: unlock the relationship of anyone you mean to court.** Lisette's is LOCKED, which freezes her affinity, which freezes her interest word. She can never warm up while that is set.
3. **Action Editor: leave "Require Confirmation" OFF** on the four LoreRim Glue actions (`Begin_Intimacy`, `Change_Intimacy`, `Change_Clothing`, `Suggest_Privacy`). They are already correct; just do not turn it on.
4. **Keep Google models out of every slot.** Gemini only disables its safety filters when a connector carries the `block_none` metadata; no Google model is in a dialogue, summary or diary slot today, so there is nothing to change - just do not put one there.
5. **Only if memories or diary entries come back sanitised** after this playtest: set Summaries + Background & Memory Tasks to Grok 4.3. The summary model cannot be judged from source.

The one real blocker inside CHIM is its word-scoring refusal detector (`checkOAIComplains`), which replaces a reply containing three of its trigger words with a canned line. The glue switches CHIM's own bypass flag on for its guided turns, per request, without touching a CHIM file or setting. Note the scope deliberately: it is also on for ordinary gated conversation with an adult, because an **in-character refusal** is exactly the sentence that trips the detector, and a canned replacement would break the refusal rail.

---

## 5. Speed

The measured cause of slow intimate turns was never the LLM - it was text-to-speech, which runs on the same RTX 4080 the game is rendering on, while CHIM holds its single request lock. Two things on your side:

1. **Take PocketTTS off the game's GPU.** Run `audio.cpp` with the CPU backend and 8+ threads (you have a 7950X), or cap the game's frame rate so the GPU is not saturated. This is 85-95 % of every slow turn and nothing in the mod can fix it.
2. **Switch the Scene Classifier off** (item 1 above). It is a full extra request after every turn, and it fails every time.

On the mod's side, this round: no action costs a follow-up LLM call and its TTS any more; intimate, initiative and scene turns ask for one sentence of at most 120 characters and are capped in tokens; and the 6-second scene index build can no longer happen inside a request.

---

## 6. Test script for this playtest, in order

**0. The crash check first.** With the same NPC as last time, both of you with a weapon drawn, start a scene **from OStim's own menu / key**, not by voice. If it still crashes, the bug is OStim + that NPC and not the glue - tell me and try once more after unequipping her weapon or instrument by hand. Only if this is clean, go on.

**1. Through the glue.** Dry-run off. Talk to her in private until she agrees. Expect a 1-4 second pause while the glue puts your weapons away, then the scene. A "stop" spoken during that pause is honoured as soon as the scene exists, so it may take a few seconds - that is the crash workaround, not a failure.

**2. On furniture.** Do the same standing next to a bed. She should start on it. This is the single least-proven thing in the build: if the scene starts oddly, ends instantly, or the pair float, set `scene_start.prefer_furniture` to `"never"` in the config and tell me.

**3. Verbal control.** In the scene, in this order: "faster", "slower", "hold it there", "carry on", then name a position in plain words, then name the position you are already in (nothing should happen - she should just answer), then "let's move to the bed", then "`<her name>` leads", then "`<your name>` leads".

**4. Progression.** Ask straight away for the furthest thing: she should say it is too soon and offer the next step. Ask for the next step: it should work at once. Then say nothing for about 45 seconds: she should move things along herself, at her own pace.

**5. Speaking the choice.** Watch the difference: when she picks kissing or holding, nothing is said. When she picks foreplay or sex, she usually says one short sentence naming it. Neither should ever be a paragraph.

**6. Climax and wind-down.** "finish" or "together", then "let's wind down" - she should move to something gentle, slow right down, hold a while, then it ends. Then start again and say "stop" instead: it must end immediately, with no wind-down.

**7. Undress and dress.** Outside a scene, in private, with someone who would also agree to intimacy: ask her to undress, then "just your boots", then "get dressed". Walking away should also re-dress her.

**8. Her own move.** Stand near an NPC who is well disposed to you, in private, and **say nothing at all** for a few minutes. She may approach you. Then do the same in public: she should suggest going somewhere instead. Then walk to that place with her and wait: she should follow through.

**9. Ordinary conversation right after a scene.** Nothing explicit may appear. Talk to a shopkeeper, a guard, a child's parent. If anything leaks, that is a bug and I want the log line.

**10. The switches.** Flip the MCM kill switch mid-scene: the glue must fall silent completely (no new lines, no lock, no cold exemption) while OStim keeps playing and your stop key still works. Turn it back on.

The log is `HerikaServer/log/lorerim_glue.log` (inside the distro: `/var/www/html/HerikaServer/log/lorerim_glue.log`). Both halves write to it; game lines are prefixed `GAME` and every line carries the same `cid=` on both sides. Useful lines: `turn npc=... mode=... interest=word(score) invite=...`, `initiative npc=... admitted/dropped`, `gate: ... passed -> ...`, `result ExtCmdLRG_... -> ...`.

If ticks never seem to be admitted, set `"initiative": {"log_drops": true}` in `lrg_config.json` and every dropped tick prints its reason.

---

## 7. What is still not done, and why

- **Nothing in 0.2.0 has been tested in a running game.** No game session is possible from where this was built. The specific unknowns: starting on a bed; moving to furniture that is some way off; whether OStim's auto mode is really stopped in time at thread start; the right amount for the cold exemption; whether CHIM's conversation cooldown swallows the new approach ticks.
- **"Pull out" cannot work on this install** (no scene defines the transition), so it is not offered at all.
- **Beast races.** OStim marks Khajiit and Argonians as having no usable mouth and hides kissing and oral scenes for them. The snapshot carries no race flag, so the index cannot pre-filter a start scene or a jump target for them. Adding one snapshot key would fix it; it is not in this round.
- **FF pairs see fewer positions than OStim would allow.** The index stays conservative about who has what body; strap-on scenes are not offered.
- **The server cannot detect a refusal - hers or yours.** "A refusal is final" is a rule the model is given, not something the server can enforce, and there is no signal for you turning her down. Repetition is limited by the 5-minute cooldown and by the wording only.
- **"Lead the way to my house"** (her actually walking you there) is built but **off by default** and untested: it is written from reading CHIM's source, not from a live run. `invitation.lead_the_way` in the config.
- **Whether the model obeys "leave the message empty"** is a playtest question. CHIM's side is verified: an action with an empty message produces no speech and no filler line, and the action still runs.
- **Provider-side softening by the summary and diary models cannot be proven from source** - item 5 in section 4 is the fallback if it turns out to be happening.
- **Menuless questing (Phase 2)** is designed but not built.
