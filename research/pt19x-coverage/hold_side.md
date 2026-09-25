# pt19x - hold side quests by voice: coverage map (group `hold_side`)

2026-09-24. Questline researcher output for the v1.0 menuless build (spec `research/pt19-menuless-v1-spec.md`
S0, S4, S5, S7, 3.3, 3.6). Machine-readable file: `hold_side.json` (same folder). It has one entry per beat and uses the
3.3 fixture's layer form: `closed` + `parent_info` from the index layer line, `single`, or `root` + `topics`.

**Method.** The facts come from the live prompt index (`prompt_index.ndjson`: 37,561 rows, 5,718 layer lines, built
for the Ultra profile), the UESP walkthroughs linked below, and the loose quest scripts that exist in this load
order. Those scripts are all in The Choice is Yours (`qf_ms01/08/09/14`, 57 topic-info fragments) and Silence is
Golden (`qf_ms13`); the vanilla fragments are packed in BSAs. Every one of the 1,045 paraphrases, 418 STT variants,
207 near-misses and 24 bargaining sentences was scored with the REAL functions, run offline on read-only copies of
the 2026-09-24 build of `lib/`: `lrgDlgMatchText`, `MatchPick` 0.55/0.15, `UniqueWordTie`, `NegationClash`,
`ServiceSense`, `KindPick`, `lrgDlgExplicit`, `lrgDlgSingleEntryRelease`, `lrgDlgAdvMs` and `lrgDlgClass` (with the
glue's own hub rule and its Invisible-Continue = scripted rule), using the `lrgDlgDefaults()` config. The scorer
reproduces the spec's own numbers: "what do you need done" 0.783 at step 5, and "uh what now" 0.328.
`expect`: **resolve** = clicked on that utterance alone (fast path, explicit, single-entry release, auto-advance, or
a check under its rails where the matcher agrees). **ask** = the commit parks and she confirms once. **nothing** = the
fast path clicks nothing; `if_model_picks` says what the model's T-key would do. **OTHER** = the matcher lands on a
different line.

## Quests found (EditorIDs; the brief's guesses corrected)

| EditorID | Quest | Beats | Notes (winning plugins) |
|---|---|---|---|
| MS13 | The Golden Claw | 10 | Silence is Golden; Riverwood Trader Is A Mess (wins 03962C); GoldenLie.esp adds a lie line |
| MS01 (+ GuardAmbushQuest, ForswornThreatenScene) | The Forsworn Conspiracy | 35 | TCIY; Immersive Speech Dialogues rewrites the success texts of the checks |
| MS02 (+ EscapeMadanach/ThonarEnding) | No One Escapes Cidhna Mine | 20 | USSEP wins Borkul's gate and the revenge line |
| MS14 | Laid to Rest (the brief said MS10) | 18 | TCIY |
| MS11 (MS11b follows) | Blood on the Ice | 26 | USSEP wins Viola's, Jorleif's evidence and Wuunferth's journal rows; NGCDT wins the amulet line |
| MS08 | In My Time of Need | 24 | TCIY; SaadiaToIman.esp |
| MS09 | Missing in Action | 26 | TCIY adds an accept/refuse layer; IDE Stormcloaks wins the ending |
| MS10 | Rise in the East (the brief said MS07, which is Lights Out!) | 19 | TCIY accept/decline |
| MS06Start / MS06 | The Man Who Cried Wolf / The Wolf Queen Awakened | 4 + 8 | Update.esm (Styrr) |
| MS04 | Unfathomable Depths | 4 | 4 rows in total |
| MS05 | Tending the Flames (only the beats spec 3.6 does not cover) | 14 | dvEdda.esp wins the induction |
| dunGauldursonQST | Forbidden Legend | 1 (cannot) | **0 player rows** |

UESP: [Golden Claw](https://en.uesp.net/wiki/Skyrim:The_Golden_Claw) ·
[Forsworn Conspiracy](https://en.uesp.net/wiki/Skyrim:The_Forsworn_Conspiracy) ·
[Cidhna Mine](https://en.uesp.net/wiki/Skyrim:No_One_Escapes_Cidhna_Mine) ·
[Laid to Rest](https://en.uesp.net/wiki/Skyrim:Laid_to_Rest) · [Blood on the Ice](https://en.uesp.net/wiki/Skyrim:Blood_on_the_Ice) ·
[In My Time of Need](https://en.uesp.net/wiki/Skyrim:In_My_Time_Of_Need) · [Missing in Action](https://en.uesp.net/wiki/Skyrim:Missing_In_Action) ·
[Rise in the East](https://en.uesp.net/wiki/Skyrim:Rise_in_the_East) · [Man Who Cried Wolf](https://en.uesp.net/wiki/Skyrim:The_Man_Who_Cried_Wolf) ·
[Wolf Queen Awakened](https://en.uesp.net/wiki/Skyrim:The_Wolf_Queen_Awakened) · [Unfathomable Depths](https://en.uesp.net/wiki/Skyrim:Unfathomable_Depths) ·
[Tending the Flames](https://en.uesp.net/wiki/Skyrim:Tending_the_Flames) · [Forbidden Legend](https://en.uesp.net/wiki/Skyrim:Forbidden_Legend)

## Counts by path (209 beats)

| pick | ask_once | scene_read_only | check | scripted_entry_candidate | service | cannot |
|---|---|---|---|---|---|---|
| 92 | 69 | 24 (17 become ask_once and 7 become pick after the first proven click) | 19 | 3 | 0 | 2 |

`service` is 0: no vendor lines. Kleppr's and Garvey's "key to ... room" lines are graded `service` by the word
"room", but they behave as picks. `cannot`: Forbidden Legend, and MS09's release order, which the game never offers
(UESP marks the peaceful route as broken). The three proven click-free entries (a fragment read on disk):
TCIY `tif__02013b34` (Fralia "Alright, I'll stop by this evening." SetStage 10), `TIF__00029A94` (Orthus "Do you have
any proof of that?" SetStage 5) and `tif__02015b8d` (Orthus "I'll see what I can do." SetStage 10).

**How the words fared.** Paraphrases: 762 resolve / 87 ask / 196 nothing (73 % click on the first sentence). STT
variants: 375 / 10 / 33. Near-misses: 127 of 207 click nothing. The 80 failures split into: a plain line clicked 38,
a commit clicked without asking 14, a commit parked (she asks) 21, a check agreed 3, and a different line 4. For 5
beats the engine's own words do not resolve on their own (see G6). Rewards are all fixed by the engine: 23 of 24
bargaining sentences click nothing. The group has one real "what do I get" line, MS08's Alik'r "What do I get for
telling you?"; every other reward is spelled out in the beats' `negotiation` field (for example MS08 500 septims on
either side, qf_ms08 read; MS09 Eorlund's weapon or 200 septims, qf_ms09 read).

## Capability gaps, ranked by how often a player hits them

1. **A question about a line's subject clicks the line** (38 of 207 near-misses; it happens in every hold). The fast
   path has no sentence-shape test on multi-entry and root lists, and the F1 tier sees only one or two content words.
   "who is Thonar" clicks "I need to see Thonar.", "is Thorald safe" clicks Fralia's turn-in, and "what can I do"
   clicks TCIY's accept (SetStage 10).
2. **A question explicitly releases a commit** (14 near-misses; rarer than 1, but irreversible). S4.3 has no shape
   or person test. "are you ready" clicks "I'm ready." (Madanach's escape), "can you do that" clicks Styrr's task,
   "is Grisvar dead" clicks the turn-in, "what's in Alva's journal" clicks the Jarl's turn-in, and "what's your job"
   releases Adelaisa's commit single.
3. **Commits are dense, and many of them are questions.** 86 of the 209 beats are commits once scenes are unlocked.
   The cause is a fragment or goodbye flag on innocent questions: the three witnesses' "Did you see what happened
   here?", "Are the murders being investigated?", "How can you be sure your son is alive?" and "So why haven't you
   arrested him?". 87 paraphrases and 21 near-misses park, at the cost of one confirmation each.
4. **Invisible-Continue siblings are graded scripted** (8 beats), so layers whose lines all lead to the same answer
   become commits: Thonar's "Will you talk now?", Braig's three stories, Viola's poster, Madanach's intro, Saadia's
   "Maybe. What do you want?", Avulstein's "on my own" and Jorleif's "Calixto ... was the real killer".
5. **Journal scenes are read-only until the first proven click** (24 beats; only on the first evening): Camilla,
   Eltrys's shrine, Dryston, the MS01 guard ambush (the hand-off to Cidhna Mine), Madanach's scene and escape,
   Thonar's exit, Kematu, Avulstein and the induction.
6. **Short lines.** A 2-token commit never releases on its own words ("I'm ready.", "Let's go.", "Understand?
   How?"). 3-word checks fail the 4-word rail even when spoken verbatim ("How about now?", "I wasn't asking.").
   "okay/fine, I'll do it" scores 0.549 and matches nothing on Kematu's "All right, I'll do it.".
7. **Negation parity refuses positive paraphrases**: "I need a shiv to kill Grisvar" against "I can't kill Grisvar
   without a shiv.", "I couldn't beat them all" against "I was unable to defeat them all", and "doesn't this belong to
   the court mage" against "Shouldn't the court mage have it?" (the inversion exception covers pronouns only).
8. **STT damage to names** (33 of 418 variants click nothing) where the name was the only meaning word: "wears
   madonna", "i've killed holdin", "if tore old is alive".
9. **Check variants.** In the ISD success/failure pairs, the index layer line lists both texts, but the live menu
   shows only one. Idolaf's top-level persuade changes its text when it would fail. Borkul's "(Brawl)" line is the
   intimidate failure variant and is graded a check, not a commit.
10. **Money outside price tags**: the 100 septim fine is taken by script (index cost 0), and the skooma "bribe" is
    tag-only with no price.
11. **Nothing to click**: Forbidden Legend, and the MS09 order.
12. **Stage effects are unproven for 204 of 209 beats**: the vanilla TIFs are in BSAs, so the entry table can only
    be proven from TCIY's loose fragments.
13. **Root lists here are approximations** (the NPC's same-quest rows plus the rumour line). The live lists are
    longer, so the live margins will be lower.
