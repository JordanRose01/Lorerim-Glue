# pt9 - FIX PASS + FINAL RELEASE, LoreRim Glue v0.5.1

Run 2026-09-22 ~08:15 local (12:15 UTC). Inputs: the defect list handed to this lane, the two build
reports (`pt9-followers-build.md`, `pt9-latency-reconcile.md`), the first release report
(`pt9-ship-v051.md`), `glue\OWNER_ADDENDA.md` items 8-10.

**Verdict: SHIPPED.** All four critical/major code and docs defects are fixed, all eight minors are
fixed, the compiler is clean, every offline test and all 74 flow scenarios pass, the server is
deployed and verified on the live copy, and the game-side files are installed by SHA-256.

**Nothing was tested in Skyrim.** This lane does not play the game. The follower work in particular
has still never had a real follower present in any playtest.

The shipped state for menuless questing is unchanged and still inert: `bMenuless = 0`,
`bDlgDryRun = 1`. The follower *facts*, the `<companion_status>` block, the hide rule, the ghost
repair and the conversation-hold skip are live at once - and after this pass they actually work,
which they did not before (defect 1).

---

## 1. The version number did not move

Everything below ships as **0.5.1**: `manifest.json` `0.5.1`, `LRG_VERSION` `0.5.1`,
`LRG_Main.CurrentVersion` `501`. No defect asked for a bump, and bumping would have touched the
Maintenance upgrade path, the MCM, `meta.ini` and four documents for no gain. New comments in the
source are tagged **`[0.5.1 fix pass]`** so a later round can tell this pass from the 07:30 build.

---

## 2. Critical and major defects

### C1 - the whole SFF integration was dead (game). FIXED

`LRG_Followers.psc` looked SFF's globals up by `SFFCanRecruitMore` / `SFFCurrentFollowerCount`.
Re-verified independently before changing anything: a record-level parse of the installed
`F:\Modlists\LoreRim\mods\Simple Follower Framework\Simple Follower Framework.esp` (14,130 bytes,
record types `TES4 1, GLOB 3, QUST 1, PACK 8, PERK 1, SPEL 1, MGEF 1`) gives

```
GLOB 0x05000001  SFF_CanRecruitMore
GLOB 0x05000002  SFF_CurrentFollowerCount
GLOB 0x05000805  SFF_FollowerSandbox
```

The underscore-less spellings do occur in the file's byte stream, but only as Papyrus property
names inside the VMAD - not as an `EDID` of any record. `PO3_SKSEFunctions.GetFormFromEditorID`
therefore returned `None`, `SffPresent()` was **false on every call for the whole of v0.5.1**, and
with it: mode-0 `RepairGhost` could never promote and always fell to the undo path (which is
defect C2), `slot:` / `cap:` / `prim:` were never sent, `fw` was never `sff`, and the server's
`hide_when_cap_full` rule plus the slot lines of `<companion_status>` were dead controls.

Fixed: `LRG_Followers.psc` lines 55/59 now read `SFF_CanRecruitMore` / `SFF_CurrentFollowerCount`,
with the reason written next to them so it cannot be "tidied" back. Verified in the shipped
artifact - the installed `LRG_Followers.pex` contains both underscored names and no occurrence of
the old spelling.

### C2 - the undo path permanently destroyed the NPC's own recruit line (game). FIXED

`RepairGhost`'s undo removed `PotentialFollowerFaction` as well as `CurrentFollowerFaction`. That
faction sits on the **base record** of every recruitable NPC and the vanilla recruit INFO is
conditioned on membership in it, so the "undo" silently deleted that NPC's own *"Follow me. I need
your help."* entry for the rest of the save - unrecoverable, and strictly more than the edit it
claimed to undo (CHIM's `AIAgentNpcUtil` only ever `AddToFaction` / `SetFactionRank(PFF, 0)`).
With C1 in place the undo was the **only** outcome of the shipped default repair mode, so every
ghost repair did this.

Fixed: the `Faction pf = FacPotentialFollower() ... RemoveFromFaction(pf)` block is gone.
`RemoveFromFaction` now occurs exactly **once** in the compiled `LRG_Followers.pex` (checked in the
installed artifact), and it is the `CurrentFollowerFaction` removal. Removing that one faction fully
clears the ghost: `IsGhost()`, CHIM's own `isInFaction(VanillaCurrentFollowerFaction)` tests and
SFF's `GetDialogueFollowerTarget` all key off it. The rule for a future round - if CHIM's rank write
ever has to be reverted, `SetFactionRank(pf, 0)`, never `RemoveFromFaction` - is written into the
function.

### M1 - `FacDismissed()` never resolved (game). FIXED

The lookup asked for EditorID `DismissedFollower`. Verified against the installed
`F:\Modlists\LoreRim\Stock Game\Data\Skyrim.esm` (249,753,412 bytes): the token
`DismissedFollowerFaction\0` occurs once, `DismissedFollower\0` **zero** times, alongside
`CurrentFollowerFaction\0` and `PotentialFollowerFaction\0` once each. So the dismissed-follower
exclusion - one of the two guards the file documents as what keeps a dismissed custom follower out
of the repair - could never fire, and the only surviving guard was Inigo's `WaitingForPlayer == -1`.

Fixed by FormID rather than by a corrected string: `Game.GetForm(0x0005C84C) as Faction`, which
matches the two factions either side of it in the same file and cannot be broken by a renaming mod.

### M2 - "the same number the real recruit entry is conditioned on" was false (both). FIXED

The claim appeared in five places. It is wrong: the vanilla recruit INFO is gated on
`PlayerFollowerCount == 0`, and SFF sets that global to 1 as soon as it has any follower, while
`SFF_CanRecruitMore` stays 1 until every slot is used. With C1 fixed, `cap` is now really sent, so
the claim mattered.

No behaviour was changed, and that is deliberate: `cap:0` is the **looser** of the two tests, so
`hide_when_cap_full` can only ever hide an action that genuinely cannot work - it never hides one
that would have worked. What was wrong was the claim, so the claim is what was fixed, in:
`lib/lrg_core.php` (the `lrgFollowerBlock` comment and the `lrgFollowerPolicy` docblock),
`config/lrg_config.default.json` `_followers_readme`, `PROTOCOL.md` 10.19, and
`pt9-followers-build.md` 3.1. The MCM help text was checked and did **not** carry the claim, so it
was left alone. Each corrected place now states the real difference, including which direction the
looseness runs in, so the next round does not have to re-derive it.

### M3 - `PLAYTEST9_NOTES.md` was never updated for 0.5.1 (docs). FIXED

The owner's only playtest document still said "v0.5.0" in its title, had zero mentions of
followers, companions or SFF, omitted the new MCM Followers page, omitted the round's own "the one
that matters" owner action, and carried 0.5.0-era verification numbers throughout sections 8 and 9.

Fixed:

* title now reads **v0.5.1**, with a line at the top pointing at the new subsection;
* **new section 1(e), "v0.5.1 - followers"**: the two CHIM web-UI Actions switches as a table
  (`MakeFollower` / `Join_<name>_Party` and `Follow` OFF, `FollowPlayer` stays ON) with the reason
  each one is dangerous here, the five controls of the new MCM Followers page in plain words, the
  **K2** and **force default voice** warnings, SFF's one-slot ini reality, and the plain statement
  that none of it has been seen in game;
* section 6 gained the line that says which half of the follower work is behind `bMenuless` and
  which half is live now - the document previously implied the whole round was inert;
* **section 8** numbers refreshed with a dated note saying they were stale: 10 scripts (not 9),
  `php -l` 74 files (not 73), gates **319** (was 296), dialogue **132** (112), prompt index 67 +
  112, MCM wiring **113 controls** (108), services **35** (24), latency 26, flows **74 scenarios /
  1161 checks** (73 / 1127), deploy **28 anchors** at 08:15 (23 at 06:30), 24 files compared by hash
  (22);
* **section 9 item 1** rewritten: 24 compared / 13 copied / 0 mismatches, naming the three `.psc`
  that changed, and `meta.ini` corrected to **`0.1.0`** (not `0.5.0`).

---

## 3. Minors

| # | what | done |
|---|---|---|
| m1 | recruit verb's topic glob missed the commonest recruit topic | `DialogueFavorGenericFollowBranchTopic` added to `services.follower.verbs.recruit.topics` **and** to `families` - named exactly, **not** as `DialogueFavorGeneric*`: that quest also carries ordinary favour topics and a family glob over all of them would let a two-token entry text resolve as a follower verb. The reason is in the file |
| m2 | three factual inaccuracies in shipped reports | (a) "SetFollower waits inside PrepareFollowerActor" corrected in `pt9-followers-build.md` 2.2, `LRG_Followers.psc` and `LRG_Main.psc`; (b) `meta.ini` says `0.1.0`, corrected in 7.4; (c) the stale "NOT DEPLOYED" - see m4 |
| m3 | `RepairGhost` reported an edit it may not have made; single-slot repair queue | `RepairGhost` returns **0**, not 2, when the faction could not be resolved. `folFixActor` / `folSeenActor` became **four-slot** arrays with dedup; `FollowerSweep` no longer stops at the first ghost; a fifth ghost is logged and left to its own next snapshot. The `fv=` row of the build report's table corrected to what the code does (`if m`, i.e. whenever `LRG_Main` exists) |
| m4 | `pt9-latency-reconcile.md` section 7 gave the owner a decision that was already made | first two paragraphs replaced with the deployed state and the byte-comparison result; the "decide whether to deploy" owner item is gone |
| m5 | act-list size "1,540 characters" wrong in a shipped config and a source comment | **measured** on the exact fixture: `tools/test_latency_prompt.php --dump=scene` gives a 78-char header line + a 1,282-char act list + 2 newlines = **1,362**, which is also the `-1,362` delta the same reconciliation quotes. Corrected in `lib/lrg_actions.php:2808` and `config/lrg_config.default.json:490`, and in `pt9-latency-fix.md`'s struck-through row |
| m6 | `lrgFollowerBlock()` truncated the closing tag | the cap now applies to the **body**; whole lines are dropped from the end until it fits and the tags are re-appended. Two new checks in `test_gates` section 34 |
| m7 | MCM control count misreported | `113` in `pt9-ship-v051.md` section 1 and `PLAYTEST9_NOTES.md` section 8 |
| m8 | two uncached `lrg_npc_state` SELECTs per request | `lrgGetNpcState()` memoised per request; `lrgStoreNpcState()` drops the memo through the new `lrgForgetNpcState()`. One new check in `test_gates` asserts both halves |
| m9 | `PROTOCOL.md` header not carried forward | "sections 10.13 - **10.19**", with the follower keys named |

### Two of these deserve a note

**m6, what it does when it really does not fit.** With `max_chars` 520 the block only overflows on
extreme names. Measured on the worst case in the new test - npc `Aela the Huntress of Jorrvaskr`,
player `Thane Ysolda-Slayer of Alduin`, a full party - the block comes out at **504 characters,
correctly closed**, and the line that gets dropped is the last one, *"X cannot take anyone else with
him at the moment."* That is the least load-bearing line of the five: the slot count line above it
(*"He has 4 of 4 companions with him"*) already carries the same fact, and the line the docblock
calls the point of the whole block - the commands are her dialogue, not the model's to narrate -
survives. Ordinary names are nowhere near the cap (the `Lydia` / `Jordan` case is 437).

**m8, why a memo is safe here.** The snapshot row cannot change inside one request: the only writer
is `lrgStoreNpcState()`, and it now invalidates that NPC's entry. The memo stores the decoded
payload and `updated_at` and **recomputes `_age` on every call**, so a test that moves
`LRG_TEST_NOW` between two reads still sees the age it expects. `fxReset()` in the flow adapter
drops the memo with the rest of the world. The `!$db` path memoises nothing (it is already free).
Two new `test_gates` checks assert the memo returns the same facts twice and is dropped the moment a
new snapshot is stored.

---

## 4. Gates

| gate | result |
|---|---|
| `compile.ps1` | **OK** - 2 rounds, 22 auto-stubs, **10 `.pex`**, compiler reported **0 errors, 0 warnings**; the 500-char `.pex` string guard and the deploy denylist both passed |
| `php -l` | **74 files, 0 errors** (project) and **14 files, 0 errors** over the deployed tree |
| `config.json` / `lrg_config.default.json` / `manifest.json` | valid JSON; `manifest` `0.5.1` |

| test | result |
|---|---|
| `test_gates` | **319 passed, 0 failed** (316 + 3 new: the memo, and two on the `<companion_status>` cap) |
| `test_intent` | **190 passed, 0 failed** |
| `test_phrases` | **14 passed, 0 failed** (hit rate 91.9 %, floor 82 %) |
| `test_scene_index` | **ALL CHECKS PASSED** (607 scenes) |
| `test_dialogue` | **132 passed, 0 failed** |
| `test_prompt_index` | **67 passed, 0 failed** |
| `test_prompt_index --db` | **112 passed, 0 failed** |
| `test_mcm_wiring` | **4 passed, 0 failed**; the tool prints **113 controls** |
| `test_services` | **35 passed, 0 failed** |
| `test_latency` | **26 passed, 0 failed** |
| `flows\run_flows.php --strict` | **74 scenarios: 74 passed, 0 FAILED, 0 pending; 1161 checks, 0 warnings. RESULT: OK** |

Nothing was skipped, relaxed or re-run to get a pass. The three tests added by this pass are the
only count changes. The two standing index notes are unchanged and neither is a failure: one
engine-only plugin (`Sanguine Symphony.esp`), and nine PNAM-inverted topics in this load order -
upstream data, reported, changed nothing.

---

## 5. Server deploy

`tools\deploy_server.ps1`, no arguments. **Postgres was already up** (`15/main (port 5432): online`,
`pg_isready` accepting connections), so nothing had to be started.

```
anchor pre-flight: 28 anchors, one match each
scene index: rebuilt, 607 scenes, 5.5s
schema ensured: lrg_index
loaded 37561 prompts, 5718 layers into schema lrg_index
prompt index v1  source=db  schema=lrg_index  rows=37561  layers=5718
                 file_rows=37561  coverage_rows=432  built=2026-09-22T12:15:23+00:00
check kinds found in this load order: bribe, intimidate, persuade
index pre-flight: lrg_index.lrg_prompt has 37561 rows, service catalog present
deployed to /var/www/html/HerikaServer/ext/lorerim_glue
```

Read back off the **live** copy afterwards, not off the project tree:

* `manifest.json` → `0.5.1`; `lib/lrg_core.php:16` → `define('LRG_VERSION', '0.5.1')`.
* `lrg_index.lrg_prompt` = **37,561** rows, `lrg_index.lrg_prompt_layer` = **5,718**. Warm.
* every fix present: `SFF_CanRecruitMore` in three places in `lrg_core.php`,
  `DialogueFavorGenericFollowBranchTopic` in `lrg_dialogue.php` (topics **and** families),
  `1,362` in `lrg_actions.php` and the default config, `lrgForgetNpcState` in `lrg_core.php`, and
  the body-capped `<companion_status>`.
* `php -l` over all 14 live `.php` files: **0 errors**.
* **No plugin errors.** `log/lorerim_glue.log` has no `fatal`, `parse error` or `uncaught` line;
  the only apache line for the glue is a routine one from 21 Sep.
* A whole-tree byte comparison of the live copy against the project afterwards: **26 `.php` /
  `.json` / `.sql` files, 0 code differences, 0 project-only files.** The only live-only file is
  `config/lrg_config.json` (the owner's overrides), and the only other differences are the three
  **generated** artifacts under `data/` (`scene_index.json`, `scene_index.meta.json`,
  `service_catalog.json`), which the deploy builds on the server and which are deliberately not
  staged.

The latency lane's two files stay live and byte-identical, as `pt9-ship-v051.md` section 2 recorded
- that is now also what `pt9-latency-reconcile.md` says (m4).

---

## 6. Game-side install

`tools\install_mo2.ps1` was run first, as the INSTALL RULE requires. It **refused**:

```
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

**Skyrim is not running** - no `SkyrimSE` and no `skse64_loader` process; the only match is
`ModOrganizer` (PID 42744). So the rule's fallback applied: our own files only, copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue` and **verified by SHA-256 after the copy**.

`LoreRimGlue.esp`, `Seq\*`, `Scripts\LRG*.pex`, `Source\Scripts\LRG*.psc`,
`MCM\Config\LoreRimGlue\*` - **24 files compared, 13 copied, 11 already byte-identical,
0 hash mismatches**:

* all **10 `.pex`** (every script is rebuilt by a compile, so all ten differ even where the source
  did not - the same Papyrus non-determinism `pt9-ship-v051.md` documented);
* the **3 `.psc`** this pass changed: `LRG_Followers.psc`, `LRG_Main.psc`, `LRG_Profile.psc`;
* already identical: the ESP, the seq file, the other seven `.psc`, `config.json`, `settings.ini`.

A full re-hash of all 24 afterwards: **0 differing**. No profile file was read or written, nothing
outside the glue's own mod folder was touched, and the three `.psc.bak-docstrings` files in the
project were **not** copied (the `LRG*.psc` filter does not match them, and the mod folder contains
no `.bak` file at all).

Two things confirmed inside the installed `Scripts\LRG_Followers.pex` itself, because these are the
two fixes a wrong build would hide: it contains `SFF_CanRecruitMore` and `SFF_CurrentFollowerCount`,
contains **no** underscore-less spelling, and contains exactly **one** `RemoveFromFaction`.

`meta.ini` still reads `version=0.1.0`. `install_mo2.ps1` is the only thing that writes it and it
could not run; MO2's left-pane version is cosmetic and nothing depends on it.

---

## 7. What this pass did not do

* **Nothing was tested in Skyrim.** Every statement above is offline verification, live-server
  verification, or a read of a shipped artifact. The eight follower playtest items (P-F1..P-F8 in
  `pt9-followers-build.md` section 8) are all still `[U]`, and P-F2 - the scenario that produced
  defect C2 - is the one to run first now that the undo no longer destroys the recruit line.
* **`cap` has never been seen on the wire.** It could not be, until C1 was fixed. The first real
  `slot:` / `cap:` / `prim:` values will come out of the owner's next evening, and with them the
  first exercise of `hide_when_cap_full` and of the promote branch of `RepairGhost`.
* **No CHIM, OStim, LoreRim or HerikaServer core file was modified**, no Nemesis and no LOOT was
  run, and no C++ tooling was used.
* **The two CHIM Actions switches are still the owner's**, in the web UI on port 8081 (start the
  launcher first - `apache2` is down while it is closed, which does not affect the deploy). They are
  now in `PLAYTEST9_NOTES.md` section 1(e) where he will actually see them.
* **`meta.ini`, the scratch `lrg_t` database and the three `.psc.bak-docstrings` files** are
  unchanged carry-forwards from `pt9-ship-v051.md` section 4. None blocks anything.

---

## 8. Files changed by this pass

Game (`glue\game\LoreRimGlue\Source\Scripts`): `LRG_Followers.psc` (C1, C2, M1, m2a, m3),
`LRG_Main.psc` (m2a, m3), `LRG_Profile.psc` (m2/naming).

Server (`glue\server\lorerim_glue`): `lib/lrg_core.php` (M2, m6, m8), `lib/lrg_dialogue.php` (m1),
`lib/lrg_actions.php` (m5), `config/lrg_config.default.json` (M2, m5).

Tests: `tools/test_gates.php` (+3 checks), `tools/flows/adapter.php` (memo reset in `fxReset`).

Docs: `glue/PROTOCOL.md` (m9, M2, C1, M1), `PLAYTEST9_NOTES.md` (M3),
`research/pt9-followers-build.md` (C1, C2, M1, M2, m1, m2a, m2b, m3),
`research/pt9-latency-reconcile.md` (m4), `research/pt9-latency-fix.md` (m5),
`research/pt9-ship-v051.md` (m7).
