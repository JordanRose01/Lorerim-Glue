# pt15 smoke check (23 Sep 05:25-05:35)

Verdict: **the four lanes' work is installed and consistent, and it passes every check.** Game side: script 507 in the
MO2 mod folder equals the project. Server side: 0.5.6 deployed at 05:20 equals the project as of that deploy.
Owner page: `CLEAN_PLAYTHROUGH.md`.

## Checks

| Check | Result |
|---|---|
| `tools\compile.ps1` | `OK ... 0 errors, 0 warnings` (10 scripts) |
| Fresh compile vs installed `.pex` (all 10) | Same header, same string-table set, same body length. Only compile timestamps and string-table order differ (the compiler is nondeterministic there). Afterwards the project `Scripts\*.pex` were **reset to the installed bytes**, so project == installed byte-for-byte |
| Project `game\LoreRimGlue` (61 files) vs `F:\...\mods\LoreRim Glue` | 54 identical. The 7 others were `.pex` rebuilt by the perf lane from unchanged `.psc` (checked as above), and are now identical. Nothing is missing; only `meta.ini` exists solely in the mod folder (expected). Every lane-installed file matches: `LRG_Main/OStim/Dialogue .pex+.psc`, `config.json` (no BOM), `settings.ini`, `CBPCSystem.ini`, `CBPConfig_Breast/ButtAmplitude.txt`, unchanged Belly/zzGlueDefaults, and `OVERRIDES.md` (the body lane's later copy is the installed one) |
| Reset tool `Documents\LoreRimGlue\reset_playthrough.ps1/.sh` | SHA-256 equals `glue\tools` (93129a4b... / 986c52ff...) |
| `php -l` (project, 84 files) | 0 errors |
| Unit tests, all 12 | gates 385/0, dialogue 174/0, prompt_index 67/0, mcm_wiring 3/0, services 35/0, latency 30/0, stt 60/0, audiofilterd 75/0, scene_index ALL PASSED, latency_prompt ran. **intent 254/1 and phrases 30/1 fail on the current project tree**; see "In-flight server edits" |
| Same suites with the **deployed** `lrg_intent.php` | intent 255/0, phrases 31/0, gates 385/0, dialogue 174/0, flows 78/78 |
| `run_flows.php --strict` | 78/78 scenarios, 1314 checks, 0 warnings, RESULT OK |
| Deployed server vs project (27 files, CR-stripped) | 26 identical. Only `lib/lrg_intent.php` differs (in-flight, not ours). Nothing is on the server that isn't in the project. Manifest and `LRG_VERSION` are 0.5.6. Deployed `php -l`: 18 files, 0 errors. No redeploy was needed for any of the four lanes |
| Live `config/lrg_config.json` | Unchanged (`Lisette.min_affinity -5` still present). The reset script removes it and sets `relationship.repair.enabled=false`. The default still ships `true` |
| Distro services | apache2 and postgresql are **stopped**. The owner must press Start in the DwemerDistro launcher (it is step 1 on the owner page) |
| Skyrim / MO2 | Neither was running at 05:26, 05:30 or 05:32 (`tasklist`) |

## Profile files (timestamps)

- `plugins.txt` 04:23:31: written by MO2 when the 04:22 session launched, before pt15.
- `initweaks.ini` + `lockedorder.txt` 05:02:40: both MO2-generated files, written by MO2 itself (probably on close).
  No lane edited them.
- `modlist.txt`, `settings.ini`, `loadorder.txt`: unchanged since 22 Sep.
- **`Skyrim.ini` 05:30: changed by me, with MO2 and Skyrim confirmed closed.** The owner asked to turn off unneeded
  logging, and MO2 was closed, so the perf lane's owner step was done instead of handed over. The pre-edit file is
  backed up as `Skyrim.ini.bak-pt15-20260923-053022`. The result is **byte-identical to LoreRim's original**
  `Skyrim.ini.bak-20260922-175053` (CRLF, 5061 B): `bEnableLogging=0`, `bEnableTrace=0`, `bLoadDebugInformation=0`.
  Note: a first attempt with Git Bash `sed -i` wrote LF endings for a few seconds. It was replaced at once by copying
  the original, and `cmp` confirms the result.

## Findings for the lead

1. **In-flight server edits by a lane not in my list (a money/offer-confirmation lane).**
   `lib/lrg_intent.php` (05:29), `lrg_core.php` (05:30) and `lrg_actions.php` (05:31) are still being edited, with
   tags `[0.5.6 / pt15]` such as `lrgPendingOffer`, `lrgBareAmount`, "1k" and "1,000". **It is not deployed.** Right
   now it fails `test_intent`: "what if I gave you 500 gold" gives `offer/high gold=500` where `askprice/0` is
   expected. It also fails `test_phrases` group "money" (3 of 90). That lane must finish, update or pass the tests,
   bump the version (it reuses the 0.5.6 label), and deploy with `deploy_server.ps1` while the CHIM server runs, so
   the prompt-index step succeeds. I did not deploy it.
2. **STT recommendation conflict, resolved on the page.** pt15-stt recommends Deepgram nova-3 because "the driver
   sends up to 30 names as keyterms". **This is wrong as shipped.** I checked the live `stt/stt-deepgram.php`: it
   builds `$url` with `&keyterm=...` and then **overwrites `$url`** on the next statement
   (`$url = "https://api.deepgram.com/v1/listen?punctuate=true..."`), so no keyterm is sent. That matches
   pt15-cloud-tts. The owner page recommends staying on Parakeet (with the glue fixes) and gives OpenAI Whisper as
   the upgrade, since its prompt carries names. Deepgram is marked "not as shipped".
3. The "first word" findings don't conflict. The DLL starts capturing 110-270 ms after the key press
   (cloud-tts lane). The owner still pauses before speaking, so the recordings hold room tone first (stt lane). The
   page gives both habits: wait a beat after pressing, and hold the key half a second after the last word.
4. Carry-overs, not blocking: `lrg_config.default.json` still ships `relationship.repair.enabled=true`. The OBody
   `RemoveClothesOverlay`/`AddClothesOverlay` scene hook is not built. The `ExtraBreast` damping override was not
   made. The prompt index goes stale if the load order changes.

## Staging and scripts

`%TEMP%\lrg_test\pt15s\` (staged tree `t\`, `pex_before\`, `pexcmp.py`, `pexdiff.py`, `pexstr.py`),
`%TEMP%\lrg_test\pt15s_all.sh`, `%TEMP%\lrg_test\pt15s_intent.sh`. Test outputs: `/tmp/lrg_pt15s/*.txt` and
`/tmp/lrg_pt15d/*.txt` in the distro.
