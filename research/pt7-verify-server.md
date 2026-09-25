# pt7-verify-server.md — independent SERVER verification of v0.3.1

Verifier lens: **SERVER**. Read-only except this file. Nothing deployed, nothing installed,
no DB written, no CHIM/OStim/OARE/LoreRim file touched. All work staged in `%TEMP%\lrg_test`.

**VERDICT: FIX FIRST.**

The infrastructure of this round is sound and the hard safety rails all hold. But the round's
headline mandate — R2, *"EVERY ACT AND POSITION PHRASE WORKS"* — is not met: the exact failure
modes the round was chartered to kill (F3 silent wrong-act substitution, F1 silent drop, F2
compound drop) are **still reproducible**, on the owner's own subject matter. One root cause
(V1) accounts for most of the wrong-act cluster and is a small, surgical fix.

---

## 0. What I reproduced of the builder's claims

Everything the server builder claimed, I ran myself from a staged copy in WSL against the real
installed packs. **All of it reproduces exactly.** No claimed number was inflated.

| claim | builder | me | verdict |
|---|---|---|---|
| `php -l` clean | 13 files | **46 files, 0 failures** (PHP 8.2.28) | confirmed, better than claimed |
| `test_scene_index.php` | ALL PASSED | ALL CHECKS PASSED | confirmed |
| `test_gates.php` | 235 / 0 | **235 passed, 0 failed** | confirmed |
| `test_intent.php` | 141 / 0 | **141 passed, 0 failed** | confirmed |
| `test_phrases.php` | 13 / 0, 84.7 % | **13 passed, 0 failed, 84.7 % (554/654)** | confirmed |
| `flows --strict` | 32 / 32, 666 checks | **32 scenarios, 32 passed, 0 FAILED, 666 checks, 0 warnings** | confirmed |

I also independently re-verified the two "not installed" claims against `F:\Modlists\LoreRim\mods`,
because F7 called them suspicious and unverified:

- **anal sex: genuinely not installed.** The base framework ships the *action definitions*
  (`analsex.json`, `analfingering.json`, `analfisting.json`, `anallicking.json`, `analtailsex.json`,
  `analtoying.json`, `rimjob.json`), but **zero installed scenes declare `"type":"analsex"`**.
  The builder is right, and saying so in character is the honest answer.
- **69: genuinely not installed.** No installed scene carries both a penis-oral action
  (`blowjob`/`deepthroating`/`lickingpenis`/`penilelicking`) and a vulva-oral action
  (`cunnilingus`/`lickingvagina`/`vulvaleating`/`rubbingclitoris`). No scene tag contains
  `sixtynine` or `69`. Confirmed.
- **But `analfingering` IS installed** — 2 scenes (`OStim2PFaceRidingMF`, `OStim2PFaceSittingMF`).
  See V4: "finger my ass" is answered with a **handjob** instead.

Hard safety rails I re-verified myself and which **all hold**:
the safety net never emits a command outside a running scene (checked across `inputtext`,
`lrg_scenetalk`, `lrg_initiative`, `funcret`); a genuinely uninterested NPC is still `mode=closed`
with `BeginIntimacy` hidden; a reply never carries more than two glue commands; adults-only
silence; kill switch.

---

## 1. DEFECTS

### V1 — MAJOR — server — the direction fix swaps the ACT instead of the ROLE (root cause of the wrong-act cluster)

`LRG_ACT_BODY` / `lrgActFixSexes()` — `glue/server/lorerim_glue/lib/lrg_scene_index.php:1301-1331`.

When a role is anatomically impossible for this pair, the table jumps to a **different act family**
instead of first trying **the other role of the same family** — which, for a male player and a
female NPC, is always the direction that *is* installed.

My probe (`probe4.php`), player male / NPC female:

```
  oralvulva:npc    -> oralpenis:npc     *** the ACT changed ***
  fingering:npc    -> handjob:npc       *** the ACT changed ***
  handjob:you      -> fingering:you     *** the ACT changed ***
  oralpenis:you    -> oralvulva:you     *** the ACT changed ***

  oralvulva:you    installed=YES        <- this is the direction it should have picked
  fingering:you    installed=YES        <- this is the direction it should have picked
```

Consequences I measured against the real index:

| input | should be | actually resolves to |
|---|---|---|
| `RequestAct item="cunnilingus"` | an oral-on-her scene (14 installed) | **`OARE_SittingFellatio`** — a blowjob |
| `RequestAct item="fingering"` | a fingering scene | **`OARE_AceStandingHandjob`** — a handjob |
| "eat my pussy" (phrase matrix D1) | oral on her | oralpenis:npc, fires the net |
| "finger me" (K1) | fingering | handjob |
| "put your fingers inside you" (K4) | she fingers herself | **handjob** (she strokes him) |
| "finger my ass" (F7) | analfingering — **installed, 2 scenes** | **handjob** |

This is exactly playtest 7's **F3** ("the server resolved `vaginal` to `OARE_SpooningFingering`"),
still alive — only moved to different families. The round's own words: *"RequestAct items the LLM
sends must resolve to the right act or position (no more vaginal -> fingering)."*

**Smallest fix:** in `lrgActFixSexes()`, before taking `LRG_ACT_BODY`'s cross-family `$fallback`,
try the same family with the opposite role and accept it when that direction is anatomically
possible; only fall back across families when neither role works.

---

### V2 — MAJOR — server — a bare directional act id is silently DROPPED

Same file. `lrgMatchAct('oralvulva')` and `lrgMatchAct('oralpenis')` both return **`DROPPED`**;
`lrgResolveAct('oralpenis', …)` and `lrgResolveAct('oralvulva', …)` both return **NULL**
(probe2, group G). A dropped RequestAct is silence — the **F1** failure mode.

This is not hypothetical: the playtest-7 log shows the LLM sending bare family names
(`RequestAct item="vaginal"` twice, 17:12:51 and 17:18:15, the second one logged
"dropped - matches no act that is open right now"). `vaginal` now works; `oralpenis` and
`oralvulva` do not. `spanking` also returns NULL.

**Smallest fix:** when a bare directional family arrives with no role, default it to the role that
is installed for these two bodies rather than dropping it.

---

### V3 — MAJOR — server — an aborted / cancelled scene counts as a real scene

`glue/server/lorerim_glue/lib/lrg_actions.php:341-347`. `lrgRomanceBump($npc, 'scenes')` runs on
**every** `ev=end` where the scene was ever open — with no duration and no `how` guard — while
`lrgAwardSceneAffinity()` immediately below it applies both guards correctly.

My probe (`probe_verify.php` A5/A5b), an 8-second `how=stopped;dur=8` scene:

```
  FAIL A5  an 8-second aborted scene does NOT count as a scene they had
           [scenes=1 history=+15]
  FAIL A5b ... so the first REAL scene still gets first_scene_gain (+8), not +4
           [["adjust:Hulda:4"]]
```

Two distinct harms:

1. **It loosens the pre-scene gate.** `lrgHistoryBonus()` pays **+15 interest** the moment
   `scenes > 0`. A start the player cancelled after three seconds permanently buys +15 — enough to
   flip an NPC from unwilling to willing (`tavern_folk` `min_affinity` is 10). The owner's hard
   rule is that a pre-scene refusal stands; inflating interest off a cancelled start erodes it.
2. **It eats the owner's "first time" gain.** The first *real* scene is then awarded
   `scene_gain` (+4) instead of `first_scene_gain` (+8).

**Smallest fix:** gate the `lrgRomanceBump($npc,'scenes')` on the same two guards the gain already
uses (`lrgSceneDuration() >= relationship.min_scene_seconds` and
`lrgSceneHow()` in `finished|stopped`).

---

### V4 — MAJOR — server — R2's act/position coverage has a reproducible miss cluster

These are rows from the builder's **own** `test_phrases.php --fails` output. The build report
frames the remaining 15 % as *"arguable … for a male player + female NPC pair"*. Many are not
arguable — they are wrong-act substitutions, inverted roles and dropped compound halves on exactly
the subject matter R2 enumerates.

Silent, no intent recognised at all (F1 class — the round says *"never silence"*):

| phrase | states | R2 wording it contradicts |
|---|---|---|
| `sit on my lap` | all 3 | "lap / sitting" is a named required position |
| `i want to be inside you` | all 3 | unambiguous vaginal request |
| `get that pussy on me` | all 3 | "crude words" are required |
| `get on the bedroll` | all 3 | furniture vocabulary |
| `cum for me` | 2 of 3 | ("cum inside me" works) |

Wrong act / inverted role (F3 class):

| phrase | resolves to | should be |
|---|---|---|
| `fuck my cock with your tits` | **vaginal sex** (in doggy: *"we're already doing that"*) | titfuck — R2 lists "boobjob / titjob" |
| `turn around and ride me` | `vaginal/doggy` | reverse cowgirl — **the owner's own complaint** |
| `let me be on top` | `OARE_CowgirlSquatting` (she on top) | missionary, for a male player |
| `lay down and let me fuck you` | `OARE_CowgirlSquatting` | missionary |
| `pick me up` | `OARE_StandingHug` (a hug) | carrying — R2 lists "carrying" |
| `lets spoon` / `cuddle with me` | `OARE_StandingHug` | spooning — R2 lists "spooning" |
| `grab my ass` | `…HandsOnBreasts` | butt — R2 lists "grope (breasts, butt)" |
| `against the wall` | `OARE_LyingOnSideSex` in one state | a wall scene |

Compound halves dropped (F2 class — R2: *"carry out both, in order"*):

| phrase | what happens |
|---|---|
| `put me on the table and fuck me` | furniture only; the "fuck me" half is dropped |
| `first kiss me then take your clothes off` | the **first** half (kiss) is dropped |
| `get undressed and get on the bed` | furniture only; the undress half is dropped |

Note the compound machinery itself is sound — I verified the wire behaviour directly (see §2).
These are recogniser splits that never produce an `extra`.

**Smallest fix:** these are table/pattern gaps, not architecture. Fixing V1 removes several of
them for free. The rest need position-phrase rows (`spoon`, `lap`, `carry/pick me up`, `wall`,
`turn around + ride`), a `tits` veto over `fuck`, a butt/breast split on `grope`, and
`lrgIntentParts()` splitting on `X and Y` where Y is a furniture or act phrase.

---

### V5 — MINOR — server — the `after=` / gain math behind the "30" contradicts the code's own window

`relationship.gain_window_seconds` is **21600 (6 real hours)** and the code enforces one gain per
window (I verified: my A1 duplicate and the builder's own section-29 second-scene check both show
the second scene adding nothing).

The repair target of **30** is justified in the config and the build report as
*"18 plus the two scene gains of that day (+8 first, +4 second)"*. The two playtest-7 scenes were
**~7 minutes apart** (17:10-17:14 and 17:17-17:19), so this very code would have awarded **+8 only**.
The arithmetic behind 30 assumes a second gain the implementation would never grant; on its own
logic the number is **26**.

The round's prompt named 10; the builder deviated to 30 and flagged it for the owner. That
deviation is well-evidenced and I do not dispute the *direction* — but the owner should be told the
stated arithmetic does not match the shipped rule. **Smallest fix:** owner decides the number; if
the "two scenes that day" reasoning is kept, say 26, or state plainly that 30 is a round-up.

Everything else about the repair verified clean: runs **exactly once** across repeated calls,
through `setRelationship(..., null)` with **no type**, reaches the target, never touches another
NPC, and fires from an ordinary live game turn (probe A7-A7e).

---

### V6 — MINOR — server — two act labels print the NPC's name twice

`glue/server/lorerim_glue/lib/lrg_scene_index.php:815` and `:826`:

```
'name_npc' => "%n uses %n's feet on %p"              -> "Hulda uses Hulda's feet on Dovah"
'name_npc' => "%n uses %n's mouth on %p's nipples"   -> "Hulda uses Hulda's mouth on Dovah's nipples"
```

This text goes straight into the LLM's context. I saw it verbatim in the outro directive my probe
produced: *"What the two of them just did: Hulda uses Hulda's feet on Dovah…"*.

**Smallest fix:** `"%n uses her feet on %p"` / a possessive placeholder.
(`masturbation` at `:854`, `"%n touches %n's own body"`, reads acceptably — leave it.)

---

### V7 — MINOR — both — the corner note for an impossible request is genuinely absent

Disclosed by the builder as NOT DONE, and I confirm it: the intent array returned for
"i want to fuck your asshole" carries `cant = "not installed"` but **no** `note` / `notify` /
`cant_note` key (probe2, J2 — keys are `kind, conf, kv, act, scene, words, why, text, frag, again,
extra, cant`). So nothing on the wire can make the game print the `Debug.Notification` R2 asked for.

The rail that matters is intact — she speaks an in-character "can't", so it is **never silence** —
and the builder's reasoning for not faking it with a doomed `goto` is correct. But R2's corner note
does not exist. **Smallest fix:** one additive key on the existing command, or accept and record
the gap; it needs the main session's word since it is a fifth wire item.

---

### V8 — MINOR — docs — PROTOCOL.md's compatibility claim is too strong for the compound path

`glue/PROTOCOL.md:5`: *"A server 0.3.1 works with game script 300."*

The server has **no way to learn the game's script version** — I grepped for it; nothing on the
wire carries one, and the second command is emitted unconditionally. The game builder's own report
says reading `HandleCommand` *"exposed a double-execution that w2's two-commands-per-reply makes
reachable"*, fixed by a new 4-slot de-dup ring in **script 310**.

So: server 0.3.1 + game **300** (what is installed right now, since the game half was deliberately
not installed) can double-execute a compound request. `deploy_server.ps1` and `install_mo2.ps1` are
separate tools, so deploying the server alone is easy to do by accident.

**Smallest fix:** either ship both halves together and say so at the top of PROTOCOL.md, or make
the compound second command conditional on a game-version key. Not a code defect in isolation —
a deployment-order hazard that the compatibility sentence currently hides.

---

## 2. What I verified as genuinely working

Written out because the round asked me to check these specifically, and they pass.

**Relationship (R1)** — my own probes, beyond the builder's section 29:
- duplicate `ev=end` awards **no** second gain and does **not** double-bump `scenes` (A1/A1b);
- the gain never pushes past CHIM's cap (98 + 8 → 100) (A2);
- a **missing** entry is created by `adjustRelationship`, through the API (A3);
- `how=stopped` gains; `how=lost` does not; a 20-second scene does not (A4/A4b/A4c);
- an NPC with `relationships_locked` is **never** written (A6);
- the repair is idempotent, API-based, type-free, NPC-scoped, live-turn-reachable (A7-A7e);
- the history bonus is bounded at +30 however many scenes (A8);
- a wiped CHIM entry falls back to our last known value and reports `rescue=missing` (A9);
- an uninterested NPC is still closed with `BeginIntimacy` hidden (A10).

**Acts / positions (R2)** — the parts that work:
- `RequestAct item="vaginal"` now resolves to a real vaginal scene — **F3's headline case is fixed**;
- **every** advertised `"<act>/<position>"` id resolves to a scene of that act (probe2 F1 — I
  enumerated all of them from the live index, zero mismatches);
- F1's killer sentence *"now get down on your knees and suck my deck"* is now `conf=high`, carries
  `{"do":"goto","scene":"OARE_SittingFellatio","warp":1}`, and unreachability is **not** a `cant` —
  exactly what R2 demanded;
- anal and 69 produce a spoken "can't" with **no substitute command**;
- out-of-scene direct start lands in the named act for "ride me" (cowgirl), "kiss me", "give me a
  blowjob", "doggy style", "give me a hug" (probe2 H).

**Compound on the wire (w2)** — verified end-to-end through `lrgPostProcessActions()`:
```
Hulda|command|ExtCmdLRG_Clothing@ok=1;cid=sbc634685;npc=Hulda;do=undress;who=player;part=all;hold=150
Hulda|command|ExtCmdLRG_SceneControl@ok=1;cid=sbc634685b;npc=Hulda;do=goto;scene=OARE_Missionary;warp=1;hold=150
```
Two commands, **distinct cids** (`…685` / `…685b`), undress first and the act second — the order
the player said them. A reply never exceeds two commands even when the LLM adds an unrelated one.

**Outro (R3)** — fires once per scene end, `mode=outro`, directive carries the real scene facts
(acts, scene labels, duration, climaxes, first-time); a **second** outro request for the same scene
is not an outro turn (ticket consumed); an outro request with no ticket is not an outro turn.

**Logging (R4)** — `say=` carries the player's words on closed **and** silent turns, and a closed
turn appends `closed="…"` in plain words. Verified on live turn lines.

**Old game scripts (300)** — an `ev=end` with **no** `dur=` and **no** `how=` still awards the gain
from `_started_at` and still writes an outro ticket; a short old-game scene is still refused a gain.

---

## 3. Recommendation

**FIX FIRST**, and the cheap part first: **V1 is one function** (`lrgActFixSexes`) and it removes
the largest, most owner-visible group of wrong-act answers — including "finger my ass", "finger me"
and any RequestAct naming oral-on-her. **V3 is one line** and closes a gate-loosening hole.
V2 is small. Together those three are perhaps an hour and they turn the owner's complaint #2
("the ai still wasnt receptive to a lot of the sex positons") from partly-fixed into mostly-fixed.

V4's remaining rows are table work and can be triaged by how loudly the owner will notice them —
`fuck my cock with your tits` → vaginal sex and `turn around and ride me` → doggy are the two I
would not ship without.

V5 needs the owner's word on a number before deploy, and `relationship.repair.enabled` must go back
to `false` after it runs. V8 needs the two halves deployed together.
