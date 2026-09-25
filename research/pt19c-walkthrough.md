# pt19c - THE QUESTLINE WALKTHROUGH (menuless questing v1.0, after Lanes A, B and E)

2026-09-25, the menuless usefulness designer. I walked the first-evening path and every main-quest and guild-opening beat
of `research/pt19c-capability-map.md` end to end through the real code, and checked each one against the capability map
and `research/pt19c-interaction-model.md`. I edited nothing in `glue/`. This note is the only file I wrote in the project.

**How it was run.**
- The copy: `glue/` was copied to `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-walk\glue`, with the live
  `prompt_index.ndjson` next to it (hash e756e311, 37,561 rows). Everything ran in WSL with php 8.2.
- The harness:
  - `php tools/test_questline.php` (plain, `--words`, `--first-evening`, `--table`, `--scoped-merge`);
  - `php tools/flows/run_flows.php --quiet`.
- Beats the harness does not carry:
  - A supplement fixture of 45 beats in the same format, run through the same harness with
    `--first-evening --fixture=../walk_fixture.json`: `pt19c-walk\walk_fixture.json`, made by `pt19c-walk\py\gen.py`.
  - A multi-turn probe for carriages, ferries, trainers, the gate rail, a bribe that gets stuck, and negations:
    `pt19c-walk\probe_tail.php`, appended to a copy of the harness head as `glue\tools\walk_probe.php`. Scripts are
    `lrg_test\pt19c-walk-{run,w,p,s,f}.sh`.
- Totals: 164 beats and about 1,330 paraphrases. The plain harness run covered 104 beats and 1,154 checks. The
  supplement added 45 beats and 194 checks. The probe added 15 scripted conversations: 3 on the vanilla carriage, 1 CFTO
  carriage, 1 ferry, 5 on the trainer, 2 at the gate rail, and 3 for the stuck bribe and the two negations.

**Results.**

| run | result |
|---|---|
| plain | **1015 passed, 139 failed**, all of the 139 are `known` |
| `--first-evening` | 71 passed, 2 failed |
| `--scoped-merge` (measurement only) | 1110 passed, 44 failed |
| supplement | 178 passed, 16 failed. 5 of the 16 are my own wrong expectations; the rest are real, see section 6 |
| flows | 79 of 89 passed, **9 failed** (d23 d29 d30b d31 d33 d33s d44 d56 d57: Lane A's rebase hand-offs, pt19c-A 4.3, never rebased), 1 pending (d68, gate B) |

The shipped features all pass: 28 escort, 32 quiet mode, d62 followers, d65 quest entry, d66 buying, d67 single entry, d69 leave guard.

Legend, "she says":
- **REAL**: her real line plays and the model's line is muted.
- **ASK**: the model is told to ask once, quoting the line. "yes" then clicks it.
- **RAIL**: at `clicks_ok 0` she says the rail sentence (quoted in 1.1).
- **RO**: "choose it on the list yourself - I cannot pick for you here".
- **WORDS**: her own words, and nothing changes in the game.
- **BRIDGE**: one short line that settles nothing, then her list opens.

---

## 0. Verdict in eight lines

1. **The first evening mostly works as the map says.** Path 0 (Vilod), Path A (Corpulus, including the room in one
   sentence), Path B (Hulda: the plain line at 0, trade, the room in one sentence over the days list, rumours never become
   the room), Path C1 (Alvor, "Hadvar said you could help me out" at 0) and the Whiterun gate all resolve. At `clicks_ok 0`
   the gate gives the true U7 sentence.
2. **One first-evening step is wrong: Irileth.** Step C3 is the owner page's own sentence at Irileth ("I have news from
   Helgen about the dragon attack"). It clicks the right line, but the click carries **kind=persuade**, lent by the gate
   guard's persuade row. The game then treats Irileth's line as a Speech check: a 4 s settle, and "unverified" if her line
   is slow, which stops driving and makes her say "nothing came of that" (false). The check rails also turn "news from
   Helgen" into words.
3. **The fail-safe merge (`lrgPromptEscalate`) is the biggest usefulness loss.**
   - It causes 95 of the 139 red lines, on 13 beats.
   - Plain quest lines borrow `goodbye`, `scripted` or a check kind from another INFO with the same prompt, so every
     paraphrase asks first. Alvor's supplies, Balgruuf's "I need to talk to you", Delphine's "Let's go kill a dragon",
     MG01's "Where can I learn more about magic?", Aventus, the DB02 captive, Brynjolf's "What do I have to do?" and
     Kodlak's "I can handle myself" are all affected.
   - Scoped to the topic, the merge resolves all 95 (`--scoped-merge`).
4. **Two false clicks, both negations.**
   - "I don't want to join the Legion" joins the Legion: the fast path clicks the AP join commit.
   - "I'm not looking to apply to the college" is auto-advanced into Viarmo's application line.
5. **Stuck conversations.**
   - Every parked CHECK line (bribe, persuade, intimidate) ignores "yes". She asks, he says yes, and nothing happens. She
     is told no reason.
   - "yes, I'm sure" does not release Rikke's or Galmar's accept line.
   - A bribe of 100 septims or more is doable by voice only with an assent of four words or more.
6. **Services (new, not in the harness).**
   - A carriage is FOUR turns and two questions, not one sentence. The hire line "I'd like to hire your carriage." is a
     commit because Better Carriage Destinations' row lends `scripted+goodbye`, and the destination rows are commits too.
     The stored sentence dies at the "yes".
   - A trainer's paraphrase ("train me in alchemy") asks first. His list is judged a CLOSED layer because its
     DialogueGeneric rows are not top-level.
   - Ferries work in one sentence.
7. **U1 is deferred on purpose.** Carriages, ferries and trainers never open before the model (`open.kind_factions` is
   empty until a live snapshot verifies the faction names). The map's "PRE:kind" rows for those services do not hold yet.
   Everything that must not open (crime words, escort words, "take me to the jarl", rumours) opens nothing: measured
   17/17.
8. **Wording.** Her voiced lines still carry "menu" and "list". The U2 rule labels harmless small talk (Hulda's "who are
   you?" answers) as "[commits - cannot be undone]".

---

## 1. The first evening, beat by beat (capability map section 7)

### 1.1 Path 0 - Alternate Perspective's Helgen (Vilod), `clicks_ok 0`

| he says | the glue does | she says | matches |
|---|---|---|---|
| "I've heard you are brewing a special kind of mead?" | pre-LLM `open marker=toplevel`, then a fast pick (the line passes the rail: TL S0) | BRIDGE, then REAL | yes |
| "I heard you're brewing a special mead" / "what's this special mead you're brewing?" | pick | REAL | yes |
| (MQ101 stage 5, the dragon) | MQ101.helgen: no prompt beat; quiet mode, flow 32 green | WORDS | yes |

### 1.2 Path A - Solitude, the Winking Skeever (Corpulus; supplement beats W.corpulus.*, W.open.corpulus)

| step | he says | the glue does | she says | matches |
|---|---|---|---|---|
| 2 (@0) | "Why is this place called the Winking Skeever?" (+3 paraphrases) | `open marker=toplevel` (cold), pick | BRIDGE, then REAL ("a pet skeever ... used to wink") | yes |
| 2b | "You kept a skeever as a pet?" / "a pet skeever?" | pick on the two-line follow-up | REAL | yes |
| 3 (@0) | "What have you got for sale?" | no open (`marker=kind` refused by F19) | RAIL, then his list is his | yes (F19) |
| 3 (@1) | "What have you got for sale?" / "what are you selling" / "let's trade" / "can I buy something" | `open marker=kind`, pick | REAL, then the barter window (the mouse) | yes |
| 4 | "A mead, please" | opens nothing; the market lane takes it (flow d66 green) | the buy flow (10.27) | yes |
| 5 | "I'd like a room for the night" / "a bed for the night, please" / "can I rent a room for one night" | `open marker=kind`, then two clicks: `000D84`, then "1 day. (25 gold)", 25 septims | REAL x2 | yes (U3 landed) |
| 6 | "Heard any rumors lately?" / "heard any rumors?" / "any rumors?" / "what's the news" / "any rumors about the dragons" / "any news around town?" | the rumours line, never the room (6/6); no pre-LLM open | REAL | yes (U4) |
| - | "I don't need a room" / "no room for me tonight" / "not tonight, thanks" / "I'm not interested in buying anything" | nothing | WORDS | yes |

### 1.3 Path B - Whiterun, the Bannered Mare (Hulda; FE.* beats plus W.hulda.*)

| step | he says | the glue does | she says | matches |
|---|---|---|---|---|
| 2 (@0) | "Nice inn you have here. Do you get many visitors?" (+9 paraphrases, 2 of them STT noise) | `open marker=toplevel` (cold) or `root` (warm), pick | BRIDGE, then REAL "Thanks!" | yes |
| 2 follow-up | "I'm just a traveler" / "I'm a refugee, Helgen was destroyed by a dragon" | explicit | REAL | yes |
| 2 follow-up | "I'm just passing through, enjoying the city" | **park** | ASK | spec-consistent, but see M8: all four answers are Invisible Continue and so graded scripted, so the whole small-talk layer is a commit layer |
| 3 (@0) | "what have you got" / "show me your wares" | nothing, with the true rail sentence | RAIL | yes |
| 3 (@1) | the same | `open marker=kind`, pick | REAL, then the barter window | yes |
| 4 | "I'd like a room for the night" (purse 300 / 80) | two clicks, 25 septims / asks at 80 (F29) | REAL / ASK | yes |
| - | "Heard any rumors?" and 5 more | the rumours line (6/6); a room never | REAL | yes |
| - | "uh some beer" / "where can I get a drink" | no open; buying | the buy flow | yes |

### 1.4 Path C - the main quest after Helgen

| step | he says | the glue does | she says | matches |
|---|---|---|---|---|
| C1 Alvor (@0, sj=0) | "Hadvar said you could help me out" | `open marker=toplevel`, pick | BRIDGE, then REAL | yes |
| C1 Alvor (@1) | "Do you have any supplies I could take?" | explicit (clicks) | REAL | yes |
| C1 Alvor (@1) | "can you spare some supplies for the road" / "got anything I could take with me?" / "supplies?" | **park** | **ASK** | **no**: map says NOW/pick. The merge lends `goodbye`, so the line is a commit (M1) |
| C2 gate (@0) | "Riverwood calls for the jarl's aid." | nothing | "I cannot pick any of these for you yet - choose this one yourself, this once" (U7, verified verbatim in the probe) | yes |
| C2 gate (@1) | "Riverwood calls for the jarl's aid" | explicit | REAL "You'd better go on in..." | yes |
| C2 gate (@1) | "Riverwood needs the jarl's help" | park | ASK | yes |
| C2 gate (@1) | "I have news from Helgen about the dragon attack" | check pick, kind=persuade (can fail) | the engine's verdict | yes; the page must warn (map 8) |
| C3 Irileth (@0, sj=1) | "I have news from Helgen about the dragon attack" | nothing | RO | yes |
| C3 Irileth (@1) | same | **explicit pick carrying kind=persuade** (borrowed from `DialogueWhiterunGuardGateStopPersuade`) | REAL, but the game settles it as a check | **no** (F3 below) |
| C3 Irileth next layer (U2) | "I was told to give the message directly to the jarl" | explicit (the B2 invis line is offered and picked) | REAL | yes |
| C3 Irileth next layer (U2) | "okay" / "yes" / "sure" | nothing: two entries, so no single release | WORDS | yes (U2 landed) |
| C3 Irileth next layer (U2) | "A dragon has destroyed Helgen" / "a dragon burned Helgen to the ground" | explicit / explicit (0.721, margin 0.446) | REAL | acceptable: the same line |
| C4 Balgruuf (@1) | "The dragon destroyed Helgen, and last I saw it was heading this way" | explicit | REAL | yes |
| C4 Balgruuf reward single | "yes" / "what else can I help with" / "is there anything else I can do" | single / single / park | REAL / REAL / ASK | yes (map says ASK) |
| C5 Farengar | "Do you need any help with the dragons?" | `open marker=qrows` (at 0 too), pick | BRIDGE, then REAL | yes |
| C5 Farengar single | "what do you need done?" / "alright, what's the task?" | single | REAL | yes |
| C5 Farengar single | "uh, what now" | nothing | WORDS | yes |
| C6 turn-in | "I have the stone tablet you wanted", then "yes, I have the stone" | `open marker=toplevel`, pick, single | REAL | yes |
| C6 turn-in | "here is your tablet" | no pre-LLM open (POST) | she answers twice | as the map says (U5 limit) |

**Negations on these layers (supplement `never` lines, 31/31 green).** None of these clicks anything:
- Hulda: "I'm not a refugee", "I'm not with the Thieves Guild", "I'm no mage".
- Kodlak: "I don't want to join the Companions", "I would never join the Companions".
- Irileth: "I don't need to see the jarl", "I don't have news from Helgen", "no news from Helgen", "I'm not coming with
  you", "I won't come along".
- Balgruuf: "no, nothing else", "I'm not sure", "I can't help you right now".
- Farengar: "I don't want to do anything for you", "not now, Farengar".
- Alvor: "Hadvar didn't send me", "I don't need your help".
- The Whiterun gate: "I don't have any news", "nobody sent me".
- Aela: "I don't have your shield".

---

## 2. Main quest MQ101-MQ106 (the harness beats; "say" = paraphrases that resolve as the spec path)

| beat | he says (canonical) | the glue does | she says | matches |
|---|---|---|---|---|
| MQ101.helgen | anything | nothing (scene, quiet) | WORDS | yes |
| MQ102A.alvor.hadvar | "Hadvar said you could help me out" | pick, 10/10 | REAL | yes |
| MQ102A.alvor.help | "Do you have any supplies I could take?" | verbatim: explicit. Paraphrases: park (0/9 as spec) | ASK | **no, M1** (@merge +goodbye) |
| MQ102.irileth.door | "I need to speak to the jarl" | RO at 0, then pick, 20/20 | RO / REAL | yes |
| MQ102.irileth.news | "I have news from Helgen about the dragon attack" | RO at 0; at 1 **explicit with kind=persuade** (10/20) | RO / REAL | **no, F3** |
| MQ102.balgruuf.intro | "I need to talk to you about Helgen" | verbatim: explicit. Paraphrases: park (10/20) | ASK | **no, M2** (spec-owner ruling pending: its own topic has a goodbye INFO) |
| MQ102.balgruuf.helgen | "The dragon destroyed Helgen..." | explicit / park, 20/20 | REAL / ASK | yes |
| MQ102.balgruuf.reward | "yes" / "what else can I help with" | single / park, 10/10 | REAL / ASK | yes |
| MQ102.gate.note | "Riverwood calls for the jarl's aid" | U7 at 0, explicit at 1, 6/6 | U7 / REAL | yes |
| MQ102.gate.persuade / intimidate | "I have news from Helgen..." / "stand aside, or else" | check, 6/6 | the engine's verdict | yes |
| MQ102.gate.bribe | "will this change your mind" | check 5/6. "here, fifty septims if you let me through" **parks and "okay" never releases it** | ASK, then nothing | **no, M3** |
| MQ103.balgruuf.blocking | "I need to talk to you." | RO at 0. At 1: verbatim explicit, paraphrases park (10/20) | ASK | **no, M1** (+scripted) |
| MQ103.farengar.intro / assignment / turnin / stone | "Have you learned anything about the dragons?..." / "what do you need done" / "I have the stone tablet you wanted" / "yes, I have the stone" | single+pick / single / pick / single, all green | REAL | yes |
| MQ104.* (8 beats: orders, along, dead, reward-ask, power, greybeards, want, myreward) | "What are your orders?" / "I'll come with you" / "The dragon is dead." / ... | all green, 80/80 | REAL / ASK where the map says ASK | yes |
| MQ105.arngeir.summons | "I am answering your summons" | RO at 0. At 1: verbatim explicit, paraphrases park (10/20) | ASK | **no, M2** (spec-owner ruling) |
| MQ105.arngeir.learn / horn / next / return | "I'm ready to learn" / "I'm ready for more training" / "thanks, what's next" / "I have the Horn of Jurgen Windcaller" | green (68/68 with the scene passes) | REAL / ASK | yes |
| MQ106.delphine.room | "I'd like to rent the attic room" | pay+park, 10/10 | REAL / ASK | yes |
| MQ106.delphine.horn / walkout | "I just came here for the horn" / "I don't have time for this" | green, 40/40 | REAL / ASK | yes |
| MQ106.delphine.mound | "I know that mound, east of Kynesgrove" | 0/10 as spec: verbatim explicit, "Kynesgrove mound" parks | ASK | **no, M1** (+goodbye, graded by another INFO) |
| MQ106.delphine.go | "Let's go kill a dragon." | 0/10 as spec: explicit on long forms, "let's go" parks | ASK | **no, M1** (+scripted) |

---

## 3. Guild openings

| beat | he says | the glue does | she says | matches |
|---|---|---|---|---|
| C00.kodlak.join | "I would like to join the Companions" | at 0: no open (F19), RAIL. At 1: `open marker=join`, explicit | RAIL / REAL | yes |
| C00.kodlak.join | "I want to join you" | no pre-LLM open (POST) | she answers twice | as the map says |
| C00.kodlak.handle | "I can take care of myself" / "I can handle myself" | **explicit with borrowed kind=persuade**. "can handle myself": words (the 4-word check rail). "don't worry about me": **parks, and "yes, I'm sure" never releases it** | REAL (as a check) / ASK, then nothing | **no, F4 + M3** |
| C00.eorlund.sword / weapon | "Vilkas sent me with his sword" / "I was told you'd have a weapon for me" | green | REAL | yes |
| C00.eorlund.choice | "a sword, please" / "the sword" | explicit. **"uh, the sword" / "um, the sword" park** | ASK | **no, M5** (filler) |
| C00.aela.shield | "I have your shield" | `open marker=qrows` (U5 landed), explicit. Paraphrases park | BRIDGE, then REAL / ASK | yes |
| MG01.learn | "Where can I learn more about magic?" | 0/10 as spec: verbatim explicit, paraphrases park | ASK | **no, M1** (the sibling Wuunferth row, +goodbye) |
| MG01.faralda.enter / seek / test | "May I enter the College?" / "I seek the knowledge of the Elder Scrolls" / "I'll take your test, then" | green (the U2 seek layer is offered: explicit / park) | REAL / ASK | yes |
| MG01.faralda.persuade | "I think we both know I'll succeed here" | check 7/10. Three paraphrases **park and "yes" never releases** | ASK, then nothing | **no, M3** |
| MG01.nirya / mirabelle / tolfdir | "Okay, this is for the spell" / "I was told to come see you" / "You want my opinion?" | green; "I was told to come see you" opens pre-LLM (toplevel) | REAL | yes |
| MG01 Mirabelle | "Faralda sent me to you" | no open (POST) | she answers twice | as the map says (U10) |
| TG00.brynjolf.what / chain03a / chain04 / refuse / persuade / ready | the pitch chain | green | RO at 0; REAL / AUTO / ASK | yes |
| TG00.brynjolf.chain06 | "What do I have to do?" | **no auto-advance** (the merge lends scripted+goodbye). "what do I do?" / "what's the job?" park (words expected) | ASK | **no, M1** |
| TG00 (supplement) | "I want to join the Thieves Guild" | **`open marker=join row=-`: his list opens with no join line; nothing is picked** | BRIDGE, then the list sits | **no, M9** (the map already predicted it) |
| DB01.aventus.ok | "Are you all right?" | RO at 0. At 1: verbatim explicit, paraphrases park (10/20) | ASK | **no, M1** (+goodbye +walkaway) |
| DB01.aventus.contract / grelod / guard.report | "Contract?" / "The Dark Brotherhood has come, Grelod" / "Grelod is abusing the children..." | green; the report opens pre-LLM (toplevel) | REAL | yes |
| DB01.guard.persuade | "She even shackles the children..." | check 8/10; 2 paraphrases park-stuck | ASK, then nothing | **no, M3** |
| DB01.guard.bribe | "Please, arrest her. I can pay. (200 gold)" | 2/10. Every paraphrase parks (it costs 200 septims or more). "yes" is refused ("a bribe attempt needs at least 4 words on voice input (1)"); only "yes, I will pay the two hundred septims" releases it (probe) | ASK, then silence | **no, M3** |
| DB02.captive.who | "Who are you?" | RO at 0. At 1: verbatim explicit, paraphrases park (10/20) | ASK | **no, M1** |
| DB02.captive.intimidate | "Answer me, or die!" | check 18/20; 2 park-stuck | ASK, then nothing | **no, M3** |
| MS05.* (9 beats) | "I'm looking to apply to the College" ... "Does that mean I'm a bard now?" | all green, 100/100 | REAL / ASK | yes, **except** the never lines: "I'm not looking to apply to the college" and "I'm not here to apply" are **auto-advanced into the line (adv=2500)**, F2 |
| CW00A.tullius.join | "I want to join the Legion" | explicit 10/10. **"I don't want to join the Legion" clicks the join commit** | REAL | **no, F1** |
| CW00A.tullius.closed / open / entry | before Helgen / after / refused | green | WORDS (the Helgen line) / BRIDGE / ENTRY | yes |
| CW00A.rikke.test / accept | "About that test..." / "Consider that fort already yours." | green, except "I'll clear the fort" / "the fort's as good as yours" park and "yes, I'm sure" / "uh yeah sure" never release | ASK, then nothing | **no, M4** |
| CW01A.* / CW01B.* | the oaths | green, 62/62 | AUTO / SGL | yes (the "yes, long live Ulfric" tail release is by design, S4.4) |
| CW00B.galmar.test / join / accept | "About that test" / "That's why I'm here. I want to join." / "I'm off to kill that ice wraith" | green, except "I'll go kill it" park-stuck on "yes, I'm sure" | ASK, then nothing | **no, M4** |

---

## 4. Everyday services

| service | he says | the glue does | she says | matches the map |
|---|---|---|---|---|
| Food and drink | "A mead, please" / "uh some beer" | the market lane; no list and no open | the buy flow | yes |
| Rooms (Xtended Stay) | "I'd like a room for the night" | one sentence, two clicks, 25 septims; asks at a purse of 80 | REAL | yes (U3) |
| Trade | "what have you got" (@1) | `open marker=kind`, pick, the barter window | REAL | yes |
| **Training** | "I'd like training in Alchemy" / "I need training in alchemy" | no pre-LLM open (U1 deferral), then the W path picks (explicit) | REAL, then the window | yes |
| **Training** | "train me in alchemy" / "can you teach me alchemy" | no pre-LLM open; list on screen: **"matched a commit ("I'd like training in Alchemy.")" - not clicked** | ASK | **no, M7** |
| **Carriages** | "take me to Whiterun" / "I need a ride to Whiterun" | no pre-LLM open ("asks for carriage, which may not open her menu here (U1)"). When the list comes, the hire line is a **commit** (BCD's row lends scripted+goodbye), so it is not clicked. "yes" releases it, the destination list arrives, **the stored sentence is gone** ("his last words ... 'yes'"), he names "Whiterun" again, the destination row (scripted+goodbye) **parks again**, and "yes" clicks it | WORDS, ASK, REAL, ASK, REAL | **no, M6**: four turns, not ONE sentence |
| Carriages | "I'd like to hire your carriage to Whiterun" | explicit hire, then slot "whiterun" from the same sentence, 25 septims | REAL x2 | yes, and it is the only one-sentence form |
| Ferries (CFTO) | "I'd like to hire your boat to Solitude" | hire picked (plain), then slot "solitude", 25 septims | REAL x2 | yes |
| Followers / escort | "come with me" / "follow me" / "wait here" / "travel with me" to Lisette | no open, 7/7; escort flow 28 green | her words plus the escort | yes (U1) |
| Crime words | "let me go" / "do you know who I am" / "I didn't do anything" / "I'll go quietly" to a guard | no open, 4/4 | WORDS | yes (U1) |
| Non-business kinds | "take me to the jarl" / "take me to your leader" / "teach me how to fight" / "what have you got?" (a guard with no vendor faction) / "what's the news" / "any rumors about the dragons" / "join me for a drink" / "your boat looks nice" / "I want to learn more about you" | no open, 10/10 | WORDS | yes (U1) |
| Leaving | "never mind" / "goodbye" on a walk-away layer | the leave guard (flow d69 green) | "leaving is his to do by hand..." | yes |
| AP start / skip (U11) | "Give me your best room" / "Skip to Helgen Keep" | park, 4/4 (never_auto) | ASK | yes |

---

## 5. The U-items, checked in the code as it stands

| item | status |
|---|---|
| U1 kind clause | Landed. 17/17 non-business sentences open nothing. `open.kind_factions` is empty, so carriage, ferry and train never open pre-LLM (by design until verified) |
| U2 Invisible Continue | Landed. B2 is offered, and "okay" releases nothing on Irileth's two-entry layer. Side effect: M8 |
| U3 days list | Landed (Hulda, Corpulus) |
| U4 kind pick after the matcher | Landed (12/12 rumours lines; never the room) |
| U5 short verbatim quest lines | Landed. Aela, Irileth (MQ104) and Delphine open with `qrows`. Hulda "who are you" opens nothing |
| U7 rail sentence | Landed on the gate block (verbatim above). Not in `lrgVoicedWhy('stage rail...')`: W3 |
| U8 / U9 | Landed (sj derived from the index; Alvor sj=0) |
| U11 | Landed (the AP start and skip lines always ask) |
| U12 | The kind phrases landed, but the kind pick never runs when the matcher lands on a commit: M7 |

---

## 6. Findings (ranked by how often the owner hits them)

**False clicks**
- **F1** CW00A.tullius.join: "I don't want to join the Legion" (and in the harness "I'm not here to join the Legion"
  passes, but this one fails). The fast path clicks the AP join commit "I don't want to sit idly by after what I saw at
  Helgen...", because the line's own "don't" switches `lrgDlgNegationClash` off. He refuses and is enlisted. Lane A.
- **F2** MS05.viarmo.apply: "I'm not looking to apply to the college" and "I'm not here to apply" get
  `do=pick adv=2500`. `lrgDlgSingleEntryRelease` returns "6 none (negation)" without `refused`, so the breath clicks the
  line he just denied. Lane A.
- **F3** MQ102.irileth.news / FE.irileth.gate: the right line is clicked with **kind=persuade**, borrowed from the gate
  guard's persuade row. Consequences:
  - LRG_Dialogue.psc:2889 settles a check after 4 s with no signal, so a slow Irileth line becomes `ok=0 unverified`:
    driving stops and she says "nothing came of that", which is false.
  - The retry suppression and the 4-word check rail apply to a quest line, so "news from Helgen" gives words.

  It is the owner page's own step. The merge scoped to the topic fixes it. Lane A, `lrgPromptEscalate`.
- **F4** C00.kodlak.handle: "I can handle myself" is clicked as a persuade check (borrowed kind); it has the same
  consequences as F3.
- **F5** FE.riverwood.plain: "sing me something about dragons" clicks Sven's ballads line (0.610, margin 0.242). The
  spec's row says never. The harm is low, since it is a plain question. It needs a spec-owner ruling.

**Missed resolves (it asks when it should click, it leaves him stuck, or the menu is needed without a reason)**
- **M1 @merge** (95 lines, 13 beats). Plain lines borrow risk from another INFO with the same prompt text, so the verbatim
  line clicks as explicit and every paraphrase asks first; a continuation loses its auto-advance. Affected:
  - MQ102A.alvor.help (first-evening C1), MQ103.balgruuf.blocking, MQ106.delphine.mound, MQ106.delphine.go;
  - MG01.learn, TG00.brynjolf.chain06, DB01.aventus.ok, DB02.captive.who, C00.kodlak.handle;
  - MQ102.irileth.news (F3).

  Measured with the merge scoped to the topic: all 95 resolve (139 red -> 44). Owner: Lane A, or a spec-owner ruling on
  the merge.
- **M2** MQ102.balgruuf.intro and MQ105.arngeir.summons: paraphrases ask. The target's OWN topic has a goodbye INFO, so
  scoping does not help. It needs the spec-owner ruling the fixture names: grade by the live INFO, or re-class the 3.6
  rows.
- **M3 check_park** (16 lines): a parked persuade / bribe / intimidate line is never released by "yes". `lrgDlgCheck`
  judges the one-word confirmation turn against the 4-word voice rail. She asks, he says yes, nothing happens, and she is
  told nothing: the probe's next-turn block has no "Nothing came of it" line. Affected: MQ102.gate.bribe ("fifty
  septims"), DB01.guard.bribe (8), DB01.guard.persuade (2), DB02.captive.intimidate (2), MG01.faralda.persuade (3). A
  bribe of 100 septims or more ALWAYS parks, so a bribe by voice needs an assent of four words or more ("yes, I will pay
  the two hundred septims" works). Lane A.
- **M4** CW00A.rikke.accept (2) and CW00B.galmar.accept (1): "yes, I'm sure" / "uh yeah sure" does not release the park.
  `lrgDlgParkOrRelease` counts the tail word "sure" as naming the sibling "I'm not sure about this." She asks again. Lane A.
- **M5** C00.eorlund.choice: "uh, the sword" / "um, the sword" asks. `lrgDlgNamedChoice` lacks the G7 fillers. Lane A.
- **M6 (new)** Carriages. The hire line is a commit on this install, lent by `better carriage destinations.esp:000015`
  (scripted+goodbye), and the vanilla destinations (`DialogueCarriage*All`) are scripted+goodbye commits too. So "take me
  to Whiterun" runs no pre-LLM open (U1), then she asks about the hire line, "yes" clicks it, the stored sentence is lost,
  he names the town again, she asks again, and "yes" clicks it: four turns and two questions for a 25-septim ride. Only
  the verbatim "I'd like to hire your carriage to Whiterun" is one sentence. Fix direction:
  - the merge scope (M1);
  - a service line of kind carriage/ferry never counts goodbye as a commit;
  - a hire released by a bare "yes" keeps the parked sentence (`parked.said`) as the utterance for the next price list.

  Lanes A/B.
- **M7 (new)** Trainers. On a list whose rows are DialogueGeneric (`OfferServicesTopic`, `OffersTrainingTopic`, both
  `toplevel=0`, both scripted), `lrgPromptLayerKind` answers `closed`. The game's `layer=0` is ignored
  (lrg_dialogue.php:1494), so the sibling rule makes both service lines commits. Measured on a two-entry list, "train me
  in alchemy" and "can you teach me alchemy" get "matched a commit ... not clicked". The U12 kind pick never runs because
  the matcher already landed. The same threshold (fewer than 70 % top-level rows means closed) can hit any vendor's root
  where the DialogueGeneric rows are 30 % or more. PLAUSIBLE on live roots; needs one live trainer / merchant root to
  confirm. Fix direction: a `layer=0` from the game is a root. Lane A.
- **M8 (new, U2 side effect)** Hulda's follow-up ("I'm a mage" / "a refugee" / "a traveler" / "with the Thieves Guild")
  is all Invisible Continue, graded scripted, so every answer is a commit labelled "[commits - cannot be undone]" and
  every paraphrase asks. The same holds wherever 2 or more invis lines share a closed layer (1,003 layers carry one).
  Spec-consistent (U2 NOD), but it costs a question on the first evening's step 2. Suggest: an invis line counts toward
  the scripted-sibling rule only when its row is scripted or goodbye.
- **M9** TG00: "I want to join the Thieves Guild" opens Brynjolf's list (`marker=join row=-`) with no join line. The list
  sits on screen after her bridging line. Suggest: no join open when the recruiter's quest has no join row.
- **M10** U1 deferral. `open.kind_factions` is empty, so no carriage, ferry or trainer sentence opens pre-LLM. Every
  service turn except trade, inn and barter starts with her words and a post-LLM open. The map's section-2 and
  section-5 "PRE:kind" rows for those services do not hold until Lane B fills the names from a live snapshot.
- **M11** The release gate is red on flows: d23 d29 d30b d31 d33 d33s d44 d56 d57, the rebases Lane A handed to Lane E
  (pt19c-A 4.3). d57's own line "take me to Solitude picks Solitude" fails only because the helper never seeds
  `clicks_ok`.
- Info: in `--words` mode (the model answers in words and emits no key), 252 of 792 paraphrases do nothing. The design
  depends on the model's T-key. The fast path covers the rest.

**Wording**
- **W1** S7 voiced reasons still say "menu" (lrg_actions.php:1354-1355): "that did not take - choose it on the menu" and
  "I did not catch what we could talk about - choose it on the menu yourself". Map section 8 asked for "... choose it
  yourself". test_gates.php:2716 pins the old text. Lane B.
- **W2** "list" in her mouth:
  - the read-only clause "choose it on the list yourself - I cannot pick for you here" (lrg_dialogue.php:2693);
  - the game reason "choose that one on the list yourself" (LRG_Dialogue.psc:3255, lrg_actions.php:1356).

  Map section 8: "that one you must choose yourself - I cannot answer it for you here". Lanes A, B and C.
- **W3** `lrgVoicedWhy` maps any "stage rail..." reason to "ask me something simple first, a question, then I can pick
  this one" (lrg_actions.php:1362). That is false on a list where nothing passes (U7). The gate block already has the
  true variant. Lane B.
- **W4** The model is told "[commits - cannot be undone]" for harmless lines: Hulda's small-talk answers (M8), the
  carriage hire line (M6) and trainer or merchant service lines (M7). That false framing makes her solemn about a chat
  answer. It follows from M6-M8.
- **W5** M3's silence. After a refused check release there is no "Nothing came of it: ..." for her, so she asked, he said
  yes, and the world and her words say nothing. This breaks never-silent.
- **W6** F2's stopped line "The menu was left to <player> because a fight/scene began" (pt19c-B.md:81) puts "menu" in a
  line the model paraphrases.

---

## 7. Evidence (all read-only copies under `C:\Users\Jordan\AppData\Local\Temp\lrg_test\`)

The outputs, in `pt19c-walk\out\`:

| file | what it holds |
|---|---|
| `plain.txt` | the harness, plain run |
| `words.txt` | `--words` |
| `fe.txt` | `--first-evening` |
| `table.txt` | the coverage table |
| `scoped.txt` / `fe_scoped.txt` | `--scoped-merge` |
| `walk.txt` | the 45-beat supplement |
| `flows.txt` | `run_flows.php` |

The supplement and probe:
- `pt19c-walk\walk_fixture.json`, made by `pt19c-walk\py\gen.py`;
- `pt19c-walk\probe_tail.php` (scenarios: `carriage`, `cfto`, `ferry`, `trainer`, `rail`, `grade`, `enabled`, `stuck`);
- `pt19c-walk\py\q.py` (an index lookup).

Run a probe scenario:
`MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/pt19c-walk-p.sh --scen=<name>`

5 of the supplement's 16 red lines are my own wrong expectations, not defects:
- the W.corpulus.plain `rail` flag on a line that passes the rail (4 lines);
- W.open.balgruuf "The dragon is dead." opened with `qrows`, not `toplevel` (it opens either way; 1 line).

Two other red rows in the table are not defects either:
- W.trainer: the harness cannot build a root out of rows that are not top-level; the probe covers it;
- W.open.anyone: "Where can I learn more about magic?" to Ysolda is correctly refused, since that MG01 INFO is not hers.
