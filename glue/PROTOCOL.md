# LoreRim Glue - wire and code contract, v0.5 (build 0.5.5) + menuless questing v1.0 (game script 513)

**[v1.0 / pt19c, game script 513] MENULESS QUESTING v1.0 - THE VISIBLE MENU, VOICE-DRIVEN. ADDITIVE IN BOTH
DIRECTIONS.** The vanilla Dialogue Menu is no longer hidden: the real list stays on screen, the player may click it at
any moment, and when his words name a line the glue clicks it (full contract **section 10.29**; the owner's page is
`glue/OWNER_MENULESS_V1.md`; design `research/pt19-menuless-v1-spec.md` rev 2). The wire, all of it in the table at the
end of **10.4**: game -> server `ev=open` gains `scene= sj= sq= sqj= drv= hid=0 [quiet=1]` after `svck=`, `lrg_topics`
gains `sj= drv= [hc=1]`, `ev=result` gains `auto=1`, `ev=calib` gains `reset=1` and its `k=` shrinks to
`cm rm fam st route timer x1 apd ms3 tail`, and there is one new `ev`, `stopped`; server -> game `do=pick` gains
`adv=<ms>` and `rearm=1`, `do=open` gains `amb=1`, `do=award` gains `give=<n>` (gate B only), all AFTER `z=1`, and
`res=` is sent empty. No longer sent, handlers kept (a version-skew no-op): `ev=unhide`, `ev=resume`, `lrg_dlgtalk`,
the `calib=1` key, and the `bi` / `rw` / `rwd` / `qi` / `qig` carriers. An older game script against this server sends
no `sj` / `drv`: the server falls back to `scene && !ambient` and reads a missing `drv` as 1, exactly as before. This
game script against an older server: the new keys are ignored and `ev=stopped` is logged as an unknown `ev` ("this is
not an error"). Version markers: `LRG_Main.CurrentVersion` **513**; `LRG_VERSION` 0.5.6 and `LRG_ACTIONS_VERSION` 11
are unchanged. Ship both halves together all the same: the stage rail and the visible menu are one behaviour.
What CHIM itself does around it (CHIM brief, `research/pt19c-chim-brief.md`): the pre-LLM open rides D2 only and D2 is
claimed on every DLL poll, not every 5 s (10.6); a SelectTopic result is never voiced by CHIM, so a game refusal is a
corner note and a loop failure reaches her words on his next turn (1.6 [v1.0]); the bridging word's mute is a
text-time race (10.29 section 4); the talk key's tap and double tap are CHIM's own hush and wait (10.29 section 6); the
first evening's CHIM checks (10.29 section 13).

**[0.5.5 + game script 507 / owner addendum 11] NEVER SILENT. SERVER -> GAME UNCHANGED; GAME -> SERVER: ONE ADDITIVE
RESULT SHAPE (1.6).** Script 507 marks every funcret result `OK: <neutral>` or `Error: <her words>` and appends
`err=<technical reason>` (on a failure) and `late=1` (a command already answered `OK:` that came undone: the escort's
scene came back, an `after=` position that cannot follow a start) to the echoed field 2. The server classifies on
`err=` (fallback: the `Error:` text, for scripts <= 506), never on her words (10.21 section 9). "if she's busy
she should say that then and it will be fine, she never told me she was busy, she just didn't do anything."
Server only (full contract **section 10.21**): (a) a glue command that FAILS - an escort refused on her stage,
clothes that would not come off, an unreachable position, a start the game refused, any `Error: ...` - is
SPOKEN in the same exchange: every glue catalog row now has CHIM's funcret follow-up ON (`LRG_ACTIONS_VERSION`
**11**; the escort gets an inactive, never-offered row for exactly this), the glue itself ends every result
that must stay silent (a success, her own scene-lead move, the hold carrier, a dry run, the kill switch, a
second failure inside `voice.min_gap_seconds`) in preprocessing BEFORE the MAIN lock, and the one it lets
through becomes CHIM's own funcret turn with a cue that says plainly what did not happen and why - ONE short
line in her voice, never the game, never pretending (new mode `voiced`, 6.2); (b) every recognised request's
directive now says "do it, or say why - never ignore it", the blind turn and the closed / public turns
included; (c) CHIM's own `ComeCloser` / `MoveTo` / `TravelTo` / `FollowPlayer` (where no escort carries it) are
judged by the next snapshots and a visible failure is told on her next player turn, as is the escort's late
"she went back to her scene" (read from the game's `lrg_log` line, and from script 507's `late=1` funcret, which
is voiced at once - the two are never both told). **Server -> game: unchanged** - no key, no verb, no `ev`, no
message type. A 0.5.5 server works with game scripts 401-507 (<= 506: the `Error:` text is the technical reason,
as before); script 507 also works with a 0.5.4 server (that one only loses the early "no scene is running" close,
because it matches the `Error:` text). Ship both together. New server-internal state: `lrg_memory` keys `sent`, `voiced_at`, `move_watch`,
`missed` (section 5); new log prefixes `voiced`, `watch:`, `missed:` (section 9); config block `voice`.

**[0.5.4 / playtest 13] ONE NEW SERVER -> GAME COMMAND, ADDITIVE, AND NOTHING ELSE ON THE WIRE.**
"I couldn't get her to follow me": CHIM chose `Follow_<player>` three times and its package really went on,
but Lisette was back in a running ENGINE scene on her stage (snapshot `scene=1`, gate reason `quest_scene`)
and a running scene outranks every package. The server now (a) recognises follow / wait / release in every
mode outside a scene with her, (b) tells the model plainly to choose `Follow_<player>` and answer in one
short line, and (c) appends **`ExtCmdLRG_Escort`** (section 2, full contract **section 10.20**) so the GAME
can stop a *stoppable* scene and let the follow package win - and carry wait / release, which have no CHIM
action here (`WaitHere` is off in CHIM's catalog). It is emitted by the server only, never offered to the
model, and never for a companion a follower framework owns. **Old game script (<= 505):** `HandleCommand`
logs `command ExtCmdLRG_Escort from <npc> via <path> param=...`, answers ONE funcret
`Error: unknown command` and - through `ReportResult` while `bNotifyErrors:General` is on - shows ONE corner
note `LoreRim Glue: unknown command`; nothing else happens in game. The server swallows that funcret
before the lock, never tells her about it, and sends no further escort in that game session (session tag).
**Game -> server: unchanged.** Server-internal only: the last `fol=` of a game session is remembered when
the game stops sending it (10.19), the `llm` log line gains a `chim=<code>` tail, and a new log prefix
`escort` (section 9). A 0.5.4 server works with game script 401-505; a new game script works with a 0.5.3
server (it simply never receives the new command).

**[0.5.3 / playtest 10] NOTHING ON THE WIRE CHANGED IN THIS BUILD, IN EITHER DIRECTION.** No key was
added, removed or reordered, no new `ev`, no new `do` verb, no new message type, and the snapshot is
byte-identical on a healthy install. The build is two internal fixes, one per lane:
`LRG_Main.CurrentVersion` 501 -> **503** (the snapshot black box: a Papyrus error inside `MaybeSnapshot`
used to unwind the whole CHIM event silently - it is now noticed, named by stage in the log, and the one
optional block that was running is retired for the session), and the server's recogniser now also runs on
a `silent` turn whose only reason is `no_fresh_snapshot` (section 6.2's one exception, section 9's
`silent:` line). **What may be offered and what may be executed did not move.** A 0.5.3 server works with
game script 401 exactly as 0.5.1 did, and game script 503 works with a 0.5.0 server.

**[0.5] THIS ROUND IS ADDITIVE ONLY, IN BOTH DIRECTIONS, AND A TEST ASSERTS IT** (`d50_wire05`):
a **0.5 server works with game scripts 401**, and **game scripts 500 work with a 0.4 server**. Every
addition below carries its own "old version behaviour" line and none of them is load-bearing in the
other half. Three new `ev` values (`calib`, `resume`, `lat`), seven new keys on messages that already
exist, one new `do` verb (`release`) and two keys that sit **after** `z=1` on the one command
(`note=`, `svc=`). An unknown `ev` is already logged as *"this is not an error"* and answered `handled`
(`lrg_dialogue.php`), an unknown key is already ignored by both parsers, and an unknown `do` verb makes
game 401 answer one `Error: unknown command` funcret - which the server never depends on.
Everything 0.5 adds is in **sections 10.13 - 10.19**, the last of which (`witchim`, `fol`, `fv` - the
follower keys of 0.5.1) came in after this paragraph was first written.

**[0.4] THAT ROUND ADDED MENULESS QUESTING (Phase 2).** Everything it added is in **section 10**.

**Backwards (server 0.4.0 + game script 310): additive, no exception.** No `lrg_topics` ever arrives, no
`ExtCmdLRG_SelectTopic` is ever emitted because no session state exists, none of the 10.12 MCM keys is sent
so every server default stands, and everything degrades to 0.3.1 exactly.

**Forwards (game script 400 + server 0.3.1): a SHIPPING RULE, not a wire rule - the two halves of 0.4 ship
together, SERVER FIRST.** This paragraph replaced a claim that was simply wrong, and the correction is the
0.4.0 fix pass's, in the shape of 0.3.1's own `w2` exception above. What a rolled-back server really does
with a 0.4 game: `lrg_topics` / `lrg_dlg` are NOT in a 0.3.1 `external_fast_commands`, so they fall into
Phase 1's `strncmp($lrgType,'lrg_',4)===0` branch, reach `lrgHandleGameMessage()`, match nothing and return
`'pass'` - and `main.php`'s `if (!in_array($gameRequest[0], $fast_commands))` is then **true**, so the
message takes the MAIN LLM semaphore, `processor/request.php` logs "Request cue is empty!" and falls back to
`TEMPLATE_DIALOG`. The result is **one LLM call plus TTS per dialogue open and per subtitle line, with the
raw wire payload read aloud as the player's words**, because the game sends `SendOpen()` on every menu open
and `HarvestLine()` per poll regardless of `bMenuless` / `bDlgDryRun`. The game half therefore carries
`bDlgWire` (`[Dialogue]` in `settings.ini`, default **1**; a MISSING ini key reads as 0, so the default line
is mandatory): with it off, `SendTopics / SendOpen / SendClosed / SendUnhide / SendResult / SendFacts /
HarvestLine` all return early, and a rollback is one MCM toggle instead of a re-install.
**Everything else in the round remains additive in both directions.** Sections 0-7 are unchanged from
v0.3.1 except where a line is marked **[0.4]**.
Design: `glue/PHASE2_DESIGN.md` rev 2; work list: `glue/V04_BUILD_PLAN.md`.

Binding for GAME, BEHAVIOUR, SCENEINDEX, FLOWTESTS, and **[0.4]** DIALOGUE and INDEX. **[v1]** = from 0.1 and must keep working; **[NEW]** = added in 0.2; **[0.3]** = added in the 0.3 round; **[0.3.1]** = added in this round. If your code and this file disagree, this file wins: do not "fix" another builder's side, put the conflict in your `forIntegrator` notes. Evidence and reasoning: `research/v02-contract.md`, `glue/V03_DESIGN.md`, and for this round `research/pt7-relationship.md`, `research/pt7-act-coverage.md`, `research/pt7-outro.md`.

**[0.3.1] Everything in this round is additive in both directions.** A server 0.3.1 works with game script 300 (it never requires `dur` / `how` / `outro`, and `after=` is one more key an old script ignores); a game script 310 works with server 0.3 (an `outro` request finds no cue and falls back to `TEMPLATE_DIALOG`, i.e. exactly today). No new `do=` verb, no new `ev`, no new error reason.
**One exception, and it is a SHIPPING rule, not a wire rule:** the compound request (w2, section 2) puts two commands in one reply unconditionally - nothing on the wire tells the server which script version the game runs - and the second one is only handled correctly by script **310** (the four-slot de-duplication ring plus the one-slot command queue). Against script 300 the second command can be dropped, and in one case double-executed. **The two halves of 0.3.1 therefore ship together**, or `intent.compound` is set to `false` until the game half is installed. Everything else in the round remains additive in both directions.
**The five wire items of this round**, all additive, none of them a new verb: (w1) `after=` on `StartIntimacy` (section 2) · (w2) two commands in one reply (section 2) · (w3) `dur=` and `how=` on `ev=end` (1.2) · (w4) nothing else · **(w5) the outro request: `lrg_scenetalk` with the text `outro` (1.4), game -> server, answered as an ordinary CHIM speech turn with no wire reply of its own.** w5 was implemented on both sides and documented on neither; it is written down here so both halves are held to the same contract. It needs the main session's explicit blessing as a fifth item beyond w1-w3.

## 0. Ground rules
- Wire format [v1]: `k=v;k=v`. A value never contains `; | @ "` or a newline (game: `LRG_Main.CleanForWire`; server: `lrgKv`). Both sides ignore unknown keys. **A missing key always means the strict / neutral reading** (fail closed), so every new key is optional for the receiver.
- NPCs are keyed by display name. The player thread is OStim ThreadID 0 and always has exactly 2 actors (player + one NPC). Adding actors is out of scope.
- Versions **[0.4.1]**: snapshot `v=2` (**unchanged** - the conversation hold adds two optional keys,
  `dist` and `hold`, section 1.1, and an absent key means "nothing is known" / "not held");
  `LRG_Main.CurrentVersion` **401**; `LRG_VERSION` `0.4.1`; `manifest.json` **0.4.1** (one new server function,
  `lrgDlgHideEndConversationOnHold()`, and one new config key `dialogue.hold_hides_end_conversation`).
  Every other version marker is unchanged, and **the two halves ship independently in both
  directions**: an 0.4.0 server ignores `hold`, and a 400 game script simply never sends it.
- Versions **[0.4]**: snapshot `v=2` (**unchanged**. Menuless questing adds no snapshot key at all; "everyone
  has a price" adds three optional ones - `door`, `paidok`, `pm`, section 1.1 - and nothing on the server
  tests `state['v']`, so no bump was needed);
  `LRG_Main.CurrentVersion` **400**; `LRG_VERSION` `0.4.0`; `manifest.json` **0.4.0**;
  `LRG_ACTIONS_VERSION` **10** (the `BeginIntimacy` row gains the `amount` slot, section 10.7 - set by the
  INTIMACY lane in `lib/lrg_actions.php`); `LRG_DLG_ACTIONS_VERSION` **1** with its OWN marker
  `data/.dlg_actions_v1`, never inside `LRG_GLUE_ACTIONS`; `LRG_PROMPT_INDEX_VERSION` **1**;
  `LRG_DLG_STATE_VERSION` **1**; `LRG_DLG_SCHEMA_VERSION` **1** (Phase 2 runs its own schema ensure with
  its own marker `data/.dlg_schema_v1` over `migrations/005_lrg_dialogue.sql` and
  `migrations/006_lrg_prompt_index.sql`, so it never waits on `LRG_SCHEMA_VERSION` moving).
- Versions **[0.3.1]**: snapshot `v=2` (unchanged); `LRG_Main.CurrentVersion` **310**; `LRG_ACTIONS_VERSION` **9** (the `RequestAct` row's description now says an act key may carry a position); `LRG_INDEX_VERSION` **4** (the digest format is unchanged - positions are derived at query time from the tags, actor tags and id words the digest already stores); `LRG_SCHEMA_VERSION` **3** (`migrations/003_lrg_romance_affinity.sql` adds `lrg_romance.last_affinity` and `last_affinity_at`); `LRG_VERSION` `0.3.1`.
- **[0.3] Compatibility matrix.** Every change this round is additive and BOTH halves ship independently (the installer refuses while the game runs):

| | game 200 (old) | game 300 (new) |
|---|---|---|
| **server 0.2 (old)** | today | the new game behaves exactly as today when `wait` / `hold` / `nowarp` are absent |
| **server 0.3 (new)** | the old game ignores `wait` / `hold` / `nowarp`, so it keeps today's timing and today's warp policy; everything else (intent, directive, safety net, acts, stale-state-by-funcret, logging) is server side and works unchanged | full feature set |

  The rules that make this true: the server never invents a new `do=` verb (`RequestAct` is resolved server side into `do=goto`); no new key is ever required; the game never requires one either.
- **[0.3] Owner addendum 3 (binding).** Inside a running scene the player's requests are never refused in character. There is no `Decline` action, no refusal-marker detection and no per-act decline memory - do not build them. Her consent is decided **before** a scene exactly as in 0.2 (gates, interest, her own choice of `BeginIntimacy`), a refusal there is final and is never overridden, and the server never starts a scene by itself. The only thing that may still say no inside a scene is the GAME (act not installed, filtered, wrong actors or furniture, unreachable with warp off) - answered in words by the CANT directive (section 7.2), never with silence.
- Rails (never configurable): adults only, fail closed (`adult=1` on a fresh snapshot, re-checked in game); the NPC must be willing; `stop` ends the scene at once and wins every tie; kill switch; player always a participant; forced / non-consensual / creature scenes never indexed; no file of LoreRim, CHIM, OStim, OARE and no OStim setting is ever written. No explicit example dialogue in code, prompts, tests or docs: write rules, not lines.

## 1. Game -> server
Transport [v1]: state = `AIAgentFunctions.logMessageForActor(payload, type, npcName)` (no reply; types listed in `external_fast_commands`, handled at main.php:193 before the MAIN lock). LLM-triggering = `AIAgentFunctions.requestMessageForActor(text, type, npcName)`. Results = `funcret` (1.6).

### 1.1 `lrg_npcstate` - snapshot of the NPC the player deals with (every ~20 s while watched, 10 s throttle, forced at scene start)
[v1] keys: `v` · `npc` · `ref` FormID · `on` 0/1 intimacy on in MCM · `adult` 0/1 · `sex`, `psex` 0 male / 1 female / -1 · `lvl`, `plvl` · `combat` 0/1 either one · `scene` 0/1 NPC in a quest Scene · `ostim` 0/1 NPC in an OStim thread · `mate` · `married` · `pspouse` · `courting` · `rank` -4..4 · `conf` `moral` `aggr` · `gold`, `pgold` · `interior` 0/1 · `wit` awake non-teammate adults in radius with LOS or < 600 · `witfol` teammates in radius · `witkid` 0/1 (scan stops there; counts partial) · `pspeech` Speech skill · `pdb` 0/1 MQ104 done · `psouls` · `pquests` · `class` EditorID · `fac` <= 48 faction EditorIDs, csv, <= 560 chars, LAST key.

[NEW] keys, inserted before `class` (all verified feasible, see report section 3):
| key | value | how (game) |
|---|---|---|
| `x` | 0..2 | MCM `iExplicitness:SceneTalk` (same value as in `lrg_scene`) |
| `loc` | location name, <= 40 chars, cleaned, may be empty | `player.GetCurrentLocation().GetName()` |
| `ltype` | first match of `inn, phouse, house, castle, temple, store, guild, jail, city, town, wild` | `Keyword.GetKeyword("LocTypeInn" / "LocTypePlayerHouse" / "LocTypeHouse" or "LocTypeDwelling" / "LocTypeCastle" / "LocTypeTemple" / "LocTypeStore" / "LocTypeGuild" / "LocTypeJail" / "LocTypeCity" / "LocTypeTown" or "LocTypeSettlement")` + `Location.HasKeyword`; no location or no match = `wild` |
| `cellown` | `npc, player, other, none` | `Cell.GetActorOwner()` == NPC `GetActorBase()` or NPC `IsInFaction(Cell.GetFactionOwner())` -> `npc`; same test for the player -> `player`; no owner -> `none` |
| `home` | 0/1 the NPC's editor location is the current location | `npc.GetEditorLocation()` vs `player.GetCurrentLocation()` (`IsSameLocation`) |
| `nhome` | name of the NPC's editor location, <= 40 chars, may be empty | `npc.GetEditorLocation().GetName()` |
| `prent` | 0/1 the player has a rented bed in this cell. Only computed when `ltype=inn`, cached per cell (refresh <= every 120 s) | `PO3_SKSEFunctions.FindAllReferencesOfFormType(player, 40, 6000.0)`; a ref whose `GetActorOwner()` == player `GetActorBase()` and whose `OFurniture.GetFurnitureType(ref)` is in the bed family (the installed `RentRoomScript.psc:23` sets exactly that owner) |
| `nearf` | csv of OStim furniture TYPES with a free ref in reach, closest first, max 6, may be empty | `OFurniture.FindFurniture(2, player, <MCM radius, default 1200>, 96.0)` + `OFurniture.GetFurnitureType(ref)`; drop `none` |
| `bedown` | `npc, player, other, none` or empty (no bed in `nearf`) | owner test as `cellown`, on the closest bed-family ref (type `bed` or `OFurniture.IsChildOf("bed", type)`) |
| `sde` | -1 or 0..5; only for Serana (actor base EditorID `DLC1Serana`) and only if `Quest.GetQuest("SDE_R001")` exists | count of `IsCompleted()` over quests `SDE_R001 .. SDE_R005`. Marriage arrives through `fac` (`SDE_RMarriedFaction`), lover status through `rank` = 4 (set by SDE_R005) |
Survival state is NOT on the wire: the cold exemption is game-only (section 4.3).

**[0.3] one new key**, inserted before `class` (`class` stays last but one, `fac` stays last):
| key | value | how (game) |
|---|---|---|
| `sess` | the session tag, `1000..9999` | `LRG_Main.sessionTag`, re-rolled in `LRG_Main.Maintenance` on every load |
Server handling: a snapshot whose `sess` differs from the open scene row's `_sess` closes **every** open row (name-free: a reload may land on a save whose partner was somebody else) and stamps `session_at`, which feeds the post-reload cooldown (6.6). A snapshot with **no** `sess` (game 200) closes nothing and stamps nothing.
**[0.4] three new keys** (owner addendum 6 + the closed-door privacy fix). All optional, `v` stays `2`:
| key | value | emitted | absent means |
|---|---|---|---|
| `door` | 0/1 | right after `interior=`. `1` = the player is in an INTERIOR **and** every door within `fDoorRadius:Intimacy` is shut | say nothing: the server words nothing about the room and reads `wit` exactly as it always did |
| `paidok` | 0/1 | MCM `bPaidIntimacy:Intimacy` | **ON, not 0.** A game script 310 has no such key at all and `paid_intimacy.enabled` decides on its own; an explicit `paidok=0` is OFF |
| `pm` | `0.25`..`4.00`, two decimals | MCM `fPriceMultiplier:Intimacy`, clamped game-side (`<= 0` is sent as `1.00`) | `1.0`. The server reads absent **or** `<= 0` as 1.0 - two independent guards, because a `0` here would make every NPC free |
`pgold=<n>` (the player's purse) was already on the snapshot from v0.1 and is unchanged; it is what the
affordability check reads, absent reads as `0`, and an affordability check that cannot be made never accepts.
`wit` is still one number with one meaning - the door rule changes how the GAME counts it, never how the
server reads it.

**[0.4.1] two new keys** (the conversation hold, owner addendum 8). Both inserted right before the place-fact block, i.e. still before `class`; `v` stays `2`:
| key | value | emitted | absent means |
|---|---|---|---|
| `dist` | the NPC's distance from the player in units, integer | every snapshot | nothing is known about the distance - exactly the gap that made research/pt8-walkaway.md 3.4 unanswerable. No server rule reads it; it is a log fact |
| `hold` | 0/1 | `1` only while `LRG_Main` really holds this NPC for a live conversation (`SetDontMove` on) | **not held.** An older game script has no such key and nothing changes |
Server handling of `hold`: `lrgDlgHideEndConversationOnHold()` (called at brace depth 0 from `functions.php`, after both lanes have built their offer) removes **`EndConversation`** from `ENABLED_FUNCTIONS` while this NPC's snapshot says `hold=1` and is at most `dialogue.hold_max_age_seconds` (45) old, so the model cannot choose to leave a conversation the player is still having. Config `dialogue.hold_hides_end_conversation`, default `true`. It only ever removes, never adds; a stale or absent snapshot changes nothing; nothing else on the wire depends on it.

**[0.5.1] three new keys** (followers, owner addendum 10). All optional, `v` stays `2`; the full table
and the server's use of them is **section 10.19**:
| key | value | emitted | absent means |
|---|---|---|---|
| `witchim` | integer | inside the witness block (`wit;witfol;witchim;witkid`) | `0`. The player's own companions the teammate flag does not see; they are counted in `wit` as well, so this can only make the privacy gate stricter |
| `fol` | one `k:v,k:v` value | right after `hold=` | nothing is known about followers and nothing changes |
| `fv` | 0/1 | after `fol` | the server's own default (`1`) |

**[0.3] A first meeting is not a reload.** `session_at` is stamped only when a reload is actually evidenced - this NPC's memory already held a DIFFERENT `sess`, or an open scene row from another session was just closed. The very first snapshot an NPC ever produces stores `sess` alone: stamping it there held her own first move and her first initiative tick back for `start.post_reload_cooldown_seconds` (120) the first time the player ever met her, which is the "NPCs would not act" complaint by construction.

### 1.2 `lrg_scene` - live scene state, debounced 0.8 s
`ev` = `start | change | climax | end`, [NEW] `winddown` (sent once when a wind-down begins). [v1] keys: `npc` · `cid` (same for the whole thread) · `scene` · `speed` · `maxspeed` · `trans` 0/1 · `furn` the SPECIFIC furniture type of the object the thread is on (`OThread.GetFurniture(0)` -> `OFurniture.GetFurnitureType(ref)`, same vocabulary as `nearf` and the command parameters; "" / `none` = none). NOT `OThread.GetFurnitureType(0)`, which reports the LIST type (`bed`) while every installed bed scene is authored `doublebed` / `singlebed` · `ppos`, `npos` actor indexes · `actors` · `byglue` 0/1 · `und` 0/1 NPC body is undressed · `who` display name (only `ev=climax`) · `tags` · `acts` · `x` · `next` live routable ids (<= 24 ids / 700 chars, LAST key, absent on transitions). In v1 `ev=end` carried only `ev, npc, cid, scene, byglue`; **[0.3.1] it also carries the optional `dur` and `how`, and every push including `ev=end` carries `sess`** - see the two tables below, which win over this sentence.
[NEW] keys: `auto` 0/1 `OThread.IsInAutoMode(0)` · `leader` `npc | player | auto` (who drives; not to be confused with the [v1] bool `$turn['lead']` = "this request is a lead tick") · `stall` 0/1 `OThread.IsClimaxStalled(0)` · `ncl`, `pcl` climaxes so far (`OActor.GetTimesClimaxed`) · `wd` 0/1 winding down · `nearf` as in 1.1, centred on the player, refreshed only on `start` and after a furniture change · `undp` csv of parts the NPC has off (`all` or `body,head,hands,feet`).

**[0.3] three new keys, and deliberately NO new `ev`:**
| key | value | when |
|---|---|---|
| `sess` | the session tag, as in 1.1 | **[0.3.1] every push, `ev=end` included** (`LRG_OStim.PushState` adds it, so does the post-load close, and since LRG_OStim.psc:3550 so does `FinishThread`'s ordinary end - the v0.3 exception is gone). Nothing depends on it: `lrgNoteSession()` is only ever driven from `lrg_npcstate` |
| `prev` | the previous **non-transition** scene id, `""` at the start | on `ev=change` |
| `sync` | `1` | on the FIRST `ev=change` after a load (`LRG_OStim.Maintenance`, running branch, after `lastSentKey` was cleared) |
`ev=sync` was considered and **rejected**: an old server would fall through `lrgStoreScene()` with `ev != end`, i.e. `active=1`, and CREATE a fresh open scene row with no scene id - manufacturing the very reload bug this round fixes. Instead:
- **running branch after a load**: an ordinary `ev=change` push carrying `sync=1`. The server only forces the `_sess` stamp and skips de-duplication; an old server reads an ordinary push.
- **no-thread branch after a load**: an ordinary `ev=end;npc=<partnerName>;cid=<startCid>;scene=;byglue=<n>;sess=<tag>`, sent **only when `partnerName != ""`**. An old server closes the row correctly; a new one also learns `sess`. The nameless case is covered by the forced crosshair snapshot (1.1 `sess` + `ostim=0`).
Server handling of `prev`: it repairs a dropped push - when `prev` names a scene that is not the last entry of `_visited`, that one is appended first (7.3, no circling).

**[0.3.1] two new keys on `ev=end` (wire agreement w3), both optional:**
| key | value | meaning |
|---|---|---|
| `dur` | seconds | how long the thread really ran. Missing = the server measures from its own `_started_at` |
| `how` | `finished \| stopped \| interrupted \| lost` | how it ended. Missing = `finished` when a climax was seen, else `stopped` |
They drive two things and nothing else: the R1b affinity gain (which needs "a scene that really happened", not a cancelled start or a reload) and the R3 outro facts.

**[0.4] one new key, `paid`:**
| key | on | meaning | absent means |
|---|---|---|---|
| `paid` | **every push** of a paid scene, `ev=start` and `ev=end` included | the gold that REALLY left the player's purse for this scene, game-confirmed | `0` = the scene was free |
On every push on purpose: the server reads it back from the last stored push at `ev=end`, and any single
push can be de-duplicated away. It is the only truth the halved relationship gain, the
`lrg_romance.gold_accepted` total and her memory are built from - **never** the `pay=` the server sent,
because the game re-checks the purse a moment before the scene really begins (10.7).

**[0.3.1] CASE. The server folds `ev`, `leader` and `how` to lower case on arrival.** In production the wire delivers capitalised values (playtest 7: 26 `scene Change`, 5 `Start`, 4 `Climax`, 35 `leader=NPC`, against 4 lowercase `scene end`), while the game source and the installed `.pex` are lowercase - CHIM's DLL transforms a value built at runtime from a variable and leaves a single string literal alone, which is why only `FinishThread`'s one-literal `ev=end` arrived intact. Every `=== 'start' | 'change' | 'climax'` test in the server failed silently: `_visited`, `_acts_done` and `_climaxes` stayed empty, the landing notes and the scene-start line never fired once, and every summary said "<npc> led". Both sides may send either case from now on.

### 1.3 `lrg_log` [v1]
`cid=<cid>;msg=<text>`; `msg` is LAST and may contain `;` and `=`; <= 300 chars; written as `[cid=..] GAME <msg>` even when the feature is off.

### 1.4 `lrg_scenetalk` [v1] - request, only while a thread with that partner runs
Text exactly `lead` = scene-lead tick (the NPC may act); any other text = speech-only moment description. [NEW] the game sends no lead tick while `leader=player`, `leader=auto`, `auto=1` or `wd=1`.
**[0.3.1] Text exactly `outro` = the goodbye turn (R3)**, fired ONCE by the game right after `ev=end`. It is deliberately the same request type: a server on 0.3 finds no cue for it, logs "Request cue is empty!" and falls back to `TEMPLATE_DIALOG` - today's behaviour, no regression. The server answers it only while an unexpired outro ticket exists (5), and the turn offers no glue action at all (6.2, mode `outro`). The game holds the NPC in place until her line has been spoken or its own timeout passes; the server sends nothing new on the wire for that.

### 1.5 `lrg_initiative` [NEW] - request, text exactly `approach`: "the NPC may make a move of her own"
The game sends it for the watch target (`LRG_Main.watchActor`) when ALL hold: MCM initiative on; intimacy enabled; no thread running or starting; NPC within 600 units, loaded, not dead, not in combat; no menu open; `isActorTalking(npc) == 0`; >= MCM interval (default 90 s, min 30) since the last tick; >= 20 s since the last spoken line either way. It forces a snapshot (`MaybeSnapshot(npc, true)`) immediately BEFORE the request. The game decides nothing else: the server drops most ticks before the MAIN lock at zero LLM cost (section 6.3).

### 1.6 Results [v1]
`logMessageForActor("command@<Code>@<param echoed unchanged>@<result>", "funcret", npc)` then `commandEndedForActor(Code, npc)`. Failure = result starts with `Error: ` + one reason from the closed list below; anything else = success. [NEW] on every `Error:` of a glue command the game also shows `Debug.Notification("LoreRim Glue: <reason>")`. **[0.5.5]** and a failure the player was waiting on is also SPOKEN by her in the same exchange, in CHIM's own funcret turn (section 10.21); a success still says nothing and now ends before the MAIN lock.
**[game script 507] Result markers, additive.** Success = result starts `OK: ` (followed by the neutral success string below). Failure = `Error: ` + a short reason **in her own words** (`LRG_Main.SayReason`: "we are not doing anything right now", "I cannot get into that from here"; escort reasons in the third person with her name, because the same line is the owner's corner note). The **technical** reason from the closed list below travels as **`err=<reason>`** appended to the echoed field 2 (`CleanForWire`, cut at 160 characters; only on a failure). A **`late=1`** funcret re-reports a command that was already answered `OK:` and came undone afterwards (the escort's scene came back - `TickEscort`; the `after=` position of a start that cannot follow - `TickAfterScene`): same code, same cid, **no** `commandEndedForActor`, no corner note. Shape: `command@<Code>@<param echoed>[;late=1][;err=<technical reason>]@OK: <neutral>` / `...@Error: <her words>`. **Every machine match uses `err=`** (fallback: the `Error:` text, which IS the technical reason for scripts <= 506); her words are only ever repeated to her. A result with neither marker comes from a script <= 506. The corner note (`bNotifyErrors`) still shows the technical reason, unchanged. The log line reads `result <Code>[ (late)]: Error: <her words> [why: <technical>]`. The server never sends `err=` or `late=`.
Success strings (neutral, never spoken): start `They draw close. The scene begins.` · stop `The scene ends.` · pace `The pace changes.` · hold `Holding back.` · release `No longer holding back.` · goto `Moving into a new position.` · [NEW] climax `A peak is reached.` · pullout `They ease apart.` · winddown `Winding down.` · furniture `Moving to the <furn>.` · lead `Lead: <who>.` · clothing `<npc> undresses.` / `<npc> undresses, and so do you.` / `You undress.` / `<npc> gets dressed again.` / `Both of you get dressed again.` / `You get dressed again.`
**[0.4] New reasons, added to this closed list** (every one of them is decided in game; the server only
matches on them): `they cannot talk right now` · `her voice is not ready` · `that is not on the table right
now` · `that moment has passed` · `not enough gold` · `dry-run mode, nothing was clicked` · `a conversation
is in progress` · **`the player does not have that much gold`** (the paid-intimacy refusal, raised by
`LRG_OStim` when the purse is short at the moment the scene would really begin; the server maps any reason
containing `gold` onto her next turn, so a future variant still reads right).
**[0.4] New success strings:** `The matter is raised.` · `The conversation is left.` · `The menu is shown.`
· `Noted.` (the `do=award` acknowledgement of 10.5).
**[v1.0 / script 513] New reasons on this closed list** (menuless questing, 10.29; `lrgVoicedWhy` / `SayReason` map
each to her words, and `tools/test_gates.php` 35v parses them out of `LRG_Dialogue.psc`): `choose that one on the list
yourself` (a pick reached a live session the driver does not drive); `the entry moved before the click`; `the click
did not take`; `the list could not be read`; `nothing came of that` (a click with no verified effect); `the
conversation was interrupted`; `still learning the dialogue menu - the next conversation of any kind measures it`;
`still learning the dialogue menu - another conversation will not finish it: <why>`; `the menuless dry run is on
(Menuless questing page)`. `that is not on the table right now` stays ONLY for a pick whose line is not on the live
list. New success string (gate B): `gave <n> septims` (the `do=award give=` acknowledgement, 10.5).
**[v1.0] Not voiced in the same exchange for `ExtCmdLRG_SelectTopic` (CHIM brief C5).** The [0.5.5] sentence above ("a
failure the player was waiting on is also SPOKEN by her ... in CHIM's own funcret turn") does NOT hold for the
SelectTopic command: its catalog row has follow-up OFF (`lrg_dialogue.php` ~5863, `followup.enabled=false`),
`lrgFuncretVerdict` passes its funcret to CHIM untouched (`lrg_actions.php` ~1070), and CHIM's `processor/funcret.php`
then ends without an LLM turn; pre-lock the glue reads that funcret only for a `do=open` refusal and a `do=award`
(`lrgDlgTopicFuncret`, `lrg_actions.php` ~912 - "nothing else, nothing voiced"). So "map each to her words" means
three different deliveries: (1) a SYNCHRONOUS game refusal of a pick (`choose that one on the list yourself`, `that is
not on the table right now`, a stale / older-than-20-s / other-speaker pick; `LRG_Dialogue.psc` ~1017 / ~1028 / ~3060
via `ReportResult` / `ReportOnce`) reaches the owner ONLY as the corner note, never in her words; (2) a loop outcome
the game reports afterwards (`ev=result why=` - the click did not take, nothing came of that, the list could not be
read, the conversation was interrupted) and a server-side gate refusal the voice gap skipped reach her words on his
NEXT speech turn, in `<what_just_happened>` ("Nothing came of it: ..." - `lrgDlgNoteRefusal`, `lrgDlgGroundTruth`,
`lrg_dialogue.php` ~2947 / ~2981); (3) only what the server knows BEFORE she speaks (the read-only clause, the stage
rail, the leave guard, crit 2) is said in the same turn, by her reply itself.
Error reasons [v1]: `OStim is not installed` · `the feature is switched off` · `not authorised by the server gate` · `a scene is already running` · `a scene is just starting` · `a scene with someone else is running` · `no scene is running` · `the actor could not be found` · `adults only` · `combat` · `a quest scene is running` · `too far apart` · `OStim does not accept these actors` · `OStim rejected the actors` · `a child is nearby` · `someone is watching` · `a companion is present` · `the weapons could not be put away` · `the scene did not start` · `dry-run mode, nothing was started` / `dry-run mode, nothing changed` · `no position was named` · `still moving into the previous position` · `the scene is still starting` · `in the middle of a transition` · `already there` · `that position does not exist for the two of you` · `that is not a position` · `that position cannot be reached from here` · `unknown scene request` · `unknown clothing request` · `unknown command`. [NEW]: `no suitable furniture nearby` · `not possible in this position` · `not available`.

## 2. Server -> game commands
Wire line [v1]: `<npc>|command|<Code>@<param>`; delivered through bridge script `LRG.DispatchExternalCommand` and mod event `CHIM_CommandReceived` (de-duplicated 5 s). Every param starts `ok=1;cid=<cid>` and [NEW] carries `npc=<display name>` so the server can attribute the echoed funcret. Without `ok=1` the game refuses. The game re-checks every hard gate itself (adults only on every verb but `stop`, pair, privacy, combat).
`warp=1` on a `goto` is a HINT, not an instruction: the server's route oracle and OStim's live one are not nested sets, so when they disagree the game warps anyway if the MCM toggle `bAllowWarp:Intimacy` is on, and only refuses with `that position cannot be reached from here` when it is off. "If I say a position it should go to that position" beats a refusal.

| Code | param keys |
|---|---|
| `ExtCmdLRG_StartIntimacy` | [v1] `scene` no-furniture start id or empty · `undress` 1 = OStim MCM rules, 0 = `NoUndressing` · `maxwit` · `folok`. [NEW] `furn` furniture type to start on or empty · `fscene` start id on that furniture. **[0.3.1]** `after` optional scene id to navigate to a few seconds after the start (w1) |
| `ExtCmdLRG_SceneControl` | `do` = [v1] `goto, faster, slower, speed, stop, hold, release`, [NEW] `climax, pullout, winddown, furniture, lead` · `scene` · `speed` · `warp` 0/1 · [NEW] `who` · `furn` · `linger` seconds |
| `ExtCmdLRG_Clothing` | [v1] `do` = `undress, dress` · `who` = `npc, both`, [NEW] `player` · [NEW] `part` = `all` (default) `, body, head, hands, feet` · `maxwit`, `folok` (outside a scene) |
| `ExtCmdLRG_Invite` [NEW] | SERVER-ONLY: the post-LLM gate records it and removes the line; it never reaches the game (if it ever does, the existing `Error: unknown command` branch answers) |
| `ExtCmdLRG_RequestAct` [0.3] | SERVER-ONLY, like `ExtCmdLRG_Invite`: the post-LLM gate resolves the act id into a scene id and emits an ordinary `ExtCmdLRG_SceneControl@do=goto;...` line. The game needs no new branch and script 200 works unchanged |
| `ExtCmdLRG_Escort` **[0.5.4]** | SERVER-EMITTED, never offered to the model (a line of it FROM the model dies as "not offered"). **[0.5.5]** It has an INACTIVE catalog row (`is_activated` 0, available to nobody, `request_types_any` `lrg_never`) only so CHIM can voice its refusals (10.21). Exact shape: `ok=1;cid=<turn cid>;npc=<name>;do=<follow\|wait\|release>;safe=<csv of quest EditorID patterns>` (key order fixed, no decoration keys). `do=follow` = stop her running engine scene ONLY if its owning quest matches `safe`, then let the follow package win; `do=wait` = stop following (she stays behind; the game takes the follow off); `do=release` = let her go back to her own AI. Full contract **section 10.20**. An old game answers `Error: unknown command` (+ one corner note); the server swallows it. **[script 507]** When her scene comes back after `OK: ... comes with you.`, the game sends one `late=1` `Error:` funcret for the same cid (1.6) - voiced at once (10.21 section 9) |

**[0.3] three new param keys, all optional, all with a neutral reading when absent:**
| key | meaning (the game's reading) | on |
|---|---|---|
| `hold=<n>` | "do not take a lead turn for the next `n` seconds". Clamp `0..600`; missing = `0` = today | every `SceneControl` verb and every `Clothing` command, with ONE exception (below) |
| `nowarp=1` | "if this position is not reachable, refuse; never fade-jump". Missing = `0` = today's `bAllowWarp:Intimacy` behaviour. A NEW key deliberately, **not** a re-reading of `warp=0`: re-reading `warp=0` would make a new game start refusing the plain `goto`s an old server sends | `goto`, `furniture` |
| `wait=begin\|end` | hold the OStim call until her spoken line has started (`begin`) or finished (`end`). Missing = act immediately = today. Both budgets end in "do it anyway", so a start is never lost | `StartIntimacy` (always `end`), and `goto` / `furniture` / `undress` / `dress` when SHE initiated them |
`warp=1` and `nowarp=1` are mutually exclusive and the server never sends both. `wait=` never produces an error, and `nowarp=1` reuses the existing `that position cannot be reached from here`: **no new error reason in this round**.
**[0.3] The ONE `hold=` exception.** A `do=lead;who=npc` that answers a recognised `lead_npc` intent ("you lead", "surprise me", "do what you want") carries **no** `hold=` at all. `LRG_OStim.CmdLead` clears its own `leadHoldUntil` and then re-applies whatever `hold=` the same command carries, so decorating the hand-over would gag her for `lead.hold_seconds` - the named G2 control doing the exact opposite of what the player said. Every other `do=lead` (one the model chose while the player is steering) keeps its hold, and the §9 hold carrier builds its own kv and is unaffected. Server side this is the only branch in `lrgDecorate()` that skips the key.

Exact shapes the server emits after `ok=1;cid=..;npc=..` (key order fixed, tests match on substrings; the [0.3] decoration keys always come LAST, in the order `warp` / `nowarp`, `wait`, `hold`):
`scene=<id>;undress=<0|1>;furn=<type or empty>;fscene=<id or empty>;maxwit=<n>;folok=<0|1>;wait=end` · `do=goto;scene=<id>[;warp=1][;nowarp=1][;wait=begin][;hold=<n>]` · `do=faster` / `do=slower` / `do=speed;speed=<n>` `[;hold=<n>]` · `do=stop[;hold=<n>]` · `do=hold` / `do=release` `[;hold=<n>]` · `do=climax;who=<npc|player|both>[;hold=<n>]` · `do=pullout[;hold=<n>]` · `do=winddown;scene=<id or empty>;warp=<0|1>;linger=<s>[;hold=<n>]` · `do=furniture;furn=<type>;scene=<id>[;nowarp=1][;wait=begin][;hold=<n>]` · `do=lead;who=<npc|player|auto>[;hold=<n>]` · `do=<undress|dress>;who=<npc|player|both>;part=<all|body|head|hands|feet>[;maxwit=<n>;folok=<0|1>][;wait=begin][;hold=<n>]`. An unknown `do` is answered `Error: unknown scene request` [v1], so a newer server never breaks an older game.

**[0.3.1] `scene=` on a start may now be the requested act's own scene.** The owner removed the forced gentle lead-in: when the player has just asked for something and SHE chooses `BeginIntimacy`, the thread begins directly in a scene of that act. OStim allows it - `OThreadBuilder.SetStartingAnimation` has no intro flag and no route requirement, so all 305 furniture-free plus 100 furniture-bound two-actor scenes are legal starts. Her own unasked-for move still begins gently. `after=` is set only when the scene the thread really begins on cannot carry the requested act (the furniture start won, or only a gentle scene was startable): an old game script ignores the key and simply starts where it always did.

**[0.3.1] two commands in one reply (wire agreement w2).** A COMPOUND request ("take my clothes off and then missionary") is sent as two ordinary commands in the same reply, each with its own `cid` (the second is `<cid>b`). `LRG_Main.HandleCommand` de-duplicates on the key `npc|command|parameter` (LRG_Main.psc:377-383; script 310 keeps four slots) and the differing `cid=` already makes the parameters differ, and `LRG_OStim` refuses a second command only while one is PARKED for her spoken line (`pendingVerb`, LRG_OStim.psc:2334 / :2140) - which never happens on this path, because a player-requested command carries no `wait=`.
**`goto` + `goto` is queued, not dropped (game script 310).** `NavigateBlockedReason()` still answers `still moving into the previous position` while `navInFlight` is true, but the game now holds that command in a ONE-slot queue (`LRG_OStim.DeferCommand` :964 / `TickDeferred` :1002; call sites :1441, :2641, :2648, :2826, :2853, :2939, :3171) and retries it about once a second for `fQueueWait` (MCM, **default 30 s** - it must be at or above the game's own 25 s navigation deadline, `GoToScene` LRG_OStim.psc:2984, or the second half is given up while the first move is still legitimately in flight; the queue is additionally held open past the deadline, up to twice it, while `starting || pendingStart || navInFlight || pendingVerb != ""`). If the deadline passes it is answered with its original reason; a THIRD command arriving while one is queued is still refused at once. On game script 300 there is no queue and the second `goto` is answered with that error immediately - one more reason the two halves of 0.3.1 ship together (section 0).
**Out of a scene there is no second command at all**: `lrgSecondCommand()` only runs on a confirmed running scene, so a compound request outside one is carried entirely by the START - the act half becomes the start's own `scene=`, or `after=` when the thread cannot begin there (w1). The turn directive names both actions in that case and promises "both happen" only then.

**[0.3] the hold carrier.** On a player speech turn inside a scene that produced no command at all, the server appends one no-op command so the game also stops taking lead turns: `do=lead;who=<the leader the scene ALREADY has>;hold=<lead.hold_seconds>`. `who=npc` would silently flip a scene the player had taken over, so the current leader is used. It is throttled to at most once per `lead.hold_seconds / 2`; the game's `leadHoldUntil` is monotonic, so a skipped carrier costs nothing.
**[0.3] It is never sent while the scene is winding down** (`wd=1` on the last `lrg_scene` push). The game's `CmdLead` cancels a running wind-down, so the player who asked to wind down and then said anything at all during the linger would never see the scene end. The game holds the same rail on its side (it also has to hold against a stray `do=lead` from an old server); the server guard makes an un-updated game safe too.

## 3. Verbal verb set (R1 / R8). OStim natives verified in the INSTALLED 7.5.1 sources (`Scripts\Source\<file>:<line>`)
| verb | game does | preconditions beyond "thread 0 runs, `ok=1`" |
|---|---|---|
| start | `OActorUtil.ToArray`:128 + `Sort`:142 -> `OThreadBuilder.Create`:46 -> if `furn` is set and a ref is found: `SetFurniture(b, ref)`:63 + `SetStartingAnimation(b, fscene)`:83, else `NoFurniture(b)`:189 + `SetStartingAnimation(b, scene)` (either id only when `OMetadata.GetActorCount(id) == 2`) -> `NoUndressing`:176 when `undress=0` -> `NoPostDialogue`:166 (OStim's own voiced after-scene lines would talk over CHIM) -> `Start`:216. `SetFurniture` and `NoFurniture` both suppress OStim's furniture message boxes. Do NOT call `NoAutoMode`:148 (upstream: it also blocks a later `StartAutoMode`); instead, on `ostim_thread_start` of a glue-started thread: `if OThread.IsInAutoMode(0)`:305 `-> OThread.StopAutoMode(0)`:321 so OStim's auto mode cannot skip the ladder | all v1 gates; hands emptied first (crash workaround stays). Furniture ref = closest free ref of exactly type `furn` from `OFurniture.FindFurniture`:41; none -> fall back to the standing `scene` |
| goto | `OThread.NavigateTo(0, id)`:96, or with `warp=1` + MCM allow `OThread.WarpTo(0, id, true)`:119 | [v1] `NavigateBlockedReason`; route oracle `OLibrary.GetScenesInRange`:43 |
| faster / slower / speed n | `OThread.SetSpeed`:170, clamped to `OMetadata.GetMaxSpeed`:77 | - |
| stop | `OThread.Stop(0)`:53, always, also in dry-run, also mid wind-down | none (rail) |
| hold / release | `OThread.StallClimax(0)`:234 / `OThread.PermitClimax(0, true)`:242 | - |
| climax | `who` = `npc, player, both`: `OThread.PermitClimax(0, true)` then `OActor.Climax(actor, false)` (OActor.psc:99) | server offers it only when the current scene tier >= sensual |
| pullout | `OThread.AutoTransition(0, "pullout")`:141; false -> `Error: not possible in this position` | not in a transition |
| winddown | separate from stop. `wd=1`, push `ev=winddown`; stop auto mode; `scene` given and allowed -> goto / warp as above; `SetSpeed(0, 0)`; `linger` seconds (clamp 5..120) after landing (or after the command when no `scene`) -> `OThread.Stop(0)`. A later `goto / furniture / climax / lead` or a scene change the glue did not request cancels it (`wd=0`); `stop` ends at once | - |
| furniture | closest free ref of type `furn` (`OFurniture.FindFurnitureOfType`:55, which also matches sub-types) -> `OThread.ChangeFurniture(0, ref, scene)`:288; register `ostim_furniturechanged` and push `ev=change` with the new `furn` and `nearf`. The server ALWAYS sends `scene` (an empty SceneID lets OStim pick any scene and would skip the ladder): empty -> `Error: no suitable furniture nearby` | MCM furniture off -> `Error: no suitable furniture nearby` (the game has no ref to offer); not in a transition; no ref -> same error |
| lead | `who` = `npc` (default; lead ticks on), `player` (no lead ticks), `auto` (`OThread.StartAutoMode(0)`:312, no lead ticks). `npc` / `player` call `OThread.StopAutoMode(0)`. State is per thread, reported as `leader=`; default from MCM; an adopted thread (started from OStim's own UI) that is in auto mode reports `leader=auto` and is left alone | server offers `auto` only once the ladder reached `scene_progression.auto_mode_min_tier` (default 4) |
| undress / dress | in a scene: `part=all` -> `OActor.Undress`:200 / `Redress`:207; a part -> `OActor.UndressPartial(a, mask)`:216 / `RedressPartial`:225. Outside a scene (OActor natives do nothing there): the glue's own slot strip / re-equip for the same masks, remembered and restored on dress / scene end / watch end [v1]. Masks: body `0x4`, head `0x1003`, hands `0x18`, feet `0x180` | outside a scene: pair + privacy gates [v1] |
Signatures as declared in the installed sources (all `Global Native`):
```papyrus
; OThreadBuilder.psc
int Function Create(Actor[] Actors)                                   ; :46   -1 = an actor is invalid
Function SetFurniture(int BuilderID, ObjectReference FurnitureRef)    ; :63
Function SetStartingAnimation(int BuilderID, string Animation)        ; :83
Function NoPostDialogue(int BuilderID)                                ; :166  API 7.4d
Function NoUndressing(int BuilderID)                                  ; :176
Function NoFurniture(int BuilderID)                                   ; :189
int Function Start(int BuilderID)                                     ; :216  asynchronous for the player thread
; OThread.psc
Function Stop(int ThreadID)                                           ; :53
Function NavigateTo(int ThreadID, string SceneID)                     ; :96   warps silently when no route exists
Function WarpTo(int ThreadID, string SceneID, bool UseFades = False)  ; :119
bool Function AutoTransition(int ThreadID, string Type)               ; :141
Function SetSpeed(int ThreadID, int Speed)                            ; :170
Function StallClimax(int ThreadID)                                    ; :234
Function PermitClimax(int ThreadID, bool PermitActors = false)        ; :242
bool Function IsClimaxStalled(int ThreadID)                           ; :251
string Function GetFurnitureType(int ThreadID)                        ; :277
Function ChangeFurniture(int ThreadID, ObjectReference FurnitureRef, string SceneID = "") ; :288  API 7.3.2
bool Function IsInAutoMode(int ThreadID)                              ; :305
Function StartAutoMode(int ThreadID)                                  ; :312
Function StopAutoMode(int ThreadID)                                   ; :321
; OActor.psc  (every call is a no-op for an actor who is not in a thread, OActor.psc:3-5)
Function Climax(Actor Act, bool IgnoreStall = false)                  ; :99
int Function GetTimesClimaxed(Actor Act)                              ; :108
Function Undress(Actor Act) / Function Redress(Actor Act)             ; :200 / :207
Function UndressPartial(Actor Act, int Mask) / Function RedressPartial(Actor Act, int Mask) ; :216 / :225
; OFurniture.psc
string Function GetFurnitureType(ObjectReference FurnitureRef)        ; :16   "none" = not usable furniture
bool Function IsChildOf(string SuperType, string SubType)             ; :28
ObjectReference[] Function FindFurniture(int ActorCount, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) ; :41  closest free ref of EACH type, sorted by distance
```
Game duties without wire impact [NEW]: re-assert `AIAgentFunctions.setAnimationBusy(1, partner)` on every scene change and every 5 s tick while the thread runs; at thread end OStim redresses what it undressed, then the glue restores weapons and remembered clothes [v1]; cold exemption (4.3).
**Dropped verbs, with reason:** realign / alignment, free camera, hide UI, scene search menu: no script native exists (UI-only keys `AlignmentKey`, `FreecamKey`, `HideUIKey`, `SearchKey`; position search is covered by `goto` by name). Leaving furniture (`furn=none`): `ChangeFurniture` with `None` is undocumented, and not needed (a furniture thread still routes to no-furniture scenes through the supertype chain). Adding a third actor: out of scope. Changing OStim MCM values (end-on-climax, undress rules): forbidden rail. `OPlayerThread.SetPlayerControl` / `NoPlayerControl`: not used, the owner keeps OStim's keys as a fallback.

## 4. Game-side notes
4.1 `compile.ps1` API list must gain `OFurniture` (and any other OStim script newly called). 4.2 New MCM settings (names are GAME's choice; defaults binding): initiative on (1) + interval 90 s; furniture on (1) + radius 1200; default lead `npc`; cold exemption on (1). 4.3 Cold exemption, a real hook exists: `SurvivalModeImprovedApi.RestoreColdLevel(float)` (global native, `Survival Mode Improved - SKSE\Source\Scripts\SurvivalModeImprovedApi.psc:4`, mod enabled in profile Ultra). Call it from the 5 s tick while a thread runs or while the glue holds remembered clothes of the player; only after confirming the plugin is loaded with `Game.IsPluginInstalled("SurvivalModeImproved.esp")` (SKSE `Game.psc:296`) - **not** `GetModByName(..) != 255`, which reports an ESL-flagged plugin (header flag 0x200, as this one is) as missing. Add that source folder (or a declaration stub) to the compile imports. Player only: SMI tracks no NPC cold.
4.4 The master switches (`bEnabled:General`, `bKillSwitch:General`, `bIntimacyEnabled:Intimacy`) reach the OStim event path too: with the glue off it sends no scene-talk request, does not re-assert `setAnimationBusy`, and does not call `RestoreColdLevel` - including on a scene started from OStim's own menu. `StopScene` and the stop hotkey stay ungated so stop always works.

## 5. Server state (everything the flow tests may assert on)
DB access only through `$GLOBALS['db']->fetchOne / upsertRowOnConflict / escapeLiteral / execQuery`, with reads shaped `... FROM <table> WHERE npc_name=<escapeLiteral(name)>` so an in-memory fake can serve them. **[0.4] Exactly two name-free scene reads are allowed, both on `lrg_scene_state`**: `WHERE active=1 AND updated_at > <n>` (the open-scene read [v1]) and `WHERE active=0 ORDER BY updated_at DESC LIMIT 1` (the narrator-warn read, Phase 1 `lrgWarnNarratorAfterScene()`: a request routed to the Narrator carries no NPC name, so the most recently ENDED scene of any NPC is the only way to name the partner). The flow harness serves both; any third shape is still reported and fails the run.
**[0.4.0] One more name-free shape is allowed, and only this one**: `SELECT to_regclass('<table>') AS t`, the
schema-marker probe of `lrgTableExists()` (`lib/lrg_core.php`). Why it had to exist: switching CHIM
playthrough runs `DROP SCHEMA IF EXISTS public CASCADE` (`HerikaServer/lib/playthrough_schema.php`) and
`lib/postgresql.class.php` pins `search_path` to `public` on connect, so every glue table is dropped while
the `data/.schema_v*` / `data/.dlg_schema_v*` marker FILE survives - and `lrgEnsureSchema()` /
`lrgDlgEnsureSchema()` returned early for ever after, leaving the glue permanently silent with no visible
cause. The marker is now only trusted when the table it claims to have created answers this probe. It is
asked at most once per table per request, and never once the answer is yes.
- `lrg_npc_state` [v1]: last snapshot, `payload` = the kv map; `lrgGetNpcState()` adds `_age`.
- `lrg_scene_state` [v1]: last scene payload + `active`; ladder keys inside payload `_maxtier` 0..4, `_tier_since`; [NEW] `_climaxes` (count of `ev=climax` in this thread). Reset rules [v1]: `ev=start`, closed row, or `cid` change.
- `lrg_romance` [v1]: counters (`scenes`, `refusals`, ...). **[0.3.1]** `last_affinity` int and `last_affinity_at` bigint (migration 003): the glue's OWN copy of CHIM's number. CHIM's `NpcMaster::restoreNPC()` runs on every game load and `chimRelationshipRestoreQuery` rewrites `extended_data.relationships` from a history row with no `lock_profile` filter - a row without the key DELETES the live entry. `lrg_romance` survived every one of playtest 7's four reloads, so this is the store the gate can trust.
- `lrg_memory` [NEW] (`migrations/002_lrg_memory.sql`, same shape as `lrg_turn`: `npc_name` PK, `payload` jsonb, `updated_at`; `lrgEnsureSchema` runs every `migrations/*.sql` in name order, all idempotent). Payload keys: `interest` word · `interest_score` int · `invite` = `{at, expires, place: home|room|quiet, loc, state: pending|followed}` or absent · `initiative_at` int · `last_result` = `{cmd, do, ok: bool, reason, at, told: bool}`.
- Invitation lifecycle: created when `ExtCmdLRG_Invite` passes the post-gate (`expires = at + invitation.ttl_seconds`, default 1800). Pending + all hard gates pass => mode `follow`. `state=followed` when BeginIntimacy or ChangeClothing passes the gate on a `follow` turn. Removed when expired (`lrgNow() > expires`), at `ev=end` of a scene with that NPC, or when the NPC is no longer willing. `lrg_turn` stays unused.
- `last_result`: written when a `funcret` whose text starts `command@ExtCmdLRG_` arrives (NPC from the echoed `npc=` key); an `ok=false` result is told to the NPC once on her next turn as one plain sentence, then `told=true`. **[0.3]** a result of exactly `Error: no scene is running` also closes that NPC's own scene row in the same call (G4 path 1).
- **[0.3] new `lrg_memory` keys** (all per NPC, all inside the same payload): `player_request_at` int (the player asked for something - the long lead hold) · `player_spoke_at` int (the player merely spoke - the short hold) · `proposal` `{act, at, expires, turns}` or absent (R11) · `heat` int, `heat_at` int · `last_scene_end_at` int · `sess`, `session_at` · `say_first` `{what, at}` (a self-initiated change was dropped for having no spoken line) · `pending_cmd` `{cid, code, do, at}` (the emitted-vs-confirmed watchdog) · `hold_sent_at` int (the carrier throttle) · `landing_at` int (the landing-note throttle).
- **[0.3] new `lrg_scene_state` payload keys**, reset with the scene exactly like `_maxtier`: `_visited` (ordered non-transition scene ids of this thread, capped at `lead.no_repeat_scenes`) · `_acts_done` (act FAMILIES seen, capped 24) · `_proposals` int · `_last_prop_at` int · `_prop_no` (act ids the player said no to) · `_sess`. **[0.3.1]** `_started_at` int - when the scene really began. `_tier_since` is the clock of the CURRENT tier and was being used as the duration, which is why a 2.5-minute scene was summarised as "about a minute".
- **[0.5.5] new `lrg_memory` keys** (10.21): `sent` - the last 8 glue commands of this NPC within 15 minutes, `[{cid, code (short), do, src: speech|lead|initiative|carrier|other, at}]`, written with every emitted command (it is how a funcret, which echoes cid and code but not the turn type, is judged) · `voiced_at` int (the last voiced failure; `voice.min_gap_seconds`) · `move_watch` `{code, at, cid, dist0, scene0}` (one of CHIM's movement orders the next snapshots judge) · `missed` `{what, why, at, code}` (an order of hers that visibly failed; told once on her next player turn). **`last_result.told`** is now `true` straight away for a VOICED failure (it was said when it happened) and goes back to `false` if the voiced turn is stopped in `context_pre.php`.
- **[0.3.1] new `lrg_memory` keys**: `outro` `{at, cid, scene, how, dur, ncl, pcl, acts[], scenes[], furn, leader, first_time, total}` - the outro ticket, written at `ev=end`, valid for `outro.window_seconds`, consumed after one use · `aff_gain_at` int (one affinity gain per NPC per `relationship.gain_window_seconds`) · `affinity_repaired_at` int (the one-time R1d repair marker, so it can never run twice).

## 6. Server behaviour contract (BEHAVIOUR)
### 6.1 Interest (R2) - `lrgInterest()`; the LLM only ever sees the WORD
**[0.3.1]** `score = lrgAffinity() - min_affinity + min(interest.max_bonus, renown + speech) + history`, where `history` = `lrgHistoryBonus()` = `min(leverage.history_bonus_max 30, leverage.history_bonus 15 + leverage.history_bonus_per_extra 5 * (scenes - 1))` when `lrg_romance.scenes > 0`, else 0. It is on the INTEREST side, never folded into affinity, so the two stay separable in the log and a history bonus can never be written back into CHIM's store. The rest is unchanged: `min_affinity` is the EFFECTIVE threshold (profile value, +20 under the v1 married-`secret` rule). `renown` = `interest.renown_bonus[profile.renown_sway][level]`, level 0 unknown / 1 many deeds / 2 Dragonborn (same tests as `lrgRenownWords`), defaults weak `[0,2,4]`, moderate `[0,5,10]`, strong `[0,10,20]`. `speech` = `min(10, 2 * floor(max(0, pspeech - 50) / 10))`. `max_bonus` 15. Words: `score < -25` indifferent · `< 0` curious · `< 20` interested · else drawn. `willing` = score >= 0 (this replaces the raw comparison behind gate reason `not_close_enough`; with no renown and no `pspeech` the result is identical to v1). `may_initiate` = drawn. Married-refuse, `never`, non-adult stay hard blocks regardless of score.
### 6.2 Turn modes - `$GLOBALS['LRG_TURN']['mode']`
| mode | when | glue actions offered | guidance |
|---|---|---|---|
| `silent` | not adult, child nearby, feature off, profile `never`, disabled, not a person, SHARMAT present, no fresh snapshot, or (outside a scene) a request type that is neither player speech nor an admitted `lrg_initiative` tick - all as in v1 | none | BOTH guidance strings are `''`; `x` = null. **[0.5.3 / pt10] ONE exception, and only this one**: a player-speech turn whose silence is *blindness* - `reasons === ['no_fresh_snapshot']` (`lrgEvaluateGates()` returns the moment the snapshot is missing or stale, so that reason is never mixed with another) **and** the last facts the game ever sent for this NPC positively said `adult=1` + `on=1`. Then the VOLATILE string carries one short `<this_moment>` note that names no action, permits no wording and carries no `<player_request>` directive; the static string is still `''` and `x` is still null. The recogniser runs on such a turn (so the `turn` line carries the real `intent=` and `heat` keeps counting - mode `closed` still does not warm up), but **nothing is offered and nothing can be executed**: `offered` is `[]`, all five glue actions are hidden `(mode silent)`, the post-LLM gate drops every one of them on its own precondition, and the safety net / second command / hold carrier are all `mode === 'scene'` only. An NPC the game has NEVER reported on, and one whose last facts said `adult=0` or `on=0`, still get **nothing at all** - not even recognition |
| `closed` | adult but not willing, or a non-privacy block (combat, quest scene, married-refuse, another scene running) | none | boundaries + plain reason [v1]; no invitation, no explicit wording; `x` = null. **[0.3.1 fix pass] The turn directive is the plain-kind one** (`lrgIntentPlainKind()`: "something physical", "clothes off"), never `lrgIntentWords()`'s explicit label - this is the one mode with no wording permission, and playtest 7's whole eighteen-minute complaint was mode closed |
| `public` | willing; the only failing gates are `witnesses` / `companion_present` | SuggestPrivacy | she steers toward privacy using real facts (`places`); no hard physical move here |
| `private` | willing and every gate passes; no pending invite | BeginIntimacy, ChangeClothing | [v1] consent text + R4 / R5 style |
| `follow` | as `private`, with a pending invite | BeginIntimacy, ChangeClothing | follow-through: she makes her move now (gentle-tier BeginIntimacy, or ChangeClothing, or a blunt proposition) |
| `scene` | live scene with this NPC, on a FRESH snapshot with `adult=1`, `witkid=0`, `on=1`, profile not `never` | ChangeIntimacy, ChangeClothing | scene notes [v1] + section 6.4 |
| `scene` (blocked) | a live scene with this NPC whose snapshot confirmed an adult, but one of the other rails now fails (stale snapshot, child nearby, MCM switch off, profile `never`) | ChangeIntimacy, and only the resolved `do=stop` passes the post-gate | one sentence: choose `stop` if the player asks. No scene description, no options, `x` = null, no lead tick. An NPC whose snapshot never confirmed an adult gets mode `silent` instead - total silence |
| `outro` **[0.3.1]** | an `lrg_scenetalk` request whose text is exactly `outro`, an unexpired outro ticket for this NPC, no live scene row, and the SAME rails a scene turn re-checks (fresh snapshot, `adult=1`, `witkid=0`, `on=1`, profile not `never`) | **none** - she only talks | `<after_intimacy>` (6.11) instead of `<this_moment>` / `<intimate_scene_now>`; `x` is set, so the wording permission applies; `FORCE_MAX_TOKENS` = `scene_talk.max_tokens.outro` (300). The ticket is consumed, so an outro fires at most once per scene |
| `voiced` **[0.5.5]** | a `funcret` request for a glue failure that `lrgFuncretVerdict()` decided to voice (10.21), for the NPC the result belongs to | **none** (CHIM switches every action off on a funcret turn anyway) | nothing injected: CHIM's `$PROMPTS['afterfunc']['cue'][<code>]` (set from `prompts.php`, `lrgVoicedCue()`) is the whole instruction - what did not happen and why, ONE short line in her own voice, never the game, never pretending. `FORCE_MAX_TOKENS` = `voice.max_tokens` (140) and CHIM's refusal filter bypassed (an in-character "not now, because ..." must not be swapped for the canned refusal line) |

**[0.5.5 / owner addendum 11] two more things a `silent` turn may carry**, both about SPEAKING, never about
doing: on the blind turn a recognised request gets ONE `<player_request>` - "nothing can be set up or carried
out on this turn: say so in one short line, with a plain human reason, never a word about the game or missing
facts, never ignoring it" (the plain kind only, no action named, `x` still null) - and on any player-speech
turn the `lrgMissedNote()` line when an order of hers visibly failed (movement only: `ComeCloser`, `MoveTo`,
`TravelTo`, `FollowPlayer`, the escort's late failure). Every other silence still injects nothing.
**Every recognised request is answered** (directive wording, 7.2): closed - "it is not happening right now,
for the reason above: one short line with that reason in her own words, never ignoring it" (the two closed
reasons that had no words, `already_in_scene` and `scene_running`, now have them); public + a physical
request - "not here - <the reason>: one short line with that reason, and she may name somewhere private";
the neutral / OPEN shapes - "a no comes with its reason. Never ignore it."; inside a confirmed scene DO-IT
gains "never ignore it" and still forbids refusing (addendum 3: the only "cannot" there is the GAME's, which
is either the CANT shape now or the voiced funcret turn afterwards).

**[0.5.4 / pt13] the escort is the one thing EVERY out-of-scene mode shares.** Follow / wait / release are
not intimacy, so on a player-speech turn outside a scene with this NPC they are recognised in `closed`,
`public`, `private`, `follow` **and** `silent` alike: where the full recogniser already runs (those four
modes and the blind turn) its context carries `escort => true` and `lrgIntentEscort()` is asked first;
on a silence the game KNOWS the reason for (not an adult, a child nearby, the switch off, `never`) only
the escort-only recogniser `lrgRecogniseEscort()` runs, which has no sexual reading to give. The
directive (`lrgEscortDirective()`, section 10.20) is therefore **the second and last exception to "a
silent turn injects nothing"**: a silent turn whose intent is `escort` carries exactly one `<this_moment>`
block with that directive (plus the blind note when that also applies), names no glue action and permits
no wording. Mode `scene` / `outro` never recognise it - inside a scene "wait" is a hold and "come with me"
a climax. New `$turn` key: `escort` (the `lrgEscortFacts()` array, on an escort turn).
**[0.3] new `$turn` keys**: `intent` (7.2) · `directive` · `acts` (`lrgActOptions()` result) · `propose` (R11) · `heat` int · `blunt` bool (replaces `announce`, which stays as an alias: it decides HOW blunt her line is, never WHETHER she speaks) · `hidden` (`[code => reason]`, for the log) · `say_first` bool · `scene_confirmed` bool · `progress['req_ceiling']`.
Request types: player speech (`LRG_PLAYER_SPEECH_TYPES`) -> any mode; `lrg_initiative` -> only `public` / `private` (both need `may_initiate`) and `follow`; `lrg_scenetalk` -> `scene` only (actions only on text `lead`). `lrgPrerequest()` switches `FUNCTIONS_ARE_ENABLED` on for an admitted lead or initiative tick and keeps only the actions of that mode (CHIM's catalog has no `Talk` code: nothing else survives). Other `$turn` keys [NEW]: `interest` (array), `invite` (array or null), `initiative` bool, `places` (a map `key => words for the LLM`, keys a subset of `home, room, quiet`: `home` needs `nhome`, or `cellown=npc` / `home=1` INDOORS - `ltype` in inn, phouse, house, castle, temple, store, guild, because a location can be a whole city; `room` needs `ltype=inn`, worded by `prent` / `cellown`; `quiet` always), `x` (0..2 or null), `announce` bool, `furn_options`, `offered` (the glue codes CHIM really offers this turn - the post-LLM gate accepts nothing else and the guidance names nothing else), `scene_blocked` bool. [v1] keys stay: `npc, type, cid, gate, scene, options, can_act, lead, progress, profile, ctx`.
### 6.3 Initiative admission, inside `lrgHandleGameMessage()` (before the MAIN lock)
Pass only if: enabled, no SHARMAT, fresh snapshot with `adult=1, on=1, combat=0, scene=0, ostim=0, witkid=0`, no active scene, profile not `never`, willing, and either (a) follow-through: pending invite, all gates pass, `lrgNow() - initiative_at >= 30`; or (b) own move: `may_initiate`, `lrgNow() - initiative_at >= initiative.cooldown_seconds` (300), `lrgRoll('initiative') <= initiative.chance_percent[pace]` (slow 15 / normal 30 / eager 50). Otherwise return `handled` (dropped silently, no LLM call, no lock). On pass set `initiative_at = lrgNow()` and return `pass`.
### 6.4 Style and speed (R4, R5, R7, R10)
- Plain, direct speech is the default everywhere; coy or discreet only when the profile or setting calls for it (married-secret, strict court profiles, `ltype=castle` / `temple`). Explicit wording at level `x` exists only when `$turn['x'] !== null`: `adult=1` AND willing AND mode in `public, private, follow, scene`. Level source: scene payload `x`, else snapshot `x`, else `scene_talk.explicitness`. Where others can hear (mode `public`) or the character / setting calls for discretion, the level is capped at 1 for the wording line: the "crude words are expected" permission belongs to the moments they are alone (`follow`, `scene`). R3's hard move happens in private, not in the market.
- Intimate, initiative and scene turns ask for ONE short sentence, <= `scene_talk.max_chars` (120), and every mode whose guidance asks for it (`public, private, follow, scene`, and any initiative tick) also gets the `FORCE_MAX_TOKENS` cap. All four catalog rows: `followup.enabled=false` (funcret.php:134 then terminates with no LLM call), `suppress_placeholder_infoaction=true`, `confirmation.default_policy=automatic`.
- `OPENAI_FILTER_DISABLED` (CHIM's word-scoring refusal detector) is switched off on every non-silent, non-blocked glue turn, mode `closed` included: an in-character refusal is exactly the sentence that trips the detector, and a canned replacement line would break the refusal rail. Silent and blocked turns leave it untouched.
- **R10 [0.3, owner addenda 1 + 2 - this REPLACES the v0.2 "say it / silent" machinery, which is deleted]**: EVERY scene or animation she initiates herself is preceded by one short spoken line that signifies it - the start of a scene, a position / act change she picks on a lead turn, undressing herself or the player, a move to furniture. Gentle picks included: there is no "silent" option any more and the notes carry no `[say it]` / `[silent]` markers. `blunt = lrgRoll('announce') <= scene_talk.blunt_chance[talk style]` (quiet 40, normal 70, vocal 85, crude 90, romantic 75, never 0) decides only HOW blunt that line is - name the act outright, or a few plain words - never WHETHER she speaks. A pace change, `hold` and `release` are not new scenes and need no line; anything the PLAYER asked for is done AND answered in the same reply. Enforcement is in the post-gate, on the resolved command: see 6.8. (`lrgIsSayIt()` survives only as a tier predicate for `tools/test_gates.php`; nothing in the plugin calls it. The old fact that an empty `message` with an action produces no TTS and still executes the action - connector/openrouterjson.php:1003-1045, lib/data_functions.php:6004, main.php:2831-2846 - is why a silent change had to be DROPPED rather than tolerated.)
- ChangeIntimacy `item` resolution order: stop > winddown > P-key > pace > hold / release > climax > pullout > lead > furniture label > label / id echo > free-text position search [v1]. A NEGATED stop ("don't stop") is not a stop. The lead keys are names, never `you lead` / `i lead`: those invert depending on who is speaking, so they are dropped and she simply answers. `pull out` is still resolved but is NOT offered - no installed scene defines the `pullout` auto transition, so every pick would fail loudly.
- R1: naming the position they are already in resolves to nothing at all - the index scores the current scene among the candidates and returns null when it answers at least as well, or when the winner is only another pack's version of the same tier / acts / poses.
### 6.5 Catalog v8 [0.3]
| Code | display name | `item` | `requirements.request_types_any` |
|---|---|---|---|
| `ExtCmdLRG_StartIntimacy` | BeginIntimacy | - (`target`) | speech + `lrg_initiative` |
| `ExtCmdLRG_SceneControl` | ChangeIntimacy | **[0.3] the VERBS only**: faster, slower, hold, release, climax, wind down, stop, a furniture label, `<npc name> leads` / `<player name> leads` / auto. The position vocabulary moved to `RequestAct`; plain-word positions and the `P<n>` keys still RESOLVE, so an old prompt or an old habit never breaks | speech + `lrg_scenetalk` |
| `ExtCmdLRG_RequestAct` **[0.3]** | RequestAct | exactly one act key from the current scene notes (see 6.7) | speech + `lrg_scenetalk` |
| `ExtCmdLRG_Clothing` | ChangeClothing | undress / dress + optional both / you + optional part | speech + `lrg_scenetalk` + `lrg_initiative` |
| `ExtCmdLRG_Invite` | SuggestPrivacy | home / room / quiet (only those in `places`) | speech + `lrg_initiative` |
Optional, default OFF (`invitation.lead_the_way`): after recording an invite the gate MAY replace the line with a CHIM movement action, but only one whose code is in this turn's `ENABLED_FUNCTIONS`.
**[0.3] description rails**: `BeginIntimacy` says a short spoken line comes first and an empty message is never allowed; `ChangeClothing` says what the player asks for is done and what nobody asked for is her own choice. The "it can simply happen, message left empty" licence is deleted everywhere.

### 6.6 [0.3] When `BeginIntimacy` is on the table (G3d)
The hard gates decide whether she is willing at all; this decides only whether the action exists on THIS turn, so a sexual QUESTION is answered in words instead of acted on. `START` (and an unasked-for `ChangeClothing`) is offered when ANY of:
1. the player asked for physical contact this turn (recognised kind `act` or `undress`, any confidence);
2. mode `follow` - she invited him and they are alone now: the invitation WAS the build-up;
3. `heat >= start.min_heat` AND `lrgNow() - last_scene_end_at >= start.post_scene_cooldown_seconds` AND `lrgNow() - session_at >= start.post_reload_cooldown_seconds`;
4. `$turn['initiative']` - an admitted initiative tick is itself the build-up (a tick that could not act is dropped **before** the MAIN lock instead, so it costs nothing).
`heat` counts romantic / sexual exchanges: +1 per player-speech turn in mode `public` / `private` / `follow` whose recognised kind is `act`, `undress`, `dress` or `climax`, or whose text matches `start.heat_pattern`; reset after `start.heat_window_seconds` of nothing and at `ev=end`. The increment happens BEFORE the comparison, so the second romantic exchange already opens the action. When it is hidden the notes add one sentence: a question about this is a question, and she starts nothing physical this turn.

### 6.7 [0.3] Acts (G5)
An act id is the stable handle that replaces the per-turn `P<n>` index (in playtest 6 `P1` meant three different scenes in ten minutes): `<family>` for a non-directional family (`kiss`, `hold`, `sixtynine`, `grinding`, `vaginal`, `anal`), `<family>:npc` / `<family>:you` for a directional one (`grope`, `nipples`, `handjob`, `fingering`, `oralpenis`, `oralvulva`, `titfuck`, `thighjob`, `masturbation`) - `:npc` = she is the one doing it, `:you` = the player is. Families are defined in config `acts` (globs over OStim action names, spoken words taken from the existing `scene_index.synonyms` vocabulary, display names that are vocabulary and never a line). An action name no family knows is reachable by plain words only.
Direction is derived from the CANDIDATE scene's own per-slot `intendedSex` whenever the two slots can be told apart; only when they cannot does the current thread's `npos` decide, and the turn logs how often that happened.
Post-gate: `item` -> `lrgMatchAct()` against THIS turn's act options -> else `lrgFindSceneByText()` with exactly the same filters as the offered list -> else dropped and logged. The result is always an ordinary `do=goto`.

**[0.3.1] POSITIONS ARE PART OF THE ACT ID.** An act id may carry a position after a slash: `vaginal/reversecowgirl`, `vaginal/doggy`, `anal/missionary`, and the notes offer them that way (`<key> = <label> (positions: <key>/<pos>, ...)`, at most `scene_index.max_positions_per_act` each). Position ids are derived at query time from the installed scenes' tags, actor tags, id words and furniture (`lrgScenePositions()`), so a new animation pack is covered with no config change. `lrgActBase()` strips the suffix again, so every comparison written against a bare family still holds. This is the fix for playtest 7's F3: the model sent `item="vaginal"`, the old resolver fell through to a plain-words search and landed on `OARE_SpooningFingering`.

**[0.3.1] Reachability never decides whether a request happens.** `lrgActOptions()` is built from a 3-hop `lrgSceneWalk()`, and from the default start scene only 6 of 24 act ids are inside it - which is exactly F1 ("suck my deck" -> "not reachable right now" -> nothing at all). A PLAYER request is resolved against `lrgActPool()`, every installed scene these two actors can be in, and a scene with no route is sent with `warp=1` rather than refused: the game already warps a player-requested `goto` whenever `NavigateBlockedReason()` says `unreachable`, `nowarp=1` is absent and `bAllowWarp:Intimacy` is on. Unreachability must never touch the confidence - they are two different things, and conflating them switched off the DO-IT directive, the safety net and the request ceiling in one go.
Three answers replace the old blanket "not from here":
- **not installed** (`lrgActInstalled()` false for the family): a short in-character "there is no way to do that", never a substitute. On these packs that is `sixtynine` (no action and no scene) and `anal` (five action definitions, but no installed SCENE declares `analsex`).
- **already happening**: "we're already doing that", in her own words, no command. `lrgActOptions()` still hides what is happening now, which is right for HER offers and was wrong for his request.
- **too soon**: only when the act exists but every scene of it is above the step this scene has reached.
A family marked `niche` in the act table (footjob, facesitting, spanking, rimjob) is never OFFERED by itself; it is reachable only when the player names it - and because only the player's own words ever produce a `RequestAct`, the post-gate resolves an `item` against the offered list FIRST and then against the whole act table, so a niche family and an act above the 3-hop walk are both reachable instead of "dropped - matches no act that is open right now".

**[0.3.1 fix pass] Four rules about direction and position, all in the act layer:**
1. **A bare family name is never dropped.** `item="vaginal"`, `"oralpenis"`, `"spanking"` - what the model really sends - resolves to the family, and the ROLE comes from the sentence when it names one and otherwise from the direction these two bodies have installed.
2. **An assumed role may be mirrored; a stated one may not.** When the player said who does what ("put your tongue on me", "finger me") that is the part he really said: an impossible direction keeps the WHO and changes the organ through `LRG_ACT_BODY`'s cross-family fallback. When nothing named a direction, the role was the code's guess, so the mirror of the SAME family wins and the family is never swapped - an assumed `oralvulva:npc` used to become `oralpenis:npc`, i.e. a request for cunnilingus answered with a blowjob.
3. **A named position that has no installed scene falls back to the nearest one** (`LRG_POS_NEAR`: wall -> standing, lap -> sitting / cowgirl, table -> bent over / standing, carrying -> standing, all fours <-> doggy <-> bent over), and only then to "any scene of this act".
4. **Groping carries a `butt` / `breasts` position** like a sex position, so "grab my ass" and "play with your tits" cannot both land on whichever groping scene ranks best.

### 6.8 [0.3] She speaks before she moves (R10, owner addenda 1 + 2)
Decided in the post-gate on the RESOLVED command, never on a marker the model saw. A command is "self-initiated" when no recognised player intent of this turn satisfies it. A self-initiated `StartIntimacy`, `SuggestPrivacy`, `goto`, `furniture`, `undress` or `dress` with `lrgSpokenThisTurn() === ''` is **dropped**: nothing happens silently. The server then sets `say_first`, and the next turn adds one line asking her to say it in the same reply; the drop does NOT count as "she let the moment pass" (`lead_idle` is left alone). A canned fallback line was rejected: it breaks section 0's no-example-dialogue rail and cannot be injected reliably from the post-process closure.
**What is exempt, exactly** - a pace change, `hold`, `release`, and the `goto` / `furniture` / `undress` / `dress` the PLAYER asked for. **`StartIntimacy` and `SuggestPrivacy` are NEVER exempt**, whatever the player said: a start is still a start, and "he asked" must not let a scene begin out of nowhere - that is the playtest-6 complaint this rule exists for. `lrgIntentSatisfiedBy()` therefore returns `false` for `StartIntimacy` unconditionally, and `SuggestPrivacy` (which is server-only and never reaches the `$emit` closure) runs the same check in its own branch. This sentence overrides any earlier reading of "anything the player asked for is exempt".

### 6.9 [0.3] She asks before a new sexual act (R11, kept by owner addendum 3g)
`lrgPickProposal()` offers a proposal only when: `lead.ask_first.enabled`, `can_act`, not blocked, the current act has run for `min_act_seconds` (75), `cooldown_seconds` (120) since the last one, fewer than `max_per_scene` (3), and none is already pending. The act must be `sexual`, its family not in `_acts_done`, and not in `_prop_no`.
- **It steps aside for EVERY actionable intent**, not only `act` / `yes`. The notes for a proposal say "chooses no action this turn" while the turn directive says "Do it now: choose … Do not refuse, stall, negotiate" - two contradictory orders in one prompt, on any turn where the player asked for `faster` / `undress` / `furniture` / `climax` / `stop` / `hold` / `release` / `lead`. The safety net still carried the request out, but she answered with an unrelated question, which reads as "she ignored me".
- **The cooldown and the cap count the ASKING; only a spoken reply becomes PENDING.** The notes cost a turn whether or not the model put words to them, and counting only the spoken ones let her pick the same act again on the very next turn for nothing.
- **Nothing is recorded when the same reply already carried a `do=goto`.** `ChangeIntimacy` deliberately stays offered on a proposal turn (hiding it would take the stop rail with it), so a model that both talks and picks a position would otherwise leave a pending question about a change that has already happened - and a "yes" inside the ttl would fire a SECOND one.
- **The scene row is re-stored without the game's own `ev` / `sync`.** Handing them back to `lrgStoreScene()` makes `lrgTrackProgression()` read an `ev=start` row as a brand-new scene - resetting `_tier_since`, i.e. BOTH her tier-ceiling clock and R11's own `min_act_seconds` clock - or count a second climax off an `ev=climax` row. The row stays `active`: `lrgStoreScene()` only closes on `ev === 'end'`. The same applies to the `no` branch of `lrgApplyProposalAnswer()`.

### 6.10 [0.3.1] Relationships (R1)
- **The glue loads `RelationshipManager` itself** (`lrgRelationshipReady()`, guarded by `is_file`, keeping the `class_exists` guard around every call so the offline tests' stand-in still works). It is otherwise loaded only by `ext/relationship_system/context_pre.php`, which `main.php` requires at `:2540` - AFTER the ext prerequest hook at `:1117` where `lrgPrepareTurn()` runs. The guard was false at every call site the gate uses, so **the CHIM term was permanently 0 and every `aff=` the glue ever logged was snapshot bonuses only**.
- **Missing is not zero.** Reads go through `getRelationships($npc)` + `isset($rels['Player'])`; `getRelationship()` returns the same `['aff'=>0,'type'=>'neutral']` for "no entry" and for a real 0. The player's key is always the literal `Player`.
- **Effective affinity** (`lrgAffinityInfo()`): CHIM's entry when present (and stored in `lrg_romance.last_affinity`); our last known value when CHIM has no entry (`rescue=missing`) or when CHIM reads 0 and we remember better (`rescue=zeroed`); then the unchanged vanilla terms (rank, courting, spouse). Switch: `affinity.trust_our_last_known`.
- **[0.3.1 fix pass] What counts as a scene at all** is ONE predicate, `lrgSceneCounted()`, and it gates both the `lrg_romance.scenes` counter and the gain. The counter used to be bumped on every `ev=end` of an open row: an eight-second cancelled start wrote `scenes=1`, which pays `lrgHistoryBonus()` +15 on the interest score (more than `tavern_folk`'s whole `min_affinity`) and turned the next real scene into "the second one", i.e. +4 instead of the first-scene +8. `outro.min_scene_seconds` (45) is deliberately LOWER than `relationship.min_scene_seconds` (60): a short scene still earns a proper goodbye, it just does not move the relationship.
- **The gain (R1b).** At `ev=end`, when the scene was really open, ran at least `relationship.min_scene_seconds` (60) and ended `finished` or `stopped`, the glue calls `RelationshipManager::adjustRelationship($npc, 'Player', $gain)` - **never with a `$type`**, so the existing type is preserved and CHIM's reserved romantic types are untouched. `first_scene_gain` 8 for the first scene with that NPC, `scene_gain` 4 after that, at most one per `gain_window_seconds` (6 real hours). The write happens inside a live game turn so `chimRelationshipTimelineStamp` puts it on the right game timeline. `extended_data.relationships_locked` is checked by the glue itself, because `setRelationship` / `adjustRelationship` / `parseChanges` do not check it.
- **The repair (R1d)** is config-driven (`relationship.repair`), runs once from a live turn, marks itself done in `lrg_memory.affinity_repaired_at`, writes through `setRelationship(..., null)` and logs exactly what it did. It is never run from the CLI: a write with no `gameRequest[2]` lands on the wrong timeline and the next reload rolls it back.
- **Recommended to the owner, not done by us:** turn on `NEVER_CLEAR_RELATIONSHIP_DATA` in CHIM's conf. That is a CHIM setting, not a code change, and it stops `restoreNPC` deleting relationships on load. No file of CHIM is edited by this round.

### 6.11 [0.3.1] The outro (R3)
At `ev=end` the server writes an **outro ticket** into `lrg_memory` (5) with the facts of the scene, unless the scene ran under `outro.min_scene_seconds` (45) or was `lost`. The game then fires one `lrg_scenetalk` request with the text `outro` (1.4). That turn:
- accepts the ticket **in place of** a live scene row in `prompts.php` (after `ev=end` the row is `active=0`, so the ordinary precondition can never hold and the request would get an empty cue), with every safety test unchanged;
- offers **no glue action at all**, and the directive forbids `End_Conversation` by name - CHIM offers it on the post-scene turn and it was picked five times in one day ("Lisette leaves the conversation");
- drops all four one-sentence caps: no `lrgOneSentence()`, no `<intimate_scene_now>` (the scene is over), `outro.max_chars` 420 and `scene_talk.max_tokens.outro` 300 instead of 140/200;
- renders the facts it has: what they did (`_acts_done` + `_visited` labels), how long, who came (`ncl`/`pcl` from the last push), how it ended, where, first time or not, and what CHIM knows about her own life (`$GLOBALS['CHIM_CORE_CURRENT_NPC_DATA']` - `occupation` first, then class and goals), plus one stay clause chosen from the snapshot (her own place, the player's home, a spouse or a follower, else "say where you are going and why");
- consumes the ticket, so an outro can fire at most once per scene.
The one-scene memory summary (`lrgSceneSummaryLine`) is unchanged and still written once per scene.

## 7. Function boundaries
### 7.1 SCENEINDEX provides (`lib/lrg_scene_index.php`). Existing signatures stay valid; new parameters only at the end with defaults.
```php
// [v1]
lrgScene(string $id): ?array
lrgDescribeScene(array $s): string
lrgSceneOptions(string $current, array $liveReachable, int $maxTier, int $max, ?array $sexes = null, ?string $furniture = null): array // ['P1' => ['id','label','tier','hops'], ...]
lrgPickStartScene(?array $sexes = null, ?string $furniture = null): string
lrgPickAfterglowScene(string $current, array $liveReachable, ?array $sexes = null, ?string $furniture = null): string // '' = none / already there
lrgFindSceneByText(string $text, string $current, ?array $sexes, string $furniture, int $maxTier, array $prefer = []): ?array // ['id','label','tier','score'] | ['too_soon'=>true,'tier'] | null
lrgSceneWalk(string $current, array $liveReachable, int $routeTier, int $maxHops, ?array $sexes = null, ?string $furniture = null): array
lrgPositionWords(string $current, ?array $sexes, string $furniture, int $maxTier, int $limit = 10): array
const LRG_TIERS = ['neutral'=>0,'affection'=>1,'kissing'=>2,'sensual'=>3,'sexual'=>4];
// [NEW]
lrgSceneIndex(): array            // outside the CLI it NEVER builds: serves the cached file even when stale, or the empty index; no 24 h expiry
lrgIndexStatus(): array           // ['built'=>int,'scenes'=>int,'stale'=>bool,'building'=>bool,'error'=>string]
lrgIndexWarm(bool $force = false): array  // synchronous build under data/.index_lock -> ['ok'=>bool,'scenes'=>int,'seconds'=>float,'skipped'=>bool]
lrgIndexMaybeRebuildAsync(): bool // <= 1 signature stat per 60 s, never decodes the index; stale or missing -> spawn the detached CLI warm-up (no shell_exec -> build synchronously: it is only ever called from the fast lrg_npcstate path, never inside an LLM request); true = a rebuild was started
lrgPickStart(?array $sexes, array $nearFurniture = [], string $actId = ''): array // ['scene','furn','fscene','after','act']; [0.3.1] $actId steers the start, 'after' is the w1 key
lrgFurnitureOptions(array $nearFurniture, string $current, ?array $sexes, int $maxTier, string $threadFurniture): array // [type => ['furn','label','words'=>[...],'scene','tier']], never the thread's own type, never above $maxTier
// [0.3.1] the act / position layer
lrgActParse(string $actId): array          // [family, role ('' | 'npc' | 'you'), position ('' when none)]
lrgActBase(string $actId): string          // the id without its position suffix
lrgScenePositions(array $scene): array     // position ids this scene offers (tags, actor tags, id words, furniture)
lrgActPool(?array $sexes, string $furn, int $npcSlot, int $actors = 2): array // [actId => ['any'=>[entry..], 'pos'=>[posId=>[entry..]]]], cached per request
lrgActInstalled(string $actId, ?array $sexes = null, string $furn = '', int $npcSlot = 1, int $actors = 2): bool
lrgActPositions(string $actId, ?array $sexes = null, string $furn = '', int $npcSlot = 1, int $actors = 2): array
lrgFindActScene(string $actId, ?array $sexes, string $furn, int $maxTier, array $prefer = [], string $current = '', int $npcSlot = 1, int $actors = 2, bool $standing = false): ?array // ['id','tier','routed','exact'] | null
lrgActLabel(string $actId, ...)            // understands a position suffix and says it in plain words
lrgMatchAct(string $text, array $available = [], ?array $sexes = null): string // [0.3.1] third parameter: a direction these two bodies cannot perform is never returned
lrgActFixSexes(string $actId, ?array $sexes, bool $roleAssumed = false): string // [0.3.1 fix pass] see 6.7
lrgMatchPosition(string $t, bool $sexVerb = true): string                       // [0.3.1 fix pass] LRG_POS_WEAK_PHRASES need the verb
lrgMatchActStrong(string $text, ?array $sexes = null): string  // only an exact id, a priority phrase or a named sex position
lrgFindSceneByText(..., array $prefer = [], bool $requireCore = true|false)     // [0.3.1] one optional trailing parameter
lrgActOptions(...)                          // each row gains 'positions' => [posId, ...]
```
CLI entry: `php <plugin>/lib/lrg_scene_index.php warm [--force]` (the file detects being the entry script); `tools/warm_index.php` is a thin wrapper for staged / offline runs; `deploy_server.ps1` runs `warm --force` after copying, before its final chown. Content filter: `scene_index.content_filter` = `standard` (today's taste lists) or `open` (taste lists off); **the default is `open`**, so the code hard list is the only consent filter and it must be complete. A hard list in CODE, not overridable by any config, always excludes forced / non-consensual / dubious-consent / aggressive, creature / bestiality, necro, gore and anything that reads as non-adult. The index signature covers that list and this library's own mtime, so a rail change really does rebuild on the next deploy. Every new config key needs an in-code default.
### 7.2 BEHAVIOUR provides (test-facing; FLOWTESTS uses nothing else)
```php
lrgNow(): int                               // $GLOBALS['LRG_TEST_NOW'] ?? time(); every time read of the glue goes through it
lrgRoll(string $what): int                  // 1..100; $GLOBALS['LRG_TEST_ROLL'][$what] ?? $GLOBALS['LRG_TEST_ROLL']['*'] ?? random_int(1, 100)
lrgHandleGameMessage(array $gameRequest): string // lib/lrg_actions.php. 'handled' = caller terminates (lrg_npcstate, lrg_scene, lrg_log, dropped lrg_initiative, [0.5.5] every glue funcret that stays silent); 'pass' = continue (everything else; a glue funcret is recorded, then passed only when VOICED - lrgFuncretVerdict, 10.21)
lrgPrepareTurn(): void                      // [v1] fills $GLOBALS['LRG_TURN'], prunes $GLOBALS['ENABLED_FUNCTIONS']
lrgPrerequest(): void                       // body of prerequest.php; must give the same result whether it runs before or after lrgPrepareTurn()
lrgStaticGuidance(array $turn): string      // [v1] '<personal_boundaries>' block or ''
lrgVolatileGuidance(array $turn): string    // [v1] '<this_moment>' or '<intimate_scene_now>' block or ''
lrgPostProcessActions(array $lines): array  // [v1] wire lines in -> wire lines for the game out
lrgMemGet(string $npc): array               // payload of lrg_memory, [] when none
lrgMemSet(string $npc, array $patch): void  // shallow merge; a null value removes the key
lrgInterest(string $npc, array $state, array $profile): array // ['word','score','willing','may_initiate']
// [0.3] G1 - the recogniser and the directive (lib/lrg_intent.php)
lrgRecogniseIntent(string $utterance, ?array $ctx = null, array $mem = [], bool $light = false): array
     // ['kind','conf','kv','act','scene','words','why','text','frag'] - see 7.2
lrgIntentClothingWho(string $t): string     // npc | player | both, from the PLAYER's mouth
lrgIntentStop(string $t): bool              // the recogniser's stop rail (NOT lrgResolveControl's)
lrgIntentBlocked(string $t): string         // hypothetical | quoted | negated | question | ''
lrgRequestDirective(array $turn): string    // the <player_request> block, or ''
// [0.3] G5 - the act layer (lib/lrg_scene_index.php)
lrgSceneActs(array $scene, int $npcSlot, ?array $sexes = null, ?bool &$assumed = null): array
lrgMatchAct(string $text, array $available = []): string
lrgActLabel(string $actId, string $npc = '', string $player = ''): string
lrgActOptions(string $current, array $live, int $maxTier, ?array $sexes, string $furn, int $npcSlot, array $avoid = [], int $max = 8): array
lrgSceneOptions(..., array $avoid = [])     // [0.3] one optional trailing parameter: scenes already visited
// [0.3] G2 / G3 / G4 (lib/lrg_actions.php, lib/lrg_core.php)
lrgDecorate(array $turn, string $code, array $kv, bool $selfInitiated): array // the ONE place that adds hold / wait / nowarp / warp
lrgSpokenThisTurn(): string                 // $GLOBALS['talkedSoFar'], else DEBUG_DATA['response'], else ''
lrgSafetyNet(array $turn, array $emitted): ?array
lrgCloseScenesFor(string $npc, string $why): int   // that NPC's own open row
lrgCloseAllScenes(string $why): int                // every open row, name-free (a reload)
// [0.3.1] R1 - relationships (lib/lrg_core.php)
lrgRelationshipReady(): bool                       // loads CHIM's RelationshipManager ourselves, guarded
lrgChimRelationship(string $npc): array            // ['found'=>bool,'aff'=>int,'type'=>string] - found tells missing from zero
lrgAffinityInfo(string $npc, array $state): array  // ['used','chim'(int|null),'lrg','rescue','rank','courting','spouse','type']
lrgHistoryBonus(string $npc, ?array $romance = null): int
lrgRelationshipLocked(string $npc): bool           // the owner's NPC-manager lock, which CHIM's own setters ignore
lrgAdjustAffinity(string $npc, int $delta, string $why): ?int   // through adjustRelationship, never with a $type
lrgMaybeRepairAffinity(string $npc): void          // the one-time, config-driven R1d repair
lrgRomanceSet(string $npc, array $patch): void     // named lrg_romance columns, counters untouched
// [0.3.1] R2 / R3 (lib/lrg_actions.php, lib/lrg_intent.php)
lrgIntentParts(string $utterance): array           // the pieces a compound request is made of
lrgIntentExtra(string $utterance, array $primary, ?array $ctx, array $mem): array  // the second request, or []
lrgSecondCommand(array $turn, array $emitted): ?array  // that second request as its own wire line (w2)
lrgSceneDuration(array $kv, array $prev): int      // the `dur` key, else _started_at
lrgSceneHow(array $kv, array $prev): string        // the `how` key, else finished|stopped from the climax count
lrgWriteOutroTicket(string $npc, array $kv, array $prev): void
lrgOutroTicket(string $npc, ?array $mem = null): ?array
lrgIsOutroTick(): bool
lrgOutroGuidance(array $turn): string              // the <after_intimacy> block
lrgAwardSceneAffinity(string $npc, array $kv, array $prev): void
// [0.3.1 fix pass]
lrgSceneCounted(string $npc, array $kv, array $prev): bool   // ONE test for "a scene that really happened": it gates the lrg_romance.scenes counter AND the affinity gain
lrgIntentScanOrdered(string $t, string $utterance, ?array $ctx, bool $light): ?array  // the first clause wins when it means something ELSE than the whole-utterance scan
lrgIntentPlainKind(array $intent): string                    // the non-explicit word for a turn with no wording permission (mode closed)
```
`preprocessing.php` becomes: `if (lrgHandleGameMessage($gameRequest) === 'handled') { terminate(); }`; on `lrg_npcstate` it also calls `lrgIndexMaybeRebuildAsync()`. BEHAVIOUR guards every [NEW] scene-index call with `function_exists()` and degrades to the v1 call, so both halves work during the parallel build.

**[0.3] `preprocessing.php` also has to admit player speech.** Its guard covers `lrg_*`, `funcret` AND `LRG_PLAYER_SPEECH_TYPES` + `LRG_NARRATOR_SPEECH_TYPES`, because the hold clock, the "heard" diagnostics line and the narrator WARN are written pre-lock. `lrgHandleGameMessage()` returns **`pass`** for every speech type (returning `handled` would make `terminate()` swallow the player's turn) and `handled` only for a lead tick that the hold drops. A NON-lead `lrg_scenetalk` always returns `pass` - it is her in-scene speech moment, and silencing it would silence the whole scene. `lrgIndexMaybeRebuildAsync()` stays bound to `lrg_npcstate` alone.

**[0.3] The recogniser, exactly (7.2 is binding for both the order and the names).**
`kind` is one of `stop, no, yes, winddown, speed, faster, slower, hold, release, climax, lead_npc, lead_player, furniture, dress, undress, act, none`, matched in that order - the order mirrors `lrgResolveControl` so the two can never disagree about the same words. `dress` before `undress` ("put your clothes back on" is not an undress), `undress` before `act` ("get naked" is never a position), `lead_*` before `act` ("surprise me" is an `@any` synonym and would otherwise be a random position change).
`conf` is `high` only when a payload resolved AND (a request frame matched OR the kind is an unambiguous verb); `low` when a bare noun matched and nothing framed it as a request; `none` when blocked. **The safety net fires on `high` only.**
Blockers (`hypothetical`, `quoted`, `negated`, `question`) force `none`, EXCEPT for `stop` and `no`, which are answers rather than requests. A polite-request frame beats the question blocker. A leading `tell me,` / `so,` / `hey,` is stripped before the question test.
**[0.3] Three amendments, each closing a way a mis-read word could act:**
- **`yes` is NOT blocker-exempt, and must be the whole answer.** A pending proposal (R11) is a yes/no question and the only path in v0.3 where one mis-read word makes a sex act happen. A `yes` counts only when the cleaned utterance IS the affirmation (`lrgIntentBareYes()`) and carries no blocker. Anything longer falls through to the ordinary scan and its own words decide, so "okay, hold on" is a `hold`, "sure, in a minute" is nothing, and "yes, but not now" is a `no`. A bare `yes / yeah / sure / ok / do it / go ahead / …` still answers. `no` keeps its exemption: its own words ("not now", "maybe later", "rather not") ARE the negation.
- **A stop wins unless the REMAINDER frames a request.** `LRG_INTENT_FRAME1` is `^`-anchored, so the frame is judged on what is left after the stop clause (`lrgIntentAfterStop()`, which also swallows the "enough TALKING" gerund), and on the scan's fragment only when the scan really narrowed one down. The whole utterance is deliberately NOT used: `stop` is itself a frame-1 verb, so every "stop, …" used to count as framed. Result: "that's enough, fuck me" is the request; "stop, I can't take any more of this" is still a stop.
- **A negation governs its CLAUSE, not the utterance.** When the only blocker is `negated`, the RAW utterance is split on `, ; but` (commas do not survive cleaning) and each clause free of the negation is scanned on its own: "don't stop, harder" keeps the `harder`, while a single-clause "don't slow down" / "don't get naked yet" stays blocked.
`lrgResolveControl()` is **never** handed the whole utterance - only the fragment the recogniser matched - or its free-text position search would fire on incidental words.
Order inside `lrgPrepareTurn()` (a circular dependency otherwise): (1) build a provisional ctx with `ceiling = 4` and no act list, so the recogniser never filters by tier; (2) recognise; (3) compute `ceiling` (her own pacing, drives what is OFFERED and the ladder sentence) and `req_ceiling` (4 on a high-confidence player act request, else `= ceiling`, used ONLY to resolve his request and by the safety net); (4) build options, acts and the final ctx; (5) resolve the intent against the final ctx - which is where `too_soon` and the CANT directive are decided, so the directive can never name an action the post-gate would drop.

**[0.3] The directive** is appended as the last lines INSIDE the existing `prompt_bottom` block, never registered as a second injection (that would depend on how CHIM orders two injections in the same slot). Shapes: DO-IT (`conf=high`, mode `scene`, not blocked, and the game has CONFIRMED a running scene) · MAYBE (`conf=low`) · OPEN (outside a scene, willing and alone, the action really on the table: it says this is the moment she can say yes by choosing the action, and in the same breath that nobody but her decides it - never an imperative, because her consent before a scene is her own) · the neutral restatement (everything else outside a scene) · CANT (the game cannot do it) · ALREADY · the plain-kind line in mode `closed` (6.2) · plus the one-line "she was told no" block.
**[0.3.1 fix pass] OPEN covers `undress` / `dress` too**, naming `ChangeClothing` with the value the player's own words resolve to. pt7 F4's first failure was "take off your clothes" - willing, private, `ChangeClothing` offered, `conf=high` - answered with "whether she does it is her own choice" beside a private-mode note telling the model to choose nothing if she hesitates. The model chose nothing, twice.
**[0.3.1 fix pass] The compound promise is only made where a path carries it.** In a confirmed scene that is `lrgSecondCommand()`; outside one it is the START, and only when the directive names BOTH actions (`ChangeClothing` + `BeginIntimacy`). Otherwise the second request is named without "Both happen, in that order".

**[0.3] The safety net** (`lrgSafetyNet`, inside the already-registered `action_post_process_fnct_ex` closure - no new hook, no queue table, no second LLM call). ALL must hold: enabled, no SHARMAT, `intent.safety_net`; mode `scene`, not blocked, `can_act`; **the scene is confirmed from the periodic SNAPSHOT** (`lrg_npc_state.ostim === '1'` and no unresolved "no scene is running") - never from the scene row's age, because `lrg_scene` is event-driven and de-duplicated, so in a calm stretch the row is minutes old; `conf === 'high'` and the kind is in `intent.net_kinds`; the reply carries no glue command that already satisfies the intent; the kv resolved and is not `too_soon` under `req_ceiling`.
**[0.3] "Already satisfies" compares the PAYLOAD, not just the verb.** The model echoes the player, and "my clothes" / "your clothes" invert between the two mouths, so it answers `undress you` -> `who=npc` to a request about the PLAYER's clothes: the verb matched, the net stood down, and the wrong person was undressed for a whole playtest. Three layers, in this order:
1. **correct at source** - in the `ChangeClothing` branch, a HIGH-confidence `undress` / `dress` intent of the SAME verb overwrites `who` and `part` from the player's own words (`lrgCorrectFromIntent()`). Only the payload; never the verb, never the decision to act.
2. **drop a contradiction** - for `speed` and `furniture` (resolved through `lrgResolveControl`, not correctable in place) the model's line is dropped when its value differs from the recognised one, exactly as an unasked-for `goto` already was, so the right command never travels beside the wrong one. For an `act` intent, "satisfies" still means the emitted `goto`'s scene really yields the requested act id.
3. **then the net** - `lrgIntentSatisfiedBy(..., $strict = true)` compares `who` / `part` (clothing), `speed`, and `furn`, so a mismatch no longer reads as "the reply already carries it". The loose reading stays the default for the 6.8 say-it-first test: the player DID ask for a change of clothes, so it is not a move of hers that needs announcing. At most one net line per turn, never `StartIntimacy`, never `SuggestPrivacy`, never outside a running scene. A net line is player-requested by definition, so it is decorated with `hold=` and never `wait=` / `nowarp=`, and section 6.8 never drops it. Fixed order inside the post-gate: the model's own lines, then the say-first check, then the net, then the carrier only if no glue command remains.
**[0.5.5] Never silent** (section 10.21), test-facing: `lrgFuncretVerdict(text) -> 'handled'|'pass'` (sets
`$GLOBALS['LRG_VOICED']` on a voiced failure and rewrites fields 2 / 3 of this request's funcret),
`lrgVoiceSkip(result)`, `lrgVoicedWhat/Why/Fact/Directive(record)`, `lrgVoicedCue(npc) -> ['code','cue'] | null`
(prompts.php), `lrgVoicedGate() -> '' | why` (context_pre.php terminates on a non-empty answer),
`lrgChimWillVoice(code)`, `lrgNoteSent(turn, code, kv, src, cid, extra)`, `lrgSentFind(mem, cid, code)`,
`lrgMoveWatchNote(turn, lines)` (post-LLM gate), `lrgMoveWatchCheck(npc, kv)` (`lib/lrg_core.php`, from
`lrgStoreNpcState()`), `lrgNoteEscortLate(msg, cid)` (the `lrg_log` branch), `lrgMissedNote(turn)`,
`lrgVoiceCfg(path, default)`. `lrgNotePending()` gained an optional trailing `$cid`.
**[0.5.4] The escort functions** (section 10.20), test-facing: `lrgIntentEscort(utterance) -> ['do','frag'] | null` and
`lrgRecogniseEscort(utterance) -> intent` (`lib/lrg_intent.php`, the recogniser's `ctx.escort` flag asks the first one);
`lrgEscortFacts(npc)`, `lrgEscortPlan(turn, lines)` (pure), `lrgEscortNet(turn, out, emitted)`, `lrgEscortDirective(turn, intent)`
(`lib/lrg_actions.php`); `lrgCarryFol(npc, kv)` inside `lrgStoreNpcState()` (`lib/lrg_core.php`). The escort runs FIRST in the
existing post-LLM closure (before `lrgDropUnaskedGoto` / the safety net) and only ever ADDS its own line or removes a
`FollowPlayer` that contradicts wait / release.

### 7.3 How a flow test plays one turn (no CHIM, no LLM)
Define `class RelationshipManager { static function getPlayerRelationship($npc) { return ['aff' => <int>]; } }` and a fake `$GLOBALS['db']` BEFORE requiring `lib/lrg_actions.php`; touch `LRG_DIR . '/data/.schema_v' . LRG_SCHEMA_VERSION` (skips real migrations); call `lrgIndexWarm()` once (the index is built from the real installed packs through `/mnt/f`). Then: (1) `lrgHandleGameMessage(['lrg_npcstate', ts, gamets, '<kv>'])`; (2) set `HERIKA_NAME`, `gameRequest`, `ENABLED_FUNCTIONS`, `FUNCTIONS_ARE_ENABLED` in `$GLOBALS` and call `lrgHandleGameMessage($GLOBALS['gameRequest'])`; (3) `lrgPrepareTurn(); lrgPrerequest();`; (4) assert on `LRG_TURN`, `ENABLED_FUNCTIONS` and both guidance strings; (5) play the LLM: `lrgPostProcessActions(["<npc>|command|<Code>@<item>\r\n"])`, assert on the returned wire lines and `lrgMemGet()`; (6) play the game: a `funcret` echoing the param, then `lrg_scene` messages. Time moves only through `LRG_TEST_NOW`.
Observable outcomes both sides must agree on (R9):
- stranger: `mode=closed`, interest word `indifferent` or `curious`, no glue action in `ENABLED_FUNCTIONS`; a hallucinated BeginIntimacy or SuggestPrivacy line is dropped (`[]`).
- willing with a witness: `mode=public`, only SuggestPrivacy offered; `...ExtCmdLRG_Invite@home` -> wire `[]`, `lrgMemGet()['invite']['state'] === 'pending'`; after `ttl_seconds` the invite is gone.
- same NPC, snapshot now private: `mode=follow`, BeginIntimacy + ChangeClothing offered, on player speech AND on an admitted `lrg_initiative` tick; the emitted start param has a gentle-tier `scene` (tier `kissing` or `affection`); invite `state=followed`.
- initiative tick for a not-willing, cooling-down or non-adult NPC: `lrgHandleGameMessage()` returns `handled`.
- scene: first turn ceiling = reached + 0 or + 1 per pace; a `lead` tick after the pace time offers exactly one tier above `reached`; a request two tiers up is dropped (`too_soon`).
- position by name -> `do=goto;scene=<id>`; "undress" -> `ExtCmdLRG_Clothing` with `do=undress`; "wind down" -> `do=winddown;...;linger=`; "stop" -> `do=stop` from every state, including mid wind-down and with any other words around it.
- R10 [0.3]: with `LRG_TEST_ROLL['announce']=1` the notes ask her to name the act outright, with `=100` a few plain words are enough - and BOTH ask for the line. No option carries a `[say it]` / `[silent]` marker and the notes never ask for an empty `message`; a self-initiated command whose reply had none is dropped (6.8).
- non-adult snapshot or `witkid=1`: `mode=silent`, both guidance strings `''`, `x` null, nothing offered, in every request type. Inside a running scene the same rails hold: a child arriving, the MCM switch going off, the profile being `never` or the snapshot going stale all strip the scene awareness, the wording and every action except the resolved `do=stop`; an unconfirmed adult gets total silence even there.
- no LLM-triggering request ever starts a scene-index build: only the fast `lrg_npcstate` path may decide one, and it spawns a detached process (`$GLOBALS['LRG_TEST_NO_SPAWN']` records the command instead in tests). A stale index is served meanwhile.

## 8. Config and ownership
Config keys [NEW], with in-code defaults: BEHAVIOUR - `interest.*`, `invitation.ttl_seconds` 1800, `invitation.lead_the_way` false, `initiative.enabled / cooldown_seconds / chance_percent`, `scene_talk.max_chars` 120, `scene_talk.announce_chance` (renamed `blunt_chance` in [0.3]; the old key is still read), `winddown.linger_seconds` 20, `sde_words`. SCENEINDEX - `scene_index.content_filter`, `scene_start.prefer_furniture` (`never | bed | any`, default `bed`), `scene_progression.auto_mode_min_tier` 4, furniture labels / words.

Config keys **[0.3.1]**, all with in-code defaults: `affinity.{trust_our_last_known true, log_rescue true}` · `leverage.{history_bonus 15, history_bonus_per_extra 5, history_bonus_max 30}` · `relationship.{enabled, scene_gain_enabled, scene_gain 4, first_scene_gain 8, gain_window_seconds 21600, min_scene_seconds 60, repair.{enabled, npc, affinity, why}}` · `outro.{enabled, window_seconds 60, min_scene_seconds 45, max_chars 420, sentences}` · `scene_talk.max_tokens.outro` 300 · `intent.{compound true, bare_act_max_words 4, speech_fixes {}, words.again}` · `scene_start.start_in_requested_act true` · `scene_index.{max_positions_per_act 6, position_phrases {}, position_labels {}}`.

Config keys **[0.3]**, all with in-code defaults: `log_timezone` (`America/New_York` - the WSL system zone, the owner's own clock and the one OStim.log uses; the UTC offset is always printed) · `command_confirm_seconds` 40 (it MUST stay above the game's own say-first wait budget: a parked command's `funcret` is only sent once the OStim call really happens, worst case ~20 s + `fSayFirstMaxWait` 20 s + the weapon prep, so 20 made the watchdog WARN on perfectly healthy `wait=end` starts) · `intent.{enabled, safety_net, log_utterance_chars 160, net_kinds, words}` · `lead.{hold_seconds 150, speech_hold_seconds 45, no_repeat_scenes 12, ask_first.{enabled, min_act_seconds 75, cooldown_seconds 120, max_per_scene 3, ttl_seconds 90}}` · `start.{min_heat 2, heat_window_seconds 600, post_scene_cooldown_seconds 300, post_reload_cooldown_seconds 120, heat_pattern}` · `acts` (the 15 families) · `landing_notes.{enabled, min_gap_seconds 20}` · `scene_summary.enabled` · `scene_talk.blunt_chance` (renamed from `announce_chance`, which is still read as a fallback so an existing `lrg_config.json` keeps working) · `scene_index.max_acts` 8.

## 9. [0.3] Diagnostics (G6) - what every turn must leave in the log
Timestamps are timezone-independent: `lrgLog()` formats `lrgNow()` in `log_timezone` and prints the UTC offset. CHIM sets `Europe/Madrid` at `main.php:11` and `npc_master.class.php:1370` flips the process to UTC mid-request without restoring it, which is why one code path used to stamp two different clocks. `date_default_timezone_set()` is never called by the glue.
Three fixed, greppable lines per turn, all under the cid:
- `turn npc=.. type=.. mode=.. scene=.. say="<first intent.log_utterance_chars chars>" intent=<kind>/<act|who|do> conf=.. why=.. offered=.. hidden=<Code(reason),..> lead=.. hold=<req>/<speech> heat=.. prop=.. reached=.. ceiling=.. req=.. pace=.. options=.. acts=.. blunt=.. idle=..` - `hidden=` carries the REASON per action (`Start(heat 0<2)`, `Start(post-reload 14s<120)`, `Act(not can_act)`), which is the diagnostic the last three playtests all lacked.
- `llm npc=.. action=<Code|none> item="<raw item, 80 chars>" spoke=<chars she said>`
- `net fired <Code> <kv>` or `net skipped: <the precondition that failed>`
**The prefix `turn npc=` belongs to that first line alone** - a second shape behind the same prefix is exactly what makes a log unparseable by prefix, which is what G6 exists to prevent. The two other per-turn lines that used to share it have their own prefixes:
- `gate npc=.. mode=.. [INITIATIVE] offer_start=.. reasons=.. aff=.. interest=..(..) invite=.. profile=..` - written on a speech or initiative turn OUTSIDE a scene, where `$turn['gate']` exists. **[0.3.1]** it also carries every component of `aff=`: ` chim=<int|missing> lrg=<int> hist=<int> courting=<int> rank=<int> min=<int> bonus=<int>[ rescued=missing|zeroed]`. Reconstructing why an NPC went closed took a full forensic pass through four different clocks after playtest 7; it is now one line.
**[0.3.1] two more fields on the `turn` line**, both appended at the END so every existing grep still matches: ` closed="<the plain reason, the same words the NPC is given>"` on a closed turn, and ` outro=dur:<n>s/<how>/acts:<a+b>/first:<yes|no>` on an outro turn, plus ` also=<kind>/<act>` when a compound request carried a second half. **`say=` now always carries the player's words** - in playtest 7 the whole eighteen-minute stretch the owner complained about was logged `say=""`, because the recogniser never ran in mode `closed`, and the only record of what she had actually said was CHIM's own context log.
- `blocked npc=.. in_scene (rail re-check failed: stop still works)` - written when a hard rail fails while a scene runs.

**[0.4] "everyone has a price" adds one new prefix and three additive tails.** None of them changes an
existing shape, so every 0.3.1 grep still matches:
- **new prefix** `price npc=.. for_sale=.. tier=.. wage=<tier>/<override|job|rule|default> gph=.. day_wage=.. band=..d infl=.. stance=.. days=.. floor=.. token=.. secret=.. renown=x.. repeat=x.. mult=.. pgold=.. offer=..(said|memory|quote) kind=.. decision=.. why=".."` - one line per turn in which coin is mentioned, gated by `paid_intimacy.log_prices`. `decision` is exactly one of `n_a | not_for_sale | free | needed | accepted | below_floor | unaffordable`.
- a tail on the fixed `turn` line, only when coin is in play: ` paid=floor:<n>/band:<a>-<b>d/tier:../wage:<tier>@<day wage>/infl:../stance:../quoted:../offer:<n>(said)/purse:../decision:..`
- **[pt15 / owner addenda 12e-f]** `tier` is her WEALTH tier (which band); `wage` / `gph` / `day_wage` are her WAGE tier, its gold per hour and its day (`paid_intimacy.wage_tiers` x `hours_per_day`), i.e. whose day the band's days are counted in.
- two components on the `gate` line: ` wit=<n>/<n> door=shut|open|-` - so a `reasons=witnesses` complaint can be answered from the log alone.
- `WARN the player's line went to the Narrator <n>s after a scene with <npc> ended (CHIM found no NPC under the crosshair)` - `narrator_warn_seconds` (120). It is how the owner sees the listener hold working: it should stop appearing.
- GAME lines through the existing `lrg_log` channel: `paid <n> gold to <npc>` · `NOT paid: the player no longer has <n> gold` · `DRY RUN WOULD PAY <n> gold to <npc> - no coin moved` · `listener forced to <npc> (the scene ended - ..)` · `listener released (<one of eleven reasons>)` · `controls repaired after the scene (camera state <n>, movement <0|1>, look <0|1>)` · `NOTE these 0.4 settings read OFF (a missing settings.ini default line reads as 0): ..` (one per game load; **an empty list is the acceptance test for the MCM merge**).

**[0.5.3 / pt10] one more new prefix, `silent:`.** Written once per request on a blind turn (section 6.2's one
exception), next to the `gate` line and never behind an existing prefix:
`silent: no fresh snapshot (age=<n>s|none) npc=.. - no action offered, nothing executed; she answers in words[ (the player asked for <kind>)]`.
`age=none` (the game has never reported on her) and `age=142s` (the game is late, or has just gone quiet) are
the two shapes; `reasons=no_fresh_snapshot` on the `gate` line says the same thing but is one of nine reasons
on the most crowded line the glue writes, and it does not say what it cost.
**[0.5.4 / pt13] one more new prefix, `escort`, and one additive tail.** Neither changes an existing shape:
- `escort <follow|wait|release> npc=.. scene=<0|1> fol=<live|remembered|fac|none> why=<reason> -> <the param sent>` when
  `ExtCmdLRG_Escort` goes out; `escort skipped: <the rail that held>` on a turn that asked to follow / wait / go
  back, or whose reply carried `FollowPlayer`, and did not get one; `escort: dropped FollowPlayer - the player asked
  her to wait|go back`; `escort: this game script does not know ExtCmdLRG_Escort yet (npc=..) - ...` once per
  game session on an old script. A turn that has nothing to do with following writes no escort line at all.
- a tail on the fixed `llm` line: ` chim=<code>` - the first of CHIM's OWN actions in the reply (playtest 13 logged
  `action=none` three times while CHIM was sending `FollowPlayer`, and only `output_to_plugin.log` could say so).
- the `turn` line needs nothing new: an escort turn reads `intent=escort/<follow|wait|release> conf=high`.

**[0.5.5 / owner addendum 11] three more new prefixes and one additive tail** (none changes an existing shape):
- `voiced <Code>[ (late)] do=<verb|-> npc=.. src=<speech|initiative|other|unknown> reason="<the game's technical reason (err= on 507)>" -> her funcret turn says why`
  when a failure is passed on to CHIM's funcret turn; `voiced: no (<why>) - <Code> do=.. npc=.. is told on her next turn`
  when a failure stays quiet (her own lead move, the hold carrier, a dry run, `adults only` / the feature switch, a
  second failure inside the gap, `voice.enabled` off); `voiced: dropped - <why>; told on her next turn instead` when
  `context_pre.php` stops a voiced turn (another character, or a catalog row without the follow-up).
- `watch: <Code> npc=.. - the next snapshots say whether she really moved (dist <n>)` when one of CHIM's movement
  orders is noted; then exactly one of `watch: <Code> npc=.. - she moved (...)`, `watch: <Code> npc=.. FAILED after <n>s
  (<why>; scene=<0|1> dist=<n|-> was <n|->) - told on her next player turn`, or `watch: <Code> npc=.. - no evidence
  either way within <n>s, nothing is said`.
- `missed: escort npc=.. - the game left her in her scene after all; she is told on the next player turn` (from the
  game's own `escort <npc>: she went back to her scene (...) and stays` log line).
- a tail on the fixed `turn` line: ` missed=<Code>` on the turn that tells her.

**[0.5.3] GAME line, through the existing `lrg_log` channel:**
`SNAPSHOT ABORTED in <stage name> (stage <n>): <door= is off for this session | fol= / witchim are off for this session | no optional block was running - this is the CORE path, please send the Papyrus log>`
- at most six per session, then one `SNAPSHOT: that is the sixth abort` line. Stages 1-10 are the payload as
`LRG_Profile.BuildSnapshot` builds it, 11 the send, 21-23 the entry checks / CHIM's `getAgentByName` / the
OStim scene check, 12 `NoteFollower` after the send, 13 `FollowerSweep` on load.

Plus, once each: `WARN empty transcript` (17 % of playtest 6's uploads came back blank) · `WARN player speech routed to the Narrator while a scene runs` · `lead tick dropped: <why>, <n>s left` · `WARN command not confirmed by the game: <code> do=.. cid=.. age=..s` after `command_confirm_seconds` (no retry: a retry could double-execute) · one line per scene row closed, with the reason · `heard npc=..` (pre-lock) · `hold carried -> ..` · `proposal <act> ..` (R11 bookkeeping) · `gate: ..` (one per accepted or dropped action).
| owner | files (nobody else edits them) |
|---|---|
| GAME | `game/LoreRimGlue/Source/Scripts/*.psc`, compiled `Scripts/*.pex`, `MCM/Config/LoreRimGlue/config.json` + `settings.ini`, `tools/compile.ps1` |
| BEHAVIOUR | `server/lorerim_glue/*.php` (hooks), `manifest.json`, `lib/lrg_core.php`, `lib/lrg_actions.php`, `lib/lrg_intent.php`, `migrations/*`, `config/lrg_config.default.json` EXCEPT the three sections below, `tools/test_gates.php`, `tools/test_intent.php`, **[0.3.1]** `tools/test_phrases.php`. Also writes the R5 audit of CHIM (document only, change nothing in CHIM) into its report |
| SCENEINDEX | `lib/lrg_scene_index.php`, the `scene_index`, `scene_start`, `scene_progression` sections of `config/lrg_config.default.json`, `tools/test_scene_index.php`, new `tools/warm_index.php`, `tools/deploy_server.ps1` (add the warm-up step only) |
| FLOWTESTS | new files under `tools/flows/` only |
| **[0.4] DIALOGUE** | `lib/lrg_dialogue.php`, `lib/lrg_speech.php`, `migrations/005_lrg_dialogue.sql`, `tools/test_dialogue.php`, `tools/flows/dlg_adapter.php`, `tools/flows/scenarios/d*.php`, and the anchored one-call edits of the six hook files (`globals.php`, `preprocessing.php`, `prerequest.php`, `functions.php`, `prompts.php`, `context_pre.php`) + `manifest.json`. Its config defaults live **in code** (`lrgDlgDefaults()`), overridden by a `dialogue` block in `lrg_config.json`, so it never edits another owner's config file |
| **[0.4] INDEX** | `tools/build_prompt_index.py`, `lib/lrg_prompt_index.php`, `migrations/006_lrg_prompt_index.sql`, `tools/test_prompt_index.php`, and the index build + load step of `tools/deploy_server.ps1` |
| ARCHITECT / INTEGRATOR | `PROTOCOL.md`; `README.md`, `install_mo2.ps1`, `make_esp.py`, `esp_dump.py`, `tools/stubs/`, the ESP, everything under `F:\Modlists` and inside WSL |
The one shared file, `lrg_config.default.json`, is split by SECTION: change it only with targeted edits inside your own sections (re-read before each edit, never rewrite the whole file, keep it valid JSON). Reading another owner's keys is fine; BEHAVIOUR keeps reading `scene_progression.*` and `scene_index.max_options` as today.

---

## 10. [0.4] MENULESS QUESTING - the Phase 2 wire

Design: `glue/PHASE2_DESIGN.md` rev 2 sections 2-4; work list: `glue/V04_BUILD_PLAN.md` section 2.
Same ground rules as section 0: `k=v;k=v`, a value never contains `; | @ "` or a newline, **both sides
ignore unknown keys and a missing key always means the strict / neutral reading**. Every item here is
additive; **no new `do=` verb on a Phase 1 command, no new `ev` on a Phase 1 message, no new Phase 1 error
reason, and `lrg_npcstate` gains no key at all** (`pgold`, `pspeech`, `plvl`, `gold`, `scene`, `ostim`,
`adult` are already there).

**The rails this wire exists to keep** (a review rejects any violation): the glue never calls a TIF
fragment, never `SetStage` / `SetObjectiveCompleted` / `CompleteQuest` / `SetBribed` / `SetIntimidated` /
`SetCrimeGold`, and never fakes a dialogue effect. It selects the REAL entry in the REAL hidden session and
the ENGINE does the work. A wrong match can never cause a wrong effect. The offline prompt index is
ADVISORY: a wrong index costs one confirmation too many or too few, never a wrong effect.
**[v1.0]** The session is no longer hidden: the glue selects the REAL entry in the REAL, VISIBLE session - the player
sees the list and may click it himself at any moment (10.29). Every rail above is unchanged.

### 10.1 `lrg_topics` - one engine-built list (game -> server, NEW type, fast)

Joins `external_fast_commands`; handled and `terminate()`d in `preprocessing.php`; never enters the event
log. Transport `LRG_Main.SendNpcMessage(type, payload, npcName)` = `AIAgentFunctions.logMessageForActor`.
Sent whenever a list has been read and the signature changed, after any click, and on a dump. Key order is
fixed and **`e` is LAST**:

```
v=1;sid=<sid>;gen=<n>;layer=<n>;origin=glue|engine|dump|probe;ref=<hex>;npc=<name>;st=<menu state>;
n=<FULL EntryCount()>;sent=<entries in this message>;part=1|2;cid=<cid>;want=0|1;ask=<intent words>;
crit=0|1|2;scene=0|1;fam=<0|1|2>;sub=0|1;pg=<player gold>;bamt=<GetBribeAmount>;vt=<hex>;q=<csv>;
sp=<Speechcraft>;perk=<csv>;lvl=<player level>;e=<entries>
```

* **`n` is always the full `EntryCount()`, never the cap** (R12 / D-22). The remainder goes out as a
  `part=2` message of texts only, and the server re-assembles head UNION tail **before** ranking. While
  `sent < n` the NPC may not deny that a matter exists (design 4.3 / CMP-M4).
* `e` entries are joined by `~~`, each `<pos>~<topicIndex>~<new 0|1|->~<col or ->~<text clipped 140>`; in a
  `part=2` message each entry is `<pos>~-~-~-~<text>`. The game replaces only `|` with `/` and `~` with `-`
  inside a text; the server takes everything after `;e=` raw. `e=` is capped at **1,600 raw chars** as well
  as at `iMaxEntries`.
* `pg` / `bamt` only when a text carries a gold tag. `q` = at most 6 quest EditorIDs, **case preserved**
  (`questlog.id_quest` is case sensitive).
* `sp` / `perk` / `lvl` are what make the free-conversation check of 10.5 possible without a second message.
  Absent means the server falls back to the snapshot's `pspeech` / `plvl` and logs `sp=snapshot`.
* `origin=dump` is the topic-dump parity tool; `origin=probe` is the first-playtest probe.
* **[v1.0 / script 513]** after `svck=`: `sj=<0|1>` (this session is a journal scene, 10.29 section 1), `drv=<0|1>`
  (the driver drives it; `want` is forced to 0 when it does not) and `hc=1` on a layer HIS OWN click produced (an older
  sentence of his and a park must not act on it). The first `want=1` list of a glue-opened session carries the open's
  `ask=`. `origin=probe` is no longer sent (the probe presses are retired).

### 10.2 `lrg_dlg` - session events, keyed by `ev` (game -> server, NEW type, fast)

| `ev` | keys | sent when |
|---|---|---|
| `open` | `sid, origin, ref, npc, crit, fam, vt, dist, sub, sg` (the five Speech globals, csv) | ARMING, on every session |
| `line` | `sid, gen, ref, npc, t` (**LAST**, subtitle <= 300) | a new non-blank subtitle, de-duplicated on the **pair** `(gen, string)` - shared `DNAM` responses repeat, so keying on the string alone would drop the second one and quote the wrong line |
| `result` | `sid, gen, cid, x, ref, npc, pos, i, kind, ok, why, closed, other, dP, dB, dI, gold, bribed, intim, bamt, wis, sp, dur, txt` (**LAST**) | after the settle poll of a click |
| `closed` | `sid, ref, npc, why=goodbye\|asked\|handoff\|external\|refused, pending, layer, sg, drift` | `OnMenuClose` |
| `unhide` | `sid, ref, npc, why=key\|watchdog\|critical\|show\|unverified\|read-failed\|wrong-speaker\|no-route\|no-stored-x` | every `Unhide()` |
| `facts` | `ref, npc, q, vt, dlg, sp, perk, lvl, wis, qobj, sg` (**[0.4 integrator] `sg` is now really sent here**, read live from the five globals: a free-conversation check on an NPC no session was ever opened on used to fall back to `checks.fallback_thresholds` and log `(fallback)`) | crosshair change for a CHIM agent, throttled 60 s per NPC, skipped entirely while a session is open |

`ev=facts` is the **only** place quest and speech facts travel outside a session. `qobj` = at most 6
`<questEditorID>:<stage>:<objId>.<d><c><f>` triples, case preserved.
`drift` on `ev=closed` is the net change of the five Speech globals across the session; a non-zero value is
logged as an upstream data bug (Little Lessons skips its `+10` restore whenever `SpeechVeryHard >= 100`).
**The glue patches nothing.**

**[v1.0 / script 513] (10.29).** `open` gains, after `svck=`: `scene=<0|1>` (any scene, the meaning `lrg_topics` has
always given it), `sj=<0|1>` (a scene whose OWNING quest shows an unfinished journal objective), `sq=<owning quest
EditorID or ->` and `sqj=<0|1>` (the basis, so a wrong verdict is one grep), `drv=<0|1>` (1 = the driver drives this
session; missing = 1, an older game), `hid=0` (always: nothing is hidden) and `quiet=1` while quiet mode runs (10.28).
`unhide` is no longer sent (the handler stays and logs). `result` gains `auto=1` on an auto-advance click. `calib`
gains `reset=1` (10.13). NEW **`stopped`** - `ev=stopped;sid=;ref=;npc=;why=<why>;layer=;n=`, why = lethal, combat,
scene, read-failed, unverified or close-failed - the driver has stopped driving this session (`StopDriving`): the server makes it
read-only and may tell her once, within 180 s, that the list was left to him.

### 10.3 `lrg_dlgtalk` - the module's ONE LLM-triggering message (game -> server)

**[v1.0] NEVER REQUESTED ANY MORE.** `Finish()` no longer calls `requestMessageForActor("again", "lrg_dlgtalk", npc)`:
first contact never pays a second LLM turn, because the pre-LLM open of 10.29 section 4 brings the list up BEFORE the
model runs. The server keeps the handler for an older game script and answers `handled` before the lock
(`dialogue.session.talk_again = false`). The contract below describes that older script.

`AIAgentFunctions.requestMessageForActor("again", "lrg_dlgtalk", npc)`, sent **only when no session is
open** and **never for a speaker in a scene** (D-17). It means "answer the player's last words yourself;
your business list is now known". Admitted by the server in `preprocessing.php` only when it holds a player
utterance for that NPC <= 30 s old and no session is open; `prerequest.php` switches actions back on for it.
An old server finds no cue, logs `Request cue is empty!` and falls back to `TEMPLATE_DIALOG` - an ordinary
reply. That is an acceptable degradation, not an error.

### 10.4 `ExtCmdLRG_SelectTopic` - the single new command (server -> game)

Wire line `<npc>|command|ExtCmdLRG_SelectTopic@<param>`, delivered exactly like every Phase 1 command.
LLM-facing action name **`TakeUpBusiness`**. **[0.4.0] THREE SPELLINGS MUST GATE, and the server's post-gate
tests all three, already normalised (`LRG_DLG_GATE_NAMES`, `lib/lrg_dialogue.php`).** The wire carries the
**code** name (`HerikaServer/functions/functions.php` builds `$actorName|$channel|$functionCodeName@$param`);
CHIM's action catalog normalises a display name by inserting `_` before each capital, so the strict JSON
enum (`$GLOBALS['FUNC_LIST']`, `"strict": true`) offers **`Take_Up_Business`**; the model sometimes echoes
the plain `TakeUpBusiness`. Every model-facing sentence reads the STORED display name back from the catalog
(`lrgDlgActionName()`), so the prompt and the enum can never name different strings.
Param, **fixed key order** (tests match substrings):

```
ok=1;cid=<cid>;npc=<name>;ref=<hex>;do=<pick|open|leave|show|noop|award>;sid=<sid or 0>;gen=<n or 0>;
pos=<array position or -1>;i=<topicIndex or -1>;txt=<safe prefix or empty>;
kind=<plain|service|persuade|intimidate|bribe|deceive|pay|commit|meta|silent|back>;cost=<gold or 0>;
res=<pass|fail|unknown or empty>;xp=<0|1>;stat=<Persuasions|Bribes|Intimidations or empty>;
take=<gold or 0>;ask=<intent words>;vt=<hex or 0>;x=<exec id>;z=1
```

* `txt` = the longest prefix (12-40 chars) of the entry text free of `; = @ | " ~`; **empty when the text is
  shorter than 12 chars** (then only server matching can find the entry).
* `z=1` is a throw-away LAST key so the D2 route's trailing `|` can never eat a real value.
  **[0.5.0] `z=1` is no longer necessarily the last key on the wire**: `note=` (10.16) and then `svc=`
  (10.15) may follow it, in that order, and both are ignored by a game script that does not know them.
  Everything **up to** `z=1` is still byte-for-byte this fixed order, which is what an older script parses.
* **`res=` is DIAGNOSTIC ONLY and no Papyrus code reads it** (the game's `ParamGet` calls are `ask cid cost
  do gen i kind ok pos ref sid stat take txt vt x xp`). It is kept because the log line and the offline
  tests read it back and because dropping a key from a fixed order costs more than it saves; do not write a
  game-side consumer for it - the outcome the game needs travels as `xp` / `stat` / `take`. **[v1.0] `res=` is now
  sent EMPTY on every emit** - the slot stays so the fixed order holds.
* **The game drops without executing**, the reason going into the `funcret`: `ok != 1`; `x` already in the
  executed ring of 8 (silently, no `funcret` - that is the second delivery route arriving); `ref` is not the
  live speaker or not `getAgentByName(npc).GetFormID()`; `sid` is the live session but `gen` is older than
  the live `gen`, giving one `stale` re-match through `lrg_topics want=1`; the command is older than 20 s
  when it starts executing; a session with another speaker is open.
* **`CmdSelectTopic` validates, stamps and returns inside ONE Papyrus frame and contains no `Utility.Wait*`
  of any kind** (D-20). It emits `ReportResult` only for the synchronous refusals; every other outcome is
  reported by the loop, exactly once per `x`, and `commandEndedForActor` is called exactly once per `x` in
  total - two deliveries of one `x` therefore produce exactly one `commandEndedForActor`.
* **The LLM-facing `item`** is one key `T1..T12` from **this** turn's `<business>` block, or `leave`, or -
  only when no block was shown or no key fits - the business in 3-8 plain words. A key from an older turn is
  `stale` and is dropped.

**[v1.0 / script 513] The menuless-questing v1.0 wire, both directions (10.29).** Every item is additive: both sides
ignore an unknown key, and a missing key keeps the old reading. After `z=1` the server now writes, in this order,
`note=` (10.16), `svc=` (10.15), then `adv=`, `rearm=1`, `amb=1`, `give=` - each only where the table says.

| direction | message | key | meaning | who reads it | absent |
|---|---|---|---|---|---|
| server -> game | `do=pick` | `adv=<ms>` | an auto-advance pick: the game clicks it only after the line-end grace (`auto_advance.grace_ms` 2500; `0` = a continuer, at once) | `CmdSelectTopic` -> `reqAdv`; `StepClicking` | an ordinary pick |
| server -> game | `do=pick` | `rearm=1` (after `adv=`) | the grace counts from the END of her CHIM answer, not from the engine line | `reqRearm` | the engine-line anchor |
| server -> game | `do=open` | `amb=1` | the actor stands in an ambient scene (10.24) | nobody (the log) | - |
| server -> game | `do=award` | `give=<n>` | gate B: move min(n, her septims) from her to him | `CmdAward` | no bonus |
| server -> game | every emit | `res=` | sent EMPTY; the slot keeps the fixed order | nobody | - |
| game -> server | `lrg_dlg ev=open` | `scene= sj= sq= sqj= drv= hid=0 [quiet=1]` | 10.2 | `lrgDlgOnEvent` | `sj`: `scene && !ambient`; `drv`: 1 |
| game -> server | `lrg_topics` | `sj= drv= [hc=1]` | 10.1 | the gate, `want=1` | as for `ev=open` |
| game -> server | `lrg_dlg ev=result` | `auto=1` | the click was an auto-advance | `lrgDlgOnResult` (`clicks_ok`, the log) | 0 |
| game -> server | `lrg_dlg ev=stopped` | a NEW `ev`: `sid ref npc why layer n` | the driver stopped driving this session | `lrgDlgOnEvent` -> read-only | an older server logs an unknown `ev` |
| game -> server | `lrg_dlg ev=calib` | `reset=1` | "Forget everything it learned": clear `clicks_ok` | `lrgDlgOnCalib` | - |
| game -> server | `lrg_dlg ev=calib` | `k=` = `cm rm fam st route timer x1 apd ms3 tail` | shrunk; an older ten-row `k=` still parses | `lrgDlgOnCalib` | - |
| game -> server | funcret of `do=award` | `OK: gave <n> septims` | gate B: the number really moved | the funcret handler -> `award_gave` | - |
| game -> server | `ev=unhide`, `ev=resume`, `lrg_dlgtalk` | no longer sent | - | handlers kept (log, `handled`) | - |
| game -> server | `ev=open`, `lrg_topics` | `bi`, `rw`, `rwd` | no longer sent (the controls are retired) | - | the server's own behaviour |
| server state | `*install*` row | `clicks_ok` | driven clicks verified on this install: the stage rail and the scene rail read it | `lrgDlgClicksOk` | 0 |

`calib=1` (the automatic calibration session's key on `do=open` / `do=pick` / `do=leave` and on the calibration topics
turn) is no longer sent by either side.

### 10.5 `do=award` - the free-conversation effect carrier (server -> game, NEW verb on 10.4)

Emitted by the server's own post-gate, **never chosen by the LLM**. It carries no `pos` / `i` / `sid`, and
it touches no menu, no dialogue, no engine flag and no stage. It does three things, in this order:
`take=<n>` moves exactly n gold from the player to the NPC (refused with `not enough gold` when the player
does not have it); `xp=1` grants Speech XP by replicating the engine's own call exactly -
`Game.AdvanceSkill("Speechcraft", SpeechSkillMult.GetValue() * player.GetActorValue("Speechcraft"))`, the
global read **live**, never hardcoded; `stat=` optionally increments a tracked stat, **default off** because
Beneficial Speech Checks pays out on that delta at menu close. It reports `Noted.` and calls
`commandEndedForActor` exactly once per `x`. On a game that does not know the verb it answers
`Error: unknown command` - harmless, and logged.
**[v1.0.1 / gate B] `give=<n>`** (after `z=1`, only with a positive bonus): `CmdAward` moves `min(n, the NPC's
septims)` from the NPC to the player (`npc.RemoveItem(gold, n, true, player)`) and answers `OK: gave <n> septims` with
the number that really moved; a short-fall is told next turn. Sent only while `dialogue.checks.reward.enabled` is true
(it ships false) - 10.29 section 8.

### 10.6 Delivery (design 2.4) - the server answers on BOTH routes with the SAME `x` (two exceptions: the last bullet)

* **D1 - the same HTTP reply**: `echo "<npc>|command|ExtCmdLRG_SelectTopic@<param>\r\n"` then
  `terminate()`. About 0.5 s. Precedent: `processor/comm.php:1021`'s `togglemodel` branch.
* **D2 - a `responselog` row**, insert shape copied verbatim from `lib/rolemaster_helpers.php:790-800`
  (`localts`, `sent=0`, `actor`, `text=''`, `action="command|<Code>@<param>"`, `tag=''`), echoed on the
  DLL's `request` poll. ~~whose rows sit at 5 s spacing~~ - withdrawn in v1.0, see the last bullet.
* The game de-duplicates by a ring of FOUR keyed on npc|command|param inside 5.0 s (LRG_Main.HandleCommand cmdKeys/cmdTimes), so two arrivals inside 5 s are one execution - a D1 echo that comes later than that runs twice. [pt18-quest] ExtCmdLRG_QuestEntry is therefore sent on ONE route only (D1, the escort pattern; see 10.26). ~~A D1 failure makes
  a business turn 11-14 s instead of 6-9 s~~ - withdrawn in v1.0: it was derived from the 5 s figure, and no measured
  number replaces it yet.
* If **both** routes are dead the design still works: cached keys plus a local prefix match need no reply.
* **[v1.0 / CHIM brief C1, section 4] What D2 really is, and the two single-route commands.** (1) D2 is not a 5 s
  poll. `processor/comm.php` claims EVERY queued row on EVERY `request` poll (`DataDequeue(time() + 1)`, :369-372) and
  writes the eventlog `request` row only when `time() % 5 == 0` (:374-376): the "5 s spacing" was that sampled log
  row. AIAgent.log shows each D2 copy 0.12-0.53 s after the D1 copy of the same `x` (lines 6847/6863, 7566/7585,
  7824/7831, 12128/12145). How long a D2 row inserted in the MIDDLE of a long LLM request takes to reach the DLL is
  UNPROVEN - no such sample exists; it is a first-evening check (10.29 section 13). (2) Two commands ride ONE route:
  the pre-LLM `do=open` (10.29 section 4) is **D2 only** - `lrgDlgPreOpen` (`lrg_dialogue.php` ~2417) discards the D1
  line `lrgDlgEmit` returns (on the LLM path D1 is the end of her streamed reply - too late for an open) and logs `do=open queued (D2)`, so v1.0's
  first contact depends on D2 alone; `ExtCmdLRG_QuestEntry` is **D1 only** (above, 10.26). (The code comment above
  `lrgDlgEmit`, `lrg_dialogue.php` ~5528, still says "5 s spacing"; it is a comment, and no code reads the figure.)

### 10.7 The two additive keys of "everyone has a price" (owner addendum 6)

| key | on | direction | meaning | absent |
|---|---|---|---|---|
| `pay=<n>` | `ExtCmdLRG_StartIntimacy` | server to game | at scene start move exactly `n` gold from the player into the NPC's inventory (`player.RemoveItem(gold, n, true, npc)`), charged immediately before `OThreadBuilder.Start` so an aborted start never charges; not enough gold means the whole start is refused with `not enough gold`; nothing is refunded if the player stops early | `0` = today's behaviour byte for byte |
| `amount` | the `BeginIntimacy` catalog row | LLM to server | the agreed price. **She accepts by choosing `BeginIntimacy` herself; the server never starts a scene.** This is why `LRG_ACTIONS_VERSION` goes 9 to 10 | no price was agreed |

Three more keys carry the same feature and are documented where they live: `paid=<n>` on `lrg_scene`
(1.2 - the game's confirmation of what really left the purse, and the ONLY figure the halved gain,
`lrg_romance.gold_accepted` and her memory are built from), and `paidok` / `pm` on the snapshot (1.1 - the
two MCM switches). `LRG_ACTIONS_VERSION` **10** must reach the database before the first paid turn, or every
acceptance reads `amount = 0` and every scene is free: `grep "action catalog: rows installed (v10)"`.
The `BeginIntimacy` row's `required` stays **empty** (`queueFunctionExecutionCommand()` drops a whole action
whose required parameter came back empty, which would kill every ordinary free scene) and its
`parameter_template` `{{parameters.amount}}` is the only way the figure reaches the wire - a leftover v9 row
puts the target's NAME there and degrades to a free scene instead of being misread as a figure.

**[0.4.0 - corrected] The price band is `lrgPriceFor()` (`lib/lrg_core.php`), and it is the ONLY
implementation.** It is the one the live turn calls, the one that honours the MCM `paidok` / `pm` keys, and
the one whose config block is `paid_intimacy` in `config/lrg_config.default.json`. A second, never-called
`lrgDlgPrice()` in `lib/lrg_speech.php` (documented here until 0.4.0, exercised only by flow `d41`) read a
different config namespace and applied money influence in the OPPOSITE direction; it has been deleted, and
`d41` now measures `lrgPriceFor()`. The wage anchor `lrgDlgWage()` / `lrgDlgPriceRound()` survives in
`lib/lrg_speech.php` for a free-conversation bribe's price and reads the live config.
**[pt15 / owner addenda 12e-f]** The single `gold_per_day_of_wage` (35) is gone: a day is HER day,
`lrgWageFor()` (`lib/lrg_core.php`) = her wage tier's gold per hour (`paid_intimacy.wage_tiers`: common 5,
performer 8, trade 12.5, fighter 16.5, craft_magic 25, court 62.5) x `hours_per_day` (8). The tier is, first
hit wins, `npc_overrides.<name>.wage_tier`, `paid_intimacy.wage_tier_by_job` (her real trade inside a broad
rule, limited to the rule ids each row names), the status rule's own `wage_tier`, `default_wage_tier`.
`lrgDlgWage($profile, $npc, $state)` returns the same day, so a bribe and a price never disagree;
`price_multiplier` / MCM `pm` still rescale the whole economy at once.
It is a pure function from her day wage, the NPC's wealth tier and
profile band, her `gold_sway` money influence, her interest word, `married_rule`, renown and a standing
arrangement, to `{for_sale, free, gold (her FLOOR), token, band[] in days, days, tier, infl, stance, ...}`.
**The LLM is told her stance in words plus ONE number and names the figure itself; the server never speaks
and never moves gold.** Adults only, the consent gates, the kill switch and the no-fetish rail are
untouched: a price never overrides a `never` profile or a refusal she gives for other reasons.

### 10.8 Server state and the index (what the flow tests may assert on)

`lrg_dialogue(npc_name PK, payload jsonb, updated_at)` - `migrations/005_lrg_dialogue.sql`. Every read is
shaped `... FROM lrg_dialogue WHERE npc_name=<escapeLiteral(name)>` so the in-memory fake can serve it.
Payload keys: `ref`, `vt`, `q[]`, `facts{pg,bamt,sp,lvl,wis,perk[],sg{}}`,
`root{sid,gen,at,loc,qsig,entries[]}`,
`session{sid,gen,layer,origin,crit,scene,state:open|pending|lost|closed,kind:root|closed,entries[],path[],cont,pg,sp}`,
`offer{turn_cid,keys{T1:...},at}`, `parked{norm,text,kind,at,expires,req}`,
`attempts{<norm>:{kind,result,at,sp,pg,wis,bamt}}`, `last_exec`, `last_result`, `utter{text,at,type}`,
`lines[]`, `losses`, `checks{...}` (10.5's per-NPC memory), `qobj{}`, `price{ask,paid,at,times}`.
The entry record and the `class` vocabulary `plain | service | check | pay | commit | meta | silent | back |
hidden` are design 3.3.

`lrg_prompt` / `lrg_prompt_layer` - `migrations/006_lrg_prompt_index.sql`, filled offline by
`tools/build_prompt_index.py` (read-only, pure python, inside WSL) and loaded with
`php lib/lrg_prompt_index.php load`. API: `lrgPromptLookup(texts, npcFacts)` (order: layer fingerprint,
subset-tolerant, then exact norm, then token pattern, then none), `lrgPromptLayerKind(records)`,
`lrgPromptIndexStatus()`, `lrgPromptKinds()` (the kinds census), `lrgPromptNorm()`, `lrgPromptCost()`.
Test seam `$GLOBALS['LRG_TEST_INDEX']`: when it is SET, the index touches no database at all.

**[v1.0 build fix] The fail-safe merge is scoped to the line's own topic.** `lrgPromptEscalate(best, recs, ownTopics)`
raises every risk claim (crit, scripted, goodbye, walk-away, say-once, cost, compound, a missing kind / twat) to the
strictest value among the candidates that share the prompt - over the rows of the line's OWN topics plus every lethal
row (crit 2) of the load order, whenever the lookup can tell which topics those are: on a known layer, the topics its
parent INFO links (`lrgPromptLinksOf`: one primary-key read of `lrg_prompt.links` per layer, cached for the request;
`lrg_prompt_layer.parent_info` is now read with the fingerprint, and the layer's row for each norm is one of those
topics); on an exact-norm hit, the topics of the candidates whose quest is in her `q=`. With neither, the merge stays
load-order-wide, as before; `ambiguous` still counts every candidate. `lrgPromptLayerKind` counts only records whose
root / closed standing is certain: an exact-norm pick among candidates that disagree on `toplevel`, with nothing to
scope it, is marked `standing=unsure` and does not vote while any other line of the list is known (Farengar's ROOT
list read closed through Andrealphus's shared "Do you need any help in the magical arts?").

**`lrgPromptNorm()` is the one key rule and it is implemented TWICE** - in `lib/lrg_prompt_index.php` and in
`tools/build_prompt_index.py`. Change both: lower-case, drop up to two leading `(tag)`/`[tag]`, drop up to
two trailing tags (a price tag included), `<Token>` becomes `<t>`, digits become `#`, keep `a-z0-9#<>'` and
space, collapse whitespace. Dropping the trailing tag is what makes the plugin's `(<BribeCost> gold)` and
the menu's `(137 gold)` the same key.

### 10.9 Function boundaries [0.4] - DIALOGUE provides (FLOWTESTS uses nothing else)

`lrgDlgHandleGameMessage(array $gameRequest): string` (`handled|pass`; echoes the D1 line itself),
`lrgDlgPrepareTurn()`, `lrgDlgPrerequest()`, `lrgDlgJsonTemplate()`, `lrgDlgStaticGuidance(array $t)`,
`lrgDlgVolatileGuidance(array $t)`, `lrgDlgTransformer(string $s)`,
`lrgDlgPostProcessActions(array $lines)`, `lrgDlgFilterChat(array &$gameRequest)`,
`lrgDlgGet(string $npc)` / `lrgDlgSet(string $npc, array $patch)`,
`lrgDlgQueue(string $npc, string $param)` (the D2 insert, isolated so tests can capture it through
`$GLOBALS['LRG_DLG_TEST_QUEUE']`), `lrgDlgEnsureActions()`, `lrgDlgCheck(array $turn, array $intent)`,
`lrgDlgQuestBlock(array $turn)`, **[0.4.0]** `lrgDlgMcm(string $key, $default, string $npc = '')` (one MCM
knob, 10.12), `lrgDlgActionName()` (the stored display name, 10.4), `lrgDlgKindFromTag(string $text)` (the
visible `(Persuade)` tag as a second source of truth), `lrgDlgWage()` / `lrgDlgPriceRound()` (the wage
anchor). **The price itself is `lrgPriceFor()` in `lib/lrg_core.php`, not a DIALOGUE function** - the Phase 2
twin `lrgDlgPrice()` is deleted (10.7).
Every time read goes through `lrgNow()`; every DB access through the `$GLOBALS['db']` wrappers.
Further seams: `$GLOBALS['LRG_DLG_TEST_QUESTLOG']` (CHIM's journal rows) and `$GLOBALS['LRG_DLG_TEST_QSIG']`.

### 10.10 Prompt blocks [0.4]

`<real_business>` (static, `character_bottom`, at most 70 words, injected only when a list is actually
known), `<business for="..." state="...">` (volatile, `prompt_bottom`), `<shared_business>` (the quest
tree, at most 3 clauses, current objective ONLY, never a future stage, nothing from
`skyrim_quest_definitions`), `<what_just_happened>` (ground truth, once, stated as fact; an `unknown`
outcome is told as "you answered: ..." with **no verdict attached**). Entries are shown **VERBATIM** - only
the leading tag is replaced by a label taken from the **index kind**, because the text tag is missing on 45
persuade INFOs and wrong on about 20. **No thresholds, no skill numbers, no response previews, no stage
numbers, ever.**

### 10.11 Coexistence with Phase 1 [0.4]

Two orthogonal turn records: Phase 1's `$GLOBALS['LRG_TURN']` is untouched and Phase 2 adds
`$GLOBALS['LRG_DLG_TURN']`. **Phase 2's gates are computed from Phase 2's OWN state and never from
`LRG_TURN`**: under SHARMAT `functions.php` takes the `if` branch, `lrgPrepareTurn()` never runs and
`LRG_TURN` is never built, so a gate in Phase 1's terms would evaluate to "not blocked" exactly where
`ext/aiagent_nsfw` is running its own scenes. `scene=1` or `ostim=1` (from `lrg_npc_state` or `ev=facts`)
means no `TakeUpBusiness` and no block. `session.state` in `{open, pending}` means `StartIntimacy`,
`Clothing`, `RequestAct` and `Invite` are hidden for the turn, and `EndConversation` with them. The two
post-gates are registered in order - **Phase 1 first**, which passes `ExtCmdLRG_SelectTopic` through
untouched (integrator edit D-12), then Phase 2 - and each exactly once per filter list.
### 10.12 [0.4.0] The MCM knobs that are SERVER behaviour - additive keys on `lrg_dlg ev=facts`

**The defect this fixes:** seven MCM controls changed nothing at all, because they had no wire carrier. The
game read them (or did not) and the server went on using its own config default. `bFreeChecks` was the worst
of them: honoured game-side only, so with the toggle OFF the server still ran the check, still injected
`<what_just_happened>` stating the outcome as fact and still emitted `do=award`, which the game then
discarded - the owner got the narration of a check they had switched off, with no XP and no gold.
`iCheckBias` and the free-checks master toggle were asked for by name in OWNER_ADDENDA item 5c.

The keys travel on **two** carriers, and which one is not a free choice:

* **`bi` / `rw` / `rwd` / `io`** are DIALOGUE knobs and ride `lrg_dlg ev=open` and `lrg_topics`
  (`LRG_Dialogue.DlgCfgWire()`), both of which go through the same parser. They are only ever needed while
  a dialogue session exists, so that is early enough.
* **`chk` / `bias` / `ql`** are CHECK and QUEST knobs and ride the ordinary **`lrg_npcstate` snapshot**
  (`LRG_Profile.psc`). They must: a FREE-conversation check happens in ordinary CHIM talk, where no
  dialogue message is ever sent, so a dialogue-only carrier would leave `bFreeChecks` dead exactly where it
  matters. Phase 1 already stores the whole snapshot kv, so the server reads them back from there
  (`lrgDlgMcmFromSnapshot()`), and **no new snapshot handling and no `v=` bump was needed**.

**All optional, all additive, all clamped**: an absent or malformed key keeps the server's own config
default, so script **310** and a script **400** that does not send them behave exactly as before.

| key | value | MCM | server effect when present |
|---|---|---|---|
| `chk` | exactly two digits `<bFreeChecks><bCheckHostility>`, e.g. `10` | `bFreeChecks:Checks`, `bCheckHostility:Checks` | first digit 0 = `lrgDlgCheck()` returns null before anything is computed (no check, no directive, no `do=award`); second digit = whether a failed threat may set `hostility` |
| `bias` | `-2`..`2` | `iCheckBias:Checks` | added to the difficulty band, exactly as `checks.bias` was |
| `ql` | `0`..`6` | `iQuestLines:Quests` | the most objectives in one `<shared_business>`; **`0` = no block at all**, which is how a game half switches quest awareness off without a second key |
| `io` | `0`/`1` | `bIntentOpen:Dialogue` | may the glue open a conversation just to read an unknown list (`do=open`) |
| `bi` | `0` auto / `1` by voice / `2` show me | `iBranchInput:Dialogue` | `2` = a **closed layer** (which IS the branching choice) is handed back with `do=show` and never clicked, in the post-gate and in the `want=1` fast path alike |
| `rw` | `0`/`1` | `bRewalk:Dialogue` | `0` = never walk a second layer by voice, whatever `rwd` says |
| `rwd` | `0`..`4` | `iRewalkDepth:Dialogue` | how deep the same-session continuation may go **when `rw` is 1**. Sending `rw` without `rwd` falls back to the config `match.cont_depth`. (Before the 0.4.0 release pass the server read no `rwd` at all and used the boolean `rw` as the depth, so `iRewalkDepth` was the seventh dead control.) |

Read them back with `lrgDlgMcm(key, default, npc)`: newest value seen this request wins (MCM is global, the
facts row is per NPC), then that NPC's stored facts, then **that NPC's last snapshot** (the `chk` / `bias` /
`ql` tier above), then the config default. The `dlg turn` log line gains
`mcm=<k>:<v>,...` **only when the game really sent them** - its absence is itself the answer to "is my MCM
reaching the server at all?".

#### 10.12a [0.5.0 fix pass] The same scar, seven more controls - `qig`, `qx`, `svx`, `tg`, `ml`

`LRG_Profile.psc` was already writing `qig` / `qx` / `svx` / `tg` onto the snapshot and **no `.php` file read
any of them**, so `iQuestInitiativeGap`, `bQuestSummary`, `bQuestNext`, `bServiceShortcut`,
`bCarriageByName`, `bNamePrices` and `bTruthGate` changed nothing and the server silently kept its own
config defaults. Worst of them, **`bTruthGate` was wired to `lf`**: switching `bLockedFacts` off therefore
disabled the truth gate too, and `bTruthGate` itself did nothing - the reverse of what both help texts say.
`ml` is new in this pass and is the one carrier the driver's own state needs (see below).

Two of them are **shape bytes**, parsed the way `chk` is, because the numeric branch only accepts one or two
digits and would have thrown `svx="111"` away outright.

| key | value | MCM | server effect when present |
|---|---|---|---|
| `qig` | `60`..`1800`, 2-4 digits | `iQuestInitiativeGap:Quests` | the gap before she may raise something herself. One reader, `lrgDlgInitiativeGap()`, so the decision and the log line can never print two different numbers |
| `qx` | exactly two digits `<bQuestSummary><bQuestNext>` | `bQuestSummary:Quests`, `bQuestNext:Quests` | first digit 0 = no `<what_you_can_ask>` block at all; second 0 = no `<your_quests>` block |
| `svx` | exactly three digits `<bServiceShortcut><bCarriageByName><bNamePrices>` | `bServiceShortcut:Services`, `bCarriageByName:Services`, `bNamePrices:Services` | byte 0 = may CHIM's own shortcut stand in when no list can be read; byte 1 = may naming a slot EXECUTE it (0 degrades a `pick` to **ask**, never back to the similarity matcher - R12); byte 2 = may she say a price out loud at all (and `services.kinds.<kind>.never_quote_price` still overrides it for `train`) |
| `tg` | `0`/`1` | `bTruthGate:Truth` | 0 = an unconfirmed number is still detected and still logged, it just no longer drops the ACTION. **`lf` no longer stands in for this.** `lf` gates only whether `<locked_facts>` is built at all |
| `ml` | `0`/`1` = `bMenuless && !bDlgDryRun` | `bMenuless:Dialogue` + `bDlgDryRun:Dialogue` | **0 = the server leaves CHIM's own `RentRoom` / `HireCarriage` / `HireFerry` / `Training` / `OpenInventory` (and `ForgiveCrime`, and the gold pair on a priced list) ON THE TABLE.** The "the real entry wins" rule only makes sense while the driver can really open a hidden session; with `bMenuless = 0` the game answers `CmdSelectTopic` with "the feature is switched off", so hiding the shortcut left the player with neither |

**`ml` defaults to 1 when absent**, which is what keeps this additive in both directions: a script **401**
that does not send it produces exactly 0.4.1's behaviour, and the hide policy is never weakened by an older
game half. `d56` asserts all three cases (`ml=0`, `ml=1`, absent).

The release gate for this whole class is `tools/test_mcm_wiring.php`, and it was itself defective: it
evaluated "is the id named in a `.psc`" **before** the wire test, so `bTruthGate` matched on its own
`SettingBool` call and the wire half was never checked; and the substring search ran over the `.psc` text
**comments included**, so a bare mention in a `;` comment satisfied it. Both are fixed - `SERVER_KNOBS` is
now authoritative and evaluated first, and Papyrus `;` line and `;/ ... /;` block comments are stripped
(honouring double-quoted strings, since the wire literals `";qig="` start with a semicolon themselves).

**[v1.0] Retired carriers (10.29, spec section 4).** `bi` / `rw` / `rwd` (`iBranchInput`, `bRewalk`, `iRewalkDepth`)
are no longer sent and nothing reads them: a closed layer is driven by the release rules of 10.29 section 5, never
handed back by a setting, and the re-walk depth went with `match.cont_depth`. `qi` (W7, 10.16) and `qig` are no longer
written (`bQuestInitiative` / `iQuestInitiativeGap` are retired with `<she_may_raise>` and `quests.initiative.*`). `io`,
`chk`, `bias`, `ql`, `qx`, `svx`, `tg` and `ml` are unchanged. `tools/test_mcm_wiring.php` section 10 asserts that
every retired id is gone from config.json, settings.ini, its own maps and every comment-stripped `.psc`.

---

## 10.13 [0.5.0] THE CALIBRATION WIRE (E1) - `ev=calib`, `cal=` and `ev=resume`

**[v1.0 / script 513] WHAT IS CURRENT (10.29 section 3); the rest of this section is the 0.5 history.** Four PASSIVE
rows gate the driver - `cm` (counting), `rm` (reading), `fam` (layout), `st` (Smart Talk settings) - learned on the
first menu of ANY kind (a conversation opened with E counts). `timer`, `x1`, `apd`, `ms3`, `tail` (and `col row actms`
in the log summary) are measurements, never gates; the rows `hide guard rb reopen items plat lp` and the store keys
`runs armed auto gopen` are gone. W1's `k=` is exactly `cm rm fam st route timer x1 apd ms3 tail` (an older ten-row
`k=` still parses) and `runs=` is no longer sent. **Route by doing:** no press and no button - the first driven click
whose RESPONDING signal proves the route writes `route` with `src=live` (log `CALIB set route src=live route=<r> ...`);
two failed clicks, or no signal within 9 s on route A, flip it for the next attempt (never a route forced by
`iClickRoute`, never one already proven live). **Forget clears `clicks_ok`:** `CalForget` wipes the store, the UI copy
and the install file, then sends `ev=calib;v=1;ref=00000000;npc=<player>;src=forget;gate=0;reset=1;miss=<...>;k=<...>`;
the server clears `clicks_ok` on `*install*` (log `calib npc=<player> reset=1 - the calibration was forgotten in game:
clicks_ok <n> -> 0 ...`), so the stage rail and the scene rail close again until the next real click. **The stage
rail** (`clicks_ok == 0`: only an indexed plain line is clicked) is the server's half of the same probation. **No
automatic session:** the `[pt18 / E1f]` automatic calibration session at the end of this section, its `calib=1` key,
`auto:` / `gopen:` and the emergency-key abort are RETIRED. **W2** `cal=` on `ev=open` is unchanged; the facts line's
`cal=` (10.24) now counts 0-4 rows. **W6 `ev=resume` is RETIRED:** nothing is ever handed back, so nothing resumes; the
handler stays and logs `resume ... - ignored (v1.0 never resumes a session; an older game script sent it)`. So is
`ev=unhide` (10.16 W5): nothing is hidden.

The driver calibrates itself from ordinary play instead of asking the owner for an evening of 21 probe
presses. Those answers are a property of the **install** (its SWF family, its frame rate, its Smart Talk
settings), not of an NPC, and the server stores them that way.

| # | message | key / value | when | old version |
|---|---|---|---|---|
| **W1** | `lrg_dlg` | **`ev=calib`** (new `ev`) - `ev=calib;v=1;ref=<hex8>;npc=<name>;src=<passive\|active\|manual>;gate=<0\|1>;runs=<n>;miss=<csv\|->;k=<raw>` | end of a session in which a key changed; throttled to one per 30 s; plus once from `Maintenance()` | a 0.4 server logs `unknown ev=calib - ignored (additive wire: this is not an error)` and returns `handled`. Nothing breaks and nothing is charged |
| **W2** | `lrg_dlg ev=open` | **`cal=rm<n>cm<n>rt<n>g<0\|1>`**, e.g. `cal=rm3cm1rt2g1` | every `ev=open` | unknown key, ignored |
| **W6** | `lrg_dlg` | **`ev=resume`** - `ev=resume;sid=;ref=;npc=;layer=<int>;after=<ms>` | the driver went back to driving a session it had handed back, after the player's own click | as W1 |

`k=` is the **LAST** key and is taken **raw**: it is `name:int` pairs separated by `,`, never `;`
(`rm:3,cm:1,lp:1,fam:1,route:2,apd:750,timer:1,hide:1,guard:1,park:1,rb:1,st:1,x1:1,reopen:1,items:8,plat:1`).
The parser is `lrgDlgSplitLast($raw, 'k')`, the same one `ev=line` uses for `t=` and `ev=result` for `txt=`.

**Where it lands.** `lrgDlgPut('*install*', ['calib' => ...])` - a reserved NPC-name row, because the
answer is a property of the install. The per-NPC row keeps a copy of **`gate`, `x1`, `rm`, `cm`, `rt`**
only, because those are the ones a PROMPT decision reads (E3's foreseen hand-back consults `cal.x1`).
A `cal=` that is not exactly `<letters><digits>` pairs is dropped **whole**: a half-read calibration is
worse than none.

**`ev=calib` and `ev=resume` are never LLM-bearing.** `lrg_dlg` is in `external_fast_commands`, so the
request is answered inside `preprocessing.php` before the MAIN semaphore and costs 2-7 ms.

---

**[RETIRED in v1.0 - history only; nothing in this paragraph is sent or read any more.]** [pt18 / E1f] The AUTOMATIC calibration session. calib=1 is an additive key AFTER z=1 on do=open, do=pick and do=leave (a calibration session), and on the calibration topics turn (lrg_topics want=1 carries ;calib=1); a pre-pt18 server or a 509 game script ignores every one and the auto-run simply never starts (proven by flow d50). The server names a candidate on an idle innkeeper/shopkeeper turn (lrgDlgCalibCandidate) and emits do=open;calib=1; the game re-vets it (LRG_DlgProbe.CalAutoWanted) and opens her real menu as a dry-run session; the server answers the calibration topics turn with the SAFEST plain entry (lrgDlgCalibPick -> do=pick;calib=1;pos, or do=leave;calib=1 when pos=-1), never a matcher hit and never a driver guess; the game clicks it only once one glue open has proven itself on this install (gopen>0). ev=calib's k= gains two keys: auto:<n> (automatic sessions run) and gopen:<n> (glue opens that read a list). The calibration is now a property of the INSTALL on the GAME side too: it is mirrored to Data/SKSE/Plugins/LoreRimGlue_calibration.ini (MiscUtil) and seeded into every new save; CalForget wipes it. A dedicated MCM key on LRG_DlgUI (LRG.du.cabt) carries the emergency-key abort into the probe's click body.

## 10.14 [0.5.0] THE CONVERSATION HOLD'S MOVEMENT HALF (E4b) - `do=release`, and why it exists

`bConvHold` holds an NPC on the spot with `SetDontMove(true)` while a CHIM conversation with her is live.
CHIM may then be told `ComeCloser` / `FollowPlayer` / `TravelTo` - and the game-side release,
`NoteChimAction()`, is **dormant for CHIM's own catalog actions**, because the DLL raises
`CHIM_CommandReceived` only on the `ExtCmd` branch. She cannot move, the command never completes, and the
player sees her refuse to follow. This is the `ComeCloser` / `FollowPlayer` risk of
`research/pt8-walkaway.md`.

**Half 1 - the actions are simply not offered.** While the snapshot says `hold=1` and is no older than
`dialogue.hold_max_age_seconds` (45), and the player's own words did **not** ask her to move,
`lrgDlgHoldMovementPolicy()` removes `dialogue.hold_move_actions` from the turn. That list is deliberately
the **same list, same spellings, as `LRG_Main.CONV_MOVE_ACTIONS`**, and `d52_holdmove` parses the `.psc`
and asserts the two are equal - a silent divergence is exactly how this class of bug comes back.

**Half 2 - when the player DID ask, the hold is released first.**

| # | addition | param | when | old version |
|---|---|---|---|---|
| **W8** | new verb **`do=release`** | the key order of 10.4 byte for byte, with `do=release;kind=hold;sid=0;gen=0;pos=-1;i=-1;txt=` | **prepended** to the batch, before CHIM's own movement action, when this turn carries one of `hold_move_actions` and the hold is live | game **401** falls through `CmdSelectTopic`'s final `else`: one `funcret` `Error: unknown command` and a corner note. Harmless, and the server does not depend on it - half 1 alone is correct and safe. `dialogue.hold_release_on_move = false` disables the verb entirely |

**Order in code, and it is binding (V05 section 21.5, risk R21).**
`lrgDlgHideEndConversationOnHold()` -> service hiding (inside `lrgDlgApplyOffer`) ->
**`lrgDlgHoldMovementPolicy()` LAST**, at brace depth 0 in `functions.php`. A service toggle can therefore
never put a movement action back on the table while she is held. The log says which rule hid what:
`dlg hold npc=... movement HireCarriage hidden` is the hold; `dlg svc ... src=shortcut why=...` is the
service rule.

---

## 10.15 [0.5.0] SERVICE DIALOGUE (E6) - `svc=`, `svck=`, and the rule that stops a wrong teleport

**CHIM already implements all five families the owner named** - `RentRoom`, `HireCarriage`, `HireFerry`,
`Training`, `OpenInventory`, `GiveGoldTo`, `Brawl`, `AddBounty`, `PayBounty`, `ArrestPlayer`,
`ForgiveCrime`, each with real server-side requirements. On **this** load order each one is wrong in a
checkable way: `HireCarriage`'s destination enum is 19 vanilla places and 8 vanilla drivers while the load
order runs **CFTO.esp** with destinations CHIM has never heard of (`Solitude Lighthouse.`); the shortcuts
charge a flat 10 / 20 / 50 gold while **774 index rows** carry a real `<Global=...>` price token across 142
distinct globals (`KmodFerryCost`, `RoomCost`, `BYOHHPCostCarriage`, `DLC1FerryCostLarge`); and
`ForgiveCrime` clears a bounty with no check at all while the real dialogue makes you earn it.

**The rule (decision D4, already half-shipped):** when a session's list contains the real entry, the glue
executes the **real entry** and hides CHIM's shortcut; CHIM's shortcut stays only when **no session is
possible**. `hide_chim` / `hide_gold` / `hide_always` are unchanged; `dialogue.services.kinds.*.hide` is
asserted by `d56_services` to be a **subset** of them, so the two can never become a second source of truth.

| # | message | key / value | when | old version |
|---|---|---|---|---|
| **W12** | `ExtCmdLRG_SelectTopic` | **`svc=<inn\|carriage\|ferry\|train\|barter\|crime\|->`**, appended **after `z=1`** | every emitted command that has a service kind | game 401 parses `k=v` pairs and ignores an unknown trailing key. **Diagnostic and corner-note wording only; nothing branches on it, on either side** |
| **W13** | `lrg_dlg ev=open` | **`svck=<kind\|->`** - the DRIVER's own cheap "is this a price list" verdict, from entries it already holds | every `ev=open` on a closed layer | unknown key, ignored. The server re-derives it and logs `svck drift` on a mismatch |

**`z=1` is no longer necessarily the last key.** `note=` (10.16) and then `svc=` may follow it, in that
order. Everything **up to** `z=1` is still byte-for-byte the fixed order of 10.4, which is what an older
game script parses. `fxDlgOrderOk()` in the flow harness asserts exactly that shape.

### The one genuinely hard problem, and the rule that solves it

A destination layer does not look like a sentence: CFTO's entries are literally `Morthal. (35 gold)` and
`Solitude Lighthouse. (35 gold)`, and **4,620 of the 37,561 index rows (12.3 %) normalise to two words or
fewer**. The similarity matcher (`match.min_score 0.55`, tuned on sentences) is both unreliable and
**dangerous** there: `Solitude.` and `Solitude Lighthouse.` differ by one token, and picking the wrong one
spends the player's gold and teleports him to the wrong hold. That is risk **R12**.

`lrgDlgServiceSlot($entries, $utter, $kind)` therefore runs **before** the similarity matcher and, when it
does not fire, **blocks it from executing that layer at all**:

* **a price list is decided from the LIVE list**, never from the index: the layer is closed, at least
  `services.slot.min_entries` (3) entries normalise to `max_words` (3) words or fewer, and at least
  `min_priced` carry a cost. **`min_priced` is 0** (it shipped as 2, and that was the round's worst bug -
  see below). `require_named` is descriptive, not configurable: turning it off would put the similarity
  matcher back on a price list, which is the one thing R12 forbids;
* **slot names come from the layer itself** - the entry text minus the price and the trailing full stop -
  never from a hard-coded list of vanilla destinations;
* **matching is exact normalised token containment**, and **longest wins**: `solitude lighthouse` is tested
  before `solitude`, in both list orders. `d57_slots` asserts both directions;
* **ambiguity is a refusal**: two slots match and neither contains the other, so nothing is executed and
  she asks which one in her own words;
* **a negation within six tokens before the slot is not an order** (`don't take me to Morthal`);
* **a price question executes nothing** (`how much to Morthal?`);
* **no slot named on a price list means NOTHING is executed**, even at similarity 0.9.

The same rule is applied on the `want=1` fast path, because that is exactly the message a freshly-opened
destination layer arrives on. Everything after it - the scene / LETHAL / commit / afford / class /
same-session-continuation rails - runs unchanged.

#### Why `min_priced` is 0 [0.5.0 fix pass]

It shipped as **2**, and a carriage list prices **one** row. A census over all 5,718 real layers with
`<Global=..>` resolved found 16 that look like a destination list; the guard engaged on 9 and **not on 7** -
every one of them because `priced < 2`, and the 7 include the **vanilla carriage list**
(`skyrim.esm:0CDF9E`, 10 entries / 1 priced), `update.esm:002F0E` (18 / **0**), `hearthfires.esm:007874`,
`waitcarriageinns.esp:000802`, `cfto.esp:019DC5` and `journey to baan malur.esp:12926B`.

With the guard off, every bullet above was off with it - **the price-question guard and the negation guard
live only inside `lrgDlgServiceSlot()`** - and the 0.55 similarity matcher decided instead. Measured on the
shipped thresholds against `skyrim.esm:0CDF9E`: `"How much to Morthal?"` → `"Morthal."` 0.85/0.61 and
`"Don't take me to Morthal."` → `"Morthal."` 0.85/0.60, both `class=plain`, which IS in `want=1`'s allow
list. Gold left the purse and the player was teleported. Reproduced end to end in `d57`: the mutated build
emits `do=pick;pos=2` for both sentences.

0 is also **what the index builder itself means by a price list** (`build_prompt_index.py`: ">= 3 siblings
of one to three words", with no price test at all), so the two halves no longer disagree. Blast radius,
measured on the live index: **138 of 5,718 layers (2.4 %)** now reach the guard where ~9 did, and a random
sample of 30 is destinations, follower names, house rooms, spell names, provinces and Daedric princes -
precisely the lists where one token separates two answers. It can only ever make execution **stricter**:
a layer that reaches the guard and names no slot executes nothing.

This also fixes **training**, which could never reach the guard at all: Requiem / LoreRim compute a training
fee from the skill level, so a trainer layer carries no visible price on any row. On a five-skill trainer
the similarity margin between `One-Handed` and `Two-Handed` is **0.19** against a floor of 0.15.

### Crime, and the LETHAL rule

`dialogue.crit.lethal_twat` is **extended from the offline census**, never hand-typed:
`build_prompt_index.py --services` writes `data/service_catalog.json`, whose `crime_topics` on this install
are the `DGCrime*` family (15 topics: `DGCrimeResistArrest`, `DGCrimePayFine`, `DGCrimeGoToJail`,
`DGCrimeBribe`, `DGCrimePersuade`, `DGCrimeOrcResistArrest` ...). An **arrest-class** session is detected
three independent ways, because a missed arrest is a voice line that sends the player to jail (risk R16):

1. an entry whose walk-away target **or own topic** is in that family;
2. wire **W10** says `bounty > 0` and either `guard=1` **or** `cf=` is one of the guard factions CHIM
   itself names (read from `herikaActionCatalogGetBuiltinRequirements('PayBounty')`, never retyped);
3. the layer is the index's "I know you..." family.

At the shipped `iCritical = 0` the menu is handed back visible (`do=show`, `why=lethal`) and nothing is
chosen by voice. **"Resist arrest" is refused before every other rail, at every setting.** `PayBounty`
stays offered (it checks the gold first); `ForgiveCrime` is never offered while menuless is on.
**[v1.0]** `iCritical` and `bCrimeManual` are RETIRED - no setting could change this rule. The list simply stays on
screen and nothing on it is picked (`ClassifyCrit`: a guard with crime gold in her hold, or an arrest topic on the list
-> crit 2 -> `StopDriving("lethal")`, a corner note, `drv=0`); the MCM shows the text row "Guards, arrests and
bounties: always yours to click".

---

### [0.5.7 / pt16] Direct barter - CHIM's own window when no session is possible

"What do you have for sale?" at Solitude's market (2026-09-23 16:40-16:43) opened nothing five times: the real
`OfferServicesTopic` entry needs a hidden session (ml=1), CHIM's own `OpenInventory` (`Trade_Items`) IS the vanilla
barter window (`AIAgentAIMind.OpenInventory` -> `ShowBarterMenu()`) but the model chose it on none of the five turns,
and nobody said why. Rule: on a player-speech turn whose words are a **barter** request (`services.kinds.barter.phrases`,
now including `for sale`, `what do you sell`, `i want to buy`, `sell me` ...) and where NO real entry can win (ml=0, or
no known list), the dialogue lane carries barter on CHIM's own code, deterministically:
* `lrgDlgServiceDirect()` decides ONCE per turn (`$turn['svc']['direct']`, `direct_why`, `vendor`): held back by a price
  question, a negation within six tokens / the same clause, ml=1 with a known list (the real entry wins, D4 -
  `hide_chim` already hides OpenInventory there), a FRESH (`services.direct.combat_max_age_seconds`, 90) `combat=1`, a
  companion (`lrgEscortFacts()['owner']` / `mate=1` - she is never refused; CHIM's teammate branch opens her pack), or
  a snapshot that listed her factions with no vendor faction (`lrgDlgVendorHint`: `class=Vendor*`, `Services*`,
  `Job<Merchant|StreetVendor|Innkeeper|Blacksmith|Apothecary|Fletcher|Spell|Fence|...>Faction`). A MISSING or stale
  snapshot is NOT a reason (first contact at a stall has none).
* `lrgDlgServiceRequestBlock()` (volatile, prompt_bottom 910, player speech only): "Do it now: choose Trade_Items in
  this same reply ... ONE short line ... name no prices, list no goods ... showing wares is done by the game itself.
  Never ignore it." - or the plain reason (price question / companion / combat / not on the table). inn / carriage /
  ferry / train under ml=0 get "choose Rent_Room / Hire_Carriage / Hire_Ferry / Training if she really offers that,
  otherwise say plainly why not" (never a code the hold hid).
* `lrgDlgServiceNet()` (Phase 2 post-gate, after the truth gate, before the corner hint): when direct=1 and the reply
  carries no OpenInventory / OpenInventory2 / Trade_Items / Accept_Gift / SelectTopic line from this NPC, and
  OpenInventory is offered and not hidden this turn, it appends exactly `<npc>|command|OpenInventory@<player>\r\n` -
  the line CHIM itself emits for Trade_Items. Never both. Phase 1's gate never sees it (it runs first).
* `bServiceShortcut` OFF still hides RentRoom / HireCarriage / HireFerry / Training with no list, never OpenInventory.
* Log: the dlg turn line carries ` direct=barter` or ` direct=no:<why>` on every barter turn; the net logs
  `net: OpenInventory appended - ...` / `net: the reply already chose ...` / `net: OpenInventory is not on the table ...`.
* Config: `dialogue.services.direct = {barter: true, request_block: true, combat_max_age_seconds: 90}`.
* Tests: `test_dialogue` 12, flow `d56` (direct barter block). No wire change, no game change (script 507 unchanged).

## 10.16 [0.5.0] QUEST AWARENESS v2 (E2) - `qal=`, `qgiver=`, `qi=`, and `note=`

| # | message | key / value | when | old version |
|---|---|---|---|---|
| **W3** | `lrg_dlg ev=facts` | **`qal=<QuestEditorID>:<AliasName>,...`** - at most 6 pairs, alias clipped to 24 chars, `-` when none | every `ev=facts` (already throttled 60 s per NPC) | unknown key, ignored |
| **W4** | `lrg_dlg ev=facts` | **`qgiver=<0\|1>`** - an alias name of this NPC looks like a quest giver | same | unknown key, ignored |
| **W7** | `lrg_npcstate` | **`qi=<0\|1>`** - `bQuestInitiative`, beside the existing `ql=` | every snapshot | unknown key; the server keeps `quests.initiative.enabled` |
| **W11** | `lrg_npcstate` | **`sv=<0\|1>`** `lf=<0\|1>` - `bServiceDialogue`, `bLockedFacts` | every snapshot | unknown keys; the server keeps `services.enabled` / `truth.locked_facts` |
| **W9** | `ExtCmdLRG_SelectTopic` | **`note=<sentence, <= 120 chars, no ; = @ \| " ~>`** on `do=noop` | the quest corner hint, at most once per session per NPC, and **only on a turn that emitted nothing else and where the model did not even try to take business up** | **already handled by game 401**: `LRG_Main.psc` reads `note=` off any `ExtCmdLRG_` command and raises a `Debug.Notification` before dispatch |

**Why `qi` rides the snapshot** and not `ev=facts`: exactly the reason `chk` / `bias` / `ql` do (10.12) -
an initiative clause has to be decidable on an ordinary CHIM turn where no dialogue message was ever sent.
`sv` and `lf` ride it for the same reason.

**What the server does with them.**
**[v1.0: `<she_may_raise>` and W7 `qi=` are RETIRED with `bQuestInitiative`, `iQuestInitiativeGap` and
`quests.initiative.*`; the rest of this paragraph stands.]** `<she_may_raise>` offers ONE entry she could bring up herself - never a check, a payment, a commitment, a
meta option or a walk-away, and never fewer than three meaning-carrying words. The slot is consumed **when
the block is emitted**, not when she speaks, so a turn where she chose not to raise it still costs the slot
and she cannot nag. Off by default. **Zero extra LLM calls**: the block rides a turn that was going to
happen. `<what_you_can_ask certain="1">` quotes the harvested list verbatim; `certain="0"` is the index
fallback by quest, explicitly approximate, forbidden to be quoted and forbidden to be executed from.
`<your_quests>` answers "what should I do next" from CHIM's own `questlog` through the same cleaner
`<shared_business>` uses - current objective only, never a stage number. One clause inside the existing
`<shared_business>` names her own part in a quest (`qal` / `qgiver`), never a spoiler.

**[RETIRED in v1.0: `ev=unhide` is no longer sent - nothing is hidden - and W6 `ev=resume` (10.13) went with it; the
handlers stay and log.]** **W5 - `ev=unhide` gains `layer=`, `n=`, `kind=<root|closed|unknown>`, `resume=<0|1>`**, added to the
existing message rather than a new `ev`: one message, not two. The server logs it twice on purpose, once
as `unhide ...` and once as `handback npc=... why=... layer=... kind=... resume=...`, which is the line to
grep for.

---

## 10.17 [0.5.0] FACT LOCKING, CONDITION TRUTHFULNESS AND LATENCY (E7, E8)

### What CHIM core really has, stated plainly

`lock_profile` (`lib/core/npc_master.class.php`) and `relationships_locked`
(`ext/relationship_system/relationship_llm.php`) are **write protection on the owner's authored profile** -
they stop automatic regeneration from overwriting it. They are **not** a set of facts the model may not
contradict, and **there is no post-hoc verifier of the model's claims anywhere in core**. The mechanism
that really is about truth is the action catalog's **`requirements`**
(`herikaActionCatalogRequirementsMatch()`, applied at `functions/functions.php`): an action whose
preconditions are false never enters the function schema, so the model cannot choose it.

### (a) our rows declare their preconditions in CHIM's own vocabulary

Every row the glue upserts now carries
`metadata.requirements.activity.current_action_not_in = [dead, unconscious, sleeping, combat, attacking]`,
evaluated by CHIM on the same code path as `RentRoom`'s. Rows installed by 0.4 are re-upserted on sight
(the marker alone is not proof). **Stated plainly because it matters:** CHIM's vocabulary cannot express
"a dialogue session with this NPC is open and its list is known" - that is per-turn state in our own
Postgres rows - so **our per-turn filter remains the primary gate and does not move**. CHIM's requirements
are a second, independent net for the turns our filter never sees.

### (b) `<locked_facts>` - CHIM's own injection slot, our content

`chimRegisterPromptInjection('character_bottom', 'lorerim_glue_locked_facts', ..., 205)` - priority **205**
sits between our boundaries (200) and our business rules (210), so the facts are read before the rules that
refer to them. Hard caps: `truth.max_chars` (600), `truth.max_lines` (6), and only these eight classes (the eighth, `faction`, adds its one rule line OUTSIDE the 600-char cap - see 10.22; 10.23 adds no class),
each freshness-bounded:

| class | source of truth | freshness |
|---|---|---|
| `price` | `cost=` parsed from a **live** entry this session | that session |
| `service` | a slot name present in the live layer | that session |
| `bounty` | wire **W10** `bounty=` from `Faction.GetCrimeGold()` | `truth.bounty_max_age_seconds` (45 s) |
| `gold` | the snapshot's `pgold` | snapshot age |
| `check` | the outcome the **engine** produced | that turn |
| `quest` | the active objective rows of `lrgDlgQuestRows()` | `ev=facts` throttle |
| `guard` | **W10** `guard=` from `Actor.IsGuard()` | as bounty |

**Never in the block:** anything from the index alone (the index holds `<Global=KmodFerryCost>`, which is a
**template**, not a price - a line containing `<` or `>` is dropped), anything from a stale cache, anything
the model said earlier, and any per-item price inside the barter window (the engine owns those).

**W10** itself: `lrg_dlg ev=facts` gains `guard=<0|1>`, `bounty=<int>`, `cf=<hex8|->`. **Absent means the
server has no bounty fact and she may not claim one** - that is a different statement from `bounty=0`,
which means "there is no crime faction here", and both are stored explicitly.

### (c) the truth gate - and its honest limit

We cannot rewrite her sentence, and trying to would produce worse dialogue than the problem it fixes. So
`lrgDlgTruthCheck()`, inside the **existing** post-LLM gate, looks only at the reply's **numbers and slot
names**, and only on a turn that carried a `price` / `service` / `bounty` fact. If she names one the game
has not confirmed **and the same reply carries an action that would act on it**, the **ACTION is dropped**:
her words still play - she was wrong out loud, which is in character - and nothing was spent. With no
action it is logged only. Every drop logs both strings, so a false positive is visible. `bTruthGate` off
makes every case log-only.

### (d) `ev=lat` - the first number that includes her voice

| # | message | key / value | when | old version |
|---|---|---|---|---|
| **W14** | `lrg_dlg` | **`ev=lat`** (new `ev`) - `ev=lat;v=1;ref=<hex8>;npc=<name>;ask=<ms>;reply=<ms>;voice=<ms>;total=<ms>;sid=<sid\|0>;first=<0\|1>` | at `OnChimSpeechStarted`, at most **one per reply**, and only with `bLatencyLog` on | a 0.4 server logs `unknown ev=lat - ignored (additive wire: this is not an error)` and returns `handled` |

CHIM's own `[PERF]` marks stop at `llm_complete`: they do **not** include TTS synthesis, the trip back to
the game, or the moment her voice actually starts - which is the thing the owner asked about. `voice=` is
that gap, measured from outside, which is what the player experiences. Stored in a per-NPC ring buffer of
`latency.keep` (200) and logged as
`dlg lat npc=... total=...ms reply=...ms voice=...ms first=...`, with `lat SLOW` over `latency.warn_ms`.

**`ev=lat` is never LLM-bearing**, and `d61_latency` asserts it together with `ev=calib`: `lrg_dlg` is a
fast command, it is not a player-speech type, and both events are answered `handled` before the MAIN lock.
Measured server-side at 2-7 ms.

**The baseline this is read against**, from 8,680 `[PERF]` lines of the owner's own logs (86 with a real
LLM call): a normal spoken turn is **~7.73 s total, ~6.54 s of it the model, ~0.61 s everything the server
does before it** - our own prompt blocks being **58 ms** of that. The model is 85-95 % of the wait.
`tools/bench_llm.php` measures the alternatives on his own connectors, read-only, and **changes no
setting**.

---

## 10.18 [0.5.0] THE PROMPT INDEX HAS ITS OWN POSTGRES SCHEMA (E4a)

CHIM's playthrough switch runs `DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public` and clones a
saved profile back in, and **no saved profile carries a Phase 2 table** - so 37,561 rows of **load-order**
data died on every playthrough switch and were cloned into every saved profile at 30 MB a copy.

Migration **007** creates `lrg_index` and **MOVES** the two tables into it (`ALTER TABLE ... SET SCHEMA`, a
move, never a rebuild), then creates the bodies schema-qualified for a fresh install, plus the new
`lrg_prompt_quest_idx` that E2(b)'s by-quest lookup needs. The schema name is `dialogue.index.schema`
(default `lrg_index`) and it is **substituted into the .sql** through `{s}`, so the config key and the
migration can never name two different schemas. `lrg_dialogue`, `lrg_memory`, `lrg_npc_state`,
`lrg_romance`, `lrg_scene_state` and `lrg_turn` **stay in `public`**: they really are playthrough data and
being cloned is the point.

Two details that are easy to get wrong and are therefore written down. The runner's statement splitter is
**dollar-quote aware**, because 007's `DO` block carries `;` inside its body; and it **strips a UTF-8 BOM**,
because a BOM in front of `CREATE SCHEMA` is a Postgres syntax error and an editor on Windows adds one
without being asked (that exact mistake made the migration a silent no-op once during this round).

A **one-release fallback**: the first qualified `SELECT` that comes back "relation does not exist" switches
that request to `public` and says so once, so a server whose code is 0.5 but whose migration has not run
yet still answers instead of reporting every prompt as unindexed. The **loader never falls back** - it
probes for the table and fails loudly rather than reporting "37,561 rows loaded" into a schema that does
not exist. `deploy_server.ps1` ends with a pre-flight that **fails the deploy** when the index schema is
empty, when `status` does not say `source=db`, or when `data/service_catalog.json` is missing.

`php lib/lrg_prompt_index.php ensure` runs the migration on its own; `... load` runs it first and then
loads; `... status` now prints `schema=` and `coverage_rows=`.

**[0.5.0 fix pass] `pg_trgm` and `lrg_prompt_norm_trgm` are gone from 007.** The matcher is pure PHP
(`lrgDlgMatchText`) and no query in `lrg_prompt_index.php` uses `similarity()`, `<->`, `%>` or any trigram
operator, so the GIN index could never be chosen by the planner - and because an extension installed into
`public` is dropped with the schema, it was the one real casualty of a playthrough switch (`DROP SCHEMA
public CASCADE` on a scratch copy printed *drop cascades to extension pg_trgm / drop cascades to index
lrg_index.lrg_prompt_norm_trgm* while all 37,561 rows and 5,718 layers survived). An existing install keeps
its copies harmlessly; they are simply never recreated. The one-release fallback was also **too eager**:
`lrgPromptMissingTable()` matched any *does not exist*, so a missing FUNCTION or COLUMN flipped the request
to `public` and printed "run `deploy_server.ps1`" advice the deploy cannot act on. It now matches only
`relation ... does not exist` / `42P01`.

**Rolling back to 0.4.1 is not just reverting the files.** 0.4.1 code looks for `public.lrg_prompt`; after
007 that table is `lrg_index.lrg_prompt` and the old code finds nothing and runs unindexed. A rollback must
first run:

```sql
ALTER TABLE lrg_index.lrg_prompt       SET SCHEMA public;
ALTER TABLE lrg_index.lrg_prompt_layer SET SCHEMA public;
```

---

## 10.19 [0.5.1] FOLLOWERS (owner addendum 10) - `fol=`, `witchim=`, `fv=`, and one hide rule

**Additive only. `v` stays `2`, every key below is optional, and an absent key means "say nothing" -
a 0.5.0 game script produces exactly the 0.5.0 behaviour in both directions.**

### The facts the game sends

Three new snapshot keys (`lrg_npcstate`, section 1.1), all emitted only while MCM
`bFollowerAware:Followers` is on. `fac` stays LAST and `class` stays last but one.

| key | value | emitted | absent means |
|---|---|---|---|
| `witchim` | integer | inside the witness block, between `witfol` and `witkid` | **0.** The player's own companions that the teammate flag does NOT see - an NPC CHIM half-recruited. She is still counted in `wit` as well, so this key can only ever make the privacy gate stricter, never looser: `companion_present` fires on `witfol + witchim > 0` |
| `fol` | `k:v` pairs separated by `,` (see below) | right after `hold=` | nothing at all is known about followers: no `<companion_status>`, no hide rule, unchanged behaviour |
| `fv` | 0/1 | after `fol` | the server's own default (`1`). MCM `bFollowerVerbsReal:Followers` - route the follower verbs to her real dialogue entry |

`fol=` is ONE value, so the snapshot's own `k=v;k=v` shape is untouched:

```
fol=fw:<none|sff|custom|chim>,mate:0/1,cff:<n>,pff:<n>,wait:<n>,chim:0/1,ghost:0/1[,slot:<used>/<max>,cap:0/1,prim:0/1]
```

| field | meaning | how (game) |
|---|---|---|
| `fw` | which framework owns her | `sff` = `SFF_SKSE.IsVanillaFollower()`; `custom` = a teammate SFF does not own (Inigo, Lucien, Auri, Remiel, Taliesin, Serana); `chim` = CHIM's own follow or half-recruit; `none` |
| `mate` | 0/1 | `IsPlayerTeammate()` |
| `cff` / `pff` | -1..n | vanilla `CurrentFollowerFaction` 0x0005C84E / `PotentialFollowerFaction` 0x0005C84D rank |
| `wait` | -1/0/1 | `GetActorValue("WaitingForPlayer")`. **-1 is Inigo's own "dismissed" convention and the glue never writes this value at all** - the real dialogue entry owns the wait verb |
| `chim` | 0/1 | `StorageUtil CHIM_FollowPlayerActive` - CHIM's package follow, which is not a recruitment |
| `ghost` | 0/1 | `cff >= 1 && !mate`, and not Inigo-dismissed and not in `DismissedFollowerFaction` (0x0005C84C, by FormID - no plugin in this load order carries an EditorID `DismissedFollower`). **The state CHIM's own MakeFollower creates here** (research/pt9-followers.md 4.1) |
| `slot` | `used/max` | SFF's `SFF_CurrentFollowerCount` global / `SFF_SKSE.GetMaxFollowers()`. Present only when SFF is installed |
| `cap` | 0/1 | SFF's `SFF_CanRecruitMore` global - the follower framework's own "a slot is free". **Not** the condition the vanilla recruit entry uses: that entry is gated on `PlayerFollowerCount == 0`, which SFF sets to 1 as soon as it has any follower, while `SFF_CanRecruitMore` stays 1 until every slot is used. `cap:0` is therefore the looser of the two and can only hide an action that really cannot work |
| `prim` | 0/1 | `DialogueFollowerScript.SFF_IsPrimaryFollower()`. Present only for `fw:sff` |

Every SFF read is behind a po3 editor-id lookup of `SFF_CanRecruitMore`: with SFF absent no SFF script
and no SFF native is touched and `fw` is `none`. **The EditorIDs carry an underscore** (`Simple Follower
Framework.esp` GLOB 05000001 / 05000002); v0.5.1 shipped the underscore-less spelling, which resolves
to nothing, so every SFF read was dead and `slot` / `cap` / `prim` were never sent.

### What the server does with them

0. **[0.5.4 / pt13] An ABSENT `fol=` in the middle of a session is not "nothing known" any more.** Game
   script 504/505 retires the whole block for the rest of a session whenever its snapshot abort rail
   *thinks* a snapshot died in it - and playtest 13 proved the rail fires falsely (six `SNAPSHOT ABORTED`
   lines, not one Papyrus error in `LRG_Main` / `LRG_Profile` / `LRG_Followers`). The server therefore
   keeps, INSIDE the `lrg_npc_state` row, the last value the SAME session sent: a snapshot with `fol=`
   stamps `_fol_last` / `_fol_at` / `_fol_sess`; a snapshot without it inherits those three keys from
   the previous row when that row carries the same `sess`. `lrgFolState()` reads the remembered value
   (marked `_remembered`) for at most `followers.remember_seconds` (900; 0 = the 0.5.3 behaviour).
   A snapshot with no `sess` (game 200, the offline fixtures) remembers nothing, a reload (new `sess`)
   forgets it, the three keys are never taken from the wire, and `<companion_status>` built from a
   remembered value leaves out the volatile "right now she is following / waiting" line. Known cost: an
   owner who switches `bFollowerAware` OFF mid-session keeps the last facts for up to 900 s of that session.

1. **`<companion_status>`** (`lrgFollowerBlock()`, `character_bottom`, priority 204, <= 520 chars):
   who she is to the party, following or waiting, the slot count, and the sentence that matters - the
   commands are *her dialogue*, not the model's to narrate. Emitted only when `fw != none`. It never
   duplicates CHIM's own `<adventuring_party>`, which keys off `conf_opts.CurrentParty` and appears by
   itself once she is a real teammate.
2. **`lrgFollowerPolicy()`**, called at brace depth 0 from `functions.php` **after every other filter**,
   so it can only ever REMOVE: `MakeFollower` while `mate=1` or `cap=0`; `MakeFollower` + `Follow` +
   `FollowPlayer` while `ghost=1`. A stale snapshot (older than `snapshot_max_age_seconds`) is not a
   fact and hides nothing. Config: `followers.*`.
3. **The follower verbs** (`services.follower` in the dialogue config, gated by `fv`): recruit / follow /
   wait / dismiss / trade / favour / home / unhome are resolved against the live list by **exact token
   containment with longest-wins and the negation guard**, exactly like the price slots and never by
   similarity - the player lines are two words long and a wrong pick dismisses the player's REAL
   follower. `lrgDlgFollowerArbitrate()` runs FIRST in `lrgDlgGateItem()`'s intent branch. A verb with
   no entry, an ambiguity, or an order the player negated executes **nothing**; `dismiss` and `home`
   are marked `commit`, so the existing two-step confirmation always runs on them.
   `services.kinds.follower.hide` steps CHIM's `MakeFollower`, `Follow`, `FollowPlayer`, `WaitHere`,
   `ComeCloser` and `ReturnBackHome` aside **only for a list that really carries follower entries** -
   that is what `hide_follower` is for, and why it is a third list beside `hide_chim` / `hide_always`.
4. **A hard rail**: an entry on `services.follower.never_topics` (a favour-BLOCKING topic, the ANIMAL
   twins, a recruit-refusal variant) is never selectable by voice, however it was resolved - including
   by the similarity matcher.
5. **Requiem's recruitment gates need no code at all.** They are INFO conditions, so using the real
   entry respects them by construction; the glue never fabricates a recruit and never offers CHIM's
   shortcut as a substitute for one the engine is not showing (flow d62 asserts exactly that).

### Game side

`LRG_Followers.psc` (new, `Hidden`, globals only) answers the facts and repairs a ghost:
`RepairGhost()` promotes her with `DialogueFollowerScript.SetFollower()` when SFF says a slot is free,
otherwise strips both vanilla follower factions and re-applies CHIM's own package follow. The repair is
queued onto `LRG_Main`'s tick and never run inside a CHIM event (`SetFollower` waits). It is checked on
every snapshot of that NPC, once per load over the actors in high process, and whenever a `MakeFollower`
command reaches `NoteChimAction`. MCM `iFollowerRepairMode:Followers`: 0 promote-else-undo, 1 always
undo, 2 report only.

**`DialogueFollowerScript.pex` must never be deployed.** `tools/stubs/DialogueFollowerScript.psc` is a
compile-only header transcribed from SFF's shipped `.pex`; a compiled copy would overwrite SFF's own
script and break every follower in the save. `tools/compile.ps1` compiles only
`game\LoreRimGlue\Source\Scripts` and refuses the build if a stub name turns up among the shipped
`.pex` files or in `game\LoreRimGlue\Scripts`.

The conversation hold now skips anyone `IsFollowerLike()` (a teammate, a CHIM package follow, or a
ghost), not only `IsPlayerTeammate()` - MCM `bFollowerHoldSkip:Followers`.

## 10.20 [0.5.4 / playtest 13] ESCORT - follow / wait / release, and `ExtCmdLRG_Escort`

**Additive only. One new server -> game command, emitted by the server and never offered to the model;
no new game -> server key, no new `ev`, `v` stays `2`.**

### Why it exists

Playtest 13, 01:43-01:44. The player said "come with me and talk to me in private", "come on follow me now"
and "are you gonna follow me?" to Lisette (FDE, a recruitable bard). The glue logged `intent=none` three
times; CHIM chose `Follow_<player>` three times (`output_to_plugin.log`: `Lisette|command|FollowPlayer@`) and
CHIM's `stayAtPlace(npc, 1)` really ran (priority-100 `FollowPlayerPackage` override,
`CHIM_FollowPlayerActive=1`). She did not move: she was back in a running **engine scene** on her stage
(`q=aaLisette,BardSongsInstrumental,BardSongs,aaLisetteIdle`, snapshot `scene=1`, gate reason `quest_scene`),
and a running scene outranks every package. CHIM's own approach / walk-to logic refuses an NPC with
`GetCurrentScene() != None` (AIAgentAIMind.psc :1560 / :1757) and its one tool for them,
`AIAgentPapyrusFunctions.InterruptScene` (:1311-1337), is destructive (`BardSongsScript.StopAllSongs()`,
`currentScene.Stop()`, and a priority-99 do-nothing package that is never removed). The glue's conversation
hold logged `skip reason=she walks with you already` - correct: the package was on, just beaten by the scene.

### 1. What the player says (server, `lib/lrg_intent.php`)

`lrgIntentEscort()` - regex only, no context, no DB - returns `['do' => follow|wait|release, 'frag']` or null.
The intent is ONE kind, `escort`, with the verb in `kv.do`, so the turn line reads `intent=escort/follow`.
It is **not** in `LRG_INTENT_ACTIONABLE` (the scene's verbs; `release` there already means "stop holding
back") and not in `intent.net_kinds`. Always `conf=high` when it matches.

| verb | phrases (on the cleaned clause) |
|---|---|
| follow | `follow me/us`, `follow along`, `come with me` (also `come upstairs/outside/... with me`), `come along`, `come to my room/place/house`, `come upstairs`, `walk with me`, `tag along`, `you're coming with me`, `let's go` (bare, or + upstairs / outside / home / somewhere ... / to my room ...), and a **bare** `come on` (nothing else said but filler: "come on", "come on then"; "oh come on" is not) |
| wait | `wait/stay/remain (right) here/there`, `wait (here) for me`, `wait up`, `stay put`, `stay where you are`, `hold (your) position` |
| release | `you can/may go` (clause end, + now/home/on/too), `you can go back (to ...)`, `go back to singing / your work / what you were doing ...`, `you're free to go`, `stop following me`, `you don't have to follow me`, `no need to follow me`, `that'll be all` |

Blockers, per CLAUSE (split on punctuation and on and / but / then / so, after the DLL context prefix and
the speaker prefix are stripped): a hypothetical or a quotation is nothing; a negation (or can't / won't /
shouldn't) blocks wait and follow but not the release phrasings that carry their own ("you don't have to
follow me"); a question that is not a polite request (`lrgIntentQuestion() && !lrgIntentPolite()`) is
nothing - "are you gonna follow me?" stays `why=question`, "will you follow me?" is a request, "why don't you
come with me" is a request. Wait is skipped when the subject is the player ("I'll wait here", "I want to
stay here"); follow and wait when it is somebody else ("they will follow me"). Owner additions:
`escort.phrases.{follow,wait,release}` (whole words). A second request in the same breath is kept in
`extra` only when it means something outside a scene (`act`, `undress`, `dress`) and is named, never
promised.

**Where it runs:** only outside a scene with this NPC (`lrgPrepareTurn()`'s out-of-scene branch). In
`closed / public / private / follow` and on the blind turn the ordinary recogniser is called with
`ctx.escort = true` and asks the escort first; on a silence the game knows the reason for, only
`lrgRecogniseEscort()` runs (no sexual reading exists there). Inside a scene with her nothing changes:
"wait here" is `hold`, "come with me" is `climax`. The dialogue lane's conversation-hold test
(`lrgDlgPlayerAskedToMove()`) also asks the escort recogniser, so a held NPC told "come along" keeps
CHIM's movement actions on the table.

### 2. What the model is told (`lrgEscortDirective()`, one `<player_request>`)

Always: the request in one plain sentence, **"This is not intimacy: nothing said above about intimacy
applies to it."**, and ONE short line of hers. Then, first match wins:

| situation | directive |
|---|---|
| her live menuless list answers it (`LRG_DLG_TURN.fol.verbs` has follow/recruit, wait, or dismiss) | take it up with the matching key from `<follower_commands>`; no other action |
| a follower framework / the party owns her (see the rails in 3) | her own follower dialogue carries it out, not a CHIM action; no movement action |
| follow, `FollowPlayer` offered this turn | **"Do it now: choose Follow_<player> in this same reply ... - never ignore it"** (+ when she is in an engine scene: "the game takes her away from what she is busy with first - she may say she needs a moment. Do not make up a reason to stay: if the game cannot take her away, she is told why and says so then" - **[0.5.5]**, owner addendum 11 (d)) |
| follow, not offered, engine scene, the escort will carry it | "the game makes her walk with the player by itself: agree in one short line, choose no action" (+ the same engine-scene sentence) |
| follow, not offered, no scene | "nothing can make her walk on this turn: she says so in one short line with a plain reason - never ignore it - and does not describe walking" |
| wait / release | "nothing to choose for it: the game stops her following / lets her go by itself" (or "no action for it this turn" when nothing will carry it), and **no movement action - not Follow_<player> either** |

`WaitHere` is switched OFF in CHIM's catalog on this install (`core_action` id 53, `is_activated=f`; CHIM's
own `AIAgentAIMind.WaitHere()` is a deprecated notification stub), so wait is routed through the glue's
`do=wait`. In mode `closed` the directive replaces the intimacy "it is not happening" shape; on a silent
turn it is the only directive (section 6.2).

### 3. The wire

```
<npc>|command|ExtCmdLRG_Escort@ok=1;cid=<turn cid>;npc=<display name>;do=<follow|wait|release>;safe=<p1,p2,...>
```

| key | meaning |
|---|---|
| `ok=1;cid;npc` | as every glue command (section 2). `cid` is the turn's own cid; the code differs from any other command of the turn, so the game's `npc|command|param` de-duplication cannot swallow it |
| `do` | `follow` - leave a STOPPABLE engine scene so the follow package wins · `wait` - stay where she is · `release` - back to her own AI |
| `safe` | comma list of **quest EditorID patterns** (`*` and `?`), config `escort.safe_quests`, default `BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*`. Only a scene whose OWNING QUEST matches may be stopped. Sent on every verb (one key order) |

No `hold` / `wait` / `warp` decoration keys, ever.

**When the server sends it** (`lrgEscortPlan()`, in the Phase 1 post-LLM gate; Phase 2's gate passes it):
- `do=follow` - the reply carries CHIM's `FollowPlayer` (code, or the snake-cased display name
  `Follow_<player>`) **or** the player asked (escort/follow, high) and the reply carries no movement
  action at all (the safety-net shape) - **and** she is in an engine scene: snapshot `scene=1`, or the
  dialogue lane's `scene=1`, or gate reason `quest_scene`. Not in a scene -> CHIM's own follow is enough,
  nothing is added. A reply that chose another movement (`TravelTo`, ...) is not overridden.
  **Placed IMMEDIATELY BEFORE CHIM's `FollowPlayer` line** when there is one, else appended.
- `do=wait` / `do=release` - the player asked (escort, high). Appended; a `FollowPlayer` the model chose
  against those words is removed from the batch (logged).
- **Never**: on a turn inside a scene with the player (`scene` / `outro` / blocked), on anything but a
  player-speech turn, without a snapshot at most `escort.snapshot_max_age_seconds` (300) old, for a
  companion somebody else owns - `fol=` says `fw:sff` / `fw:custom` / `mate:1` / `ghost:1` (the
  remembered value counts, 10.19 item 0), or with no `fol=` known the snapshot's own `mate=1` or an
  `escort.follower_factions` entry on `fac=` (default `CurrentFollowerFaction`) - when her live menuless
  list answers the verb, when `escort.enabled` / `escort.emit` is off, and for the rest of a game session
  (session tag) after the game answered `unknown command` once. At most one per turn. No adult gate: it is
  not intimacy (the kill switch and SHARMAT still silence it, like every Phase 1 line).

**The funcret** (`command@ExtCmdLRG_Escort@<param>@<result>`) is recorded in `last_result` like any glue
result. **[0.5.5]** A success (either sentence, including "stops what she was doing and comes with you")
**ends before the MAIN lock** exactly as in 0.5.4; the "one moment" belongs to her line on the request turn
(section 2). An `Error: <reason>` other than `unknown command` is **voiced** in CHIM's funcret turn (10.21):
the inactive catalog row exists for exactly that. The game's own late failure (the follow-up tick left her
in a scene that kept starting again: log line `escort <npc>: she went back to her scene (...) and stays`) is
told on her next player turn. `unknown command` from a script <= 505 is still swallowed, as before.

### 4. What the game does (GAME lane, script 506 as built; the server depends on none of it)

**[script 507]** The `Error:` reasons below are the TECHNICAL reasons: 507 sends them as `err=` and puts her words in
the `Error:` text (1.6; `EscortSayRefusal` / `EscortSayBusy`, e.g. `<name> is in the middle of a performance she cannot
leave`). Successes are `OK: <name> comes with you.` etc. Two silent paths are closed: a follow CHIM's forms could not
put on answers `Error: <name> cannot come with you right now` (`err=<name> cannot follow you: ...`; 506 said "comes
with you"), and when the follow-up tick gives up (her scene came back twice) the game sends one `late=1` `Error:`
funcret (`err=she went back to her scene (<quest>) and stays - <why>`) besides its log line.

- `LRG_Main.HandleCommand` routes `ExtCmdLRG_Escort` to `CmdEscort` (after the usual de-duplication ring,
  the `command ... from <npc> via <path> param=...` log line and the `IsEnabled()` check). The actor is
  resolved by `npc=` (CHIM `getAgentByName`), falling back to the line's actor. Unknown `do` ->
  `Error: unknown escort request (<do>)`; dry run -> `Error: the glue is in dry-run mode - nothing was changed`.
- **Guards** (follow and wait): alive / enabled / 3D-loaded, **never a teammate or an SFF follower**
  (`Error: <npc> will not do that now (she is your follower - her own follow and wait apply)`), never
  hostile, fighting, in an arrest or in the glue's own OStim scene (`... (hostile|combat|an arrest|a scene
  with you)`). The server's own rails (section 3) already keep ghosts and framework companions out.
- `do=follow`: if `GetCurrentScene()` is set, its OWNING QUEST's EditorID is judged in this order: no quest
  -> refuse; the game's own **deny list** (`MQ*,DLC1MQ*,DLC2MQ*,DLC1VQ*,CW*,DB*,TG*,MG*,C0*,DA*,*Courier*,
  *ForceGreet*,*Arrest*,Windhelm*,Winterhold*`, no `safe=` gets past it) -> refuse; **an open journal
  objective** of that quest -> refuse; not on `safe` (absent = the same built-in default) -> refuse. A
  refusal answers `Error: <npc> is in the middle of something she cannot leave (<why>)`. MCM
  `bEscortStopScene:Followers` (default on) can switch the scene stop off. The stop itself: a bard quest
  (`BardSongs*`) gets `BardSongsScript.StopAllSongs()` - on the owning quest, or on Skyrim.esm `BardSongs`
  (0x74A55) when the owner does not carry the script (`BardSongsInstrumental`) - because a bare
  `Scene.Stop()` lets the song's end fragment start the next song; then `Scene.Stop()` if the scene is
  still playing. **Never CHIM's `InterruptScene`, never a do-nothing package.** Then CHIM's follow: if
  `CHIM_FollowPlayerActive` is not 1 the glue applies it with CHIM's own forms and recipe (FollowFaction
  0x1BC24 rank 1, `AddPackageOverride(FollowPlayerPackage 0x2226D, 100, 0)`, `CHIM_FollowPlayerActive=1`,
  `EvaluatePackage`) and remembers that it did (`LRG_EscortApplied`); otherwise only `EvaluatePackage()`.
  A follow-up tick at +2.5 s and +6 s ends a scene that started again (same verdict, at most twice), then
  leaves her in it with the corner note `<npc> is in the middle of something she cannot leave`.
- `do=wait`: `WaitingForPlayer` 1 (the old value kept) and **the follow comes off whoever put it on** -
  CHIM's `FollowPlayerPackage` runs on `AIAgentFactionFollow` rank 1 and never reads `WaitingForPlayer`.
  A follow the glue applied goes with the faction the glue added; CHIM's own gets exactly the follow half
  of CHIM's `ResetPackages` (override off, `CHIM_FollowPlayerActive=0`). No stand-still package is added:
  she stops following and her own AI (for a bard, her stage) takes over.
- `do=release` (no guards; it only undoes): the follow off exactly as for wait (CHIM's own only when she is
  not a teammate / SFF follower), the glue's `WaitingForPlayer` given back, `EvaluatePackage()`.
- Every command answers through `ReportResult` (section 1.6). Successes: `<npc> stops what she was doing
  and comes with you.` · `<npc> comes with you.` · `<npc> waits here.` · `<npc> goes back to her own
  business.` Every `Error:` is also the corner note (`bNotifyErrors:General`).
- Self-heal: once she becomes the player's teammate the glue takes back its own follow / wait edits
  (snapshot path, `NoteFollower`).

### 5. Config (`escort`, all optional)

`enabled` · `recognise` · `directive` · `emit` (all true) · `safe_quests` · `snapshot_max_age_seconds` (300) ·
`follower_factions` (`["CurrentFollowerFaction"]`) · `phrases.{follow,wait,release}` ([]) ·
`words.{follow,wait,release}` (the plain phrase for the directive). `followers.remember_seconds` (900) is
10.19 item 0.

### 6. Tests

`test_intent` J (the three pt13 lines verbatim, every phrase above, the blockers, the scene readings, the
directive shapes), `test_phrases` D (17 rows x 6 out-of-scene modes x the directive, plus the two scene
states), `test_gates` 34 (a2) (the remembered `fol=`), flow `28` (recognition in closed / silent, the batch
order, the funcret, the net shape, no scene, wait / release, SFF, the remembered `fol=`, a reload, an old
game script, the model forging the code, inside a scene).

### 7. [script 508 / pt16] The natural follow (game only)

**[script 508 / pt16] THE NATURAL FOLLOW - game only, nothing on the wire.** CHIM's own follow package
(AIAgent.esp PACK 0x2226D) is a Follow procedure authored with Min 512 / Max 1024 units and Need LOS - four
times the radii of Skyrim.esm's own follower packages (FollowPlayer 0x750BE: 128 / 256, no LOS rule) - which
is the sprint-and-stop of playtest 16. Package inputs cannot be changed at runtime, so LoreRimGlue.esp now
carries one PACK of its own, `LRG_FollowPackage` 0x803 (CHIM's record byte for byte, minus its VMAD and CTDA,
with those three inputs patched), which the game lays OVER CHIM's follow at PapyrusUtil priority 100, where
the last added override wins a tie: `LRG_Main.EscortFollow` puts it on with the escort, `NoteGlueFollow` (the
snapshot path, before `NoteFollower`'s 20 s throttle) shadows a follow CHIM's own `FollowPlayer` put on, and
a 4-second tick (`TickGlueFollow`, the saved 4-slot ring `gfQ`) puts ours back whenever CHIM re-adds its own,
stands down while CHIM moves her itself (its MoveTarget linked ref is set: ComeCloser, walk-to-target) and
takes ours off when `CHIM_FollowPlayerActive` is 0, when she becomes a teammate, or when the MCM toggle
`bNaturalFollow:Followers` (default on) is off. CHIM stays the owner of `CHIM_FollowPlayerActive` and of its
package; wait / release take ours off first. **Server -> game and game -> server: unchanged** - no key, no
verb, no `ev`, no message type; the current server with script 508, and script 507 with any server,
interoperate (507 simply keeps CHIM's radii). The `watch: FollowPlayer ... did not move` judge of 10.21
becomes truthful by itself: with Max 256 she really closes the gap. StorageUtil key on the NPC, the glue's
own: `LRG_GlueFollow`. Object id 0x803 is spent for good (a save may hold the override by form).

**### 8. [script 509 / pt17] follow / wait / release through Simple Follower Framework**

Additive. One optional key on the OK funcret; no new verb, no new message type. Owner: "lets change it to
whatever follower mod is managing it and work with it that way". GAME (MCM `bSffFollow:Followers`, default on):
`do=follow` makes her the player's real companion through SFF's own `SetFollower` when SFF can take her
(installed, `SFF_CanRecruitMore`, PotentialFollowerFaction, not a hireling - `LRG_Followers.SffRefuseReason`),
after taking every follow-type move of CHIM's off her (`ChimFollowVeto`: flag 0 first, linked ref and soft-move
bookkeeping cleared, overrides 0x2226D / 0x1BC25 / 0x268B0, faction 0x1BC24, the pt16 overlay); a companion of
SFF's follows again / waits through `SFF_SetLastSpeaker` + `FollowerFollow` / `FollowerWait` (the direct
`WaitingForPlayer` write only when `GetDialogueFollowerTarget()` does not answer her); `do=release` on her is a
WAIT, never a dismissal. Otherwise the 508 path runs (CHIM's follow + the overlay) and the OK funcret carries
`fb=<technical reason>` on field 2 (`OK: <npc> comes with you.`), plus the corner note. Any follow-type move of
CHIM's found on a teammate is vetoed at her next snapshot / tick. The escort log line ends in
`fol=[<fol=>] walkto=<0|1>`. SERVER: `lrgEscortFacts` `sff` / `take`; `fw:sff` and `ghost` are routed
(`escort.sff_owned`), `fw:custom` / teammate / faction stay skipped and she SAYS her own dialogue is the way;
outside a scene `do=follow` goes out when the player asked and SFF can take her (`escort.sff_promote`), and CHIM's
`Follow_<player>` line is dropped whenever the framework may take her; an OK with `fb=` is voiced like a failure
(`escort.voice_fallback`) or told on her next turn through `missed`. Tests: test_gates 35 (1d), flow 28 (5b, 5c, 7,
8). The framework's follow is USSEP's `PlayerFollowerPackage` (Min 384 / Max 512) - wider than the pt16 overlay;
SFF's `IvyFollowerIdleSandbox` sandboxes her indoors with `bFollowerSandbox=1`.

## 10.21 [0.5.5 / owner addendum 11] NEVER SILENT - voiced failures, "never ignore it", and CHIM's own movement

**Server side as built by the server lane; the game side (script 507) added the result markers of 1.6 - see
section 9 for how the server reads them.**
Owner, after playtest 13: "well if she's busy she should say that then and it will be fine, she never told me
she was busy, she just didn't do anything." Until 0.5.4 every glue row had CHIM's funcret follow-up OFF, so a
failed command was a corner note at best and she said nothing until the player spoke again (then "what you
last tried did not happen"). Silence after a request is now a defect in every case.

### 1. How CHIM decides to voice a funcret (verified in the installed HerikaServer)

`main.php:2657` -> `processor/funcret.php`: the follow-up config comes from the catalog ROW of the code
(`herikaActionCatalogGetResolvedFollowupConfig()`, `lib/core/action_catalog.php:1436`); with `followup.enabled`
false, or an empty `followup.prompt`, it calls `terminate()` (no LLM call). Otherwise it runs ONE turn whose last
user line is `"(<followup.prompt>) <cue>"`, where `<cue>` is `$PROMPTS['afterfunc']['cue'][<code>]` (or `default`,
`processor/request.php:14-18`), the tool call is `{"<arg_name>":"<field 2>"}` (raw interpolation) and the tool
result is field 3; `use_functions_again` false switches every action off for that turn.
`suppress_placeholder_infoaction` only drops the "issued ACTION ..." placeholder line from her event log
(`chimLogFuncretResultInfoAction`) - it never controlled the follow-up. **The switch is per ROW; the glue needs
it per RESULT.**

### 2. The design: follow-up ON for every row, silence decided by the glue BEFORE the lock

- **Catalog v11** (`LRG_ACTIONS_VERSION` 11): every glue row has `followup = {enabled: true, arg_name,
  use_functions_again: false, prompt: LRG_FOLLOWUP_PROMPT}` (a rule, not a line). `ExtCmdLRG_Escort` gets its own
  row, **inactive** (`is_activated` 0, `available_to_npc` / `_followers` 0, `request_types_any` `['lrg_never']`),
  because a code with no row can never be voiced. `suppress_placeholder_infoaction` stays true on every row.
- **`lrgFuncretVerdict()`** in `lrgHandleGameMessage()` (preprocessing, main.php:193, before the MAIN lock):

| result | verdict |
|---|---|
| not a glue code, or `ExtCmdLRG_SelectTopic` (Phase 2: its own row, follow-up off) | `pass`, untouched |
| kill switch / feature off, SHARMAT, no `npc=` echoed | `handled` - silent |
| a success | `handled` - QUIET, exactly as before (only earlier: 0.5.4 passed it on to wait for the MAIN lock and a full prompt build before `funcret.php` ended it) |
| `unknown command` to the escort from a script <= 505 | `handled` (10.20) |
| a failure, but `lrgVoiceSkip()` says no: `voice.enabled` off · a dry run · `adults only` / `the feature is switched off` (hard rails: no line about intimacy is ever asked for there) · the hold carrier (`src=carrier`, or its exact shape) · her own move on a scene-lead tick (`src=lead`, unless `voice.lead_failures`) · another failure voiced for her less than `voice.min_gap_seconds` (8) ago | `handled`; `last_result.told=false`, so her next turn is told once, as before |
| every other failure or refusal | **VOICED**: `last_result.told=true`, `voiced_at`, `$GLOBALS['LRG_VOICED']`, and this request's `gameRequest[3]` becomes `command@<Code>@<plain token>@Error: <plain fact>` (the token is the verb; the k=v parameter would otherwise land in her event log as the action's target). `pass` |

  The `src` comes from `lrg_memory.sent` (written with every emitted command: `$emit`, the net, the second command,
  the escort, the carrier); a result whose cid is not there is judged voiced unless it has the carrier's shape.
- **`prompts.php`** puts `lrgVoicedCue(<HERIKA_NAME>)` + `TEMPLATE_DIALOG` into `$PROMPTS['afterfunc']['cue'][<code>]`,
  only for the NPC the result belongs to.
- **`lrgPrepareTurn()`**: a funcret turn with a voiced record for this NPC is mode **`voiced`** - glue actions hidden,
  nothing injected, no gate run. `lrgApplyTurnRuntime()`: `FORCE_MAX_TOKENS` = `voice.max_tokens` (140) and
  `OPENAI_FILTER_DISABLED` (CHIM's word-scoring refusal detector would otherwise swap an in-character "not now,
  because ..." for its canned refusal line).
- **`context_pre.php`** first calls `lrgVoicedGate()`: when the request is for another character than the result's
  NPC, or CHIM's row for the code has no follow-up yet (an install whose rows predate v11 - they are reinstalled on
  the first turn after the deploy), it logs why, puts `told` back to false and **terminates** - the result is then
  told on her next turn, never lost and never spoken by the wrong mouth.

### 3. What she is told (rules, never lines)

`What just happened: <what> - <why>. <npc> says so now, in ONE short line in <npc>'s own voice - the real reason,
in plain words, never a word about the game, commands or errors. <npc> does not pretend it happened, does not
promise it, and makes no speech of it.`
- `<what>` (`lrgVoicedWhat`): escort follow / wait / release - "could not come along with <player>" / "could not stay
  behind and wait" / "could not go back to what she was doing"; clothing - whose clothes did not come off / go back
  on (`who=` from the echoed parameter); a start - "nothing began between <npc> and <player>"; scene control - the
  change of position / the move to the <furn> / the pace / winding down ... did not happen.
- `<why>` (`lrgVoicedWhy`), from the game's closed reason list (1.6, 10.20 section 4): the escort's "in the middle of
  something she cannot leave (...)" becomes "in the middle of a performance that she cannot just leave" (a bard, by
  `fac=`) or "something important that she cannot walk away from" (a journal / main / faction quest) - never
  "quest" or "journal"; "will not do that now (...)" becomes the plain fact (her own companion orders, hostile,
  fighting, an arrest, the two of them busy together); the privacy reasons stay facts ("somebody is watching",
  "<player>'s companion is right there"); a transition reason says it can be asked again in a moment; a technical
  reason (not installed, unknown request, not authorised, the actor not found ...) becomes "it just would not work
  right now"; anything else on the closed list is plain English already and is used as it is.
- The same fact is field 3 of the funcret CHIM feeds the model (the JSON connector turns it into "<npc> issued
  ACTION, but Error: <fact>").

### 4. Every recognised request says "do it, or say why - never ignore it"

See 6.2. The escort follow directive on a stage adds (addendum 11 (d)): "she may say she needs a moment. Do not make
up a reason to stay: if the game cannot take her away, she is told why and says so then" - the GAME decides whether
a scene may be left; a refusal comes back as the voiced turn above.

### 5. CHIM's own movement that fails in silence (addendum 11 (c))

- The escort already covers follow / wait / release (its funcret). For CHIM's own `ComeCloser`, `MoveTo`, `TravelTo`,
  and `FollowPlayer` when no escort went out, `lrgMoveWatchNote()` (post-LLM gate, player-speech turns outside a
  scene with her) writes `move_watch {code, at, cid, dist0 = the snapshot's dist, scene0}`.
- `lrgMoveWatchCheck()` runs on every snapshot of that NPC (pre-lock, `lrgStoreNpcState()`): nothing before
  `voice.watch.min_seconds` (6); FAILED when she is still in an engine scene (`scene=1`), or - for `ComeCloser` /
  `FollowPlayer` - still more than `near_units` (350) away and not `moved_units` (100) closer than `dist0`; SUCCESS
  (cleared in silence) when free of any scene and near or clearly closer (for `MoveTo` / `TravelTo`: free of any
  scene); no evidence within `max_seconds` (30) = cleared, nothing said. A failure becomes `missed`.
- The escort's late failure (10.20 section 4, the follow-up tick) arrives only as the game's `lrg_log` line
  `escort <npc>: she went back to her scene (<quest>) and stays - <why>` (sent while `bDebugLog:General` is on, the
  default); `lrgNoteEscortLate()` turns it into `missed`. The game lane could send it as a fact of its own instead of
  a log line; the server would only need a second reader.
- `missed` is told ONCE on her next player-speech turn, in every mode but a scene with her (there it is stale and
  simply dropped), through `lrgMissedNote()`: "<npc> did not manage to <what> just now (<why>). <npc> does not act as
  if it had worked; if the player asks about it, <npc> says why in one short line." It names no action and permits
  nothing; on a silent turn it is one of the two things 6.2 allows.

### 6. Cost

No new LLM call of the glue's own. A voiced failure is ONE LLM + TTS turn, the one CHIM already supports for a
funcret, and it replaces a silence (pt9 measured MAIN-held spoken turns at ~7-8 s median with TTS on the game's GPU;
the model's time to first sentence was ~1.5 s). A success is now CHEAPER than in 0.5.4: it ends before the lock
instead of waiting for it and a full prompt build (pt9: 24 funcrets, 255 ms median under MAIN, 8.2 s worst case of
lock wait). The pre-lock verdict itself costs a few ms (`test_latency` section 6 measures it). The movement watch
is one memory read per snapshot of an NPC and one write per order.

### 7. Config (`voice`, all optional; code defaults `LRG_VOICE_DEFAULTS`)

`enabled` (true) · `lead_failures` (false) · `min_gap_seconds` (8) · `max_tokens` (140) ·
`watch.{enabled true, min_seconds 6, max_seconds 30, near_units 350, moved_units 100}`.

### 8. Tests

`test_gates` 23 (catalog v11, the escort row, follow-up on every row), 24 (quiet success, voiced failure, the
rewritten funcret, the voiced turn's mode / cue / runtime, the gate, the quiet failures: lead move, carrier, dry
run, adults only, the gap), 35 (the reasons in words, closed / public / private directives, the movement watch, the
escort's late failure), 8b (the blind turn's one-line answer); `test_latency` 6 (the verdict's cost); flow `29` (a
refused escort, failed clothing, an unreachable position, a quiet success, her own lead move, a row without the
follow-up, closed / public / blind requests, `ComeCloser` failed and succeeded, the escort's late failure - all
through the real hook files), `27` (blind), `13` / `16` / `28` (the new result semantics), `11` (the off variants
never voice).
Flow `30` and `test_gates` 35 (1b) / (1c): the game-507 result shape below, end to end through the hook files.

### 9. [game script 507] How the server reads the 507 result shape (1.6)

Built by the release pass after the two lanes (they were built in parallel; the server lane matched on the
`Error:` text, which 507 turned into her words).
- `lrgRecordResult()`: `ok` = the result does not start `Error:` (so `OK: ...` is a success, and an unmarked
  result from a script <= 506 still is). `last_result.reason` = **`err=`** when present, else the `Error:` text
  (= the technical reason, exactly as before 507); `last_result.say` = her words (507 only, else `''`);
  `last_result.late` = `late=1`. Every server match stays on `reason`: the G4 early close on
  `no scene is running`, `lrgResultSaysNoScene()`, the old-game `unknown command` of the escort, the voice skips
  (`adults only`, `the feature is switched off`, `dry-run`), and `lrgVoicedWhy()`. The paid refusal still matches
  any reason with `gold` (both of her lines keep the word). The `result` log line gains ` (late)` and
  ` [why: <err>]`.
- `lrgVoicedWhat()`: a `late=1` start = "the change of position did not happen" (the scene DID begin).
- `lrgVoicedWhy()` additions: the escort's late reason `she went back to her scene (<quest>) and stays - ...` =
  the scene that keeps pulling her back ("a performance" for a bard), never the quest's EditorID; `unreachable` =
  the closed-list sentence; a clothing `not possible in this position` (507's "nothing changed") = "there was
  nothing left to take off" / "nothing to put back on"; any reason naming the machinery (OStim, CHIM, the MCM, the
  glue, a script / plugin / package, a dry run, `.esp`, `cannot follow you:`) = "it just would not work right now".
  Her own words are never put into a cue (no example lines, section 0).
- **Spoken once, never twice.** A `late=1` escort failure that is voiced clears a `missed` note the game's
  `lrg_log` line may already have left; a log line that arrives after a voiced late failure leaves none
  (`lrgNoteEscortLate`); and when a late failure was NOT voiced (inside `voice.min_gap_seconds`), her next turn
  carries the `missed` note only - the "what she last tried did not happen" note steps aside for the same code.
- Her next-turn "did not happen (...)" note (a failure that was not voiced) now carries her words (`say`) when the
  game sent them, else the technical reason as before.

**[pt17] A dry run IS voiced.** `lrgVoiceSkip` no longer keeps the developer dry run quiet (`voice.dry_run_voiced`);
`lrgVoicedWhy` names the switch and the page ("the LoreRim Glue developer dry-run switch is on in its MCM (Diagnostics
page) ..."); the directive lets her name the game's settings for this one reason. In 508 the corner note existed
(`ReportResult` -> `SendFuncret abNote`) but named no page and no voice followed - playtest 17's seven silent refusals.

## 10.22 [0.5.7 / pt16-legion] ENLISTMENT TRUTH - the `faction` class, the `faction` log prefix, no wire change

"I want to join the Legion" said to Captain Aldis (2026-09-23 16:38) got an invented appointment ("Report to the training
yard at Castle Dour when the bell rings") and no quest: no menu was open, ml=0, and nothing TRUE about recruitment was in
the prompt, so the model worked from CHIM's own auto-profile ("trainer for the Imperial Legion") and repeated itself on the
rechat turn, which carries no locked facts by design. Aldis owns no join line anyway. `lib/lrg_factions.php`
(research/pt16-legion.md sections 4 and 8) adds:
- a `<locked_facts>` class **`faction`** (in `truth.classes`; the table is overridable through `dialogue.factions`,
  lrgMerge: maps merge, lists replace). The POSITIVE fact about who really recruits, decided from what the game already
  sends: the display name, the snapshot's `fac` EditorIDs (1.1), her cached topic list and `q=`. `recruiter` = a named
  recruiter, or the row's recruiter faction set plus a recruiter topic really on her list; `redirect` = a member faction
  glob or a row quest (quest membership alone is never a recruiter); else `none`. The lines are LOAD-ORDER facts, not
  per-turn confirmation: every row carries `verified_from`, every line is prefixed "of this world, not of this moment",
  and they go FIRST in the block. On a faction turn the block ends with one rule: never invent an appointment, a meeting
  time or place, a drill, a test, a rank or a next step; never say an enlistment has begun unless stated or the game said.
  A recruiter under ml=0 is told enlistment is settled in her own dialogue and to say so (addendum 11); under ml=1 her
  real entry and the action are named. Inside `factions.window_seconds` (90) a `rechat` turn and the player's next
  line without an ask carry the `faction` class ALONE - the only class that ever rides a non-speech turn.
  `lrgDlgStaticGuidance()` is unchanged (flow 16).
- no open-for-awareness on a redirect (the Say-Once greeting is kept; `q` no longer opens Aldis) nor on a recruiter
  under ml=0 (the game would refuse it); a recruiter under ml=1 is a business marker of her own.
- the hand-off, post-gate and want=1 alike: an enlistment is answered by the exact join entry (a `recruiter_topics` /
  `redirect_topics` glob AND a join word in the entry's own words - `CRNoWorkBranchTopic` is shared - or an exact line)
  or by her words, never by similarity; one runs under every existing rail, two ask, none executes nothing. It runs after
  the follower verbs and before the slots. `SolitudeFreeformGuardSolitudeArmy` is the guard's own real answer.
- log: `dlg faction npc=.. asked=<row> role=<recruiter|redirect|none> ...`; the turn line gains
  ` faction=<row>:<role>[:carried]`. Wire: nothing new - the hand-off is an ordinary `do=pick` / `do=open`.
- tests: `test_dialogue` 13 (60 checks: the three real utterances, the roles from the real fac csvs, the lines, the
  carry, the open, the hand-off, the table), `test_prompt_index` 5c (the table against the built index).

- [reviewer] A carried ask (inside the 90 s window) rides `<locked_facts>` only - it never owns a click or an open; the
  player's words this turn or the model's own item decide (`lrgFacClickAsk`).
- [pt17-replies] A carried ask must never make an unrelated line answerless. On a carried LIVE turn (his words now, no
  ask in them) the first `faction` line is prefixed "(background to what he asked a moment ago, not what he is saying
  now)" and the rule says his words now may be about something else - answer them, in words; on every other faction
  turn the rule ends "Answer what he says, in words." A rechat carry keeps the plain wording (`lrgFacCarriedLive`;
  `factions.carried_note`, `factions.answer_in_words`; `test_dialogue` 13 (i)). The floor for a reply that carries no
  words at all is 10.23.

**[pt17, corrected by pt18-quest] Tullius on an Alternate Perspective start.** AP's greet 0D5146 is CLOSED on this save (AP adds GetGlobalValue(MQQuickstart) < 7 and pins the global at 7.0 until its Helgen path; Save15/16 decode 7.0), so the FreeToGo line (0D5150 -> 0D5136 SetStage 10) is unreachable; every Tullius line that starts CW00A needs MQ101 complete (0D5145 GetQuestCompleted -> 0D5113 -> 05206780 -> alternateperspective.esp:206783 CW00TulliusGreetTalkRikkateAP, TIF = SetStage 10). `lrg_factions.php` anchors Tullius on that AP line, keeps the FreeToGo norm in `entries` for a save whose greet is open, and adds per-recruiter `closed` (the ROAD: MQ101 complete_stage 900, lrgFacRoad - known incomplete = the Helgen line replaces every recruiter line for Tullius and Rikke; unknown = nothing asserted open, Rikke's 'before' line hedged) and `effects` (Tullius only; 10.26). The words lane's flag `$turn['faction']['executed'] = {quest, stage, how 'entry'|'pick'|'open', cid}` is set by lrgFacTurn pre-LLM ONLY on a licensed queue (10.26); the OK funcret writes `lrgDlgState(npc)['exec_qst']` either way. Log tail: ` faction=<row>:<role>[:carried][:road=closed|unknown][:qe=<state>[:licensed]][:executed=<Q>:<S>]`.

- [pt18-words] **`$turn['faction']['executed']`** = `['quest' => 'CW00A', 'stage' => 10, 'how' => 'entry'|'pick'|'open', 'cid' => <cid>]` (`[]` / absent = nothing executed). Written ONLY by the quest lane, ONLY before `call_llm()` (the faction block of `lrgDlgPrepareTurn`, or `context_pre.php` before the injections) - the words stream before any post-process hook, so a flag set later cannot make them true; `how=entry` is the pt18-quest click-free entry (exec_qst is written by lrg_actions.php lrgQuestEntryResult on the funcret OK, PROTOCOL 10.26). Read by the never-false judge (10.25) as the freshest stage source, by `lrgFacLockedLines` (a second line: "the game has just recorded <Q> stage <n> - <meaning> - you may say so, and nothing beyond it") and by `lrgFacRule` (one extra clause). `lrgDlgState(npc)['exec_qst'] {quest, stage, at, cid}` is its persisted twin, written when the funcret confirms the pick played and read while newer than the last facts line (SendFacts is throttled 60 s and never sent while a session is open). The turn line gains `:executed=<Q>:<S>`. The recruiter's ml=0 line no longer carries "settled in your own dialogue with him, not in this talk" nor the learning counter: it states that the game records an enlistment only through his real entry in the dialogue menu and has recorded none, and the concrete prohibitions (test_dialogue 13 (k)).

## 10.23 [pt17-replies / owner addendum 11] NEVER EMPTY - the validator, the re-ask, the floor line, the next-turn rule

Captain Aldis, 2026-09-23 20:10:31: twelve seconds after "I would like to join the Legion" the player said "it says it's
locked" and grok-4.3 (openrouterjson, strict json_schema) answered `{"action":"Talk", ..., "message":"", ...}` - a
schema-valid JSON with no words (audit request 850). CHIM core has no floor for that: the stream loop never reaches a
sentence, `returnLines()` never runs, `processActions()` yields 0 commands, `call_llm_internal()` returns TRUE, the
`LLM_RETRY_FNCT` seam (main.php:2825) is consulted only for an INVALID reply and nobody had registered one, main.php:2830
only writes a log row, X-CUSTOM-CLOSE goes out. The glue logged `llm ... spoke=0` and had no rail either. 17 of the 322
replies logged since the log began (5.3 %) have `"message": ""` (research/pt17-replies.md section 3: 10 ordinary-speech
defects, 6 in-scene, 1 the glue itself asked for). `lib/lrg_replies.php` (registered from `functions.php` after the two
post-gates and again from `context_pre.php`, which runs on every request):

### 1. Three layers

- **Validator** (`$GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']`, lib/data_functions.php:5937, per streamed chunk, EVERY LLM turn -
  the voiced funcret turn included, where `FUNCTIONS_ARE_ENABLED` is false and no post-process hook runs). It judges only
  a COMPLETE decoded reply (`$GLOBALS['LAST_LLM_RESPONSE']`, set per chunk by every JSON connector): every schema-required
  key present, the schema's last property present, and `message` not the last key the model wrote (connector/__jpd.php
  closes a partial object with `"}`, so a mid-stream "" is not a verdict). Trimmed message "" on one of OUR turns -> the
  reply is rejected: CHIM marks the output invalid, discards it, `call_llm()` returns false and the retry runs. Let
  through untouched: a business pick the game answers itself (`lrgDlgWillEmit` - the real line plays and the mute would
  drop any line of ours), a scene / outro turn (log only unless `never_empty.in_scene`), and - with `reask` off - an
  action-bearing reply (a rejection would throw Follow / Come_Closer / Trade_Items away with the words).
- **Retry** (`$GLOBALS['LLM_RETRY_FNCT']`). `why` = `empty message` (our rejection), `no reply` (no decoded reply at all:
  connector error body, the 60 s receive timeout, non-JSON) or `invalid output` (a decoded reply CHIM rejected). For an
  empty message it RE-ASKS ONCE (`never_empty.reask`, default on): the rule "<npc> answers in words - the message field
  carries at least one short spoken sentence ... An empty message is not an answer." is appended to the last user
  message of `$GLOBALS['contextData']`, `call_llm()` runs again under `$GLOBALS['IN_FALLBACK_MODE']` (exactly what CHIM's
  own fallback re-entry sets, data_functions.php:5810; it is what skips processor/player_tts.php on the second pass;
  nothing of the rejected attempt was echoed, so nothing is duplicated), the context is restored. Her own words beat any
  line of ours (addendum 11). Only when the second reply is empty too (`empty message twice`), or on `no reply` /
  `invalid output`, does she say ONE floor line through CHIM's own `returnLines()` - TTS, `$talkedSoFar`, the chat
  eventlog row (the next prompt no longer shows two player lines in a row), the ScriptQueue echo, all before
  X-CUSTOM-CLOSE. If sentences were already spoken mid-stream (the invalid branch), nothing is added.
- **Hook** (last entry of `action_post_process_fnct_ex`, after `LRG_POSTGATE` and `LRG_DLG_POSTGATE`). It floors what the
  validator let through on purpose - an action-bearing empty reply with `reask` off, a reply whose `message` came last -
  BEFORE CHIM echoes the command lines (data_functions.php:6483), so "she says a line before starting anything herself"
  holds for CHIM's own actions. It never touches the action list, and it stays idle on an emitted pick (`do=pick` among
  the final lines, or `lrgDlgWillEmit`). After `returnLines()` the rail re-reads `lrgSpokenThisTurn()`: a line the
  transformer chain dropped is logged as not spoken and not remembered.

### 2. The lines, the memory, the note

- The floor lines are SPOKEN text, never prompt text: `never_empty.lines.question` (his words ended in `?` or began with a
  question word), `.statement`, `.trouble` (no reply / invalid output: she did not catch it - never a line that implies
  she heard and understood); each capped at `max_chars` (60), rotated per NPC so the same line is not heard twice running.
  Config `never_empty {enabled true, reask true, in_scene false, note_seconds 120, max_chars 60, lines}` (defaults in
  code, `lrgNeDefaults`).
- `lrg_memory.empty_reply {at, cid, n, why, line, i, kind, told}` - `n` counts every empty reply the rail handled (the
  re-ask that spoke included), for the owner's greps.
- Next turn (rules only): `lrgNeNote()` gives Phase 2's volatile guidance one sentence while `empty_reply.at` is within
  `note_seconds`, on the player's next speech / lrg_dlgtalk turn with this NPC, told once: "<npc>'s last reply carried no
  words at all. Whatever the player says now, <npc> answers in words: one or two short spoken sentences, then the action
  if any."

### 3. Prevention on the prompt side (rules only, section 0)

- `lrgDlgJsonTemplate()` appends "Never empty: at least one short spoken sentence, even when the action says it all." to
  `message`'s DESCRIPTION in the strict schema and to the text template - never a schema keyword (`minLength` would 400
  in strict modes). `dialogue.reorder_json_scope` (`always`, as D3 decided; `business` = the action-first order only when
  the turn carries a <business> list, a service, a follower verb, a fresh enlistment ask, an arrest or a list / next
  question) is the second lever after `dialogue.reorder_json=false`: since the reorder went live 10 of 100 action-first
  replies came back empty against 1 of 41 character-first ones.
- The <business> block no longer says "With the action leave message empty": request 706 (Beirand, 2026-09-23 04:43)
  picked a [commits] key with message "", the gate PARKED it and her confirming question - which IS the message - was
  never heard. It now says: say your one line in message even with the action; when the world answers for you that line
  is simply not played (the mute only ever silences a pick that is really emitted).
- 10.22: the carried faction wording (background mark, "answer what he says, in words").

### 4. Log

`never-empty npc=<n> action=<code> item="<i>" verdict=rejected|let-through (...)|log-only (...)|idle (...)` from the
validator / hook, and `never-empty npc=<n> action=<code> why=<why> said="<line>"` or `... reask=spoke chars=<n>` from the
retry. `grep never-empty lorerim_glue.log` is the count of turns the model returned no words.

### 5. Cost and limits

No new LLM call on an ordinary turn. On the ~5 % of turns the model returns no words: one more LLM call (about 1.5-2 s
under the MAIN lock) and, only if that fails too, one short TTS line. The `no reply` / `invalid output` branch is
speculative - zero "Invalid JSON Output" / "LLM didn't output anything" lines in chim.log so far - so it is gated on
`lrgSpokenThisTurn() === ''` and kept simple. A connector that never sets `LAST_LLM_RESPONSE` (all six JSON connectors
do) leaves the rail idle, i.e. today's behaviour. Owner-side levers, cheaper than any server code: try another model,
json_schema off (json_object) for a session, `reasoning.effort` above `none`, `dialogue.reorder_json=false`.

### 6. Tests

`test_gates` 36 (the validator on complete / incomplete / message-last / character-first / json_object replies, the
re-ask that speaks, the re-ask that fails -> one question line, a statement line, two consecutive empties -> two lines,
`reask=false`, the emitted pick vs the parked [commits] pick (request 706's shape), the muted shape, not-our turns, the
VOICED funcret turn with functions off, `no reply` / `invalid output` after spoken sentences, the scene turn, `enabled=false`,
the note); `test_dialogue` 13 (i) (the carried wording) and (j) (the JSON template clause, the <business> wording,
`lrgDlgTurnHasBusiness`); flow `31` (all of it through the real hook files, plus the barter shape); `16` (three
post-filters now).

## 10.24 [pt17-switches] AMBIENT SCENES - `sq=`, `sqj=`, `cal=`, `qst=` on the facts line; a scene actor who can still talk

Legate Rikke and General Tullius live in `CW00SolitudeMapTableScene`, a looping ambient scene the vanilla game lets you
talk through; in 508 any scene made the turn `on=0` / `list=none` (playtest 17, 20:12). The facts line now carries
`sq=` (the owning quest EditorID of her current scene, or `-`), `sqj=` (1 when that quest has an unfinished journal
objective), `cal=` (0-10 calibration rows answered) and `qst=` (quest:stage csv). A scene whose quest matches
`dialogue.scenes.ambient` (globs, default `*MapTableScene*`), with `sqj=0` and a facts line fresher than
`dialogue.scenes.fresh_seconds` (300), keeps the turn `on=1 ambient=1`: faction facts, locked facts and the recruiter
hand-off apply, but NO open - the game never opens a conversation on a scene actor (LRG_Dialogue.psc: "the game still
never OPENS on a scene actor"), a session's own scene flag, OStim and an open session are unchanged. Real quest scenes
(`sqj=1`) stay `on=0` exactly as before.

[pt18-quest, script 510] three more additive keys: `mqq=` (GLOB MQQuickstart as an int), `mq101=` (MQ101's current stage), `mq101c=` (1 when MQ101 is complete - a completed quest leaves the PO3 sweep, so `qst=` can never show it); `-` = unknown. `lrgDlgFactsFrom` stores them as ints; lrgFacRoad reads them (this NPC's line first, then another recruiter's cached line within factions.quest_entry.cache_seconds).

[v1.0 / 10.29] AMBIENT, NARROWED - AND NOW OPENABLE. `ambient` = fresh facts AND `sqj=0` AND (a `dialogue.scenes.ambient`
glob - now an allow-list SHORTCUT, no longer a requirement - OR the scene's owning quest has no journal row in the
index, `lrgDlgQuestIndexed`): Hulda's `DialogueWhiterunBanneredMareScene3`, `BardAudienceQuest` and
`DialogueGenericScene04`, which match no glob, are ambient; MQ102 and TG00 are not. The game's `iSceneGate = 1` now
OPENS on a scene actor unless the scene's owning quest shows an unfinished journal objective (the same test the facts
line runs for `sqj=`), and the server no longer refuses an `ambient=1` turn: the pre-LLM open fires on the same five
clauses as anywhere else (join, kind, root, toplevel, qrows - never on the bare quest-in-`q` marker) and `do=open`
carries `amb=1`. The road rule still guards a say-once greeter (Tullius / Rikke before Helgen: `lrgFacSayOnceClosed`).
An E-press session on an ambient actor arms `sj=0` and is driven. `cal=` on this line now counts 0-4 answered rows.

## 10.25 [pt18-words] NEVER FALSE - the words of a faction turn are judged against the game's quest stages

General Tullius, 2026-09-23 23:19:29-50 -04:00 (lorerim_glue.log 3192-3206, cids d33efd779 / d07f9e97d), the map table, `ml=0` (0 of 10 conversations measured), ambient scene, `open=0`, no command: "okay i guess you're the guy i talked to to join the legion" got "You want to join the Legion? Swear your oath here, or move on." and "i swear to uphold the imperial vows" got "Then you are a Legionnaire. Report to Legate Rikke at the training yard for your first orders." (output_from_llm.log Request IDs 857 / 858, x-ai/grok-4.3) - with the facts line at `qst=CW00A:0` before and after (3187, 3207), CW01A not running, CHIM's own quest engine off (no `CHIM_AI_QUEST_PROGRESSION` in conf.php, 0 `QuestProgression` rows). The prompt rule ("nothing has begun", twice) was ignored: CHIM core's `<roleplay_instructions>` tell the model to treat the director's prompts as established fact and build the next story beat on them, so a rule alone is structurally losing. No rail judged the words: the truth gate (10.17) drops only a money action after the words were spoken; the never-empty validator (10.23) tests emptiness. `lib/lrg_replies.php`, the never-false section, rides the same three seams:

### 1. What is judged, and what makes a claim true
- Scope: a player-speech / `lrg_dlgtalk` / rechat turn where the dialogue turn is on and this NPC has a recruiter or redirect role for a faction row (`lrgFacRoles`) or a faction ask is on the turn (role none included). Every sentence once. Never a claim: a question; a negation anywhere; a conditional at the start of the sentence or a clause; would / could / might / perhaps / maybe / one day ("when" mid-sentence is NOT a guard: "report to the training yard ... when the bell rings" stays caught).
- Classes, per row (`never_false.rows.<id>`; a faction row's own cells of the same name win): `member` (a rank, "one of us", "sworn in", "welcome to the Legion", "your oath is accepted"), `oath` (administered here and now), `next` (the recruiter's next step BY NAME - recruiter only; a redirect's / a guard's "speak to Legate Rikke in Castle Dour" is his own real line and is exempt), `orders` (an appointment the game never records: training yard, barracks, drill, muster, quartermaster, duty, "for your first orders", "report for duty", a time + a report verb, "your training begins", "clear out the fort" - FALSE whatever the stage and role, unless the row's `allow` map names the phrase for a stage: the Fort Hraggstad test at CW01A >= 1). A redirect / none never administers an oath or gives orders. Membership is judged by stage for every role.
- Truth: `rows.<id>.truth` = class -> quest -> minimum stage. legion (UESP Joining_the_Legion / The_Jagged_Crown_(Imperial)): `next {CW00A:10, CW01A:100, CW02A:10}`, `oath {CW01A:160}`, `member {CW01A:200, CW02A:10}`; CW00A 10 = spoken directly to Tullius, CW01A 1 = Rikke has set the Fort Hraggstad test, 100 = report back, 160 = take the oath, 200 = done (the quest stops running), CW02A 10 = the first assignment of a member. The stage-10 effect of `TIF__000D5136` is INFERRED (VMAD, Fragment_0, GetIsID only, no USSEP/AP override, UESP), not decompiled.
- Sources, freshest per quest: the facts line `qst=` (`facts.at`; CAPPED AT SIX quests on both sides - `QstCsvOf used < 6`, `lrgDlgFactsFrom count($qst) < 6` - and Tullius's sweep fills all six), `$turn['faction']['executed']` (10.22; wins this turn), `exec_qst` while newer than `facts.at`, and the row's recruiters' cached facts lines / `exec_qst` within `never_false.cache_seconds` (1800; quest stages are global). A quest ABSENT from every source is UNKNOWN: the claim passes with `verdict=unknown`, never stage 0 (a completed CW01A never appears in qst=), except where `rows.<id>.derive` knows better: CW01A / CW02A are at 0 while CW00A runs below 10, and also when our own pick has just set CW00A to exactly 10 (src executed / exec). Tonight: `CW01A:0(derived:CW00A:0<10)<160` - false. After a real enlistment (CW02A running): "Welcome to the Legion" is TRUE - never the mirror-image lie.

### 2. The three layers, chosen by the SHAPE of the reply (`lrgNfShape`, once per request)
- **reject** (`never_false.mode` reask; the action known and not a dialogue pick; or ANY turn functions are off for - a rechat - because no post-process hook runs there): `lrgNfValidate()` runs inside `lrgNeValidate()` before the never-empty kind check and judges the PARTIAL message per chunk. Timing, verified in the installed core: connector/openrouterjson.php:1038 sets `LAST_LLM_RESPONSE` (the partial message included) BEFORE returning the delta; lib/data_functions.php:5937 runs the validator BEFORE the chunk is appended and before `findFastSentencePosition`, which splits only on .?! followed by whitespace (chat_helper_functions.php:377-405) - so a completed sentence is in `LAST_LLM_RESPONSE['message']` at least one chunk before `returnLines` can take it, and a rejection in that chunk is in time; the LAST sentence never splits mid-stream and is judged once `lrgNeComplete()` is true (before the REMAINING DATA flush, 6002). A rejection sets `LRG_NE_REJECT {why 'false claim', class, said, fact, row, role}`; CHIM discards the stream, skips the flush and `processActions`, `call_llm()` returns false, `LLM_RETRY_FNCT` -> `lrgNeRetry()` takes the false-claim branch BEFORE its kind bail (a rechat must not fall silent) -> `lrgNfRetry()`: ONE re-ask through `lrgNeReask($k, lrgNfNudge())` under `IN_FALLBACK_MODE` - the nudge quotes the model's own sentence (single quotes: state, not an authored line), says "The game, not the story, decides what has happened ... his words do not make it so; nothing has begun", names the row's `how` and ranks, and what she already said ("continue from it without repeating it"); her words win (`reask=spoke`) - else ONE floor line from `never_false.lines.<role>` (a per-row `floor` cell wins; rotated per NPC; `max_chars` 100) through `returnLines()` AFTER any truthful partial (the never-empty "she already spoke" exit is not taken). Sentences spoken before the false one stay spoken (by design of the stream loop).
- **mute** (`mode` mute; or the reply's action is a dialogue pick (`LRG_DLG_GATE_NAMES`); or the action is not known yet - the character-first key order - with functions on): a rejection would throw the pick away with the words, so `lrgNfTransformer()` - chained ONCE per process onto CHIM's `TRANSFORMER_FUNCTION` in `lrgNeRegister()`; `context_pre.php` chains `lrgDlgTransformer` onto ours afterwards, so ours runs first - drops each false sentence inside `returnLines` (no TTS, not in `talkedSoFar`), the stream and the pick go on, and `lrgNfHook()` (first thing in `lrgNeHook`) speaks the floor line after the stream, idle when an emitted pick answers the turn itself. No second LLM call ever. A false tail in the message-LAST key order is judged by the hook (`verdict=late`) and floored after the words.
- **log** (`mode` log): judge and log only.
- Next turn: `lrgNeNote()` now carries the never-empty sentence and/or `lrgNfNote()`'s (once within `never_false.note_seconds`): "<npc>'s last reply claimed <an enlistment or a rank | an oath | a next step | orders, a place or a time> the game had not recorded; that sentence was not spoken. Nothing has begun: <npc> claims no enlistment, oath, rank, orders or next step the game has not recorded, and answers in words." `lrg_memory.false_claim {at, cid, n, why, class, said, fact, line, i, kind reask|floor|pick|log, told}`.

### 3. Prompt side, config, log, tests
- The recruiter's ml=0 line (10.22 above) and, on an executed turn, the second locked line + the rule's extra clause. `lrgFacRule` is otherwise byte-identical: test_dialogue 13(c)/(i) hold the redirect ask-turn block under 900 chars and it sits at 898.
- Config `never_false {enabled true, mode reask|mute|log, classes, max_chars 100, note_seconds 120, cache_seconds 1800, lines {recruiter, redirect, none}, rows {legion {ranks, next_names, truth, derive, allow, stages}}}` (defaults in code `lrgNfDefaults`; the other rows carry ranks only - an unverified row's recruiter claim is UNKNOWN). Seam `$GLOBALS['LRG_NF_TEST_OVERRIDE']`.
- Log: `never-false npc=<n> role=<r> class=<c> said="<sentence>" fact="<quest>:<stage>[(src)][<|>=]<min>[,...]" verdict=rejected|muted|late|log-only|true|unknown|exempt|idle ml=<0|1> cal=<n|-> ambient=<0|1> open=<0|1> type=<t>` (WHY enlistment cannot start by voice at the map table: `ml=0 cal=0 ambient=1 open=0`), `... why=false claim reask=spoke chars=<n>`, `... action=<code> why=false claim twice|false claim (muted)|false claim (late) said="<line>" [after=<n> chars she had already spoken]`. `grep never-false` = how often the model does it.
- Cost: a handful of regexes per chunk on faction turns only; on a false claim one aborted stream + one re-ask (~1.5-2 s under the MAIN lock) and, only if that is false too, one short TTS line. Limits: a negation or conditional anywhere makes a sentence "no claim" (false negatives tolerated); membership with no CW quest anywhere is UNKNOWN (passes, logged) until a recruiter's facts line is cached; the real `call_llm()` re-entry of the re-ask has never fired in production (the `mode=mute` knob and the shapes above are the rails).
- Tests: `test_gates` 36 (n)-(x) (49 checks: the three exact Tullius sentences streamed as partials, the 16:38 Aldis line, the guard's exempt redirect, seven truthful lines, executed / CW02A / unknown / cache / exec_qst / a fresher facts line, the nudge and both retry outcomes, the rechat carry, an ordinary turn, mode mute incl. the emitted pick, the three shapes, mode log, the note, config + rows); `test_dialogue` 13 (k) (14); flow `31` step 7 (14, through the real hook files: ev=facts before the lock, reject -> nudge -> floor after the partial -> the note -> a truthful reply -> three post-LLM filters unchanged).


## 10.26 [pt18-quest] QUEST ENTRY BY VOICE - `ExtCmdLRG_QuestEntry`, the road, the licence, the voiced success
Playtest 2026-09-23 23:19: three enlistment asks to Tullius / Rikke were words only by construction (ml=0, an ambient map-table actor the game never opens on, no model item) and the road was closed anyway (10.22). THE ROAD: a recruiter row may carry `closed[<name>] = {quest, complete_stage, line}`; lrgFacRoad decides open / closed / unknown from the facts line (`<quest>c=` / `qst=` / `mqq=` >= 7) or another recruiter's cached line. Closed -> the Helgen line; unknown -> nothing asserted, NOTHING sent (fail closed). THE EFFECT: `effects[<name>] = {entry, info, quest, stage, conds {isid, qdone[], notdone[], max, qnd{quest: stage}}, words, meaning, say, hint, journal}` - only for a line whose whole fragment is GetOwningQuest().SetStage(n), decoded (Tullius: alternateperspective.esp:206783 -> CW00A 10; Rikke has none this round). THE PLAN (lrgFacQuestPlan, pre-LLM, the player's own words, never the model's item / carried / rechat): recruiter, effect row, road open, CHIM quest engine off, the driver cannot click (ml=0 OR ambient OR the entry not on her list - with ml=1 and the entry listed lrgFacArbitrate's real click wins; for the map-table NPCs the click-free path is the ONLY path because lrgDlgMaybeOpen never opens on an ambient actor), facts younger than factions.quest_entry.require_facts_fresh (300 s), the quest on qst= below the stage (a fresher exec_qst counts), no facexec pending (pending_seconds 120), no qnd done. States: queued / closed / unknown / engine-on / stale / no-stage / already / pending / closed-intro, each with its own locked line (never silent). LICENSED = every qnd quest also on the facts line and clear -> `executed` set pre-LLM, the words lane's line rides, the OK stays quiet; UNLICENSED -> no flag, the line says the game has NOT confirmed it, and the OK is VOICED (lrgQuestEntryResult: LRG_VOICED.success, field 3 `@entry@OK: <meaning>`, directive from `say`) - never both. THE WIRE (ONE route, D1, appended by lrgFacQuestNet in Phase 1's post-gate; no D2 row): `<npc>|command|ExtCmdLRG_QuestEntry@ok=1;cid;npc;quest=<EditorID>;stage=<n>[;isid=<decimal low24>][;notdone=<n,n>][;max=<n>][;qdone=<EditorID,..>][;qnd=<EditorID:stage,..>];entry=<topic>;hint=<corner note>;x=<10 hex>;z=1` - every cond key optional and additive. THE GAME (LRG_Main.CmdQuestEntry, script 510) re-checks every condition on the live forms in this order: no quest/stage; the developer dry run (named with the page; the menuless dry run does not apply - not a click); the agent, loaded; isid (base or leveled base, low 24 bits); the quest (Quest.GetQuest, CW00A FormID fallback), running; qdone each IsCompleted (unknown = not complete) -> `<quest> waits on <miss> being complete`; GetStageDone(stage) -> a plain OK `already at stage <n> - nothing to do` (idempotent, quiet); max / notdone -> `<quest> is already past that (stage <n>)` (never lower); qnd -> `he has already had the other side's introduction (<pair> is done)`; then before = GetCurrentStageID, ok = SetStage(stage) (the very call the fragment makes), after; a refused set -> `the game refused stage ...`; success -> the corner note (hint=, bQuestHint, only AFTER the stage is set) and `OK: <quest> now at stage <n> (<before> before): <hint>` with `;qs=<after>;qj=<0|1>` (EscortHasJournal read back) on field 2. Her words on the funcret: 'that road is not open to you yet - there is something you must see through first', 'that step is already behind you', 'that line of mine is closed to you now', 'I am not the one who can take that up', 'I cannot do that right now'; the server maps the technical texts (lrgVoicedWhy) to 'his road into the Legion begins at Helgen, and that is not behind him yet', 'that step is already behind him', 'he has already been given the other side's introduction ...', 'that was not really him', 'it just would not work right now' - never a quest id, a stage, a global or 'the game'. Catalog: the inactive GlueQuestEntry row (is_activated 0, nobody, lrg_never, follow-up ON with LRG_QUESTENTRY_FOLLOWUP_PROMPT 'exactly what the game recorded or refused'); LRG_ACTIONS_VERSION stays 11 (the missing-row path installs it). State: Phase 2 `facexec {quest, stage, at, cid, x, lic, done}` (the pending guard; cleared on a refusal), `exec_qst {quest, stage, at, cid}` (written on the game's OK - the words lane's 9.2 contract); lrg_memory `sent` (src speech) + `qe_lic {cid, lic, quest, stage, say, meaning}`. Log: `dlg faction npc=.. asked=.. role=recruiter quest=.. stage=.. entry=<state> -> <why>`, `dlg faction net npc=.. ExtCmdLRG_QuestEntry appended (D1 only) ... licensed=<0|1> x=..`, `questentry OK ...` / `voiced QuestEntry OK ... (no licence was given pre-LLM)`; game: `questentry <quest> stage <n> applied|refused - <why>`. Config: `dialogue.factions.quest_entry {enabled, pending_seconds, require_facts_fresh, cache_seconds}`. Stands down with CHIM AI Quest Progression (lrgDlgQuestEngineOn); idle under SHARMAT (Phase 1's gate is not registered there). Tests: test_dialogue 13 (l), test_gates 37, flow d65, test_prompt_index 5c additions. Not built this round: Rikke's stage 20 by voice (needs stage 10 first, the alive-count branch 0D5133 -> 21, an explicit yes), the CWScript stub for CW.PlayerGotIntro (CW00B:10 stands in), the Stormcloak rows.

[v1.0 / 10.29, spec S2.3] THE OPEN FIRST; THIS ENTRY IS THE FALLBACK UNTIL THE OPEN IS PROVEN IN GAME. lrgFacQuestPlan changes ONE condition: "the driver cannot click" = ml=0, OR a list of hers is known and the entry is not on it, OR (ambient AND the open is impossible: io off; the game refused the last do=open for this NPC within 120 s - `open_refused {why, at}`, written from that funcret by lrgDlgTopicFuncret; no click verified on this install yet, clicks_ok 0; or out of reach, beyond open.max_distance or in combat). An ambient join ask with the road open and the open possible (her list carries the entry, or no list of hers is known yet) takes the new plan state `open-first`: this section sends nothing and the pre-LLM open (10.29 section 4, clause 1) brings her real list up instead; one sentence never gets both carriers (the open stands down when this entry is queued for it). Road, licence, voiced OK and CmdQuestEntry are untouched in gate A. GATE B decides on evidence: lrgFacQuestPlan/Param/Net/EffectFor, the legion effects cells, lrgQuestEntryResult, the catalog row and CmdQuestEntry (-> a three-line quiet OK stub) are deleted only if a `do=open amb=1` on Tullius after Helgen produced `opened sid= origin=glue` and the AP line on his list; otherwise this section stays.

## 10.27 [pt19-purchase] BUYING FOOD AND DRINK BY VOICE - `ExtCmdLRG_Buy`, the stock on the snapshot, the price the game asks, the price rail
Hulda, 2026-09-24 02:53-02:55: "i'll have uh l please" / "uh some beer" / "yeah thank you" -> "That'll be one septim for a bottle of Honningbrew" and no bottle. Three defects: no lane recognised an order; nothing could hand over stock from the inn's merchant chest (the DLL ships her PERSONAL inventory per utterance and Give_Item_To is bound to it); no rail judged the price. ONE route, D1 (the 10.26 shape), no CHIM core edit.
THE WIRE (snapshot, additive; LRG_Profile.MarketFacts, before class=): `vend=0|1` (Faction.IsVendor and not not-sell-buy, one PO3 GetVendorFaction call for a non-vendor); for a vendor `room=<GLOB RoomCost as int>`, `bp=<fBarterMax>,<fBarterMin>,<fBarterBuyMin>,<speech>,<spmod>,<sppow>,<mult>,<mods>` (mods = `<name>:<value>/...` of the held price perks, `-` for none), `stock=<hex8 runtime id>:<name>:<unit price>:<count>:<value>,...` (<= 6 drinks then <= 8 food from the merchant chest, else her own inventory; names cleaned of ; = : , @ | " and clipped to 24). Cached 60 s per NPC in StorageUtil (LRG.mkt / LRG.mkt.at); CmdBuy clears it after a sale. `pgold` (already on every snapshot) is the purse.
THE PRICE MODEL (LRG_Profile.BuyMods / BuyMultFrom / BuyPrice; mirrored by lrgMktModelPrice): f = fBarterMax - (fBarterMax - fBarterMin) x min(Speech,100)/100 (UESP Skyrim:Speech); mod starts 1.0; multiply entries in priority order (Gift of Gab 0.95, Sign of the Lover 1.15, Silent Dovah 2.0, DVS Weary 1.5 / Debilitated 2.0, Painful Regrets 0.75, Bard 0.9, King's Heart 0.85, Vigilant gold-cat 0.5, Lover's Insight 0.9 opposite sex, Arch-Mage 0.9 in the College, Skill Boosts x(1 - 0.01 x SpeechcraftMod), power boosts x(1 - 0.01 x SpeechcraftPowerMod), Merchant 0.8, Silver Tongue 0.9); Requiem's Haggling ADDS -0.01 x Speech at priority 101; mult = max(fBarterBuyMin, f x mod); price = floor(value x mult + 0.5). The add-vs-multiply order is an ASSUMPTION (CK wiki unreachable): the server logs `buy price drift <name> game=<p> model=<q>`, the game recomputes before every sale and re-quotes when it moved. Dynamic Pricing Framework is NOT modelled: the locked line says "at the price <npc> asks" (dialogue.market.wording) and the game charges exactly that or refuses. Checked: Ale 5 x 3.85 = 19 (the owner's "like 19"), the meads 10 x 3.85 = 39.
THE SERVER (lib/lrg_market.php; config dialogue.market): recogniser = exact name run > distinctive name word > class word (beer/ale -> ale; mead with two meads -> ask; something to drink -> every drink); digits and number words for counts; guards: negation within the clause, a person after the frame ("can i buy | you a drink"), a price question -> quote, a Phase 1 intent -> never an order; a strong frame ("i'll have", "pour me", "a bottle of") is an order alone, a weak one ("i want", "can i get") only with an item or class. "yeah thank you" confirms ONLY the glue's own one-item offer (lrg_memory buyask, 90 s, kept only when her line named it). States: none | not-vendor | no-stock (-> the 10.15 barter window, never a refusal) | ask (<= 3 items with prices) | quote | pending (120 s) | unaffordable | queued. Locked facts (class `stock`, market turns only): "what <npc> sells, at the price <npc> asks: Ale 19 septims (8 left); ..."; "a room here costs 25 septims" (innkeepers, class price); "<player> ordered N <item>: T septims in all"; the purse on money turns. Directive <player_request> per state; hide Give_Item_To on every buy turn and OpenInventory on a queued one; the barter net stands down ('voice buy'). The net (lrgDlgPostProcessActions after lrgDlgServiceNet, before the corner hint / calibration): ONE `<npc>|command|ExtCmdLRG_Buy@ok=1;cid=<cid>;npc=<name>;item=<hex8>;n=<count>;price=<LOCKED unit>;name=<short>;x=<10hex>;z=1`, once per request, never beside a trade / hand-over action of hers, WITHHELD when the truth gate flagged a price in the same reply. [v1.0: the calibration candidate 'buying' is gone with the automatic calibration session, 10.13.] Turn line ` buy=<state>[:<name>@<price>[xN]]`.
THE GAME (LRG_Main.CmdBuy, script 512; catalog row GlueBuy inactive, lrg_never, follow-up ON): dry run (names the Diagnostics page) -> bServiceDialogue:Services -> quiet mode -> agent -> loaded -> hostile / combat / an arrest / an OStim scene (an AMBIENT quest scene is no reason) -> vendor faction -> hours (GLOB GameHour vs GetVendorStartHour/EndHour) -> Potion.IsFood -> the chest with >= n, else her inventory ("I am out of X" / "I only have C of those left") -> BuyPrice recomputed (one log line with every input; `;unit=` on the refusal "it is N septims now, not M - say the word and it is yours") -> the purse ("you cannot pay T septims with the G you carry") -> BuyWaitForLine (fSayFirstWait / fSayFirstMaxWait, SceneTalk page) -> src.RemoveItem(item, n, false, player) (the vanilla "<item> added" receipt), player.RemoveItem(gold, total, true, src), IdleGive -> `OK: <n> <name> handed over for <total> septims` with `;unit=;paid=;left=;stock=`. Funcret: OK quiet (buyadj overlays the cached row: count/price at or after the snapshot's time); Error voiced (lrgMktVoicedWhy). Technical reasons (the closed list grows): `unknown buy request`, `dry-run mode is ON ... nothing was sold`, `the feature is switched off`, `quiet mode: ...`, `<name> is not here`, `hostile`, `combat`, `an arrest`, `a scene with you`, `no vendor faction`, `vendor faction not-sell-buy`, `closed (vendor hours H1-H2)`, `unknown item form <hex8>`, `she is out of <name>[ (has C, wants N)]`, `the price is N septims, not M`, `not enough gold (has G, needs T)`.
NEVER FALSE (10.25 extended): class `price` - a price frame around digits or number words, or a number at the start of a clause, is judged on a vendor turn with fresh stock / a room price (pseudo-row 'vendor', prices only); allowed = the stock prices, the order's total, live entry costs, the room, the bounty, the purse; false -> re-asked once (her sentence quoted, the list's price named), then `never_false.lines.vendor` ({item} / {price}), the next-turn rule "claimed a price the game had not set"; the buy net is withheld; lrgDlgTruthCheck reads number words too. Log: grep 'buy ' (buy plan / buy net / buy result / buy price / buy price drift / buy ask), 'never-false ... role=vendor class=price', 'inn: CHIM RentRoom cost_gold'.
THE ROOM: CHIM's RentRoom charges its own cost_gold (default 10); the game's RoomCost is 25 here. Warned once per game session (dialogue.market.warn_rentroom_cost); dialogue.market.align_rentroom_cost (shipped FALSE) writes CHIM's row from the live global.
=== 10.17 table, two new rows === | stock | the snapshot's stock= (fresh <= dialogue.market.fresh_seconds), on market turns only | "what <npc> sells, at the price <npc> asks: ..." | ; | price (room) | the snapshot's room= (innkeepers) | "a room here costs N septims" |
=== 10.15 note === 'something to eat' / 'something to drink' left the inn kind (pt19-purchase): an order of food or drink is the market lane's (10.27); a vendor with no fresh stock on the snapshot falls back to this section's barter window after her line.


## 10.28 [pt19-helgen] QUIET MODE - the glue stands aside for scripted intros (script 511; research/pt19-helgen.md section 7)
The GAME decides: LRG_Main.QuietOn() is true while a curated quest (LRG_Main.psc QUIET_QUESTS = "MQ101>=5": EditorID with an optional stage floor ">=<n>", comma-separated, at most 8 rows; an empty list means this default, never "quiet everywhere") is running, not complete and at or past its floor, AND MCM bQuietIntro:Quests is on (Menuless questing page, "Stay out of scripted intro scenes (Helgen)"; settings.ini [Quests] bQuietIntro = 1; a MISSING key reads 0 = off). Cached QUIET_LOOK_SECS (5 s): a member read between looks, then per row GetCurrentStageID first, IsRunning, IsCompleted - no PO3 walk, no GetCurrentScene (MQ101 shows no objective and runs no scene between stages 200 and 250).
While quiet the game: takes no snapshot of anybody (forced too - the server's adult / freshness rails fail closed by design), watches nobody (no SnapTick, no lrg_initiative tick), holds nobody (ConvRefuseReason "a scripted intro"), lays no natural follow (ours comes OFF tracked NPCs - QuietFollowOff), repairs and sweeps no follower, refuses ExtCmdLRG_Escort do=follow|wait ("a scripted intro" -> "<name> is in the middle of the Helgen business and cannot leave it"; do=release still runs - it only takes the glue's own edits off), refuses ExtCmdLRG_QuestEntry ("not now - the Helgen business is under way and I will not take anything up until it is over"; err= "quiet mode: MQ101 stage <n> is running - the glue touches no quest while it does"). [v1.0] Every dialogue session arms read-only while quiet (`drv=0`, `ev=open quiet=1`; the server's read-only why is `quiet`), OpenBlockedReason refuses a glue open with "a quest scene is running", and the pre-LLM open stands down (the automatic calibration open it used to skip is retired). UNTOUCHED: CHIM's own agents, its scene refusal and its packages, the OStim stop key, the kill switch, the never-silent voice. (The emergency vanilla-menu key is retired in v1.0: nothing is hidden any more.)
Wire (additive): snapshot key quiet=0|1 after fv=; facts line key quiet=0|1 (ev=facts; while quiet the PO3 sweep is skipped, mqq=/mq101=/mq101c= stay). Server (lib/lrg_core.php lrgQuietPolicy, called from lrgFollowerPolicy before followers.enabled): while quiet=1 is fresh for the NPC (facts quiet_at within quiet.fresh_seconds 300, or the snapshot within snapshot_max_age_seconds) CHIM's LRG_MOVEMENT_ACTIONS (+ config quiet.hide) are withheld from the offer - an OFFER change, never a mute - the pre-LLM open stands down (the automatic-calibration candidate that used to answer 'quiet' is retired in v1.0), and one prompt_bottom line (quiet.line with <npc> / <quest> from quiet.names, injected by context_pre.php as lorerim_glue_quiet 905) carries the reason. Config block "quiet": enabled, fresh_seconds, hide, names, line.
Log: game "QUIET on: MQ101 stage <n> - no snapshots, holds, natural follow, follower repair, escort, quest entry, opened conversations or initiative until it ends (bQuietIntro:Quests)" / "QUIET off: MQ101 stage <n> - <the quest is complete | the quest stopped | switched off in the MCM | no curated quest runs | the quest fell below its stage floor> - the glue is back"; server "quiet: withheld <codes> from <npc> (MQ101 stage <n>, from the facts) - she answers in words; the prompt line carries the reason" once per spell (lrg_memory quiet_held), "quiet: released <npc> - no fresh quiet=1 from the game any more".
Tests: tools/test_gates.php section 36; tools/flows/scenarios/32_quiet.php; tools/test_mcm_wiring.php (path a).


## 10.29 [v1.0 / pt19c, game script 513] THE VISIBLE MENU, VOICE-DRIVEN - `sj=`, `drv=`, the read-only clause, the three releases, the line-end grace, the talk key

Design: `research/pt19-menuless-v1-spec.md` rev 2 (S1-S4, S6-S10; its section 4 is the removal list). Behavioural contract:
`research/pt19c-interaction-model.md` (its F-resolutions are binding where they name the spec item they correct). Owner
page: `glue/OWNER_MENULESS_V1.md`. Lane notes: `research/pt19c-A.md` ... `pt19c-F.md`. Every wire item is additive in both
directions (the table at the end of 10.4): an older server or an older game script sees what it saw before.

**What changed, in one paragraph.** The glue no longer hides the vanilla Dialogue Menu. Whether the glue opened it
(`do=open`) or the engine did (an E-press, a forcegreet, a blocking branch), the real list is on screen, the player may
click it at any moment, and when his words name a line the glue clicks it. "Menuless" now means he never HAS to click;
nothing is hidden, guarded, parked or timed out behind a hidden menu, so no emergency key is needed. Gone (spec section
4): Guard / Hide / HideCursor at arming, PENDING and its 45 s watchdog, the emergency vanilla-menu key, `MaybeResume` /
`ev=resume`, `ev=unhide`, the assisted rail, the re-walk (`bi` / `rw` / `rwd`), the dry run's MCM-Helper auto-clear,
the automatic calibration session and six of its ten rows, the `lrg_dlgtalk` "again" turn, and `<she_may_raise>`.
`LRG_DlgUI`'s hide primitives stay compiled and unused (a possible opt-in later).

**1. Who drives a session (game, `LRG_Dialogue.Arm`).** A session is one open Dialogue Menu on one speaker. The driver
DRIVES it (reads the list, forwards it, clicks on the server's decision) when all hold: `bMenuless` on; the speaker is
not lethal (`ClassifyCrit`: a guard with crime gold in her hold, or an arrest topic on her list -> crit 2); Smart Talk
is safe; the SWF family is known; quiet mode is off (10.28); and EITHER the speaker is not in a journal scene OR
`bDriveSceneMenus = 1` (settings.ini, ships 1, no MCM control) AND `LRG_DlgProbe.CalRouteLive()` (the click route
proven by a real click on this install, section 3). **A journal scene** = `GetCurrentScene() != None` AND
`EscortHasJournal(scene.GetOwningQuest())` (an objective displayed, not completed, not failed); the facts line's
answer for the SAME scene of the same NPC is reused when it is < 30 s old (0 natives). `OpenBlockedReason` at
`iSceneGate = 1` uses the same test - never PO3's alias sweep (Hulda is a BQ01 alias while she stands in her patter)
and never a bare `GetCurrentScene()` - so the bard's audience, tavern patter and the map table are ambient, and Irileth
at the door, Balgruuf's court, Arngeir and the sacrament are journal scenes. A session the driver does not drive
(`drv=0`: module off, smart talk skip, lethal, a journal scene before the route proof, an unknown swf family, quiet) is
READ - list forwarded, lines harvested - and never clicked. Arming log: `arming sid= origin= ... crit= scene= sq= sqj=
sj= drv= subs= dryrun= ...[ visible=<why>][ quiet=1]`. `StopDriving(why)` replaces the hand-back: log `stopped
driving why=<lethal|combat|scene|read-failed|unverified|close-failed>`, state MANUAL, one `ev=stopped` (10.2), a corner
note only for lethal. After the decide window the driver waits in HELD (it polls `EntryCount` and the harvested
subtitle only; a stamped pick resolves against the held arrays; a changed count or a new line re-reads the layer). The
poll is 0.1 s in CLICKING / RESPONDING, 0.5 s in MANUAL / SUSPENDED and 0.25 s otherwise, with at most three natives
per poll outside the click window (plus a combat look once a second while a list waits).

**2. The server's view (`lrgDlgOnEvent`, `lrgDlgReadOnlyWhy`, `lrgDlgDecide`).** `sess.sj` = the game's `sj`, OR
`scene=1 && lrgDlgQuestIndexed(sq)` (the index holds journal rows for the scene's quest: Brynjolf's TG00 pitch has
`sqj=0` in game but 31 journal rows, so it is a scene session here); an older `ev=open` without `sj` falls back to
`scene && !ambient`. **Ambient** is 10.24's narrowed definition. **Read-only** (`lrgDlgReadOnlyWhy` = `stopped | quiet |
vis | drive_scene off | scene-unproven`): the gate and `want=1` emit nothing (log `gate: read-only session why=<why>`);
`<business>` carries the plain list as facts (no T-keys, no bucket wording that presumes keys) and the clause "choose
it on the list yourself - I cannot pick for you here", so her words say it in the SAME turn; `TakeUpBusiness` is off the
enum; no funcret error turn is ever paid. crit 2 and an arrest keep `lrgDlgHandBackForeseen`'s "choose that yourself".
**The scene rail:** a `sj=1` session is driven only with `clicks_ok >= 1` (section 3) AND `dialogue.session.drive_scene`
(true) - THE kill switch, one config line, no rebuild; inside it every class and rail of a free-standing session
applies. **One decision:** `lrgDlgDecide($t, $item, $st)` is the gate; `lrgDlgWillEmit($t, $item)` is its
side-effect-free twin (no state write, no log line: `LRG_DLG_SILENT`), so the TTS mute (`lrgDlgTransformer`) and the
never-empty let-through (`lrgNeWillEmitPick`) agree with the gate on every input - a refused pick is never muted. The
`<business>` state wording says the list is on screen only while the session is open; a cached root says "things he
could raise with <npc> (her list is not open now)"; "his" / "her" follow the snapshot's sex.

**3. Calibration, the route, the stage rail (spec S3; `LRG_DlgProbe`, the `*install*` row).** `CalMissing()` = four
PASSIVE rows, `cm` (counting), `rm` (reading, mode 3), `fam` (layout) and `st` (Smart Talk settings), written on the
first menu of ANY kind (an E-press counts); `timer x1 apd ms3 tail col row actms` are measurements, never gates. The
red -> green edge logs `CALIB GREEN 4 of 4 learned (<how>) ...` and shows the corner note "the dialogue menu is learned
(4 of 4)" once per install (store key `gnote`; Forget re-arms it). **Route by doing:** `route` starts from the `fam`
table; the first click whose RESPONDING signal is a real proof (the list clears, or a menu opens on top) writes
`CalSet("route", r, "live", 1)` and logs `CALIB set route src=live route=<r> was=<w> n=<n> proven=<r> clicks=1
npc=<who>`; `ClickResult -1` twice, or no signal 9 s after a click on route A, flips the route - never one forced by
`iClickRoute`, never one already proven live. `CalRouteLive()` = `route > 0 && rproof == route`. **`clicks_ok`**
(server, `*install*` row, `lrgDlgClicksOk`): +1 for each `ev=result ok=1` of a driven click (auto-advances included; the
`result` log line prints `clicks_ok=`); `ev=calib reset=1` clears it (10.13). **The stage rail**
(`dialogue.session.stage_rail`, true) while `clicks_ok == 0`: only an INDEXED line of class plain / back with
`scripted=0`, `cost=0`, `kind=''` and `goodbye=0` is clicked - in the gate, on `want=1`, and as the pre-LLM open's
predicted row (F19). The rest is covered by ONE `<business>` line naming the keys that pass ("Until he has asked me
something simple I can only pick T1, T4; for anything else say: I have not picked a line for you yet - ask me something
simple first, like "<a line that passes>", then I can pick this one") or, when no key passes (U7), "I cannot pick any
of these for you yet - choose this one yourself, this once". The gate logs `gate: stage rail`; the game shows a corner
note once per session (`do=noop` with `note=` and `kind=rail`: the game's `kind=rail` branch shows it once per `sid`, and
"Tell me when she has something" (`bQuestHint`) does not silence it - it is no quest hint; the pt19c final fixer moved the
server off `kind=hint`). **The dry run:** `bDlgDryRun` ships 0 and is never forced or auto-cleared. While the four rows are not
all known, `StepClicking` answers `Error: still learning the dialogue menu - the next conversation of any kind measures
it` (or `... - another conversation will not finish it: <why>` when `CalLearnable()` is false), logs `WOULD CLICK` and
leaves the list on screen; with the owner's toggle on it answers `Error: the menuless dry run is on (Menuless
questing page)`. `MenulessLive() = bMenuless && !bDlgDryRun`, so `ml=1` is the normal state now.

**4. Opening (spec S2; `lrgDlgPreOpen`, `lrgDlgBusinessMarker`).** On a player speech turn with no open session for
that NPC, with `io` on, `ml=1`, not quiet, no `open_refused` within `open.refused_seconds` (120), within
`open.max_distance` (200) and out of combat, and no voice order (10.27), queued 10.26 entry or escort order owning the
sentence, the NARROW marker's five clauses run cheapest first; the hit is logged `open marker=<join|kind|root|toplevel|
qrows> row=<info_key> npc=<npc> pre-LLM - do=open queued (D2) ...`, a miss as `open npc=<npc> pre-LLM: none - <why>`:
1. a faction join ask;
2. a service KIND phrase (`services.kinds.*.phrases`, never a bare service word, never a bare "a room"), vetted by
   `lrgDlgKindMayOpen`: inn / barter only on a vendor, follower only on a follower, carriage / ferry / train only on a
   job faction named in `open.kind_factions` (her snapshot's `fac=`, faction EditorIDs) - verified read-only in the plugins by
   the pt19c final fixer: `CarriageSystemFaction` (every vanilla / Dawnguard / CFTO driver) and `KmodCarriageFreeFaction`
   (CFTO's three new drivers); `DLC1FerrySystemFaction` and CFTO's `KmodFerryRoute1Faction`-`KmodFerryRoute4Faction`;
   `JobTrainerFaction` (every skill trainer, never `JobAnimalTrainerFaction`); an NPC without one gets that kind after
   her line only; crime never;
3. her cached root >= 0.5 (on the similarity tier his words must also share a meaning word outside the frame words -
   tell, about, know, think, like, want, need, got, get ...);
4. a VERBATIM top-level prompt of this load order with >= `open.toplevel_min_words` (3) strict meaning words, carried by
   at most `open.toplevel_max_topics` (5) of HER topics, never on a companion, and only a row her list can carry: her
   quest (never a town's shared journal-free Dialogue quest such as `DialogueWhiterun`) or an editor id that names her;
5. her JOURNAL-quest rows (`lrgPromptRowsForQuests`, capped at `open.qrows_cap` 300, cached per NPC), matched as a
   list (`lrgDlgMatchText`, never a short containment) and passing EITHER test: >= `open.qrows_score` (0.55) with >= 2
   shared strict words, OR (capability map U5) a hit of tier `exact` or `contain` at score >= 0.85 with >= 1 shared
   strict word - so a short verbatim quest line with one meaning word opens before the model: "I have your shield"
   (Aela, C00), "What are your orders?" (Irileth, MQ104), "Where are we headed?" (Delphine, MQ106) all give
   `marker=qrows` (walkthrough W.open.u5.*). A negation clash with the row refuses either way. It stays q-scoped: "who
   are you" to Hulda opens nothing (W.open.u5.hulda).

Never the bare "a quest this NPC is in"; the post-LLM open on the MODEL's item (`lrgDlgMaybeOpen`) keeps the wide
marker. At `clicks_ok 0` only a row that passes the stage rail opens. `do=open` carries `ask=<his words, <= 60>` and,
on an ambient actor, `amb=1`; the road rule refuses any open on a say-once greeter whose faction road is closed
(`lrgFacSayOnceClosed`, 10.26). The `do=open` travels on **D2 only** (10.6, last bullet): how fast a row queued in the
middle of the LLM request reaches the game is a first-evening measurement (section 13). **On that turn**
(`open_pending = cid`): the bridging directive ("The list of what he can raise is being brought up now. Say ONE short
line that does not settle the matter ...", <= 220 chars) REPLACES the state wording; hidden from the enum (before
`main.php` builds it, `lrgDlgPrepareTurn`): `OpenInventory` / `OpenInventory2` / `RentRoom` / `HireCarriage`, ALSO
`hide_in_session` (`EndConversation`, config default `lrg_dialogue.php` :135) and, on a `kind` open, that kind's own
`services.kinds.<kind>.hide` codes (a follower open hides `MakeFollower` / `Follow` / `FollowPlayer` / `WaitHere` /
`ComeCloser` / `ReturnBackHome`; `lrg_dialogue.php` ~2461 and ~2500-2506) - CHIM's EndConversation starts the DLL's
talk cooldown and releases packages while the vanilla list stays on screen (CHIM brief P13); the direct-barter net of
10.15 holds; the reply's key is HELD until the fast path answered this cid and DROPPED when the fast pick already landed
(F15); the transformer mutes her bridge only when that fast pick landed in time (a fresh `last_exec` read, at most one
store read per streamed sentence). **The mute is a TEXT-time race** (CHIM brief C2 / P6): her bridging sentence is
judged when its text streams, but its audio reaches the game only after CHIM's synchronous TTS (~4.8 s into the turn in
the one timed sample), while the D2 open, the list and the fast pick may land ~2-3 s in; the game's click guard
(`isActorTalking`) cannot see a CHIM line that is not queued yet. When the mute misses, the likely order is her real
engine line FIRST and her CHIM word over it - unproven; the owner page asks for the order heard, and section 13 names
the AIAgent.log lines that settle it. The list arrives on `lrg_topics want=1` and is answered from his own words.
`lrg_dlgtalk` is never requested (10.3). A refused open (`Error:` on a `do=open` funcret) sets `open_refused {why,
at}` (`lrgDlgTopicFuncret`), which is what lets 10.26 carry an ambient join ask.

**5. Classes and the three releases (spec S4).** A line is a COMMIT when it has a `never_auto` override; is indexed
`scripted && goodbye`; is indexed `scripted` on a closed layer with >= 2 scripted siblings that end the talk, walk out
or branch away (a HUB question, whose `links` lead back to its own layer, is no commit and no sibling: Delphine's C2Horn
hub keeps C5 as its one commit); carries a `commit_tags` tag; costs >= 100 septims or >= 25 % of the purse; falls to
the unindexed fallbacks; or is `commit: true` in `config/lrg_dialogue_overrides.default.json` (both Oath4 rows; that
file also marks `APStartIntroDiaTopic`, `MessengerAlduinSkipImp` and `MessengerMQSkipImp` `never_auto`). Not a commit
any more: a walk-away flag and crit 1 - they guard LEAVING (the leave guard: no back line and a walk-away / `twat` line
-> `do=show kind=back` and "No line here backs out cleanly; leaving is his to do by hand, and it ends things with her -
tell him so."; `pos=-1` is never sent on such a layer). A SERVICE line (its own words ask for trade, training, a room, a
ride or a crossing) is never a commit by the scripted / goodbye / sibling heuristics - its price still makes it one. A
check kind the fail-safe merge lent from ANOTHER topic's row (Irileth's "I have news from Helgen ..." shares its prompt
with the gate guard's persuade row) is no check. An Invisible Continue line is graded scripted (U2). A commit is clicked
by exactly one of three releases; otherwise it PARKS and her question quotes the line:
- **(a) explicit** (`lrgDlgExplicit`, mode `explicit`, or `faction` for the exact join line): his own NEW sentence
  (newer than the last click for this NPC and at most `confirm.utter_window` 30 s old, or the sentence whose open
  produced this list) lands on THIS line at >= `confirm.said_line_score` 0.70 with margin >= 0.25 (an exact hit needs
  only > 0) and >= min(4, the line's tokens) tokens; or `lrgFacArbitrate`'s exact join line; or an exact slot with >= 2
  tokens. Never for meta, `never_auto`, crit 2 / arrest, follower dismiss / home, a cost of 100 septims or a quarter of
  the purse, a negation clash, or a bargain / condition in his words.
- **(b) bare yes** (`lrgDlgParkOrRelease`, mode `bare-yes`): a LEADING phrase of `confirm.assent_words` (one list, e.g.
  yes, yeah, sure, sure thing, i swear, alright, ok, okay, of course, i'm in, count me in, i suppose so, no problem ...),
  with a refusal checked FIRST ("yes, but not now" un-parks), a tail veto ("yeah no", "okay wait", "yes, if you pay
  me"), and her parking question must have NAMED the line (never after a two-candidate hint). When the model chooses no
  key on that turn, `lrgDlgBareYesLine` appends the parked pick itself (F27).
- **(c) single entry** (`lrgDlgSingleEntryRelease`, mode `single`): a closed, indexed layer with exactly one visible
  line - refusal first, then shape (a question never stands for a statement line), then a leading assent (on a commit
  single only with no foreign strict word after it), then content (>= `confirm.single_entry_exact` 0.85, with precision
  >= 0.75 on a commit; an unscripted single: one shared strict word or >= 0.65; a scripted one: the same shape and shared
  words). The oath's last line releases on "long live the Emperor", "I swear" or "yes", never on "yes, long live Ulfric".

Also: two lines within 0.10 of his words -> nothing is clicked and `<business>` gets "T<a> or T<b> fit what he said -
ask which, naming both."; a scripted line needs a shared meaning word on the similarity path (S4.8; `overlap=` on the
emit line); a service KIND on a root list with exactly one line of that kind is picked by kind once the matcher found
nothing (mode `kind`) - unless his words name a SIBLING of that line (another line sharing its words) by a word only
the sibling carries: "can I have the ATTIC room for the night" never picks "I'd like to rent a room." (the model decides). The rail order is unchanged (S4.9): hidden -> read-only -> resist-arrest -> follower
`never_topics` -> arrest-class -> freeze -> crit 2 -> meta -> stage rail -> pay & !afford -> check rails -> scoff-first
-> the two-step. Resist-arrest, arrest-class and crit 2 answer `do=show;kind=meta` on BOTH paths (spec 3.5): the gate and,
since the pt19c final fixer, the fast path (`want=1`) too - never a click, and the game stops driving that layer at once.

**Price lists, the days list and the chained sentence (capability map U3; 10.15's price-list rule).** A price list is
still exact-slot, longest-wins, and a price question executes nothing; the similarity matcher is not consulted. NEW in
v1.0, on a layer whose EVERY slot is a number of days (Xtended Stay: "1 day. (25 gold)" ... "7 days", which
`lrgPromptNorm` reads as `# day` and six copies of `# days`), `lrgDlgServiceSlot` hands over to `lrgDlgDaysSlot`
(`lrg_dialogue.php` ~6355 and ~6431): the number is read from the LIVE text (digits kept, the price tag stripped);
number words count ("one" .. "ten", "a" / "an" = 1, `LRG_MKT_NUMWORDS`, plus "single" and the STT "won"); a night counts as a day ("two nights" = 2
days); "tonight", "for the night", "a night" / "the night" = 1 day; the STT spellings "nite" / "tonite" are folded;
the negation guard ("not tonight") and the price-question guard ("how much for a night") stay, and "one day I'll come
back" names no stay. **The chained sentence:** the sentence whose click produced the list may still name its slot
(`lrgDlgUtterFor` `chain`, ~4001, within `confirm.utter_window` 30 s), so "I'd like a room for the night" is ONE
sentence and TWO clicks - the room line (`mode=kind`), then "1 day" (`cost=25`); a purse where 25 septims is a quarter
or more asks first (F29). When the first click was a PARK released by his "yes", the parked sentence is kept as
`chain_utter {text, cid, at}` (written on the release, ~4897; read for the next price list only, ~4162), so "take me to
Whiterun" -> her question -> "yes" -> the destinations -> Whiterun. Tests: `FE.hulda.room.chain` (purse 300 picks,
purse 80 asks) and `FE.hulda.room.days` (`tools/test_questline.php --first-evening`); `tools/test_dialogue.php` v29.

**6. The line-end grace and the talk key (spec S4.6).** On `want=1`, a closed layer with ONE visible line that is
indexed, `scripted=0`, `kind=''`, `cost=0`, not crit 2 and not goodbye (a walk-away flag is allowed: the oath lines) gets
`do=pick;...;z=1;adv=<ms>` - `auto_advance.grace_ms` (2500), or 0 for a continuer ("...", "Go on.", "And?", "Continue.",
"Then what?"). The game clicks it only after its ANCHOR:
- **the engine line** - CONTINUOUS blank subtitle for the grace AFTER a non-blank subtitle was seen on this layer (any
  non-blank read restarts the count, so does a CHIM speech event of hers; the progress timer is never an anchor); log
  `adv anchor: line seen at=<t> blank since=<t>`;
- **her CHIM answer** (`rearm=1`: the server re-emits the pick on the turn after he asked something in the breath and no
  pick was emitted, never after a refusal) - her voice has begun, `isActorTalking` has read 0 for 1.0 s with no new
  speech event of hers, then the grace; logs `adv wait: her line` and `adv anchor: her line ended, quiet since=<t>`.

**The talk key** `iKeyPushToTalk:Dialogue` (Keys page, "Your talk key (CHIM's push-to-talk)", default 29 = Left Ctrl,
CHIM's mapped key on this install; 0 = off) is registered like the leave key and costs one `OnKeyDown` per press and no
polling: a press after the layer appeared (after the request, for `rearm=1`) closes the waiting pick silently (`adv
cancelled by speech`) and the line waits for his words. Subtitles off -> the engine anchor is never tried (a continuer,
`adv=0`, still clicks once the menu reads ready); `bAutoAdvance` off -> every line waits; 30 s with no anchor -> `adv
expired`; a click by his own hand while it waits closes it (`pick overtaken`). `ev=result` carries `auto=1`. A
scripted single never auto-advances, and nothing else is ever clicked by a timeout.
**The key is CHIM's too (CHIM brief P9 / C3).** The glue's `LRG_Main.OnKeyDown` (~1267-1280) calls
`NoteSpeechToDriver(0)` on EVERY key-down of that key - a tap, each press of a double tap, a hold - and it does so even
while a paused menu is open (the talk-key test comes before the `IsInMenuMode` return), i.e. even when CHIM refuses to
record. CHIM acts on the same presses in its own `AIAgentPapyrusFunctions.psc`: `BeginVoiceHotkey` (:646-665) returns
unless `SafeProcess()` (:1228-1250: not `Utility.IsInMenuMode()` - Barter, Gift, Training and the other pausing menus -
and no Console / Crafting / MessageBox / Container / Loot / listmenu / text input) and `isGameFocused`; a HOLD of >=
0.35 s records (`recordSoundEx`, `UpdateChatHotkeys` ~743-758); a single TAP with no second tap inside 0.35 s calls
`AIAgentFunctions.stopAllDialogue()` - every NPC's current and queued CHIM lines stop, so in the `rearm=1` case his own
tap stops her answer (:654-657, ~761-764); a DOUBLE tap calls `AIAgentAIMind.StartWait` on the NPC in the crosshair and
shows "[CHIM] <name> will wait here" (`FinishVoiceHotkey` / `WaitForCrosshairNpc`, ~682-711). CHIM raises no mod event
for any of it, which is why the glue keeps its own `RegisterForKey`. CHIM's default key is unset (-1); 29 is this
install's mapping (AIAgent.log:432 "Using mapped key code: 29 -> 17"). Owner-facing wording (owner page, README keys
table): hold it to speak as always; a quick tap is CHIM's own hush; a double tap tells the person looked at to wait
here. The MCM help text of `iKeyPushToTalk:Dialogue` (`game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` line 113)
still says "It only tells her you are about to speak" - a hand-off, not a docs file.

**7. Failures are words - but not all in the same exchange (spec S7; CHIM brief C5).** The game's new closed-list
reasons (1.6) are mapped to her words by `lrgVoicedWhy` / `SayReason`. A pick that reaches an undriven live session
answers `Error: choose that one on the list yourself` (the old "that is not on the table right now" only for a line
NOT on the live list). A gate refusal that the voice gap skipped (afford / amount / words / retry / frozen) is told on
the next turn as "Nothing came of it: <plain sentence>" (`lrgDlgNoteRefusal`, `lrgDlgGroundTruth`); `ev=result
why=unverified` is told as "nothing came of that"; an `ev=stopped` is told once (180 s) as the list having been left to
him because a fight or a scene began. **Delivery, three facts (1.6 [v1.0]):** CHIM never voices an
`ExtCmdLRG_SelectTopic` funcret (follow-up off, `processor/funcret.php` ends without an LLM turn), so (1) a synchronous
game refusal of a pick - `Error: choose that one on the list yourself` included - reaches the owner only as the corner
note, never in her words; (2) a loop outcome (`ev=result why=`) and a skipped gate refusal reach her words on his NEXT
speech turn (`<what_just_happened>`); (3) only the foreseen clauses (read-only, stage rail, the leave guard, crit 2) are
said in the same exchange. And every SelectTopic funcret still passes pre-lock (`lrgFuncretVerdict` ~1070) and takes
the MAIN lock plus a full prompt build that `funcret.php` then discards - one per click, auto-advances included (CHIM
brief P11 / C7: budget MAIN-lock occupancy, not only LLM calls; measured on the first evening, section 13).

**8. Rewards (spec S6).** On a quest turn (`lrgDlgQuestTurn`: a journal quest of hers in `q`, or a Phase 2 result <
180 s old) `hide_reward` (GiveGoldTo, SpawnGold, SpawnItem, GiveItemTo, TakeGoldFromPlayer) joins `LRG_DLG_SVC_HIDDEN`;
the `reward` locked line ("the reward for this is what the world gives and nothing else ...") rides only when he
bargains (the `checks.stakes.reward` phrases) or a reward window is open; `lrgDlgTruthCheck` drops a surviving money
action whose amount nothing confirmed. **Gate B (v1.0.1):** `dialogue.checks.reward.enabled` (ships false) switches on
the bounded bonus - a real Speech check inside a reward window, `N = min(the named sum or half the cap, her purse, one
day of her wage, checks.reward.max_gold 500)`, once per quest, carried by `do=award;...;z=1;give=<n>` (10.5).
**[v1.0] What never-false does NOT cover while the bonus is off.** The `reward` pseudo-row is added to the truth
check ONLY while `checks.reward.enabled` is true (`lrg_replies.php` ~712), and it ships false - so in v1.0 no sentence
in which she promises extra septims on a quest turn is judged at all. What stands between her and "Very well - a
hundred septims, from my own purse" is the prompt alone: `hide_reward` (no money action on the enum) and the `reward`
locked line, which rides only when his words carry a `checks.stakes.reward` phrase. The words can be spoken and
nothing is paid - a false promise. The owner page says so (section 4, "A different reward") instead of "never".
Open for Lane B / the spec owner: on a quest turn with the bonus OFF, judge any promised amount as false (the widened
class Lane E measured in a scratch copy: d68 15/15) - wanted before gate B, and worth having in v1.0.

**9. Config (`dialogue.*` in `config/lrg_config.default.json`; its `_v1_readme` explains every key).**
`confirm.{said_line_score 0.70, said_line_margin 0.25, said_line_tokens 4, slot_tokens 2, assent_words,
single_entry_extra [go on, carry on], named_score 0.45, park_seconds 60, single_entry_score 0.65, single_entry_exact
0.85, single_entry_precision 0.75, utter_window 30, bare_yes true, single_entry true}`; `auto_advance.{enabled true,
grace_ms 2500, rearm true, continuer_words}`; `match.{scripted_needs_word true, service_kind_pick true, hint_gap 0.10}`;
`open.{narrow_marker, toplevel_marker, toplevel_min_words 3, toplevel_max_topics 5, qrows_marker, qrows_score 0.55,
qrows_cap 300, refused_seconds 120, max_distance 200,
kind_factions {carriage: [CarriageSystemFaction, KmodCarriageFreeFaction], ferry: [DLC1FerrySystemFaction,
KmodFerryRoute1Faction .. KmodFerryRoute4Faction], train: [JobTrainerFaction]}}`; `session.{talk_again false, stage_rail true,
drive_scene true, open_seconds 900}`; `services.kinds.{inn,barter,train}.phrases` / `not_after`; `scenes.ambient` (a
shortcut now); `truth.classes` + `reward`; `hide_reward`; `checks.stakes.reward`; `checks.reward.{enabled false,
window_seconds 180, max_gold 500}`; `reorder_json_scope business`. Every list in the JSON is the COMPLETE list (lists
replace, maps merge; `tools/test_services.php` fails a list that is not a superset of the code default). Removed, with
no reader left: `confirm.typed_skips`, `auto_advance.grace_seconds`, `assist.*`, `calib.auto.*`, `quests.initiative.*`,
`match.cont_depth`, `session.silence_seconds`, `session.lost_seconds`, `script_proxy_watch`, `quest_colour`,
`entries.more_words`. NEW file `config/lrg_dialogue_overrides.default.json` (section 5), deployed by the ordinary copy
of `tools/deploy_server.ps1`.

**10. MCM and settings.ini (spec S10 as built; `tools/test_mcm_wiring.php` is the gate).** Menuless questing page: NEW
"Let her carry on by herself when there is only one thing to say" (`bAutoAdvance:Dialogue`, on); "Menuless questing:
dry run (nothing is clicked)" ships OFF; "NPCs inside a quest scene" (`iSceneGate`) ships 1, "Unless a journal quest
owns it"; "Topics counted beyond that" (`iTailMax`) ships 16; `iCritical` and `bCrimeManual` are RETIRED (no setting
could change the lethal rule) and replaced by the text row "Guards, arrests and bounties: always yours to click";
`bQuestInitiative` / `iQuestInitiativeGap` are retired with `<she_may_raise>`; iEngineOpen, iBranchInput,
fSilenceTimeout, iHideMode, bHideCursor, bRewalk, iRewalkDepth, bHandBackNote, bResumeAfterChoice, the probe key,
bProbe, iProbePress, bIaccToggle and bQuestColour are gone. Keys page: NEW "Your talk key (CHIM's push-to-talk)"
(`iKeyPushToTalk:Dialogue`, 29); "Open vanilla dialogue (emergency)" is removed. Calibration page: only "Calibration
status" and "Forget everything it learned". settings.ini: `bDriveSceneMenus = 1` (no control); the `[Calib]` section,
`bDlgDryRunHold` and every retired id are gone. Status strings: "4 of 4 learned, route proven by N click(s)"; "4 of 4
learned, route not proven yet - the next line she picks for you proves it"; "learning: N of 4 - talk to anyone once
(...)"; "blocked - ...".

**11. Log (grep `lorerim_glue.log`; `GAME` marks the game's lines).** `open marker=` and `open npc=... pre-LLM: none -
<why>`; `emit npc=<npc> do=<pick|open|show|leave|noop> mode=<explicit|faction|bare-yes|single|kind|intent|key|
advance|...> pos= i= kind= cost= x= [overlap=] [adv=<ms> [rearm=1]] [amb=1] txt=`; `gate: read-only session why=`,
`gate: stage rail`, `PARKED`; `result npc= x= kind= ok= why= ... auto= clicks_ok= txt=`; `calib npc= src= gate= miss=
k=` and `calib npc=<player> reset=1 ...`; `GAME arming ... scene= sq= sqj= sj= drv=`; `GAME opened sid= origin=glue`;
`GAME clicked pos= origin= sj= i= route= result= kind= auto= [adv anchor: ...] text=`; `GAME stopped driving why=`;
`GAME WOULD CLICK`; `GAME CALIB GREEN 4 of 4 learned`; `GAME CALIB set route src=live`. The owner's first evening,
step by step with its lines, is `glue/OWNER_MENULESS_V1.md` section 3.

**12. Tests.** `tools/test_dialogue.php` v16-v30 (the gate, the twin, the releases, the marker); `tools/test_gates.php`
35v, 36v, 38v, 39-41 (the closed list, the voiced reasons, F14, S2.3, rewards); `tools/test_services.php` section 8
(the kind phrases - it also reads the owner page's `mode kind (<kind>)` rows); `tools/test_mcm_wiring.php` sections
7-11; `tools/test_latency.php` 6b (the pre-LLM marker, in ms); `tools/test_prompt_index.php` 5d;
`tools/test_questline.php` (spec section 3: the main quest and the guild openings over the real index; `--first-evening`
= the owner page's own sentences; `--words`, `--stage=B`); flows `d21c d26 d26b d50 d53 d55 d63 d66v d67 d68 d69`
(`d68` is PENDING until gate B).

**13. Release gates (spec 2.4).** **Gate A = v1.0 (this build):** S1-S5, S6.1, S7, S8 without `give=`, S9-S12;
`bDriveSceneMenus = 1`, `session.drive_scene = true`, `iKeyPushToTalk = 29`, `checks.reward.enabled = false`. Proof
before install: every suite green twice, `test_questline.php` green over the live index (the scene beats at both
`clicks_ok` values, `--first-evening`), `compile.ps1` clean, `test_mcm_wiring` green. **Gate B = v1.0.1, after one
green evening** (`clicked pos=` and a `result` line with `ok=1 ... clicks_ok=1` on a free-standing NPC; `CALIB set
route src=live`; a `clicked pos= origin=engine sj=1` at Irileth whose arming line reads `sq=MQ102 sqj=1`; a `closed
why=goodbye` after a driven click; the four oath lines whole; the count of engine-opened sessions in the log):
switches on `checks.reward.enabled` once `do=award` and `ev=result` have run, applies the Papyrus diet (the
`SendFacts` form cache, `QalCsv` every third line, the 13 MCM reads cached out of the snapshot) and decides 10.26's
deletion on evidence (10.26's last paragraph). Scene driving is not a gate-B flip: if the first evening shows a wrong
click inside a journal scene, the kill switch is `dialogue.session.drive_scene = false` - a config line, not a rebuild.
**The CHIM checks of the first evening (CHIM brief section 12 - they settle what v1.0 ships on UNPROVEN).** Keep that
session's `AIAgent.log` (`Documents\My Games\Skyrim.INI\SKSE\AIAgent.log`; the DLL starts it afresh at each game
start, so copy it before the next one) next to `lorerim_glue.log`; CHIM's own server log is `HerikaServer/log/chim.log`.
(a) **D2 latency mid-request** (10.6): the glue's `emit npc=<npc> do=open mode=open-pre ... x=<x>` line against
AIAgent.log `Pushed command,1,ExtCmdLRG_SelectTopic@...do=open...x=<x>` - one pair settles it; (b) **the audible order
at first contact** (section 4's race): AIAgent.log `Queueing new line - Actor: <npc>` / `Starting DownloadAndPlay`
against `GAME clicked pos=` and `adv anchor:`, plus the owner's own report of which he heard first; (c) **the abort
rate**: `Generation stopped because user_input` in chim.log - a reply killed by his next words runs no post-gate, no
emit and no re-arm (CHIM brief P5); (d) **the MAIN cost of click funcrets** (section 7): `Audit:Lock acquired by
funcret` in chim.log next to the next `Audit:Lock acquired by inputtext`; (e) `Function not found for` in chim.log -
actions CHIM dropped before any glue hook saw them. (a) and (b) need AIAgent.log; (c)-(e) need chim.log. None is a
gate-B condition; each decides a follow-up (how long the fast path waits for the open, the bridging mute, a pre-lock
`handled` verdict for SelectTopic funcrets).

**14. [pt19h r2] Hardening round 2 (2026-09-25, the cloud session; `research/pt19h-r2-problems.json` -> `research/pt19h-r2-resolution.md`).**
Server only (`lib/lrg_dialogue.php`, `lib/lrg_factions.php`, `lib/lrg_actions.php`, the config JSON); no wire change, no
Papyrus change, no floor moved. By rule:
- **Hand-over lines (G16).** A line whose stage direction gives an object (`(Give fragments)`, `(Give Auriel's Bow)`) carries
  `hand` = the object's words, kept OUT of its match norm. `lrgDlgHandsOver` reads his giving frame around that object ("here are
  the fragments", "here, take Auriel's Bow", "I give them back with honor", "here take em") - never a question, a refusal, a hedge,
  a deferral, a bargain, a negated object, his taking, or a keep ("I'm keeping the amulet", `lrgDlgKeepsIt`) - and
  `lrgDlgHandOverPick` picks the ONE candidate (two: the matcher's margin decides, else the first converging one). The fast path's
  step `hand` runs before the single step; the explicit test lets a hand-over through on its frame (mode `hand`); a keep refuses
  the single release (S4.5) and the words path.
- **The key-mode rail (grading P1, P12; G3).** With `dialogue.grading.converge_plain` (NEW, true) siblings that continue into the
  same topic are plain, so the model's T-key clicks them - but a plain line that still runs a script (scripted, an Invisible
  Continue) passes `lrgDlgKeyRailWhy` first: a refusal, a deferral, a hedge the line does not carry, a negation, a keep, or a
  refusal around the quoted line -> nothing, her words answer; a QUESTION of his the line does not ask, the line asked back, or a
  hedge around it -> the line PARKS and she asks, quoting it (S4.4), as the commit it converged from did. Check lines and service
  / pay lines are not this rail's (their own rails judge them).
- **LEAVE and the label (safety arch P4, P5).** LEAVE finds the real back-out on the ranked head AND the tail; `[leave]` is
  printed only on a line `lrgDlgRealBackOut` accepts.
- **Deferrals, hedges, back-outs, refusals.** `LRG_DLG_DEFER_RE` + "some other day / another day / next time"; "I guess / I
  suppose / I might" + "so / yes / not / later / can / could" hedge; the spoken back-out heads ("let's not", "better not", "not
  today", "some other time", "go away") are anchored to the lead (after a filler or a no); the "no" that opens no refusal is
  exempt ("no one should have it", "no joke, you can have it", "no wonder", "no kidding", "no matter what", "no idea"); "... or
  not" at the end of a QUESTION is his impatience, not a hedge (`lrgFacAskQualm`: "so can I join the Legion or not" still asks,
  and the second ask 30 s later is `pending`).
- **The same question (safety lang P4, G9).** `lrgDlgQuestionsSame`: the line said whole is the line; an echo compares the
  question sentences only (quantifiers are frame words); how / where and what / where find-get questions agree; the "is there /
  do you" split holds only when the "you" side asks her knowledge; the same question word about the same thing needs HALF of the
  line's content words in his ("what do they want with me" is "What do these Greybeards want with me?") and, on a question about
  two or more things, no content word of his own ("who's Gianna" is no "Who's the Gourmet here?"; an STT slip with the line
  word's first letter counts as that word). A vocative is stripped before the kind is read ("Kodlak, is that you?").
- **Statements, quotes, negation (safety lang P3, P5, P6, P10).** `lrgDlgDeclares` never on an assent, a flattened "you ... any"
  question, or a first-person need ("I need a room" is a request); `lrgDlgQuoteQualm` reads the raw run of the line, counts a
  "?" after the line only when the line does not end in one, and a deferral only right after the line; negation parity: "all
  most" / "near ly" folded, two questions compare only not / no / never, "but" opens a clause, almost / nearly reach two tokens,
  barely / hardly / scarcely negate only a stative, and a bare "never" after the subject negates its predicate as "didn't" does
  ("the courier never arrived" is "the courier didn't come").
- **The words path.** The verbatim line (his form of address included) is never refused - unless his "?" asks a statement line
  back; `lrgDlgWordsCarry`'s one-shared-word rule (G9) stands down on a QUESTION line when his other word only narrows its
  subject in an "about / of / on" tail, or both say "about <the same thing>" ("any rumors about the dragons" -> "Heard any rumors
  lately?", "sing me something about dragons" -> "Do you know any old ballads about dragons?"; "who is the Jarl's steward" is
  still no "Who is the Jarl?"); a one-word STT echo read as a PROTECTED line ("rent a groom") clicks nothing on his words alone -
  she asks, quoting it (S4.4), and his yes releases it; a report phrase carries an unprotected report line.
- **Reach (reach P1-P9).** The work ask is HIS request only: never a deferral, a hedge, a refusal or a take-back anywhere in the
  sentence ("I'll help you out later", "any work? actually no"), never a second- or third-person subject ("did you find any
  work?", "who's looking for work?"), never a help offer to somebody else ("can I help him"), never hiring or repairs ("I need
  work done on my armor"), never a musing ("I wonder if there is anything I can do"), never "got a job to do"; `reach.work.say`
  gained the commonest offers ("what can I do for you", "let me help", "any errands"), `not_after` grew, `entry` gained Urag's
  books line (two radiant starts on one list -> she asks which); on a closed layer only a radiant start whose phrase LEADS the
  line; the report pick never reads a question line, and a protected entry it returns passes the qualms (quote, hedge, deferral,
  bargain, negation - a report phrase with its own "won't" excepted); the kind pick refuses on a refusal, a deferral or a hedge,
  and on a "yn:it" question ("is this for sale?").
- **Single-entry (money G14, grading arch P2).** A `never_auto` / `fcommit` single, or one priced at `confirm.min_gold` or more,
  is never released by his words alone (step 6: the model asks, naming it); a hedge around a commit single parks it (the model
  asks, quoting it); a keep refuses it.
- **Parks (safety arch P3, P10).** A park written from his bare assent is released by his next assent; a refusal or a deferral
  around the parked line un-parks it; an echo or a hedge keeps it (she asks again); a question restates the park only when the
  line is itself a question.
- **Escort (reach G6).** `lrgEscortPlan` stands down for `follow` while her menu is driven by his words (a pick within 15 s, or
  an open session).
- **Enlistment (quest use P1-P3).** `lrgFacArbitrateWant` never clicks the commit on a question, an echo or a deferral, end to
  end (`tools/test_gates.php` (g)); a hedge or an echo makes her ask.
- **Config.** `dialogue.grading.converge_plain` (true); `dialogue.reach.work.{say, not_after, entry}` are supersets of the code
  defaults (`tools/test_services.php` checks it).
- **Tests.** `tools/test_gates.php` (g) end to end; `tools/test_dialogue.php` "[pt19h r2]" rows and its round-2 regression
  section; `tools/test_services.php` reach P1 / P3 / P5 / P6 / P8 / P9 rows; `tools/test_questline.php` plain / `--words` /
  `--first-evening` / `--first-evening --words` / `--extended` against the 06:00 baselines (`tools/fixtures/*_baseline.json`;
  every `accepted` ruling is listed in `research/pt19h-r2-resolution.md`). `tools/test_scene_index.php` and flow `[18]` need
  the MO2 profile and run on the owner's machine only.

### 10.29 addendum [v1.0.1, 2026-09-25] gate B ON - the bounded bonus ships enabled

- `dialogue.checks.reward.enabled` now ships **true** (code default `lrg_speech.php` and `lrg_config.default.json`): the
  owner asked to haggle a quest reward in septims. Everything in S6.2 / `give=` above applies unchanged; `false` in
  `config/lrg_config.json` is the kill switch (test_dialogue v27 proves it). No wire change, no `.psc` change - script
  513 already carries `CmdAward give=`.
- The never-false rail's `reward` pseudo-row (`lrgNfClassify`, `lib/lrg_replies.php`) also reads a sum handed over with no
  giving verb and no bonus word as a grant: a sum paid "from my own purse / out of my pocket", or a sum right after her
  assent ("Very well - a hundred septims."). Flow d68 is no longer PENDING: 15/15, including both false-promise rejections.
- `dialogue.market.align_rentroom_cost` ships true (it did since the pt19 ship; the readme text said off): CHIM's
  RentRoom Gold Cost follows the game's RoomCost global (25 here).
- **[v1.0.1, 2026-09-25 - the Helgen start by voice]** An overrides entry may carry `open_on` (phrase runs) and `open_when`
  ({facts key: {min, max}}): `lrgDlgOverrideOpen` runs as clause 1b of the narrow marker, before the kind guard (marker
  `override`, `row=<topic>`), a refusal never opens, and the stage rail lets an override open through at `clicks_ok 0`
  (the list on screen is the point; the click stays under the rail and the entry's class). Shipped for
  `APStartIntroDiaTopic`: `best room` / `finest room` / `best bed` / `nicest room` while `mq101 <= 4` (the intro not
  started; quiet mode's floor is 5). The inn kind gains `best room` / `finest room` / `best bed` for real innkeepers.
  Tests: test_dialogue v25 "[Helgen]" rows; questline `W.ap.start` (park on "hey I'd like your best room", never on a
  price question / refusal) and `F.open.ap.bestroom` (marker=override at clicks_ok 0, no vendor faction).
- **[v1.0.1, 2026-09-25 - the directors' final sign-off, `research/pt19-oversight-final.md`]** v1.0 as built: both directors
  APPROVED WITH NOTES. Gate B was refused as first switched on (D1, D2) and is fixed before it ships: **D1**
  `lrgDlgRewardDeclines` (lrg_speech.php, read by `lrgDlgRewardBargain`): the clause holding his ask phrase is no bargain when
  it negates his want before the phrase, defers (later / maybe / think about), states sufficiency or a waiver (more than
  enough, keep the), or is an information question about the reward (is there / what is / did you already give); a
  proposal-shaped question ("don't I deserve more?", "can you sweeten it?", "what's in it for me?") and "that's not enough"
  stay bargains - test_dialogue v27 "[D1]" (14 declines, 8 bargains), flow d68 (the decline at Balgruuf). **D2** the rail's
  reward pseudo-row (`lrgNfClassify`) reads any giving frame + money as a claim, with or without a bonus word ("I will give you
  200 septims", "Here are fifty septims", "Take these..."), never money she denies ("no more gold"), and `lrgNfVerdict` judges a
  LATER payment false whatever the sum ("you'll have your N septims tomorrow") - flow d68 "[D2]" rows.
