# pt16 - "What do you have for sale?" never opened the trade menu (Jala, Addvar, Evette San, 2026-09-23 16:40-16:43)

Investigator notes, read-only. Line numbers are of the source tree as of 2026-09-23 (glue 0.5.7 / game script 507).
Log = `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log` (times -04:00); chim.log uses +00:00 / +02:00.

## 1. What happened, turn by turn

| time | npc | STT text (`say=`) | svc kind | dlg list | intimacy mode | LLM | evidence (log line) |
|---|---|---|---|---|---|---|---|
| 16:40:01 | Jala | `a for several` (STT retry of a 0.38 s utterance) | - | `list=none layer=unknown n=0` | silent, `no_fresh_snapshot` (age 212647 s) | `action=none` | 2793-2801 |
| 16:40:36 | Addvar | `for sale` | - | none | silent, `no_fresh_snapshot` (age 42945 s) | `action=none` | 2807-2816 |
| 16:41:14 | Addvar | `what have you what have you got for sale sir?` | **barter** | none | silent, `child_nearby` wit=5 | `action=none`; spoke "Got fresh ..." / "... slaughterfish at four septims apiece" (chim.log 22:41:17+02) | 2818-2832 |
| 16:42:31 | Addvar | `thing for sale` | - | none | silent, `child_nearby` | `action=none` | 2837-2844 |
| 16:42:47-49 | Evette San | (owner pressed E: engine dialogue) | `GAME SVC kind=barter layer=0 priced=0/0` | `dlg topics n=4 sent=4 kind=closed`, `closed why=external clicked=0` | - | - | 2848-2863 |
| 16:42:54 | Evette San | `excuse me what do you have for sale?` | - | none | silent, `child_nearby` | `action=none` | 2864-2871 |

Constant all evening: `GAME dlg self-test v400 menuless=0 dryrun=1 wire=1` (2706, 2713) and every dlg turn line carries
`mcm=...ml:0...sv:1,svsc:1,svbn:1,svnp:1,tg:1`. `responselog` in Postgres is EMPTY (0 rows) and `actions_issued` holds one row
tonight (the Lisette escort release), so the function set CHIM offered is not recoverable from the DB; chim.log shows
`openrouterjson: Returning command buffer with 0 commands` for the 16:41 Addvar turn.

Snapshots (`lrg_npc_state.payload`) for all three carry `fac=CrimeFactionHaafingar,TownSolitudeFaction,JobMerchantFaction,
JobStreetVendorFaction,Services<City><Name>,...`, `class=VendorFood`, `ml=0`, `sv=1`, `svx=111`, `sess=8733`.

## 2. Why nothing opened - four independent gaps, all on the path

### 2a. The REAL-ENTRY path (E6) is structurally off, and no list was known anyway
- Game: `ml = bMenuless && !bDlgDryRun` (LRG_Profile.psc:582-592) rides the snapshot; settings.ini `[Dialogue] bMenuless = 0`,
  `bDlgDryRun = 1`. Even with bMenuless on, `CmdSelectTopic` refuses: `!sMenuless -> "Error: the feature is switched off"`
  (LRG_Dialogue.psc:962-964), dry run -> `WOULD CLICK` + `"Error: dry-run mode, nothing was clicked"` (:989-998).
- The calibration is red and FORCES dry run whatever the toggle says: LRG_Dialogue.psc:687-693 (`!sDryRun && !sCalibOverride
  && !calGreen -> sDryRun = true`); tonight's summary `calibration green=0 missing=counting, Reading, progress timer, hide round
  trip, input guard, click route, state read-back, vanilla menu after a hide, Smart Talk settings, menu layout` (2709/2716);
  passive learning did fill rm=3 cm=1 fam=1 st=1 apd=750 at 16:42:47-49, the six remaining ones need `bCalibActive` (MCM help).
- Server: `lrgDlgApplyOffer` (lrg_dialogue.php:1627-1690) reads `ml` via `lrgDlgMcm('ml', 1, npc)` (:1652) and with ml=0 logs
  ONCE per process "ml=0: menuless questing is off (or in dry run), so CHIM's own RentRoom / HireCarriage / HireFerry /
  Training / OpenInventory are LEFT ON THE TABLE" (:1653-1658) - a log fact only.
- For the three merchants no list was cached at all (`list=none`). Evette's E-opened menu at 16:42:47 WAS read (4 entries,
  `GAME SVC ... kind=barter` = `SvcScan` :1915-1960 / `SvcKindOf` :1962-1985 found sale/wares/trade in the visible entries, i.e.
  the real `OfferServicesTopic` "What have you got for sale?" was on it) but it is diagnostic: PROTOCOL 10.15 W13 "nothing
  branches on it, on either side", and the owner closed it 1 s later (`why=external clicked=0`).

### 2b. CHIM's OpenInventory IS a barter menu, was (as far as the code shows) offered, and the LLM never chose it
- `core_action` id 30: `OpenInventory` / `Trade_Items`, `is_activated t`, description "Initiates trading or exchange items with
  #PLAYER_NAME#.", `metadata.dispatch plugin_command`, NO requirements (V05 plan 18.1 table agrees).
- AIAgentAIMind.psc:929-987: `OpenInventory2` -> `ShowGiftMenu` (:941-943); a teammate in CurrentFollowerFaction ->
  `npc.OpenInventory(true)` (:946-948); EVERYONE ELSE -> `npc.ShowBarterMenu()` (:951, :954, :983; the "commerce rules" block
  is `if (false)`). So the task's premise "an inventory, not a barter menu" is wrong for non-followers: it is the vanilla barter
  window on ANY NPC, vendor or not (that is exactly p2-requiem.md R1's complaint).
- Not hidden tonight: the hold hid only the 7 movement codes (log 16:41:14 `dlg hold ... hidden`; `CONV_MOVE_ACTIONS` has no
  OpenInventory); the intimacy lane hides `LRG_MOVEMENT_ACTIONS` (which DOES contain OpenInventory, lrg_actions.php:247-250)
  only in the outro / scene branches (:1430, :1447, :1539), never in mode `silent`; `lrgDlgServiceHidePolicy`
  (:3675-3707) hides the per-kind codes only when `$known = (entries||tail) && $menuless` (:3683) or when bServiceShortcut is
  OFF (:3699-3703) - tonight `svsc:1`. The function list itself is not logged anywhere (open question 1).
- Nothing in the prompt asked for it: `lrgDlgStaticGuidance` returns '' with no list (:1766-1772), `lrgDlgServiceBlock` returns
  '' unless the live layer is a price list (:1836), and the intimacy lane's `lrgBuildRequestDirective` (lrg_intent.php:1504+)
  only knows its own kinds (`intent=none/- why=no pattern matched` on every turn line). The one `svc=barter` fact (16:41:14)
  reached the log line (`lrgDlgTurnLine` :4277) and nothing else.

### 2c. Four of the five utterances were never recognised as barter
`lrgDlgServiceKind` (lrg_dialogue.php:3473-3483) = exact normalised token-run containment (`lrgDlgPhraseHit` :3029-3041) of
`services.kinds.barter.phrases` (:169-172: "what have you got for sale", "let me see your wares", "show me your goods",
"i want to sell", "i'd like to trade", "do you buy", "anything to trade", "your wares", "what are you selling",
"let me see what you have"). Run offline against the real function (staged copy, WSL):

| utterance | kind today |
|---|---|
| `a for several` | - (STT noise, unfixable here) |
| `for sale` | - |
| `thing for sale` | - |
| `what have you what have you got for sale sir?` | barter |
| `excuse me what do you have for sale?` | - |
| `what do you sell` / `anything for sale` / `i want to buy some fish` / `can i buy something` / `got anything to sell` | - |
| `how much for the salmon` | - (price question: `lrgDlgIsPriceQuestion` yes) |

`service_words` (:309-311) already has `sale`, `goods`, `merchandise` (S-8) but that list classifies ENTRIES, not the player's
words. The intimacy lane's `lrgIntentGoodsTalk` (lrg_intent.php:306-310) knows "for sale" but only guards the money pre-scan.

### 2d. NEVER SILENT (owner addendum 11) was violated on every turn
No corner note, no voiced reason, and Addvar invented a per-item price ("four septims apiece") - E7's rule is that the barter
window is the price authority; the truth gate (:2297-2305) only drops an ACTION that acts on an unconfirmed number, it never
touches her words. `bServiceShortcut` today: svx byte 0 (LRG_Profile.psc:577-579 -> lrg_dialogue.php:437-441 `svsc`), consumed
ONLY at :3699-3703: OFF hides the shortcut codes even with no list. ON is the do-nothing state, which is why it "did not fire".

## 3. What Papyrus can know about "is she really a vendor / may she trade now"
- `PO3_SKSEFunctions.GetVendorFaction(Actor)` (powerofthree's Papyrus Extender/Source/scripts/PO3_SKSEFunctions.psc:101,
  already imported by compile.ps1) -> the `Services*` faction or None. SKSE `Faction.psc`: `IsVendor()` (:100, flag test),
  `GetVendorStartHour()` :115, `GetVendorEndHour()` :118, `GetVendorRadius()` :121, `GetMerchantContainer()` :124,
  `IsNotSellBuy()` :127. Game hour: GlobalVariable `GameHour` (Skyrim.esm 0x38).
- `Actor.ShowBarterMenu()` is vanilla native (SKSE Actor.psc:650; used by `DialogueGenericVendor` fragments and DAK's
  `DAK_MerchantTradePerkScript`, research/p2-lorerim-dialogue-stack.md:136). "Fails if the actor isn't loaded" -> `Is3DLoaded()`.
- The vanilla `OfferServicesTopic` INFO conditions (`GetOffersServicesNow` etc., `nconds=3` in the index) are engine condition
  functions with no Papyrus twin; hours + vendor faction + not hostile / combat / arrested / dead / in a scene with the player /
  no menu open is as close as Papyrus gets, and the barter window itself then applies Requiem / LoreRim prices exactly as the
  real entry would (same window).
- Say-first exists already: `AIAgentFunctions.isActorTalking(name)` with `fSayFirstWait:SceneTalk` (6 s) /
  `fSayFirstMaxWait` (20 s) / `fSayFirstSettle` (1 s) - LRG_OStim.psc:710-760.
- Game-side pattern to copy: `ExtCmdLRG_Escort` (LRG_Main.psc HandleCommand :1614-1616, `CmdEscort` :1668-1712,
  `EscortRefuseReason` :1714-1745, `EscortSayRefusal` :1747-1765, state fields :296-305 cleared in Maintenance :355-363,
  `TickEscort` :2151-2213 driven from OnUpdate :1323-1325 via `RequestTick`). `note=` on ANY ExtCmdLRG_ command already
  raises `Debug.Notification("LoreRim Glue: ...")` before dispatch (:1568-1576), `kind=hint` is the only gated note.

## 4. Decision on (c) - bMenuless default
Keep `bMenuless = 0` / `bDlgDryRun = 1` shipped. Flipping bMenuless ON gains nothing today: dry run is FORCED by the red
calibration (:687) so no click path opens either way, while bMenuless=1 changes what the owner sees in dry run (every E
conversation is armed and logged WOULD CLICK, and `do=open` -> `StartOpen` :1085-1100 opens a VISIBLE vanilla menu on the player
whenever he says a business sentence - untested by him; the plan's own acceptance ladder puts "bMenuless ON with dry run still
ON" as a deliberate owner step, V05_EXPANSION_PLAN.md:1328-1381 / 2062-2070). What the lane really needs is the second half of
the sentence: services that click NOTHING (barter via `ShowBarterMenu`, corner notes) must run live regardless of `ml` - gated by
`bServiceDialogue` and the General dry run only. The MCM help must say so (section 6).

## 5. Baseline test runs (2026-09-23, WSL, staged copy)
The distro cannot see the project root (`ls /mnt/c/Users/Jordan/AppData/Roaming/Claude/` -> No such file), and
`run_flows.php` refuses to run inside HerikaServer, so `glue/server` + `glue/tools` were copied to
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\glue_stage` and run from there. The census is not in the source tree:
`--file=/var/www/html/HerikaServer/ext/lorerim_glue/data/service_catalog.json`.
- `php tools/test_services.php --file=...` -> 24 passed, 0 failed (section 6 notes "no prompt_index.ndjson").
- `php tools/test_dialogue.php --quiet` -> 174 passed, 0 failed.
- `php tools/test_phrases.php --quiet` -> 47 passed, 0 failed.
- `php tools/flows/run_flows.php --only=d20,d29,d56` -> 3 passed (52 checks). (`--only=d56` alone reports PENDING
  "lrgDlgCfg() is not delivered yet" because the scenario touches `lrgDlgCfg` before `fxDlgLoad()` ran - run it with d20.)
- Note for the deploy: the log says `prompt index is STALE: the load order changed (index hash e756e311, live 01414bfe)`
  (16:39:27, 16:40:32, 16:41:31, 16:42:39) - `build_prompt_index.py --services` + `lrg_prompt_index.php load` is due.

## 6. Proposed fix (smallest correct change) - see the structured answer for the file list
Server (SUPERSEDED by section 7 - built without the game half): barter phrases extended; `$turn['svc']['direct']` (barter, not a price question, not negated, no ml=1 real-entry
session); hide OpenInventory/OpenInventory2 on that turn (D4); a `<player_request>` block for every service kind under ml=0
("do it with <CHIM name> or say why - never pretend"); post-LLM net appends `ExtCmdLRG_Service@...;do=barter` when the reply
carries no trade action, and a throttled `do=note;note=...` for inn/carriage/ferry/train under ml=0; inactive catalog row +
voiced wording so a refusal is spoken. Game: `CmdService` in LRG_Main (escort pattern): gates -> say-first -> `ShowBarterMenu()`
-> `OK: The wares are shown.` / `Error: <her words>` with `err=`. MCM help rewritten for bMenuless, bDlgDryRun, Calibration,
bServiceDialogue, bServiceShortcut.

## 7. What was built (implementer lane, 2026-09-23 17:30-17:55) - and what the refuters corrected

Two refuter passes on section 6 changed the shape of the fix before it was built. Their corrections, applied:
- **Not a new command.** No `ExtCmdLRG_Service`, no `CmdService` / `TickService` in Papyrus, no catalog row, no version
  bump. PROTOCOL 10.15 D4 already makes CHIM's shortcut the fallback when no session is possible, and CHIM's
  `OpenInventory` (LLM name `Trade_Items`) IS the vanilla barter window (`AIAgentAIMind.OpenInventory` ->
  `npc.ShowBarterMenu()` for every non-teammate, `npc.OpenInventory(true)` for a teammate). The glue now carries barter
  on that code deterministically instead of hoping the model picks it. A Papyrus `OtherMenuOpen("BarterMenu")` check
  from OnUpdate would never have run anyway - BarterMenu pauses the VM until it closes.
- **Section 2b over-claimed.** The model did NOT choose `action=none` *because* nothing asked for it: the same model
  chose `Trade_Items` for Beirand twice this morning under identical conditions (glue log 04:41:26 / 04:42:32 -04:00
  `llm npc=Beirand ... chim=OpenInventory`; chim.log 10:41:26+02 `Beirand|command|OpenInventory@Jordan`, the first
  with no spoken line). Model selection is simply unreliable (2 of 7 barter turns today) and CHIM's own path can open
  the window silently - which is the real argument for a deterministic server-side line. Whether CHIM's OpenInventory
  really opened the window on Beirand is not verifiable (AIAgent.log was rotated at 16:33).
- **"No list was cached for any of the three merchants" was wrong for Evette.** Session D8733-460 (16:42:48) captured
  four entries, `entries[0]` = "What have you got for sale?" `topic=OfferServicesTopic class=service`. The 16:42:54
  turn read `list=none` because `lrgDlgListFor` (lrg_dialogue.php) retains only open / pending, lost (<= 120 s) or a
  ROOT layer, and that layer was `kind=closed`. A street vendor's greeting layer classified closed is not retained.
- **A missing snapshot must not block barter.** Jala 16:40:01 (age 212647 s, no `mcm=` on the dlg line, `ml` defaulted
  to 1) and Addvar 16:40:36 (age 42945 s) were first-contact turns with no usable snapshot. The direct decision treats
  a missing or stale snapshot as "not combat, not a companion, vendor unknown" and goes ahead.
- **The intimacy lane's blind note contradicts a trade directive.** On those two turns `lrgVolatileGuidance`
  (lrg_actions.php ~3534-3537) injected `<this_moment> ... nothing physical can be set up, agreed or carried out on this
  turn ... Do not act anything out` at prompt_bottom 900 - and it steered the model away from every action, CHIM's
  Trade_Items included. The barter directive (prompt_bottom 910, i.e. after it) now says in so many words that it
  overrides that sentence; the carve-out inside lrg_actions.php itself is another lane's file and is handed to the
  orchestrator as a patch (structured answer).
- **Versions.** The tree is LRG_VERSION 0.5.6 / manifest 0.5.6 with LRG_Main.psc CurrentVersion 507. Nothing here bumps
  anything: no wire change, no game change.

### 7.1 Server (`server/lorerim_glue/lib/lrg_dialogue.php`, the only server file touched)
- `services.kinds.barter.phrases` += `for sale`, `what do you sell`, `do you sell`, `anything to sell`, `got anything to
  sell`, `i want to buy`, `i'd like to buy`, `can i buy`, `let me buy`, `buy something`, `see your goods`, `your goods`,
  `sell me`, `like to trade`, `trade with you`. Every STT shape of tonight (`for sale`, `thing for sale`, `excuse me what
  do you have for sale?`, `what have you what have you got for sale sir?`) and the owner's literal `what do you have for
  sale` now classify as barter; `a for several` stays nothing (STT lane); `let's trade` stays the follower kind (d62).
- New config `services.direct = {barter: true, request_block: true, combat_max_age_seconds: 90}`.
- `lrgDlgServiceDirect($turn, $utter, $snap, $isSpeech)` - THE ONE DECISION, computed in `lrgDlgPrepareTurn` and stored
  as `$turn['svc']['direct'] / ['direct_why'] / ['vendor']`. direct=1 when: kind barter, config on, a player-speech
  turn (never the module's own `lrg_dlgtalk`), not a price question (`lrgDlgIsPriceQuestion`), the phrase not negated
  within six tokens / same clause (`lrgDlgNegatedAt` + `lrgDlgClauseStarts`: "don't sell me anything"), NOT (ml=1 with
  a known list - there `hide_chim` takes OpenInventory away for the real entry, D4), no FRESH snapshot (<= 90 s) saying
  `combat=1`, not a companion (`lrgEscortFacts()['owner']` when the intimacy lane is loaded, else `mate=1`), and the
  snapshot did not list her factions WITHOUT a vendor faction (`lrgDlgVendorHint`: `class=Vendor*`, `Services*`,
  `JobMerchant / JobStreetVendor / JobInnkeeper / JobBlacksmith / JobApothecary / JobFletcher / JobSpell / JobFence /
  ...Faction` -> vendor; a snapshot with factions and none of those -> `none`; no snapshot -> `unknown`, which does
  NOT block). `fac=` is capped at 48 factions / 560 chars, so `none` is never spoken as "she sells nothing" by the
  server - it only leaves the choice to the model.
- `lrgDlgServiceRequestBlock($t)` - a `<player_request>` in `lrgDlgVolatileGuidance` (after `<her_list>`, before
  `<follower_commands>`), player-speech turns only, for every recognised service kind:
  - barter, direct and `OpenInventory` on the table: "Do it now: choose Trade_Items in this same reply - that is what
    shows <npc>'s wares - and say ONE short line ... Name no prices and list no goods ... Nothing said above about this
    moment or about missing facts changes that - showing wares is done by the game itself. Never ignore it."
  - barter, price question: never invent a price; choose Trade_Items so he sees the real prices (or say he will see them).
  - barter, companion: choose Trade_Items (it opens what she carries) - never a refusal.
  - barter, combat: the plain reason, no action. barter, real entry / negated / dlgtalk: nothing (the `<business>` block,
    or nothing, applies). barter, `OpenInventory` NOT on the table: "cannot show any wares this moment: say so plainly".
  - inn / carriage / ferry / train, ONLY under ml=0 and no price list: "Do it now: choose Rent_Room / Hire_Carriage /
    Hire_Ferry / Training if <npc> really offers that ... otherwise say plainly why not" when the code is offered AND
    not hidden by the hold (`lrgDlgHiddenThisTurn`, so a held NPC is told to say why); else "there is no action for it
    this turn: say plainly why not". A price question -> answer in words, choose no action, name no unshown price.
- `lrgDlgServiceNet($t, $out)` - in `lrgDlgPostProcessActions`, after the truth gate and BEFORE the corner hint: when
  direct=1 and the reply carries no `OpenInventory` / `OpenInventory2` / `Trade_Items` / `Accept_Gift` / `SelectTopic`
  line FROM THIS NPC, and `OpenInventory` is offered and not hidden this turn, it appends exactly
  `<npc>|command|OpenInventory@<player>\r\n` (the line CHIM itself emits for Trade_Items) and logs `net: OpenInventory
  appended - ...`. Never both: a model line passes untouched and nothing is added. Phase 2's gate runs AFTER Phase 1's
  (functions.php order), so Phase 1's "not offered" rail never sees it.
- `lrgDlgServiceHidePolicy`: `bServiceShortcut = OFF` still hides RentRoom / HireCarriage / HireFerry / Training with no
  list, but no longer `OpenInventory` / `OpenInventory2` - the owner's reason for that switch was a flat 10 / 20 / 50 gold
  where the real entry knows the real price, and the barter window IS the real one.
- Turn line: ` direct=barter` / ` direct=no:<why>` on every barter turn.

### 7.2 MCM (`game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json`, help texts only; JSON validated, no BOM)
`bMenuless` (+ "Trade is NOT behind this switch ..."), `bServiceDialogue` (+ trade opens the real window with no
menuless needed; rooms / rides / training need menuless + green calibration, until then CHIM's own actions answer and
she says so when she cannot), `bServiceShortcut` (+ "This never affects trade"). No new setting, settings.ini untouched
by this lane (the follow lane added `bNaturalFollow:Followers` to both files at the same time).

### 7.3 Tests (staged copy under Temp, WSL - the distro cannot see the project root)
- `tools/test_dialogue.php` section 12 (53 new checks): the matcher on tonight's exact STT strings; the decision
  (vendor / no snapshot / fresh vs stale combat / teammate / price question / negation / ml=1 + list / ml=0 + list /
  non-vendor / dlgtalk / other kind); the directive shapes; the net's exact wire line, never twice, other actor, not
  offered, hidden this turn; bServiceShortcut off hides rooms / rides / training but never OpenInventory.
  -> `227 passed, 0 failed` (was 174).
- `tools/flows/scenarios/d56_services.php` (33 new checks): ml=0 + fresh vendor snapshot + the owner's words ->
  OpenInventory stays offered, direct=barter on the turn line, the directive, exactly ONE `OpenInventory@Flowtest Player`
  line when the model chose nothing and none added when it chose Trade_Items itself; the four STT fragments each open
  the window and `a for several` does not; a 400 s-stale snapshot still opens it and the override sentence is present;
  ml=1 + the real entry on the list -> OpenInventory hidden, `<business>` block, no line; the guards (negation, price
  question, companion, fresh combat, non-vendor) each block the line, a companion is still named the action;
  `how much for the salmon` is no request; bServiceShortcut off keeps the window; OpenInventory deactivated -> no line
  and "cannot show any wares"; inn under ml=0 -> "Do it now: choose RentRoom", no wire line of ours; inn under ml=1 ->
  no such directive. -> d56 `64 ok, 0 fail`.
- Full runs from the staged copy: `test_dialogue.php` 227/0, `test_phrases.php` 47/0, `test_services.php --file=...`
  24/0, `test_gates.php` 459/0, `test_intent.php` 316/0, `test_mcm_wiring.php` 4/0 (once the follow lane's LRG_Main.psc
  was in the stage; with a stale copy its new `bNaturalFollow` control had no reader), `run_flows.php` all 78 scenarios
  -> 77 + d53 green on the re-staged tree (1349 checks).

### 7.4 What this does NOT do (owner steps unchanged from section 6, minus the game half)
- Her line and the window race: CHIM dispatches the command with the reply, and BarterMenu pauses the game, so a late
  TTS can be cut. That is CHIM's own Trade_Items behaviour (Beirand 04:41:26 opened with no line at all); a game-side
  say-first wait would be a v0.5.8 item if the owner minds.
- Words are not gated: the directive forbids prices and goods lists, the truth gate only ever drops actions.
- Rooms / rides / training / quest lines through the REAL dialogue still need menuless ON + a green calibration.
- The blind-note carve-out inside lrg_actions.php (intimacy lane's file) is a patch for the orchestrator, not applied here.
