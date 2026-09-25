# Playtest 6 — Turn Forensics (read-only investigation)

Status: COMPLETE
Window: UTC 2026-09-21 17:27–17:39 (glue-log / CHIM clock 19:27–19:39, owner local 13:27–13:38)
NPC: Lisette. **NOT a private room** — see §2.

## 0. Method / sources

WSL distro `DwemerAI4Skyrim3`, HerikaServer at `/var/www/html/HerikaServer`.

### Clock facts (settles the G6 timestamp bug)

| clock | same instant |
|---|---|
| UTC | 17:29:00 |
| WSL system TZ (`America/New_York`, EDT) = owner local | 13:29:00 |
| PHP **web SAPI** — all CHIM logs *and* our glue log | 19:29:00 (`Europe/Madrid`, +02:00) |
| PHP **CLI** default | `UTC` |

**Root cause of our mixed stamps, proven:** `main.php:11` sets `date_default_timezone_set('Europe/Madrid')`
for every web request, but CHIM's NPC-backup path calls
`date_default_timezone_set('UTC')` at `lib/core/npc_master.class.php:1370`
(caller: `processor/comm.php:1337`, the `infosave` request) **and never restores it**. Everything
formatted with a bare `date()` later in that request comes out in UTC. Our logger
`ext/lorerim_glue/lib/lrg_core.php:62` is exactly that:

```php
$line = date('Y-m-d H:i:s') . ' ' . ($cid !== '' ? "[cid=$cid] " : '') . $msg . "\n";
```

Two independent witnesses in this window: our line `2026-09-21 17:34:04 scene row for Lisette closed…`
and CHIM's own `[NPC BACKUP] 2026-09-21 17:37:04` (UTC) sitting next to `[NPC RESTORE] 2026-09-21 19:27:52`
(UTC+2) in the apache error log.
**Fix:** format with an explicit `DateTimeZone` captured once at plugin load; never rely on the ambient ini.

### Where the evidence lives

| file / table | what it holds |
|---|---|
| `log/output_to_plugin.log` | the wire lines the game receives: `Player\|ScriptQueue\|<transcript>//__player_text_only///1.0`, the NPC's TTS lines, then `Lisette\|command\|ExtCmd…`. Writers: `lib/data_functions.php:6485`, `lib/chat_helper_functions.php:1862`. **The NPC's spoken lines are queued BEFORE the command line in the same payload.** |
| `log/context_sent_to_llm.log` | full `var_export` of the request: system prompt, our injected blocks, and the `json_schema` **action enum actually offered**. Block format `date(DATE_ATOM)\n=\n…\n=\n` (`connector/openrouterjson.php:816`). |
| `log/output_from_llm.log` | `== <ts> START / Request ID: N / <raw JSON> / == <ts> END` ⇒ LLM latency = END−START; `N` = `audit_request.rowid`. |
| `log/chim.log` | `Audit:Lock acquired by <request_type>`, `[PERF]` per run, SemaphoreManager MAIN acquire/release, `Echoing action to plugin:`, TTS. |
| `/var/log/apache2/other_vhosts_access.log` | every game→server call; `comm.php?DATA=` and `streamv2.php?DATA=` are **base64** and decode to `type\|gamets\|…\|Jordan: <text>`. Best single source for "what the game actually asked". |
| Postgres `dwemer` (`host=localhost port=5432 user=dwemer password=dwemer`) | `eventlog` (per-turn events + `delivery_state`), `speech`, `audit_request` (request/response/usage/connector), `actions_issued`, our `lrg_*` tables. |
| `log/stt.log` | **0 bytes — STT is not logged server-side at all.** The only STT trace is `Audit run ID: … (STT) started` in chim.log + the `POST /HerikaServer/stt.php` line in the access log. |

Connector: **OpenRouter → `x-ai/grok-4.3` (provider xAI)**, strict `json_schema`, `stream:true`,
`max_tokens:200`, `temperature:0.75`, `reasoning.effort:none`. 4.5k–7.8k tokens per turn.

**A save reload wipes CHIM's own audit.** The `init` at 19:33:45 cleared `eventlog`, `speech` and
`actions_issued` of everything before it. The 19:29–19:32 turns survive **only** in the flat logs.

---

## 1. THE TURN TABLE

`offered` = which of our four actions were in the `action` enum sent to the LLM that turn.
`lat` = LLM START→END. `req` = whole server request (MAIN lock held).

| PHP time | request type | what the PLAYER said (verbatim transcript) | our actions offered | what the LLM answered (message → action/item) | lat | req | glue `turn` line |
|---|---|---|---|---|---|---|---|
| 19:28:25 | `inputtext` | *(empty transcript: `Jordan:   `)* | — no LLM call — | — | — | 0.12 s | **NO** |
| 19:28:45 | `lrg_initiative` | — | — | — | — | — | (`GAME initiative tick`) |
| 19:28:59 | `inputtext` | " Tell me, what do you picture my cock looking like? " | **BeginIntimacy, ChangeClothing** | "Thick, hard, veins bulging when you get excited. The kind that stretches me open and leaves me sore." → **Begin_Intimacy** / Jordan | 9 s | 11 s | YES `s9f8361a9` |
| 19:29:51 | **`narrator_inputtext`** | " I want you to take my clothes off. " | **NONE** | The Narrator: "You want her hands on you right now." → Talk | 3 s | 5 s | **NO** |
| 19:30:06 | `lrg_scenetalk` (LEAD) | — | ChangeIntimacy, ChangeClothing | *(message empty)* → Change_Intimacy item `P1` | 1 s | 1.6 s | YES `sa6f90c7e` |
| 19:30:14 | **`narrator_inputtext`** | " I want to see you naked. " | **NONE** | The Narrator: "She's already bare and waiting for you." → Talk | 9 s | 11.1 s | **NO** |
| 19:30:45 | **`narrator_inputtext`** | " What do you want to try? " | **NONE** | The Narrator: "Try moving closer to her, see what happens next." → Talk | 5 s | 7 s | **NO** |
| 19:30:55 | `lrg_scenetalk` (LEAD) | — | ChangeIntimacy, ChangeClothing | *(empty)* → Change_Intimacy `P1` | 1 s | 1.7 s | YES `s2175d91b` |
| 19:31:34 | **`narrator_inputtext`** | " I want more. " | **NONE** | The Narrator: "What next, Jordan?" → Talk | 7 s | 9 s | **NO** |
| 19:31:45 | `lrg_scenetalk` (LEAD) | — | ChangeIntimacy, ChangeClothing | *(empty)* → Change_Intimacy `P4` | 1 s | ~2 s | YES `scbe60eb5` |
| 19:32:43 | **`narrator_inputtext`** | "i want to bend you over" — **typed, not spoken** (no `stt.php` upload, no leading space) | **NONE** | The Narrator: "What next, Jordan?" → Talk | 5 s | 6 s | **NO** |
| 19:32:45 → 19:32:49 | `lrg_scenetalk` (LEAD) | — | ChangeIntimacy, ChangeClothing | *(empty)* → Change_Intimacy `P2` | 1 s | ~2 s **+4 s waiting on MAIN** | YES `sbaa98cb3` |
| 19:33:45 | `init` | *(owner reloaded a save)* | | | | | (`GAME maintenance done`) |
| 19:33:54 | `inputtext` | " Hey Lazette, do you wanna follow me? " | ChangeIntimacy, ChangeClothing | "Take me right here, hard and deep. Now." → Change_Intimacy `P1` | 2 s | 3 s | YES `s618c972f` → **"Error: no scene is running"** |
| 19:34:13 | `inputtext` | *(empty transcript)* | — no LLM call — | — | — | 0.19 s | **NO** |
| 19:34:53 | `lrg_initiative` | — | — | — | — | — | (`GAME initiative tick`) |
| 19:35:43 | `inputtext` | " I want you to get naked. " | **BeginIntimacy, ChangeClothing** | "Already naked. On the floor. Waiting for you to fuck me." → **Begin_Intimacy** | 7 s | 8 s | YES `s55e91563` |
| 19:36:10 | `inputtext` | " Take off your clothes. " | ChangeIntimacy, **ChangeClothing** | "Take me right here, hard and deep." → **Talk (no action)** | 5 s | 6 s | YES `s60c86ee5` |
| 19:36:36 | `inputtext` | " I wanna you doggy style. " *(STT dropped "fuck")* | ChangeIntimacy, ChangeClothing | "Fuck me doggy style right here, now." → Change_Intimacy `P1` = **OARE_GropingButtKiss (a kiss)** | 4 s | 6 s | YES `s458a1b57` |
| 19:37:56 | `lrg_scenetalk` (LEAD) | — | ChangeIntimacy, ChangeClothing | *(empty)* → Change_Intimacy `faster` | 1 s | 2 s | YES `s6091dd5b` |
| 19:38:17 | `inputtext` | " Take your clothes off. " | ChangeIntimacy, **ChangeClothing** | "Fuck me on this floor, doggy style, hard and deep." → Change_Intimacy item **`from behind`** → `OARE_RearSexFPerformer warp=1` → **CRASH** | 6 s | 8 s | YES `s709ed526` |

`SuggestPrivacy` (`ExtCmdLRG_Invite`) **was never offered once** in the whole window.
`ChangeClothing` was offered on **every** Lisette turn and **chosen zero times**.

---

## 2. THE SETTING WAS NOT A PRIVATE ROOM

`eventlog` shows the owner walked out after the reload: The Winking Skeever → Solitude →
*Bits and Pieces* → Solitude → **Solitude Sewers**. The scene at 19:35:51 and everything after it
(including the crash) happened in the **Solitude Sewers**, with the prompt's `<actors_nearby>` reading:

> `Skeever (dead), Elytra Nymph (hostile), Lisette, Elytra Nymph (hostile), Skeever (hostile), … Gnarl (hostile), Skeleton (dead) …`

Our gate still logged `mode=private … maxwit=0` and the prompt still said **"It is private here."**
The privacy test counts *human witnesses*; hostile creatures and a sewer count as private. Worth a
config knob, and it explains why `Begin_Intimacy` was on the table in a dungeon.

---

## 3. ANSWERS

### (1) The "get naked" attempts

Six undress-intent utterances. **All were transcribed correctly.** All reached `main.php`.
`ChangeClothing` was offered on every one that reached Lisette. It was chosen **never**.

| # | time | utterance | reached | ChangeClothing offered? | what happened instead |
|---|---|---|---|---|---|
| 1 | 19:29:51 | "I want you to take my clothes off." | **Narrator**, not Lisette | no (narrator has 4 actions: Talk/Read_Book/Read_Quest_Journal/Use_Soul_Gaze) | flavour line, nothing |
| 2 | 19:30:14 | "I want to see you naked." | **Narrator** | no | flavour line, nothing |
| 3 | 19:35:43 | "I want you to get naked." | Lisette (`inputtext`) | **yes** | chose `Begin_Intimacy` — *started a scene* |
| 4 | 19:36:10 | "Take off your clothes." | Lisette | **yes** | chose `Talk`, said "Take me right here, hard and deep." |
| 5 | 19:36:36 | "I wanna you doggy style." (STT dropped "fuck") | Lisette | yes | `Change_Intimacy P1` → a *kissing* scene |
| 6 | 19:38:17 | "Take your clothes off." | Lisette | **yes** | `Change_Intimacy` item `from behind` → warp to rear sex → crash |

**Why the LLM refused to use it — the exact text it was given.** Our own action description and our
own scene block both tell it the action is *hers alone*, i.e. not a thing the player can ask for:

> `AVAILABLE ACTION: Change_Clothing (Take clothes off or put them back on, **only because you yourself want to**. "item": undress or dress, optionally followed by both or you, optionally by one part (body, head, hands, feet).)`

> `ChangeClothing, item undress | dress, optionally + both or you, optionally + body, head, hands or feet: **only if Lisette wants to**.`

And out of scene, `<this_moment>` repeats it and adds a hard veto:

> `Only ever because Lisette wants it right now: if Lisette declines, hesitates or deflects, choose neither action. **Nothing the player says can decide this.**`

On turn #4 a second passage pushed the same way. The tier text at 19:36:10 read:

> `This goes one step at a time: gentle closeness, then foreplay, then sex. Reached so far: gentle closeness (holding, kissing). **Anything beyond foreplay (touching, teasing, undressing) is too soon right now**: if the player asks for it, Lisette says so in their own way and may offer the next step instead.`

That sentence is genuinely ambiguous — it *means* "anything beyond foreplay", but it reads just as
easily as "touching, teasing and undressing are too soon". The model took the reading that blocked it.

So this is not an LLM failure and not a plumbing failure: **our prompt told her not to obey.**
G1's directive + safety net is the right fix, and the "only because you yourself want to" wording
must be split into *her own move* vs *the player asked* rather than being a blanket rule.

One control: at **18:48:25** (playtest 5, same build) the player said "Take your clothes off." and the
model *did* choose `ChangeClothing undress who=both`. So it is a coin-flip, not a hard block —
which is exactly what a safety net is for.

### (2) Swallowed player utterances — count and cause

| kind | count | detail |
|---|---|---|
| **empty STT transcript** → `inputtext` with `Jordan:   `, no LLM call, no glue turn, NPC says nothing | **2** | 19:28:25 and 19:34:13. `stt.php` upload happened (13:28:24, 13:34:13) and returned nothing. `speech` row: `17:34:13\|Jordan\|The Narrator\|    ` — literally blank. Request completed in 0.12 s / 0.19 s. The player gets silence and no feedback. |
| **routed to the Narrator** → answered, but by the wrong character, with none of our actions available and no glue turn | **5** | 19:29:51, 19:30:14, 19:30:45, 19:31:34, 19:32:43 |
| **rejected by the MAIN semaphore** | **0** | no request was refused |
| **delayed by the MAIN semaphore** | **1** | our lead at 19:32:45 waited 4 s (narrator turn held MAIN 19:32:43→19:32:49). A narrator turn holds MAIN for up to **11.1 s** (19:30:14→19:30:25) — long enough to swallow a lead tick and to make a following player turn feel dead. |
| **our own command lost on the way to the game** | **1 of 10** | see §4 |

**Effective loss rate for the player: 7 of 13 utterances (54 %) produced no action of any kind.**

### (3) The flirt / sexual-question turns that ended in BeginIntimacy

Two, and both were *questions or statements*, not requests for contact.

**19:28:59** — the very first player turn after loading. Player: *" Tell me, what do you picture my
cock looking like? "* → `Begin_Intimacy`, 11 s later the OStim fade started. **The `message` was not
empty** ("Thick, hard, veins bulging…") — the TTS lines were queued *before* the command in
`output_to_plugin.log`, so G3a's ordering already holds on the server side; what the owner
experienced as "out of nowhere" is that the line does not *announce* the move.

**19:35:43** — Player: *" I want you to get naked. "* → `Begin_Intimacy` (message "Already naked. On
the floor. Waiting for you to fuck me.").

**What in the prompt pushed her.** `<this_moment>`, injected by us on every private out-of-scene turn,
verbatim:

> `It is private here. If the moment is right, Lisette may make a move of their own or answer the player's: a plain proposition in words, BeginIntimacy (it starts gently - a kiss, an embrace - and **can simply happen, message left empty**), or ChangeClothing (item "undress") to strip.`

plus `<personal_boundaries>`:

> `How Lisette feels about the player right now: Lisette is attracted to the player and open to more. … **Lisette may well make the first move.**`

plus the action description:

> `AVAILABLE ACTION: Begin_Intimacy (Begin physical intimacy with the player; it always starts gently (a kiss, an embrace). **You may make the first move yourself.** ONLY because you yourself want it right now …)`

plus a memory summary that was in context on both turns: *"…Lisette confessed she wanted his hands on
her, to have him push deep inside her where no one else could reach."*

Nothing in that set says "answer a question in words"; three separate passages say "you may just do
it", and one explicitly licenses an **empty message**. With `temperature 0.75` and a sexual question
in front of it, `Begin_Intimacy` is the obvious pick. There was **no build-up counter and no
post-reload cooldown** — turn 1 after a load was enough.

`"can simply happen, message left empty"` is the single line that must go for G3(a)/(b).

### (4) "Director mode" — yes, and this is the headline

Between 19:29:51 and 19:32:43 **five consecutive player utterances arrived as
`narrator_inputtext`**. Decoded straight from the access log:

```
13:29:51 | narrator_inputtext|…|Jordan:  I want you to take my clothes off.
13:30:14 | narrator_inputtext|…|Jordan:  I want to see you naked.
13:30:45 | narrator_inputtext|…|Jordan:  What do you want to try?
13:31:34 | narrator_inputtext|…|Jordan:  I want more.
13:32:43 | narrator_inputtext|…|Jordan:i want to bend you over
```

Three consequences, all of them "I had no control":

1. **The Narrator answered, not Lisette** — "You want her hands on you right now." / "She's already
   bare and waiting for you." / "Try moving closer to her, see what happens next." / "What next,
   Jordan?" ×2. Exactly the voice of a director, which is what the owner heard.
2. **None of our four actions exist on a narrator turn.** The narrator's enum is
   `Talk | Read_Book | Read_Quest_Journal | Use_Soul_Gaze`. Nothing the player said could have moved
   the scene even in principle.
3. **Lisette never saw a word of it.** Narrator turns are stored under the narrator's conversation.
   Lisette's context for the four LEAD turns in that stretch ends with our own lead text, repeated
   and concatenated:

   > `# (a while passes; the player leaves it to Lisette) (a while passes; the player leaves it to Lisette) (a while passes; the player leaves it to Lisette) (a while passes; the player leaves it to Lisette`

   **The server was telling her the player was passive while the player was talking non-stop.** She
   then "chose" from the same unchanged option list four times running — which is precisely the
   "circling" the owner saw (`P1 → P1 → P4 → P2`), not a behaviour bug.

Mechanism, from the requests themselves: the `inputtext` calls carry an audience payload
(`{"audience_source":"unified_player_routing","companions":["Lisette","Jordan"],…}`) and the text is
suffixed `(Talking to Lisette)`. The five narrator calls carry **no audience payload at all** and no
suffix, and the `_speech` row the game sent alongside says `"listener":"The Narrator"`. So **the game
decided the listener was the Narrator** and CHIM simply obeyed. During an OStim scene the player is in
the scene camera with no NPC under the crosshair, so CHIM's "who am I talking to" routing has nothing
to lock onto and falls back to the Narrator. This is a *game-side / routing* problem, not a prompt
problem — hand it to the game-side audit, and consider having the game force the listener to the
scene partner while `LRG_OStim` holds a scene.

### (5) Latency — not the problem

LLM round-trip: min 1 s, median ~5 s, max 9 s. Whole-request (MAIN held): 1.6 s – 11.1 s.
End-to-end that the player feels (STT upload → "Speech sent for Lisette"): 6 s, 6 s, 8 s, 11 s.
`lrg_scenetalk` LEAD turns are the cheapest at 1.6–2.0 s.

Slow spots, in order:
- **narrator turns are the slowest** (7, 9, 9, 6, 5 s of LLM time; 11.1 s of MAIN) *and* produce nothing.
- `rechat` fires after most `inputtext` turns and takes MAIN again for ~0.2 s;
  `[RECHAT_SELECT] No valid responder selected; terminating rechat` ×6 — harmless but it is an extra
  MAIN acquisition sitting between the answer and the funcret.
- **TTS**: `stream_socket_client(): Unable to connect to unix://…/tts/audiofilterd.sock (No such file
  or directory)` + `Audio processing failed for PocketTTS response` on **every single line** (14× in
  the window). It falls back to ffmpeg and the audio is produced, so this is cosmetic — but it is 14
  error-log entries per 10 minutes masking real errors.
- `fsockopen(): Unable to connect to 127.0.0.1:12345 (Connection refused)` ×19, all at 19:27:51
  (`lib/background_processor.php:69`) — the background processor is not running. Load-time only.
- No 404s, no connector retries, no HTTP errors. `audit_request` shows 16 clean
  `openrouterjson/standard` calls.
- Two harmless warnings from **our** code, 9× each:
  `mkdir(): File exists in …/lorerim_glue/lib/lrg_scene_index.php:225` and
  `unlink(…/data/.index_wanted): No such file or directory` — noise worth silencing.

### (6) Everything else that makes voice control feel unreliable

**a. One of our ten commands never reached the game.** `cid=sa6f90c7e` (LEAD, 19:30:06):
gate passed 19:30:08, `Echoing action to plugin: Lisette|command|ExtCmdLRG_SceneControl@ok=1;cid=sa6f90c7e;…`
at 19:30:08 — and then **no `GAME command` line, no `lrg_log`, no `funcret`, ever.** The other nine all
produced a funcret within ~1 s. In `output_to_plugin.log` that command sits in a payload whose speaker
is **The Narrator**:

```
Player|ScriptQueue|I want you to take my clothes off.//__player_text_only///1.0
The Narrator|ScriptQueue|You want her hands on you right now./DialogueHappy/Jordan///1/Lisette/utt_a507c0ad897978fe70bc
Lisette|command|ExtCmdLRG_SceneControl@ok=1;cid=sa6f90c7e;npc=Lisette;do=goto;scene=OARE_GropingButtKiss
```

i.e. it was queued at 19:30:08, held while the narrator request owned MAIN (19:30:14→19:30:25), and
flushed bundled with a narrator response — and the game did not dispatch it. The exact DLL-side reason
needs the game-side audit; the server-side fact is firm. **Our design must not assume a command that
passed the gate was executed** — there is no timeout/retry today, and the glue log has no "command
never confirmed" line.

**b. Plain-words position items bypass the offered list — and warp.** At 19:38:17 the player said
*"Take your clothes off."*; the model answered `Change_Intimacy item="from behind"`. Our resolver
turned that into `OARE_RearSexFPerformer` **with `warp=1`**, a scene that was **not** in the P-list it
had just been given (P1…P6 were all kissing/sensual). So a scene change the player never asked for,
chosen by a free-text item, warped. Under G5(5) an NPC-led move must never warp. The
`"or any position in plain words"` escape hatch needs the same reachability/role/tier filtering as the
P-list, and `warp` needs to be gated on "the player explicitly asked" — which today we cannot tell,
because we have no intent recogniser.

**c. `P<n>` indices are re-numbered every turn.** `P1` meant `GropingButtKiss` at 19:30, `GropingButtKiss`
again at 19:36, `StandingEmbraceKiss` at 19:38. The model has no stable handle, and the same token
means different things two turns apart. G5's `RequestAct(act)` with stable act names fixes this.

**d. She answers the wrong thing after a reload.** At 19:33:54 the player asked *"Hey Lazette, do you
wanna follow me?"* — a plain follow request — and got "Take me right here, hard and deep. Now." plus a
`goto OARE_StandingCarryingSex`, because our stale `<intimate_scene_now>` block was still in the prompt.
That is G4 in one line: the prompt block is as stale as the scene row.

**e. The stale scene row is stale *right now*.** `lrg_scene_state` still reads
`active=1, cid=s55e91563, scene=OARE_GropingButtKiss, updated_at=1790012279` (17:37:59 UTC) — the crash
at 17:38:26 never closed it. The owner's **next** load will reproduce the 19:33:54 failure exactly.

**f. Contradictory nudity state in the prompt.** After `StartIntimacy … undress=1` at 19:35:51 the
`<equipment>` block still listed "Bard Common Robes / Boots / Wench Amulet" while her own last line in
context said "Already naked." The model then had no coherent answer to "take off your clothes".
Whatever the clothing state is, one place in the prompt must own it.

**g. STT quality is good but not perfect.** 2 empty transcripts out of 12 uploads (17 %);
"Lisette" → "**Lazette**"; "I wanna **fuck** you doggy style" → "I wanna you doggy style". An intent
recogniser must be tolerant of a dropped verb and of the NPC's name being mangled — and an empty
transcript should produce a visible "I didn't catch that", not silence.

**h. `delivery_state` is the hook G3(a) needs.** `eventlog` rows of type `chat` carry
`delivery_state`: every line in the window is `spoken` **except the last one before the crash**
(`17:38:25 | chat | emitted | Lisette: Fuck me on this floor…`). So the server already learns when a
TTS line has actually been delivered vs merely emitted — worth reading before the game-side audit
invents its own mechanism. (Ordering is already right in the payload: her `ScriptQueue` lines precede
the `command` line.)

**i. Our diagnostics gap, concretely.** The glue log today has no record of: the player's utterance,
the request type, which actions we offered or hid and why, what the LLM answered, or whether the
command was confirmed. Every one of those is recoverable from CHIM's own logs (§0) — G6 should log
them under the cid so the next playtest does not need this reconstruction.

---

## 3b. OWNER ADDENDUM 2 (revised R10) — the evidence for it

Addendum 2, verbatim: *"if she initiates any new scene/animation she should say something signifying it"*.
This round's forensics measures exactly how often that was violated, and why.

**Every single self-initiated change in the window was silent.** All five LEAD turns
(19:30:06, 19:30:55, 19:31:45, 19:32:45, 19:37:56) came back with `"message": ""`:

```
{"character":"Lisette","listener":"Jordan","message":"","mood":"sexy",
 "action":"Change_Intimacy","target":"Jordan","item":"P1", …}
```

Four of them were position changes (P1, P1, P4, P2) and one was `faster`. Under the revised R10 the
four position changes each owed the player a line; only `faster` is exempt (a pace change is not a new
scene). So **4 of 4 self-initiated scene changes broke the new rule**, plus the two `Begin_Intimacy`
starts had a line but not an announcing one.

The cause is again our own text — the scene block tells her to stay silent for anything gentle:

> `P1 = Groping Butt Kiss … [sensual] [say it] [next step]; P3 = OutstretchedArmsKiss … [kissing] **[silent]**; P4 = Standing Kiss … **[silent]** …`

> `**[silent] choices (kissing, holding), and pace, hold or clothes: leave message empty - it simply happens**, unless the player asked something that needs an answer. [say it] choices (foreplay, sex): Lisette says ONE short sentence that names what they want …`

Two notes for whoever implements R10:
- The `[silent]` / `[say it]` machinery already exists per option and is plainly being obeyed — the
  cheapest correct change is to **stop emitting `[silent]` for anything she initiates herself**, keep
  it only for changes the player asked for, and keep pace/hold outside the rule.
- Note that at 19:31:45 she picked `P4` and the glue resolved it to `OARE_GropingButtKissOA`, a
  `[say it]` sensual option — i.e. the index the model picked and the tier the glue landed on can
  disagree, so the "must she speak?" decision has to be made **after** resolution, server-side, not
  left to the marker the model saw. That also gives G3(b)/R10's server-side guarantee a natural home:
  resolve first, then require (or supply) a line.

---

## 4. ONE-LINE SUMMARY FOR THE DESIGN

Voice control felt broken for four independent reasons, in order of damage:
**(1)** 5 of 13 utterances went to CHIM's Narrator, where our actions do not exist and Lisette never
heard them, while our LEAD prompt simultaneously told her the player was passive;
**(2)** our own prompt forbids `ChangeClothing` in answer to the player ("only because you yourself
want to" / "Nothing the player says can decide this"), so it was offered 8 times and used 0;
**(3)** `Begin_Intimacy` is licensed to happen with an empty message and no build-up, so a sexual
*question* started a cutscene on the first turn after a load;
**(4)** small but real plumbing losses — 2 empty STT transcripts, 1 command of 10 silently dropped, a
stale scene row surviving a reload (and still open right now).
