# pt7 verification - OWNER EXPERIENCE + WIRE lens (v0.3.1)

Independent verifier, read-only except this file. 2026-09-21.
Verdict: **FIX FIRST** (3 major, all small fixes; everything else is minor or docs).

## 0. What I actually ran (not just read)

Staged the plugin + tools to `$env:TEMP\lrg_test` and ran them in WSL `DwemerAI4Skyrim3`
(PHP 8.2.28) against the REAL installed packs at `/mnt/f/Modlists/LoreRim`.

| run | result |
|---|---|
| `php -l` over 13 server + tool files | clean |
| `tools/test_scene_index.php` | ALL CHECKS PASSED |
| `tools/test_intent.php` | 141 passed / 0 failed |
| `tools/test_gates.php` | 235 passed / 0 failed |
| `tools/test_phrases.php` | 13 passed / 0 failed, hit rate 84.7 % (554/654), floor 82 % |
| `tools/flows/run_flows.php --strict` | 32 scenarios, 32 passed, 0 FAILED, 666 checks, 0 warnings |

Every test claim in both build reports reproduces. I also wrote my own probes (a
recogniser probe over the owner's six phrases in three states, an outro-guidance probe,
and an extra flow scenario `90` in the TEMP copy only) because some claims had no test
behind them - see D11.

F7 settled against the installed JSON: `grep -ro '"type" *: *"[a-z0-9_]*"'` over all
607 installed scenes gives **no `analsex` and no `sixtynine` action anywhere**.
`analsex` appears only as an ICON path (`OStim/sexual/analsex_mf`) in navigation
entries. The anal-adjacent things that ARE installed are `buttjob` (6 scenes) and
`analfingering` (2). So "anal and 69 map to zero installed scenes" is **true**.

## 1. Wire, diffed three ways

| item | PHP sends/parses | Papyrus sends/parses | PROTOCOL.md | verdict |
|---|---|---|---|---|
| w1 `after=` on StartIntimacy | emits `lrg_actions.php:1333`, sourced `:1328` + `:1331` | parses `LRG_OStim.psc:3372`, applied by `TickAfterScene :1046-1088` with warp under `bAllowWarp` | §2 table row + §2 [0.3.1] note | **agree** |
| w3 `dur=` / `how=` on `ev=end` | `lrgSceneDuration :463`, `lrgSceneHow :472` | `FinishThread :3550` and the post-load close `:404` | §1.2 [0.3.1] table | agree, but §1.2 line 51 still says "`ev=end` carries only ev, npc, cid, scene, byglue" -> **D5** |
| `sess=` on the ordinary `ev=end` | ignored (nothing in `lrgHandleSceneMessage` reads it) | **now sent** `:3550` | §1.2 says FinishThread's ordinary end does NOT add it | doc wrong, harmless -> **D5** |
| outro request (`lrg_scenetalk` text `outro`) | `lrgIsOutroTick :563` via `lrgIsTickText :161` (tolerates the DLL's "(Context location: X)" prefix and a `Name:` prefix) | `RequestOutroLine :835-855` | §1.4 [0.3.1] - already documented | **agree.** The game report calls this "a fifth wire item needing the main session's blessing"; the server builder already wrote it into PROTOCOL 1.4, so the two halves agree and the doc covers it |
| w2 two commands in one reply | `lrgSecondCommand :1719`, cid `<cid>b`, appended at `:1416-1421` | `HandleCommand` 4-slot dedup ring `LRG_Main.psc:383-407`; transient refusals now QUEUE (`DeferCommand :964`, `TickDeferred :1002`, call sites `:1441 :2641 :2648 :2826 :2853 :2939 :3171`), MCM `fQueueWait=20` | §2 [0.3.1] says goto+goto is still dropped | **stale doc -> D4** |
| catalog / versions | manifest 0.3.1, `LRG_ACTIONS_VERSION` 9 (deploy log: "action catalog: rows installed (v9)") | `LRG_Main.CurrentVersion = 310` (`:15`) | §0 | agree |

No other wire key changed. No new `do=`, no new `ev`, no new error reason. Additive in
both directions as claimed.

## 2. Playing the six phrases against the real code

Probe output is reproduced verbatim in the findings below. Player male, NPC female,
nearby furniture `doublebed, chair, table`.

### (a) out of a scene, private, willing

| the owner says | recognised | what happens |
|---|---|---|
| "give me a blowjob" | `act / oralpenis:npc`, **high** | OPEN directive, start scene named `OARE_SittingFellatio`. **One try.** |
| "give me a hug" | `act / hold`, high | OPEN, `OARE_StandingHug`. One try. |
| "let me eat your pussy" | `act / oralvulva:you`, high | OPEN, `OARE_SittingCunnilingus`. One try. |
| "lets do 69" | `act / sixtynine`, high, `cant=not installed` | CANT-NEVER: "There is no way for the two of them to do that at all - not here, not later. Say so plainly, in character, in a few words." She speaks, chooses nothing. Correct and truthful. |
| "i want to fuck your asshole" | `act / anal`, high, `cant=not installed` | same. Correct for these packs. |
| "do reverse cowgirl" | `act / vaginal/reversecowgirl`, high | OPEN, `OARE_ReverseCowgirlMounted` - the position is honoured at the START, which is the F4 fix. One try. |

The OPEN directive is the real change and it is blunt in the right way:
> "This is the moment: if Lisette wants it too, say one short line that makes plain what
> is about to happen AND choose BeginIntimacy in the same reply - that is the only way it
> can begin. It would begin exactly there: Sitting Fellatio (sitting/kneeling; blowjob)
> [sexual]. If Lisette does not want it, say no plainly and choose nothing..."

Also fixed out of a scene: "go again" -> `act`, high, OPEN (pt7 logged `intent=none`,
`hidden=Start(heat 0<2)`); "i want you to ride me" -> `vaginal/cowgirl`, start scene
`OARE_CowgirlSquatting` (pt7 started at the standing kiss).

### (b) in a kissing scene / (c) in a sex scene

All six behave identically in both states, which is what R2 asked for:

- blowjob -> DOIT + `do=goto;scene=OARE_SittingFellatio;warp=1`
- hug -> DOIT + `goto OARE_StandingHug` (navigated from the kiss, warped from doggy)
- eat your pussy -> DOIT + `goto OARE_KneelingCL` / `OARE_SittingCunnilingus`
- reverse cowgirl -> DOIT + `goto OARE_ReverseCowgirlMounted;warp=1`
- 69 / anal -> CANT-NEVER **plus** a named alternative list drawn from the live act
  options ("...and name what is possible instead: Dovah's mouth on Lisette's pussy;
  Dovah's cock between Lisette's breasts").

F1 is genuinely dead: unreachability now produces `warp=1`, never a downgrade to LOW
confidence. The DOIT line is "Do it now: choose RequestAct with item "oralpenis:npc"...
Do not refuse, stall, negotiate or ask whether it is a good idea." and the safety net
fires on all of them if the model ignores it.

### the outro, read as the LLM sees it

Probed with a real `lrgVolatileGuidance()` call on an outro turn. 1 572 characters
(~390 tokens), `FORCE_MAX_TOKENS` 300, `max_chars` 420, "two to four plain spoken
sentences". It renders real facts, e.g. for Lisette the bard in the Winking Skeever:

> What the two of them just did: kissing; Lisette's mouth on Dovah's cock; vaginal sex;
> StandingEmbraceKiss (standing; kissing); Doggystyle (kneeling/allfours; vaginalsex).
> It lasted several minutes. They both finished. It ended because they were done.
> Where: where they stood, in The Winking Skeever. This was the first time for the two of
> them. What Lisette's own life is: A bard who performs at the Winking Skeever in
> Solitude, singing for coin and lodging.
> ... 3. Say what Lisette does next and why, taken from Lisette's own life - work,
> duties, the hour, where they both are. Be concrete: a place, a task, someone waiting.
> If Lisette has no reason to stay, say where Lisette is going and why.

Stay clause, checked on all four cases the round named:
- bard in an inn that is not hers -> "say where Lisette is going and why" + the
  occupation line -> the "set to play tonight" answer the owner asked for
- innkeeper in her own inn -> "This is Hulda's own place. Say whether Hulda stays here"
- housecarl follower (`CurrentFollowerFaction` in `fac`) -> "has every reason to stay"
- spouse -> "has every reason to stay"

`CHIM_CORE_CURRENT_NPC_DATA` really is populated by then (`main.php:489`, ext hooks at
1117 / 1615 / 2540), so the occupation line is not theoretical. With no CHIM row the
life line simply disappears and only the stay clause carries the reason - acceptable.

`EndConversation` is not merely forbidden in words: it is in `LRG_MOVEMENT_ACTIONS`
(`lrg_actions.php:150`) and the outro branch hides the whole list (`:781`). Good.

Cue ordering verified against CHIM: `prompts/prompts.php` (which loads our
`prompts.php`) is required at `prompt.includes.php:19`, i.e. BEFORE `functions/functions.php`
-> before `lrgPrepareTurn()` consumes the ticket at `lrg_actions.php:777`. The cue is
therefore always set. Not a defect, but it is a one-line-ordering dependency nobody
documented.

**The one thing that breaks it: she can still be released mid-sentence. See D3.**

## 3. Relationship arithmetic

State on disk right now (queried live):
- `lrg_romance` Lisette: `scenes=2`, **`last_affinity=0`**, `last_affinity_at=0`
- `core_npc_master.extended_data->relationships` for Lisette: **`[]`** (still wiped)
- no `relationship repair` line in `lorerim_glue.log` -> the repair has NOT run yet
- user override `config/lrg_config.json` sets `npc_overrides.Lisette.min_affinity = -5`

After the repair fires (first live turn with her) and two more completed scenes:

| | CHIM aff | lrg last_affinity | history | min | score | word |
|---|---|---|---|---|---|---|
| now, no repair | missing -> 0 | 0 | +20 | -5 | ~+25 (+renown/speech) | drawn |
| after repair | 30 | 30 | +20 | -5 | ~+55 | drawn |
| +2 scenes (one gain, 6 h window) | 34 | 34 | +30 | -5 | ~+69 | drawn |

**Can a reload close her again (F5)? No.** Two independent belts now hold:
`lrgAffinityInfo :604-628` falls back to `lrg_romance.last_affinity` with
`rescue=missing`, and the history bonus is on the interest side and never lived in
CHIM's store at all. Even with `last_affinity` still 0 and CHIM wiped, Lisette scores
`0 - (-5) + 20 = +25` -> drawn. With the stock `tavern_folk` min of 10 it would be +10
-> "interested" -> willing. F5 cannot recur for an NPC with history.

Residual: for an NPC whose CHIM entry is wiped *before* the glue ever read a non-zero
value, `last_affinity` stays 0 and only the history bonus carries her - enough for any
profile with `min_affinity <= 30`, not enough for `housecarl` (40) or `jarl` (55).

**Strict NPCs are not trivialised.** `vigilant` is `never: true` (hard block, score
irrelevant). `jarl` needs 55 and `housecarl` 40 for the FIRST scene, and both the
history bonus and the scene gain are zero until a scene has completed. The relaxation
(up to -30 threshold, +8 affinity) only ever applies to someone he has already been
with, which is exactly what R1c asked for.

## 4. Prompt size, latency, conflicting instructions, softening

- Added per ordinary request turn: the `<player_request>` directive, 200-560 characters
  (~50-140 tokens). The act list gained position suffixes but is still capped
  (`max_acts` 8, `max_positions_per_act` 6). No new DB round trip, no index build inside
  an LLM request (`lrgIndexMaybeRebuildAsync` stays bound to `lrg_npcstate`).
- Added per scene: exactly ONE extra LLM + TTS turn (the outro), ~390 tokens of prompt,
  300 output tokens. That is the cost of R3 and it is the right trade.
- Conflicting instructions found: **D6** (BeginIntimacy described as "it starts gently"
  next to a directive naming a sexual start scene) and **D12** (explicit act vocabulary
  injected into a `closed` turn whose own rule says no explicit wording).
- Softening: none in the outro ("Crude, plain words are fine and expected", "fully
  explicit and vulgar ... never soften them, dodge them or swap in euphemisms"), none in
  DOIT ("Do not refuse, stall, negotiate"), none in CANT ("never apologise at length,
  never pretend to do it instead"). Only D6 softens anything.

## 5. Defects

### D3 - MAJOR - game - she can still walk off mid-goodbye
`LRG_OStim.psc:919-922` releases the hold on `afNow >= outroUntil` with no
`isActorTalking` check, although `OutroLineState():820` has exactly that check and is
only reached *after* the timeout branch. `outroUntil` = request time + `fOutroHold`
(25 s). The outro is now up to 420 characters / four sentences, where every other line
in the mod is capped at 120. The game's own existing tuning says a <=120-character line
may need up to **20 s** to finish (`fSayFirstMaxWait = 20`, and PROTOCOL 8 budgets
`command_confirm_seconds` 40 around it); pt7's log shows 2-13 s of LLM latency before
the first syllable (e.g. `17:11:28 turn` -> `17:11:41 llm`). A 3.5x longer line on a
25 s budget will regularly be cut off - which is the precise complaint R3 exists for.
**Fix (smallest):** in `TickOutro`, before the timeout branch, if
`AIAgentFunctions.isActorTalking(outroName) != 0` then `outroUntil = afNow + 5.0` and
return (the existing `2.0 * budget + 30.0` self-heal at `:877` already bounds it).
Alternative one-liner: `fOutroHold` default 25 -> 45 in `settings.ini` + `config.json`.

### D1 - MAJOR - server - out of a scene, "take off your clothes" still gets the neutral answer
`lrg_intent.php:750-752` gates the new OPEN directive on
`in_array($kind, ['act', 'yes'], true)`. An `undress` request falls through to `:758`:
> "The player just asked for this: you take your own clothes off. Whether Lisette does it
> is Lisette's own choice - answer in Lisette's own voice either way."

That is the same shape that produced pt7 F4's first failure (17:09:29, `undress/npc`
high, "LLM used no action"). `ChangeClothing` IS offered on that turn
(`lrgStartHeldBack :1075` returns '' for `undress`), so the action exists and nothing
points the model at it - while the surrounding private-mode note still says "Only ever
because $n wants it right now: if $n declines, hesitates or deflects, choose neither
action."
**Fix:** add `'undress', 'dress'` to the kind list at `:752` and, for those kinds, gate
on `lrgTurnOffers($turn, LRG_ACT_CLOTHING)` and name ChangeClothing instead of
BeginIntimacy in the same sentence.

### D2 - MAJOR - server - out of a scene the compound directive promises something that cannot happen
`lrg_intent.php:723` appends "Both happen, in that order." on every mode, but
`lrgSecondCommand()` (`lrg_actions.php:1728`) returns null unless `mode === 'scene'`.
Verified with flow probe 90 against the real library: out of a scene,
"take my clothes off and then let's do missionary" produces exactly **one** wire line
(`do=undress;who=player;part=all`) - the missionary half is gone, with no log line
other than "second command skipped: not an open scene turn".
The `after=` path at `lrg_actions.php:1331` DOES carry it, but only if she picks
BeginIntimacy - and D1 means the directive on that turn never asks her to.
In a scene the same sentence works perfectly (probe: two lines, second is
`do=goto` to a missionary scene, cid `...b`).
**Fix:** either drop the "Both happen" clause when no path can carry the second half, or
(better) when the primary is `undress`/`dress` and the extra is an `act`, name BOTH
ChangeClothing and BeginIntimacy in the directive so `after=` is used.

### D4 - MAJOR - docs - PROTOCOL.md says the game drops goto+goto; it no longer does
`PROTOCOL.md:115`: "The one combination the game still drops is `goto` + `goto` ...
Until the game queues that case, the server emits both and the second is answered with
that error." The game now has a one-slot queue with a 20 s budget
(`LRG_OStim.psc:964-1036`, `fQueueWait` in `settings.ini`), reached from every transient
refusal including `still moving into the previous position` (`:2648`, `:2853`). The
server build report repeats the stale claim and hands the fix pass a requirement that is
already implemented - straight into duplicated or conflicting work. PROTOCOL.md's own
rule is that the file wins over code, so this must be corrected before anyone reads it.
**Fix:** replace that paragraph (one slot, `fQueueWait` default 20 s, retried once a
second, answered with its original reason at the deadline, a third command still refused)
and strike the "game-side need" bullet from `pt7-build-server.md`.

### D8 - MINOR (but act on it first) - both - it is ALREADY deployed and installed
Both build reports say "Not deployed" / "Not installed". Both are wrong:
- `/var/www/html/HerikaServer/ext/lorerim_glue/*` is md5-identical to the built copy for
  all 10 files, mtime `2026-09-21 19:20`; the log has
  `19:20:50 schema ensured (v3, 3 migration files)`,
  `19:20:50 action catalog: rows installed (v9)`,
  `19:20:51 scene index rebuilt: 607 scenes from 3 pack folder(s) ... filter=open`.
- `F:\Modlists\LoreRim\mods\LoreRim Glue\Scripts\*.pex`, mtime `19:17`, byte-identical
  sizes to `glue\game\LoreRimGlue\Scripts` (e.g. `LRG_OStim.pex` 108 573 both).
This matters because `relationship.repair` is live (`enabled: true`, `npc: "Lisette"`,
`affinity: 30`) and will fire on the owner's very next line with Lisette - the server
builder's deviation said it was "flagged for the owner's word before deploy". It has not
run yet (no repair line in the log, `last_affinity` still 0, CHIM still `[]`), so there
is still a moment to change the number if 30 is not wanted.
Also confirmed cosmetic: `meta.ini` in the MO2 mod says `version=0.1.0`
(`install_mo2.ps1:54`, already flagged by the game builder).
Reassuring: no saved `MCM\Settings\LoreRimGlue.ini` exists anywhere in the modlist or in
Overwrite, so the mod's own `settings.ini` defaults (`fOutroHold=25`, `fQueueWait=20`,
`fOutroSettle=2.5`, `fOutroMinScene=45`, `fOutroFar=1500`) really are in force - the
"a missing ini key reads as 0" trap does not bite here.

### D13 - MINOR - server - the repair target assumes a gain the shipped rule would not grant
`relationship.gain_window_seconds` is 21600 (6 real hours) and
`lrgAwardSceneAffinity :499-504` allows one gain per NPC per window. pt7's two scenes
(17:10-17:14 and 17:17-17:19) would therefore have earned **+8 only**, so the "18 + 8 + 4
= 30" in the config `_repair_readme` credits 4 points the code would never have given.
Harmless as a one-off constant, but the same rule is the owner-facing consequence:
in a normal evening of two or three scenes she gets exactly one +4. If "sex should
slightly improve the relationship" is meant to be felt per scene, `gain_window_seconds`
wants to be ~1800, not 21600.

### D6 - MINOR - server - the notes still call BeginIntimacy gentle
`lrg_actions.php:2208` unconditionally prints
`BeginIntimacy (it starts gently - a kiss, an embrace)` while the directive three lines
later says "It would begin exactly there: Sitting Fellatio ... [sexual]". Two
descriptions of the same action in one prompt, and the softer one is the one attached to
the action's own name - exactly the kind of hedge that makes a model decline.
**Fix:** when `$turn['intent']['start_scene'] !== ''`, print
`BeginIntimacy (it begins in what the player asked for)` instead.

### D7 - MINOR - server - raw scene ids leak into the outro prompt
`lrg_actions.php:2151-2154` appends `lrgDescribeScene()` labels for the last three
visited scenes on top of the act labels, producing
`"...; StandingEmbraceKiss (standing; kissing); Doggystyle (kneeling/allfours; vaginalsex)"`.
Those are CamelCase mod identifiers, on the one turn with a 420-character budget and a
model that likes to echo its context. The act labels already say the same thing in
English.
**Fix:** drop the `$t['scenes']` loop, or run it only when `$t['acts']` is empty.

### D9 - MINOR - server - the second command is not watchdogged
`lrg_actions.php:1417-1421` appends the second line without calling `lrgNotePending()`,
unlike the safety net at `:1412`. If it is lost between server and game, nothing WARNs.
One line.

### D10 - MINOR - both - the corner note for an impossible request does not exist
R2 asks for a short in-character "can't do that here" **and** the corner note. She
speaks (verified), but the CANT path emits no command, so there is no `funcret`, so the
existing `Debug.Notification("LoreRim Glue: <reason>")` at PROTOCOL 1.6 never fires.
Both builders correctly listed it as not done; recording it here so it is not lost. It
needs one additive key (`note=<text>` on any command, or a `do=note`) next round.

### D11 - MINOR - server - w2's wire emission has no test
`grep -rn "lrgSecondCommand|second command" tools/test_gates.php tools/flows/scenarios/*.php`
returns nothing. `test_gates` section 31 asserts only that the INTENT keeps both halves
(`["undress","act","vaginal/missionary"]`); nothing asserts that a second `|command|`
line leaves `lrgPostProcessActions`. The server report describes the compound as covered
there. I verified the in-scene path works with my own flow probe (two lines, second
`do=goto`, cid `...b`), so this is a coverage hole rather than a live bug - but it is how
D2 slipped through.

### D12 - MINOR - server - explicit vocabulary on a `closed` turn
On mode `closed` the guidance says "no invitation, no explicit wording" and `x` is null,
yet `lrg_actions.php:2257` still appends the directive, which names the act in the
crudest available words ("The player just asked for this: Lisette's mouth on Dovah's
cock"). `closed` always implies `adult=1`, so no rail is broken - but the two
instructions contradict each other, and the whole eighteen-minute stretch the owner
complained about was mode `closed`.
**Fix:** on mode `closed`, use `$intent['kind']` plainly rather than
`lrgIntentWords()`'s explicit label, or skip the directive.

### D5 - MINOR - docs - PROTOCOL.md 1.2 contradicts itself
Line 51 still says "`ev=end` carries only `ev, npc, cid, scene, byglue`" (lines 65-70 add
`dur`/`how`), and the `sess` row says "FinishThread's ordinary end does not" add it while
`LRG_OStim.psc:3550` now does. Functionally harmless - the server never reads `sess` off
`lrg_scene` - but the file claims to win over the code.

## 6. Owner actions that are really needed

1. **Nothing to deploy or install** - both halves went live at 19:17 / 19:20 (D8). She
   only needs to load the game and check MCM > LoreRim Glue > SceneTalk shows the new
   outro rows.
2. **Let the repair fire, then switch it off.** Say one line to Lisette, then check
   `/var/www/html/HerikaServer/log/lorerim_glue.log` for
   `relationship repair: Lisette -> Player set to 30 (was no entry)`. After that, set
   `relationship.repair.enabled` to false in
   `ext/lorerim_glue/config/lrg_config.json` (her user override file - it currently
   holds only the Lisette `min_affinity` line). The code marks itself done in
   `lrg_memory.affinity_repaired_at` so it cannot run twice, but leaving it armed means
   any future wipe gets silently re-set to 30.
3. **Optional, no longer load-bearing:** CHIM setting `NEVER_CLEAR_RELATIONSHIP_DATA`
   (`conf/conf_schema.json:117`, default false). The gate no longer needs it (see §3),
   but it stops CHIM's own narrative memory of the relationship being rewritten on every
   load. Her call; it is a CHIM setting, not a code change.
4. **Worth telling her:** her own override `npc_overrides.Lisette.min_affinity = -5` is
   still in `lrg_config.json`. Combined with the repaired 30 and the +20 history bonus,
   Lisette will read `drawn` permanently and will never be able to refuse beforehand. If
   she wants the pre-scene "no" to stay possible for Lisette, that line should go.
5. **Not needed:** nothing about LOOT, Nemesis, saves or profiles. No OStim/CHIM file was
   touched by this round; I verified the deployed tree contains only `ext/lorerim_glue`.
