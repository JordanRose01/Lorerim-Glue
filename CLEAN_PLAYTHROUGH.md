# Clean playthrough - what changed and what to do (23 Sep, 05:35)

Everything below is installed and tested: game scripts v507 (same number, new contents) and server 0.5.6.
Do the steps in this order before you start the real playthrough.

---

## 1. Performance: what was turned off

Already done, nothing for you to do:

| What | Before | Now |
|---|---|---|
| **CBPC physics logging** | Wrote to the log on every physics frame: a 710 MB file in one 25-minute session | Off (`CBPCSystem.ini` Logging = 0) |
| **Papyrus script logging** | On since our debugging: about 6,500 lines a minute, plus a freeze and stack dump about every 62 s | **Off.** MO2 was closed at 05:30, so I made the change myself (details below) |
| **The glue's own logging** | About 7 lines a minute, each one also reading MCM settings | New MCM setting **LoreRim Glue > General > "How much to log"**, default **Normal**. That cuts it to about 2-3 lines a minute, with no MCM reads. Use **Everything** only when chasing a problem |
| **Crosshair "facts" check** on NPCs | Median 0.8 s of script time, with 2 quest sweeps each time | One sweep and a 30 s settings cache |
| **The LoreRim Glue MCM** | Never opened: showed an error page because of a hidden byte at the start of its file | Fixed. It opens properly for the first time (12 pages) |

**The Papyrus change, for reference.** In `F:\Modlists\LoreRim\profiles\Ultra\Skyrim.ini`, under `[Papyrus]`:
`bEnableLogging=0`, `bEnableTrace=0`, `bLoadDebugInformation=0`. The file is now byte-for-byte LoreRim's original. The
previous version is saved as `Skyrim.ini.bak-pt15-20260923-053022`. To turn logging back on for a debugging session,
close MO2 first, then set those three lines to `1` (or ask Claude).

**Start a NEW game.** This is the one performance step left for you. Your current saves still carry a dead copy of the
old glue quest. It piles up stuck script calls, about 1,200 by the end of the last session, and gets worse every time
you load. A new game has none of them.

Optional: delete `Documents\My Games\Skyrim Special Edition\SKSE\CBPC-Collision.log` (710 MB). It is leftover from
the old logging and only takes up disk space now.

TTS and speech-to-text run on the CPU (8 threads), so your RTX 4080 stays free for the game.

---

## 2. Reset the NPCs' memory (do this right before New Game)

1. **Start the CHIM server.** In the DwemerDistro launcher, press **Start**. It is stopped right now.
2. **Quit Skyrim.** MO2 can stay open.
3. *(Optional restore point.)* Open http://localhost:8081, then **Control Panel** > **Playthrough Manager** tab >
   **Save Current Public Database**. Type `testing` as the *Snapshot name*, then click **Save Snapshot**.
4. *(Optional.)* If you hand-edited an NPC's profile or voice and want to keep it, open CHIM's NPC manager and press
   **Lock Profile** on that NPC. Unlocked NPCs are rebuilt fresh when the new game first loads.
5. Open PowerShell. First see the plan (this changes nothing):
   ```
   powershell -ExecutionPolicy Bypass -File "C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.ps1" -ChimToo
   ```
   Then run the reset:
   ```
   powershell -ExecutionPolicy Bypass -File "C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.ps1" -Yes -ChimToo
   ```
   The script backs everything up first. It then clears conversations, memories, relationships, quest history and the
   glue's own notes, and removes the Lisette test tweaks. Voices, NPC profiles, settings and your CHIM setup are
   kept.
6. **Start a New Game.**
7. **To undo:** the script prints a `wsl ... restore.sh` command, and the step 3 snapshot also works through
   **Copy to Public**.

---

## 3. Voice-to-text

**Fixed (server 0.5.6; it switches on by itself when the CHIM server starts):**
- **Names.** A misheard word that sounds like the name of someone present is corrected to that name: "Lazette" becomes
  "Lisette". Tested on all 121 of your logged lines: it made 12 corrections, all right, and changed nothing else. It
  only swaps words that aren't English, so "brass" or "list" are never touched.
- **NPC didn't answer.** Most of those times, the speech engine returned *nothing* for a clear recording, and CHIM
  dropped the empty line. The glue now transcribes those again. It recovered 2 of your 3 real failed lines. Lines
  that came back too short also get one more try.
- **Cut-off words.** Your recordings show the start of what you say is captured, but the game cuts off about the last
  0.2 s when you let go of the key. That cut-off is what makes the engine drop or garble words.

**Two habits, free:** press Left Ctrl, **wait a beat**, then speak. **Keep holding about half a second** after your last
word. Don't switch CHIM to open mic, because that really does clip first syllables.

**Recommended engine: stay on Parakeet (current).** It is the fastest (about 0.1 s per line) and free, and the new
fixes are built around it.
**If names or dropped words still bother you after a few sessions, switch to OpenAI Whisper.** It is the only cloud
option in CHIM whose name hints actually reach the engine. It costs about $0.40 a month and probably adds 0.5-1.5 s
per line (an estimate).
Steps:
1. CHIM UI > **API Keys** > **OpenAI** card > paste your key.
2. Open http://localhost:8081/HerikaServer/ui/stt_connectors.php and click the **Whisper** card. Set API Badge to
   OpenAI, `LANG` to `en` and `TRANSLATE` to off, then **Save**.
3. To revert: same page, **Parakeet** card > **Save**.

The name fix keeps working on Whisper; the empty-line retry only runs on Parakeet.
*Not Deepgram:* CHIM's Deepgram driver builds the name list and then throws it away (a bug in CHIM itself), so it
gives no name help.

---

## 4. Nipples and breast physics

- **Nipples.** The textures are fine. The cause is **OBody NG's "ORefit"**: clothed NPCs get a push-up shape that
  also *deletes the nipple*, and it stays on when OStim undresses them.
  **Fix:** in game, go to **MCM > OBody NG** and untick **"Enable ORefit"** and **"Enable ORefit nipple morphing"**.
  On the new game, do this **as soon as the MCM appears**: both are on by default, and the setting is saved per
  save file.
- **Physics toned down.** Breasts went from 1.0 to **0.55** and belly stays at 0.4. **Butt (24 Sep): the other way.** It was never visible - the 3BA body's butt bones carry only a fifth of the skin and LoreRim installed 3BA's stiffest butt springs - so the glue now ships 3BA's own "Very Soft" butt springs (`CBPConfig_butt.txt`) and the butt volume goes 0.6 -> **1.0** (3BA's "Normal"). Expect a modest, visible movement on a fuller body, nude in a scene or in a dress walking away - never on anyone in armour (most armour meshes have no butt bone).
- **Dressed NPCs toned down separately (23 Sep evening, after the Solitude walk).** CBPC has its own multiplier for
  a woman who is wearing something, and 3BA ships it at 1.0 - so a dress, leather or plate never changed the bounce,
  and the townsfolk you watched were moving at the full nude volume. The glue now sets it to **0.70 in clothes,
  0.50 in light armour, 0.30 in heavy armour**. The nude body in scenes is unchanged.
  The dials are in `F:\Modlists\LoreRim\mods\LoreRim Glue\SKSE\Plugins\`:
  - `CBPConfig_3b_armor.txt`: **dressed** women. Change the six `breastClothedAmplitude` numbers together: 0.60 is
    calmer, 0.80 livelier (light and heavy armour have six lines each of their own). Keep `CBPConfig_BBP_armor.txt`
    at the same numbers.
  - `CBPConfig_BreastAmplitude.txt`: the **nude / scene** body. Change all 12 numbers the same way. 0.45 is calmer,
    0.65 livelier.
  - `CBPConfig_ButtAmplitude.txt`: change all 3 numbers together. 0.8 is calmer, 1.0 (shipped) is 3BA's "Normal"; not above 1.0. If in scenes the cheeks look shoved rather than wobbling, set the three `collisionMultipler` lines 2.0 -> 1.0 in `CBPConfig_butt.txt`.
  - `CBPConfig_BellyAmplitude.txt`: 0.4.

  The install needs no command from you (the game starts fresh afterwards). After editing a dial later, type
  `cbpc reload` in the console to apply it without restarting. If they still bounce too long rather than too far,
  ask Claude to raise the damping.

  **One look after the install, please:** in third person in the Solitude market, watch a woman in a dress walking
  (not the farmer-robe outfit, it uses an older bone), then a guard, then the nude body in any scene. Dressed should
  now move clearly LESS than nude. If dressed looks as lively as nude or livelier, say so: it means CBPC uses the
  dressed number on its own instead of multiplying, and the fix is the six Clothed lines to 0.50 (light 0.35,
  heavy 0.20) plus `cbpc reload`.

---

## 5. Still needs your word

1. **Better NPC voices.** Pick one, or keep PocketTTS:
   - **Chatterbox 0.5B (local, free, no content rules, keeps every NPC's current voice).** It sounds clearly better
     than PocketTTS, but it runs on your **RTX 4080, next to Skyrim**. The last time TTS shared the GPU with the game,
     replies waited **3.4 s** on average, compared with about 0.6 s now on the CPU. It needs a few GB of downloads,
     and its server setup is untested here. Undoing it takes one field: set the connector's Model back to
     `pocket-tts`. Worth trying only if you're willing to cap your framerate or lower a texture tier to leave the
     GPU room.
   - **ElevenLabs (cloud).** Best quality, no load on your PC or GPU, and a paid subscription. Every NPC line is sent
     to ElevenLabs. If their service is down, NPCs go silent until you switch back. Its policy doesn't list
     consensual adult content as banned (it isn't explicitly allowed either). Voices are assigned by race and gender
     at first, and key NPCs can get their own. You enter the API key yourself.

   Whichever you pick, **set per-NPC voices after you start the new game** (or lock those NPCs first). CHIM's first
   load rebuilds unlocked NPCs.
2. **OpenAI Whisper** for speech-to-text (section 3), only if Parakeet plus the fixes still isn't good enough.
3. *(Optional build.)* To keep ORefit's clothed shaping outside scenes and still have correct nude bodies inside
   them, the glue could switch ORefit off per actor for the length of each OStim scene. It's small, but it isn't
   needed if you untick ORefit as in section 4.
