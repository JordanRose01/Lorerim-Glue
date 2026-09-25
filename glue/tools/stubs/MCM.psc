Scriptname MCM Hidden
{COMPILE-ONLY STUB of MCM Helper's script API (the SDK sources are not on disk). Never deployed.}

bool Function IsInstalled() native global
int Function GetVersionCode() native global
int Function GetModSettingInt(string a_modName, string a_settingName) native global
bool Function GetModSettingBool(string a_modName, string a_settingName) native global
float Function GetModSettingFloat(string a_modName, string a_settingName) native global
string Function GetModSettingString(string a_modName, string a_settingName) native global
Function SetModSettingInt(string a_modName, string a_settingName, int a_value) native global
Function SetModSettingBool(string a_modName, string a_settingName, bool a_value) native global
Function SetModSettingFloat(string a_modName, string a_settingName, float a_value) native global
Function SetModSettingString(string a_modName, string a_settingName, string a_value) native global
