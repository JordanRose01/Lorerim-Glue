# pt14 - GAME lane: never silent. Every refusal carries her words (script 507)

LoreRim Glue, GAME lane. Written 2026-09-23 (~03:00-03:20) for owner addendum 11 ("if she's busy she
should say that then and it will be fine, she never told me she was busy, she just didn't do anything").
`LRG_Main.CurrentVersion` **506 -> 507**. **NOT installed.** Nothing was written under
`F:\Modlists\LoreRim\mods\LoreRim Glue` and no profile file was touched. MO2 (pid 41132) is running.
SkyrimSE is not running. `tools\compile.ps1` prints `OK` (10 scripts, 0 errors, 0 warnings).
The 506 sources, `.pex` files and MCM files were backed up first to `%TEMP%\lrg_pt14g\backup506\`.

---

## 0. The short version

1. **One place writes every funcret now** (`LRG_Main.SendFuncret`, checked in the bytecode: it holds the only
   `logMessageForActor(..., "funcret")` and the only `commandEndedForActor`). Every result carries a marker:
   * `OK: <neutral sentence>`: it was done, and there is nothing for her to say. This covers all 34 success sites.
   * `Error: <her reason>`: it was not done. The reason is a short line in plain words that she can repeat,
     for example `Error: we are not doing anything right now` or `Error: I cannot get into that from here`.
2. **The technical reason is kept, but it is never what she is told.** Call sites still pass the old
   technical reason (PROTOCOL 1.6's closed list). `LRG_Main.SayReason(tech, command)` turns it into her words.
   The technical text goes to three places only:
   * the log line: `result <Code>: Error: <her words> [why: <technical>]`;
   * the corner note, which is **byte-for-byte what 506 showed**, still gated by `bNotifyErrors:General`;
   * a new **additive key `err=<technical reason>`** appended to field 2 of the funcret. The server can use
     it to classify without matching her wording.
3. **Escort** has its own words, in the third person with her name, because the corner note needs the name.
   The sentences are chosen by the verdict. Two examples: `Lisette is in the middle of a performance she cannot leave`
   and `Lisette already travels with you, ask her the way you always do`.
   Two silent paths are closed:
   * **The follow could not be put on.** 506 still answered "comes with you". 507 answers `Error: Lisette cannot come with you right now`.
   * **Her scene came back after "comes with you".** 506 showed only a corner note. 507 also sends a **late**
     `Error:` funcret (`late=1`): `Lisette had to go back to her performance`.
4. **After a start, an `after=` position that turns out to be impossible** now sends a late `Error:` for the
   start command, in her words. In 506 it was dropped with only a log line.
5. **Clothing that changed nothing** says why in her words: `I have nothing left to take off` or
   `you have nothing to put back on`. It used to say "not possible in this position".
6. No MCM key was added and no MCM file changed. The ESP and SEQ did not change.

---

## 1. The wire (additive; the server lane decides what to do with it)

```
command@<Code>@<param echoed>[;late=1][;err=<technical reason>]@OK: <neutral sentence>
command@<Code>@<param echoed>[;late=1][;err=<technical reason>]@Error: <her words>
```

* `OK:` / `Error:` is the classifier. 0.5.4's `stripos($result, 'Error:') !== 0` still reads `OK: ...` as a
  success, so the marker is additive. A result with neither marker comes from a game script of 506 or older.
* `err=` appears **only on `Error:`**. Its value is the technical reason. `CleanForWire` removes
  `; | @ " \n`, and the value is cut at 160 characters. In practice it is the closed-list text
  (`no scene is running`, `unknown command`, `someone is watching` ...) or, for the escort, the full 506
  sentence (`Lisette will not do that now (hostile)`).
* `late=1`: a **second** funcret for a command that was already answered `OK:`. It carries the same code and
  the same cid. It is sent **without** `commandEndedForActor` (the command already ended) and **without** a
  corner note (the caller keeps its own 506 note). Only two places send it:
  `TickEscort` (her scene came back) and `TickAfterScene` (the requested position cannot follow).
* Both new keys are appended after the server's own keys. 0.5.4's `lrgParseKv` gives them to nobody.
  The server never sends `err=` or `late=` itself (grep of `lib/*.php`, `*.php` and PROTOCOL: 0 hits).
* CHIM side, checked read-only in the installed `processor/funcret.php`: our rows are
  `suppress_placeholder_infoaction` with the follow-up disabled, so field 2 is only trimmed and the request
  `terminate()`s. A late funcret therefore costs CHIM nothing. The Escort funcret is ended by the glue server
  before the MAIN lock, as before.

**Examples (exact shapes the code produces):**

| case | funcret |
|---|---|
| "faster" in a scene | `command@ExtCmdLRG_SceneControl@ok=1;cid=c7;npc=Lisette;do=faster@OK: The pace changes.` |
| "stop" with no scene | `command@ExtCmdLRG_SceneControl@ok=1;cid=c8;npc=Lisette;do=stop;err=no scene is running@Error: we are not doing anything right now` |
| a position that cannot be reached, warp off | `...;do=goto;scene=X;err=that position cannot be reached from here@Error: I cannot get into that from here` |
| "follow me", scene of a journal quest | `command@ExtCmdLRG_Escort@ok=1;cid=c9;npc=Lisette;do=follow;safe=...;err=Lisette is in the middle of something she cannot leave (a quest in your journal)@Error: Lisette is in the middle of something for your quest and cannot leave` |
| her song came back twice | `command@ExtCmdLRG_Escort@ok=1;cid=c9;npc=Lisette;do=follow;safe=...;late=1;err=she went back to her scene (BardSongs) and stays - it keeps starting again@Error: Lisette had to go back to her performance` |

Log line (Papyrus.0.log and `GAME ...` in lorerim_glue.log):
`result ExtCmdLRG_SceneControl: Error: we are not doing anything right now [why: no scene is running]`,
and `result ExtCmdLRG_Escort (late): Error: ...` for a late one. The corner note is unchanged:
`LoreRim Glue: no scene is running`.

### 1.1 What the SERVER must change (server lane; the game depends on none of it)

The deployed 0.5.4 server matches three technical reasons **by their text**. With 507 that text is now her words:

| server site | 0.5.4 test | with 507 | fix |
|---|---|---|---|
| `lrgRecordResult` (lrg_actions.php ~788): close the scene row | `strcasecmp($reason, 'no scene is running')` | never matches, so the G4 early close is lost (the game's `ev=end` and the session tag still close the row) | `$tech = (string) ($kv['err'] ?? $reason);` and test `$tech` |
| `lrgResultSaysNoScene` (~1360) | `last_result.reason == 'no scene is running'` | never matches | store `'err' => $tech` in `last_result` and test `err ?? reason` |
| escort old-game test (~767) | `strcasecmp($reason, 'unknown command')` | 507 knows Escort, and 505 sends no `err=` | `$tech` as above covers both |
| paid refusal (`stripos(fail, 'gold')`) | any reason containing `gold` | **still works**: both of her lines keep the word (`you do not have that much gold`, `you do not have enough gold`) | none |

Classification: `OK:` = quiet success. `Error:` = voice `substr($result, 7)` (already her words). No marker =
a game of 506 or older (today's rules). `late=1` = the command ended earlier; this is a new failure of it.
The failure is still told the same way (0.5.4 already records it as `last_result` with `told=false` and
tells her once on the next turn: "What Lisette last tried did not happen (Lisette had to go back to her performance)").

**Mixed versions:** script 507 with server 0.5.4 works. Her next-turn "did not happen (...)" line now carries
her words. The only loss is the early no-scene close above. Script 506 with a new server works too: no marker
and no `err=`, so the server falls back to the reason text. **Ship both halves together anyway.**

**Proposed PROTOCOL 1.6 wording** (the server lane owns the file, so it is not edited here): "Success =
result starts `OK: ` (script 507+), failure = `Error: ` + a short reason in her own words. The technical
reason from the closed list below travels as `err=<reason>` appended to the echoed param (507+); a
`late=1` funcret re-reports a command already answered `OK:` that came undone (no commandEndedForActor).
Machine matches use `err` (fallback: the reason text, for scripts <= 506)."

---

## 2. Her words: `LRG_Main.SayReason(tech, command)`

The list covers every technical reason the game can produce. `verify507.py` extracts them from the sources:
`"Error: ..."` literals, the returns of `Pair/Privacy/Start/NavigateBlockedReason`, `OpenBlockedReason`,
`PrepareWeapons` and `SettleWeapons`, every `why=` / `err=` / `prepWhy=` in LRG_OStim and LRG_Main, and the
`DeferCommand` / `DropDeferred` / `DropPendingVerb` / `FailOpen` arguments. That gives 46 reasons, and all of
them are mapped. The fallback is used only for the reasons that have no meaning inside the game.

| technical reason (log, corner, `err=`) | what she is told |
|---|---|
| no scene is running | we are not doing anything right now |
| a scene is already running | not now, something is already going on (dialogue: not now, I am busy with something) |
| a scene is just starting / the scene is still starting | give me a moment, we are only just starting |
| still moving into the previous position / in the middle of a transition | give me a moment, I am still moving |
| a scene with someone else is running | you are busy with someone else right now |
| already there | we are already doing that |
| no position was named | you did not say what you want |
| that position does not exist for the two of you | that is not something the two of us can do |
| that is not a position | that is not something we can do |
| that position cannot be reached from here / unreachable | I cannot get into that from here |
| not possible in this position | that does not work the way we are now |
| no suitable furniture nearby | there is nothing here we could use for that |
| the scene did not start / OStim rejected the actors | something got in the way, it did not happen |
| OStim does not accept these actors | that cannot happen between us right now |
| the weapons could not be put away | not while we still have weapons in our hands |
| combat | not in the middle of a fight |
| a quest scene is running | I am in the middle of something I cannot leave |
| too far apart | you are too far away |
| a child is nearby | not with a child nearby |
| someone is watching | not here, someone could see us |
| a companion is present | not with your companion right here |
| adults only | no, I will not do that |
| the player does not have that much gold | you do not have that much gold |
| not enough gold | you do not have enough gold |
| a conversation is in progress | not in the middle of a conversation (dialogue: you are already talking to someone) |
| they cannot talk right now | I cannot talk right now |
| the actor could not be found | I cannot do that right now (dialogue: I cannot talk right now) |
| her voice is not ready | give me a moment, I am not ready to answer |
| that is not on the table right now / that moment has passed | unchanged (already plain) |
| unknown scene request / unknown clothing request / unknown command | I do not know what you mean |
| not authorised by the server gate | I have not agreed to that (dialogue: I cannot do that right now) |
| the glue is still starting after the load - say it again in a moment | give me a moment, then ask me again |
| **no in-world reason:** the feature is switched off, OStim is not installed, not available, the three dry-run reasons, this part of the glue did not start this session | I cannot do that right now (the corner note keeps the real cause for the owner) |

Voice rules: in-scene reasons are in her first person ("I" / "we", "you" = the player), as in the examples
of the brief. Escort reasons use her name in the third person, because the same line is the owner's corner
note. No reason contains `; | @ "`. The check fails on any of those characters, and it also fails if she would
be told the raw technical text.

## 3. Every call site (script 507 line numbers)

In all ten `.pex` files, `verify507.py` finds 34 `OK:` sites, 81 `Error:` literal sites, 4 `ModDownWhy`
sites (always `Error: ...`), 11 `ReportError` / `ReportLateError` sites and 1 pass-through
(`LRG_Dialogue.ReportOnce(asResult)`). Every `ReportOnce` literal is `OK:` or `Error:` (source check). The
`parkedWhy`, `DropDeferred` and `DropPendingVerb` strings are traced back to an `"Error: "` constant in the
bytecode. **No site is unmarked.**

**LRG_OStim: CmdStart / BeginStartThread / Tick / thread start**

| line | site | goes out as |
|---|---|---|
| 1879 | CmdStart, `StartBlockedReason` | `Error:` + words of: OStim is not installed, feature off, a conversation is in progress, not authorised, a scene is already running, the actor could not be found, adults only, combat, a quest scene is running, too far apart, OStim does not accept these actors, a child is nearby, someone is watching, a companion is present, the player does not have that much gold, still moving (after the queue gives up) |
| 1910 | dry run | `Error: I cannot do that right now` |
| 1950 | OStim 'Remove Weapons at Start' is on | `Error: I cannot, not right now` (**explicit `ReportError`**; the corner note and the extra owner notification are unchanged) |
| 1977 | weapon preparation | `Error:` + weapons could not be put away / combat / the actor could not be found |
| 1993, 2050, 2103, 2143, 3932, 4245 | the scene did not start (no scene id, OStim refused, stopped while waiting, stop during build, 40 s deadline) | `Error: something got in the way, it did not happen` |
| 2033 / 2068 | partner gone / OThreadBuilder -1 | `Error: I cannot do that right now` / `Error: something got in the way, it did not happen` |
| 3924 | parked start overtaken by another thread | `Error: not now, something is already going on`, or the "did not start" words |
| 3937 | thread up | `OK: They draw close. The scene begins.` |
| 1505 | **new** `TickAfterScene`: `after=` is impossible (not "already there") | **late** `Error:` + her words (e.g. `I cannot get into that from here`) for the start's own cid |

**LRG_OStim: CmdControl / CmdClimax / CmdPullOut / CmdWindDown / CmdFurniture / CmdLead / queue**

| line | site | goes out as |
|---|---|---|
| 3362, 3398 | not authorised | `Error: I have not agreed to that` |
| 3369 | do=note | `OK: Noted.` |
| 3378, 3410 | stop | `OK: The scene ends.` |
| 3394 | any verb with no scene | `Error: we are not doing anything right now` |
| 3415 | not her scene | `Error: you are busy with someone else right now` |
| 3420 | adults only | `Error: no, I will not do that` |
| 3434 | a command parked for her line (queue full / expired) | `Error: give me a moment, I am still moving` |
| 3441 | dry run | `Error: I cannot do that right now` |
| 3455 | speed while the thread builds | `Error: give me a moment, we are only just starting` |
| 3474 / 3478 / 3482 | pace / hold / release | `OK: The pace changes.` / `OK: Holding back.` / `OK: No longer holding back.` |
| 3512 | goto refused (`NavigateBlockedReason`, nowarp, warp off) | `Error:` + no position was named / still moving / only just starting / already doing that / not something the two of us can do / not something we can do / **I cannot get into that from here** |
| 3530, 3565, 3785 | unknown verb / who | `Error: I do not know what you mean` |
| 3540 | goto done | `OK: Moving into a new position.` |
| 3571, 3575 | climax, partner not in the scene | `Error: I cannot do that right now` |
| 3588 | climax | `OK: A peak is reached.` |
| 3603 / 3618 | pullout while moving / no pullout transition | `Error: give me a moment, ...` / `Error: that does not work the way we are now` |
| 3624 / 3681 | pullout / winddown | `OK: They ease apart.` / `OK: Winding down.` |
| 3712 | furniture off in the MCM | `Error: I cannot do that right now` |
| 3719, 3750, 3765 | no free furniture | `Error: there is nothing here we could use for that` |
| 3744 | furniture refused (transient / wrong scene) | `Error:` + words as for goto |
| 3774 / 3817 | furniture / lead | `OK: Moving to the <furn>.` / `OK: Lead: <who>.` |
| 1352 / 1451 / 1545 | queued command dropped / unknown queued code / parked verb dropped | `Error:` + the words of its reason (no scene, still moving, just starting, the actor...) / `I do not know what you mean` |

**LRG_OStim: CmdClothing / PerformClothing**

| line | site | goes out as |
|---|---|---|
| 3117 / 3121 / 3125 | not authorised / unknown request / feature off | `Error: I have not agreed to that` / `Error: I do not know what you mean` / `Error: I cannot do that right now` |
| 3144 / 3148 | actor / adults | `Error: I cannot do that right now` / `Error: no, I will not do that` |
| 3157 / 3164 | start building / verb parked | `Error: give me a moment, we are only just starting` / `... I am still moving` |
| 3172 / 3182 | someone else's scene / the scene just ended | `Error: you are busy with someone else right now` / `Error: we are not doing anything right now` |
| 3201 | outside a scene: conversation / pair / privacy | `Error:` + `not in the middle of a conversation`, `not here, someone could see us`, `not with a child nearby` ... |
| 3207 | dry run | `Error: I cannot do that right now` |
| 3290 / 3321 | **nothing changed** (explicit `ReportError`, new `ClothingNothingSay`) | `Error: I have nothing left to take off` / `you have ...` / `neither of us has anything left to take off` (`... there` for a part); `... nothing to put back on` for dress. The technical reason stays `not possible in this position` |
| 3295-3299, 3326-3330 | done | `OK: <npc> undresses[, and so do you].` / `OK: You undress.` / `OK: ... get(s) dressed again.` |

**LRG_Main: HandleCommand and Escort**

| line | site | goes out as |
|---|---|---|
| 1508 | glue switched off | `Error: I cannot do that right now` |
| 1541 / 1547 / 1553 / 1562 | module missing (`ModDownWhy`) | `Error: give me a moment, then ask me again` (still booting), otherwise `Error: I cannot do that right now` |
| 1569 | unknown code | `Error: I do not know what you mean` |
| 1631 | no such agent | `Error: <name> is not here` |
| 1637 | unknown do= | `Error: <name> does not know what you want her to do` |
| 1642 | dry run | `Error: <name> cannot do that right now` |
| 1653 | `EscortRefuseReason` via `EscortSayRefusal` | `<name> is not here` · `<name> already travels with you, ask her the way you always do` (recruited / SFF) · `<name> is hostile to you` · `<name> cannot, not in the middle of a fight` · `<name> is here to arrest you` · `<name> is busy with you right now` (our scene) · otherwise `<name> cannot do that right now` |
| 1737 | a scene she may not leave, via `EscortSayBusy` | journal quest: `<name> is in the middle of something for your quest and cannot leave` · deny list: `<name> is in the middle of something important and cannot leave` · a `BardSongs*` scene (incl. MCM `bEscortStopScene` off): `<name> is in the middle of a performance she cannot leave` · anything else: `<name> is in the middle of something she cannot leave` |
| 1772 | **new:** CHIM's follow could not be put on | `Error: <name> cannot come with you right now` (506 said "comes with you") |
| 1777 / 1792 / 1816 / 1837 | done | `OK: <name> comes with you.` / `OK: <name> stops what she was doing and comes with you.` / `OK: <name> waits here.` / `OK: <name> goes back to her own business.` |
| 2159 | **new:** `TickEscort` gives up | **late** `Error: <name> had to go back to her performance` (bard) / `... to what she was doing`. The corner note is the unchanged 506 line. New fields `escortCall` / `escortParam` hold the command's addressee and parameter; they are cleared on every load and after use |

**LRG_Dialogue: CmdSelectTopic / CmdAward / the driver**

`OK:` sites: `Noted.` x4 (release, noop, open while live, award), `The menu is shown.` x5, `The matter is raised.`,
`The conversation is left.`

`Error:` sites, in her words:
* not available / feature off / dry run -> `I cannot do that right now`;
* not authorised -> `I cannot do that right now`;
* they cannot talk / actor not found -> `I cannot talk right now`;
* another speaker's menu -> `you are already talking to someone`;
* `OpenBlockedReason` -> the table above (combat, a quest scene, busy, too far...);
* not on the table / that moment has passed -> unchanged;
* her voice is not ready -> `give me a moment, I am not ready to answer`;
* not enough gold -> `you do not have enough gold`;
* unknown verb -> `I do not know what you mean`.

The Phase-2 server ignores SelectTopic funcrets today (`lrgRecordResult` returns early). The words are
there if the server lane decides to voice them. The dialogue's own `ev=result;why=` (HandBack words such as
`refused` / `goodbye`) is a separate channel. It is not a funcret, and it is untouched.

## 4. Files changed

| file | change |
|---|---|
| `game\LoreRimGlue\Source\Scripts\LRG_Main.psc` | 507. `ReportResult` (markers, translation), new `ReportError`, `ReportLateError`, `SendFuncret` (the only funcret writer), `SayReason`, `EscortSayRefusal`, `EscortSayBusy`. Escort: all results marked, a follow that failed is now an error, late funcret from `TickEscort`, fields `escortCall` / `escortParam` (reset in `Maintenance`). Header note |
| `game\LoreRimGlue\Source\Scripts\LRG_OStim.psc` | every success `OK: `. The Remove-Weapons refusal and the two nothing-changed clothing refusals go through `ReportError`; new `ClothingNothingSay`; late funcret in `TickAfterScene` |
| `game\LoreRimGlue\Source\Scripts\LRG_Dialogue.psc` | every success `OK: ` (11 sites); the `ReportOnce` comment |
| `game\LoreRimGlue\Scripts\*.pex` | all ten recompiled |

Unchanged: `LRG.psc`, `LRG_DlgProbe`, `LRG_DlgUI`, `LRG_Followers`, `LRG_MCM`, `LRG_PlayerAlias`, `LRG_Profile`,
`MCM\Config\LoreRimGlue\config.json` (`F3D03D4E30FD4C61`), `settings.ini` (`330718AA09BA0E78`; no new key, so
`iSettingsVersion` stays 506, which is only tested > 0), the ESP, the SEQ, `tools\`, the server, and PROTOCOL.md.
`glue\README.md` still says 506 (outside this lane).

## 5. Verification

* `tools\compile.ps1`: `compiled: [all 10] ... OK - ... 0 errors, 0 warnings` (21 auto-stubs, as in 506).
* `%TEMP%\lrg_pt14g\verify507.py` (bytecode via the pt12 `vpex` reader plus a source scan): **RESULT: OK**
  * `CurrentVersion` 507;
  * longest docstring is 290 characters (pre-existing, `LRG_Dialogue.ServiceMenuOpen`); the new ones are all under 150;
  * the funcret writer and `commandEndedForActor` exist only in `LRG_Main.SendFuncret`;
  * every result site is marked (section 3);
  * all 46 technical reasons are mapped; none is told as raw technical text; no reply contains `; | @ "`.
* Offline suite on a staged copy of the current glue folder (`%TEMP%\lrg_test\game14`, WSL
  `g14_tests.sh`; index data copied from the pt13 stage): test_gates 339/0 · test_intent 255/0 ·
  test_phrases 31/0 · test_scene_index ALL PASSED · test_dialogue 174/0 · test_prompt_index 67/0 ·
  test_mcm_wiring 3/0 · test_services 35/0 · test_latency 26/0 · `flows --strict` **76 scenarios, 0 failed,
  0 pending, 1252 checks, 0 warnings**. (The suite exercises the server. It confirms that nothing in the
  shared tree broke. It cannot run Papyrus.)

## 6. For the installer (not done here)

Hand-copy into `F:\Modlists\LoreRim\mods\LoreRim Glue` and verify with SHA-256. **STOP if SkyrimSE runs.**
No profile file is involved.
* `Scripts\LRG*.pex`: **all ten together.** `LRG_OStim` and `LRG_Dialogue` call the new `LRG_Main.ReportError`
  / `ReportLateError` / `SayReason`, so a mixed 506/507 set would fail on those calls.
* `Source\Scripts\LRG*.psc` (only Main, OStim and Dialogue changed; copying all ten is fine).
* The MCM files are unchanged and do not need copying.

| file | SHA-256 (first 16 hex; hash the full files) |
|---|---|
| `LRG_Main.pex` | `627D67B5B78C802F` |
| `LRG_OStim.pex` | `06934065BB408118` |
| `LRG_Dialogue.pex` | `8E56C0047B483C9B` |
| `LRG_Profile.pex` | `D19CE1EAF8132097` |
| `LRG_Followers.pex` | `06D90741607023D6` |
| `LRG.pex` | `FF54CC80FF1F2EFE` |
| `LRG_DlgProbe.pex` | `32485CA96FBF7813` |
| `LRG_DlgUI.pex` | `8726FE53C5CF08DF` |
| `LRG_MCM.pex` | `6F2FB84295EA47CB` |
| `LRG_PlayerAlias.pex` | `0DEEAED610592A62` |
| `LRG_Main.psc` / `LRG_OStim.psc` / `LRG_Dialogue.psc` | `1E81E1E56EF19AA5` / `3427B781AD215DA5` / `561934784015E2DD` |

On load the corner should say `LoreRim Glue v507 loaded - session NNNN`.

**Next Papyrus.0.log:** `result <Code>: OK: ...` for successes and `result <Code>: Error: <her words> [why: <technical>]`
for refusals, plus `result ExtCmdLRG_Escort (late): ...` if a bard's song restarts. A line
`unmarked result of ... sent as a success` must never appear; if it does, a caller was missed.

## 7. Open points

* **Server lane (required for the full effect):** voice `Error:` funcrets in the same exchange (0.5.4
  still tells her only on her next turn), and switch the three text matches to `err=` (section 1.1).
* **`late=1` is new behaviour.** 0.5.4 records it and tells her once on her next turn. A server that voices
  errors at once will make her speak it seconds after the command, and that is the intent (rule 11(c)).
  An `after=` that gives up only on its **timeout** is still not voiced (log line only): the most common
  reason is that the player changed course (another goto, a wind-down), and saying "I could not" would then be wrong.
* **CHIM's own actions** (FollowPlayer with no stage scene, ComeCloser, MoveTo, TravelTo) still report
  nothing from the game. The glue cannot observe their outcome without new watchers. Escort covers
  follow, wait and release on a stage.
* The out-of-world reasons (feature off, OStim missing, dry run, module not started) deliberately become
  `I cannot do that right now`. The owner learns the real cause from the corner note and the log.
