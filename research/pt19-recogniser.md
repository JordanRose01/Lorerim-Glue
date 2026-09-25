# pt19 - recogniser: "why don't you get naked" is a request, not a negation (INVESTIGATOR notes)

Lane: the negation guard in `glue/server/lorerim_glue/lib/lrg_intent.php`. Sections 1-7 are the INVESTIGATOR's record
(no project file was edited at that stage; the prototype lived on a staged copy under
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\recogniser\`). **Section 8 is the IMPLEMENTER's record (2026-09-24
04:30 -04:00): the fix IS applied to the project, with the two refuters' corrections - where section 8 differs from
sections 3-7, section 8 is what the code does.** The applied diffs are in 8.6 and at
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\recogniser\impl_*.diff`; the investigator's prototype diffs (7.x) are
kept for the record only.

## 1. The failing turn (log = data)

`\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log`, 2026-09-23 00:17:38 -04:00,
cid=s19a2033f: `turn npc=Lisette type=inputtext mode=silent say="well we're in a private room already why don't you get
naked?" intent=none/- conf=none why=negated`. Seventeen seconds later (00:17:55) "okay take your clothes off" WAS
recognised (`intent=undress/npc conf=high`) and still executed nothing: `silent: no fresh snapshot (age=93602s) ... she
answers in words (the player asked for undress)`, hidden=`Clothing(mode silent)`. Tonight (2026-09-24 02:42) no
"strip"/"naked" line reached the glue at all (only "i want you to get on that bed and fuck me" -> act/vaginal high, scene
started with undress=1).

**Attribution, corrected in pass 2 (refuter 2; the implementer re-read the log lines): the owner's "strip naked" report is
NOT explained by this fix.** Tonight no strip utterance reached CHIM at all: AIAgent.log's first push-to-talk recording of
the session is 02:42:24 (the "fuck me" line), chim.log is empty 08:00-08:39 +02:00, the glue's first turn is log line 3232
(02:42:27), and Lisette was undressed by the scene start ("glue undressed 1 actor(s)"). The most recent strip requests on
record - 2026-09-23 20:05:11 `say="take your clothes off"` and 20:05:29 `say="i wan see you take your clothes off"` (log
lines 2919-2946) - were recognised `intent=undress/npc conf=high` by the UNMODIFIED code, the model chose `Clothing
item="undress"`, the gate passed, `ExtCmdLRG_Clothing` reached the game, and the GAME refused: `GAME DRY RUN WOULD Undress
who=NPC part=All inscene=0` -> `Error: I cannot do that right now [why: dry-run mode, nothing changed]`, `voiced: no (a dry
run changes nothing)`. That is the developer dry run (`bDryRun:General`, LRG_Main.psc / MCM Diagnostics): ON in that
session, OFF since 23:15:59 (`GAME dev dry run off (bDryRun:General 0)`, line 3174) and OFF tonight (line 3229, 02:42:15).
The 00:17:38 turn this lane fixes was blind (mode=silent, snapshot 93585 s old). Three sessions, three causes - dry run;
blind/silent; no utterance reached CHIM - and the recogniser fix changes the outcome of none of them. The owner's own
wording, "strip naked", is undress/high in the base grammar. What the fix buys is the phrasing the owner used once ("why
don't you get naked"), the family gaps of section 3, and the rails the refuters found (section 9).

Two consequences, stated plainly:
- The recogniser bug is real and is fixed below (the log line will read `intent=undress/npc conf=high`, the directive
  will name the undress, heat/quote memory move).
- The recogniser fix ALONE would not have made Lisette strip at 00:17: that turn was `mode=silent`, blind (snapshot 26 h
  old) and Clothing was hidden by the mode. On a blind turn the fixed recognition yields the "not right now" directive
  (lrg_actions.php:3814-3823, owner addendum 11) instead of silence - which is the NEVER SILENT rule, not the strip. In
  mode private/scene with a fresh snapshot (as tonight's 02:42 turn was) the same sentence now leads to ChangeClothing.

## 2. Root cause, line by line (project file `glue/server/lorerim_glue/lib/lrg_intent.php`, unmodified)

1. `lrgIntentNegated()` lines 126-129: `\b(don'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop
   asking)\b` matches the `don't` inside "why don't you". No exemption for invitation frames.
2. `lrgIntentBlocked()` lines 132-140 tests `negated` (line 136) BEFORE the question/polite test (line 137), so the polite
   frame never gets a say. `lrgIntentPolite()` lines 120-123 ALREADY lists `why don'?t (you|we)` and `how about (you|we)`
   as polite frames - the knowledge exists, it is consulted only against the question blocker.
3. The negation test is also applied per clause in three callers - the rescue loop in `lrgRecogniseIntent()` lines
   872-883, `lrgIntentScanOrdered()` line 1035, `lrgIntentExtra()` line 982 - so a comma version ("we're in a private room
   already, why don't you get naked?") dies the same way: the clause that carries the request is the one skipped as
   negated. Hence the fix belongs INSIDE `lrgIntentNegated()`, once, not in the callers.
4. Precedent: `lrgEscortClause()` line 638 already rewrites `why don'?t (you|we)` / `why don t (you|we)` to `please` before
   its own negation test - which is why test_intent section J's "why don't you come with me" -> follow passes today while
   the general recogniser fails on the same frame.
5. A SECOND defect in the same sentence, found by the prototype's tests: `lrgIntentClothingWho()` line 568 returns `both`
   on `\b(both|us|we|each other|together|our)\b`, and the aside "we're in a private room" contains `we` (the apostrophe
   is a word boundary). With only the negation fixed, the owner sentence resolved to `undress/high who=BOTH` - the player
   would have been undressed too. The `who` must not be read off an aside.
6. Family gaps (probe of the unmodified code, ctx=null and a real out-of-scene ctx, same result): "lose the clothes",
   "lose my clothes", "ditch the armor", "remove your armour" -> `none` (the undress regex at line 1102 needs
   `clothes off` / `take ... off` / `get ... off`); "bare yourself" -> `undress/low` (no frame verb, so no order);
   "bare me" -> `undress/low who=npc` (wrong target: `(undress|strip|get|take) me` at line 570 lacks `bare`).
7. "why not X?" and "won't you X?" were blocked as `question` (not as negated): `why`/trailing `?` trips
   `lrgIntentQuestion()` and neither frame is in `lrgIntentPolite()`. Speech-to-text "why don t you get naked" the same
   (`don t` is neither a negation nor a known polite frame).
8. "not naked" / "not now" (no proposal pending) are NOT negations today: `not` alone is not in the guard, so "not naked"
   came back `undress/low`. The lane lists "not X" among the negations that must hold, so a clause-initial `^not\b` is
   added (clause-initial only, to stay clear of "not so fast" / "not enough" mid-sentence idioms).

Probe before the fix (WSL, `probe.php` in the staging dir): all ten "why don't you ..." forms -> `why=negated`;
"why not get undressed?", "won't you take your clothes off?", "why don t you get naked" -> `why=question`;
"how about you take it all off", "would you get naked for me?" -> `undress/high` already. All true negations -> negated.

## 3. The fix (smallest complete, one library file + one test file; no Papyrus, no config, no wire change)

`glue/server/lorerim_glue/lib/lrg_intent.php` (six hunks, see the diff):
- a) `LRG_INTENT_FRAME1` (line 80): add the imperative verbs `lose|ditch|shed|remove|bare` so "lose the clothes" /
  "bare yourself" are framed (conf=high) like "strip" is.
- b) new `const LRG_INTENT_INVITE` = `why (don'?t|don t|not) (you|we|ya)` | `why not` | `won'?t you` | `won t you` |
  `how about (you|we)`, and `lrgIntentUninvite($t)` which rewrites those frames to `please` (the escort rule of line 638,
  made shared). Placed right above `lrgIntentNegated()`.
- c) `lrgIntentPolite()`: the invitation const replaces its inline `why don't` / `how about` alternatives (one source of
  truth) - this is what un-blocks "why not X?", "won't you X?" and the STT "why don t you".
- d) `lrgIntentNegated()`: match on `lrgIntentUninvite($t)` and add `|^not\b`. Every caller (whole utterance, clause
  rescue, ordered scan, extra, escort) inherits it.
- e) `lrgIntentStop()`: `$t = lrgIntentUninvite(trim(...))` so "why don't you stop" / "why don't we stop" are the stop
  they are (they were `none/negated`; without this line they would become `none/no pattern matched`). "why don't you
  stop teasing me and fuck me" stays the act (the `stop\b(?!\s+\w+ing)` gerund guard still applies).
- f) `lrgIntentClothingWho()`: judge `who` from the LAST invitation frame onward when one is present, and stop counting
  `we're / we've / we'll / we'd / we are / we were / we have / we got` as "both"; add `bare|make` to the "... me" verbs.
- g) undress branch (line 1102): a second alternative `(lose|ditch|shed|remove|drop) ... <garment>` with an explicit
  garment list (no `them` / `it back`, so "drop to your knees" and "lose them" stay what they were).

`glue/tools/test_intent.php`: new section K appended before the summary - the owner sentence verbatim, the comma
variant, 13 further paraphrases (incl. STT, lead-in, compound, aside-with-"we"), 5 true negations, the stop/none
non-regressions, and the undress family for both targets (20 rows). The diff is at the end of this note.

Optional, one sentence in `glue/PROTOCOL.md` 7.2 (line 568, "Blockers ... A polite-request frame beats the question
blocker."): add "An INVITATION frame (`why don't you X`, `why not X`, `won't you X`, `how about you X`) is a polite
request and never a negation (`lrgIntentUninvite()`); a clause that opens with `not` is one." No other doc line is
contradicted (the escort paragraph at lines 1484-1488 already states the same rule for its own clause).

## 4. Verification (WSL, DwemerAI4Skyrim3, staged copy + the live `data/` dir copied next to it)

| suite | before (project tree) | after (prototype) |
|---|---|---|
| `php -l lrg_intent.php`, `test_intent.php` | ok | ok |
| `tools/test_intent.php --quiet` | 316 passed, 0 failed | **369 passed, 0 failed** (53 new checks in section K) |
| `tools/test_phrases.php --quiet` | 47/0, verdicts ok=720 | 47/0, verdicts ok=720 (unchanged: MUST groups and the 0.82 floor hold) |
| `tools/flows/run_flows.php --strict` | (82 scenarios listed) | **82 scenarios: 82 passed, 0 FAILED, 0 pending; 1467 checks, 0 warnings - RESULT: OK** |

Probe after the fix: all 16 invitation forms -> `undress/high` with the expected who (npc / both / player); the 10 true
negations -> `none/negated` ("don't stop, harder" still `faster/high`, "don't slow down" still blocked); family npc and
player rows all `undress/high` with the right who; "why don't you tell me about yourself", "why don't you sit down",
"why not?", "how about we talk" -> `none/no pattern matched`; "would you ever get naked" -> `hypothetical`; "why don't
you stop" / "why don't we stop" -> `stop/high`; escort "why don't you come with me" -> follow (section J unchanged).

First prototype pass (before hunk f) failed exactly the two owner-sentence rows with `who=both` - the evidence for
finding 5 above.

## 5. Blast radius and what was deliberately NOT changed

- Server-side PHP only. No `.psc` touched, so `CurrentVersion` 510 stays (no bump); no config.json, no MCM, no wire.
- `lrgEscortClause()` keeps its own inline rewrite (line 638) - it could call `lrgIntentUninvite()` instead, but its
  explicit `won'?t` blocker (line 685) means "won't you come with me" is still no escort; out of this lane, noted below.
- `lrgIntentPart()` (line 577) can still be poisoned by an aside ("hand me the ale, then take your clothes off" ->
  part=hands, `hands?` matches "hand"); pre-existing, out of scope.
- The dress branch's `\bdress\b(?!\s+me\b)` (line 1103 of the unmodified file, not 1094) reads the garment noun "dress"
  as "get dressed": "take off your dress" / "lose the dress" -> kind=dress. **[implementer] This bullet's "not made
  worse" claim was FALSE for the prototype: `lose` joining LRG_INTENT_FRAME1 turned "lose the dress" from dress/low into
  dress/HIGH - a confident order to put clothes ON, actionable by the safety net. The determiner guard IS applied now
  (8.1 item 4): "lose the dress" / "take off your dress" / "drop your dress" -> undress/high; "take off my dress", which
  was dress/high who=npc (HER told to get dressed), -> undress/high who=player.**
- The dialogue lane's barter/follower guard `lrgDlgNegatedAt()` (lrg_dialogue.php:3384-3404, `LRG_DLG_NEG_WORDS`
  includes `dont`/`don't`/`not`) has NO invitation exemption either: "why don't you sell me a mead" is likely read as a
  negated barter phrase there. Different file, different owner; flagged as an open question.

## 6. Owner / implementer steps

(Rewritten in pass 2 - the original steps 1-2 are done: sections 8 and 9.)

1. Nothing is left for the implementer: pass 1 (section 8) and pass 2 (section 9) are applied to the project
   (`glue/server/lorerim_glue/lib/lrg_intent.php`, `glue/tools/test_intent.php`, `glue/tools/flows/scenarios/19_intent_directive_net.php`).
   The PROTOCOL sentences (8.7) are the orchestrator's.
2. Suites as they stand after pass 2 (WSL, staged copy + the live `data/`): `php tools/test_intent.php --quiet` 459/0,
   `php tools/test_phrases.php --quiet` 47/0 (ok=720, 92.3%), `php tools/flows/run_flows.php --strict` 82/82, 1473 checks,
   0 warnings.
3. Owner: deploy with `tools/deploy_server.ps1` (never run by an agent). The live
   `HerikaServer/ext/lorerim_glue/lib/lrg_intent.php` is STILL the pre-fix file (mtime 2026-09-24 02:07, identical to
   `.backup/pt19-recogniser/lrg_intent.php.bak`): nothing played before the deploy exercises this fix.
4. In-game check, in THIS order (refuter 2 - the report was never a recogniser failure, section 1):
   a. On load, the glue log must show `GAME dev dry run off (bDryRun:General 0)` (MCM Diagnostics). With the dry run ON the
      game answers every ChangeClothing with `DRY RUN WOULD Undress ... [why: dry-run mode, nothing changed]` - that is
      what refused the 09-23 20:05 strips.
   b. Lisette in a private room with a FRESH snapshot (mode private, not blind: the turn line must not say `no fresh
      snapshot`).
   c. Say the owner's own words first: "strip naked" (undress/high in the base grammar). Expect the turn line
      `intent=undress/npc conf=high`, then `llm ... action=Clothing item="undress"`, `gate: Clothing passed`, and a GAME
      result WITHOUT the dry-run error.
   d. Then "why don't you get naked" - the same three lines (this is what the fix changed: it was `intent=none why=negated`).
   e. If she still does not strip, the turn / gate / result lines name the real gate (dry run, `no fresh snapshot`, the
      mode) - not the recogniser. On a blind turn expect her voiced "not right now" line, not silence and not a strip.

## 7. Diffs (prototype vs project)

### 7.1 glue/server/lorerim_glue/lib/lrg_intent.php
```diff
--- PROJECT/server/lorerim_glue/lib/lrg_intent.php	2026-09-23 05:39:23.940103200 -0400
+++ STAGED/server/lorerim_glue/lib/lrg_intent.php	2026-09-24 04:08:05.229323400 -0400
@@ -77,7 +77,7 @@
  * request ceiling in one go, and the request died in silence (F1). The list is now the verbs people
  * really use, and lrgIntentFrame() tests it on every clause, not only on the whole utterance.
  */
-const LRG_INTENT_FRAME1 = '/^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep|do|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|snuggle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|slide|shove|climb|straddle|lean|drop|roll|flip|swallow|deepthroat|tease|finish|make|gimme|fondle|spank|massage|suck|smack)\b/';
+const LRG_INTENT_FRAME1 = '/^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep|do|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|snuggle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|slide|shove|climb|straddle|lean|drop|roll|flip|swallow|deepthroat|tease|finish|make|gimme|fondle|spank|massage|suck|smack|lose|ditch|shed|remove|bare)\b/';
 
 // ---------------------------------------------------------------- cleaning
 /** The player's utterance, ready to match on: no DLL context prefix, no "Name: " prefix, lowercase, collapsed. */
@@ -119,13 +119,31 @@
  */
 function lrgIntentPolite(string $t): bool
 {
-    return (bool) preg_match('/\b(can|could|will|would) you\b|\b(can|could|may) i\b|\bwhy don\'?t (you|we)\b|\bhow about (you|we)\b|\bmind if\b|\bwould you like to\b/', $t);
+    return (bool) preg_match('/\b(can|could|will|would) you\b|\b(can|could|may) i\b|\bmind if\b|\bwould you like to\b/', $t)
+        || (bool) preg_match(LRG_INTENT_INVITE, $t);
+}
+
+/**
+ * [0.5.8 / pt19] The INVITATION frames. "why don't you X", "why not X", "won't you X", "how about you X" ask for X:
+ * the don't / not / won't they carry is not a refusal. "well we're in a private room already why don't you get
+ * naked?" (2026-09-23 00:17:38) was logged intent=none why=negated because lrgIntentNegated() saw the don't.
+ * The frames are rewritten to "please" before the negation test (the escort clause has done the same since
+ * 0.5.4), and they are the polite frames lrgIntentPolite() accepts, so "why not get undressed?" is no longer
+ * thrown away as a question either. "don't X", "do not X", "never X", "I don't want you to X" and a clause
+ * that OPENS with "not" ("not naked", "not now") stay negations.
+ */
+const LRG_INTENT_INVITE = '/\bwhy (?:don\'?t|don t|not) (?:you|we|ya)\b|\bwhy not\b|\bwon\'?t you\b|\bwon t you\b|\bhow about (?:you|we)\b/';
+
+/** The cleaned utterance with its invitation frames read as the "please" they are. */
+function lrgIntentUninvite(string $t): string
+{
+    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(LRG_INTENT_INVITE, 'please', $t)));
 }
 
 /** The negation test on its own, so it can also be applied to the FRAGMENT the scan matched. */
 function lrgIntentNegated(string $t): bool
 {
-    return (bool) preg_match('/\b(don\'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b/', $t);
+    return (bool) preg_match('/\b(don\'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b|^not\b/', lrgIntentUninvite($t));
 }
 
 /** Why this utterance is not a request at all, '' = it may be one. */
@@ -209,7 +227,7 @@
  */
 function lrgIntentStop(string $t): bool
 {
-    $t = trim($t, " \t?.!");
+    $t = lrgIntentUninvite(trim($t, " \t?.!"));   // [pt19] "why don't you stop" is "please stop"
     if (preg_match('/\b(can\'?t get|cannot get|never|not)\s+enough\b/', $t)) { return false; }
     if (preg_match('/^(?:please\s+)?(stop it|stop this|stop|halt|quit)\b(?!\s+\w+ing\b)/', $t)) { return true; }
     if (preg_match('/\b(we|let\'?s|i)\s+(should|need to|want to|have to)\s+stop\b/', $t)) { return true; }
@@ -565,9 +583,13 @@
  */
 function lrgIntentClothingWho(string $t): string
 {
-    if (preg_match('/\b(both|us|we|each other|together|our)\b/', $t)) { return 'both'; }
+    // [pt19] an aside before the request says nothing about whose clothes: "well we're in a private room already
+    // why don't you get naked?" resolved to BOTH on the aside's "we're". From the invitation frame on only, and
+    // a "we're / we are / we have" that states where they are is not a "we" that asks for both.
+    if (preg_match_all(LRG_INTENT_INVITE, $t, $m, PREG_OFFSET_CAPTURE) && $m[0]) { $last = end($m[0]); $t = substr($t, (int) $last[1]); }
+    if (preg_match('/\b(both|us|each other|together|our)\b|\bwe\b(?!\'(?:re|ve|ll|d)\b| are\b| were\b| have\b| got\b)/', $t)) { return 'both'; }
     if (preg_match('/\bmy (clothes|armou?r|shirt|boots|things|gear|tunic|robes?|pants|trousers)\b/', $t)
-        || preg_match('/\b(undress|strip|get|take) me\b/', $t)
+        || preg_match('/\b(undress|strip|get|take|bare|make) me\b/', $t)
         || preg_match('/\b(off|from) me\b/', $t)
         || preg_match('/\bmine\b/', $t)) { return 'player'; }
     return 'npc';
@@ -1108,7 +1130,9 @@
     // [0.3.1 fix pass] `undress` was anchored on both sides, so "get undressed" - the plainest way to
     // say it - matched nothing at all and the whole sentence went silent.
     if (!$veto
-        && preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b.*\boff\b|out of (those|your|that))\b/', $t)) {
+        && (preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b.*\boff\b|out of (those|your|that))\b/', $t)
+            // [pt19] "lose the clothes", "remove your armour", "drop your pants": a shedding verb plus a garment of its own
+            || preg_match('/\b(?:lose|ditch|shed|remove|drop)\b[^.]{0,20}\b(?:clothes|clothing|garments?|armou?r|dress|shirt|tunic|robes?|pants|trousers|boots|shoes|gloves|gauntlets|helmet|helm|hood|hat|gear)\b/', $t))) {
         return ['kind' => 'undress', 'kv' => ['do' => 'undress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
     }
     // acts last: the recogniser NEVER filters by tier here (the ceiling is applied when the kv is
```

### 7.2 glue/tools/test_intent.php
```diff
--- PROJECT/tools/test_intent.php	2026-09-23 22:28:45.234675600 -0400
+++ STAGED/tools/test_intent.php	2026-09-24 04:08:05.234834700 -0400
@@ -590,6 +590,61 @@
     str_contains($d, '<follower_commands>') && !str_contains($d, 'Follow_'), $d);
 unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['ENABLED_FUNCTIONS']);
 
+// ------------------------------------------------------------------ K. [pt19] an invitation is a request, not a negation
+say();
+echo "K. [0.5.8 / pt19] \"why don't you X\" asks, \"don't X\" refuses (2026-09-23 00:17:38: logged intent=none why=negated)\n";
+$invite = [
+    // [utterance, kind, conf, who, note]
+    ["well we're in a private room already why don't you get naked?", 'undress', 'high', 'npc', 'THE OWNER SENTENCE, verbatim'],
+    ["we're in a private room already, why don't you get naked?",     'undress', 'high', 'npc', 'the aside as its own clause'],
+    ["why don't you get naked",                    'undress', 'high', 'npc',    ''],
+    ["why don't you strip",                        'undress', 'high', 'npc',    ''],
+    ["why don't you strip naked for me",           'undress', 'high', 'npc',    ''],
+    ["why not get undressed?",                     'undress', 'high', 'npc',    '"why not" used to be blocked as a question'],
+    ["won't you take your clothes off?",           'undress', 'high', 'npc',    '"won\'t you" used to be blocked as a question'],
+    ["how about you take it all off",              'undress', 'high', 'npc',    ''],
+    ["would you get naked for me?",                'undress', 'high', 'npc',    ''],
+    ["why don't you lose the clothes",             'undress', 'high', 'npc',    '"lose the clothes" joins the family'],
+    ["why don't we both get naked",                'undress', 'high', 'both',   ''],
+    ["why don't you undress me",                   'undress', 'high', 'player', 'the who-inversion holds through the frame'],
+    ["so, why don't you strip for me?",            'undress', 'high', 'npc',    'a lead-in'],
+    ["why don t you get naked",                    'undress', 'high', 'npc',    'speech-to-text dropped the apostrophe'],
+    ["hey, why don't you get naked and lie down on the bed", 'undress', 'high', 'npc', 'a compound: the first request is the primary'],
+    ["we have the room to ourselves, why don't you strip",   'undress', 'high', 'npc',  'the aside\'s "we" is not "both"'],
+    ["we're alone now, take your clothes off",               'undress', 'high', 'npc',  'no invitation frame, the aside\'s "we\'re" is still not "both"'],
+    ["why don't we get naked",                               'undress', 'high', 'both', '"we" in the request itself IS both'],
+    ["let's both get undressed",                             'undress', 'high', 'both', 'unchanged'],
+];
+foreach ($invite as [$text, $kind, $conf, $who, $note]) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" -> %s/%s who=%s%s', $text, $kind, $conf, $who, $note !== '' ? "   ($note)" : ''),
+        $r['kind'] === $kind && $r['conf'] === $conf && (string) ($r['kv']['who'] ?? '') === $who,
+        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
+}
+foreach (["don't get naked", "do not strip", "never take your clothes off", "i don't want you to get naked", "not naked"] as $text) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" is still a negation', $text), $r['kind'] === 'none' && $r['why'] === 'negated', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
+}
+check('"don\'t stop, harder" still keeps the "harder"', lrgRecogniseIntent("don't stop, harder", null, [])['kind'] === 'faster');
+check('"why don\'t you stop" is a stop', lrgRecogniseIntent("why don't you stop", null, [])['kind'] === 'stop', json_encode(lrgRecogniseIntent("why don't you stop", null, [])));
+check('"why don\'t we stop" is a stop', lrgRecogniseIntent("why don't we stop", null, [])['kind'] === 'stop');
+check('"why don\'t you stop teasing me and fuck me" is the act', lrgRecogniseIntent("why don't you stop teasing me and fuck me", null, [])['kind'] === 'act');
+check('"why don\'t you tell me about yourself" is nothing', lrgRecogniseIntent("why don't you tell me about yourself", null, [])['kind'] === 'none');
+check('"why not?" on its own is nothing', lrgRecogniseIntent('why not?', null, [])['kind'] === 'none');
+check('"would you ever get naked" stays hypothetical', lrgRecogniseIntent('would you ever get naked', null, [])['why'] === 'hypothetical');
+check('"not now" with no proposal pending is a negation, not a guess', lrgRecogniseIntent('not now', null, [])['kind'] === 'none');
+// the undress family, her and him
+foreach (['strip' => 'npc', 'strip naked' => 'npc', 'get naked' => 'npc', 'undress' => 'npc', 'get undressed' => 'npc',
+          'take it all off' => 'npc', 'lose the clothes' => 'npc', 'bare yourself' => 'npc', 'take your clothes off' => 'npc',
+          'get out of those clothes' => 'npc', 'remove your armour' => 'npc', 'drop your pants' => 'npc',
+          'strip me' => 'player', 'strip me naked' => 'player', 'get me naked' => 'player', 'undress me' => 'player',
+          'take it all off me' => 'player', 'lose my clothes' => 'player', 'bare me' => 'player', 'take my clothes off' => 'player'] as $text => $who) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" -> undress/high who=%s', $text, $who), $r['kind'] === 'undress' && $r['conf'] === 'high' && (string) ($r['kv']['who'] ?? '') === $who,
+        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
+}
+check('"drop to your knees" is not an undress (no garment)', lrgRecogniseIntent('drop to your knees', null, [])['kind'] !== 'undress');
+
 say();
 printf("%d passed, %d failed\n", $pass, $fails);
 if ($fails === 0) { echo "OK\n"; }
```

## 8. IMPLEMENTER (2026-09-24 04:30-04:45 -04:00): what was applied, and how it differs from section 3

Applied to the project (backups first, in `glue/.backup/pt19-recogniser/*.bak`): `glue/server/lorerim_glue/lib/lrg_intent.php`
(6 hunks), `glue/tools/test_intent.php` (3 hunks: section I +3 money rows, section J +4 escort rows, section K new: 106 checks),
`glue/tools/flows/scenarios/19_intent_directive_net.php` (+4 end-to-end checks through a real private-mode turn). Nothing else:
no Papyrus (CurrentVersion 510 stays), no config.json, no MCM, no wire, no PROTOCOL edit (its text is in 8.7 for the orchestrator).
The staged copy the suites ran on is `C:\Users\Jordan\AppData\Local\Temp\lrg_test\recogniser\glue_impl\` (project + the live
`ext/lorerim_glue/data`), byte-identical to the project for the three files (cmp ok). The refuter's untouched copy
`...\lrg_test\refuter19b\glue_orig\` is the "base" of every before/after below (`probe_impl.php`, `probe_base.txt`, `probe_impl.txt`).

### 8.1 The refuters' must_change list, item by item

| # | asked for | done | how |
|---|---|---|---|
| 1 | INVITE needs an ACTION after the frame (stative lookahead); `why won't you X` stays a question | yes | `LRG_INTENT_STATIVE` = `not\|want\|wanna\|like\|love\|need\|ever\|have\|think\|know\|care\|feel\|seem\|mind\|trust\|believe\|understand\|see\|admit\|just admit\|say\|tell me`; `(?! STATIVE\b)` after `why (don't\|don t\|not) (you\|we\|ya)` and after both `won't you` forms; `(?<!\bwhy )` before both `won't you` forms |
| 2 | drop the clause-initial `^not\b` blocker (it discarded STT lines with no comma) | yes | not in the code; K asserts "not so fast kiss me first" -> act/kiss, "not so rough slow down" -> slower, "not like that from behind" -> act/vaginal, "not now follow me" -> escort/follow, "not naked" -> never high |
| 3 | `bare` not a bare frame-1 verb ("bare with me" went low -> HIGH) | yes, narrow form | `bare(?= (?:yourself\|me\|all\|it all)\b)`; "bare with me" stays undress/low (as before), "bare yourself" / "bare me" / "bare it all" are high |
| 4 | dress-branch determiner guard ("lose the dress" went dress/low -> dress/HIGH) | yes | `(?<!\byour \|\bmy \|\bthe \|\bthat \|\bthis \|\bher \|\ba )\bdress\b(?!\s+me\b)`; K asserts lose / take off / drop / remove your dress -> undress, "put your dress back on" and "get dressed" -> dress/high |
| 5 | who-cut only when letters FOLLOW the frame (tag question) + STT `we re / we ve / we ll` not "both" | yes | `$after = substr(...)`; cut only if `/[a-z]/` matches; `\bwe\b(?!\'(?:re\|ve\|ll\|d)\b\| (?:re\|ve\|ll\|are\|were\|have\|got)\b)`; K: "take my clothes off, why don't you" -> player, "strip for me, why don't you" -> npc, "we re alone now take your clothes off" -> npc |
| 6 | no `gear` in the new garment alternative ("drop your gear" is a loot line) | yes | removed; K asserts "drop your gear" is not an undress |
| 7 | double negative "why don't you not get naked" (was undress/high in the prototype) | yes, differently | `not` is the FIRST entry of LRG_INTENT_STATIVE, so the frame never un-invites before "not" and the plain `don't` test blocks it. Refuter 1's `^(?:please\s+)?not\b` was not used because it re-introduces a clause-initial `not` blocker (refuter 2's item 2) |
| 8 | money pre-scan: document + test (`lrgIntentMoney` calls `lrgIntentNegated`) | yes | caller named in the lrgIntentNegated docblock; section I rows: "why don't you take fifty septims" -> offer 50 implied (firm=false, she asks him to confirm), "why don't you take my fifty septims and come upstairs" -> offer 50 FIRM, "why don't you want my fifty septims" -> none/negated. Kept natural (not forced non-firm): `firm` only caps `pay=` in `lrgPayForStart()` (lrg_actions.php:2564) on a StartIntimacy the gate already allowed, and the un-framed twin "take my fifty septims and come upstairs" is firm today - the frame adds no weight. The refuter's own example "why don't you take my fifty septims and leave me alone" is firm too, exactly like its un-framed form (the pre-scan does not read "leave me alone"; pre-existing, not this lane) |
| 9 | escort: document + test `why not come with me` -> follow, `why not wait here` -> wait; `won't you come with me` stays nothing | yes | section J rows (+ "how about you come with me" -> follow). `lrgEscortClause()` itself is NOT changed (its inline rewrite at old line 638 and its `won't` blocker at old 674 stand): "won't you come with me" -> none/no pattern matched under the escort ctx |
| 10 | `why don't you stop` = stop: owner decision | kept | see 8.3 |
| 11 | bookkeeping corrections | yes | 8.5 |
| 12 | PROTOCOL 7.2 sentence without the "opens with not" clause | text only | 8.7 (PROTOCOL.md is not this lane's file) |
| 13 | re-run the three suites on a staged copy with the live data dir | yes | 8.4 |

### 8.2 Behaviour, base -> implemented (every row from `probe_impl.php`; rows not listed are unchanged)

| utterance | base (unmodified project) | implemented |
|---|---|---|
| "well we're in a private room already why don't you get naked?" (THE OWNER SENTENCE) | none/negated | **undress/high who=npc** |
| "we're in a private room already, why don't you get naked?" | none/negated | undress/high npc |
| why don't you get naked / just get naked / get naked already / strip / strip naked for me / lose the clothes / undress me / why don't ya get naked | none/negated | undress/high (npc; player for "undress me") |
| why dont you get naked (no apostrophe) | none/negated | undress/high npc |
| why don t you get naked (STT) / why not get undressed? / won't you take your clothes off? | none/question | undress/high npc |
| won t you strip (STT) | undress/LOW | undress/high npc |
| why don't we both get naked / why don't we get naked | none/negated | undress/high BOTH |
| so, why don't you strip for me? / hey, why don't you get naked and lie down on the bed / we have the room to ourselves, why don't you strip | none/negated | undress/high npc |
| we're alone now, take your clothes off / we re alone now take your clothes off / we are alone now take your clothes off | undress/high **both** (the player undressed too) | undress/high npc |
| take my clothes off, why don't you (tag question) | none/negated | undress/high **player** |
| why don't you get dressed | none/negated | dress/high npc |
| why don't you stop / why don't we stop / why don't you stop that and get on the bed | none/negated | stop/high (8.3) |
| why don't you stop teasing me and fuck me | none/negated | act/high vaginal (the gerund guard) |
| why don't you sit down / why not? | none/negated, none/question | none/no pattern matched |
| why don't you tell me about yourself | none/negated | none/negated ("tell me" is stative: unchanged) |
| why don't you not get naked / want to get naked? / want to fuck me? / ever get naked / have any clothes on? / want me to take your clothes off / just admit you want to fuck me | none/negated | none/negated (UNCHANGED - the prototype had made these undress / act / DRESS high) |
| why won't you get naked? / why won't you fuck me / why won t you strip | none/question | none/question (unchanged) |
| don't get naked / do not strip / never take your clothes off / i don't want you to get naked / don't get naked yet / don't take your clothes off / don't slow down | none/negated | none/negated |
| don't stop, harder | faster/high | faster/high |
| not so fast kiss me first / not so rough slow down / not like that from behind / not now, kiss me / not yet, slower | act kiss / slower / act vaginal-doggy / act kiss / slower | unchanged |
| not naked / not now / bare with me / drop to your knees / drop your gear / lose them / dress me | undress-low / none / undress-low / none / none / none / none | unchanged |
| lose the clothes / ditch the armor / remove your armour / drop your pants / remove your hood / shed those robes | none/no pattern | undress/high npc (part body / body / all / head / body) |
| bare yourself / bare it all | undress/low | undress/high npc |
| lose the dress | dress/low | undress/high npc |
| take off your dress / drop your dress / remove your dress | **dress/high** (an order to get DRESSED) | undress/high npc |
| take off my dress | **dress/high who=npc** (HER told to get dressed) | undress/high **player** |
| take my gloves off | undress/high who=**npc** | undress/high player (part hands) |
| lose my clothes / remove my armour | none/no pattern | undress/high player |
| bare me | undress/low who=npc | undress/high player |
| wear the dress | dress/low | none/no pattern matched (the only row that lost a reading; "wear" was never a request frame, so nothing acted on it) |
| why don't you take fifty septims | none/negated | offer/high 50 from=implied firm=no |
| why don't you take my fifty septims and come upstairs | none/negated | offer/high 50 firm=yes |
| why don't you want my fifty septims | none/negated | none/negated |
| (escort ctx) why not come with me / why not wait here | none/question | escort/follow, escort/wait |
| (escort ctx) won't you come with me | none | none (unchanged) |
| (escort ctx) why don't you come with me / how about you come with me / not now follow me / why don't you wait here | escort | escort (unchanged) |
| (escort ctx) why don't you ever come with me | escort/follow | escort/follow (unchanged, PRE-EXISTING: lrgEscortClause's own inline rewrite has no stative guard - 8.8) |

### 8.3 Decisions taken (each reversible in one line)

- **"why don't you stop" ends the scene (stop/high).** Both refuters flagged it as the owner's call. Kept: a soft stop that is NOT
  honoured is the consent rail failing, while a scene ended one turn early is restarted with a word; it was `none/negated` before,
  which is neither a stop nor an answer. "why don't you stop that and get on the bed" -> stop, exactly as today's "stop that and get
  on the bed" -> stop (same class, not new). To revert: drop `lrgIntentUninvite(` from the first line of `lrgIntentStop()` and the two
  "is a stop" rows in K (those phrases then fall to `none/no pattern matched`).
- **"why won't you X" stays a question** (refuter 2): the owner's natural complaint when she refuses must not become a DO-IT order
  inside a scene. NEVER SILENT holds - the model answers a question in words.
- **Money: the natural result, not a forced non-firm** (8.1 item 8).
- **`lrgEscortClause()` untouched** (investigator open question 1 / refuter 1 item 4): it keeps its own `why don't you|we` -> please
  rewrite and its `won't` blocker; the shared helper is not wired in there (escort lane's call). Consequence, documented in J: "why
  not come with me" -> follow through the new polite test; "won't you come with me" -> nothing.
- **One adjacent fix in the same function, same rail:** `lrgIntentClothingWho()`'s "my <garment>" list now names every garment the
  branches know (dress, gloves, gauntlets, helmet, helm, hood, hat, shoes, clothing, garments). "take my gloves off" resolved
  who=npc (the wrong person undressed - the playtest-6 class this file's header names as rail 1) and "take off my dress" was an
  order for HER to get dressed. Both are in K.

### 8.4 Verification (WSL DwemerAI4Skyrim3, staged copy `glue_impl` + the live `data/`)

| suite | base (project before) | implemented |
|---|---|---|
| `php -l` lrg_intent.php, test_intent.php, scenario 19 | ok | ok |
| `tools/test_intent.php --quiet` | 316 passed, 0 failed | **429 passed, 0 failed** (+106 K, +3 I, +4 J) |
| `tools/test_phrases.php --quiet` | 47/0, verdicts ok=720 | **47/0, verdicts {"ok":720,"POS":13,"ACT":21,"KIND":20,"FIRED":6}, hit rate 92.3% >= 82%** (unchanged) |
| `tools/flows/run_flows.php --only=19` | 26 ok | **30 ok, 0 fail, 0 pending, 0 warn** |
| `tools/flows/run_flows.php --strict` | 82/82, 1467 checks, 0 warnings | **82 scenarios: 82 passed, 0 FAILED, 0 pending; 1471 checks, 0 warnings - RESULT: OK** |

The library-only run (before the tests were extended) already gave 316/0, 47/0 and 82/82 - no existing row moved.

### 8.5 Bookkeeping corrections to sections 2-5 (refuter 1, item 6)

- The prototype's `lrg_intent.diff` had 5 hunks, not 6 (the "six" in section 3 counted (a)-(g) minus the shared const). The
  applied diff (8.6) has 6 hunks because the who hunk grew.
- Line numbers of the UNMODIFIED file: `lrgIntentScanOrdered()`'s negation test is line 1032 (section 2 says 1035); the undress
  regex is line 1111 (not 1102); the dress-branch quirk is line 1103 (not 1094); `LRG_DLG_NEG_WORDS` is lrg_dialogue.php:3381 and
  `lrgDlgNegatedAt()` 3384-3404.
- Section 4's "section J unchanged" was true of the test file only: the behaviour changed (8.2, escort rows) and J now says so.
- Section 5's dress bullet is corrected in place (above).

### 8.6 The applied diffs (project vs `.backup/pt19-recogniser/*.bak`; also at `Temp\lrg_test\recogniser\impl_*.diff`)

#### 8.6.1 glue/server/lorerim_glue/lib/lrg_intent.php
```diff
--- /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/.backup/pt19-recogniser/lrg_intent.php.bak	2026-09-24 04:30:34.563831900 -0400
+++ /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/server/lorerim_glue/lib/lrg_intent.php	2026-09-24 04:33:24.454456600 -0400
@@ -76,8 +76,11 @@
  * suck my deck" came out conf=LOW - which switched off the DO-IT directive, the safety net AND the
  * request ceiling in one go, and the request died in silence (F1). The list is now the verbs people
  * really use, and lrgIntentFrame() tests it on every clause, not only on the whole utterance.
+ * [0.5.8 / pt19] + the shedding verbs "lose / ditch / shed / remove" ("lose the clothes", "remove your
+ * armour") and "bare" ONLY as "bare yourself / me / all / it all" - "bare with me" (the bear/bare slip)
+ * must never become a high-confidence undress.
  */
-const LRG_INTENT_FRAME1 = '/^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep|do|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|snuggle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|slide|shove|climb|straddle|lean|drop|roll|flip|swallow|deepthroat|tease|finish|make|gimme|fondle|spank|massage|suck|smack)\b/';
+const LRG_INTENT_FRAME1 = '/^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep|do|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|snuggle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|slide|shove|climb|straddle|lean|drop|roll|flip|swallow|deepthroat|tease|finish|make|gimme|fondle|spank|massage|suck|smack|lose|ditch|shed|remove|bare(?= (?:yourself|me|all|it all)\b))\b/';
 
 // ---------------------------------------------------------------- cleaning
 /** The player's utterance, ready to match on: no DLL context prefix, no "Name: " prefix, lowercase, collapsed. */
@@ -116,16 +119,51 @@
  * True when one of the polite-request frames matched: "can you ..." is a request, not a question.
  * [0.3.1 / D11] "can i / may i / could i" is the same thing from the other side ("can i lick you") and
  * was being thrown away by the question blocker.
+ * [0.5.8 / pt19] the invitation frames (LRG_INTENT_INVITE) are polite frames too, so "why not get
+ * undressed?", "won't you take your clothes off?" and the speech-to-text "why don t you get naked" are
+ * no longer thrown away as questions either.
  */
 function lrgIntentPolite(string $t): bool
 {
-    return (bool) preg_match('/\b(can|could|will|would) you\b|\b(can|could|may) i\b|\bwhy don\'?t (you|we)\b|\bhow about (you|we)\b|\bmind if\b|\bwould you like to\b/', $t);
+    return (bool) preg_match('/\b(can|could|will|would) you\b|\b(can|could|may) i\b|\bmind if\b|\bwould you like to\b/', $t)
+        || (bool) preg_match(LRG_INTENT_INVITE, $t);
 }
 
-/** The negation test on its own, so it can also be applied to the FRAGMENT the scan matched. */
+/**
+ * [0.5.8 / pt19] The INVITATION frames: "why don't you X", "why not X", "won't you X", "how about you X"
+ * ASK for X - the don't / not / won't they carry is not a refusal. "well we're in a private room already
+ * why don't you get naked?" (2026-09-23 00:17:38) was logged intent=none why=negated because
+ * lrgIntentNegated() saw the don't. lrgIntentUninvite() rewrites the frames to "please" before the
+ * negation test (the escort clause has done the same for its own text since 0.5.4).
+ * A frame counts ONLY when an action follows it: "why don't you WANT / LIKE / EVER / HAVE ... X" is a
+ * question about her, "why don't you NOT X" is a refusal (a double negative) and "why WON'T you X" is a
+ * complaint - they keep their don't / won't and stay blocked. "just" is an intensifier, not a stative
+ * ("why don't you just get naked" asks). Every other negation is untouched: "don't X", "do not X",
+ * "never X", "i don't want you to X". A clause that merely OPENS with "not" ("not so fast kiss me first")
+ * is deliberately NOT a negation: speech-to-text gives no commas, and the request after it must survive.
+ */
+const LRG_INTENT_STATIVE = '(?:not|want|wanna|like|love|need|ever|have|think|know|care|feel|seem|mind|trust|believe|understand|see|admit|just admit|say|tell me)';
+const LRG_INTENT_INVITE = '/\bwhy (?:don\'?t|don t|not) (?:you|we|ya)\b(?! ' . LRG_INTENT_STATIVE . '\b)'
+    . '|\bwhy not\b'
+    . '|(?<!\bwhy )\bwon\'?t you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
+    . '|(?<!\bwhy )\bwon t you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
+    . '|\bhow about (?:you|we)\b/';
+
+/** The cleaned utterance with its invitation frames read as the "please" they are. */
+function lrgIntentUninvite(string $t): string
+{
+    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(LRG_INTENT_INVITE, 'please', $t)));
+}
+
+/**
+ * The negation test on its own, so it can also be applied to the FRAGMENT the scan matched.
+ * [pt19] judged on the un-invited text, so every caller reads an invitation as the request it is: the
+ * whole utterance (lrgIntentBlocked), the clause rescue, lrgIntentScanOrdered, lrgIntentExtra, the
+ * money pre-scan (lrgIntentMoney) and the escort clause (lrgEscortClause).
+ */
 function lrgIntentNegated(string $t): bool
 {
-    return (bool) preg_match('/\b(don\'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b/', $t);
+    return (bool) preg_match('/\b(don\'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b/', lrgIntentUninvite($t));
 }
 
 /** Why this utterance is not a request at all, '' = it may be one. */
@@ -209,7 +247,7 @@
  */
 function lrgIntentStop(string $t): bool
 {
-    $t = trim($t, " \t?.!");
+    $t = lrgIntentUninvite(trim($t, " \t?.!"));   // [pt19] "why don't you stop" is "please stop" (the gerund guard below still holds)
     if (preg_match('/\b(can\'?t get|cannot get|never|not)\s+enough\b/', $t)) { return false; }
     if (preg_match('/^(?:please\s+)?(stop it|stop this|stop|halt|quit)\b(?!\s+\w+ing\b)/', $t)) { return true; }
     if (preg_match('/\b(we|let\'?s|i)\s+(should|need to|want to|have to)\s+stop\b/', $t)) { return true; }
@@ -565,9 +603,20 @@
  */
 function lrgIntentClothingWho(string $t): string
 {
-    if (preg_match('/\b(both|us|we|each other|together|our)\b/', $t)) { return 'both'; }
-    if (preg_match('/\bmy (clothes|armou?r|shirt|boots|things|gear|tunic|robes?|pants|trousers)\b/', $t)
-        || preg_match('/\b(undress|strip|get|take) me\b/', $t)
+    // [pt19] an aside BEFORE the request says nothing about whose clothes: "well we're in a private room
+    // already why don't you get naked?" resolved to BOTH on the aside's "we're". The who is judged from
+    // the last invitation frame on - unless that frame is a trailing tag ("take my clothes off, why don't
+    // you"), when the words before it ARE the request. And a "we're / we are / we have / we got" (or the
+    // speech-to-text "we re") that says where they are is not a "we" that asks for both.
+    if (preg_match_all(LRG_INTENT_INVITE, $t, $m, PREG_OFFSET_CAPTURE) && $m[0]) {
+        $last = end($m[0]);
+        $after = substr($t, (int) $last[1] + strlen((string) $last[0]));
+        if (preg_match('/[a-z]/', $after)) { $t = substr($t, (int) $last[1]); }
+    }
+    if (preg_match('/\b(both|us|each other|together|our)\b|\bwe\b(?!\'(?:re|ve|ll|d)\b| (?:re|ve|ll|are|were|have|got)\b)/', $t)) { return 'both'; }
+    // [pt19] every garment the branches know ("take off my dress" undressed HER - the playtest-6 class)
+    if (preg_match('/\bmy (clothes|clothing|garments?|armou?r|shirt|dress|boots|shoes|things|gear|tunic|robes?|pants|trousers|gloves|gauntlets|helmet|helm|hood|hat)\b/', $t)
+        || preg_match('/\b(undress|strip|get|take|bare|make) me\b/', $t)
         || preg_match('/\b(off|from) me\b/', $t)
         || preg_match('/\bmine\b/', $t)) { return 'player'; }
     return 'npc';
@@ -1100,7 +1149,8 @@
     $veto = $strongAct !== '' && !$strongCloth;
     if (!$veto
         && (preg_match('/\b(redress|get dressed|dressed again|clothes? (back )?on|cover (up|yourself))\b/', $t)
-            || preg_match('/\bdress\b(?!\s+me\b)/', $t)
+            // [pt19] "dress" after a determiner is the GARMENT ("lose the dress", "take off your dress"), not "get dressed"
+            || preg_match('/(?<!\byour |\bmy |\bthe |\bthat |\bthis |\bher |\ba )\bdress\b(?!\s+me\b)/', $t)
             || preg_match('/\bput\b[^.]{0,24}\b' . $garment . '\b[^.]{0,16}\bon\b|\bput\b[^.]{0,16}\bon\b[^.]{0,24}\b' . $garment . '\b/', $t))) {
         return ['kind' => 'dress', 'kv' => ['do' => 'dress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
     }
@@ -1108,7 +1158,10 @@
     // [0.3.1 fix pass] `undress` was anchored on both sides, so "get undressed" - the plainest way to
     // say it - matched nothing at all and the whole sentence went silent.
     if (!$veto
-        && preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b.*\boff\b|out of (those|your|that))\b/', $t)) {
+        && (preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b.*\boff\b|out of (those|your|that))\b/', $t)
+            // [pt19] "lose the clothes", "remove your armour", "drop your pants": a shedding verb and a garment of its
+            // own. No `gear` ("drop your gear" is a loot line) and no `them` / `it`, so "drop to your knees" stays what it was.
+            || preg_match('/\b(?:lose|ditch|shed|remove|drop)\b[^.]{0,20}\b(?:clothes|clothing|garments?|armou?r|dress|shirt|tunic|robes?|pants|trousers|boots|shoes|gloves|gauntlets|helmet|helm|hood|hat)\b/', $t))) {
         return ['kind' => 'undress', 'kv' => ['do' => 'undress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
     }
     // acts last: the recogniser NEVER filters by tier here (the ceiling is applied when the kv is
```

#### 8.6.2 glue/tools/test_intent.php
```diff
--- /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/.backup/pt19-recogniser/test_intent.php.bak	2026-09-24 04:30:34.584349400 -0400
+++ /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/tools/test_intent.php	2026-09-24 04:37:07.909976500 -0400
@@ -302,6 +302,12 @@
     ["I'll pay you well for it",          'offer',    0,    [], 'no figure and no asking verb: in her company "it" is what he wants'],
     ["I'll pay you 200 to suck my cock", 'offer',    200,  ['extra' => 'act'], 'the money hit is PRIMARY, the act goes to extra'],
     ['keep your gold',                   'none',     0,    [], 'the player refusing to pay is not an offer'],
+    // [pt19] an invitation frame is not a negation in the money pre-scan either (lrgIntentNegated() is shared):
+    // the figure carries exactly the weight its un-framed twin carries ("take fifty septims" is implied, "take my
+    // fifty septims" is firm), and a question about her wishes stays blocked
+    ["why don't you take fifty septims",  'offer',    50,   ['firm' => false], '[pt19] a figure, no commitment: she asks him to confirm'],
+    ["why don't you take my fifty septims and come upstairs", 'offer', 50, ['firm' => true], '[pt19] "take my ... septims" is firm, framed or not'],
+    ["why don't you want my fifty septims", 'none',   0,    [], '[pt19] a question about her wishes stays negated'],
 ];
 foreach ($money as [$text, $kind, $gold, $want, $note]) {
     $r = lrgRecogniseIntent($text, null, []);
@@ -481,6 +487,10 @@
     ["let's go upstairs",                   'follow', '', ''],
     ['will you follow me?',                 'follow', '', 'a polite request is a request'],
     ["why don't you come with me",          'follow', '', 'a polite request, not a negation'],
+    ["why not come with me",                'follow', '', '[pt19] the invitation frame is a polite request (was blocked as a question)'],
+    ["why not wait here",                   'wait',   '', '[pt19]'],
+    ["how about you come with me",          'follow', '', ''],
+    ["won't you come with me",              '',       '', "[pt19] documented, not changed: the escort clause's own won't blocker still stands"],
     ['wait here',                           'wait',   '', ''],
     ['stay here',                           'wait',   '', ''],
     ['stay put',                            'wait',   '', ''],
@@ -590,6 +600,120 @@
     str_contains($d, '<follower_commands>') && !str_contains($d, 'Follow_'), $d);
 unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['ENABLED_FUNCTIONS']);
 
+// ------------------------------------------------------------------ K. [pt19] an invitation is a request, not a negation
+say();
+echo "K. [0.5.8 / pt19] \"why don't you X\" asks, \"don't X\" refuses (2026-09-23 00:17:38: logged intent=none why=negated)\n";
+// the helpers on their own
+check('lrgIntentUninvite: an invitation frame reads as "please"', lrgIntentUninvite("well why don't you get naked") === 'well please get naked', lrgIntentUninvite("well why don't you get naked"));
+check('... "why not X" and "won\'t you X" too', lrgIntentUninvite("why not strip? won't you strip?") === 'please strip? please strip?', lrgIntentUninvite("why not strip? won't you strip?"));
+check('... but not before a stative verb, and never "why won\'t you"',
+    lrgIntentUninvite("why don't you want to strip") === "why don't you want to strip" && lrgIntentUninvite("why won't you strip") === "why won't you strip");
+check('lrgIntentNegated: an invitation is not a negation, a refusal and a double negative are',
+    !lrgIntentNegated("why don't you get naked") && lrgIntentNegated("don't get naked") && lrgIntentNegated("why don't you not get naked"));
+check('lrgIntentBlocked: the owner sentence is not blocked at all', lrgIntentBlocked(lrgIntentClean("well we're in a private room already why don't you get naked?")) === '',
+    lrgIntentBlocked(lrgIntentClean("well we're in a private room already why don't you get naked?")));
+$invite = [
+    // [utterance, kind, conf, who, note]
+    ["well we're in a private room already why don't you get naked?", 'undress', 'high', 'npc', 'THE OWNER SENTENCE, verbatim (was none/negated; who=both in the first prototype)'],
+    ["we're in a private room already, why don't you get naked?",     'undress', 'high', 'npc', 'the aside as its own clause'],
+    ["why don't you get naked",                    'undress', 'high', 'npc',    ''],
+    ["why don't you just get naked",               'undress', 'high', 'npc',    '"just" is an intensifier, not a stative verb'],
+    ["why don't you get naked already",            'undress', 'high', 'npc',    ''],
+    ["why don't ya get naked",                     'undress', 'high', 'npc',    ''],
+    ["why don't you strip",                        'undress', 'high', 'npc',    ''],
+    ["why don't you strip naked for me",           'undress', 'high', 'npc',    ''],
+    ["why dont you get naked",                     'undress', 'high', 'npc',    'no apostrophe'],
+    ["why don t you get naked",                    'undress', 'high', 'npc',    'speech-to-text dropped the apostrophe (was blocked as a question)'],
+    ["why not get undressed?",                     'undress', 'high', 'npc',    '"why not" used to be blocked as a question'],
+    ["won't you take your clothes off?",           'undress', 'high', 'npc',    '"won\'t you" used to be blocked as a question'],
+    ["won t you strip",                            'undress', 'high', 'npc',    'speech-to-text (was undress/LOW: no frame)'],
+    ["how about you take it all off",              'undress', 'high', 'npc',    'unchanged'],
+    ["would you get naked for me?",                'undress', 'high', 'npc',    'unchanged'],
+    ["why don't you lose the clothes",             'undress', 'high', 'npc',    '"lose the clothes" joins the family'],
+    ["why don't we both get naked",                'undress', 'high', 'both',   ''],
+    ["why don't we get naked",                     'undress', 'high', 'both',   '"we" in the request itself IS both'],
+    ["why don't you undress me",                   'undress', 'high', 'player', 'the who-inversion holds through the frame'],
+    ["so, why don't you strip for me?",            'undress', 'high', 'npc',    'a lead-in'],
+    ["hey, why don't you get naked and lie down on the bed", 'undress', 'high', 'npc', 'a compound: the first request is the primary'],
+    ["we have the room to ourselves, why don't you strip",   'undress', 'high', 'npc',  'the aside\'s "we" is not "both"'],
+    ["we're alone now, take your clothes off",               'undress', 'high', 'npc',  'no invitation frame: the aside\'s "we\'re" is still not "both" (was both)'],
+    ["we re alone now take your clothes off",                'undress', 'high', 'npc',  'speech-to-text "we re", no comma (was both)'],
+    ["we are alone now take your clothes off",               'undress', 'high', 'npc',  '(was both)'],
+    ["take my clothes off, why don't you",                   'undress', 'high', 'player', 'a TRAILING tag: the words before it are the request'],
+    ["take my clothes off why don't you",                    'undress', 'high', 'player', 'the same without the comma'],
+    ["strip for me, why don't you",                          'undress', 'high', 'npc',  ''],
+    ["let's both get undressed",                             'undress', 'high', 'both', 'unchanged'],
+    ["why don't you get dressed",                            'dress',   'high', 'npc',  'the frame works for the other direction too'],
+];
+foreach ($invite as [$text, $kind, $conf, $who, $note]) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" -> %s/%s who=%s%s', $text, $kind, $conf, $who, $note !== '' ? "   ($note)" : ''),
+        $r['kind'] === $kind && $r['conf'] === $conf && (string) ($r['kv']['who'] ?? '') === $who,
+        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
+}
+// what is STILL blocked: every true negation, a double negative, and a question that only LOOKS like an invitation
+// ("why don't you WANT / EVER / HAVE ... X" asks about her - the first prototype read "why don't you have any
+// clothes on?" as a high-confidence order to put clothes ON)
+foreach (["don't get naked", "do not strip", "never take your clothes off", "i don't want you to get naked", "don't get naked yet",
+          "don't take your clothes off", "why don't you not get naked", "why don't you want to get naked?", "why don't you want to fuck me?",
+          "why don't you ever get naked", "why don't you have any clothes on?", "why don't you want me to take your clothes off",
+          "why don't you just admit you want to fuck me"] as $text) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" is still a negation', $text), $r['kind'] === 'none' && $r['why'] === 'negated', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
+}
+foreach (["why won't you get naked?", "why won't you fuck me", "why won t you strip"] as $text) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" is a complaint: a question, not a request', $text), $r['kind'] === 'none' && $r['why'] === 'question', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
+}
+// the neighbours that must not move
+check('"don\'t stop, harder" still keeps the "harder"', lrgRecogniseIntent("don't stop, harder", null, [])['kind'] === 'faster');
+check('"don\'t slow down" stays blocked', lrgRecogniseIntent("don't slow down", null, [])['why'] === 'negated');
+check('"why don\'t you stop" is a stop (a soft stop ends the scene - owner decision, see pt19-recogniser.md)',
+    lrgRecogniseIntent("why don't you stop", null, [])['kind'] === 'stop', json_encode(lrgRecogniseIntent("why don't you stop", null, [])));
+check('"why don\'t we stop" is a stop', lrgRecogniseIntent("why don't we stop", null, [])['kind'] === 'stop');
+check('"why don\'t you stop teasing me and fuck me" is the act (the gerund guard)', lrgRecogniseIntent("why don't you stop teasing me and fuck me", null, [])['kind'] === 'act');
+check('"why don\'t you tell me about yourself" is nothing', lrgRecogniseIntent("why don't you tell me about yourself", null, [])['kind'] === 'none');
+check('"why don\'t you sit down" is nothing', lrgRecogniseIntent("why don't you sit down", null, [])['kind'] === 'none');
+check('"why not?" on its own is nothing', lrgRecogniseIntent('why not?', null, [])['kind'] === 'none');
+check('"how about we talk" is nothing', lrgRecogniseIntent('how about we talk', null, [])['kind'] === 'none');
+check('"would you ever get naked" stays hypothetical', lrgRecogniseIntent('would you ever get naked', null, [])['why'] === 'hypothetical');
+check('"not now" with no proposal pending is nothing', lrgRecogniseIntent('not now', null, [])['kind'] === 'none');
+// a clause that merely OPENS with "not" is NOT a negation: speech-to-text gives no commas, so there is no clause
+// to rescue, and the request after it must survive (a clause-initial `^not` blocker was tried and dropped for this)
+$r = lrgRecogniseIntent('not so fast kiss me first', null, []);
+check('"not so fast kiss me first" keeps the kiss (no comma: the whole line is scanned)', $r['kind'] === 'act' && str_starts_with((string) $r['act'], 'kiss'), json_encode($r));
+$r = lrgRecogniseIntent('not so rough slow down', null, []);
+check('"not so rough slow down" keeps the pace control', $r['kind'] === 'slower', json_encode($r));
+$r = lrgRecogniseIntent('not like that from behind', null, []);
+check('"not like that from behind" keeps the position', $r['kind'] === 'act' && str_starts_with((string) $r['act'], 'vaginal'), json_encode($r));
+$r = lrgRecogniseIntent('not naked', null, []);
+check('"not naked" is never an order (a bare noun: low at most)', $r['conf'] !== 'high', json_encode($r));
+$r = lrgRecogniseIntent('not now follow me', $ectx, []);
+check('"not now follow me" (no comma) is still the escort', $r['kind'] === LRG_INTENT_ESCORT && ($r['kv']['do'] ?? '') === 'follow', json_encode($r));
+// the undress family, her and him: the shedding verbs ("lose / ditch / shed / remove / drop" + a garment), the
+// garment "dress" (which the dress branch read as "get dressed": "take off my dress" was a confident order for
+// HER to get DRESSED), "bare" in its narrow form, and every garment after "my" naming the player
+foreach (['strip' => 'npc', 'strip naked' => 'npc', 'get naked' => 'npc', 'undress' => 'npc', 'get undressed' => 'npc',
+          'take it all off' => 'npc', 'lose the clothes' => 'npc', 'bare yourself' => 'npc', 'bare it all' => 'npc', 'take your clothes off' => 'npc',
+          'get out of those clothes' => 'npc', 'ditch the armor' => 'npc', 'remove your armour' => 'npc', 'drop your pants' => 'npc',
+          'lose the dress' => 'npc', 'take off your dress' => 'npc', 'drop your dress' => 'npc', 'remove your dress' => 'npc',
+          'remove your hood' => 'npc', 'shed those robes' => 'npc',
+          'strip me' => 'player', 'strip me naked' => 'player', 'get me naked' => 'player', 'undress me' => 'player',
+          'take it all off me' => 'player', 'lose my clothes' => 'player', 'bare me' => 'player', 'take my clothes off' => 'player',
+          'take off my dress' => 'player', 'take my gloves off' => 'player', 'remove my armour' => 'player'] as $text => $who) {
+    $r = lrgRecogniseIntent($text, null, []);
+    check(sprintf('"%s" -> undress/high who=%s', $text, $who), $r['kind'] === 'undress' && $r['conf'] === 'high' && (string) ($r['kv']['who'] ?? '') === $who,
+        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
+}
+check('"remove your armour" names the body slot', (lrgRecogniseIntent('remove your armour', null, [])['kv']['part'] ?? '') === 'body');
+check('"remove your hood" names the head', (lrgRecogniseIntent('remove your hood', null, [])['kv']['part'] ?? '') === 'head');
+check('"put your dress back on" is still a dress', lrgRecogniseIntent('put your dress back on', null, [])['kind'] === 'dress');
+check('"get dressed" is still a dress, high', lrgRecogniseIntent('get dressed', null, [])['kind'] === 'dress' && lrgRecogniseIntent('get dressed', null, [])['conf'] === 'high');
+check('"drop to your knees" is not an undress (no garment)', lrgRecogniseIntent('drop to your knees', null, [])['kind'] !== 'undress');
+check('"drop your gear" is not an undress (a loot line)', lrgRecogniseIntent('drop your gear', null, [])['kind'] !== 'undress');
+check('"lose them" is not an undress', lrgRecogniseIntent('lose them', null, [])['kind'] !== 'undress');
+check('"bare with me" (the bear/bare slip) is never an order', lrgRecogniseIntent('bare with me', null, [])['conf'] !== 'high', json_encode(lrgRecogniseIntent('bare with me', null, [])));
+
 say();
 printf("%d passed, %d failed\n", $pass, $fails);
 if ($fails === 0) { echo "OK\n"; }
```

#### 8.6.3 glue/tools/flows/scenarios/19_intent_directive_net.php
```diff
--- /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/.backup/pt19-recogniser/19_intent_directive_net.php.bak	2026-09-24 04:38:45.806828800 -0400
+++ /c/Users/Jordan/AppData/Roaming/Claude/scratch-workspaces/81ce68fa-07b1-4b77-bf43-737182b06d3c/6e558c34-6aca-475c-b527-d967c1b190e9/scratch-2026-09-21-8ca12a/glue/tools/flows/scenarios/19_intent_directive_net.php	2026-09-24 04:38:55.464452300 -0400
@@ -94,4 +94,19 @@
         fn() => !$out->hasDirective() || str_contains($out->directive(), 'own choice') || str_contains($out->directive(), 'ChangeClothing'), fn() => $out->directive());
     $t->mustCap('decorate', '... and the net never acts outside a running scene', fn() => fxNothingSent(fxLlmNothing($npc)));
     $t->must('... the prompt does not both forbid and order the same thing', !(str_contains($out->guidance(), 'Do it now') && str_contains($out->guidance(), 'Nothing the player says can decide this')));
+
+    // ---- [pt19] the owner's own sentence (2026-09-23 00:17:38, logged intent=none why=negated): an INVITATION frame
+    // ("why don't you X", "why not X", "won't you X") is a request, not a refusal - and the aside's "we're" is not
+    // "both". Same room, same private mode as above: recognised, named, and still her choice.
+    $out = fxSay($npc, $alone, "well we're in a private room already why don't you get naked?");
+    $t->mustCap('intent', '[pt19] "why don\'t you get naked" is recognised: undress, high, HER clothes (was none/negated)',
+        fn() => $out->intentIs('undress', 'high') && ($out->intent()['kv']['who'] ?? '') === 'npc', fn() => json_encode($out->intent()));
+    $t->mustCap('intent', '... the directive names ChangeClothing and leaves it her choice, never an order',
+        fn() => !str_contains($out->directive(), 'Do it now') && !str_contains($out->directive(), 'Do not refuse')
+            && (!$out->hasDirective() || str_contains($out->directive(), 'own choice') || str_contains($out->directive(), 'ChangeClothing')), fn() => $out->directive());
+    $out = fxSay($npc, $alone, "why don't you want to get naked?");
+    $t->mustCap('intent', '[pt19] "why don\'t you WANT to get naked?" is a question about her, not a request: no directive',
+        fn() => !$out->hasDirective() && ($out->intent()['kind'] ?? '') === 'none', fn() => json_encode($out->intent()));
+    $out = fxSay($npc, $alone, "don't get naked");
+    $t->mustCap('intent', '[pt19] "don\'t get naked" is still a refusal: no directive', fn() => !$out->hasDirective(), fn() => json_encode($out->intent()));
 });
```

### 8.7 Doc text for the orchestrator (PROTOCOL.md / README are not this lane's files - NOT applied)

1. `glue/PROTOCOL.md` 7.2, append to the "Blockers ..." paragraph (line 568 today, right after "A leading `tell me,` / `so,` /
   `hey,` is stripped before the question test."):
   > **[0.5.8 / pt19]** An INVITATION frame (`why don't you X`, `why not X`, `won't you X`, `how about you X`) is a polite request
   > and never a negation: `lrgIntentUninvite()` rewrites it to `please` before the negation test, in every caller (the whole
   > utterance, the clause rescue, the ordered scan, the extras, the money pre-scan, the escort clause) - but only when an action
   > follows the frame. `why don't you WANT / LIKE / EVER / HAVE X` and `why WON'T you X` stay questions - however the complaint is
   > intensified (`why the hell won't you X`, `why on earth won't you X`): a `won't you` is a frame only when no `why` stands earlier
   > in its clause. `why don't you NOT X` and `why not NOT X` stay negations. A clause that merely opens with `not` is NOT a negation (speech-to-text gives no commas: "not so fast kiss me first"
   > keeps the kiss). The stop rail reads the same frame: "why don't you stop" is a stop. Whose clothes (`lrgIntentClothingWho()`) is
   > judged from the last invitation frame on (a trailing tag, "take my clothes off, why don't you", keeps the words before it), a
   > "we're / we are / we re" aside is not "both", and "my <garment>" is always the player.
2. `glue/PROTOCOL.md` 10.20, the blockers paragraph (lines 1484-1488 today, ending "... "why don't you come with me" is a request."):
   append: `"why not come with me" / "why not wait here" are requests too (the invitation frames of 7.2); "won't you come with me"
   is still nothing (the escort clause's own won't blocker).`
3. `glue/PROTOCOL.md`, wherever the [pt15] firm-vs-implied money rule is stated (OWNER ADDENDA 6 / ruling A): one line - `an
   invitation frame around a figure carries exactly the weight of its un-framed twin: "why don't you take fifty septims" is
   implied (she asks him to confirm), "why don't you take my fifty septims" is firm.`
4. [pass 2] `glue/PROTOCOL.md` 7.2, the clothing readings (where "take ... off" / "get ... off" are named as the undress
   forms): one line - `a "get off" with nothing between the words ("get off me", "get off of me", "get off", "get off the bed")
   is a dismount or a leave, never an undress; "get <something> off" and "get off <garment>" are.`

### 8.8 Open, not this lane

- `lrgEscortClause()` (old line 638) keeps its own `why don't you|we` rewrite WITHOUT the stative guard: "why don't you ever come
  with me" -> follow (pre-existing; base and implemented agree). One-line fix for the escort lane: replace the inline
  `preg_replace(...)` with `lrgIntentUninvite($c)` - which also turns "won't you come with me" into a follow (its `won't` blocker
  then never sees the word). Both rows are in section J with today's behaviour, so the change would be visible.
- `lrgDlgNegatedAt()` (lrg_dialogue.php:3384-3404, `LRG_DLG_NEG_WORDS` at :3381) has no invitation exemption: "why don't you sell
  me a mead" is very likely read as a negated barter phrase there. Drink/food lane. [pass 2, refuter 2] The same split now
  shows on a carriage line: "why don't you take me to morthal" / "take me home" resolve `act/high vaginal` in the recogniser
  (exactly like their un-framed twins - `\btake me\b` is in the STRONG act regex, lrg_scene_index.php:1356, and in the
  config's strong words, lrg_config.default.json:691; before the frame they were none/negated), while `lrgDlgNegatedAt()`
  still reads the framed sentence as negated - the two lanes DISAGREE on "why don't you take me to <place>". K pins the
  equivalence (framed == un-framed) as a KNOWN RESIDUAL; the `take me <place>` guard
  (`\btake me\b(?! (?:home|back|there|along|with you)\b)(?! to (?!(?:the )?bed\b))` or equivalent) belongs to the act / dialogue
  lanes, not this diff.
- `lrgIntentPart()` can still be poisoned by an aside ("hand me the ale, then take your clothes off" -> part=hands).
- "why don't I X" (a self-invitation: "why don't i undress you", "why don't i pay you fifty") is still `negated` - `i` is not in the
  frame's subjects on purpose (smallest fix); worth a row if the owner says it.
- The strip itself at 00:17:38 was ALSO withheld by mode=silent + no fresh snapshot (section 1); this fix changes the recognition,
  the directive and the log line, not that gate.

## 9. IMPLEMENTER pass 2 (2026-09-24 05:45-06:05 -04:00): the refuters' deltas on the applied fix

The owner's usage limit cut pass 1 off after its report; this pass applies the two refuters' `must_change_in_fix` items to the
CURRENT project files (never to the `.bak` originals). Backups of the pass-1 state: `glue/.backup/pt19-recogniser/*.pass1.bak`
(the `*.bak` files stay the pre-pt19 originals). Staged copy: `Temp\lrg_test\recogniser\glue_pass2\` (project + the live
`data/`, cmp ok for all three files); `glue_impl\` is kept as the pass-1 baseline and `..\refuter19b\glue_orig\` as the base.
Probe: `probe_pass2.php`, outputs `probe2_base.txt` / `probe2_pass1.txt` / `probe2_pass2.txt`. Log lines quoted in section 1
were re-read from the live `lorerim_glue.log` (2919-2946, 3174, 3229, 3232).

### 9.1 The must_change items, item by item

| # | who | asked for | done | how |
|---|---|---|---|---|
| 1 | R1 | do not re-apply the section-3 hunks | yes | deltas only, on the pass-1 file |
| 2 | R1 | the intensified complaint ("why the hell won't you strip", "why on earth won't you get naked", "why in the world won't you get naked" -> undress/high; "why the fuck won't you fuck me" -> act/high) must stay a question, with ONE test shared by lrgIntentPolite(), lrgIntentUninvite() and the who-cut | yes | not a separate pre-test function but the same effect inside the ONE constant: the `won't you` alternative of `LRG_INTENT_INVITE` is now `(?:^\|[,;?.!])(?:(?!\bwhy\b)[^,;?.!])*?\K\bwon(?:\'?t\| t) you\b(?! STATIVE\b)` - a clause-anchored `\K`. The distro's PHP 8.2.28 carries PCRE2 10.42, which has no variable-length lookbehind (10.43 added it); `\K` resets the match start to the "won't", so `preg_replace` replaces only the frame and `PREG_OFFSET_CAPTURE` reports the frame's offset. All three readers share the constant, so they cannot disagree. The two `(?<!\bwhy )` lookbehinds are gone (subsumed) and the `won't you` / `won t you` alternatives are merged. K: 6 intensified forms -> none/question; 4 invitations with the `why` in an EARLIER clause or none ("well won't you strip", "we're alone now, won't you take your clothes off", "why? won't you strip", "it's late, won t you get naked") -> undress/high npc |
| 3 | R1 | a `not` guard on the bare `\bwhy not\b` | yes | `\bwhy not\b(?! not\b)`; K: "why not not get naked" -> none/question (pass 1: undress/high) |
| 4 | R1 | optional, pre-existing: "how about you not get naked", "get out of these clothes", "remove your hand" | not touched | 9.4 |
| 5 | R2 | correct the attribution (sections 1, 6, 8.8, owner steps) | yes | section 1 carries the corrected account with the log lines; section 6 is rewritten with the check ORDER (dev dry run off on load, fresh snapshot, "strip naked" first, then "why don't you get naked") |
| 6 | R2 | guard the dismount: "get off me" / "get off of me" / bare "get off" are not an undress | yes, slightly wider | `get\b(?! off\b).*\boff\b`: a "get off" with NOTHING between the words is never the undress reading - which also covers "get off the bed" / "get off my lap" (undress/high on base). Plus `get off (?:(?:your\|those\|these\|that\|the\|my\|all\|them) )?<garment>` so "get off those clothes" keeps its reading. The garment list is hoisted into `$shed` and shared with the shedding-verb alternative. K: 7 dismount rows (not undress), 7 "get <something> off" keepers (undress/high with the right who and part). Scenario 19: "get off me" through a real private-mode turn - not an undress, no ChangeClothing directive |
| 7 | R2 | make the widened carriage rail visible: K rows for "why don't you take me to morthal" / "take me home"; record the lrgDlgNegatedAt disagreement in 8.8 | yes | K pins the EQUIVALENCE framed == un-framed (both act/high vaginal today) under a KNOWN RESIDUAL comment - not the reading; 8.8 has the disagreement and the guard that belongs to the act / dialogue lanes |
| 8 | R1 | re-run the three suites from a staged copy with the live data | yes | 9.3 |

### 9.2 Behaviour, base -> pass 1 -> pass 2 (from `probe2_*.txt`; every other probe row is unchanged from pass 1)

| utterance | base | pass 1 | pass 2 |
|---|---|---|---|
| why the hell won't you strip / why on earth won't you get naked / why in the world won't you get naked / why in gods name won t you strip / why is it that every time i ask won't you strip | none/question | **undress/high npc** | none/question |
| why the fuck won't you fuck me | none/question | **act/high vaginal** | none/question |
| why not not get naked | none/question | **undress/high** | none/question |
| well won't you strip / it's late, won t you get naked | undress/LOW | undress/high npc | undress/high npc |
| we're alone now, won't you take your clothes off | undress/high **both** | undress/high npc | undress/high npc |
| why? won't you strip (the why in an earlier clause) | none/question | undress/high npc | undress/high npc |
| why don't you get naked won't you | none/negated | undress/high npc | undress/high npc |
| get off me / why don't you get off me | undress/high **who=player** / none/negated | undress/high **who=player** | none/no pattern matched |
| get off of me / get off / get off the bed / get off my lap | undress/high npc (the framed twins none/negated) | undress/high npc | none/no pattern matched |
| get it off me / get everything off / get those off / get off those clothes / get your clothes off / get my boots off / get that armour off / get out of those clothes | undress/high | undress/high | undress/high, same who and part |
| why don't you take me to morthal / take me home / take me to bed | none/negated | act/high vaginal | act/high vaginal (= the un-framed twins; KNOWN RESIDUAL, 8.8) |
| get off me and get naked | undress/high who=player | same | same (the "off me" who-poisoning, 9.4) |
| the owner sentence, strip naked, why don't you get naked, don't get naked, why don't you stop, don't stop harder, the escort rows | (8.2) | (8.2) | unchanged from pass 1 |

### 9.3 Verification (WSL DwemerAI4Skyrim3, `glue_pass2` + the live `data/`)

| suite | pass 1 | pass 2 |
|---|---|---|
| `php -l` lrg_intent.php, test_intent.php, scenario 19 | ok | ok |
| `tools/test_intent.php --quiet` | 429 passed, 0 failed | **459 passed, 0 failed** (+30 K checks) |
| `tools/test_phrases.php --quiet` | 47/0, ok=720, 92.3% | **47/0, verdicts {"ok":720,"POS":13,"ACT":21,"KIND":20,"FIRED":6}, hit rate 92.3% >= 82%** (unchanged) |
| `tools/flows/run_flows.php --only=19` | 30 ok | **32 ok, 0 fail, 0 pending, 0 warn** |
| `tools/flows/run_flows.php --strict` | 82/82, 1471 checks, 0 warnings | **82 scenarios: 82 passed, 0 FAILED, 0 pending; 1473 checks, 0 warnings - RESULT: OK** |

### 9.4 Open after pass 2 (adds to 8.8; none is a regression of this lane)

- "how about you not get naked" -> undress/high on base, pass 1 and pass 2: the `how about` alternative has no `not` guard and a
  bare `not` is not a negation (the dropped clause-initial blocker, 8.1 item 2). Rare; a `(?! not\b)` on that alternative alone
  would not change the outcome (nothing then blocks it) - it needs a "you/we not X" negation form. Owner's call.
- "get out of these clothes" -> none (the undress regex knows `out of (those|your|that)` only); `the` / `these` were NOT added
  because that alternative carries no garment word ("get out of the way" would become an undress). Pre-existing.
- "remove your hand" -> act/high handjob on base and now (the `remove` frame verb changes nothing there). Pre-existing.
- "get off me and get naked" -> undress/high who=PLAYER: the aside's "off me" names the player through `(off|from) me` - the
  same class as the `lrgIntentPart()` aside-poisoning in 8.8. Judge the who on the request clause; not this pass.
- Data, not chased: 2026-09-22 17:41:10 "i wan see you naked take your clothes off now" and 17:45:02 "i meant to say get naked"
  were logged `intent=none conf=none why=-` (no blocker reason - a different failure mode from the negation guard); on today's code
  they resolve undress/high npc and undress/low npc (probe), so that was an older build or a different path.
- The live server still runs the pre-fix `lrg_intent.php` (section 6, step 3).

### 9.5 The pass-2 library diff (project vs `.backup/pt19-recogniser/lrg_intent.php.pass1.bak`)

The test and scenario diffs are at `Temp\lrg_test\recogniser\pass2_test_intent.diff` (65 lines: the 4 helper checks after
line 610 and the 30 rows before the summary, as described in 9.1) and `pass2_scenario19.diff` (17 lines: the two `fxSay`
checks at the end of G1), both reproducible from the `*.pass1.bak` files.

```diff
--- .backup/pt19-recogniser/lrg_intent.php.pass1.bak
+++ server/lorerim_glue/lib/lrg_intent.php
@@ -136,17 +136,22 @@
  * lrgIntentNegated() saw the don't. lrgIntentUninvite() rewrites the frames to "please" before the
  * negation test (the escort clause has done the same for its own text since 0.5.4).
  * A frame counts ONLY when an action follows it: "why don't you WANT / LIKE / EVER / HAVE ... X" is a
- * question about her, "why don't you NOT X" is a refusal (a double negative) and "why WON'T you X" is a
- * complaint - they keep their don't / won't and stay blocked. "just" is an intensifier, not a stative
- * ("why don't you just get naked" asks). Every other negation is untouched: "don't X", "do not X",
- * "never X", "i don't want you to X". A clause that merely OPENS with "not" ("not so fast kiss me first")
- * is deliberately NOT a negation: speech-to-text gives no commas, and the request after it must survive.
+ * question about her, "why don't you NOT X" / "why not NOT X" is a refusal (a double negative) and
+ * "why WON'T you X" is a complaint - they keep their don't / won't and stay blocked. The complaint keeps
+ * its reading however it is intensified ("why the hell won't you strip", "why on earth won't you get
+ * naked"): a "won't you" counts as an invitation only when no "why" stands earlier in ITS clause (from
+ * the start of the text or the last , ; ? . !). PCRE2 10.42 has no variable-length lookbehind, so that
+ * is written as a clause-anchored `\K` (the match starts at the "won't"), and it lives in this ONE
+ * constant on purpose: lrgIntentPolite(), lrgIntentUninvite() and lrgIntentClothingWho() all read it,
+ * so they can never disagree on what is a frame. "just" is an intensifier, not a stative ("why don't you
+ * just get naked" asks). Every other negation is untouched: "don't X", "do not X", "never X", "i don't
+ * want you to X". A clause that merely OPENS with "not" ("not so fast kiss me first") is deliberately NOT
+ * a negation: speech-to-text gives no commas, and the request after it must survive.
  */
 const LRG_INTENT_STATIVE = '(?:not|want|wanna|like|love|need|ever|have|think|know|care|feel|seem|mind|trust|believe|understand|see|admit|just admit|say|tell me)';
 const LRG_INTENT_INVITE = '/\bwhy (?:don\'?t|don t|not) (?:you|we|ya)\b(?! ' . LRG_INTENT_STATIVE . '\b)'
-    . '|\bwhy not\b'
-    . '|(?<!\bwhy )\bwon\'?t you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
-    . '|(?<!\bwhy )\bwon t you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
+    . '|\bwhy not\b(?! not\b)'
+    . '|(?:^|[,;?.!])(?:(?!\bwhy\b)[^,;?.!])*?\K\bwon(?:\'?t| t) you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
     . '|\bhow about (?:you|we)\b/';
 
 /** The cleaned utterance with its invitation frames read as the "please" they are. */
@@ -1157,11 +1162,17 @@
     // "take off your clothes" and "take your clothes off" are the same request: the word order varies
     // [0.3.1 fix pass] `undress` was anchored on both sides, so "get undressed" - the plainest way to
     // say it - matched nothing at all and the whole sentence went silent.
+    // [pt19] the garments the shedding verbs and "get off <garment>" accept: no `gear` ("drop your gear" is a
+    // loot line) and no `them` / `it`, so "drop to your knees" and "get off them" stay what they were.
+    $shed = '(?:clothes|clothing|garments?|armou?r|dress|shirt|tunic|robes?|pants|trousers|boots|shoes|gloves|gauntlets|helmet|helm|hood|hat)';
     if (!$veto
-        && (preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b.*\boff\b|out of (those|your|that))\b/', $t)
-            // [pt19] "lose the clothes", "remove your armour", "drop your pants": a shedding verb and a garment of its
-            // own. No `gear` ("drop your gear" is a loot line) and no `them` / `it`, so "drop to your knees" stays what it was.
-            || preg_match('/\b(?:lose|ditch|shed|remove|drop)\b[^.]{0,20}\b(?:clothes|clothing|garments?|armou?r|dress|shirt|tunic|robes?|pants|trousers|boots|shoes|gloves|gauntlets|helmet|helm|hood|hat)\b/', $t))) {
+        // [pt19] "get ... off" is "get <something> off": a "get off" with nothing between the words is a dismount
+        // ("get off me", "get off of me", "get off") or a leave ("get off the bed"), never an undress - the loose
+        // reading had "why don't you get off me" undressing the PLAYER in a private room. "get off those clothes"
+        // keeps its reading through the second alternative (a garment must follow).
+        && (preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b(?! off\b).*\boff\b|get off (?:(?:your|those|these|that|the|my|all|them) )?' . $shed . '|out of (those|your|that))\b/', $t)
+            // [pt19] "lose the clothes", "remove your armour", "drop your pants": a shedding verb and a garment of its own
+            || preg_match('/\b(?:lose|ditch|shed|remove|drop)\b[^.]{0,20}\b' . $shed . '\b/', $t))) {
         return ['kind' => 'undress', 'kv' => ['do' => 'undress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
     }
     // acts last: the recogniser NEVER filters by tier here (the ceiling is applied when the kv is
```
