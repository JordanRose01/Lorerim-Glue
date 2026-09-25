# pt8 - intimacy investigation + design (round v0.4.0)

Lane: INTIMACY INVESTIGATOR + DESIGNER. Three items, item 3 first because it is the biggest.
Read-only everywhere except this file. Nothing was installed, compiled, deployed or written to the game,
to CHIM, to HerikaServer or to Postgres.

Sources are named inline as `file:line`. Log evidence is quoted verbatim. Log clocks:
the glue log is `America/New_York` (-04:00), `chim.log` is `Europe/Madrid` (+02:00) - Madrid minus 6 h
= the owner's clock. Both are in this file where it matters.

**NEW, UNPLANNED EVIDENCE: the owner played AGAIN tonight (21:47-22:17 local, after v0.3.1 was
installed at 20:37) and the "director mode" defect is in THAT log, three times, with the player's
verbatim words. Playtest 8 is the primary evidence for item 1 and item 2, not playtest 7.**

---

## 0. What the builders must take away (one screen)

| # | item | verdict |
|---|------|---------|
| 3 | "everyone has a price" | designed in full below (§1): config schema, exact arithmetic, prompt words, intent patterns, gate rules, wire keys, game transfer, halved gain, memory, log fields, MCM keys, 8 test cases, worked price table |
| 1 | "left in OStim director mode" | ROOT CAUSE FOUND WITH PROOF (§2). `LRG_OStim.FinishThread` releases CHIM's listener at the scene end (`LRG_OStim.psc:3651`), CHIM's own router then answers `no_eligible_npc` and the player's next lines become `narrator_inputtext`. Fix = keep the listener on the partner past the outro with a bounded hold + a camera/controls self-repair. |
| 2 | "a closed door is private, CHIM must not hear through walls" | ROOT CAUSE FOUND WITH PROOF (§3). `LRG_Profile.WitnessScan` counts every awake adult within 600 units **with no line-of-sight test at all** (`LRG_Profile.psc:158`). Fix = one new snapshot fact (`door=`) plus dropping the no-LOS clause behind a closed door. CHIM's own hearing is already narrow in WHISPER mode; what leaks through walls is the `<nearby_actors>` prompt block, which is an owner setting, not a glue bug. |

Everything below is additive to the wire (PROTOCOL -> v0.4): an old game script ignores every new key,
and a new server works with script 310 because every new key has a documented "absent" meaning.

---

# 1. "EVERYONE HAS A PRICE" - paid intimacy

Owner text: OWNER_ADDENDA item 6 (2026-09-21 ~21:15). The design decisions in 6a-6c are binding;
this section refines the numbers and writes the mechanism out exactly.

## 1.1 What already exists (so nothing is built twice)

| thing | where | state |
|---|---|---|
| player's gold | snapshot key `pgold=` - `LRG_Profile.psc:178` (`PlayerFacts`) | **already sent**. The addendum says "add pgold="; it is already there and has been since v=1. Nothing to add game-side. `tools/flows/adapter.php:133` already fakes it (`pgold=300`). |
| NPC's gold | snapshot key `gold=` - `LRG_Profile.psc:347` | already sent |
| wealth tier | `lrgWealthWords()` - `lrg_core.php:931-942`, from `profile['wealth']` else `leverage.gold_tier_by_npc_gold` | exists; **needs a tier-returning twin** (`lrgWealthTier()`), because today only the WORDS come out |
| how much coin moves them | `profile['gold_sway']` (`insulting|risky|indifferent|offering|tempting|decisive`) on every status rule, `lrg_config.default.json:82-186`; words in `lrgStaticGuidance()` `lrg_actions.php:2090-2095` | exists as words only, never as a number |
| renown pull | `lrgRenownLevel()` / `lrgRenownWords()` `lrg_core.php:763-951`, `interest.renown_bonus` x `profile['renown_sway']` | exists |
| interest score / word | `lrgInterest()` `lrg_core.php:776-799`; thresholds indifferent < -25, curious < 0, interested < 20, drawn >= 20 | exists. `willing = score >= 0`; `!willing` is gate reason `not_close_enough` (`lrg_core.php:856`) |
| history | `lrg_romance.scenes`, `lrgHistoryBonus()` `lrg_core.php:746-755` | exists |
| **`lrg_romance.gold_accepted`** | `migrations/001_lrg_tables.sql`, whitelisted in `lrgRomanceBump()` `lrg_core.php:397` and `lrgRomanceSet()` `lrg_core.php:420` | **column exists, is written nowhere, read nowhere. It was put there for exactly this feature.** No migration needed. |
| `AMOUNT` slot | `lrg_actions.php:9` header: "with the strict JSON schema the LLM can only fill target, item, amount" | the slot exists in CHIM's schema; BeginIntimacy's `parameters_json` (`lrg_actions.php:79`) declares only `target`, and the wire param for our rows is whatever `followup.arg_name` names - so the amount does **not** reach us today. §1.7 has the verified mechanism that makes it reach us. |
| scene-end gain | `lrgAwardSceneAffinity()` `lrg_actions.php:513-533` (`first_scene_gain` 8 / `scene_gain` 4, window 1800 s, min 60 s) | exists, no notion of payment |
| memory writer | `lrgMemSet()` `lrg_core.php:443-458` (shallow merge, `null` deletes) + `logEvent(['infoaction', ...])` for things CHIM must remember (`lrg_actions.php:354-360`) | exists |
| the gold form | `Gold001` = `0x0000000F` | the addendum's own call is right; `Actor.RemoveItem(Form, int, bool, ObjectReference)` is vanilla |

Nothing in the glue has ever moved a single septim. `grep -n "gold\|septim\|pay" server/lorerim_glue/**`
returns only the words in prompts and one comment. This is a from-scratch feature on top of existing data.

## 1.2 Config schema (`config/lrg_config.default.json`, new top-level block)

```jsonc
"paid_intimacy": {
  "_readme": "OWNER ADDENDA 6 - 'everyone has a price'. Prices are expressed in DAYS OF WAGE and turned into gold by the server, so one number (gold_per_day_of_wage) rescales the whole economy. Anchor: the owner's 3-5 gold per hour => a day ~35, a week ~250, a month ~1000. A price is only ever NEEDED when the NPC is not already willing (interest score < 0): a willing NPC is never for sale, she is interested. Gold removes exactly ONE gate reason (not_close_enough) and nothing else: never, married-refuse, witnesses, a child nearby, combat, adults-only and the kill switch are untouched, and a refusal she gives for any other reason stands. not_for_sale removes the whole subject - money is then never mentioned to her at all.",
  "enabled": true,
  "gold_per_day_of_wage": 35,
  "round_to": 5,
  "min_price": 10,
  "max_price": 200000,
  "price_multiplier": 1.0,

  "bands_by_wealth": {
    "_readme": "[floor, ceiling] in DAYS OF WAGE, used when the matched status rule carries no price_band of its own. Owner's ladder from item 6a.",
    "destitute":   [0.5, 2],
    "poor":        [1, 4],
    "modest":      [4, 15],
    "comfortable": [15, 60],
    "wealthy":     [60, 300],
    "noble":       [300, 3000]
  },

  "money_influence": {
    "_readme": "How strongly coin moves this character, derived from the profile's existing gold_sway when the profile sets no money_influence of its own. A factor ON THE DAYS, so 'insulting' does not mean 'impossible' - it means exorbitant, which is the owner's whole point. A profile that carries an explicit price_band should also carry an explicit money_influence (1.0 in the shipped rules), because the band already expresses the exorbitance.",
    "decisive": 0.5, "tempting": 0.8, "offering": 1.2, "indifferent": 1.6, "risky": 2.5, "insulting": 4.0
  },

  "stance": {
    "_readme": "Where inside the band she starts, from the interest WORD lrgInterest() already produces. interested / drawn need no price at all (owner: 'genuinely interested -> no price needed'); a token gift is allowed and is capped by token_days.",
    "indifferent": "ceiling",
    "curious": "floor",
    "interested": "free",
    "drawn": "free"
  },
  "token_days": 0.5,

  "secret_factor": 3.0,
  "repeat_factor": 0.85,
  "renown_discount": {
    "_readme": "Mirrors interest.renown_bonus: [unknown, many deeds, Dragonborn] per the profile's renown_sway. Status and fame make her cheaper, which is the 'massive symbols of status and wealth' half of the owner's request.",
    "weak":     [1.0, 0.97, 0.95],
    "moderate": [1.0, 0.92, 0.85],
    "strong":   [1.0, 0.85, 0.75]
  },

  "_ttl_readme": "offer_ttl_seconds has to survive the WALK: the player offers in the tavern (mode public, reasons=witnesses), she suggests going somewhere private, and the gate only sees mode private minutes later - playtest 8 took seven minutes over exactly that. 600, not 180. The offer is cleared outright at ev=start and ev=end, so it can never be spent twice.",
  "offer_ttl_seconds": 600,
  "quote_ttl_seconds": 300,
  "paid_gain_factor": 0.5,
  "remember_price": true,
  "log_prices": true
}
```

Per-rule keys (new, all optional, read from a status rule or from `npc_overrides.<name>`):
`price_band` `[lo, hi]` in days, `money_influence` (float), `price_gold` (pins the final gold outright),
`not_for_sale` (bool). Recommended values for the shipped rules, chosen so each row lands where the
owner put it in 6a:

| rule | price_band (days) | money_influence | indifferent | curious |
|---|---|---|---|---|
| beggar | [0.5, 2] | 0.5 (decisive) | 35 | 20 |
| tavern_folk | [1, 4] | 0.8 (tempting) | **110** | 35 |
| commoner | [1, 4] | 0.8 | 110 | 35 |
| priest_dibella | [1, 4] | 1.2 (offering - a gift, never a price) | 170 | 40 |
| innkeeper | [4, 15] | 0.8 | 420 | 140 |
| merchant | [4, 15] | 1.6 (indifferent) | 840 | 225 |
| adventurer | [4, 15] | 1.6 | 840 | 225 |
| guard | [15, 60] | 2.5 (risky) | 5250 | 1315 |
| housecarl | [60, 300] | 1.0 | 10500 | 2100 |
| court | [60, 300] | 1.0 | 10500 | 2100 |
| priest | [60, 300] | 1.0 | 10500 | 2100 |
| jarl | [300, 3000] | 1.0 | **105000** | 10500 |
| priest_mara | - | - | `not_for_sale: true` (she wants commitment; coin is an insult) | - |
| vigilant | - | - | `never: true` already - not for sale, money never mentioned | - |
| fallback | from `bands_by_wealth[wealth]` (modest -> [4,15]) | 1.6 | 840 | 225 |

`Serana` (`npc_overrides`): computed (modest band x insulting 4.0) gives 2100 / 840. Recommend
`not_for_sale: true` on taste grounds - see §5 owner actions. Not decided here.

**The owner's own yardstick checks out:** a tavern girl who barely knows the player (indifferent,
`tavern_folk`) = 110 gold = 3.1 days of wage. The owner asked for "a tavern girl at 3 days' wage".

## 1.3 The exact price computation

New function in `lib/lrg_core.php` (it needs config + profile + interest + romance, all of which live
there). Signature and return shape are the contract:

```php
/**
 * What a night with this NPC would cost, or why it cannot be bought.
 * Returns:
 *   for_sale  bool    false = the subject is off entirely (disabled, not_for_sale, never, married-refuse)
 *   free      bool    true = no price is needed (she is willing anyway); token is what she may ask as a gift
 *   gold      int     her FLOOR: the one number the LLM is given. She may name more.
 *   token     int     0 unless free: the most she may ask as a gift
 *   band      [float,float]  the band in days, after nothing (raw, for the log)
 *   days      float   the price in days after every modifier (for the log)
 *   tier      string  wealth tier the band came from
 *   infl      float   money_influence applied
 *   stance    string  the interest word used
 *   why       string  one short reason when for_sale is false
 */
function lrgPriceFor(string $npc, array $state, array $profile, array $interest): array
```

Arithmetic, in this fixed order (every step is logged, §1.9):

```
0. for_sale = paid_intimacy.enabled
              AND snapshot paidok != '0'            (absent = on, see §1.7)
              AND empty(profile['never'])
              AND empty(profile['not_for_sale'])
              AND NOT (married-to-other AND married_rule === 'refuse')
   -> for_sale = false ends it here. why = disabled|not_for_sale|never|married
1. tier   = profile['wealth'] ?? lrgWealthTier($state)        // the twin of lrgWealthWords
   band   = profile['price_band'] ?? bands_by_wealth[tier] ?? bands_by_wealth['modest']
2. word   = interest['word']                                   // lrgInterest(), unchanged
   stance[word] === 'free' -> free = true,
                              token = round5(min(band[0], token_days) * gold_per_day * mult)
                              gold  = 0 ; RETURN
   stance[word] === 'floor'   -> days = band[0]
   stance[word] === 'ceiling' -> days = band[1]
3. days *= money_influence                                     // profile['money_influence'] ?? map[gold_sway] ?? 1.6
4. if married-to-other && married_rule === 'secret' -> days *= secret_factor        (3.0)
5. days *= renown_discount[renown_sway][lrgRenownLevel($state)]                      (status lowers it)
6. if lrg_romance.gold_accepted > 0 -> days *= repeat_factor                         (0.85)
7. days  = max(days, band[0])                                  // never below her own floor; no upper clamp:
                                                               // "exorbitant" is the point
8. mult  = paid_intimacy.price_multiplier * snapshot pm        // pm absent or <= 0 reads as 1.0
   gold  = clamp(round_to_nearest(days * gold_per_day * mult, round_to), min_price, max_price)
9. profile['price_gold'] set -> gold = that number, days = gold / gold_per_day (log both, skip 2-8)
```

Design notes that must survive into the code comments:

- **Only `indifferent` and `curious` ever produce a price.** A willing NPC (score >= 0) is not for
  sale; she is interested. This is why the feature can never turn a romance into a transaction.
- The price is her **FLOOR**, not a fixed fee. She may name any higher figure in her own voice; the
  server enforces only the floor, the affordability and the player's own agreed number (§1.6).
- No upper clamp beyond `max_price` (200000 gold). A jarl at 105000 is unaffordable in practice and
  that IS the design ("very immune ... ridiculous high price"). It is refused on affordability, with
  a log line that says so, never with silence.
- The gold path is not folded into the interest score. It removes exactly one gate reason. Keeping
  them separate is what makes "she may still say no to a rude buyer" true and the log readable.

## 1.4 What the LLM is told: words, plus exactly ONE number

In `lrgStaticGuidance()` (`lrg_actions.php:2084-2111`), directly after the existing wealth/gold_sway
sentence (:2095) and only when the turn is a romantic mode (`closed|public|private|follow`) and
`for_sale` is true:

- price needed (`free === false`):
  > "What coin means here: `$npc` would not go below **`<gold>` septims** for a night with the player.
  > `$npc` may name more, and turns down less - unless `$npc` wants the player anyway. Coin is not a
  > command: `$npc` may still say no to someone rude, and no sum buys anything `$npc` will not do."
- no price needed (`free === true`):
  > "`$npc` does not need to be paid: `$npc` wants the player. If `$npc` feels like asking for a
  > gift, a few septims is a gesture, not a price."
- `for_sale === false`: **nothing at all is emitted.** Money is never mentioned to a vigilant, a
  priest of Mara, a `not_for_sale` NPC, or a married NPC under `refuse`. This is a hard rule: the
  absence of the sentence is what stops the model inventing a price for someone who has none.

Plus one purse WORD, never the number (`pgold` must not leak into the prompt):

```
purse = pgold >= gold*3 ? 'easily' : (pgold >= gold ? 'just about' : (pgold >= gold/2 ? 'not quite' : 'nowhere near'))
```
> "The player's purse: `<purse>` enough for that."

And one history clause when `lrg_romance.gold_accepted > 0`:
> "They have had this arrangement before (`<n>` times, last time `<m>` septims)."

The per-turn directive (`lrgBuildRequestDirective()`, `lrg_intent.php:744`) gains three shapes, all of
them **words only** - none of them may order her to accept, because consent before a scene is hers
alone (addendum 3a, PROTOCOL 0):

| recognised | directive |
|---|---|
| `askprice` | "The player asked what it would take. `$npc` answers in `$npc`'s own voice - name a figure at or above `<gold>` septims, or refuse the question. Choose no action this turn." |
| `offer` with `gold >= floor`, affordable | "The player just offered `<n>` septims. That is at or above what `$npc` would take. If `$npc` wants to, say so plainly AND choose BeginIntimacy with amount `<n>` - that is the only way it happens. If not, say no; nobody but `$npc` decides that." |
| `offer` below the floor, or unaffordable | "The player offered `<n>` septims. That is below what `$npc` would take / more than the player actually has. `$npc` says so plainly in `$npc`'s own words and chooses no action." |
| `haggle` | "The player thinks the price is too high. `$npc` may come down to `<gold>` septims at the very lowest, or hold firm - `$npc`'s choice. Choose no action this turn." |

## 1.5 Intent patterns (`lib/lrg_intent.php`)

Three new kinds: `offer`, `askprice`, `haggle`. New const `LRG_INTENT_MONEY = ['offer','askprice','haggle']`.

**They must NOT be added to `LRG_INTENT_ACTIONABLE`** (`lrg_intent.php:24`): the safety net
(`lrgSafetyNet()`) may never start a scene or move gold on its own. They resolve to no `kv`.

Where they run: a dedicated pre-scan `lrgIntentMoney(string $t): ?array` called from
`lrgRecogniseIntent()` **before** `lrgIntentBlocked()`, the same way `$isStop` is
(`lrg_intent.php:230`). Reason, to be written into the comment: `"how much"` and
`"what's your price"` are questions, and `lrgIntentQuestion()` (`:64`) blocks anything starting with
`what|how`, so a post-blocker placement would throw the entire feature away.

Blocker rules for the money kinds (deliberately asymmetric):

| blocker | askprice | haggle | offer |
|---|---|---|---|
| `question` | **lifted** (it IS a question) | lifted | **lifted only when a number AND a money word are both present** ("would you for a hundred septims?") |
| `negated` | applies | applies | applies ("I'm not paying you") |
| `hypothetical` | **downgraded to askprice** ("what if I gave you 500 gold" = asking, not offering) | applies | **applies** - a hypothetical never commits gold |
| `quoted` | applies | applies | applies ("you said you'd take 100") |

Patterns:

```php
// money words (shared): gold|septims?|coins?|coin|drakes?|pieces?
// 1. amount, numeral, either order - a NUMBER ALONE IS NEVER AN OFFER
'/\b(\d{1,6})\s*(?:gold|septims?|coins?|pieces?|drakes?)\b/'
'/\b(?:gold|septims?|coins?)\b\D{0,12}(\d{1,6})\b/'
// 2. amount in words: a closed table 1-20, tens to ninety, hundred, thousand, plus
//    "a/one hundred", "a couple hundred" (=200), "a few hundred" (=300), "a thousand"
// 3. bare offer, amount unnamed -> gold = 0
'/\bi(?:\'|’)?ll pay\b|\bi can pay\b|\blet me pay\b|\bpay(?:ing)? you\b|\bi(?:\'|’)?ll make it worth your while\b'
 . '|\bfor the right price\b|\bmoney is no (?:object|problem)\b|\bname it and it(?:\'|’)?s yours\b'
 . '|\bhere(?:\'|’)s (?:some )?(?:gold|coin|septims)\b|\btake my (?:gold|coin|purse)\b|\bi have gold\b/'
// 4. ask the price
'/\bhow much\b|\bwhat(?:\'|’)?s your price\b|\bname your price\b|\bwhat would it take\b'
 . '|\bhow many (?:gold|septims|coins)\b|\bwhat do you charge\b|\bfor how much\b|\byour price\b/'
// 5. haggle
'/\btoo (?:much|expensive|steep|rich|dear)\b|\bthat(?:\'|’)?s a lot\b|\bcan you do better\b'
 . '|\bmeet me (?:in the )?(?:middle|half ?way)\b|\bhalf (?:of )?that\b|\bcome down\b|\bdiscount\b|\bhaggl\w*/'
// 6. closing HER quote: only when lrg_memory.price_quoted is inside quote_ttl_seconds
'/^(?:please\s+)?(deal|done|agreed|fine|it(?:\'|’)?s yours|take it|you(?:\'|’)?ve got it|alright then)\b/'
//    -> kind=offer, gold = the quoted figure
```

Precedence and compounds:

- A money hit is the **PRIMARY** kind; an act recognised in the same utterance goes to `extra`
  (the existing `lrgIntentExtra()` machinery, `lrg_intent.php:347`). So "I'll pay you 200 to suck my
  cock" = `offer gold=200` + `extra act/oralpenis:npc`, and the existing w1 `after=` / `start_scene`
  path makes the scene begin in the requested act. No new compound code.
- `offer` with a number wins over `askprice` ("how much... fine, 200" -> offer 200).
- Confidence: `high` when an amount was parsed, when the utterance is one of the bare-offer phrases,
  or when it is `askprice`/`haggle` with an unmistakable phrase; never `high` from a number alone.
- `lrgIntentWords()` (`:627`) additions: `offer_n` -> "to pay you %d septims", `offer` -> "to pay you
  for it", `askprice` -> "to know your price", `haggle` -> "a lower price".
- `lrgIntentPlainKind()` (`:672`) additions (mode `closed` has no wording permission):
  `offer|askprice|haggle` -> "something physical, for money".

## 1.6 Gate and post-process rules (server)

**a. `lrgEvaluateGates()` (`lrg_core.php:809`)** - after `$interest` is computed (:853) and before
`$res['ok']` (:859):

```php
$res['price'] = lrgPriceFor($npc, $state, $profile, $interest);
$res['paid']  = lrgPaidOffer($npc, $res['price'], $state, $requestType);   // new, lrg_core.php
if (!$interest['willing'] && ($res['paid']['accepted'] ?? false)) {
    $res['reasons'] = array_values(array_diff($res['reasons'], ['not_close_enough']));
}
```

`lrgPaidOffer()` returns `['offer'=>int, 'source'=>'said|quote|none', 'accepted'=>bool, 'why'=>string]`:

- The offer comes from **this turn's recognised `offer`** or from `lrg_memory.paid_offer`
  (`['gold'=>n,'at'=>ts]`, TTL `offer_ttl_seconds` 180 s), whichever is larger.
- `accepted` requires ALL of: `price.for_sale`, `offer > 0`, `offer >= price.gold`,
  `pgold >= offer`, and the request type is player speech (`LRG_PLAYER_SPEECH_TYPES`).
  **Never on an `lrg_initiative` tick** - she cannot accept an offer nobody made.
- It removes **`not_close_enough` only**. `never`, `married` (refuse), `witnesses`,
  `companion_present`, `child_nearby`, `not_adult`, `combat`, `quest_scene`, `already_in_scene`,
  `feature_off`, `no_fresh_snapshot` are untouched. A jarl with a 105000-gold offer in a crowded
  hall is still `witnesses`.

**b. `lrgStartHeldBack()` (`lrg_actions.php:1095`)** - add `offer` to the kinds that open the action at
once (:1099): an offer of gold IS the build-up, exactly like an explicit request for contact. The
heat / post-scene / post-reload cooldowns stay for everything else.

**c. Post-LLM gate, `LRG_ACT_START` branch (`lrg_actions.php:1336-1373`)** - the whole safety of the
feature is in five rules. `$amount` is the integer parsed from the wire param (§1.7):

1. `$pay = min($amount, $playerOffer)` where `$playerOffer` is `lrgPaidOffer()['offer']`.
   **Gold may never exceed a number the PLAYER said out loud.** If she names 200 and the player
   never said a figure, `$playerOffer` is 0, so `$pay` is 0.
2. `$pay > 0` and `$pay < $price['gold']` and `$interest['willing'] === false`
   -> **drop the action** (`gate: dropped StartIntimacy - accepted below her own floor`).
   A willing NPC may accept anything, including a token: that is a gift, not a price.
3. `$pay > (int) $state['pgold']` -> **drop the action**
   (`gate: dropped StartIntimacy - the player cannot afford <n>`), and set
   `lrg_memory.last_result = ['ok'=>false,'reason'=>'the player does not have that much gold']` so
   her NEXT turn tells her, in character, that his purse was empty (the existing `$turn['fail']`
   path, `lrg_actions.php:994-999` / `lrg_actions.php:2253`).
4. `$price['for_sale'] === false` and `$pay > 0` -> **drop the pay, keep the action**
   (she was willing for her own reasons; no gold moves; log it). Never the reverse.
5. `$pay > 0` -> add `pay=<n>` to `$kvStart` (before `$gate['game_params']`, so the existing
   `maxwit`/`folok` keys are untouched) and, once the game confirms, remember it (§1.9).

Everything else in that branch (the w1 `scene=`/`after=` start-in-the-requested-act logic) is unchanged.

**d. Nothing on the server ever starts a scene, quotes a binding price, or moves gold by itself.**
She chooses `BeginIntimacy`; the server only bounds the number.

## 1.7 Wire (PROTOCOL -> v0.4, additive only)

| direction | key | on | meaning | absent means |
|---|---|---|---|---|
| game -> server | `paidok=0\|1` | snapshot | MCM `bPaidIntimacy` | **on** (an old script has no key; `paid_intimacy.enabled` decides) |
| game -> server | `pm=<0.25..4.00>` | snapshot | MCM `fPriceMultiplier`, two decimals, clamped game-side, `<= 0` sent as `1.00` | 1.0 |
| game -> server | `door=0\|1` | snapshot | see §3 | 0 = say nothing (old behaviour) |
| game -> server | `pgold=<n>` | snapshot | **already there** (`LRG_Profile.psc:178`) | price cannot be checked for affordability -> treat as 0 and never accept |
| server -> game | `pay=<n>` | `ExtCmdLRG_StartIntimacy` | gold to move from the player to the NPC at scene start | 0 = free |
| game -> server | `paid=<n>` | `lrg_scene` push, `ev=start` and every later push of that scene | gold that REALLY moved (game-confirmed) | 0 |

**How the LLM's AMOUNT reaches us - verified in the installed server, this is the one fact the
feature stands on:**

- `functions.php:2542` builds the wire line as
  `actor|channel|CODE@` . `$executionContext["parameter_string"]`.
- `parameter_string` comes from `buildFunctionExecutionParameter()` (`functions.php:2563`), which
  first calls `buildConfiguredActionParameterFromMetadata()` (`functions.php:137-184`).
- That function reads `metadata.parameter_template` from the catalog row and resolves it against a
  context whose `parameters` key is the decoded JSON the LLM produced - i.e.
  `{target, item, amount}` (`functions.php:154-172`).
- `herikaActionCatalogResolveTemplateString()` (`lib/core/action_catalog.php:3520-3541`) supports
  `{{path}}`: a whole-string `{{parameters.amount}}` returns the value itself, and embedded
  `{{...}}` are substituted inside a longer string.
- `metadata.parameter_template` is a first-class metadata key (`action_catalog.php:1263`).

So the BeginIntimacy row gains:

```php
'parameters_json' => ['type' => 'object', 'properties' => [
    'target' => ['type' => 'string', 'description' => 'The player'],
    'amount' => ['type' => 'integer', 'description' => 'Only when accepting an offer of gold: the number of septims the PLAYER has agreed to pay. Leave empty otherwise.'],
 ], 'required' => []],                                   // amount MUST stay out of "required"
'metadata' => $meta('target', $ownMove) + ['parameter_template' => '{{parameters.amount}}'],
```

and the post-gate parses `$cmd[1]` (the text after `@`) as: k=v containing `amount=` -> that;
else a bare integer -> that; else 0. Both readings are kept so a catalog row installed by an older
version (which sends the target name there) degrades to `amount = 0` instead of misreading a name.

`required` must stay empty: `queueFunctionExecutionCommand()` drops the whole action when a row has
required parameters and the parameter came back empty (`functions.php:2526-2529`), which would kill
every free scene.

`LRG_ACTIONS_VERSION` 9 -> **10** (`lrg_actions.php:25`): the row changed, so the catalog must be
reinstalled.

## 1.8 Game side: the transfer and its refusal

All in `LRG_OStim.psc`. New fields: `int startPayGold`, `int scenePaidGold`.

1. **`StartBlockedReason()` (`:2256`)** - a new check, AFTER `PrivacyBlockedReason` and BEFORE the
   transient `pendingVerb` check (so an unaffordable start is refused outright and never occupies the
   single queue slot):
   ```
   int pay = m.ParamGet(asParam, "pay") as int
   if pay > 0 && Game.GetPlayer().GetItemCount(Gold()) < pay
       return "the player does not have that much gold"
   ```
   `Gold()` = `Game.GetForm(0x0000000F)`, fetched once and cached in a script field.
2. **`CmdStart()` (`:1509`)** - remember it: `startPayGold = pay` right after `startParam` is stored
   (:1560). Dry run (`:1542`) logs `DRY RUN WOULD PAY <n> gold to <npc>` and moves nothing.
3. **`BeginStartThread()` (`:1659`)** - re-check and transfer in the one place where the scene is
   certainly real:
   ```
   ; after  int tid = OThreadBuilder.Start(builder)  and the tid < 0 early return (:1736-1744)
   if startPayGold > 0
       Form g = Gold()
       Actor p = Game.GetPlayer()
       if g && p.GetItemCount(g) >= startPayGold
           p.RemoveItem(g, startPayGold, false, npc)     ; abSilent = false: the owner sees the coin leave
           scenePaidGold = startPayGold
           m.LogC(asCid, "paid " + startPayGold + " gold to " + partnerName, partnerName)
       else
           scenePaidGold = 0
           m.LogC(asCid, "NOT paid: the player no longer has " + startPayGold + " gold", partnerName)
       endif
       startPayGold = 0
   endif
   ```
   The re-check window is milliseconds. A scene that has already started is never cancelled over
   gold: it runs, `paid=0` is reported, and the server's halved gain simply does not apply. That is
   the safe direction (a scene the player got for free), and it is logged.
4. **`PushState()` (`:3907`)** - emit `paid=<scenePaidGold>` whenever it is `> 0`, on every push of
   that scene, so `ev=end` finds it in `$prev` regardless of which pushes were dropped.
5. **`ResetState()` (`:432`)** - `scenePaidGold = 0` and `startPayGold = 0`.
6. **No refund, ever.** The owner's rule: "gives nothing back if the player stops early." There is
   no code to write for this - only a comment saying so, so nobody adds one later.

Refusal reason string: `"the player does not have that much gold"`. It travels through
`ReportResult` -> `lrgRecordResult()` (`lrg_actions.php:605`) -> `lrg_memory.last_result` -> the
existing one-shot `$turn['fail']` sentence. Add one mapping in `lrgVolatileGuidance()` so it reads
right in her mouth: when the fail text contains `gold`, use
> "What `$npc` agreed to did not happen: the player could not actually pay. `$npc` says what `$npc`
> thinks of that, in a few plain words, and nothing begins."

## 1.9 Halved relationship gain, memory, log fields

**Halved gain** - `lrgAwardSceneAffinity()` (`lrg_actions.php:513`), after `$gain` is chosen (:530):

```php
$paid = (int) ($prev['paid'] ?? 0);
if ($paid > 0) {
    $gain = max(1, (int) floor($gain * (float) ($cfg['paid_gain_factor'] ?? 0.5)));
    lrgLog("relationship: the scene was paid for ($paid gold) - the gain is halved to $gain");
}
```
So a first paid scene gives +4 instead of +8, a later one +2 instead of +4. `max(1, ...)` keeps it
from vanishing. The guards are unchanged (60 s, ended `finished|stopped`, one per 1800 s window), so
a paid start that was cancelled still gives nothing.

**Counters** - in `lrgHandleSceneMessage()` at `ev=end`, inside the existing `if ($wasOpen)` block
(`lrg_actions.php:341-352`), next to the `scenes` bump:
```php
$paid = (int) ($kv['paid'] ?? ($prev['paid'] ?? 0));
if ($paid > 0 && lrgSceneCounted($npc, $kv, $prev)) { lrgRomanceBump($npc, 'gold_accepted', $paid); }
```
(`gold_accepted` is already whitelisted in `lrgRomanceBump`, `lrg_core.php:397`. It now holds the
total gold, which is exactly what `repeat_factor` and the history clause need.)

**Memory (glue)** - `lrg_memory` payload keys, all new, all via `lrgMemSet()`:

| key | written | read | TTL |
|---|---|---|---|
| `paid_offer` `{gold, at}` | on a recognised `offer` (highest so far this conversation) | `lrgPaidOffer()` | `offer_ttl_seconds` 180 |
| `price_quoted` `{gold, at}` | when the directive gave her the floor on an `askprice`/`haggle` turn | the "deal" pattern (§1.5 #6) | `quote_ttl_seconds` 300 |
| `paid` `{gold, at, times}` | at `ev=start` when `paid>0` | the history clause, `repeat_factor`, the outro | none (it is history) |

Both `paid_offer` and `price_quoted` are cleared at `ev=start` and `ev=end` alongside the existing
keys (`lrg_actions.php:334`, `:338`) - an offer is spent when the scene begins.

**Memory (CHIM, so she can refer to it later)** - in `lrgHandleSceneMessage()` at `ev=start`, where
the existing intimate-moment line is written (`lrg_actions.php:354-360`), when `paid > 0` use a line
that names the arrangement instead of the romantic one:
> "`$npc` and `$player` came to an arrangement: `$paid` septims, and they went to bed on it."

and add `'paid' => $paid` to the outro ticket (`lrgWriteOutroTicket()`, `:550-564`) plus one fact line
in `lrgOutroGuidance()` (`:2196-2226`):
> "The player paid `$npc` `<n>` septims for this."
so the goodbye can be about what it really was.

**Log fields.** One new segment on the fixed G6 `turn` line (additive, at the end, exactly like the
existing `outro=` and `also=` tails, `lrg_actions.php:1223-1228`) - emitted only when
`for_sale || offer > 0`:

```
paid=floor:110/band:1-4d/tier:poor/infl:0.8/stance:curious/quoted:150/offer:200/purse:easily/decision:accepted
```
`decision` is one of `n_a | needed | accepted | below_floor | unaffordable | not_for_sale | free`.

Plus one dedicated line (prefix `price`, a new prefix, documented in PROTOCOL 9) whenever a price is
computed for a turn that mentions money, gated by `paid_intimacy.log_prices`:
```
price npc=Lisette tier=poor band=1-4d infl=0.8 stance=curious days=3.2 floor=110 renown=x0.75 repeat=x1.0 mult=1.00 pgold=430 purse=easily
```
and the gate line already carries the result for free, because `pay=` is inside the emitted kv:
```
gate: StartIntimacy passed -> ok=1;cid=...;npc=Lisette;scene=...;undress=1;...;pay=110
```

## 1.10 MCM keys

In the single table in §4. Two keys for this feature: `bPaidIntimacy:Intimacy` (default ON) and
`fPriceMultiplier:Intimacy` (default 1.0).

**The missing-key trap, and how each of the two avoids it.** A missing ini key reads as 0/FALSE:
- `bPaidIntimacy` would read 0 = OFF, which contradicts "default on". Mitigation: the ini default
  line ships (§4), AND the snapshot sends `paidok=` only as `0` or `1` from the real setting, AND
  **the server treats an ABSENT `paidok` as ON** (an old game script) while an explicit `paidok=0`
  is OFF. Written out: absent != 0.
- `fPriceMultiplier` would read 0.0 and multiply every price to the minimum. Mitigation: the game
  clamps before sending - `pm <= 0 -> 1.00`, then clamp to `[0.25, 4.00]` - and the server treats
  absent or `<= 0` as 1.0. Two independent guards, because this one silently makes everyone free.

## 1.11 Test cases

**`tools/test_gates.php`** (rules asserted, never example lines; `snap()` + `TEST_ENABLED` already
exist). All of these run with `wit=0`, `witkid=0`, a fresh snapshot and the two warm-up exchanges the
file already does for the heat gate:

| # | case | snapshot / profile | asserted |
|---|---|---|---|
| 1 | tavern girl, barely knows him | `fac=JobBardFaction`, `gold=40`, affinity such that `interest=indifferent`, `pgold=500` | `price.for_sale`, `price.gold` in 105..115 (3 days of wage), `free===false`; an offer of 110 removes `not_close_enough` and NOTHING else from `reasons`; an offer of 50 does not; mode goes `closed -> private` with the offer |
| 2 | merchant, curious | `fac=JobMerchantFaction`, `pgold=1000` | `price.gold === 225`; accepted at 225, dropped at 220 (`below_floor`) |
| 3 | housecarl, indifferent | `fac=JobHousecarlFaction`, `pgold=300` | `price.gold === 10500`; `purse === 'nowhere near'`; an offer of 300 is `below_floor`; a (hallucinated) `amount=10500` with `pgold=300` is dropped `unaffordable` and writes the `last_result` reason |
| 4 | jarl | `fac=JobJarlFaction`, `pgold=100000` | `price.gold === 105000` (indifferent) / `10500` (curious); `for_sale === true` (everyone has a price); with `wit=2` the offer changes nothing - `reasons` still contains `witnesses` |
| 5 | married innkeeper, `married_rule: secret` | `fac=JobInnkeeperFaction`, `married=1`, `pspouse=0` | `price.gold === 3 x` the unmarried figure (1260); `soft` still contains `married_secret`; `game_params.maxwit === 0`, `folok === 0` |
| 6 | vigilant | `fac=VigilantOfStendarrFaction` | `for_sale === false`, `why === 'never'`; `lrgStaticGuidance()` output contains no money sentence at all (assert the absence of `septims`); an offer of 100000 leaves `reasons` containing `never` |
| 7 | interested NPC who wants no money | any profile, `interest.willing === true` | `free === true`, `gold === 0`, `token <= 20`; an `amount=200` acceptance PASSES (a gift) if affordable; `paid_gain_factor` still halves the gain when gold really moved |
| 8 | player cannot afford anything | `pgold=10`, tavern girl indifferent | no acceptance passes; `decision === 'unaffordable'`; the wire carries no `pay=`; `last_result` reason is the gold one |
| 9 | Dragonborn discount | case 1 + `pdb=1` (`renown_sway: strong`) | `price.gold === 85` (x0.75), and case 1's own number is unchanged with `pdb=0` |
| 10 | repeat customer | case 1 + `lrg_romance.gold_accepted = 110` | `price.gold === 95` (x0.85 on 110 -> 93.5 -> 95) |
| 11 | multiplier guards | `pm` absent; `pm=0`; `pm=2.00` | absent and 0 both give the case-1 number; 2.00 doubles it |
| 12 | no gold path on her own move | case 1, request type `lrg_initiative`, `paid_offer` in memory | `accepted === false` (`why === 'not_player_speech'`); `not_close_enough` stays |

**`tools/test_intent.php` / `tools/test_phrases.php`** (the phrase matrix; MUST tier for the first
eleven, because each is a rail):

| say | kind | gold | note |
|---|---|---|---|
| "how much for a night?" | askprice | - | question blocker lifted |
| "name your price" | askprice | - | |
| "what would it take?" | askprice | - | |
| "I'll pay you 200 septims" | offer | 200 | |
| "here's fifty gold" | offer | 50 | number word |
| "I've got a thousand septims" | offer | 1000 | |
| "would you for a hundred septims?" | offer | 100 | question + amount + money word |
| "what if I gave you 500 gold" | askprice | - | hypothetical never commits gold |
| "you said you'd take 100" | none | - | `quoted` |
| "I'm not paying you" | none | - | `negated` |
| "fifty" | none | - | a number alone is never an offer |
| "that's too much" | haggle | - | |
| "come on, meet me in the middle" | haggle | - | |
| "deal" (with `price_quoted` 150 in memory) | offer | 150 | closing her quote |
| "deal" (no quote in memory) | none | - | nothing to close |
| "take my gold" | offer | 0 | amount unnamed |
| "money is no object" | offer | 0 | |
| "I'll pay you 200 to suck my cock" | offer | 200 | `extra` = act `oralpenis:npc` |
| "I'll pay for the room, not for you" | offer | 0 | acceptable miss; must not resolve to an act |

---

# 2. "Sometimes at the end of the sex scene it leaves you in the OStim director mode"

## 2.1 What the owner means, and the proof of what happened

The owner uses "director mode" for the state where his voice goes to CHIM's **Narrator** instead of
the NPC, and/or the camera / controls are not his. **The narrator half is confirmed, with the
player's verbatim words, three times in tonight's session (playtest 8, v0.3.1 installed).**

Glue log (`/var/www/html/HerikaServer/log/lorerim_glue.log`, local -04:00):

```
21:59:20 [cid=sfc1a1b7a] GAME outro hold: Lisette stays with you (Finished, the scene ran 105 s, up to 25 s)
21:59:20 [cid=sfc1a1b7a] GAME listener released (the scene ended)
21:59:22 [cid=sfc1a1b7a] GAME outro line requested from Lisette
21:59:22 [cid=s86dc766a] turn npc=Lisette type=lrg_scenetalk mode=outro ... outro=dur:105s/finished/acts:oralvulva+vaginal/first:no
21:59:39 [cid=sfc1a1b7a] GAME outro released (she has said her piece) held=17.9
22:01:19 [cid=s5939bf58] llm npc=The Narrator action=none item="" spoke=62
22:02:01 [cid=sf8e13d78] llm npc=The Narrator action=none item="" spoke=110
22:03:03 [cid=sdf07108d] turn npc=Lisette type=inputtext mode=public ...
```

`chim.log` (+02:00, so 04:01 = 22:01 local) - the same two turns, with the request type and what he said:

```
[2026-09-22T04:01:17+02:00] [info] Audit:Lock acquired by narrator_inputtext
[2026-09-22T04:01:17+02:00] [info] Scoped CACHE_PEOPLE for WHISPER narrator_inputtext: |Jordan|The Narrator|
[2026-09-22T04:01:17+02:00] [info] Using DataSearchMemoryByVector Jordan: Oh, Liz uh, I want to do some inappropriate shit to you.
[2026-09-22T04:01:54+02:00] [info] Audit:Lock acquired by narrator_inputtext
[2026-09-22T04:01:54+02:00] [info] Using DataSearchMemoryByVector Jordan: I do want to make a request, Lizette, you and me somewhere private now.
```

and one more, 51 s before a scene ever started that evening:

```
[2026-09-22T03:48:47+02:00] [info] Audit:Lock acquired by narrator_inputtext
[2026-09-22T03:48:47+02:00] [info] Using DataSearchMemoryByVector Jordan: Hey Lazette, follow me.
```

All three lines NAME HER (Whisper heard "Lazette" / "Liz uh" / "Lizette"). All three were answered by
The Narrator. Two of the three are inside the 2.5 minutes after the scene ended. A fourth trace of
the same thing is still in the database: `conf_opts.chim_whisper_target = 'The Narrator'`, written
at `chim_whisper_updated = 1790043449` = 22:17:29 local, six seconds before the session's last line
to Lisette (`processor/comm.php:764-767` writes it from the player's `_speech` listener).

The same pattern exists in playtest 7 in a milder form (no outro existed then):
`17:14:08 GAME listener released (the scene ended)` -> the next player line at 17:16:24 landed on a
turn with `mode=silent reasons=no_fresh_snapshot` and `say=""`; `17:19:30 GAME listener released` ->
the next line ("go again", 17:19:45) did reach Lisette. "Sometimes" is exactly right.

## 2.2 The mechanism, step by step

1. `LRG_OStim.FinishThread()` (`LRG_OStim.psc:3618`) runs `BeginOutroHold(...)` (:3650) and then,
   on the very next line, `ReleaseListener("the scene ended")` (:3651).
2. `ReleaseListener()` (:706-716) calls `AIAgentFunctions.setDrivenByAI()` **with no argument**.
   That is CHIM's own "nothing is under the crosshair" call (`AIAgentPapyrusFunctions.psc:474` and
   `:840` use it in exactly that situation).
3. From that instant CHIM's DLL router decides who the player is talking to, per utterance. Its
   reason strings are `explicit_narrator_mode, explicit_narrator_name, explicit_ui_target,
   explicit_npc_name, true_crosshair, narrator_camera_gesture, bare_hey_fov, nearest_eligible,
   no_eligible_npc` (DLL@0x30a728-0x30a7d8, recorded in `research/p2-chim-interplay.md` V.3-7).
   A line that hits `no_eligible_npc` becomes `narrator_inputtext`.
4. Right after an OStim scene the crosshair is the worst case there is: the camera has just been
   handed back wherever OStim left it, she is usually beside or behind the player, and for the
   whole outro hold she is standing still with `SetDontMove(true)` while the player is the one who
   turns away. `Game.GetCurrentCrosshairRef()` is then empty and the router falls through.
   `p2-chim-interplay.md` has the same failure from an earlier session with the crosshair logged:
   "four voice lines clearly meant for Lisette were answered by The Narrator because
   `crosshair=00000000 ... reason=no_eligible_npc`".
5. The glue then goes silent on top of it, which is why it feels like a mode and not a hiccup:
   `narrator_inputtext` is in `LRG_NARRATOR_SPEECH_TYPES`, not in `LRG_PLAYER_SPEECH_TYPES`
   (`lrg_core.php:25-27`), so `lrgEvaluateGates()` answers `not_player_speech` (:815) -> mode
   `silent` -> no gate, no directive, no action. The player is talking to a narrator with none of
   our actions, about a scene that just happened, and nothing he says can reach her.
6. The outro hold makes this MORE likely, not less: it holds HER still for up to 25 s
   (`fOutroHold`) while the listener has already been given away, so the entire goodbye window is
   played with CHIM's routing free. In tonight's log the release at 21:59:20 and the outro release
   at 21:59:39 are 19 s apart, and the first Narrator line is 1 min 40 s later.

The camera/controls half: **the glue never touches the camera or the player's controls** - verified,
`grep -in "camera|freecam|PlayerControls|ForceThirdPerson|GetCameraState|SetPlayerAIDriven"` over
all five `.psc` files returns nothing. OStim restores its own camera and UI at thread end. So there
is no glue bug to fix there, only a defect to catch: §2.3 F3 is a self-repair that fires only when
the camera really is still OStim's.

## 2.3 The exact fix

**F1 (the fix). Never hand the listener back at the end of a scene. Hold it on the partner, with a
bounded, self-healing clock.** `LRG_OStim.psc` only; no server change, no wire change.

New fields: `Actor listenerActor`, `string listenerName`, `float listenerUntil`.

1. Refactor `ForceListener(why)` (:688) into `ForceListenerOn(Actor akWho, string asName, string asWhy)`
   and keep `ForceListener(why)` as `ForceListenerOn(partner, partnerName, why)`. **Reason it has to
   be refactored:** `ForceListener` returns early when `partner == None` (:693), and
   `FinishThread -> ResetState` (:3663) clears `partner`/`partnerName` a few lines later - so after a
   scene there is nothing left to force. `BeginOutroHold` already keeps its own `outroActor` /
   `outroName` for exactly this reason (:747-750, :782-783).
2. In `FinishThread()`, replace line :3651 with:
   ```
   ; the listener is the LAST thing to go, not the first: the goodbye and the next minute of
   ; conversation still belong to her (see pt8: three narrator_inputtext turns in 13 minutes)
   Actor who = partner
   string nm = partnerName
   if outroActive && outroActor != None
       who = outroActor
       nm = outroName
   endif
   float hold = ListenerHoldSeconds()          ; fListenerHold:SceneTalk, 0..60, default 20
   if hold > 0.0 && who != None && nm != ""
       listenerActor = who
       listenerName = nm
       listenerUntil = endAt + hold + OutroHoldSeconds()   ; the outro window is ON TOP of the hold
       ForceListenerOn(who, nm, "the scene ended - she is still the one you are talking to")
       m.RequestTick(1.0)
   else
       ReleaseListener("the scene ended")
   endif
   ```
   `ResetState()` (:432) must then **not** release it either: change its `ReleaseListener(...)` call
   (:433) to release only when `listenerUntil <= 0.0`, and document why (FinishThread calls
   ResetState two lines after arming the hold - the same ordering trap the outro hold already has a
   comment about at :450-452).
3. New `TickListener(float afNow)`, called from `Tick()` (:3700) immediately after the `TickOutro`
   block, and cheap when `listenerUntil <= 0.0`. It releases through the existing
   `ReleaseListener()` on ANY of:

   | exit | test | why |
   |---|---|---|
   | time | `afNow >= listenerUntil` | the ordinary end |
   | she spoke her goodbye and the player left it there | `!outroActive && OutroLineState()` was 1 and `afNow >= outroDoneAt + fListenerGrace` (2.5 s) | the owner's "until she has spoken the outro and the player has left" |
   | still talking | extend, do not release: `AIAgentFunctions.isActorTalking(listenerName) != 0` or `< 4 s` since her last sentence stopped -> `listenerUntil = afNow + 5.0` | same rule `TickOutro` already uses (:947-967) so the hold is never cut across her |
   | the player keeps talking to her | `NoteActivity()` / `NotePlayerSpeech()` push `listenerUntil` out by `fListenerHold` again | the conversation is alive: that is the whole point |
   | the player turned to somebody else | `Game.GetCurrentCrosshairRef() as Actor` is a non-None actor, not `listenerActor`, within 400 units | never steal another NPC's conversation |
   | she is gone | `IsDead \|\| IsDisabled \|\| !Is3DLoaded \|\| IsUnconscious` | |
   | distance | `listenerActor.GetDistance(player) > fOutroFar` (reuse the existing key, default 1500) | |
   | another cell | both-or-either interior and `GetParentCell()` differ (the same guarded test as :941-946) | |
   | combat / player dead | `IsInCombat` either side, `player.IsDead()` | |
   | a new scene | `starting \|\| pendingStart \|\| OThread.IsRunning(0)` - `OnOStimThreadStart` (:3422) and `CmdStart` (:1513) already release the outro hold; add the listener hold to both | |
   | switches | `!m.IsEnabled() \|\| !m.IsIntimacyEnabled() \|\| !m.SettingBool("bForceListener:Intimacy") \|\| hold == 0` | |
   | stop hotkey | `StopScene()` (:3396) already calls `ReleaseListener` (:3409) - it must clear `listenerUntil` too | |
   | self-heal | `afNow < listenerUntil - (2 * hold + OutroHoldSeconds() + 30)` or a hold that only the save remembers (`listenerForced && listenerUntil <= 0`) | the real-time clock restarts at 0 on every game launch; same shape as `TickOutro`'s self-heal (:905-908) |

   Worst case the listener stays forced for `2 x fListenerHold + fOutroHold + 30 s` = 95 s at the
   defaults, and the existing 60 s re-assert in `ThreadTick` (:3803) is NOT used for it (that path
   only runs while a thread runs).
4. **Face her once, at the end.** In `FinishThread`, next to the hold, `player.SetLookAt(who, false)`
   is wrong (it would fight CHIM, which owns the look-at - see the comment at :726-727). Instead do
   the thing that actually helps the router: nothing in Papyrus can move the player's camera onto
   her without taking control away from him. The hold in F1 is what makes the facing irrelevant, and
   that is the point of choosing it over a camera nudge.

**F2. Tell the owner when a line went to the Narrator, instead of going silent.** Server-side, two
lines, no behaviour change: in `lrgPrepareTurn()`, when the request type is in
`LRG_NARRATOR_SPEECH_TYPES` and a scene with any NPC ended within the last 120 s (the scene row's
`last_scene_end_at` in `lrg_memory`), write
```
WARN the player's line went to the Narrator <n>s after a scene with <npc> ended (CHIM found no NPC under the crosshair)
```
This is how the owner will know the fix worked, and it costs nothing when it never happens.
It must stay a `WARN` line: the G6 prefixes (`turn` / `llm` / `net`) are contract (PROTOCOL 9).

**F3. Camera / controls self-repair, bounded and evidence-gated.** In `LRG_OStim.Tick()`, once, in the
10 s after `sceneEndedAt`, and only when every one of these holds:
`Game.GetPlayer().GetCurrentScene() == None`, `!UI.IsMenuOpen("Dialogue Menu")`,
`!UI.IsMenuOpen("Console")`, `!OThread.IsRunning(0)`, `!starting`:
```
int cam = Game.GetCameraState()                      ; 3 = free (verified in Game.psc:370-390)
bool noCtl = !Game.IsMovementControlsEnabled() || !Game.IsLookingControlsEnabled()
if cam == 3 || noCtl
    Game.EnablePlayerControls()                      ; Game.psc:32
    if cam == 3
        Game.ForceThirdPerson()                      ; Game.psc:93 - the only reliable way out of free camera
    endif
    m.LogC(cid, "controls repaired after the scene (camera state " + cam + ", movement " + ...)", nm)
endif
```
All four natives are in the installed `Game.psc` (`IsMovementControlsEnabled` :167,
`IsLookingControlsEnabled` :161, `EnablePlayerControls` :32, `ForceThirdPerson` :93,
`GetCameraState` :389 with the state table at :370-388). It fires **only** when the camera really is
still free or the controls really are off, so it can never take a legitimate cutscene away from the
player, and it logs every time it fires - if it never fires, the camera half of the complaint was
the narrator half all along. Behind `bFixControlsAfterScene:SceneTalk`, default ON.

**F4 (owner setting, not code).** Saying her name already makes routing deterministic
(`explicit_npc_name`), but Whisper mangled it every single time tonight ("Lazette", "Liz uh",
"Lizette"). See §5: CHIM's pronunciation/alias list is the owner's lever, and the manual-activate
hotkey is the instant escape.

**Not recommended:** re-asserting the listener forever, or forcing it outside a scene window. The
`setDrivenByAIA` path is documented in `research/chim-papyrus-plumbing.md:313` as a **toggle** whose
DLL twin prints "is now active" / "was already active. Removing from CHIM." Tonight's log shows the
existing re-assert being safe in practice (21:53:54, 21:57:31, 21:58:40, and she answered every time
after), but a permanent hold multiplies the number of calls for no gain. A bounded hold keeps the
call count where it is today.

---

# 3. Privacy: a closed door is private, and nobody hears through walls

## 3.1 The proof

Playtest 8, the owner in an inn back room / bathing area, saying out loud that they are alone:

```
22:03:03 turn npc=Lisette mode=public say="laz uh you can't believe the horny spell that i'm under for ya come on let's do something about it"
22:03:03 gate npc=Lisette mode=public offer_start=no reasons=witnesses aff=8 interest=drawn(38)
22:04:57 turn npc=Lisette mode=public say="do you think it's private enough here for us to do something lazat?"
22:05:12 turn npc=Lisette mode=public say="come with me into one of the back rooms"
22:06:35 turn npc=Lisette mode=public say="it's just me and you here lazat since you're bathing i assume it makes sense to take your clothes off"
22:06:35 gate npc=Lisette mode=public offer_start=no reasons=witnesses
22:07:22 gate npc=Lisette mode=public offer_start=no reasons=witnesses
22:07:36 gate npc=Lisette mode=closed offer_start=no reasons=quest_scene,witnesses
22:08:01 gate npc=Lisette mode=public offer_start=no reasons=witnesses
22:10:11 turn npc=Lisette mode=follow say="finally just me and you alone show me what you look like without those robes on"
```

`interest=drawn(38)` the whole time - she was willing, wanted it, and said so. Seven minutes of
`reasons=witnesses` in a room the owner believed was private, ending only when he walked further
away. The same thing in playtest 7 at 21:51:01 ("hey lizette can we talk in private somewhere?" ->
`reasons=quest_scene,witnesses`).

## 3.2 The mechanism

`LRG_Profile.WitnessScan()` (`LRG_Profile.psc:133-166`), the one and only witness counter (it is also
re-run game-side by `PrivacyBlockedReason`, `LRG_OStim.psc:2234-2254`, with the same code):

```papyrus
if d <= afRadius                                    ; 1200 interior / 2500 exterior, MCM
    if !a.IsDead() && !a.IsUnconscious() && a.HasKeywordString("ActorTypeNPC")
        ...
        elseif a.GetSleepState() < 3
            if a.IsPlayerTeammate()
                fol += 1
            elseif d < 600.0 || a.HasLOS(akPlayer) || a.HasLOS(akNpc)      ; <-- line 158
                wit += 1
```

`d < 600.0` counts **anybody within 600 units with no line-of-sight test at all** - through a closed
door, through a floor, through a stone wall. 600 units is about 8.5 m: in the Winking Skeever the
common room is well inside that from a back room, and the radius that gates the whole loop is 1200.
So in a busy inn the count can never reach 0 while the building is occupied, whatever door is shut.

That single clause is the whole bug. `HasLOS` is a real engine line-of-sight test and already fails
through a closed door, so the LOS half of the condition was right all along.

## 3.3 The smallest reliable change (smallest first)

**P1 - game, one boolean, no new scan cost in the common case.** New global in `LRG_Profile.psc`:

```papyrus
int Function DoorState(Actor akPlayer, float afRadius) Global
    {1 = the player is in an interior and every door within afRadius is CLOSED (or there is none in
     reach but the cell is an interior with no open door near); 0 = an open door is in reach, or this
     is not an interior. GetOpenState: 0 none, 1 open, 2 opening, 3 closed, 4 closing.}
```
Implementation: `PO3_SKSEFunctions.FindAllReferencesOfFormType(akPlayer, 29, afRadius)` - form type
**29 = doors, confirmed against two installed call sites**
(`Biggie Traits/.../Traits_HomeownerVisitHouseScript.psc:9` uses `FindAllReferencesOfFormType(akTarget, 29, 1000)`
for `allDoors`; `CHIM/.../AIAgentAIMind.psc:2798` uses 29 for `doors`) - loop capped at 12 refs,
`GetOpenState()` on each (`ObjectReference.psc:347`),
`GetOpenState() == 1 || == 2` on any of them -> return 0. Skip load doors? No: a load door counts,
because a closed load door is the most private door there is.
Cost: one native call plus up to 12 cheap ones, and it is cached exactly like the furniture scan
(`FurnitureFacts`, `LRG_OStim.psc:1409-1435`: same cell, player moved < 500 units, < 60 s old).

**P2 - game, the actual behaviour change, three lines in `WitnessScan`.** Pass the door fact in and
drop the no-LOS clause behind a closed door:

```papyrus
string Function WitnessScan(Actor akNpc, Actor akPlayer, float afRadius, bool abDoorShut = false, float afHearRadius = 600.0) Global
    ...
        elseif a.GetSleepState() < 3
            if a.IsPlayerTeammate()
                fol += 1
            elseif a.HasLOS(akPlayer) || a.HasLOS(akNpc)
                wit += 1                                  ; seen: always a witness, door or no door
            elseif !abDoorShut && d < afHearRadius
                wit += 1                                  ; heard: only when no closed door is between
            endif
```
Defaults keep every existing caller correct: `abDoorShut = false` and `afHearRadius = 600.0` reproduce
today's behaviour exactly, so an un-updated call site cannot get more permissive by accident.

**P3 - game, the snapshot.** `BuildSnapshot` (`LRG_Profile.psc:305`) gains `door=<0|1>` right after
the existing `interior=` key (:348), and `WitnessScan` is called with the new arguments (:349).
`LRG_Main.PlaceFacts` caches `DoorState` per cell/position exactly like the furniture facts.
`PrivacyBlockedReason` (`LRG_OStim.psc:2234`) must compute the same two arguments, or the game-side
re-check would refuse what the server allowed.

**P4 - server, words only.** `lrg_core.php`:
```php
/** One plain clause about where they are, or '' - only from snapshot facts. */
function lrgPrivacyWords(array $state): string   // "they are behind a closed door" |
                                                 // "they are alone in a closed room, and the door is shut"
```
Used in the `private` / `follow` branch of `lrgVolatileGuidance()` (`lrg_actions.php:2292-2305`) and
in `lrgPlaces()`'s `quiet` wording (`lrg_core.php:908`), so the LLM knows the room is shut and stops
arguing about it. No gate logic changes on the server at all: the witness count is corrected at its
source, which keeps `wit=` one number with one meaning.
Add `door=` to the `gate` log line's component list so the next forensic pass can see it.

**P5 - server, config (no code):**
```jsonc
"privacy_defaults": { "max_witnesses": 0, "followers_ok": true,
  "_door_readme": "[0.4] A closed door makes a room private: an NPC with no line of sight to either of you no longer counts as a witness just for being within the hearing radius. Seen is still seen - HasLOS ignores the door state, so an open doorway still counts." }
```

**What is deliberately NOT built:** a per-actor "is there a door between them and us" test. It cannot
be done reliably in Papyrus (no ray cast to an arbitrary actor), and the room-level boolean gets the
owner's case right - a shut room is private, an open one is not - with one scan instead of one per
actor. This is stated so nobody "improves" it later into 40 door scans per snapshot.

## 3.4 CHIM's own group hearing (who gets the player's line)

Read from the installed server, not assumed:

- `conf_opts.chim_mode = 'WHISPER'` (live value, read tonight). In WHISPER mode every player-speech
  request is scoped to the player and the target ONLY: `main.php:1801-1806` calls
  `buildWhisperPrivatePeople()` -> `buildPrivateConversationPeople()`
  (`lib/chat_helper_functions.php:3389-3392`) and overwrites `CACHE_PEOPLE`. Tonight's log proves it:
  every one of the 20 player turns logged `Scoped CACHE_PEOPLE for WHISPER inputtext: |Jordan|Lisette|`.
  **So no other NPC "hears" the player's line today. That half of the complaint is already solved by
  the owner's own CHIM mode.** The NPC's reply is also tagged `(whispering to <name>)`
  (`chat_helper_functions.php:3418`).
- What DOES come through walls is the `<nearby_actors>` prompt block - 6135 to 7655 characters of it
  in tonight's three narrator requests (`[PROMPT-COMPOSITION]` lines in `chim.log`). It is built from
  `$actorsInRange` (`lib/data_functions.php:1321`), which comes from the DLL's `infonpc` /
  `infonpc_close` event rows (`DataBeingsInRange()` :4476, `DataBeingsInCloseRange()` :4622) and has
  no door or LOS test whatsoever. That is what makes an NPC behind a wall "present" in her head.
- The owner's levers for it (both owner settings, both listed in §5):
  `_auto_hearing_radius_m` / `_player_auto_include_radius_m` - CHIM MCM -> Auto Activate -> Hearing,
  "Auto Hearing Radius ... Direct auto-hearing radius in meters", slider 1-20, default 8
  (`AIAgentMCMConfigScript.psc:116-117, 526-535, 841`; neither key is in `conf_opts` today, so both
  are at their defaults); and the `<nearby_actors>` section itself, which CHIM's own settings page can
  switch off per profile (`lib/settings.php:609-610`, `lib/prompt_composition.php:39-42`).
- **Per round decision D2, the glue writes no CHIM setting.** It could narrow `CACHE_PEOPLE` on its
  own turns from our `context_pre` hook, but that would change CHIM's context for the turn and is
  out of this lane's scope; it is recorded here as an option with its risk, not as a design.

---

# 4. Every MCM key this report proposes (the builder hands them over)

The builder cannot edit `config.json` this round. All eight rows, ready to paste.
**Every one also needs its default line in `MCM/Config/LoreRimGlue/settings.ini`, because a missing
key reads as 0/FALSE** - the `[Intimacy]` and `[SceneTalk]` sections are named in the last column.

| id | page | text | help | type | range / options | default | ini line |
|---|---|---|---|---|---|---|---|
| `bPaidIntimacy:Intimacy` | Intimacy (new header "Paying for it") | Let NPCs be paid for intimacy | Some people can be bought and some cannot, and everybody has a price. An NPC who already wants you is never for sale. What it would take depends on who they are, how poor they are, how much coin means to them, and what you are to them - a tavern girl is a few days' wage, a jarl is a fortune nobody could pay. The gold really leaves your purse when the scene starts, and you get nothing back if you stop early. Off = money is never mentioned and no gold ever moves. | toggle | - | **1 (on)** | `[Intimacy] bPaidIntimacy = 1` |
| `fPriceMultiplier:Intimacy` | Intimacy | Price multiplier | Scales every price at once. 1.0 is the built-in economy (about 35 gold for a day's wage, the way Skyrim pays). Lower it if you want people cheaper, raise it if gold is easy for you and you want it to mean something. | slider | 0.25 - 4.0, step 0.25, `{1}x` | **1.0** | `[Intimacy] fPriceMultiplier = 1.0` |
| `bPrivacyDoors:Intimacy` | Intimacy (Privacy header) | A closed door makes a room private | People who cannot see either of you no longer count just for being nearby, as long as the door of the room is shut. Anyone who can actually see you always counts, door or no door. Off = the old behaviour, where a busy inn was never private even in a room with the door closed. | toggle | - | **1 (on)** | `[Intimacy] bPrivacyDoors = 1` |
| `fHearRadius:Intimacy` | Intimacy | Heard-through-the-room distance | How close somebody has to be to count as a witness when they cannot see you and no door is shut between you. Lower it if scenes are blocked in places that feel private; raise it if people walk in on you. | slider | 200 - 1200, step 50 | **600** (today's hard-coded value) | `[Intimacy] fHearRadius = 600` |
| `fDoorRadius:Intimacy` | Intimacy | How near a door counts as this room's door | Doors inside this distance decide whether the room you are in is shut. | slider | 100 - 600, step 25 | **250** | `[Intimacy] fDoorRadius = 250` |
| `fListenerHold:SceneTalk` | Scene talk (After a scene header) | Keep talking to her after a scene for | When a scene ends, what you say next is meant for her - but the game has nobody under your crosshair at that moment, so CHIM can answer as the Narrator instead, and she never hears you. With this on she stays the one you are talking to for this long after the scene (and for as long as the conversation keeps going), then normal routing comes back. 0 = off. | slider | 0 - 60, step 5, `{0} s` | **20** | `[SceneTalk] fListenerHold = 20` |
| `fListenerGrace:SceneTalk` | Scene talk | Let go after her goodbye | Once she has finished her goodbye and you have said nothing more, this is how long the game waits before handing the conversation back to whoever you are looking at. | slider | 0 - 10, step 0.5, `{1} s` | **2.5** | `[SceneTalk] fListenerGrace = 2.5` |
| `bFixControlsAfterScene:SceneTalk` | Scene talk | Give me my camera back after a scene | If a scene ever leaves you in OStim's free camera, or without movement or look controls, the game puts them back a moment after the scene ends. It only ever does this when they really are still gone, and it writes a line in the log when it happens. | toggle | - | **1 (on)** | `[SceneTalk] bFixControlsAfterScene = 1` |

Reused existing keys (no new rows): `fOutroFar:SceneTalk` (the listener hold's distance exit),
`bForceListener:Intimacy` (master switch for the whole listener mechanism, including the new hold),
`fWitnessRadiusInterior/Exterior:Intimacy` (the outer loop of the witness scan, unchanged).

---

# 5. What the owner must set or decide by hand

1. **CHIM MCM -> Auto Activate -> Hearing -> "Auto Hearing Radius"**: default 8 m, range 1-20
   (`_auto_hearing_radius_m`). Recommended **4-5 m**. It decides how far away an NPC is still
   auto-included / listed as present, which is what makes people behind walls "present" in her head.
   Per decision D2 the glue writes no CHIM setting, so this one is the owner's.
2. **CHIM's `<nearby_actors>` prompt section** (CHIM settings -> prompt sections): it was 6-7.6 KB of
   tonight's requests and has no wall or door test. Turning it off, or down, is the direct answer to
   "CHIM should not be hearing through closed doors and walls" on the *knowledge* side. Keep in mind
   it is also how she knows who is in the room at all.
3. **CHIM mode stays `WHISPER`.** It is what already stops other NPCs from receiving the player's
   lines (`conf_opts.chim_mode = WHISPER`, proven on all 20 player turns tonight). Switching to
   STANDARD would re-open group hearing.
4. **Her name in Whisper.** All three Narrator turns tonight named her and all three were mangled:
   "Lazette", "Liz uh", "Lizette". CHIM's `core_tts_pronunciation` / alias handling is the place to
   teach the STT the spellings, and CHIM's **Manual AI Activate** hotkey
   (`AIAgentPapyrusFunctions.psc:458-475`) is the instant fix in the moment: look at her, press it,
   and routing is deterministic again.
5. **`Serana`: `not_for_sale`?** The computed price would be 2100 / 840 gold. The addendum does not
   say. Recommend `"not_for_sale": true` in `npc_overrides.Serana` on taste grounds (her whole
   `npc_overrides` note is about trust), and it is a one-line config change either way.
6. **`relationship.repair` is still `enabled: true`** in `lrg_config.default.json:34-39` with
   `npc: "Lisette", affinity: 26`. Tonight's log shows the repair did its job - 21:47:58 reads
   `aff=26 chim=26 lrg=26` - and by 22:03:03 CHIM had already wiped the entry again
   (`chim=missing lrg=8 rescued=missing`). **Switch `enabled` to false now**: a second repair would
   push her back up to 26 from whatever she has honestly earned since.
7. **A lost command, for the owner's awareness** (not in my three items, no design offered):
   ```
   22:06:34 gate: StartIntimacy passed -> ok=1;cid=s43281f6d;...;furn=singlebed;fscene=OStimSingleBedLeft2PBothSittingKissMF;...
   22:07:22 WARN command not confirmed by the game: ExtCmdLRG_StartIntimacy do= cid=s43281f6d age=48s
   ```
   A start that passed the gate on an initiative tick never reached the game at all - no refusal, no
   result, nothing. The 0.3 watchdog caught it (that is what it is for). Same class as the one lost
   command in playtest 6. Worth a lane of its own next round.

---

# 6. Open risks and what I could not verify

| # | risk / unknown | how to close it |
|---|---|---|
| 1 | `metadata.parameter_template` was read from the installed server source and is unambiguous, but **no live request has ever carried an `amount` for one of our rows**. If the strict JSON schema for a custom row omits `amount` entirely, the template resolves to `''` and every acceptance reads 0 - i.e. free scenes, never wrong charges. | the failure mode is safe by construction; confirm on the owner's first paid turn by grepping `gate: StartIntimacy passed` for `pay=` |
| 2 | `herikaNormalizePositiveActionAmount()` caps amounts at 1000 by default (`functions.php:186`) but is only called for `SpawnItem` (max 1000), `SpawnGold` (max **1000000**) and `SpawnActor` (max 10) - never for a `plugin_command` row. A 105000-gold jarl price is therefore not clamped on the way through CHIM. | verified by grep (three call sites, all named); re-check if CHIM ever routes custom rows through the same normaliser |
| 3 | `setDrivenByAIA` is documented as a **toggle** that can REMOVE an active agent (`research/chim-papyrus-plumbing.md:313`, DLL:46198-46203, itself string-adjacency inference). The listener hold adds calls after a scene, when she is definitely an active agent. Tonight's three re-asserts during scenes were all followed by her answering normally, so the practical risk is low. | the hold is bounded and behind `bForceListener`; if she ever stops answering right after a scene, set `fListenerHold = 0` and the old behaviour is back |
| 4 | Whether OStim can leave the player in camera state 3 was NOT reproduced - the owner's words are the only evidence, and the glue provably never touches the camera. | F3 logs every time it fires; if the line never appears, the camera half was the narrator half |
| 5 | ~~Form type 29~~ **CONFIRMED**: two installed mods use `FindAllReferencesOfFormType(ref, 29, radius)` for doors - `Biggie Traits/Source/Scripts/Traits_HomeownerVisitHouseScript.psc:9` (`allDoors`, radius 1000) and `CHIM/Source/Scripts/AIAgentAIMind.psc:2798` (`doors`, radius 10000; that block is inside `if (false)` and is dead code, but the type number is the same). What is still unmeasured is whether the result includes load doors as well as in-cell doors. | either is fine: a load door counts as a door for this purpose, and a wrong answer is `door=0` = today's behaviour |
| 6 | The exact gold value of "a day's wage" is a taste call. 35 is the mid-point of the owner's own 3-5 gold/hour over a 9-hour day, and it makes his own yardstick (a tavern girl at 3 days) land on 110. | `gold_per_day_of_wage` rescales the entire economy with one number |
| 7 | `paid_gain_factor` halves the gain whenever gold moved, including a willing NPC's token gift. That is the owner's literal wording ("paid sex counts half") but arguably wrong for a gift. | one config key; flip it or add `token_free_of_penalty` if the owner dislikes it |
| 8 | I did not read `PHASE2_DESIGN.md` §1.2/§1.11 signature and MCM tables in full - my lane touches neither the dialogue driver nor its keys. Every key I propose is in a NEW row of an existing page, so a clash is only possible if the Phase 2 builder picked one of my eight ids. | the ids are all prefixed with this lane's subjects (`Paid`, `Privacy`, `Hear`, `Door`, `Listener`, `FixControls`) |

---

# 7. Precision pass - the six details that decide whether §1 compiles as designed

Written after re-reading the call order in `lrgPrepareTurn()`. Each one is a correction or a
sharpening of §1, and each one is load-bearing.

**7.1 The gate runs BEFORE the recogniser, so the offer cannot come from `$turn['intent']`.**
`lrgPrepareTurn()` calls `lrgEvaluateGates()` at `lrg_actions.php:924` and
`lrgRecogniseIntent()` only at `:960` - the mode is already decided in between (`:935`). So
`lrgPaidOffer()` must do its own recognition:

```php
$money = lrgIntentMoney(lrgIntentClean((string) ($GLOBALS['gameRequest'][3] ?? '')));
```
That is legitimate and cheap: `lrgIntentMoney()` is pure regex, needs no `ctx`, no index and no DB,
and the recogniser is already documented as running twice per turn by design
(`lrg_intent.php:6-7`). The normal path at `:960` produces the same money result a moment later, so
the turn line, the directive and the gate can never disagree. Do **not** reorder `lrgPrepareTurn()`
for this - the D9 context build depends on the mode that the gate produces.

**7.2 `paid_offer` must be written idempotently, because the turn is prepared twice.**
`lrgPrepareTurn()` runs once from the `functions.php` hook and again from `prerequest` /
`context_pre` (`$same` at `:729`, `lrgPrerequest()` `:715`). `lrgMemSet()` skips a write only when
the value is unchanged (`lrg_core.php:452`), and `{gold, at}` with a fresh `at` changes every pass.
Rule: write `paid_offer` only when the recognised amount is **higher** than the stored one, or when
the stored one is expired; keep the original `at`. Then two passes of the same request write once.

**7.3 Offer TTL: 600 s, not 180.** The realistic flow is: the player offers in the tavern
(`mode=public`, `reasons=witnesses`) -> she suggests going somewhere private -> they walk -> the
gate runs again in `mode=private` and the offer has to still stand. The invitation's own TTL is
1800 s (`invitation.ttl_seconds`), and playtest 8 shows the walk taking minutes (22:03 offer window
-> 22:10 `mode=follow`). `offer_ttl_seconds: 600` in §1.2, and the offer is still cleared outright
at `ev=start` and `ev=end`.

**7.4 Inside a running scene, money produces WORDS ONLY.** The money kinds must not reach the
in-scene DO-IT directive shape (`lrg_intent.php:848`), because there is nothing to buy: gold moves
at a scene START and nowhere else. Rule: when `$turn['mode'] === 'scene'`, an `offer` / `askprice` /
`haggle` gets a one-line neutral restatement ("the player is talking about coin - answer in your own
voice, change nothing") and no action, and `lrgPaidOffer()` is not consulted at all. Also: the money
kinds stay out of `intent.net_kinds` (they are not in the shipped default,
`lrg_actions.php:1729`), so neither `lrgSafetyNet()` nor `lrgSecondCommand()` can ever act on them.

**7.5 Two test fixtures need one line each.**
- `tools/test_gates.php`'s `snap()` (`:33-34`) has **no `pgold` key**, so every paid assertion would
  read a purse of 0 and nothing would ever be accepted. Add `'pgold' => '500'` to the default and
  override it per case. (`tools/flows/adapter.php:133` already sends `pgold => 300`.)
- The snapshot's own `v=2` needs **no bump** for `door=`: nothing on the server ever tests it
  (`grep` for `state['v']` finds only the scene index's unrelated `v`). Keep `v=2`, additive.

**7.6 Corrections to two numbers in §1.11.**
- Case 10 (repeat customer): the factor applies to the **days**, not to the rounded gold.
  3.2 d x 0.85 = 2.72 d -> 95.2 -> **95**. The answer is the same as the sloppy route, the method is
  not; assert 95 and compute on days.
- Case 5 (married innkeeper, `secret`): `lrgInterest()` adds +20 to `min_affinity` for a secret
  marriage (`lrg_core.php:780`), which lowers the score and can flip the stance word from `curious`
  to `indifferent` - and the stance chooses floor vs ceiling before `secret_factor` is applied. So
  assert the x3 **at a pinned stance** (set the affinity so the word is `indifferent` in both the
  married and the unmarried fixture), or the test measures two things at once. 420 x 3 = **1260**.

**7.7 One more field for §2's F1.** The "she has said her goodbye and the player let it lie" exit
needs a timestamp that does not exist yet: add `float outroDoneAt`, set in `ReleaseOutroHold()`
(`LRG_OStim.psc:800`) to `Utility.GetCurrentRealTime()` whenever the release reason was
`"she has said her piece"`, and leave it 0.0 for every other reason. `TickListener` then uses
`afNow >= outroDoneAt + fListenerGrace` for that one exit, and ignores it when `outroDoneAt` is 0.0
(released early, timed out, walked away - in those cases only the ordinary clock applies).
