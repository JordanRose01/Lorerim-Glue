# pt9 - CHIM x the follower mods of LoreRim (investigation, read-only)

Owner ask: OWNER_ADDENDA.md item 10 - "make sure chim is compatible with the NFF and any other follower
mods included in lorerim as well, make sure they are aware of each other, do not conflict, and hopefully
could potentially even work together."

Scope, method and citation conventions are in section 11. Nothing was modified. Every claim is tagged
**[V]** verified from a file I read, **[I]** inference from evidence I read, **[K]** known vanilla data
I did not re-verify from the plugin, **[U]** untested in game.

---

## 0. Executive summary - read this first

1. **NFF IS NOT IN LORERIM.** `nwsFollowerFramework.esp` appears in **no** line of
   `profiles\Ultra\plugins.txt` (3,417 plugins) or `modlist.txt`, and no file named `*nws*` or
   `*FollowerFramework*` exists under `F:\Modlists\LoreRim\mods`. **[V]** The follower framework this
   list actually ships is **Simple Follower Framework (SFF) 1.4.1.1** by ItzIvy
   (`mods\Simple Follower Framework`, `Simple Follower Framework.esp`, plugins.txt line 926), whose own
   mod page text says in as many words that it is **"Not Compatible with: Nether's Follower Framework"**
   **[V - meta.ini nexusDescription]**. Everything the owner asked about NFF has to be re-aimed at SFF.
2. **CHIM 3.3.2 contains NFF-specific code that is dead here.** `AIAgentNpcUtil.MakeFollower`
   (`AIAgentNpcUtil.psc:376-397`) adds the NPC to the vanilla PotentialFollower/CurrentFollower factions
   and then tries `Game.GetFormFromFile(0x0000434F, "nwsFollowerFramework.esp")` and
   `(nwsFF as nwsFollowerControllerScript).RecruitAction(akTarget)`. With NFF absent the `if (nwsFF)`
   guard is false, so the recruit call never runs **[V]** - CHIM's "Join party" leaves an NPC in a
   **half-recruited state that no framework knows about** (section 4.1). CHIM's own comment on the call
   site says it: `AIAgentAIMind.psc:572` `AIAgentNpcUtil.MakeFollower(npc); Needs NFF`.
3. **The single most dangerous conflict is a wrong-target dismiss/wait.** SFF resolves *which* follower a
   dialogue command applies to with `GetDialogueFollowerTarget()`: `Game.GetDialogueTarget()` →
   if `IsManagedFollower` return it → else `SFFLastSpeaker` → **else `pFollowerAlias.GetActorReference()`**
   **[V - decompiled from `Scripts\dialoguefollowerscript.pex`]**. A CHIM-made pseudo-follower is in no SFF
   alias, so `IsManagedFollower` is false for her and **"It's time for us to part ways" / "Wait here"
   spoken to her hits the player's REAL primary follower instead.** (section 4.2)
4. **CHIM already has a follower-awareness switch and it is currently dead.** `conf_opts.CurrentParty` is
   **empty** in the live DB **[V]**, so `$GLOBALS["IS_NPC"]` is true for every NPC (`main.php:1103-1111`)
   and the `available_to_followers` column of `core_action` is **never consulted** in this install
   (`lib/core/action_catalog.php:2665-2694`, `:2751-2763`). The moment the player recruits a real SFF
   follower who is also a CHIM agent, CurrentParty fills, `IS_NPC` flips to false for her, and her action
   set **silently changes**: `FollowPlayer`, `MakeFollower`, `MoveTo` and **`EndConversation`** disappear,
   `SheatheWeapon` appears. The whole glue has only ever been played with `IS_NPC = true`. **[V + I]**
5. **The frameworks are not scriptable from outside, except SFF.** SFF's compiled
   `DialogueFollowerScript` exposes a full, usable Papyrus API (23 functions, section 7.1). Quick Follower
   Commands, Set Follower Home and the six custom-voiced followers expose nothing - they are driven by
   dialogue topics and hotkeys only. So "work together" = **drive the real dialogue entries with the
   menuless questing driver**, and use SFF's API only to *read* state and to repair what CHIM broke.
6. **Owner-side, three things should change today** (section 9): switch off `MakeFollower`
   (Join_#PLAYER_NAME#_Party) and `Follow` in the CHIM web UI action catalog, and leave `FollowPlayer`
   on but understand what it is. `WaitHere` is already `is_activated = f` and its Papyrus body is a
   deprecated no-op anyway (`AIAgentAIMind.psc:1488-1498`) **[V]**.

---

## 1. Inventory: every follower / follower-adjacent mod in this load order

### 1.1 The framework layer

| Mod (mods\ folder) | Plugin (plugins.txt line) | What it does to follow / wait / dismiss / sandbox / combat / relationship / dialogue |
|---|---|---|
| **Simple Follower Framework 1.4.1.1** | `Simple Follower Framework.esp` (926) + `Simple Follower Framework Patch.esp` (3335, from `mods\LoreRim - xEdit64 Output`) + `SKSE\Plugins\SimpleFollowerFramework.dll` | **THE** framework. Does **not** replace the vanilla follower quest - it *extends* `DialogueFollower` (Skyrim.esm **0x000750BA**) with 7 extra aliases `Alias_ExtraFollower01..07` beside `Alias_Follower`/`Alias_Animal`, replaces the quest script `DialogueFollowerScript` with its own compiled version, and adds two globals `SFFCurrentFollowerCount` / `SFFCanRecruitMore` plus a sandbox package `SFF_FollowerSandbox` and idle markers `IvyFollowerIdleSandbox01..07`, and a friendly-fire perk/spell `IvyCompanionsSafePerk/Spell/Effect`. **[V - esp EDID scan + pex decompile]** Follow/wait/dismiss stay 100 % vanilla dialogue; recruitment is `SetFollower` → `PrepareFollowerActor` → `SetPlayerTeammate(true,true)` + `SFF_SKSE.AddVanillaFollower()`. |
| *(live config)* | `SKSE\Plugins\SimpleFollowerFramework.ini` | `iMaxFollowers=4` but **`bFollowerOptionSelector=2`** so the cap is **Speech-driven**: `iSpeechLevelsPerSlot=25` → Speech 0-24 = 1 follower, 25-49 = 2, 50-74 = 3, 75-99 = 4, ... up to 8. `bFollowerEssential=0`, `bFriendlyFireProtection=0`, **`bFollowerSandbox=1`** (followers wander/sit/idle in Dwellings & Habitation). **[V]** |
| **Swiftly Order Squad - Follower Commands UI** | `Quick Follower Commands.esp` (2123) | Hotkey/wheel command UI (`mfc_menu.swf`, default key `dxcode 40` = `'`). Tracks followers in a FormList, fed by a SPID-distributed spell `0x802` plus a topic fragment `qfcManageScript.Fragment_0` that adds `qfcSpell` on recruit. Commands: **StartWaiting / StopWaiting / Inventory / Teleport**, implemented as `SetActorValue("WaitingForPlayer",0/1) + EvaluatePackage`, `OpenInventory(true)`, `MoveTo(player)`. Membership test `IsFollower()` = **`IsPlayerTeammate()` or PetFramework faction rank >= 1**; `IsDismissedCustomFollower()` knows Vilja factions and **Inigo's `WaitingForPlayer == -1` dismissed convention**. **[V - qfcAliasScript.psc:185-263]** |
| **Settling of Squad - Set Follower Home** | `SetHome.esp` (967) + `SetHomeInigoBugFix.esp` (968) | Adds **two dialogue topics** ("settle down here" / "come with me again") whose fragments call `SetHomeQuestScript.SetHome(akSpeaker)` / `.UnsetHome(akSpeaker)`; parks the follower in one of 50 aliases with a home marker; has a Serana special case. MCM via `SetHomeMCMQuestScript`. **[V]** |
| *(absent)* | - | **Nether's Follower Framework, AFT, EFF, Busy Follower Framework: NOT PRESENT.** **[V]** |

### 1.2 The SKSE behaviour layer (no plugin, no Papyrus, DLL-only)

| Mod | DLL / ini | Effect on a follower | CHIM relevance |
|---|---|---|---|
| **Automatic Follower Teleporter NG** | `AutomaticFollowerTeleporter.dll` + ini (`fDrawDistance 2000`, `fDrawnDistance 1500`, `fBehindOffset 450`, `fPerActorCooldown 5`) | Teleports **followers** behind the player when the weapon is drawn and they lag | Will **not** move a CHIM pseudo-follower (she is not a teammate); will yank a real SFF follower out from under a CHIM `TravelTo`/`Sandbox`. Log at `Documents\My Games\Skyrim Special Edition\SKSE\AutomaticFollowerTeleporter.log` shows only load events so far **[V]** |
| **Considerate Followers** | `ConsiderateFollowers.dll`, `fMaxConversationDistance = 400.0`, `bPreventFollowerPileup = false` | Silences followers while the player is in an **engine** dialogue menu | Menuless CHIM talk does **not** trigger it → follower barks can talk over CHIM speech. Already noted in `research\lorerim-scan.md:165`. A menuless-questing session that really opens the Dialogue Menu *will* trigger it. **[V]** |
| **Dynamic Follower Scaling SKSE** | `FollowerScaling.dll`, `ReductionPerFollower = 20`, `Mode = 1` | Each extra follower cuts all follower damage by 20 % (exponential) | Counts *followers*; a CHIM pseudo-follower is invisible to it → free extra damage. Cosmetic/balance only. **[V]** |
| **Keep Up - Follower Locomotion Fix** | `tesfankeepup.dll` | Locomotion fix for following NPCs | Harmless |
| **Auto Follower Stuck Sneaking Fix** | `AutoSneakBugFix.dll` | Un-sticks sneaking followers every 2 s | Harmless |
| **Project Bro He's There** | `ProjectBroHesThere.esp` (plugins 1459 area) + dll | Makes followers join combat | Harmless |
| **Press E to Heal Followers** (+ SKSE + bleedout fix + Gore patch) | `Press E To Heal Followers.esp` (822), `Press E to heal Gore.esp` (823) | Heal/revive a downed follower | Harmless |
| **Follower Stats** | `Follower Stats.esp` (1007) | HUD panel on every **Dialogue Menu open**; casts `FollowerStats_DialogCatcherCloakAb` when the speaker is not under the crosshair | Already a known menuless-questing cost - `research\p2-requiem.md` R2-A3 / PHASE2_DESIGN F13, C12 |
| **Show Follower Carry Weight**, **FollowerInteractWithGestures**, **Followers React to Crafting**, **Chatty NPCs and Followers**, **mtsfollowerbanter** | various | Cosmetic / banter | `FollowerInteractWithGestures` polls `UI.IsMenuOpen("Dialogue Menu")` (`lorerim-scan.md:299`) |

### 1.3 Custom-voiced followers with their own state machines (vanilla teammate flag, own dialogue trees)

`Inigo.esp` (687) + Lulu's Inigo (1809) - `Lucien.esp` - `018Auri.esp` (677, Song of the Green, `A18_AuriController`)
- `HLIORemi.esp` (1764, Remiel) - `00Taliesin.esp` (`TallyController`, `TallyHorseAuto2`) - `Katana.esp` (1778) +
`KatanaRaven.esp` - `GORE.esp` (72) + `Gore - SaSEC.esp` - `SeranaDialogueExpansion.esp` (969) + Romance (1021).
**[V - plugins.txt / mods\ listing]**

SFF's own page states it "does not touch any modded followers with their own system (Inigo, Lucien,
Remiel, etc)" **[V]**. Practical consequences for CHIM:

- They are `IsPlayerTeammate()` when recruited, so QFC, AFT, Considerate Followers, Follower Stats and
  the glue's teammate checks see them. **[K]**
- They do **not** occupy an SFF alias, so `IsManagedFollower` is false for them and **SFF's
  `FollowerWait` / `DismissFollower` would fall through to the SFF primary follower if their own topics
  ever routed through the vanilla quest** - they don't; they use their own topics. **[V/I]**
- Inigo's dismissed state is `WaitingForPlayer == -1` (QFC knows this, `qfcAliasScript.psc:258-261`)
  **[V]** - any glue code that sets `WaitingForPlayer` must never write -1 and must treat -1 as "not
  available", or it will un-dismiss Inigo.

### 1.4 Dialogue-content mods that own the follower topics

- **Missing Follower Dialogue Fix** (`Missing Follower Dialogue Fix.esp`, 1699) is the **winner** of the
  vanilla follower topics in this list: 257 vanilla INFOs won, 1,025 new INFOs inside vanilla topics
  (`research\p2-requiem.md:129`, `:135`). Its plugin contains the full `DialogueFollower*` topic set and
  its own fragment scripts `MFMD_Follower{Recruit,RecruitReject,Follow,Wait,Dismiss,Trade,FavorStart,
  FavorEnd,ContinueFavorState}` and the `*Animal*` twins. **[V]**
- **Requiem** makes recruitment hard on purpose (Ghorbash needs level 35 or Speech 100; Borgakh needs Orc
  or Speech 100; `REQ_Quest_FollowerControl`) - `research\p2-requiem.md:64`, `:122`, R12.

---

## 2. How CHIM 3.3.2 handles followers today

### 2.1 Game side - the five package verbs (all in `mods\CHIM\Source\Scripts\AIAgentAIMind.psc`)

| Function | line | Faction set | Package + priority | Teammate flag? |
|---|---|---|---|---|
| `ResetPackages(npc)` | 21-68 | - | removes **all 11** CHIM packages, `StorageUtil CHIM_FollowPlayerActive = 0` | no |
| `StartWait(npc)` | 404-425 | +WaitFaction, -FollowFaction | `WaitPackage 0x02021F` **prio 100** | no |
| `StartWaitSoft(npc)` | 427-448 | +WaitFaction, -FollowFaction | `WaitPackage 0x0268b1` **prio 50** | no |
| `Follow(npc, target)` | 481-500 | +FollowFaction rank 1, -SandboxFaction | `FollowPackage 0x01BC25` **prio 100** | no |
| `FollowSoft(npc, target)` | 502-528 | +FollowFaction rank 1 | `FollowPackageSoft 0x0268b0` **prio 55** | no |
| `ComeCloser(npc, target)` | 558-565 | via FollowSoft | prio 55, restores `FollowPlayerPackage` on `EndFollowSoft` (530-554) | no |
| `stayAtPlace(npc, 0)` | 898-912 | +SandboxFaction, +BardAudienceExcluded | `SandboxPackage 0x20ce2` **prio 99** | no |
| `stayAtPlace(npc, 1)` = **FollowPlayer** | 913-922 | +FollowFaction rank 1 | `FollowPlayerPackage 0x2226d` **prio 100**, `CHIM_FollowPlayerActive = 1` | **no** |
| `Sandbox(npc, taskid)` | 4687-4713 | +SandboxFaction | `SandboxWorkPackage 0x40be6` **prio 100** (`sandboxSleep 0x4adf0` for "sleep") | no |
| `MakeFollower(npc)` | 567-574 | → `AIAgentNpcUtil.MakeFollower` | (ResetPackages only) | **no** |
| `AIAgentNpcUtil.MakeFollower` | NpcUtil 376-397 | **+vanilla PotentialFollowerFaction rank 0, +vanilla CurrentFollowerFaction rank 1**, then the dead NFF call | none | **no** |
| `WaitHere(npc)` | 1488-1498 | - | **deprecated no-op** (a Debug.Notification, everything else commented out) | - |
| `StopCurrent(npc)` | 576-625 | strips every CHIM faction, `ClearPackageOverride` | - | `SetPlayerTeammate(false)` is **commented out** (`:1016`) |

**The headline: not one CHIM path ever calls `SetPlayerTeammate`.** **[V - grep of the whole
`Source\Scripts` tree: the only two hits are commented-out lines `:1016` and a comment at `:945`]**

Two places where CHIM *does* look at real follower state:
- `StopCurrent` `:613-621`: a "rolemastered" NPC who `isInFaction(VanillaCurrentFollowerFaction)` keeps
  no overrides; a non-follower gets `stayAtPlace(npc,0)`.
- `TravelToTargetEnd` `:786`: an NPC who is **not** in `VanillaCurrentFollowerFaction` is put into
  Sandbox at the destination; a follower is left alone.
- `OpenInventory` `:929-987`: `IsPlayerTeammate() && GetFactionRank(CurrentFollowerFaction) > -1` →
  `OpenInventory(true)` (real follower trade); otherwise **`ShowBarterMenu()`** - i.e. a CHIM
  pseudo-follower gets a *merchant* barter window, not follower trade. **[V]**

### 2.2 Server side - the action catalog

Live `core_action` rows that matter (`PGPASSWORD=dwemer psql ... dwemer`, 53 rows) **[V]**:

| id | code_name | action_name | npc | **fol** | act | dispatch |
|---|---|---|---|---|---|---|
| 7 | ComeCloser | Come_Closer | t | t | t | plugin_command |
| 13 | EndConversation | End_Conversation | t | **f** | t | plugin_command |
| 15 | Follow | Follow | t | t | t | plugin_command, `cooldown_seconds 60` |
| 16 | FollowPlayer | Follow_#PLAYER_NAME# | t | **f** | t | plugin_command, `cooldown_seconds 60` |
| 27 | LeadTheWayTo | LeadTheWayTo | f | f | **f** | plugin_command |
| 28 | **MakeFollower** | **Join_#PLAYER_NAME#_Party** | t | **f** | **t** | plugin_command |
| 29 | MoveTo | Move_To | t | **f** | t | plugin_command |
| 30 | OpenInventory | Trade_Items | t | t | t | plugin_command |
| 38 | SheatheWeapon | Sheathe_Weapon | **f** | t | t | plugin_command |
| 43 | StopWalk | Stop_Walk | f | f | t | plugin_command |
| 53 | **WaitHere** | Wait_Here | t | t | **f** (off) | plugin_command |

`available_to_followers` does **not** mean "the NPC is a follower in the game". It means "this request's
NPC is in CHIM's own `CurrentParty`", i.e. `!$GLOBALS["IS_NPC"]` - see `main.php:1103-1111` and
`lib/chat_helper_functions.php:4018-4021`, and the two selectors
`lib/core/action_catalog.php:2665-2694` (`herikaLoadEnabledActionCodesForMode`) and `:2751-2763`
(`herikaActionCatalogRowIsAvailableInCurrentMode`). **[V]**

`CurrentParty` itself is a DLL-written `setconf` blob -
DLL string `setconf|{}|{}|CurrentParty@{}` next to the record template
`"level":{},"name":"{}","race":"{}","gender":"{}","isVampire":"{}"` **[V]**; parsed by
`lib/data_functions.php:4434-4470`. **Live value: empty.** **[V]** The DLL also logs
`isPlayerTeammate` when it marks an actor dialogue-busy (DLL `Setting dialogue busy for {},
isPlayerTeammate {}` and `[HTTPStream] Setting dialogue busy for actor: {}, isPlayerTeammate: {},
resolved listener: {}`) and carries a `companions='{}'` field in its nearby-context logging, so the
party list is built from the engine's teammate set. **[I - strings only, not decompiled]**

### 2.3 Server side - follower awareness in the prompt

`lib/data_functions.php:1288-1307` turns `CurrentParty` into a `$followers[]` list, and `:1500-1521`
emits the only follower-facing prompt block:

```
<adventuring_party>
 # ADVENTURING PARTY
 <names> are together as an **adventuring party**, acting as close companions.
 - The others **can know each other**, but they are **not part** of <names>'s group.
 - Generally speaking, any mention of **plans, missions, or objectives** refers **only to the
   adventuring party**, never to the other NPCs.
</adventuring_party>
```

The older `# PARTY STATUS` / `# YOU'RE NOT PART OF THE GROUP FORMED BY` pair is commented out
(`:1489-1499`). **With `CurrentParty` empty, this block is never emitted** - the LLM is told **nothing**
about who follows the player. **[V]**

`diary_followers` (DLL `Processing diary_followers request - checking all agents for followers` /
`Found follower: {}`) is the only other follower-aware CHIM feature. **[V - strings]**

### 2.4 Listener choice with followers present

Listener resolution is entirely DLL-side (`[LISTENER-RESOLVE] ...`, `PlayerSpatialCandidate`,
`chatbox_override` / `look_target` / `ranked_targets` / narrator fallback) and is driven by spatial
tiers (`SpatialAwareness::Evaluate`, `tier=`, `closedDoors=`), **not** by follower status. **[V -
strings]** There is **no** "prefer my follower" rule. The MCM has no follower-related listener option.

### 2.5 The MCM knobs that touch follower behaviour

`mods\CHIM\Source\Scripts\AIAgentMCMConfigScript.psc`:

| Key / label | line | meaning |
|---|---|---|
| `npc_sandbox_near` / **"NPCs Sandbox Near Player"** | 854, 1093, 1347 | "Let NPCs subtly move near the player during conversations" - this is the `FollowSoft`/`ComeCloser` prio-55 layer |
| `npc_walk_to_target` / "NPCs Walk To Target" | 1348 | |
| "Enable AI Actions" (`_toggle1OID_C`) | 1335, 2755 | master switch for the whole action layer |
| `_toggle1OID_D` "force default voice" | 2758 | *"If using mods like RDO, check this ... so dialog Follow me should appear"* - explicitly about the vanilla follow topic; **not needed** here (RDO is not installed, `lorerim-scan.md:16`) |
| Hotkey K2 | 2752 | "Double-tap to make the NPC in your crosshair **wait here**" → `StartWait`/`StartWaitSoft`, a CHIM wait that no framework knows about |
| "NPC Scene Safety" `_toggle_restrict_onscene` | 1351 | |

---

## 3. What "aware of each other" means in practice

- **CHIM → the frameworks: impossible today, and not needed.** SFF, QFC and SetHome have no listener,
  no mod event, no MCM API. They only need CHIM **not to lie to them**: don't create followers behind
  their back, don't freeze a follower they are steering, don't answer "wait here" with a package instead
  of the real actor value.
- **The frameworks → CHIM: cheap and high value.** The glue can read the truth every turn and put it in
  the prompt: *is she a follower, under which framework, primary or extra, waiting or following, has a
  home set, how many slots are left*. That is the whole of "aware of each other" that is actually
  buildable. See the `folstate` wire block in section 10.
- **"Work together" = one owner per verb.** Recruit / dismiss / wait / follow-again / trade / favour /
  settle-home belong to **the real dialogue entries** (menuless questing). CHIM's own follow shortcut
  stays as a **fallback only**, for an NPC who has no real recruit topic at all, and it must never run in
  the same turn as the real entry.

---

## 4. Where CHIM and the follower stack fight - with evidence

### 4.1 Double recruitment: CHIM `MakeFollower` creates a follower no framework owns **[V]**

`AIAgentNpcUtil.psc:376-397`:

```papyrus
akTarget.AddToFaction(PotentialFollowerFaction)      ; 0x0005c84d
akTarget.SetFactionRank(PotentialFollowerFaction, 0)
akTarget.AddToFaction(CurrentFollowerFaction)        ; 0x0005c84e
akTarget.SetFactionRank(CurrentFollowerFaction, 1)
Quest nwsFF = (Game.GetFormFromFile(0x0000434F, "nwsFollowerFramework.esp") as Quest)
if (nwsFF)                                            ; <- always None in LoreRim
    (nwsFF as nwsFollowerControllerScript).RecruitAction(akTarget)
endif
```

What SFF's real recruit path does instead (`SetFollower` → `PrepareFollowerActor`, decompiled from
`Scripts\dialoguefollowerscript.pex`) **[V]**:

```
SetFollower(ObjectReference FollowerRef):
    SyncSFFFollowerState()
    maxFollowers  = SFF_GetMaxFollowersSafe()
    currentCount  = SFF_GetTrackedFollowerCount()
    if IsManagedFollower(actor): PrepareFollowerActor(actor); return       ; already ours
    if SFF_GetPrimaryFollower() != None:
        if currentCount >= maxFollowers: return                            ; CAP ENFORCED
        SFF_AddExtraFollowerAlias(actor); PrepareFollowerActor(actor); ...
    else:
        if currentCount >= maxFollowers: return
        PrepareFollowerActor(actor); pFollowerAlias.ForceRefTo(actor)
    SyncSFFFollowerState()

PrepareFollowerActor(Actor a):
    a.RemoveFromFaction(pDismissedFollower)
    if 0 <= a.GetRelationshipRank(player) < 3: a.SetRelationshipRank(player, 3)
    a.SetPlayerTeammate(True, True)
    SFF_SKSE.AddVanillaFollower(a)            ; the DLL side of the multi-follower system
    a.StopCombatAlarm()
    a.SetAV("WaitingForPlayer", 0)
    a.EvaluatePackage()
```

So a CHIM "Join party" produces an NPC who:

| | CHIM MakeFollower | real SFF recruit |
|---|---|---|
| vanilla `CurrentFollowerFaction` rank 1 | **yes** | yes (via the DLL) |
| `SetPlayerTeammate` | **no** | yes |
| SFF alias (`Alias_Follower` / `ExtraFollower01..07`) | **no** | yes |
| `SFF_SKSE.AddVanillaFollower` | **no** | yes |
| `pPlayerFollowerCount` / `SFFCurrentFollowerCount` / `SFFCanRecruitMore` updated | **no** | yes (`SFF_UpdateFollowerGlobals`) |
| the Speech-based slot cap respected | **no - bypassed entirely** | yes |
| relationship rank raised to 3 (Ally) | **no** | yes |
| Requiem's recruitment gates respected | **no** | yes (they are INFO conditions) |

Downstream, because `IsPlayerTeammate()` is false, she is invisible to: **Quick Follower Commands**
(`qfcAliasScript.psc:246`), **Automatic Follower Teleporter**, **Considerate Followers**,
**Dynamic Follower Scaling**, **Follower Stats**, the vanilla "current follower" HUD, **and the glue's
own teammate checks** (`LRG_Main.psc:1010`, `LRG_Profile.psc:208`, `:412`). But because she *is* in
`CurrentFollowerFaction` rank 1, the **vanilla follower dialogue topics turn on for her** **[K - vanilla
INFO conditions are `GetFactionRank CurrentFollowerFaction >= 1` / `GetGlobalValue PlayerFollowerCount`;
not re-read from the plugin here]** - which is exactly what makes 4.2 reachable.

### 4.2 Wrong-target dismiss / wait - the real bug **[V, decompiled]**

```
GetDialogueFollowerTarget():
    target = Game.GetDialogueTarget()
    if target != None && IsManagedFollower(target): return target
    if SFFLastSpeaker != None && IsManagedFollower(SFFLastSpeaker): return SFFLastSpeaker
    return pFollowerAlias.GetActorReference()          ; <-- the fallback

IsManagedFollower(a) = SFF_IsPrimaryFollower(a) || SFF_IsExtraFollower(a)   ; alias membership only

FollowerWait():    SyncSFFFollowerState(); a = GetDialogueFollowerTarget()
                   a.SetAV("WaitingForPlayer",1); a.EvaluatePackage()
                   if SFF_IsPrimaryFollower(a): pFollowerAlias.RegisterForUpdateGameTime(72)
FollowerFollow():  ... a.SetAV("WaitingForPlayer",0); a.EvaluatePackage() ...
DismissFollower(iMessage,iSayLine):
                   a = GetDialogueFollowerTarget()
                   wasPrimary = SFF_IsPrimaryFollower(a)
                   ShowFollowerDismissMessageByType(iMessage)
                   CleanupDismissedFollowerActor(a, iSayLine, wasPrimary)
                       -> StopCombatAlarm, AddToFaction(DismissedFollower),
                          SetPlayerTeammate(False,True), RemoveFromFaction(CurrentHireling),
                          SetAV("WaitingForPlayer",0), remove hunting bow/arrows, DismissHireling
                   if wasPrimary: RestoreFollowerEssential(a); pFollowerAlias.Clear()
                   else: SFF_RemoveExtraFollowerAlias(a)
                   SFF_ClearLastSpeakerIfMatches(a); SyncSFFFollowerState();
                   SFF_PromoteExtraToPrimaryIfNeeded(); SyncSFFFollowerState()
```

`SFFLastSpeaker` is only ever written by `SFF_FollowerAliasScript.OnActivate` (`SFF_FollowerAliasScript.psc:5-21`),
which is attached to the **aliases**. A CHIM pseudo-follower is in no alias → that event never fires for
her → `GetDialogueFollowerTarget()` falls all the way through to `pFollowerAlias.GetActorReference()`.

**Failure scenario (concrete):** the player has Lydia as the SFF primary follower. CHIM's
`Join_Jordan_Party` is offered on Lisette (it is in the live catalog, `is_activated = t`,
`available_to_npc = t`, and the server log shows it being offered - see 4.7). The LLM calls it. Lisette
is now `CurrentFollowerFaction` rank 1, so the vanilla dismiss topic shows on her. The player says
"it's time we parted ways" to **Lisette** → `MFMD_FollowerDismiss.Fragment_0` →
`DismissFollower(0,0)` → `GetDialogueFollowerTarget()` returns **Lydia** → **Lydia is dismissed**, the
dismiss message plays, `pFollowerAlias` is cleared, an extra follower is promoted - and **Lisette stays
in `CurrentFollowerFaction` forever**, with no alias, no teammate flag and no way to dismiss her through
any UI. Same shape for "Wait here" (Lydia stops, Lisette does not) and "Follow me" again. **[I - each
step is [V]; the composition is untested in game]**

There is also a quieter variant: `MFMD_FollowerDismiss` guards with
`If !(akspeaker.IsInFaction(DismissedFollowerFaction))` **[V]** - the guard is on the *speaker*, the
action is on the *alias*, so the guard does not protect against this at all.

### 4.3 Package priority collisions **[V for the numbers, [U] for the outcomes]**

| Layer | priority | set by |
|---|---|---|
| CHIM `FollowPackage` / `FollowPlayerPackage` / `MoveToPackage` / `SandboxWorkPackage` / `TravelToPackage` / `StartWait` | **100** (Travel 99/100) | `ActorUtil.AddPackageOverride` |
| CHIM `SandboxPackage` (stayAtPlace) | 99 | idem |
| CHIM `FollowPackageSoft` / `ComeCloser` | **55** | idem |
| CHIM `StartWaitSoft` | **50** | idem |
| glue conversation-hold `doNothing` (opt-in, `bConvHoldPackage` default **OFF**) | **60** | `LRG_Main.psc:1088` |
| SFF / vanilla follow, wait and `SFF_FollowerSandbox` | **quest-alias packages**, no numeric override | `DialogueFollower` aliases |

`ActorUtil.AddPackageOverride` inserts above the actor's ordinary package stack, which is where alias
packages live, so **every CHIM override at 50-100 outranks the vanilla/SFF follow package**. A CHIM
`TravelTo` / `Sandbox` / `StartWait` on a real SFF follower therefore takes her away from the player and
`WaitingForPlayer` is *not* set, so QFC's "stop waiting", the vanilla "follow me" topic and AFT all think
she is following while she is standing in a tavern sandboxing. The only thing that restores her is CHIM's
own `ResetPackages` / `StopCurrent`. **[I on the precedence, [U] in game - this is the number-one
playtest item.]**

The mirror case: CHIM's `ResetPackages` (`:21-68`) removes only **CHIM's own 11 packages** by form, so
it cannot clobber SFF. Good. And `StopCurrent` (`:603`) calls `ActorUtil.ClearPackageOverride(npc)`
which clears **every** override on the actor **including another mod's** - including the glue's own
conversation-hold package if `bConvHoldPackage` were on. **[V]**

### 4.4 Relationship rank and factions **[V]**

- CHIM `AttackTarget` (`:1024-1030`) does `npc.SetRelationshipRank(target,-3)` **both ways** and
  `SetActorValue("Confidence",4)`. On a follower that permanently rewrites her relationship to the
  player's enemies; on the **player** as target it would set rank -3 with the player, which SFF's
  `PrepareFollowerActor` would then silently raise back to 3 on the next recruit. Messy but not fatal.
- CHIM never writes rank 3 on recruit, so a CHIM pseudo-follower keeps whatever rank she had - another
  reason her behaviour differs from a real follower (morality/aggression checks, "Ally" lines).
- CHIM's own factions (`AIAgent.esp` FollowFaction `0x01BC24`, WaitFaction `0x02021E`, SandboxFaction
  `0x21246`, MoveToFaction `0x01A69B`, TravelToFaction `0x01A69C`, SeatFaction `0x01C6EA`,
  AttackFaction `0x01B6C1`) are private to CHIM and collide with nothing. **[V]**
- `stayAtPlace` also puts the NPC into vanilla `BardAudienceExcludedFaction 0x10fcb4` rank 1
  (`:904-905`) and **never removes it**. On a follower that is a permanent, invisible edit. **[V]**

### 4.5 Trade / inventory **[V]**

`OpenInventory` (`:929-987`) gives `npc.OpenInventory(true)` only to
`IsPlayerTeammate() && GetFactionRank(CurrentFollowerFaction) > -1`. A CHIM pseudo-follower fails the
first test → `ShowBarterMenu()`, i.e. she *sells* you her gear at merchant prices instead of carrying it.
A real SFF follower passes → correct behaviour. This is the one place CHIM's own code already does the
right test; it is also the proof that CHIM expects `SetPlayerTeammate` to have been done by someone else.

### 4.6 The action set flips under the player's feet **[V]**

Because `IS_NPC` is derived from `CurrentParty` membership and `CurrentParty` is filled from the engine's
teammate set **[I]**, recruiting a CHIM agent as a real follower changes her offered actions from the
`available_to_npc` column to the `available_to_followers` column *in the middle of a save*:

- **loses** `FollowPlayer`, `MakeFollower`, `MoveTo`, **`EndConversation`**
- **gains** `SheatheWeapon`
- everything the glue registers keeps working: the glue's own rows are upserted with
  `'available_to_npc' => 1, 'available_to_followers' => 1` (`ext/lorerim_glue/lib/lrg_actions.php:69`,
  `lib/lrg_dialogue.php:2106`). **Good - this was done right.**

Losing `EndConversation` for followers matters to the pt8 walkaway work: the whole
`lrgHideActions(['EndConversation'])` logic in `lrg_dialogue.php:1214` becomes a no-op for a follower,
and whatever `EndConversation` was doing for the conversation lifecycle simply stops happening.
**[V for the mechanism, [U] for the consequence]**

### 4.7 What the owner's logs actually show **[V]**

- `HerikaServer/log/chim.log` - `FollowPlayer` was really issued three times in the last two sessions:
  `Lisette|command|FollowPlayer@Jordan` at 23:35:55 and 04:07:45 / 04:08:36 / 04:17:45,
  `Braste|command|FollowPlayer@Jordan` at 23:38:37. No `MakeFollower` ever reached the plugin.
- `log/apache_error.log` + `log/service.log` - `[FUNCTIONS] NOT Skipping function: Join_Jordan_Party
  <MakeFollower>` (it was offered) alternating with `[FUNCTION] Removing 15 Join_Jordan_Party:MakeFollower`
  (the glue hid it that turn - `LRG_MOVEMENT_ACTIONS`, `lrg_actions.php:168`, dropped at `:871` / `:888`
  / `:970`). So **the only thing that has been stopping `MakeFollower` so far is the glue's scene/gate
  logic, not a setting.**
- `log/lorerim_glue.log` (142 KB) contains **zero** lines with `mate=1`, `witfol=[1-9]` or
  `companion_present` - **no playtest has ever had a real follower present.** Every conclusion about the
  follower path is therefore [U] in game.
- `Documents\My Games\Skyrim Special Edition\SKSE\` has **no** Papyrus script log (logging off), so there
  is no game-side evidence either way.

### 4.8 Things that are *not* a conflict (checked, clean)

- SFF ships a **stale** `Source\Scripts\DialogueFollowerScript.psc` (it is the vanilla 1.9 script and
  does **not** contain any `SFF_*` function) while `Scripts\dialoguefollowerscript.pex` is the real,
  extended one. **[V]** This bites anyone compiling against it - see the build-brief risk R4.
- `nwsFollowerControllerScript.pex` is not shipped by CHIM and does not exist in the list, but the cast
  at `AIAgentNpcUtil.psc:391` sits inside `if (nwsFF)` so it is never executed. **[V]**
- `ExtCmdCHIMNFF_*` wrappers (WaitHere / FollowMe / BehindMe) exist upstream in CHIM's unit tests only
  (`unittests/tests/ActionCatalogTest.php:470-486`) and there is a code comment about them at
  `functions/functions.php:2758-2763`. **Not installed here.** **[V]**
- CHIM's `ResetPackages` removes only its own packages (4.3).
- The glue's `LRG_MOVEMENT_ACTIONS` (`lrg_actions.php:167-171`) already lists `Follow`, `FollowPlayer`,
  `MakeFollower`, `MoveTo`, `TravelTo`, `WaitHere`, `ComeCloser`, `ReturnBackHome`, so during a scene /
  intimacy gate none of them can fire. **[V]**

---

## 5. The glue's own follower touches - are they safe with SFF?

| Glue behaviour | where | verdict |
|---|---|---|
| **Conversation hold skips teammates** - `if akNpc.IsPlayerTeammate() : return "she follows you already"` | `LRG_Main.psc:1010-1011` | **Right idea, wrong flag.** It correctly protects a real SFF / Inigo / Lucien follower from `SetDontMove`. It does **not** protect a CHIM pseudo-follower (not a teammate) - she gets frozen while a prio-100 `FollowPlayerPackage` runs underneath. Harmless today (SetDontMove wins), but the reason line in the log will be wrong. Extend the test to *"teammate OR `CHIM_FollowPlayerActive == 1` OR `CurrentFollowerFaction` rank >= 1"*. |
| **Hold mechanism is `SetDontMove`**, opt-in second layer `doNothing` at prio 60 | `LRG_Main.psc:839-842`, `:971`, `:1076-1090`, release `:1128` | Safe with SFF: `SetDontMove` does not touch packages or aliases, and the glue re-asserts it after CHIM's `ResetPackages` (`:1265`). Keep `bConvHoldPackage` **off** with followers - prio 60 would out-rank SFF's alias package and `StopCurrent`'s `ClearPackageOverride` could strip it. |
| **Witness scan counts followers separately** - `if a.IsPlayerTeammate() : fol += 1` before the LOS test | `LRG_Profile.psc:185-225`, esp. `:208-209` | Correct for SFF / vanilla / custom followers. **Misses a CHIM pseudo-follower**, who will be counted as an ordinary `wit` (or dropped entirely behind a closed door) - so "my companion is right there" would read as a stranger. |
| **Privacy gate** `followers_ok` → `companion_present` | `lib/lrg_core.php:1136-1149`, config `lrg_config.default.json:68`, per-profile `:136-269` | Works as designed, **never exercised**: `witfol` has been 0 in every logged turn (4.7). The `married_secret` path forces `followers_ok = false` (`:1144`). |
| **`mate=` fact in the snapshot** | `LRG_Profile.psc:412` | Sent to the server but **not used by any gate or prompt** - grep of `ext/lorerim_glue` finds no reader of `mate`. Free win: it is already on the wire. |
| **invite = "follow me somewhere private"** | `lrg_actions.php:1571` (records the invite), `:2102-2106` (`state: followed`), `lib/lrg_core.php:490-499`, `:1184-1193` (`lrgGateMode` → `follow`) | **The glue never makes her follow.** It records the invitation server-side and tells her *in words* to say it. The optional `lead_the_way` (default **off**, `lrg_config.default.json:292`) at `lrg_actions.php:2129-2133` may replace her line with CHIM's own `FollowPlayer@` **only when the place is a rented room and `FollowPlayer` is offered this turn**; `lrgMovementHint` (`:2405`) merely mentions the action. So the glue rides on CHIM's package follow, with all of 4.3's consequences, and **only when the owner turns `lead_the_way` on**. |
| **OStim scenes with a follower present** | `LRG_OStim.psc` (no teammate / follower code at all - grep clean) | The scene itself is follower-blind. The gate in front of it is not (`companion_present`). Residual risk: a real follower standing next to a scene will keep her own follow package and walk into it; nothing pauses her. **[U]** |
| **`CONV_MOVE_ACTIONS`** (hold yields to a CHIM move) | `LRG_Main.psc:26` | Already contains `FollowPlayer`, `Follow`, `MakeFollower`, `WaitHere`. Correct. |

---

## 6. Menuless questing x the follower frameworks' own dialogue

### 6.1 The real entries the index and matcher must know

All of these live on quest **`DialogueFollower` = Skyrim.esm 0x000750BA** (proved by SFF's fragment
script name `QF_DialogueFollower_000750BA.pex` **[V]**), and are **won by Missing Follower Dialogue
Fix** in this load order (1.4).

| Topic EDID (verified present in `Missing Follower Dialogue Fix.esp`) | player line (vanilla) | fragment that runs | real effect |
|---|---|---|---|
| *(recruit INFO, on the NPC's own quest/`DialogueFollower`)* | "Follow me, I need your help." | `MFMD_FollowerRecruit` | `(pDialogueFollower as DialogueFollowerScript).SetFollower(akSpeaker)` → SFF cap check → alias → `PrepareFollowerActor` **[V]** |
| *(recruit-reject INFO)* | - | `MFMD_FollowerRecruitReject` | refusal line only |
| `DialogueFollowerDismissTopic` | "It's time for us to part ways." | `MFMD_FollowerDismiss` | `DismissFollower(0, 0)` on `GetDialogueFollowerTarget()` **[V]** |
| `DialogueFollowerWaitTopic` + `DialogueFollowerWaitDummyTopic` / `...DummyBranch` | "Wait here." | `MFMD_FollowerWait` | `FollowerWait()` → `SetAV WaitingForPlayer 1` + 72 h timer if primary **[V]** |
| `DialogueFollowerFollowTopic` + `DialogueFollowerFollowDummyTopic` / `...DummyBranch` | "Follow me." | `MFMD_FollowerFollow` | `FollowerFollow()` → `SetAV WaitingForPlayer 0` **[V]** |
| `DialogueFollowerTradeTopic` | "I need to trade some things with you." | `MFMD_FollowerTrade` | `akSpeaker.OpenInventory()` **[V]** |
| `DialogueFollowerFavorStateTopic` | "I need you to do something." | `MFMD_FollowerFavorStart` | `akSpeaker.SetDoingFavor()` **[V]** |
| `DialogueFollowerContinueFavorState` / `DialogueFollowerEndFavorState` | "(never mind)" / end | `MFMD_FollowerFavorEnd` | clears the favour state **[V]** |
| `DialogueFollowerDoingFavorBlockingTopic` | blocking topic while a favour is running | - | **must never be selected** |
| the `*Animal*` twins: `MFMD_FollowerAnimal{Recruit,RecruitReject,Follow,Wait,Dismiss,Trade,FavorStart,FavorEnd}` | pets / animal followers | `SetAnimal` / `AnimalWait` / `AnimalFollow` / `DismissAnimal` **[V]** | a **separate** single slot (`pAnimalAlias`, `pPlayerAnimalCount`) |
| Set Follower Home: two topics | "settle down here" / "come with me" | `SetHomeSetHomeTopicInfoScript` / `SetHomeUnsetHomeTopicInfoScript` | `SetHomeQuestScript.SetHome/UnsetHome(akSpeaker)` **[V]** |
| Quick Follower Commands | one topic fragment `qfcManageScript.Fragment_0` | `akSpeaker.AddSpell(qfcSpell)` **[V]** - enrolls her in the command UI; the actual commands are **hotkeys/wheel, not dialogue** |
| hireling rehire | `CanRehire{Belrand,Erik,Jenassa,Marcurio,Stenvar,Vorstag}` globals + `RehireWindow`, `HirelingCommentScript`, `SetHirelingRehire` in the SFF esp **[V]** | "I need your help again" | `HirelingRehireScript.DismissHireling(base)` on dismiss **[V]** |

**Conditions.** The gating globals SFF maintains are (`SFF_UpdateFollowerGlobals`, **[V]**):

```
pPlayerFollowerCount    = (iSFFFollowerCount > 0) ? 1 : 0     ; the vanilla global, kept "1 if any"
SFFCurrentFollowerCount = iSFFFollowerCount
SFFCanRecruitMore       = (iSFFFollowerCount < SFF_GetMaxFollowersSafe()) ? 1 : 0
SFF_GetMaxFollowersSafe = min(max(SFF_SKSE.GetMaxFollowers(),1), SFFExtraAliases.Length + 1)
```

The recruit INFO's availability condition in this list is therefore **`SFFCanRecruitMore`, not
`PlayerFollowerCount`** - that is the whole point of the SFF esp and of
`Simple Follower Framework Patch.esp`. **[I - the globals exist and are maintained for exactly this
purpose; the CTDA records themselves were not parsed]** The rest of the conditions are the vanilla ones
(`GetFactionRank CurrentFollowerFaction`, `GetActorValue WaitingForPlayer`, `GetIsID` for hirelings,
`GetRelationshipRank`) plus **Requiem / LoreRim additions** on the recruit INFOs of specific NPCs
(`p2-requiem.md:64` Ghorbash, R12 Borgakh). **The index builder must read conditions at runtime, never
pre-bake them** - already PHASE2_DESIGN's rule.

### 6.2 What the index / matcher must do

1. **Own the verbs.** A voice request that means recruit / dismiss / wait / follow-again / trade /
   favour / settle-home must map to the **real entry** in the list above and to nothing else. Build an
   explicit **verb → topic-EDID family** table (the 16 rows above) rather than relying on lexical
   similarity: the player lines are short, generic and collide with everything ("wait", "follow me",
   "trade").
2. **Never both.** In the same turn in which the matcher resolves a follower verb to a real entry, the
   server must `lrgHideActions(['MakeFollower','Follow','FollowPlayer','WaitHere','OpenInventory',
   'OpenInventory2','MoveTo','ReturnBackHome'])` so the LLM cannot also fire the CHIM shortcut. The
   mechanism already exists (`lrg_actions.php:143-150`; the core's final filter at
   `functions/functions.php:2790-2804` prunes `$GLOBALS["FUNCTIONS"]` afterwards). **[V]**
3. **Ranking.** PHASE2_DESIGN already penalises follower-framework command menus (−3, D-22 / CMP-M4).
   Keep that for *browsing*, but a **direct verb match must bypass the ranking penalty entirely**, or
   "wait here" will never reach the real entry on a follower carrying SFF + QFC + AJO + Missives topics.
4. **Never select** `DialogueFollowerDoingFavorBlockingTopic`, and never select a dismiss entry without
   the two-step confirmation - dismissal is commit-class, exactly like the romance entries in
   PHASE2_DESIGN:889.
5. **`Game.GetDialogueTarget()` must be right.** SFF resolves the actor from it (4.2). So the menuless
   driver **must** have a real Dialogue Menu open on the *correct* NPC when a follower fragment runs. A
   "programmatic" INFO execution with no menu open would make `GetDialogueTarget()` return None and send
   the command to `pFollowerAlias` - i.e. the wrong follower again. **This is a hard constraint on the
   driver design.** **[V on the code path, [U] on the driver]**
6. **Animal topics are a separate slot.** Don't let "follow me" on a dog run the humanoid fragment.

---

## 7. Cooperation - the APIs that actually exist

### 7.1 SFF: `DialogueFollowerScript` on quest `DialogueFollower` (Skyrim.esm 0x000750BA)

Decompiled from `F:\Modlists\LoreRim\mods\Simple Follower Framework\Scripts\dialoguefollowerscript.pex`
(PEX v3.2, gameID 1). **These signatures are exact.** **[V]**

```papyrus
; --- state queries (safe, read-only, no side effects except SyncSFFFollowerState) ---
Bool   Function IsManagedFollower(Actor akActor)            ; primary OR extra
Bool   Function SFF_IsPrimaryFollower(Actor akActor)
Bool   Function SFF_IsExtraFollower(Actor akActor)
Actor  Function SFF_GetPrimaryFollower()
Actor  Function GetDialogueFollowerTarget()                 ; GetDialogueTarget -> SFFLastSpeaker -> pFollowerAlias
Int    Function SFF_GetTrackedFollowerCount()
Int    Function SFF_GetExtraFollowerCount()
Int    Function SFF_GetMaxFollowersSafe()                   ; min(SFF_SKSE.GetMaxFollowers(), aliases+1)
Int    Function SFF_FindFirstFreeExtraAliasIndex()
Int    Function SFF_GetExtraAliasIndexByActor(Actor akActor)
ReferenceAlias Function SFF_GetExtraAliasByActor(Actor akActor)
ReferenceAlias Function SFF_GetAliasForTrackedFollower(Actor akActor)

; --- state maintenance ---
Function SyncSFFFollowerState()                             ; cleanup dead + recount + update globals
Function SFF_UpdateFollowerGlobals()
Int  Function SFF_CleanupDeadExtraFollowers()
Function SFF_SetLastSpeaker(Actor akActor)                  ; <<-- THE REPAIR HOOK (see 7.3)
Function SFF_ClearLastSpeakerIfMatches(Actor akActor)
Function SFF_PromoteExtraToPrimaryIfNeeded()

; --- mutations (prefer the real dialogue entry; use these only as documented in 10) ---
Function SetFollower(ObjectReference FollowerRef)           ; full recruit, CAP ENFORCED
Function PrepareFollowerActor(Actor FollowerActor)          ; teammate + AddVanillaFollower + rank 3
Bool Function SFF_AddExtraFollowerAlias(Actor akActor)
Bool Function SFF_RemoveExtraFollowerAlias(Actor akActor)
Actor Function SFF_PopFirstExtraFollower()
Function FollowerWait()                                     ; acts on GetDialogueFollowerTarget()
Function FollowerFollow()                                   ; idem
Function DismissFollower(Int iMessage, Int iSayLine)        ; idem; iMessage 0..5, iSayLine 0/1
Function CleanupDismissedFollowerActor(Actor a, Int iSayLine, Bool abUnregisterPrimaryTimer)
Function ShowFollowerDismissMessageByType(Int iMessage)
Function SetAnimal(ObjectReference AnimalRef)
Function AnimalWait()
Function AnimalFollow()
Function DismissAnimal()
```

Properties worth reading: `SFFExtraAliases` (`ReferenceAlias[]`), `pFollowerAlias`, `pAnimalAlias`,
`pDismissedFollower` (Faction), `pCurrentHireling` (Faction), `pPlayerFollowerCount`,
`SFFCurrentFollowerCount`, `SFFCanRecruitMore` (all `GlobalVariable`), `iSFFFollowerCount` (Int).
Variable `SFFLastSpeaker` (Actor) is **not** a property - reach it only through the two accessors.

### 7.2 SFF: the SKSE natives (`SFF_SKSE.psc:3-8`, confirmed by pex) **[V]**

```papyrus
Bool Function AddVanillaFollower(Actor a)        Global Native   ; puts them in the DLL's multi-follower set
Bool Function IsVanillaFollower(Actor a)         Global Native   ; <<-- cheapest truthful "is she a follower"
Int  Function GetMaxFollowers()                  Global Native   ; honours bFollowerOptionSelector / Speech
Bool Function ApplyFollowerEssential(Actor a)    Global Native
Bool Function RestoreFollowerEssential(Actor a)  Global Native
```

`SFF_SKSE` is a `Quest`-extending script with only global natives, so it can be called from anywhere with
no property and no cast: `SFF_SKSE.IsVanillaFollower(akNpc)`. **This is the single best
"aware of each other" primitive in the whole list.**

### 7.3 The repair hook

Because `GetDialogueFollowerTarget()` prefers `SFFLastSpeaker` over the alias fallback, a single call

```papyrus
(Quest.GetQuest("DialogueFollower") as DialogueFollowerScript).SFF_SetLastSpeaker(akNpc)
```

before a follower verb runs would make the wrong-target bug (4.2) **unreachable** for an NPC the glue
knows about... **but only if `IsManagedFollower(akNpc)` is true**, because `GetDialogueFollowerTarget`
checks `IsManagedFollower(SFFLastSpeaker)` too. **[V]** So the real repair for a CHIM pseudo-follower is
to make her a *real* one (`SetFollower`) or to undo CHIM's faction edit - see 10 / G3.

### 7.4 Everything else

- **Quick Follower Commands**: `qfcAliasScript` is a `ReferenceAlias` with hotkeys; it listens for the
  mod events **`QuickFollowerMenu` (strArg = `StartWaiting` | `StopWaiting` | `Inventory` | `Teleport`)**,
  `QuickFollowerMenu_Toggle`, `QuickFollowerMenu_Delete` (`qfcAliasScript.psc:44-46`, `:160-183`).
  **[V]** Those are `RegisterForModEvent` handlers, so the glue *could* send
  `QuickFollowerMenu` with `StartWaiting` and QFC would apply it **to the whole squad** (`doCommand`
  loops the FormList, `:185-195`) - squad-wide only, never per-NPC. Useful for "everyone wait here",
  useless for "you wait here".
- **Set Follower Home**: `SetHomeQuestScript.SetHome(Actor)` / `.UnsetHome(Actor)` are plain public
  quest functions **[V]** - callable with a cast, but the two dialogue topics do exactly this, so use the
  topics.
- **Custom-voiced followers** (Inigo, Lucien, Auri, Remiel, Taliesin, Katana, Gore, Serana/SDE): no
  public API, no mod events found. Dialogue only. **[V for Auri/Taliesin script listings; [I] for the
  BSA-packed ones - Inigo, Lucien, HLIORemi, Katana, GORE ship their scripts inside `.bsa`, which I did
  not open.]**
- **AFT / Considerate Followers / Dynamic Follower Scaling / Keep Up / AutoSneak**: DLL-only, ini-only,
  no API.

---

## 8. What is still unknown (and why)

| # | Unknown | Why | How to close it |
|---|---|---|---|
| U1 | The exact CTDA conditions on the recruit / wait / follow / dismiss INFOs **as they win in this list** | Needs an xEdit / plugin-record parse; `Missing Follower Dialogue Fix.esp`, `Simple Follower Framework.esp`, `Simple Follower Framework Patch.esp` and Requiem all touch them | The dialogue index builder reads them at runtime anyway (PHASE2_DESIGN); dump the resolved topic list on one real follower in game |
| U2 | Whether `AddPackageOverride` at 50-100 really beats SFF's alias packages | Engine behaviour; no log | Playtest P-F3 (section 10) |
| U3 | Whether the DLL fills `CurrentParty` from `IsPlayerTeammate` or from CHIM's own agent list | DLL strings only | Recruit one CHIM agent, then `select value from conf_opts where id='CurrentParty'` |
| U4 | Custom-voiced followers' internal states (Inigo's `WaitingForPlayer == -1`, Auri's `A18_AuriController`, Taliesin's `TallyController`) | scripts inside BSAs for several of them | Only matters if the glue ever writes `WaitingForPlayer` - so **don't** |
| U5 | Whether `Considerate Followers` mutes followers during a *menuless* session | needs the real Dialogue Menu to be open | Playtest P-F6 |

---

## 9. OWNER SETTINGS - do these now, before any build

All of these are **owner actions**, not glue changes. Nothing here needs the game to be running except
the MCM ones.

### 9.1 CHIM web UI → Actions / action catalog (the `core_action` table)

| Action (as shown) | code | do | why |
|---|---|---|---|
| **`Join_#PLAYER_NAME#_Party`** | `MakeFollower` | **switch OFF** (`is_activated` → false) | It is the only path to 4.1/4.2. It cannot be made safe without NFF. Leave it off until the glue's G3 lands. |
| **`Follow`** | `Follow` | **switch OFF** | Follow *another actor* at prio 100; it strips the NPC's own packages, has no owner and no end condition. Nothing in the glue uses it. |
| `Follow_#PLAYER_NAME#` | `FollowPlayer` | **leave ON** | It is what `lead_the_way` and `lrgMovementHint` reference, and it is the only "come with me" the LLM has that is not a recruitment. Understand it is a *package*, not a recruitment. |
| `Wait_Here` | `WaitHere` | **leave OFF** (already `is_activated = f`) | its Papyrus body is a deprecated no-op (`AIAgentAIMind.psc:1488-1498`) - it would print a notification and do nothing |
| `LeadTheWayTo` | `LeadTheWayTo` | leave OFF (already) | |
| `Move_To` | `MoveTo` | leave ON | bounded, ends itself |
| `Trade_Items` / `Accept_Gift` | `OpenInventory` / `OpenInventory2` | leave ON **for now** | already flagged for re-routing by `p2-requiem.md` R1/R2-C2 - unchanged by this investigation |

### 9.2 CHIM MCM (in game)

| Page / option | set to | why |
|---|---|---|
| Behavior → **"NPCs Sandbox Near Player"** (`npc_sandbox_near`) | **off** while testing with a real follower | it is the prio-55 `FollowSoft`/`ComeCloser` layer; with a follower it competes with her follow package for no benefit |
| Behavior → "NPCs Walk To Target" (`npc_walk_to_target`) | off while testing | same reason |
| Keys → **K2 hotkey**: "double-tap to make the NPC in your crosshair wait here" | **don't use it on a follower** | it calls `StartWait`/`StartWaitSoft` (prio 100/50 package), not `WaitingForPlayer` - the follower will stand still and every framework will still think she is following |
| "force default voice" (`_toggle1OID_D`, MCM:2758) | leave **off** | it is an RDO workaround; RDO is not installed (`lorerim-scan.md:16`) and turning it on kills custom-voiced follower audio |
| Actions → "Enable AI Actions" | leave on | |

### 9.3 Simple Follower Framework (`mods\Simple Follower Framework\SKSE\Plugins\SimpleFollowerFramework.ini`) - owner's call, no change required

Current: `bFollowerOptionSelector=2`, `iSpeechLevelsPerSlot=25` → **the slot count is the player's Speech
skill / 25 + 1**. That means early game = **1 slot**, and CHIM's "join my party" would be the *only* way
to get a second follower - which is exactly the unsupported path. If the owner wants to test the
follower paths right away, either raise Speech or temporarily set `bFollowerOptionSelector=0` with
`iMaxFollowers=2`. `bFollowerEssential=0` and `bFriendlyFireProtection=0` are fine as they are.
SFF has **no MCM** - it uses SKSE Menu Framework; the ini is the real control surface. **[V]**

### 9.4 Nothing to do in an NFF MCM

There is no NFF and no NFF MCM in this install.

---

## 10. BUILD BRIEF for the glue (next round can execute this without re-reading the mods)

> Ordering note (OWNER_ADDENDA item 10): this lands **after** the v0.5 round, because G1/G2/G5 touch
> `LRG_Main` and `LRG_Dialogue`, which the v0.5 lanes own.

### Game side - new file `LRG_Followers.psc` (all globals, no state, no properties)

**G1 - `FolState(Actor akNpc) Global` → one `;`-separated fact string.** Cheap, no casts:

```
fw=<none|sff|custom|chim>      ; sff = SFF_SKSE.IsVanillaFollower(akNpc)
mate=<0|1>                     ; akNpc.IsPlayerTeammate()
cff=<-1..n>                    ; akNpc.GetFactionRank(Game.GetForm(0x0005C84E))     ; CurrentFollower
pff=<-1..n>                    ; akNpc.GetFactionRank(Game.GetForm(0x0005C84D))     ; PotentialFollower
wait=<-1|0|1>                  ; akNpc.GetActorValue("WaitingForPlayer")            ; -1 = Inigo-dismissed
chimfol=<0|1>                  ; StorageUtil.GetIntValue(akNpc,"CHIM_FollowPlayerActive",0)
rank=<n>                       ; GetRelationshipRank(player)
slots=<used>/<max>             ; SFF_GetTrackedFollowerCount() / SFF_GetMaxFollowersSafe()
prim=<0|1> extra=<0|1>         ; SFF_IsPrimaryFollower / SFF_IsExtraFollower
ghost=<0|1>                    ; cff>=1 AND mate=0  -> a CHIM pseudo-follower (the 4.1 state)
```

`fw=custom` when `mate=1` but `SFF_SKSE.IsVanillaFollower()` is false **and** `IsManagedFollower()` is
false → Inigo / Lucien / Auri / Remiel / Taliesin / Katana / Gore / Serana.

**G2 - `IsFollowerLike(Actor)`** = `mate || chimfol || cff >= 1`. Use it to replace the bare
`IsPlayerTeammate()` at `LRG_Main.psc:1010` (conversation-hold skip) and to add a `witchim` counter next
to `witfol` in `LRG_Profile.WitnessScan` (`:208`).

**G3 - `RepairGhost(Actor akNpc)`** - the fix for 4.1/4.2. Only when `ghost=1`:
- if `SFF_GetTrackedFollowerCount() < SFF_GetMaxFollowersSafe()` → `SetFollower(akNpc as ObjectReference)`
  (promotes her to a real, framework-owned follower; the cap is respected inside SFF);
- else → **undo CHIM's edit**: `akNpc.RemoveFromFaction(CurrentFollowerFaction)` and
  `RemoveFromFaction(PotentialFollowerFaction)`, then `AIAgentAIMind.stayAtPlace(akNpc, 1)` so she still
  *walks* with the player without any framework thinking she is recruited.
- Either way, log it and tell the server (`lrg_log`).
- **Run it on load and whenever a `MakeFollower` is seen in `NoteChimAction`** - `MakeFollower` is
  already in `CONV_MOVE_ACTIONS` (`LRG_Main.psc:26`), so the hook point exists.

**G4 - `SetWait(Actor, bool)` / `SetFollowAgain(Actor)`** - the framework-agnostic wait:
`SetActorValue("WaitingForPlayer", 1|0)` + `EvaluatePackage()` (exactly what QFC does,
`qfcAliasScript.psc:220-234`). **Never write -1.** **Never** call SFF's `FollowerWait()` /
`FollowerFollow()` from Papyrus - they act on `GetDialogueFollowerTarget()`, not on the actor you pass.

**G5 - compile-time note (RISK R4).** `LRG_Followers.psc` must **not** need a cast to
`DialogueFollowerScript` for anything in G1/G2/G4 - use `SFF_SKSE.*` globals and plain `Actor` calls.
Only G3's `SetFollower` needs the cast. To compile it:
- put a **header-only** `DialogueFollowerScript.psc` (declaring just `SetFollower`,
  `SFF_GetTrackedFollowerCount`, `SFF_GetMaxFollowersSafe`, `IsManagedFollower`, `SFF_SetLastSpeaker`)
  in the glue's **compile-import folder only**;
- **never ship `DialogueFollowerScript.pex`** - deploying one would overwrite SFF's real script and
  break every follower in the save. Add it to the deploy denylist and to the release checklist.
- The `Source\Scripts\DialogueFollowerScript.psc` SFF ships is the **stale vanilla 1.9 script** and does
  not contain the `SFF_*` functions (4.8) - do not compile against it, and do not "fix" it in place.

### Server side - `ext/lorerim_glue`

**S1 - wire key `folstate`** in the existing `lrg_npcstate` snapshot (`LRG_Profile` already sends a
`;`-separated string; keys are additively safe). Parse in `lib/lrg_core.php` next to the other state
keys. No new message type.

**S2 - prompt block.** When `fw != none`, emit a compact, factual block (the LLM is told; the
framework is told nothing - section 3):

```
<companion_status>
# COMPANION STATUS
<Name> travels with the player as a companion (<primary|second|third...> of <used>/<max>).
She is <following the player | waiting here, where the player left her>.
<She has a home set at ...>          (only when SetHome reports one)
The player can ask her to wait, to follow again, to trade, to do him a favour,
or to part ways - those are her own words to give, not yours to narrate.
</companion_status>
```

Do **not** duplicate CHIM's `<adventuring_party>` block; that one keys off `CurrentParty` and will
appear on its own once she is a real follower (2.3).

**S3 - `ghost=1` handling.** Log it loudly (`lorerim_glue.log`), and for that turn
`lrgHideActions(['MakeFollower','Follow','FollowPlayer'])` so nothing makes it worse.

**S4 - the "never both" rule.** In `lrg_dialogue.php`, when the matcher resolves a **follower verb** to
a real entry: `lrgHideActions(['MakeFollower','Follow','FollowPlayer','WaitHere','MoveTo',
'ReturnBackHome','OpenInventory','OpenInventory2'])` for that turn (mechanism: `lrg_actions.php:143-150`).

**S5 - follower verb table** in the index/matcher: the 16 rows of 6.1, keyed by topic EDID family, with
a direct-match bypass of the follower-framework ranking penalty (6.2 item 3), a two-step confirmation on
dismiss, and a hard exclusion of `DialogueFollowerDoingFavorBlockingTopic` and of the `*Animal*` twins
for humanoid speakers.

**S6 - action-catalog health check.** Add `MakeFollower` and `Follow` to whatever the glue already
asserts about the catalog, and warn in the log if either is `is_activated = t` (9.1).

**S7 - `EndConversation` is not offered to followers** (4.6): guard `lrg_dialogue.php:1214`'s
`lrgHideActions(['EndConversation'])` with `lrgIsOffered('EndConversation')` and re-test the pt8 walkaway
behaviour with a real follower.

### MCM keys (LRG MCM, all default = today's behaviour)

| key | default | meaning |
|---|---|---|
| `bFollowerAware:Followers` | **1** | send `folstate` and the companion prompt block at all |
| `bFollowerRepair:Followers` | **1** | G3 RepairGhost on |
| `iFollowerRepairMode:Followers` | **0** | 0 = promote if a slot is free else undo; 1 = always undo; 2 = log only |
| `bFollowerVerbsReal:Followers` | **1** | S4/S5: route follower verbs to the real entries |
| `bFollowerHoldSkip:Followers` | **1** | G2: conversation hold skips follower-like NPCs |

### Tests (all [U] today - no playtest has ever had a follower present, 4.7)

| # | test | pass |
|---|---|---|
| **P-F1** | Recruit Lydia normally (real topic). Talk to her with CHIM. | `folstate` shows `fw=sff prim=1 mate=1 slots=1/n`; the companion block appears; `CurrentParty` in `conf_opts` is no longer empty; her offered actions lose `FollowPlayer`/`MakeFollower`/`EndConversation` |
| **P-F2** | With Lydia recruited, force CHIM's `MakeFollower` on a second NPC (temporarily re-enable it). | `ghost=1` is logged; G3 either promotes her (slot free) or removes the two factions; **then** say "it's time we parted ways" to the ghost - **Lydia must NOT be dismissed** |
| **P-F3** | With Lydia following, issue CHIM `TravelTo` / `Sandbox` on her, then walk away. | closes U2. Record whether she comes back on her own, on `EndConversation`, or never |
| **P-F4** | Say "wait here" / "follow me" / "let's trade" / "I need a favour" / "settle down here" by voice. | each maps to the **real** entry, exactly one command per turn, no CHIM shortcut in the same reply |
| **P-F5** | Conversation hold on a follower, and on a `chimfol=1` pseudo-follower. | neither is frozen; the log reason is right |
| **P-F6** | A follower present during an OStim gate and during a scene. | `witfol >= 1`; `companion_present` fires on a `followers_ok:false` profile; closes U5 (does Considerate Followers mute her?) |
| **P-F7** | Dismiss the SFF primary while an extra follower exists. | `SFF_PromoteExtraToPrimaryIfNeeded` runs; `folstate` follows the promotion within one turn |
| **P-F8** | Inigo (custom framework). | `fw=custom`; the glue never writes his `WaitingForPlayer`; his own dialogue is untouched |

### Risks

| id | risk | mitigation |
|---|---|---|
| **R1** | Package precedence (U2) may make every CHIM movement verb unusable on a follower | P-F3 first; if confirmed, hide `TravelTo`/`Sandbox`/`MoveTo` whenever `IsFollowerLike` |
| **R2** | `GetDialogueTarget()` must be correct when a follower fragment runs (6.2 item 5) | the driver must keep a real menu open on the right NPC; add an assertion + refuse otherwise |
| **R3** | `SetFollower` in G3 has real side effects (teammate, rank 3, essential flag) | gate behind `iFollowerRepairMode`, log every call, never run it inside a scene or a hold |
| **R4** | Shipping a `DialogueFollowerScript.pex` would destroy SFF | deploy denylist + release-checklist item (G5) |
| **R5** | The action set flips when she becomes a follower (4.6) | every glue action row already carries `available_to_followers = 1`; re-run the full v0.4/v0.5 test matrix once with a real follower |
| **R6** | SFF's slot cap is Speech-based and is **1** at low Speech (9.3) | the "join me" verb must say *why* it failed (`SFFCanRecruitMore = 0`), not go silent |
| **R7** | `stayAtPlace` leaves the NPC in `BardAudienceExcludedFaction` forever (4.4) | cosmetic; note it, do not fix another mod's actor |

---

## 11. Method, coverage, and where everything is

**Read (game side):** `F:\Modlists\LoreRim\profiles\Ultra\{plugins,modlist}.txt`;
`mods\CHIM\Source\Scripts\{AIAgentAIMind,AIAgentNpcUtil,AIAgentFunctions,AIAgentMCMConfigScript,AIAgentPapyrusFunctions}.psc`;
a printable-strings dump of `mods\CHIM\SKSE\Plugins\AIAgent.dll`;
`mods\Simple Follower Framework\{meta.ini, Simple Follower Framework.esp, SKSE\Plugins\SimpleFollowerFramework.ini, Source\Scripts\*.psc, Scripts\*.pex}`;
`mods\Swiftly Order Squad - Follower Commands UI\{Source\Scripts\*.psc, Quick Follower Commands.json, *_DISTR.ini}`;
`mods\Settling of Squad - Set Follower Home\Source\Scripts\*.psc`;
`mods\Missing Follower Dialogue Edit\{Missing Follower Dialogue Fix.esp, Scripts\Source\MFMD_*.psc}`;
`mods\LoreRim - xEdit64 Output\Simple Follower Framework Patch.esp`;
the INIs of AFT NG / Considerate Followers / Dynamic Follower Scaling / AutoSneakBugFix;
`Documents\My Games\Skyrim Special Edition\SKSE\AutomaticFollowerTeleporter.log`.

**Read (server side, WSL `DwemerAI4Skyrim3`, `/var/www/html/HerikaServer`):** `main.php`,
`functions/functions.php`, `lib/core/action_catalog.php`, `lib/data_functions.php`,
`lib/chat_helper_functions.php`, `ext/lorerim_glue/{lib/lrg_actions.php, lib/lrg_core.php,
lib/lrg_dialogue.php, config/lrg_config.default.json}`, `log/{chim.log, apache_error.log, service.log,
lorerim_glue.log}`; Postgres `core_action` (53 rows), `conf_opts`, `general_settings`.

**Read (glue, owner's, read-only):** `glue\OWNER_ADDENDA.md` item 10, `glue\PHASE2_DESIGN.md`,
`glue\game\LoreRimGlue\Source\Scripts\{LRG_Main,LRG_Profile,LRG_OStim}.psc`.

**Tooling.** The decompiled SFF signatures and bodies come from a **read-only PEX reader I wrote for
this investigation** (`C:\Users\Jordan\AppData\Local\Temp\lrg_test\pexdump.py` +
`pexcalls.py`, run with WSL's Python 3). It parses the documented Skyrim PEX v3.2 container - header,
string table, debug table, objects, variables, properties, states, functions and the instruction stream -
and prints signatures and call targets. No C++ tooling, no Champollion, nothing installed, nothing in the
game folder written. Everything in section 7.1 / 7.2 and the bodies in 4.1 / 4.2 is machine-read from
`dialoguefollowerscript.pex` and `SFF_SKSE.pex`; opcode operands are printed raw, so the pseudo-code in
4.1/4.2 is my transcription of a verified instruction stream (marked [V] for the calls and their
arguments, which is what the conclusions rest on).

**Not done.** No plugin-record (CTDA / INFO) parsing - U1. No BSA extraction, so Inigo / Lucien /
HLIORemi / Katana / GORE internals are unread - U4. No game run.
