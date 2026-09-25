# pt8 verify - THE OWNER'S EVENING (independent verifier, read-only)

Lens: can the owner, with `PLAYTEST8_NOTES.md` in front of him and nothing else, (a) run the probe in the
right order and know what to send back, (b) do the dry-run conversation test on a real LoreRim quest giver,
(c) use the free-conversation speech checks of OWNER_ADDENDA item 5, and (d) trust what the notes say about
the three intimacy changes, the emergency key, the owner-side settings and the two upstream bugs.

Everything below was re-derived from the files and from live runs; nothing is taken from the build reports.

## What I actually ran

* `diff -rq` staged source vs the DEPLOYED plugin at `/var/www/html/HerikaServer/ext/lorerim_glue`:
  **identical** (only the runtime `config/lrg_config.json` and `data/` exist extra). manifest **0.4.0**,
  deployed 00:44. The MO2 mod folder `F:\Modlists\LoreRim\mods\LoreRim Glue` holds all nine `.pex`
  (00:49) and the 00:29 MCM files. So the notes' "installed / deployed" is true; `meta.ini` is still
  `version=0.1.0`, exactly as §8 says.
* Re-ran the offline suites myself from a staged copy (`$env:TEMP\lrg_test\glue`, WSL php):
  `tools/test_dialogue.php` **85 passed / 0 failed**, `tools/flows/run_flows.php --strict`
  **58 scenarios, 58 passed, 897 checks, 0 warnings, RESULT: OK**. The builders' test claims hold.
* Live DB: `lrg_prompt` **37,561** rows, `lrg_prompt_layer` **5,718** - matches §10 of the notes.
* Wrote four verifier-only trace scenarios (temp copy only, nothing in the project changed) that drive the
  DEPLOYED module with **real index rows pulled from Postgres** (Whiterun gate guard, Jarl Balgruuf MQ102)
  and with the owner's own three free-check sentences.

---

## Defects

### 1. CRITICAL (docs/ui) - the probe key is dead in the session in which the owner arms it

`PLAYTEST8_NOTES.md` §3.1 has the owner switch `bProbe` ON, pick `iKeyProbe`, `iKeyVanillaMenu`,
`iKeyDumpTopics`, "Save", and then §3.2 "press your probe key". On this build that key does nothing:

* `LRG_DlgProbe` only ever registers its key inside its own `Maintenance()`
  (`LRG_DlgProbe.psc:49-71`), which is called from `LRG_Main.Maintenance()` (`LRG_Main.psc:136-139`),
  i.e. **on a game load only**. Nothing reacts to `bProbe:Dialogue` changing in the MCM
  (`LRG_MCM.psc:6-13` only looks for `:Keys`).
* Worse, every `:Keys` change the owner makes in that same MCM session calls
  `m.RegisterKeys()`, which begins with the form-wide `UnregisterForAllKeys()`
  (`LRG_Main.psc:221-239`) and then re-registers only LRG_Main's four keys - the probe's registration is
  wiped and never restored until the next load. (This is the project's own model of that native: it is
  why `LRG_Main.Maintenance()` deliberately calls the probe AFTER `RegisterKeys()`.)

Result: the owner arms everything as instructed, presses the key, gets nothing, and there is no log line
saying why. "Save, and make a save game you are happy to reload" reads as "make a restore point", not as
"you must reload before the probe exists".

Smallest fix (code, `LRG_MCM.psc` - it is in no lane's file list, so name an owner for it):

```papyrus
Event OnSettingChange(string a_ID)
	LRG_Main m = LRG.GetMain()
	if !m
		return
	endif
	if StringUtil.Find(a_ID, ":Keys") >= 0
		m.RegisterKeys()
	endif
	if StringUtil.Find(a_ID, ":Keys") >= 0 || a_ID == "bProbe:Dialogue"
		LRG_DlgProbe p = m.GetProbe()
		if p
			p.Maintenance()
		endif
	endif
EndEvent
```

Smallest fix (docs, if no code changes): §3.1 step 6 becomes "Save, **then load that save** - the probe key
and its listeners are only picked up on a game load. Re-load again after every change to a hotkey."
Either way, give the owner the confirmation line to look for: the probe writes
`probe armed, key <n>` on every load it arms on (`LRG_DlgProbe.psc:70`). No such line = no probe.

### 2. CRITICAL (docs) - the one command the notes give for collecting the results returns nothing

§3.3: `grep -E "^.{0,40}(P[0-9]+[a-z]?) " /var/www/.../lorerim_glue.log > probe.txt`.

A probe line is written by `LRG_Main.LogC` -> the server's `lrgLog`
(`lib/lrg_core.php:76-88`, format `Y-m-d H:i:s P`), so a real line reads:

```
2026-09-22 00:43:34 -04:00 [cid=g2690-15] GAME PROBE P2 firstlist_ms=120 ... at=93.12 npc=Delphine
```

`PROBE` starts at **column 48** (measured on the live log format), so `^.{0,40}` can never reach the `P<n>`.
I ran the notes' own regex against realistic lines: **0 matches**; `grep -cE "PROBE P[0-9]+[a-z]?"` gives 3/3.
The prose fallback ("every line with a `P` and a number right after the timestamp") is wrong too - after the
timestamp comes `[cid=...] GAME PROBE`.

Fix: `grep -E "PROBE P[0-9]+[a-z]?" /var/www/html/HerikaServer/log/lorerim_glue.log > probe.txt`
(and on Windows: "every line containing `GAME PROBE`").

### 3. MAJOR (driver/docs) - the emergency key does NOT give back a menu the PROBE hid

§3.2 row 7: "the menu is hidden and guarded. **Press your emergency key afterwards to get it back**", and
§5: "It always works". For a probe-hidden menu it does not.

`LRG_Dialogue.HandleEmergencyKey()` (`LRG_Dialogue.psc:552-577`) only un-hides when the DRIVER has a
session: with no session it takes the `!IsSessionOpen()` branch, and `HardReset()` - the only path that calls
`LRG_DlgUI.Unhide()` - runs solely when `dlgState != ST_IDLE`. The probe hides through
`LRG_DlgUI.Hide()`/`Guard(true)` (`LRG_DlgProbe.psc:429-462`) without touching driver state, so `dlgState`
is `ST_IDLE`, nothing is un-hidden, and `LRG_Main.OpenVanillaDialogue()` just calls `Activate` on an NPC
while a menu is already open. The hidden/guarded state lives in StorageUtil (`hid`/`hok`, `LRG_DlgUI.psc:469-512`),
so it survives the key press.

This bites on presses **15** (`P6 hide=H1`, mode 2 = the whole menu `_visible=false`), **16** and **17**
(`P7s` leaves `Guard(true)` on) - and the notes only tell the owner to press the escape key after the cycle
press 2 (which happens to end with the TAB test that may close the menu anyway).

Smallest fix (`LRG_Dialogue.psc:559-566`, inside the `!IsSessionOpen()` branch, before `return false`):

```papyrus
if LRG_DlgUI.IsOpen() && LRG_DlgUI.IsHidden()
	LRG_DlgUI.Unhide()          ; a menu hidden by the probe (or a state that outlived a session)
	Debug.Notification("LoreRim Glue: the menu is yours")
endif
```

`IsHidden()` and `Unhide()` already exist as globals (`LRG_DlgUI.psc:606-655`) and `Unhide()` releases the
guard and the cursor as well. Until that lands, §3.2 must say: after presses 15, 16 and 17 the menu stays
hidden/guarded - **reload the save**.

### 4. MAJOR (server/intimacy) - ordinary money talk is read as paying for sex

The round prompt asks whether anything in the prompts is fighting the intimacy prompts. It is, and in the
most common sentence in the game. `lrgIntentMoney()` (`lib/lrg_intent.php:263-330`) has no notion of *what*
is being bought; the only exclusions are negation, quoting and hypotheticals. Live trace (scenario d94, run
against the deployed plugin):

| the player says, to | the guidance the model gets |
|---|---|
| "How much do you want for it?" (commoner, affinity 0) | `<player_request>The player asked what it would take. ... name a figure at or above 35 septims, or refuse ... Coin is not a command` |
| "I will give you thirty gold for the sword." | `The player just offered 30 septims. That is below what ... would take.` |
| **"Here is two hundred septims for the room and the food." (innkeeper)** | **`That is at or above what Brenna would take. If Brenna wants to, say so plainly AND choose BeginIntimacy with amount 200 in the same reply`** |
| "How much do you want for it?" (jarl) | `name a figure at or above 105000 septims` |
| the same lines to a Vigilant | (correctly) no money directive |

Paying an innkeeper for a room is the single most frequent money sentence in Skyrim, and it is routed into
an explicit invitation to call `BeginIntimacy`. Consent gates still apply, but the owner's evening will be
full of shopkeepers quoting their body price. Note also that the free-check path gives these lines nothing
(defect 5), so the sex price is the ONLY guidance the model gets about a haggle.

Smallest fix: gate the money pre-scan on an intimacy context. In `lrgIntentMoney()`, return `null` when the
utterance names what is being bought and no proposition is live, e.g. before the `$kind` precedence block:

```php
$goods = (bool) preg_match('/\b(buy|sell|sold|trade|barter|wares|goods|for (?:the|a|an|this|that|your) '
    . '(?:room|bed|night|sword|axe|bow|armou?r|potion|horse|food|meal|drink|ale|mead|supplies|lot|it)|'
    . 'room for the night|what does .* cost)\b/', $t);
if ($goods && !$hasQuote) { return null; }
```

and, better, require for `askprice`/bare `offer` with no live quote that the NPC has already been
propositioned in this conversation (a romance/intimacy marker in `lrgMemGet`, an open invitation, or a
running scene). The shipped `bPaidIntimacy` MCM switch is the owner's only current escape.

### 5. MAJOR (server) - two of the owner's own three free-conversation examples produce no check at all

OWNER_ADDENDA item 5b names three cases; the round prompt names the same three. Measured against the
deployed module (scenario d91):

* haggle with a merchant ("Come on, knock the price down - twenty septims for the lot.") -> **check = null**
* lie to a guard about a bounty ("That bounty is not mine. You have the wrong man - I have never set foot
  in Riften.") -> **check = null**
* intimidate a bandit ("Drop the axe and walk away, or I will put you in the ground.") -> works:
  `kind=intimidate result=pass`, one `do=award ... xp=1`, and the directive states the outcome as fact.

Cause: `lrgDlgCheckKind()` (`lib/lrg_speech.php:268-292`) first asks `lrgRecogniseIntent()` for a kind and
maps `persuade/intimidate/bribe/deceive`, **but `lib/lrg_intent.php` never returns any of those kinds** -
grep for them there is empty, so that whole branch is dead code. Everything therefore rests on four
hard-coded regexes of ~30 literal phrases, and there is **no haggle/barter kind at all**, although "get a
better price" is the addendum's first example.

Recall measured on 18 natural phrasings (scenario d92): **6 of 18**. The four that the shipped flow tests
use are all literal members of the pattern list. Failures include "I am begging you - just tell me where he
went" (persuade), "You do not want me as an enemy" (intimidate), "I have never set foot in Riften"
(deceive) and "**Here is two hundred septims. Look the other way.**" (bribe - the regex wants `\d+ septims`
and does not read spelled-out numbers, although the intimacy lane's own money scanner does).

Smallest fix: (a) widen the four fallback patterns (and reuse `lrgIntentAmount()` for spelled-out sums in
the bribe branch), (b) add a `barter` kind that maps onto a persuade-type check with `stakes=small`,
(c) delete or implement the dead `lrgRecogniseIntent` mapping so the code says what it does. Nothing here
is unsafe - it simply does not fire - but "fully working in any situation" (addendum item 7) is not true yet,
and the notes promise it without qualification (§6 `bFreeChecks`: "persuade / threaten / bribe / lie in
ordinary CHIM talk are decided by the game's real rules").

### 6. MINOR (docs) - the WOULD CLICK line has two shapes, and the notes show only one

§4.2 shows `WOULD CLICK cid=... pos=3 i=7 kind=plain route=1 text=...`, which is the driven path
(`LRG_Dialogue.psc:1631-1640`). With the shipped `iEngineOpen = 0`, a conversation the OWNER opens with E is
"assisted", and the dry-run line is then
`WOULD CLICK (assisted, nothing driven) pos=... txt=... text=...` (`LRG_Dialogue.psc:675-684`) - no `cid=`,
no `i=`, no `kind=`, no `route=`. An owner grepping `WOULD CLICK cid=` loses half his evidence. Also worth a
sentence: after a driven WOULD CLICK the session goes MANUAL (`GoManual("show")`), so it is **one WOULD CLICK
per conversation** - "ten or so turns" means ten conversations.

Fix: grep `WOULD CLICK` (no `cid=`), and add the second shape plus the one-per-conversation note to §4.2.

### 7. MINOR (docs) - press 16 is missing from the probe order, and the MCM help mislabels 15/16

The order table in §3.2 lists 20 rows and never mentions `iProbePress = 16` (`P6f`, the second SWF family).
The MCM help for `iProbePress` says "15 and 16 the two ways of hiding" - in code 15 is `PressP6(..., 2,
"P6 hide=H1")` and 16 is `PressP6(..., 1, "P6f hide=H2")`, i.e. 16 is the same hide as cycle press 2, on the
other SWF family (`LRG_DlgProbe.psc:223-226`). Either tell the owner to run it (one press, harmless) or say
in the notes that it is only meaningful if the dialogue-interface mod is swapped.

Two smaller order notes: press **23** (`P11b`) needs **two** presses with a hand re-open in between when
step 1 closes the menu (the probe says so in a corner note, the notes say "press once"), and a game reload
resets the cycle counter to 1 (`LRG_DlgProbe.psc:49-58`), which matters for §3.2 rows 13 and 14.

### 8. MINOR (ui) - `iHideMode` is a 0-based MCM enum wired to a 1-based code value

`config.json:121` offers `["Topics only", "Everything (test)"]` (values 0 and 1); `settings.ini:117` ships
`iHideMode = 1`; the driver passes it straight to `LRG_DlgUI.Hide()`, where **1 = topics-only and 2 = full**
(`LRG_DlgUI.psc:469-471`). So the shipped default behaves correctly (topics only) but the MCM shows
"Everything (test)" as the selected option, and picking "Topics only" writes 0, which `Hide()` also treats as
topics-only. "Everything (test)" is unreachable. Fails safe, but it contradicts both the MCM help and the
notes ("Topics only (the default)").

Fix: make the enum `["(unused)", "Topics only", "Everything (test)"]`, or map in `ReadSettings()`
(`LRG_Dialogue.psc:396`) with `sHideMode = m.SettingInt(...) + 1`.

### 9. MINOR (docs) - the "`read OFF`" acceptance test does not cover "all of the above"

§6 says: after the first load, search the log for `read OFF`, and "Acceptance test for all of the above".
The only line that can produce it is `LRG_OStim.psc:359-380`, and it names four ids only
(`bPrivacyDoors`, `bPaidIntimacy`, `bFixControlsAfterScene`, `fListenerHold`). The Menuless-questing
on-by-default keys are not covered - including `bDlgDryRun`, whose absence would read FALSE and mean
**live clicking**. (All lines are present in the shipped `settings.ini`, so nothing is off today.)

The load-time line that does answer the question for Phase 2 is
`dlg self-test v400 menuless=0 dryrun=1 engineopen=0 crit=0 scenegate=0 hide=1 ...`
(`LRG_Dialogue.psc:350-372`). Fix: point §6 at that line as well, or extend the NOTE list with
`bDlgDryRun/bFreeChecks/bQuestAware/bIntentOpen/bHideCursor`.

### 10. MINOR (docs) - nothing tells the owner where a speech check is logged

§7.1 gives the owner `price npc=...` for money, but the speech checks have no pointer anywhere in the notes,
although addendum 5c demands they be readable. The line exists and is complete
(`lib/lrg_speech.php:246,251-261`):

```
check npc=<name> where=free kind=intimidate diff=0/SpeechVeryEasy=10 sp=40(live) wis=1 pg=420 N=0
      stakes=small stance=0 bias=0 mem=miss res=pass why=... aff=0 xp=1 gold=0
```

Add it to §4/§6, with the sentence that `where=free` is a free-conversation check and `res=` is the
server's verdict, not the model's.

### 11. MINOR (server) - 54 check-tagged lines in this load order are classified as ordinary entries

Measured in the live index: of the 699 rows whose player text carries `(Persuade|Intimidate|Bribe)`,
**54 have `kind=''` and `topic_kind=''`**, so `lrgDlgClass()` (`lib/lrg_dialogue.php:640-656`) cannot call
them `check` - there is no fall-back on the visible tag. Example the owner will meet early: the Whiterun
gate guard's "Stand aside, or else. (Intimidate)" (`conds=[]`, `scripted=1`) is labelled
`[commits - cannot be undone]` rather than an attempt (my d90 trace). Because these rows are mostly
`scripted=1` they still land on the commit/two-step path, so no hard rule is broken, but the labelling
promise of §7 of the design is ~92 % true, not 100 %.

Smallest fix in `lrgDlgClass()`: if `kind === ''` and the live text matches
`/\((persuade|intimidate|bribe|lie)\)/i`, treat it as `check` (label "attempt") or at minimum as `commit`.

### 12. MINOR (docs) - unmentioned cost of the very first business turn

When the glue opens a conversation only to read a list (`bIntentOpen = 1`, the default) and then clicks
nothing, it fires `lrg_dlgtalk` (`LRG_Dialogue.psc:1952-1967`), which is a **second full LLM turn** for that
player line, after an open/read/close of roughly 11-14 s (still an open [U]). Everything else Phase 2 sends
(`lrg_topics`, `lrg_dlg ev=*`, `lrg_log`) ends inside preprocessing and costs no LLM call. Latency answer:
**0 extra LLM requests per ordinary player line, 1 extra on a first contact with an unknown list.**
Worth one line in §4.2 so the pause and the token spend are expected.

---

## What I checked and found TRUE

* **Dry run really is inert.** `Arm()` hides and guards only `if !goManual && !sDryRun`
  (`LRG_Dialogue.psc:1168-1185`), so with `bMenuless=1, bDlgDryRun=1` the menu stays visible, unguarded and
  clickable, exactly as §4.2 promises. `bMenuless=0` (the shipped value) never opens or hides anything.
* **The dry-run quest trace works on real LoreRim data.** With the live index rows for the Whiterun gate
  guard, the model is shown:
  `T1 [persuasion attempt] I have news from Helgen about the dragon attack. (Persuade)`,
  `T2 [bribe: costs 100 gold; the player has 300] Will this change your mind? (<bribecost> gold)`,
  `T3 [commits - cannot be undone] Stand aside, or else. (Intimidate)`, `T5 [leave] I'll come back later.`
  A single pick is **parked first** (scoff-first / two-step) and executes on the second turn as
  `do=pick;...;pos=0;i=100;txt=I have news from Helgen about the dragon;kind=persuade` - which is exactly
  what becomes the `WOULD CLICK` line. The bribe behaves the same and carries `cost=100`.
  The real MQ102 root (`I need to talk to you about Helgen.`) picks at once, and the real closed layer
  (A0/B1/B2/B3 + Riverwood) comes through as `layer_kind=closed`, every answer written out, none bucketed,
  with the three consequential ones labelled `[commits - cannot be undone]`.
* **The LLM never decides a check.** `<real_business>` carries "Never say whether a persuasion, a threat or
  a bribe worked. The world decides; you are told afterwards", the outcome arrives as
  `<what_just_happened>` stated as fact, no threshold, skill number or rule reaches the prompt (asserted),
  and the only wire line a check can produce is `do=award ... xp=1` with `pos=-1` and no stage, no
  `SetBribed/SetIntimidated/SetCrimeGold`.
* **The quest-tree context is short and spoiler-free**: at most 3 lines of
  `You and <player> have unfinished business: "<current objective>".`, ≤120 chars each, bookkeeping quests
  dropped, alias tags rewritten, no stage numbers (`lib/lrg_speech.php:434-458`), and only quests CHIM's own
  journal already has a row for.
* **The emergency key description is otherwise accurate** (`LRG_Main.psc:266-295`, `LRG_Dialogue.psc:552-577`):
  hands a live session back with "the menu is yours", opens a normal conversation when there is none, and
  resets a stale state. Only the probe-hidden case (defect 3) is wrong.
* **Closed doors match §7.3 exactly.** `LRG_Profile.WitnessScan` (`LRG_Profile.psc:185-225`): seen is always
  a witness (`HasLOS` on either party), heard-but-unseen counts only while no door within `fDoorRadius` is
  shut. Defaults are right for a busy inn - a rented room with the door closed is private, the common room
  is not - and the `gate ... wit=%d/%d door=%s` line (`lib/lrg_actions.php:1102`) lets the owner argue with a
  refusal. The scene-start re-check uses `abFresh = true` so a stale 15 s cache can never open a scene
  (`LRG_OStim.psc:1003-1026, 2597-2604`).
* **Post-scene fixes are real and log what the notes say**: `controls repaired after the scene (camera
  state ..., movement ..., look ...)` (`LRG_OStim.psc:987`) and the Narrator warning
  (`lib/lrg_actions.php:1142`), with `fListenerHold = 20`, `fListenerGrace = 2.5`,
  `bFixControlsAfterScene = 1` all present in `settings.ini` **and** on the MCM Intimacy/Scene-talk pages -
  the six ids the intimacy lane flagged as missing have landed.
* **IACC / Smart Talk / D8 are described truthfully.** `bIaccToggle` exists, is off, and the code writes no
  IACC setting (it only logs that the switch is on, `LRG_Dialogue.psc:377-381`); Smart Talk unsafe raises a
  corner note naming the offending key (`:373-376`); the Speech-globals drift is measured per conversation
  and escalates to a `WARNING ... a LoreRim/Little Lessons data bug, not the glue` after three sessions
  (`:1942-1950`). One nit: §9 quotes the line as `closed npc=... drift=...`; it is actually
  `closed sid=... why=... layer=... clicked=... pending=... drift=...` (the NPC name is the log's actor
  field, not a `npc=` key).
* **Topic dump (T2) is doable as written**: `dump: opening a visible menu with <name>` (`LRG_Dialogue.psc:618`)
  and `topics npc=... origin=dump ... n=... sent=... unindexed=...` (`lib/lrg_dialogue.php:323`) both exist
  with those spellings.
* **Log noise from the 5 s CHIM poll is already suppressed** (`lib/lrg_dialogue.php:947-954`); the ~700
  `turn npc=(actor)` lines an hour I found in the live log all pre-date the deploy.
* **The probe's P-tag inventory matches §3.3 exactly** (25 tags: P0, P0b, P1..P17 with the lettered
  variants), and the read-only matrix is enforced in code, not by discipline
  (`LRG_DlgProbe.psc:1046-1081`: a guard, an actor in a scene or outstanding crime gold refuses every click
  and every close).

## Unverified / worth one check by whoever owns the file

* §10 says "67" prompt-index checks; the lane report says 71 (74 with `--db`). I could not re-run that suite
  (it needs `data/prompt_index.ndjson`, which the deployed install does not keep - the index lives in
  Postgres). Harmless, but the number in the notes is wrong by one of the two accounts.
* Whether `tab` closes a fully hidden menu (defect 3's only natural escape) is precisely what probe press
  `P7 tab_closed=` measures - so until the probe evening runs, the emergency-key fix is the only guaranteed
  way out.

## Verdict

**FIX FIRST.** Not because the feature is unsafe - it ships inert, the dry run really is dry, and the
engine-decides-the-outcome rule holds everywhere I could test it - but because the evening the notes
describe cannot be completed as written: the probe key is dead until a reload (1), the one command for
collecting results returns nothing (2), and the escape hatch the notes promise after the hide presses does
not un-hide (3). Those three are ~20 lines of change between them and are all that stand between this build
and a usable playtest. Defect 4 (every price conversation reading as a proposition, up to an explicit
`BeginIntimacy` invitation when the player pays an innkeeper for a room) is live *today*, needs no probe,
and will colour the whole evening; it should go in the same pass. Defect 5 means the owner should be told
plainly that free-conversation checks currently fire on a narrow phrase list, so that "nothing happened" is
read as a recogniser gap and not as a broken feature.
