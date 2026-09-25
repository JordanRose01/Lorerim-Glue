# pt9 go-live — ADVERSARY, SERVER SIDE

LoreRim Glue v0.5.1 · assumption: **live** (`bMenuless=1`, `bDlgDryRun=0`) · 2026-09-22

Everything below is a **real run** against the **deployed** plugin
(`/var/www/html/HerikaServer/ext/lorerim_glue`, md5-identical to
`glue/server/lorerim_glue`) and the **real prompt index** in Postgres
(`lrg_index.lrg_prompt`, 37,561 rows / 5,718 layers, hash `6b8e111…`) and the
**real service census** (`data/service_catalog.json`: 97 destinations, 56 travel,
22 skills, 15 crime topics). No fixtures, no in-memory index seam.

Harness: throw-away probes in `%TEMP%\lrg_test` (`pboot.php` + `p0..p13.php`),
run through WSL `DwemerAI4Skyrim3`. `pboot.php` routes **index reads** to the live
Postgres and keeps **every write** in memory, so nothing in the owner's tables or
in the live NPC state was touched. Only this file was written.

---

## Verdict

**Do not flip `bMenuless=1` yet.** Nine defects, two of them capable of an
irreversible in-game consequence the owner never asked for (jail; a paid ride
after an explicit refusal). Both live on paths that the *other* route already
guards correctly — the rails exist, they are just not on both routes.

What **held** under attack, and held well:

* price questions, negations (`don't` / `not` / `anywhere but`), quoted orders,
  partial names (`Sol`, `Solit`), two destinations in one line (→ `ask`), and
  the `Solitude` / `Solitude Lighthouse` prefix pair **in both orders**
  (longest-wins is correct on the real CFTO data — the pair really exists here);
* `want=1` refusing commit / crit≥1 / walkaway / meta / `class=check` /
  unaffordable entries;
* a persuade entry never executed because the player merely *mentioned* persuasion
  (both routes);
* two `TakeUpBusiness` lines in one batch → at most one pick;
* a model-authored `do=award` never reaching the wire;
* every `ok=1` line carrying a non-empty `cid`, in the byte-for-byte v0.4 key
  order (an old 401 script still parses it);
* a v0.4-shaped `lrg_topics` (no `crit=`/`pg=`/`sp=`/`q=`/`perk=`) → no crash, and
  the afford/freeze rails still refuse rather than guess.

---

## D1 — HIGH — `want=1` jails the player on a layer the post-LLM gate hands back

**Scenario.** A hold enforcer stops the player. `ClassifyCrit()`
(`LRG_Dialogue.psc:3351`) grades LETHAL as `IsGuard() AND (engine-opened OR
crimeGold>0)`, so a **glue-opened** session with a mod-added guard, or a guard
whose `GetCrimeFaction()` shows 0 gold, arrives as `crit=0`. The server's own
second net (`lrgDlgArrestClass()`, `lrg_dialogue.php:3836`) *does* recognise the
layer from its `DGCrime*` topics — and the post-LLM gate hands the menu back.
`lrgDlgAnswerWant()` never consults it.

**Evidence** (probe `p12.php` 12a — identical layer, identical state, two routes):

```
POST-LLM GATE  : arrest="topic" -> show
LOG  dlg gate: ARREST session (detected by topic) - the menu is handed back visible, nothing is chosen by voice
LOG  dlg emit npc=Markarth Enforcer do=show mode=lethal pos=-1 kind=meta

WANT=1 FASTPATH: Markarth Enforcer|command|ExtCmdLRG_SelectTopic@ok=1;...;do=pick;sid=s1;gen=1;pos=1;
                 i=101;txt=I submit, take me to the cells.;kind=plain;cost=0;...
LOG  dlg emit npc=Markarth Enforcer do=pick mode=intent pos=1 kind=plain
```

The `want=1` route in `lrgDlgAnswerWant()` (2058–2173) carries the scene rail
(2124), the LETHAL rail (2128), the consequential-entry rail (2133) and the
afford rail — but **not** `lrgDlgIsResistArrest()` (gate rail at 2383) and **not**
the arrest-session hand-back (gate rail at 2402). The only reason a *vanilla*
`DGCrimeResistArrest` row survives this is the unrelated
`scripted && goodbye ⇒ commit` heuristic; a mod's own resist line with
`goodbye=0` would be `class=plain` and clickable.

**Smallest fix.** In `lrgDlgAnswerWant()`, immediately after the LETHAL rail at
line 2128, add the two rails the gate already has:

```php
if (lrgDlgIsResistArrest($e)) { …log…; return; }
if (lrgDlgArrestClass(['entries' => $entries, 'tail' => [], 'crime' => (array) ($st['crime'] ?? [])]) !== ''
    && !empty(lrgDlgCfg('services.kinds.crime.lethal', true))
    && (int) ($sess['crit'] ?? 0) !== 1) { …log…; return; }
```

Same three lines, same wording in the log, so the two routes can never disagree again.

---

## D2 — HIGH — the two-step confirmation is released by a refusal, or by no player turn at all

`lrgDlgParkOrRelease()` (2512) releases a parked commit on *any* second selection
of the same norm from a different `cid`. The only content test is
`if ($tokens < 2 && !empty($t['speech']))` (2521) — a token **count**, and it is
skipped entirely on a non-speech turn. Nothing checks that the player agreed, and
on `lrg_dlgtalk` (`speech=false`, `$st['utter']` unchanged) nothing checks that
the player said anything at all.

**Scenario A (refusal).** `p6.php` 6b(i), real CFTO layer:

```
turn 1  "Take me to Solitude."                                  -> PARKED (norm "solitude")
turn 2  "No, forget it, I have changed my mind entirely."        -> do=pick pos=0  cost=50
```

The model re-emits the same item because `lrgDlgServiceArbitrate()` (3441) falls
back to the model's own `$item` whenever the player's words name no slot — so a
refusal that names nothing hands the decision straight back to the model.

**Scenario B (no player turn).** `p12.php` 12b:

```
LOG  dlg gate: first selection of "Solitude. (50 gold)" PARKED
   turn2(lrg_dlgtalk)=pick pos=0
LOG  dlg emit npc=Bjorlam do=pick mode=slot pos=0 kind=pay cost=50
```

**Scenario C (the full chain with D3).** `p7.php` 7d:

```
turn 1  "I've just come from Riften."                            -> PARKED (norm "riften")
turn 2  "Never mind all that, what's the weather like up north?"  -> do=pick pos=3 "Riften. (50 gold)"
```

50 septims and a cross-province teleport, from two sentences neither of which
ordered a ride.

**Smallest fix.** Two lines in `lrgDlgParkOrRelease()`:

1. require a fresh player utterance — replace the `!empty($t['speech'])` guard
   with `if (!$t['speech'] || (int) ($st['utter']['at'] ?? 0) <= (int) $p['at']) { return 'parked'; }`;
2. require assent — release only when the new utterance is not a refusal, e.g.
   `if (lrgDlgIsBackOut($utter) || lrgDlgPhraseHit($utter, ['no','nope','forget it','never mind','changed my mind']) !== '') { lrgDlgPut($npc, ['parked' => null]); return 'parked'; }`.

---

## D3 — HIGH — the slot matcher reads a *mention* of a hold as an order

`lrgDlgServiceSlot()` (3374) matches a slot on exact token containment anywhere
in the utterance, with no test that the sentence is an instruction. Every one of
these returns `mode=pick` on the real CFTO layer (`p6.php` 6a, `p7.php` 7c):

```
"I've just come from Riften."          -> pick riften
"My brother lives in Morthal."         -> pick morthal
"They call me Riften, after the city." -> pick riften     (the player's own name)
"Solitude is a beautiful city, is it not?" -> pick solitude
"I was robbed on the road to Morthal." -> pick morthal
"My cousin farms near Morthal."        -> pick morthal
"Is the road to Dawnstar safe?"        -> pick dawnstar
```

On the vanilla CFTO list the click is (accidentally) deferred by the
`scripted && goodbye ⇒ commit` park — which is exactly the park D2 then releases
on the next turn. On any short priced layer whose entries are *not* both
`scripted` and `goodbye` (and the census has plenty:
`KmodFastTravelCarriageEastmarch`, `…FalkreathHold`, `…Haafingar` all have
`scripted=0 goodbye=0`), it fires on the first turn with no confirmation at all.

It also poisons `<locked_facts>` — see D4.

**Smallest fix.** Require an imperative frame before a slot may *execute*:
in `lrgDlgServiceSlot()`, before returning `mode => 'pick'`, demand that the
utterance also carry a service phrase of the matching kind
(`lrgDlgServiceKind($utter) !== ''`, whose table already holds "take me to",
"drive me to", "a ride to", "rent a room", "train me in" …) **or** that the whole
utterance normalise to the slot name alone (the answer-a-question case). Degrade
to `mode => 'ask'` otherwise — the design already treats "ask" as the safe answer.

---

## D4 — MEDIUM — `<locked_facts>` tells the NPC her list holds one destination

`lrgDlgPrepareTurn():1552` collapses `svc.slots` to the single matched slot on a
`pick`, and `lrgDlgLockedFacts():3924` then renders it under a header that says
*"These are facts the game has confirmed this moment. They are true."*

**Evidence** (`p7.php` 7e), layer = Solitude / Solitude Lighthouse / Morthal / Riften:

```
locked: [price]   what she is asking for "Solitude. (50 gold)": 50 septims
locked: [price]   what she is asking for "Solitude Lighthouse. (50 gold)": 50 septims
locked: [service] the only ones on her list: morthal          <-- false
locked: [gold]    Jorunn is carrying 900 septims
```

The block that exists to stop her inventing destinations is itself asserting a
false one, and it is the *only* destination fact she is given. Combined with D3,
merely mentioning a hold rewrites what she believes she can offer.

**Smallest fix.** At 3924 use `$svc['all']` (the full slot census the same
function already builds at 1546) rather than `$svc['slots']`, and keep
`$svc['slots']` for the `ask` wording only.

---

## D5 — MEDIUM — `bLockedFacts = 0` still switches the truth gate off

`lrg_dialogue.php:2273–2283` carries a comment stating that `lf` and `tg` are
separate controls and that with `tg=1` an unconfirmed number is still stopped
from costing gold. It is not so: `lrgDlgTruthCheck():3996` returns `null` the
moment `$t['locked']` is empty, and `$t['locked']` is empty whenever
`lrgDlgLockedFacts():3899` sees `lf !== 1`.

**Evidence** (`p7.php` 7f / 7g), same layer, same reply *"That will be 500 gold,
friend."* against a 35-gold ride:

```
lf=0 tg=1 -> locked facts built: 0 ; action EMITTED  (do=pick pos=2)
lf=1 tg=1 -> action dropped       (LOG: truth … claim=price said="500 septims" action=dropped)
```

**Smallest fix.** Build the facts for the gate independently of the injection
toggle: in `lrgDlgPrepareTurn()` compute `$turn['locked']` unconditionally and let
`lrgDlgLockedBlock()` alone honour `lf`; or, smaller still, have
`lrgDlgTruthCheck()` call `lrgDlgLockedFacts($t)` itself when `$t['locked']` is
empty and `tg=1`.

---

## D6 — MEDIUM — the truth gate drops a correct, already-confirmed action over flavour talk

`lrgDlgTruthCheck():4011` matches **any** `<n> gold|septims|coins` in the reply
against the live prices. A driver reminiscing about money cancels the ride the
player just confirmed.

**Evidence** (`p12.php` 12c — control and case, same seat, same confirmation):

```
reply "Climb up then."                                                        -> do=pick pos=1
reply "Aye. I lost 300 gold at dice in Riften last winter. Climb up."         -> nothing
LOG  dlg truth npc=Bjorlam claim=price said="300 septims" fact="50/35/900 septims" action=dropped
```

The owner sees the NPC agree out loud and the ride silently not happen — the
failure mode hardest to diagnose from inside the game.

**Smallest fix.** Only treat a number as a *price claim* when it sits in a price
frame. Tighten the pattern at 4011 to require a price verb or preposition within
a few words — e.g.
`/\b(?:costs?|charge|fare|price|for|that(?:'ll| will) be|pay(?: me)?)\b[^.]{0,20}?(\d[\d,]*)\s*(?:gold|septims?|coins?)\b/i`
— and leave past-tense narration alone.

---

## D7 — MEDIUM — "Follow me." and "Wait here." can never resolve, and the CHIM fallback has already been taken away

`lrgDlgFollowerVerbOf():3571` trusts the topic EditorID first and falls back to
the entry text only when `$inFamily || count($pt) >= 3` (3593). On the real index
the two-word commands lose the tie-break:

**Evidence** (`p9.php` 9a):

```
"Follow me."  -> topic=CWPrisonerFollower (CWMission04, toplevel=0)   verb=''
   candidates also present: DialogueFollowerFollowTopic ×5, …FollowDummyTopic ×2  (all shared=25)
"Wait here."  -> topic=CWPrisonerWait     (CWMission04, toplevel=0)   verb=''
"It's time for us to part ways." -> DialogueFollowerDismissTopic      verb='dismiss'
```

`CWPrisonerFollower` is in no `services.follower.families` glob, and `follow me`
is 2 tokens, so the text fallback refuses it. Result (`p12.php` 12d):

```
fol.verbs=dismiss  asked=follow  resolved=0  -> gate=nothing
LOG  dlg fol npc=Lydia verb=follow entry=- why=no-entry-for-that-verb (she has dismiss)
hidden: MakeFollower,FollowPlayer,WaitHere,Follow
```

Because `fol.verbs` is non-empty (dismiss survived), `lrgDlgServiceHidePolicy()`
hides CHIM's `Follow` / `FollowPlayer` / `WaitHere` / `MakeFollower`. So the two
commands the owner will use most do **nothing at all**, and the shortcut that used
to do them is gone. `dismiss`, `trade`, `home` and `unhome` resolve correctly.

**Smallest fix.** At 3593 accept a 2-token phrase when it is the *whole* entry, not
a substring: `if (lrgDlgTokenRun($norm, $pt) && ($inFamily || count($pt) >= 3 || $norm === $pt))`.
(`"Follow me."`/`"Wait here."` normalise exactly to the configured phrases, so this
cannot widen into ordinary quest lines.) Optionally also add `CWPrisoner*` to
`services.follower.families`.

---

## D8 — MEDIUM — the same sentence is paid for Speech XP for ever, and a repeated bribe passes for free

`lrgDlgCheck()` (`lrg_speech.php:112`) remembers the *result* in `$st['checks']`
(229) but still sets `award = true` on every pass (237). The memory hit also
leaves `$N = 0`, so the bribe's `take=` collapses to zero while the verdict stays
`pass`.

**Evidence** (`p10.php` 10b / 10c, three consecutive turns, same words):

```
bribe "Here is 200 gold, just look the other way about the bounty."
  turn 1  res=pass mem=miss N=200 | do=award xp=1 take=200
  turn 2  res=pass mem=hit  N=0   | do=award xp=1 take=0     <-- passes again, pays nothing
  turn 3  res=pass mem=hit  N=0   | do=award xp=1 take=0
persuade "Come on, you can tell me what really happened that night."
  turn 1..3  res=pass  mem=miss/hit/hit | do=award xp=1 each time
```

So: unbounded Speech XP from one repeated line, and a bribe that succeeds without
the gold from the second attempt onward. Gold is never taken *twice* — the defect
is the opposite, it stops being taken at all.

**Smallest fix.** At `lrg_speech.php:236`, gate the payout on novelty:
`if ($res === 'pass' && $memState === 'miss') { $out['award'] = true; … }` — a
remembered pass keeps its verdict for the narration and grants nothing. If a
remembered *bribe* should still cost, carry `$mem['N']` forward instead of 0.

---

## D9 — MEDIUM — 7.7 % of decisions ship `txt=` empty, and the game then clicks on position alone

`lrgDlgSafePrefix():2596` returns `''` whenever the sanitised text is under 12
characters (2600). `TryResolvePick()` (`LRG_Dialogue.psc:1966`) reads
`if reqTxt == "" || StringUtil.Find(eText[reqPos], reqTxt) == 0` — an empty `txt`
**skips the only content check the click has**.

**Evidence** (`p11.php` 11a, measured over the whole real index):

```
2,895 of 37,561 rows (7.7%) -> txt= empty
samples: Flute. | Drum. | Lute. | Not yet. | …
```

and that set includes **every bare destination name** — `Morthal.`, `Riften.`,
`Dawnstar.`, `Falkreath.`, `Haafingar.`, `The Pale.` — i.e. precisely the entries
where a mis-positioned click spends gold and teleports. The driver bumps `gen`
only when `aiCount|eText[0]|eText[last]` changes (`LRG_Dialogue.psc:1715`), so a
list whose **middle** changed keeps its `gen` and passes the freshness test; with
`txt=` empty nothing else disagrees.

**Smallest fix.** Drop the 12-character floor to something a short entry clears,
and let the game keep its prefix test — `if ($s === '') { return ''; } return substr($s, 0, 40);`
(the sanitiser already strips `; = @ | " ~`, which is what the 12 was protecting).
`Morthal` and `Riften` then travel as verifiable prefixes.

---

## D10 — LOW — `min_priced = 0` routes 138 price-free layers through the slot matcher

`services.slot.min_priced` is 0 (`lrg_dialogue.php:150`; the deploy pre-flight
pins it there on purpose for R12). The side effect on the real index
(`p13.php` 13a): **138 of 5,718 multi-entry layers (2.4 %) carry no priced entry at
all and are still treated as price lists**, which switches the similarity matcher
off for execution on them:

```
feim / fus / yol
heavy / light / medium
consider it done / forget it / i don't have time right now / i'll do it / it's not my problem
master bedroom / new banners / poisoner's nook / secret entrance
```

On those layers a paraphrase can no longer be taken up — only exact token
containment. Safe, but it quietly removes menuless questing from a slice of real
quest choices. **Smallest fix:** keep `min_priced = 0` only when the layer's kind
really is a service (`lrgDlgServiceKind($utter) !== ''` or a priced entry present),
otherwise require `min_priced >= 1`.

---

## D11 — LOW — this gate passes a model-authored money action straight through

`lrgDlgPostProcessActions():2226` forwards every line whose code is not in
`LRG_DLG_GATE_NAMES`. A batch of
`Lydia|command|MakeFollower@Player` + our own pick survives whole (`p8.php` 8d),
and so does `Ysolda|command|GiveGoldTo@500` (`p10.php` 10d) — even though the
same file's `lrgDlgActsOnMoney():4034` lists `givegoldto` / `paybounty` /
`hirecarriage` / `rentroom` / `training` as money movers, and the offer policy has
just *hidden* those codes. The hide is advisory (it edits `ENABLED_FUNCTIONS`, i.e.
the prompt); nothing in this module drops the line if the model emits it anyway.

Whether CHIM core itself refuses an unoffered action was not testable here, so this
is reported as a gap in *our* net rather than a proven in-game effect.

**Smallest fix.** In `lrgDlgPostProcessActions()`, drop any line whose code is in
`$GLOBALS['LRG_DLG_SVC_HIDDEN'] ∪ LRG_DLG_HOLD_HIDDEN` for this NPC this turn —
one `in_array()` beside the existing pass-through.

---

## D12 — LOW — a spelled-out bribe is classified but never priced

`lrgDlgCheckKind()` (`lrg_speech.php:336`) recognises a bribe through
`lrgIntentAmount()`, which understands words; `lrgDlgNamedAmount():2548` — used by
**both** the free check and `lrgDlgCheckRails()`'s "he offered less than the price"
test — is digits-only.

**Evidence** (`p10.php` 10a):

```
"Here is 200 gold, …"                    named=200  intentAmount=200
"Here is two hundred gold, …"            named=0    intentAmount=200
"I'll give you two hundred septims …"    named=0    intentAmount=200
```

So the owner's own phrasing yields `result=ask` ("she names her price, nothing
moves"), and on an **engine** bribe entry the `$named > 0` guard in
`lrgDlgCheckRails()` is skipped — the rail that refuses an under-offer never runs.

**Smallest fix.** `function lrgDlgNamedAmount(string $utter): int { if (function_exists('lrgIntentAmount')) { $n = lrgIntentAmount($utter, false); if ($n > 0) { return $n; } } …existing regexes… }`

---

## D13 — LOW — the six-token negation window swallows the order that follows a refusal

`lrgDlgNegatedAt():2949` scans six tokens back. `"Don't wait here, come with me
instead."` negates *both* verbs, so the turn settles nothing
(`lrgDlgFollowerNegatedOnly()` returns 1 — `p13.php` 13c). Safe (it costs a
question), and the comment at 2949 says the trade was deliberate — recorded so the
owner is not surprised when a compound sentence is answered with "which do you
mean?". The equivalent carriage case (`"I don't need a room, take me to Morthal."`)
is far enough apart and resolves correctly.

---

## Attack coverage

| Attack | Result |
|---|---|
| "what does the carriage cost?" / fare questions | **held** (both routes) |
| a question naming a slot ("Do you go to Morthal?") | **held** — `price`/`ask`/commit rails |
| negations, apostrophe forms, "anywhere but" | **held** |
| quoted order (`She said "take me to Morthal"`) | **held** |
| partial names (`Sol`, `Solit`) | **held** |
| two destinations in one line | **held** (`ask`) |
| player's own name matching an entry | **D3** |
| prefix pair, both orders (Solitude / Solitude Lighthouse) | **held** — longest-wins correct on real data |
| persuade entry vs a mention of persuasion | **held** (both routes) |
| confirmation answered by an unrelated turn / by no player turn | **D2** |
| `want=1` clicking commit / crit / walkaway / meta / unaffordable | **held** |
| `want=1` on an arrest layer / resist-arrest | **D1** |
| LETHAL under-grading by the layer tier | **D1** (server net exists, one route skips it) |
| stale sid / changed list between answer and click | **D9** |
| `ok=1` without a `cid` | **held** |
| truth gate / safety net dropping the wrong thing | **D6** (and **D5**) |
| follower verbs: CHIM shortcut + real entry | **D7**, **D11** |
| service kinds double-firing | **held** |
| old game script (401/500) against the new server | **held** |
| LLM's words treated as the check outcome | **held** |
| XP granted twice | **D8** (granted every repeat) |
| gold moved twice | **held** — the opposite: **D8**, gold stops moving while the bribe still passes |

## Suggested order

1. **D1** and **D2** before any live flip — both end in an irreversible effect.
2. **D3** + **D4** together (same data path).
3. **D5**, **D6**, **D7**, **D8**.
4. **D9**–**D13** at leisure.
