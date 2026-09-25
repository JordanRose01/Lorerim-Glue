# pt6 verification - WIRE CONTRACT + SAFETY lens

Independent verifier, read-only except this file. Verdict at the bottom.

Owner addenda read first (`glue/OWNER_ADDENDA.md`). **Addendum 3 (2026-09-21 ~14:05) changes this
verifier's own lens**: the Decline action, refusal-marker detection, per-act decline memory and
"a decline is final for that act" must be REMOVED, not verified. I checked the removal instead
(see S6) and verified the reduced safety-net preconditions the addendum leaves in place.

## What I actually ran (evidence base)

Staged the project to `%TEMP%\lrg_test` and ran everything in WSL `DwemerAI4Skyrim3`, PHP 8.2.28:

* `php -l` over every plugin and tool file: clean.
* `tools/test_intent.php` **105 passed / 0 failed**; `tools/test_gates.php` **206 / 0**;
  `tools/test_scene_index.php` **ALL CHECKS PASSED**; `tools/flows/run_flows.php --strict`
  **32 scenarios, 32 passed, 0 FAILED, 0 pending, 644 checks, 0 warnings**.
  -> the builders' test claims are reproducible and true.
* Two **probe scenarios of my own** (staged copy only, `tools/flows/scenarios/90_verify_probe.php`
  and `91_verify_probe2.php`, never written into the project) to test what the existing suite does
  not assert. Their output is quoted verbatim as evidence below.

Diffed by hand: `PROTOCOL.md` v0.3 §1-§9 vs `lrg_core.php` / `lrg_actions.php` / `lrg_intent.php` /
the five hook files vs `LRG_Main.psc` / `LRG_OStim.psc` / `LRG_Profile.psc` / `MCM/config.json` +
`settings.ini`.

---

## DEFECTS

### D1 (MAJOR, server) - "you lead" still gags her for 150 s: the server decorates the lead hand-over with `hold=`

`lrgHoldSeconds()` (`lib/lrg_actions.php:1245-1254`) returns `lead.hold_seconds` for **every**
command emitted on a player-speech turn, with no exception for the one command that means the
opposite. `lrgDecorate()` (`:1239-1240`) therefore appends `hold=150` to `do=lead;who=npc`.

Game side, `LRG_OStim.CmdLead` (`:2587-2594`) clears `leadHoldUntil` and then **re-applies the
`hold=` carried by the same command**, so the hold is restored immediately:

```
PROBE A intent={"kind":"lead_npc","conf":"high","kv":{"do":"lead","who":"npc"},...}
PROBE A wire(net/carrier): ...|ExtCmdLRG_SceneControl@ok=1;cid=..;npc=..;do=lead;who=npc;hold=150
PROBE A2 wire(model):      ...|ExtCmdLRG_SceneControl@ok=1;cid=..;npc=..;do=lead;who=npc;hold=150
```

Both paths reproduce: the safety net firing for `lead_npc`, and the model choosing
`ChangeIntimacy "<npc> leads"` itself. `MaybeLead` (`LRG_OStim.psc:3197`) then returns for 150 s.
The GAME builder's report states this explicitly as a hand-over ("SERVER-SIDE NOTE: do not decorate
a `lead;who=npc` that answers a `lead_npc` intent with a long `hold=`, or the game will hold her
anyway") and the server side did not honour it. This defeats G2's "'You lead / do what you want /
surprise me' hands her the lead" on the game side; flow 20 only asserts the *server's* two memory
clocks (`20_lead_hold.php:39-40`) and never looks at the emitted line, so the suite is blind to it.
(`fxCarrier()` in `adapter.php:400-404` even classifies this 6-key line as "the hold carrier", so
`fxReal()` filters it out of the scenarios' view.)

**Smallest fix (server):** in `lrgDecorate()`, before the hold is added -
`if ($do === 'lead' && ($kv['who'] ?? '') === 'npc' && ($turn['intent']['kind'] ?? '') === 'lead_npc') { return $kv; }`
(or make `lrgHoldSeconds()` return 0 for that case).

### D2 (MAJOR, server) - a hedged "yes" or an unrelated "ok" executes the sex act she proposed (R11)

`lrgRecogniseIntent()` (`lib/lrg_intent.php:151-163`) tests the pending-proposal yes/no branch
**before** the blockers and before the ordered verb scan, and its yes pattern only anchors the first
word: anything after it is ignored. `lrgIntentResolve(..., 4)` then resolves the proposal's act at
ceiling 4, and the safety net (`yes` is in `intent.net_kinds`) carries it out. Probe 91, verbatim:

```
PROBE91 "yes, but not now"            kind=yes conf=high act=vaginal scene=OARE_StandingCarryingSex
PROBE91   wire: ok=1;..;do=goto;scene=OARE_StandingCarryingSex;hold=150
PROBE91 "yes, not yet"                kind=yes conf=high act=vaginal  -> same goto
PROBE91 "okay, hold on"               kind=yes conf=high act=vaginal  -> same goto
PROBE91 "sure, in a minute"           kind=yes conf=high act=vaginal  -> same goto
PROBE91 "ok, so what were you saying" kind=yes conf=high act=vaginal  -> same goto
PROBE91 "go ahead and tell me more"   kind=yes conf=high act=vaginal  -> same goto
```

"okay, hold on" is the worst case: the player asked her to **hold on**, and the deterministic path
navigates into vaginal sex instead. (`hold` would have matched in `lrgIntentScan`, but the proposal
branch pre-empts the scan.) `not now` / `maybe later` / `no` / `I would rather not` are read
correctly. This is the one place in v0.3 where a mis-read word makes a sex act happen, and R11 -
which addendum 3g explicitly keeps - says only a yes makes it happen.

**Smallest fix (server):** in the yes branch, (a) run `lrgIntentBlocked($t)` first and fall through
when it is non-empty, and (b) accept the yes only when the utterance is essentially the affirmation -
e.g. require `preg_match('/^(yes|yeah|...)\b[\s,.!]*$/')`, or fall through when the rest of the
utterance still matches another actionable pattern (`hold`, `stop`, `not now`, ...).

### D3 (MAJOR, server) - "that's enough, fuck me" ends the scene

`lrgIntentStop()` matches `that's enough` (`lib/lrg_intent.php:89`). The override that is supposed
to let a framed request win (`:171`, comment: *"A request frame plus another actionable kind in the
same breath wins over it ('that's enough, fuck me')"*) tests `lrgIntentFrame($t)` on the **whole**
utterance, and `LRG_INTENT_FRAME1` (`:31`) is `^`-anchored, so a request that does not start the
sentence never counts as framed. Probe 90:

```
PROBE B "that's enough, fuck me" -> {"kind":"stop","conf":"high","kv":{"do":"stop"},...}
PROBE B wire: ...|ExtCmdLRG_SceneControl@ok=1;..;do=stop;hold=150
```

The net ends the scene when the player asked for sex. (`"stop teasing me and fuck me"` is handled
correctly by the `(?!\s+\w+ing\b)` lookahead - only the `enough` family is affected.) Fails in the
safe direction, but it is a G1 reliability defect and it contradicts the code's own stated contract.

**Smallest fix (server):** in the `$isStop` branch, judge the frame on the matched fragment, i.e.
`$frame || lrgIntentFrame((string) $scan['frag'])`, or treat `$scan['kind'] !== 'stop'` with a
resolved payload as sufficient to win.

### D4 (MINOR, both/docs) - `command_confirm_seconds` is 20, the game side requires >= 32

The GAME report hands over: *"`command_confirm_seconds` must be >= 20 + `fSayFirstMaxWait` (32 s with
the defaults), because a parked command's funcret only fires when the OStim call really happens."*
`config/lrg_config.default.json` ships `command_confirm_seconds: 20`, and `lrgNotePending()`
(`lrg_actions.php:1257`) stamps at **emit** time while `ReportResult` only follows the real OStim
call (`LRG_OStim.TickPendingVerb:697-723`, `TickPendingStart:1171-1172`). Every `wait=end` start that
uses its 12 s budget, plus CHIM poll latency, will trip
`WARN command not confirmed by the game` (`lrg_actions.php:832-843`) although nothing was lost.
Log-only (no retry), so cosmetic - but it poisons exactly the G6 diagnostic the round added.

**Smallest fix:** set `command_confirm_seconds` to 35 in the default config (or compute it as
`20 + fSayFirstMaxWait`).

### D5 (MINOR, server/docs) - two different log lines start with `turn npc=`, contradicting the build report and G6

The SERVER report claims *"The old second `turn npc=... in_scene=...` log line was deleted ... two
lines with the same prefix made the log unparseable by prefix, which is what G6 exists to prevent."*
It was not deleted, only reworded: `lrg_actions.php:799-802` still emits a second
`turn npc=%s mode=%s%s offer_start=... reasons=... aff=... interest=...` line on every speech and
initiative turn, next to the G6 line at `:796`. A third variant exists at `:628`
(`turn npc=$npc in_scene BLOCKED ...`). `PROTOCOL.md:307-311` promises "Three fixed, greppable lines
per turn" with `turn ...` as one of them.

**Smallest fix:** rename the gate line's prefix (e.g. `gate npc=...`) or fold its three remaining
unique fields (`offer_start`, `reasons`, `aff`) into the G6 `turn` line.

### D6 (MINOR, docs) - `sess=` is documented as "every push" but the ordinary `ev=end` does not carry it

`PROTOCOL.md:54` (§1.2 table) says `sess` | *"the session tag, as in 1.1"* | **"every push"**, while
the same section's `[v1]` text (`:48`) says `ev=end` carries only `ev, npc, cid, scene, byglue`, and
`:59` gives the post-load end shape *with* `sess`. The game matches the two explicit shapes and not
the table: `PushState` adds `sess` to every push (`LRG_OStim.psc:3102`), the post-load close adds it
(`:312`), but the ordinary end in `FinishThread` does not (`:2843`). No functional consequence
(`lrgNoteSession()` is only driven from `lrg_npcstate`, `lrg_core.php:175`), but the contract
disagrees with itself and with the code.

**Smallest fix (docs):** change the table row to "every push except the ordinary `ev=end`", or add
`;sess=" + Main().SessionTag()` at `LRG_OStim.psc:2843`.

### D7 (MINOR, game) - the documented stop fallback is unbound by default

`PROTOCOL.md:179` and `:154` rest the non-adult / blocked-scene case on "the stop hotkey stays".
`settings.ini` ships `iKeyStopScene = 0` and `LRG_Main.RegisterKeys` (`:187-189`) only registers a
key when `> 0`, so out of the box **there is no glue stop hotkey**. In the two states where the
spoken stop is weakest this matters: with an unconfirmed adult every glue action including
`ChangeIntimacy` is hidden (`lrg_actions.php:609-612`), and in `scene_blocked` the deterministic net
is switched off by design (`lrgSafetyNet` requires `empty($turn['scene_blocked'])`, `:1325`), leaving
only the LLM's own choice. OStim's own keys remain as the real fallback, which is why this is minor
rather than major.

**Smallest fix (game/docs):** ship a real DXScanCode default for `iKeyStopScene`, or change the two
PROTOCOL sentences to name OStim's own end-scene key as the fallback.

### D8 (MINOR, server) - a proposal is recorded even when the same reply already carried an act change

`lrgRecordProposal()` (`lrg_actions.php:1372-1386`) runs unconditionally at the end of the post-gate
whenever `$turn['propose'] !== null` and she spoke. On a proposal turn `ChangeIntimacy` deliberately
stays offered (`:710-715`, correct - hiding it would take the stop rail with it), so a model that
both talks and picks a `goto` leaves a *pending* proposal behind for a change that already happened;
a "yes" in the next 90 s then fires a second act change (and, via D2, a mere "ok" does too). The same
function rewrites the scene row, refreshing `updated_at`, which delays
`lrgGetActiveScene()`'s "a newer snapshot says the scene is over" auto-close (`lrg_core.php:359`).

**Smallest fix:** skip `lrgRecordProposal()` when `$emitted` already contains a `do=goto`
(pass `$emitted` in, as `lrgSafetyNet` already gets it).

### D9 (MINOR, server) - the proposal cooldown only starts when she speaks

`lrgPickProposal()`'s cooldown/`max_per_scene` counters (`_last_prop_at`, `_proposals`) are bumped
only inside `lrgRecordProposal()`, i.e. only when `lrgSpokenThisTurn() !== ''` (`:1375`). If the model
answers with an empty message, the proposal is neither recorded nor counted and the very next turn
may pick the same act again. Nothing happens on screen (that is the point of the guard), so this is a
prompt-cost issue, not nagging - but "cooldown/max enforced server-side" is only true for turns she
spoke on.

**Smallest fix:** bump `_last_prop_at` / `_proposals` whenever the proposal was *put into the notes*,
and clear them if she said nothing.

---

## SAFETY AUDIT - what I confirmed holds (with code evidence)

* **S1 The server never starts a scene by itself.** The only emitter of `ExtCmdLRG_StartIntimacy` is
  the LLM's own line inside `lrgPostProcessActions` (`:1080-1092`), gated on `gate['ok']` and
  `mode in {private, follow}`. `lrgSafetyNet()` can only ever choose
  `LRG_ACT_CLOTHING` or `LRG_ACT_CONTROL` (`:1333`) and requires `mode === 'scene'`; `lrgHoldCarrier()`
  emits only `do=lead`. Probe C: outside a scene, `"fuck me"` recognised at `conf=high` in mode
  `private` produced **no wire line at all**.
* **S2 The net needs a game-confirmed running scene.** `$turn['scene_confirmed']` is taken from the
  periodic snapshot (`lrg_npc_state.ostim === '1'`) and an unresolved `Error: no scene is running`
  (`:645`, `lrgResultSaysNoScene` `:820-826`), never from the scene row's age; the net re-checks it
  (`:1326`) plus `mode === 'scene'`, `!scene_blocked`, `can_act`.
* **S3 High confidence only, and never a negated / questioned / quoted / hypothetical utterance.**
  `lrgSafetyNet:1327` requires `conf === 'high'`; `lrgIntentBlocked` (`lrg_intent.php:69-76`) forces
  `kind=none` for hypothetical/quoted/negated/question. Verified in `test_intent.php` section A/H
  (105/105) and by probe: `"don't get naked yet"`-shaped input never resolves. **Exception, by
  design: `stop`, `yes`, `no` bypass the blockers** - which is what D2 and D3 exploit.
* **S4 Kill switch / SHARMAT / module off.** `lrgPostProcessActions` runs the whole net + carrier
  block inside `lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')` (`:1149`); `intent.enabled=false`
  makes the recogniser return `kind=none` (`lrg_intent.php:141`); `intent.safety_net=false` skips it
  (`:1322`). `test_gates.php` section 10 (kill switch variant) passes. Dry-run is game-side and every
  verb refuses with `Error: dry-run mode, nothing changed` before touching OStim
  (`LRG_OStim.psc:2257-2261`, `:937-945`, `:2095-2099`).
* **S5 Adult + player-participant rails untouched.** `lrgPrepareTurn` re-checks a fresh snapshot with
  `adult=1`, `witkid=0`, `on=1`, profile not `never` on **every** scene turn (`:600-628`); the game
  re-checks `LRG_Profile.IsAdult` on every verb but `stop` (`LRG_OStim.psc:2251-2254`, `:2052-2055`)
  and builds the thread from `OActorUtil.ToArray(player, npc)` (`:1095`).
* **S6 Addendum 3 really was carried out.** No `Decline` action, no refusal-marker scan of her reply,
  no per-act decline memory anywhere in `server/lorerim_glue/**` (grep for
  `decline|refusal|declined`: only prose in the guidance strings and R11's `_prop_no`). `_prop_no` is
  the R11 "the player said no to HER proposal" memory, which addendum 3g explicitly keeps, and
  `lrgPickProposal:909` honours it.
* **S7 The hidden-action filter cannot hide the stop path.** In the normal scene branch only
  `Start`/`Invite` (and `RequestAct` on a proposal turn) are hidden (`:702-715`); in `scene_blocked`
  `ChangeIntimacy` survives whenever `can_act` and only `do=stop` passes the gate (`:624-627`,
  `:1097-1100`). Game side, `stop` is the first branch of `CmdControl`, is exempt from the `ok=1`
  ordering, cancels a parked verb and works during thread construction
  (`LRG_OStim.psc:2208-2238`); `StopScene` is ungated by `IsEnabled()` (`:2643-2657`). See D7 for the
  one gap (unbound hotkey default).
* **S8 Compatibility matrix holds.** No new `do=` verb (`RequestAct` resolves to `do=goto` server
  side, `:1108-1118`), no new `ev` (`sync=1` rides an ordinary `ev=change`, `LRG_OStim.psc:300` /
  `lrg_actions.php:317`), and every new key reads neutral when absent
  (`ParamGet` -> `""` -> `AnnounceBudget 0`, `ApplyHold` returns, `nowarp != "1"`).
  `lrgNoteSession` does nothing without `sess` (`lrg_core.php:187`).
* **S9 Wire shapes match.** `StartIntimacy`, `goto`, `faster/slower/speed`, `stop`, `hold/release`,
  `climax`, `pullout`, `winddown`, `furniture`, `lead`, `undress/dress` all emit the §2 key order with
  the decoration keys last (`warp|nowarp`, `wait`, `hold`); `warp` and `nowarp` are mutually exclusive
  by construction (`lrgDecorate:1229-1231`). Snapshot `sess` sits before `class`, `fac` stays last
  (`LRG_Profile.psc:347-351`); `next` stays the last scene key (`LRG_OStim.psc:3130`). All 35 MCM ids
  in `config.json` have a matching `settings.ini` default line (checked one by one - the "missing ini
  key reads FALSE/0" trap is clear).
* **S10 G4.** Three closing paths all present and reachable: the `Error: no scene is running` funcret
  (`lrgRecordResult:472-474`), the session-tag mismatch (`lrgNoteSession:188-191` ->
  `lrgCloseAllScenes`), and the post-load `ev=end` / forced `ostim=0` snapshot
  (`LRG_OStim.psc:308-314`, `LRG_Main.psc:123-128`). Flow 22 covers all three.

---

## VERDICT: **FIX FIRST**

Three defects should not reach playtest 7: **D1** (the lead hand-over is cancelled by the server's own
`hold=`, a G2 goal of this very round, and an explicitly handed-over cross-side requirement),
**D2** (a hedged "yes" or an unrelated "ok" executes the sex act she proposed - the only path in v0.3
where a mis-read word makes a sex act happen, against R11 which addendum 3g keeps), and **D3**
("that's enough, fuck me" ends the scene). All three are small, server-side-only edits in
`lrg_intent.php` / `lrg_actions.php`, with no wire change and no game rebuild: the installer can ship
the game half as it stands. D4-D9 are minor and can ride along.

Nothing I found breaks a hard safety rail: the server still cannot start a scene, the adult and
player-participant checks are intact, the kill switch holds, the net stays inside a game-confirmed
running scene, and `stop` still wins every tie in the game.
