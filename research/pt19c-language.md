# pt19c - THE QUEST LANGUAGE PARAMETERS (menuless questing v1.0)

2026-09-24, the quest-language specialist. Every number below comes from the REAL functions of the CURRENT glue tree
(`glue/server/lorerim_glue/lib`, script 512 era: quiet mode and buying shipped), copied to
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-language\` and run under php 8.2 in WSL against the live prompt
index (`/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson`, 37,561 rows, 5,718 layers, hash
`e756e311aefaeab54a1184d609adb77b`): `lrgDlgMatchText`, `lrgPromptNorm`, `lrgPromptWords`, `lrgDlgIsBackOut`,
`lrgDlgPhraseHit` / `lrgDlgHitFollowedBy` / `lrgDlgServiceKind`, `lrgDlgServiceSlot`, `lrgDlgNegatedAt` /
`lrgDlgClauseStarts`, `lrgFacAsk`, `lrgIntentAmount` / `lrgDlgNamedAmount`, `lrgDlgCheckKind`, `lrgDlgStakes`. The
spec's revision-2 rules (S4.3 explicit, S4.4 assent, S4.5 single-entry steps 0-6, S4.8 shared word, the STRICT list) are
written on top exactly as `research/pt19-menuless-v1-spec.md` states them (the `rev2_verify.php` logic, re-checked).
Nothing in the glue was edited. Layers are the engine's real sibling sets: the parent row's `links` resolved to rows,
or the index layer line; a top-level (root) target is measured against the other top-level rows named in its beat
(so a root margin is an upper bound until the fixture carries her real root - 3.3's rule).

Measured: **833 lines** over 79 beats (the spec's 3.6/3.7 rows + its `never` lines + 5 natural and 2 STT-damaged
paraphrases per advancing line, 553 of them new), **126 probes** on 42 extra index lines sampled from MQ102-MQ106 and
the guild openings (C00, MG01, TG00, DB01, DB02, MS05, CW00A, CW00B, CW01B) on their real layers, 132 assent/refusal
probes, 29 shape probes, 56 negotiation probes (44 bargaining sentences, 12 number phrases), 25 service-kind probes (owner's own log lines), 30 enlistment probes.
Every line with its score, margin, token count and path is in `research/pt19c-language-paraphrases.json`
(beat id -> [{say, expect, note}]).

**Reading guide.** Lane A (gate, two-step, matcher): sections 0, 1, 2, 3.3. Lane B (words, truth, speech): 1.1-1.2,
5, 6. Lane E (harness, flows): 3.2, 3.3, 4, Appendix A/B and the JSON. Lane F (owner page, PROTOCOL 10.29): 6.
QA: 0, 3.2, the JSON.

---

## 0. What the measurements change (ranked by what the owner would hit)

1. **Negation is invisible to the matcher and to the assent test.** `not`/`no` are shipped stop words and `don't` splits
   into a meaning word `don`, so "I'm not ready to learn" scores **1.000** (margin 0.723) against Arngeir's commit
   "I'm ready to learn" and is EXPLICIT under S4.3 - it would click the goodbye line and advance MQ105. Same for "I would
   not like to join the Companions" (1.000/1.000, explicit), "I can't handle myself" (1.000/0.688), "I won't take your
   test" (0.783/0.303), "I'm not ready to take the oath" (1.000/0.600, plain fast pick), "I have time for this" against
   "I don't have time for this" (0.783/0.619), and "I'm ready to go" on Delphine's End layer clicks the SIBLING "Hold on.
   I'm not ready to go yet." (0.783/0.217). On a park, "I do not" / "I will not" / "of course not" / "yeah no" are
   ASSENTS under S4.4 (leading `i do` / `i will` / `of course` / `yeah`, tail untested) - "I do not" to her "You'll swear
   the oath?" would release the oath. **Fix: G1 (3.3) + the tail veto A3 (1.1).** G1 stops all 7 defects; of the lines
   that click correctly today it blocks exactly one ("I will not break the law for you" on Brynjolf's refusal line, which
   then parks and a "yes" clicks it).
2. **The reward trigger words fire on quest lines that are not bargaining.** Matched the `lrgDlgStakes` way (substring),
   `more` hits five REAL entries of these very quests - "I'm ready for more training." (MQ105), "Where can I learn more
   about magic?" (MG01), "Tell me more about the Dragon War." (MQ103), "Safety should be more important than anything."
   (MG01), "Skyrim is home to more than just Nords." (CW00B) - 15 of 815 fixture/index quest lines. And it misses what
   players actually say when they bargain ("what's in it for me", "give me the gold instead", "make me a thane", "double
   the reward", any named sum). Section 5 gives the token-phrase list; it hits 0 non-bargaining quest lines.
3. **Five of the spec's own rows fail on the real layers** (3.2): Eorlund's "a sword, please" / "the sword" (the slot
   matcher returns null for his five - 4-token entries exceed `services.slot.max_words` 3, so S4.3 (c) never fires);
   "and my reward, jarl?" (0.675 - parks); "I'm ready to start" (0.567 - parks); "Olaf found the dragon asleep"
   (0.838 but margin 0.199 - parks); Delphine's "I'd like to rent the attic room" (1.000 but margin **0.093** once her
   real root carries the vanilla "I'd like to rent a room" - she is the innkeeper). And two rows expect `park` where S4.3
   gives `explicit` (3.2 items 6-7).
4. **The owner's STT damages quest language in three measurable ways**: compound names split ("gray beards", "storm
   cloaks", "sky rim", "dragon born", "watch tower", "white run", "con tract") - G2 fixes 11 of 14 probes; the first word
   is clipped (the DLL starts the mic 110-270 ms after the talk key) - 8 commit lines park only because of it (G3); and
   fillers lead the sentence (31 of his 167 distinct logged utterances start with uh/um/hey/well/so/sorry/excuse me, 7 more
   with okay/ok) - "uh yes", "um okay", "well alright", "uh no" are not assents/refusals under the spec (A2, R1).
5. **Service kinds ignore negation**: the owner's own logged line "can't get a room around here" and "I don't need a
   room" return `inn` under the rev2 phrases (`get a room`, `need a room`), which S5 turns into a click on the 10-septim
   RentRoom entry. `lrgDlgNegatedAt` must guard the kind hit (1.4).

---

## 1. Assent, back-out, precedence

### 1.1 The assent list (`confirm.assent_words`, S4.4 + S4.5, one shared list)

**Keep the spec list exactly** (all 27 phrases measured as assents; none is a back-out): yes, aye, yeah, yep, deal,
agreed, sure, sure thing, do it, i do, i will, i swear, i swear it, so be it, i'm ready, i am ready, fine, alright,
all right, ok, okay, very well, of course, let's do it, i accept, i'm in, count me in.

**`ok` == `okay`, measured.** `lrgPromptNorm` gives "OK." -> `ok`, "Ok" -> `ok`, "Okay." / "Okay," -> `okay`; both are
on the list, so every capitalisation/punctuation is one assent. Two STT spellings are NOT covered: "O.K." -> `o k`
(two tokens) and "okey" -> `okey`. Add **`o k`** and **`okey`**.

**Additions (A4)** - each measured as an assent under the proposed compare and as no back-out: yup, yea, yah, o k, okey,
mm hmm, uh huh, absolutely, certainly, definitely, indeed, you bet, for sure, sounds good, go ahead, understood, as you
wish. Deliberately NOT added: `why not` (leads real questions: "why not just make up the missing parts?"), `right`
("right now I'm busy"), `ay`/`eye`/`i` (Parakeet may write "aye" as "I" - unrecoverable, it is a pronoun), `aight`.
`go on` / `carry on` stay continuers for UNSCRIPTED singles only (`confirm.single_entry_extra`), as the spec says.

**The compare (A1-A3)** - over `lrgPromptNorm` tokens, as the spec says, plus three rules the measurements need:
- **A1 apostrophes folded on both sides** (`i'm`=`im`, `let's`=`lets`, `that's`=`thats`): the owner's STT sometimes
  drops them (log: "youre lookin"). Measured: "Im ready", "lets do it", "Im in" are nothing under the spec, assents with A1.
- **A2 leading fillers**: try the raw first 1-3 tokens first (so "so be it", "uh huh", "mm hmm" match as phrases), then
  again with up to TWO leading fillers removed: uh, um, er, erm, ah, oh, hmm, mm, well, so, hey, and, then, look,
  listen. Measured: "uh yes", "um okay", "uh yeah sure", "well alright", "oh yes", "hey okay", "so yes" are nothing
  under the spec, assents with A2.
- **A3 the tail veto** - an assent phrase followed by a tail whose FIRST token is one of but, stop, wait, hold, hang,
  though, although, unless, if, only, later, yet, not, no, never, nope, nothing, don't, won't, can't, OR whose tail
  contains any negator (not, no, never, nope, don't, won't, can't, cannot, didn't, doesn't, isn't, aren't, wasn't,
  weren't, haven't, hasn't, shouldn't, wouldn't, couldn't, ain't), is NOT an assent: nothing is released, the model
  answers. Spec-as-written ASSENTS that A3 stops (measured): "I do not", "I do not want that", "I will not", "I will
  never join you", "of course not", "yeah no", "yeah, no", "okay wait", "alright stop" (**the owner's own logged
  utterance**), "alright, stop", "yes but later", "yes, if you pay me", "I swear I did not do it". Cost: "yes, nothing
  would make me happier" is vetoed (she asks again) - a refusal only ever costs a question.

A3 applies on EVERY path that reads a leading assent (S4.4 park release and S4.5 step 1). S4.5 step 1's own foreign-word
test on commit singles is unchanged and runs after A3.

### 1.2 The back-out list (refusals)

Today: `lrgDlgIsBackOut` (`lrg_dialogue.php:1375`: never ?mind | forget (i|it) | not (sure|yet|right now|now) | need
(more )?time | come back later | maybe later | another time | i('| a)?ll think | on second thought | no thanks |
nothing), ai S2's hesitations (need to think, let me think, give me a (moment|minute|second), hold on, hang on, one
moment, not so fast) and a leading no / nope / stop / never. The SHIPPED park release (`lrg_dialogue.php:2849-2851`)
also refuses on `changed my mind` and `cancel that` (anywhere) - **S4.4's rewrite must keep those two** or "I've changed
my mind" / "cancel that" become non-refusals (measured: nothing under the spec's S4.4 text). The same shipped list also
refuses on `no` and `stop` ANYWHERE as token runs ("yes, no problem" is a refusal today); S4.4 narrows them to a leading
word, and A3's tail veto then covers "yeah, no" / "alright, stop" without refusing "yes, no problem" outright (it is
vetoed as an assent - she asks again - which costs one question, not a dropped park).

- **R1 additions**, all measured as missing today: leading (after <= 2 fillers, A2's list) `nah`, `wait` (alone), `not`,
  `no`, `nope`, `stop`, `never`, `i refuse`, `i won't`, `i will not`, `i do not`, `i don't`, `i can't`, `absolutely not`,
  `hell no`, `no way`; anywhere: `changed my mind`, `cancel that`, `rather not`, `not interested`, `i'll pass`,
  `no thank you`, `don't want to`, `do not want to`. Measured misses today: "nah", "uh no", "um, no", "well no",
  "absolutely not", "hell no", "I refuse", "I'll pass", "I'd rather not", "I'm not interested", "I don't want to".
- **R2 `nothing` only when it LEADS** ("nothing", "nothing for now", "uh, nothing"). Anywhere, it turns sentences into
  refusals: "I barely made it out of Helgen with nothing, so do you have any supplies I could take with me?", "I have
  nothing to lose, count me in", "I didn't walk all the way to Windhelm for nothing, that's why I'm here, I want to join".
- **G6 a leading hesitation that carries a question is not a refusal**: `^(hold on|hang on|one moment|not so fast)`
  followed by a sentence ending in `?` is judged on the rest of the sentence (step 0 onward). Measured: "hold on, what do
  you mean a contract, who am I supposed to kill?" is a refusal today; with G6 it releases "Contract?" (containment
  0.850 - Aventus's real answer is the answer he asked for); "hold on, the Greybeards, those old monks up on the
  mountain, is that who you mean?" likewise on "The Greybeards?".
- `test_gates` must keep asserting that no assent phrase is a refusal phrase (measured: none, with the additions).

### 1.3 Precedence (the order every release path uses)

Park release (S4.4) and single-entry release (S4.5), in this order, first decisive step wins:
0. **Refusal** (1.2: the widened back-out, R1, R2, G6's exception) -> nothing; a park is dropped.
0b. **Shape** (S4.5 only; 2.1): a question to HER against a non-question entry -> nothing.
1. **Leading assent** (1.1: A1-A3; on a commit single the tail must also carry no foreign strict word).
2-5. **Content** (S4.5 steps 2-5, STRICT list with G7) - each step also passes **G1** (3.3).
6. The model (T-key under the gate's rules).

Fast picks (want=1 and the gate's words path), in this order: the enlistment ask (path b, `lrgFacAsk` ->
`lrgFacArbitrate`, exact topic/line) -> price list / slot (incl. G5) -> service kind (S5, with the negation guard 1.4)
-> `lrgDlgMatchText` 0.55/0.15 with S4.8's shared word, **G1 on the entry that would be clicked**, and **G4** for a
plain/pay/check tie -> nothing (the model).

### 1.4 Service kinds and enlistment asks (first contact language, measured)

- rev2's phrases work on the owner's logged shapes: "what do you got for sale?", "excuse me what do you have for
  sale?", "what have you what have you got for sale sir?", "hey could i get a room?" (barter/inn), and the guards hold
  ("hey could i buy something to drink?" '' - `to drink` not_after; "uh some beer" ''; "a for several" '').
- **Negation**: "can't get a room around here" (logged) and "I don't need a room" return `inn`; the hit is negated per
  `lrgDlgNegatedAt` with clause stops. `lrgDlgServiceKind` (or the S2.1 kind clause and the S5 kind pick) must skip a
  negated hit, as `lrgDlgServiceSlot` already does (`lrg_dialogue.php:3964`).
- Not covered (no change proposed; the model answers): "trade some things", "i was looking to maybe buy a weapon today",
  "got anything good?", "give me your best room".
- `lrgFacAsk` (shipped) recognises every enlistment paraphrase in the fixture and the owner's STT: "i want to join the
  lead jam" and "i was looking to sign up for the lead jam" (strong `i want to join` / `sign up`, the NPC's own row fills
  the faction), "I wanna join the Legion" (`join the`), "I wish to enlist in the Imperial Legion", "enlist", "I wanna
  join up", "i would like to join the come pinions", "may I enter the College", "I'm looking to apply to the college".
  It refuses the negations ("I would not like to join the Companions", "I don't want to join the Legion") and the
  third-person/object shapes ("why did you join the Legion?", "can I join you"). So on Kodlak, Tullius, Galmar,
  Faralda and Viarmo, path (b) - not the matcher - carries these lines (the JSON marks them `path (b)`).

---

## 2. Question shape and the stop lists

### 2.1 The question-shape rule (S4.5 step 0)

Spec: an utterance is a question when it ends in `?`, or its first word (optionally after so / well / uh / um / and /
ok / okay) is what why how who where when which is are do does can could would should. An ENTRY is a question when its
txt (trailing tags stripped) ends in `?` or its norm starts with a question word.

Measured gaps (all "statement" under the spec when the STT loses the `?`): "did you see it", "was that the dragon", "will
you help me", "shall we start", "may I ask something", "am I in, then", "have you heard", "has anyone seen it", "isn't it
dangerous", "don't you think so", "hey, what is this place", "sorry, what", "and then what". Parakeet puts `?` on most
fluent questions (36 of the owner's 167 distinct logged utterances end in `?`), so this matters for clipped or run-on
utterances ("did you hear what happened in" was logged with none).

**Q1 (proposed):** add `did|was|were|am|isn't|aren't|don't|doesn't|didn't|won't|can't|wasn't` as leading question
words, and `will|shall|may|have|has` ONLY when the next token is a pronoun or `anyone|anything|someone|anybody` ("Will
do." and "Have a look." stay statements); add `hey|oh|sorry|then|but|now|right` to the optional lead-ins. Known false
positive of the SPEC regex, harmless: "Can do." is a question (bare `can`).

### 2.2 The two stop lists (the rev2 flag: the shipped list is NOT widened)

- **SCORING** (`lrgPromptWords`, `lrg_prompt_index.php:186-197`) stays exactly the shipped 36: the a an i to of and is
  it you your my me do for in on that this what was are be have has with so but not no yes d s t ll re. Every score in
  this note is under it; the spec's cited numbers reproduce exactly on the current tree: "tell me who you are" vs `who
  are you` 0.783, "what do you need done" 0.783, "uh what now" 0.328, "who are the Greybeards" 0.850 (containment), "what
  do they want with me" 0.645 (shares `want`, precision 1.0), "I need a drink" 0.783, "what comes next" 0.675, "is that
  all of it" 0.593, "long live Ulfric" 0.721 (precision 0.67), "all hail the Empire" 0.610, "I brought your shield,
  Aela" 0.675, "a dragon attacked Helgen and I must tell the jarl" 0.567, "you mean this old stone" 0.907.
- **STRICT** (decisions only: meaning / shared / foreign word, precision, "named", the S2.1 clauses) = the shipped list
  + why how who where when which can could would should does mean now then wait uh they them these those there here we us
  he she him his her its our their. The scorer never sees it.
- **G7 (proposed, STRICT only):** + `um er erm hmm ah mm hey`. `uh` is already on it but `um` is not, so a leading "um"
  is a FOREIGN strict word and costs precision on commit singles: "um, what do these Greybeards want with me?" precision
  0.67 -> parks; "um, is that it?" precision 0.00 -> parks. With G7 both release (step 2). Index impact: 54 of 21,148
  distinct norms contain one of these tokens (hey 19, um 14, hmm 10, ah 10, erm 1), none a fixture target. `oh` and
  `okay` are NOT added: they are meaning words of real entries ("Oh, do you mean this old stone?", "Okay, this is for
  the spell").

---

## 3. Floors and thresholds, re-measured

### 3.1 The floors (unchanged - the measurements support them)

| parameter | value | what the real lines say |
|---|---|---|
| `confirm.said_line_score` / `_margin` / `_tokens` (S4.3 a) | 0.70 / 0.25 / min(4, entry tokens); exact 1.0 needs >= 3 tokens | commit beats, natural+STT lines on target: 106 of 171 explicit. Score 0.65 would add 10 (all 0.675 lines) and would also make the negated never-line "I'm not coming along with you" (0.675/0.424) explicit. 0.75 loses 9 real ones. Margin 0.20 or 0.30 changes nothing in this set. Token floor 3 adds 11, mostly first-word clips - G3 takes those precisely without lowering the floor for "got your shield"-type 3-token lines. |
| fast pick (S5) | 0.55 / 0.15, scripted needs a shared word (S4.8) | plain beats: 255 of 300 lines click on the fast path, the other 45 by the model's T-key; S4.8 blocks one real line ("are you alright" vs `are you all right`, 0.750 - G2 fixes it). |
| two-candidate hint (S5) | 0.10 | used as the `ask` boundary in the JSON: both candidates >= 0.55 and within 0.10 = she asks which; 0.10-0.15 = the model picks. |
| `confirm.single_entry_exact` / `_score` / `_precision` (S4.5) | 0.85 / 0.65 / 0.75 | every S4.5 table row reproduces. Natural+STT lines: non-commit scripted singles 25 of 42 release on the fast path (16 go to the model's T-key, 1 is refused by a leading "hold on" - G6); commit singles 17 of 35 release, 18 park and a "yes" clicks them. |
| `confirm.named_score`, `park_seconds`, `utter_window` | 0.45, 60, 30 s | not text-measurable offline; no evidence to change. |

### 3.2 The spec's own rows against the real layers (Lane E: the fixture)

| # | beat | row | measured | what to do |
|---|---|---|---|---|
| 1 | C00.eorlund.choice | "a sword, please" / "the sword" (explicit, slot) | `lrgDlgServiceSlot` = null for all 8 probes (entries `i'd like a sword` are 4 tokens > `max_words` 3; no priced entry; no service kind). Matcher: 0.675/0.355 tok 3; 0.783/0.410 tok 2 -> park | G5 (3.3), or re-class both to `park` |
| 2 | MQ104.balgruuf.myreward | "and my reward, jarl?" (explicit) | 0.675 -> park | re-class `park` (floors are never lowered) |
| 3 | TG00.brynjolf.ready | "I'm ready to start" (explicit) | 0.567 -> park | re-class `park` |
| 4 | MS05.verse.coward | "Olaf found the dragon asleep" (explicit) | 0.838, margin 0.199 vs "Olaf was Numinex..." -> park | re-class `park` |
| 5 | MQ106.delphine.room | "I'd like to rent the attic room", "can I have the attic room for the night" (pay) | with the vanilla "I'd like to rent a room" on her root: 1.000/**0.093**, 0.675/0.139 | the root MUST carry RentRoomTopic (she is the Sleeping Giant's innkeeper; a root without it proves nothing); then G4, or re-class to `ask` |
| 6 | MQ106.delphine.walkout | "I don't have time for this" (park) | explicit 1.000/0.751 under S4.3 | the row is right only with **W** (3.3): the entry IS the walk-away topic of its parent (`MQ106DelphineIntroB3.twat = MQ106DelphineIntroExclusiveA3` **[X]**) and S4.10 says a walk-out always asks, but S4.3's "never true for" list omits it |
| 7 | TG00.brynjolf.refuse | "break the law? are you kidding" (park) | explicit 1.000/0.738 | NOT a walk-out: `TG00BrynjolfIntroBranch06.twat = TG00BrynjolfWalkAwayTopic` **[X]**. It is a plain scripted-goodbye commit, so S4.3 makes the verbatim explicit - re-class the verbatim row to `explicit` |
| 8 | MQ102.irileth.news | parent `skyrim.esm:098D4B` | 098D4B is BALGRUUF's CW03 layer (`MQ102BalgruufIntroCW03` "I have a message from Ulfric Stormcloak") | use Irileth's forcegreet `skyrim.esm:0D39B2` (4 norms; in play the news line + "<t> sent me. Riverwood is in danger.") |
| 9 | MQ104.balgruuf.greybeards | the single "The Greybeards?" | single only after B1 (`05EE38`, power); after B2 (`05EE3E`) the layer is TWO entries (C1 + C2 `05EE35` "What do these Greybeards want with me?", a different info than D1 `0DD042`) | parent_info = 05EE38 |
| 10 | MQ106.delphine.walkout | "forget it, I'm done" (park) | `lrgDlgIsBackOut` is true ("forget it"): the model may send a leave, and S4.2's leave guard answers instead of a park | replace with "I'm leaving, I don't have time for this" (0.610 -> park) |
| 11 | FE.riverwood.plain | never: "sing me something about dragons" | 0.610 on a one-entry list | the spec's 0.098 margin exists only on Sven's real root - the beat must carry his top-level rows |
| 12 | CW00A.tullius.join | "I'm here to enlist in the Imperial Legion" (explicit) | matcher 0.443 -> park; `lrgFacAsk` join=1 (`enlist in`, legion) -> path (b) explicit | consistent via path (b); the fixture must not expect a matcher mode |

Rows the spec states and that reproduce as stated: every S4.5 table row (3.3's cited numbers above), "who are the
Greybeards" 0.850, "the empire will live long" not re-run, "tell me who you are" 0.783, "news from Helgen about the
dragon" 0.941/0.726 on the real Irileth layer (the spec's 0.355 is the margin on 098D4B - row 8).

### 3.3 The guards this note proposes (each one rule, measured)

| id | rule | measured effect | cost |
|---|---|---|---|
| **G1** negation parity | NEG = not no never don't dont won't wont can't cant cannot doesn't didn't isn't aren't wasn't weren't haven't hasn't shouldn't wouldn't couldn't ain't nope neither nor (NOT `LRG_DLG_NEG_WORDS`: it holds `rather`, and "I'd rather come along with you" must not be negated). A shared STRICT word is negated on a side when EVERY occurrence has a NEG within the 6 tokens before it inside its clause (clauses split at , ; : . ! ? ... and spaced dashes; the entry's clauses from its txt, tags stripped); a NEG directly followed by it you we i he she they there that this is question inversion ("isn't it", "don't you", "can't we") and does not count; contraction stems (don won isn aren wasn didn doesn haven shouldn wouldn couldn ain) leave the shared set. **Block** when (the entry has no NEG and >= half the shared words are negated in the utterance) or (the utterance has no NEG and >= half are negated in the entry). Applies to S4.3 (a), the 0.55/0.15 pick (to the entry that WOULD be clicked), S4.5 steps 2-5 and check picks; never to step 1, slots or path (b) (they have their own guards). | stops all 7 defects of 0.1 (6 against the target, "I'm ready to go" against the sibling it would click). Against the target it fires on 11 of 833 beat lines: the 6 defects, 4 lines that park or refuse anyway ("I'm not coming along with you", "never mind", "I'm leaving, this is a waste of time", "I'm not breaking the law for you"), and ONE line that clicks correctly today - "I will not break the law for you" on Brynjolf's refusal line (explicit 0.783/0.508), which then goes to the model, parks and a "yes" clicks it. On the 126 extra probes it fires once, correctly ("not only Nords live in Skyrim", whose top was the WRONG sibling "So you only take Nords?"). | one pass over <= 20 tokens per candidate; when it fires the path does not click, the model decides, a commit parks |
| **G2** STT compound fold | for MATCHING only (never `lrgPromptNorm`, which the index builder mirrors byte for byte): two adjacent utterance tokens whose concatenation equals a token of a visible entry, or is within Levenshtein 1 of an entry token of >= 8 letters, are joined; `alright` -> `all right` when the list carries both. Entry-driven: no table. | "the gray beards" 0.717->1.000; "what do these gray beards want with me" 0.768->1.000 (park -> release); "all hail the storm cloaks" 0.586->0.850 (park -> release); "...daughters of sky rim" 0.870->1.000; "are you alright" 0.750->1.000 (S4.8 no longer blocks); "turns out i may be something called dragon born" margin 0.413->0.754; "the watch tower..." 0.823->1.000; "how do i get to white run from here" 0.823->1.000; "con tract" 0.753->1.000; "i got you the dragon stone what next" 0.789->1.000. Not fixed: "yergen wind caller" 0.745->0.783, "ulf rick", "in mine". | O(tokens x entry words) per list, on the fast path only |
| **G3** first-word clip | the utterance equals the entry's norm minus its FIRST token and scores >= 0.85 -> the explicit token floor is (entry tokens - 1) | 8 commit lines park today only because of the clip: "about my reward" 1.000, "ready to learn" 1.000/0.709 (twice), "can handle myself" 1.000/0.697, "have your shield" 1.000/1.000, "like a sword" 1.000/0.325, "found him asleep" 0.907/0.694, "we ready" 1.000 | one compare |
| **G4** unique-word tie | plain / pay / check only (never grants a commit): when the top two candidates both score >= 0.55 within 0.15 and exactly one of them holds a strict word of the utterance that no other candidate has, it is the pick | Delphine's attic room (5 lines two-fit -> resolve: the word `attic`); "I killed the dragon, where's my reward?" (0.783/0.144, model -> fast: `reward`); MS05 verse.dragon (2 lines, model -> fast). No wrong pick in 833 lines once it requires two candidates >= 0.55 (a single-candidate draft picked "What do you want with me?" for "give me the horn, that's all I want") | per tie only |
| **G5** one-token-difference slot list | a closed layer of >= 3 entries of equal token length differing in exactly ONE position is a slot list; the slot names are the differing tokens; exact token match, `lrgDlgNegatedAt` guard, >= 2 utterance tokens (S4.3 c) | 6 of 5,718 index layers qualify, all real choice lists (Eorlund's weapons; "I'll trade in my armor/boots/gloves/hood"; "tell me about francois/hroar/runa/samuel"; the Akatosh...Stendar amulets; "they sound fascinating/hazardous/strange"; "the gift of company/kindness/laughter/love/music"). Eorlund: 7 lines park today -> explicit; "i'd like a soared" still parks; "sword" (1 token) parks - note that the matcher ties it with the greatsword (both 0.850: `sword` is a substring of `greatsword`), G5's exact token match does not; "a weapon" nothing | one pass per layer, cacheable |
| **W** walk-out | S4.3's "never true for" list gains: the entry is its layer parent's walk-away topic (`twat`) | Delphine's "I don't have time for this" (explicit today) and its 4 close paraphrases park, as S4.10 and the fixture say | one compare |
| **G6** hesitation + question | 1.2 | 2 lines | - |
| **G7** filler strict words | 2.2 | 2 lines | - |

With every guard, the 833 lines resolve as: spec rows 201 resolve / 32 ask / 1 nothing; natural 328 / 59 / 8; STT
152 / 6 / 0; never lines 46 nothing. Without them (the spec as written), 38 lines differ - each is marked in the JSON
("spec-as-written: X; REQUIRES Gn"; one line needs two guards).

### 3.4 Single entries (S4.5) - the thresholds hold

All the spec's single-entry numbers reproduce (step, score, precision) on the current tree. What the new paraphrases add:
- Non-commit scripted singles (the assignment, Task2, HaveStone, HornA2, App2, Contract): 25 of 42 natural+STT lines
  release on the fast path; 16 are statements or paraphrases without the entry's word ("What would you have me do?"
  0.543/0.576, "what's the job?" 0.305/0.145) -> the model's T-key, which the gate admits (not a commit); 1 is refused by
  its leading "hold on" (G6).
- Commit singles (Reward, D1, Final, Oath4 x2): natural lines with a foreign word park, as designed ("anything else I can
  help with?" precision 0.67; "What could the Greybeards possibly want with me?" 0.67; "for the Empire" 0.610; "Skyrim
  for the Nords" 0.494). Every `never` line of both oaths parks or is refused; none releases.
- The owner's own oath shape "i swear to uphold the imperial vows" (logged) releases the UNSCRIPTED oath lines 1-3 (step
  1, `i swear`) but NOT Oath4 (commit: `uphold imperial vows` are foreign words) -> she asks, "yes" finishes it.
- Unscripted singles: STT homophones release by score ("what do you have in mine" 0.767, "what do i have to due" 0.741);
  off-topic questions do not ("what are you thinking?" 0.533, "like what?" 0.194) - the breath or the re-arm advances.

### 3.5 The 42 extra index lines (Appendix B)

42 player lines of MQ102-MQ106, C00, MG01, TG00, DB01, DB02, MS05, CW00A, CW00B, CW01B on their real layers, each said
verbatim, as a natural paraphrase and in the owner's STT style: 119 resolve, 7 ask, 0 nothing, 0 wrong sibling. Verbatim:
42/42 resolve (fast, explicit or single). STT: 42/42 resolve. The 7 asks are commit paraphrases below 0.70 ("my message
is for the jarl only" 0.675, "that's only what the guards called me" 0.608, "why all the secrecy?" 0.567, "glad to
help" 0.267, "I'm the Dragonborn, let me in" 0.567, "skip the tour, I want to start learning" 0.586, "safety comes first"
0.494) - she asks once, as designed.

---

## 4. The paraphrase table

`research/pt19c-language-paraphrases.json`: `{"_": "pt19c-language-paraphrases", "v": 1, "index_hash", "legend",
"beats": {beat id: [{say, expect, note}]}}`, 79 beats (the 3.6 table's advancing lines, the 3.7 first-evening lines),
833 entries: per beat the spec's own rows (`[pick]`/`[explicit]`/... in the note), its `never` lines (`[never]`), five
natural paraphrases (`[casual]`, `[formal]`, `[terse]`, `[rambling]`, `[filler]`) and two STT-damaged variants (`[stt1]`,
`[stt2]`) in this owner's style (first word clipped: "have your shield", "you have any supplies i could take"; compound
split: "gray beards", "storm cloaks", "sky rim"; homophones: "hell gun", "the drag in", "lead jam", "olaf's purse",
"king olaf's verse" -> "purse", "ice raith"; filler and stutter: "uh", "um", "what have you what have you"; dropped
apostrophes).

- `expect`: **resolve** = the target is clicked with no question (fast path, explicit, single, slot, check, path (b),
  or the model's T-key where the note says `path=model`); **ask** = she asks once quoting the line, "yes" clicks it;
  **nothing** = nothing is clicked or parked (on an auto beat the breath still advances).
- `note`: `[label] path=...; s= m= tok=` from the real run on the beat's real layer, then `spec-as-written: X; REQUIRES
  Gn` when the outcome depends on a guard of 3.3, then remarks. Lane E: a line marked REQUIRES belongs in the fixture
  only once Lane A lands that guard; until then it is the evidence for it.
- Appendix A lists the natural and STT lines per beat with their expectation.

---

## 5. Negotiation language (septims) and how gate A answers

### 5.1 What players say (56 probes) and what the shipped functions read

| he says | amount (`lrgDlgNamedAmount`) | today's trigger (spec words, substring) | proposed trigger (5.2) |
|---|---|---|---|
| "I want more", "I deserve more than that", "I think I deserve more", "pay me more", "I'll need more than that", "I was hoping for more", "I expected more gold" | 0 | yes (`more`) | yes |
| "that's not enough", "is that all I get", "double it", "can you double the reward", "throw in something extra", "sweeten the deal", "what about a bonus" | 0 | only `extra`/`sweeten`/`bonus` ones | yes |
| "make it two hundred septims", "I'll do it for five hundred septims", "not for less than three hundred septims", "a hundred septims on top", "fifty septims more and we have a deal" | 200 / 500 / 300 / 100 / 50 | only the `on top`/`more` ones | yes (a named sum) |
| "give me the gold instead", "I'd rather have gold", "can I have a different reward", "can I have a house instead", "I want a title", "make me a thane", "I'd like a horse instead" | 0 | **no** | yes |
| "what's in it for me", "what do I get", "what's the pay", "how much does it pay", "is there a reward", "what's my reward", "will I be paid" | 0 | **no** | yes (reward talk) |
| "let's haggle", "let's negotiate", "how much is it worth to you", "half now, half later", "pay me first" | 0 | only `pay me` | yes |
| "I'm ready for more training", "Where can I learn more about magic?", "Tell me more about the Dragon War", "Safety should be more important than anything", "Skyrim is home to more than just Nords" (REAL entries) | 0 | **yes - false** | no |

Number words (`lrgIntentAmount`, shipped): "two hundred and fifty septims" 250, "a thousand gold" 1000, "a few hundred
septims" 300, "fifteen hundred septims" 1500, "a hundred and fifty septims" 150, "twenty-five septims" 25, "three
hundred and twenty two gold" 322 (the owner's logged phrasing). Wrong or missing: **"5 hundred gold" -> 100** (a digit
before `hundred` is dropped), "I'll do it for three hundred and fifty" -> 0 (no money word; by design "a number alone is
never an amount", but "for 350" in digits IS read - an asymmetry), "one fifty" -> 0, and STT damage of the money word
("five hundred sept ums", "a hundred septem's") -> 0. `LRG_MONEY_WORD` should take `septum|septums|septem|septems`.

### 5.2 The reward trigger (S6.1) - token phrases, not substrings

Match with `lrgDlgPhraseHit` (contiguous tokens), never the `lrgDlgStakes` substring test, and fire on ANY of:
- phrases: more gold, more septims, more coin, more money, more than that, a bit more, a little more, something more,
  pay me more, want more, deserve more, worth more, need more, ask for more, hoping for more, expected more, extra gold,
  extra septims, extra coin, something extra, a little extra, a bonus, bonus, on top of that, on top of it, sweeten,
  raise it, double it, double that, double the, twice that, in septims, in gold, in coin, pay me, name your price, not
  enough, is that all i get, that's all i get, what's in it for me, what do i get, is there a reward, what's the reward,
  what's my reward, the reward, my reward, will i be paid, do i get paid, how much does it pay, what does it pay, what's
  the pay, what are you paying, how much will you pay, a different reward, something else instead, gold instead, septims
  instead, house instead, horse instead, that instead, it instead, one instead, rather have, in exchange, in return,
  negotiate, haggle, worth to you, half now, up front, in advance, a title, make me a thane, make me thane;
- or a named sum (`lrgDlgNamedAmount > 0`) on a quest turn whose list carries no priced entry (a sum on a pay or bribe
  list is a price, not a reward bargain).
Measured: 43 of the 44 distinct bargaining probes hit (the miss is bare "is that all?", excluded on purpose: it hits
MS05's "is that all of it"); on 913 fixture + index quest lines it hits the 11 that ARE reward talk (the MQ103/MQ104
reward entries and their paraphrases, "what do I get for killing the dragon") and 4 named sums on pay/bribe lists (Nirya,
the DB01 guard - excluded by the no-price rule), nothing else. Bare `instead` was dropped: it hit "...there was a note
instead, but I have the Horn of Jurgen Windcaller now". The substring list hit 15 non-bargaining lines.

**Keep the words out of `checks.stakes`.** `lrgDlgStakes` picks the HIGHEST band of any matched stakes row for every free
check; a `reward` row at band 2 moves "please just give me a little more time" and "I need more information, you can
trust me" from band 0 to band 2 (measured). Put the list in `checks.reward.words` (gate A reads it for the locked line;
gate B's reward branch sets band 2 itself).

**`lrgDlgCheckKind` on a quest turn:** "let's haggle" returns `barter` (the merchant haggle regex) - a free barter check
would run against a quest giver whose reward has no price. On a quest turn (S6.1's definition) with no live price, the
`barter` kind should return '' so the reward line answers instead.

### 5.3 How gate A must answer each

| he | gate A does | she says (rule, never an authored line) |
|---|---|---|
| asks for more septims / a bonus / double / names a sum | the `reward` locked line rides; `hide_reward` actions are off the table; no command; no check (gate B) | the reward is what it is; she cannot add septims; she promises nothing; if a reward line is on his list she tells him to ask it |
| wants a different reward (a house, a title, a horse, gold instead of the item) | same | she cannot change what the reward is; nothing else is offered. EXCEPTION: when the game itself offers the choice (Eorlund's five weapons) it is a real entry and S4 picks it ("I'd like the greatsword instead" -> G5) |
| asks what the reward is | the line rides (proposed trigger); a real reward entry on the list is picked by the matcher (measured: "and what about my reward?" 1.000 on MQ103's layer; "what about my reward" explicit on MQ104) | what the game pays is fixed and not hers to state beyond what she knows; she points to the real line |
| haggles / negotiates / "half now, half later" / "pay me first" | the line rides; `barter` check suppressed on the quest turn | she does not bargain over the reward; the reward comes when the work is done |
| offers a bribe on a bribe entry (DB01 guard) | S4 check rails: named amount >= the live price clicks the check; below it ("fifty septims should do" vs 200) nothing is clicked | the refusal in plain words ("he offered less than it costs") next turn, per S7 |
| gate B (v1.0.1) | S6.2 inside the reward window only | the outcome as fact, in septims |

---

## 6. Owner-facing wording: refusals and questions

What she says is the owner's whole interface. Rules for every voiced reason (S7), every park question (S4.4/S11), the
stage-rail label (S3.3), the leave guard (S4.2) and the owner page (5.x / PROTOCOL 10.29):

1. **In her mouth.** First person, as the character, about the world: "I cannot pick for you here" is the approved
   limit; nothing about software.
2. **No machinery words.** Never: glue, CHIM, MCM, mod, plugin, script, server, model, LLM, AI, index, topic, entry,
   T-key, key (except the owner page's "talk key"), matcher, score, calibration / calibrate, session, layer, fast path,
   gate, park / parked, rail, stage rail, clicks_ok, token, dry run (except S7's named switch), an EditorID
   (`MQ102IrilethIntroA1`, anything CamelCase-with-digits), a quest id (MQ102), `.esp`. Allowed: list, menu, line, choose,
   pick, click (the owner sees a list). `lrgVoicedWhy`'s machinery regex (`ostim|chim|mcm|glue|script|plugin|dry-?run|
   package|\.esp|cannot follow you:`) should gain `index|topic|session|calibrat|park|rail|matcher|t-key|editorid`.
3. **Septims.** Every amount she speaks and every amount on the owner page is in septims ("ten septims", "a hundred
   septims"). `gold` only when quoting the game's own on-screen text ("(10 gold)"). Two strings drift today: S7's "not
   enough gold" should be "not enough septims", and `lrgDlgLabel` tells the model `[costs N gold]` / `[bribe: costs N gold
   ...]` - she echoes labels, so they should read septims.
4. **A refusal names what cannot happen, why in world terms, and what he can do** - one sentence, <= 20 words: "Choose
   that one on the list yourself." / "Ask me something simple first, a question, then I can pick this one." / "No line
   here backs out cleanly - leaving is yours to do, and it ends things with me." Never "that is not on the table" when
   something was left unsaid (the conditional rail), never a promise of later payment, never a result she was not told.
5. **A park question quotes the choice in its own words and is a yes/no question** ("You'll ride with me to the tower,
   then - 'I'll come along with you'?"), one question, no alternatives - except the two-candidate case, where she names
   both ("the attic room, or a room for the night?").
6. **Her question must be answerable by the owner's habits.** "Yes", "okay", "alright", "I swear", "count me in" all
   release (1.1); she never asks "say yes to confirm". A "no", "not now", "never mind" ends it with "as you like".
7. Bad -> good: "The stage rail blocks this entry" -> "I have not picked a line for you yet - ask me something simple
   first"; "Parked: MQ104BalgruufIntroA1" -> "You want to ask about your reward?"; "It costs 10 gold" -> "It costs ten
   septims"; "The matcher could not decide" -> "Do you mean the attic room, or a room for the night?".

---

## 7. For the synthesiser: conflicts and pitfalls in one list

Spec conflicts: (a) S4.3's never-list lacks walk-out lines while S4.10 and the fixture expect them to ask (3.2 row 6);
(b) the fixture expects `park` for Brynjolf's verbatim "Break the law?" which S4.3 makes explicit and which is not a
walk-out (row 7); (c) S4.3 (c) claims Eorlund's five are slot lists - the shipped slot matcher says null (row 1);
(d) S6.1's `checks.stakes.reward` placement changes free-check bands (5.2); (e) S6.1's word list matched as the stakes
substring fires on five real entries (0.2); (f) S7's "not enough gold" vs the septims rule (6.3); (g) the fixture's
Irileth parent 098D4B is Balgruuf's layer (row 8); (h) S4.4's rewrite drops the shipped `changed my mind` / `cancel that`
refusals unless kept (1.2); (i) S4.4 makes "I do not" / "I will not" / "of course not" assents (0.1).

---

## Appendix A - natural and STT paraphrases per beat (R resolve, A ask, N nothing; Gn = needs that guard)

| beat | natural: casual / formal / terse / rambling / filler | STT-damaged (this owner's style) |
|---|---|---|
| MQ102A.alvor.help | "got anything I could take with me?" **R** / "Could you spare any supplies for my journey?" **R** / "supplies?" **R** / "I barely made it out of Helgen with nothing, so do you have any supplies I could take with me?" **R** / "um, do you have any supplies I could take?" **R** | "you have any supplies i could take" **R** / "uh do you have any sup lies i could take" **R** |
| MQ102.irileth.door | "gotta see the jarl" **R** / "I must speak with Jarl Balgruuf at once" **R** / "the jarl" **R** / "look, I came all the way from Riverwood and I really need to speak to the jarl right now" **R** / "uh, I need to speak to the jarl" **R** | "need to speak to the jar" **R** / "i need to speak to the yarl" **R** |
| MQ102.irileth.news | "got news from Helgen, about that dragon" **R** / "I bring word from Helgen regarding the dragon attack" **R** / "news from Helgen" **A** / "I was at Helgen when the dragon came down, and I have news about the attack for the jarl" **R** / "um, I have news from Helgen about the dragon attack" **R** | "have news from hell gun about the dragon attack" **R** / "i have news from helgen about the drag and attack" **R** |
| MQ102.balgruuf.intro | "we need to talk about Helgen" **R** / "My lord, I must speak with you about Helgen" **R** / "Helgen" **R** / "jarl, it's about what happened down at Helgen, I really need to talk to you about it" **R** / "so, uh, I need to talk to you about Helgen" **R** | "need to talk to you about hell again" **R** / "i need to talk to you about helgan" **R** |
| MQ102.balgruuf.helgen | "the dragon wrecked Helgen and it was flying this way" **A** / "The dragon destroyed Helgen, and when I last saw it, it was heading toward Whiterun" **R** / "dragon destroyed Helgen" **A** / "so the dragon just tore Helgen apart, burned everything, and the last time I saw it, it was flying this way" **A** / "well, the dragon destroyed Helgen, and last I saw it was heading this way" **R** | "dragon destroyed helgen and last i saw it was heading this way" **R** / "the dragon destroyed hell gun and last i saw it was heading this way" **R** |
| MQ102.balgruuf.reward | "anything else I can help with?" **A** / "Is there anything further I might help you with, my lord?" **A** / "what else?" **R** / "I'm glad I could help, so what else can I help you with while I'm here?" **A** / "uh, what else can I help you with?" **R** | "else can i help you with" **R** / "what else can i help you whip" **A** |
| MQ103.balgruuf.blocking | "got a minute?" **R** / "May I have a word, my lord?" **R** / "a word" **R** / "jarl, sorry to bother you, but there's something I really need to talk to you about" **R** / "um, I need to talk to you" **R** | "need to talk to you" **R** / "i need to talk to ya" **R** |
| MQ103.farengar.intro | "found out anything about the dragons? need a hand?" **R** / "Have you learned anything about the dragons? I would be glad to assist" **R** / "any news on the dragons?" **R** / "the jarl sent me to you, so have you learned anything about these dragons, and do you need any help with it?" **R** / "uh, do you need any help with the dragons?" **R** | "you learned anything about the dragons" **R** / "have you learned anything about the dragons do you need any how" **R** |
| MQ103.farengar.assignment | "ok, what do you need?" **R** / "What would you have me do?" **R** / "what's the job?" **R** / "alright, I'm listening, so what do you need me to do exactly?" **R** / "um, so what do you need me to do?" **R** | "what do you need me to do" **R** / "so what do you need me to to" **R** |
| MQ103.farengar.turnin | "got your tablet" **R** / "I have retrieved the stone tablet you requested" **R** / "the tablet" **R** / "I went all the way down into Bleak Falls Barrow and I have the stone tablet you wanted" **R** / "uh, I have the stone tablet you wanted" **R** | "have the stone tablet you wanted" **R** / "i have the stone tablet you wanna" **R** |
| MQ103.farengar.stone | "you mean this old rock?" **R** / "Do you perhaps mean this old stone?" **R** / "this stone?" **R** / "oh wait, do you mean this old stone I picked up in the barrow?" **R** / "oh, uh, do you mean this old stone?" **R** | "you mean this old stone" **R** / "oh do you mean this old tone" **R** |
| MQ104.irileth.orders | "what's the plan?" **R** / "What are your orders, Housecarl?" **R** / "orders?" **R** / "I came as fast as I could to help with the dragon, so what are your orders?" **R** / "so, um, what are your orders?" **R** | "are your orders" **R** / "what are your orders ear with" **R** |
| MQ104.irileth.along | "I'll tag along" **A** / "I shall accompany you and your men" **A** / "with you" **A** / "I'm not going to scout ahead, I'd rather come along with you and the guards" **A** / "yeah, I'll come along with you" **R** | "come along with you" **R** / "i'll come a long with you" **R** |
| MQ104.balgruuf.dead | "dragon's dead" **R** / "The dragon has been slain, my lord" **R** / "it's dead" **R** / "we went out to the watchtower and fought it, and the dragon is dead now" **R** / "uh, the dragon is dead" **R** | "dragon is dead" **R** / "the drag in is dead" **R** |
| MQ104.balgruuf.reward-ask | "I killed the dragon, where's my reward?" **R** / "I slew the dragon. I believe a reward is in order" **R** / "reward?" **R** / "so I killed that dragon out at the watchtower and honestly I think I deserve a reward for it" **R** / "well, I killed the dragon, I think I deserve a reward" **R** | "killed the dragon i think i deserve a reward" **R** / "i killed the dragon i think i deserve a re word" **R** |
| MQ104.balgruuf.power | "when it died I sucked up some kind of power from it" **R** / "Upon the dragon's death, I absorbed some manner of power from it" **R** / "I absorbed its power" **A** / "something strange happened out there, when the dragon died I absorbed some kind of power from it, like its soul" **R** / "um, when the dragon died I absorbed some kind of power from it" **R** | "the dragon died i absorbed some kind of power from it" **R** / "when the dragon died i absorb some kind of power from it" **R** |
| MQ104.balgruuf.greybeards | "the Greybeards? who are they?" **R** / "The Greybeards, you say?" **R** / "Greybeards?" **R** / "hold on, the Greybeards, those old monks up on the mountain, is that who you mean?" **R** G6 / "uh, the Greybeards?" **R** | "the gray beards" **R** / "the grey beards?" **R** |
| MQ104.balgruuf.want | "what do they want from me?" **A** / "What could the Greybeards possibly want with me?" **A** / "what do they want?" **R** / "I don't get it, why would a bunch of old monks on a mountain want anything to do with me, what do these Greybeards want with me?" **A** / "um, what do these Greybeards want with me?" **R** G7 | "what do these gray beards want with me" **R** G2 / "do these greybeards want with me" **R** |
| MQ104.balgruuf.myreward | "so, what about my reward?" **R** / "I trust I will be compensated for my efforts?" **A** / "my reward?" **A** / "I did everything you asked and killed that thing, so what about my reward, jarl?" **R** / "uh, what about my reward?" **R** | "about my reward" **R** G3 / "what about my re word" **R** |
| MQ105.arngeir.summons | "you called for me?" **R** / "I have come in answer to your summons, Master" **R** / "your summons" **R** / "I heard the voice of the Greybeards call my name from the mountain, and I'm here to answer your summons" **R** / "um, I am answering your summons" **R** | "am answering your summons" **R** / "i am answering your some ons" **R** |
| MQ105.arngeir.learn | "I'm ready, teach me" **A** / "I am prepared to learn whatever you can teach me, Master" **A** / "ready to learn" **R** G3 / "I climbed all seven thousand steps to get here, so I'm ready to learn whatever you have to teach" **R** / "okay, I'm ready to learn" **R** | "ready to learn" **R** G3 / "i'm ready to lauren" **R** |
| MQ105.arngeir.horn | "I'm ready for the next lesson" **R** / "Master, I am prepared to continue my training" **R** / "more training" **R** / "I've practised the Unrelenting Force shout and I think I'm ready for more training now" **R** / "uh, I'm ready for more training" **R** | "ready for more training" **R** / "i'm ready for more train" **R** |
| MQ105.arngeir.next | "thanks, what now?" **R** / "Thank you, Master. What is the next step?" **R** / "what's next?" **R** / "thank you for teaching me that, so what's next on the path?" **R** / "okay, thank you, what's next?" **R** | "thank you what next" **R** / "thank you what's text" **R** |
| MQ105.arngeir.return | "got the horn" **A** / "I have recovered the Horn of Jurgen Windcaller" **R** / "the horn" **A** / "it was a long trip through Ustengrav and there was a note instead, but I have the Horn of Jurgen Windcaller now" **R** / "um, I have the Horn of Jurgen Windcaller" **R** | "have the horn of jurgen windcaller" **R** / "i have the horn of yergen wind caller" **R** |
| MQ106.delphine.room | "can I get the attic room?" **R** / "I would like to rent the attic room, please" **R** G4 / "attic room" **R** / "I was told to ask for the attic room, so I'd like to rent it for the night if it's free" **R** G4 / "uh, I'd like to rent the attic room" **R** G4 | "like to rent the attic room" **R** G4 / "i'd like to rent the attic groom" **R** |
| MQ106.delphine.horn | "I'm just here for the horn" **R** / "I came solely to retrieve the horn" **A** / "the horn" **A** / "look, I don't care about any of this, I just came here for the horn that you took" **R** / "um, I just came here for the horn" **R** | "just came here for the horn" **R** / "i just came here for the born" **R** |
| MQ106.delphine.walkout | "I got no time for this" **A** / "I'm afraid I cannot spare the time for this" **A** / "no time" **A** / "you dragged me up here for riddles, I don't have time for this, I'm going" **A** W / "ugh, I don't have time for this" **A** W | "don't have time for this" **A** W / "i don't have time for the sauce" **A** W |
| MQ106.delphine.mound | "I know that hill, east of Kynesgrove" **R** / "I know the burial mound you speak of, on the hill east of Kynesgrove" **R** / "Kynesgrove mound" **R** / "oh yeah, I've seen that place, the mound high up on the hill east of Kynesgrove, I know exactly where it is" **R** / "uh, I know that mound, east of Kynesgrove" **R** | "know that mound high on the hill east of kines grove" **R** / "i know that mound high on the hill east of kinds grove" **R** |
| MQ106.delphine.go | "let's go get that dragon" **R** / "Let us go and slay this dragon" **R** / "let's go" **R** / "alright, enough talking, I'm ready, let's go kill a dragon before it rises" **R** / "okay, let's go kill a dragon" **R** | "let's go kill a drag in" **R** / "go kill a dragon" **R** |
| C00.kodlak.join | "I wanna join up" **R** / "I wish to join the Companions, if you'll have me" **R** / "join the Companions" **R** / "I've heard a lot about the Companions and I've been fighting my whole life, so I'd like to join the Companions" **R** / "um, I'd like to join the Companions" **R** | "would like to join the companions" **R** / "i would like to join the come pinions" **R** |
| C00.kodlak.handle | "I'll be fine, I can handle myself" **R** / "I assure you, I am quite capable of handling myself" **A** / "I can handle it" **R** / "I've been fighting bandits and worse all over Skyrim, trust me, I can handle myself" **R** / "well, I can handle myself" **R** | "can handle myself" **R** G3 / "i can handle my shelf" **R** |
| C00.eorlund.sword | "Vilkas wanted you to have his sword" **R** / "Vilkas asked me to bring you his sword" **R** / "Vilkas's sword" **R** / "hi, I'm new with the Companions and Vilkas sent me up here with his sword for you to sharpen" **R** / "um, Vilkas sent me with his sword" **R** | "sent me with his sword" **R** / "vil cuss sent me with his sword" **R** |
| C00.aela.shield | "got your shield" **A** / "I have brought your shield back from Eorlund" **A** / "your shield" **A** / "Eorlund finished working on it and asked me to bring it over, so I have your shield" **R** / "uh, I have your shield" **R** | "have your shield" **R** G3 / "i have your she old" **R** |
| C00.eorlund.weapon | "heard you had a weapon for me" **R** / "I was informed you would provide me with a weapon" **R** / "a weapon?" **R** / "Kodlak said I'm one of you now and that you'd have a weapon for me from the forge" **R** / "um, I was told you'd have a weapon for me" **R** | "was told you would have a weapon for me" **R** / "i was told you would have a weapon for may" **R** |
| C00.eorlund.choice | "give me a sword" **R** G5 / "I would prefer a sword, if you please" **R** G5 / "sword please" **R** G5 / "I've always fought with a one handed blade, so I'd like a sword please" **R** / "um, the sword" **R** G5 | "like a sword" **R** G3+G5 / "i'd like a soared" **A** |
| MG01.learn | "where do I go to learn magic?" **R** / "Where might one study the magical arts?" **R** / "magic?" **R** / "I've always wanted to learn spells properly, so where can I learn more about magic around here?" **R** / "uh, where can I learn more about magic?" **R** | "can i learn more about magic" **R** / "where can i learn more a bout magic" **R** |
| MG01.faralda.enter | "can I come in?" **R** / "I request entry to the College of Winterhold" **R** / "let me in" **R** / "I came all this way across the bridge, so may I enter the College, please?" **R** / "um, may I enter the College?" **R** | "i enter the college" **R** / "may i enter the collage" **R** |
| MG01.faralda.persuade | "your little test is an insult, I'm the best mage you'll ever see" **R** / "I am the finest mage you will ever encounter; this test is beneath me" **R** / "this test is an insult" **R** / "honestly, I'm the best mage you'll ever see, and making me do this little test is an insult" **R** / "um, I'm the best mage you'll ever see, this test is an insult" **R** | "the best mage you'll ever see this little test is an insult" **R** / "i'm the best mage you'll ever see this little test is an in salt" **R** |
| MG01.faralda.test | "alright, I'll do your test" **A** / "Very well, I shall take your test" **A** / "test me" **A** / "fine, if that's what it takes to get in, I'll take your test then" **R** / "okay, I'll take your test then" **R** | "take your test then" **R** / "i'll take your text then" **R** |
| MG01.nirya.spell | "here's your gold for the spell" **R** / "Here is the payment for the spell" **R** / "I'll pay" **R** / "fine, thirty septims for the spell, here you go" **R** / "uh, okay, this is for the spell" **R** | "this is for the spell" **R** / "okay this is for the smell" **R** |
| MG01.mirabelle | "Faralda said to come see you" **R** / "I was instructed to report to you" **R** / "I was sent to you" **R** / "I just got through the gate, and Faralda told me I should come see you first" **R** / "um, I was told to come see you" **R** | "was told to come see you" **R** / "i was told to come sea you" **R** |
| MG01.mirabelle.tour | "sure, show me around" **A** / "I would be delighted to have a look around" **R** / "a tour" **A** / "I've never been inside a real college of mages, so yes, I'd love to have a look around" **R** / "oh, I'd love to have a look around" **R** | "love to have a look around" **R** / "i'd love to have a look a round" **R** |
| MG01.tolfdir.class | "my opinion?" **R** / "You wish to hear my opinion?" **R** / "me?" **R** / "wait, you're asking me, the new student, what I think about all this?" **R** / "uh, you want my opinion?" **R** | "want my opinion" **R** / "you want my a pinion" **R** |
| TG00.brynjolf.what | "what?" **R** / "I beg your pardon?" **R** / "huh?" **R** / "sorry, I didn't catch that, what are you talking about?" **R** / "uh, sorry, what?" **R** | "sorry what" **R** / "i'm sorry watt" **R** |
| TG00.brynjolf.chain04 | "what are you thinking?" **N** / "What exactly do you have in mind?" **R** / "like what?" **N** / "alright, I'm listening, what kind of job do you have in mind for me?" **R** / "so, uh, what do you have in mind?" **R** | "what do you have in mine" **R** / "do you have in mind" **R** |
| TG00.brynjolf.chain06 | "what's the job?" **N** / "What exactly would you have me do?" **N** / "what do I do?" **N** / "okay, you've got my attention, so what do I have to do to earn this coin?" **R** / "um, what do I have to do?" **R** | "what do i have to due" **R** / "do i have to do" **R** |
| TG00.brynjolf.refuse | "you want me to break the law? no way" **R** / "I will not break the law for you" **A** G1 / "no" **A** / "are you serious, you want me to plant a ring on an innocent man, break the law, are you kidding?" **R** / "uh, break the law? are you kidding?" **R** | "break the law are you kidding" **R** / "break the lore are you kidding" **R** |
| TG00.brynjolf.persuade | "what good is all that gold if the dragons kill you all?" **R** / "Your gold will be worth nothing if the dragons slay you all" **R** / "dragons will kill you all" **R** / "think about it, there's no point earning all that gold if the dragons come and kill every one of you" **R** / "uh, won't be much point earning all that gold if the dragons kill you all" **R** | "have a point earning all that gold if the dragons kill you all" **R** / "won't have a point earning all that gold if the drag and skill you all" **R** |
| TG00.brynjolf.ready | "ready, let's do this" **R** / "I am ready to begin" **A** / "let's start" **A** / "I've got the ring and I know the plan, so I'm ready, let's get this started" **R** / "alright, I'm ready, let's get this started" **R** | "ready let's get this started" **R** / "i'm ready let's get the started" **R** |
| DB01.aventus.ok | "you okay, kid?" **R** / "Are you well, boy?" **R** / "alright?" **R** / "I heard a kid was doing the Black Sacrament in here, are you all right?" **R** / "uh, are you all right?" **R** | "you all right" **R** / "are you alright" **R** |
| DB01.aventus.contract | "what's the job?" **R** / "What contract do you speak of?" **R** / "contract?" **R** / "hold on, what do you mean a contract, who am I supposed to kill?" **R** G6 / "um, a contract?" **R** | "contact?" **R** / "con tract" **R** |
| DB01.grelod.threat | "the Brotherhood's here for you, Grelod" **R** / "Grelod, the Dark Brotherhood has come for you" **R** / "Dark Brotherhood" **A** / "your time is up, old woman, the Dark Brotherhood has come, Grelod" **R** / "well, the Dark Brotherhood has come, Grelod" **R** | "the dark brotherhood has come grey lod" **R** / "dark brotherhood has come grelod" **R** |
| DB01.guard.report | "Grelod's hurting the orphans, do something" **R** / "Guard, the matron of the orphanage is abusing the children. You must act" **R** / "the orphanage" **R** / "I was just at Honorhall and that woman Grelod is abusing the children there, you have to do something about it" **R** / "um, Grelod is abusing the children at the orphanage" **R** | "grey load is abusing the children at the orphan age" **R** / "is abusing the children at the orphanage you must do something" **R** |
| DB01.guard.persuade | "she chains the kids to the wall, please help them" **R** / "She even shackles the children to the walls. I beg you, do something" **R** / "she shackles the children" **R** / "you should see what she does in there, she even shackles the children to the wall, please, you have to do something" **R** / "uh, she even shackles the children to the wall, please do something" **R** | "even shackles the children to the wall please do something" **R** / "she even shackles the children to the wall please do some thing" **R** |
| DB01.guard.bribe | "arrest her, I'll pay you" **R** / "Please arrest her; I am willing to pay for your trouble" **R** / "I can pay" **N** / "look, I know it's not easy, but please arrest her, I can pay you two hundred septims for it" **R** / "um, please arrest her, I can pay" **R** | "please arrest her i can pay" **R** / "please a rest her i can pay" **R** |
| DB02.captive.who | "who're you?" **R** / "Who might you be?" **R** / "name?" **R** / "I don't remember how I got here, so who are you, and why are you tied up?" **R** / "uh, who are you?" **R** | "who are ya" **R** / "are you" **R** |
| DB02.captive.intimidate | "talk or you're dead" **R** / "You will answer me, or you will die" **R** / "answer or die" **N** / "I'm not going to ask again, answer me or die right here in this shack" **R** / "uh, answer me or die" **R** | "answer me or dye" **R** / "answer me or i" **R** |
| MS05.viarmo.apply | "I want to join the Bards College" **R** / "I would like to apply for admission to the Bards College" **R** / "apply" **R** / "I've been playing the lute for years and I'm looking to apply to the college, is there a way in?" **R** / "um, I'm looking to apply to the college" **R** | "looking to apply to the college" **R** / "i'm looking to apply to the collage" **R** |
| MS05.viarmo.comein | "so you need me for that?" **R** / "And I presume that is where I come in?" **R** / "me?" **R** / "let me guess, you need someone to go get the poem, and that's where I come in?" **R** / "so, uh, that's where I come in?" **R** | "that's where i come in" **R** / "and that's wear i come in" **R** |
| MS05.viarmo.task | "what do you need?" **R** / "What would you have me do?" **R** / "what's the task?" **R** / "alright, I'll help you get the festival back, so what do you need me to do?" **R** / "um, what do you need me to do?" **R** | "what do you need me to to" **R** / "do you need me to do" **R** |
| MS05.viarmo.poem | "got Olaf's verse" **R** / "I have recovered King Olaf's Verse" **R** / "the verse" **R** / "I went all the way to Dead Men's Respite and I found King Olaf's verse" **R** / "uh, I found King Olaf's verse" **R** | "found king olaf's verse" **R** / "i found king olaf's purse" **R** |
| MS05.verse.dragon | "Olaf was the dragon, Numinex in human form" **R** / "Olaf was in truth Numinex, a dragon in human guise" **R** / "Olaf was Numinex" **N** / "here's the twist, Olaf never captured Numinex, Olaf was Numinex, a dragon in human form all along" **R** / "um, Olaf was Numinex, a dragon in human form" **R** | "olaf was new minx a dragon in human form" **R** / "oh laugh was numinex a dragon in human form" **R** |
| MS05.verse.coward | "Olaf caught him sleeping" **A** / "Olaf discovered the dragon while it slept" **A** / "found him asleep" **R** G3 / "the verse should say Olaf didn't beat the dragon at all, Olaf found him asleep" **R** / "uh, Olaf found him asleep" **R** | "olaf found him a sleep" **R** / "oh laugh found him asleep" **R** |
| MS05.verse.final | "that's it?" **A** / "Is that the whole of it?" **A** / "done?" **A** / "so we changed the whole verse, is that it then?" **A** / "um, is that it?" **R** G7 | "is that eat" **A** / "that it?" **R** |
| MS05.bard | "so I'm a bard now?" **R** / "Does this mean I am now a bard of the College?" **R** / "a bard?" **A** / "the festival went great, so does that mean I'm finally a bard now?" **R** / "uh, does that mean I'm a bard now?" **R** | "that mean i'm a bard now" **R** / "does that mean i'm a bored now" **R** |
| MS05.court | "we good to go?" **A** / "Are we prepared to begin?" **A** / "ready?" **A** / "everyone's here and the court is waiting, are we ready?" **R** / "so, uh, are we ready?" **R** | "we ready" **R** G3 / "are we red e" **A** |
| CW00A.tullius.join | "I wanna join the Legion" **R** / "I wish to enlist in the Imperial Legion" **R** / "enlist" **R** / "after what I saw at Helgen I can't just sit around, I want to join the Legion" **R** / "um, I want to join the Legion" **R** | "i want to join the lead jam" **R** / "i was looking to sign up for the lead jam" **R** |
| CW00A.rikke.test | "so, the test?" **R** / "Regarding the test you mentioned" **R** / "the test" **R** / "you said there was some kind of test before I can join, so what about that test?" **R** / "um, about that test" **R** | "a bout that test" **R** / "about that text" **R** |
| CW00A.rikke.accept | "the fort's as good as yours" **A** / "Consider Fort Hraagstad already taken" **R** / "done" **A** / "don't worry about those bandits, consider that fort already yours" **R** / "alright, consider that fort already yours" **R** | "consider that fought already yours" **R** / "that fort already yours" **R** |
| CW01A.oath.ready | "I'm ready, let's do the oath" **R** / "I am prepared to swear the oath" **R** / "the oath" **R** / "I've made up my mind, I want to serve the Empire, I'm ready to take the oath" **R** / "okay, I'm ready to take the oath" **R** | "ready to take the oath" **R** / "i'm ready to take the old" **R** |
| CW01A.oath.1 | "I swear loyalty to the Emperor" **R** / "Upon my honour, I swear undying loyalty to Emperor Titus Mede the Second" **R** / "I swear" **R** / "alright, here goes, upon my honor I do swear undying loyalty to the Emperor Titus Mede" **R** / "um, upon my honor I swear loyalty to the Emperor" **R** | "upon my honor i do swear undying loyalty to the emperor titus mead the second" **R** / "i swear to uphold the imperial vows" **R** |
| CW01A.oath.4 | "long live the Empire!" **R** / "Long live the Emperor, and long live the Empire" **R** / "for the Empire" **A** / "I'm with you, general, long live the Emperor and long live the Empire" **R** / "uh, long live the Emperor, long live the Empire" **R** | "long live the emperor long live the umpire" **R** / "live the emperor long live the empire" **R** |
| CW00B.galmar.test | "so, the test?" **R** / "I am ready to hear about this test" **R** / "the test" **R** / "you said I'd need to prove myself before I can join, so what about that test?" **R** / "um, about that test" **R** | "a bout that test" **R** / "about that text" **R** |
| CW00B.galmar.join | "that's why I came, I wanna join" **A** / "That is precisely why I am here. I wish to join" **R** / "I want to join" **R** / "I didn't walk all the way to Windhelm for nothing, that's why I'm here, I want to join" **R** / "well, that's why I'm here, I want to join" **R** | "that's why i'm hear i want to join" **R** / "that's why i'm here i want to joy" **R** |
| CW00B.galmar.accept | "I'm off to go kill that wraith" **R** / "I shall go and slay the ice wraith, and return shortly" **A** / "off to kill it" **A** / "fine, it's just an ice wraith on Serpent's Bluff, I'm off to kill that ice wraith and I'll be back soon" **R** / "alright, I'm off to kill that ice wraith, I'll be back soon" **R** | "off to kill that ice wraith i'll be back soon" **R** / "i'm off to kill that ice raith i'll be back soon" **R** |
| CW01B.oath.1 | "I swear my blood to Ulfric" **R** / "I do swear my blood and honour to the service of Ulfric Stormcloak" **R** / "I swear" **R** / "alright, I'm ready, I do swear my blood and honor to the service of Ulfric Stormcloak" **R** / "um, I do swear my blood and honor to Ulfric" **R** | "i do swear my blood and honor to the service of ulf rick storm cloak" **R** / "swear my blood and honor to the service of ulfric" **R** |
| CW01B.oath.4 | "hail the Stormcloaks!" **R** / "All hail the Stormcloaks, true sons and daughters of Skyrim" **R** / "Skyrim for the Nords" **A** / "I stand with you, brother, all hail the Stormcloaks, the true sons and daughters of Skyrim" **R** / "uh, all hail the Stormcloaks" **R** | "all hail the storm cloaks" **R** G2 / "all hail the stormcloaks the true sons and daughters of sky rim" **R** |
| FE.hulda.plain | "nice place, get a lot of visitors?" **R** / "This is a fine inn. Do you receive many visitors?" **R** / "busy place?" **R** / "I just got into Whiterun and this is a nice inn you have here, do you get many visitors?" **R** / "um, nice inn you have here" **R** | "nice in you have here do you get many visitors" **R** / "ice inn you have here" **R** |
| FE.riverwood.plain | "know any songs about dragons?" **R** / "Might you know any old ballads concerning dragons?" **R** / "dragon ballads?" **R** / "I just saw a dragon at Helgen, so as a bard, do you know any old ballads about dragons?" **R** / "uh, do you know any old ballads about dragons?" **R** | "you know any old ballads about dragons" **R** / "do you know any old ballots about dragons" **R** |

## Appendix B - the 42 extra index lines on their real layers (verbatim / natural / STT; s = score, m = margin)

| quest | index line | class | layer size | verbatim | natural | STT |
|---|---|---|---|---|---|---|
| MQ102A | "How do I get to Whiterun from here?" | plain | 1 | resolve/fast s=1.000 m=1.000 | "which way to Whiterun?" -> resolve/model s=0.513 m=0.513 | "how do i get to white run from here" -> resolve/fast s=0.823 m=0.823 |
| MQ102A | "General Tullius ordered my execution. Why would I want to help him?" | commit | 2 | resolve/explicit s=1.000 m=0.497 | "Tullius tried to have me executed, why would I help him?" -> resolve/explicit s=0.756 m=0.320 | "general tully us ordered my execution why would i want to help him" -> resolve/explicit s=0.897 m=0.475 |
| MQ102A | "A dragon attacked Helgen and destroyed it. Hadvar and I escaped together." | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "a dragon destroyed Helgen, Hadvar and I got out together" -> resolve/single:step3 s=0.814 m=0.814 | "dragon attacked helgen and destroyed it had far and i escaped together" -> resolve/single:step2 s=0.870 m=0.870 |
| MQ102A | "That dragon flew off this way. You must have seen it." | plain | 2 | resolve/fast s=1.000 m=0.542 | "the dragon flew this way, you must have seen it" -> resolve/fast s=0.941 m=0.473 | "that dragon flew off this way you must have scene it" -> resolve/fast s=0.892 m=0.433 |
| MQ102 | "<Alias=RiverwoodFriend> sent me. Riverwood is in danger." | commit | 4 | resolve/explicit s=1.000 m=0.730 | "Riverwood is in danger, Alvor sent me" -> resolve/explicit s=0.907 m=0.637 | "sent me riverwood is in danger" -> resolve/explicit s=1.000 m=0.737 |
| MQ102 | "I was told to give the message directly to the jarl." | commit | 2 | resolve/explicit s=1.000 m=0.760 | "my message is for the jarl only" -> ask/park s=0.675 m=0.515 | "i was told to give the message directly to the yarl" -> resolve/explicit s=0.870 m=0.630 |
| MQ103 | "All right. Where am I going and what am I fetching?" | single | 1 | resolve/single:step1 s=1.000 m=1.000 | "fine, where am I going and what am I getting?" -> resolve/single:step1 s=0.705 m=0.705 | "alright where am i going and what am i fetching" -> resolve/single:step1 s=0.823 m=0.823 |
| MQ103 | "What does this have to do with dragons?" | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "how does this help with the dragons?" -> resolve/single:step3 s=0.783 m=0.783 | "what does this have to do with drag ins" -> resolve/single:step3 s=0.769 m=0.769 |
| MQ103 | "Just tell me what you need me to do." | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "tell me what you need done" -> resolve/single:step3 s=0.783 m=0.783 | "just tell me what you need me to to" -> resolve/single:step2 s=1.000 m=1.000 |
| MQ103 | "I got you the Dragonstone. What next?" | plain | 2 | resolve/fast s=1.000 m=0.779 | "here's your dragonstone, now what?" -> resolve/fast s=0.567 m=0.363 | "i got you the dragon stone what next" -> resolve/fast s=0.789 m=0.572 |
| MQ103 | "So what about my reward?" | plain | 2 | resolve/fast s=1.000 m=0.752 | "and what about my reward?" -> resolve/fast s=1.000 m=0.756 | "so what about my re word" -> resolve/fast s=0.749 m=0.505 |
| MQ103 | "Tell me more about the Dragon War." | plain | 1 | resolve/fast s=1.000 m=1.000 | "what was the dragon war?" -> resolve/fast s=0.721 m=0.721 | "tell me more about the dragon wore" -> resolve/fast s=0.870 m=0.870 |
| MQ104 | "The watchtower was attacked, but we killed the dragon." | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "the dragon attacked the watchtower but we killed it" -> resolve/single:step2 s=1.000 m=1.000 | "the watch tower was attacked but we killed the dragon" -> resolve/single:step3 s=0.823 m=0.823 |
| MQ104 | "Turns out I may be something called " | plain | 2 | resolve/fast s=1.000 m=0.754 | "they say I'm Dragonborn" -> resolve/model s=0.494 m=0.218 | "turns out i may be something called dragon born" -> resolve/fast s=0.850 m=0.413 |
| MQ104 | "That's just what the men called me." | commit | 2 | resolve/explicit s=1.000 m=0.757 | "that's only what the guards called me" -> ask/park s=0.608 m=0.373 | "that's just what the men call me" -> resolve/explicit s=0.783 m=0.552 |
| MQ104 | "I think you may be right." | plain | 2 | resolve/fast s=1.000 m=0.714 | "maybe you're right" -> resolve/fast s=0.610 m=0.546 | "i think you may be write" -> resolve/fast s=0.783 m=0.498 |
| MQ105 | "Who are you? What is this place?" | plain | 3 | resolve/fast s=1.000 m=0.635 | "who are you, and where am I?" -> resolve/fast s=0.610 m=0.312 | "who are you what is the space" -> resolve/fast s=0.732 m=0.342 |
| MQ105 | "I want to find out what it means to be Dragonborn." | plain | 3 | resolve/fast s=1.000 m=0.635 | "what does being Dragonborn mean?" -> resolve/model s=0.494 m=0.194 | "i want to find out what it means to be dragon born" -> resolve/fast s=0.823 m=0.463 |
| MQ105 | "You call me Dragonborn. What does that mean?" | commit | 2 | resolve/explicit s=1.000 m=0.812 | "why do you call me Dragonborn?" -> resolve/explicit s=0.721 m=0.460 | "you call me dragon born what does that mean" -> resolve/explicit s=0.791 m=0.605 |
| MQ105 | "I thought it was this easy for everyone." | plain | 3 | resolve/fast s=1.000 m=0.707 | "isn't it this easy for everybody?" -> resolve/fast s=0.586 m=0.341 | "i thought it was this easy for every one" -> resolve/fast s=0.790 m=0.501 |
| MQ106 | "You're the one who took the horn?" | plain | 3 | resolve/fast s=1.000 m=0.730 | "so you took the horn" -> resolve/fast s=0.783 m=0.488 | "you're the one who took the born" -> resolve/fast s=0.838 m=0.567 |
| MQ106 | "What's with all the cloak and dagger?" | commit | 3 | resolve/explicit s=1.000 m=0.733 | "why all the secrecy?" -> ask/park s=0.567 m=0.338 | "what's with all the clock and dagger" -> resolve/explicit s=0.783 m=0.517 |
| MQ106 | "Why did you take the horn from Ustengrav?" | commit | 7 | resolve/explicit s=1.000 m=0.506 | "why'd you steal the horn from Ustengrav?" -> resolve/explicit s=0.823 m=0.310 | "why did you take the horn from used and grav" -> resolve/explicit s=0.850 m=0.370 |
| MQ106 | "So what's the part you're not telling me?" | plain | 7 | resolve/fast s=1.000 m=0.632 | "what aren't you telling me?" -> resolve/fast s=0.675 m=0.262 | "so what's the part your not telling me" -> resolve/fast s=1.000 m=0.667 |
| MQ106 | "Hold on. I'm not ready to go yet." | plain | 3 | resolve/fast s=1.000 m=0.488 | "wait, I'm not ready yet" -> resolve/fast s=0.721 m=0.487 | "old on i'm not ready to go yet" -> resolve/fast s=0.850 m=0.338 |
| C00 | "Does Vilkas always send newcomers on errands?" | plain | 2 | resolve/fast s=1.000 m=0.825 | "does Vilkas always make new people run errands?" -> resolve/fast s=0.721 m=0.529 | "does vil cuss always send newcomers on errands" -> resolve/fast s=0.850 m=0.658 |
| C00 | "I'm happy to lend a hand." | commit | 2 | resolve/explicit s=1.000 m=0.666 | "glad to help" -> ask/park s=0.267 m=0.092 | "i'm happy to land a hand" -> resolve/explicit s=0.783 m=0.473 |
| MG01 | "What is this place?" | plain | 4 | resolve/fast s=1.000 m=0.649 | "what is this building?" -> resolve/fast s=0.574 m=0.247 | "what is the space" -> resolve/fast s=0.686 m=0.286 |
| MG01 | "Would you grant entry to the Dragonborn?" | commit | 4 | resolve/explicit s=1.000 m=0.670 | "I'm the Dragonborn, let me in" -> ask/park s=0.567 m=0.268 | "would you grant entry to the dragon born" -> resolve/explicit s=0.790 m=0.465 |
| MG01 | "I'd rather start learning something right away." | commit | 3 | resolve/explicit s=1.000 m=0.769 | "skip the tour, I want to start learning" -> ask/park s=0.586 m=0.233 | "rather start learning something right away" -> resolve/explicit s=1.000 m=0.818 |
| MG01 | "Safety should be more important than anything." | commit | 3 | resolve/explicit s=1.000 m=0.542 | "safety comes first" -> ask/park s=0.494 m=0.285 | "safety should be more important than any thing" -> resolve/explicit s=0.850 m=0.400 |
| TG00 | "My wealth is none of your business." | plain | 5 | resolve/fast s=1.000 m=0.520 | "how much money I have is none of your business" -> resolve/fast s=0.675 m=0.181 | "my well this none of your business" -> resolve/fast s=0.783 m=0.303 |
| TG00 | "Why plant the ring on Brand-Shei?" | commit | 2 | resolve/explicit s=1.000 m=0.738 | "why frame Brand-Shei?" -> resolve/explicit s=0.783 m=0.620 | "why plant the ring on brand shay" -> resolve/explicit s=0.870 m=0.608 |
| DB01 | "I'm sorry boy, but I'm not who you think I am." | plain | 2 | resolve/fast s=1.000 m=0.751 | "sorry kid, I'm not who you think" -> resolve/fast s=0.783 m=0.558 | "i'm sorry boy but i'm not who you think i am" -> resolve/fast s=1.000 m=0.751 |
| DB01 | "Yes, of course... The Black Sacrament..." | plain | 2 | resolve/fast s=1.000 m=0.730 | "yes, I heard the Black Sacrament" -> resolve/fast s=0.783 m=0.655 | "yes of course the black sack rament" -> resolve/fast s=0.776 m=0.493 |
| DB02 | "Shhh... Don't be afraid. You can tell me. (Persuade)" | commit | 2 | resolve/explicit s=1.000 m=0.815 | "don't be scared, you can tell me" -> resolve/explicit s=0.783 m=0.579 | "shh don't be afraid you can tell me" -> resolve/explicit s=0.870 m=0.682 |
| CW00A | "I helped Hadvar escape. He said he'd vouch for me." | plain | 3 | resolve/fast s=1.000 m=0.695 | "Hadvar said he'd vouch for me, I helped him escape" -> resolve/fast s=0.950 m=0.686 | "i helped had far escape he said he'd vouch for me" -> resolve/fast s=0.850 m=0.553 |
| CW00A | "I'm going alone?" | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "I'll go by myself" -> resolve/model s=0.350 m=0.350 | "i'm going a loan" -> resolve/single:step3 s=0.723 m=0.723 |
| CW00B | "Skyrim is home to more than just Nords." | plain | 4 | resolve/fast s=1.000 m=0.325 | "not only Nords live in Skyrim" -> resolve/model s=0.721 m=0.111 G1 | "sky rim is home to more than just nords" -> resolve/fast s=0.850 m=0.356 |
| CW00B | "What kind of test?" | plain | 2 | resolve/fast s=1.000 m=0.852 | "what's the test?" -> resolve/fast s=0.783 m=0.568 | "what kind of test" -> resolve/fast s=1.000 m=0.852 |
| CW01B | "Isn't it enough that I want to fight Imperials?" | plain | 3 | resolve/fast s=1.000 m=0.768 | "isn't wanting to fight Imperials enough?" -> resolve/fast s=0.870 m=0.586 | "isn't it enough that i want to fight imperials" -> resolve/fast s=1.000 m=0.768 |
| MS05 | "Can't we just make up missing parts of the verse?" | single | 1 | resolve/single:step2 s=1.000 m=1.000 | "why not just make up the missing parts?" -> resolve/single:step4 s=0.814 m=0.814 | "can't we just make up missing parts of the verse" -> resolve/single:step2 s=1.000 m=1.000 |

## Appendix C - reproduce

Scripts (read-only against the glue; run in WSL with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/pt19c-language/find.sh <script> [mode]`) in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-language\`: `lang.php beats` (the 833 lines, `beats.txt` is the fixture DSL), `lang.php extras` (Appendix B), `lang.php tests` (assent / refusal / shape / negotiation / kinds / enlistment / slot / G1 / G2 / G3 / stop lists; `tests.inc.php`), `lang.php neg` (G1 over the beats), `gen.php` (the JSON + Appendix A), `rw.php` (the reward trigger list), `g5.php` (the one-token-difference layers), `cnt.php` (G7 index impact). The lib is a copy of `glue/server/lorerim_glue` taken 2026-09-24.
