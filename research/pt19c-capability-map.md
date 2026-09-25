# pt19c - THE CAPABILITY MAP: what the owner can do by voice in MENULESS QUESTING v1.0

2026-09-24, MENULESS USEFULNESS DESIGNER. Read-only for the glue; binding for the lanes where the spec allows (each fix
says BINDING = inside the spec's own latitude or required by a hard rule, or NOD = a small spec amendment the
synthesiser must accept). Where this map and `pt19c-interaction-model.md` touch, the model's F-items rule; this map adds
the U-items below and does not repeat what F16-F19, F29 already resolve.

Sources and marks: **[X]** the live index (`prompt_index.ndjson` v1, 37,561 rows, 5,718 layers, hash e756e311, copied
2026-09-24 15:00); **[M]** measured with the REAL functions of the current tree (`lib/` copied to
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-usefulness\lorerim_glue`, php 8.2 in WSL, `probe.php` modes
`open` / `slot` / `market` / `train`, `cases.json` + `cases2.json`; the rev2 kind phrases added through the real
`lrgDlgPhraseHit` / `lrgDlgHitFollowedBy` loop; python census scripts `inv.py`, `svc.py`, `room.py`, `gate2.py` in the
same folder); **[L]** the live server log / `context_sent_to_llm.log` (Hulda 2026-09-24 02:53-02:55, Matlara after);
**[S]** the spec rev 2; **[P]** PROTOCOL; **[I]** inferred (not measured - the lane confirms).

---

## 0. The verdict in ten lines

1. **Quests.** After the first plain click on this install, every indexed, VISIBLE line of MQ101-MQ106 and the guild
   openings can be said instead of clicked - except lines whose INFO carries the Invisible Continue flag: the glue
   classes them `hidden` (`lrg_dialogue.php:1337`) and never offers or matches them. That is 2,105 distinct prompts on
   1,003 of 5,718 layers **[X, M]**, including Faralda's whole "what do you seek" layer, Irileth's "I was told to give the
   message directly to the jarl", Delphine's "What do you want with me?", Tullius's "I was at Helgen" and the persuade
   SUCCESS lines the spec's own beats `MG01.faralda.persuade` and `TG00.brynjolf.persuade` target (**U2**).
2. **First contact.** When his sentence IS her line, her list opens before the model runs (join / kind / cached root /
   verbatim top-level / her journal-quest rows), measured on 75 sentences **[M]** (section 2). Short verbatim quest lines
   with ONE meaning word ("I have your shield", "What are your orders?") and paraphrases to NPCs outside the quest fall
   back to the post-LLM open (she answers twice) or to words only (**U5**, **U10**).
3. **The kind clause is too wide.** As written, clause 2 opens the VISIBLE menu, pre-LLM, on ANY NPC for "come with me",
   "wait here", "let me go", "do you know who I am", "take me to the jarl", "teach me how to fight" and "any rumors
   about the dragons" **[M]**. "Come with me" to a non-follower is the SHIPPED escort (10.20), which must keep working
   (**U1**).
4. **Services.** Food and drink are fully voice, with no window (10.27). Trade and training open the engine's window, and
   the window itself needs the mouse. A carriage ride takes one sentence: the hire line is picked by kind, the destination
   by its exact name. Rooms are TWO layers on this install (Xtended Stay) and today the days list cannot be picked by
   natural words: "one night" picks nothing, only a literal "1 day" does **[M]** (**U3**).
5. **Checks** (persuade, intimidate, bribe) are voice under the check rails. Arrests are never voice (the lethal rule).
   Rewards are fixed by the game and she says so.
6. **The first-click fences** (the stage rail and read-only journal scenes) apply once per INSTALL, not per evening:
   `clicks_ok` sits in Postgres and `route` in the install ini. The owner page's "of the evening" is wrong, and on the
   Whiterun gate guard's list the rail sentence "ask me something simple first" cannot be true (**U6**, **U7**).
7. **The owner's first evening is mostly right.** The fixes it needs are the room (Xtended Stay), Hulda's follow-up
   question, the missing Whiterun gate, the gate's persuade trap and Riverwood's reason (**U6**, **U9**).

---

## 1. Legend - what happens by voice

| code | meaning (spec rule) |
|---|---|
| NOW | clicks now: plain / back / service / pay < 100 septims and < 25 % of the purse (S4.10 row 1) |
| KIND | a service by kind on a ROOT list ("what have you got" -> the trade line) (S5) |
| SLOT | a price list by exact name, longest wins; a price question quotes, a negation never orders (10.15) |
| EXPL | a commit he said plainly, clicked in one (S4.3) |
| ASK | a commit said loosely: she asks once, quoting the line; "yes" or "I swear" clicks it (S4.4) |
| SGL | a single-entry layer released by his answer (S4.5) |
| AUTO | an unscripted single that carries on after a breath (S4.6) |
| CHK | a persuade / intimidate / bribe line clicked under the check rails; the engine decides the outcome (S4.9) |
| BUY | `ExtCmdLRG_Buy`, food and drink, no window (10.27) |
| ESC | `ExtCmdLRG_Escort` follow / wait / release, no menu (10.20) |
| ENTRY | `ExtCmdLRG_QuestEntry`, the click-free quest entry (10.26, kept as the fallback) |
| RO | read-only journal scene until the first real click on this install: "choose it on the list yourself" (S1.3) |
| RAIL | the stage rail until the first plain click on this install: "ask me something simple first" (S3.3) |
| WORDS | no list; CHIM answers in words; nothing changes in the game |
| HAND | the menu or window is his, with the reason given (lethal/arrest, walk-away leave, the engine's barter/training window) |
| HID | on screen, but the glue classes it `hidden` (Invisible Continue): today it can only be clicked (U2) |

Open paths: `PRE:join|kind|root|top|qrows` (S2.1 clauses 1-5, measured), `POST` (the model's item plus the wide marker:
she answers in words and then her real line plays), `ENG` (a forcegreet, blocking topic or scene opens it), `E` (he
presses E). An ENG session in a journal-quest scene (`sj=1`) is RO until `clicks_ok >= 1`.

---

## 2. How her list gets on screen - first contact, measured [M]

Clause order as S2.1, with the rev2 kind phrases applied. "q" = the quests she is an alias of **[I]**. F16's containment
rule and F19 (no kind or join open at `clicks_ok == 0`) apply on top.

| NPC (q) | he says | clause that fires (score, shared strict words) |
|---|---|---|
| Farengar (MQ103) | "Have you learned anything about the dragons? Do you need any help?" | qrows 1.00 (`MQ103FarengarIntroTopic` is NOT top-level [X]) |
| Farengar | "do you need any help with the dragons" | qrows 0.82 sh=4 |
| Farengar | "the jarl sent me to help you with the dragons" | qrows 0.59 sh=2 |
| Farengar | "Balgruuf said you could use some help" | none (0.47) -> POST |
| Farengar | "I have the stone tablet you wanted" | top (`MQ103FarengarRetrieveBookTopic`) |
| Farengar | "I brought the dragonstone from Bleak Falls Barrow" | qrows 0.65 sh=3 |
| Farengar | "here is your tablet" | none (0.61, sh=1) -> POST |
| Balgruuf (MQ104) | "The dragon is dead." / "we killed the dragon" / "the dragon at the watchtower is dead" | top / qrows 0.85 / qrows 0.78 |
| Balgruuf (MQ102) | "I need to talk to you about Helgen" / "I come with news from Helgen" | top / qrows 0.74 |
| Irileth (MQ104) | "What are your orders?" | none: a verbatim top-level row with ONE strict word (U5); a blocking topic - any open plays it |
| Arngeir (MQ105) | "I have the Horn of Jurgen Windcaller" / "I'm ready for more training" / "I brought the horn back" | top / top / none (0.57, sh=1) |
| Delphine (MQ106) | "I'd like to rent the attic room" / "the attic room please" / "where are we headed" | top / qrows 0.72 / none (1 word, U5) |
| Kodlak (C00) | "I would like to join the Companions" / "I want to join you" | join + top / none (object stop "join YOU") -> POST |
| Aela (C00) | "I have your shield" | none (a verbatim top-level row, 1 strict word) -> POST (U5) |
| Eorlund (C00) | "Vilkas sent me with his sword" / "I was told you would have a weapon for me" | qrows 1.00 / top |
| Faralda | "May I enter the College?" | join (Faralda is the named recruiter; the tier resolves "college"); qrows 1.00 if MG01 is in her q |
| anyone | "Where can I learn more about magic?" / "where can I learn magic" | top (26 NPC variants) / none -> WORDS (U10) |
| Mirabelle (MG01) | "I was told to come see you" / "Faralda sent me to you" | top / none -> POST |
| Brynjolf (TG00) | "I'm ready. Let's get this started." / "I'm ready to start" | top / none (0.57) -> POST |
| a guard | "Grelod is abusing the children at the orphanage. You must do something!" / the same as a paraphrase | top / none, and the guard is in no DB01 alias -> WORDS (U10) |
| Viarmo | "I'm looking to apply to the College" / "I found King Olaf's Verse" / "I have the verse you wanted" | join (recruiter) / top / none -> POST |
| Tullius, Galmar | "I want to join the Legion" / "I want to join the Stormcloaks" | join (the road rule: Helgen first) |
| Hulda | "Nice inn you have here. Do you get many visitors?" | top (`moretosaywhiterun.esp:000940`) |
| Hulda | "What have you got for sale?" / "I need a bed" / "can I rent a room" / "I would like a room for the night" | kind barter / inn / inn / inn |
| Hulda | "I'll have a mead" | no open: the market lane takes it (BUY, `kind=order cls=mead`) |
| Hulda | "Heard any rumors lately?" / "what's the news" | **kind INN** (`any rumors`, `what's the news` are inn phrases) - U4 |
| Corpulus (Winking Skeever) | "Why is this place called the Winking Skeever?" | top (`skyrim.esm:084987`, TL, S0) |
| Sven | "Do you know any old ballads about dragons?" | top |
| Alvor | "Do you have any supplies I could take?" / "Hadvar said you could help me out" | top / top (the latter is S0 - a legal FIRST click) |
| a carriage driver | "Take me to Whiterun" / "I need a ride to Solitude" / "how much to Riften" | kind carriage / kind carriage / none |
| a trainer | "Can you train me in one-handed?" / "I want to learn archery" | kind train |
| anyone | "Follow me" / "wait here" / "it's time for us to part ways" / "come with me" | **kind follower** - U1 |
| anyone | "let me go" / "do you know who I am" / "I didn't do anything" / "I'll go quietly" | **kind crime** - U1 |
| anyone | "take me to the jarl" / "take me to your leader" / "teach me how to fight" / "I want to learn more about you" / "join me for a drink" / "your boat looks nice" | **kind carriage / carriage / train / train / follower / ferry** - U1 |

---

## 3. Main quest MQ101-MQ106

Columns: what he says / how the list opens / what happens by voice at `clicks_ok` 0 -> >= 1 / what still needs the menu
and why. Line classes follow the spec's beat table (3.6) unless a U-item corrects them.

| beat | he says | opens | by voice (0 -> >= 1) | still the menu / cannot, and why |
|---|---|---|---|---|
| MQ101 Helgen (AP start on this save [L]) | anything | ENG scene | WORDS; quiet mode from MQ101 stage 5 (10.28; F17 adds `quiet` -> no open, RO) | the keep door "What's happening?" (TL S G) - a scene beat in quiet mode: HAND |
| MQ102A/B Riverwood, Alvor / Gerdur | "Hadvar said you could help me out" | ENG forcegreet, **sj=0**: MQ102A/B carry no journal objective (all 70 rows `journal=0` [X]) - U9 | NOW at BOTH clicks_ok values (TL, S0, G0 - it passes the rail and is a legal first click) | - |
| same | "Do you have any supplies I could take?" / "can you spare some supplies" | same | RAIL -> NOW (scripted; the shared word "supplies" [S4.8]) | - |
| same | "It was a dragon, Hadvar will tell you the same" (C1 S crit1 W **I**) | same | HID | U2 |
| Riverwood exit, Hadvar / Ralof | "Sounds good. Let's go." | ENG | RAIL -> ASK/EXPL (S G) | - |
| **Whiterun gate guard** (NOT in 3.6 - U6) | "Riverwood calls for the jarl's aid." | ENG forcegreet, sj=0 (`DialogueWhiterunGuardGateStop`, no journal [X]); bounty 0 -> crit 0 | RAIL (S G) -> EXPL (verbatim) / ASK (loose) | nothing on his list passes the rail: at 0 the sentence "ask me something simple first" is false - U7 |
| same | "I have news from Helgen about the dragon attack" | same | RAIL -> **CHK** (at the gate this is the PERSUADE line, `0D1981`: a Speech check that can FAIL; retries are suppressed) | the page must say so (section 8) |
| same | "will this change your mind" (bribe) / "stand aside, or else" (intimidate) | same | RAIL -> CHK (the bribe price is live from `bamt`) | with a bounty > 0: crit 2 -> HAND (lethal) |
| MQ102 Irileth at the Dragonsreach door | "I have news from Helgen about the dragon attack" | ENG, journal scene sj=1 (`sq=MQ102 sqj=1`) | RO -> EXPL | - |
| same, next layer [B1 "A dragon has destroyed Helgen" S G, B2 "I was told to give the message directly to the jarl" S **I**] | "I was told to give the message directly to the jarl" | same | HID; worse, B2 hidden makes the layer look SINGLE, so a bare "yes" or "okay" releases B1 (a commit single) | U2 |
| MQ102 Balgruuf | "I need to talk to you about Helgen" | ENG court scene, sj=1 (PRE:top when free) | RO -> NOW | - |
| same, "So you were at Helgen?" [B1/B2 S G, A1 S0] | "The dragon destroyed Helgen and last I saw it was heading this way" / "yes, I had a great view" | same | RO -> EXPL / NOW | - |
| MQ102 reward single "What else can I help you with?" (G O commit single) | "yes" / "what else can I help with" / "is there anything else I can do" | same | RO -> SGL / SGL / ASK (0.721, precision 0.5) | - |
| MQ103 Balgruuf blocking "I need to talk to you." | anything that opens | ENG blocking (plays on the open) | RO -> NOW | - |
| MQ103 Farengar intro | "do you need any help with the dragons" | PRE:qrows 0.82 | NOW (a scripted-0 intro) | his skill-flavour answers (Alchemy/Enchanting/Magic, **I**) HID - U2 |
| MQ103 assignment single "So what do you need me to do?" | "what do you need done" / "alright, what's the task" / "tell me what you need done" | same | SGL / SGL / the model's T-key; "uh what now" does nothing | - |
| MQ103 turn-in | "I have the stone tablet you wanted" | PRE:top | NOW (scripted, shared word) | "here is your tablet" -> POST (she answers twice) |
| same, "Oh, do you mean this old stone?" (S single) | "yes, I have the stone" | same | SGL | - |
| MQ104 Irileth blocking "What are your orders?" | anything that opens her | ENG / POST (U5) | NOW (the blocking topic plays) | - |
| same [A1 "I'll come along with you" S, A2 "I'd rather scout ahead" S G] | "I'll come with you" / "count me in, I'm coming" | same | EXPL / ASK | - |
| MQ104 guards at the tower "Dragonborn? What do you mean?" | the line | ENG scene sj=1 | RO -> NOW | - |
| MQ104 Balgruuf outro | "The dragon is dead." | PRE:top | NOW | - |
| same, A1-A3 / B1 / "The Greybeards?" / D1 / "What about my reward?" | his words | same | NOW / EXPL / AUTO / SGL or ASK / EXPL | a DIFFERENT reward: never; the reward line rides only when he bargains (S6.1) |
| MQ105 Arngeir summons | "I am answering your summons" | ENG courtyard, sj=1 | RO -> NOW; "I'm ready to learn" EXPL | - |
| MQ105 horn | "I'm ready for more training" / "Thank you, what's next" | PRE:top or ENG | NOW / SGL | HornA1/A3 (**I**) HID |
| MQ105 return | "I have the Horn of Jurgen Windcaller" | PRE:top | EXPL (S G) | "I brought the horn back" -> POST |
| MQ106 Delphine room | "I'd like to rent the attic room" | PRE:top | pay (the live RoomCost, 25 septims [P 10.27]) - EXPL | "I'd like a room" rents a NORMAL room (Xtended's line scores higher [M]); the quest needs "the attic room" |
| MQ106 secret room (sj=1) | hub questions ("Why did you take the horn from Ustengrav?") | ENG | RO -> NOW (the hub rule, S4.1) | "What do you want with me?", "You'd better have a good reason...", "Go on. I'm listening." (**I**) HID - U2 |
| same | "I just came here for the horn" / "I don't have time for this" | same | EXPL / ASK (a walk-out; F20) | LEAVING a walk-away layer: HAND (leave guard, S4.2) |
| MQ106 Kynesgrove | "I know that mound, east of Kynesgrove" / "Let's go kill a dragon" / "Where are we headed?" | PRE / ENG | NOW / NOW / POST (U5) | - |

## 4. Guild openings

| beat | he says | opens | by voice (0 -> >= 1) | still the menu / cannot, and why |
|---|---|---|---|---|
| C00 Kodlak | "I would like to join the Companions" | PRE:join + top | RAIL (scripted) -> EXPL, one sentence | "I want to join you" -> POST |
| C00 Kodlak's test layer | "I can take care of myself" / "don't worry about me" | same | EXPL / ASK | - |
| C00 Vilkas's yard | - | a sparring fight | HAND (combat) | "So you're supposed to train me?" -> NOW |
| C00 Eorlund | "Vilkas sent me with his sword" | PRE:qrows 1.00 | NOW (crit1 W is no longer a commit) | leaving his walk-away layer: HAND |
| C00 Aela | "I have your shield" | POST (U5) | EXPL | her answers "I don't care for boasting." / "I would kill him before he drew his sword." (**I**) HID |
| C00 Eorlund's weapon | "I was told you'd have a weapon for me" -> "a sword, please" | PRE:top | NOW -> EXPL (a slot at 2 tokens); a lone STT "sword" -> ASK | - |
| MG01 any NPC | "Where can I learn more about magic?" | PRE:top | RAIL (S O) -> NOW | a paraphrase: WORDS (U10) |
| MG01 Faralda | "May I enter the College?" | PRE:join (at 0: F19, no open) | NOW | **her 8 answers to "what do you expect to find within?" are all S I: the whole layer is HID - he must click (U2)** |
| MG01 Faralda's entry | "I'll take your test, then" / "I think we both know I'll succeed here" (persuade) | same | EXPL / CHK | the persuade SUCCESS variant "I'm the best mage you'll ever see..." (`0B810C`, **I**) HID; `0B811B` "I'll take your test" (**I**) HID; casting the spell is gameplay |
| MG01 Nirya | "Okay, this is for the spell" | ENG / E | pay 30 septims - NOW | - |
| MG01 Mirabelle | "I was told to come see you" -> "I'd love to have a look around" | PRE:top | NOW -> EXPL | "Faralda sent me" -> POST |
| MG01 Tolfdir's class | "You want my opinion?" | ENG scene sj=1 | RO -> NOW | the ward answers (**I**) HID |
| TG00 Brynjolf's pitch | "I'm sorry, what?" -> "What do you have in mind?" -> "What do I have to do?" | ENG forcegreet (sj: U8) | RO -> NOW, then AUTO on the singles | the persuade success "Won't have a point earning all that gold..." (`097FCC`, **I**) HID; the failure variant is CHK |
| TG00 refusal / job start | "Break the law? Are you kidding?" / "I'm ready, let's get this started" | ENG / PRE:top | ASK / EXPL | "I want to join the Thieves Guild" -> the join marker opens a list with no join line: nothing is picked (his row has none) |
| DB01 Aventus | "Are you all right?" -> "Contract?" | ENG scene sj=1 | RO -> NOW -> SGL | - |
| DB01 guard (ILQE) | "Grelod is abusing the children at the orphanage..." (verbatim) | PRE:top | NOW (bounty 0 -> crit 0) -> persuade/bribe CHK | a paraphrase: WORDS (U10) |
| DB01 Grelod | "The Dark Brotherhood has come, Grelod" | E / ENG | EXPL | - |
| DB02 captives | "Who are you?" / "Answer me, or die!" | ENG scene sj=1 | RO -> NOW / CHK (tag-only, always succeeds) | Astrid's "(Remain silent)" layers are not under DB02 in the index: `silent` class - HAND |
| MS05 Viarmo | "I'm looking to apply to the College" -> "And that's where I come in?" -> "What do you need me to do?" | PRE:join | NOW -> SGL -> SGL | - |
| MS05 verse | "I found King Olaf's Verse" -> the verse choices -> "Is that it?" | PRE:top | NOW -> CHK / EXPL -> SGL | - |
| MS05 court / induction | "Are we ready?" / "Does that mean I'm a bard now?" | ENG sj=1 / E | RO -> EXPL / EXPL | - |
| CW00A Tullius before Helgen | "I want to join the Legion" | none (road closed) | WORDS: the Helgen line | - |
| CW00A Tullius after Helgen | "I want to join the Legion" | PRE:join, amb=1 | EXPL (the AP line is the exact faction line); if the open is refused -> ENTRY | his greet answers "I was at Helgen." / "I helped Hadvar escape..." (**I**) HID |
| CW00A Rikke | "About that test..." -> "Consider that fort already yours." | PRE:top / ENG | NOW -> EXPL | "What kind of test?" (**I**) HID |
| CW01A oath | "I'm ready to take the oath" -> "yes" -> oath 1-3 -> oath 4 | PRE:top | NOW -> NOW (own confirmation) -> AUTO x3 -> SGL | - |
| CW00B/CW01B Galmar | "About that test" -> "That's why I'm here. I want to join." -> oath | ENG | NOW -> EXPL -> AUTO x4 -> SGL (commit by override) | Ulfric's greet answers (**I**) HID |

## 5. Everyday services

| service | he says | what happens by voice | still the menu / cannot, and why |
|---|---|---|---|
| **Food and drink** | "I'll have a mead", "uh some beer", "two ales please", "give me some bread" | BUY [M]: `kind=order` with the class and count; two meads -> she names both with prices and asks; "can I buy you a drink" is a gesture; "how much is the ale" is a quote. No list and no window. The price is the one the game asks (10.27) | out of stock -> the 10.15 barter window after her line |
| **Rooms** (every innkeeper runs Xtended Stay [X, L]) | "I'd like a room", "I need a bed", "can I rent a room" | PRE:kind (inn) -> her root line "I'd like to rent a room." (`xtended stay.esp:000D84`, S1, no price; the live list showed no price [L]) -> KIND or similarity (0.87) -> "Sure thing." -> **the DAYS list** "1 day. (25 gold)" ... "7 days" | the days list: SLOT picks only a literal "1 day"; "one night", "a room for the night" and "for a day" pick nothing, and "2 days" asks because six slots share the name `# days` - **HAND today (U3)**. RAIL at 0 |
| **Trade** | "what have you got for sale", "show me your wares", "what have you got" | PRE:kind (at 0: F19 -> the 10.15 barter window through CHIM) -> KIND on the trade line -> the Barter window opens | buying and selling items INSIDE the window needs the mouse (engine UI; no SWF/C++ tooling allowed). An innkeeper whose line reads "What's on the menu?" (`0DEE85`) has no barter-kind entry: the KIND pick misses [M] |
| **Training** | "train me in alchemy", "can you teach me archery" | similarity 0.57-0.74 on the trainer's line, or KIND where the line carries a train phrase ("Can you train me to Block?") -> the Training window | the window needs the mouse; the fee is Requiem's and never quoted. Entries "I'd like training in X" / "I need training in Y" have NO kind [M] (U12) |
| **Carriages** (CFTO) | "take me to Whiterun", "I need a ride to Solitude" | ONE sentence: PRE:kind -> "I'd like to hire your carriage." (KIND) -> the destination list -> SLOT from the same sentence (exact name, longest wins: Solitude vs Solitude Lighthouse) -> pay < 100 septims -> the ride | a destination not on her list: she says so (`refuse_fallback_offlist`); "how much to Riften" quotes and executes nothing |
| **Ferries** | "I'd like to hire your boat", "take me across" | the same shape (CFTO) | as carriages |
| **Followers** (SFF) | "follow me", "wait here", "let's trade", "it's time for us to part ways" | her own follower lines by the verb table: NOW; dismiss / home -> ASK; trade opens the gift/inventory window | the window needs the mouse. First contact is POST today (U1 makes it pre-LLM for a real follower only) |
| **Non-followers** | "come with me", "wait here", "you can go" | ESC (10.20) - must NOT open a menu (U1) | a stoppable scene is left; an unstoppable one: she says why |
| **Hiring** | a mercenary's "(500 gold)" line | ASK (>= 100 septims always asks) | - |
| **Crime / bounty** | "I want to pay my bounty" to a guard | CHIM's `PayBounty` (it checks the gold; 10.15) - no menu. With U1, crime words never open a menu | a guard's arrest list (crime gold > 0): HAND ("choose that yourself", corner note), never by voice; resisting arrest: never |
| **Persuade / intimidate / bribe** | any on-screen check line in >= 4 words | CHK: bribe affordability from the live price; a named amount below the price refused; retry suppression; scoff-first; the outcome is told the next turn as fact | success variants flagged Invisible Continue: HID (U2). Free conversation with no line: `lrgDlgCheck` (Speech threshold) plus `do=award` (Phase 2) |
| **Leaving** | "never mind", "goodbye", "that's all" | the back line, or the engine cancel: NOW | a walk-away layer with no back line: HAND ("leaving is yours to do, and it ends things with me") |

---

## 6. (a) The top usefulness gaps, ranked by how often he hits them

Each gap gives: frequency, then the smallest spec-consistent fix, the owning lane, and the test that proves it.

**U1 - the kind clause opens the menu on non-business sentences and breaks the shipped escort.** *Every evening.*
Measured [M]: "come with me", "follow me", "wait here", "stay here", "travel with me", "join me for a drink" and "let's
trade stories" are kind `follower`; "let me go", "do you know who I am", "I didn't do anything" and "I'll go quietly" are
kind `crime`; "take me to the jarl" and "take me to your leader" are kind `carriage`; "teach me how to fight" and "I want
to learn more about you" are kind `train`; "your boat looks nice" is kind `ferry`; "what's the news" and "any rumors
about the dragons" are kind `inn`. S2.1 clause 2 admits any kind, so each of these queues a visible `do=open` on ANY
NPC before the model runs. For "come with me" the same turn also carries the shipped `ExtCmdLRG_Escort do=follow`, so
the menu takes the camera and the movement while the escort tries to walk her. The owner used exactly these phrases in
playtest 13 [P 10.20].
Fix, **BINDING** (the task's hard rule: escort and SFF hand-off must keep working):
- **Lane A**, in `lrgDlgBusinessMarker(..., narrow: true)` clause 2:
  - `inn` and `barter` open only when `lrgDlgVendorHint($snap) !== 'none'`. This is 10.15's own test, so a missing
    snapshot still opens.
  - `follower` opens only when `lrgEscortFacts($npc)['fol']` names her a follower (SFF or party).
  - `carriage`, `ferry` and `train` open only when her `fac=` carries a job faction of that kind. **Lane B** adds
    `dialogue.open.kind_factions` after checking the names on a live snapshot of a driver, a ferryman and a trainer.
    Until the names are verified, those kinds do not open pre-LLM and the post-LLM path runs as today.
  - `crime` never opens.
- **Lane B:** delete `what's the news`, `any rumours` and `any rumors` from `inn.phrases`. They are rumour questions,
  not a room. Under `ml=0` they even tell her to choose `Rent_Room` (10.15 request block).
- **Tests.** `test_dialogue` 25 adds, with rows as [M]:
  - "come with me" to Lisette (no `fol`) -> no open, and the escort command is still queued;
  - "let me go" / "do you know who I am" to a guard -> no open;
  - "take me to the jarl" to a guard -> no open;
  - "take me to Whiterun" to a driver with the verified faction -> `open marker=kind`;
  - "what have you got?" to a guard whose snapshot lists no vendor faction -> no open.

  Lane E's `d66` adds the escort row.

**U2 - Invisible Continue lines are hidden.** *Every quest-heavy evening. 17.5 % of all layers.*
`lrgDlgClass` returns `hidden` for `$e['invis']` (`lrg_dialogue.php:1337`). The flag belongs to the NPC's RESPONSE (the
conversation continues without a new choice) and does not hide the player's prompt. Prior research says as much
(`research/p2-quest-branching-speech.md:102,218,227`, "Let me see if I can bring him back..." is a visible Invisible
Continue choice). Census [X, M]: 3,559 rows with real prompt text carry the flag; 2,105 distinct prompts carry it on
EVERY row, so they are hidden whenever they are matched; 1,003 of 5,718 layers hold at least one; and on 327 layers the
hiding leaves exactly ONE visible entry, so S4.5/S4.6 treat a two-choice layer as a single. At Irileth's
`0D39AB [A dragon has destroyed Helgen | I was told to give the message directly to the jarl]` a bare "okay" releases
the commit B1. In the fixture quests: MG01 15, MQ106 10, CW00A 10, MQ102B 9, CW00B 9, MQ103 8, MQ102A 4, C00 3, MQ105 2,
TG00 2, MQ102 2. Two of the spec's own beats target such rows: `MG01FaraldaEntryPersuade` `0B810C` and
`TG00BrynjolfIntroMQ203C1` `097FCC`. Lane E's harness cannot go green on them as the code stands.
Fix, **NOD** (one line plus one grading rule), **Lane A**:
- `hidden` only for `placeholder` and empty text. `(invisible continue)` dev placeholders are already caught by
  `placeholder` [X: 5 rows].
- An `invis` entry is graded `scripted = 1` for S3.3, S4.1, S4.5, S4.6 and S4.8, because the invisible continuation may
  carry the fragment. So it never auto-advances, needs a shared word, and counts as a scripted sibling.

Tests:
- `test_dialogue` 16: an `invis=1` fixture row is offered and picked. Irileth's `0D39AB` layer is two entries, not a
  single ("okay" releases nothing).
- Lane E: `MG01.faralda.seek` ("I seek the knowledge of the Elder Scrolls" -> EXPL; "I want to learn destruction magic"
  -> ASK).

**U3 - rooms are two layers on this install, and the days list cannot be said.** *Every night at an inn.*
Xtended Stay owns the root line: "I'd like to rent a room." with no price [L], which leads to "1 day. (<RoomCost> gold)"
... "7 days" (`xtended stay.esp:000D84` links, 6 lines [X]). `lrgPromptNorm` turns every digit into `#`, so the slots
become `# day` and six copies of `# days` [M]:

| he says | result [M] |
|---|---|
| "1 day" | `pick` |
| "2 days" | `ask` (six identical slot names) |
| "one day", "just one night", "a room for the night", "for one night", "a day", "two days" | `none` - nothing |

On this install he must click every night. Spec 3.7 `FE.hulda.room` targets the vanilla priced `RentRoomTopic`, a line
that is not on her list [L].
Fix, **NOD** (~15 lines), **Lane A**, `lrgDlgServiceSlot`:
- build the slot tokens from the LIVE text, keeping its digits (strip only the price tag);
- in the utterance, number words one..ten -> digits, using the table `lrg_intent.php:275` / `LRG_MKT_NUMWORDS` already
  holds;
- ONLY on a layer whose every slot is `^\d+ days?$`: `night(s)` counts as `day(s)`, and `a/the/one night`, `tonight`
  and `for the night` count as `1 day`.

The negation and price-question guards stay. The stored sentence rides into the days list the way a carriage sentence
does, so "I'd like a room for the night" becomes ONE sentence and two clicks: `mode=kind`, then `slot=1 day`, at
25 septims (< 100 septims and < 25 % of a purse over 100: it clicks; below that she asks, F29).
Tests:
- `test_dialogue` 29: on the real Xtended texts with "(25 gold)" live, "one night" -> 1 day, "two nights" -> 2 days,
  "a room for the night" -> 1 day, "not tonight" -> nothing, "how much for a night" -> price, "for a week" -> ask.
- Lane E: `FE.hulda.room` rebuilt as `FE.hulda.room.start` (`000D84`, KIND or similarity) plus `FE.hulda.room.days`
  (the links of `000D84`).
- Lane F: step 4 of the owner page (section 7).

**U4 - the kind pick must never beat a real match.** *Every inn visit where he asks for rumours.*
On Hulda's live root [L] the entry "Heard any rumors lately?" is kind `inn` but class `plain`. The ROOM line is the only
class `service` entry of kind `inn` [M]. Under F16, "any rumors" (2 tokens) loses its containment hit, so the S5 kind
pick would click "I'd like to rent a room." S5 states no order.
Fix, **BINDING** (a clarification; S5's own rationale is "they fail 0.55"), **Lane A**: the kind pick runs ONLY when
the similarity path chose no entry. It is always behind the U1 phrase removal.
Test 29: Hulda's root plus "any rumors?" or "heard any rumors?" -> the rumours line or nothing, never `RentRoom`;
"can I get a room" (0.567, margin 0.07) -> KIND `RentRoom`.

**U5 - short verbatim quest lines never open before the model.** *Most quest turn-ins.*
"I have your shield" (Aela), "What are your orders?" (Irileth) and "Where are we headed?" (Delphine) are verbatim rows of
her journal quest, but they carry ONE strict meaning word. Clause 4 wants 2 and clause 5 wants 2 shared, so all fall to
POST: she answers in words, and then her real line plays.
Fix, **NOD**, **Lane A**: clause 5 also admits a hit of tier exact or contain (score >= 0.85, the F16 token rule) with
>= 1 shared strict word. It is q-scoped, so "who are you" to anyone outside DB02 still opens nothing.
Test 25: Aela (q C00) "I have your shield" -> `marker=qrows`; Irileth (MQ104) "what are your orders" -> `qrows`; Hulda
"who are you" -> none.

**U6 - the owner page and the first-evening harness do not match this install.** *Once, but it is his first evening.*
1. Step 4 is the Xtended room (U3).
2. Step 2: after "Nice inn...", Hulda answers "Thanks!" and a SECOND list appears: "I'm a mage", "I'm a refugee. Helgen
   was destroyed by a dragon", "I'm just a traveler enjoying my visit to Whiterun" and more (`000940` links [X]). The
   page must tell him to answer it or press Tab.
3. Step 8's reason is false. Alvor and Gerdur are not journal scenes (U9). Their supplies line waits because it is
   scripted, and "Hadvar said you could help me out" is itself a legal first click.
4. The Whiterun gate guard is missing from 3.6 and 5.3, and his natural "I have news from Helgen about the dragon attack"
   is the gate's PERSUADE line [X].
5. "Of the evening" in 5.2 and 5.4: both fences are per install (section 0 line 6).

Fix, **BINDING**:
- Lane F: the page wording (section 7).
- Lane E: new beats `FE.hulda.plain.followup`, `MQ102.gate.note` (EXPL "Riverwood calls for the jarl's aid"; ASK
  "Riverwood needs the jarl's help"; 0 -> nothing, with the U7 sentence), and `MQ102.gate.persuade` (CHK; "fifty
  septims" on the bribe -> words).

**U7 - the stage-rail sentence is false where nothing can be picked.** *Once per install. Never false.*
"ask me something simple first, a question, then I can pick this one" cannot come true on a list where NO key passes
the rail. The Whiterun gate guard's lines are all scripted, checks or goodbye; asking him a question makes CHIM answer
in words and unlocks nothing.
Fix, **NOD** (a text), **Lane A**: when no offered key passes the rail, the one line reads `I cannot pick any of these
for you yet - choose this one yourself, this once`. Lane B's test 35 scans it.

**U8 - `lrgDlgQuestKnown` is not what S1.3 says it is.**
The code reads CHIM's `questlog` table: the player's own journal, in Postgres (`lrg_speech.php:582`,
`lrg_dialogue.php:2144`). S1.3 says it means "the index has journal rows for the scene's owning quest". With the
shipped function, Brynjolf's TG00 approach (no journal entry yet) is `sj=0` server-side, so it is driven at
`clicks_ok == 0`. And whenever Postgres is down, every `sqj=0` quest scene counts as ambient.
Fix, **BINDING** (the spec's stated intent), **Lane A**: the `sj`/ambient test uses the INDEX journal flag (a quest
with >= 1 `journal=1` row; one hash lookup, as S1.3 budgets). `lrgDlgQuestKnown` keeps its no-spoiler job unchanged.
Test 16: `sq=TG00 sqj=0` with an empty questlog -> `sj=1`.

**U9 - MQ102A/MQ102B are not journal scenes.**
All 70 of their rows are `journal=0` [X]. By S1.1's own test (`EscortHasJournal(owning quest)`), a scene they own is
`sj=0`, so Alvor's and Gerdur's help is a free-standing E session, held only by the rail.
Fix, **BINDING**, **Lane E**: derive each beat's `sc` from its owning quest's journal flag, not by hand;
`MQ102A.alvor.help` becomes pick with the rail at 0. Lane E confirms the scene's owner with `esp_dump.py` **[I]**.

**U10 - a paraphrase to an NPC outside the quest opens nothing.** *Occasional.*
"where can I learn magic", "Faralda sent me to you" and the ILQE guard report in his own words find no clause and no
wide marker, so they get words only and the quest does not move [M]. The spec rightly refuses a whole-index similarity.
Fix, **BINDING**, **Lane F** (wording only): the page says "use her line, or press E and say it".

**U11 - Alternate Perspective's start and skip lines are unguarded.** *Next new game.*
- "Give me your best room. (<RoomCost> gold) (Start Intro)" reads cost 0: `lrgPromptCost` needs the price bracket LAST.
  So it is class `service` and can be clicked by similarity with no price guard.
- "Skip to Helgen Keep" and "Skip to "The Way of the Voice"" (S G, `MessengerAlduinSkipImp` / `MessengerMQSkipImp`
  [X]) skip the intro or the main quest on an explicit sentence.

Fix, **BINDING**, **Lane B**: the new overrides file adds `never_auto` for `APStartIntroDiaTopic`,
`MessengerAlduinSkipImp` and `MessengerMQSkipImp`, so she always asks.

**U12 - trainer lines without a kind.** *Low.*
Fix, **BINDING**, **Lane B** config: `train.phrases += training in, train in`, so "I'd like training in Alchemy" and
"I need training in Smithing" become train-kind entries and the S5 kind pick finds them. After U1, a player's "I'm
training in the arena" opens nothing pre-LLM.

By design, not gaps: the engine's barter, training and gift windows need the mouse. Arrest lists are lethal. Leaving a
walk-away layer is done by hand. Price lists take exact names only. Helgen is a scene and runs in quiet mode.

---

## 7. (b) The first evening, beat by beat

For every step: what he says, what he must see and hear, and the log line that proves it. Before the first real click on
this install (`clicks_ok == 0`), only a plain, unscripted, indexed line can be picked, so each path starts with one.
The save as it stands [L] is a new Alternate Perspective game in Helgen (Matlara, the Resting Pilgrim; "Escape Helgen"
open).

**Path 0 - AP's Helgen town, before the attack (if he loads that save).**
- Vilod: "I've heard you are brewing a special kind of mead?" (TL S0 [X]). She says a word, his list comes up (3-8 s),
  the line is picked, and his real answer plays.
  Log: `open marker=toplevel`, `clicked pos=`, `result ok=1 clicks_ok=1`, `CALIB set route src=live`.
- Once the dragon comes (MQ101 stage 5): `QUIET on: MQ101 stage <n>`. Nothing opens (F17); CHIM talks.
  After Helgen: `QUIET off`.

**Path A - Solitude, the Winking Skeever.**
1. Press E on Corpulus Vinius, look at his list, press Tab. Log: `cal k=... fam:1 st:1`. His list is kept for half an
   hour.
2. "Why is this place called the Winking Skeever?" -> his list appears and the line is picked -> "Well, as it turns out,
   I had a pet skeever when I was a boy and he used to wink." A two-line list follows ("You kept a skeever as a pet?"):
   answer it or press Tab.
   Log: `open marker=root` (or `marker=toplevel` cold), `clicked pos=`, `result ok=1 clicks_ok=1`.
3. "What have you got for sale?" -> the trade line is picked and the barter window opens (buying inside it is the
   mouse). Log: `open marker=kind`, `clicked ... mode=kind`.
4. "A mead, please." -> no list. He names the meads and their prices in septims and asks which; "the Nord mead" -> the
   bottle arrives and the septims go. Log: `buy plan`, `buy net`, `buy result OK`.
5. "I'd like a room for the night." -> the room line is picked, the days list appears, then:
   - after U3: "1 day" is picked, 25 septims;
   - before U3: say "one day" and nothing happens - click "1 day" yourself.

   Log: `mode=kind`, then `slot=1 day cost=25`.
6. "Heard any rumors?" -> his real rumour line, never the room (U1, U4).

**Path B - Whiterun, the Bannered Mare.** As in spec 5.3 (1)-(4), with three changes:
- After step 2's "Nice inn you have here. Do you get many visitors?", Hulda says "Thanks!" and asks who he is. Say "I'm
  just a traveler" (or "I'm a refugee, Helgen was destroyed by a dragon") or press Tab.
- Step 4 is Path A step 5.
- Step 2b (Hulda while the bard sings) is unchanged.

**Path C - the main quest after Helgen** (AP decides where MQ102 begins; skip what does not apply).
1. **Riverwood, Alvor or Gerdur** (forcegreet; journal-free, so driven): "Hadvar said you could help me out" is picked
   even as the install's first click. Then "Do you have any supplies I could take?" is picked (once the first click is
   in). Log: `arming ... sq=MQ102A sqj=0 sj=0 drv=1`, `clicked pos=`.
2. **Whiterun gate** (the guard stops him): "Riverwood calls for the jarl's aid." -> the guard's "You'd better go on
   in. You'll find the jarl in Dragonsreach." He must NOT use "I have news from Helgen about the dragon attack" here:
   at the gate that is the persuade line, and it can fail.
   Log: `clicked pos= mode=explicit origin=engine sj=0`.
3. **Irileth at the Dragonsreach door** (journal scene): when her list appears, "I have news from Helgen about the
   dragon attack". Log: `clicked pos= origin=engine sj=1 mode=explicit`, arming `sq=MQ102 sqj=1`.
4. **Balgruuf**: "The dragon destroyed Helgen, and last I saw it was heading this way." -> "By Ysmir, Irileth was
   right!" He talks, gives the armour, and his single line waits: say "yes" or "what else can I help with" (a bare
   "okay" also works). Log: `clicked ... mode=explicit`, then `mode=single`.
5. **Farengar**: "Do you need any help with the dragons?" -> she says a word, his list comes up, the question is picked
   and his long answer plays. When one line is left, "So what do you need me to do?", say "what do you need done?" or
   "alright, what's the task?". "Uh, what now" does nothing.
   Log: `open marker=qrows`, `clicked`, `mode=single`.
6. **Later, the turn-in**: "I have the stone tablet you wanted" -> "yes, I have the stone".
   Log: `open marker=toplevel`, `mode=single`.

If step 2 of Path A/B (or C1) does not pick, stop there and send the log: everything after it depends on that first click.

---

## 8. (c) Owner-facing wording checks (septims, plain, in her mouth)

| where | as written | problem | say instead | lane |
|---|---|---|---|---|
| 5.2 bullets 2 and 6, 5.4 | "after your first simple question of the evening" / "On your first conversation of the evening" | false after the first evening: `clicks_ok` and `route` persist per install | "the first time on this install (and again after 'Forget everything it learned')" | F |
| 5.3 (4) | "I'd like a room" - the room and the price, no question asked under 100 septims; `do=pick cost=10` | Xtended Stay: a days list follows; the room is 25 septims; F29 | "I'd like a room for the night" - the room, then the days list, one night picked, 25 septims (no question unless it is 100 septims or a quarter of your purse) | F |
| 5.3 (2) | "the line is picked in front of you" | silent about her follow-up list | "...then she asks who you are - answer, or press Tab" | F |
| 5.3 (8) | "Alvor and Gerdur are inside the main quest's scene and their lists stay yours" | false by S1.1's own test (U9) | "their supplies line waits for your first simple question - 'Hadvar said you could help me out' is one" | F |
| 5.2 Irileth / 5.3 | (the gate is missing) | the gate's persuade trap | "At the Whiterun gate say 'Riverwood calls for the jarl's aid'. 'I have news from Helgen...' is a persuasion there and can fail" | F |
| 5.1 | "There is no key to remember" | 5.2 and 5.5 name the talk key (Left Ctrl) | "There is no new key to remember" | F |
| 5.5 | "set it to 0 and she will simply carry on after the breath" | plain and correct | keep | - |
| 6.3 | "a farmhand 40, a merchant 100, a jarl's court 500" | the currency is unnamed | "40 septims, 100 septims, 500 septims" | F |
| S7 "not enough gold" | voiced in her mouth | the currency is septims | "not enough septims" | B (`lrgVoicedWhy`) |
| S7 "that did not take - choose it on the menu" / "I did not catch what we could talk about - choose it on the menu yourself" | machinery word in her mouth; S3.3 bans "menu" for the rail line only | the same rule for every S7 line | "that did not take - choose it yourself" / "I did not catch what we could talk about - choose it yourself" | B (test 35 scans ALL S7 strings) |
| S1.3 read-only clause | "choose it on the list yourself - I cannot pick for you here" | "list" is machinery in character (the model says it nearly verbatim) | "that one you must choose yourself - I cannot answer it for you here" | A (text) + B (test 35) |
| S3.3 rail line | "ask me something simple first, a question, then I can pick this one" | false on a list with no pickable line (U7) | keep, plus the U7 variant "I cannot pick any of these for you yet - choose this one yourself, this once" | A + B |
| 5.2 trade bullet | "before it she asks for one" | F19 changed it | "before it the shop opens through her own trade window" (F19) | F |
| every owner string | "gold" | only the game's own menu text ("(25 gold)") may say gold | septims everywhere else; the log keeps `cost=` | F |

---

## 9. Evidence, and what the lanes still confirm

- **[M] probe:** `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-usefulness\probe.php`
  - `open cases.json` - section 2;
  - `open cases2.json` - U1;
  - `slot` - U3 and U4 (Hulda's live root);
  - `market` - BUY;
  - `train` - U12.

  Run: `wsl -d DwemerAI4Skyrim3 -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/pt19c-usefulness/run.sh <mode> [cases]`.
- **[X] census:**
  - `inv.py` - U2: 3,564 / 3,559 / 2,105 / 1,003 / 327;
  - `svc.py` - the service topics;
  - `room.py` - Xtended Stay;
  - `gate2.py` - the Whiterun and Riften gates and AP's Helgen.
- **[L]:** Hulda's real root, `context_sent_to_llm.log` line ~32100 (T1 "I'd like to rent a room." with no price; T2
  "What have you got for sale?"; "Heard any rumors lately?"; "(+2 more: nice inn, need any)").
- **[I] for the lanes to confirm:**
  - the quest aliases assumed in section 2's q column (Lane A logs `q=` on each first contact);
  - the owner of Alvor's scene (Lane E, `esp_dump.py`);
  - Corpulus's trade line text;
  - the job-faction names of U1 (Lane B, from a live snapshot);
  - whether AP starts MQ102 in Riverwood.
