# pt19c - THE MENULESS INTERACTION MODEL (v1.0 behavioural contract)

2026-09-24, the menuless interaction architect. Distilled from `research/pt19-menuless-v1-spec.md` rev 2 (S1-S7, S11,
3.4) and checked against the tree as it is today (script 512, server 0.5.7+pt19 with quiet mode and buying). Read-only
for the glue: nothing was edited. Tree corrections that apply throughout: Lane C bumps `LRG_Main.CurrentVersion` 512 ->
**513** (the spec says 511); the spec's new PROTOCOL section "THE VISIBLE MENU, VOICE-DRIVEN (v1.0)" is **10.29**
(10.27 = buying, 10.28 = quiet mode).

**Status.** Sections 1-3 restate the spec as ONE machine; where they add a rule it is marked `[F<n>]` and justified in
section 4. Section 4's resolutions are BINDING for the lanes unless the spec explicitly says otherwise. Function names
are the anchors; the spec's line numbers have drifted by 0..+40 since Phase A (e.g. `lrgDlgLayerIsOwnConfirmation` is at
:2874, `HasActiveJournalQuest` at :4135, `EscortHasJournal` at LRG_Main :2809).

**Paths** (who decides): **P** = pre-LLM (`lrgDlgPrepareTurn`, the player's speech turn, before the model runs); **W** =
the fast path (`lrg_topics want=1` -> `lrgDlgAnswerWant`, echoed D1 ~0.5 s and D2 on the DLL's 5 s poll); **G** = the
post-LLM gate (`lrgDlgPostProcessActions` -> `lrgDlgGateItem`, the model's item: a T-key, words, or LEAVE); **E** =
game/engine event; **K** = the talk key (`iKeyPushToTalk`); **T** = timer. **Silent close** = funcret `OK: Noted.` +
`XPush(x)`, no corner note [F5, F26]. `last_click`, `utter.cid`, `want_none`, `HELD` are new names defined in section 4.

---

## 1. The conversation state machine

### 1.1 States

| id | state | game (`dlgState`) | server (`lrg_dialogue` row) | what the player has |
|---|---|---|---|---|
| N0 | no session, nothing cached | IDLE | `session.state` closed (or open > 900 s old [F23]); no fresh `root` | free talk with CHIM |
| N1 | no session, root cached | IDLE | closed; `root` <= 1800 s, same loc, same qsig | free talk; she "knows" her list |
| OP | open pending | IDLE (do=open not yet polled) | `open_pending = cid`; `do=open` in responselog (D2) | her bridging word |
| OG | opening | OPENING (`DoOpen`: voice keeper <= 3 s, Activate <= 3 x 1 s) | unchanged | nothing yet |
| AR | arming | (inside `Arm()`) | receives `ev=open` | dialogue camera, greeting starts |
| RD | list read, decision asked | READING -> DECIDING | `session` open, `want=1` being answered | the list on screen |
| HD | layer shown, nothing outstanding (**HELD**, the v1.0 name for "stay LISTENING") | DECIDING/LISTENING idle [F10] | open, `list=pending` for the next turn | the list; he talks or clicks |
| PK | asked once / parked | HELD | `parked = {norm, said, req, at, expires +60 s, hint}` | her question quoting the line |
| PF | pick in flight | CLICKING (`reqAdv < 0`) | `last_exec` = the pick | the list; the click is imminent |
| AV | continuation pending (auto-advance) | CLICKING with `reqAdv >= 0` (+`reqRearm`) | `last_exec` = the adv pick | her line, then a 2.5 s breath |
| RS | responding | RESPONDING (six signals) | waits for `ev=result` | her real line playing |
| SU | suspended | SUSPENDED (barter/training/gift over the menu) | unchanged | the service window |
| RO | read-only | MANUAL (`drv=0`, or `StopDriving`) | `sess.drv = 0`, or `sj=1` with `clicks_ok == 0` / `drive_scene` off, or crit 2 / arrest / quiet | the list is his; she says so |
| LV | leaving | CLOSING (`CloseClean`, <= 2 s) | - | the menu closing |
| CL | closed | `Finish()` -> IDLE | `session.state` closed; `root` kept if layer 0 was read | back to N1 |

### 1.2 Transitions (first matching row wins within one "from")

| # | from | trigger | guard (exact rule) | effect (wire / log) | she says | to |
|---|---|---|---|---|---|---|
| 1 | N0,N1 | P: his speech turn | `io` on; `!lrgFacRefusesOpen`; no OPEN session [F23]; `!lrgDlgQuietOn` [F17]; `lrgMktState` not active when the hit is clause 2 [F18]; at `clicks_ok == 0` the clause's predicted row passes the stage rail [F19]; narrow marker hits, clauses in order join -> kind -> root (>= 0.5, contain-tier floor [F16]) -> toplevel (>= 2 strict words) -> qrows (>= 0.55, >= 2 shared strict words) | queue `do=open;sid=0;gen=0;pos=-1;i=-1;txt=;kind=plain;ask=<words<=60>` (+`amb=1` after `z=1` when ambient), D2 only; `open_pending = cid`; `utter.cid = cid`; log `open marker=<clause> row=<info_key>` | the bridging directive REPLACES the state wording: ONE short line that does not settle the matter (S2.1) | OP |
| 2 | N1 | G: model T-key from the cached root | section 2 rails pass | `do=pick;sid=0` -> game not live -> `StartOpen` -> opens and resolves by `txt` prefix (existing) | muted (WillEmit TRUE) | OG (pick stamped) |
| 3 | N0,N1 | P: speech, no marker / a guard fails | - | nothing; N1: T-keys from the root, state `things <player> could raise with <npc> (her list is not open now)` | her own words | same |
| 4 | N0,N1 | E: he presses E / engine forcegreet or blocking branch | - | `OnMenuOpen` -> `RunSession` -> `Arm`, `origin=engine` | the greeting / the engine line | AR |
| 5 | OP | E: `do=open` polled (0-5 s) | `OpenBlockedReason() == ""` (now incl. `QuietOn()` -> "a quest scene is running" [F17]; `iSceneGate=1`: refuse only when the scene's OWNING quest has an unfinished journal objective) | `StartOpen` -> pump -> `DoOpen` | - | OG |
| 6 | OP,OG | E: refused / `FailOpen` | a closed-list reason | funcret `Error: <reason>`; server `open_refused = {why, at}` ONLY for OpenBlockedReason/FailOpen reasons [F4] | corner note; next turn 10.26's quest entry may run (S2.3) | N |
| 7 | OP..RD | G: the reply of the open turn lands | `last_click.cid == cid` -> drop the model's pick; `want_none == cid` -> gate normally; else HOLD the pick [F15] | log `gate: held - the open for this sentence decides` | her bridging line, muted only if the fresh `last_exec` is an immediate pick with this cid | same |
| 8 | OG | E: menu opens | - | `Arm()` | greeting plays | AR |
| 9 | AR | Arm classifies | `drv=0` when vis in {module off, smart talk skip, asked for, lethal (IsGuard && crime gold > 0 [F31]), scene (journal-objective scene unless `bDriveSceneMenus==1 && route src=live`), quiet (reported as `scene` [F17]), unknown swf family} | `ev=open ... z=1;sj=;sq=;sqj=;drv=;hid=0` (+`quiet=1` [F17]); a stamped `do=open` is reported `OK: Noted.` keeping its `ask=`/`cid=` for the first `lrg_topics` [F4]; log `arming ... scene= sq= sqj= sj= drv=` | lethal: corner note "choose this one by hand" | drv=1 -> HD; drv=0 -> RO |
| 10 | HD,AR | E: list present / changed | HELD polls `EntryCount()` + the harvested subtitle only; ReadList (<= 48 reads) only on a change [F10] | sig changed -> `gen+1`; `lrg_topics want=1 ... ;sj=;drv=` [F1] + `hc=1` when no driven pick was in flight [F9] | - | RD |
| 11 | RD | W: `want=1` answered | section 2 | `do=pick` (mode) -> PF; with `adv=` -> AV; nothing -> HD (server writes `want_none = cid`) | - | PF/AV/HD |
| 12 | RD | T: `fDecideTimeout` (4 s) | an x still outstanding | close it: `Error: that is not on the table right now` only when its pos/txt is not on the live list, else silent [F26]; stays driven | - | HD |
| 13 | HD | P: his speech on an open layer | - | T-keys from the live list; state `the list is on screen in front of <player>; things he can raise` (closed layer: `<npc> is waiting for an answer - the list is on screen`; single: `<npc> waits for one answer, something like: "<entry>"`); stage-rail / leave-guard / two-candidate lines as they apply | - | HD (G follows) |
| 14 | HD | G: model item | section 2 | pick -> PF (her line muted); park -> PK; `do=show kind=back` (leave guard) stays HD [F3]; LEAVE with a back entry -> PF; `do=leave;pos=-1` -> LV; nothing -> HD, plus row 24 on a single | her line unless muted | ... |
| 15 | HD,PK,AV | E: HIS click (layer changed, no driven click in flight) | - | READING; `hc=1` -> server `last_click.at = now`, `parked = null` [F9]; a stamped pick is closed silently [F26] | her real line | RD |
| 16 | PK | G: his next speech turn | S4.4 release (section 2.5) | the model re-emits the key -> pick; the model emits no SelectTopic -> the gate appends the parked pick (mode `bare-yes`) [F27] | model key: muted; appended: her line plays, the click waits for her voice (<= 8 s) | PF |
| 17 | PK | G: a refusal (`lrgDlgIsBackOut` or leading no/nope/stop/never) | - | `parked = null`; log `the parked selection is dropped` | "as you like" | HD |
| 18 | PK | T: 60 s (`confirm.park_seconds`) | - | park lapses | - | HD |
| 19 | PF | E: click conditions | first click of the session: `now - openTime >= 0.6`, `MenuState()==1` seen, subtitle unchanged `fLineSettle` 0.4 s; every click: no CHIM voice (`isActorTalking`, max 8 s) and no `NoteSpeech(1/2)` younger than 1.0 s [F30]; `VkRestore`; freeze; purse >= cost | `XPush(x)`, `Click(route)`; log `clicked pos= i= route= result= kind= origin= sj=` | her real line | RS |
| 20 | PF | E: SelectAndVerify mismatch x2 (same gen) / `ClickResult <= 0` x2 | - | `Error:` funcret; `click aborted result=` flips the route per S3.2 | "I lost the thread - say that again" / "that did not take - choose it on the menu" | HD |
| 21 | PF,AV | E: gen moved under the pick, or a newer server pick for this session | - | silent close; log `pick overtaken` [F26] | - | RD / PF |
| 22 | RD | W: single unscripted indexed layer, no release by a NEW utterance | S4.6 (`scripted=0`, `kind=''`, `cost=0`, `crit!=2`, `goodbye=0`); a NEW utterance that is a refusal suppresses it | `do=pick;...;z=1;adv=2500` (entry text is a continuer -> `adv=0`) | - | AV |
| 23 | AV | E: anchor met | engine anchor: a line seen after `layerStart` and the subtitle blank (GetLength < 2) continuously >= 2.5 s [F11]; CHIM anchor (`rearm=1`): her voice began after `reqAt` (or 8 s passed) [F12], then `isActorTalking==0` held 1.0 s with no `NoteSpeech`, then 2.5 s; a `NoteSpeech` < 1.0 s old restarts either count | click as row 19; log `adv anchor: line seen at= blank since=` / `adv wait: her line`; `ev=result auto=1` | her next line | RS |
| 24 | HD (single unscripted, AV cancelled or none) | G: his turn, NO pick emitted this turn (none found, or a T-key refused by shape), not a refusal | `auto_advance.rearm` | same reply carries `do=pick;...;adv=2500;rearm=1`; WillEmit FALSE [F14] | her answer plays in full | AV |
| 25 | AV | K: talk key | first adv: `speechAt > layerStart`; rearm: `speechAt > reqAt` [F13] | silent close; log `adv cancelled by speech` | - | HD |
| 26 | AV | T/E: 30 s without the anchor, `SubtitlesOn()` false, or `bAutoAdvance` off | - | silent close; log `adv expired` / `adv off` [F11, F25] | - (his words decide next turn) | HD |
| 27 | RS | E: any of the six signals | - | `SettleAndReport`: `ev=result ok=1 [auto=1]`, funcret `OK: The matter is raised.`; the first proven click writes `CalSet("route",...,"live")`; server `clicks_ok += 1`, `last_result`; log `result ok=1 clicks_ok=` | - | RD (new layer), SU, or CL |
| 28 | RS | T: no signal 20 s (4 s for a check) | - | `ev=result ok=0 why=unverified`; `StopDriving("unverified")` + `ev=stopped` [F2] | "nothing came of that" | RO |
| 29 | RS -> SU -> RD | E: a service window opens over the dialogue, then closes | - | handoff = success; back to READING | the shop / training | SU, RD |
| 30 | HD,RD,PF | E: combat; a journal-objective scene starts on the speaker (the Arm test, not bare `GetCurrentScene`) and the route is unproven or `bDriveSceneMenus=0` [F24]; read fails x2 | - | `StopDriving(why)` -> MANUAL + `lrg_dlg ev=stopped;sid=;why=` [F2] | next turn, once: "the menu was left to him because a fight/scene began" | RO |
| 31 | RO | P/G/W: any turn | - | server: plain list (no T-keys, no `(+N more)`), the read-only clause pre-LLM, the gate emits nothing (log `gate: read-only session why=<vis|scene-unproven|drive_scene off|quiet|stopped>`); game sends `want=0` | "choose it on the list yourself - I cannot pick for you here" (lethal/arrest: "choose that yourself") | RO |
| 32 | RO | E: a pick still reaches the game (skew) | live, list has the entry | `Error: choose that one on the list yourself` (not-on-list: the old string) | corner note | RO |
| 33 | HD | G: LEAVE | back entry listed -> click it; else any visible `walkaway=1` or `twat != ''` -> `do=show;kind=back` (game: OK, NO state change [F3]); else `do=leave;pos=-1` | leave guard log `leave-guard` | guard: `No line here backs out cleanly; leaving is his to do by hand, and it ends things with her - tell him so.` | PF / HD / LV |
| 34 | HD | E: the Leave hotkey | crit 2 -> nothing (menu is his) | `BeginLeave` | - | LV |
| 35 | LV | E: `CloseClean` | - | `OK: The conversation is left.` | - | CL |
| 36 | any live | E: menu gone / 900 s cap | - | `Finish`: `ev=closed why=<asked|goodbye|handoff|external|refused>`; never `lrg_dlgtalk`; server: state closed ALWAYS (no `lost` [F22]); `losses` counts `pending=1` as a log counter | - | CL -> N1/N0 |

---

## 2. Decision table - one utterance on a known layer

### 2.1 Which words may act (every W decision, and S4.3/S4.5 on G)

The stored utterance `utter = {text, at, type, cid}` may drive a W decision only when **both** hold [F7, F8]:
1. `utter.at > last_click.at` (this NPC, any session). `last_click` is written at EMIT time by `do=pick` and by
   `do=leave` with `pos >= 0`, and at `hc=1` (his own click) - never by `do=open`/`do=show`/`do=award`.
2. `now - utter.at <= confirm.utter_window` (30 s) **or** `utter.cid == ` the `lrg_topics cid=` (the game sends the
   stamped `do=open`'s cid on that session's lists) **or** `utter.cid == open_pending`.

On G the utterance is this turn's (new cid) by construction; S4.3/S4.5 still require a speech turn. `ask=` (the model's
or the open's words) is a W candidate only on the list that answers its own `do=open`/`do=pick`, and never releases a
commit (S4.3).

### 2.2 Choosing the entry

| path | order (first that yields an entry) | ambiguity |
|---|---|---|
| W | faction exact line (`lrgFacArbitrateWant`) -> price list: exact slot only, longest wins, price question = nothing -> single-entry layer: section 2.4 -> service KIND on a ROOT list with exactly one `class=service` entry of that kind (S5; stands down for an active market order [F18]) -> `lrgDlgMatchText` over {`ask`, `utter`} at >= 0.55 / margin >= 0.15, contain-tier floor [F16], scripted needs `f1 > 0` (S4.8) | `ask` and `utter` both clear on DIFFERENT entries -> nothing, log `want=1 ambiguous model= player=` (S4.7); two kind entries -> nothing |
| G key | the T-key of THIS turn's offer (stale key -> dropped) | - |
| G words | follower verbs -> faction exact -> slot -> `lrgDlgMatchText(item)` 0.55/0.15 (contain floor); may execute `plain`/`service` (+`pay` in a same-session continuation), a service by kind, and a commit only through S4.3/S4.5 | two entries within 0.10 for his words -> the hint line `T<a> or T<b> fit what he said - ask which, naming both.` (once, offer only, not on a price list) |
| G LEAVE | row 33 | - |

### 2.3 Rails and outcome (S4.9 order; first hit wins)

| # | condition | W outcome | G outcome | she says | spec |
|---|---|---|---|---|---|
| R1 | read-only session: `drv=0`, or `sj=1` && (`clicks_ok==0` or `!session.drive_scene`), or crit 2 / arrest, or quiet [F17], or `stopped` [F2] | nothing | nothing (no keys were offered) | pre-LLM: "choose it on the list yourself - I cannot pick for you here" | S1.3 |
| R2 | class `hidden` | nothing | nothing | - | S4.9 |
| R3 | resist-arrest entry | nothing | `do=show;kind=meta` -> game `StopDriving("lethal")` [F3] | "choose that yourself" + corner note | S4.9, S7 |
| R4 | follower `never_topics` | nothing | nothing | her words | S4.9 |
| R5 | arrest-class session, or entry/session crit 2 | nothing | `do=show;kind=meta` (lethal) | "choose that yourself" | S4.9 |
| R6 | freeze B1: purse moved since the list was read | nothing | nothing (re-read) | - | S4.9 |
| R7 | class `meta` | nothing | nothing | her words | S4.9 |
| R8 | stage rail: `clicks_ok == 0` and NOT (`indexed=1`, class plain/back, `scripted=0`, `cost=0`, `kind=''`, `goodbye=0`) - kind picks included | nothing | nothing; log `gate: stage rail` | ONE line per prompt: `Until he has asked me something simple I can only pick T1, T4; for anything else say: I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one` (+ corner note once per session) | S3.3 |
| R9 | `pay` and cannot afford | nothing | nothing | "you cannot pay that"; next turn "Nothing came of it: he could not pay what that costs" | S7 |
| R10 | check (`kind` persuade/intimidate/bribe) | pick when the check rails pass (>= 4 words on voice, bribe price affordable, named amount >= price, no retry of a refused attempt); scoff-first doomed -> park once | same | the engine's verdict, told next turn as fact; a refusal next turn as "Nothing came of it: ..." | S4.9, S4.10 |
| R11 | COMMIT (S4.1: `never_auto`; indexed `scripted && goodbye`; scripted on a closed layer with >= 2 scripted non-hub siblings; `commit: true` override; `commit_tags`; cost >= 100 or >= 25 % purse; unindexed fallbacks) | only S4.3 explicit on the PLAYER's utterance, or S4.5 single release; else nothing | explicit -> pick (mode `explicit`); single release; own-confirmation layer -> pick; parked + released -> pick; else PARK | park: her question "quoting the choice in its own words" | S4.1-S4.5 |
| R12 | cost >= 100 septims / >= 25 % purse, follower `dismiss`/`home`, `never_auto` | never explicit | park always (asks) | her question | S4.3, S4.10 [F20] |
| R13 | engine's own Yes/No confirmation layer (`lrgDlgLayerIsOwnConfirmation`, regexes widened by Lane A) | the matched side | release at once | her real line | S4.1 note |
| R14 | anything else (plain, service, back, hub question, walk-away line) | pick (mode intent/kind/slot/faction/follower/continuation) | pick (mode key/intent) | her real line (muted) | S4.10 |

**Explicit (S4.3)**, player speech turn on a new utterance, entry not meta/never_auto/crit 2/arrest/scoff-first/
dismiss/home/costly: (a) `lrgDlgMatchText` lands on THIS entry at >= 0.70, margin >= 0.25, `tokens(u) >= min(4,
tokens(entry))` (an exact 1.0 needs >= 3 tokens); (b) `lrgFacArbitrate` matched the exact join line; (c) the slot
matcher matched exactly with >= 2 tokens.

### 2.4 A single-entry layer (closed, exactly one visible, indexed, class commit|plain) - `lrgDlgSingleEntryRelease`

Words: SCORE = `lrgDlgMatchText` as shipped; "strict word / shared / foreign / precision" = `lrgPromptWords($n, strict:
true)`; precision = shared strict / the utterance's strict words (1.0 when it has none). Utterance must be NEW (2.1).

| step | test | result |
|---|---|---|
| 0 | `lrgDlgIsBackOut` (widened: need to think, let me think, give me a moment/minute/second, hold on, hang on, one moment, not so fast) or leading no/nope/stop/never | nothing (and no auto-advance) |
| 0b | utterance question-shaped (`?` or leading what/why/how/who/where/when/which/is/are/do/does/can/could/would/should after optional so/well/uh/um/and/ok/okay) while the ENTRY is not a question | nothing |
| 1 | first 1-3 tokens equal a `confirm.assent_words` phrase (unscripted singles also `go on`, `carry on`, `auto_advance.continuer_words`) | bare -> release; with a tail: non-commit -> release; commit -> release only if `W_s(tail) ⊆ W_s(entry)` |
| 2 | score >= 0.85 (exact, containment, F1 >= 0.77) and, for a contain-tier hit, `tokens(u) >= min(3, tokens(entry))` [F16] | release; commit also needs precision >= 0.75 |
| 3 | unscripted: >= 1 shared strict word, or score >= 0.65 (contain floor [F16]) | release |
| 4 | scripted, entry >= 3 strict words: shape-equal and score >= 0.65 and precision >= 0.75 | release |
| 5 | scripted, entry 1-2 strict words: shape-equal and >= 1 shared strict word (commit: precision >= 0.75) | release |
| 6 | otherwise | W: nothing (then row 22's auto-advance if the class allows). G: a T-key on a NON-commit single gets step 0/0b only, then clicks; on a COMMIT single it must pass 0-5, else PARK (S4.4 releases it) |

Order on W: release (immediate pick, mode `single`) -> else the S4.6 adv pick -> else nothing. A release is an
immediate pick (WillEmit TRUE, table 2.6). On G, step 0/0b applies to EVERY single-entry T-key (scripted or not): a
shape refusal there is what re-arms the auto-advance (row 24).

### 2.5 The park and its release (S4.4)

Release when ALL: a speech turn; the utterance differs from the one that parked it; `lrgDlgIsBackOut` / leading no
first (-> un-park); the first 1-3 normalised tokens equal an assent phrase (`ok` = `okay`; tail not tested); the park is
<= 60 s old and "named": >= 1 strict word of the entry in `parked.said`, or `lrgDlgMatchText(said, entry) >= 0.45`,
or `said` contains `?` (NOT when the two-candidate hint fired on the parking turn), or the entry is the layer's only
commit. Other single tokens stay parked. The model re-emitting the key is the normal carrier; if it emits none, the
gate appends the parked pick itself [F27].

### 2.6 WillEmit / the mute / never-empty (one truth table) [F14, F15]

| outcome of the turn | `lrgDlgWillEmit` | transformer mute | `lrgNeLinesCarryPick` lets an empty message through |
|---|---|---|---|
| immediate pick (key, intent, explicit, single, released park, kind, slot, faction) passing every rail | TRUE | yes | yes |
| LEAVE with a back entry | TRUE | yes | yes |
| any pick carrying `adv=` (auto-advance or `rearm=1`) | **FALSE** | no | **no** |
| park, stage rail, read-only, leave guard `do=show`, `do=leave;pos=-1`, two-candidate, unreleased commit, pick HELD on the open turn, parked pick appended by the gate [F27] | FALSE | no | no |

The transformer's fresh `last_exec` read (S2.1) mutes only when `last_exec.do == pick`, same cid, and no `adv`.

---

## 3. The timing contract

### 3.1 Constants (all existing unless marked)

| clock | value | owner |
|---|---|---|
| D1 fast-path reply / D2 echo | ~0.5 s / 0-5 s (DLL request poll) | server |
| game poll | 0.1 s CLICKING/RESPONDING; 0.25 s HELD/LISTENING/READING/DECIDING; MANUAL/SUSPENDED keep 0.5 s [F28] | C |
| decide window `fDecideTimeout` | 4 s (closes an outstanding x only; never a state change) | C |
| first-click settle `fLineSettle` | 0.4 s unchanged subtitle, after `openTime + 0.6 s` and `MenuState()==1` | C |
| CHIM-voice rail | no click while `isActorTalking`, max 8 s from `clickStart`; + `NoteSpeech` < 1.0 s old [F30] | C |
| auto-advance grace | 2.5 s continuous blank after a seen line (`auto_advance.grace_ms`); CHIM anchor 1.0 s quiet + 2.5 s | A/C |
| adv cap (NEW) | 30 s from `reqAt`, then silent close [F11] | C |
| pick expiry | 20 s from `reqAt` in DECIDING (`TryResolvePick`) | C |
| RESPONDING | signal within 20 s (4 s for a check); verified settle up to 4 s; route flip after 9 s with no signal on route A | C |
| new-utterance window | 30 s (`confirm.utter_window`) | A |
| park | 60 s | A |
| `open_refused` | 120 s | B |
| reward window (gate B) | 180 s | B |
| root cache | 1800 s, loc and qsig unchanged | A |
| session | 900 s cap (game); the server's open-session test uses the same 900 s [F23] | A/C |

### 3.2 What the player perceives, step by step

| scenario | sequence (t0 = his speech ends) | he perceives |
|---|---|---|
| **First contact, cold or warm** (Hulda: "Nice inn you have here...") | t0+~1 s server has the text; P: marker (`toplevel` cold / `root` warm), `do=open` queued, LLM starts with the bridging directive -> t0+1..6 s DLL poll delivers `do=open` -> Activate (<= 1 s) -> Arm, greeting, list on screen -> ReadList <= 0.3 s -> `want=1` -> D1 pick <= 0.5 s -> click once the greeting has settled (and not over her voice) -> **t0+3..8 s** her real line. Her reply lands t0+~6.5 s: muted if the pick landed before her first sentence streamed (~t0+3-4 s), else her word plays and the click waits for it (<= 8 s) | "she says a word, then her list comes up and the line is picked in front of me" |
| **E-press then a sentence** | E -> list at once; he speaks -> t0+~6.5 s the model's T-key with her (muted) reply -> click within 0.1 s | the real line ~7 s after he spoke |
| **Sentence just before an E-press** (< 30 s, no click since) | E -> list -> `want=1` answers from the stored sentence at once | the line is picked as the list appears |
| **Commit, not explicit** | turn 1: park, her question quoting the line (~6.5 s); turn 2 "yes" -> the key (or the appended pick) -> click | one question, then the line |
| **Commit, explicit** (Kodlak's join) | as the first two rows; one utterance | the real line, no question |
| **Auto-advance** (the oath) | line N plays (subtitle seen) -> subtitle blank -> 2.5 s -> click at the next 0.1 s poll -> line N+1 | a short breath after each line |
| **Talk key in the breath** | K -> cancel now (no click); his words -> the model; a question ABOUT the line releases it (S4.5) with her muted reply; another question -> her CHIM answer (5-10 s) with `rearm=1` -> her voice ends -> 1.0 s quiet -> 2.5 s -> click | he is answered, then the next line follows a breath later |
| **Nothing matched** | the list stays on screen; she answers in words; he may click or Tab | no surprise click |
| **Read-only** | the list is his; the same turn carries "choose it on the list yourself - I cannot pick for you here" | never "that is not on the table" for a line on his screen |

---

## 4. Ambiguities, contradictions and gaps - binding resolutions

Each: **type** (C contradiction, G gap, A ambiguity) - spec item - what the code/spec says (verified) - resolution -
lanes.

**F1 (C) S8 vs S1.3/3.4 - `sj`/`drv` do not survive the first list.** `lrgDlgOnTopics` rebuilds `session` from
scratch on every layer (`lrg_dialogue.php:753`, only `path`/`cont` carried), so `sj/sq/sqj/drv` set by `ev=open` vanish
at the first `lrg_topics`; the harness (3.4 step 2) passes `sj`/`drv` on `fxDlgTopics`. **Resolution:** OnTopics
carries `sj, sq, sqj, drv, amb, stopped` forward when `sid` matches; Lane C also appends `;sj=<0|1>;drv=<0|1>` to EVERY
`lrg_topics` (additive, before `e=`), which win when present. Lanes A, C, E.

**F2 (C) S1.1 vs S7 - the server never learns that driving stopped.** `StopDriving` sends no `ev=unhide`, yet S7 needs
"the menu was left to him because a fight/scene began" next turn, and the server would keep emitting picks to a MANUAL
session (skew error on every pick). **Resolution:** `StopDriving(why)` sends one additive `lrg_dlg ev=stopped;sid=;why=`;
the server sets `sess.drv=0`, `sess.stopped={why, at}` (read-only from then on; the S7 line reads it once within 180 s).
An old server logs "unknown ev". Lanes C, A (B words the line).

**F3 (C) S4.2 vs the game's `do=show`.** `Step()` answers any `reqShow` with `OK: The menu is shown.` then
`HandBack("branch_show", true)` (`LRG_Dialogue.psc:1959-1966`), i.e. `StopDriving` in v1.0 - the leave guard would end
driving for the session. **Resolution:** the leave guard emits `do=show;...;kind=back`; the game answers a `reqShow`
whose `reqKind == "back"` with the OK funcret and NO state change; every other `do=show` (lethal/arrest, `kind=meta`) ->
`StopDriving("lethal")` + `ev=stopped`. `HandleLeaveKey` on crit 2 keeps its lethal path. Lanes A, C.

**F4 (G) S2.1/S2.3 - the open's own request poisons `open_refused`.** A stamped `do=open` is never reported (no
`reqDo=="open"` branch); the first pick closes it as `Error: that moment has passed` (`CmdSelectTopic`, :1231) - a
corner note on every good first contact, and S2.3's writer would read it as a refusal and fire 10.26's fallback for
120 s. **Resolution:** `Arm()` reports a stamped `do=open` as `OK: Noted.` once the session is armed, first copying its
`ask=`/`cid=` so the first `lrg_topics` still carries them; Lane B writes `open_refused` only for the OpenBlockedReason
/ FailOpen closed-list reasons, never for "that moment has passed". Lanes C, B.

**F5 (G) S4.6 - the D2 twin of a waiting adv pick.** `XSeen` is written at click time only, so the D2 echo (<= 5 s)
of an adv pick still waiting for its anchor passes `XSeen`, closes the first copy with an Error and re-stamps it.
**Resolution:** `CmdSelectTopic` drops silently a command whose `x == reqX` while `!reqReported`; every silent close
(cancel, expiry, auto off, overtaken) calls `XPush(reqX)` first. Lane C.

**F6 (C) S1.1/S1.3/5.3(2b) vs D-17 in the server.** With a session open and `sess.scene=1` the turn is switched OFF
(`lrgDlgPrepareTurn`, :1638: `$amb` is computed only with no open session) and `lrgDlgAnswerWant` returns on
`sess.scene==1` (:2345) - Hulda under the bard (`sj=0`) is never driven. **Resolution:** while a session is open,
`scene` never switches the module off; `sj`/`drv`/`clicks_ok`/`drive_scene` decide (R1 or the scene rail). D-17's
`on=false` applies only with NO open session and a non-ambient snapshot scene. `lrgDlgOnTalk` becomes the `handled`
stub. Lane A.

**F7 (G) S4.3 guard scope.** `lrgDlgAnswerWant` uses `st.utter` of ANY age on every `want=1`; the spec guards only
the explicit and single releases, but 3.4 step 4 ("a 300 s-old utterance on an E-press asserts nothing") needs it for
plain picks too, and without it a click chains into the next layer. **Resolution:** section 2.1 applies to EVERY W
decision (matcher, kind, slot, faction, single, explicit). Lanes A, E.

**F8 (C) S4.3 "`last_exec.at`".** `lrgDlgEmit` writes `last_exec` for every emit incl. `do=open` (:3094): at first contact
the open's stamp is >= `utter.at`, so the guard as written refuses the flagship one-sentence join. And `ask=` carries
words, not a cid. **Resolution:** a separate `last_click = {at, cid}` (section 2.1); `utter` gains `cid` in
`lrgDlgPrepareTurn`; the cid clause compares with the `lrg_topics cid=` (the game sends the stamped open's cid,
`SendTopics`: `reqCid`, else `sessCid`) or `open_pending`. Lane A.

**F9 (G) his own clicks are invisible.** On a visible menu he clicks by hand; the server keeps his older sentence (< 30 s)
and his park, which can then act on the layer HE chose. **Resolution:** the game adds `hc=1` to the `lrg_topics` of a
layer that changed while no driven pick was in flight (HELD/MANUAL); the server sets `last_click.at = now` and clears
`parked`. Lanes C, A.

**F10 (G) S1.1 "stay LISTENING".** `StepListening` with `n > 0` goes straight to READING, so a list sitting on screen is
fully re-read (<= 48 natives) every 4 s; DECIDING never looks for a hand click. **Resolution:** HELD polls
`EntryCount()` (+ the subtitle `HarvestLine` already reads); a changed count, a new harvested line, or a stamped pick
(`TryResolvePick` against the held arrays) moves on; ReadList only on a change. <= 3 natives per poll holds. Lane C.

**F11 (G) S4.6 engine anchor robustness.** The layer is read after RESPONDING settles and D2 can be 5 s late, so a
per-pick `sawLine` misses a line that began or ended before the pick landed; a subtitle-less line never arms.
**Resolution:** `HarvestLine` keeps `lineSeenAt` (last non-blank read) and `blankSince` (start of the current blank run;
blank = `GetLength < 2`, its own rule). The anchor = `lineSeenAt > layerStart` (the click that produced the layer, the
hand-click detection, or `openTime`) and `now - blankSince >= 2.5 s` - possibly already true when the pick lands. An
adv pick not fired 30 s after `reqAt` is closed silently (`adv expired`). 0 new natives. Lane C.

**F12 (G) S4.6 CHIM anchor.** `NoteSpeechToDriver(1)` fires on SpeechStarted, SpeechStopped AND TextReceived
(LRG_Main :3475/:3512/:3542); the text arrives 1-3 s before her TTS, so "talking == 0 for 1.0 s" can be met before she
speaks and the click would land before her answer. **Resolution:** `OnChimSpeechStarted` passes a distinct kind
(`NoteSpeech(2)` -> `herVoiceAt`); a `rearm=1` pick starts counting only after `herVoiceAt > reqAt` (or 8 s with no
voice - muted/empty reply), then the spec's 1.0 s + 2.5 s. `NoteSpeech`'s early return on `!sCalibPassive` must not
gate the new stamps. Lane C.

**F13 (A) S4.6 cancel window.** `speechAt > reqAt` misses a key press made after the layer appeared but before the pick
landed. **Resolution:** first adv pick: cancel on `speechAt > layerStart`; `rearm=1` pick: on `speechAt > reqAt` (his
press preceded the question by design). A NEW utterance that is a refusal also suppresses the server's adv (row 22).
Lanes C, A.

**F14 (C) S1.3 WillEmit vs S4.6.** "`decide.do === 'pick'`" is TRUE for the re-arm pick, so her answer would be muted and
the rearm would wait for speech that never comes - silence. `lrgNeLinesCarryPick` (`lrg_replies.php:220`) would also let
an empty message through on it. **Resolution:** table 2.6: any `adv=` pick is WillEmit FALSE, never mutes, never lets an
empty message through; `last_exec` stores `adv`. Lanes A, B.

**F15 (G) S2.1 - two decisions for one sentence.** On the open turn the model may pick a T-key from the cached root while
the fast pick is still to come; the second pick closes the first with an Error. **Resolution:** row 7: on the
`open_pending` turn the gate emits the model's pick only when `want_none == cid` (the fast path already answered this cid
with nothing - written by `lrgDlgAnswerWant`); otherwise it holds it (WillEmit FALSE, her line plays). Lane A.

**F16 (G) the matcher's character containment.** `lrgDlgMatchText` scores 0.85 whenever one norm is a SUBSTRING of the
other (`strpos`, not tokens). Real function, WSL php, this tree **[M]**: against a Hulda-like root "hi" -> "Anything
interesting going on in town?" 0.85 (margin 0.785), "what?" -> "What have you got for sale?" 0.85, "go" and "an" 0.85;
against singles "the" / "live" -> the Legion Oath4 0.85, "a" / "it" / "that" / "is that" -> "Is that it?" 0.85, "con" ->
"Contract?" 0.85 (`%TEMP%\lrg_test\pt19c-architect\verify.php`, the shipped functions copied verbatim). So clause 3
opens her menu on "hi", W picks the trade line on "what?", and S4.5 step 2 releases a COMMIT
oath on the fragment "the" (precision is 1.0 by definition when the utterance has no strict word). **Resolution (scores
untouched):** `lrgDlgMatchText` also returns `tier` (exact|contain|f1|trigram); a `contain` hit where the UTTERANCE is the
shorter side counts only when `tokens(u) >= min(3, tokens(entry))` - in marker clause 3, every W matcher decision, G
intent mode and S4.5 steps 2-4's score routes. Checked by hand against every row of S4.5's verified table: all hold
(the shortest are exact lines, entry-inside-utterance hits like "what contract?", or F1-tier hits like "what do you
need"). Test rows: "hi", "what?" -> no open, no pick; "the", "a", "live" -> no release. Lanes A, E.

**F17 (G) quiet mode (10.28) vs `iSceneGate = 1` and scene driving.** pt19-helgen.md:283 kept Helgen safe with
`iSceneGate = 0` ("already refused"); v1.0 ships 1. `SendFacts` forces `sqj=0` while quiet (`LRG_Dialogue.psc:3711`), so
a cached `sqj` reused by `Arm()` is false then; `clicks_ok` is an INSTALL fact that survives into a new game's Helgen;
`lrgDlgQuietOn` loses its only caller (`lrgDlgCalibCandidate`, deleted). **Resolution:** `OpenBlockedReason` refuses while
`QuietOn()` ("a quest scene is running", on the closed list); `Arm()` grades `drv=0` (vis `scene`) while quiet and never
reuses a cached `sqj` taken under quiet; `ev=open` carries `quiet=1` (the facts key exists); the server's `lrgDlgQuietOn`
refuses the P open and makes the session read-only (why=quiet). Nothing else of 10.28 changes. Lanes C, A.

**F18 (G) buying (10.27) vs the kind clause.** "I'd like to buy a mead" is barter kind (phrase `i'd like to buy`) AND a
10.27 order: a P open + the trade-line pick AND `ExtCmdLRG_Buy` in one turn. **Resolution:** the P open is decided after
`lrgMktPlan`; clause 2 and the S5 kind pick stand down while `lrgMktState` is in `LRG_MKT_ACTIVE`; `no-stock` still falls
to 10.15 as shipped. Lane A.

**F19 (G) the stage rail vs the P open.** At `clicks_ok == 0` every kind entry is refused by the rail
(`OfferServicesTopic` scripted=1, rooms cost > 0, join lines scripted), so a kind/join open shows the menu (camera, lock)
for nothing, and cold there is no `<business>` to carry the rail line. **Resolution:** at `clicks_ok == 0` open only when
the clause's predicted row (clause 3/4/5) passes the rail; a kind/join marker does not open - the turn falls through to
10.15's direct net / request block as shipped (the shop still opens through CHIM's trade window) and the rail sentence
rides the volatile block. Owner page 5.2: "before it she asks for one" -> "before it the shop opens through her own
trade window". FE.hulda.plain/engine/trade.rail and FE.kodlak.rail (words, label present) still pass. Lanes A, F, E.

**F20 (C) S4.10 "a walk-out line (twat target) always asks" vs S4.1 / verdict 6.** Walk-away is not a commit; no rule
implements that row. **Resolution:** verdict 6 rules - `walkaway`/`twat` feed only the leave guard; the always-asks row
is cost >= 100 / >= 25 % purse, follower dismiss/home, `never_auto` (meta: never). Lanes A, F.

**F21 (G) config lists are replaced, not merged.** `lrgMerge` (`lrg_core.php:59-69`) replaces lists wholesale; the dialogue
lists live in `lrgDlgDefaults()`, so a JSON `barter.phrases` holding only S2.1's four would delete the 25 shipped ones.
**Resolution:** any list Lane B puts in `lrg_config.default.json` is the COMPLETE list (shipped order, then the
additions); `test_services` asserts it is a superset of the code default. New keys also get a code default (Lane A) so
`lrgDlgCfg` never depends on the JSON. Lanes B, A.

**F22 (G) dead keys and states section 4 misses.** `dialogue.continuer_words` is read by nobody and overlaps
`auto_advance.continuer_words`; `session.utter_max_age` loses its only reader (`lrgDlgOnTalk` -> stub) and duplicates
`confirm.utter_window`; removing `session.lost_seconds` leaves `lrgDlgListFor`'s `lost` branch on a call-site default,
and a Tab-out with a read layer (`pending=1`) would show a closed menu as "waiting for an answer"; `bCalibPassive` leaves
the MCM/ini but `sCalibPassive` gates the four passive rows. **Resolution:** delete `continuer_words`,
`session.utter_max_age`, the `lost` state (`ev=closed` -> closed; `losses` stays a log counter), and `sCalibPassive` with
its reader and gates (the passive pass is always on). Lanes A, C.

**F23 (G) an OPEN session that never closes.** A lost `ev=closed` (load, crash) leaves `session.state=open` for ever: no
P open again for that NPC and "the list is on screen" is false. **Resolution:** open := `state == open && now - sess.at
<= 900` (the game's own cap). Lane A.

**F24 (G) G4 mid-session scene check.** `StepReading` hands back on ANY scene appearing mid-session
(`LRG_Dialogue.psc:2164`, bare `GetCurrentScene`) - the test S1.1 retires; main-quest clicks start scenes. **Resolution:**
the same owning-quest-objective test as `Arm()` (only on a changed layer); a journal scene with the route proven and
`bDriveSceneMenus=1` keeps driving and sends `sj=1` on that layer's `lrg_topics` (F1); otherwise `StopDriving("scene")` +
`ev=stopped`. Lane C.

**F25 (A) "the pick waits for his words".** With `bAutoAdvance` off or subtitles off an adv pick would sit in CLICKING
for ever. **Resolution:** the game closes such a pick silently (no click) and his next utterance decides through S4.5;
`reqAdv` defaults to -1 (absent), 0 = an immediate continuer. Lane C.

**F26 (A) overtaken picks.** Today a replaced x or a pick whose layer changed under it ends as `Error: that moment has
passed` - a corner note, common now that he may click. **Resolution:** gen changed since the pick, or a newer server pick
for the same session -> silent close; same gen with the entry moved -> S7's stale sentence. The decide-window close uses
"not on the table" only for a pos/txt that is not on the live list. Lane C.

**F27 (G) S4.4 needs the model's key.** `lrgDlgParkOrRelease` runs only when the model re-emits the parked key; a "yes"
answered in words leaves the park ("she keeps asking me"). **Resolution:** in `lrgDlgPostProcessActions` (it runs on every
reply - the barter and market nets append there), a speech turn that satisfies every S4.4 release condition and whose
reply carries no SelectTopic line appends the parked pick (mode `bare-yes`); not muted, so her line plays and the click
waits for her voice. Lanes A, E (test 20 row: "yes" + no model item -> release).

**F28 (A) S1.2 poll rate.** "0.25 s elsewhere" would double MANUAL's shipped 0.5 s. **Resolution:** MANUAL/SUSPENDED keep
0.5 s; 0.25 s in the driven non-click states. Lane C.

**F29 (A) owner page 5.3 (4).** `do=pick cost=10` is hard-coded; this install's RoomCost is 25 (10.27), and 25 septims is
>= 25 % of a purse under 100, which asks. **Resolution:** the page says `do=pick cost=<her room price>` and "no question
unless it costs 100 septims or a quarter of your purse"; FE.hulda.room's `live_text` uses the live price. Lanes F, E.

**F30 (A) the first-contact overlap.** A pick landing between her first streamed sentence and her TTS start plays the
engine line under her word. **Resolution:** the click rail also waits while a `NoteSpeech(1|2)` is < 1.0 s old (row
19; inside the existing 8 s CHIM-voice cap; 0 natives). Lane C.

**F31 (A) ClassifyCrit's list half.** "a DGCrime*/arrest topic is on the list" cannot be known at `Arm()` (no list yet).
**Resolution:** Arm grades crit 2 on `IsGuard() && crime gold > 0` only; the list half stays the server's arrest-class
rail (R5, `do=show kind=meta` -> `StopDriving("lethal")`); no Papyrus list scan. Lane C.

**F32 (A) the overrides file.** `lrgDlgOverrides` stops at the first valid file, so an owner
`lrg_dialogue_overrides.json` would shadow the default's Oath4 `commit: true` rows (none exists on the live server
today). **Resolution:** `commit: true` rows of the default file are always read (merge `entries`). Lane A.

**F33 (A) SelectTopic funcrets are not voiced turns.** The catalog row has `followup.enabled = false`
(`lrg_dialogue.php:3360`); an `Error:` result surfaces as a corner note with the TECHNICAL reason (`SendFuncret`) and in
the next turn's context. S12's "1 paid funcret turn" premise is inaccurate; the design is unchanged, but every game-side
refusal in S7 is heard NEXT turn at the earliest - which is why F2/F3/F4/F26 keep avoidable ones off the wire. Lanes B, F
(the owner page must not promise her voice for those).

### 4.1 Test rows the resolutions add (owners in brackets)

`test_dialogue` 16: `ev=open drv=0` then a `lrg_topics` WITHOUT `drv` -> still read-only (F1); `ev=stopped why=combat`
-> read-only + the S7 line once (F2); session `scene=1 sj=0` open -> keys offered, W picks (F6); `session.at` 901 s old ->
P open allowed (F23). 18: leave guard emits `kind=back` (F3). 19/23: `do=open` emitted after the sentence -> explicit
still fires (F8); a plain entry with a 300 s-old utterance -> nothing (F7); `hc=1` -> old utterance and park inert (F9).
20: "yes" with no model item -> `bare-yes` (F27). 21: "the", "a", "live", "is that" -> nothing (F16). 22: adv pick ->
WillEmit FALSE, not muted, empty message not let through (F14); a NEW refusal suppresses adv (F13). 25: "hi" to warm Hulda
-> no open; "I'd like to buy a mead" with fresh stock -> no open (F18); `clicks_ok=0` + "what have you got?" cold -> no open,
10.15 net (F19); `quiet=1` -> no open (F17); open turn T-key before `want_none` -> held (F15) [A]. `test_services`: JSON
lists superset (F21) [B]. `test_gates` 39: "that moment has passed" never sets `open_refused` (F4) [B]. Game log proofs for
the first evening [C]: `adv anchor: line seen at= blank since=`, `adv cancelled by speech`, no `closing the old one` for
a D2 twin (F5), `select: ... do=open reported OK` at arming (F4).
