Scriptname Debug Hidden
{COMPILE-ONLY STUB. Declarations only, so the glue compiles without the Creation Kit's
 Scripts.zip. Never compiled to .pex and never deployed: the game uses its own Debug script.}

Function Trace(string asTextToPrint, int aiSeverity = 0) native global
bool Function TraceUser(string asUserLog, string asTextToPrint, int aiSeverity = 0) native global
bool Function OpenUserLog(string asLogName) native global
Function CloseUserLog(string asLogName) native global
Function Notification(string asNotificationText) native global
Function MessageBox(string asMessageBoxText) native global
Function SendAnimationEvent(ObjectReference arRef, string asEventName) native global
