# LoreRim Glue v0.3 - buildable change spec

Status: COMPLETE. Written for two builders working IN PARALLEL without talking to each other:

| builder | owns | reads this doc for |
|---|---|---|
| **SERVER** | `glue/server/lorerim_glue/**`, `glue/PROTOCOL.md`, `glue/tools/*.php`, `glue/tools/flows/**` | §1-§13, §16, §17 |
| **GAME** | `glue/game/LoreRimGlue/Source/Scripts/*.psc`, `MCM/Config/LoreRimGlue/{config.json,settings.ini}`, `glue/tools/compile.ps1` | §1, §2, §14, §15, §17 |

Neither builder edits the other's files. Every wire change in §2 is **additive**: an old game must keep working with a new server and a new game with an old server (§1.3 is the matrix both sides must satisfy).

Grounded in `research/pt6-forensics.md`, `research/pt6-server-audit.md`, `research/pt6-game-audit.md` and `glue/OWNER_ADDENDA.md` (all three addenda are handled; addendum 3 arrived after the diagnosis stage and **removes** mechanisms the round prompt asked for - see §0.2).

---

## 0. Ground rules for this round

### 0.1 What must NOT change
- Every rail of `PROTOCOL.md §0`: adults only / fail closed, the player is always a participant, `stop` wins every tie, kill switch, the NPC must be willing **before** a scene, forced / non-consensual / creature scenes never indexed, no file of LoreRim / CHIM / OStim / OARE and no OStim MCM value is ever written.
- The hard gates (`lrgEvaluateGates`), the profile / status-rule system, the interest word model, the tier ladder as a model of *her own* pacing, the weapons preparation, and **today's OStim gate hold** (`HoldOStimGates` / `ReleaseOStimGates` / `GlueUndressNode`, `LRG_OStim.psc:666-771`). Nothing in this spec weakens them; §14.2 only fixes two seam bugs *around* `GlueUndressNode` without touching its logic.
- No example dialogue anywhere (`PROTOCOL.md §0`): this spec writes **rules and vocabulary**, never a line an NPC says. That rail is also why §8.3 rejects the "small pool of fallback lines" option the round prompt floated.

### 0.2 Owner addendum 3 overrides the round prompt
Verbatim: *"also lets get rid of the possibility of them denying u during the romance … they can only deny beforehand, your requests for whatever in the middle wont be denied."*

Consequences, binding for both builders:

| removed - do not build | kept |
|---|---|
| a `Decline` action / catalog row | her consent **before** a scene: gates, interest, her own choice of `BeginIntimacy`; a refusal there is final and never overridden |
| refusal-marker detection in her reply | R11: when **she** proposes a sex act, the **player** may say yes or no, and a no is remembered (`_prop_no`) |
| per-act decline memory, "a decline is final for that act" | `stop` always works; kill switch; adults only |
| the pacing ceiling as a limit on **player** requests (§3.6) | the pacing ceiling as a limit on **her own** lead picks and proposals |

The only thing that may still say no inside a scene is the **game** (act not installed, filtered out, wrong actors / furniture, unreachable with warp off). That case is answered by the CANT directive (§4.3), in words, in the same turn - never silence.

### 0.3 Design priorities, in order
1. Reliability over cleverness. Where two mechanisms would work, this spec picks the one with fewer states.
2. Cost. The owner pays per token: a turn that cannot do anything useful is dropped **before** CHIM's MAIN semaphore (`main.php:193`), as dropped initiative ticks already are.
3. Nothing that works today regresses.

---

## 1. Versions and compatibility

### 1.1 Bumps (both builders)
| constant | file | 0.2 | 0.3 |
|---|---|---|---|
| `LRG_VERSION` | `lib/lrg_core.php:13` | `0.2.0` | `0.3.0` |
| `version` | `manifest.json` | `0.2.0` | `0.3.0` |
| `LRG_ACTIONS_VERSION` | `lib/lrg_actions.php:24` | 7 | **8** |
| `LRG_INDEX_VERSION` | `lib/lrg_scene_index.php:28` | 3 | **4** (new per-scene `roles`, §6.1 - forces one rebuild) |
| `LRG_SCHEMA_VERSION` | `lib/lrg_core.php:14` | 2 | **2, unchanged** - every new state key fits into existing `jsonb` payloads, so there is no migration |
| `LRG_Main.CurrentVersion` | `LRG_Main.psc:11` | 200 | **300** |
| snapshot `v=` | `LRG_Main.PlaceFacts` | 2 | **2, unchanged** - only additive keys |

### 1.2 New `manifest.json`
```json
{ "name": "lorerim_glue", "display_name": "LoreRim Glue",
  "description": "<unchanged>", "version": "0.3.0" }
```

### 1.3 Compatibility matrix - both builders must satisfy every cell
| | game 200 (old) | game 300 (new) |
|---|---|---|
| **server 0.2 (old)** | today | new game must behave exactly as today when `wait` / `hold` / `nowarp` are absent (missing key = neutral reading, `PROTOCOL §0`) |
| **server 0.3 (new)** | `wait` / `hold` / `nowarp` are ignored by the old game -> today's timing and today's warp policy; everything else (intent, directive, safety net, acts, stale-state-by-funcret, logging) works unchanged, because it is all server side | full feature set |

Rules that make this true:
- The server **never** invents a new `do=` verb: `RequestAct` is resolved server side into `do=goto` (§6.4). An old game therefore needs no new branch.
- The server never sends a new **required** key. `hold`, `wait`, `nowarp`, `sess`, `prev` are all optional with a neutral default.
- The game never *requires* a new key either: `wait` absent = act immediately (today), `hold` absent = 0, `nowarp` absent = 0 (today's warp policy).
- The game's new `ev=sync` message is ignored by an old server (unknown `ev` falls through `lrgStoreScene`, which only special-cases `end`) - harmless.

---

## 2. Wire contract v0.3 (PROTOCOL.md update - SERVER owns the file, GAME must match)

Everything here is **[NEW v0.3]**; all v1/v2 keys stay.

### 2.1 Game -> server

**`lrg_npcstate`** - one new key, inserted before `class` (which stays last but one; `fac` stays last):

| key | value | how (game) |
|---|---|---|
| `sess` | the session tag, `1000..9999` | `LRG_Main.sessionTag`, re-rolled in `LRG_Main.Maintenance` on every load |

**`lrg_scene`** - two new keys plus one new `ev`:

| key | value | when |
|---|---|---|
| `sess` | as above | every push |
| `prev` | the previous **non-transition** scene id, `""` at the start | on `ev=change` |
| `ev=sync` | with `running=0` or `running=1` | **once** from `LRG_OStim.Maintenance`, on both branches, before anything else is sent (§14.4) |

`ev=sync;running=0` carries only `ev, npc, cid, sess, running` (`npc` may be empty when the save had no partner). `ev=sync;running=1` carries the full `ev=change` shape plus `running=1`.

Server handling:
- `ev=sync;running=0` -> close **every** active scene row at once (`lrgStoreScene($npc, ['ev'=>'end'] + payload)` for the row's own NPC, whatever `npc` the sync names).
- `ev=sync;running=1` -> treat exactly like `ev=change`, and stamp `_sess`.
- `sess` on any message -> stamp `_sess` into the scene payload; a snapshot whose `sess` differs from the open row's `_sess` closes that row (§10.2).

### 2.2 Server -> game (commands)

| Code | new param keys |
|---|---|
| `ExtCmdLRG_StartIntimacy` | `wait=end` (always sent by 0.3) |
| `ExtCmdLRG_SceneControl` | `hold=<0..600>` on every verb · `nowarp=1` on `goto` / `furniture` · `wait=begin` on `goto` / `furniture` |
| `ExtCmdLRG_Clothing` | `hold=<0..600>` · `wait=begin` |
| `ExtCmdLRG_RequestAct` | **server-only**, like `ExtCmdLRG_Invite`: the post-LLM gate resolves it to a `ExtCmdLRG_SceneControl@do=goto;…` line and the `RequestAct` line itself never reaches the game |

Key meanings (the game's reading, §14):
- **`hold=<n>`** - "the player steered `n` seconds ago; do not fire a scene-lead tick until `now + n`". Clamp `0..600`. Missing = `0` = today.
- **`nowarp=1`** - "if this position is not reachable, refuse; never fade-jump". Missing = `0` = today's `bAllowWarp:Intimacy` behaviour. A **new key** deliberately, not a re-reading of `warp=0`: re-reading `warp=0` would make a new game start refusing plain gotos an old server sends (game audit §6.2).
- **`wait=begin|end`** - hold the OStim call until her voice is out (§14.5). Missing = act immediately = today.

Exact shapes the server emits (key order fixed; the offline tests match on substrings), after `ok=1;cid=..;npc=..`:

```
scene=<id>;undress=<0|1>;furn=<type|"">;fscene=<id|"">;maxwit=<n>;folok=<0|1>;wait=end
do=goto;scene=<id>[;warp=1][;nowarp=1][;wait=begin][;hold=<n>]
do=furniture;furn=<type>;scene=<id>[;nowarp=1][;wait=begin][;hold=<n>]
do=<undress|dress>;who=<npc|player|both>;part=<all|body|head|hands|feet>[;maxwit=<n>;folok=<0|1>][;wait=begin][;hold=<n>]
do=faster|slower|stop|hold|release|pullout            [;hold=<n>]
do=speed;speed=<n>                                    [;hold=<n>]
do=climax;who=<npc|player|both>                       [;hold=<n>]
do=winddown;scene=<id|"">;warp=<0|1>;linger=<s>       [;hold=<n>]
do=lead;who=<npc|player|auto>                         [;hold=<n>]
```
`warp=1` and `nowarp=1` are mutually exclusive; the server never sends both.

### 2.3 New error reasons (closed list, PROTOCOL §1.6)
No new ones. `wait=` never produces an error (both budgets end in "do it anyway"), and `nowarp=1` reuses the existing `that position cannot be reached from here`.

---

## 3. G1 - the intent recogniser (SERVER)

New file section in `lib/lrg_actions.php` (or a new `lib/lrg_intent.php` required from it - the builder's choice; `PROTOCOL §7.2` only fixes the function names below).

### 3.1 Entry point and shape
```php
lrgRecogniseIntent(string $utterance, ?array $ctx = null, array $mem = []): array
// ['kind'   => one of the kinds in 3.3, or 'none'
//  'conf'   => 'high' | 'low' | 'none'
//  'kv'     => the command kv this resolves to, or []          (filled in 3.5)
//  'act'    => act id, '' when the kind is not 'act'
//  'words'  => plain phrase for the directive (3.7 / §4)
//  'why'    => why it was blocked or downgraded (log only)
//  'text'   => the cleaned utterance, <= 160 chars, for the log]
```
`$ctx` is `$turn['ctx']` (current scene, live list, sexes, furniture, ceiling, player / npc names, furniture options, act options). `$mem` is `lrgMemGet($npc)` - needed only for `yes` / `no` against a pending proposal.

Deterministic, English, no LLM, no network. Must run in well under a millisecond: it is on every player turn.

### 3.2 Cleaning the utterance
1. `lrgStripContext()` (removes the DLL's `(Context location: …)`).
2. Strip a leading speaker prefix with the pattern `lrgIsTickText` already uses: `/^[^:]{1,40}:\s*/`. CHIM only strips it for `instruction` / `suggestion` (`main.php:1092`), so player speech still carries `"Jordan: "`.
3. Lowercase, collapse whitespace, keep `?` and `'`.
4. If the result is empty -> `kind=none, conf=none, why='empty transcript'` **and** log it (playtest 6 had 2 of 12 uploads come back blank and the player got total silence - §12.3 makes that visible).

### 3.3 Kinds and patterns
Matched in this **order**; first hit wins. The order mirrors `lrgResolveControl` so the recogniser and the LLM path can never disagree about the same words.

| # | kind | matches (regex, case-insensitive, on the cleaned text) | kv it produces |
|---|---|---|---|
| 1 | `stop` | the existing `lrgResolveControl` stop rail, **including** its negated-stop guard (`don't stop` is not a stop) | `do=stop` |
| 2 | `no` | only when a proposal is pending (§7.4): `^(no|nope|nah|not now|not yet|later|maybe later)\b` or `\b(rather not|i'?d rather not|not right now|don'?t want to)\b` | - |
| 3 | `yes` | only when a proposal is pending: `^(yes|yeah|yea|yep|yup|sure|ok|okay|alright|all right|gods? yes|fuck yes|mhm|uh ?huh|do it|go ahead|please do)\b` or `\b(do it|go ahead|i want that|yes please)\b` | the pending act |
| 4 | `winddown` | `\bwind(ing)? ?down\b|\bafterglow\b|\bcool ?down\b` | `do=winddown;…` (built by `lrgResolveControl`) |
| 5 | `speed` | `\b(?:speed|pace)\s*(?:to\s*)?([0-5])\b` | `do=speed;speed=n` |
| 6 | `faster` | `\b(faster|quicker|harder|rougher|speed up|go faster)\b` | `do=faster` |
| 7 | `slower` | `\b(slower|gentler|softer|slow down|take it slow|ease up)\b` | `do=slower` |
| 8 | `hold` | `\bhold (it|on|back|there|still)\b|^hold$|\bnot yet\b|\bstay (like )?(this\|that)\b|^wait\b|\bstall\b|\bdon'?t come yet\b` | `do=hold` |
| 9 | `release` | `\b(let go|carry on|keep going|go on|don'?t hold back|let it go)\b` | `do=release` |
| 10 | `climax` | `\b(climax\w*|orgasm\w*|cum\w*|finish\w*)\b\|\bcome (now\|together\|for me\|with me\|inside me)\b`, only when the current scene tier >= `sensual` | `do=climax;who=npc\|player\|both` (same `who` logic as `lrgResolveControl`) |
| 11 | `lead_npc` | `\byou (lead\|take over\|decide\|choose)\b\|\bdo what(ever)? you want\b\|\bsurprise me\b\|\byour (turn\|choice\|call)\b\|\btake the lead\b\|\bshow me what you\b` | `do=lead;who=npc` |
| 12 | `lead_player` | `\bi'?ll lead\b\|\blet me (lead\|do\|take)\b\|\bmy turn\b\|\bi'?m in charge\b\|\bi want to lead\b\|\bi'?ll take over\b` | `do=lead;who=player` |
| 13 | `furniture` | any word of `lrgFurnitureWords()` for a type in `$ctx['furn_options']`, with a move verb (`move|go|get|take me|lets?|over) …` **or** a bare furniture word | `do=furniture;furn=<type>;scene=<id>` |
| 14 | `dress` | `\b(dress|redress|get dressed|clothes? (back )?on|put .* on|cover (up\|yourself))\b` | `do=dress;who=…;part=…` (3.4) |
| 15 | `undress` | `\b(undress|strip|disrobe|naked|nude|bare|clothes? off|take .* off|get .* off|out of (those\|your\|that))\b` | `do=undress;who=…;part=…` (3.4) |
| 16 | `act` | an act id via §6.3 `lrgMatchAct()`, else the existing `lrgFindSceneByText()` over the whole index | `do=goto;scene=<id>` |
| 17 | `none` | nothing matched | `[]` |

Notes that matter:
- **14 before 15**: "put your clothes back on" contains "on" and must not fall through to the undress branch.
- **15 before 16**: "get naked" / "take your clothes off" must never be resolved as a *position*. This is exactly playtest 6's failure #4.
- **11/12 before 16**: `surprise me` / `you decide` are also `@any` keys in `scene_index.synonyms` and would otherwise become a random position change.
- `lrgResolveControl()` stays the single implementation for kinds 1, 4-10, 13 - the recogniser calls it with the matched fragment so the two can never drift.

### 3.4 `who` and `part` for undress / dress - **player perspective**
This is a new function, deliberately **not** `lrgResolveClothing()`. That one reads the *NPC's* `item` and documents that "take your clothes off" from the model means the NPC. From the *player's* mouth the possessives mean the opposite, which is why playtest 6's `"I want you to take my clothes off."` would have undressed the wrong person.

```php
lrgIntentClothingWho(string $t): string   // 'npc' | 'player' | 'both'
```
| result | patterns |
|---|---|
| `both` | `\b(both|us|we|each other|together|our)\b` |
| `player` | `\bmy (clothes|armou?r|shirt|boots|things|gear)\b` · `\b(undress|strip|get|take) me\b` · `\b(off|from) me\b` · `\bmine\b` |
| `npc` | everything else, including `\byour (clothes\|…)\b`, `get naked`, `take it off`, a bare `strip` |

`part` uses the same table as `lrgResolveClothing` (feet / hands / head / body, else `all`).

`lrgResolveClothing()` keeps its current NPC-perspective behaviour, with one addition so the LLM path stops being ambiguous too: `\bmy\b` + a garment word -> `player`.

### 3.5 Blockers - what is *not* a request (addendum 3d)
`lrgIntentBlocked(string $t): string` returns a reason or `''`. A non-empty reason forces `kind=none, conf=none` **except** for kinds 1 (`stop`), 2 (`no`) and 3 (`yes`), which are answers, not requests.

| reason | pattern |
|---|---|
| `hypothetical` | `\b(what if|imagine|pretend|suppose|if i |if you |would you ever|have you ever|do you ever|what would|one day|some ?day)\b` |
| `quoted` | `\b(you said|i said|she said|he said|they said|told (me|you)|remember when)\b` |
| `negated` | `\b(don'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b` **and** the stop rail did not fire |
| `question` | the text starts with `should|do i|did|does|are|is|was|were|have i|has|can i|could i|may i|what|how|why|when|who|which|where`, **unless** a polite-request frame (§3.6) matched |

`question` is what makes *"should I take this off?"* and *"Tell me, what do you picture my cock looking like?"* not requests, while *"can you take your clothes off"* still is.

### 3.6 Confidence
```
conf = 'none'  when blocked, or when no payload resolved
conf = 'high'  when a payload resolved AND (a REQUEST FRAME matched
                                            OR kind in {stop, yes, no, faster, slower, speed,
                                                        hold, release, climax, winddown,
                                                        lead_npc, lead_player})
conf = 'low'   otherwise  (a bare noun matched and nothing framed it as a request)
```
**REQUEST FRAMES** (any one):
1. imperative first word: `^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep)\b`
2. want / ask frames: `\b(i (want|wanna|need|would like|'?d like|wish)|let'?s|lets|give me|do me|show me|i'?m going to|gonna)\b`
3. polite-request frames: `\b(can|could|will|would) you\b|\bwhy don'?t you\b|\bhow about you\b|\bmind if\b`
4. second-person object of a garment: `\byour (clothes|shirt|dress|armou?r|robes?|boots)\b`

Why this rule: the **safety net only fires on `high`** (§5), so `high` has to mean "an explicit instruction was spoken". `low` still drives the directive (§4), which is the cheap half of G1 and cannot do anything by itself.

STT tolerance, measured in playtest 6:
- a dropped verb - `"I wanna you doggy style."` - still reaches frame 2 (`i wanna`) plus act `vaginal` from `doggy`: **high**. Correct.
- a mangled NPC name - `"Hey Lazette, do you wanna follow me?"` - matches no frame with a payload and starts with `hey`; kind `none`. Correct.
- a bare noun - `"naked"` - kind `undress`, conf `low`: the directive names it, the net stays out of it. Correct.

### 3.7 Where it runs and what it writes
- **Pre-lock**, in `lrgHandleGameMessage()`: for request types in `LRG_PLAYER_SPEECH_TYPES` **and** in the new `LRG_NARRATOR_SPEECH_TYPES = ['narrator_inputtext', 'narrator_inputtext_s']`, when `lrgGetActiveScene() !== null`:
  - `lrgMemSet($scene['_npc'], ['player_request_at' => lrgNow()])` - the hold clock (§7.2). Keyed on the **scene's** NPC, not on the request's, so a narrator-routed utterance still stops her autopilot.
  - a G6 log line (§12.2). For a narrator type also `WARN player speech routed to the Narrator while a scene runs`.
  - the request itself is **passed through untouched** - no re-routing. `FUNCTIONS_ARE_ENABLED` is false for `narrator_inputtext` (`main.php:1077-1079`) and the reply belongs to another character, so there is nothing the server can safely do with it. The real fix is game side (§14.6).
  - **exception**: when the recognised kind is `lead_npc`, `player_request_at` is **cleared** instead of set - "you lead" must let her lead at the next tick, not block her for 150 s.
- **Under the lock**, in `lrgPrepareTurn()`: the full recognition (it needs `$turn['ctx']`), stored as `$turn['intent']`. Only for player-speech types, and only when `$turn['mode']` is `scene`, `private` or `follow`.

---

## 4. G1 - the per-turn directive (SERVER)

### 4.1 Where it goes
A new function `lrgRequestDirective(array $turn): string`, appended by `lrgVolatileGuidance()` as the **last lines inside** the `<intimate_scene_now>` / `<this_moment>` block. Not a second `chimRegisterPromptInjection` call: appending inside the existing block guarantees it is the last text the model reads without depending on how CHIM orders two injections in the same slot.

### 4.2 DO-IT shape
Emitted when `$turn['intent']['conf']` is `high` or `low`, the kind is actionable, **and** the intent resolves to something the server can really send (checked first, §4.3).

```
<player_request>
The player just asked for this: {WORDS}. Do it now: choose {ACTION_NAME} with {FIELD} "{VALUE}", and answer in your own voice in the same reply. Do not refuse, stall, negotiate or ask whether it is a good idea.
</player_request>
```
- `{ACTION_NAME}` / `{FIELD}` / `{VALUE}` are taken from the resolved kv and from `$turn['offered']` - the directive **never names an action CHIM is not offering this turn** (the rule `lrgTurnOffers()` already enforces elsewhere).
- `{WORDS}` comes from a config map `intent.words` so it is data, not prose scattered in code:

| kind | `{WORDS}` | `{ACTION_NAME}` / `{FIELD}` / `{VALUE}` |
|---|---|---|
| `undress` who=npc | `you take your own clothes off` | ChangeClothing / item / `undress` |
| `undress` who=player | `you take the player's clothes off` | ChangeClothing / item / `undress you` |
| `undress` who=both | `you both get undressed` | ChangeClothing / item / `undress both` |
| `dress` … | mirrored | ChangeClothing / item / `dress …` |
| `act` | the act's display name (§6.2) | RequestAct / item / `<act id>` |
| `faster` / `slower` / `speed` | `a faster pace` / `a slower pace` / `pace <n>` | ChangeIntimacy / item / `faster` … |
| `stop` | `the scene to end` | ChangeIntimacy / item / `stop` |
| `hold` / `release` | `you to hold back` / `you to stop holding back` | ChangeIntimacy / item / `hold` / `release` |
| `climax` | `<who> to finish` | ChangeIntimacy / item / `climax` |
| `winddown` | `to wind down` | ChangeIntimacy / item / `wind down` |
| `furniture` | `moving to <furniture label>` | ChangeIntimacy / item / `<furniture label>` |
| `lead_npc` / `lead_player` | `you to lead from now on` / `to lead himself` | ChangeIntimacy / item / `<npc name> leads` / `<player name> leads` |
| `yes` (proposal pending) | `yes to what you just asked` | RequestAct / item / `<pending act id>` |
| `no` (proposal pending) | - | **no directive**: §7.4 clears the proposal and the block says one line: `The player said no to what <npc> suggested. <npc> lets it go and does not bring it up again this time.` |

### 4.3 CANT shape (G5.8, addendum 3f)
Emitted when the intent resolved to a kind but the server cannot send it: the act is not installed / filtered / not reachable with warp off / wrong actors or furniture / the scene is blocked by a rail.

```
<player_request>
The player just asked for this: {WORDS}. That is not possible from here. Say so plainly in a few words - never apologise at length - and name what is possible instead: {UP TO 3 ACT DISPLAY NAMES}. Choose no action this turn.
</player_request>
```
This is why the resolution happens **before** the directive is written: the directive can then never name an action the post-gate would drop, and the safety net never fires for a CANT.

### 4.4 Two prompt passages that must change (they are the proven cause of "she wasn't listening")
Forensics quoted these verbatim from the 19:36:10 prompt. Both must go:

1. `lib/lrg_actions.php:1022-1025` - the `$unless` clause `"… leave message empty - it simply happens, unless the player asked something that needs an answer."` The whole `[silent]` branch disappears under R10 (§8). Replacement rule, in the notes: *"Anything the player asks for: do it AND answer in the same reply - never answer instead of doing it."*
2. `:1026-1028` - `"ChangeClothing … : only if $npc wants to."` must move up to sit directly next to the act block, and must split into two sentences: *"When the player asks for clothes off or on, do it. When nobody asked, only because `$npc` wants to."*
3. `:977-982` - the ambiguous ladder sentence (`"Anything beyond foreplay (touching, teasing, undressing) is too soon right now"`, which the model read as "undressing is too soon"). Replacement, two unambiguous sentences built from `lrgStepWords()`:
   - `"What <npc> starts by herself right now: up to <words for ceiling>."`
   - `"<npc>'s own next step beyond that is not yet. This limit is about what <npc> starts herself - anything the player asks for, <npc> simply does."`
   The second sentence is omitted entirely when `ceiling == 4` (which, per addendum 3e and §3.6, is always the case on a turn with a recognised player act request - §6.6).
4. `:914-918` and `:885` - `"BeginIntimacy (it starts gently - a kiss, an embrace - and can simply happen, message left empty)"`. Delete the parenthesis. Replacement in §8.1.
5. `:992` - `"or declines what does not suit them - then nothing changes"` - **deleted** (addendum 3b).
6. `:966-969` - the `"silence is fine"` clause stays only on turns where **no** action is in play (a pure `lrg_scenetalk` speech moment). On a turn that carries an action it is removed: it competes with R10.

### 4.5 The rewritten `<intimate_scene_now>` block - skeleton
This is the shape `lrgSceneNotes()` must produce on a turn where she `can_act`. Order matters: the request directive is last. Roughly 40 % shorter than today's block, which is what pays for the directive (the option list was ~60 % of the injection).

```
1  what is happening now: <label>, pace n of m[, undressed state][, a climax has happened][, winding down]
2  how <npc> talks now (unchanged, minus "silence is fine" on action turns)
3  wording level + manner (unchanged)
4  [only when a command of hers failed in game: the one-shot fail line - unchanged]
5  ladder, rewritten per §4.4.3 - two unambiguous sentences, second one omitted at ceiling 4
6  stop rule: "If the player asks to stop or seems unwilling: ChangeIntimacy item stop, at once, no argument."
7  who leads (leader line, WITHOUT the deleted "or declines what does not suit them")
8  [lead tick only] "Nothing has changed for a while and the player leaves it to <npc>. ONE change that
      suits <npc>: an act below, or the pace. Something that has not happened yet, or a change of pace -
      not back to something already done, unless the player asks."
   [lead tick + R11 proposal instead] the ask-first paragraph of §7.4
9  "RequestAct takes exactly one act key. Open right now:" + "<act id> = <display name>[ next step]" x up to max_acts
10 "ChangeIntimacy takes exactly one item:" + faster; slower; hold; release; [climax]; wind down;
      stop (ends at once); <furniture labels>; <npc> leads; <player> leads; [auto]
11 "ChangeClothing, item undress | dress, optionally + both or you, optionally + body, head, hands or feet.
      When the player asks for clothes off or on, do it. When nobody asked, only because <npc> wants to."
12 R10 (replaces the whole [silent] paragraph):
      "Whatever <npc> chooses here by <npc>'s own decision, <npc> says one short line that makes plain
       what is about to happen - before or as it happens. Never silently. A change of pace, holding back
       or letting go needs no line. Anything the player asked for: do it AND answer in the same reply -
       never answer instead of doing it."
13 [§8.3 one-shot re-ask line, when say_first is set]
14 <player_request> … </player_request>   (§4.2 or §4.3; absent when nothing was recognised)
```

Scene-mode offer map in `lrgPrepareTurn()` becomes `[CONTROL, CLOTHING, REQUESTACT]` (`START` and `INVITE` stay hidden, movement actions stay hidden). `$turn['offered']` therefore gains `ExtCmdLRG_RequestAct`, and the post-gate's "was it offered this turn" check covers it like the others.

### 4.6 The lead cue in `prompts.php`
Two changes, both from the forensics:
- `PROMPTS['lrg_scenetalk']['cue']` for a lead tick: delete `"a [silent] choice simply happens with an empty message"`; replace with `"one short spoken line that makes plain what <npc> is about to do, then the action"`.
- `PROMPTS['lrg_scenetalk']['player_request']` for a lead tick is today the literal text `(a while passes; the player leaves it to <npc>)`. CHIM concatenates it into the conversation, and playtest 6 shows it stacked **four times** in one context while the player was in fact talking non-stop. Replace it with a neutral, non-accusatory marker (`(the moment continues)`) that says nothing about the player being passive. The hold (§7.2) already guarantees no lead tick fires while the player is steering, so the "leaves it to her" claim is not needed anywhere.

---

## 5. G1 - the safety net (SERVER)

### 5.1 Mechanism (verified by the server audit §7)
Append one extra wire line from the existing `$GLOBALS['action_post_process_fnct_ex']` closure - i.e. inside `lrgPostProcessActions()`, after the loop over the LLM's lines. No new hook, no queue table, no second LLM call.

```php
$net = lrgSafetyNet($turn, $out);       // ?string
if ($net !== null) { $out[] = $net; }
return $out;
```
The line is built exactly as the existing `$emit()` closure builds one, except that the actor and the channel are synthesised:
```php
$turn['npc'] . '|command|' . $code . '@' . lrgKv(['ok'=>1,'cid'=>$turn['cid'],'npc'=>$turn['npc']] + $kv) . "\r\n"
```
Channel `command` is correct for our rows (`action_catalog.php:414-447` only returns `approvedcommand` when `custom_config.confirmation_policy` was saved as `automatic` in the web editor; our rows set only `metadata.confirmation.default_policy`). `lrgKv()` already strips `; = @ | "` and newlines.

The hooks run **before** the `sizeof($actions) > 0` test (`lib/data_functions.php:6041`), so the net also works when the LLM emitted no action at all - which is exactly the playtest-6 19:36:10 case.

### 5.2 Preconditions - ALL must hold
1. `lrgEnabled()`, `!defined('LRG_SHARMAT_PRESENT')`, `intent.safety_net` true in config.
2. `$turn['mode'] === 'scene'` and `empty($turn['scene_blocked'])` and `!empty($turn['can_act'])`.
3. **A scene is really running**, confirmed by the game: `lrgGetActiveScene()` returned a row (so §10 already closed it if the game said otherwise), the row's `_age <= intent.scene_confirm_seconds` (default 60), and `lrg_memory.last_result` is not an unresolved `no scene is running`.
4. `$turn['intent']['conf'] === 'high'` and the kind is in `intent.net_kinds` (default: `undress, dress, act, faster, slower, speed, stop, hold, release, climax, winddown, furniture, lead_npc, lead_player, yes`).
5. The LLM's own surviving lines in `$out` contain **no** glue command that already satisfies the intent. "Satisfies": same `Code`, and for `ExtCmdLRG_SceneControl` the same `do` (any `do=goto` satisfies an `act` intent); for `ExtCmdLRG_Clothing` the same `do`.
   If the LLM chose the right action but the gate **dropped** it, the net still fires - that is the whole point.
6. The kv was produced by the same resolvers and the same `$turn['ctx']` the gate uses, so a net-fired action can never exceed what the LLM was allowed to choose. The kv resolved to something non-null and not `too_soon` (with the ceiling already lifted per §6.6).
7. At most **one** net line per turn. Never `ExtCmdLRG_StartIntimacy`. Never `ExtCmdLRG_Invite`. Never outside a running scene.

There is **no refusal check** and no `Decline` action (addendum 3b/3d): whatever her words were, the request is carried out.

### 5.3 What the net does NOT cover, by design
- `lrg_scenetalk` non-lead turns: `FUNCTIONS_ARE_ENABLED` is off there, the post-process chain never runs. Correct - those are speech-only moments.
- `narrator_inputtext`: functions are off and the reply is another character's. Game side fixes this (§14.6).
- Connector errors (`$outputWasValid` false): nothing is echoed at all.

### 5.4 Logging
Always one line (§12.2): `net fired <code> <kv>` or `net skipped: <reason>` with the reason being exactly which precondition failed.

---

## 6. G5 - the act layer and `RequestAct` (SERVER)

### 6.1 Index change (`LRG_INDEX_VERSION` 3 -> 4)
`lrgDigestScene()` already builds `_acts` as `[[type, actor, target, performer], …]` and `lrgIndexBuild()` then throws it away (`unset($s['_origins'], $s['_acts'], $s['_slotreq'])`). Keep it, canonicalised through `$aliasOf` and trimmed to three ints:

```php
$s['roles'] = [[canonicalActionName, actorSlot, targetSlot], …];   // de-duplicated
```
Cost: ~607 scenes x a handful of triples. The signature already covers this library's own mtime, so `deploy_server.ps1`'s `warm --force` rebuilds once.

### 6.2 Act table (config `acts`, new section, owned by SERVER)
```json
"acts": {
  "<act id>": {
    "match": ["<OStim action name glob>", …],
    "directional": true|false,
    "name": "<display name>"                       // non-directional
    "name_npc": "…", "name_player": "…",           // directional: who is doing it to whom
    "words": ["<spoken synonym>", …],
    "tier_hint": "kissing|sensual|sexual"          // only used for sorting / R11, never as a gate
  }
}
```
Families to ship (15; the builder writes the plain display names, which are **vocabulary, not dialogue**):
`kiss`, `hold`, `grope`, `nipples`, `handjob`, `fingering`, `oralpenis`, `oralvulva`, `sixtynine`, `titfuck`, `thighjob`, `grinding`, `vaginal`, `anal`, `masturbation`.

Their `match` lists come straight from the action names already in `scene_index.synonyms` (`config/lrg_config.default.json:296-336`) - reuse those words as each family's `words`, do not invent new vocabulary.

**Act id with role** - this is the stable handle the LLM and the player use, replacing the per-turn `P<n>` indices (forensics §6c: `P1` meant three different scenes in ten minutes):
- non-directional family -> the family id itself: `kiss`, `vaginal`, `grinding`, `sixtynine`, `anal`, `hold`.
- directional family -> `<family>:npc` (she does it) or `<family>:you` (the player does it): `oralvulva:you`, `grope:npc`, `handjob:npc`, `fingering:you`.

### 6.3 Deriving acts from a scene
```php
lrgSceneActs(array $scene, int $npcSlot): array          // ['vaginal', 'grope:npc', …]
lrgMatchAct(string $text, array $available): string      // spoken words -> act id, '' = no match
lrgActLabel(string $actId): string                       // display name
```
`lrgSceneActs()` walks `$scene['roles']`, maps each action name through `acts.*.match`, and for a directional family compares `actorSlot` against `$npcSlot`: equal -> `:npc`, otherwise -> `:you`. `$npcSlot` comes from the wire key `npos` and the player's from `ppos` (both already pushed, `LRG_OStim.psc:2408-2413`); when they are missing or equal, treat every directional family as `:npc` and log it.

`lrgMatchAct()` checks, in order: an exact act id; an exact `acts.*.words` entry; a phrase from `scene_index.synonyms` whose targets include one of a family's `match` names. Role words (`me` / `you` / `my` / `your`) pick the direction; with no role word, prefer a direction that exists in `$available`.

### 6.4 `lrgActOptions()` - what is offered this turn
```php
lrgActOptions(string $current, array $live, int $maxTier, ?array $sexes, string $furn,
              int $npcSlot, array $avoid = [], int $max = 8): array
// [actId => ['act','name','tier','scene','hops']]
```
Implementation: one `lrgSceneWalk()` exactly as `lrgSceneOptions()` does today (same filters: excluded, actor count, sexes, furniture, tier ceiling, live oracle), then group the reachable scenes **by act id** and keep the best scene per act:
1. not in `$avoid` (the scenes already visited this thread) beats in `$avoid`;
2. fewer hops;
3. `lrgSceneNameFits()`;
4. not taste-listed.
Sorting of the returned acts: the current scene's own acts are dropped; then nearest tier first, with at least `max(1, intdiv($max,3))` slots reserved for the ceiling tier (the same reservation `lrgSceneOptions` makes today, which is what keeps "the next step" visible).

`lrgSceneOptions()` itself stays (the safety net, `P<n>` compatibility and the flow tests use it) and gains one optional trailing parameter `array $avoid = []` (§7.5).

### 6.5 New catalog row (`LRG_ACTIONS_VERSION` 8)
| field | value |
|---|---|
| `code_name` | `ExtCmdLRG_RequestAct` |
| `action_name` | `RequestAct` |
| `description` | `During an intimate scene only: move to a different act or position. "item": exactly one act key from the current scene notes, nothing else.` |
| `parameters_json` | one required `item` |
| `requirements.request_types_any` | player speech + `lrg_scenetalk` |
| rest | the same `$meta` / `$common` as the other rows (`followup.enabled=false`, `suppress_placeholder_infoaction=true`) |

`ExtCmdLRG_SceneControl`'s description loses the position vocabulary and becomes the **verb** action:
`During an intimate scene only: change the pace, hold back or release, climax, move to furniture, hand over who leads, wind down or stop. "item": exactly one key from the current scene notes.`

The other three rows change only as §4.4 and §8.1 require. The version bump to 8 is mandatory because descriptions and `request_types_any` change (`lib/lrg_actions.php:43` marker file).

### 6.6 Post-gate handling of `RequestAct`
```
item -> lrgMatchAct() against this turn's act options
     -> no match: fall back to lrgFindSceneByText() with the SAME filters (ceiling, sexes, furniture,
        prefer = routable) - the plain-words escape hatch keeps working but is now filtered exactly
        like the offered list (forensics §6b: today it is not, which is how an unasked-for warp into
        rear sex happened)
     -> still nothing: drop + log; the CANT directive had already been written this turn if the
        player asked for it, so she has already answered in words
emit ExtCmdLRG_SceneControl@do=goto;scene=<id>  +  §9 decoration (nowarp / wait / hold / warp)
```
**Ceiling (addendum 3e).** On a turn where `$turn['intent']` is an `act` kind with `conf=high`, `$turn['progress']['ceiling']` is set to `4` before the options are built. An explicit player request is not held back by the pacing ladder, and therefore `too_soon` can no longer be returned for one. For her own picks and for `conf=low`, today's `lrgTierCeiling()` (reached +0/+1 by pace) is unchanged.

**Warp.** `warp=1` only when the player asked for exactly this act on this turn (`conf=high`); every NPC-initiated goto and every furniture move she initiates carries `nowarp=1` instead (G5.5).

---

## 7. G2 - no autopilot (SERVER + GAME)

### 7.1 Lead interval
`fSceneLeadInterval:SceneTalk` 45 -> **75** (§15).

### 7.2 The hold
- Server writes `player_request_at` pre-lock (§3.7).
- **Server refuses the lead tick before the MAIN lock.** In `lrgHandleGameMessage()`, for `lrg_scenetalk` whose text is exactly `lead`:
  ```
  $scene = lrgGetActiveScene();
  if ($scene) {
      $mem  = lrgMemGet($scene['_npc']);
      $left = (int)($mem['player_request_at'] ?? 0) + lead.hold_seconds - lrgNow();
      if ($left > 0)                      -> log "lead tick dropped: hold {$left}s left" ; return 'handled';
      if (a proposal is pending, §7.4)    -> log "lead tick dropped: proposal pending"   ; return 'handled';
  }
  ```
  `handled` means `preprocessing.php` calls `terminate()` **before** the semaphore: zero LLM call, zero TTS, zero tokens. This is the cheapest possible implementation of G2 and it also removes the MAIN-lock collision the forensics measured (a lead turn holding the lock while the player is speaking).
- **Game applies its own hold** so an old server cannot flood a new game and so the game stops firing into a lock the player is about to need: new key `hold=<seconds>` on every `ExtCmdLRG_SceneControl` / `ExtCmdLRG_Clothing`, `leadHoldUntil = now + hold`, and `MaybeLead` returns early while `now < leadHoldUntil` (§14.3).
- **Carrier when the turn produced no command**: the server appends
  `do=lead;who=<the CURRENT leader from the scene payload, default npc>;hold=<lead.hold_seconds>`
  on any player speech turn inside a scene that emitted nothing else. Using the *current* leader is essential: `who=npc` would silently flip the leader back on a scene the player had taken over. An old game just re-asserts the leader it already has - a no-op.

### 7.3 No circling
Per-scene memory, inside the `lrg_scene_state` payload (reset with the scene, exactly like `_maxtier`):
| key | content |
|---|---|
| `_visited` | ordered list of non-transition scene ids this thread has been in, capped at `lead.no_repeat_scenes` (default 12) |
| `_acts_done` | act **families** (without the role suffix) seen this thread, capped 24 |
| `_proposals` | how many proposals she has made this scene |
| `_last_prop_at` | when the last one was made |
| `_prop_no` | act ids the player said no to (R11 - "a no is remembered so she does not nag") |

`_visited` / `_acts_done` are appended in `lrgHandleSceneMessage()` on `ev=start` and on `ev=change` with `trans=0`; the game's new `prev=` key is used only to detect a dropped push (if `prev` is set and is not the last entry of `_visited`, append `prev` first).

Use:
- NPC-led act choice (`lrgActOptions($avoid = $_visited)`): a visited scene is pushed behind every unvisited one; if **every** candidate is visited the penalty is dropped (never return an empty list).
- `lrgSceneOptions()` gains the same optional `$avoid` parameter, used the same way (`+6` on `_rank`).
- **Never applied** when the player asked: the player may ask to go back to anything.
- The lead guidance says, in one sentence: *"Something that has not happened yet, or a change of pace - not back to something already done, unless the player asks."*

### 7.4 R11 - she asks before a sex act
**When she must ask instead of doing it** (all must hold):
1. it is a lead tick (her own move, not a player request);
2. the act she would pick has tier `sexual` **and** its family is not in `_acts_done` (a *new* sexual act, not a position variation of one already happening - a variation is just announced, §8);
3. `lrgNow() - $scene['_tier_since'] >= lead.ask_first.min_act_seconds` (default 75);
4. `lrgNow() - _last_prop_at >= lead.ask_first.cooldown_seconds` (default 120);
5. `_proposals < lead.ask_first.max_per_scene` (default 3);
6. the act is not in `_prop_no`.

**What happens then**: `$turn['propose'] = ['act' => <id>, 'name' => <display>]`; the scene notes replace the "choose one change" paragraph with:
*"`<npc>` wants `<act display name>` next. `<npc>` asks for it in `<npc>`'s own words - one short sentence - and chooses no action this turn. It only happens if the player says yes."*
`RequestAct` and `ChangeIntimacy` are hidden for that turn except for pace / hold / stop.

**Recording it**: in the post-gate, *after* the LLM answered, only when `lrgSpokenThisTurn() !== ''`:
```
lrgMemSet($npc, ['proposal' => ['act'=>…, 'at'=>now, 'expires'=>now + lead.ask_first.ttl_seconds]]);
scene payload: _proposals++, _last_prop_at = now
```
If she said nothing, nothing is recorded (she did not really ask) and `lead_idle` increments as today.

**Answering it** (next player speech turn):
| player | effect |
|---|---|
| `yes` | the proposal's act is executed - the directive names `RequestAct` with that act id, and the safety net carries it out if the LLM does not (kind `yes` is in `net_kinds`). Proposal cleared. |
| `no` | proposal cleared, act id appended to `_prop_no`, the `no` guidance line of §4.2 is injected. No action. |
| anything else that recognises as a request | that request wins; the proposal is cleared **without** recording a no (she may ask again later). |
| nothing for `ttl_seconds` | proposal expires silently. |

**While pending**: the server refuses lead ticks (§7.2) and sends `hold=<seconds left>` on any command it does emit, so a new game also stops asking.

### 7.5 Who leads, by voice
Kinds `lead_npc` / `lead_player` (§3.3 rows 11-12) resolve to `do=lead;who=npc|player`. This finally works because the **player** is the speaker, so "you" and "I" are unambiguous - which is exactly why `lrgResolveControl()` drops bare "you lead" / "I lead" from the model's `item` (`lib/lrg_actions.php:724-731`). That drop stays; the recogniser is the new route. `lead_npc` also clears `player_request_at` (§3.7).

---

## 8. G3 / R10 - she speaks before she moves (SERVER + GAME)

### 8.1 Prompt rules (SERVER)
- `BeginIntimacy` description becomes:
  `Begin physical intimacy with the player; it always starts gently (a kiss, an embrace). In the SAME reply, first say one short line that makes plain what you are about to do - never an empty message. Choose it only because you want it right now, never to be polite, under pressure, or because you were asked twice.`
- `<this_moment>`, private / follow branch: delete `"can simply happen, message left empty"` and `"or lets the moment pass without a word (empty message)"`. Replace with one rule:
  `Whatever <npc> does physically - starting something, undressing, suggesting somewhere private - <npc> says one short line first that makes plain what is about to happen. Direct and blunt by default; coy only if that is who <npc> is. Never a speech.`
- **Delete the whole `[silent]` / `[say it]` marker machinery** from `lrgSceneNotes()` (`:994-1025`) and from `prompts.php:44`. Under addendum 2 nothing she initiates is silent.
- `scene_talk.announce_chance` is **repurposed**, not deleted: renamed to `scene_talk.blunt_chance` with the same per-talk-style numbers. It now decides only **how** blunt her announcing line is (naming the act outright vs. a few plain words), never **whether** she speaks. `$turn['announce']` is renamed `$turn['blunt']`. An old `announce_chance` key in a user config is read as a fallback so an existing `lrg_config.json` keeps working.
- **Pace changes are exempt** (`faster`, `slower`, `speed`, `hold`, `release`) and so is anything the player asked for. Those need no line (a short reaction is fine, silence is fine).

### 8.2 The decision is made AFTER resolution (forensics §3b)
At 19:31:45 she picked `P4` and the glue landed on a *different tier* than the marker implied. So "must she speak?" is decided in the post-gate, on the **resolved** kv, never from a marker the model saw:

```
$selfInitiated = the turn has no player intent (any confidence) that this action satisfies
$needsLine     = $selfInitiated && code in {START, INVITE} 
                 || $selfInitiated && resolved do in {goto, furniture, undress, dress}
```

### 8.3 Server-side guarantee (G3b)
```php
lrgSpokenThisTurn(): string
// implode of $GLOBALS['talkedSoFar'] (array, filled by returnLines() BEFORE the post-process
// hooks run - lib/chat_helper_functions.php:1635 -> lib/data_functions.php:6036),
// else $GLOBALS['DEBUG_DATA']['response'], else ''
```
If `$needsLine` and `lrgSpokenThisTurn() === ''`:
1. **drop the command** (nothing happens silently);
2. `lrgMemSet($npc, ['say_first' => ['what' => <code/do>, 'at' => lrgNow()]])`;
3. log `gate: dropped <code> - a self-initiated change with no spoken line (R10)`.

The **next** turn for that NPC adds one line to the guidance and clears the flag (the same one-shot pattern `last_result` already uses):
*"Last time `<npc>` chose to act without saying anything, so nothing happened. If `<npc>` still wants it, say the one short line that makes plain what `<npc>` is about to do, in the same reply as the action."*
The flag also expires after `result_memory_seconds`.

**Rejected alternative**: a small pool of canned fallback lines spoken through CHIM. It breaks `PROTOCOL §0` ("no example dialogue in code"), it would sound identical every time, and the server cannot reliably inject a TTS line from the post-process closure (only *action* lines are echoed there; a synthesised `ScriptQueue` line is unverified). Re-asking is the other remedy the owner named and it costs at most one turn.

### 8.4 Sequencing in the game (G3a) - `wait=`
The command reaches the game 0-1 s after the server emits it, long before her TTS is fetched (game audit F5). The server cannot fix that ordering; the game must hold the action. New key `wait=begin|end` (§2.2, §14.5).

**Who sets what** (SERVER):
| command | the player asked for it | she initiated it |
|---|---|---|
| `ExtCmdLRG_StartIntimacy` | `wait=end` | `wait=end` |
| `do=goto`, `do=furniture` | *(no wait - as fast as today)* | `wait=begin` + `nowarp=1` |
| `do=undress`, `do=dress` | *(no wait)* | `wait=begin` |
| `stop`, pace, `hold`, `release`, `climax`, `winddown`, `lead` | never | never |

`StartIntimacy` always waits for the **end** of her line because OStim's intro fade (`OStimUseFades = 1`) would otherwise cut across it. Both budgets end in "do it anyway", so a start is never lost.

---

## 9. Command decoration - the single place that assembles a command (SERVER)

To keep §7, §8 and §6 from drifting, the post-gate builds every outgoing kv through one helper:

```php
lrgDecorate(array $turn, string $code, array $kv, bool $selfInitiated): array
```
| adds | when |
|---|---|
| `hold=<lead.hold_seconds>` | on any command emitted on a **player speech** turn inside a scene |
| `hold=<seconds left>` | while a proposal is pending |
| `wait=end` | `$code === LRG_ACT_START` |
| `wait=begin` | `$selfInitiated` and resolved `do` in `{goto, furniture, undress, dress}` |
| `nowarp=1` | `$selfInitiated` and resolved `do` in `{goto, furniture}` |
| `warp=1` | only when the player asked for exactly this act this turn with `conf=high` and the index found no route (today's `lrgResolveControl` behaviour) |

And, on a player speech turn inside a scene that produced **no** command at all, the post-gate appends the carrier
`ExtCmdLRG_SceneControl@ok=1;cid=..;npc=..;do=lead;who=<current leader>;hold=<lead.hold_seconds>`.

---

## 10. G4 - no stale state (SERVER + GAME)

Four independent paths, because any one of them can be missed. Path 1 alone would have fixed playtest 6.

### 10.1 A funcret closes the row (SERVER, path 1)
`lrgRecordResult()` today stores and logs and stops. Add: when `$ok === false` and the reason is exactly `no scene is running` and the code starts `ExtCmdLRG_`:
```php
$scene = lrgGetActiveScene();
if ($scene && strcasecmp($scene['_npc'], $npc) === 0) {
    lrgStoreScene($npc, ['ev' => 'end'] + $scene);
    lrgLog("scene row for $npc closed: the game says no scene is running", $cid);
}
```
This is the single most reliable statement the game can make, and the closed error list (`PROTOCOL §1.6`) makes matching on it safe. The server already had this evidence at 19:33:57 and threw it away.

### 10.2 A session change closes the row (SERVER + GAME, path 2)
The game adds `sess=<sessionTag>` to `lrg_npcstate` and to `lrg_scene` (§2.1). The server stamps `_sess` into the scene payload from every scene message. Then, in `lrgStoreNpcState()`:
```php
if ($kv['sess'] is set) {
    $row = the active scene row (any NPC);
    if ($row has _sess and _sess !== $kv['sess']) -> close it, log "…: the game was reloaded";
    if ($mem['sess'] !== $kv['sess']) lrgMemSet($npc, ['sess'=>…, 'session_at'=>lrgNow()]);
}
```
This is name-free: it closes the row even when the reload lands on a save whose partner was somebody else, which is precisely playtest 6's case. `session_at` also feeds the post-reload cooldown (§11).

### 10.3 The game says so on load (GAME, path 3)
`ev=sync;running=0|1` from `LRG_OStim.Maintenance`, on both branches, plus `ev=end` on the no-thread branch when `partnerName` survived the save, plus one forced snapshot of the crosshair actor from `LRG_Main.Maintenance` (§14.4). These arrive as *fast* messages handled at `main.php:193`, before the MAIN lock, so the row is closed before any turn can be built from it.

### 10.4 The existing snapshot rule (SERVER, path 4) - unchanged
`lrgGetActiveScene()`'s `+2 s` newer-snapshot rule stays exactly as it is. It is a slow backstop, and tightening it risks closing a row on the forced snapshot the game sends *just before* a scene starts. Paths 1-3 are the fast ones.

### 10.5 Cache invalidation
`context_pre.php:26-29` sets `SCRIPTLINE_ANIMATION_SENT` from `$turn['scene']` / `lrgGetActiveScene()`. Because paths 1-3 close the row before the turn is built, no separate invalidation is needed. Add only: `lrgPrepareTurn()` must call `lrgGetActiveScene()` **after** `lrgHandleGameMessage()` has run for this request (it already does - `preprocessing.php` runs at `main.php:193`, `functions.php` at `:1615`).

---

## 11. G3(d) - heat, cooldowns and when `BeginIntimacy` is offered (SERVER)

### 11.1 The counter
`lrg_memory` keys: `heat` (int), `heat_at` (int), `session_at` (int, §10.2), and `last_scene_end_at` (int, written in `lrgHandleSceneMessage()` on `ev=end`).

`heat` is incremented by 1 in `lrgPrepareTurn()` on a player-speech turn in mode `public` / `private` / `follow` when **either**
- the recogniser found a kind in `{act, undress, dress, climax}` (any confidence), **or**
- the cleaned utterance matches `start.heat_pattern` (config; default covers kiss / bed / naked / want you / touch / beautiful / love / sex / fuck / flirt vocabulary),

and it is **reset to 0** when `lrgNow() - heat_at > start.heat_window_seconds` (default 600) before the increment, and on `ev=end` of a scene.

### 11.2 The offer rule for `LRG_ACT_START`
In `lrgPrepareTurn()`'s non-scene branch, the `private` / `follow` offer map gains a condition. `START` is offered when **any** of:
1. the player asked for physical contact this turn: `$turn['intent']['kind']` in `{act, undress}` with any confidence - *"when the PLAYER asks for physical contact the action is always offered at once"*;
2. `$mode === 'follow'` - she already invited him and they are now alone; the invitation **is** the build-up;
3. `heat >= start.min_heat` (default **2**) **and** `lrgNow() - last_scene_end_at >= start.post_scene_cooldown_seconds` (default 300) **and** `lrgNow() - session_at >= start.post_reload_cooldown_seconds` (default 120).

Otherwise `START` is **hidden** and the `<this_moment>` block adds one sentence:
*"A question about this is a question: `<npc>` answers it in words. `<npc>` does not start anything physical this turn."*

That sentence plus the hidden action is the whole fix for playtest 6's 19:28:59 (a sexual *question*, first turn after a load, heat 0, session 14 s old -> all three of rule 3's clauses fail).

`ChangeClothing` outside a scene keeps today's rule (offered in `private` / `follow`), because undressing is reversible and the player's own request path needs it.

### 11.3 Unchanged
The hard gates decide *whether she is willing at all*; heat only decides *whether the action is on the table this turn*. An NPC who fails a gate is still `closed` or `silent` exactly as today.

---

## 12. G6 - diagnostics (SERVER)

### 12.1 Timestamps
`lrgLog()` must stop depending on the ambient process timezone (`main.php:11` sets `Europe/Madrid`, `npc_master.class.php:1370` flips it to UTC mid-request and never restores it, CLI runs default to UTC):
```php
$tz   = new DateTimeZone((string) (lrgConfig()['log_timezone'] ?? 'Europe/Madrid'));
$when = (new DateTimeImmutable('@' . lrgNow()))->setTimezone($tz);
$line = $when->format('Y-m-d H:i:s P') . ' ' . ($cid !== '' ? "[cid=$cid] " : '') . $msg . "\n";
```
The offset is printed so a future mismatch is visible instead of silent. `lrgNow()` stays the time source (the flow tests move the clock). Never call `date_default_timezone_set()` ourselves.

### 12.2 The three lines every turn must produce
All under the cid. Formats are fixed so the next forensics pass can grep them.

**(a) turn** - extends the existing two `turn …` lines in `lrgPrepareTurn()`:
```
turn npc=<n> type=<request type> mode=<mode> scene=<id|-> say="<first intent.log_utterance_chars chars>"
     intent=<kind>/<who|act|-> conf=<high|low|none> why=<blocked reason|->
     offered=<Start,Control,Clothing,Act,Invite|none> hidden=<Code(reason),…>
     lead=<yes|no> hold=<seconds left|0> heat=<n> prop=<act|-> reached=<n> ceiling=<n>
```
`hidden=` must carry the **reason** for each glue action that was hidden: `Start(heat 0<2)`, `Start(post-reload 14s<120)`, `Control(not can_act)`, `Act(no scene)`, `Invite(mode private)`. This is the diagnostic the last three playtests all lacked.

**(b) llm** - new, written at the top of `lrgPostProcessActions()`:
```
llm npc=<n> action=<Code|none> item="<raw item, 80 chars>" spoke=<chars she said>
    resolved=<kv | dropped:<reason>>
```

**(c) net** - new, written at the end of `lrgPostProcessActions()`:
```
net fired <Code> <kv>          |   net skipped: <precondition that failed>
```

### 12.3 Also log, once each
- an empty player transcript (`say=""` with a request type of player speech) - `WARN empty transcript` (17 % of playtest 6's uploads);
- `narrator_inputtext` while a scene runs - `WARN player speech routed to the Narrator while a scene runs (npc=<n>)`;
- a dropped lead tick, with the reason and the seconds left.

### 12.4 Emitted-vs-confirmed watchdog
One of ten commands in playtest 6 passed the gate and never reached the game, and nothing noticed.
- On every emitted glue command: `lrgMemSet($npc, ['pending_cmd' => ['cid'=>…, 'code'=>…, 'do'=>…, 'at'=>lrgNow()]])`.
- `lrgRecordResult()` clears it when the funcret's `cid` matches.
- At the top of `lrgPrepareTurn()`, if a `pending_cmd` is older than `command_confirm_seconds` (default 20) and still set: log
  `WARN command not confirmed by the game: <code> do=<do> cid=<cid> age=<n>s` and clear it.
This turns a silent loss into a grep-able line without any retry logic (a retry could double-execute).

### 12.5 Two warnings from our own code, 9x each in a 10-minute window
- `lib/lrg_scene_index.php:225` `@mkdir($dir, 0770, true)` - `@` does not suppress it because CHIM installs an error handler. Guard: `if (!is_dir($dir)) { @mkdir(…); }`.
- `@unlink(LRG_DIR . '/data/.index_wanted')` (two call sites) - guard with `is_file()`.

---

## 13. Server state and config - the complete list

### 13.1 `lrg_memory` payload (per NPC)
| key | v0.2 | v0.3 |
|---|---|---|
| `interest`, `interest_score`, `invite`, `initiative_at`, `last_result`, `lead_idle` | yes | unchanged |
| `player_request_at` | - | **new** int, the hold clock (§7.2) |
| `proposal` | - | **new** `{act, at, expires}` or absent (§7.4) |
| `heat`, `heat_at` | - | **new** int (§11.1) |
| `last_scene_end_at` | - | **new** int (§11.1) |
| `sess`, `session_at` | - | **new** (§10.2) |
| `say_first` | - | **new** `{what, at}` (§8.3) |
| `pending_cmd` | - | **new** `{cid, code, do, at}` (§12.4) |

### 13.2 `lrg_scene_state` payload (per scene, reset with the scene)
`_maxtier`, `_tier_since`, `_climaxes` unchanged. **New**: `_visited`, `_acts_done`, `_proposals`, `_last_prop_at`, `_prop_no`, `_sess`.

### 13.3 `$GLOBALS['LRG_TURN']` - new keys
`intent` (§3.1) · `directive` (the string §4 produced, for the log and the tests) · `acts` (`lrgActOptions()` result) · `propose` (§7.4) · `heat` (int) · `blunt` (bool, replaces `announce`) · `hidden` (`[code => reason]`, for §12.2a) · `say_first` (bool).

### 13.4 New config keys (`config/lrg_config.default.json`), all with in-code defaults
```json
"log_timezone": "Europe/Madrid",
"command_confirm_seconds": 20,

"intent": {
  "enabled": true,
  "safety_net": true,
  "scene_confirm_seconds": 60,
  "log_utterance_chars": 160,
  "net_kinds": ["undress","dress","act","faster","slower","speed","stop","hold","release",
                "climax","winddown","furniture","lead_npc","lead_player","yes"],
  "words": { "<kind>": "<plain phrase for the directive>", … }
},

"lead": {
  "hold_seconds": 150,
  "no_repeat_scenes": 12,
  "ask_first": { "enabled": true, "min_act_seconds": 75, "cooldown_seconds": 120,
                 "max_per_scene": 3, "ttl_seconds": 90 }
},

"start": {
  "min_heat": 2,
  "heat_window_seconds": 600,
  "post_scene_cooldown_seconds": 300,
  "post_reload_cooldown_seconds": 120,
  "heat_pattern": "<regex of romantic / sexual vocabulary>"
},

"acts": { … §6.2 … },

"landing_notes": { "enabled": true, "min_gap_seconds": 20 },
"scene_summary": { "enabled": true },

"scene_talk": { "blunt_chance": { "quiet":40, "normal":70, "vocal":85, "crude":90, "romantic":75, "never":0 } },
"scene_index": { "max_acts": 8 }
```
`scene_talk.announce_chance` stays readable as a fallback for `blunt_chance` so an existing user `lrg_config.json` keeps working.

### 13.5 G5.9 - a landing description after every change
In `lrgHandleSceneMessage()`, on `ev=change` with `trans=0`, a scene id that really changed, and at least `landing_notes.min_gap_seconds` since the last one:
```php
logEvent(['infoaction', $ts, $gamets, lrgLandingLine($npc, $sceneDigest)]);
```
`lrgLandingLine()` composes ONE neutral present-tense clause from index vocabulary only - the readable scene name (`lrgDescribeScene()` minus the `[tier]` bracket and the pack tokens), the pose words and the furniture. No tier word, no numbers, no invented prose. This is the existing `infoaction` channel (log-only on the server, but it enters CHIM's context, memory and diary), so it is what gives her a coherent answer to "what are we doing".

### 13.6 G5.10 - one summary per scene
On `ev=end` of a scene that was really open (`lrgSceneWasOpen()`), replace today's fixed end line with one composed line built from the scene payload: how long it ran, the act families in `_acts_done`, whether `_climaxes > 0`, the furniture, and who led. One sentence, same `infoaction` channel. Written once, and only when `scene_summary.enabled`.

---

## 14. GAME - the change list, smallest first

All additive on the wire. **Every new MCM id needs a line in `settings.ini` as well as an entry in `config.json`, or MCM Helper reads it as 0 / false.**

### 14.1 One number (G2)
`settings.ini:33` `fSceneLeadInterval = 45` -> **75**, and the `config.json` slider help updated.

### 14.2 Two `CmdClothing` seam bugs (found by the game audit; neither weakens the crash fix)
1. **`glueStripped` is never cleared** (`LRG_OStim.psc:130`, set at `:760`). After any mid-scene `do=dress` for the NPC, `GlueUndressNode`'s guard at `:758` skips her for the rest of the scene - she stays dressed through a full-strip sex node. In the `dress` branch of `CmdClothing`, clear the bit of each actor being redressed. Symmetrically, an explicit in-scene `undress … part=all` may set the bit so the ladder does not strip twice (cosmetic).
   Also key the bitmask on `OThread.GetActorPosition(0, actor)` rather than the array index of `OThread.GetActors(0)`, so the bits do not describe the wrong actor when positions swap.
2. **`CmdClothing` resolves the NPC only through `getAgentByName`** (`:1479`). Mirror `CmdControl` (`:1628`): in a running scene, when `IsPartnerName(asNpcName)` is true, prefer the `partner` the script already holds. Removes a spurious `Error: the actor could not be found` inside a scene the glue is running.
3. While in there: the in-scene param shape carries no `maxwit` / `folok`, so if `OThread.IsRunning(0)` happens to be false at that instant the command falls into the out-of-scene branch (`:1503-1513`) and refuses with `someone is watching`. Guard: when `startedByGlue` and the thread ended within the last 2 s, answer `Error: no scene is running` instead of running the privacy branch.

### 14.3 `hold=` and the lead hold (G2)
- `CmdControl` (`:1617`) and `CmdClothing` (`:1521`): one `ParamGet(asParam, "hold")`, clamp `0..600`, `leadHoldUntil = Utility.GetCurrentRealTime() + hold` when `> 0`. New script variable `float leadHoldUntil`.
- `MaybeLead` (`:2484`): `if afNow < leadHoldUntil : return`.
- `leadHoldUntil` must **survive** `MarkDirty` (unlike `lastActivity`) and is cleared in `ResetState()` and `Maintenance()`.
- `CmdLead` (`:1929`) must accept `hold=` too - it is the server's no-op carrier.
- New MCM `fLeadHold:SceneTalk` default **150** is the value the game uses when `hold=` arrives without a number, and the owner-visible knob.

### 14.4 Load / maintenance sync (G4)
1. `LRG_OStim.Maintenance`, **no-thread branch** (`:226`), before `ResetState()`:
   - when `partnerName != ""` and the glue is enabled: send `ev=end` with the surviving `partnerName`, `startCid`, `byglue`;
   - always: send `ev=sync;running=0;sess=<sessionTag>;npc=<partnerName or "">`.
2. `LRG_OStim.Maintenance`, **running branch** (`:210`): after `AdoptRunningThread()`, set `lastSentKey = ""` (so the first push after a load is never de-duplicated away, `:2392-2396`) and send `ev=sync;running=1;…` via `PushState`-shaped payload.
3. `LRG_Main.Maintenance`, right after `ost.Maintenance()` (`LRG_Main.psc:113`): force one snapshot of the crosshair actor -
   ```
   Actor look = Game.GetCurrentCrosshairRef() as Actor
   if look
       MaybeSnapshot(look, true)
       Watch(look, false)
   endif
   ```
   Name-free, and it carries `ostim=0` and the new `sess`, which closes the row in the same second.
4. `sess=<sessionTag>` added to `PlaceFacts` (`LRG_Main.psc:683`, next to `x=`) and to `PushState` (`LRG_OStim.psc:2403`).
5. `prev=<last non-transition scene id>` added to the `ev=change` push (`PushState`), from `lastSceneId` before it is overwritten.

### 14.5 `wait=begin|end` - the announce gate (G3a / R10) - the largest item
Honoured by `CmdStart`, `CmdControl` (`goto`, `furniture`) and `CmdClothing`. Missing key = today's immediate behaviour.

- **`wait=begin`** - satisfied immediately if `AIAgentFunctions.isActorTalking(partnerName) != 0` when the command arrives; otherwise wait for the first `CHIM_SpeechStarted` for the partner that is **strictly after** the command arrived (so a still-playing earlier sentence does not satisfy it), budget `fSayFirstWait:SceneTalk` (default 6 s).
- **`wait=end`** - as above, plus wait for `CHIM_SpeechStopped` for the partner (or `isActorTalking()` returning to 0), budget `fSayFirstMaxWait:SceneTalk` (default 12 s).
- **Both budgets end in "do it anyway."** A start is never lost.
- **Do the waiting in `Tick`, never inline.** `CmdStart` already blocks its Papyrus thread ~4 s in `SettleWeapons` (`:875-906`) and ~2.5 s in `GuardStart` (`:908-922`); adding 12 more on the bridge call stack invites a dumped stack. Restructure: `CmdStart` does the gates and the weapon prep, then parks in a new `pendingStart` state with a deadline; `Tick` builds the thread and calls `OThreadBuilder.Start` once the speech condition or the deadline is met. `RequestTick(0.5)` while parked.
- `startDeadline` must grow by the budget (`:506` `now + 40` and `:609` `now + 20`), or a slow TTS fetch becomes `Error: the scene did not start` in `Tick` (`:2211-2218`).
- Same pattern, much simpler, for `goto` / `furniture` / clothing: park the OStim call in a small pending record (`pendingVerb`, `pendingParam`, `pendingUntil`, `pendingNeedEnd`) and let `Tick` perform it.
- Feed the partner's speech timestamps from `LRG_Main`: `OnChimSpeechStarted` / `OnChimSpeechStopped` forward to a new `ost.NotePartnerSpeech(bool abStarted, float afNow)` so `LRG_OStim` does not have to poll the native every 0.5 s. (`CHIM_SpeechStarted` fires **per sentence** - `AIAgentAIMind.psc:1708` - so "started" must only latch the first one after the command.)
- New MCM master `bAnnounceWait:Intimacy` (default 1). Off = ignore `wait=` entirely, i.e. today's behaviour, as an escape hatch if the owner finds the pause annoying.
- **`stop` is never waited on**, in any form.

### 14.6 Listener routing - stop the Narrator stealing the player's words
Five of thirteen player utterances in playtest 6 went to `narrator_inputtext` because during a scene no NPC is under the crosshair, so CHIM's "who am I talking to" routing fell back to the Narrator. Our four actions do not exist on a narrator turn and the partner never heard a word of it.

The lever exists and is CHIM's own: `AIAgentFunctions.setDrivenByAIA(Actor forcedActor, bool salutation)` - the native CHIM's own crosshair hotkey uses (`AIAgentPapyrusFunctions.psc:469`, `:835`) to force the conversation partner. Nothing in CHIM is edited; this is a public Papyrus native.

Design:
- New MCM toggle `bForceListener:Intimacy`, default **1**.
- Call `AIAgentFunctions.setDrivenByAIA(partner, false)` once in `OnOStimThreadStart` for a glue-run thread, once in `AdoptRunningThread`, and re-assert from `ThreadTick` at most every `15 s` while the thread runs (the same place that re-asserts `setAnimationBusy`).
- Do **not** call anything at scene end: the crosshair takes over again by itself.
- Log each call through `LogC` so the next playtest can see it in the glue log.

**This is the one game-side item with a real unknown** (see §17.1). If the in-game check shows a side effect (a salutation, a stolen conversation, a forced greeting), turn the toggle off and ship without it; the server-side half of the defence (§3.7: the hold clock still advances, and a WARN line is logged) works either way.

### 14.7 `nowarp=1` (G5.5)
`CmdControl`'s `goto` branch (`:1682-1692`) and `CmdFurniture` (`:1882`):
```
if why == "unreachable"
    if ParamGet(asParam, "nowarp") == "1"
        why = "that position cannot be reached from here"     ; never warp on an NPC-led move
    elseif m.SettingBool("bAllowWarp:Intimacy", true)
        useWarp = true ; why = ""
    else
        why = "that position cannot be reached from here"
    endif
endif
```
Missing key = today's behaviour exactly.

### 14.8 Route length (note, no code change required)
`iNavigationReach:Intimacy = 5` means one `goto` may traverse five transitions while `navInFlight`'s deadline is 25 s (`:1725`). The server now routes act by act and prefers near hops (§6.4), so this stays as it is - but if the game builder sees navigations outliving the deadline in testing, raise `navDeadline` rather than lowering the reach.

### 14.9 Deferred, not in this round
`iKeyPushToTalk:Keys` (a glue hotkey mirrored on CHIM's voice key, so the game knows the player is speaking). The server-side hold (§7.2) covers the same ground at zero risk; revisit only if lead ticks still collide with the player after playtest 7.

---

## 15. GAME - MCM additions (exact)

`MCM/Config/LoreRimGlue/settings.ini` - **every one of these lines is mandatory**:
```ini
[Intimacy]
bAnnounceWait = 1
bForceListener = 1

[SceneTalk]
fSceneLeadInterval = 75      ; changed from 45
fLeadHold = 150
fSayFirstWait = 6
fSayFirstMaxWait = 12
```

`config.json` entries (page / id / type / help - text written for the owner, not for a developer):

| page | id | type | text | help (substance) |
|---|---|---|---|---|
| Intimacy | `bAnnounceWait:Intimacy` | toggle | `Let her finish her line before she moves` | When she starts something herself - a kiss, a new position, undressing, moving to a bed - the game waits until her spoken line has started (or finished, for the start of a scene) before the animation changes, so a scene never begins out of nowhere. Waits a few seconds at most and then goes ahead anyway. Things YOU ask for happen immediately. |
| Intimacy | `bForceListener:Intimacy` | toggle | `Always talk to your partner during a scene` | During a scene there is usually nobody under your crosshair, so what you say can be answered by the Narrator instead of your partner - who then never hears it. With this on, your partner stays the one you are talking to for as long as the scene runs. |
| Scene talk | `fSceneLeadInterval:SceneTalk` | slider (20-180, step 5) | `NPC takes the lead after` | *(existing; default now 75)* |
| Scene talk | `fLeadHold:SceneTalk` | slider (0-600, step 10) | `Leave the lead to me for` | After you say what you want, she does not take a turn of her own for this long. Raise it if she still moves things along while you are steering; 0 = she may take a turn as soon as the interval above has passed. |
| Scene talk | `fSayFirstWait:SceneTalk` | slider (0-15, step 1) | `Wait for her line (position changes)` | How long the game waits for her voice before making a change she chose herself. 0 = no wait. |
| Scene talk | `fSayFirstMaxWait:SceneTalk` | slider (0-25, step 1) | `Wait for her line (start of a scene)` | How long the game waits for her line to finish before a scene starts, so the fade does not cut across it. 0 = no wait. |

---

## 16. Tests that must pass (SERVER owns all of them)

### 16.1 New offline tool: `tools/test_intent.php`
Pure table-driven, no DB, no index: loads `lib/lrg_actions.php` with a fake `$GLOBALS['db']`, then asserts `lrgRecogniseIntent()` row by row. Must print `OK` and exit 0. **The playtest-6 replay is this table** - every row below is a verbatim transcript from the forensics report (or the owner's list) and each is a regression test.

*(These are the **player's** utterances, the input a recogniser cannot be tested without, and the round prompt names them explicitly. `PROTOCOL §0`'s "no example dialogue" rail is about lines an **NPC** says - never write one of those into a test, a prompt or a config.)*

| # | utterance | kind | conf | kv / act | note |
|---|---|---|---|---|---|
| 1 | `I want you to get naked.` | `undress` | high | `who=npc` | frame 2 |
| 2 | `Take off your clothes.` | `undress` | high | `who=npc` | frame 1 + frame 4 |
| 3 | `Take your clothes off.` | `undress` | high | `who=npc` | the 19:38:17 turn that warped into sex instead |
| 4 | `I want you to take my clothes off.` | `undress` | high | `who=player` | **the who-inversion fix** (§3.4) |
| 5 | `I want to see you naked.` | `undress` | high | `who=npc` | |
| 6 | `get naked` | `undress` | high | `who=npc` | |
| 7 | `naked` | `undress` | **low** | `who=npc` | bare noun: net must NOT fire |
| 8 | `Tell me, what do you picture my cock looking like?` | `none` | none | - | question (§3.5); `BeginIntimacy` must also be hidden by §11.2 |
| 9 | `Hey Lazette, do you wanna follow me?` | `none` | none | - | mangled name, not a request |
| 10 | `kiss me` | `act` | high | `act=kiss` | |
| 11 | `fuck me from behind` | `act` | high | `act=vaginal`, scene from the `doggystyle` synonyms | |
| 12 | `I wanna you doggy style.` | `act` | high | `act=vaginal` | STT dropped the verb |
| 13 | `i want to bend you over` | `act` | high | `act=vaginal` / `bendover` | |
| 14 | `faster` | `faster` | high | `do=faster` | |
| 15 | `slow down` | `slower` | high | `do=slower` | |
| 16 | `stop` | `stop` | high | `do=stop` | |
| 17 | `don't stop` | `none` | none | - | negated stop must NOT be a stop |
| 18 | `you lead` | `lead_npc` | high | `do=lead;who=npc` | and it must CLEAR `player_request_at` |
| 19 | `surprise me` | `lead_npc` | high | `do=lead;who=npc` | must not become a random position (`@any`) |
| 20 | `I'll lead` | `lead_player` | high | `do=lead;who=player` | |
| 21 | `yes` *(proposal pending)* | `yes` | high | the pending act | |
| 22 | `not now` *(proposal pending)* | `no` | high | - | and the act lands in `_prop_no` |
| 23 | `yes` *(no proposal)* | `none` | none | - | |
| 24 | `should I take this off?` | `none` | none | - | addendum 3d, verbatim |
| 25 | `don't get naked yet` | `none` | none | - | addendum 3d, verbatim |
| 26 | `can you take your clothes off` | `undress` | high | `who=npc` | polite-request frame beats the question blocker |
| 27 | `let's move to the bed` | `furniture` | high | `do=furniture;furn=<bed type>` | needs a `furn_options` ctx |
| 28 | `put your clothes back on` | `dress` | high | `who=npc` | must not hit the undress branch |
| 29 | `` *(empty)* | `none` | none | - | and a `WARN empty transcript` line |
| 30 | `What do you want to try?` | `none` | none | - | question, from the playtest |

### 16.2 New flow scenarios (`tools/flows/scenarios/`)
| id | name | asserts |
|---|---|---|
| `19` | `19_intent_directive_net` | on a scene turn with utterance #2: `$turn['intent']` is `undress/high`; the volatile guidance contains a `<player_request>` block naming `ChangeClothing` and `undress`; **`fxLlm()` with NO action returns exactly one wire line**, `ExtCmdLRG_Clothing@…do=undress;who=npc…`; with the correct action it returns exactly one line (no duplicate); with utterance #7 (`low`) and no action it returns **zero** lines |
| `20` | `20_lead_hold` | a player speech turn in a scene writes `player_request_at`; a lead tick 10 s later returns `handled` (no turn, no guidance); one 160 s later is served; `you lead` clears the hold; every command emitted on a player speech turn carries `hold=150`; a turn that emits nothing appends the `do=lead;who=<current leader>;hold=150` carrier and **keeps the leader** when the payload says `leader=player` |
| `21` | `21_say_first` | `BeginIntimacy` with `$GLOBALS['talkedSoFar'] = []` -> dropped, `say_first` set, next turn's guidance carries the re-ask line; with speech -> emitted and carries `wait=end`; a self-initiated `goto` with no speech -> dropped; the same `goto` requested by the player -> emitted, **no** `wait`, **no** `nowarp`; a self-initiated one -> `wait=begin;nowarp=1`; `faster` is exempt in both directions |
| `22` | `22_stale_state` | a funcret `Error: no scene is running` closes the row in the same call; `ev=sync;running=0` closes it; a snapshot with a changed `sess` closes it; after each, the next turn is **not** in mode `scene` and `<intimate_scene_now>` is absent |
| `23` | `23_acts` | act ids are stable across two turns for the same scene (the `P<n>` regression); `RequestAct` with a valid act id emits `do=goto` with a scene of that act; with an act above the ceiling **on her own lead tick** -> dropped as too soon; the **same** act requested by the player -> emitted (addendum 3e); a visited scene is not re-offered on a lead tick but **is** reachable when the player names it |
| `24` | `24_ask_first` | a lead tick with a new sexual act and `_tier_since` 80 s -> `$turn['propose']` set, no command emitted, guidance asks; with speech the proposal is recorded; without speech it is not; while pending, lead ticks return `handled`; `yes` executes; `no` records `_prop_no` and the same act is never proposed again this scene; `max_per_scene` holds |
| `25` | `25_heat_and_start` | first turn of a session, a sexual question -> `BeginIntimacy` hidden, `hidden=` names the reason; after two romantic exchanges -> offered; right after a scene ended -> hidden for `post_scene_cooldown_seconds`; a player request for contact -> offered immediately regardless of heat; mode `follow` -> offered regardless of heat |
| `26` | `26_diagnostics` | the three log lines of §12.2 are written with the right fields; the timestamp carries an offset and does not change when `date_default_timezone_set('UTC')` is called mid-test; the watchdog logs an unconfirmed command after `command_confirm_seconds` |

### 16.3 Harness / adapter additions (`tools/flows/adapter.php`, `harness.php`)
- `fxLlm($npc, $code, $item, $message = '')` must now set `$GLOBALS['talkedSoFar'] = $message === '' ? [] : [$message]` before calling `lrgPostProcessActions()` and clear it afterwards. Without this, §8.3 and §7.4 cannot be tested.
- New `fxLlmNothing($npc, $message = '')` - the LLM answered with speech only (or nothing): calls `lrgPostProcessActions([])`. This is the safety-net path and the most important new helper.
- `FX_ACT_REQUESTACT` constant + `FX_NAME` / `FX_GLUE` entries; `fxEnabledDefault()` adds it behind a new capability probe `actions_v8`.
- `FxTurnResult` gains `intent()`, `directive()`, `acts()`, `hidden()`.

### 16.4 Existing tests that must be updated, not deleted
- `14_say_it_or_silent.php` - the `[silent]` contract is gone (§8.1). Rewrite it as "every self-initiated change asks for a line; pace and player-requested changes do not", keeping the `LRG_TEST_ROLL['announce']` seam as `['blunt']`.
- `08_position_by_name.php`, `09_verb_set.php`, `07_scene_ladder.php` - a position by name may now arrive as `RequestAct`; assert on the **emitted wire line** (`do=goto;scene=…`), which is unchanged, not on which action the model used.
- `99_everything_offered.php` - add the act options as a source of scene ids.
- `tools/test_gates.php` - add the §11.2 heat / cooldown conditions to the `START` offer assertions.
- `tools/test_scene_index.php` - assert `roles` exists on digested scenes and that `lrgSceneActs()` flips direction with `$npcSlot`.

### 16.5 Run order (what "green" means)
```
php tools/test_intent.php            # new, must print OK
php tools/test_gates.php             # must print OK
php tools/test_scene_index.php       # must print OK
php tools/flows/run_flows.php        # 0 FAIL
php tools/flows/run_flows.php --strict   # release gate: also 0 pending, 0 warn
pwsh tools/compile.ps1               # GAME: must print OK
```
All of them run in WSL out of `$env:TEMP\lrg_test` per the environment rules; `deploy_server.ps1` lints and warms the index afterwards.

---

## 17. Assumptions that could not be verified, and the risks

1. **`setDrivenByAIA` really re-targets the next player utterance** (§14.6). Strongly implied - CHIM's own crosshair hotkey calls exactly this to pick who the player is talking to - but it was not observed doing so from a third-party script, and its side effects (`salutation=false` is passed, so no greeting is expected) are not documented. **Mitigation**: behind `bForceListener:Intimacy` (default on, owner can switch off); one log line per call; the server's WARN line (§12.3) measures whether narrator routing still happens in playtest 7. If it fails, G1's directive and safety net still work for every utterance that does reach the NPC.
2. **`chimRegisterPromptInjection` priority ordering** was not re-verified. Avoided entirely: the directive is appended inside the existing `prompt_bottom` block rather than registered separately (§4.1).
3. **`$GLOBALS['talkedSoFar']` is populated for every connector.** Verified for the streaming path (`returnLines()` at `lib/chat_helper_functions.php:1635`, filled before the post-process hooks at `lib/data_functions.php:6036`). `$GLOBALS['DEBUG_DATA']['response']` is the documented fallback and the code must use it when `talkedSoFar` is empty, so a non-streaming connector cannot silently turn every self-initiated change into a drop.
4. **Appending a wire line from the post-process closure works in game.** Verified in the source (the closure's return value *is* what is echoed, `:6483`) and our plugin already appends in one branch (`lrg_actions.php:611-612`) - but that branch has never fired in a real session. **Mitigation**: §12.2c logs every net fire, and §12.4's watchdog will say within 20 s if the game never confirmed it.
5. **R11's scope.** "For SEX acts she asks first" is implemented as *a sexual-tier act whose family is not yet in `_acts_done`* (§7.4). Asking before every position change inside an act already running would be nagging and contradicts "rarely (cooldown, max per scene)". If the owner meant every sexual-tier change, it is a one-line change to condition 2.
6. **Act display names.** The 15 families are derived from the action vocabulary of the installed packs (`scene_index.synonyms`), not from a published OStim taxonomy. A pack that names an action differently falls into no family; such a scene is then reachable only by plain words, never as an act key. `lrgActOptions()` must log the count of unmapped actions once per index build so gaps are visible.
7. **`ppos` / `npos` are reliable for roles.** They come straight from `OThread.GetActorPosition` and are already on the wire, but they were never used before. When they are missing or equal, every directional family degrades to `:npc` and a log line says so.
8. **The privacy test still counts human witnesses only.** Playtest 6's second half happened in the Solitude Sewers surrounded by hostile creatures while the gate logged `mode=private`. That is existing behaviour and **out of scope for this round**; it is noted here so it is not mistaken for a v0.3 regression. Worth a config knob next round.
9. **OStim's free camera** (`OStimUseFreeCam = 1`) is half of "director mode" and is the **owner's** MCM setting - the glue must never write it (PROTOCOL 0 rail). Escape hatch meanwhile: numpad `/`.
10. **The lost command of playtest 6** (one of ten passed the gate and never reached the game) has a hypothesis only (`setAnimationBusy` + CHIM's speaker lock). §12.4 turns it into a measurement; nothing in this round retries a command, because a retry could double-execute.

---

## 18. Build order and done criteria

**SERVER** (each step leaves the plugin working):
1. §12.1 timestamps, §12.5 warnings, §10.1 funcret-closes-row - three small, independent fixes that already repay themselves.
2. §3 recogniser + §12.2 logging (no behaviour change yet: the recogniser only logs). `tools/test_intent.php` green here.
3. §4 directive + §4.4 prompt rewrites + §11 heat/start rules.
4. §5 safety net.
5. §6 act layer, `LRG_INDEX_VERSION` 4, `RequestAct` row, `LRG_ACTIONS_VERSION` 8.
6. §7 lead hold, anti-circling, R11; §8 say-first; §9 decoration.
7. §13.5/§13.6 landing notes and summary; §10.2 sess; PROTOCOL.md rewritten to §2.

**GAME**:
1. §14.1 the one number, §14.2 the two `CmdClothing` bugs (independent, ship-able alone).
2. §14.3 `hold=`, §14.7 `nowarp=`, §14.4 load sync + `sess` + `prev` - all small and additive.
3. §14.5 `wait=` (the big one; `pendingStart` restructure first, then the verb path).
4. §14.6 listener routing, §15 MCM.

**Done when**: §16.5 is green on both sides, `install_mo2.ps1` installs with MO2 and Skyrim closed, `deploy_server.ps1` deploys and lints clean, and the glue log for one scripted scene shows - in order - a `turn` line with `intent=`, a `<player_request>` block in the prompt, an `llm` line, a `net` line, `hold=150` on the emitted command, no lead tick inside the hold, `wait=begin` only on self-initiated changes, and a `scene row … closed` line within one second of a reload.
