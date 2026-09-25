# pt19c final fixer - the completeness critic's MUST_FIX / MISSING list, 2026-09-25 04:31-05:10

Staging and scripts: `%TEMP%\lrg_test\pt19c-final\` (`base` = the tree as found at 04:33, `wk` = the work copy, `r1`/`r2` =
the frozen gate record; `pt19c-final_stage.sh` / `pt19c-final_run.sh` in `%TEMP%\lrg_test`). Originals of every file changed:
`glue\.backup\pt19c-final\`. No other writer touched `glue\` after 04:16 (checked before copying back). No .psc changed.

## 1. Release gate A - the formal record (MUST_FIX 1 + 2)

- The spec-owner rulings, applied as recommended (fixture only; both fixtures; each beat carries a `ruling` field with the
  evidence, each line `spec_via` with the spec's old path; the 31 @spec_row `known` entries and the `spec_row` reason are gone):
  1. MQ102.balgruuf.intro and MQ105.arngeir.summons re-classed explicit / park (`stageB` explicit, every line's `via` = the
     measured path). Evidence re-read in the live index: MQ102BalgruufIntroTopic carries 0DF024 (goodbye=1) and 0D50EB
     (invis); MQ105ArngeirIntroTopic carries 02F2C4 (goodbye + scripted), 02F2C6 (walk-away, crit 1), 0649B7 (scripted).
  2. FE.riverwood.plain: "sing me something about dragons" moved from never to say, via pick (fast pick mode=intent).
- `tools\compile.ps1` at 05:03: 0 errors, 0 warnings, the 500-character guard and the denylist passed (log:
  `pt19c-final\compile.log`).
- Frozen tree staged twice (manifest md5 `5498dee02872`, 138 files incl. .psc, .pex, fixtures, configs, owner page, PROTOCOL)
  and run whole on each; r1 = r2 apart from random cids / temp paths:
  gates 793/0, intent 470/0, phrases 47/0, dialogue 1019/0, prompt_index 99/0, mcm_wiring 49/0, services 99/0, scene_index
  all passed, latency 34/0, latency_prompt mean 2,306 chars, stt 60/0, questline 1956/0 (0 known), --first-evening 222/0,
  --words 2194/0, --first-evening --words 247/0, --stage=B 39/0, run_flows 88 passed / 0 failed / 1 pending (d68, gate B).
  README section 7 holds the table. After the runs only README.md changed (re-staged manifest identical).

## 2. MISSING items - fixed

| item | fix (file) | proof |
|---|---|---|
| S6.1 reward line on ordinary quest talk | `lrgDlgRewardNotBargain` (lrg_speech.php) over every tier: a pay idiom (attention / respects / heed / visit / mind ...), a waiver ("for free" with no negation, question or you/expect/want; a pay phrase right after don't / no / needn't outside a question or an if/unless clause), a place after the phrase ("in the barrow", "out of the barrow"; "out of the deal", "at the end" stay bargains), a third party or activity verb before a weak phrase; tails "of that / of this / of it" removed (code default + config) | test_gates: the critic's 11 + 11 siblings are no bargain (and the 11 on the real Farengar path), 19 bargains beside them still are; every earlier list unchanged |
| spec 3.5 LETHAL show on both paths | `lrgDlgAnswerWant` (lrg_dialogue.php): resist-arrest / arrest-class / crit 2 emit `do=show;kind=meta` on the fast path too | test_questline cross check now requires the fast-path show; d56 (flow d58) rebased: the only command is the show |
| U1 open.kind_factions empty | code default + config: carriage `CarriageSystemFaction`, `KmodCarriageFreeFaction`; ferry `DLC1FerrySystemFaction`, `KmodFerryRoute1-4Faction`; train `JobTrainerFaction` | read-only probe `pt19c-final\esp\fac_probe.py` over Skyrim.esm, Dawnguard.esm, Dragonborn.esm, CFTO.esp (every driver / ferryman / trainer NPC_ and its SNAM); test_dialogue v25: driver, CFTO driver, two ferrymen, a trainer open; guard, farmer, Banning (JobAnimalTrainerFaction) do not |
| S3.3 rail note sent as kind=hint | `lrgDlgRailNote` sends `kind=rail` (LRG_Main's once-per-sid branch; bQuestHint no longer silences it) | test_dialogue v24 asserts `;kind=rail;`; PROTOCOL 10.29 and the owner page updated |
| STT sum root | lrg_intent.php: `LRG_MONEY_WORD` + septum(s) / septem(s) / septem's / sept ums / sep tims; `lrgIntentSumFold` (digit before hundred / thousand -> words, thousands comma, lower case) at the head of `lrgIntentAmount` | test_intent: 10 STT sums on lrgIntentAmount and lrgDlgNamedAmount, "5 hundred men" still 0; test_gates: the engine bribe rail passes "2 hundred septims" / "two hundred sept ums" on a 200-septim line, still refuses 100 |
| clause-4/5 name-word proxy | `lrgDlgNameWord` skips ranks and bare roles (general, captain, commander, legate, priest, hunter ... and the Nine's names); `lrgDlgStripVocative` keeps those ranks as addresses | test_dialogue: the six names; Erik's RoriksteadFreeformErikGeneralTopic2Topic row ("Have you lived here all your life?") is no longer Tullius's |
| Lane A test gaps | test_dialogue v21b reads the single beats' target rows from the staged prompt index (the old block ran over 0 beats): 22 beats, 34 never lines on the fast path, 24 through the gate with the T-key; a new MS11-shape row (the real Jorleif rows) | both green |
| Sven / Alvor plugin facts | re-probed read-only on today's disk (`pt19c-Efix\esp\esm_probe.py`, the equivalent of esp_dump.py, which cannot target one INFO of a 250 MB master): 0BB965 `GetIsID(Sven)`; 0BCCC6 alias Sven; 0BCC9B alias Sven + MQ102 < 110; MQ102HadvarAlvorScene owned by MQ102A | new finding: moretosayriverwood.esp adds `GetIsID(Sven)` lines; in the inn only "I think Hod might think you drink too much." (MQ102B stage 40 done - the Ralof path) can join the three. Owner page step (3) says so |

Docs: PROTOCOL.md (rail order note: show on both paths; 10.29 rail note; kind_factions twice), README (known limits, the gate
table), owner page (rides / crossings / lessons bullet, the "Tell me when she has something" bullet, Sven's count, section 7
rows re-classed 2026-09-25, no red line left).

## 3. Not done, and why

- **Deploy, install, the memory note** (MUST_FIX 3): this run's task says "Do not deploy or install". The orchestrator runs
  `tools\deploy_server.ps1` with CHIM's launcher up, checks `config/lrg_dialogue_overrides.default.json` reached
  `/var/www/html/HerikaServer/ext/lorerim_glue/config`, then `tools\install_mo2.ps1` with MO2 closed (hash-verified, backups
  `glue\.backup\pt19-*`). The installed game is still script 512 and the live server 0.5.6 until then. The memory note follows
  the owner's first evening.
- **Lane C's -900 line budget**: not met (+16 net over LRG_Dialogue / LRG_Main / LRG_Profile), and not forced here. Every
  retired Papyrus identifier of spec section 4 / Lane C's task is gone from the code (HandleEmergencyKey, MaybeResume,
  DoUnhide, SendUnhide / SendResume, the calib session, CalActive / CalAuto / CalPending, bRewalk, sBranchInput, sKeyVanilla,
  sHide*, sEngineOpen, sSilence, bResumeAfterChoice, bHandBackNote: only history comments name three of them). The gap is
  comment lines, which never reach a .pex; a 900-line comment trim changes no compiled byte and would need a rebuild and a new
  gate run. Recommendation: the orchestrator waives the line figure (the runtime budgets S12 hold).
- Not in this list but seen: the F. / W. extra-run beats are still not folded into the standing fixture (Lane F's
  `pt19c-Ffix2_build.php` does it); the owner page says they are being moved.
