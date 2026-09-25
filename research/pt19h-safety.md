# pt19h safety fixer: what I changed, what closed, what is still open

2026-09-25, the SAFETY FIXER. The rule I worked to: a question, a negation, a deferral, an echo, a hedge or a near-miss never clicks a commit, a crit, a priced, a scripted or an irreversible line. When a phrasing cannot be resolved safely, she ASKS. It never clicks.

No floor was lowered. Nothing was deployed or installed. No Papyrus was touched: every fix is server-side.

## What I edited, and where

**Backups** (first edit only): `glue/.backup/pt19h-safety/` holds `lrg_dialogue.php.bak` (fe28ae3e, the measured tree), `lrg_prompt_index.php.bak` and `test_dialogue.php.bak`.

### `glue/server/lorerim_glue/lib/lrg_dialogue.php`
Every change is tagged `[pt19h-safety / Gn]`.

**Existing functions I own that I changed**

| function | change |
|---|---|
| `lrgDlgSingleEntryRelease` (S4.5) | The **`$hit` exemption now holds only for the line's own words.** When his sentence contains the whole line, the words around it are judged (`lrgDlgQuoteQualm`): a refusal, deferral or hedge there refuses the line, and a question there asks it back. A paraphrase hit keeps his refusal unless the line itself refuses. It also keeps his question unless the line asks too. A refusal refuses the line, with no key press and no breath, unless the line opens with the same refusal words (`lrgDlgSameLead`). A protected single needs the same question (`lrgDlgQuestionsSame`). A statement against a question line gets no step 2 (`lrgDlgDeclares`). A near-miss swap releases no scripted single (`lrgDlgSubstitutes`). A deferral releases no protected single (`lrgDlgDefers`). A commit single that opens with an assent is not said by its question alone (`lrgDlgSkipsAssent`). |
| `lrgDlgExplicit` (S4.3) | The step-0 tests now run **before every return, the enlistment's included**: what he said around the line, his own refusal (it agrees with a refusal-shaped line unless his is a deferral), a hedge and a deferral. On the faction path, a question counts only as a fitting request. A commit line needs the same question, and a statement is not a question line. A fragment of a multi-clause line needs at least 40% of its strict words. A question may carry no word of its own; frame words and spelling or sound slips are allowed. A near-miss swap never counts. |
| `lrgDlgDecide` (the LEAVE path) | LEAVE takes only `lrgDlgRealBackOut` lines. Otherwise it falls to the leave guard or the engine cancel. |
| `lrgDlgLeaveGuarded` | Counts only real back-out lines, so the guard and her business line agree with LEAVE. |
| `lrgDlgParkOrRelease` (S4.4, the engine's own Yes/No layer) | The key on the YES side releases nothing after his refusal, deferral, hedge or question. The layer returns `still`. |
| `lrgDlgWordsQualm` (the words and intent path's question tests) | Adds, in order: what he said around the line; a hedge or deferral on a protected line; a short goodbye clause; a request that must fit (`lrgDlgRequestFits`); the same question on protected lines; different question words on any line; a statement against any question line; a near-miss on protected lines. |
| `lrgDlgIsBackOut` (spoken) | Adds `let's not`, `better not`, `not today`, `not this time`, `some other time`, `go away`, `leave me alone`, `get lost`. |
| `lrgDlgIsQuestion` | Reads a tag question with its `?` dropped ("…, couldn't I", "…, isn't it"). `it` and `there` count only after a comma. |
| `lrgDlgLeadsQuestion` | Adds `haven't`, `hasn't`, `hadn't`, `ain't`, `whose` and `whom`. `has`/`have` + name + past participle asks ("has Malborn got my gear"). "has burn open the door", the STT's "Esbern", does not. |
| `lrgDlgQuestionKind` | A yes/no question's subject is read past a determiner ("is the Augur dangerous" has the subject *augur*). `whose` counts as *who*. |
| `lrgDlgNegationClash` | Negation parity: two negators cancel. An idiom word is a negation when it is the word being judged ("that makes no sense"). A "no" right after a negated auxiliary is not counted, since it is the STT's "know". A "no" + noun before a negated verb is not counted. *almost*, *nearly*, *hardly*, *barely* and *scarcely* deny their predicate, but not a quantity. |
| `lrgDlgNameWord` | Role, faction, people and place words are no names (*dark*, *courier*, *college*, *innkeeper*, *thalmor*, *vigilant*, *dawnguard*, *embassy*, *gate* and others). `guard` stays, by design. |

**New functions, all `[pt19h-safety]`**
- `LRG_DLG_DEFER_RE`
- `lrgDlgHedges`
- `lrgDlgProtected`
- `lrgDlgQuoteQualm`
- `lrgDlgSkipsAssent`
- `lrgDlgDefers`
- `lrgDlgSameLead`
- `lrgDlgDeclares`
- `lrgDlgQuestionsSame` and `lrgDlgQuestionsSame1`
- `lrgDlgRequestFits`
- `lrgDlgStemIn`
- `lrgDlgSubstitutes`
- `lrgDlgOpposite`
- `lrgDlgRealBackOut`

**Unchanged (checked by diff against the backup):**
- `lrgDlgQuestionsAgree`: I tried a stricter version, it broke v21 and v31 and three test_questline beats, so it is restored. The strict rules now live only in `lrgDlgQuestionsSame`, which protected lines use.
- `lrgDlgWillEmit`: it is `lrgDlgDecide`'s twin, so LEAVE on a line that is not a real back-out now gives `do=leave pos=-1` with no entry, and WillEmit is FALSE, so her line plays. A test pins this.
- `lrgDlgAdvMs`
- `lrgDlgBareYesLine`
- `lrgDlgLayerIsOwnConfirmation`
- `lrgDlgOwnWords`
- `lrgDlgSoundsLike`

### Other files
- **`lib/lrg_prompt_index.php`:** I did not change it. The strict list needed no change. Its hash moved because another lane edited it.
- **`tools/test_dialogue.php`:** I appended the section `pt19h-safety`, which holds **113 checks, all passing**. They are the measurer's concrete examples, as must-not-click rows and must-resolve rows.

## Measured result

I re-ran the measurer's own engine (`pt19h-measure/m_engine.php`, unchanged) and a patched copy of its `analyze.py`. The inputs were the same 39,052 utterances over 2,324 beats and the same index (e756e311). Everything is in `%TEMP%\lrg_test\pt19h-safety\`.

**The tree is shared.** It is my edits plus the other pt19h lanes' edits as they stood at 07:20. Other lanes' numbers move too (grading, reach, quest).

| measure | baseline (fe28ae3e) | now |
|---|---|---|
| must-not-click rows that click on the fast path | 644 | **69** |
| ... on a protected line | 478 | **39** |
| `never_red` rows that still click | 534 | **30** |
| G1: refusal, deferral or hedge clicks a protected line | 1,072 | **15** |
| G1: "not now, &lt;line&gt; later" clicks, per commit beat | 113 / 1,033 | **0 / 976** |
| G1 (LLM path): her key releases the engine's Yes/No layer after a refusal | 33 | **1** (the refusal line itself, see below) |
| G1 (LLM path): her key releases EXPLICIT after a refusal | 11 | **1** (the refusal line itself) |
| G5: a question clicks a statement line | 22 | **1** |
| G8: LEAVE takes a scripted, commit or word-class line | 67 lists | **0** |
| G9 | 88 | **29** (most are legitimate, see below) |
| G10 | 15 | **3** (all judged legitimate) |
| G11: echo questions | 342 | **0** |
| name-word proxy: generic names | 46 | **10** (all `guard`, by design) |
| say lines clicked on the fast path (16,186) | 58.7% | 61.7% (shared tree) |
| canonical `test_questline --extended` | not run | 2,800 passed, 9 failed (12 before my last round; list below) |

## Gaps: closed

- **G1: closed for every path I own.** Examples:
  - "not now, Can I enter the Hall of Valor later" is refused.
  - "not now, no, Onmund. … instead of me later" is not explicit.
  - "never mind" against Ancano's "I don't understand what's going on." is refused, with no breath.
  - "I'm sure I need to think about it", "never mind" and "no" leave Serana's "I'm sure." layer still.
  - "no, i'm ready to take the Oath" is closed by pt19h-quest (`lrgFacAskActs` calls my `lrgDlgRefuses` and `lrgDlgHedges`).
- **G5: closed.** Examples:
  - "have you brought the fragment?", "do you trust her to do the right thing?", "what should I keep in mind" and "what do I do with the Elder Scroll?" are refused by shape.
  - "can anyone join the Dawnguard?" is no explicit join.
  - "will you be right back", "can you help me cure myself?" and "can I kill the escaped prisoner?" fail `lrgDlgRequestFits`.
- **G8: closed.** All 126 distinct lines LEAVE now takes are unscripted, not commit and crit 0 ("Never mind.", "Not yet.", "Forget it.", "No, nothing. I'll just be moving on.").
  - "Nothing I couldn't handle.", "I have nothing to hide…", "Ulfric holds nothing worth trading Markarth for.", "We're not sure, but…" and "I'll do nothing of the sort." now get the engine cancel.
  - "Forget it. I'll just open it myself." gets the leave guard, `do=show`.
  - The measurer's metric still shows 41, because its `protected()` counts goodbye=1. Those 41 are the engine's own goodbye rows (scripted 0), which is what LEAVE is for.
- **G9: the evidence is closed.**
  - COVERAGE evidence closed: "tell me where it is" (0799DC), "yes she wants you" (06A79B), "I made a mistake" (05A6B1), "I understand" on "Understand? How?", "I have a plan" on "What's the plan?".
  - Also closed: "who are the Blood Horkers", "who's behind the door", "where do I sign up for the Companions?", "I want to fight as champion of the Stormcloaks", "I almost ran out of scrolls", "close the door", "Mirabelle is fine", "who wrote the first verse", "what's the passphrase?" on "Agreed. What's the passphrase?".
  - Still clicks, by design: "where do I sign up to kill vampires" (the measurer asks for it to be promoted to a say line).
- **G10: closed.** "I don't want to refuse your gift", "that makes no sense", "haven't you had enough of this", "I could just kill you now, couldn't I", "let's not" and "I can't let you lead Thirsk? says who" no longer click. "that's not where I come in" is pinned so it stays refused.
- **G11: closed** (0 of 3,172). "wait, &lt;line&gt;?", "is it true that &lt;line&gt;", "&lt;line&gt;?", "&lt;line&gt;? what stones", and "wait, really? Silus Vesuius says otherwise?" all ask the line back.
- **Name-word proxy: closed**, except `guard`.

## Gaps: still open (honest list)

1. **G1, 12 cases: service KIND picks. Not my function.**
   - What happens: "not now, what do you have for sale later", "no, what have you got for sale" and "I'm not sure, what…" click "What have you got for sale?" through `lrgDlgKindPick`.
   - Why: `lrgDlgWordsQualm` refuses the similarity pick, but the kind pick runs after it with no refusal test.
   - Fix for the owner of `lrgDlgKindPick` / `lrgDlgServiceKindSaid`: return none when `lrgDlgRefuses($utter) || preg_match(LRG_DLG_DEFER_RE, strtolower($utter)) || lrgDlgHedges($utter)`.
   - Harm: low, since it opens the barter window, which he can close.
2. **G1, 3 cases: "no, &lt;line&gt;" on Brotherhood lines that themselves refuse.** They are "I won't condemn an innocent man.", "Nah, I'll make my own way, thanks." and "No, no, you misunderstand, I mean I…". His "no" agrees with the refusal line, so I judge the click correct. The fixture marks these rows never; I disagree with that.
3. **G1 on the LLM path, 1 + 1 cases: judged correct.**
   - His refusal released "Actually, I'm not sure. I need to think about it." (the NO side of CW01A's layer).
   - His refusal also released "Forget it. Maybe I'll come back later." (TG06).
   - In both, his words are that line.
4. **G5, 1 case.**
   - Case: "can you get her arrested" against the plain, unscripted "Well, you can consider her gone for good. I had her arrested."
   - The line is not protected, so the rule does not bind it. It is still a wrong-sense click.
5. **G9: 29 remain.** About 14 are legitimate or out of scope:
   - He said another listed line almost word for word (8): "here, take the money", "I'll let you get to it", "I helped a man in need", "what if the Skaal refuse?", "I'd like to learn the spell", "skip the story…", "the Dawnguard nearly killed me", "my wealth is my own business".
   - The same question, reworded (5): "would you buy it?" / "Will you buy it?", "can the Sanctuary be repaired?" / "Can you repair and refit…", "is everything ready for the scroll?", "where are these other scrolls?", "where is &lt;Veezara&gt;".
   - Durak (1): intended.
   - "yes" and "okay" on TGBan "Here's the gold." belong to G14 (the price is invisible).

   Still live and mine:
   - "what was next" and "what's next for you" on Arniel's "So what's next?" (0E0CF1). Nothing in the words tells them apart from the STT-slipped say lines the canonical fixture requires, such as "i don't have time for the sauce". A one-strict-word rule I tried broke those fixture rows, so I removed it.
   - "who's Gianna" and "who protects the jarl?". The same question word, but a different object.
   - "what business is the College in" on "Is there any College business I can assist with?".
   - "what's a wayshrine?" on "Wayshrine?". I judge this the same question.
   - "how are you doing?" on "How am I doing?" is now fixed on scripted singles.
6. **G10: 3 remain, all judged legitimate.**
   - Two are the other line said: "I don't think I can help you right now." and "Slow down here. I don't want to hurt you.".
   - One asks the same question: "can't you take us to it now?" / "Does that mean you can take us to it now?".
7. **Extended canonical harness (`--extended`): 9 never rows fail.**
   - Judged legitimate (3): "would you buy it?", "can the Sanctuary be repaired?", "my wealth is my own business".
   - G14 (2): "yes" and "okay" on TGBan.
   - Plain-line near-misses with no lexical signal (4): "nice coat" / "I need your coat.", "I learned a new word", "Paarthurnax is dead" / "Paarthurnax has changed…", "what do you think of Markarth" / "What are you doing in Markarth?".

## Cost in say lines, attributed to my rules

This is on the shared tree, against the baseline. Lines that no longer click on the fast path because of a rule of mine now go to her T-key or to one question. **None goes to a wrong click.** About 33 lines out of 16,186:

- **21: "he asks another question" on lines that ask back as a fragment.** Examples:
  - "have you seen a moth priest in town" / "Know anything about a moth priest visiting Dragon Bridge?"
  - "Would you purchase this bust of the Gray Fox?" / "… Worth anything?"
  - "Could you advise me on Bersi?" / "Any help with Bersi?"

  The strict fragment rule is what closes "who gave those orders?" and "where can I find the bust…?". These lines still resolve by her key.
- **4: step 0b by shape, which the spec itself requires.** Example: "are you hurt" / "You're hurt.".
- **2: tag questions.** Example: "this is your letter to Olfina, isn't it".
- **"hoo are you":** the STT spelling. Her key still picks it.
- **1 each: a hedge on a commit, and "tomorrow" misheard for "Tamriel".**

The remaining lost lines are other lanes' work:
- lines `pt19h-grading` made `never_auto` (the CYA Arch-Mage hand-overs, the side switch, J'zargo);
- lines it re-graded as commits;
- the CWMission07 "Recognize this?" catch-all.

## Suites (WSL copy `/tmp/pt19h-safety/g`, the tree at 07:20)

```
test_dialogue.php     1185 passed, 2 failed      at 07:20: the 2 were section 13 (Tullius's road plan "qe=closed"), see below
                      re-run on the tree at 07:27: 1187 passed, 0 failed - ALL CHECKS PASSED (the quest lane fixed its part)
                      pt19h-safety section: 113 checks, 0 failed
test_gates.php         847 passed, 0 failed
test_intent.php        470 passed, 0 failed
test_mcm_wiring.php     49 passed, 0 failed
test_phrases.php        47 passed, 0 failed
test_prompt_index.php  103 passed, 0 failed
test_scene_index.php   ALL CHECKS PASSED
test_services.php      126 passed, 1 failed      [pt19h-reach] dialogue.reach.* config completeness
test_stt.php            60 passed, 0 failed
test_questline.php    1955 passed, 1 failed      MS05.viarmo.comein "and that's wear i come in" (see below)
  --first-evening      463 passed, 0 failed
  --fixture=…adversarial.json  841 passed, 0 failed
  --extended          2800 passed, 9 failed      listed under open item 7
flows/run_flows.php  87 passed, 1 FAILED, 1 pending   d65 [pt18-quest] Tullius road plan (same cause as test_dialogue section 13)
```

## Failures that are not mine

None of these touches a function I own:

- **`test_dialogue` section 13 and `flows` d65.**
  - What fails: `lrgFacAskActs` (pt19h-quest) reads "okay i guess you're the guy i talked to to join the legion" as a hedge. The cause is its own `i (?:guess|suppose|reckon|think)` regex. `lrgDlgHedges` does not match that sentence.
  - Result: no quest-entry plan is made, so `qe=closed` is missing.
- **`test_questline` MS05.viarmo.comein.**
  - What fails: pt19h-reach added the homophone fold `'wear' => ['where']` (`lrg_dialogue.php` ~3436). That makes "and that's wear i come in" an exact 1.0 release.
  - The fixture still expects the LLM path, so the fixture needs updating.
- **`test_services`:** pt19h-reach's config keys.

## Notes for other lanes

- **Kind-pick owner:** add the refusal and deferral guard to `lrgDlgKindPick` (open item 1). That closes the last 12 G1 clicks.
- **pt19h-quest:**
  - `lrgFacAskActs` also refuses "would like to join the companions" (C00.kodlak.join), which is the STT clipping the "I" off the join line.
  - Fix: exempt `lrgDlgQuoteQualm($utter, $joinLine)['kind'] === 'frag'`. My `lrgDlgExplicit` faction branch already does this.
- **`lrgDlgLabel` (not mine):**
  - It still prints `[leave]` for any class-back line, such as "Nothing I couldn't handle." (a commit).
  - Suggest `[leave]` only when `lrgDlgRealBackOut($e)`.
  - It is safe as it stands, because a T-key on such a line goes through the commit rails and parks. The label just misleads the model.

## Reproduce

Work folder: `%TEMP%\lrg_test\pt19h-safety\`.

1. `sync.sh` (Git Bash) copies the tree.
2. `stage.sh` (WSL) stages it to `/tmp/pt19h-safety/g` with the live index.
3. `run_m.sh <tag>` runs the measurer's engine on 24 shards.
4. `an.sh <tag>` runs the measurer's analysis with a safety summary.
5. For diffs and checks:
   - `sdiff2.sh` / `sdiff3.sh`: say-line diffs;
   - `leave2.sh`: LEAVE targets;
   - `probe.php` / `probe2.php`: per-beat fast path, key and explicit reasons;
   - `suites.sh <tag>`: all suites.

The final measurement is `analysis_r12.json`. The baseline analysis is `analysis_base.json`.
