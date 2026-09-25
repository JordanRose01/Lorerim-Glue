# pt15: prices by trade (owner addenda 12e-f)

Server 0.5.6 (ext `lorerim_glue`), deployed 2026-09-23 06:04. All tests and flows pass.
The deployed tree lints (18 PHP files) and both config files parse.
Nothing in CHIM core was changed. Your own `config/lrg_config.json` was not touched.

## What changed

**1. Each trade has its own daily wage.** The single `gold_per_day_of_wage` (35 septims) is gone. A day is now 8 hours of *her* work:

| Wage tier | Gold per hour | Gold per day | Who |
|---|---|---|---|
| common | 5 | 40 | beggars, tavern folk, commoners, farmers, servants, anyone no rule knows |
| performer | 8 | 64 | bards |
| trade | 12.5 | 100 | merchants, innkeepers, guards, hunters, priests (including Dibella's and Mara's) |
| fighter | 16.5 | 132 | adventurers, mercenaries, housecarls, guard captains |
| craft_magic | 25 | 200 | smiths, alchemists, enchanters, mages, court wizards, healers |
| court | 62.5 | 500 | stewards, thanes, nobles, jarls |

**How her tier is chosen (first match wins):**
1. `npc_overrides.<name>.wage_tier`: your call for one character. `price_gold` still pins an exact figure, as before.
2. `paid_intimacy.wage_tier_by_job`: her real trade inside a broad rule. Each row only applies to the rule ids it lists, so a jarl or a priest can never be moved by it. The rows are:
   - a bard among tavern folk becomes a performer
   - a smith, apothecary or spell vendor among merchants becomes craft_magic
   - a court wizard at court becomes craft_magic
   - a guard captain becomes a fighter
   - an apothecary, mage or hunter that no rule knows gets that trade's wage, read from her factions and class
3. The matched status rule's own `wage_tier`. Every one of the 14 rules and the fallback now has one.
4. `default_wage_tier` (common).

An unknown tier name is ignored. The wage never becomes 0.

**Bands still count days:** destitute 0.5-2, poor 1-4, modest 4-15, comfortable 15-60, wealthy 60-300, noble 300-3000.
- Curious means the floor of her band. Indifferent means the ceiling. Interested or drawn means free.
- Her price = days x her daily wage x modifiers (marriage, renown, repeat customer, MCM multiplier), rounded to 5.

**2. `money_influence` is now 1.0 on every shipped rule and on the fallback.**
- Your calibration is "band days x her daily wage", and nothing else. With the old factors, Katana would have been 4 x 1.6 x 132 = 845, not about 530.
- The factor is still available per NPC. Serana keeps her 4.0 override.

**3. `max_price` is now 2,000,000 (it was 200,000).** An indifferent jarl costs 3000 x 500 = 1,500,000, and the old cap would have lowered her to the same figure as a curious jarl.

**4. The log lines show her wage tier and daily wage.**

The `price` line:
```
price npc=Katana for_sale=yes tier=modest wage=fighter/rule gph=16.5 day_wage=132 band=4-15d infl=1 stance=curious days=4 floor=530 ... pgold=322 offer=0/none kind=askprice decision=needed
```
- `tier` is still her wealth tier.
- `wage=<tier>/<where it came from: override|job|rule|default>`, `gph` is gold per hour, and `day_wage` is her daily wage.

The `turn` line: its `paid=` segment gains `/wage:fighter@132/`.

**5. The one-time Lisette repair is switched off.**
- `relationship.repair.enabled` is now false.
- The block and its explanation stay in the file, marked SHIPPED DISABLED, as a template for any future one-off repair.
- Test: a fresh memory plus a live turn with Lisette writes nothing into CHIM.

**6. Free-conversation bribes use the same daily wage.** A bribe is priced from the NPC's own daily wage (`lrgDlgWage($profile, $npc, $state)`), not a flat 35, so bribing a guard now costs more than bribing a beggar.

## Your examples, checked (test_gates 32c, and on the deployed server)

| Example | Result |
|---|---|
| Katana (adventurer, fighter tier, modest, curious = floor of her band) | 4 days x 132 = **528**, shown as **530** because prices round to 5. 530 is also the figure you asked for in 12f. |
| Poor tavern girl (common, poor, curious) | **40** |
| The same tavern girl, indifferent (top of her band) | **160** |
| Modest merchant (trade) | **400 - 1500** |
| Jarl (court tier, noble band) | **150,000** curious, **1,500,000** indifferent |
| Interested or drawn NPCs, any tier | **no price** (the gift cap scales too: 20 for a tavern girl, 100 for a smith) |

Tonight's case replayed (32b): Katana is curious, you carried 322.
- "and everybody has a price": she names **530**.
- The firm "I'll give you 300 gold" that bought her at 225 is now *below her floor*. She answers "not for 300", names 530, and nothing starts.
- With 700 gold on you, a firm 600 is accepted.

## Example prices per profile

Curious = floor of the band. Indifferent = ceiling. No renown discount, not married, MCM multiplier 1.0. Where there is a name, the factions and class come from your own game's NPC snapshots.

| NPC (example) | Status rule | Wage tier (source) | Gold/day | Band (days) | Curious | Indifferent |
|---|---|---|---|---|---|---|
| a beggar | beggar | common (rule) | 40 | 0.5-2 | 20 | 80 |
| a tavern girl (server) | tavern_folk | common (rule) | 40 | 1-4 | 40 | 160 |
| a farmer | commoner | common (rule) | 40 | 1-4 | 40 | 160 |
| a servant / unknown mod faction | fallback | common (rule) | 40 | 4-15 | 160 | 600 |
| a bard (Lisette) | tavern_folk | performer (job) | 64 | 1-4 | 65 | 255 |
| a merchant (Belethor, Addvar) | merchant | trade (rule) | 100 | 4-15 | 400 | 1,500 |
| an innkeeper (Corpulus Vinius) | innkeeper | trade (rule) | 100 | 4-15 | 400 | 1,500 |
| a guard | guard | trade (rule) | 100 | 15-60 | 1,500 | 6,000 |
| a hunter (Hak) | fallback | trade (job) | 100 | 4-15 | 400 | 1,500 |
| a priest (Rorlund) | priest | trade (rule) | 100 | 60-300 | 6,000 | 30,000 |
| a priestess of Dibella | priest_dibella | trade (rule) | 100 | 1-4 | 100 | 400 |
| a priestess of Mara | priest_mara | trade (rule) | - | - | not for sale | not for sale |
| Katana / a mercenary | adventurer | fighter (rule) | 132 | 4-15 | 530 | 1,980 |
| a housecarl | housecarl | fighter (rule) | 132 | 60-300 | 7,920 | 39,600 |
| a guard captain | guard | fighter (job) | 132 | 15-60 | 1,980 | 7,920 |
| a smith (Beirand) | merchant | craft_magic (job) | 200 | 4-15 | 800 | 3,000 |
| an apothecary (Vivienne Onis) | fallback | craft_magic (job) | 200 | 4-15 | 800 | 3,000 |
| a mage (Makar [Mage]) | fallback | craft_magic (job) | 200 | 4-15 | 800 | 3,000 |
| a court wizard | court | craft_magic (job) | 200 | 60-300 | 12,000 | 60,000 |
| a steward / thane / noble | court | court (rule) | 500 | 60-300 | 30,000 | 150,000 |
| a jarl (Elisif) | jarl | court (rule) | 500 | 300-3000 | 150,000 | 1,500,000 |
| Serana (override: money_influence 4.0) | adventurer | fighter (rule) | 132 | 4-15 | 2,110 | 7,920 |
| a Vigilant of Stendarr | vigilant | fighter (rule) | - | - | never | never |

Modifiers still apply on top:
- Married under "secret": x3. An innkeeper who is secretly married costs 4,500 indifferent.
- Dragonborn with a "strong" renown sway: x0.75. A tavern girl costs 120 instead of 160.
- A repeat customer: x0.85.
- None of these can take her below the floor of her band.

## Your decisions

- **530, not 528.** `round_to` is still 5. Set it to 1 if you want exact figures: Katana 528, a bard 64/256.
- **Bards now cost 64 a day (65 / 255), not the common 40.** This includes Lisette, but she is interested in you, so she is free anyway.
- **Serana keeps money_influence 4.0**, so she costs 2,110 / 7,920. Set it to 1.0 in her override for the plain adventurer figures (530 / 1,980).
- **Guards lost their 2.5 "risky" factor.** Their band of 15-60 days already makes them expensive: 1,500 / 6,000.
- **Bribes follow the NPC's trade.** A bribe with no engine amount costs 0.3-3 days of *her* wage, rounded to 5: 10-120 for a beggar, 30-300 for a guard. An engine bribe entry still uses its own amount.

## Files changed

- `server/lorerim_glue/lib/lrg_core.php`:
  - new `lrgWageFor()`
  - `lrgPriceFor()` uses her daily wage and returns `wage_tier` / `wage_hour` / `wage_day` / `wage_from`
  - the default `max_price` is now 2,000,000
  - the price log line has the new fields
- `server/lorerim_glue/lib/lrg_actions.php`: the turn line's `paid=` segment gains `/wage:<tier>@<day>`.
- `server/lorerim_glue/lib/lrg_speech.php`: `lrgDlgWage()` returns her tier's daily wage (40 with no profile). The bribe price passes in her profile.
- `server/lorerim_glue/config/lrg_config.default.json`:
  - `hours_per_day`, `wage_tiers`, `default_wage_tier`, `wage_tier_by_job`, and a readme
  - `wage_tier` on all 14 status rules and the fallback
  - `money_influence` 1.0 everywhere shipped
  - `max_price` 2,000,000
  - the price notes rewritten with the new figures
  - `relationship.repair.enabled` = false
- `tools/test_gates.php`:
  - section 32 figures updated; its tavern-girl fixture is now a server (`JobInnServer`), because a bard is a performer
  - 32b replays Katana (adventurer, floor 530) instead of the Belethor stand-in (floor 225)
  - new section 32c: the tier table (21 profiles), your examples, the override / pin / bad-tier-name path, and the repair switched off
- `tools/flows/scenarios/d40_quests.php` (d41): checks that the default wage is 40, the flat wage is gone, a jarl's day is 500, and a bribe uses the same daily wage as her price.
- `PROTOCOL.md` (section 9 log format, section 10.7) and `README.md`: wording updated.

## Results

| Suite | Result |
|---|---|
| php -l, all source and tools (84 files) | clean |
| test_gates | 459/459 (was 421) |
| test_intent | 316/316 |
| test_phrases | 47/47 |
| test_scene_index | all pass |
| test_dialogue | 174/174 |
| test_prompt_index | 67/67 |
| test_mcm_wiring | 3/3 |
| test_services | 35/35 |
| test_latency | 30/30 |
| test_stt | 60/60 |
| test_audiofilterd | 75/75 |
| flows `--strict` | 78/78 scenarios, 1316 checks, RESULT OK |

`test_audiofilterd` first reported 74/75. It keeps its log in `/tmp/lrg_af_test`, which fills up from earlier runs, so its "no log line per health probe" count keeps growing. The same failure appears on the tree deployed before this change. With the folder emptied it passes 75/75. It has nothing to do with this change.

**Deploy:**
- Postgres was up.
- The four changed deployed files were backed up first to `%TEMP%\lrg_test\pt15w\deployed_before\`.
- `deploy_server.ps1` checks:
  - the pre-flight check of code markers passed (34/34)
  - the scene index was rebuilt (607 scenes)
  - the prompt index was reloaded (37,561 rows)
  - the index pre-flight check passed
- After the deploy:
  - all 27 source files match the server
  - `lrg_config.json` is byte-identical to before
  - all 18 deployed PHP files lint
  - both config files parse
  - the merged config has no `gold_per_day_of_wage` and has `repair.enabled=false`
  - your Lisette override (`min_affinity -5`) is intact
- Prices computed on the deployed server:

| NPC | Curious | Indifferent |
|---|---|---|
| Katana | 530 | 1,980 |
| Lisette (performer) | 65 | 255 |
| Tavern girl | 40 | 160 |
| Corpulus / Addvar | 400 | 1,500 |
| Beirand | 800 | 3,000 |
| Elisif | 150,000 | 1,500,000 |

## Not done / limits

- Not tested in game.
- The job table only knows the factions and classes seen in your snapshots plus the vanilla job factions. An NPC from a mod with no job faction and an unusual class falls back to her status rule's tier, or to common.
