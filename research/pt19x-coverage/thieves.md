# pt19x coverage - Thieves Guild (TG00-TG09, TGLeadership, radiant, fences, city jobs)

**What this is.** Every player line that moves or branches the Thieves Guild questline, taken from this load order's prompt index
(`prompt_index.ndjson`, profile Ultra, hash `e756e311...`). Each line has 6 paraphrases, 2 speech-to-text variants and 1 near-miss, and every
one was scored with the real v1.0 matcher. The data is in `thieves.json` (327 beats, 26 quest EditorIDs). TG00's opening beats are already in
the spec's harness (3.6: `what`, `chain`, `refuse`, `persuade`, `ready`). This file maps everything after them.

**Method.** The functions were run from a read-only copy of `glue/server/lorerim_glue/lib`, taken on 2026-09-24. Each utterance was scored
against its real layer:

- `lrgDlgMatchText` / `MatchPick` / `UniqueWordTie` / `NegationClash` / `Explicit` / `SingleEntryRelease` / `AdvMs` / `Class` / `IsCommit`.
- A closed layer is its index layer line, with **one row per topic kept** (the live menu shows one), or a single entry.
- A root list is a plausible subset of the NPC's top-level rows.

`expect` is what the want=1 fast path does, as `lrgDlgAnswerWant` runs it. When the fast path picks nothing, the model's T-key route is
assumed and the line is marked `model_dependent`. Stages come from UESP (URL per quest in the JSON). `fx` is filled only where a fragment was
read: `TIF__000B3893.psc` sets `SetStage 37`. The `QF_TG02B` fragments were read, but their stage map lives in the plugin.

## Headline

- **327 beats, 2,616 utterances:** 2,308 resolve (88%), 237 ask once, 71 nothing.
- **Verbatim lines:** 319 of 327 resolve. **Speech-to-text variants:** 610 of 654 resolve. Owner-style name damage ("brin yolf", "car leah",
  "golden glow") still lands on other shared words.
- **454 utterances (17%) resolve only through the model's T-key.** Nothing on the fast path would click them.
- **Near-misses:** 238 of 327 click nothing. 47 would **click the target** and 20 would park on it. A further 22 depend on the model or are a
  by-design re-arm.

## Counts by path

| pick | ask_once | check | scene_read_only | scripted_entry_candidate | service | cannot |
|---|---|---|---|---|---|---|
| 178 | 63 | 15 | 64 (driven as: 48 pick, 15 ask_once, 1 check) | 3 | 2 | 2 |

The 3 click-free candidates are `TG01.flagon.hello` 020537, `TG03.sabjorn.done` 0549D1 and `TG03.maven.report` 0B8819. They are TL1
scripted report lines, but their fragments are not loose, so each needs a CK read before it goes into the 10.26 table.

| quest | name | rows | beats | pick | ask | check | scene | cand | svc | cannot | reward |
|---|---|---|---|---|---|---|---|---|---|---|---|
| TG00 | A Chance Arrangement (beyond spec) | 31 | 11 | 2 | 3 | 0 | 6 | 0 | 0 | 0 | 1 |
| TG01 | Taking Care of Business | 59 | 41 | 24 | 16 | 0 | 0 | 1 | 0 | 0 | 2 |
| TG02 | Loud and Clear | 39 | 24 | 14 | 6 | 1 | 3 | 0 | 0 | 0 | 2 |
| TG02B | meet Vex / Delvin / Tonilia | 17 | 14 | 14 | 0 | 0 | 0 | 0 | 0 | 0 | 1 |
| TG03 | Dampened Spirits | 49 | 26 | 17 | 5 | 2 | 0 | 2 | 0 | 0 | 4 |
| TG04 (+Post) | Scoundrel's Folly | 69 | 31 | 12 | 10 | 3 | 6 | 0 | 0 | 0 | 1 |
| TG05 | Speaking With Silence | 23 | 10 | 0 | 0 | 0 | 10 | 0 | 0 | 0 | 0 |
| TG06 | Hard Answers | 38 | 17 | 9 | 5 | 3 | 0 | 0 | 0 | 0 | 1 |
| TG07 | The Pursuit | 32 | 20 | 7 | 4 | 3 | 6 | 0 | 0 | 0 | 0 |
| TG08A | Trinity Restored | 27 | 15 | 0 | 0 | 0 | 15 | 0 | 0 | 0 | 0 |
| TG08B | Blindsighted | 32 | 14 | 0 | 0 | 0 | 10 | 0 | 2 | 2 | 1 |
| TG09 (+Post) | Darkness Returns | 43 | 16 | 7 | 1 | 0 | 8 | 0 | 0 | 0 | 0 |
| TGLeadership | Under New Management | 2 | 2 | 1 | 1 | 0 | 0 | 0 | 0 | 0 | 1 |
| TGRShell | Vex/Delvin jobs, special jobs | 396 | 22 | 19 | 3 | 0 | 0 | 0 | 0 | 0 | 3 |
| TGBan / TGCrown / TGFenceCaravan / TGLarceny | banishment, Barenziah, caravans, Larceny | 55 | 24 | 22 | 2 | 0 | 0 | 0 | 0 | 0 | 9 |
| TGTQ01-04 | Silver Lining, Dainty Sload, Imitation Amnesty, Summerset Shadows | 61 | 36 | 26 | 7 | 3 | 0 | 0 | 0 | 0 | 6 |
| mods | Sell Stones of Barenziah, Revealing Rune | 76 | 4 | 4 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

**Not dialogue, so never by voice.** These steps are activations or scenes with no player line:

- the ring plant (TG00) and the urn and statue (TG01), done by hand;
- the Honningbrew tasting (TG03 70), the Snow Veil ambush (TG05) and the Cistern confrontation (TG07);
- the Oath (TG08A 50->57);
- the Mercer fight (TG08B);
- returning the Key and choosing the Agent circle (TG09 40/70);
- the Guild Master offer (TGLeadership 10/20), which has no indexed player row.

## Negotiation

32 beats carry reward lines, and every reward is fixed (S6). She says so and never invents more. The layers already hold real bargaining
lines, and she should point to those:

| line | where | what it does |
|---|---|---|
| "Will I get a cut?" | TG01 03C6F8 | real, but it names no amount |
| "So how do I get my cut of the spoils?" | TG02 084847 | real, names no amount |
| "Speaking of which..." | TG02 0480BF | the pay line: leveled 50-800 septims |
| Sabjorn's persuade/intimidate | TG03 09F157 / 09F108 | a real Speech check that pays 500 septims up front (UESP) |
| "What about my pay?" | TG03 0813AC | real line |
| "That price is outrageous!" / "Any other way to earn it?" | TGTQ02 0DC168 / 0DC16D | Sabine's 1,500 septims stay fixed |
| Rhorlak's `<BribeCost>` | TGTQ01 0799DA | a named amount below the live price is refused by the check rail |

## Capability gaps, ranked by how often a player hits them

1. **A question about a turn-in line clicks it.** The fast path and `lrgDlgExplicit` have no shape test; S4.5 step 0 covers single-entry
   layers only. 31 near-miss questions click and 20 more park on a line the player never asked for. Players ask this at every turn-in NPC:
   - "where's the Firebrand Wine?" clicks 0B3896 at 0.87;
   - "where's the crown?" hands in the Crown (09DFB4);
   - "where are the Stones of Barenziah?" clicks 09DFB6;
   - "where's the locket?" clicks 07D674, and "is Arn safe?" clicks 07D020;
   - "do they need to die?" is *explicit* on Mercer's commit 0B246A (0.838);
   - "are you disappointed?" is explicit on 04E3A1.

   *Fix:* apply the step-0 shape rule on the fast path and inside `lrgDlgExplicit`. A question never clicks a statement entry; it may
   only park.
2. **Loose paraphrases rely on the model.** 454 utterances on 172 beats have no fast-path pick. Most sit on scripted single entries (step 6
   trusts the model) and root lines under 0.55. If she answers in words, the player has to repeat himself.
3. **Harmless flavour choices ask once.** On 27 closed layers every scripted sibling leads to the same next line, but S4.1's "two or more
   scripted siblings" rule makes each one a commit. That produces 237 asks. The layers are:
   - Brynjolf's Flagon welcome (03626A);
   - Maven (04E3AC), Mercer (050AE0), Brynjolf's Gulum-Ei tips (050ADF);
   - Gulum-Ei cornered (02CC40), Brynjolf's leadership offer (091944), Gallus "I did this..." (01A2B2).

   *Fix:* siblings whose `links` all converge on one topic are hubs, not commits.
4. **Negations and refusal words, handled three wrong ways (37 beats).**
   - **Negated replies still advance (16).** "I'm not listening" auto-advances 0D7708. "I'm not in" advances 03C700. "let's not" releases
     091942 through the shared word "let". "sounds hard" releases 07D024 "Sounds easy.". "we can't do anything" releases 08483B at 0.87.
   - **Agreeing replies refused as refusals (12 lines, 8 beats).** "Hold on... you shot me!" is Karliah's own line, spoken verbatim
     (01BB45). Also "stop him" (037CD8), "pay up and I'll forget it" (029726), "I won't kill you" (0D771D) and "not our way".
   - **Lines that contain a negation refuse agreeing words (23 lines).** "this is personal" misses 038A46. "Nocturnal guides me" misses
     038A3D. "time to go" misses 0799D4. Speech-to-text also turns no into know and won't into want ("know way...", 0.928).

   *Fix:* negating the line's own words stops the automatic advance. The clash test should run on the negated clause only, with know/no and
   want/won't folded first.
5. **Menu variants read as siblings, and same words with different meanings.** On 38 beats the index layer line lists several INFOs of ONE
   topic: persuade success and failure, can-pay and cannot-pay, first time and repeat. Scored raw, "Yes, and here's what was in the safe."
   only parks, but live it is a single entry. On 11 more beats one norm has different scripted or goodbye flags. The TGoB last-debtor
   variants 078742/5/8 and the 18 "I've completed the X job" rows (paid or unpaid) are examples. *Fix:* the builder groups layer norms by
   topic, and the harness picks the variant from the facts.
6. **Scenes stay read-only until the first proven click.** This covers 64 beats: TG05, TG08A, TG08B and TG09's Gallus in full, plus TG04
   Brinewater, the TG07 Cistern, TG02 Mercer and the TG00 intro. It is by design and only bites before the first click on this install.
7. **Greeting-opened single lines have no indexed parent (26 beats).** Examples are 084847, 01897B, 02C34C and 0967FC. The fixture cannot
   name the layer by `parent_info`. It needs `{"kind":"single","npc_side":true,"info_key":...}`.
8. **Statement lines refuse the natural question.** Brynjolf's pay line "Speaking of which..." refuses "where's my pay?". "Nightingale?" and
   "Gallus?" fail the same way (step-0 shape, applied to T-keys too).
9. **The 4-word rail blocks short checks (6 beats).** Rhorlak's "Remember now?" (0799DA) is two words, so it can never be said as written.
   *Fix:* require min(4, the entry's token count) words.
10. **Money moves without a price the glue can see.**
    - TGBan's "Here's the gold." (0B038E) takes about 1,000 septims at index cost 0.
    - Sell Stones' "Buy unusual gem for 1000 gold." (00080B) has no "(N gold)" tag, so it is graded *service* and clicks with no ask.
11. **Radiant job quirks.**
    - Delvin's accepts carry a goodbye flag from Missing Follower Dialogue Fix, so they ask. Vex's click at once.
    - "I want to quit that burglary job." (0D785E) is plain, so a misheard sentence abandons the job.
12. **A walk-away line graded "back".** Aringoth's "Forget it. I'll just open it myself." (0562FD) is caught by the shipped back-out list.
    Any LEAVE ("never mind") clicks it, and he attacks. The Guild Master line 0AA78C is also graded back, because of the word "nothing".
13. **A word-folding bug.** `lrgDlgFoldTokens` turns "we're" into "were", which `lrgDlgLeadsQuestion` reads as a question word. "we're out
    of patience" is then refused as a question to her (021E86).
14. **One wrong-entry hit.** "I nearly died getting here" lands on its sibling "Getting here was easy." (0.721 against 0.284). That line is a
    commit, so she asks about the wrong line.

Files: `research/pt19x-coverage/thieves.json`. The working scripts are in `%TEMP%\lrg_test\pt19x-thieves\`:

- `spec_a/b/c.py`: the authored beats;
- `build.py`: the layers from the index;
- `evalq.php`: the real-function scorer;
- `assemble.py`.
