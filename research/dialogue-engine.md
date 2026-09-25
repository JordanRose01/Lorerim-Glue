# Dialogue Engine Feasibility - driving vanilla dialogue programmatically ("menuless questing")

Phase 0 research report. Scope: Skyrim SE 1.6.1170 / LoreRim (MO2 profile `Ultra`) / CHIM AIAgent 3.3.2 / CommonLibSSE-NG.
Conventions: **[V]** = verified in a source I opened (file:line or URL given). **[I]** = inference / design reasoning, not directly verified. **NOT FOUND** = searched, nothing found (search locations stated).
Caveat on web sources: GitHub raw files were read through a fetch tool that relays text through a small model. Struct/enum text below was requested verbatim and cross-checked between forks where possible, but the lead should diff against the exact CommonLib commit that gets pinned before relying on offsets. `ck.uesp.net` (CK wiki) blocks automated fetches (HTTP 403); CK wiki statements are quoted from the BellCube Papyrus index (which mirrors CK wiki text) or from search-result snippets and are marked as such.

---

## Executive summary (10 lines)

1. No installed Papyrus native lists an NPC's available topics. One installed native CAN evaluate INFO conditions: `PO3_SKSEFunctions.EvaluateConditionList(Form, ObjectReference, ObjectReference)` (supports `FormType::Info`), but without quest/alias context.
2. Vanilla Papyrus dialogue surface is tiny: `Topic.Add()`, `TopicInfo.GetOwningQuest()`, `ObjectReference.Say()`, `IsInDialogueWithPlayer()`, SKSE `GetDialogueTarget()`; nothing enumerates or "clicks" topics.
3. The engine's player topic list lives in `RE::MenuTopicManager::dialogueList` (`Dialogue{topicText,parentTopic,parentTopicInfo,parentQuest,responses,neverSaid}`); it only exists while a dialogue session is open.
4. INFO effects in Skyrim are Papyrus fragments (`Function Fragment_N(ObjectReference akSpeakerRef)` on a `TopicInfo` script), fired by the engine through `TESTopicInfoEvent` (kTopicBegin / kTopicEnd) - there is no native "result: set stage" field to replay.
5. TCLT links, RNAM prompt, TWAT walk-away topic and response text are NOT in the runtime `TESTopicInfo` struct; the engine re-reads them from the plugin via `fileOffset`. A native re-implementation of the dialogue tree therefore needs an offline data export.
6. Every open-source prior art (DSN, DDR, Dialogue History, Predictable Persuasion, Mantella-vanilla-dialogue, CHIM's own player-TTS gate) works WITH the Dialogue Menu open: read `dialogueList` / `PopulateDialogueList`, select via Scaleform `onSelectionClick` -> `TopicClicked`. No published technique executes an INFO "as if clicked" without the menu: NOT FOUND.
7. `Say()`/console `SayTo` do evaluate INFO conditions (CK wiki: speaker "passed to topic conditions"), but CK wiki warns fragments of a Say topic "are often not processed correctly". Not a safe quest-progression path.
8. Recommended architecture: "headless real dialogue" - let the engine run the real session (conditions, say-once, links, walk-away, fragments, service menus), hide the menu, read `dialogueList` natively, select by Scaleform/native click; plus a read-only native pre-enumerator for awareness when no session is open.
9. Minimum native surface is small (about 6 functions + 2 event sinks); a Papyrus-only prototype of the select/hide half is possible today via SKSE `UI.Invoke*`, which is how CHIM itself talks to its patched `dialoguemenu.swf`.
10. CHIM 3.3.2 already ships a hand-authored quest progression engine (`lib/chim_quest_engine.php` + `AIAgentQuestProgressionBridge.psc`, 1.3 MB of per-quest JSON) that sets stages directly; the glue must coexist with it or disable it (`CHIM_AI_QUEST_PROGRESSION`).

---

## 1. Papyrus-level capabilities (vanilla + SKSE + installed extenders)

### 1.1 Bounded scan performed
Scan: `F:\Modlists\LoreRim\mods\**\*.psc`, lines matching (case-insensitive) `Function ... native` AND (`topic` | `dialogue` | `dialog`). Complete result set **[V]**:

```
Andrealphus' Papyrus Functions\Source\Scripts\ANDR_PapyrusFunctions.psc:381: InfoTopic Function GetCurrentDialogueTopic()  global native      <- inside a ;/ ... /; "Wish list" comment block, NOT implemented
Andrealphus' Papyrus Functions\Source\Scripts\ANDR_PapyrusFunctions.psc:491: Function EndDialogue(Actor akActor) Global Native               <- inside ";/ Unused or deprecated" comment block
CHIM\Source\Scripts\AIAgentFunctions.psc:13:   int function stopAllDialogue() Global Native
CHIM\Source\Scripts\AIAgentFunctions.psc:88:   int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native
CHIM\Source\Scripts\AIAgentFunctions.psc:138:  int function startPlayerMenuDialogueTTS(String fallbackText) Global Native
Mfg Fix NG\source\scripts\MfgConsoleFuncExt.psc:121: bool Function IsInDialogue(Actor akActor) global native
OStim Standalone ...\Scripts\Source\OThreadBuilder.psc:166: Function NoPostDialogue(int BuilderID) Global Native
OStim Standalone ...\Scripts\Source\OSANative.psc:66:       Function EndPlayerDialogue() Global Native
OStim Standalone ...\Scripts\Source\OActorUtil.psc:82:      Function SayTo(Actor Act, Actor Target, Topic Dialogue) Global Native
OStim Standalone ...\Scripts\Source\OActorUtil.psc:94:      Function SayAs(Actor Act, Actor Target, Topic Dialogue, VoiceType Voice) Global Native
Skyrim Script Extender (SKSE64)\Scripts\Source\Actor.psc:56:   Function AllowBleedoutDialogue(bool abCanTalk ) native
Skyrim Script Extender (SKSE64)\Scripts\Source\Actor.psc:59:   Function AllowPCDialogue(bool abTalk) native
Skyrim Script Extender (SKSE64)\Scripts\Source\Actor.psc:190:  Actor Function GetDialogueTarget() native
Skyrim Script Extender (SKSE64)\Scripts\Source\Game.psc:446:   ObjectReference Function GetDialogueTarget() global native
Skyrim Script Extender (SKSE64)\Scripts\Source\ObjectReference.psc:426: bool Function IsInDialogueWithPlayer() native
Skyrim Script Extender (SKSE64)\Scripts\Source\ObjectReference.psc:517: Function Say(Topic akTopicToSay, Actor akActorToSpeakAs = None, bool abSpeakInPlayersHead = false) native
```
The ANDR entries are commented out (file lines 378-383 and 481-495 are inside `;/ ... /;` blocks) **[V]** - they are wishes, not functions.

### 1.2 powerofthree's Papyrus Extender
File: `F:\Modlists\LoreRim\mods\powerofthree's Papyrus Extender\Source\scripts\PO3_SKSEFunctions.psc`. Grep for `topic` and `dialogue`: **no matches** **[V]**. Relevant natives that do exist **[V]**:

```papyrus
Bool Function EvaluateConditionList(Form akForm, ObjectReference akActionRef, ObjectReference akTargetRef) global native   ; :478
string[] Function GetConditionList(Form akForm, int aiIndex = 0) global native                                              ; :482
string[] Function GetScriptsAttachedToForm(Form akForm) global native                                                       ; :490
Bool Function IsScriptAttachedToForm(Form akForm, string asScriptName) global native                                        ; :498
Quest[] Function GetActiveAssociatedQuests(ObjectReference akRef, Bool abAllowEmptyStages = True) global native             ; :812
Quest[] Function GetAllAssociatedQuests(ObjectReference akRef, Bool abAllowEmptyStages = True) global native                ; :820
int[] Function GetAllQuestObjectives(Quest akQuest) global native                                                           ; :1007
int[] Function GetAllQuestStages(Quest akQuest) global native                                                               ; :1009
Actor[] Function GetActorsInScene(Scene akScene) global native                                                              ; :1025
bool Function IsActorInScene(Scene akScene, Actor akActor) global native                                                    ; :1027
```
Upstream implementation (GitHub master) confirms INFO support **[V]**:
- `https://raw.githubusercontent.com/powerof3/PapyrusExtenderSSE/master/src/Papyrus/Functions/Form/Functions.cpp` - `EvaluateConditionList`: `case RE::FormType::Info: result = a_form->As<RE::TESTopicInfo>()->objConditions.IsTrue(a_actionRef, a_target);`
- `https://raw.githubusercontent.com/powerof3/PapyrusExtenderSSE/master/src/Papyrus/Util/ConditionParser.cpp` - `GetConditions`: `case RE::FormType::Info: condition = &a_form.As<RE::TESTopicInfo>()->objConditions;`

Limit **[I]**: it calls `TESCondition::IsTrue(actionRef, targetRef)` which builds `ConditionCheckParams` with `quest = nullptr` (see 2.6), so alias-based conditions (`GetIsAliasRef`, run-on `kQuestAlias`) can return false negatives. Predictable Persuasion's author observed exactly this ("evaluating the full responseInfo->objConditions sometimes returns false negatives" - see section 5).

### 1.3 Vanilla Papyrus dialogue API (from BellCube Papyrus index, mirrors CK wiki)
- `Topic` script: single function `Add()` - "Adds this topic to the player's list of known topics." **[V]** https://papyrus.bellcube.dev/skyrimse/script/topic/
- `TopicInfo` script: single function `Quest GetOwningQuest()`; no events documented **[V]** https://papyrus.bellcube.dev/skyrimse/script/topicinfo/
- `ObjectReference.Say`, `IsInDialogueWithPlayer()` ("Is this actor or talking activator currently talking to the player?") **[V]** https://papyrus.bellcube.dev/skyrimse/script/objectreference/function/isindialoguewithplayer/
- Story Manager hook: `Event OnStoryDialogue(Location akLocation, ObjectReference akActor1, ObjectReference akActor2)` **[V]** `Skyrim Script Extender (SKSE64)\Scripts\Source\Quest.psc:169`
- Vanilla Papyrus sources (Topic.psc / TopicInfo.psc / FavorDialogueScript.psc) are NOT extracted anywhere under `F:\Modlists\LoreRim` (Glob `**/TopicInfo.psc`, `**/FavorDialogueScript.*` -> no files) **[V]**.

### 1.4 SKSE UI scripting (enough to drive the dialogue SWF from Papyrus)
`Skyrim Script Extender (SKSE64)\Scripts\Source\UI.psc` **[V]**:
```papyrus
bool Function IsMenuOpen(string menuName) global native                       ; :47
Function SetBool(string menuName, string target, bool value) global native    ; :57
int Function GetInt(string menuName, string target) global native             ; :72
string Function GetString(string menuName, string target) global native       ; :74
Function InvokeBool(string menuName, string target, bool arg) global native   ; :90
Function InvokeInt(string menuName, string target, int arg) global native     ; :91
Function InvokeFloat(string menuName, string target, float arg) global native ; :92
Function InvokeString(string menuName, string target, string arg) global native ; :93
```
Menu name is `"Dialogue Menu"` (UI.psc:6; CHIM uses it at `AIAgentPapyrusFunctions.psc:1174`).
Console bridge also installed: `ConsoleUtilSSE NG\Scripts\Source\ConsoleUtil.psc:22  function ExecuteCommand(String a_command) global native` **[V]**.

### 1.5 Answer
- Lists an NPC's currently available topics: **NOT FOUND** in any installed `.psc` (scan above; also checked DLL list of 343 SKSE plugins for dialogue-related names: only `SmartTalk.dll`, `ConsiderateFollowers.dll`, `AlternateConversationCamera.dll`, `LingeringSubtitlesFix.dll`, `Fuz Ro D'oh.dll`, `AIAgent.dll`, `ANDR_PapyrusFunctions.dll`, none with a Papyrus topic API).
- Evaluates INFO conditions: **YES**, `PO3_SKSEFunctions.EvaluateConditionList` (needs the INFO Form, e.g. `Game.GetFormFromFile(id, plugin)`; no quest context).
- Runs a chosen INFO's fragments generically: **NOT POSSIBLE in pure Papyrus** - Papyrus has no dynamic dispatch onto an arbitrary `TIF__xxxxxxxx` script type; only the engine (or a native `DispatchMethodCall`) can do it **[I]**.

---

## 2. CommonLibSSE-NG structures and engine mechanics

### 2.1 `RE::TESTopicInfo`
URL: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/T/TESTopicInfo.h **[V]**
```cpp
struct TOPIC_INFO_DATA  // ENAM
{
    enum class TOPIC_INFO_FLAGS
    {
        kNone = 0,
        kStartSceneOnEnd = 1 << 0,
        kRandom = 1 << 1,
        kSayOnce = 1 << 2,
        kRequiresPlayerActivation = 1 << 3,
        kInfoRefusal = 1 << 4,
        kRandomEnd = 1 << 5,
        kEndRunningScene = 1 << 6,
        kIsForceGreet = 1 << 7,
        kPlayerAddress = 1 << 8,
        kForceSubtitle = 1 << 9,
        kCanMoveWhileGreeting = 1 << 10,
        kNoLIPFile = 1 << 11,
        kPostProcess = 1 << 12,
        kCustomSoundOutput = 1 << 13,
        kSpendsFavorPoints = 1 << 14
    };
    [[nodiscard]] float GetResetHours() const;
    stl::enumeration<TOPIC_INFO_FLAGS, std::uint16_t> flags;           // 0
    std::uint16_t                                     timeUntilReset;  // 2
};

class TESTopicInfo : public TESForm   // FORMTYPE = FormType::Info
{
    enum class FavorLevel { kNone = 0, kSmall = 1, kMedium = 2, kLarge = 3 };          // CNAM
    struct ChangeFlags { enum ChangeFlag : std::uint32_t { kSaidOnce = (std::uint32_t)1 << 31 }; };
    struct ResponseData  // TRDT   (named TESResponse in powerof3/CommonLibVR forks)
    {
        void PopulateResponseText(TESFile* a_file);          // RELOCATION_ID(24985, 25491)  (fork name: LoadResponseText)
        stl::enumeration<EmotionType, std::uint32_t> emotionType;     // 00
        std::uint32_t                                emotionValue;    // 04
        TESTopic*                                    unk08;           // 08
        std::uint8_t                                 responseNumber;  // 10
        BGSSoundDescriptorForm*                      sound;           // 18
        stl::enumeration<Flag, std::uint8_t>         flags;           // 20  (kUseEmotionAnimation = 1 << 0)
        BSFixedString                                responseText;    // 28 - NAM1
        TESIdleForm*                                 speakerIdle;     // 30
        TESIdleForm*                                 listenerIdle;    // 38
        ResponseData*                                next;            // 40
    };
    DialogueItem GetDialogueData(Actor* a_speaker);   // == { parentTopic->ownerQuest, parentTopic, this, a_speaker }

    TESTopic*                                  parentTopic;    // 20
    TESTopicInfo*                              dataInfo;       // 28 - shared-info link (NG comment says PNAM; po3/VR forks say DNAM)
    TESCondition                               objConditions;  // 30 - CTDA
    std::uint16_t                              infoIndex;      // 38 - index in infoTopics array of parent topic
    bool                                       saidOnce;       // 3A
    stl::enumeration<FavorLevel, std::uint8_t> favorLevel;     // 3B - CNAM
    TOPIC_INFO_DATA                            data;           // 3C - ENAM
    std::uint32_t                              fileOffset;     // 40
    std::uint32_t                              pad44;          // 44
};
static_assert(sizeof(TESTopicInfo) == 0x48);
```
Source for the two relocations: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/src/RE/T/TESTopicInfo.cpp **[V]**.
powerof3 fork adds `TESResponseList* GetResponseList(TESResponseList* a_list = nullptr);` = `RELOCATION_ID(25083, 25626)` and sizes the class `0x50` under `SKYRIM_SUPPORT_AE` (`unk44`, `unk48`) **[V]** https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/T/TESTopicInfo.h and `.../src/RE/T/TESTopicInfo.cpp`. alandtse/CommonLibVR `ng` exposes the same 8 extra bytes as an optional versioned runtime-data block at 0x48 **[V]** https://raw.githubusercontent.com/alandtse/CommonLibVR/ng/include/RE/T/TESTopicInfo.h. Members up to 0x44 are identical in all three; do not `sizeof`/allocate this class on 1.6.1170.

**Flag-name trap [V + I]:** CommonLib names for ENAM bits do not match the Creation Kit names. CK/xEdit names by bit value (UESP `https://en.uesp.net/wiki/Skyrim_Mod:Mod_File_Format/INFO`, Mutagen `DialogResponses.cs`):

| bit | CK / UESP / Mutagen name | CommonLib enumerator |
|---|---|---|
| 0x0001 | Goodbye | kStartSceneOnEnd |
| 0x0002 | Random | kRandom |
| 0x0004 | Say once | kSayOnce |
| 0x0008 | (unlisted) | kRequiresPlayerActivation |
| 0x0010 | "On activation" (UESP) | kInfoRefusal |
| 0x0020 | Random end | kRandomEnd |
| 0x0040 | Invisible continue | kEndRunningScene |
| 0x0080 | Walk away | kIsForceGreet |
| 0x0100 | Walk away invisible in menu | kPlayerAddress |
| 0x0200 | Force subtitle | kForceSubtitle |
| 0x0400 | Can move while greeting | kCanMoveWhileGreeting |
| 0x0800 | Has no lip file | kNoLIPFile |
| 0x1000 | Requires post-processing | kPostProcess |
| 0x2000 | Has audio output override | kCustomSoundOutput |
| 0x4000 | Spends favor points | kSpendsFavorPoints |

Use numeric bit values in glue code, not the CommonLib names (the mapping by bit position is my inference; both name lists are verified).
**Not present in the runtime struct [V by absence]:** TCLT (link-to topics), RNAM (prompt override), TWAT (walk-away topic), ANAM (speaker), response text. INFO subrecord list: UESP INFO page and Mutagen `DialogResponses.xml` (`https://raw.githubusercontent.com/Mutagen-Modding/Mutagen/dev/Mutagen.Bethesda.Skyrim/Records/Major%20Records/DialogResponses.xml`): VMAD, DATA, ENAM, TPIC, PNAM, CNAM, TCLT, DNAM, TRDT/NAM1/NAM2/NAM3/SNAM/LNAM, CTDA, SCHR/QNAM/NEXT, RNAM, ANAM, TWAT, ONAM.

### 2.2 `RE::TESTopic`
URL: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/T/TESTopic.h **[V]**
```cpp
struct DIALOGUE_DATA  // DATA
{
    enum class TopicFlag { kNone = 0, kDoAllBeforeRepeating = 1 << 0 };
    enum class Subtype { kCustom = 0, kForceGreet = 1, kRumors = 2, kUnk3 = 3, kIntimidate = 4, kFlatter = 5, kBribe = 6,
        kAskGift = 7, kGift = 8, kAskFavor = 9, kFavor = 10, kShowRelationships = 11, kFollow = 12, kReject = 13, kScene = 14,
        kShow = 15, kAgree = 16, kRefuse = 17, kExitFavorState = 18, kMoralRefusal = 19, /* 20-65 combat/detection/etc */
        kServiceRefusal = 66, kRepair = 67, kTravel = 68, kTraining = 69, kBarterExit = 70, kRepairExit = 71, kRecharge = 72,
        kRechargeExit = 73, kTrainingExit = 74, kObserveCombat = 75, kNoticeCorpse = 76, kTimeToGo = 77, kGoodBye = 78,
        kHello = 79, /* 80-89 */ kSharedInfo = 90, /* 91-102 */ };
    stl::enumeration<TopicFlag, std::uint8_t>     topicFlags;  // 0
    stl::enumeration<DIALOGUE_TYPE, std::uint8_t> type;        // 1
    stl::enumeration<Subtype, std::uint16_t>      subtype;     // 2
};
class TESTopic : public TESForm, public TESFullName   // FORMTYPE = FormType::Dialogue
{
    [[nodiscard]] float GetPriority() const;
    DIALOGUE_DATA      data;                     // 30 - DATA
    std::uint32_t      priorityAndJournalIndex;  // 34 - PNAM
    BGSDialogueBranch* ownerBranch;              // 38 - BNAM
    TESQuest*          ownerQuest;               // 40 - QNAM
    TESTopicInfo**     topicInfos;               // 48 - infoTopics[infoCount]
    std::uint32_t      numTopicInfos;            // 50 - TIFC
    std::uint32_t      firstFileOffset;          // 54
    BSFixedString      formEditorID;             // 58
};
```
`TESFullName::GetFullName()` on a TESTopic = the player prompt (FULL). `DIALOGUE_TYPE` (https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/D/DialogueTypes.h) **[V]**: `kPlayerDialogue = 0, kCommandDialogue = 1, kBranchedTotal = 2, kSceneDialogue = 2, kCombat = 3, kFavors = 4, kDetection = 5, kService = 6, kMiscellaneous = 7, kTotal = 8`.

### 2.3 `RE::BGSDialogueBranch`
URL: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/B/BGSDialogueBranch.h **[V]**
```cpp
enum class Flag { kNone = 0, kTopLevel = 1 << 0, kBlocking = 1 << 1, kExclusive = 1 << 2 };
stl::enumeration<Flag, std::uint32_t> flags;          // 20 - DNAM
TESQuest*                             quest;          // 28 - QNAM
TESTopic*                             startingTopic;  // 30 - SNAM
DIALOGUE_TYPE                         type;           // 38 - TNAM
```

### 2.4 `RE::MenuTopicManager`
URLs: NG https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/M/MenuTopicManager.h ; newer names in https://raw.githubusercontent.com/alandtse/CommonLibVR/ng/include/RE/M/MenuTopicManager.h and https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/M/MenuTopicManager.h **[V]**
```cpp
class MenuTopicManager : public BSTSingletonSDM<MenuTopicManager>, public BSTEventSink<MenuOpenCloseEvent>, public BSTEventSink<PositionPlayerEvent>
{
    struct Dialogue
    {
        BSString                               topicText;        // 00
        bool                                   unk10, unk11;     // 10, 11
        bool                                   unk12;            // 12 - data.topic->formID == 0xFD || data.topic->formID == 0x118
        BSSimpleList<DialogueResponse*>        responses;        // 18
        TESQuest*                              parentQuest;      // 28
        TESTopicInfo*                          parentTopicInfo;  // 30
        TESTopic*                              parentTopic;      // 38
        BSSimpleList<DialogueResponse*>::Node* currentResponse;  // 40
        std::uint8_t                           unk48;            // 48
        bool                                   neverSaid;        // 49
        TESTopic*                              unk50;            // 50
    };                                                           // sizeof == 0x58
    static MenuTopicManager* GetSingleton();                     // RELOCATION_ID(514959, 401099)
    bool IsCurrentSpeaker(const ObjectRefHandle& a_handle) const { return menuOpen && speaker == a_handle; }   // po3/VR forks only

    BSSimpleList<Dialogue*>::Node* selectedResponseNode;  // 18
    BSSimpleList<Dialogue*>*       dialogueList;          // 20
    std::uint64_t                  unk28;                 // 28
    TESTopicInfo*                  rootTopicInfo;         // 30
    Dialogue*                      lastSelectedDialogue;  // 38
    REX::W32::CRITICAL_SECTION     criticalSection;       // 40
    ObjectRefHandle                speaker;               // 68
    ObjectRefHandle                lastSpeaker;           // 6C - used if the dialogue menu was closed but the NPC is still talking
    TESTopicInfo*                  currentTopicInfo;      // 70 - only valid when the NPC is talking
    TESTopicInfo*                  lastTopicInfo;         // 78
    BSTArray<BGSDialogueBranch*>   blockingBranches;      // 80
    BSTArray<BGSDialogueBranch*>   topLevelBranches;      // 98
    bool                           isGreetingPlayer;      // B0
    bool                           menuOpen;              // B1   (NG: unkB1)
    bool                           forceGoodbye;          // B2   (NG: isSayingGoodbye)
    bool                           shutMenu;              // B3
    bool                           rumorTopicAdded;       // B4
    bool                           waitingToAdvance;      // B5
    bool                           canSkip;               // BA
    BSTArray<TESTopic*>            unkC0;                 // C0
};                                                        // sizeof == 0xD8
```
`RE::DialogueMenu` (https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/D/DialogueMenu.h) **[V]**: `MENU_NAME = "Dialogue Menu"`, comment "menuDepth = 3, flags = kUpdateUsesCursor | kDontHideCursorWhenTopmost, context = kMenuMode"; overrides `Accept(CallbackProcessor*)` // 01 and `ProcessMessage(UIMessage&)` // 04.

### 2.5 `RE::DialogueItem` / `RE::DialogueResponse` / `RE::ExtraSayToTopicInfo`
URL: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/D/DialogueItem.h (DialogueResponse is declared in the same header; `RE/D/DialogueResponse.h` is 404) **[V]**
```cpp
class DialogueResponse
{
    BSString                                     text;              // 00
    stl::enumeration<EmotionType, std::uint32_t> animFaceArchType;  // 10
    std::uint16_t                                percent;           // 14
    BSFixedString                                voice;             // 18  (voice file path)
    TESIdleForm*                                 speakerIdle;       // 20
    TESIdleForm*                                 listenIdle;        // 28
    BGSSoundDescriptorForm*                      voiceSound;        // 30
    bool                                         useEmotion;        // 38
    bool                                         soundLip;          // 39
};                                                                  // 0x40
class DialogueItem : public BSIntrusiveRefCounted
{
    DialogueItem(TESQuest* a_quest, TESTopic* a_topic, TESTopicInfo* a_topicInfo, Actor* a_speaker);   // Ctor = RELOCATION_ID(34413, 35220)
    BSSimpleList<DialogueResponse*>        responses;        // 08
    BSSimpleList<DialogueResponse*>::Node* currentResponse;  // 18
    TESTopicInfo*                          info;             // 20
    TESTopic*                              topic;            // 28
    TESQuest*                              quest;            // 30
    TESObjectREFR*                         speaker;          // 38
    ExtraSayToTopicInfo*                   extraData;        // 40
};                                                           // 0x48
```
`ExtraSayToTopicInfo` (https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/E/ExtraSayToTopicInfo.h) **[V]**: `TESTopic* topic; // 10`, `bool voicePaused; // 18`, `float subtitleSpeechDelay; // 1C`, `BGSDialogueBranch* exclusiveBranch; // 20`, `BSSoundHandle sound; // 28`, `DialogueItem* item; // 38`.
On-demand response text: `info->GetDialogueData(actor).responses.front()->text.c_str()` - used in production by Predictable Persuasion (`getResponseText`, section 5) **[V]**.

### 2.6 `RE::TESCondition`
URLs: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/T/TESCondition.h and `.../src/RE/T/TESCondition.cpp` **[V]**
```cpp
enum class CONDITIONITEMOBJECT { kSelf = 0, kTarget = 1, kRef = 2, kCombatTarget = 3, kLinkedRef = 4, kQuestAlias = 5, kPackData = 6, kEventData = 7, kCommandTarget = 8 };

struct CONDITION_ITEM_DATA {
    struct Flags { bool isOR: 1; bool usesAliases: 1; bool global: 1; bool usePackData: 1; bool swapTarget: 1; OpCode opCode: 3; };
    GlobalOrFloat   comparisonValue;  // 08   (union { TESGlobal* g; float f; })
    ObjectRefHandle runOnRef;         // 10 - kReference
    std::uint32_t   dataID;           // 14
    FUNCTION_DATA   functionData;     // 18   (function : uint16 FunctionID, void* params[2])
    Flags           flags;            // 30
    stl::enumeration<CONDITIONITEMOBJECT, std::uint8_t> object;  // 31
};
struct ConditionCheckParams {
    constexpr ConditionCheckParams(TESObjectREFR* a_actionRef, TESObjectREFR* a_targetRef);   // quest, questStartEvent, unk20, packageDataList = nullptr
    TESObjectREFR*      actionRef;        // 00
    TESObjectREFR*      targetRef;        // 08
    TESQuest*           quest;            // 10
    BGSStoryEvent*      questStartEvent;  // 18
    void*               unk20;            // 20
    bool                unk28;            // 28
    BGSPackageDataList* packageDataList;  // 30
};
struct TESConditionItem {                 // CTDA
    bool operator()(ConditionCheckParams& a_solution) const;
    bool IsTrue(ConditionCheckParams& a_solution) const;          // RELOCATION_ID(29090, 29924)
    TESConditionItem* next;  // 00
    CONDITION_ITEM_DATA data; // 08
};
class TESCondition {
    bool operator()(TESObjectREFR* a_actionRef, TESObjectREFR* a_targetRef) const;
    bool IsTrue(TESObjectREFR* a_actionRef, TESObjectREFR* a_targetRef) const;  // RELOCATION_ID(29074, 29888)  "Perk fragments will short circuit"
    TESConditionItem* head;  // 0
};
```
Dialogue-relevant `FUNCTION_DATA::FunctionID` values **[V]**: `kGetActorValue = 14`, `kMenuMode = 36`, `kGetItemCount = 47`, `kGetGold = 48`, `kGetTalkedToPC = 50`, `kSay = 51`, `kSayTo = 52`, `kGetQuestRunning = 56`, `kSetStage = 57`, `kGetStage = 58`, `kGetStageDone = 59`, `kGetIsID = 72`, `kGetGlobalValue = 74`, `kStartConversation = 86`, `kAddTopic = 88`, `kIsIntimidatedByPlayer = 116`, `kIsGreetingPlayer = 123`, `kIsTalking = 141`, `kGetTalkedToPCParam = 172`, `kGetEquipped = 182`, `kGetPersuasionNumber = 225`, `kIsInDialogueWithPlayer = 249`, `kGetTotalPersuasionNumber = 315`, `kShowBarterMenu = 371`, `kIsBribedbyPlayer = 402`, `kGetRelationshipRank = 403`, `kGetDialogueEmotion = 434`, `kGetIsAliasRef = 566`, `kGetVMQuestVariable = 629`, `kGetVMScriptVariable = 630`, `kSetFavorState = 634`, `kIsInFavorState = 635`, `kGetBribeAmount = 653`, `kGetBribeSuccess = 654`, `kGetIntimidateSuccess = 655`. A "GetIsPlayerInDialogue"-style function: NOT FOUND in the enum.

Evaluating with quest context **[I]**: construct `RE::ConditionCheckParams params(speaker, player); params.quest = info->parentTopic->ownerQuest;` and walk `info->objConditions.head` calling `item->IsTrue(params)`, combining with CK OR-block semantics. Working reference implementation of the OR/AND walk and Random/RandomEnd grouping: OStim `GameDialogue::sayAs` (section 5.7).

### 2.7 `RE::TESQuest` dialogue containers
URL: https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/T/TESQuest.h **[V]**
```cpp
using DT = DIALOGUE_TYPE;
BSTArray<BGSBaseAlias*>                              aliases;                                  // 058
TESCondition                                         objConditions;                            // 108   (quest dialogue conditions)
TESCondition                                         storyManagerConditions;                   // 110
BSTHashMap<BGSDialogueBranch*, BSTArray<TESTopic*>*> branchedDialogue[DT::kBranchedTotal];     // 118   [0]=player dialogue, [1]=command dialogue
BSTArray<TESTopic*>                                  topics[DT::kTotal - DT::kBranchedTotal];  // 178   scene, combat, favors, detection, service, misc
BSTArray<BGSScene*>                                  scenes;                                   // 208
std::uint16_t                                        currentStage;                             // 228
bool IsRunning() const;  bool IsEnabled() const;  bool IsCompleted() const;  std::uint16_t GetCurrentStageID() const;
bool EnsureQuestStarted(bool& a_result, bool a_startNow);  bool Start();  void Stop();
```

### 2.8 Events and VM
- `RE::TESTopicInfoEvent` (https://raw.githubusercontent.com/alandtse/CommonLibVR/ng/include/RE/T/TESTopicInfoEvent.h; NG path `RE/T/TESTopicInfoEvent.h` is 404 on CharmedBaryon main) **[V]**:
```cpp
struct TESTopicInfoEvent {
    enum class TopicInfoEventType { kTopicBegin = 0, kTopicEnd };
    BSTSmartPointer<REFREventCallbacks::IEventCallback> callback;         // 00
    NiPointer<TESObjectREFR>                            speakerRef;       // 08
    FormID                                              topicInfoFormID;  // 10
    REX::EnumSet<TopicInfoEventType, std::uint32_t>     type;             // 14
    std::uint16_t                                       stage;            // 18
};  // 0x20
```
- `RE::SkyrimVM` is a `BSTEventSink<TESTopicInfoEvent>` (base at 0x140) and owns `SkyrimScript::FragmentSystem fragmentSystem; // 0478` **[V]** https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/S/SkyrimVM.h. `FragmentSystem` is five un-reversed `BSTHashMap<UnkKey, UnkValue>` + spinlocks (https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/F/FragmentSystem.h) **[V]** - i.e. the runtime INFO -> (script, fragment function) map is NOT exposed by CommonLib.
- AIAgent.dll already sinks this event: RTTI strings `.?AV?$BSTEventSink@UTESTopicInfoEvent@RE@@@RE@@`, and log formats `infonpc|{}|{}|{}` / `infonpc_close|{}|{}|{}` **[V]** (strings of `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll`); server accepts them as fast commands **[V]** `HerikaServer\main.php:227`.
- Fragment shape, local example **[V]** `F:\Modlists\LoreRim\mods\CAM - Companions at Mirmulnir\Scripts\Source\TIF__CAM_BribeDialogScript.psc:3-23`: `Scriptname TIF__CAM_BribeDialogScript Extends TopicInfo Hidden`, `Function Fragment_0(ObjectReference akSpeakerRef)` (here the OnEnd one) and `Function Fragment_1(ObjectReference akSpeakerRef)` (here OnBegin). Fragment index does not tell begin/end; that mapping is in VMAD: INFO fragment header = `unknown(int8), flags(uint8: 0x1 Has Begin Script, 0x2 Has End Script), fileName(wstring), fragments[]{unknown(int8), scriptName(wstring), fragmentName(wstring)}` **[V]** https://en.uesp.net/wiki/Skyrim_Mod:Mod_File_Format/VMAD_Field
- CK wiki (search snippet of `ck.uesp.net/wiki/Topic_Info_Fragments` / `Topic_Info`): "Begin Script runs when the Info is played, when the Actor starts saying the line." / "End Script runs when the Info is finished, when the Actor finishes speaking." **[V-snippet]**

### 2.9 Actor-side dialogue virtuals
`TESObjectREFR` (https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/T/TESObjectREFR.h) **[V]**:
```cpp
virtual bool SetDialogueWithPlayer(bool a_flag, bool a_forceGreet, TESTopicInfo* a_topic);  // 41
virtual bool UpdateInDialogue(DialogueResponse* a_response, bool a_unused);                 // 4C
[[nodiscard]] virtual BGSDialogueBranch* GetExclusiveBranch() const;                        // 4D
virtual void SetExclusiveBranch(BGSDialogueBranch* a_branch);                               // 4E
virtual void PauseCurrentDialogue();                                                        // 4F   (po3 fork: StopCurrentDialogue)
```
`Actor` (https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/A/Actor.h) **[V]**: `void InitiateDialogue(Actor* a_target, PackageLocation* a_loc1, PackageLocation* a_loc2);`, `void EndDialogue();`, `void AllowPCDialogue(bool a_talk);`, `void AllowBleedoutDialogue(bool a_canTalk);`, `bool QSpeakingDone() const; // 107`, `void SetSpeakingDone(bool a_set); // 108`, runtime `float voiceTimer; // 108`. (po3 fork also lists `bool CanTalkToPlayer() const`.)
Scene dialogue action: `BGSSceneActionDialogue { TESTopic* topic; // 20 - DATA ... }` **[V]** https://raw.githubusercontent.com/powerof3/CommonLibSSE/dev/include/RE/B/BGSSceneActionDialogue.h

### 2.10 How the engine builds the player's topic list
Verified pieces:
- CK wiki "Dialogue Branch" (search snippet) **[V-snippet]**: "The Starting Topic of a Top-Level Branch will appear in the NPC's initial topic list (assuming there is a valid info in the Starting Topic for that NPC)." / "Blocking Branches are used to override an NPC's normal topic list. When an NPC has a valid info in a Blocking Branch's Starting Topic, the NPC will use that info as his greeting (and Hello, unless the info is conditioned only to be valid in the dialogue menu, such as with IsInDialogueWithPlayer)." / "Normal branches will not show up in the topic list unless they are explicitly linked from another branch ... or for branches which are triggered from ForceGreet packages."
- Engine functions known from Dynamic Dialogue Replacer's hooks (https://raw.githubusercontent.com/KrisV-777/Dynamic-Dialogue-Replacer/main/src/Hooks/Hooks.cpp) **[V]**:
```cpp
// the function that appends a topic to MenuTopicManager::dialogueList
int64_t AddTopic(RE::MenuTopicManager* a_this, RE::TESTopic* a_topic, int64_t a_3, int64_t a_4);   // REL::ID(35303)  (AE id; comment in source: "// add SE address")
// its two call sites that DDR patches:
REL::VariantID{ 34460, 35287, 0x05766F0 } + REL::Relocate(0xFA, 0x154)
REL::VariantID{ 34477, 35304, 0x05778D0 } + REL::Relocate(0x79, 0x6C)
// DDR's typed view of the hook: AddTopic(MenuTopicManager* a_this, TESTopic* a_topic, TESTopic* a_activeTopic, uint64_t a_4)
// response construction:
int64_t PopulateTopicInfo(int64_t a_1, RE::TESTopic* a_2, RE::TESTopicInfo* a_3, RE::Character* a_speaker, RE::TESTopicInfo::TESResponse* a_5);  // REL::RelocationID(34429, 35249)
//   +0x61 : char* SetSubtitle(RE::DialogueResponse* a_response, char* a_text, int32_t a_3)
//   +0xDE : bool ConstructResponse(TESTopicInfo::TESResponse* a_response, char* a_filePath, BGSVoiceType* a_voiceType, TESTopic* a_topic, TESTopicInfo* a_topicInfo)
```
  DDR's own validity test for a topic is the simple loop `for each info in a_topic->topicInfos: if (info->objConditions.IsTrue(target, RE::PlayerCharacter::GetSingleton())) { hasValidResponse = true; break; }` **[V]**.
- Click handling, from powerof3 Dialogue History (https://raw.githubusercontent.com/powerof3/DialogueHistory/master/src/Hooks.cpp) **[V]**:
```cpp
REL::Relocation<std::uintptr_t> topicClicked(RELOCATION_ID(50615, 51509), 0x5A);          // call inside the "TopicClicked" Scaleform callback
struct UpdateSelectedResponse { static void thunk(RE::MenuTopicManager* a_this, bool a_unk01); };   // after it: a_this->selectedResponseNode->item->topicText is the chosen prompt
REL::Relocation<std::uintptr_t> showSub_0(RELOCATION_ID(19119, 19521), 0x2B2);            // subtitle display call sites
REL::Relocation<std::uintptr_t> showSub_1(RELOCATION_ID(36543, 37544), OFFSET(0x8EC, 0x8C2));
```
  Mantella-vanilla-dialogue uses the same two subtitle sites with thunk `static void thunk(RE::SubtitleManager* a_this, RE::TESObjectREFR* a_speaker, const char* a_subtitle, bool a_alwaysDisplay)` **[V]**.
Inferred flow **[I]**: on dialogue start the engine walks running quests' `branchedDialogue[kPlayerDialogue]`, keeps branches flagged kTopLevel/kBlocking into `topLevelBranches`/`blockingBranches`, and for each starting topic with a first-passing INFO calls `AddTopic` (site 34460/35287). After an NPC response finishes it reads the INFO's TCLT links from file and calls `AddTopic` for each linked topic (site 34477/35304), or auto-continues (Invisible Continue), or closes (Goodbye). Function names for 34460/34477 are not published: NOT FOUND (looked in CommonLibSSE-NG, powerof3 dev, CommonLibVR ng headers, DDR, DialogueHistory).

### 2.11 Known ways for an SKSE plugin to ...
**(a) enumerate available player topics WITHOUT the menu open:** no published implementation - NOT FOUND (searched GitHub/Nexus for MenuTopicManager users: DDR, DialogueHistory, PredictablePersuasion, mantella-vanilla-dialogue, DSN, SmartTalk; all operate during an open session). Feasible by re-implementation **[I]**: iterate `TESDataHandler` quests -> `IsRunning()` -> `branchedDialogue[0]` -> branches with `kTopLevel`/`kBlocking` -> `startingTopic` -> first INFO whose conditions pass (2.6 walk with `params.quest`), honouring `saidOnce && (flags & 0x4)` and Random groups. Result is an approximation (conditions that require the menu state evaluate false; exclusive branches/favor state not modelled).
**(b) execute a chosen INFO "as if clicked":**
- With a dialogue session open **[V technique]**: Scaleform path used by Dragonborn Speaks Naturally (section 5.1): `TopicList.SetSelectedTopic`, `TopicList.doSetSelectedIndex`, `TopicList.UpdateList`, then `DialogueMenu_mc.onSelectionClick` -> SWF calls `GameDelegate.call("TopicClicked", ...)` -> engine handler `RELOCATION_ID(50615, 51509)` -> `MenuTopicManager` update. Native alternative **[I]**: set `selectedResponseNode` to the wanted node and call the function at the `+0x5A` call site (resolve the rel32 target at runtime); untested.
- Without a session: NOT FOUND. Options are `Say/SayTo` (section 3, unreliable for fragments) or synthesising `TESTopicInfoEvent` begin/end through `ScriptEventSourceHolder` plus manual flag bookkeeping **[I]**, which skips everything else the engine does (links, goodbye, walk-away, favor points, scene start).

---

## 3. Do `ObjectReference.Say(Topic)` / CHIM `SayTo` evaluate conditions and run fragments?

- Signature **[V]** `Skyrim Script Extender (SKSE64)\Scripts\Source\ObjectReference.psc:517`: `Function Say(Topic akTopicToSay, Actor akActorToSpeakAs = None, bool abSpeakInPlayersHead = false) native`
- CK wiki text via BellCube (https://papyrus.bellcube.dev/skyrimse/script/objectreference/function/say/) **[V]**:
  - "Causes this reference to speak a topic as if it were the specified actor."
  - `akActorToSpeakAs`: "The actor this object reference should use to speak as (passed to topic conditions and used to select voices)." -> **conditions ARE evaluated**; the engine picks the INFO.
  - Notes: "If used on an actor and that actor attempts to initiate normal dialogue while saying something through Say(), the game will crash to desktop"; "ObjectReferences aren't exempt from the need for a SEQ file."
  - "Don't put important statements in Topic Info fragment scripts of a say topic - they are often not processed correctly" -> **fragments are attempted but documented as unreliable.**
- Console `SayTo` exists as script function `kSayTo = 52` (2.6). OStim implements its `OActorUtil.SayTo` by compiling the console command **[V]** https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/GameAPI/GameDialogue.cpp :
```cpp
script->SetCommand("SayTo " + std::format("{:x}", target.getFormID()) + " " + std::format("{:x}", getFormID()));
script->CompileAndRun(speaker.form);
```
- CHIM `int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native` (`AIAgentFunctions.psc:88`); only in-repo use is `AIAgentFunctions.SayTo(npc,Game.GetPlayer(),stopSinging)` (`AIAgentPapyrusFunctions.psc:1327`); DLL log format `[PAPYRUS] Papyrus::SayTo - Source: {} (0x{:08x}), Target: {} (0x{:08x}), Topic: {} (0x{:08x})` **[V]**. Internal mechanism (console vs direct call): NOT FOUND (closed source; AIAgent.dll does contain the string `ExecuteCommand`).
- Smart Talk (installed) documents the lifecycle coupling **[V]** `Smart Talk (Dialogue Menu Enhancer)\SKSE\Plugins\SmartTalk.ini:109-116`: "skipping a dialogue too early may prevent the fragment from running, potentially causing the dialogue to repeat."
- What Say/SayTo certainly do NOT do **[I]**: they are not a dialogue session - no `dialogueList`, no TCLT follow-ups, no Goodbye/walk-away handling, `IsInDialogueWithPlayer` stays false, no "Actor Dialogue" story event.
Conclusion: usable for barks/acks; not a trustworthy way to apply quest effects. Needs in-game test E1 (Open questions) before any reliance.

---

## 4. Speech checks as data

- Difficulty thresholds **[V]** https://en.uesp.net/wiki/Skyrim:Speech : "Very Easy (10)", "Easy (25/18)", "Average (50/35)", "Hard (75/53)", "Very Hard (100/70)" (second number with the Persuasion perk, "Persuasion attempts are 30% easier.").
- Bribe cost formula **[V]** same page: `bribeValue = (v11 ^ fBribeCostCurve) * (fBribeScale * NPC Morality)` with `v11 = ((NPC Actor Level * fBribeNPCLevelMult) + Player Level) - (((Player Speech - NPC Speech) - 100.0) * fBribeSpeechCraftMult)`, `NPC Morality = (Morality AV * fBribeMoralityMult) + 1.0`.
- Intimidate **[V]** same page: "Player's Scariness = Player Level x (1.0 + max(-1.0, (Player Speech - NPC Speech) / 100)) ^ fIntimidateSpeechcraftCurve"; "NPC's Scariness = NPC Level x fIntimidateConfidenceMult___"; Intimidation perk "twice as likely".
- Condition encoding, as detected at runtime by Predictable Persuasion (https://raw.githubusercontent.com/JonathanFeenstra/PredictablePersuasion/main/src/Hooks.cpp) **[V]**:
  - Persuade = condition `FunctionID::kGetActorValue` with `params[0] == ActorValue::kSpeech` and `OpCode::kGreaterThanOrEqualTo`; threshold = `data.flags.global ? data.comparisonValue.g->value : data.comparisonValue.f` (i.e. usually a global such as SpeechEasy); typically OR-ed with a following `kGetEquipped` on formlist `TGAmuletOfArticulationList`.
  - Bribe = `FunctionID::kGetBribeSuccess`; prompt FULL contains the token `<bribecost>`.
  - Intimidate = `FunctionID::kGetIntimidateSuccess`.
  - Success/failure are separate INFOs under the same topic; the first passing INFO wins.
- CK wiki tutorial (search snippet of `ck.uesp.net/wiki/Tutorial:_Dialogue_Speech_Checks`) **[V-snippet]**: add a property "'pFDS' (short for Favor Dialogue Script) with the type 'FavorDialogueScript'" set to quest `DialogueFavorGeneric`; comparison ">=" against "predetermined constant variables like SpeechVeryEasy or SpeechEasy".
- Success fragments, local examples **[V]**:
  - `CAM - Companions at Mirmulnir\Scripts\Source\TIF__CAM_PersuadeDialogScript.psc:20`: `(DialogueFavorGeneric As FavorDialogueScript).Persuade(AkSpeaker)`
  - `...\TIF__CAM_BribeDialogScript.psc:20`: `(DialogueFavorGeneric As FavorDialogueScript).Bribe(AkSpeaker)` (OnBegin)
  - `Caught Red Handed - Quest Expansion\Source\scripts\IntimidateTythis.psc:9-10`: `pFDS.Intimidate(akSpeaker)` then `GetOwningQuest().SetStage(50)`
  - CHIM itself: `Quest dialogueFavorGeneric = Game.GetForm(0x0005A6DC) as Quest` ... `favorDialogue.Brawl(npc)`; brawl quest `Game.GetForm(0x00047AE6)` (`AIAgentAIMind.psc:1117-1128`).
- Actor natives (BellCube) **[V]** https://papyrus.bellcube.dev/skyrimse/script/actor/function/getbribeamount/ : `int function GetBribeAmount() Native` "Returns the amount of gold required to bribe this actor."; related `SetBribed`, `IsBribed`, `SetIntimidated`, `IsIntimidated`.
- Bodies of `FavorDialogueScript.Persuade/Bribe/Intimidate` (gold removal, skill XP): NOT FOUND verbatim (vanilla source not extracted locally; GitHub mirror 404; CK wiki 403). Gold removal being inside `Bribe()` is therefore **[I]**. Irrelevant if the real INFO is executed - the fragment runs the real script.
Implication: speech checks need no special code under "execute the real INFO"; the engine picks success vs failure INFO. For the LLM's benefit the native layer can pre-compute pass/fail exactly as Predictable Persuasion does (`conditionItem->IsTrue(checkParams)`).

---

## 5. Prior art

5.1 **Dragonborn Speaks Naturally** (voice-selects vanilla topics; menu open). https://raw.githubusercontent.com/YihaoPeng/DragonbornSpeaksNaturally/master/dsn_plugin/dsn_plugin/Hooks.cpp **[V]**: hooks the GFx `Invoke`; on command `"PopulateDialogueList"` reads prompts from args (`for (int j = 1; j < argc - 1; j = j + 3)` - triples of text / isNew / index); selects with
```
_level0.DialogueMenu_mc.TopicList.SetSelectedTopic   ("%d", index)
_level0.DialogueMenu_mc.TopicList.doSetSelectedIndex ("%d", index)
_level0.DialogueMenu_mc.TopicList.UpdateList
_level0.DialogueMenu_mc.onSelectionClick             ("%d", 1.0)
```
5.2 **Dynamic Dialogue Replacer** (KrisV-777): `AddTopic` / `PopulateTopicInfo` hooks (2.10); rewrites `Dialogue::topicText` inside a `DialogueMenu::ProcessMessage` vfunc (0x4) hook by iterating `MenuTopicManager::dialogueList` on `kShow`/`kUpdate` **[V]** `.../src/Hooks/DialogueMenuEx.cpp`. Papyrus API: `DynamicDialogueReplacer.AddReplacementTopic(FormID, string)`, `RemoveReplacementTopic` **[V]** `.../src/Papyrus.h`. Not installed in LoreRim.
5.3 **Predictable Persuasion** (JonathanFeenstra): per-topic speech-check prediction from `dialogueList` + INFO conditions + `GetDialogueData` (section 4). Source comment: "evaluating the full responseInfo->objConditions sometimes returns false negatives, so only the speech checks are evaluated here." **[V]**
5.4 **powerof3 Dialogue History**: `topicClicked` call-site hook and subtitle hooks (2.10) **[V]**.
5.5 **Mantella**: core Mantella does not drive vanilla topics. Companion SKSE plugin https://github.com/mikastamm/mantella-vanilla-dialogue makes the LLM aware of vanilla exchanges: on subtitle hook it reads `RE::MenuTopicManager::GetSingleton()->lastSelectedDialogue->topicText` and concatenates `dialogue->responses[*]->text` - "As soon as a dialogue item is selected by the player, all the sentences get sent to Mantella that the NPC will say in response." **[V]**. Mantella issue #628 (Dec 2025, no maintainer reply) proposes an LLM quest-dialogue layer that calls `QuestX.SetStage(10)` directly - no topic execution **[V]** https://github.com/art-from-the-machine/Mantella/issues/628
5.6 **SkyrimNet** README: "Vanilla dialogue trees. SkyrimNet handles freeform conversation; vanilla quest dialogue trees still go through Skyrim's normal system." / "Integrating the two is on the roadmap." **[V]** https://github.com/MinLL/SkyrimNet-GamePlugin
5.7 **OStim** `GameDialogue::sayAs` - a complete manual INFO selector (Random / RandomEnd groups, OR-block walk with `TESConditionItem::IsTrue(params)`, shared-info resolution `while (info->dataInfo) info = info->dataInfo;`), and the voice path rule `voice/<plugin>/<voiceTypeEditorID>/<questEDID[0..10]>_<topicEDID[0..25-len]>_<formid 8 hex, masked 0xFFF for light plugins>_1.fuz`, played through console `SpeakSound` **[V]** (URL in section 3).
5.8 **DBVO**: v1 shipped a patched `dialoguemenu.swf`; "Dragonborn Voice Over 2" is an SKSE plugin that "does not require any patched dialoguemenu.swf" (Nexus descriptions via search; pages 403) **[V-snippet]**. Closed source: mechanism NOT FOUND.
5.9 **CHIM itself** (installed; highest priority in `profiles\Ultra\modlist.txt:7`, so its `Interface\dialoguemenu.swf` wins over `Norden UI 16x9`, line 147) **[V]**. Strings inside CHIM's SWF (decompressed read-only): `PopulateDialogueList`, `PopulateDialogueLists`, `SetSpeakerName`, `onItemSelect`, `SetSelectedTopic`, `doSetSelectedIndex`, `onSelectionClick`, `onCancelPress`, `CloseMenu`, `startTopicClickedTimer`, `PlayMenuTopic`, `TopicClicked`, `skse`, `SendModEvent`, `topicIsNew`, `topicIndex`, `TOPIC_LIST_SHOWN`, `TOPIC_CLICKED`. CHIM holds the click, sends mod event `PlayMenuTopic`, plays player TTS, then releases with `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")` (`AIAgentPapyrusFunctions.psc:1170-1195`; note line 1097: "Traditional dialogue Player TTS is handled in native code."). Setting key: `_player_tts_traditional_dialogue`. Server fast commands include `infonpc`, `infonpc_close`, `player_menu_tts_prefetch`, `player_menu_tts_play` (`main.php:227-230`).
5.10 **CHIM quest progression engine** (not dialogue execution): `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentQuestProgressionBridge.psc` - "Static bridge used by the CHIM SKSE plugin to apply server-approved quest actions." Functions: `SetQuestStage(int questFormId, int stage)`, `SetQuestObjectiveCompleted`, `SetQuestObjectiveDisplayed`, `SetQuestStageObjective`, `FailAllQuestObjectives`, `StartQuest`, `StartQuestStageObjective`, `ExecuteConsoleCommand(String command)` (uses `ConsoleUtil.ExecuteCommand`), `ExecuteConsoleCommandSequence(String commands)` ("||"-separated), `StopQuest`, `StartScene(int sceneFormId)`, `SetActorValue`, `SetActorGhost`, `EvaluateActorPackage`, `RemoveItemFromPlayer`, `AddItemToPlayer`, `EnableReference`, `SetActorRelationshipToPlayer` **[V]**. Server: `HerikaServer\lib\chim_quest_engine.php` (feature flag `CHIM_AI_QUEST_PROGRESSION`, lines 111-170; bundled data `data/skyrim_quest_definitions.json`, 1,321,722 bytes, hand-authored "beats" with keyword/intent dialogue triggers). DLL strings: `[QuestProgression] Queued ActorDialogue story event actor={:08X} target={:08X} ...`, `actor_dialogue_start_quest_stage_objective`. It advances stages directly and does NOT run INFO fragments.
5.11 **Smart Talk** (installed): statically analyses INFO scripts for `Start()/Stop()/SetStage()` to tag quest topics (`SmartTalk.ini:23-29`) - evidence that parsing TIF `.pex` for quest effects is practical **[V]**.
5.12 Any SKSE plugin that exposes available topics to Papyrus or an external process: **NOT FOUND** (DSN exposes prompts to its external speech-recogniser process only, menu open).

---

## 6. Hard limits

Strategy keys: **S1** = headless real dialogue session (engine-driven, menu hidden). **S2** = native re-implementation without a session. **S3** = Say/SayTo.

| # | Limit | Feasible? | API needed | Risk | Proposed handling |
|---|---|---|---|---|---|
| 1 | Scripted Scene forms with dialogue actions | Yes (nothing to drive) | `BGSSceneActionDialogue::topic`; Papyrus `Actor.GetCurrentScene()`; PO3 `GetActorsInScene`, `IsActorInScene`; `TESSceneEvent` sinks | Low | Scenes run on their own; gate the glue while the NPC is in a scene; lines already reach CHIM via `infonpc`. Do not open a session on scene actors. |
| 2 | ForceGreet packages, blocking Hello/greeting | Yes under S1 | `MenuOpenCloseEvent` sink; `MenuTopicManager::blockingBranches`, `isGreetingPlayer`, `rootTopicInfo`; subtype `kForceGreet = 1`, `kHello = 79` | Medium | Engine opens the menu itself. Treat ANY Dialogue Menu open as a session: hide, read `dialogueList`, route the choice through conversation. Fallback: un-hide the vanilla menu on timeout. |
| 3 | Walk-away and Goodbye flags | Yes under S1 only | ENAM bits 0x0001, 0x0080, 0x0100; TWAT not in runtime struct; `forceGoodbye`/`shutMenu` | Medium | End sessions through the engine (`onCancelPress` / `UIMessageQueue kHide`), never `StopCurrentDialogue` mid-INFO. CK: "If the player backs out of this Info's Choice List, the Walk Away Topic will be spoken as dialogue ends." |
| 4 | Say once | S1 automatic; S2 manual | `TESTopicInfo::saidOnce` (0x3A), `ChangeFlags::kSaidOnce`, ENAM 0x0004, `timeUntilReset` | Low (S1) / Medium (S2) | Let the engine set it. CK quirk: Say Once on quests not Start Game Enabled "will be reset after exiting Skyrim". Pre-enumerator must skip `saidOnce && bit 0x4`. |
| 5 | Shared INFOs | Yes | `TESTopicInfo::dataInfo` (0x28); subtype `kSharedInfo = 90` | Low | Conditions/fragments/flags come from the referencing INFO, responses from the shared one; resolve `while (info->dataInfo)` only for text/voice. |
| 6 | Response text loaded on demand | Yes (native) | `GetDialogueData(speaker)` -> `DialogueItem::responses` -> `DialogueResponse::text`; Ctor `RELOCATION_ID(34413, 35220)`; `GetResponseList` `(25083, 25626)`; `LoadResponseText(TESFile*)` `(24985, 25491)` | Low-Medium | Native helper for previews; during a session read `Dialogue::responses`. Offline export as second source for the server. Main thread only. |
| 7 | Voiced vs silent lines / Fuz Ro D-oh | Yes | none (engine + installed `Fuz Ro D'oh.dll`, `Sound\Voice\Fuz Ro Doh\Stock_1..10.fuz`) | Low | Real lines keep real voice acting; silent lines get Fuz timing so End fragments still fire. Optional later: CHIM TTS for unvoiced lines. |
| 8 | Topics with empty prompts | Yes | `Dialogue::topicText`, `parentTopic->GetFullName()`; RNAM only offline | Medium | Single empty-prompt entry -> auto-select. Several -> label by predicted response (`GetDialogueData`) or offline RNAM. |
| 9 | Invisible-continue chains and linked topics (TCLT) | S1 automatic; S2 needs offline data | ENAM 0x0040; TCLT not in runtime struct; `AddTopic` site 34477/35304 | Low (S1) / High (S2) | After each response re-read `dialogueList` and push the new choice list to the server. CK: "When this Info is finished, the next Info will begin automatically; no player prompt will be displayed." |
| 10 | Service topics (barter, training, rent room, carriage) | Yes under S1 | Fragments: `akSpeaker.ShowBarterMenu()` (`Arena - Markarth Side Town\...\TIF__0500FB75.psc:9`), `Game.ShowTrainingMenu(akSpeaker)` (`Fists of Fury - Skyrim\...\TIF_FOFWindhelm_051FB0C1.psc:9`), `akspeaker.OpenInventory()` | Low | Real fragment opens the real menu visibly. BellCube note: ShowBarterMenu "will fail if the actor isn't actually loaded". Rent-room / carriage script names NOT VERIFIED here. On service-menu close decide continue vs end session. |
| 11 | Conditions depending on subject/target/run-on refs | S1 exact; S2 approximate | `CONDITIONITEMOBJECT`, `ConditionCheckParams::quest`, `TESConditionItem::IsTrue` `(29090, 29924)` | Medium | S1: engine evaluates. Pre-enumerator: `ConditionCheckParams(speaker, player)` + `quest = topic->ownerQuest`, OStim-style OR walk; label results "approximate". PO3 `EvaluateConditionList` is the Papyrus fallback without quest context. |
| 12 | Dialogue needing the player in dialogue-menu state | S1 yes; S2/S3 no | `kIsInDialogueWithPlayer = 249`, `kIsGreetingPlayer = 123`, `kMenuMode = 36`; `MenuTopicManager::menuOpen`; `Actor::SetDialogueWithPlayer` | Medium-High (UX) | S1 satisfies these genuinely. Costs: player locked in menu context with cursor (`kUpdateUsesCursor`), dialogue camera (`AlternateConversationCamera.dll` installed), CHIM checks (`[BORED] Avoiding bored event because player is in dialogue`, `_pause_dialogue_when_menu_open`). Keep sessions short; open only when an utterance maps to a real topic. |
| 13 | (extra) Story Manager "Actor Dialogue" events | S1 automatic | `Quest.OnStoryDialogue`; CHIM already synthesises them (`[QuestProgression] Queued ActorDialogue story event`) | Low | Rely on S1; note CHIM may also fire them - avoid double starts. |
| 14 | (extra) `GetTalkedToPC`, `neverSaid`, favor points | S1 automatic | `kGetTalkedToPC = 50`, `Dialogue::neverSaid`, ENAM 0x4000, `favorLevel` | Low | Engine bookkeeping stays consistent only if the real session runs. |

### Feasibility table

| Capability | Papyrus only (installed extenders) | Native needed? | Verdict |
|---|---|---|---|
| Know an NPC's topics with no session open | No (no enumerator); partial via offline INFO list + PO3 `EvaluateConditionList` per INFO (slow, no alias context) | Yes - read-only walker | Feasible, approximate by nature |
| Know the authoritative topic list in a session | Partial: prompts only, by reading SWF state with `UI.GetString` (entry fields `text`/`topicIsNew`/`topicIndex` exist in SWF strings) **[I, untested]**; no FormIDs | Yes for FormIDs (`dialogueList`) | Feasible, low risk |
| Start a session programmatically | Yes: `npc.Activate(Game.GetPlayer())` **[I - common modding practice, not source-verified here]** | Optional (`SetDialogueWithPlayer`) | Feasible |
| Hide the menu | Yes: `UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc._visible", false)` **[I, untested]** | Better natively (hide before first frame, cursor) | Feasible, UX risk |
| Select a topic | Yes: `UI.InvokeInt(... "TopicList.doSetSelectedIndex", i)` + `onSelectionClick` (DSN path; CHIM SWF has both names) | Optional | Feasible; must coexist with CHIM's click-hold gate |
| Run real INFO effects (fragments, stages, say-once, links, services) | Only via the engine (session) | No extra native if S1 | Feasible under S1; NOT reliably feasible under S2/S3 |
| Generic fragment invocation without a session | No | Yes, plus offline VMAD map (FragmentSystem un-reversed) | High risk - avoid |
| Observe INFO begin/end with FormIDs | No Papyrus event | Yes: `BSTEventSink<TESTopicInfoEvent>` (CHIM already has one; whether it forwards INFO FormIDs is unknown) | Feasible |

---

## Recommended architecture

**Principle: never re-implement the dialogue engine; re-skin it.** The real session is the only path where conditions (with alias context), say-once, Random, TCLT, invisible-continue, walk-away, goodbye, favor points, fragments, scene starts, service menus and story events are all correct for arbitrary LoreRim mods with zero per-quest data.

1. **Awareness (no session):** native read-only walker returns JSON of candidate top-level topics for a speaker: `{questFormID, questEDID, branchFormID, branchFlags, topicFormID, topicEDID, prompt, firstValidInfoFormID, infoFlags, saidOnce, speechCheck{type,required,passes}}`. Server injects prompts into CHIM context as "things the player can raise". Marked approximate.
2. **Intent match (server, PHP ext/ plugin):** match the player's utterance to a candidate prompt (or none). Below threshold the conversation stays pure CHIM.
3. **Execution (S1):** start session -> hide menu -> wait for greeting -> read authoritative `dialogueList` -> select by `parentTopic->formID` -> stream NPC responses (CHIM logs them via `infonpc`) -> when the list repopulates, send the follow-up choice list to the server for the next utterance -> finish via Goodbye INFO or engine-clean close (walk-away honoured).
4. **Offline export (data-driven, no plugin edits):** one-time xEdit/Mutagen dump of DIAL/INFO/DLBR for the load order (TCLT, RNAM, TWAT, ENAM, CTDA text, VMAD fragment names, strings) into the server DB for planning/labels only; never for execution.
5. **Fallback:** on any mismatch or timeout, un-hide the vanilla menu so the player is never soft-locked.

### Minimum native surface (proposed names - ours to define, nothing below exists today)
```
; Papyrus-callable natives
string Function GetCandidateTopicsJSON(Actor akSpeaker)      ; step 1, no session required, main thread
string Function GetSessionTopicsJSON()                       ; reads MenuTopicManager::dialogueList incl. FormIDs, neverSaid, predicted first response
bool   Function SelectSessionTopic(int aiTopicFormID)        ; Scaleform click path (or selectedResponseNode + 50615/51509 call target)
Function        SetDialogueMenuHidden(bool abHidden)         ; hide before first frame via MenuOpenCloseEvent sink; optional cursor suppression
bool   Function EndSession()                                 ; engine-clean close (walk-away / goodbye honoured)
string Function GetInfoResponsesJSON(int aiInfoFormID, Actor akSpeaker)   ; GetDialogueData wrapper
; native -> Papyrus mod events (or straight to the server)
GlueTopicListChanged, GlueTopicInfoBegin / GlueTopicInfoEnd (topicInfoFormID, speaker), GlueSessionOpened / GlueSessionClosed
```
Engine bindings required: `MenuTopicManager::GetSingleton` `(514959, 401099)`, `TESConditionItem::IsTrue` `(29090, 29924)`, `DialogueItem` Ctor `(34413, 35220)`, sinks for `MenuOpenCloseEvent` and `TESTopicInfoEvent`; optionally `AddTopic` AE `35303` and the `TopicClicked` site `(50615, 51509)+0x5A`. No trampolines are strictly required for the minimum set.
**What Papyrus can do alone (Phase 1 prototype, no DLL):** start session (`Activate`), hide (`UI.SetBool`), select by index (`UI.InvokeInt` + `onSelectionClick`), close (`onCancelPress`), detect open/close (`RegisterForMenu("Dialogue Menu")`), evaluate known INFOs (`PO3_SKSEFunctions.EvaluateConditionList`). Missing without a DLL: FormID identity of listed topics, no-session enumeration, INFO begin/end FormIDs, robust hiding. That gap is the justification for the SKSE DLL.

---

## Implications for the glue

1. Build on S1. Do not use `Say`/`SayTo` or direct `SetStage` for quest progression; reserve `SayTo` for cosmetic barks.
2. CHIM's patched `dialoguemenu.swf` is the active SWF. Programmatic clicks pass through its hold-and-release gate (`PlayMenuTopic` -> native -> `startTopicClickedTimer "off"`). Test with `_player_tts_traditional_dialogue` both 0 and 1; with 1 CHIM will voice the vanilla prompt in the player's TTS voice.
3. Decide coexistence with CHIM's quest engine (`CHIM_AI_QUEST_PROGRESSION`): if enabled it may `SetStage` the same quests from keyword "beats" and double-advance. Recommend disabling while the glue drives quests, or restricting the glue to quests absent from `skyrim_quest_definitions.json`.
4. Vanilla NPC lines already flow into CHIM's event log (`infonpc`), so LLM context stays coherent after a vanilla exchange with no extra work; the player's chosen prompt should be logged too (Mantella-vanilla-dialogue pattern: `lastSelectedDialogue->topicText`).
5. Use numeric ENAM bits (0x0001 Goodbye, 0x0040 Invisible Continue, 0x0080 Walk Away), never CommonLib's enumerator names.
6. Pin one CommonLib fork and re-verify `TESTopicInfo` tail layout for 1.6.1170 (NG 0x48 vs po3 AE 0x50). Only touch members at or below 0x44.
7. All engine reads (`dialogueList`, conditions, `GetDialogueData`) on the main thread via `SKSE::GetTaskInterface()->AddTask`; `MenuTopicManager` has a `criticalSection` at 0x40.
8. Hard gating for the intimacy feature is orthogonal but shares the matcher; keep them separate action types server-side.
9. Treat engine-opened sessions (ForceGreet, blocking greetings, guard arrest) with the same handler as glue-opened ones.
10. Always ship the un-hide fallback and a session watchdog (CHIM has the same idea: `[PlayerMenuTTS] Timed out waiting for selected dialogue topic; replaying original click`).

---

## Open questions (with the experiment that settles each)

- **E1** Does `Say()` / console `SayTo` / CHIM `SayTo` fire Begin/End fragments, set say-once, or honour Goodbye? Test topic with a fragment that writes a global; compare against a real click.
- **E2** Does `npc.Activate(player)` reliably open a session for non-ForceGreet NPCs at conversational distance under LoreRim (Requiem AI, followers, mounted, sitting)? Is native `SetDialogueWithPlayer(true, false, nullptr)` better?
- **E3** Can the Dialogue Menu be hidden before its first rendered frame from Papyrus, and does the cursor stay visible (`kUpdateUsesCursor`)? Does `AlternateConversationCamera.dll` still move the camera?
- **E4** Do CHIM push-to-talk / open-mic / chatbox inputs work while the (hidden) Dialogue Menu is open? What exactly does `_pause_dialogue_when_menu_open` pause?
- **E5** Do `UI.InvokeInt(... doSetSelectedIndex)` + `onSelectionClick` work against CHIM's SWF exactly as DSN's path did against vanilla? Is the arg to `onSelectionClick` needed?
- **E6** Is SWF `TopicList` entry data readable via `UI.GetString("Dialogue Menu", "_root.DialogueMenu_mc.TopicList.EntriesA.N.text")` (path is a guess; only the field names `topicIsNew`/`topicIndex` are verified)?
- **E7** Accuracy of the no-session walker versus the real list across 20+ LoreRim NPCs; which condition functions cause the misses.
- **E8** Does `GetDialogueData()` return text already rewritten by runtime text-replacement mods, and is it safe to call for an unloaded speaker?
- **E9** AE `AddTopic` id 35303 and sites 35287/35304: confirm against Address Library for 1.6.1170; SE id for `AddTopic` is NOT FOUND (irrelevant for this list, AE only).
- **E10** Does AIAgent.dll's `infonpc` payload carry the INFO FormID? (format is `infonpc|{}|{}|{}`; field meaning unknown.) If yes, the glue may not need its own `TESTopicInfoEvent` sink.
- **E11** Exact bodies of `FavorDialogueScript.Persuade/Bribe/Intimidate` and the rent-room / carriage fragment scripts (extract vanilla `Scripts.zip` sources in a later phase; not present in the modlist today).
- **E12** Behaviour when a service menu (barter/training) closes during a hidden session: does the engine return to the topic list, and does the hidden state persist?
