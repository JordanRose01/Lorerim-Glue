# pt19 - buying food and drink by voice at the REAL price (investigator notes, read-only, 2026-09-24)

Owner (02:52-02:55 -04:00, The Drunken Huntsman / The Bannered Mare): "i ordered a beer/ale ... she noticed i was trying to
buy ... gave me a price (too cheap ... 1-3 septims ... her in-menu prices were like 19 for a mead) but she couldn't seem to
give me the drink ... 1. make the prices slightly higher/more accurate 2. make it so she can physically give me an order".
Line numbers are of the tree as of 2026-09-24 03:30 (server 0.5.6 libs, LRG_Main.psc CurrentVersion 510).
Logs: lorerim_glue.log (-04:00), chim.log / context_sent_to_llm.log / output_from_llm.log (+02:00), AIAgent.log (local time).
Scan tools written for this lane (read-only, WSL python3, the distro sees /mnt/f): C:\Users\Jordan\AppData\Local\Temp\lrg_test\purchase\
perk_scan.py (price perks + fBarter GMSTs, output perk_scan.out), glob_scan.py (RoomCost), alch_scan.py (drink values), idx_food.sh.

## 1. What happened, turn by turn (evidence)

| time | npc | STT (`say=`) | Phase 1 intent | Phase 2 `svc=` | locked facts | model | log line |
|---|---|---|---|---|---|---|---|
| 02:52:43 | Elrindir | `hey could i buy something to drink?` | none / no pattern matched | **inn** ("something to drink" is an INN phrase, lrg_dialogue.php:181-183) | (none) | action=none | lorerim_glue.log 3372-3376; chim.log 24969-24973 |
| 02:52:48 | Elrindir | - | - | the auto-calibration opened her menu instead (`do=open;calib=1`) | | | 3379-3382 |
| 02:53:39 | Hulda | `i'll have uh l please` | none | (no svc key) | classes=quest, `pg=-` | "An L you say? Never stock that here. Mead and wine only" | 3389-3397; output_from_llm.log 4523-4530 (Request 886) |
| 02:54:23 | Hulda | `uh some beer` | none | (none) | classes=quest, `pg=-` | action=none "Beer, you say. Well, we got Honningbrew Mead ... Want a bottle?" | 3421-3429; Request 887 |
| 02:54:35 | Hulda | `yeah thank you` | none | (none) | classes=quest | action=none **"That'll be one septim for a bottle of Honningbrew, friend."** amount=1 | 3428-3435; Request 890 (4551-4552) |

What the model could see for Hulda (context_sent_to_llm.log 32311-32667, the 08:54:35+02 request):
* `<inventory>` = Clothes, Iron Dagger of Sapping, Silver Ring, Stitched Boots of Resist Fire (32377-32383). **No mead.** The DLL
  delivers the NPC's PERSONAL inventory on every player utterance (AIAgent.log 02:53:39.518 `[INVENTORY_UPDATE] Hulda inventory
  delivery queued (4 items)` right after `[PLAYER-ROUTING] ... utterance='i ll have uh l please'`; HerikaServer gamedata.php
  `handleInventoryUpdate` 484-520 -> npc `metadata["inventory"]` with name/baseid/count/goldvalue; `buildInventoryMetadataValue`
  408-437 also upserts `market_cache`). The merchant chest is never sent; comm.php `itemtransfer` (461-535) only edits that list.
* the mead bottles are WORLD refs: `<nearby_items>` `0xFE89E8AC:Honningbrew Mead (STEALING)` x20 (32540-32595) - the inn's own
  property; `Give_Item_To` "REQUIRED: exact item name from <inventory>" (32498) cannot name them.
* the function list offered Rent_Room, Give_Gold_To, Give_Item_To, Trade_Items, Consume ... (32136 / 32667): nothing that sells.
So (2) "she couldn't give me the drink" is structural: no offered action can move a bottle from the inn's merchant chest to the
player, and the one item action she has is bound to a list without mead.

Why (1) the price was invented and nothing stopped it:
* the `price` class of `<locked_facts>` comes ONLY from a LIVE entry's `cost=` (lrgDlgLockedFacts 4685-4692); the turn had `open=0`
  and no priced entry -> `lock ... classes=quest` (3397, 3427). The `gold` class needs `facts.pg` (4711-4712), and the facts line
  never carries `pg=` (SendFacts 3730-3737; `pg=` rides only the list-open message, LRG_Dialogue.psc 3361) -> `pg=-` on every turn.
* the truth gate (lrgDlgTruthCheck 4776-4824) drops only an ACTION acting on an unconfirmed number; the reply had `action=none`.
* the never-false rail (lib/lrg_replies.php lrgNfContext 665-694) is scoped to faction turns (`lrgFacRoles`); prices are never judged.
* the intimacy recogniser (lib/lrg_intent.php lrgRecogniseIntent 774+) knows undress / acts / money-for-sex / escort - not an order.
* the dialogue lane's kinds (lrg_dialogue.php 179-224) are inn / carriage / ferry / train / barter / crime / follower; "i'll have",
  "some beer", "a mead" match none; the barter phrases need buy / for sale / sell words (194-207). `lrgDlgServiceRequestBlock`
  (4180-4255) for `inn` under ml=0 says "choose Rent_Room if she really offers that, otherwise say why" - the wrong ask for a drink.
* the prompt index has NO generic "buy a drink" real entry for an innkeeper (idx_food.sh over data/prompt_index.ndjson: 235
  food/drink prompts, all quest lines - MQ201AskForDrinkTopic is the Thalmor party; the vanilla inn drink route is the barter
  window via `OfferServicesTopic`). So D4 ("the real entry wins") has nothing to win with: a glue-owned carrier (the 10.26 shape).

## 2. The room: 10 septims is CHIM's default, NOT the game's price

* `Matlara|command|RentRoom@{"amount":10}` (chim.log 27751 / output_to_plugin.log 1059; Corpulus Vinius 781 the same). `amount` is
  `{{config.cost_gold}}` (action_catalog.php `herikaActionCatalogGetBuiltinParameterTemplate` 1106-1109), editor field
  `RentRoom.cost_gold` default **10** (1048-1057), charged by `AIAgentAIMind.RentRoom(player, innkeeper, cost, ..)` (:3080-3109).
* the GAME's price is the GLOB `RoomCost` (Skyrim.esm 0009CC98) that every vanilla "I'd like to rent a room. (<Global=RoomCost>
  gold)" entry prints (15 index rows carry the token) - and its WINNER in this load order is **25.0 (LoreRim - Economy
  Overhaul.esp)** (glob_scan.py). The owner's "could be slightly more expensive" is right: the real entry charges 25.
* consequence for lane item (5): "quote it as is" would lock a CHIM default, not the engine's price. Truthful room fact = the live
  `RoomCost` (`GlobalVariable.GetValue()` on the facts line as `room=`); CHIM's row has to match it - by the owner in the Action
  Editor (Gold Cost 25) or by the server through CHIM's own config API (`herikaActionCatalogUpsertCustomConfigValue('RentRoom',
  'cost_gold', <room>)`, action_catalog.php 3063) behind a config switch; `herikaActionCatalogGetCustomConfigValue('RentRoom',
  'cost_gold', 10)` (2644) lets the server log the mismatch once per session either way.

## 3. The price the barter menu charges - formula, inputs, this load order's perks

Formula (UESP Skyrim:Speech, https://en.uesp.net/wiki/Skyrim:Speech, quoted): "price factor = fBarterMax - (fBarterMax -
fBarterMin) * min(skill,100)/100"; "buy price modifier = HagglingB * AllureB * (1 - Fortify Barter from potion) * (1 - sum of
Fortify Barter from equipment - Blessing)"; "buy price = round(value of item * buy price modifier * base price factor)". Vanilla
3.3/2.0 -> 3.3 at skill 0, 2.0 at 100. `fBarterBuyMin` / `fBarterSellMax` cap the final multiplier (buy never below, sell never
above; vanilla 1.05 / 0.95 - nexusmods.com/skyrim/mods/38116 "Barter Rates Guide", gamesas.com/barter-rates-t335407.html).

Winning GAME SETTINGS (perk_scan.py: every enabled plugin of profiles\Ultra\plugins.txt, MO2 modlist priority, last writer wins):
`fBarterMin 3.0`, `fBarterMax 4.0`, `fBarterBuyMin 2.0`, `fBarterSellMax 0.5` - LoreRim - Economy Overhaul.esp (research/p2-requiem.md
99-100 agrees). At Speech 15 the base factor is 4.0 - 1.0 x 0.15 = **3.85**. The GAME reads them LIVE (`Game.GetGameSettingFloat`,
SKSE Game.psc:108) so a runtime override by any DLL is honoured.

Winning PRICE PERKS (entry points 0x08 Mod Buy Prices / 0x3C Mod Sell Prices, UESP Skyrim_Mod:Mod_File_Format/PERK; fn 3 =
Multiply Value, 5 = Add Actor Value Mult, 14 = Multiply 1 + AV Mult; AV 17 Speechcraft, 107 SpeechcraftMod = "price/Haggling
enchantment calculations", 146 SpeechcraftPowerMod = fortify potions, UESP Skyrim_Mod:Actor_Value_Indices):

| perk (canonical id, winner) | buy entry | note |
|---|---|---|
| REQ_Speech_Haggling Skyrim.esm:0BE128 (Big Tweaks.esp) | prio 101 **fn 5: += -0.01 x Speech** (cond: HasPerk Haggling20 == 0) | Requiem's "1 % per Speech point"; vanilla ranks 0C07CE-D1 are REQ_NULL |
| REQ_Speech_SilverTongue Skyrim.esm:058F72 (LoreRim - Economy Overhaul) | prio 103 x0.90 | already `perkSilver` in LRG_Dialogue.EnsureForms |
| REQ_Speech_Merchant Skyrim.esm:058F7A (LoreRim - Economy Overhaul) | prio 100 x0.80 | |
| RFTI_All_ActorValueModifier 'Skill Boosts' Skyrim.esm:0CF788 (Big Tweaks) | prio 4 fn 14 x(1 - 0.01 x SpeechcraftMod) | Requiem's Fortify Speech ENCHANTMENTS as a price change |
| RFTI_All_ActorValuePowerModifier Skyrim.esm:0A725C (Requiem - Magic Redone) | prio 5 fn 14 x(1 - 0.01 x SpeechcraftPowerMod) | Fortify Speech POTIONS |
| USKPMS05GiftofGab USSEP:02029E (LoreRim - Static Skill Leveling Patch) | prio 0 x0.95 | Tending the Flames reward |
| DLC2BlackBookHagglingPerk 'Lover's Insight' Dragonborn.esm:01E7F1 (USSEP) | x0.90 | opposite sex only |
| REQ_Ability_ArchMage_FortifyBarterInCollege Skyrim.esm:10F9DB (Requiem) | x0.90 | GetInFaction 0001F259 (College) |
| REQ_Ability_Birthsign_Lover_Perk Skyrim.esm:0E5F57 (Big Tweaks) | x1.15 (worse) | birthsign |
| LoreTraits_SilentDovahPerk LoreRim Traits.esp:00080D | x2.00 | a trait |
| DVS ExhaustionStage4/5 DVSsurvivaltweaks.esp:000833/000834 | x1.5 / x2.0 | survival exhaustion |
| Painful Regrets Requiem.esp:47B5B8 (Wintersun - Reqtificated) | x0.75 | |
| Bard subclass x0.9, King's Heart x0.85, Wintersun boons (cond), Biggie Traits (cond), Ordinator rows (not in the tree), CC Turn of the Seasons (cond), Requiem - Vendor tweaks RVT x1.25 (GetIsID on the vendor), MR Illusion Mechanics (global-ranked) | | exotic: `HasPerk` them, apply only when the condition is trivially readable, else log "unmodelled price perk held" |

`REQ_NULL_Allure / Investor / MasterTrader` carry no entries. Full dump: %LOCALAPPDATA%\Temp\lrg_test\purchase\perk_scan.out.

Model (game side, LRG_Profile Global `BuyPrice(Form item, Actor npc, Actor player) -> int` + a `BuyPriceLine()` for the log):
f as above with Speech = `GetActorValue("Speechcraft")` clamped 100; mod starts 1.0 and applies the held perks in priority order
(prio-0 group, then 4, 5, 100, 101 (the ADD), 103); mult = max(fBarterBuyMin, f x mod); price = round(value x mult) with value =
`Form.GetGoldValue()` (SKSE Form.psc:7). Log line: `buy price <name> value=<v> sp=<n> f=<3.85> mods=<merchant:0.8,haggling:-0.15,..>
mult=<m> clamp=<0|1> price=<p>`.

CHECK AGAINST THE OWNER'S MEMORY - unresolved: Honningbrew Mead's winning value is **10** (Skyrim.esm:0508CA, winner
DVSsurvivaltweaks.esp; Nord Mead 10, Black-Briar 30, Alto Wine 20, Wine 7, Bread 6, Beef Stew 24; alch_scan.py) -> the model says
**39** septims at Speech 15 with no price perk, while the owner recalls "like 19". 19 = 5 x 3.85 or 10 x 1.9, neither of which this
data produces (the clamp floor is 2.0). Either the recalled figure is off or a runtime input differs (a DLL touching the GMSTs, a
trait / perk held, a runtime value override) - which is why every input is read LIVE and logged and why the one-time owner check
is part of the fix: "open Hulda's barter window once, read the price of one Honningbrew Mead, compare with the `buy price
Honningbrew Mead ... price=` line in lorerim_glue.log; if they differ, the log line says which input to look at".

## 4. What Papyrus can do (verified in the installed sources)
* vendor: `PO3_SKSEFunctions.GetVendorFaction(Actor)` (PO3_SKSEFunctions.psc:101) -> `Faction.IsVendor()` (SKSE Faction.psc:100),
  `GetMerchantContainer()` (:124), `GetVendorStartHour/EndHour` (:115/118), `IsNotSellBuy()` (:127), `GetBuySellList()` (:130).
  Hulda's faction is ServicesWhiterunBanneredMare (UESP Skyrim:Hulda: "Food, raw food, and standard merchandise").
* stock: `ObjectReference.GetNumItems()` / `GetNthForm(i)` (:771-772), `GetItemCount(Form)` (:263), `Form.GetType()` (46 = ALCH),
  `Potion.IsFood()` (Potion.psc:8), `Form.GetGoldValue()`, `Form.GetName()`.
* transfer: `container.RemoveItem(item, n, false, player)` (:505) - CHIM's own hand-over pattern (AIAgentAIMind.GiveItemToTarget
  ~3394 `source.RemoveItem(itemForm, amount, true, target)` + `Debug.SendAnimationEvent(source, "IdleGive")`); gold:
  `player.RemoveItem(Game.GetForm(0xF), price, true, <container or npc>)` (RentRoom :3106-3107 does the same to the innkeeper).
* already in the glue: `AIAgentFunctions.getAgentByName / logMessageForActor / commandEndedForActor / isActorTalking`
  (AIAgentFunctions.psc 62/28/8/33); `ReportError / ReportResult / SendFuncret / SayReason` (LRG_Main 1414-1567); the D1 dispatcher
  `HandleCommand` (1568-1666); `CmdQuestEntry` as the pattern (1799-1903: dry run named, agent, loaded, every condition re-checked,
  `OK:` / `Error:` + `err=`); `EscortRefuseReason` (2012-2041: hostile / combat / arrest / OStim scene); the facts line
  `SendFacts` (LRG_Dialogue 3663-3745, 60 s per NPC 3856-3870, additive keys like `CrimeWire`); `EnsureForms` (forms by MASTER
  FormID - `Game.GetFormFromFile(0x00058F72, "Skyrim.esm")` returns the winning override); `CleanForWire` (300-char guard).

## 5. The fix (smallest complete; ONE route D1, the 10.26 pattern) - the structured answer carries the file list and wordings
Server: new `lib/lrg_purchase.php` (recogniser, stock matching, plan, locked-facts class `stock`, directive, net, the price
rail's `price` class, funcret voicing helpers), hooked from `lrg_dialogue.php` (PrepareTurn, LockedFacts, VolatileGuidance,
PostProcessActions, FactsFrom) and `lrg_replies.php` (context + classify seam), one line in `lrg_actions.php` (blind-note carve-out
+ catalog row `GlueBuy` + funcret verdict), `lrg_core.php` untouched. Game: `LRG_Main.CmdBuy` (+ dispatcher branch), `LRG_Profile
.BuyPrice / StockCsv`, `SendFacts` keys `stock=`, `bp=`, `pg=`, `room=`; MCM `bVoiceBuy:Services`; CurrentVersion 510 -> 511
(re-read line 36 first - the helgen lane may have bumped it). PROTOCOL 10.27; tests test_dialogue 14, test_gates 38, flow d66.

---

## 6. IMPLEMENTED (pt19-purchase implementer, 2026-09-24 06:40-07:40 -04:00) - what changed and why

Line numbers above are of the tree at 03:30; the refuters' corrections were honoured as follows.

### 6.1 The 19-vs-39 question is CLOSED: it was Ale
The investigator's alch_scan.py filtered FULL with len(v) > 4, and "Ale\0" is exactly 4 bytes. The refuters' alch_chain.py (re-run by
this lane, C:\Users\Jordan\AppData\Local\Temp\lrg_test\refute_buy) shows the winning values: Ale 5 (REQ_Drink_Ale, Requiem -> LoreRim -
Food Tweaks -> DVSsurvivaltweaks, food-flagged), Honningbrew Mead 10, Nord Mead 10, Bread 6, "Bread, Half" 3, Village White Wine 20, Alto
Blanc Wine 20, Jug of Milk 3. At Speech 15 with no price perk (x3.85): Ale 19.25 -> 19 (the owner's "like 19" - he had ordered "beer/ale"),
the meads 38.5 -> 39. The UESP formula with LoreRim's GMSTs is CONFIRMED by the owner's own memory. vendor_scan.py over
REQ_VendorChest_Inn_Whiterun (Skyrim.esm:09CAFA, winner LoreRim - Economy Overhaul) shows ~35-50 distinct forms, Ale among the 15
LItemInnRuralDrink draws - so the test fixtures carry Ale, and "uh some beer" resolves to it.

### 6.2 The wire rides the SNAPSHOT, not the facts line (file ownership)
LRG_Dialogue.psc (SendFacts) was not this lane's to edit; LRG_Profile.psc's snapshot was. LRG_Profile.MarketFacts(npc, player, m) is
appended in BuildSnapshot right before class= (a separate call: a Papyrus error inside it aborts that call alone):
  ;vend=0|1[;room=<RoomCost>;bp=<fBarterMax>,<fBarterMin>,<fBarterBuyMin>,<speech>,<spmod>,<sppow>,<mult>,<mods>;stock=<hex8>:<name>:<price>:<count>:<value>,...]
One PO3 GetVendorFaction call for a non-vendor; a vendor's scan is cached 60 s per NPC in StorageUtil (LRG.mkt / LRG.mkt.at, keyed on the
actor - no new LRG_Main field) and CmdBuy unsets the stamp after a sale. pgold was already on every snapshot, which is what revived the
dead gold class (on MONEY turns only, see 6.6). A snapshot is at most one per 10 s per NPC (LRG_Main.MaybeSnapshot).

### 6.3 The price model, as the refuters asked
* GetActorValue("SpeechcraftMod") / ("SpeechcraftPowerMod") (AV 107 / 146) - NOT the "...Modifier" spellings.
* The multiplier is computed ONCE per snapshot (BuyMods -> BuyMultFrom); every row is floor(value x mult + 0.5).
* PO3_SKSEFunctions.AddItemsOfTypeToArray(chest, 46) (PO3_SKSEFunctions.psc:792) + Potion.IsFood, <= 40 forms looked at, <= 6 drinks
  THEN <= 8 food (food is never starved by the drinks); the chest = Faction.GetMerchantContainer(), else the NPC herself.
* Perks by MASTER FormID (the winning override): Gift of Gab 0.95, Sign of the Lover 1.15, Silent Dovah 2.0, DVS Weary / Debilitated
  1.5 / 2.0, Painful Regrets 0.75, Bard 0.9, King's Heart 0.85, Vigilant gold-cat 0.5, Lover's Insight 0.9 (opposite sex), Arch-Mage 0.9
  (in the College faction), Skill Boosts x(1 - 0.01 x SpeechcraftMod), power boosts x(1 - 0.01 x SpeechcraftPowerMod), Merchant 0.8,
  Haggling ADD -0.01 x Speech, Silver Tongue 0.9; Requiem - Vendor tweaks (GetIsID on one vendor) is DETECTED and carried as
  unmodelled_rvt:1.0 so the log names it.
* The entry-point order (multiply entries multiply a modifier that starts at 1.0, Haggling adds to it, then max(fBarterBuyMin, f x mod))
  is an ASSUMPTION (the CK wiki was unreachable) - stated in BuyMultFrom's docstring; the server mirror (lrgMktModelPrice) recomputes every
  row from bp= and logs "buy price drift <name> game=<p> model=<q>" when they disagree; CmdBuy recomputes before the sale and refuses with
  the live number when it moved ("it is N septims now, not M - say the word and it is yours").
* Dynamic Pricing Framework (refuter 1) is NOT modelled: the locked line is worded "what <npc> sells, at the price <npc> asks"
  (dialogue.market.wording: asking; 'game' restores "the price the game charges" once the owner's check matches), and the game charges
  exactly the number she quoted or refuses - words and coin never disagree even when the barter window does. No server-side price_scale
  knob on purpose: a server-side scale would make the locked number differ from what the game charges.

### 6.4 The server lane (lib/lrg_market.php, hooked from lrg_dialogue.php / lrg_actions.php / lrg_replies.php)
* Recogniser: exact name run first (bread -> "Bread", never "Bread Half"; the longest run wins; two runs that do not contain each other
  -> ambiguous), then a distinctive name word (honningbrew, nord, village), then a class word (beer / ale / lager -> the rows with the
  token ale; mead with two meads -> ambiguous -> ask; something to drink -> every drink). Counts: number words, and DIGITS read off the RAW
  words (lrgPromptNorm folds every digit to '#'). Guards: lrgDlgNegatedAt within the clause ("don't give me mead"), a person after the
  frame ("can i buy | you a drink" - a gesture), lrgDlgIsPriceQuestion -> a quote. A strong frame ("i'll have", "pour me", "a bottle of")
  is an order on its own; a weak one ("i want", "can i get") only with an item or a class word; a Phase 1 intent on the turn (clothes,
  an act) is never an order. "yeah thank you" confirms ONLY the glue's own ONE-item offer (lrg_memory buyask, 90 s, written on an ask
  with exactly one candidate, kept only when her line named it, cleared by anything but a confirmation).
* Plan states: none | not-vendor (vend=0: "she sells nothing") | no-stock (a vendor with no fresh stock -> the pt16 BARTER route, CHIM's
  OpenInventory after her line - never a refusal) | ask (<= 3 real items with prices) | quote | pending (a buy in flight, 120 s) |
  unaffordable (pgold < total) | queued (the param ok=1;cid;npc;item=<hex8>;n;price=<LOCKED unit>;name;x=<10hex>;z=1).
* Locked facts (class stock, on MARKET turns only: an order / ask / quote / refusal of the lane, an inn or barter conversation, a price
  question): "what <npc> sells, at the price <npc> asks: Ale 19 septims (8 left); ..." (<= 6 rows), "a room here costs 25 septims"
  (innkeepers, class price, num 25), "<player> ordered 1 Ale: 19 septims in all" (queued). gold from the snapshot's pgold on money turns.
* Directive <player_request> per state (queued: "say ONE short line handing it over for N septims - that price and no other number; the
  game itself takes the coin and hands it over right after your line; choose no action"). Hide: Give_Item_To on every buy turn (it is
  bound to her PERSONAL inventory and can never hand over stock), the barter window on a queued one. The barter net stands down on an
  active market state (svc direct_why 'voice buy').
* The net (lrgDlgPostProcessActions, after the barter net, BEFORE the corner hint / the calibration open): ONE
  <npc>|command|ExtCmdLRG_Buy@ line, once per request, never beside a trade / hand-over action from her, and WITHHELD when the truth gate
  flagged a price in the same reply ($GLOBALS LRG_DLG_TRUTH_CLAIM). lrgDlgCalibCandidate answers 'buying'; lrgDlgTurnHasBusiness counts
  it; the turn line gains " buy=<state>[:<name>@<price>[xN]]"; lrgDlgActsOnMoney knows the carrier.
* Funcret (lrgFuncretVerdict -> lrgMktResult): OK -> 'handled' (quiet; her line said it, "<item> added" is the receipt), lrg_memory
  bought, buyexec.done, and buyadj[<id>] = {count: stock=, price: unit=, at} - an adjustment AT OR AFTER the snapshot's time overlays the
  snapshot row (the stale-price loop the refuter described cannot happen: unit= rides BOTH outcomes and the next plan quotes the live
  number). Error -> buyexec cleared, the ordinary voicing with lrgMktVoicedWhy: "it is N septims now, not M - say the word and it is his",
  "<player> cannot pay T septims with the G he carries", "has none of that left to sell" / "has only C of that left", "sells nothing of
  the kind", "is not serving at this hour", "did not catch what <player> asked for", the quiet-mode and hostile / arrest / scene wordings;
  the developer dry run names the Diagnostics page (as every glue refusal since pt17).
* NEVER FALSE for prices (lib/lrg_replies.php): class price in never_false.classes; lrgNfContext covers a vendor turn with fresh stock or
  a room price through the pseudo-row 'vendor' (judged on prices ONLY - a merchant's "welcome to the ranks" is nobody's enlistment);
  lrgMktPriceMentions reads a price frame around DIGITS OR NUMBER WORDS ("That'll be one septim", "for twenty gold", "a couple of
  septims") and a number at the start of a clause ("Ale, nineteen septims."); the verdict is true when every figure is in the allowed set
  (the stock prices, the order's total, a live entry cost, the room, the bounty, the purse); the re-ask quotes her sentence and names the
  list's price; the floor lines never_false.lines.vendor carry {item} / {price}; the next-turn note says "claimed a price the game had not
  set". lrgDlgTruthCheck (the second net, action-drop) reads number words too and counts the stock class as a price fact.
* The room: lrgMktRoomCheck logs ONCE per game session (lrg_memory '*market*') when CHIM's RentRoom.cost_gold (10) differs from the
  snapshot's room= (RoomCost 25): "inn: CHIM RentRoom cost_gold=10 differs from the game's RoomCost=25 - set Gold Cost in CHIM's Action
  Editor (RentRoom), or dialogue.market.align_rentroom_cost"; align_rentroom_cost (shipped FALSE) writes the row through CHIM's own config
  API. The inn request block tells her "A room here costs 25 septims - quote only that number".
* 'something to eat' / 'something to drink' left the inn kind (lrg_dialogue.php services.kinds.inn.phrases); test_dialogue 12's
  expectation was changed deliberately ("can i buy something to drink" is barter now, and the market lane takes the turn over).

### 6.5 The game half (LRG_Main.CmdBuy, script 512)
Dispatch ExtCmdLRG_Buy -> CmdBuy, in the CmdQuestEntry order: parse (item hex8 -> HexToInt, n, the quoted price) -> dry run (names the
Diagnostics page) -> bServiceDialogue:Services off -> quiet mode -> the agent -> loaded -> BuyRefuseReason (hostile / combat / an arrest
/ an OStim scene with the player; an AMBIENT quest scene is NO reason) -> the vendor faction (IsVendor, !IsNotSellBuy) -> the hours (GLOB
GameHour 0x38 against GetVendorStartHour / EndHour, a wrapped range crosses midnight) -> the form (Potion.IsFood) -> the source (the
merchant chest with >= n, else her own inventory; "I only have C of those left" / "I am out of X") -> LRG_Profile.BuyPrice recomputed NOW
(one log line with every input) and the re-quote refusal when it moved (;unit=) -> the purse -> BuyWaitForLine (her line started within
fSayFirstWait and stopped within fSayFirstMaxWait, SceneTalk page, polled 0.25 s) -> src.RemoveItem(item, n, false, player) (the vanilla
"<item> added" message is the receipt), player.RemoveItem(gold, total, true, src) (the septims go where the stock came from), IdleGive,
the StorageUtil stamp cleared -> "OK: <n> <name> handed over for <total> septims" with ;unit=;paid=;left=;stock=.
No new MCM toggle (MCM files were not this lane's): the game gates on the existing bServiceDialogue:Services, the server on
dialogue.market.enabled. A dedicated "Buy food and drink by talking" toggle is offered to the orchestrator as a patch.

### 6.6 Regressions caught by the full suites, and the rule that came out of them
The first full flow run failed 11b (SHARMAT) and 16 (non-adult / "never"): the gold class from the snapshot's pgold injected a
<locked_facts> block on EVERY turn with a fresh snapshot. Rule: the market lane's lines (stock, room) ride MARKET turns only
(lrgMktShowFacts), and the purse rides a MONEY turn only (a price / stock / bounty / service fact already in hand). After that:
test_dialogue 559/0 (section 16: 90 checks), test_gates 676/0 (section 38: 35 checks), test_services 40/0 (section 7), test_intent 459/0,
test_prompt_index 85/0, test_mcm_wiring 22/0, test_phrases 47/0, test_stt 60/0, flows 84/84 (1517 checks, 0 warnings; d66_purchase
36/36). compile.ps1 was NOT run (the Build stage does); the .psc were checked mechanically (no try / catch, docstrings < 500 chars,
PapyrusUtil.StringSplit / StorageUtil Get-Set-UnsetFloatValue + Get-SetStringValue / PO3 AddItemsOfTypeToArray signatures verified in the
installed sources).

### 6.7 Owner check, corrected
Look at Hulda (the snapshot fires), order "an ale" and "a honningbrew": expect 19 and 39 at Speech 15 with no price perk; compare with her
barter window and with the "buy price <name> value=.. sp=.. bp=.. price=.." lines in lorerim_glue.log. Ale (19) is the "19" he remembered;
the meads are 39. If the window shows another number the bp= inputs on the line name the wrong one (a Fortify Speech item shows as
spmod=<magnitude>). The Dynamic Pricing Framework is the one runtime layer the model cannot read: a constant factor across every item would
point at it, and dialogue.market.wording stays 'asking' until the check matches.

## 7. REVIEW (2026-09-24) - four defects fixed inline, all suites re-run green (test_dialogue 567/0, test_gates 679/0, flows 84/84)

1. `IsNotSellBuy()` is NOT "sells nothing". A FACT VENV scan of Skyrim.esm (145 vendor factions, 37 with notSellBuy=1) shows the flag
   only turns the vendor list into an EXCLUSION list: every general-goods vendor (Belethor 0009CAF5, Riverwood Trader, Arnleif and Sons,
   Bits and Pieces, Birna, the Khajiit caravans) and Riften's food stall (Marise 000A31CA, Grelka, Brandish) carry it with list 0006CB48
   (VendorItemsMisc) and sell food; every innkeeper carries 0 with 000914F0 (VendorItemsInnkeeper). LRG_Profile.MarketFacts sent vend=0
   and LRG_Main.CmdBuy refused "I do not sell anything" on it - a never-false failure on every general store. Both checks dropped; the
   chest / her inventory decide. PROTOCOL 10.27 text: "IsVendor" only, not "and not not-sell-buy".
2. `Game.GetForm(itemId)` -> `Game.GetFormEx(itemId)` in CmdBuy: the snapshot sends the RUNTIME id, and an ESL-flagged plugin's is
   >= 0x80000000 (installed SKSE Game.psc:442 "GetFormEx ... also works for formIds >= 0x80000000"; LRG_Dialogue already does this
   for voice types). Without it any FE-space food form was "unknown item form".
3. "it is N septims now, not M - say the word and it is yours" was not a real offer: the player's next "yes" hit no pending offer, and
   the game's 60 s stock cache still carried the stale quote, so a repeat order refused again. CmdBuy now drops LRG.mkt.at on that
   refusal and lrgMktResult opens the glue's own one-item offer (buyask) at the live unit= - the confirm path re-orders it
   (test_gates 38(c) has the check).
4. Availability questions were sales on the spot: "do you have any ale?", "do you sell bread", "is there any bread left", "what ales do
   you have" resolved as an ORDER and queued ExtCmdLRG_Buy. New dialogue.market.frames_ask ('do you have', 'have you got', 'got any',
   'any', 'do you sell', 'do you serve', 'is there any'; the last three added to frames_weak too): with such a frame a resolved item
   becomes the ask state's one-item offer ("asked whether you have Ale. Offer the one thing ... Ale (19 septims) ... ask whether he
   wants it") and the next "yes" buys it. Imperatives ("i'll have", "sell me", "get me") stay orders (test_dialogue 16(e), 8 checks).
Not changed, noted for the orchestrator: the price rail reads "for a septim less" as a 1-septim claim (lrgMktNumber treats a bare
'a'/'an' as one); CHIM's RentRoom Gold Cost (10) vs the locked "a room here costs 25 septims" until the owner aligns it; the price
model is a mirror of the engine's formula (no native reads the barter price) and Dynamic Pricing Framework is unmodelled.
