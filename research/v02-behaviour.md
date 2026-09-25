# v0.2 BEHAVIOUR builder - report (2026-09-21)

Role: server behaviour (gates, interest, invitations, initiative, language, actions) against `glue/PROTOCOL.md` v2.
Result: `php -l` clean on all nine files; `tools/test_gates.php` **196 passed / 0 failed** (was 80); the FLOWTESTS suite that was written in parallel
(`tools/flows/run_flows.php`) **22 scenarios passed, 0 failed, 0 pending, 464 checks**; `tools/test_scene_index.php` ALL CHECKS PASSED (not my file, run as a regression).
Nothing was deployed; nothing under `F:\Modlists`, inside WSL or in CHIM was written. The CHIM database was READ (SELECT only, read-only session) for the R5 audit.
Second deliverable: `research/v02-chim-nsfw-audit.md`.

## Files changed (all mine per PROTOCOL 8)
- `glue/server/lorerim_glue/lib/lrg_core.php` - rewritten: `lrgNow`, `lrgRoll`, migrations runner, `lrgMemGet/Set`, `lrgInviteGet`, `lrgInterest`, `lrgGateMode`, `lrgPlaces`, `lrgExplicitLevel`, `lrgIsDiscreet`, `lrgSdeWords`, `_climaxes`, every `time()` -> `lrgNow()`; version 0.2.0 / schema 2
- `glue/server/lorerim_glue/lib/lrg_actions.php` - rewritten: catalog v7 (4 rows), `lrgHandleGameMessage`, `lrgInitiativeAdmit`, `lrgResolveRequestNpc`, `lrgPrerequest`, turn modes in `lrgPrepareTurn`, `lrgApplyTurnRuntime`, full verb set in `lrgResolveControl`, clothing who / part, Invite gate, all prompt text
- `glue/server/lorerim_glue/{globals,preprocessing,prerequest,functions,prompts,context_pre}.php` - thin hooks over the library; new `lrg_initiative` cue
- `glue/server/lorerim_glue/migrations/002_lrg_memory.sql` (new), `manifest.json` (0.2.0)
- `glue/server/lorerim_glue/config/lrg_config.default.json` - targeted edits in MY sections only: `scene_talk` (max_chars, max_tokens, announce_chance, level-2 wording; `max_words` removed), new `interest`, `invitation`, `initiative`, `winddown`, `language`, `result_memory_seconds`, `sde_*`, `npc_overrides._readme`. `scene_index / scene_start / scene_progression` untouched.
- `glue/tools/test_gates.php` - 2 assertions updated to the v2 contract, sections 17-28 added
- `research/v02-behaviour.md`, `research/v02-chim-nsfw-audit.md`

## What was built, by requirement
**R2 interest.** `lrgInterest()` = PROTOCOL 6.1 exactly: `score = affinity - effective min_affinity (+20 married-secret) + min(15, renown_bonus[sway][0|1|2] + Speech bonus)`;
words at -25 / 0 / 20; `willing = score >= 0` replaces the raw comparison behind `not_close_enough` (identical to v1 without renown / Speech - asserted); `may_initiate = drawn`.
The LLM sees one sentence built from the word (`interest.words`), never a number (asserted). Married-refuse, `never`, non-adult stay hard blocks regardless of score (asserted).
The word + score are kept in `lrg_memory`. BeginIntimacy's description and the guidance now say she may make the first move herself - and never because of pressure.

**Turn modes (6.2).** One `$turn['mode']` per request: `silent | closed | public | private | follow | scene`; offers per mode exactly as the table; `x` is non-null only for
adult + willing + a romantic mode. `silent` = both guidance strings `''`, nothing offered, CHIM runtime untouched. SHARMAT and the kill switch are silent at hook level AND inside
the library (`lrgPrepareTurn`, `lrgPostProcessActions`, `lrgPrerequest`, `lrgInitiativeAdmit`).

**R3 invitations.** Fourth, server-only action `ExtCmdLRG_Invite` / SuggestPrivacy, offered only in mode `public` (willing, only `witnesses` / `companion_present` failing).
Places come from snapshot facts (`lrgPlaces`): `home` (cellown=npc / home=1 -> "right here", else `nhome` by name), `room` (ltype=inn; worded by `prent` / owning the inn), `quiet` always.
The post-gate records `invite {at, expires = at + 1800, place, loc, state: pending}` and strips the line (the game never sees it). An unsupported place falls back to `quiet` rather than
losing an invitation she has already spoken. Pending + all gates pass -> mode `follow` (guidance flips: she brought the player here and makes her move now - BeginIntimacy, ChangeClothing or a
blunt proposition); BeginIntimacy / ChangeClothing passing on a follow turn -> `followed`. Dropped when expired, when she is no longer willing, and at `ev=end` of a scene with her.
Still in public with a standing invitation: she is told not to nag. CHIM movement actions: see "come with me" below.

**R2 initiative (6.3).** `lrg_initiative` is admitted or dropped inside `lrgHandleGameMessage()` = ext `preprocessing.php` = main.php:193, before the MAIN lock (:243), so an uninterested
NPC costs no LLM call and no TTS. Admission = the same gate evaluation as a speech turn (fresh snapshot, adult, on, no combat / quest scene / OStim / child, no active scene, not `never`,
no non-privacy block, willing) plus (a) follow-through: pending invite + private + >= 30 s since the last tick, or (b) own move: `drawn` + 300 s cooldown + `lrgRoll('initiative') <= chance[pace]`
(15 / 30 / 50). A drawn NPC in public may invite by herself once; with a standing invitation no second tick is admitted there. On pass `initiative_at = now`. `prerequest.php` switches actions on
only for an admitted tick (global `LRG_INITIATIVE`), `lrgPrepareTurn` keeps only Talk + the mode's actions. The NPC name is resolved at :193 from `$_GET['profile']` (md5 of the name,
checked against the live `core_npc_master.md5` column = `md5(npc_name)`), falling back to `HERIKA_NAME`.

**R4 + R5 language.** All prompt text rewritten as RULES (no example lines anywhere): plain, direct, real speech by default; metaphor / simile / poetry / theatrical phrasing / narrated feelings banned;
directness scaled by talk style (quiet, normal, vocal, crude, romantic, never) and setting (`public` -> "kept low, for the player only"; very strict profile, married-secret, `ltype` castle / temple ->
"discreet, never coy: what they do say, they say straight"). The wording permission (`scene_talk.explicitness_words[x]`, level 2 = "fully explicit and vulgar - crude words ... are expected, not avoided")
is sent ONLY when `$turn['x'] !== null`; level source = scene payload `x`, else snapshot `x`, else config. The old `personal_boundaries` block now has two faces: NOT willing -> the v1 anti-agreeableness
text (unchanged in substance); willing -> "what she does about it is her own choice ... she may well make the first move ... never the result of pressure" and nothing that pushes her toward restraint.
CHIM-side blocker found and neutralised without touching CHIM: see audit finding 1 (`OPENAI_FILTER_DISABLED` set per request on glue-guided turns; config `language.disable_chim_refusal_filter`).
Deviation note: the workflow brief says the wording permission applies from interest "curious"; PROTOCOL 6.4 (binding) says adult + WILLING. I followed the contract: a curious-but-unwilling NPC gets plain speech and no wording permission.

**R6 progression.** Ladder and pacing kept. Gap fixed: the v1 lead text let her "stay as it is, or nothing", and models take that exit. Now: "leads now ... and moves things along: choose ONE change",
the newly opened tier is marked `[next step]` on its options, doing nothing "fits only if this has just begun", and the server remembers a lead tick that ended without an accepted action
(`lrg_memory.lead_idle`): the next lead tick says "this time she makes a change". After a climax the notes suggest "wind down". Under `leader=player` the notes say the player directs;
no lead tick counts while `leader=player`, `auto=1` or `wd=1` (server-side re-check of the game's rule).

**R7 speed.** All four rows `followup.enabled=false` (funcret.php:131-136 -> no second LLM + TTS), `LRG_ACTIONS_VERSION` 7. Results come back through `funcret` -> `lrg_memory.last_result` (needs the echoed `npc=`);
a failure is told to her once on her next glue-guided turn in one plain sentence, then `told=true`. ONE short sentence of at most `scene_talk.max_chars` (120) is asked for in the cue AND in the notes on scene,
follow and initiative turns; ordinary closed turns are NOT squeezed (asserted). `FORCE_MAX_TOKENS`: 140 speech-only scene line, 200 when the turn may carry an action (v1's 60 could not hold CHIM's 8-field JSON reply:
about 45 tokens of fields around the message; a truncated reply is an invalid reply). No index build can start from an LLM request on my side: only `lrgScene* / lrgPick* / lrgFind*` are called, and
`preprocessing.php` calls `lrgIndexMaybeRebuildAsync()` on the fast `lrg_npcstate` message only. `preprocessing.php` loads the big library only for `lrg_*` and `funcret` requests.

**R10 speak the choice.** Tier rule + dice: an option is `[say it]` when its tier is sensual / sexual AND this turn's `announce` roll passed (`lrgRoll('announce') <= scene_talk.announce_chance[talk style]`:
quiet 40, normal 70, vocal 85, crude 90, romantic 75, never 0), otherwise `[silent]`; gentle options are always `[silent]`. Options stand on a line of their own so every mark belongs to one option.
Rule text: `[say it]` -> ONE short sentence that names the act / position she just chose, plainly, in her own words, at the wording level; `[silent]` (and pace, hold, clothes) -> leave `message` empty, it simply happens.
When the dice say no: "whatever she chooses this time simply happens: leave message empty". BeginIntimacy (always a gentle start) "can simply happen, message left empty". Re-verified in the CHIM source:
empty `message` -> no TTS (`lib/data_functions.php:6004`), the action still runs (`:6029`), the "Sure thing!" filler is commented out (`main.php:2846`).

**R1 / R8 verbs.** `lrgResolveControl` maps free text in the contract's order: stop > winddown > P-key > pace (`faster / slower / speed n`) > hold / release > climax (`who` npc / player / both; only from tier sensual) >
pullout > lead (npc / player / auto; auto only once `reached >= auto_mode_min_tier`) > furniture label (from `lrgFurnitureOptions`, only with a scene id) > label / id echo > free-text position search. Unknown text is dropped.
`stop` is a rail: any item containing stop / enough / halt / quit / "no more" / "end it" is `do=stop`, mid wind-down too ("finish" no longer means stop - it is ambiguous with climax). Wind-down emits
`do=winddown;scene=<lrgPickAfterglowScene or empty>;warp=<0|1>;linger=<winddown.linger_seconds>`. Start emits `scene;undress;furn;fscene;maxwit;folok` using `lrgPickStart()` (guarded, falls back to `lrgPickStartScene`).
Clothing: `who` npc / both / player (player only for "undress you / the player": an echo of the player's own "take your clothes off" still means the NPC), `part` all / body / head / hands / feet. Every param starts `ok=1;cid=..;npc=..`.
The notes list the verbs compactly and only when they apply (climax from sensual, pull out at sex, auto at the ladder top, furniture labels when the game reports furniture). Lead keys in the notes are `<npc> leads` /
`player leads` / `auto` (unambiguous); "you lead" / "i lead" are still understood. Movement hiding during scenes kept and widened (TravelTo, LeadTheWayTo, Relax, StopWalk, Inspect, CastSpell, Toast ... were missing in v1).
SHARMAT idle guard kept (now also library-level). Memory hygiene kept: only scene start / end reach CHIM's event log; `ev=winddown` and `ev=climax` do not.

**Serana Dialogue Expansion.** `sde` 0..5 -> coarse words in her static block (`sde_words`; -1 / absent = nothing). Faction `SDE_RMarriedFaction` in `fac` is read as "married to the player" (`pspouse=1`), so SDE marriage
no longer trips married-refuse and earns the spouse bonus.

## "Come with me" - which CHIM action really exists (R3)
From `data/core_action_seed.sql` and `functions/functions.php:20-66`: `TravelTo` ("Travel long distance to a building, city, door or other location. Also known as lead the way"; available to NPCs and followers, active) and
`FollowPlayer` (NPC follows the player; available to NPCs, active) are usable. `LeadTheWayTo` is inactive and not available to NPCs; `ReturnBackHome` requires a rolemastered NPC; `MoveTo` is actor-only; `ComeCloser` is a step, not a walk.
The public guidance names TravelTo / FollowPlayer (by CHIM's display name) ONLY when they are in this turn's `ENABLED_FUNCTIONS` (asserted both ways). `invitation.lead_the_way` (default false, per contract) can additionally send
`TravelTo@<nhome>` / `FollowPlayer@` after recording an invite; written, not tested in game.

## Tests (`tools/test_gates.php`, 196 checks)
1-16 v1 sections kept (2 assertions adapted: `npc=` + `part=all` in the clothing param; private + willing now carries the wording permission, closed and x=0 do not). New: 17 interest formula / words / v1 identity / memory;
18 modes, offers, places from facts, movement hint, discreet; 19 invitation lifecycle (pending, snake-cased display name, no nagging, follow, gentle start scene + v2 start shape, followed, TTL edge 1799 / 1802 s, unwilling, scene end);
20 initiative (every drop reason, dice edges 50 / 51, cooldown, public invite, follow-through, prerequest before / after, non-admitted tick, prompts.php cue + cap); 21 verb set incl. stop ties, wind-down, lead, furniture, clothing who / part,
wd / leader guards, climax count; 22 R10 marks for roll 1 / 100, 85 / 86, silent pick still commands; 23 catalog v7 rows (captured through a stand-in for CHIM's upsert), speed, token caps, refusal-filter flag, closed / silent untouched;
24 last_result told once; 25 lead idle; 26 total silence for non-adult / child in six request types incl. forged lines; 27 SDE; 28 clock seam.

## Not done / not proven
- Nothing was run in game or against a live LLM. Whether Grok follows "leave message empty" and the `[say it]` rule as intended is a playtest question; the server side and CHIM's handling of an empty message are verified.
- `invitation.lead_the_way` wire format for TravelTo / FollowPlayer (bare value parameter) is from reading `buildFunctionExecutionParameter`, untested; default off.
- A refusal by the PLAYER of her own move is not tracked (no signal exists); she is only told to drop it "if the player has made clear they do not want this". The 300 s cooldown limits repetition.
- "A refusal is final for this conversation" remains an LLM rule; the server does not detect her refusals (same as v1).
- CHIM's conversation cooldown may swallow `lrg_initiative` like any `requestMessageForActor` (contract section 6, open item for GAME / playtest).
- Provider-side behaviour of the summary / diary models cannot be proven from source (audit finding 7).
