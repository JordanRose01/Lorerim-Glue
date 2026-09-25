# pt9-verify-experience — INDEPENDENT VERIFIER, lens: THE OWNER'S EVENING (v0.5.0)

**Verdict: FIX FIRST.**

Read-only pass over the shipped v0.5.0 code, `PLAYTEST9_NOTES.md`, the MCM data, the deployed server
and the three build reports. Nothing was modified except this file. Everything below is traced to a
line; where I could check the owner's real install (the Postgres rows, the deployed manifest) I did.

The round's headline promise — *"the dry-run switch is now locked shut until the calibration is
green"* — ships as **a lock with no key**. One of the eleven gate rows (`park`) can only be written
from a state the gate itself forbids the owner from reaching. The evening in section 3 of the notes
therefore ends on a red Calibration page however well it goes, and step 4 ("When it says green, you
are done") is unreachable. Four further defects sit on top of that, all met in the first twenty
minutes, plus one that sends the owner's model decision at the wrong setting entirely.

What is genuinely good is listed in section 3; it is not a short list.

---

## 1. Findings

| # | Sev | Side | One line |
|---|---|---|---|
| **E1** | **critical** | driver | `park` is a gate row only a LIVE session can answer, and dry-run is force-locked until the gate is green. Permanent deadlock; the gate can never go green. |
| **E2** | **critical** | server | With `bMenuless = 0` (the shipped state) the server still hides CHIM's `RentRoom` / `HireCarriage` / `Training` / `OpenInventory` and offers a replacement the game refuses. Services get *worse*, not first-class. |
| **E3** | **major** | ui | The active pass runs the two *non-gate* tests before the `hide` gate. With the shipped budget of 3 the `hide` row never runs, and the owner sees **none** of the corner notes section 3 promises. |
| **E4** | **major** | docs | Press 2 (`iProbePress = 35`) requires the owner to **click a topic with the mouse**. The notes say it "only checks". Miss it and `reopen` stays 0 and the gate stays red. |
| **E5** | **major** | server | Seven MCM controls the notes advertise are dead: nothing server-side reads `qig`, `qx`, `svx` or `tg`. `bTruthGate` does nothing, and `bLockedFacts` silently switches the truth gate off with it. |
| **E6** | **major** | docs | The model switch is named as `CORE_CONNECTOR_DIRECTOR`. That is Director Mode. The connector that produces the 6.5 s reply is `core_profiles.llm_primary_id` — verified 10 (Grok 4.3) on this install. |
| **E7** | **major** | docs | `ev=lat` never fires unless CHIM voices the player's own line. The driver's own build report asked for a line in the notes; section 7 promises "every reply now writes one latency line" with no caveat. |
| **E8** | minor | docs/ui | The "guaranteed fallback" (`iProbePress` + probe key) silently refuses unless `bProbe:Dialogue` is on and `iKeyProbe` is bound. Both ship at 0 and PT9 never says so. Two of the five Calibration buttons go the same way. |
| **E9** | minor | ui | `bCalibActive`'s help says "It switches itself off again after the number of conversations below." Nothing switches it off; it goes silently inert, and the "budget spent" refusal is deliberately not logged. |
| **E10** | minor | docs | Section 3 step 2 says the active pass "hides the topic list … three times". Only A1 and A4 hide anything. Section 5 (the assisted hand-back) is entirely inert in the shipped state and section 6 does not say so. |
| **E11** | minor | ui | `CalPressClickBody` tells the owner to "try the other click **on the Calibration page**". There is no such button; the other route is only `iProbePress = 34`. |

---

## 2. The findings in full

### E1 — critical — driver — the calibration gate can never go green

**What the owner does.** Plays the whole of section 3 perfectly: passive conversations, the active
pass, both manual presses. Opens MCM → Calibration. It reads
`not yet - 10 of 11: missing parked push` and never changes, for ever.

**Evidence.**

* `park` is a gate row: `LRG_DlgProbe.psc:2619-2622` —
  `int pk = CalG("park") / if pk != 1 && pk != 2 / out = CalAdd(out, "parked push")`.
* `park` is written by exactly one collector, armed from exactly one call site:
  `LRG_DlgProbe.CalPending():1814-1826` (arms) → `CalParkPoll():1828-1849` (decides). The only
  caller is `LRG_Dialogue.StepPending():2005`.
* `StepPending` runs only in `ST_PENDING`. The only entry to `ST_PENDING` is
  `EnterPendingOrManual()` (`LRG_Dialogue.psc:1973-1987`), whose first line is
  `if glueOpened && isHidden && crit != 2 && !inScene` — otherwise `HandBack("watchdog")`.
* `isHidden` is only ever set in `Arm()` at `LRG_Dialogue.psc:1430` — `if !goManual && !sDryRun` —
  and in `MaybeResume():2576`, which itself requires `!sDryRun` (`:2564`).
* `sDryRun` is forced back on whenever the gate is red: `ReadCalibration():632-641`.

So: *park* needs a hidden, glue-driven session → needs dry-run off → needs the gate green → needs
*park*. Airtight. The only exit is `bCalibOverride:Calib`, which the notes mention once (section 1b)
as a thing you would use "if you ever want past it", never as the thing you *must* use.

**Why it is worth fixing rather than documenting.** Lane A's own deviation D6 states the reason:
*"BOTH values are green, so the gate outcome is identical; only the diagnostic differs."* A row
whose every possible answer is green carries no safety information at all — it is a measurement
wearing a gate's clothes, and it is the one holding the whole feature shut.

**Smallest fix.** Delete the four lines `LRG_DlgProbe.psc:2619-2622` from `CalMissing()` (and drop
"parked push" from the eleven in the docstring at `:2599` and from `CalAnswered()`'s `11`). `park`
keeps being collected and keeps riding `CalWire()`; it simply stops holding the door shut.
If the row is wanted as a gate, the alternative one-liner is to let `CalPressClick`'s real click
settle it — but that measures a different thing and I would not.

---

### E2 — critical — server — in the shipped state, services are removed, not expanded

**What the owner does.** Walks into the Bannered Mare, talks to Hulda, opens her menu once (the
glue reads and caches the list), then says *"a room for the night."*

**What happens.**

1. `lrgDlgApplyOffer()` (`lib/lrg_dialogue.php:1468-1473`) — `$turn['on']` is true and
   `$turn['entries']` is non-empty, so `hide_chim` is applied:
   `'hide_chim' => ['RentRoom', 'HireCarriage', 'HireFerry', 'Brawl', 'Training', 'OpenInventory',
   'OpenInventory2']` (`:75`). Plus `hide_gold` (`GiveGoldTo`, `TakeGoldFromPlayer`) as soon as one
   entry carries a price (`:1474-1478`).
2. The model is offered `TakeUpBusiness` instead and takes it.
3. The game answers `LRG_Dialogue.CmdSelectTopic():873-876` —
   `if !sMenuless || !m.IsEnabled() → "Error: the feature is switched off"`.

Nothing rents the room. CHIM's own shortcut was withdrawn for a replacement that is switched off.
The same applies to a carriage driver (`HireCarriage`/`HireFerry`), a trainer (`Training`) and
**every merchant** (`OpenInventory`) — the whole of the notes' section 1(c).

It is not even bounded to the conversation: `lrgDlgListFor()` falls back to the cached root
(`:1243-1247`) and `lrgDlgRoot()` keeps it for `cache.max_age_seconds` = **1800 s**
(`:1219-1220`). One menu read hides those actions for half an hour in that location.

**Why the server cannot know better.** There is no wire carrier for `bMenuless` or `bDlgDryRun` at
all — `grep -rn "menuless\|dry" server/lorerim_glue/lib/*.php` finds only comments. The server
assumes the driver can act on the list it was sent. With E1 in place the owner is *required* to stay
in exactly the state where it cannot.

**And it is every turn, not just dialogue turns.** `lrgDlgPrepareTurn()` runs at brace depth 0 from
`functions.php:36` (and `context_pre.php:29`), so `lrgDlgApplyOffer()` makes this decision on every
ordinary CHIM exchange with that NPC while the cached root is fresh.

**Smallest fix (one key each side).**

* `LRG_Profile.BuildSnapshot()`, beside the existing `sv=` / `lf=` at `:509-514`:
  `s += ";ml=" + B2I(m.SettingBool("bMenuless:Dialogue", false) && !m.SettingBool("bDlgDryRun:Dialogue", true))`
  — one digit meaning "the driver can really click".
* `lrgDlgMcmFromSnapshot()` (`lib/lrg_dialogue.php:297`): add `'ml' => [0, 1]` to the whitelist.
* `lrgDlgApplyOffer()` (`:1471`): `if (($turn['entries'] || $turn['tail']) && (int) lrgDlgMcm('ml', 1, $npc) === 1)`.
  Default 1 keeps every existing test and every older game script behaving exactly as today.

**Zero-code mitigation for tonight, if this ships as-is:** it must be in the notes, because there is
no obvious one. `bServiceDialogue = 0` does *not* help — it only disables
`lrgDlgServiceHidePolicy()` (`:3234`), not the base `hide_chim` at `:1472`. The only owner-side
escape is `bDlgWire = 0`, which turns the whole round off.

---

### E3 — major — ui — the active pass spends its budget on the tests that are not gates

**What the owner does.** Section 3 step 2: ticks `bCalibActive`, has three ordinary conversations,
and is told to look for `CALIB active A1-hide ms=88 ok=1 gave_back=1`.

**What really happens.** `CalNextActive()` (`LRG_DlgProbe.psc:2005-2026`) returns, in order:

| order | test | is it a gate row? | does it hide anything? | does it show a corner note? |
|---|---|---|---|---|
| 1 | A2 read-back (`rb`) | **yes** | no | no |
| 2 | A3 guard reset (`apdr`) | no — measurement | no | no |
| 3 | A5 inject (`inj`) | no — measurement | no | no |
| 4 | **A1 hide (`hide`)** | **yes** | yes | yes |
| 5 | A4 read mode 4 (`rm`) | only when `rm == -3` | yes | yes |

The budget is `iCalibRuns:Calib`, shipped **3** (`settings.ini:208`), enforced at
`CalActiveWanted():2065-2072`. So the three conversations run A2, A3, A5 — and then stop.

Consequences, all owner-visible:

* `hide` (a gate row, `CalMissing():2613-2615`) is never answered, so the page stays red even
  without E1.
* Nothing is hidden and **no corner note ever appears** — only `CalActiveA1` and `CalActiveA4`
  call `CalNotify("calibrating - one moment")` (`:2252`, `:2318`). The owner will conclude the
  switch is broken.
* The log will show `A2-readback`, `A3-guardreset`, `A5-inject` — not the `A1-hide` the notes print
  first.
* Nothing then tells him why it stopped: `CalLogSkip():2148-2150` explicitly suppresses
  `budget-spent`, and the status line never mentions `runs`.
* Re-arming needs the **0 → 1 edge** of the switch (`CalArmBudget():2749-2751`). The notes say
  "Untick `bCalibActive` again when the Calibration page stops asking for more" — i.e. exactly the
  wrong way round. Nothing re-arms for someone who leaves it ticked.

**Smallest fix.** Move the `hide` block above the `apdr` block in `CalNextActive()` — i.e. make
`:2019-2021` the *second* test, after `rb`. Then the shipped budget of 3 answers both gate rows
(`rb`, `hide`) and the measurements follow on a later arming. Raising `settings.ini:208` to
`iCalibRuns = 5` (and the config default) is worth doing as well, and is what makes the queue drain
in one go.

---

### E4 — major — docs — press 2 needs a mouse click nobody told the owner about

`PLAYTEST9_NOTES.md:108-112`:

> **Press 2 - the menu-still-works test.** … It **only checks** that the vanilla menu still opens and
> reads normally after everything above.

`LRG_DlgProbe.CalPressReopen():2549-2568`:

```
CalNotify("click a topic with the mouse now")
...
while i < 30 && !moved            ; three seconds
...
int verdict = 0
if moved && visible
    verdict = 1
CalSet("reopen", verdict, "manual", 1)
```

`reopen != 1` keeps the gate row "vanilla menu after a hide" red (`CalMissing():2630-2632`).
An owner who reads "only checks" will press the button, watch, and answer nothing.

The MCM's own help for that button is **correct** (`config.json:185`: "gives you three seconds to
click a topic with the mouse so it can see that it worked"), which is exactly why the notes should
match it.

**Smallest fix.** One sentence in `PLAYTEST9_NOTES.md` section 3 press 2: *"It gives you three
seconds — click any topic with the mouse while it watches, or it cannot pass."*

Related, same press: `apd` is only written when `delay > 0` (`:2542-2548`), and the gate needs
`apd == 750` (`CalMissing():2630`). The passive half at `CalArm():1578-1584` has the same `> 0`
guard. If `AllowProgressDelay()` ever reads 0 on this install, that row is unanswerable too. I could
not settle that offline; it is worth one look at the first `CALIB SUMMARY` line in the log.

---

### E5 — major — server — seven MCM controls are dead, and one of them mis-wires the truth gate

Lane B built wire carriers for the seven new controls precisely to avoid dead controls
(build report D-B1, and `LRG_Profile.psc:491-514` emits `qig=`, `qx=`, `svx=`, `tg=`). **No server
file reads any of them.** Both MCM intake tiers whitelist by name and neither list includes them:

* snapshot tier — `lrgDlgMcmFromSnapshot()`, `lib/lrg_dialogue.php:297`:
  `['bias', 'ql', 'qi', 'sv', 'lf']`
* facts tier — `lrgDlgFactsFrom()`, `:571-572`: `['bias', 'ql', 'io', 'bi', 'rw', 'rwd']`

`grep -rn "'qig'\|'qx'\|'svx'\|'tg'" server/ tools/` → no hits.

Dead as a result: `iQuestInitiativeGap`, `bQuestSummary`, `bQuestNext`, `bServiceShortcut`,
`bCarriageByName`, `bNamePrices`, `bTruthGate`. The notes' section 4 table presents the first three
as working switches, and the MCM "Services and truth" page presents the rest as working.

The worst of the seven is `bTruthGate`, because it is not merely inert — the gate it names is wired
to the **other** toggle (`lib/lrg_dialogue.php:2041-2042`):

```php
$gateOn = !empty(lrgDlgCfg('truth.gate', true))
    && (int) lrgDlgMcm('lf', 1, (string) $t['npc']) === 1;
```

So `bTruthGate = 0` still drops actions, and `bLockedFacts = 0` silently disables the truth gate as
well as the locked facts. That is the reverse of what both help texts say.

The irony is on the line above the facts whitelist (`:569`): *"rwd was the seventh dead control: the
game sent it and nobody read it"*. Same scar, same shape, one round later.

**Smallest fix.** Two edits, no new wire:

1. `:297` → `foreach (['bias' => [-2,2], 'ql' => [0,6], 'qi' => [0,1], 'sv' => [0,1], 'lf' => [0,1],
   'tg' => [0,1], 'qig' => [60,1800]] as $mk => $range)`, and parse `qx` / `svx` as digit strings
   beside `chk` at `:565-568`.
2. `:2042` → `(int) lrgDlgMcm('tg', 1, (string) $t['npc']) === 1`, and read `qx`/`svx` where
   `quests.summary.enabled` (`:2738`), `quests.next.enabled` (`:2785`),
   `services.shortcut_fallback` (`:3242`) and the carriage/price switches are consulted today.

If that is too much for this round, the honest alternative is to strike the seven from the notes and
grey them in the MCM — but `bTruthGate`'s mis-wiring should be fixed either way.

---

### E6 — major — docs — the model recommendation names the wrong setting

`PLAYTEST9_NOTES.md:209-210`:

> the switch itself is one value (`CORE_CONNECTOR_DIRECTOR`, 10 to 1) in `general_settings`

`tools/bench_llm.php:29` says the same, and the tool reads only that row (`:80-83`).

**Read from the owner's own live database (read-only):**

```
core_profiles: id=1 "Default Profile"
  llm_primary_id = 10   <-- the ordinary conversation connector ("Standard LLM")
  llm_secondary_id = 1  llm_tertiary_id = 3  llm_quaternary_id = 4
  llm_formatter_id = 6  llm_fallback_id = 1
general_settings: CORE_CONNECTOR_DIRECTOR = 10
```

and in CHIM core: `lib/core/prisma_settings_catalog.php:211` labels `llm_primary_id`
*"🧠 Standard LLM"*, while `lib/settings.php:357` labels `CORE_CONNECTOR_DIRECTOR`
*"Director Mode"*.

So the ~6.5 s the owner is complaining about comes out of `core_profiles.llm_primary_id`.
Changing `CORE_CONNECTOR_DIRECTOR` from 10 to 1 would change Director Mode and leave every ordinary
NPC reply exactly as slow as it is now — and it would look like the advice was wrong, because it
was.

**Everything else in section 7 checks out**, and the warning is a good one. Verified from the same
table:

```
id=10 Grok 4.3          reasoning_model=1  metadata={"extra_parameters":{"reasoning":{"effort":"none"}},
                                                    "extra_parameters_enabled":true}
id=1  DeepSeek V4 Flash reasoning_model=1  metadata={}
```

The "V4 Flash may think before every line and come out slower" caution is exactly right.

**Smallest fix.** In the notes and in `bench_llm.php:29`/`:53-59`, name
`core_profiles.llm_primary_id` (CHIM UI: the profile's **Standard LLM**) as the switch, mention that
`llm_secondary/tertiary/quaternary/formatter` exist and are 1/3/4/6 here, and keep
`CORE_CONNECTOR_DIRECTOR` as a separate line for Director Mode. `bench_llm.php` should print the
profile row in `--list` next to the director row.

---

### E7 — major — docs — the latency line is silent without CHIM's player TTS

`LRG_Main.psc:697-703` — the measurement is armed only inside
`OnChimSpeechStopped(akNpc == player)`:

```
latAskStop = now
latSent = false
```

and `LatStarted():779` returns immediately while `latAskStop <= 0.0`. If CHIM does not voice the
player's own line, neither `CHIM_SpeechStarted(player)` nor `CHIM_SpeechStopped(player)` ever fires,
and **no `LAT` line and no `ev=lat` is ever produced** — silently.

`pt9-build-driver.md:384` says exactly this and ends *"Worth one line in the notes."* It is not in
the notes. Section 7 reads "every reply now writes …".

**Smallest fix.** One line in section 7: *"This needs CHIM to voice your own line (player TTS /
re-speech). With it off there is no zero to measure from and no LAT line appears — the self-test
line `dlg self-test chim tts=` says which you have."* `LRG_Dialogue.SelfTest():435` already logs
`_player_tts_traditional_dialogue`, so the answer is in the log on every load.

*(The notes' example log lines themselves are fine — I checked: `lrgDlgLog()` prefixes `dlg `
(`lib/lrg_dialogue.php:244`) and `lrgDlgOnLatency():751` writes
`dlg lat npc=… total=…ms reply=…ms voice=…ms first=…` verbatim. The game writes its own
`LAT npc=… ask= reply= voice= total= first=` beside it. Both are greppable.)*

---

### E8 — minor — docs/ui — the "guaranteed route" has two undocumented preconditions

`PLAYTEST9_NOTES.md:98` calls `iProbePress` the thing to use "if the buttons do not work", and
section 6 calls it "the guaranteed route". `LRG_DlgProbe.RunStep():288-291`:

```
if !m.SettingBool("bProbe:Dialogue", false)
    Notify("probe is off (bProbe)")
    return
```

and `settings.ini:171` ships `bProbe = 0`, `:120` ships `iKeyProbe = 0` (unbound). PLAYTEST8 told
the owner to set both (`PLAYTEST8_NOTES.md:56, 59`); PLAYTEST9 says that evening "is gone" and never
repeats them. If his save still carries the PT8 values he is fine; if he ever turned the probe back
off, the fallback is as dead as the buttons.

Two of the five Calibration buttons go through the same gate — `LRG_MCM.CalPressSmartTalk()` →
`p.RunStep(17)` and `CalPressForceClose()` → `p.RunStep(23)` (`LRG_MCM.psc:92, 103`) — while buttons
1, 2 and 5 call `CalPressClick` / `CalPressReopen` / `CalForget` directly and do not check `bProbe`.
So on a save with the probe off, three buttons work and two say "probe is off".

**Smallest fix.** One sentence in section 3 step 3 ("the slider route needs *Arm the probe* on and
*Run the probe press* bound, both on the Menuless questing / Keys pages"), and either drop the
`bProbe` test for steps 33-36 in `RunStep()` or route buttons 3 and 4 the same way as 1, 2 and 5.
`settings.ini:172-176`'s comment still stops at press 32 and should list 33-36.

---

### E9 — minor — ui — `bCalibActive` does not switch itself off

`config.json:177`: *"It switches itself off again after the number of conversations below."*
Nothing writes that setting. `CalActiveWanted()` simply starts returning
`cSkipWhy = "budget-spent"` (`:2069-2072`), which `CalLogSkip()` refuses to log (`:2148-2150`).
The toggle stays on, nothing happens, nothing says why.

**Smallest fix.** Change the help to "it stops after this many; untick and tick it again to run
another set", and let `CalStatusText()` append `- active pass used N of M` when
`CalG("runs") >= iCalibRuns`. That single addition also removes most of E3's confusion.

---

### E10 — minor — docs — two places where the notes describe a livelier evening than ships

* Section 3 step 2: *"three times, on three separate conversations, it hides the topic list for about
  a fifth of a second"*. Only `CalActiveA1` and `CalActiveA4` hide (`:2255`, `:2321`); A2/A3/A5
  write a property and put it back. With E3's ordering, zero of the three shipped runs hide
  anything.
* Section 5 (the assisted hand-back and the resume) is **entirely inert** in the shipped state:
  `HandBack()`'s note is only reached from a hand-back, and with `bMenuless = 0` `Arm()` takes the
  `vis = "module off"` branch first (`LRG_Dialogue.psc:1410-1411`) so no hand-back ever occurs;
  `manualResume` additionally requires `sMenuless && !sDryRun && calGreen` (`:2526-2527`,
  `:2564-2567`). Section 6 lists what is not live and does not mention it.
* Section 1a: *"Two of the eleven cannot be learned by watching, and one is opt-in."* It is three
  keys by hand (`guard`, `route`, `reopen`) and two by the opt-in pass (`hide`, `rb`).

---

### E11 — minor — ui — a corner note pointing at a button that does not exist

`LRG_DlgProbe.psc:2497`:
`CalNotify("the click did not take - try the other click on the Calibration page")`

The Calibration page has five buttons (`config.json:184-188`) and none of them is the other click
route; route B is only `iProbePress = 34`. `config.json:184` makes it worse by saying "the status
line offers the other click" — `CalStatusText()` (`:2673-2695`) never mentions it.

**Smallest fix.** Either add a sixth button calling `p.CalPressClick(true)`, or change the note to
"…try probe press 34 (the click test, the other route)".

---

## 3. What is right, and worth saying

I went looking for problems and these held up.

* **The passive pass really is invisible.** Every passive collector writes to StorageUtil and the
  log only. All sixteen `CalNotify` (= `Debug.Notification`) call sites are in the active pass
  (`:2116` disable, `:2252/2270/2280` A1, `:2318/2337` A4) or in the manual presses and
  `CalForget` (`:2374-2591`) — not one is reachable from `CalArm` / `CalPoll` / `CalLayer` /
  `CalPending` / `CalSpeech` / `CalClose` / `CalPayload`. Step 1 of the evening genuinely costs the
  owner nothing to do and nothing to see.
* **The emergency key survives the new states.** I re-walked the ST_MANUAL-after-hand-back and
  ST_READING-after-resume rows of the driver's own table against `NeedsUnhide():2489-2503` and
  `GoManual()`; the `HolderX() > 4000` test at the end is the right last resort and it is reached
  from every one of them.
* **`CalActiveA1` cannot leave the list hidden.** Between `Hide(` (`:2255`) and `Unhide()`
  (`:2261`) there is no `return` and no wait, the flash loop is bounded at 6, and the
  `hid == false` case is tested before the `lu == 2` disable so a refused hide does not switch the
  pass off. The warning text names the emergency key and press 32.
* **The arrest rail is intact.** `Arm()`'s `crit == 2 && (sCritical == 0 || sCrimeManual)` →
  `HandBack("lethal", false)` (`:1416-1420`, `:1481-1483`), `BeginLeave():2363-2368` the same, and
  nothing in the driver selects on a LETHAL session at all.
* **"What can I ask you" / "what should I do next" are honest.** `certain="1"` reads back only
  entries the glue really saw; `certain="0"` says so in the prompt and forbids the action
  (`:2770-2775`). `lrgDlgAskNextBlock()` goes through the same objective cleaner as
  `<shared_business>` and never emits a stage number (`:2796-2807`). Both phrase lists contain the
  notes' own example wordings (`:107-111`), and both work with `bMenuless = 0` — they do not depend
  on `$t['on']` being anything but a non-scene turn.
* **The hint does not nag.** It is emitted only on a turn that produced no other line and where the
  model did not try to take business up (`:2061`), once per session per NPC (`:2938-2940`), and it
  is gated game-side on `bQuestHint` — that one control is properly wired, unlike E5's seven.
* **Nothing fights the intimacy prompts.** `lrgDlgPrepareTurn():1344-1348` sets `on = false` and
  `list = 'none'` for a scene or an OStim scene, which removes `<business>`, `<her_list>`,
  `<locked_facts>`, the ask blocks, the initiative block and every `hide` decision in one place;
  `lrgDlgApplyOffer():1463-1466` stands Phase 1's intimacy actions down only while a dialogue
  session is open. The active pass refuses a scene from both sides (`ActiveCalibSafe():1494`,
  `CalActiveWanted():2093`). `LRG_OStim.psc` is untouched this round.
* **Latency added per line is real but small.** One extra `lrg_dlg` message per reply, no LLM
  (`SendLat():2968-2983` → `lrgDlgOnLatency():737-760`, a ring buffer and two log lines). The
  once-per-reply discipline holds: `latSent = true` is set before every other test
  (`LRG_Main.psc:782`), and the "text arrived, voice never started" case sends nothing at all.
* **Section 7's fact-locking finding is correct and well put.** `core_npc_master.lock_profile`
  exists as a column on this install, it is write protection, and the round's own mechanism
  (`requirements` in the catalog row, `lrg_dialogue.php:2610-2618`) is stated with its honest limit:
  *"CHIM's requirement vocabulary CANNOT express 'a session with this NPC is open'"*.
* **Sections 8 and 9 are honest, and the deploy really happened.** Verified on the live server:
  `manifest.json` version **0.5.0**, schema `lrg_index` present with `lrg_prompt` = **37,561** rows,
  `public.lrg_prompt` gone (moved, as migration 007 intends), the scratch database `lrg_t` still
  there as declared. The `$0.049` accidental spend and the `test_latency_models.php` trap are
  reported plainly and are exactly the kind of thing that should be in section 9.
  *(Note for whoever files this: `pt9-build-server.md`'s "the server was NOT deployed" is stale —
  a later step deployed it. The notes are the accurate document.)*

---

## 4. If only three things get fixed tonight

1. **E1** — four lines out of `CalMissing()`. Without it the owner's evening cannot succeed, and
   every other calibration fix is decoration.
2. **E2** — the `ml=` digit, three small edits. Without it the round's advertised headline feature
   makes the game worse for the entire evening, at every innkeeper, carriage, trainer and merchant.
3. **E3** — move one `if` block up in `CalNextActive()` and set `iCalibRuns = 5`. Without it the
   active pass appears to do nothing at all and the owner reports a broken switch.

E4, E6 and E7 are one sentence each in `PLAYTEST9_NOTES.md` and should go in the same pass — E6 in
particular, because it is the owner's own question and the current answer points at the wrong knob.
