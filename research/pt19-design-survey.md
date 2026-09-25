# pt19 - design survey: the map of menuless questing as it exists today (SURVEYOR, read-only)

2026-09-24. Nothing in the glue was edited. Sources, in order of authority: `glue/PROTOCOL.md` 10.x (marked **[P]**),
the code where PROTOCOL is silent (**[C]**, file:function), the live index `/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson`
(43,280 lines, header + 37,561 rows, hash e756e311, built in 33.4 s over 3,496 plugins; marked **[X]**, census script
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\design\survey_index.py`, rows in `quest_rows.tsv`, per-quest summary in
`quest_summary.txt`), research notes (**[R]** file), and inference (**[I]**). Currency: septims. No designs are proposed here.

Installed versions this map describes: server 0.5.7+pt18 (catalog v11), game script **510** (LRG_Main.psc:36), index v1.

---

## 0. The shape in one paragraph

The player talks. CHIM sends `inputtext` / `inputtext_s`. The server's Phase 2 lane (`lib/lrg_dialogue.php`) builds one turn
record from what it already knows about that NPC (her cached or live menu list, the last snapshot, the last facts line), ranks
the entries into keys `T1..T12`, tells the model about them in a `<business>` block, and lets the model choose ONE key (or
`leave`, or words). A post-LLM gate turns that choice into exactly one `ExtCmdLRG_SelectTopic` command, or nothing, after
a fixed ladder of rails (class, crit, arrest, freeze, affordability, check rails, two-step confirmation). The game
(`LRG_Dialogue.psc`) resolves the command against the list that is live NOW inside a hidden real Dialogue Menu session and
clicks it; the ENGINE does everything else (conditions, fragments, stages, gold). When no list is known yet, the server asks
the game to OPEN a session for awareness and the freshly read list comes back on `lrg_topics want=1`, which the server answers
without an LLM (the fast path). Everything that is not a click is words, and every refusal has a voiced reason.

---

## 1. How a player sentence becomes a click today, end to end

### 1.1 The shared front half - the turn build (server, every request)

Registered at brace depth 0 in `functions.php` **[C functions.php:14-81]**, in this order: `lrgPrepareTurn()` (Phase 1) ->
`LRG_POSTGATE` registered -> `lrgDlgPrepareTurn()` -> `LRG_DLG_POSTGATE` registered -> `lrgNeRegister()` (never-empty /
never-false) -> `lrgDlgHideEndConversationOnHold()` -> `lrgDlgHoldMovementPolicy()` -> `lrgFollowerPolicy()` (the last three
only ever REMOVE actions). `context_pre.php` repeats `lrgDlgPrepareTurn()` when it has not run (SHARMAT) **[C context_pre.php:49]**.

`lrgDlgPrepareTurn()` **[C lrg_dialogue.php:1574-1757]**, per request:
1. `cid` = `d` + 8 hex, unique per REQUEST (the two-step release needs a different request, not a different second).
2. The player's words -> `utter{text,at,type}` in the NPC's `lrg_dialogue` row (`lrgDlgPut`), 400 chars.
3. Snapshot (`lrgGetNpcState`): `scene`, `ostim`, and the MCM carriers (`ml`, `cal`, `chk`, `bias`, `ql`, `qi`, `qig`, `qx`,
   `svx`, `sv`, `lf`, `tg`, `pgold`, `hold`, `fol`, `fac`, `class`) **[P 1.1, 10.12, 10.12a; C LRG_Profile.psc:537-598]**.
4. `lrgDlgListFor()` **[C :1504]** picks WHICH list this turn sees: `pending` (session state open/pending, its entries),
   `lost` (a session closed with a pending layer, kept `session.lost_seconds` 120 s), `root` (the cached root list of this NPC:
   `cache.max_age_seconds` 1800, same location, same quest signature `lrgDlgQsig`), else `none`.
5. Gates from Phase 2's OWN state: `scene`/`ostim` -> `on=false` unless AMBIENT (`lrgDlgSceneAmbient`: facts line `sq=` matches
   `dialogue.scenes.ambient` globs, `sqj=0`, facts younger than 300 s) -> `ambient=1`, words on, open refused; override file
   `vanilla` mode -> off **[C :1608-1636; P 10.24]**.
6. `lrgDlgRank()` **[C :1533]**: a CLOSED layer = all visible entries in engine order, cap `entries.closed_cap` 12; a ROOT list =
   scored (quest in `q` +4, journal +1, new +3, tagged/priced +2, service +2, word overlap with the utterance x6, filler quest -3,
   shared prompt over 20 topics -2, quest colour +3 only with `quest_colour`), top `entries.head` 8 re-sorted to engine order,
   the rest = `tail`. Keys `T1..T12` -> `offer{turn_cid,keys,at,sid,gen,list}` persisted.
7. `lrgDlgCheck()` (free-conversation check, `lib/lrg_speech.php`) on a speech turn; `lrgDlgGroundTruth()` (<what_just_happened>);
   `lrgDlgQuestBlock()`; `ask` kind (`lrgDlgAskKind`: list / next); `svc` (kind from the player's words, `lrgDlgServiceSlot` on the
   live layer, `direct` barter decision); `fol` verbs really on the list; `arrest` class; `faction` (`lrgFacTurn`: the ask, the
   road, the click-free plan); `locked` facts; `initiative`.
8. `lrgDlgApplyOffer()` **[C :1771]** hides actions through `lrgHideActions()`: `TakeUpBusiness` unless `on && (speech||talk)`;
   the intimacy actions + `EndConversation` while a session is open/lost; with **`ml=1`** (default when absent): `hide_always`
   (ForgiveCrime), `hide_chim` (RentRoom, HireCarriage, HireFerry, Brawl, Training, OpenInventory, OpenInventory2) whenever ANY
   list is known, `hide_gold` (GiveGoldTo, TakeGoldFromPlayer) when a priced or bribe entry is listed; the per-kind service hide
   policy (`lrgDlgServiceHidePolicy`, a subset by contract; `bServiceShortcut` off hides shortcuts even with no list, at any ml).
   With `ml=0` CHIM's own shortcuts stay ON THE TABLE (logged once: "still learning (N of 10)").
9. One `dlg turn npc=.. type=.. on=.. list=.. n=.. ..` log line per real turn (`lrgDlgTurnLine`).

Prompt side **[P 10.10; C :1910-2100]**: `lrgDlgStaticGuidance` -> `<real_business>` (character_bottom, <=70 words, only when
a list is known); `lrgDlgVolatileGuidance` (prompt_bottom) = `<business for=".." state="things X can raise | NPC is waiting for
an answer">` with `T<n> [label] <text verbatim minus the leading tag> {QuestID}` lines, `(+N more: words)` on a root list or
"N further answer(s) ... ask which" on a closed layer, the rules (one key ONLY when his last words clearly do that thing; two
fit -> ask; never leave message empty; a [commits] key asks first and executes on confirmation; while `sent < n` never deny a
matter exists; the foreseen hand-back clause) + `<shared_business>` + `<what_you_can_ask>`/`<your_quests>` + `<her_list>` +
`<player_request>` + `<follower_commands>` + `<she_may_raise>` + `<what_just_happened>` + the check directive + the never-empty
note. `<locked_facts>` is a separate injection at character_bottom priority 205 (`lrgDlgLockedBlock`, gated by `lf`), faction
lines FIRST, 600 chars / 6 lines. `lrgDlgJsonTemplate()` puts the action before the message on speech turns (`reorder_json`
'always') and appends the never-empty clause to the message description.

### 1.2 Path A - the model picks a key on a KNOWN list (the LLM turn)

1. The model answers `{"action":"Take_Up_Business","item":"T3", "message":"..."}` (three spellings gate: `ExtCmdLRG_SelectTopic`,
   `Take_Up_Business`, `TakeUpBusiness` - `LRG_DLG_GATE_NAMES`) **[P 10.4]**.
2. `action_post_process_fnct_ex` runs, in order: Phase 1 `lrgPostProcessActions` (passes SelectTopic through untouched; appends
   the escort and `lrgFacQuestNet` lines), then Phase 2 `lrgDlgPostProcessActions()` **[C :2430-2551]**, then the never-empty /
   never-false hook.
3. `lrgDlgPostProcessActions()`: drops any code this module hid this turn; keeps ONE SelectTopic line per turn; the actor must be
   this turn's NPC; hands the item to `lrgDlgGateItem()`. Afterwards: the free check's `do=award` line; the truth gate
   (`lrgDlgTruthCheck` -> drops money actions on an unconfirmed quoted price/destination when `tg=1`); `lrgDlgServiceNet` (direct
   barter); the quest corner hint `do=noop;note=` (once per session, only on a turn that emitted nothing); the automatic
   calibration `do=open;calib=1` (lowest priority, idle turn only); `do=release` PREPENDED when a held NPC was asked to move.
4. `lrgDlgGateItem()` **[C :2551-2727]**:
   - `leave` -> `do=leave` naming the back-out entry (`class=back`) if one is listed, else `pos=-1` (the engine cancel).
   - `T<n>` -> the entry of THIS turn's offer by `pos`+`norm`; anything else = "dropped stale".
   - words (the model named the business) -> the pool = entries + tail minus `hidden`; **no pool -> `lrgDlgMaybeOpen()`** (1.3);
     with a pool, in this order: `lrgDlgFollowerArbitrate` (exact containment on follower entries) -> `lrgFacArbitrate` (exact
     join topic/line) -> `lrgDlgServiceArbitrate` (the price-list slot matcher) -> `lrgDlgMatchText` (similarity: exact 1.0,
     containment 0.85, word-F1 0.35+0.65*F1, `similar_text`*0.8; needs `match.min_score` 0.55 and `match.min_margin` 0.15) and
     then ONLY `plain` / `service` classes.
   - the rail ladder (first hit wins): `hidden` -> null; **resist arrest -> `do=show` (lethal) at any setting**; a follower
     `never_topics` entry -> null; **arrest-class session -> `do=show`** (unless session crit==1, which the game never sends);
     **freeze rule B1** (the purse moved since the list was read) -> null (re-read); assisted (two lost layers with this NPC) on a
     closed layer -> `do=show`; `bi=2` on a closed layer -> `do=show`; `crit==2` (entry or session) -> `do=show`; `meta` -> null;
     `pay`/cost and not `afford` -> null; a check kind -> `lrgDlgCheckRails` (section 4) -> null on a reason; **two-step**:
     `commit` || class `commit` || `lrgDlgScoffFirst` -> `lrgDlgParkOrRelease` -> `parked` = null (her question is the message),
     `release` = falls through; -> `lrgDlgEmit(do=pick, sid, gen, pos, i, txt, kind, cost)`.
5. `lrgDlgEmit()` **[C :3010]**: `x` = 10 hex; param in the FIXED order of 10.4 up to `z=1`, then `note=` / `svc=` / `calib=1`;
   `last_exec` stored; **D2 always** (`lrgDlgQueue` -> a `responselog` row echoed on the DLL's 5 s poll); **D1 only on the fast
   path** (`lrg_topics` / `lrg_dlg` requests, where the server may `echo`). On an LLM turn the line is returned in the action list
   and CHIM sends it with the reply - so an LLM-turn pick still arrives twice (CHIM's own line + the D2 row).

### 1.3 Path B - first contact: open for awareness, then the `want=1` fast path (no LLM)

1. No list known -> `lrgDlgMaybeOpen()` **[C :2942]** needs: `io` (bIntentOpen) on; NOT an ambient scene actor; `lrgFacRefusesOpen`
   false (a redirect never opens; a recruiter under ml=0 never opens; a closed road never opens; a queued quest entry never
   opens); a business marker (`lrgFacMarker`, a service word, a quest in `q`, an index hit on the item, or a >=0.5 hit against
   the stale root cache). Then `do=open;sid=0;gen=0;pos=-1;ask=<item words>`.
2. Game: `CmdSelectTopic` verb `open`, no live session -> `StartOpen` -> `OpenBlockedReason` (dead/not loaded, combat, a menu
   already open, `Utility.IsInMenuMode`, ANY scene when `iSceneGate` 0 (shipped), OStim, asleep/unconscious/bleeding out,
   sneaking/mounted/beast form, distance > min(`fOpenDistance` 200, 0.85 x `fAIInDialogueModeWithPlayerDistance`)) -> the pump
   -> `DoOpen` (voice keeper `VkRestore` <=3 s, `npc.Activate(player)` up to 3 tries x 1 s) **[C LRG_Dialogue.psc:1304-1586]**.
3. `OnMenuOpen("Dialogue Menu")` -> `RunSession` -> `Arm()` **[C :1648-1876]**: `GuardReset()` on every open; speaker =
   `Game.GetDialogueTarget()`; `origin` glue|engine; `crit = ClassifyCrit` (**2 only when `IsGuard()` AND (engine-opened OR
   crime gold > 0)**, else 0); `inScene`; `route` from the SWF family; `sessQ` (quests); `sgOpen` (five Speech globals); the
   passive calibration hooks; the visible reasons: `module off`, `smart talk skip`, `asked for`, **`lethal`** (crit 2 with
   `iCritical` 0 or `bCrimeManual`), `scene`, **`assisted`** (engine-opened while `iEngineOpen` 0 - the shipped default, so
   EVERY forcegreet / blocking-branch session the ENGINE opens runs visible and is only harvested), `unknown swf family`,
   `hide refused`. Driving and not dry-run -> `Guard(true)`, `Hide(iHideMode)`, `HideCursor`. `SendOpen()` = `lrg_dlg ev=open`
   with `cal=`, `svck=`, `sg=`. Then ST_LISTENING or ST_MANUAL.
4. The loop `Step()` at 0.1 s (0.5 s in PENDING/MANUAL/SUSPENDED), 900 s hard cap **[C :1887-2028]**: LISTENING -> READING
   (`ReadList`: read mode 3/4 (calibrated), head <= `iMaxEntries` 16 with topicIndex, tail texts <= `iTailMax` 40, signature
   `count|first|last|idxsum`; a changed signature -> `gen++`, a scene that STARTED inside the session -> hand-back, `FreezeCapture`,
   `SvcScan`) -> DECIDING: `SendTopics(want=1)` = `lrg_topics` (`e=` <= 1,600 raw chars + a `part=2` tail of texts, `n` = the full
   count, `ask=` the item words, `crit`, `scene`, `pg`/`bamt` only with a gold tag, `sp`, `perk`, `lvl`, `svck`, `calib`) and wait
   `fDecideTimeout` **4 s**.
5. Server, in `preprocessing.php` before the MAIN lock: `lrgDlgOnTopics()` **[C :719]** parses, merges part 2, `lrgPromptLookup`
   (layer fingerprint, then exact norm, then token pattern, then none **[P 10.8]**), `lrgPromptLayerKind`, `lrgDlgDecorateEntries`
   (kind/variant/class/cost/afford/commit/label per entry), stores `session{sid,gen,layer,origin,crit,scene,state,kind,entries,
   path,pg,sp,cont}` and, for a root list, the `root` cache; `want=1` -> `lrgDlgAnswerWant()` **[C :2221-2382]**: a calibration
   session -> `lrgDlgCalibPick`; an enlistment -> `lrgFacArbitrateWant`; a price list -> the slot matcher (the similarity matcher
   is NOT consulted); else the best of the model's `ask=` and the player's last utterance through `lrgDlgMatchText` (0.55/0.15);
   then the same rails as the gate: scene, LETHAL, resist arrest, arrest class, **any consequential entry (crit>=1, commit,
   walkaway, class commit/meta) is NEVER executed from intent mode**, afford, `bi=2` closed, class `plain`/`service` (+`pay` on a
   same-session continuation: score >= 0.70, margin >= 0.25, closed layer, depth 1, no twat); then `lrgDlgEmit(do=pick)` -
   **D1 echoed in the same HTTP reply (~0.5 s) + D2**.
6. Game: the D1 reply reaches `CmdSelectTopic` with a live session -> `reqPickReady` -> `TryResolvePick()` (pos + i for this gen,
   or a tail position with the live text verified, or a UNIQUE prefix hit on `txt=`; a tail pick with no `txt` is refused) ->
   CLICKING (1.4). On the decide timeout: `EnterPendingOrManual` -> PENDING only when glue-opened AND hidden AND crit != 2 AND not
   in a scene (`Park()`, corner note "<npc> is waiting for your answer", `fSilenceTimeout` 45 s -> hand-back "watchdog"); else
   hand-back at once. The PENDING list is what the next LLM turn sees as `list=pending` (Path A).
7. When the session closes with no click (`Finish`: glue-opened, no click, no scene, menuless on, wire on, not refused, not a
   calibration session) the game sends `requestMessageForActor("again", "lrg_dlgtalk")` - the ONE extra paid LLM turn of first
   contact, admitted by the server only while it holds a <=30 s utterance and no session is open **[P 10.3; C :2943-3046]**.
   That is why a D1 failure turns a business turn from 6-9 s into 11-14 s **[P 10.6]**.

### 1.4 The game half of a pick - `CmdSelectTopic` -> click -> result

`CmdSelectTopic` **[C LRG_Dialogue.psc:1088-1304]** returns inside one frame, no waits: `x` in the ring of 8 -> silently dropped
(the second delivery); `attached`; `ReadSettings`; `sMenuless && IsEnabled` else `Error: the feature is switched off`; `ok=1`;
`getAgentByName`; `RefMatches(ref)`; a live session with ANOTHER speaker -> `a conversation is in progress`; a live session the
glue is NOT driving (assisted/lethal/scene/dump/dry-run) -> a dry-run pick logs `WOULD CLICK (assisted)` and answers `Error:
<DlgDryReason>`, `show` answers OK, anything else `that is not on the table right now`; the `calib=1` branch; an unreported
older `x` is closed (`that moment has passed`); the request is stamped; verbs: `leave` (live -> `reqLeave`), `show` (live ->
`reqShow` -> hand-back `branch_show` resumable; else `forceVisible`), `noop` (OK), `pick` (live: a `gen` older than the live one ->
ONE stale re-match through `lrg_topics want=1`; else `reqPickReady`; not live -> `StartOpen`), `open` (live -> `reqWant`; else
`StartOpen`), else `unknown command`.

`StepClicking` **[C :2553-2712]**: nothing before 0.6 s after the open (Little Lessons writes the Speech globals in OnMenuOpen);
`pos` inside `nTotal`; the list state 1 seen (else 8 s -> watchdog); the line-settle gate (`fLineSettle` 0.4 s of an unchanged
subtitle / progress timer); never over CHIM's own TTS (<= 8 s); `VkRestore` (`her voice is not ready`); `FreezeMoved()` (gold or
Speechcraft moved since the read -> re-read, twice at most); `reqCost` vs `GetGoldAmount()` (`not enough gold`); `CapturePre()`
(stats Persuasions/Bribes/Intimidations, gold, IsBribed/IsIntimidated, `wis`, timer, subtitle, talking); **dry run -> `WOULD
CLICK` + `Error: <DlgDryReason>` + `HandBack("dryrun", resume=true)`**; `SelectAndVerify(pos, i, txt, routeB)` (a mismatch =
`stale`, re-read, twice -> `that moment has passed`); `XPush(x)` BEFORE the click; `Click(route)`; `ClickResult` <= 0 -> nothing
was clicked (`ShowList()`, re-read). Then RESPONDING: any of six signals verifies the click (`EntryCount()==0`, the progress
timer moved, a menu opened on top = a service hand-off, `isActorTalking`, a new subtitle); a check kind waits 4 s, others 20 s;
route A with the gate still armed after 9 s -> `unverified` hand-back; `SettleAndReport` (for a check/pay kind polls
`PreFactsMoved()` up to 4 s) -> `SendResult` (`lrg_dlg ev=result` with `ok, why, closed, other, pos, i, dP, dB, dI, gold,
bribed, intim, bamt, wis, sp, dur, txt`) -> funcret **`OK: The matter is raised.`** (the verdict travels in ev=result, never as an
error) -> closed = IDLE, ok = READING (the next layer), else hand-back `unverified`.

The de-duplication stack: `LRG_Main.HandleCommand` ring of 4 keyed `npc|command|param` inside 5 s **[C LRG_Main.psc:1577-1590]**
-> `LRG_Dialogue.xRing` of 8 (`XSeen` at entry; `XPush` at the click, the dry-run WOULD CLICK, `do=release`, the calibration
verbs) -> "close the overwritten request" (`ReportOnce("Error: that moment has passed")` when a new command arrives with an
unreported `x`) -> `HandBack` closes any in-flight `x` -> `commandEndedForActor` exactly once per `x` **[P 10.4]**.

### 1.5 The outcome pipeline (how the glue learns what the engine did)

`lrg_dlg ev=line` (every new non-blank subtitle, de-duplicated on `(gen, string)`) -> `lrgDlgOnLine` -> `lines[]`;
`ev=result` -> `lrgDlgOnResult` **[C :1055]**: for persuade/intimidate/bribe `lrgDlgOutcome` in this order - stat delta ->
gold delta == `bamt` -> bribed/intimidated flags -> the NPC's real line against the index `resp` -> the shown `variant` ->
`unknown`; `attempts[norm]` memory (kind, result, sp, pg, wis, bamt) for retry suppression; `session.path[]` (<= 8) for a re-walk;
`ev=closed` -> `lrgDlgOnClosed` (`why=goodbye|asked|handoff|external|refused`, `pending=1` counts a LOSS; two losses = assisted
closed layers for this NPC); `ev=unhide` -> the `handback` log line. Next player turn: `lrgDlgGroundTruth()` -> `<what_just_happened>`
(told once, within 180 s): what he said, what she answered, `It worked on you` / `It FAILED` / `You took the gold: exactly N
septims`, `N septims changed hands`, `Nothing came of it: <why>`, `The matter moved on: "<objective>"`; an `unknown` verdict is
stated with NO verdict. The same outcome is a `check` locked fact for 180 s.

### 1.6 Path C - the click-free quest entry (`ExtCmdLRG_QuestEntry`) **[P 10.26; C lrg_factions.php:1153-1341]**

Pre-LLM, from the player's OWN words (never the model's item, never a carried ask): `lrgFacTurn` -> `lrgFacAsk` (an enlistment
phrase + a faction word, or the NPC's own faction) -> role `recruiter` -> `lrgFacRoad` (open/closed/unknown from `mq101c=` /
`qst=MQ101` >= 900 / `mqq=` >= 7 on this NPC's facts line, else another recruiter's cached line within 1800 s) ->
`lrgFacQuestPlan` -> states `queued | closed | unknown | engine-on | stale | no-stage | already | pending | closed-intro |
click-wins`. `queued` needs: an `effects[<name>]` row (today ONLY General Tullius: AP INFO 206783 -> CW00A stage 10), road open,
CHIM's quest engine off, the driver unable to click (`ml=0` OR ambient OR the entry not on her list), a facts line younger than
300 s, the quest on `qst=` below the stage (a fresher `exec_qst` counts), no `facexec` pending inside 120 s, no `qnd` pair done.
`licensed` (every `qnd` quest ALSO on the facts line and clear) -> `executed` set pre-LLM and the OK stays quiet; otherwise the
OK is VOICED. `lrgFacQuestNet` (Phase 1's post-gate) appends ONE `<npc>|command|ExtCmdLRG_QuestEntry@ok=1;cid;npc;quest;stage
[;isid][;notdone][;max][;qdone][;qnd];entry;hint;x;z=1` - **D1 only, no D2 row** (the 4-entry / 5 s ring would not catch a
second delivery). Game `LRG_Main.CmdQuestEntry` (script 510) re-checks every condition on the live forms and calls
`Quest.SetStage()` - the same call the fragment makes; refusals are voiced in her words; a repeat is a quiet idempotent OK.

### 1.7 Path D - direct barter (CHIM's own window when no real entry can win) **[P 10.15; C :4137-4301]**

On a speech turn whose words are a barter request and no real entry can answer (ml=0, or no known list), `lrgDlgServiceDirect`
decides once (held back by a price question, a negation, ml=1 with a list, `combat=1` fresher than 90 s, a companion, a snapshot
whose factions carry no vendor faction); `lrgDlgServiceRequestBlock` tells the model to choose `Trade_Items` now or say why;
`lrgDlgServiceNet` appends `<npc>|command|OpenInventory@<player>` when the reply lacks it. No glue command, no game change.

### 1.8 Wire cheat sheet (names as they are on the wire)

Game -> server: `lrg_npcstate` (the snapshot, ~20 s; carries `ml=`, `cal=`, `chk=`, `bias=`, `ql=`, `qi=`, `qig=`, `qx=`,
`svx=`, `sv=`, `lf=`, `tg=`, `pgold=`, `scene=`, `ostim=`, `hold=`, `fol=`, `fv=`, `fac=`) · `lrg_topics` (`v sid gen layer
origin ref npc st n sent part cid want ask crit scene fam sub pg bamt vt q sp perk lvl [bi rw rwd io] svck calib e=LAST`) ·
`lrg_dlg` with `ev=open|line|result|closed|unhide|facts|calib|resume|lat` (`facts`: `q vt dlg sp perk lvl wis qobj sg qal
qgiver cal sq sqj qst mqq mq101 mq101c guard bounty cf`, throttled 60 s per NPC, never while a session is open, <= 6 quests) ·
`lrg_dlgtalk` (the one LLM-bearing request of the module) · `lrg_log` · `funcret` (`command@<Code>@<param>[;late=1][;err=]@OK: |
Error: <her words>`).
Server -> game: `ExtCmdLRG_SelectTopic@ok=1;cid;npc;ref;do=pick|open|leave|show|noop|award|release;sid;gen;pos;i;txt;kind;cost;
res;xp;stat;take;ask;vt;x;z=1[;note=][;svc=][;calib=1]` · `ExtCmdLRG_QuestEntry@...` (D1 only) · `ExtCmdLRG_Escort@...` ·
CHIM's own `OpenInventory@<player>` (the barter net). Game-side readers of the SelectTopic param: `ask cid cost do gen i kind ok
pos ref sid stat take txt vt x xp` + `calib` + `note` (`res=` is read by nobody) **[P 10.4]**.

---

## 2. What the calibration / dry run gates, and what it does not

Two different switches, two different texts **[C LRG_Dialogue.psc:454-487, 720-865]**:
- **The developer dry run** `bDryRun` (`LRG_Main.IsDryRun()`): blocks EVERY glue command - the quest entry, the escort, the award,
  the click - and is voiced by name ("the LoreRim Glue developer dry-run switch is on in its MCM (Diagnostics page)") **[P 10.21]**.
  It also blocks the automatic calibration open (`CalAutoWanted` requires `!IsDryRun()`: dev dry run OFF means off).
- **The menuless dry run** `bDlgDryRun` (`sDryRun`): ships ON; FORCED on while the calibration gate is red (`ReadCalibration` (e));
  auto-cleared ONCE when the gate is green AND the emergency key `iKeyVanillaMenu` is bound AND MCM Helper writes our settings
  (else released in memory only, or "bind it" is said); `bDlgDryRunHold` keeps it, `bCalibOverride` bypasses the gate.
  `MenulessLive() = sMenuless && !sDryRun` is what the snapshot sends as **`ml=`** and the facts line as `dlg=`.

**Gated by the menuless dry run / red calibration** (the click and everything that presupposes a click):
- the CLICK itself (`StepClicking`: `WOULD CLICK` + `Error: still learning the dialogue menu (N of 10) - nothing was clicked` or
  `the menuless dry run is on (Menuless questing page)`, then a resumable hand-back; the refusal is voiced through
  `lrgVoicedWhy`'s "still learning" mapping **[R pt18-calibration 3e]**);
- the HIDE and the input guard at `Arm()` (only when driving and not dry run - a dry-run session runs VISIBLE, listening);
- `MaybeResume` (needs `calGreen`, `!sDryRun`);
- server side through `ml=`: `hide_chim` / `hide_gold` / `hide_always` (the "real entry wins" hides are conditional on `ml=1`);
  the recruiter hand-off and the open for a recruiter (`lrgFacMenuless` = ml; `lrgFacRefusesOpen`); and, inverted, the click-free
  quest entry, which runs exactly when the driver CANNOT click (`ml=0` OR ambient OR entry not listed).
- the automatic calibration session itself runs while the menuless dry run is on - that is its purpose - and is gated by
  `bCalibAuto`, `bCalibActive`, `!IsDryRun()`, `!calOff`, `runs < iCalibAutoRuns` (4), a 120 s cooldown, not guard/scene/crime/
  follower/journal-quest, a service NPC; the CLICK inside it additionally needs `gopen > 0` (one glue open has already read a
  list on this install) **[P 10.13 E1f; R pt18-calibration 7b]**.

**NOT gated** (works in the dry run and while red):
- reading and forwarding lists (`StepManual` still `ReadList` + `SendTopics(0)`), `ev=open/line/result/closed/unhide/facts/calib/lat`,
  the root cache, the `<business>` block and the T-keys (the model may still pick; the gate still emits; the game answers WOULD
  CLICK), the open for awareness (`do=open` opens a VISIBLE session that is read, not clicked), `do=show`, `do=noop;note=`,
  `do=release` (never gated by anything but the hold switch), the passive and active calibration rows, the words lane entirely
  (faction facts, locked facts, truth gate, never-empty, never-false), the direct-barter `OpenInventory` net (CHIM's own action),
  the click-free quest entry (gated by the DEVELOPER dry run only), the escort, the free-conversation check and `do=award`
  (`CmdAward`; gated by `chk` and the developer dry run).

**The ten rows** (`CalMissing`, install-side store `LRG.cal.*` per save, mirrored to `Data/SKSE/Plugins/LoreRimGlue_calibration.ini`
and seeded into new games; server copy on the `*install*` row from `ev=open cal=rm3cm1rt2g1` and `ev=calib k=`)
**[R pt18-calibration 1b, 7a]**: `cm` counting (passive) · `rm` reading (passive / active A4) · `timer` progress timer (passive:
3 timer ids or 3 subtitle changes in ONE session, or the click test) · `hide` hide round trip (active A1 only) · `guard` input
guard (a CLICK body only: button 1 or the auto session) · `route` click route (click body only) · `rb` state read-back (active
A2) · `apd` + `reopen` vanilla menu after a hide (`apd` passive; `reopen` now `CalReopenProof` from any click on a pristine menu,
or button 2) · `st` Smart Talk settings (passive) · `fam` menu layout (passive). Measurements that change behaviour but not
safety: `x1` (does the session survive the player speaking -> `iBranchInput` auto), `col`, `row`, `inj`, `pay`, `ms3`, `tail`,
`actms`. Emergency key: shipped unbound (`iKeyVanillaMenu = 0`); Home (199) is the recommended free default **[R pt18-calibration 4]**.

---

## 3. The crit / confirm rules for quest-critical lines

Grades, three sources **[C lrg_dialogue.php:1257-1413; LRG_Dialogue.psc:3996]**:
- Game session `crit` = 0 or **2** (`IsGuard()` AND (engine-opened OR `GetCrimeGold() > 0`)). The game never sends 1.
- Index row `crit` = 0 / **1** (COSTLY: a walk-away-flagged INFO or one naming a `twat`) / **2** (LETHAL: the `lethal_twat` family
  `DGCrimeResistArrest` - 12 rows, all `DialogueCrimeGuards` **[X]**).
- Server `lrgDlgIsCommit()` (first hit wins): an override `never_auto`; indexed: `scripted && goodbye`, a closed layer with >= 2
  scripted siblings and this one scripted, `walkaway`, `crit >= 1`; a `commit_tags` tag (`(attack)`, `(brawl)`, `(go to jail)`,
  `(remain silent)`, romance switches, `(skip quest)`, `(fail quest)`); a cost >= `confirm.min_gold` 100 or >= 25 % of the purse;
  UNindexed: a `new` entry on a closed layer, or a `choice_words` hit (kill/spare/join/accept/refuse/give/keep/free/arrest/betray/
  promise/marry/attack/pay/surrender/yes/no).
- `lrgDlgClass()` (first hit): `hidden` (placeholder/invisible/empty) -> `meta` (skip/fail quest) -> `check` (index kind or a visible
  tag) -> `silent` (`(remain silent)`) -> `back` (`lrgDlgIsBackOut`) -> `pay` (cost > 0) -> `service` (`service_words`) -> `commit` ->
  `plain`. NB: the service-word test runs BEFORE the commit test, so an entry can be `class=service` AND `commit=1` at once.

What each grade does (gate and fast path alike):
- `crit == 2` (row or session) -> **`do=show`, why=lethal**: the menu is handed back visible; `PayBounty` stays offered,
  `ForgiveCrime` never. Resist-arrest rows (`lrgDlgIsResistArrest`) are refused before every other rail at every setting.
  An arrest-class SESSION (`lrgDlgArrestClass`: a `DGCrime*` topic or twat on the list, `guard=1 && bounty>0` from W10, the
  "I know you..." family) -> `do=show` as well. The MCM `iCritical` (shipped 0) and `bCrimeManual` act GAME-side in `Arm()`
  (`visible=lethal` + `HandBack("lethal")`); the server's lethal rail does not read `iCritical`.
- `crit == 1` / `commit` / class `commit` -> **the two-step** (`lrgDlgParkOrRelease` **[C :2774]**): first selection PARKED
  (`parked{norm,text,kind,at,utter,expires=+60 s,req=cid}`, her question is the message, nothing emitted); the release needs ALL of:
  a DIFFERENT request cid, a player SPEECH turn since the park, an utterance that differs from the one that parked it, not a
  back-out / refusal (`no`, `forget it`, `changed my mind`, `not now`, `stop` ... un-park), at least 2 tokens. A vanilla
  "Are you sure?" layer (exactly two entries, one yes-shaped, one no-shaped: `lrgDlgLayerIsOwnConfirmation`) releases at once.
  Intent mode (words) and the `want=1` fast path never execute a consequential entry at all - only a T-key can, after the park.
- `iBranchInput = 2` (`bi`) on a closed layer -> `do=show` (branch_show, resumable); `assisted` (two lost layers) on a closed
  layer -> `do=show`; the foreseen hand-back clause is added to `<business>` for all of these plus `crit==2`, an arrest, and
  `cal.x1 == 2` on a closed layer (`lrgDlgHandBackForeseen`).
- Freeze rule B1: the list's `pg` vs the current `pg` -> re-read, never click; the game's `FreezeMoved()` (gold/Speechcraft)
  re-reads too.
- Scoff-first (`lrgDlgScoffFirst`): a check kind, indexed, not compound, and (`wis == 0` on an intimidate) or (the shown variant is
  the FAILURE one and it is scripted or goodbye) -> parked like a commit.

**Not implemented although declared** **[C]**: `auto_advance.grace_seconds` (2.5) and `continuer_words` exist in `lrgDlgDefaults()`
and are read by NO function - there is no single-entry auto-advance; every single-entry layer waits for the player (PENDING,
45 s) or the model's T1. The driver has no walk-away logic of its own (`grep -i walkaway LRG_Dialogue.psc` = nothing); a
walk-away INFO is only ever a server-side `commit` grade.

---

## 4. Persuade / intimidate / bribe and priced lines

**Engine checks (a real entry on the list)** **[P 10.10, 10.5; C :1257-1413, 2727-2871]**: the index carries `kind`
(persuade 366 / intimidate 135 / bribe 86 rows) and `variant` (`success` / `failure` where Immersive Speech Dialogues gives the
two INFOs different prompts - MG01FaraldaEntryPersuade, TG00BrynjolfIntroMQ203C1, MS05PoemVerse2Evil/4Dragon, DB01_Persuade_*
**[X]**); a visible `(Persuade)`/`(Intimidate)`/`(Bribe ..)` tag is the second source (`lrgDlgKindFromTag`, 54 tag-only rows);
the DB02 captive lines carry NO kind (tag-only in RNAM, always succeed) **[X]**. Shown to the model verbatim minus the tag,
labelled `[persuasion attempt]` etc. The model judges only whether the player made the attempt; `lrgDlgCheckRails` then refuses:
fewer than `attempt.min_words` 4 words on voice; a bribe the player cannot pay (`cost` from the LIVE `(N gold)` text via
`lrgPromptCost`, `-1` = a `<Global>`/`<BribeCost>` token resolved from `bamt=GetBribeAmount()`); a named amount below the price
(`lrgDlgNamedAmount`: `lrgIntentAmount` words first, then digits); **retry suppression**: the same norm already `fail`ed and
`sp`/`pg`/`wis` are unchanged. Scoff-first parks doomed attempts. The game re-checks the purse (`reqCost`), captures PRE facts,
clicks, and the ENGINE picks success vs failure; `ev=result` carries `dP dB dI gold bribed intim bamt wis sp`; the verdict ladder is
1.5. Speech globals ride `sg=` on open/facts/closed (drift logged, never patched); perks `perk=` (incl. `amulet`), `sp=`, `lvl=`.
With `ml=1` and a priced/bribe entry listed, `GiveGoldTo`/`TakeGoldFromPlayer` are hidden so gold moves once, through the engine.

**Free-conversation checks (no entry of that kind listed)** **[C lrg_speech.php:120-273; P 10.5, 10.12]**: `lrgDlgCheck` runs
on a speech turn when `chk` byte 0 is 1, >= 4 words, `lrgDlgCheckKind` names persuade/intimidate/bribe/deceive/barter and no
engine entry of that kind (or a persuade for deceive/barter) is listed. Difficulty band = stakes word + her stance + `bias`
(`iCheckBias`) (+1 deceive), clamped 0..4 -> one of the five `LRG_SPEECH_GLOBALS`, threshold read LIVE from `sg`. Memory per
`kind|utter-hash` for 86,400 s unless `sp`/`wis`/`pg`/affinity band changed ("she has heard this from him before"); a caught lie
auto-fails later deceives. Intimidate: immune profiles fail; else `wis` (`WillIntimidateSucceed`) decides, and with no `wis` the
check REFUSES to guess. Bribe: her price `N` from the wage anchor (`lrgDlgBribePrice` / `lrgDlgWage`); no amount named ->
`result=ask` (she names her price, nothing moves); below N -> fail; cannot pay -> fail; else pass and `N = named`. Persuade /
deceive: the amulet passes, else `sp >= threshold`. Consequences: the directive `lrgDlgCheckDirective` (outcome as fact), memory,
and the server-emitted **`do=award`** (`take=N` gold moves, `xp=1` = `AdvanceSkill("Speechcraft", SpeechSkillMult x Speech)`,
`stat=` off by default; `CmdAward` answers `Noted.`). The G6 log line `check npc=.. kind=.. diff=.. threshold=.. sp=.. result=..`.

**Priced lines** (rooms, carriages, fines, spells): class `pay`; `afford` from `pg`; `commit` when cost >= 100 or >= 25 % of the
purse (two-step); the price-list slot matcher for short layers (section 5); the `price` locked facts (<= 2, from LIVE entries
only); the truth gate drops a money action when she quotes a price no fact/entry carries (frame-required regex) or a destination
off her list; `lrgDlgMayQuotePrice` (`svnp`, `never_quote_price` for `train`) decides whether she may say a number at all; the
game refuses `not enough gold` a frame before the click. Paid intimacy (`lrgPriceFor`, addendum 6/12) is Phase 1 and separate.

---

## 5. Services and direct barter

Kinds and their phrase lists live in `dialogue.services.kinds` (`inn`, `carriage`, `ferry`, `train`, `barter` (+`not_after`
guards), `crime`, `follower`) **[C :200-300]**; `lrgDlgServiceKind(utter)` names the kind of the player's words.
`lrgDlgServiceSlot(entries, utter, kind)` **[P 10.15; C :3867]** runs on a CLOSED live layer where >= 3 entries normalise to
<= 3 words (`min_priced` 0): slot names = entry text minus price and full stop; exact token containment, longest wins; a
negation within six tokens is not an order; a price question executes nothing (`mode=price`); two slots neither containing the
other -> `mode=ask`; none named -> `mode=none` and **the similarity matcher is blocked for that layer**. It runs in the gate
(`lrgDlgServiceArbitrate`) AND on `want=1`. The driver's own verdict `svck=` is a diagnostic (`svck drift`). `<her_list kind=..>`
tells the model the only names on the list and the price rule. Hide policy: the real entry wins and hides CHIM's shortcut of that
kind (`kinds.*.hide`, a subset of `hide_chim` + `hide_always` + `hide_follower`); `bServiceShortcut` off hides the shortcuts even
with no list. Training: Requiem computes the fee, so no price is ever quoted and the engine's training menu is the effect.
Carriage/ferry: `refuse_fallback_offlist` - CHIM's 19-destination enum is never used for a CFTO destination. Crime: arrest class
= LETHAL (section 3), `PayBounty` kept, `ForgiveCrime` hidden while menuless is on. A service window opening over a driven session
(`ServiceMenuOpen()` by name: Barter/Training/Gift, or `IsInMenuMode` when it was false at arming) = SUSPENDED, `why=handoff`
at close **[C LRG_Dialogue.psc:2804-2866]**. Direct barter (1.7) carries "what do you have for sale" on CHIM's own barter window
whenever no real entry can answer; `<player_request>` for inn/carriage/ferry/train under ml=0 says "choose Rent_Room / Hire_Carriage
/ ... if she really offers that, otherwise say plainly why not". The service census `data/service_catalog.json` (`--services`)
feeds `destinations_travel` (the off-list truth check) and `crime_topics` (arrest detection) - never typed by hand.

---

## 6. Faction rows and quest entry

`lrgFacDefaults()['rows']` **[C lrg_factions.php:82-510]**: legion, stormcloaks, companions, college, thieves_guild,
dark_brotherhood, bards, dawnguard, volkihar, blades, penitus, vigilants, guards, eec, thalmor. Each row: `words` (how the
player names the faction), `recruiters {name: her real join line}`, `recruiter_factions`, `member_factions`, `quests`,
`recruiter_topics` / `redirect_topics` (globs), `entries` (exact player lines), `how`, `member`, `redirect`, `gate`, `offer*`,
`recruiter_line`, `verified_from`. Legion alone carries `before` (Rikke), `closed` (the MQ101/Helgen road for Tullius and
Rikke) and `effects` (Tullius -> CW00A 10, conds `isid 78462 (0x01327E)`, `qdone [MQ101]`, `notdone [10,20]`, `max 9`,
`qnd {CW00B: 10}`). Roles (`lrgFacRoles`, `lrgFacRoleOf`): `recruiter` = a named recruiter, or the recruiter faction set plus a
recruiter topic really on her cached list; `redirect` = a member-faction glob or a row quest; else `none`. The ask
(`lrgFacAsk`): strong phrases stand alone, weak/word phrases need a faction word; object/subject stops ("join YOU", "why did
YOU join"). `lrgFacTurn` keeps a live ask for `window_seconds` 90 as `carried=1` on rechat / next lines (locked facts only,
never a click). `lrgFacLockedLines` go FIRST in `<locked_facts>` (the Helgen line is 500 / 522 chars - most of the 600-char body
on its own), plus `lrgFacRule` (never invent an appointment, a drill, a rank, a next step; answer in words). Clicks:
`lrgFacArbitrate` (a topic glob AND a join word in the entry, or an exact line; one -> click, several -> ask, none -> words) and
`lrgFacArbitrateWant` on the fast path; a recruiter under ml=1 with the entry listed is an ordinary `do=pick`; under ml=0 or on an
ambient actor the ONLY path is the click-free entry (1.6). Never-false (10.25) judges a faction turn's sentences against
`rows.legion.truth` (`next {CW00A:10, CW01A:100, CW02A:10}`, `oath {CW01A:160}`, `member {CW01A:200, CW02A:10}`) from `qst=`,
`executed`, `exec_qst` and the recruiters' cached lines; other rows carry ranks only (their claims are UNKNOWN, logged).
The road on THIS save: `MQQuickstart` = 7.0 (Alternate Perspective), MQ101 at stage 0 -> Tullius's greet 0D5146 is CLOSED; the
whole Legion road opens only after AP's Helgen (MQ101 stage 900) **[R pt18-quest 6]**.

---

## 7. QUESTLINE FACT SHEET (from the index census; flags: TL = top-level, sub = reached by link/forcegreet/blocking branch,
S = scripted INFO, nc = condition count, crit, kind/variant, G goodbye, O say-once, W walk-away, I invisible continue;
"scene" = whether the speaker is normally inside an engine scene / a forcegreet at that beat, from the index (`sub` first
lists) and UESP knowledge marked [I])

Reading rule for the designers: under the SHIPPED `iEngineOpen = 0`, every session the ENGINE opens (a forcegreet, a blocking
branch, a scene that pauses on the player) runs `visible=assisted` - the glue reads and forwards the list, nothing is clicked by
voice, the vanilla menu is on screen. Only sessions the GLUE opens (`do=open` on an NPC standing free, not in any scene) are
hidden and driven. "scene-bound" below therefore means "not menuless today".

**MQ101 Unbound** - 1 topic / 2 rows **[X]**: `MQ101DecisionTopic` "What's happening?" (TL, S, nc3, G x2 - the keep-door beat).
Everything else in Helgen is scene dialogue with no player prompt. On this save MQ101 is at stage 0 and Alternate Perspective owns
the start (AP's Helgen sets MQ101 900 = complete). Verdict: nothing to drive by voice; scene-bound.

**MQ102 Before the Storm** - Riverwood half is quests MQ102A (Hadvar/Alvor, 32 rows) / MQ102B (Ralof/Gerdur, 38 rows), Whiterun
half MQ102 (22 rows / 15 topics, 10 scripted, 6 TL, crit 0) **[X]**:
- Riverwood: `MQ102AHadvarIntroA1-A4` / `MQ102RalofIntroA1`, `MQ102RaolofIntroA2` (sub, S: "Sounds good. Let's go." / "I think I'll
  make my own way from here") - the exit scene; `MQ102RiverwoodHelpTopic` / `MQ102BRiverwoodHelpTopic` (TL, S: "Do you have any
  supplies I could take?" / "Hadvar|Ralof said you could help me out") - Alvor/Gerdur forcegreet scene [I]; `MQ102AlvorHelpB1/C1`,
  `MQ102GerdurHelpB1/C1` (sub; C1 S: "It was a dragon. Hadvar/Ralof will tell you the same thing").
- Whiterun: `MQ102IrilethForcegreetTopic` (TL, nc2, "I need to speak to the jarl" -> `IrilethIntroA1` S G "I have news from Helgen
  about the dragon attack" / `B1` S G "A dragon has destroyed Helgen" / `B2` I "I was told to give the message directly to the jarl"
  -> `A2` S "<Alvor|Gerdur> sent me. Riverwood is in danger") - a FORCEGREET at the door (engine-opened, assisted);
  `MQ102BalgruufIntroTopic` (TL, 5 INFOs by stage: "I need to talk to you about Helgen" - one is G "One moment." mid court scene)
  -> `BalgruufIntroHelgenB1/B2/B3` (sub, S, G - the lines that move the stage: "The dragon destroyed Helgen and last I saw it was
  heading this way" / "The Imperials were about to execute Ulfric Stormcloak..." / "I was there - I saw the dragon burn Helgen
  to the ground"), `IntroRiverwoodA1` (sub, `<t>` token = Alvor/Gerdur), `MQ102BalgruufIntroCW03` (a courier variant);
  `MQ102BalgruufReward` (sub, S, G O: "What else can I help you with?", winner **Delay Bleak Falls Barrow.esp**). Balgruuf is in
  the throne scene with Irileth/Proventus [I] -> engine-opened/blocking at first contact. Plain picks: the questions; scripted:
  the three Helgen statements and the reward. No checks.

**MQ103 Bleak Falls Barrow** - 26 rows / 20 topics, 5 scripted, 9 TL, crit 0 **[X]**: `MQ103BalgruufBlockingTopic` (TL, "I need to
talk to you", 5 stage variants ALL won by **Delay Bleak Falls Barrow.esp**, nc4-5, G/GO/GR) - a BLOCKING branch (engine-opened);
`MQ103FarengarIntroTopic` (sub, 2: "Have you learned anything about the dragons? Do you need any help?", one with an EMPTY
response, DBFB) -> `FarengarIntroA1/A2/A3` (I) -> `FarengarIntroB1` (sub, S, 2: "So what do you need me to do?" - the assignment,
stage-moving); `MQ103FarengarIntroAlchemy/Enchanting/Magic` (I, nc2-6, skill-gated flavour); `MQ103FarengarBleakFallsBarrowTopic`
(TL, S, nc3); `MQ103FarengarRetrieveBookTopic` (TL, S, nc3, USSEP: "I have the stone tablet you wanted") and
`FarengarIntroHaveStone` (sub, S: "Oh, do you mean this old stone?") - the turn-in; `RetrieveBookA1/A2` (I: "So what about my
reward?" / "I got you the Dragonstone. What next?"); `FarengarNeverMind` (G, back-out); Orgnar/Delphine innkeeper rows (plain).
Farengar is introduced by Balgruuf's scene and at the turn-in is mid-scene with Delphine [I]. No checks; plain picks dominate;
the two stage-movers are scripted single-entry layers.

**MQ104 Dragon Rising** - 22 rows / 16 topics, 8 scripted, 6 TL, crit1 x2 **[X]**: `MQ104IrilethBlockingTopic` (TL, "What are your
orders?", 5 variants G/GO by stage - the gate and the tower: blocking, engine-opened, mid-scene); `IrilethIntroA1` (sub, S: "I'll
come along with you") / `A2` (S, G: "I'd rather scout ahead"); `MQ104ADragonbornA1` (sub, S: "Dragonborn? What do you mean?") ->
`B1/B2` (G) - the guards after the kill (scene); `MQ104BDragonDeadTopic` (TL, nc3: "The dragon is dead." - Balgruuf, plain);
`MQ104BalgruufOutroA1-A3` (sub, links: "The watchtower was attacked, but we killed the dragon" / "Turns out I may be something
called Dragonborn" / "I killed the dragon. I think I deserve a reward"), `OutroB1` (S: "When the dragon died I absorbed some kind
of power from it"), `OutroB2` (S, **crit1 W**, twat `OutroC2`: "That's just what the men called me"), `OutroC1` (**crit1 W**: "The
Greybeards?"), `OutroC2`/`D1` (S, G: "What do these Greybeards want with me?"), `BalgruufIntroA1` (S, G: "What about my
reward?"). Balgruuf's outro follows the Greybeards' summons scene [I] (engine-opened forcegreet). Two walk-away rows = two-step.

**MQ105 The Way of the Voice** - 21 rows / 14 topics, 9 scripted, 10 TL, crit1 x2 **[X]**: `MQ105ArngeirIntroTopic` (TL, 4:
"I am answering your summons" - #0 S, #1 S GO, #2 **crit1 W** twat `IntroA1`, #3 G "Shout for us"); `IntroA1` (sub, S, GO:
"I'm answering your summons"), `IntroA2` (S, GO: "You call me Dragonborn. What does that mean?"), `IntroB1-B3A1` (questions),
`IntroB4` (S, G: "I'm ready to learn"); `MQ105ArngeirHornBlockingTopic` (TL, S, 2: "I'm ready for more training" - #0 won by
**GORE.esp**, **crit1** OW twat `HornA2`; #1 S); `HornA2` (S: "Thank you. What's next?"), `HornA1/A3` (I);
`MQ105ArngeirReturnHornTopic` (TL, "I have the Horn of Jurgen Windcaller", 4 stage variants, #0 S G). Arngeir speaks inside the
Greybeard courtyard scenes for most of the quest [I] (engine-opened); the summons greeting is a forcegreet.

**MQ106 The Horn of Jurgen Windcaller** - 65 rows / 45 topics, 21 scripted, 22 TL, **crit1 x14** **[X]**: Delphine's secret-room
conversation is a chain of walk-away-flagged layers (twats `MQ106DelphineIntroExclusiveC5/A3`, `IntroEndA2`, `IntroA0`):
`MQ106DelphineIntroTopic` (TL, "What do you want?", 4: #0 crit1 WI twat A0, #1 G "Close the door, then we can talk", #2 G "Not
here. Follow me", #3 links); `IntroA0` (S, G: "What of it?"); `IntroA1-A4`; `IntroB1/B2` (S, I: "What do you want with me?" /
"You'd better have a good reason for dragging me here"); `IntroB3` (S, crit1 W: "I just came here for the horn"); `ExclusiveA1/A2`
(I); `ExclusiveA3` (S, G: "I don't have time for this" - the walk-out); `ExclusiveC1` (crit1); `ExclusiveC3Dragonborn` (S, crit1 x2);
`ExclusiveC5` (S, G: "I don't need to prove anything to you. I'm done here."); `IntroDragonbornA1-A3` (crit1 W);
`Exclusive2EntryTopic` (TL: "You want me to kill a dragon for you?"); `Exclusive2A1-2A3`; `ExclusiveEndTopic` (S, crit1 W: "So
where are we headed?"); `IntroEndA1` (S: "I know that mound, high on the hill east of Kynesgrove"), `EndA2` (G: "Let's go kill a
dragon"), `EndA3` (G: "Hold on, I'm not ready to go yet"); `MQ106KynesgroveEntryTopic` (TL, 4: "Where are we headed?");
`KynegroveEntryA1` (S, G); Iddra's `KynesgroveDragonA1/A2` (I) / `B1` (S, G: "What's wrong?"); `MQ106DelphineDragonAtttackTopic`
(TL, 7 stage variants: "What should we do?" - the mound scene); `MQ106RentRoomTopic` (TL, S, **cost -1** = a price token:
"I'd like to rent the attic room." nc5/nc4 - the priced line that starts the meeting; the live cost comes from the menu text);
`MQ106DelphineBookTopic`, `OrgnarDelphineTopic` (plain). Delphine walks the player to the room (scene) [I]; Kynesgrove is a scene.
Plain picks: the questions; commits: 14 walk-away rows -> every one of them parks first; scripted: the meeting exits and the
dragon-mound lines.

**C00 Take Up Arms (Companions)** - 68 rows / 37 topics, 32 scripted, 17 TL, crit1 x5 **[X]**: the join line
`C00KodlakJoinUpStartTopic` (TL, S, nc7, G: "I would like to join the Companions." - winner **Companions at Mirmulnir.esp**; this
IS the faction row's recruiter line) -> `KodlakDontWorryAboutMe` (S, G: "I can handle myself") / `KodlakTeachMeMaster` (S, G:
"I have much to learn") / `KodlakOffended` (S, G, nc11, NGCDT: "You dare question my skill?"); `C00VilkasTrainPlayerTopic` (TL,
"So you're supposed to train me?", 2: G / S G) + `C00VilkasTrainPlayerSkipTopic` (TL, S, G, **Vilkas Spar Skip.esp**);
`C00EorlundIntroTopic` (sub, S, **crit1 W** twat `EorlundWalkaway`: "Vilkas sent me with his sword") -> `EorlundHappyToHelp` (S,
G, 2: "I'm happy to lend a hand") / `WaitASec` (S, G) / `ImAGoodBoy`, `NameAndGame`, `ImAScrub`, `CompanionsHierarchy` (crit1 W);
`C00AelaIhaveYourShieldTopic` (TL, S, 3 variants, G: "I have your shield"); `AelaWhoMe` (S, I, 2), `AelaIfYouWereAnyOtherMan` (S, I);
`C00EorlundBranchTopic` (TL, 2: "I was told you would have a weapon for me" -> `Sword/Dagger/Greatsword/Waraxe/Battleaxe...Please`
S G - the reward CHOICE); `CRNoWorkBranchTopic` (sub, "Can I join the Companions?" - LoreRim Dialogue Patch, S, nc9-10 -> redirect to
Kodlak, NGCDT "Nah. I don't think so." variants) and `CRWhereIsKodlak`; Skjor's advice/war-story rows (Companions Dialogue Bundle,
S, O R). Kodlak's join is a free-standing top-level pick (no scene) - the ONE clean menuless join in this list; the Vilkas test
and the shield delivery run as scenes/forcegreets [I]. No checks.

**MG01 First Lessons (College)** - 99 rows / 47 topics, 56 scripted, 52 TL, persuade x2, crit1 x2 **[X]**: `MG01InitialBranchTopic`
(TL, S, O, 26 NPC variants: "Where can I learn more about magic?"); Faralda: `MG01Faralda1WhyAreYouHereBranchTopic` (sub, nc1,
**CollegeEntry.esp**: "May I enter the College?") -> `HereResponse1-5` + `Alteration/Conjuration/Restoration` (S, I: the "what do
you seek" answers) -> `MG01FaraldaEntryBranchIntro` (sub, 2: "May I enter the College?" O / "If you can pass the test") ->
`MG01FaraldaEntryPersuade` (2: ISD **success variant** "I'm the best mage you'll ever see. This little test is an insult." S I;
vanilla "I think we both know I'll succeed here." persuade) / `MG01FaraldaEntryTakeTest` (S, 2: "I'll take your test, then" -
nc7 "your skills haven't improved enough" (CollegeEntry/Requiem gate) / nc1 "Excellent."); `MG01FaraldaDontKnowSpellTopic` (TL, 10:
"I don't know the <spell> spell" - CollegeEntry's G "try your luck" vs vanilla "I'd be happy to provide" links);
`MG01FaraldaShoutForcegreetBranchTopic` (TL, I: "That proves I have the Voice"); Nirya rows (CollegeEntry's second gatekeeper:
`NiryaStage10SellSpell` **cost 30** S G "Okay, this is for the spell" x5, `NiryaStage20BranchTopic` TL "There, I cast the spell"
#1 S, `NiryaRejectionBranchTopic`, `NiryaProblemResponse2` nc0 with an EMPTY response); Mirabelle `MG01MirabelleStage30BranchTopic`
(TL, S: "I was told to come see you") -> `TakeTour` (S, G: "I'd love to have a look around") / `TourSkip` (S, CollegeEntry) /
`TourNotYet` (G); Tolfdir `MG01TolfdirStage50SceneBranchTopic` (TL, **crit1 W**: "You want my opinion?") -> `Response1-3` (S, G)
and `MG01TolfdirClassroomScene2BranchTopic` (TL, **crit1 W**: "So we're learning about wards?") -> `WardResponse1-3` (**Requiem.esp**,
nc5, S I). Faralda on the bridge is free-standing (menuless-able) but her entry is a scripted chain with a priced spell (30) and
a persuade; Tolfdir's class is a SCENE (blocking branch, engine-opened).

**TG00 A Chance Arrangement (Thieves Guild)** - 31 rows / 22 topics, 11 scripted, 6 TL, persuade x1, **crit1 x10** **[X]**:
Brynjolf's approach is a FORCEGREET (engine-opened, assisted) whose every intro layer is walk-away flagged (twat
`TG00BrynjolfWalkAwayTopic`): `IntroBranch01` "I'm sorry, what?", `02` "How could you possibly know that?", `02a`, `03` "My wealth is
none of your business", `03a`, `04` "What do you have in mind?", `06` "What do I have to do?" (all sub, crit1 W); `Branch07` (S, G:
"Break the law? Are you kidding?" - the refusal), `Branch08` (G: "No, never mind"), `Branch09` (S: "Why plant the ring on
Brand-Shei?"); `IntroMQ203A1/B1/C1` (C1: ISD **persuade success** "Won't have a point earning all that gold if the dragons kill you
all" S I; failure "Let me find him first, dragons are bad for business"); `TG00BrynjolfReadyToStartBranchTopic` (TL, S, G, USSEP:
"I'm ready. Let's get this started."); `LostRingBranchTopic` (TL, S); `HelpBranchTopic` (TL); `OutroBranchTopic01-04` (sub, S G:
"I can handle it" / "The money's nice, but I don't know" / "No way. It was wrong to do those things"). There is NO player join line
(the row says so); the recruiter line is his own hello. Scene-bound at the start; the job-start pick is top-level.

**DB01 Innocence Lost** - 33 rows / 33 topics, 15 scripted, 11 TL, persuade x2, bribe x2, crit1 x2 **[X]** (21 rows from
**Innocence Lost - Quest Expansion.esp**): Aventus `DB01AventusQuestBranchTopic` (TL, S, nc2, USSEP: "Are you all right?" - inside the
Black Sacrament forcegreet scene [I]) -> `Response1/2` -> `Response4` (S: "contract" token line); `AventusPaymentBranchTopic` (TL:
"Assassinations don't come cheap, boy"), `LiveAlone`, `YouSure` (TL, plain); `AventusGrelodDeadBranchTopic` (sub, S, G: "Grelod
the Kind is dead"), `GrelodAlreadyKilled` (S, G), `AlreadyArrested` / `DB01_ARRESTED` (crit1 W, ILQE's non-lethal ending);
Grelod `DB01GrelodThreatenBranchTopic` (sub, S, G: "The Dark Brotherhood has come, Grelod") / `Threaten2` (S, G: "Aventus Aretino
says hello"); ILQE's guard route `ICQEGuardMaybeTopic` (TL, nc7: "Grelod is abusing the children at the orphanage. You must do
something!") -> `DB01_Persuade_Success/Fail` (persuade, S G / G), `DB01_Bribe_Yes/No` (bribe, **cost -1** token, S G / G), `DB01_Thane`
(S, G), `DB01_NeverMind` (G back-out); ILQE's prank/push/attack/take-you-there lines (S, G). The orphanage kids' `..GrelodBranchTopic`
(TL, plain). Mixed: plain picks, a real persuade and a real bribe on a guard (arrest-class detection by `guard=` - a guard with
bounty 0 is NOT lethal), scripted ends. Aventus: scene-bound.

**DB02 With Friends Like These...** - 12 rows / 8 topics, 6 scripted, 6 TL, crit 0 **[X]**: `DB02CaptiveWhoAreYouBranchTopic` (TL,
3 per captive: "Who are you?"), `DB02CaptivePayToKillTopicTopic` (TL, 3: "Would someone pay to have you killed?") ->
`DB02Captive1/2/3Intimidate` + `Persuade` (sub, S, nc1, **kind '' - tag-only in RNAM, always succeed**: "Answer me, or die!" / "Shhh...
don't be afraid. You can tell me"). Astrid's own shack lines ("(Remain silent)" layers) are NOT under quest DB02 in the index
(gap 9.4). The shack is a forcegreet scene on waking [I]: engine-opened.

**MS05 Tending the Flames (Bards)** - 35 rows / 28 topics, 24 scripted, 10 TL, persuade x2, **crit1 x9** **[X]**:
`MS05ViarmoApplicationTopic` (sub, nc3, winner **TheGiftofSaturalia.esp**: "I'm looking to apply to the College" - the faction row's
recruiter line; `sub` = reached from his greeting) -> `ApplicationTopic2` (S, O: "And that's where I come in?") -> `ViarmoTask2` (S:
"What do you need me to do?"); `TaskDescription`, `PoeticEdda` rows (TL, plain); `MS05GivePoemTopic` (TL, S, 4 variants: "I found King
Olaf's Verse"); the reconstruction: `PoemVerse1` (TL: "We can do this. What's the first verse?") -> `Verse2Evil` (ISD **persuade
success** "Olaf was Numinex, a dragon in human form" S crit1 OW twat `MS05PoemExit` / failure), `Verse2Incompetent`, `Verse2Underhanded`
(S, crit1 W) -> `Verse3` -> `Verse4` / `Verse4Dragon` (**persuade**) / `Verse4Evil2` / `Verse4Underhanded` (S, crit1 W) ->
`PoemFinalVerse` (S, G: "Is that it?"); `SendToJornTopic` (S, G: "Does that mean I'm a bard now?"); `TulliusCoatFavorTopic` (TL: "I
need your coat" -> `2` -> `3` S: "It's all in good fun, Tullius"); `ReturnWithCoatTopic1` (S, G, 2: "The festival is back on");
`ViarmoAtCourtBranchTopic` (TL, S, G: "Are we ready?" - Elisif's court scene); `BardsCollegeInductionTopic2` (S, G, dvEdda.esp:
"So I'm a bard?"); `WeddingDelayBlockingTopic1` (Vittoria's Alternate Wedding). Viarmo's application is a plain-ish pick off his
hello; the verse layers are walk-away commits with two persuade variants; the court and the induction are scenes.

**CW00A Imperial Introductions / CW01A Joining the Legion** - 28 + 12 rows **[X; R pt18-quest]**: `CW00TulliusForcegreetTopic` (TL,
3: 0C348B S O "hello" (AP, needs MQ101 complete), 0D5145 crit1 OW twat `GreetHelgen` (AP), 0D5146 nc6 - CLOSED on this save);
`CW00TulliusGreetFreeToGo` (sub, I, USSEP: "I was set free. I could've gone anywhere. I came here to fight for the Empire" -
unreachable here), `GreetHadvar` (I), `GreetHelgen` (I: "I was at Helgen"), **`CW00TulliusGreetTalkRikkateAP`** (sub, S, G, nc1, AP
206783: "I don't want to sit idly by after what I've witnessed. I want to join the Legion" = `SetStage(10)`, the ONE modelled
effect), `TulliusAP01` (I); `CW00RikkeBlockingTopic` (TL, nc4, USSEP: "About that test..." needs CW00A 10) -> `RikkeGreetWhatTest` (I),
`HandleAnything` (S, I), `AboutHraagstad`, `RikkeGreet` "I'm going alone" (#1 crit1 W), **`CW00RikkeGreetAcceptQuest`** (S, G:
"Consider that fort already yours." = stage 20 -> CW01A 1, the first JOURNAL entry), `AlreadyDone` (S, G: "I already cleared it
out" = 21), `NotSure` (S, G, TCIY: no longer sets a stage); `CW00JoinTopic` (TL, S, nc7, AP: "How does one join the Imperial Legion?"
- the soldier/guard redirect line, scripted); `CW00AboutTopic`, `CW00JoinAboutFactionTopic` (plain). CW01A: `CW01AJoinLegionBranchTopic`
(TL, 4 variants: "I'm ready to take the oath" - two G "deliver your report", one O with links) -> `MQ102JoinLegionYes` (sub, 2:
"Well, then. Repeat after me.") -> **`MQ102ALegionOath1-3`** (crit1 W, twat `MQ102AJoinLegionInterrupted`: the oath lines, single-
entry layers) -> `MQ102ALegionOath4` (S, G, winner **JK's Castle Dour.esp**: "Long live the Emperor! Long live the Empire!" =
"Welcome to the Imperial Legion, soldier."); `MQ102JoinLegionNo` (S, G). Tullius and Rikke live in the ambient map-table scene
(`CW00SolitudeMapTableScene`): never opened -> words + the click-free entry only.

**CW00B / CW01B (Stormcloaks)** - 24 + 12 rows, **toplevel 1 each** **[X]**: everything reaches through Ulfric's forcegreet and
Galmar's blocking branch (engine-opened). CW00B: `CW00BGalmarBlockingTopic` (TL, nc4: "About that test") -> `CW00BGalmarSignMeUp`
(sub, S, **crit1 W**, twat `ClearIsland`: "That's why I'm here. I want to join." - the recruiter line), `FightEmpire`, `OnlyNords`,
`SkyrimIsHome`, `HandleAnything` (S, I), `WhatTest` (I), `Alone` (#1 crit1), `EveryoneDoThis` (crit1), `NotSure` (S, G, TCIY),
**`CW00BGalmarAcceptQuest`** (S, G, 2: "I'm off to kill that ice wraith. I'll be back soon."); Ulfric: `GreetFreeToGo` (I, AP),
`GreetRalof` (I), `GreetHelgen` (I), `AlreadyMet` (I), `Greet` "That's not why I'm here", `Yessir` (G), `CW00UlfricPlayerAP01/02` (I,
AP). CW01B (**crit1 x10 of 12**): `MQ102GalmarJoinTopic` (TL, crit1 W: "I'm ready to take the oath") -> `CW01BGalmarGreetReady` (crit1 x2)
-> **`MQ102BStormcloakOath1-3` + `CW01BStormcloakOath3b`** (crit1 W, twat `CW00BStormcloakOathInterrupted`) -> `MQ102BStormcloakOath4`
(S: "All hail the Stormcloaks..." = "Now you're one of us."); `GreetOath`, `IsntEnough`, `SentMeToDie` (crit1); `GreetGoodbye` (S, G:
"I need to think it over"). No `effects` row exists for the Stormcloaks (words only).

Cross-cutting facts from the census: every row in these 16 quests has nconds >= 1 except one (MG01NiryaProblemResponse2, nc0,
empty response); the ONLY real speech checks in the set are 4 persuades (MG01, TG00, MS05 x2) and 2 bribes (DB01, ILQE) - the
main quest MQ101-MQ106 has none; the oath chains (CW01A x3, CW01B x4) are consecutive single-entry walk-away layers, i.e. each
line is a `commit` that the shipped two-step parks before it can be clicked; 7 of the 16 quests have their advancing lines
inside engine-opened sessions (forcegreets / blocking branches / scenes), which today run `assisted` (visible).

---

## 8. Complexity hotspots and known fragile paths

1. **The ten calibration rows** - two of them (`guard`, `route`) need a real CLICK body, one (`timer`) needs three lines in one
   session; the store was per SAVE until pt18 (0 of 10 relearned three times on 2026-09-23); the auto-clear needs the emergency key
   bound AND MCM Helper live; the auto session needs an idle innkeeper turn, `gopen > 0` before any click, and the server's safe
   pick; `ml=` (snapshot) and `dlg=` (facts) both derive from `MenulessLive()` and the server's whole hide policy flips on it.
   Not yet run in game (built 2026-09-24, all offline tests green).
2. **Ambient scenes** - the map table is talkable only through the `sq=/sqj=` facts line (fresh <= 300 s, throttled 60 s, never
   while a session is open); the open is refused on BOTH sides (`lrgDlgMaybeOpen`, `OpenBlockedReason` with `iSceneGate` 0); for
   these NPCs the click-free entry is the only path and it needs a facts line younger than 300 s; a scene that STARTS inside a
   driven session hands the menu back (`ReadList` G4).
3. **The executed ring and double delivery** - D1 + D2 with one `x`; `xRing` 8 written only at CLICK time (so a `do=show` then
   `do=pick` for the same NPC both pass `XSeen`, hence "close the overwritten request"); `LRG_Main`'s 4-entry 5 s ring is why the
   quest entry is D1-only; a D1 that arrives later than 5 s runs twice at the LRG_Main layer (caught by `xRing` only for
   SelectTopic); `LRG_FAC_QE_SENT` is per request; `facexec` pending 120 s.
4. **The snapshot pipeline** - one `lrg_npcstate` every ~20 s carries 14+ MCM knobs, `scene/ostim/hold/fol/fac`; the abort rail
   fires falsely (pt13) and a missing block is "remembered" from the same session for 900 s; three carriers with three freshness
   windows (snapshot age, `ev=facts` 60 s / 300 s, `ev=open` per session); `qst=` is CAPPED AT SIX quests on both sides and
   Tullius's sweep fills all six, so `CW00B` is never on it and the licensed path never happens; a COMPLETED quest leaves the PO3
   sweep (hence `mq101c=`).
5. **Prompt size and shape** - `<business>` <= 12 keys + "(+N more)" from `e=` <= 1,600 chars + part 2 (head 16 / tail 40 game
   side); `<locked_facts>` 600 chars / 6 lines with faction lines first (the Helgen line alone is 500-522 chars, so price / bounty /
   check / quest facts are trimmed off on a faction turn); `<real_business>` 70 words; the action-first JSON order produced 10 %
   empty replies against 1 % character-first (the never-empty re-ask costs 1.5-2 s on ~5 % of turns); prompt_ready is 58 ms of a
   7.7 s turn - the model is 85-95 % of the wait.
6. **The matcher** - F1 + `similar_text` at 0.55 / 0.15 tuned on sentences; 12.3 % of rows normalise to <= 2 words (the slot
   matcher covers price lists only - follower names, spell names, Daedric princes reach the guard through `min_priced` 0 and then
   execute NOTHING unless named exactly); two candidates (`ask=` and the utterance), best wins; `txt=` prefix (40 chars) is the
   game's ONLY content check on a click.
7. **Engine-opened sessions run assisted** at the shipped `iEngineOpen = 0`: every forcegreet, blocking branch and scene-paused
   conversation in section 7 shows the vanilla menu; the glue only harvests. `iEngineOpen = 1` exists but was decided OFF (D5).
8. **The two-step on walk-away rows** - `crit1` = `commit` = park-then-release with "a different request, different words, >= 2
   tokens": the CW01A/CW01B oath chains are 3-4 consecutive single-entry commits, MQ106 has 14, TG00 10, MS05 9 (the verse
   choices). No single-entry auto-advance exists in code (section 3).
9. **Timing of the LLM path vs the driver** - `fDecideTimeout` 4 s < a 6.5 s model reply: an LLM-turn pick on a freshly opened
   session always lands in PENDING (park + "waiting for your answer" note), and the 45 s `fSilenceTimeout` watchdog hands the menu
   back if the player says nothing; the stale-`gen` re-match sends one more `want=1`; a session lost twice = assisted closed layers
   for that NPC for the rest of the game session (`losses`).
10. **Load-order winners that surprise** - Delay Bleak Falls Barrow.esp owns Balgruuf's MQ103 blocking topic and the MQ102 reward;
    GORE.esp owns an MQ105 horn line; JK's Castle Dour.esp owns the Legion oath's last line; CollegeEntry.esp rewrites Faralda/Nirya;
    Innocence Lost QE rewrites DB01; Companions at Mirmulnir owns Kodlak's join; TheGiftofSaturalia owns Viarmo's application; AP
    owns the Legion road. The index has them right; a hand-written table would not.
11. **Voice-side plumbing that is not the glue's** - CHIM's SWF click gate (`ALLOW_PROGRESS_DELAY` 750 vs the poisoned value), Smart
    Talk (`SmartTalkSafe` precondition), the voice keeper (`VkRestore`), `Utility.IsInMenuMode()` from inside a Dialogue Menu
    (unmeasured - `ServiceMenuOpen` by name is the fallback), Speech-global drift (Little Lessons), STT blanks (17 % once).

---

## 9. Gaps - what this survey could not verify, and what is declared but absent

1. `auto_advance.grace_seconds` and `continuer_words` are config-only: no code path auto-advances a single-entry layer **[C]**.
2. The driver never reads `walkaway`/`twat`; a walk-away row is only a server-side `commit`. Cancelling a hidden session on a
   walk-away layer (`do=leave` with `pos=-1` -> `CloseClean`) plays the engine's walk-away topic - the design's E-R7 was never
   answered in game **[C BeginLeave; R p2 5.3]**.
3. `res=` on the wire is read by nobody game-side (kept for logs/tests) **[P 10.4]**.
4. Astrid's DB02 shack layers and the DarkBrotherhood filler are not under quest `DB02` in the index census; MQ101's Helgen lines are
   scene dialogue with no player prompt; Alternate Perspective's own start quest was not queried - the DB/main-quest fact sheet
   rows for those beats are incomplete.
5. "Speaker usually in a scene" in section 7 is inferred from `sub` first lists + UESP knowledge [I], not from the plugin's SCEN
   records; the index has no per-row "this INFO is reachable only inside a scene" flag.
6. Whether `PayBounty`-class arrest detection handles a guard with bounty 0 (ILQE's DB01 guard route) as non-lethal was read from
   code (`bounty > 0` required) but not seen in a log.
7. The licensed quest-entry path (every `qnd` quest on the facts line) cannot happen for Tullius on this install (six-quest cap);
   only the voiced path was tested offline; neither has run in game.
8. `Utility.IsInMenuMode()` from inside a Dialogue Menu on this install is unmeasured (G9); the SUSPENDED detection by menu name is
   the guard against it.
9. The whole pt18 build (script 510, calibration auto session, quest entry, `mqq/mq101/mq101c`) is untested in game as of this map.
10. `iEngineOpen = 1` (drive engine-opened sessions) has never been exercised; every scene-bound beat in section 7 depends on it or
    on a design that does not need the click.
