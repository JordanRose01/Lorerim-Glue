Scriptname Package extends Form Hidden
{COMPILE-ONLY declaration stub - never deployed}
; The Creation Kit's vanilla Package.psc is not on this machine (no Scripts.zip) and SKSE ships only
; the vanilla scripts it extends, so the auto-stub generator would produce an EMPTY Package type and
; LRG_Main.ConvQuestPackage() ("never freeze an NPC whose package a quest owns") could not compile.
; Declared here verbatim in vanilla's own shape - zero arguments, native, the same name - so the
; compiled call is the same CallMethod the game resolves against the real Package script at runtime.
; CHIM itself compiles exactly this call: AIAgentAIMind.psc:1896 npc.GetCurrentPackage().GetOwningQuest()

Quest Function GetOwningQuest() native
