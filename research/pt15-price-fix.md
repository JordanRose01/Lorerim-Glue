# pt15 - "everybody has a price": firm offers, the confirming question, the purse

Server 0.5.6 (ext `lorerim_glue`), deployed 2026-09-23 05:40. All tests and flows pass. The deployed tree lints (18 PHP files).
Nothing in CHIM core was changed. `preprocessing.php` and `lib/lrg_stt.php` were not touched: they were deployed byte-identical to the copies already live.

## What went wrong tonight

Katana was a curious merchant: aff 1, score -19, floor 225. You were carrying 322 gold.

| You said | Server before | Why she refused |
|---|---|---|
| "and everybody has a price" | intent=none | The phrase was not recognised at all. |
| "if you were to fuck me i'd give you a thousand gold" | intent=askprice, offer=0/none, `why="no amount was named"` | Every "if" sentence was turned into "asking the price", and the 1000 was thrown away. The askprice directive also said "name a figure ... *or refuse the question outright*". The closed-mode boundary text said "Strangers and near-strangers are refused". She took the refusal option: "I don't have a price". |

## What happens now (your rulings A-D)

- **A.** An "if" sentence is still non-committing. It now keeps its amount: `offer=1000/hypothetical`. Nothing can start on it, and no gold can be charged for it.
- **B.** A **firm** offer at or above her price, when you are carrying the coin (`pgold`), removes `not_close_enough` by itself. Trust and affinity no longer matter.
  - The gate line shows `lifted=bought:<N>`, and a separate `price: bought - ...` line is logged.
  - Her directive says: take it with **BeginIntimacy amount N**, or haggle upward. The gold is the trust, and she does not refuse for lack of trust.
  - If there are witnesses, she takes the offer by choosing **SuggestPrivacy**. BeginIntimacy is still not offered in public.
- **C.** A grey offer (an "if", a "would", or an amount that is only implied) goes like this:
  - If it clears her price and your purse, she asks **one** short confirming question and starts nothing.
  - The server remembers the offer for 2 minutes: amount, NPC, time and expiry, in `lrg_memory.offer_pending`.
  - Your answer then completes it, cancels it or replaces it (see the table below).
- **D.** An offer larger than the gold you carry is called out plainly: "you don't have that on you, show me the coin first". It is never silently dropped, and no scene starts.
  - If the post-scene check drops a start because your purse turned out to be short, she says so on her next turn in the same words.

**Tonight replayed with this build (322 gold on you):**
1. "and everybody has a price": she names 225 and cannot say she has no price.
2. The "if ... a thousand gold" line: 1000 is more than you carry, so she calls it out and tells you to show the coin. Nothing starts.
3. "I'll give you 300 gold": BeginIntimacy is offered at once with amount 300.

With 1000+ gold on you, step 2 would instead get "a thousand, and you mean it, right now?", and "yes" would start it.

## Phrases you can use, and what each one does

Her price is her **floor**. Your **purse** is the gold on you in the snapshot.

| You say (examples) | Server reads | What she does |
|---|---|---|
| "everybody has a price", "everybody's got a price", "name your price", "how much", "what would it take", "what's it gonna cost" | askprice | Names her price (floor or more). She is told she never claims to have no price. The figure is remembered for 5 minutes, so "deal" can close it. |
| "I'll give you 300 gold", "I'll give you a thousand", "here's five hundred", "five hundred septims, right now", "take three hundred and come upstairs", "I'll pay you 1,000 gold", "I'll give you 1k", "deal" / "fine, I'll pay it" (after she named a figure) | **FIRM** offer of N | If N ≥ floor and N ≤ purse: gate opens (private: BeginIntimacy offered with amount N; witnesses: SuggestPrivacy). If N < floor: "not for N", and she names her price. If N > purse: "show me the coin first". |
| "if you ... I'd give you a thousand gold", "what if I gave you 500 gold", "would you for a hundred septims?", "would fifty do?", "I could give you 400" | grey offer of N (`hypothetical`) | If N ≥ floor and N ≤ purse: **one confirming question**, nothing starts, offer pending 2 min. If N < floor: "not for N", and she names her price. If N > purse: "show me the coin first". |
| "a thousand gold for a night with you", "I've got a thousand septims", "is 200 enough?", "how about 150" / "make it 300" (once coin is the subject) | grey offer of N (`implied`) | Same as the row above. |
| While she is waiting: "yes", "yeah", "deal", "done", "agreed", "right", "that's right", "I mean it", "I swear", "right now", "a thousand" (the same figure), "yes, a thousand, right now" | **FIRM** offer at the remembered amount (`confirm`) | Handled exactly like the firm row above. Normally BeginIntimacy is offered with the amount. |
| While she is waiting: "no", "nope", "not now", "forget it", "never mind", "I was joking", "I take it back", "no deal" | withdraw | She lets it go in one line. The pending offer, and any firm figure at that amount, are dropped. A later "yes" buys nothing. |
| While she is waiting: a **different** figure, e.g. "five hundred", "make it 500" | replaces the pending offer | She asks again about the new figure. Said firmly ("five hundred gold, right now"), it is handled as a firm offer. |
| "no, I mean it" (while she is waiting) | confirm | "I mean it" beats the "no". |
| More than 2 minutes later: "yes" | nothing | The pending offer has expired. |
| "I'll pay you well", "take my purse", "money is no object" | offer with no figure | She names her price. |
| "that's too much", "come down on your price", "meet me in the middle" | haggle | She may come down to her floor, or hold firm. |

**Amounts understood:** 50, 1,000, 2,500, 1k, fifty, a hundred, a thousand, two hundred and fifty, five hundred septims, fifteen hundred, one thousand five hundred, a couple hundred, a few hundred. Coin words are gold, septims, coins, pieces and drakes.

A number alone is not an offer ("fifty"). The exception is when she has just named a figure or is waiting for your answer. A small figure (under 10) with no coin word is ignored: "take one for the team" is not an offer. Paying for goods ("for the room", "for the sword") is still never read as paying her.

**Unchanged:** adults only, the kill switch, `never` profiles (silent, no price), not-for-sale / married-refuse NPCs (plain refusal, no figure), and "nothing is bought or sold" inside a running scene. Interested and drawn NPCs need no price, and an "if" is never charged even to them. Paid scenes give half the relationship gain, and paid arrangements are remembered.

## What to grep in `lorerim_glue.log`

**The `price npc=` line:**
- The offer now shows as `offer=N/<source>`. Sources are `said`, `confirm`, `quote`, `memory`, `hypothetical`, `implied` and `withdrawn`.
- The decision is one of `accepted`, `confirm`, `below_floor`, `unaffordable`, `withdrawn`, `needed` or `free`.
- `pending=N` appears at the end while she is waiting on an offer.

**The `turn` line:** its `paid=` segment carries the same decision, and `why=` says `hypothetical: ...` or `confirming his offer: ...`.

**Other lines:**
- The gate line ends in `lifted=bought:N` when the gold opened the gate.
- `price: <npc> - the player spoke of N septims without committing (...): she asks him to confirm; pending for 120s`
- `price: <npc> - the player confirmed his offer of N septims: it is firm now`
- `price: <npc> - the player took back his offer of N septims`
- `price: bought - <npc> would take F septims, the player made a firm offer of N (...) and is carrying P`

**Config:** `paid_intimacy.confirm_ttl_seconds` is 120, the life of the pending offer.

## Files changed

- `server/lorerim_glue/lib/lrg_intent.php`: amount parsing ("1,000", "1k", word numbers, the first real number phrase). Also: the money recogniser (askprice phrases, firm vs grey, pending confirm / withdraw / replace, counters), the new `withdraw` kind, offers taking precedence over the escort recogniser when a figure is named, and the money directives (rulings A-D).
- `server/lorerim_glue/lib/lrg_core.php`: `lrgPaidOffer`. Only a firm figure is ever stored or charged. The pending-offer state machine is idempotent across the two passes of one request: it remembers which request created it, and withdrawals and confirmations are marked instead of deleted. Also the new decisions, the `pending=` field and the `bought` log line.
- `server/lorerim_glue/lib/lrg_actions.php`:
  - Only a firm figure can be charged (post-gate, `pay=`).
  - The pending offer is cleared at scene start and end.
  - Her price is remembered whenever she is told to name it.
  - The gate line gets `lifted=bought:N`.
  - The boundary and moment text changed:
    - "has a price, never claims to have none"
    - "strangers are refused - unless they pay her price"
    - on a paid turn: "the gold is the trust, BeginIntimacy carries amount N"
    - the voiced "show me the coin first" when a start is dropped for gold.
  - A withdrawn offer no longer raises heat.
  - `LRG_ACTIONS_VERSION` was not bumped, because no catalog row changed.
- `server/lorerim_glue/config/lrg_config.default.json`: `confirm_ttl_seconds` plus its readme.
- `tools/test_gates.php`: new section 32b, the log replay and every ruling end to end, including the second pass of one request. Checks [10] were extended.
- `tools/test_intent.php`: new section I2, about 45 rows (log lines, firm / grey / confirm / counter / withdraw, number words, directives). The old "what if ... 500 gold = askprice/0" and "clearing offer: if not, say no" expectations were updated to your rulings.
- `tools/test_phrases.php`: money rows M31-M42 and section E.

## Results

| Suite | Result |
|---|---|
| php -l, all source and tools | clean |
| test_gates | 421/421 |
| test_intent | 316/316 |
| test_phrases | 47/47 |
| test_scene_index | all pass |
| test_dialogue | 174/174 |
| test_prompt_index | 67/67 |
| test_mcm_wiring | 3/3 |
| test_services | 35/35 |
| test_latency | 30/30 |
| flows `--strict` | 78/78 scenarios, 1314 checks, RESULT OK |

**Deploy:**
- Postgres was down and was started first.
- `deploy_server.ps1` passed the anchor pre-flight (34/34). It rebuilt the scene index and reloaded the prompt index (37561 rows), and the index pre-flight passed.
- The deployed hashes of the four changed files match the source. All 18 deployed PHP files lint, and the JSON is valid.
- The deployed recogniser reads tonight's two lines as askprice/0 and offer/1000 hypothetical.

## Known limits

- A "yes" said within 2 minutes of her confirming question counts as confirming the offer, even if you meant it as the answer to something else. Say "no" or "forget it" to cancel.
- A negation anywhere in an offer still voids it, as before. "I'll give you 500 gold, don't tell anyone" reads as no offer.
- If she accepts and your purse turns out short at the post-gate, she can only call it out on her *next* turn. Her reply for this turn has already been written.
- Not tested in game yet.
