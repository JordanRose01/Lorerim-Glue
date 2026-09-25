# LoreRim + CHIM build log

Last updated: 2026-09-21

## Environment
- LoreRim MO2 instance: `F:\Modlists\LoreRim` (portable, MO2 2.5.2, active profile **Ultra**; other profiles Default/Extreme untouched). Game: `F:\Modlists\LoreRim\Stock Game`, runtime 1.6.1170.
- MO2 is connected to Nexus (Premium). nxm links are registered to this instance.
- Claude cannot drive MO2's or Nemesis's window (computer_resolve_access finds neither ModOrganizer nor Nemesis, even by exe path) and Claude's built-in browser is not logged in to Nexus (adult-flagged pages like OStim are unreadable there). Workflow that works: Jordan downloads/installs/runs tools in MO2, Claude verifies via the profile files and logs; or MO2 closed and Claude edits files directly (back up modlist.txt/settings.ini first).
- Claude's built-in browser CAN open the CHIM web UI at `http://localhost:8081` (read config, verify settings). Jordan enters API keys himself.
- LoreRim's FOMOD Plus installer plugin crashed MO2 once right after finishing the CHIM install (install was intact). MO2 also crashed once while ticking newly installed mods (4k+ mod refresh); mods were intact, just unticked.
- Log locations: crash logs + most SKSE logs in `Documents\My Games\Skyrim Special Edition\SKSE\` (`crash-*.log`); some plugin logs incl. `AIAgent.log` land in `Documents\My Games\Skyrim.INI\SKSE\`. DwemerDistro logs in `F:\DwemerDistro\Logs`. Nemesis logs in `mods\LoreRim - Nemesis Output\Nemesis_Engine\` (PatchLog/UpdateLog/CriticalLog; timestamps local time).

## Done (Ultra profile)
- Already in LoreRim, untouched: Address Library (v11), ConsoleUtilSSE NG 1.5.1, Papyrus MessageBox - SKSE NG 1.0.0, PapyrusUtil SE 4.6, powerofthree's Papyrus Extender 6.4.0, SkyUI 6.11.
- Installed + enabled at bottom of left pane: `Prisma UI - Next-Gen Web UI Framework` (1.5.1) and `CHIM` (= AIAgent 3.3.2, Nexus file 801345, FOMOD option "Vanilla SE/AE Skyrim UI").
- `AIAgent.esp` enabled near the end of plugins.txt / loadorder.txt. No LOOT, no sorting.
- Known conflict: `CHIM\Interface\dialoguemenu.swf` overrides Norden UI's dialogue menu (needed for CHIM Player Dialogue TTS). Hide the file in MO2 if Norden's look is preferred.
- Both plugins load correctly in game (skse64.log: AIAgent.dll and PrismaUI.dll "loaded correctly"; PrismaUI reports running on 1.6.1170).

## CHIM server (DwemerDistro 3.2.0, `F:\DwemerDistro`)
- Installed (needed a reboot for WSL first). WSL distro `DwemerAI4Skyrim3`. Launcher must stay open with server running while playing.
- **No AIAgent.ini needed**: AIAgent 3.3.2 auto-discovers the server through the launcher proxy on `localhost:7135`; ini is only a manual fallback.
- Quickstart done: OpenRouter key saved, TTS = PocketTTS (GPU/CUDA 12.8), STT = Parakeet (installed in CPU mode – could be moved to GPU later), MiniMe/TXT2VEC installed.
- LLM connector `Grok 4.3` = `x-ai/grok-4.3`, temperature 0.75, YAML body param `reasoning: {effort: none}`. Provider field currently `x-ai` (defaults use `openrouter`; change back if requests error).
- Default Profile: Standard LLM = Grok 4.3, Fast LLM = DeepSeek V4 Flash, Powerful = GLM 5.2, Experimental = DeepSeek V4 Pro, Diary = DeepSeek V4 Flash, Formatter = Ministral 8B.
- Global connectors (background jobs) left at defaults; `CORE_CONNECTOR_PLAYER` is still Gemini 2.5 Flash Lite – candidate to swap to DeepSeek V4 Flash now that adult content is added. Check Roleplay → events/memories for refusals/sanitised summaries.

## Crashes seen
- 2026-09-21 01:04 first launch CTD after ~3 min: `std::invalid_argument: invalid stoull argument` inside SkyrimSE.exe while parsing `%LOCALAPPDATA%\Skyrim Special Edition\ContentCatalog.txt`. Vanilla AE bug, not CHIM/Prisma. Fix applied: renamed file to `ContentCatalog.txt.bak-2026-09-21`. If it recurs: sanitise the Version strings and set the file read-only.

## Relevant things LoreRim already ships (for OStim planning)
CBBE 3BA (3BBB), HIMBO refits, XPMSSE, Nemesis, Open Animation Replacer, Paired Animation Improvements, Animation Queue Fix, RaceMenu, JContainers, UIExtensions, MCM Helper, Mfg Fix NG, Expressive Facegen Morphs, SPID, KID, Fuz Ro D-oh.

## Body randomizer audit (2026-09-21)
- LoreRim ships **no** body randomizer: no BodyGenData folder in any mod, overwrite or Stock Game Data; no OBody/AutoBody files or plugins.
- `Racial Body Morphs Redux SSE AE - FKDRS` is only per-race skeletons (height/proportions), no scripts – compatible with OBody.
- Female body: CBBE 3BA (3BBB) + Caliente's Beautiful Bodies Enhancer. `LoreRim - BodySlide Output` built with morphs (.tri present). `RaceMenuMorphsCBBE.esp` active. No base male body mod (only HIMBO armor refits).
- OBody NG prerequisites already present: BodySlide and Outfit Studio, PapyrusUtil SE, RaceMenu, UIExtensions.
- Consider blacklisting NPCs with custom BodySlide bodies in OBody (Serana, Valerica, Elisif, Bijin NPCs).

## OStim batch – installed 2026-09-21
- Installed and enabled at the bottom of the left pane (lowest = highest priority): OStim Standalone 7.5.1 (`OStim Standalone - Advanced Adult Animation Framework`, 3BA option), OStim Community Resource 1.17.6, Open Animations Romance and Erotica 1.52.1, OBody Next Generation 4.4.3, then `Nemesis Output - OStim` at the very bottom.
- Plugins enabled last: `OStim.esp`, `OStimCommunityResource.esp`, `OBody.esp` (OARE has no plugin).
- Nemesis: OStim ships two hidden Nemesis patches (`ostim`, `oanims`, hidden=true) that are applied automatically – nothing to tick.
- Nemesis custom output in `profiles\Ultra\settings.ini` changed from `LoreRim - Nemesis Output` to `Nemesis Output - OStim` (backups `modlist.txt.bak-20260921-055706`, `settings.ini.bak-20260921-055706`). **But** usvfs rewrote existing files in place, so the regenerated behaviors landed in `LoreRim - Nemesis Output` (prebuilt LoreRim output is now overwritten; `Nemesis Output - OStim` only holds Nemesis's python cache). Redownload the LoreRim output from Wabbajack/Nexus if a clean rollback is ever needed.
- Nemesis run 2026-09-21 02:04 local: "Behavior generation complete: 29 second(s)", 6326 animations, `Mod Checked: ostim` / `oanims`, 44 LoreRim patches still selected, no critical errors. Only warning: `immersiveinteractions\..\idlekneel.hkx` not registered (unrelated to OStim).

## Next
1. New game test: CHIM voice reply, OStim scene starts, OBody gives varied bodies.
2. Custom CHIM plugin work (OStim awareness). Amorous Adventures now optional.
