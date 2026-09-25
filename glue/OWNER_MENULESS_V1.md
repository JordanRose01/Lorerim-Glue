# Menuless questing v1.0 - what you will see, and your first evening

This page is for LoreRim Glue v1.0 (when you load a save, the corner note says `LoreRim Glue v513 loaded`).
It says what changed, what you will notice, what to do on your first evening, and the few things only you can decide.

Every sentence this page tells you to say was checked before you play, against your own load order, for the person it
names. The list at the end says where. If one of them does not work in game, the page is wrong, not you.

Every step writes lines into one file:
`\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log`.
Every line starts with the time; lines from the game then say `GAME`. Where a log line below shows `...`, other words
sit between the parts, and a word ending in `=` (like `pos=`) is followed by a value in the real line.
CHIM keeps a log of its own as well; the end of section 3 says when to keep a copy of it.

---

## 1. What changed

When you talk to somebody about their business, their real list of things to say comes up on screen - the same list
you would get by pressing E - and stays there. You do not have to click it. Say what you want in your own words and
the matching line is picked for you; her real reply plays, and the game does the rest exactly as if you had clicked.
You can still click any line yourself at any moment, and nothing ever takes the list away from you.

There is no new key to remember and nothing to set up. After your first conversation with anyone (even one you open
with E), it has learned what it needs.

The first line it ever picks for you must be a simple one - a question, a bit of small talk. Until one has been
picked, a line that would move a quest along is left for you to click, and she tells you so: "I have not picked a line
for you yet - ask me something simple first". From then on it picks anything you name. This is counted once for your
whole install, not once per evening. It starts over only if you press "Forget everything it learned" (section 5).

---

## 2. What you will notice

- **The first time you talk business with someone, her list can take a few seconds to appear (up to eight).** She may
  say a short word while it comes up; her real line plays when it is picked. Which of the two you hear first is not
  certain yet - her word can come over her real line. Please tell us which it was. While the list is on screen you may
  also click it or press Tab.
  Do not repeat yourself: once the list is up, your second sentence would be answered from it too.
  One honest exception: small talk in your own words, to someone whose list has not been on screen in the last half
  hour, can be answered twice - once by her, once by the real line. The sentences on this page are her real lines,
  so they are not.
- **Trade.** "What have you got?" to an innkeeper or a merchant, even one you have never spoken to: her list appears,
  the trade line is picked in front of you, and the shop opens. "Show me your wares" works too.
  Buying and selling inside the shop is done with the mouse, as always. Close the shop (Tab) before you speak again:
  she cannot hear you while a trade, gift or training window is open.
  Before the first line has been picked for you on this install, the trade line is not picked for you: she tells you
  to ask her something simple first - or, if she has not shown you her list in the last half hour, she answers and her
  trade window opens after her line, the way it did before v1.0.
- **Rooms.** "I'd like a room for the night" (or "I need a bed for the night") at the Bannered Mare: the room line is
  picked, the list of how many days comes up, and one day is picked for you - 25 septims.
  She asks you first only when the price is 100 septims or more, or a quarter or more of what you carry.
  "I'd like a room" on its own picks the room line and leaves the days to you: say "one day" or "just one night".
- **Joining.** "I want to join the Companions" to Kodlak: one sentence, the real line, his real answer.
- **Quest conversations** (Balgruuf's hall, Delphine's room, the Greybeards). Say the line as it is written, or in your
  own words ("let's go", "Kynesgrove mound", "can you spare some supplies for the road"), and it is picked at once. Two
  lines still ask you once when you say them any other way than as written: Balgruuf's "I need to talk to you about
  Helgen" and Arngeir's "I am answering your summons" - she quotes the line, and "yes" is enough. A line that walks out
  on her ("I don't have time for this") is picked at once only when you say it as written.
- **The oath.** His line, then the next one plays by itself a short breath after his words have gone from the screen
  (or when you say it). "Long live the Emperor" ends it.
  - If you hold your talk key (Left Ctrl) and speak during that breath, the next line waits for your words.
  - If you ask him something in it, he answers, and the next line follows a breath after his answer ends.
  - Hold the key to speak, as always. A quick tap of it is CHIM's own hush: it stops whatever anyone is saying through
    CHIM, his answer included. A double tap tells the person you are looking at to wait here.
  - Only lines that change nothing by themselves play on like this. A lone line that moves a quest along (Balgruuf's
    "What else can I help you with?", Farengar's "So what do you need me to do?") never plays by itself: it waits for
    you to say it, or "yes".
- **The Whiterun gate, after Helgen.** Give the guard your message: "Riverwood calls for the jarl's aid", and he lets
  you in. Do not say "I have news from Helgen about the dragon attack" to the guard: at the gate that is the
  persuasion line, and it can fail.
- **Irileth, when she stops you inside Dragonsreach.** Wait for her list to show, then say "I have news from Helgen
  about the dragon attack", and it happens. This is the one place where saying it again, once the list is up, is right.
  Before the first line has been picked for you on this install she says "choose it on the list yourself" instead.
- **A choice that cannot be undone** (an oath, a refusal that ends things): she asks once unless you already said it
  plainly, and she quotes the line she means. Anything that costs 100 septims or more, or a quarter or more of what you
  carry, is always asked first.
  - "Yes" is enough after her question; so is "I swear", "alright", "okay" or "count me in".
  - "Yes, I'll do it" is a yes. "Yes, but not now" is a no, and so is "no" or "never mind".
- **When something cannot be done** and she knows it before she answers, she says why in her own words, and the list
  is yours. When the game refuses a line she asked for, the corner note says why at once; if the game found out only
  after trying the click, she also tells you the next time you speak.
- **Where it is still clumsy in v1.0.**
  - Two quest lines can ask once when you say them in your own words: Balgruuf's "I need to talk to you about Helgen"
    and Arngeir's "I am answering your summons". The game has other versions of each of these lines that end the
    conversation, and the words alone cannot tell which version is on your screen, so she checks first. Say "yes" and
    it goes through. Saying the line as it is written picks it at once.
  - A quest line said to someone who is not part of that quest opens nothing. Asking a passer-by "Where can I learn
    more about magic?" gets an answer in words, and the College quest does not move. Press E on her, and if the line
    is on her list, say it: then it is picked from her list.
  - Rides, crossings and lessons: to a carriage driver, a ferryman or a trainer, "take me to Whiterun", "take me
    across" or "can you train me" now brings her list up before she answers (the glue knows them by their job in the
    game); then say the place or the skill, or click it. This was checked offline, not yet in game: if a driver, a
    ferryman or a trainer answers without her list coming up, please tell us who it was.
- **Handing something over** (added in the second hardening round). When a line gives an item away ("Here, take them.",
  "Very well." with Auriel's Bow), your own words do it: "here are the fragments", "here, take the bow", "I give them back
  with honor". "I'm keeping it" never hands it over.
- **A question about a line is a question.** Ask about a line that moves a quest along ("where's the book?", "something
  dangerous?") and she asks you back whether you mean that line; "yes" picks it, "no" or "later" leaves it. "No one should
  have it" and "no joke, you can have it" are read as what they say, not as a refusal.
- **Asking for work.** "Got any work?", "anything need doing?", "what can I do for you?" pick the one line on her list that
  offers work; when two lines offer it (Urag has two) she asks which. "Did you find any work?", "I got a job to do" and "I
  wonder if there's anything I can do" pick nothing - they are not you asking.
- **Rumours and songs in your own words.** "Any rumors about the dragons" to Hulda, "sing me something about dragons" or "got
  any songs about dragons" to Sven pick their lines. A slip of the ear on a line that costs septims ("rent a groom") makes her
  ask first.

---

## 3. Your first evening, in this order

Your save is a new game that starts in Helgen, so the evening starts there. Each step names the sentence to say and
the log line that proves it. Skip a step your game does not reach; only the first one has to come first.

**(1) Helgen, before the dragon: Vilod.** "I've heard you are brewing a special kind of mead?"
- His list comes up after a moment, the line is picked in front of you, and his real answer plays. This is the first
  line ever picked for you on this install, and it is a simple one on purpose.
- The corner note "LoreRim Glue: the dialogue menu is learned (4 of 4)" shows once for your install, on this first
  conversation.
- Log: `open marker=toplevel`, `GAME opened sid=`, `GAME CALIB GREEN 4 of 4 learned`, `GAME clicked pos=`,
  `result npc=Vilod ... ok=1 ... clicks_ok=1`, `GAME CALIB set route src=live`.
- If this does not pick, stop here and send the log. Everything after it depends on that first pick.

**(2) When the dragon comes.** Nothing is opened or picked for you until Helgen is over; people answer in words.
- Log: a line starting `GAME QUIET on: MQ101 stage`, and after Helgen one starting `GAME QUIET off: MQ101 stage`.

**(3) Riverwood.**
- Sven in the Sleeping Giant Inn: "Do you know any old ballads about dragons?"
  Log: `open marker=toplevel`, `GAME clicked pos=`.
  Please note how many lines his list has: it should be four (the ballads, the stores, the dragon, and "How do I get
  to Whiterun from here?" - More to Say gives him that one in Riverwood). If you escaped with Ralof, a fifth, "I think
  Hod might think you drink too much." (More to Say), can be there too - its conditions allow it partway through that
  path. Any other extra line means the check behind this step has to be run again: please write it down.
- If you escaped with Hadvar - Alvor: "Hadvar said you could help me out". He asks how you know Hadvar: "He helped me
  escape from prison". Then "Do you have any supplies I could take?" - said as written it is picked at once; in your
  own words he may ask once ("yes" is enough).
  Log: `GAME arming ... sq=MQ102A sqj=0 sj=0 drv=1`, `GAME clicked pos=`.
- If you escaped with Ralof - Gerdur: "Ralof said you could help me out". She asks how you know him: "We escaped from
  the Imperials together".
  Log: `emit npc=Gerdur do=pick`, `GAME clicked pos=`.

**(4) The Whiterun gate** (the guard stops you): "Riverwood calls for the jarl's aid." He lets you in.
- Do not say "I have news from Helgen about the dragon attack" here: at the gate that is the persuasion line, and it
  can fail.
- If no line had been picked for you yet on this install (you skipped steps 1 and 3), he leaves this one for your
  click and says "I cannot pick any of these for you yet - choose this one yourself, this once".
- Log: `do=pick mode=explicit`, `GAME clicked pos= origin=engine`.

**(5) Irileth inside Dragonsreach** (with Hadvar's or Ralof's message): when her list appears, say "I have news from
Helgen about the dragon attack".
- Log: `emit npc=Irileth do=pick mode=explicit`, `GAME clicked pos= origin=engine sj=1`. The `GAME arming` line before
  it shows `sq=MQ102 sqj=1 sj=1 drv=1`.

**(6) Balgruuf.**
- If his list shows "I need to talk to you about Helgen", say that.
- When he asks about the dragon: "The dragon destroyed Helgen, and last I saw it was heading this way." It is picked
  at once.
- When he has rewarded you, one line is left: "What else can I help you with?". It waits for you, because it moves
  the quest along: say "what else can I help with", or just "yes".
- Log: `emit npc=Jarl Balgruuf the Greater do=pick mode=explicit`, later `emit npc=Jarl Balgruuf the Greater do=pick
  mode=single`.

**(7) Farengar**, where Balgruuf sends you: "Do you need any help with the dragons?"
- His list comes up, the line is picked, and his long answer plays.
- When one line is left, "So what do you need me to do?", it waits for you: say "what do you need done?" or "yes".
  ("Uh, what now" does nothing.)
- Log: `open marker=qrows`, `emit npc=Farengar Secret-Fire do=pick mode=single`.
- Later, back with the tablet: "I have the stone tablet you wanted". Log: `open marker=toplevel`,
  `emit npc=Farengar Secret-Fire do=pick`.

**Later in the evening, or on a save that starts in a town:**

**(8) Hulda in the Bannered Mare.** Press E on her, look at her list, press Tab.
- She keeps her list in mind for half an hour.
- Log: `calib npc=Hulda ... gate=1` (and `GAME CALIB GREEN 4 of 4 learned` if this is the first conversation on this
  install).

**(9) Hulda again, within that half hour:** "Nice inn you have here, do you get many visitors?"
- Her list comes up, and the line is picked in front of you.
- Then she asks who you are. Answer ("I'm just a traveler", or "I'm a refugee, Helgen was destroyed by a dragon"), or
  press Tab.
- If you waited longer than half an hour, press E on her again first.
- Log: `open marker=root` (or `open marker=toplevel`), `GAME opened sid=`, `GAME clicked pos=`,
  `result npc=Hulda ... ok=1 ... clicks_ok=`.

**(9b) Press E on Hulda while the bard is singing** and say "nice inn you have here" - it still works.
- Log: `GAME clicked pos= origin=engine sj=0`. The `GAME arming` line before it shows `sqj=0 sj=0 drv=1`: her scene
  (the bard's audience, or her own tavern patter) belongs to no quest in your journal.

**(10) Hulda again:** "What have you got?"
- Her list comes up, the trade line is picked, and the shop opens. Close it (Tab) before you speak again.
- Log: `open marker=kind`, then `emit npc=Hulda do=pick mode=kind` and `GAME clicked pos=`.

**(11) Hulda again:** "I'd like a room for the night."
- The room line, then one day from the days list, 25 septims. No question, unless 25 septims is a quarter or more of
  what you carry.
- Log: two `emit npc=Hulda do=pick` lines, the second with `cost=25`.

**(12) In Solitude, Corpulus in the Winking Skeever:** "Why is this place called the Winking Skeever?" - a simple
line, fine as the first one on a new install too. A short list follows: say "You kept a skeever as a pet?", or press
Tab.

**(13) Faralda on the College bridge:** "May I enter the College?"
- Then she asks what you expect to find within: answer with one of her lines as written, for example "I seek the
  knowledge of the Elder Scrolls". In your own words she asks once which you mean.
- Log: `emit npc=Faralda do=pick mode=faction` (her exact joining line), `GAME clicked pos=`, then
  `emit npc=Faralda do=pick mode=explicit`.

**(14) Kodlak:** "I would like to join the Companions."
- Log: `emit npc=Kodlak Whitemane do=pick mode=explicit`, `GAME clicked pos=`.

**(15) If you happen to have a bounty,** a guard's list stays yours and she says so.
- Log: `GAME stopped driving why=lethal`.

**Report what she said and what you saw.** Every one of these writes a line in the log. Please keep two files from
the evening:
- `lorerim_glue.log` (above);
- CHIM's own `AIAgent.log`, in `Documents\My Games\Skyrim.INI\SKSE\`. It starts afresh every time Skyrim starts, so
  copy it before you play again. It shows how quickly each list was sent to the game and in which order her voice and
  her real line played.

Also tell us, for the first list that came up, whether you heard her short word before her real line or over it.

---

## 4. What it will not do, and says so

- **Helgen.** There is nothing to pick there; the game plays it.
- **A guard arresting you.** The list is yours.
- **Before the first line has been picked for you on this install,** an answer that would move a quest along, or one
  that costs septims, is left for you to click once. Or ask something simple first; she says which.
- **Conversations that begin inside a running quest scene** (a court in session, the sacrament ritual): the list shows.
  As soon as the first line has been picked for you on this install, those are picked by voice too. Before that, she
  tells you to choose on the list yourself.
- **Windows that need the mouse.** Buying and selling inside a shop, choosing a lesson in the training window and a
  follower's gift or inventory window are done with the mouse, and she cannot hear you while one of them is open. A
  list with no way back out of it is yours to leave by hand; she tells you so. Food and drink need no window at all:
  "A mead, please" to an innkeeper, and she names what she has with the price in septims and asks which one.
- **A different reward.** What a quest pays is fixed by the game, and she is told so when you bargain. If the list has
  a line about your reward, she will point you to it. An item, a house, a title or a favour is never hers to add, and
  she says so.
- **Extra septims (on since v1.0.1).** Within about three minutes of being handed a reward, ask for more in your own
  words ("I deserve more than this, a hundred septims", "can you sweeten it"). A real Speech check runs. If you pass,
  she gives you the extra from her own purse: never more than she carries, never more than a day of her wages, never
  more than 500 septims, and only once per quest. If you fail, or her purse is empty, she says so and gives nothing.
  If she ever claims to add septims the check did not grant, that line is stopped before she speaks it. The same words
  outside a reward window are no check at all (haggling with a merchant is never turned into one). To switch it off:
  `"dialogue": {"checks": {"reward": {"enabled": false}}}` in `config/lrg_config.json`, no rebuild.

### If the Helgen keep locks you in (the known Unbound stall, not the glue)

Symptom: you enter the keep after the dragon, Hadvar or Ralof never shows up, and you cannot activate anything (no
chests, no doors). The companion's keep scene never started, so the game never gave your controls back. Open the
console (`~`) and type:

1. `prid 0002BFA2` (Hadvar) or `prid 0002BF9E` (Ralof), then `moveto player`. Close the console and stand still for
   10 to 20 seconds; the scene should start and the quest carries on.
2. If nothing starts within a minute: `enableplayercontrols`. That gives activation back at once.

On a new game, before you rent the room at the Helgen inn, Alternate Perspective's Messenger can skip the dragon
attack entirely. Once the room is rented that skip is gone, so use the console steps above. Full diagnosis: `research/pt19-helgen.md`.

---

## 5. Settings

None needed. Everything below is in the game's settings: Esc > Mod Configuration > LoreRim Glue.

- **Menuless questing page.**
  - "Quest talk without the menu" is on. Leave it on.
  - "Menuless questing: dry run (nothing is clicked)" is off. Tick it only if you want to see what it would pick
    without it picking; leave it off for play.
  - "Let her carry on by herself when there is only one thing to say" is the short breath in the oath. Leave it on.
  - "Guards, arrests and bounties: always yours to click" is a line of text, not a setting: an arrest is never picked
    for you.
  - "NPCs inside a quest scene" is set to "Unless a journal quest owns it". Leave it there.
  - "Tell me when she has something" is on. It only governs her quest hints: the corner note "not picked yet - ask
    something simple first" shows once per conversation whatever it is set to.
- **Keys page.** "Your talk key (CHIM's push-to-talk)" is set to Left Ctrl, the same key CHIM uses. LoreRim Glue only
  listens to it: a press during that breath makes the lone line wait for your words. Everything else the key does is
  CHIM's own - hold it to speak, as always; a quick tap stops whatever anyone is saying through CHIM; a double tap
  tells the person you are looking at to wait here. If you ever change CHIM's key, change this one too - or clear it,
  and she will simply carry on after the breath and answer your words on the next line.
- **Calibration page.**
  - "Calibration status" should read "4 of 4 learned, route proven by 1 click" after the first line picked for you.
    Before any conversation it reads "learning: N of 4 - talk to anyone once".
  - "Forget everything it learned" starts over from nothing. The first line picked for you afterwards must be a simple
    one again.
- **Dialogue subtitles.** The short breath needs them on (they are on in your install). With them off, every such
  line waits for your voice.

---

## 6. Four things only you can decide

Each has a default, so nothing needs deciding to play.

1. **The list is on screen.** v1.0 shows the real list while you talk (you never have to click it). Hiding it is a
   possible polish for later and is not in v1.0. Default: visible. Say so if you would rather wait for a hidden version
   than play v1.0 as it is.
2. **The short breath.** A line that is the only thing to say and changes nothing by itself (the oath lines, "The
   Greybeards?") plays by itself 2.5 seconds after her words have gone from the screen - or, if you asked her
   something in the breath, 2.5 seconds after her answer ends - unless you press your talk key (Left Ctrl) in that
   breath. A lone line that moves a quest along never plays by itself; it waits for you to say it, or "yes". Default:
   on, the key is Left Ctrl, dialogue subtitles on. Say so if you want every line to wait for your voice, or if your
   CHIM push-to-talk is not Left Ctrl.
3. **Extra septims (on since v1.0.1).** The most she gives is what she carries, and at most one day of her wages: 40
   septims for a farmhand, 100 septims for a merchant, 500 septims at a jarl's court. Once per quest, after a real
   Speech check. Default: on at those numbers.
4. **Conversations that begin inside a running quest scene** are picked by voice from the moment the first line has
   been picked for you on this install (it checks this itself). Until then she tells you to choose on the list.
   Default: on. Say so if you would rather they always stay yours to click. That is one line in LoreRim Glue's config
   file (`"dialogue": {"session": {"drive_scene": false}}` in `config/lrg_config.json`), with no rebuild.

---

## 7. Where each sentence on this page was checked

For the record. Each sentence was run through the real code, offline, against the dialogue of your load order, for the
person named. Rows whose last column starts with `FE.` are the first-evening check
(`tools/test_questline.php --first-evening`); plain beat names are beats of the same checker
(`tools/fixtures/lrg_questline.json`). Rows marked extra run (`F.` beats) or walkthrough (`W.` beats) were checked by
the same checker on two extra sets of beats, on the same code and the same load order (the `F.` beats are written out
in `research/pt19c-F.md`); they are being moved into the standing first-evening check. That move adds the extra-run
beats (they include corrected copies of the walkthrough ones) and changes nothing else. It was run: every one of them
passes there too. The standing checks are green (2026-09-25, final fix round).
The second hardening round (2026-09-25, the cloud session; `research/pt19h-r2-resolution.md`) ran every standing check again
on the finished tree, twice, and compared the words-mode and the extended coverage line by line with the morning's
baselines: no first-evening sentence got worse; the one change on this page's sentences is "i'd like to rent a groom" (a slip
of the ear on a priced line), which now makes her ask first instead of picking.
A row marked "re-classed 2026-09-25" was ruled that day: the line's own conversation also carries a version of it that
ends the talk, so the glue treats it as a line to confirm - said as written it is picked at once, and some looser
wordings make her ask once ("yes" picks it). The check now asserts exactly that. Sven's "sing me something about
dragons" was ruled the same day: it picks his ballads line, which is what you asked for.
The quest rows marked "(fixed 2026-09-25)" were such rows until the glue stopped borrowing another conversation's
"this ends the talk" and check marks for a quest line (the standing check now passes every wording); their extra-run
(`F.`) beats were measured before that fix.
In the first table, `mode kind (<service>)` marks a sentence that is also read as a request for that service
(`tools/test_services.php` reads those rows and checks each one).

**Asking for a service**

| who | sentence | what it is | checked by |
|---|---|---|---|
| Hulda | "What have you got?" | trade - mode kind (barter) | FE.hulda.trade, FE.hulda.open |
| Hulda | "Show me your wares" | trade - mode kind (barter) | FE.hulda.trade |
| Hulda | "I'd like a room for the night" | a room - mode kind (inn); then one day, 25 septims | FE.hulda.room.chain |
| Hulda | "I need a bed for the night" | a room - mode kind (inn); then one day, 25 septims | FE.hulda.room.chain |
| Hulda | "I'd like a room" | a room - mode kind (inn); the days are left to you | FE.hulda.room.start |

**Everything else**

| who | sentence | what happens | checked by |
|---|---|---|---|
| Vilod | "I've heard you are brewing a special kind of mead?" | the list comes up, the line is picked, even as the first one | walkthrough W.vilod.plain, W.open.vilod; extra run F.vilod.plain, F.open.vilod |
| Sven | "Do you know any old ballads about dragons?" | a simple first line | FE.riverwood.plain |
| Alvor | "Hadvar said you could help me out" | a simple first line | FE.riverwood.alvor, MQ102A.alvor.hadvar |
| Alvor | "He helped me escape from prison" | picked | extra run F.alvor.answer |
| Alvor | "Do you have any supplies I could take?" | picked at once, as written or in your own words ("can you spare some supplies for the road") | MQ102A.alvor.help (fixed 2026-09-25); extra run F.alvor.help |
| Gerdur | "Ralof said you could help me out" | a simple first line; the list comes up | extra run F.gerdur.ralof, F.open.gerdur |
| Gerdur | "We escaped from the Imperials together" | picked | extra run F.gerdur.answer |
| Whiterun gate guard | "Riverwood calls for the jarl's aid" | picked at once; left for your click if it would be the first pick | MQ102.gate.note; extra run F.gate |
| Whiterun gate guard | "I have news from Helgen about the dragon attack" | the persuasion line (can fail) | MQ102.gate.persuade |
| Irileth | "I have news from Helgen about the dragon attack" | picked at once (after the first pick) | FE.irileth.gate |
| Balgruuf | "I need to talk to you about Helgen" | picked at once when said as written; in your own words she may ask once | extra run F.balgruuf.intro; MQ102.balgruuf.intro (re-classed 2026-09-25) |
| Balgruuf | "The dragon destroyed Helgen, and last I saw it was heading this way" | picked at once | MQ102.balgruuf.helgen; extra run F.balgruuf.helgen |
| Balgruuf | "what else can I help with" | his last line, which waits for you | MQ102.balgruuf.reward; extra run F.balgruuf.reward |
| Balgruuf | "yes" | the same | extra run F.balgruuf.reward |
| Farengar | "Do you need any help with the dragons?" | the list comes up, the line is picked | MQ103.farengar.intro; extra run F.open.farengar, F.farengar.intro |
| Farengar | "what do you need done?" | his last line, which waits for you | MQ103.farengar.assignment; extra run F.farengar.assign |
| Farengar | "yes" | the same | extra run F.farengar.assign |
| Farengar | "I have the stone tablet you wanted" | the list comes up, the line is picked | MQ103.farengar.turnin; extra run F.open.farengar |
| Balgruuf, later | "I need to talk to you" | picked at once, as written or in your own words ("I need a word with you") | MQ103.balgruuf.blocking (fixed 2026-09-25); extra run F.balgruuf.blocking |
| Hulda | "Nice inn you have here, do you get many visitors?" | the list comes up, the line is picked | FE.hulda.plain, FE.hulda.open |
| Hulda | "nice inn you have here" | picked while the bard sings | FE.hulda.engine |
| Hulda | "I'm just a traveler" | picked | FE.hulda.plain.followup |
| Hulda | "I'm a refugee, Helgen was destroyed by a dragon" | picked | FE.hulda.plain.refugee |
| Hulda | "one day" | picked from the days list | FE.hulda.room.days |
| Hulda | "just one night" | picked from the days list | FE.hulda.room.days |
| Corpulus | "Why is this place called the Winking Skeever?" | the list comes up, the line is picked, even as the first one | walkthrough W.corpulus.plain, W.open.corpulus; extra run F.corpulus.plain, F.open.corpulus |
| Corpulus | "You kept a skeever as a pet?" | picked | walkthrough W.corpulus.pet; extra run F.corpulus.pet |
| Faralda | "May I enter the College?" | picked at once | MG01.faralda.enter |
| Faralda | "I seek the knowledge of the Elder Scrolls" | picked at once | MG01.faralda.seek |
| Kodlak | "I would like to join the Companions" | picked at once | FE.kodlak, C00.kodlak.join |
| Kodlak | "I want to join the Companions" | the list comes up, the line is picked | C00.kodlak.join; extra run F.open.kodlak |
| Arngeir | "I am answering your summons" | picked at once when said as written; "am answering your summons" asks once | extra run F.arngeir.summons; MQ105.arngeir.summons (re-classed 2026-09-25) |
| Delphine | "I know that mound - high on the hill east of Kynesgrove" | picked at once, as written or in your own words ("Kynesgrove mound") | MQ106.delphine.mound (fixed 2026-09-25); extra run F.delphine.mound |
| Delphine | "Let's go kill a dragon" | picked at once, as written or in your own words ("let's go") | MQ106.delphine.go (fixed 2026-09-25); extra run F.delphine.go |
| Delphine | "I don't have time for this" | picked at once when said as written (it walks out on her) | MQ106.delphine.walkout; extra run F.delphine.walkout |
| Brynjolf | "What do I have to do?" | picked when you say it; otherwise it plays by itself a short breath after his line | TG00.brynjolf.chain06 (fixed 2026-09-25); extra run F.brynjolf.what |
| Aventus | "Are you all right?" | picked at once, as written or in your own words ("you okay, kid?") | DB01.aventus.ok (fixed 2026-09-25); extra run F.aventus.ok |
| the captive in the shack | "Who are you?" | picked at once, as written or in your own words ("and who are you") | DB02.captive.who (fixed 2026-09-25); extra run F.captive.who |
| a passer-by | "Where can I learn more about magic?" | opens nothing (she answers in words) | walkthrough W.open.anyone; extra run F.open.anyone |
| Tullius | "Long live the Emperor" | ends the oath | CW01A.oath.4 |
| Tullius | "I swear" | the next oath line | CW01A.oath.1, CW01A.oath.4 |
| an innkeeper | "A mead, please" | she names the meads with their prices and asks which | `tools/test_dialogue.php` (buying by voice) |
