# pt8 INTIMACY lane - handoff (v0.4.0)

Everything the other lanes need from lane D (intimacy). Three sections, as the round prompt asks:
(a) MCM `config.json` + `settings.ini`, (b) the additive wire keys for `PROTOCOL.md`,
(c) `LRG_ACTIONS_VERSION`.

---

## 0. READ THIS FIRST - the missing-ini-key trap

**Six of the eight MCM ids below are still missing from `MCM/Config/LoreRimGlue/settings.ini`, and a
missing ini key reads as 0 / FALSE.** Three of them are ON-by-default toggles, so until their default
lines exist:

| id | what its absence does |
|---|---|
| `bPrivacyDoors:Intimacy` | the closed-door privacy fix is OFF - a busy inn is never private, exactly as the owner complained |
| `bFixControlsAfterScene:SceneTalk` | the camera / controls self-repair never runs |
| `fListenerHold:SceneTalk` | reads 0.0 = the listener hold is OFF, so the "left in OStim director mode" fix does nothing |

The game says so out loud: `LRG_OStim.Maintenance()` writes one line per game load,

```
NOTE these 0.4 settings read OFF (a missing settings.ini default line reads as 0): bPrivacyDoors bFixControlsAfterScene fListenerHold=0
```

so `grep "read OFF" lorerim_glue.log` after the first load is the acceptance test for this handoff.

`bPaidIntimacy` and `fPriceMultiplier` are **already** in `config.json` and `settings.ini` - thank you.
For `bPaidIntimacy` there is a second escape hatch on the server
(`paid_intimacy.ignore_game_switch`, default false) in case its line is ever lost again; the other
three have none, because they are game-side behaviour.

---

## 1. (a) MCM entries

### 1.1 `MCM/Config/LoreRimGlue/config.json` - page **Intimacy**

Already present and correct (no change needed): the `"Paying for it"` header with
`bPaidIntimacy:Intimacy` and `fPriceMultiplier:Intimacy`.

**To add, under the existing `{ "text": "Privacy", "type": "header" }`, after the two witness-radius
sliders** (that keeps the three privacy numbers together):

```json
{ "id": "bPrivacyDoors:Intimacy", "text": "A closed door makes a room private", "type": "toggle", "help": "Until now anyone awake and nearby counted as a witness even through a wall or a shut door, so a room in a busy inn could never be private - which is exactly what went wrong. With this on, somebody who cannot see either of you no longer counts just for being close, as long as the door of the room you are in is shut. Anyone who can actually SEE you always counts, door or no door. Off = the old behaviour.", "valueOptions": { "sourceType": "ModSettingBool" } },
{ "id": "fHearRadius:Intimacy", "text": "Heard-through-the-room distance", "type": "slider", "help": "How close somebody has to be to count as a witness when they cannot see you and no door is shut between you. Lower it if scenes are refused in places that feel private, raise it if people walk in on you.", "valueOptions": { "min": 200, "max": 1200, "step": 50, "formatString": "{0}", "sourceType": "ModSettingFloat" } },
{ "id": "fDoorRadius:Intimacy", "text": "How near a door counts as this room's door", "type": "slider", "help": "Doors within this distance decide whether the room you are standing in is shut. Small rooms need less, a hall needs more. Every door in reach has to be closed for the room to count as private - including the one you came in through.", "valueOptions": { "min": 100, "max": 600, "step": 25, "formatString": "{0}", "sourceType": "ModSettingFloat" } },
```

### 1.2 `config.json` - page **Scene talk**, header `"After a scene"`

**To add after `fOutroFar:SceneTalk`** (the last row of that header today):

```json
{ "id": "fListenerHold:SceneTalk", "text": "Keep talking to her after a scene for", "type": "slider", "help": "When a scene ends, what you say next is meant for her - but at that exact moment the game has nobody under your crosshair, so CHIM can answer as the Narrator instead and she never hears you. With this on she stays the one you are talking to for this long after the scene, and for as long as the conversation keeps going; then normal routing comes back. She is also let go at once if you walk away, turn to somebody else, a fight starts, or a new scene begins. 0 = off.", "valueOptions": { "min": 0, "max": 60, "step": 5, "formatString": "{0} s", "sourceType": "ModSettingFloat" } },
{ "id": "fListenerGrace:SceneTalk", "text": "Let go after her goodbye", "type": "slider", "help": "Once she has finished her goodbye and you have said nothing more, this is how long the game waits before handing the conversation back to whoever you are looking at.", "valueOptions": { "min": 0, "max": 10, "step": 0.5, "formatString": "{1} s", "sourceType": "ModSettingFloat" } },
{ "id": "bFixControlsAfterScene:SceneTalk", "text": "Give me my camera back after a scene", "type": "toggle", "help": "If a scene ever leaves you in OStim's free camera, or without movement or look controls, the game puts them back a moment after it ends. It only ever does this when they really are still gone - never during a quest scene, a menu or another scene - and it writes a line in the log every time it fires.", "valueOptions": { "sourceType": "ModSettingBool" } },
```

### 1.3 `MCM/Config/LoreRimGlue/settings.ini`

**`[Intimacy]`** - add (the two paid-intimacy lines are already there):

```ini
; v0.4 - a closed door makes a room private (owner complaint 2). Until 0.4 the witness scan counted
; every awake adult inside fHearRadius with NO line-of-sight test at all, so a room in an occupied
; inn could never reach zero witnesses whatever door was shut. Seen is still seen: an open doorway,
; a shared room or a balcony above still counts. bPrivacyDoors = 0 restores the old behaviour.
; A MISSING key here reads as 0, which would switch the whole fix off - keep these three lines.
bPrivacyDoors = 1
fHearRadius = 600
fDoorRadius = 250
```

**`[SceneTalk]`** - add:

```ini
; v0.4 - who you are talking to after a scene (owner complaint 1, "it leaves u in the ostim director
; mode"). The scene used to end by handing CHIM's routing back on the spot, and right then there is
; nobody under the crosshair - so CHIM answered as the Narrator and she never heard a word of it
; (playtest 8: three times in thirteen minutes, every line naming her). She now stays the listener
; for fListenerHold after the scene, plus the goodbye window on top, and for as long as either of you
; keeps talking. Worst case 2 x fListenerHold + fOutroHold + 30 s = 95 s at these defaults.
; fListenerHold = 0 switches it off. A MISSING key reads as 0, i.e. off - keep these lines.
fListenerHold = 20
fListenerGrace = 2.5
; ... and if a scene ever leaves the camera or the controls with OStim, put them back. It only fires
; on real evidence (free camera, or movement / look controls off) and logs every time it does.
bFixControlsAfterScene = 1
```

### 1.4 Existing keys this round reuses (no new rows)

`bForceListener:Intimacy` (master switch for the listener mechanism, the new hold included),
`fOutroFar:SceneTalk` (the hold's distance exit), `fOutroHold:SceneTalk` (added on top of the hold),
`fWitnessRadiusInterior/Exterior:Intimacy` (the outer loop of the witness scan, unchanged),
`bIntimacyEnabled:Intimacy`, `bDryRun:General`, `bKillSwitch:General`.

---

## 2. (b) Additive wire keys for `PROTOCOL.md` -> v0.4

Every key is additive with a documented "absent" meaning, so a v0.3.1 game script works against the
new server and the new script works against a v0.3.1 server.

### 2.1 game -> server, `lrg_npcstate` (the snapshot, still `v=2` - no bump needed)

| key | values | meaning | absent means |
|---|---|---|---|
| `door` | `0` / `1` | 1 = the player is in an INTERIOR and every door within `fDoorRadius` is shut. Emitted right after `interior=`. | say nothing: the server words nothing about the room and the witness count is whatever the game sent |
| `paidok` | `0` / `1` | MCM `bPaidIntimacy`. | **ON.** An old game script has no such key at all, and `paid_intimacy.enabled` decides on its own. An explicit `paidok=0` is OFF. *Absent is not 0.* |
| `pm` | `0.25`..`4.00`, two decimals | MCM `fPriceMultiplier`, already clamped game-side (`<= 0` is sent as `1.00`) | 1.0. The server also reads absent or `<= 0` as 1.0 - two independent guards, because a 0 here would make every NPC free |

`pgold=<n>` (the player's purse) was **already** on the snapshot and is unchanged - the design's "add
pgold=" was already done in v0.1. It is what the affordability check reads; absent reads as 0, and an
affordability check that cannot be made never accepts.

Also unchanged but newly load-bearing: `wit` is still one number with one meaning. The door rule
changes how the GAME counts it, never how the server reads it.

### 2.2 game -> server, `lrg_scene`

| key | on | meaning | absent means |
|---|---|---|---|
| `paid` | **every push** of a paid scene, including `ev=start` and `ev=end` | the gold that REALLY left the player's purse for this scene, game-confirmed | 0 = the scene was free |

On every push on purpose: the server reads it from the last stored push at `ev=end`, and any single
push can be de-duplicated away. It is the only truth the halved relationship gain, the
`lrg_romance.gold_accepted` total and her memory are built from - never the `pay=` the server sent,
because the game re-checks the purse a moment before the scene really begins.

### 2.3 server -> game, `ExtCmdLRG_StartIntimacy`

| key | values | meaning | absent means |
|---|---|---|---|
| `pay` | `1`..`200000` | gold to move from the player to the NPC at the moment the scene really starts | 0 = a free scene |

Already bounded by the server before it is sent: never more than a figure the PLAYER said out loud,
never more than his purse, never below her own floor, never for an NPC who is not for sale.
The game re-checks the purse and refuses outright when it is short.

### 2.4 game -> server, refusal reason (closed list, PROTOCOL 1.6)

One new string, returned through `ReportResult` / `funcret`:

```
the player does not have that much gold
```

The server maps it onto her next turn ("What <npc> agreed to did not happen: the player could not
actually pay"). Any string containing `gold` hits that mapping, so a future variant still reads right.

### 2.5 Log lines (PROTOCOL 9)

| shape | where |
|---|---|
| a **new prefix** `price npc=... for_sale=... tier=... band=...d infl=... stance=... days=... floor=... token=... secret=... renown=x... repeat=x... mult=... pgold=... offer=...(said\|memory\|quote) kind=... decision=... why="..."` | one line per turn that mentions coin, gated by `paid_intimacy.log_prices` |
| a new tail on the fixed `turn` line: ` paid=floor:110/band:1-4d/tier:poor/infl:0.8/stance:indifferent/quoted:-/offer:110(said)/purse:easily/decision:accepted` | additive, exactly like the existing `outro=` and `also=` tails; only when coin is in play |
| two new components on the `gate` line: ` wit=<n>/<n> door=shut\|open\|-` | so a `reasons=witnesses` complaint can be answered from the log |
| a `WARN` line: `WARN the player's line went to the Narrator <n>s after a scene with <npc> ended (CHIM found no NPC under the crosshair)` | `narrator_warn_seconds` (120); it is how the owner sees the listener hold working - it should stop appearing |
| game lines: `paid <n> gold to <npc>`, `NOT paid: the player no longer has <n> gold`, `DRY RUN WOULD PAY <n> gold to <npc> - no coin moved`, `listener forced to <npc> (the scene ended - ...)`, `listener released (<one of eleven reasons>)`, `controls repaired after the scene (camera state <n>, movement <0\|1>, look <0\|1>)`, `NOTE these 0.4 settings read OFF ...` | all through the existing `GAME` channel |

`decision` is one of `n_a | not_for_sale | free | needed | accepted | below_floor | unaffordable`.

### 2.6 Nothing was removed or re-read

No existing key changed meaning, no key was dropped, and `v=2` stays. Nothing on the server tests
`state['v']`, so `door=` needed no bump.

---

## 3. (c) `LRG_ACTIONS_VERSION`

```
LRG_ACTIONS_VERSION = 10        (was 9)
```

`lib/lrg_actions.php:25`. The bump is required because the **`BeginIntimacy` catalog row changed**:

```php
'parameters_json' => ['type' => 'object', 'properties' => [
    'target' => ['type' => 'string', 'description' => 'The player'],
    'amount' => ['type' => 'integer', 'description' => 'ONLY when accepting an offer of gold: ...'],
 ], 'required' => []],
'metadata' => $meta('target', $ownMove) + ['parameter_template' => '{{parameters.amount}}'],
```

Two things about it that must not be "tidied up" later:

1. **`required` stays empty.** `queueFunctionExecutionCommand()` (`functions.php:2526-2529`) drops the
   whole action when a row has required parameters and one came back empty - which would kill every
   ordinary free scene.
2. **`parameter_template` is the only way the LLM's amount reaches us.** `functions.php:2542` builds
   the wire line from `$executionContext["parameter_string"]`, which
   `buildConfiguredActionParameterFromMetadata()` (`:137-184`) resolves from that template against the
   decoded JSON the LLM produced. The post-gate reads the result two ways - an `amount=` k=v or a bare
   integer - and **anything else is 0**, so a catalog row left over from version 9 (which puts the
   target's NAME there) degrades to a free scene instead of being misread as a figure.

The deploy has to reinstall the catalog rows: `lrgEnsureActions()` does it by itself the first time it
sees the new `LRG_ACTIONS_VERSION` (the marker file `data/.actions_v10` does not exist yet), so nothing
extra is needed - but the row must reach the DB before the first paid turn, or every acceptance reads
`amount = 0` and every scene is free. `grep "action catalog: rows installed (v10)"` confirms it.

---

## 4. What I did NOT touch (and who owns it)

| thing | owner |
|---|---|
| `LRG_Main.CurrentVersion` -> **400** | the LRG_Main lane. My scripts do not read it, but the server's "script version" reasoning does |
| `manifest.json` -> 0.4.0 | the packaging lane |
| `PROTOCOL.md` -> v0.4 | the protocol lane - section 2 above is the whole of my contribution |
| `MCM/Config/LoreRimGlue/config.json` + `settings.ini` | the MCM lane - section 1 above is ready to paste |
| `prerequest` / `functions` / `prompts` / `context_pre` / `postrequest` / `globals` / `preprocessing` | the server-hook lane. **Everything of mine is reachable from `lrg_core.php` / `lrg_actions.php` and needs no hook change**: `lrgPrepareTurn()` already runs from `prerequest`, the post-process gate is already registered, and `prompts.php` already emits `lrgStaticGuidance()` / `lrgVolatileGuidance()` |
| `tools/flows/run_flows.php` and the deploy / install scripts | other lanes |
