# PT7 Relationship Forensics (v0.3.1 round)

Read-only investigation. No database writes were made. Every claim below is backed by a
file:line or a log/DB line quoted verbatim.

---

## 0. CLOCK KEY - read this before any other evidence

Four different clocks are in play on this box. Getting this wrong inverts the whole story,
so it is pinned down first.

| Source | Timezone | Proof |
|---|---|---|
| WSL distro `DwemerAI4Skyrim3` system clock | `America/New_York` (EDT, UTC-4) = **owner local** | `date` -> `Mon Sep 21 18:03:59 EDT 2026`; `/etc/timezone` -> `America/New_York` |
| `/var/log/apache2/error.log` bracket prefix | owner local (EDT) | `[Mon Sep 21 17:53:22 ...] POST /ui/core/npc_master.php` matches `other_vhosts_access.log` `[21/Sep/2026:17:53:22 -0400]` |
| PHP under Apache (`date()`, chim.log `[REL]`) | `Europe/Madrid` (UTC+2) = local **+6h** | `[Mon Sep 21 17:25:28 ...] [NPC RESTORE] using gamets: 6089460.. 2026-09-21 23:25:28`; chim.log `[REL]` lines carry `+02:00` |
| PHP CLI / sub-request (chim.log `[REL-LLM]`, `relationship_worker.log`) | UTC = local **+4h** | `php -r date_default_timezone_get()` -> `UTC`; worker log `[2026-09-21 22:06:13]` at local 18:06 |
| Postgres session `TimeZone` | `Europe/Madrid` | `SHOW timezone` |
| **`core_npc_master_history.created`** | **UTC -> owner local = `created - 4h`** | see below |

`core_npc_master_history.created` is `timestamp without time zone DEFAULT now()`, but
`NpcMaster::backupNpcById` overrides it with PHP's `date('Y-m-d H:i:s')`
(`lib/core/npc_master.class.php:1352`), and `backupAllNpcs` calls
`date_default_timezone_set('UTC')` process-wide (`:1370`). Empirically the stored values are
UTC. Three independent second-exact anchors (apache prefix = local, on the left; `created` on
the right):

- apache `17:53:02` `[REL] Timeline snapshot for npc_id 2209` <-> `history_id 285 created 21:53:02`
- apache `10:43:05` `[REL] Timeline snapshot for npc_id 2209` <-> `history_id 208/209 created 14:43:05`
- apache `06:01:41` `[REL] Timeline snapshot for npc_id 2209` <-> `history_id 106/107 created 10:01:41`

**All times in the rest of this report are owner local (EDT).**

Lisette is `core_npc_master.id = 2209`, `refid 000198A2`, and there is exactly one row for her
(no duplicate / drifted name row).

---

## 1. ROOT CAUSE OF F5

### 1a. HEADLINE CORRECTION - the glue has NEVER read CHIM's affinity

The `aff=` number in every `gate npc=...` line the glue has ever written does **not** come from
CHIM. It is the sum of the snapshot bonuses only.

**Proof (direct, no inference):**

- glue log `17:27:08`, `17:27:30`, `17:28:04` - all three: `aff=0 interest=curious(-10)`
- chim.log `[2026-09-21T21:28:14+00:00]` (= local **17:28:14**)
  `[REL-LLM] Lisette -> Player: +3 (was 2, now 5)`
- No `restoreNPC` ran between 17:25:28 and 17:28:38 (apache `[NPC RESTORE]` lines).

So CHIM's stored affinity was **+2** during exactly the turns where the glue logged **0**.

**Mechanism.** `lrgAffinity()` — project copy `glue/server/lorerim_glue/lib/lrg_core.php:493-509`,
deployed copy `/var/www/html/HerikaServer/ext/lorerim_glue/lib/lrg_core.php:493-509`, byte-identical:

```php
$aff = 0;
if (class_exists('RelationshipManager')) {              // :497
    $rel = RelationshipManager::getPlayerRelationship($npc);
    if (is_array($rel) && isset($rel['aff'])) { $aff = (int) $rel['aff']; }
}
$aff += (int) ($state['rank'] ?? 0) * 6;                // :505
if (($state['courting'] ?? '0') === '1') { $aff += 10; } // :506  courting_bonus
if (($state['pspouse'] ?? '0') === '1') { $aff += 60; }  // :507
```

`RelationshipManager` is never loaded when that runs:

- The glue never requires it. `grep -rn "relationship_manager" glue/server/lorerim_glue/` -> no hit;
  the only mention is the `class_exists` guard itself.
- The only loader in a game turn is `ext/relationship_system/context_pre.php:32`.
- `main.php` hook order: `globals.php` `:54` -> `preprocessing.php` `:193` -> **`prerequest.php` `:1117`**
  -> `context_pre.php` `:2540` -> `prepostrequest.php` `:2944` -> `postrequest.php` `:2946`.
  `lrgPrepareTurn()` (which calls the gate, and writes the `turn`/`gate` log lines) runs from
  `lorerim_glue/prerequest.php:11` and from `lorerim_glue/functions.php:14` - both before `:2540`.
- Even at `:2540`, `requireFilesRecursively` (`lib/data_functions.php:7729-7747`) walks `scandir`
  order, so `lorerim_glue/context_pre.php` runs **before** `relationship_system/context_pre.php`,
  and `$GLOBALS['LRG_TURN']` is memoised from the earlier hook anyway.
- The two other `require_once relationship_manager.php` in `lib/eventlog_helper.php:129,206` sit
  inside `chimBuildRelationshipHistoryTimelineRows` / `chimBuildRelationshipChangeDetails`, which
  are UI timeline helpers and are not called in the turn path.

**Consequence.** `aff=10` throughout 17:05:54-17:19:45 and `aff=0` from 17:26:41 onward were
`affinity.courting_bonus` toggling, not CHIM. Arithmetic forces it: the only term worth exactly 10
is `courting_bonus` (`rank` is 6/point, spouse 60, and the interest-side bonus was 0 because
`pdb=0`, `pquests=2 < 25`, `pspeech=15 < 50` - the log confirms `interested(0)` = `10-10+0` and
`curious(-10)` = `0-10+0`, tavern_folk `min_affinity` 10). Across the whole glue log only two
affinity values were ever produced, 0 (29x) and 10 (9x).

Lisette's newest snapshot (`lrg_npc_state`, `updated_at 1790027097` = 17:44:57) has
`"courting": "0"`, `"rank": "0"`, `"pspouse": "0"`. `lrg_npc_state` is keyed by `npc_name` and keeps
only the newest row, so the earlier `courting="1"` cannot be re-read - this part is inference from
the arithmetic, everything else in 1a is direct proof.

**Related game-side bug worth fixing in the same round.**
`glue/game/LoreRimGlue/Source/Scripts/LRG_Profile.psc:94-96`:

```papyrus
bool Function IsCourting(Actor akActor) Global
	AssociationType courting = PO3_SKSEFunctions.GetFormFromEditorID("Courting") as AssociationType
	return courting && akActor.HasAssociation(courting)
```

`Actor.HasAssociation(akAssociation)` with the second argument omitted means "has this association
with **anyone**", not "with the player". Live snapshots show `courting="1"` for Jala, Vivienne Onis
and Sorex Vinius - none of them courting the player. Today that silently hands +10 affinity to
strangers. Fix: `akActor.HasAssociation(courting, akPlayer)`.

### 1b. CHIM's relationship data really was destroyed too - repeatedly, by `restoreNPC`

Independently of 1a, Lisette's relationship entry was rolled back or deleted on essentially every
save reload. This is the defect that matters for R1b/R1c, because it will bite the moment the glue
starts reading and writing CHIM affinity.

**The code path.**

1. `processor/comm.php:221` - on every game load: `$npcMaster->restoreNPC($gameRequest[2]);`
2. `NpcMaster::restoreNPC($timestamp)` - `lib/core/npc_master.class.php:1400`.
   - The main DELETE/re-INSERT (`:1413-1508`) is filtered by
     `COALESCE(c.lock_profile,0)=0`. **Lisette has `lock_profile = 1` (since at least 10:43 today)**,
     so her *profile* is protected.
   - `chimRelationshipRestoreQuery($timestamp)` (`:176-233`, called at `:1520`) has **no
     `lock_profile` filter**. Per NPC it picks
     `DISTINCT ON (h.npc_id)` the newest history row with
     `h.gamets_last_updated <= $timestamp OR IS NULL`, then does
     `COALESCE(c.extended_data,'{}') - 'relationships' - 'relationships_analyzed' - ... ||
      jsonb_strip_nulls(jsonb_build_object('relationships', restore.extended_data -> 'relationships', ...))`.
     **If the chosen history row has no `relationships` key, `-> 'relationships'` is SQL NULL,
     `jsonb_strip_nulls` drops the key, and the live relationships are deleted outright** - not
     preserved, not left alone.
   - `chimRelationshipFutureClearQuery($timestamp)` (`:236-274`, called at `:1577`) then strips the
     same keys from any NPC whose `gamets_last_updated > $timestamp` when no eligible history row
     carries relationships at all.
   - The only opt-out is `$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']` (`:1406-1410`). It is currently
     off (the "Preserved current relationship data" line never appears in today's logs).
3. `chimRelationshipTimelineStamp($npcId)` (`:277-359`) stamps
   `core_npc_master.gamets_last_updated = $GLOBALS['gameRequest'][2]` on **every** relationship write
   and snapshots to history. So relationship state lives on the *game* timeline: loading any older
   save legitimately rolls it back.

**The evidence, step by step (local time).** `(was X, now Y)` in `[REL-LLM]` is CHIM telling us what
it read immediately before writing - that is the ground truth for what survived each reload.

| local | event | value |
|---|---|---|
| 17:19:58 | `[REL-LLM] +3 (was 15, now 18)` "explicitly invited intimate physical escalation" | **aff 18** (history 279, gamets 6443554) |
| 17:25:28 | `[NPC RESTORE] using gamets: 6089460` / `Restored relationship timeline data for 40 NPCs` / `Cleared future-only ... for 0 NPCs` | newest eligible Lisette row = **226** (`infosave`, gamets 6089459, aff 2) |
| 17:28:14 | `[REL-LLM] +3 (was 2, now 5)` | **rollback 18 -> 2 confirmed** |
| 17:28:38 | `[NPC RESTORE] using gamets: 5876088` | newest eligible = **181** (`infosave`, gamets 5876087, **no `relationships` key**) |
| 17:29:43 | `[REL-LLM] +1 (was 0, now 1)` | **key deletion confirmed (reads as 0)** |
| 17:31:06 | `[REL-LLM] +3 (was 1, now 4)` | |
| 17:32:50 | `[NPC RESTORE] using gamets: 5876088` | same wipe |
| 17:34:41 | `[REL-LLM] -1 (was 0, now -1)` | wipe confirmed again |
| 17:35:35 | `[NPC RESTORE] using gamets: 5441980` | newest eligible = **138** (`infosave`, gamets 5441979, no `relationships` key) |
| 17:36:42 | `[REL-LLM] -1 (was 0, now -1)` | wipe confirmed again |
| 17:40:45 | `[NPC RESTORE] using gamets: 5192102` / `Restored ... for 0 NPCs` / **`Cleared future-only relationship data for 1 NPCs at gamets 5192102 [Lisette]`** | the `chimRelationshipFutureClearQuery` branch, explicitly naming her |
| 17:53:02 / 17:53:22 | apache `POST /HerikaServer/ui/core/npc_master.php` + `[REL] Timeline snapshot for npc_id 2209` | history 285/286/287, `relationships: []`, gamets 5423094 - an **NPC-manager UI open+save from a browser**, not the game |

Current live state (read-only query):

```
id=2209 npc_name=Lisette lock_profile=1 gamets_last_updated=5423094
extended_data.relationships   = []          <-- empty ARRAY, entry gone
extended_data.relationships_locked = false
(no relationships_updated / relationships_last_eval keys)
```

**Will it happen again on every reload? Yes.** Any reload whose gamets is older than the newest
relationship snapshot rolls the entry back, and it is deleted outright whenever the newest eligible
history row for that NPC is an `infosave` snapshot taken before relationships existed (rows 181 and
138 are exactly that). `lock_profile=1` does not protect it. This is CHIM's intended "paradox
prevention", with two defects from our point of view:
(i) the relationship-only restore ignores `lock_profile`;
(ii) a history row lacking the key deletes rather than preserves.

We must not patch CHIM core. The glue-side answer is R1c (own last-known value, section 3).
Optionally recommend the owner turn on `NEVER_CLEAR_RELATIONSHIP_DATA` in CHIM's conf - that is a
CHIM setting, not a code change, and it makes `restoreNPC` keep the live relationships
(`:1514-1515`). Flag it to her; do not set it ourselves.

### 1c. Ruled out

- **`relationship_init_queue` / `relationship_eval_queue`** - both empty; `processor/comm.php:227-228`
  truncates both on every game load ("Cleared relationship async queues for paradox prevention").
  The worker daemon never touched Lisette (`relationship_worker.log` has zero Lisette lines);
  evaluation ran inline from `ext/relationship_system/postrequest.php`.
- **`relationships_locked`** - `false` for Lisette; nothing was skipped for that reason.
- **Profile / voice / dynamic-profile refresh** - `extended_data` carries
  `voice_refresh_last_result`, `voice_refresh_last_resolved_at`; those writes go through
  `updateByArray` and never build a `relationships` value.
- **Our own ext** - confirms F6: no `adjustRelationship`, `setRelationship`, `parseChanges` or any
  `extended_data` write exists anywhere under `ext/lorerim_glue`. The glue is purely a reader.
- **An LLM re-evaluation zeroing her** - the opposite: every `[REL-LLM]` write today was a *positive
  or tiny negative delta applied on top of an already-wiped base*. The LLM never wrote 0 or `[]`.

---

## 2. CHIM RELATIONSHIP API - exact reference

File: `/var/www/html/HerikaServer/lib/relationship_manager.php`, class `RelationshipManager`,
every method `static`.

### Storage shape
`core_npc_master.extended_data` (jsonb) -> key `relationships` -> map keyed by target name.
The player's key is **always the literal string `Player`** (`normalizeTargetName`, `:186-213`, maps
`player / the player / player character / dragonborn / #player_name# / {player_name} / <PLAYER_NAME>`
to `Player`). Per-target value:
`{"aff": int, "type": string, "relation"?, "note"?, "best"?, "worst"?, "best_delta"?, "custom_info"?}`.

### Reads
| Signature | Line | Notes |
|---|---|---|
| `getRelationships(string $npcName): array` | `:671` | resolves the row, decodes, `normalizeRelationshipMap`. Returns `[]` if the NPC row is missing. |
| `getRelationship($npcName, $targetName): array` | `:687` | `['aff'=>int,'type'=>string,'tier'=>string]`. **Returns the default `['aff'=>0,'type'=>'neutral','tier'=>'Neutral']` both when the entry is missing and when the NPC row is missing** - indistinguishable from a genuine 0. This is the hole behind R1c. |
| `getPlayerRelationship($npcName)` | `:709` | = `getRelationship($npc, 'Player')` |
| `resolveNpcByName($npcName)` | `:597` | exact -> `(far away)`-suffix stripped -> `ucfirst(strtolower())` -> case-insensitive SQL -> in-range-actor bridge via `DataBeingsInCloseRange`. Returns `null` for `The Narrator`. Logs the 3 closest names on a miss. |
| `getTierLabel(int $score)` | `:405` | 11 tiers: Bonded >=91, Devoted >=76, Fond >=56, Friendly >=31, Acquaintance >=6, Neutral >=-5, Wary >=-30, Cold >=-55, Resentful >=-75, Hateful >=-90, else Hostile. |
| `affinityToLegacyRank($aff)` | `:717` | -100..100 -> -4..4 |
| `buildContext($npc, $nearbyNpcs)` | `:746` | the `[<Npc>'s RELATIONSHIPS]` prompt block |

### Writes
Both wrap the update in `chimRunWithRelationshipExtendedDataWrite()` and then call
`chimRelationshipTimelineStamp($npcData['id'])`.

| Signature | Line | Behaviour |
|---|---|---|
| `setRelationship($npcName, $targetName, $affinity, $type = null): bool` | `:983` | **Creates the entry when absent** (`['aff'=>0,'type'=>'neutral']`, `:998-1000`). Clamps `max(-100, min(100, (int)$affinity))` (`:1002`). Sets `type` only when `$type !== null` and `1 <= strlen <= 50`, canonicalising known aliases (`:1006-1009`). Returns `false` + `error_log` when the NPC cannot be resolved (`:989-992`). |
| `adjustRelationship($npcName, $targetName, $delta): bool` | `:1031` | read-modify-write on top of `setRelationship`, **preserving the current type**. |
| `parseChanges($aiResponse, $npcName)` | `:899` | the `#REL:Target=+5#` / `#TYPE:Target=X#` path used by the conversation model. Also creates missing entries (`:921-923`, `:951-953`). Strips the commands from the reply before TTS (`:977`). |

### Ranges and caps
Affinity is an integer clamped to **-100 .. +100** at every write (`:927`, `:1002`). There is **no
per-call delta cap** in `RelationshipManager`. The model-facing guidance (`:1052-1056`) suggests
minor +/-5..10, moderate +/-15..25, major +/-30..50, extreme +/-60..80 - guidance only, not enforced.

### Creating an entry when none exists
`setRelationship($npc, 'Player', $aff, null)` is exactly what CHIM itself does for a first-time
entry: insert `['aff'=>0,'type'=>'neutral']`, then set `aff`; passing `null` for `$type` leaves it
`neutral`. Do **not** invent `relation` / `best` / `worst` / `best_delta` - those belong to the
RelationshipLLM. Writing `note` is harmless (it surfaces in the prompt block via `formatEventNotes`,
`:859`).

### `relationships_locked`
A boolean in `extended_data`, set from the NPC-manager UI
(`ext/relationship_system/npc_save_handler.php:56-57`, `ui/api/chim_npc_manager.php:797-798`,
checkbox at `ext/relationship_system/relationship_editor.php:127`).
It is honoured **only** by the RelationshipLLM and its postrequest:
`relationship_llm.php:638` (skip `saveRelationships`), `:1625` (skip `applyChanges`),
`ext/relationship_system/postrequest.php:219`.
**`RelationshipManager::setRelationship` / `adjustRelationship` / `parseChanges` do NOT check it.**
If we want to respect the owner's manual edits, the glue must test it itself:

```php
$ext = json_decode($npcRow['extended_data'] ?? '{}', true) ?: [];
if (!empty($ext['relationships_locked'])) { /* log + skip the gain */ }
```

Lisette's is `false`.

### Relationship TYPES the LLM / our code may not set
- Closed allow-list: `RelationshipManager::TYPES` - 32 values (`:48-54`): `romantic, platonic,
  familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective,
  indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious,
  admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive`,
  plus player-created custom types already present in the data (`getCustomRelationshipTypes`, `:255`).
  `canonicalizeRelationshipType` (`:222`) returns `null` for anything else and `parseChanges` then
  **ignores** the type change (`:947`).
- Aliases normalised on input (`:58-66`): `romance/marriage/married/lover/lovers -> romantic`,
  `betrayal -> betrayed`, `enemies -> enemy`.
- **Romantic-leaning types are gated by CHIM and must be left alone.**
  `ext/relationship_system/relationship_llm.php:1513`:
  `$romanticTypes = ['romantic','crush','admirer','obsessed','infatuated','lover'];`
  Promotion into one of those is blocked unless the new affinity is `>= 56` (Fond tier) **and** the
  model gave a reason (`:1520`). This fired 11 times for Lisette today, e.g.
  `[REL-LLM] BLOCKED romantic promotion: Lisette -> Player: neutral => romantic (aff 18 below fond tier or no reason; kept 'neutral')`.

### Safest way for our ext to add a small gain (R1b)

```php
// 1. load what we need ourselves - the glue cannot rely on CHIM having loaded it
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';

// 2. respect the owner's manual lock (CHIM's own setters do not)
//    ... read extended_data.relationships_locked, skip + log if set ...

// 3. the gain itself - type preserved, clamped, entry created when absent
$before = RelationshipManager::getRelationship($npc, 'Player');   // ['aff','type','tier']
RelationshipManager::adjustRelationship($npc, 'Player', $gain);   // never pass a $type
```

Rules:
- **Never pass a `$type`.** `adjustRelationship` keeps the existing one, which is what R1b requires
  ("never touching romantic relationship TYPES which CHIM reserves").
- **Do the write in the glue's `postrequest.php` / `prepostrequest.php` hook** (main.php `:2946` /
  `:2944`), not in `prerequest`: by then `relationship_system/context_pre.php` has loaded the class,
  `$GLOBALS['gameRequest'][2]` is available so `chimRelationshipTimelineStamp` can put the gain on
  the correct game timeline, and the LLM call is already done.
- The timeline stamp is a feature here: the gain is snapshotted to history at the scene's game time,
  so a later reload of a save taken *after* the scene keeps it. A reload of an older save will still
  roll it back - that is what the glue-side `lrg_romance` memory in R1c is for.
- One gain per NPC per window: `lrg_romance.last_scene_at` is already a real-clock epoch
  (`lrgRomanceBump` sets `last_scene_at = lrgNow()`, `lrg_core.php:389`), so the "6 real hours" rule
  is reliable today. In-game-day needs `gameRequest[2]` + `lib/utils_game_timestamp.php`.

---

## 3. HOW THE GATE READS AFFINITY TODAY, AND THE ROBUST READING (R1c)

### Today
`lrg_core.php:493-509` `lrgAffinity()` (quoted in 1a) then `lrg_core.php:530-548` `lrgInterest()`:

```
score   = affinity - min_affinity (+20 for a married NPC under the "secret" rule) + min(max_bonus, renown + speech)
willing = score >= 0                      -> otherwise gate reason "not_close_enough"
word    = indifferent | curious | interested | drawn   (thresholds -25 / 0 / 20)
```
`lrgEvaluateGates` (`:558`) records `$res['affinity'] = $interest['affinity']` (`:604`) - that is the
`aff=` in the log line.

### Two defects
1. **`class_exists('RelationshipManager')` is false at every call site**, so the CHIM term is
   permanently 0 (section 1a). This is the bug that made the gate deaf to everything the
   RelationshipLLM wrote all day.
2. Even once fixed, `getRelationship()` collapses three different situations into `aff = 0`:
   entry missing, NPC row missing, genuine zero. A wiped entry silently becomes `not_close_enough`.

### Proposed robust reading
- **Load the class ourselves.** At the top of `lrg_core.php`, guarded:
  ```php
  if (!class_exists('RelationshipManager') && !empty($GLOBALS['ENGINE_PATH'])
      && is_file($GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php')) {
      require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
      require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
  }
  ```
  Keep the `class_exists` guard around the call so the offline tests (`test_gates.php`) still run.
- **Tell "missing" apart from "zero".** Use `RelationshipManager::getRelationships($npc)` and test
  `isset($rels['Player'])`. `getRelationship()` cannot express the difference.
- **Keep our own last-known value.** `lrg_romance` already exists and already works:
  `npc_name, stage, scenes, refusals, gold_accepted, last_scene_at, last_refusal_at`
  (read `lrgRomance()` `:376`, write `lrgRomanceBump()` `:383`). Lisette's row today is already
  `stage=0 scenes=2 refusals=0 last_scene_at=1790025570` (= 17:19:30, the exact moment scene 2 ended
  per the glue log) - so the scene counter survived everything. Add
  `last_affinity int NOT NULL DEFAULT 0` and `last_affinity_at bigint NOT NULL DEFAULT 0` in a new
  migration and refresh them on every turn where the CHIM entry exists.
- **Effective affinity:**
  ```
  chim entry exists                          -> use it, and store it as last_affinity
  chim entry missing                         -> use last_affinity, log rescued=missing
  chim entry present but 0 and last_affinity > 0 -> use last_affinity, log rescued=zeroed
  ```
- **History bonus on the interest score, not on affinity**, so the two stay separable in the log:
  `history_bonus = min(cap, base * (scenes > 0) + per_extra * max(0, scenes - 1))`,
  config `leverage.history_bonus` default +15.
- **Log the components** so the next playtest is diagnosable in one line:
  `aff=18 chim=missing lrg=18 hist=15 courting=0 rank=0 min=10 interest=drawn(23)`
- **Fix `IsCourting`** (`LRG_Profile.psc:96`) to `akActor.HasAssociation(courting, akPlayer)` -
  otherwise +10 keeps leaking to NPCs courting someone else.
- Pre-scene refusal stays possible: an NPC who genuinely has no history and a real low affinity
  still fails the gate.

### R1d - repairing today's damage: what the number should be
The owner's prompt says "restore to what it was (10)". **10 was never CHIM's value** - it was the
courting bonus (section 1a). CHIM's last good value before the rollback cascade was **+18**, written
at 17:19:58 (`[REL-LLM] +3 (was 15, now 18)`, history 279, gamets 6443554, type `neutral`).
Recommended: restore **18**, then add the R1b gains for the two scenes completed today
(first scene +8, second +4) -> **30**, type left `neutral`. Flag the 10-vs-18 discrepancy to the
owner rather than silently choosing.
Two constraints on the repair:
- Do it **from inside a live game turn** (so `chimRelationshipTimelineStamp` stamps the current
  gamets and the value lands on the timeline the owner is actually playing). A CLI repair with no
  `gameRequest[2]` falls back to `DataLastKnownGameTS()` and can be rolled straight back by the next
  reload.
- `extended_data.relationships` is currently the empty **array** `[]`, not an object.
  `normalizeRelationshipMap([])` returns `[]` harmlessly and `setRelationship` replaces it with a
  proper object, so no special handling is needed.

---

## 4. WHAT CHIM KNOWS ABOUT AN NPC FOR THE R3 OUTRO

### Where the turn code reads it
`$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]` - the **whole `core_npc_master` row** for the speaking NPC.
Set in `main.php` at `:489`, `:554` (fallback), `:629`, `:680`, and refreshed at `:762-770`.
All of those are **before every ext hook the glue uses** (`preprocessing` `:193`,
`prerequest` `:1117`, `context_pre` `:2540`, `postrequest` `:2946`), so it is available wherever the
outro is built. Mirror global: `$GLOBALS["STOBE_CORE_CURRENT_NPC_DATA"]`.
Fallback for CLI / tests: `(new NpcMaster())->getByName($GLOBALS['HERIKA_NAME'])`
(`lib/core/npc_master.class.php`, required from `main.php:60`, so `NpcMaster` is always loaded).

### Fields, with Lisette's live values as the worked example
| Field | Lisette |
|---|---|
| `occupation` | "Lisette serves as the resident bard at the Winking Skeever, performing music, taking requests, and documenting notable events in song. Her position requires her to entertain guests throughout the day and maintain a welcoming atmosphere in the tavern." |
| `npc_static_bio` | "Born and raised in High Rock, Lisette traveled to Skyrim to study at the prestigious Bards College in Solitude. After graduating, she made the conscious choice to remain in the city but work at the Winking Skeever rather than pursue a more formal position at the College..." |
| `goals` | bulleted; first bullet is literally "Maintain her position as a respected tavern performer at the Winking Skeever while preserving her professional boundaries against unwanted advances" |
| `personality` | "warm and welcoming in her tavern role, but recent encounters with persistent, disrespectful patrons have sharpened her resolve..." |
| `speechstyle` | "melodic, poetic cadence, often weaving metaphors of music and song... favours elegant, formal phrasing" - the voice rules for the outro |
| `extended_data.class.name` | `"Bard"` (+ `class.formid` `0001325D`) |
| `extended_data.factions[]` | name / rank / plugin / formid / stable_key |
| also | `gender`, `race`, `voiceid`, `refid`, `prompt_head`, `oghma_knowledge_tags`, `emote_moods`, `metadata`, `dynamic_profile`, `profile_id` |

`occupation` alone carries the whole "I have to get back to ..." beat for a tavern bard. For NPCs
with a thin `occupation`, `extended_data.class.name` + the faction list + `goals` are the fallback.

### Place and time - from our own snapshot
`lrgGetNpcState($npc)` (`lrg_npc_state.payload`, v2 keys already shipped). Lisette's newest row:

```
loc "The Winking Skeever" | ltype "Inn" | nhome "The Winking Skeever" | home "1"
interior "1" | cellown "OTHER" | bedown "OTHER" | nearf "Chair,Wall,shelf,singlebed"
wit "6" | witfol "0" | witkid "0" | married "0" | pspouse "0" | courting "0" | mate "0"
sde "-1" | gold "0" | lvl "4" | class "Bard" | fac "CrimeFactionHaafingar,TownSolitudeFaction,JobBardFaction,BardSingerFaction"
```

- **"or she stays" test (R3):** `home == "1"` (she is in her home cell) plus `ltype`/`nhome`, or
  `pspouse == "1"`, or a follower faction in `fac` (compare Katana:
  `CurrentFollowerFaction,PlayerFollowerFaction`). `witfol` gives the follower count nearby.
- **Time of day:** not in the snapshot today. The request carries the game timestamp as
  `$GLOBALS['gameRequest'][2]` (the same value the glue already uses for the scene/timeline logic);
  CHIM converts it through `lib/utils_game_timestamp.php` (required from `lib/data_functions.php:6`).
  If R3 wants "tonight" / "this morning" wording, either derive it there or add an hour key to the
  v2 snapshot - additive and wire-safe.

---

## 5. WHAT THE OWNER ACTUALLY SAID, 17:26:41 - 17:44:22

### Method and confidence
The glue logs `say=""` for closed and silent turns (the logging gap R4 fixes), and CHIM's `memory`
and `eventlog` tables were truncated by the 17:40:45 game load (only 4 `inputtext` rows survive).
The surviving full record is `log/context_sent_to_llm.log`: 72 request blocks (delimited by
`'messages' =>`), each containing the running conversation, whose last
`# Jordan, speaking to <npc>:` line is that turn's utterance.

The block sequence was aligned to the glue log at **three independent anchors**, all exact:
- block 40 `"I want you to ride me."` <-> glue `17:16:59 say="i want you to ride me"`
- block 45 `"Go again."` <-> glue `17:19:45 say="go again"`
- blocks 69-72 <-> `eventlog` rows at `17:42:40 / 17:42:53 / 17:43:34 / 17:44:22` (verbatim match)
and the counts line up exactly: blocks 46-63 = 18 Lisette blocks <-> the 18 Lisette turns from
17:26:41 to 17:37:09; blocks 64-68 = 5 Braste blocks <-> the 5 Braste turns 17:38:08-17:40:09.

### The closed period (every one of these got `mode=closed`, `aff=0`, all actions hidden)

| local | verbatim |
|---|---|
| 17:26:41 | `The fuck you standing from behind so bad right now.` (STT damage; "I wanna fuck you standing from behind so bad right now") |
| 17:27:08 | `Let's do standing from behind sack.` (STT: "...sex") |
| 17:27:30 | `Fuck me reverse cowgirl.` |
| 17:28:04 | `Take our clothes off.` |
| 17:29:34 | `Can't take it anymore. Give me a blow job.` |
| 17:29:56 | `Give me a hand job.` |
| 17:30:42 | `Uh.` |
| 17:30:51 | `Um` |
| 17:31:17 | `Me a hug then.` (STT: "Give me a hug then") |
| 17:32:09 | `Striped naked.` (STT: "Strip naked") |
| 17:33:41 | `Come give me a hug. I missed you.` |
| 17:34:06 | `Come on. I know you want me, so start kissing me.` |
| 17:34:30 | `No you want me sexually.` |
| 17:34:49 | `Come on, come fuck me.` |
| 17:35:52 | `That why don't we go find somewhere private?` |
| 17:36:31 | `I want you to fuck me so bad. I can't even control myself. I can't hold it in anymore.` |
| 17:36:51 | `Me the` (fragment) |
| 17:37:09 | `Follow me then.` |
| 17:42:40 | `Hey, sorry to interrupt you, Lizette. I just wanted to ask if we're still cool, right?` |
| 17:42:53 | `Do in private. Follow me.` |
| 17:43:34 | `i want to fuck..` (typed, whispering) |
| 17:44:22 | `i wanna shove cum into that pussy.. cmon.. lets do it upstairs in one of the rooms` (typed, whispering) |

### Immediately before the closed period (same session, for act coverage)

| local | verbatim | glue outcome |
|---|---|---|
| 17:09:29 | `Take off your clothes.` | `undress/npc high` -> LLM no action |
| 17:09:55 | `Bend over and let me start doing doggy style.` | `act/vaginal high` -> LLM no action |
| 17:10:34 | `Come kiss me.` | Start worked |
| ~17:11:28 | `Now get down on your knees and suck my deck.` | F1: `act/oralpenis:npc conf=LOW` -> nothing happened |
| ~17:11:5x | `Yeah, let me touch those titties.` | |
| 17:12:28 | `Take my clothes off, and then let's book a missionary.` | F2: only undress/player carried out |
| 17:12:5x | `Let's have doggy style socks.` (x3) | safety net -> `OARE_Doggystyle` |
| ~17:13:xx | `How do you want me to fuck you?` | |
| 17:16:59 | `I want you to ride me.` | Start worked, began at `OARE_StandingEmbraceKiss` |
| ~17:17:4x | `I wanna fuck you standing up.` | safety net -> `OARE_StandingHoldingSex` |
| ~17:18-19 | `Faster.` (x2) | worked |
| 17:19:45 | `Go again.` | `intent=none`, "no pattern matched" |

### Phrases the owner named that are NOT in today's logs
`"let me eat your pussy"`, `"lets do 69"`, `"i want to fuck your asshole"` do not appear anywhere in
`context_sent_to_llm.log` or `debugStream.log` today - searched for `pussy`, `eat`, `lick`, `69`,
`sixty`, `anal`, `asshole` across every player line. The only `pussy` hit is the 17:44:22 line above.
They were most likely said in an earlier session whose context log has rolled, or mangled past
recognition by speech-to-text. What **is** confirmed verbatim from her list: `Give me a blow job`,
`Come give me a hug` / `Me a hug then`, `Fuck me reverse cowgirl`, plus `Give me a hand job`,
`Take our clothes off`, `Strip naked`, `standing from behind`. The act table should cover the full
list regardless.

---

## APPENDIX - commands used (all read-only)

- `\\wsl$\DwemerAI4Skyrim3\...` UNC paths for source reading (Grep/Read work directly).
- Postgres, read-only: `PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer` via
  `wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/<script>.sh`
  (scripts `mine_q5..mine_q28`, LF endings; the shared `lrg_test` folder also holds other agents'
  scripts - mine are prefixed `mine_`).
- Logs: `/var/log/apache2/error.log` (= `HerikaServer/log/apache_error.log`),
  `/var/log/apache2/other_vhosts_access.log`, `HerikaServer/log/chim.log`,
  `HerikaServer/log/context_sent_to_llm.log`, `HerikaServer/log/lorerim_glue.log`,
  `HerikaServer/log/relationship_worker.log`.
- No `INSERT` / `UPDATE` / `DELETE` was issued. No file outside this report was modified.

### One thing to be aware of
At **17:53:02 and 17:53:22 local**, after the playtest ended, someone opened Lisette in the
NPC-manager UI from a browser (`172.31.176.1`) and saved her
(`POST /HerikaServer/ui/core/npc_master.php`), creating history rows 285/286/287 with
`relationships: []` and `gamets_last_updated 5423094`. Also, a live config override
`ext/lorerim_glue/config/lrg_config.json` was created at **17:58** containing
`{"npc_overrides":{"Lisette":{"min_affinity":-5}}}`. Neither existed during the playtest
(it ended 17:44), so neither affects the findings above - but both are live on the server now and
the main session should decide whether to keep them.
