# Refine pass - Papyrus specialist (LoreRim Glue 0.1.0)

Tool calls used: 32 of 60. Compile after fixes: OK (all six .pex rebuilt).

## Verified clean (no change needed)
- Every external call in LRG_Main / LRG_Profile / LRG_OStim checked against the INSTALLED sources:
  AIAgentFunctions (logMessageForActor, commandEndedForActor, requestMessageForActor, setAnimationBusy(int,String),
  isActorTalking, getAgentByName), OThread / OThreadBuilder / OActor / OActorUtil / OLibrary / OMetadata / OJSON,
  PO3_SKSEFunctions (GetFormEditorID, GetFormFromEditorID, GetActorsByProcessingLevel), SKSE (GetFactions(int,int),
  HasKeywordString, GetSex, GetCurrentCrosshairRef, IsTextInputEnabled, GetPluginVersion, StringUtil.Find/Substring).
  Names, argument order and return types all match. OStim's own scripts use SKSE.GetPluginVersion("OStim"), same as ours.
- CHIM_SpeechStarted pushes exactly one Form (AIAgentAIMind.psc:1719-1723); CHIM_CommandReceived is created at :1397.
- LRG_MCM lives on its OWN quest (0x801, make_esp.py:45), so LRG_Main's UnregisterForAllModEvents / UnregisterForAllKeys
  cannot wipe SkyUI/MCM Helper registrations. LRG_Main + LRG_OStim share quest 0x800: Main unregisters first and then
  calls ost.Maintenance(), which re-registers - order is correct. Only LRG_OStim has OnUpdate, so the shared per-form
  update registration is safe.
- lastTalkTime declared mid-file: legal Papyrus, compiler accepts it, initialised to 0.0.
- De-dup window: key includes the parameter, which carries the server's per-turn cid, so two "faster" in a row differ.
- ParamGet index arithmetic is correct for ok / cid / scene / missing key (but see finding 2).

## Findings
1. MAJOR (fixed) - real-time stamps survive in the save. Utility.GetCurrentRealTime() restarts at 0 on each game launch.
   After a 3 h session, save, relaunch: `now - lastAnySnapTime` is negative => `< 2.0` is true => NO snapshot is sent for
   3 hours; server gates on stale data. Same for lastTalkTime (scene talk silent), lastCmdTime, and `starting` +
   startDeadline (a save made during start-up => "a scene is already running" for hours).
   Fix: LRG_Main.Maintenance resets all stamps; LRG_OStim.Maintenance resets starting / navInFlight / lastTalkTime;
   throttle comparisons also require now >= stamp.
2. MAJOR (fixed) - HasChildToken("young") matched the vanilla ADULT voice types MaleYoungEager / FemaleYoungEager
   (LRG_Profile.psc:20,49). Hundreds of adults (Ysolda, Camilla, Sven ...) reported adult=0, and any of them inside the
   witness radius produced witkid=1 => "a child is nearby" in practically every town. Fix: for voice types only, the
   exact token "YoungEager" is exempt; IsChild(), race, class and every other token are unchanged (child check not weakened).
3. MINOR (fixed) - ParamGet with an empty value in the middle ("scene=;undress=1"): Substring(s, start, 0) means
   "to end of string", so it returned ";undress=1;...". Harmless today only because GetActorCount() rejected the garbage.
   Now returns "".
4. MINOR (fixed) - MaybeSnapshot: IsEnabled() (3 MCM natives) + GetPlayer + IsDead + keyword ran on EVERY crosshair change,
   and non-CHIM NPCs hit getAgentByName every time. Reordered: one GetCurrentRealTime call then throttles; 5 s negative
   cache for non-agents; snapBusy taken before the first yielding call and auto-released after 30 s if a stack was dumped.
5. MINOR (fixed) - WitnessScan: distance test now first (1 native rejects most of the high-process list instead of 3-4);
   returns immediately on the first child in range (it blocks anyway, no more LOS work). NOTE: when witkid=1 the wit/witfol
   counts are partial.
6. MINOR (fixed) - OThreadBuilder.Start returns int; a -1 now reports the error at once instead of holding the NPC
   animation-busy for the 20 s timeout.
7. MINOR (fixed) - PushState guarded against partner == None (npos=-1).
8. MINOR (fixed) - IsMarried / IsPlayerSpouse fall back to vanilla FormIDs (Spouse 0x142CA, PlayerMarriedFaction 0xC6472)
   when the EditorID lookup returns None (it depends on po3 Tweaks' editor-id cache).
9. ADDED - ";psex=" (0 male, 1 female, -1 unknown) appended at the end of PlayerFacts.

## Not fixed / notes
- CHIM_SpeechStarted fires when the NPC STARTS SPEAKING (after the LLM answered), so it refreshes the snapshot for the
  next turn only; the pre-speech snapshot relies on the crosshair event. Acceptable; a first turn spoken without ever
  looking at the NPC will gate on "no snapshot" server side (fails closed).
- WitnessScan cap of 40 in-range NPCs kept.

## For the integrator
- Re-deploy the rebuilt .pex (game\LoreRimGlue\Scripts) with install_mo2.ps1.
- Server: parse the new snapshot key psex; treat witkid=1 snapshots as having partial wit/witfol counts.
