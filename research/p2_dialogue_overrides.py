#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
p2_dialogue_overrides.py  -  READ-ONLY load-order scan for LoreRim Glue, Phase 2 research.

Walks the ACTIVE load order of MO2 profile "Ultra" (loadorder.txt order, active = starred in plugins.txt or
implicit base-game/Creation Club file), locates each plugin (MO2 priority: overwrite > enabled mods by modlist.txt
priority > Stock Game\\Data; only the TOP LEVEL of every enabled mod folder is listed) and parses ONLY the top-level
groups DIAL (+INFO children), QUST, GLOB, GMST, PERK, AVIF, DLBR.

Nothing is written except the JSON given as argv[1] (default /tmp/p2_dialogue_overrides.json).
Run inside WSL:  python3 p2_dialogue_overrides.py /mnt/c/Users/<user>/AppData/Local/Temp/p2_dialogue_overrides.json
"""
import os, sys, struct, zlib, json, re, time
from collections import defaultdict, Counter

MO2 = '/mnt/f/Modlists/LoreRim'
PROFILE = MO2 + '/profiles/Ultra'
MODS = MO2 + '/mods'
STOCK = MO2 + '/Stock Game/Data'
STOCKROOT = MO2 + '/Stock Game'
OVERWRITE = MO2 + '/overwrite'
OUT = sys.argv[1] if len(sys.argv) > 1 else '/tmp/p2_dialogue_overrides.json'

VANILLA = ['skyrim.esm', 'update.esm', 'dawnguard.esm', 'hearthfires.esm', 'dragonborn.esm']
VANILLA_SET = set(VANILLA)
FOCUS = {
    'requiem.esp', 'requiem for the indifferent.esp', 'requiem lite.esp', 'immersive speech dialogues.esp',
    'beneficial speech checks.esp', 'requiem - minor arcana - civil war.esp', 'requiem - minor arcana - forsworn.esp',
    'requiem - minor arcana - roleplaying.esp', 'requiem - special feats.esp', 'less jail.esp',
    'requiem - vendor tweaks.esp', 'requiem - creation club.esp', 'requiem - no messages.esp',
}
WANT = {b'DIAL', b'QUST', b'GLOB', b'GMST', b'PERK', b'AVIF', b'DLBR'}

# condition function ids: verified against CommonLibSSE-NG include/RE/T/TESCondition.h (FUNCTION_DATA::FunctionID)
FUNC = {14: 'GetActorValue', 36: 'MenuMode', 47: 'GetItemCount', 48: 'GetGold', 50: 'GetTalkedToPC', 56: 'GetQuestRunning',
        58: 'GetStage', 59: 'GetStageDone', 66: 'GetShouldAttack', 67: 'GetInCell', 69: 'GetIsRace', 70: 'GetIsSex',
        71: 'GetInFaction', 72: 'GetIsID', 73: 'GetFactionRank', 74: 'GetGlobalValue', 77: 'GetRandomPercent', 80: 'GetLevel',
        116: 'IsIntimidatedByPlayer', 119: 'GetCrimeKnown', 122: 'GetCrime', 123: 'IsGreetingPlayer', 125: 'IsGuard',
        128: 'GetStaminaPercentage', 130: 'GetPCIsRace', 131: 'GetPCIsSex', 141: 'IsTalking', 152: 'GetIsCrimeFaction',
        172: 'GetTalkedToPCParam', 176: 'IsPCAMurderer', 182: 'GetEquipped', 190: 'GetAmountSoldStolen',
        214: 'HasMagicEffect', 225: 'GetPersuasionNumber', 249: 'IsInDialogueWithPlayer', 263: 'IsWeaponOut',
        264: 'HasSpell', 277: 'GetBaseActorValue', 286: 'IsSneaking', 312: 'GetPCMiscStat', 315: 'GetTotalPersuasionNumber',
        359: 'GetInCurrentLoc', 371: 'ShowBarterMenu', 372: 'IsInList', 375: 'GetCrimeGoldViolent',
        376: 'GetCrimeGoldNonviolent', 402: 'IsBribedbyPlayer', 403: 'GetRelationshipRank', 426: 'GetIsVoiceType',
        430: 'GetHealthPercentage', 448: 'HasPerk', 449: 'GetFactionRelation', 453: 'GetPlayerTeammate',
        459: 'GetCrimeGold', 482: 'PayCrimeGold', 494: 'GetPermanentActorValue', 497: 'CanPayCrimeGold',
        543: 'GetQuestCompleted', 560: 'HasKeyword', 562: 'LocationHasKeyword', 565: 'GetIsEditorLocation',
        566: 'GetIsAliasRef', 629: 'GetVMQuestVariable', 630: 'GetVMScriptVariable', 634: 'SetFavorState',
        635: 'IsInFavorState', 640: 'GetActorValuePercent', 652: 'GetInSharedCrimeFaction', 653: 'GetBribeAmount',
        654: 'GetBribeSuccess', 655: 'GetIntimidateSuccess', 656: 'GetArrestedState', 657: 'GetArrestingActor',
        682: 'WornHasKeyword'}
AV_FUNCS = {14, 277, 494, 640}
AV_SPEECH = 17   # ActorValue index of Speechcraft; self-verified by the scan (comparands are the Speech* globals)
OPS = ['==', '!=', '>', '>=', '<', '<=', '?6', '?7']
RUNON = ['Subject', 'Target', 'Reference', 'CombatTarget', 'LinkedRef', 'QuestAlias', 'PackageData', 'EventData']
# perk entry points: UESP Skyrim_Mod:Mod_File_Format/PERK
EP = {0x08: 'Mod Buy Prices', 0x0E: 'Activate', 0x2B: 'Mod Player Intimidation', 0x2C: 'Mod Player Reputation',
      0x2D: 'Mod Favor Points', 0x2E: 'Mod Bribe Amount', 0x3C: 'Mod Sell Prices', 0x4A: 'Filter Activation',
      0x51: 'Set Activate Label'}
EPFUNC = {1: 'Set Value', 2: 'Add Value', 3: 'Multiply Value', 4: 'Add Range to Value', 5: 'Add Actor Value Mult',
          6: 'Absolute', 7: 'Negative ABS', 8: 'Add Level List', 9: 'Add Activate Choice', 10: 'Select Spell',
          11: 'Select Text', 12: 'Set AV Mult', 13: 'Multiply AV Mult', 14: 'Multiply 1 + AV Mult', 15: 'Set Text'}
GMST_SPEECH_RE = re.compile(r'^[fibs](bribe|intimidat|persua|speech|favor|barter|haggl)', re.I)
GMST_DLG_RE = re.compile(r'(dialog|greet|subtitle|crimegold|bounty|arrest|jail|fAIMinGreet|fAISocial|iAISocial)', re.I)
GLOB_RE = re.compile(r'^(speech|favor|bribe|persua|intimid)', re.I)

t0 = time.time()
def log(*a):
    print('[%6.1fs]' % (time.time() - t0), *a, file=sys.stderr, flush=True)

# ---------------------------------------------------------------- file index
def read_lines(p):
    with open(p, 'r', encoding='utf-8', errors='replace') as f:
        return [l.rstrip('\r\n') for l in f]

def build_index():
    idx = {}
    fav_script = []     # mods shipping a loose FavorDialogueScript
    def add_dir(d, tag):
        try:
            with os.scandir(d) as it:
                for e in it:
                    n = e.name.lower()
                    if n.endswith(('.esp', '.esm', '.esl')):
                        idx[n] = (e.path, tag)
        except OSError:
            pass
    add_dir(STOCK, '<Stock Game>')
    ml = [l for l in read_lines(PROFILE + '/modlist.txt') if l.startswith('+')]
    enabled = [l[1:] for l in ml]
    for mod in reversed(enabled):          # lowest priority first, highest priority assigned last (wins)
        d = MODS + '/' + mod
        add_dir(d, mod)
        for rel in ('Scripts/FavorDialogueScript.pex', 'Scripts/Source/FavorDialogueScript.psc',
                    'Source/Scripts/FavorDialogueScript.psc'):
            if os.path.exists(d + '/' + rel):
                fav_script.append((mod, rel))
    add_dir(OVERWRITE, '<overwrite>')
    prio = {m: i for i, m in enumerate(enabled)}   # 0 = highest priority
    fav_script.sort(key=lambda x: prio.get(x[0], 99999))
    return idx, enabled, fav_script

def load_order():
    lo = [l.strip() for l in read_lines(PROFILE + '/loadorder.txt') if l.strip() and not l.startswith('#')]
    starred = set()
    for l in read_lines(PROFILE + '/plugins.txt'):
        if l.startswith('*'):
            starred.add(l[1:].strip().lower())
    implicit = {'skyrim.esm', 'update.esm', 'dawnguard.esm', 'hearthfires.esm', 'dragonborn.esm', '_resourcepack.esl'}
    ccc = STOCKROOT + '/Skyrim.ccc'
    if os.path.exists(ccc):
        for l in read_lines(ccc):
            if l.strip():
                implicit.add(l.strip().lower())
    active = [p for p in lo if p.lower() in starred or p.lower() in implicit]
    return lo, active, starred, implicit

# ---------------------------------------------------------------- strings (vanilla, from Skyrim - Interface.bsa if uncompressed)
def load_vanilla_strings():
    res = {}
    note = ''
    p = STOCK + '/Skyrim - Interface.bsa'
    try:
        with open(p, 'rb') as f:
            magic, ver, off, aflags, nfold, nfiles, tfl, tfil, fflags = struct.unpack('<4sIIIIIIII', f.read(36))
            if magic != b'BSA\x00' or ver != 105:
                return res, 'unexpected BSA header'
            folders = []
            for _ in range(nfold):
                h, cnt, unk, offs = struct.unpack('<QIIQ', f.read(24))
                folders.append(cnt)
            recs = []
            for cnt in folders:
                ln = f.read(1)[0]
                name = f.read(ln)[:-1].decode('cp1252').lower()
                for _ in range(cnt):
                    h, size, offs = struct.unpack('<QII', f.read(16))
                    recs.append([name, size, offs])
            names = f.read(tfil).split(b'\x00')
            for i, r in enumerate(recs):
                r.append(names[i].decode('cp1252').lower())
            for folder, size, offs, fn in recs:
                if folder != 'strings' or '_english.' not in fn:
                    continue
                compressed = bool(aflags & 0x4) ^ bool(size & 0x40000000)
                if compressed:
                    note += ' %s compressed (skipped);' % fn
                    continue
                f.seek(offs)
                data = f.read(size & 0x3FFFFFFF)
                if aflags & 0x100:
                    ln = data[0]; data = data[1 + ln:]
                plug = fn.split('_english')[0]
                ext = fn.rsplit('.', 1)[1]
                cnt, dsz = struct.unpack_from('<II', data, 0)
                base = 8 + cnt * 8
                d = res.setdefault(plug, {})
                for i in range(cnt):
                    sid, so = struct.unpack_from('<II', data, 8 + i * 8)
                    o = base + so
                    if ext in ('dlstrings', 'ilstrings'):
                        ln = struct.unpack_from('<I', data, o)[0]
                        s = data[o + 4:o + 4 + ln].rstrip(b'\x00')
                    else:
                        e = data.find(b'\x00', o)
                        s = data[o:e]
                    d[sid] = s.decode('cp1252', errors='replace')
    except Exception as ex:
        note += ' error: %r' % (ex,)
    return res, note

# ---------------------------------------------------------------- record parsing helpers
def subrecords(data):
    pos = 0; n = len(data); big = None
    while pos + 6 <= n:
        t = data[pos:pos + 4]
        sz = data[pos + 4] | (data[pos + 5] << 8)
        pos += 6
        if t == b'XXXX':
            big = struct.unpack_from('<I', data, pos)[0]
            pos += sz
            continue
        if big is not None:
            sz = big; big = None
        yield t, data[pos:pos + sz]
        pos += sz

def zstr(b):
    i = b.find(b'\x00')
    if i >= 0:
        b = b[:i]
    return b.decode('cp1252', errors='replace')

class Plugin:
    __slots__ = ('name', 'lname', 'path', 'mod', 'idx', 'masters', 'flags', 'localized', 'strings', 'counts')
    def canon(self, fid):
        if fid == 0:
            return None
        mi = fid >> 24
        m = self.masters[mi] if mi < len(self.masters) else self.lname
        return '%s:%06X' % (m, fid & 0xFFFFFF)
    def origin(self, fid):
        mi = fid >> 24
        return self.masters[mi] if mi < len(self.masters) else self.lname
    def lstr(self, b):
        if self.localized:
            if len(b) == 4:
                sid = struct.unpack('<I', b)[0]
                if sid == 0:
                    return ''
                if self.strings is not None and sid in self.strings:
                    return self.strings[sid]
                return '<strid %d>' % sid
            return '<lstring?>'
        return zstr(b)
    def param(self, v):
        if v < 0x800:
            return v
        return self.canon(v)

def parse_vmad(b, kind):
    """returns (scripts[list of (name,[propnames])], fraginfo) ; tolerant"""
    out = []; frag = None
    try:
        ver, objfmt, nscripts = struct.unpack_from('<hhH', b, 0)
        pos = 6
        def wstr():
            nonlocal pos
            ln = struct.unpack_from('<H', b, pos)[0]; pos += 2
            s = b[pos:pos + ln].decode('cp1252', errors='replace'); pos += ln
            return s
        def skipval(t):
            nonlocal pos
            if t == 1: pos += 8
            elif t == 2: wstr()
            elif t in (3, 4): pos += 4
            elif t == 5: pos += 1
            elif t in (11, 12, 13, 14, 15):
                cnt = struct.unpack_from('<I', b, pos)[0]; pos += 4
                for _ in range(cnt):
                    skipval(t - 10)
        def scripts(n):
            nonlocal pos
            res = []
            for _ in range(n):
                name = wstr()
                if ver >= 4: pos += 1
                npr = struct.unpack_from('<H', b, pos)[0]; pos += 2
                props = []
                for _ in range(npr):
                    pn = wstr()
                    t = b[pos]; pos += 1
                    if ver >= 4: pos += 1
                    skipval(t)
                    props.append(pn)
                res.append((name, props))
            return res
        out = scripts(nscripts)
        if pos < len(b):
            if kind == 'INFO':
                pos += 1
                fl = b[pos]; pos += 1
                fn = wstr()
                fr = []
                for _ in range(bin(fl & 3).count('1')):
                    pos += 1
                    sn = wstr(); fnn = wstr()
                    fr.append((sn, fnn))
                frag = {'flags': fl, 'file': fn, 'fragments': fr}
            elif kind == 'QUST':
                pos += 1
                cnt = struct.unpack_from('<H', b, pos)[0]; pos += 2
                fn = wstr()
                frag = {'file': fn, 'fragment_count': cnt}
    except Exception as ex:
        frag = {'vmad_parse_error': repr(ex)}
    return out, frag

def parse_qust_fragments(b):
    """QUST VMAD -> [(stage, scriptName, fragmentName)]"""
    out = []
    try:
        ver, objfmt, nscripts = struct.unpack_from('<hhH', b, 0)
        pos = [6]
        def wstr():
            ln = struct.unpack_from('<H', b, pos[0])[0]; pos[0] += 2
            s = b[pos[0]:pos[0] + ln].decode('cp1252', errors='replace'); pos[0] += ln
            return s
        def skipval(t):
            if t == 1: pos[0] += 8
            elif t == 2: wstr()
            elif t in (3, 4): pos[0] += 4
            elif t == 5: pos[0] += 1
            elif t in (11, 12, 13, 14, 15):
                cnt = struct.unpack_from('<I', b, pos[0])[0]; pos[0] += 4
                for _ in range(cnt): skipval(t - 10)
        for _ in range(nscripts):
            wstr()
            if ver >= 4: pos[0] += 1
            npr = struct.unpack_from('<H', b, pos[0])[0]; pos[0] += 2
            for _ in range(npr):
                wstr(); t = b[pos[0]]; pos[0] += 1
                if ver >= 4: pos[0] += 1
                skipval(t)
        pos[0] += 1
        cnt = struct.unpack_from('<H', b, pos[0])[0]; pos[0] += 2
        fn = wstr()
        for _ in range(cnt):
            stage, unk, logentry = struct.unpack_from('<Hhi', b, pos[0]); pos[0] += 8
            pos[0] += 1
            sn = wstr(); fr = wstr()
            out.append((stage, sn, fr))
    except Exception as ex:
        out.append(('error', repr(ex), ''))
    return out

def parse_ctda(P, b, cis):
    opflags = b[0]
    func = struct.unpack_from('<H', b, 8)[0]
    p1, p2, runon, ref = struct.unpack_from('<IIII', b, 12)
    if opflags & 0x04:
        comp = P.canon(struct.unpack_from('<I', b, 4)[0])
    else:
        comp = round(struct.unpack_from('<f', b, 4)[0], 4)
    if func in AV_FUNCS:
        p1s = p1
    else:
        p1s = P.param(p1)
    p2s = P.param(p2)
    if func in (629, 630):
        p2s = '?'          # replaced by the CIS2 string that follows
    return (opflags >> 5, opflags & 0x1F, comp, func, p1s, p2s, runon, P.canon(ref) if runon == 2 else (ref if runon in (5, 6, 7) else 0))

def speech_kinds(conds):
    k = set()
    for c in conds:
        f = c[3]
        if f == 654: k.add('bribe')
        elif f == 655: k.add('intimidate')
        elif f == 277 and c[4] == AV_SPEECH: k.add('speech_base_av')      # GetBaseActorValue Speechcraft = trainer skill cap, not a persuade check
        elif f in AV_FUNCS and c[4] == AV_SPEECH: k.add('persuade')
        elif f in (225, 315): k.add('persuasion_number')
        elif f == 653: k.add('bribe_amount')
    return k

def parse_info(P, flags, fid, data):
    if flags & 0x00040000:
        data = zlib.decompress(data[4:])
    s = {'id': P.canon(fid), 'edid': '', 'flags': 0, 'reset': 0, 'favor': None, 'nresp': 0, 'resp': [], 'prompt': None,
         'conds': [], 'scripts': [], 'frag': None, 'links': [], 'shared': None, 'walkaway': None, 'speaker': None,
         'prev': None, 'deleted': bool(flags & 0x20)}
    for t, b in subrecords(data):
        if t == b'EDID': s['edid'] = zstr(b)
        elif t == b'VMAD':
            sc, fr = parse_vmad(b, 'INFO'); s['scripts'] = [x[0] for x in sc]; s['frag'] = fr
        elif t == b'ENAM' and len(b) >= 4: s['flags'], s['reset'] = struct.unpack_from('<HH', b, 0)
        elif t == b'PNAM' and len(b) == 4: s['prev'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'CNAM' and len(b) >= 1: s['favor'] = b[0]
        elif t == b'TCLT' and len(b) == 4: s['links'].append(P.canon(struct.unpack('<I', b)[0]))
        elif t == b'DNAM' and len(b) == 4: s['shared'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'TRDT': s['nresp'] += 1
        elif t == b'NAM1': s['resp'].append(P.lstr(b)[:300])
        elif t == b'CTDA' and len(b) >= 28: s['conds'].append(parse_ctda(P, b if len(b) >= 32 else b + b'\x00' * 4, None))
        elif t == b'CIS2' and s['conds']:
            c = s['conds'][-1]; s['conds'][-1] = c[:5] + (zstr(b),) + c[6:]
        elif t == b'CIS1' and s['conds']:
            c = s['conds'][-1]; s['conds'][-1] = c[:4] + (zstr(b),) + c[5:]
        elif t == b'RNAM': s['prompt'] = P.lstr(b)
        elif t == b'ANAM' and len(b) == 4: s['speaker'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'TWAT' and len(b) == 4: s['walkaway'] = P.canon(struct.unpack('<I', b)[0])
    return s

def info_sig(s):
    return (hash(tuple(s['conds'])), s['flags'], s['nresp'], tuple(x.lower() for x in s['scripts']), tuple(s['links']),
            s['shared'], s['deleted'])

def parse_dial(P, flags, fid, data):
    if flags & 0x00040000:
        data = zlib.decompress(data[4:])
    d = {'id': P.canon(fid), 'edid': '', 'full': None, 'prio': None, 'branch': None, 'quest': None, 'cat': None,
         'subtype': None, 'sname': None, 'tifc': None}
    for t, b in subrecords(data):
        if t == b'EDID': d['edid'] = zstr(b)
        elif t == b'FULL': d['full'] = P.lstr(b)
        elif t == b'PNAM' and len(b) == 4: d['prio'] = round(struct.unpack('<f', b)[0], 2)
        elif t == b'BNAM' and len(b) == 4: d['branch'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'QNAM' and len(b) == 4: d['quest'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'DATA' and len(b) >= 4: d['cat'] = b[1]; d['subtype'] = struct.unpack_from('<H', b, 2)[0]
        elif t == b'SNAM': d['sname'] = b[:4].decode('cp1252', errors='replace')
        elif t == b'TIFC' and len(b) == 4: d['tifc'] = struct.unpack('<I', b)[0]
    return d

def parse_perk(P, flags, fid, data):
    if flags & 0x00040000:
        data = zlib.decompress(data[4:])
    p = {'id': P.canon(fid), 'edid': '', 'full': None, 'desc': None, 'next': None, 'effects': [], 'nconds': 0}
    cur = None; in_effect = False
    for t, b in subrecords(data):
        if t == b'EDID': p['edid'] = zstr(b)
        elif t == b'FULL' and not in_effect: p['full'] = P.lstr(b)
        elif t == b'DESC': p['desc'] = P.lstr(b)[:500]
        elif t == b'NNAM' and len(b) == 4: p['next'] = P.canon(struct.unpack('<I', b)[0])
        elif t == b'PRKE' and len(b) >= 3:
            in_effect = True
            cur = {'type': b[0], 'rank': b[1], 'prio': b[2], 'conds': []}
            p['effects'].append(cur)
        elif t == b'DATA' and in_effect and cur is not None:
            if cur['type'] == 2 and len(b) >= 3:
                cur['ep'] = b[0]; cur['fn'] = b[1]; cur['tabs'] = b[2]
            elif cur['type'] == 1 and len(b) >= 4:
                cur['spell'] = P.canon(struct.unpack_from('<I', b, 0)[0])
            elif cur['type'] == 0 and len(b) >= 5:
                cur['quest'] = P.canon(struct.unpack_from('<I', b, 0)[0]); cur['stage'] = b[4]
        elif t == b'PRKC' and cur is not None:
            cur['conds'].append(('TAB', b[0] if b else -1))
        elif t == b'CTDA' and len(b) >= 28:
            c = parse_ctda(P, b if len(b) >= 32 else b + b'\x00' * 4, None)
            if in_effect and cur is not None: cur['conds'].append(c)
            else: p['nconds'] += 1
        elif t == b'EPFT' and cur is not None and b: cur['epft'] = b[0]
        elif t == b'EPFD' and cur is not None:
            ft = cur.get('epft')
            if ft == 1 and len(b) >= 4: cur['val'] = round(struct.unpack_from('<f', b, 0)[0], 4)
            elif ft == 2 and len(b) >= 8: cur['val'] = [round(x, 4) for x in struct.unpack_from('<ff', b, 0)]
            elif ft in (3, 4, 5) and len(b) >= 4: cur['val'] = P.canon(struct.unpack_from('<I', b, 0)[0])
            elif ft == 7: cur['val'] = P.lstr(b) if len(b) == 4 and P.localized else zstr(b)
        elif t == b'EPF2' and cur is not None: cur['label'] = P.lstr(b)
        elif t == b'PRKF': in_effect = False; cur = None
    return p

# ---------------------------------------------------------------- stores
plugins = []
edids = {}                                  # canon -> edid (GLOB, QUST, PERK, DLBR, DIAL with edid)
globs = defaultdict(list)                   # canon -> [(pidx, edid, type, value)]
gmsts = defaultdict(list)                   # edid -> [(pidx, value)]
qust_plugins = defaultdict(list)            # canon -> [pidx]
info_win = {}                               # every INFO canon -> minimal winning summary (last loaded wins)
perks_quest = []                            # (pidx, stage->fragment list) for skyrim.esm:05F596 PerksQuest
flag_stats = defaultdict(Counter)           # 'vanilla'/'mods' -> Counter of ENAM flag usage among NEW infos
favor_quest = []                            # (pidx, scripts, frag) for skyrim.esm:05A6DC
dial_store = {}                             # canon -> {'van': d or None, 'win': d, 'plugins':[pidx]}
info_store = {}                             # vanilla-origin INFO canon -> {'dial','van_sig','van','ovr':[(pidx,sig,summary|None)]}
perk_store = defaultdict(list)              # canon -> [(pidx, perk)]
avif_speech = []                            # (pidx, [perk canon...])
dlbr_store = {}                             # canon -> {'plugins':[], 'win':{...}}
per_plugin = defaultdict(lambda: Counter())
persuade_comparands = {'vanilla': Counter(), 'mods': Counter()}
new_speech_examples = defaultdict(list)
injected_into_vanilla_speech_topics = []
vanilla_speech_dials = set()
focus_dump = defaultdict(lambda: {'DIAL': [], 'INFO': [], 'QUST': [], 'DLBR': []})
cond_func_tally = defaultdict(Counter)      # focus plugin -> Counter(func)
errors = []

def handle_info(P, flags, fid, data, parent_dial):
    origin = P.origin(fid)
    canon = P.canon(fid)
    is_van_origin = origin in VANILLA_SET
    self_is_van = P.lname in VANILLA_SET
    new = (origin == P.lname)
    C = per_plugin[P.lname]
    C['INFO_total'] += 1
    if new: C['INFO_new'] += 1
    else:
        C['INFO_override'] += 1
        if is_van_origin: C['INFO_override_vanilla'] += 1
    try:
        s = parse_info(P, flags, fid, data)
    except Exception as ex:
        errors.append('%s INFO %08X: %r' % (P.name, fid, ex)); return
    s['dial'] = parent_dial
    info_win[canon] = (P.idx, parent_dial, s['prompt'], tuple(sorted(speech_kinds(s['conds']))), s['flags'],
                       (s['resp'][0][:140] if s['resp'] else ''), len(s['links']), tuple(s['scripts']), s['nresp'])
    if new:
        FS = flag_stats['vanilla' if self_is_van else 'mods']
        FS['total'] += 1
        fl = s['flags']
        for bit, nm in ((0x1, 'goodbye'), (0x2, 'random'), (0x4, 'say_once'), (0x20, 'random_end'), (0x40, 'invisible_continue'),
                        (0x80, 'walk_away'), (0x100, 'walk_away_invisible'), (0x200, 'force_subtitle'), (0x4000, 'spends_favor_points')):
            if fl & bit: FS[nm] += 1
        if s['links']: FS['has_TCLT_links'] += 1
        if s['prompt']: FS['has_RNAM_prompt_override'] += 1
        if s['shared']: FS['uses_shared_info'] += 1
        if s['walkaway']: FS['has_TWAT_walkaway_topic'] += 1
        if s['scripts']: FS['has_script_fragment'] += 1
        if s['nresp'] == 0 and not s['shared']: FS['no_response_lines'] += 1
        if s['prompt']: per_plugin[P.lname]['INFO_new_with_RNAM'] += 1
    kinds = speech_kinds(s['conds'])
    if kinds:
        s['kinds'] = sorted(kinds)
        for c in s['conds']:
            if c[3] in AV_FUNCS and c[4] == AV_SPEECH:
                persuade_comparands['vanilla' if self_is_van else 'mods'][(OPS[c[0]], str(c[2]))] += 1
    if P.lname in FOCUS:
        focus_dump[P.lname]['INFO'].append(s)
        for c in s['conds']:
            cond_func_tally[P.lname][c[3]] += 1
    if is_van_origin:
        e = info_store.get(canon)
        if e is None:
            e = info_store[canon] = {'dial': parent_dial, 'van_sig': None, 'van': None, 'ovr': []}
        sig = info_sig(s)
        if self_is_van:
            e['van_sig'] = sig; e['dial'] = parent_dial
            e['van'] = s if kinds else None
            e['van_kinds'] = sorted(kinds)
            if kinds and parent_dial: vanilla_speech_dials.add(parent_dial)
        else:
            keep = bool(kinds) or bool(e.get('van_kinds')) or (P.lname in FOCUS)
            e['ovr'].append((P.idx, sig, s if keep else None))
            if kinds: C['speech_INFO_override_vanilla'] += 1
            elif e.get('van_kinds'): C['speech_INFO_override_vanilla'] += 1
    else:
        if kinds:
            if new:
                C['speech_INFO_new'] += 1
                for k in kinds: C['speech_new_' + k] += 1
                if len(new_speech_examples[P.lname]) < 4:
                    new_speech_examples[P.lname].append(s)
            else:
                C['speech_INFO_override_mod'] += 1
    if new and parent_dial and parent_dial.split(':')[0] in VANILLA_SET and not self_is_van:
        C['INFO_new_in_vanilla_topic'] += 1
        if parent_dial in vanilla_speech_dials:
            injected_into_vanilla_speech_topics.append((P.idx, s))

def handle_group(P, label, buf):
    pos = 0; end = len(buf)
    lab = label.decode('ascii')
    C = per_plugin[P.lname]
    def walk(pos, end, parent):
        while pos + 24 <= end:
            typ = buf[pos:pos + 4]
            if typ == b'GRUP':
                gsize, glabel, gtype = struct.unpack_from('<I4sI', buf, pos + 4)
                par = parent
                if gtype == 7:
                    par = P.canon(struct.unpack('<I', glabel)[0])
                walk(pos + 24, pos + gsize, par)
                pos += gsize
                continue
            size, flags, fid = struct.unpack_from('<III', buf, pos + 4)
            data = buf[pos + 24:pos + 24 + size]
            pos += 24 + size
            try:
                handle_record(P, typ, flags, fid, data, parent)
            except Exception as ex:
                errors.append('%s %s %08X: %r' % (P.name, typ, fid, ex))
    walk(0, end, None)

def handle_record(P, typ, flags, fid, data, parent):
    C = per_plugin[P.lname]
    origin = P.origin(fid); canon = P.canon(fid)
    new = (origin == P.lname); van_origin = origin in VANILLA_SET; self_van = P.lname in VANILLA_SET
    if typ == b'INFO':
        handle_info(P, flags, fid, data, parent); return
    t = typ.decode('ascii', errors='replace')
    C[t + '_total'] += 1
    if new: C[t + '_new'] += 1
    else:
        C[t + '_override'] += 1
        if van_origin: C[t + '_override_vanilla'] += 1
    if typ == b'DIAL':
        d = parse_dial(P, flags, fid, data)
        if d['edid']: edids[canon] = d['edid']
        e = dial_store.get(canon)
        if e is None: e = dial_store[canon] = {'van': None, 'win': None, 'plugins': []}
        e['plugins'].append(P.idx); e['win'] = d
        if self_van: e['van'] = d
        if P.lname in FOCUS: focus_dump[P.lname]['DIAL'].append(d)
        return
    if flags & 0x00040000:
        data = zlib.decompress(data[4:])
    if typ == b'GLOB':
        ed = ''; ty = ''; val = None
        for st, b in subrecords(data):
            if st == b'EDID': ed = zstr(b)
            elif st == b'FNAM' and b: ty = chr(b[0])
            elif st == b'FLTV' and len(b) == 4: val = round(struct.unpack('<f', b)[0], 4)
        edids[canon] = ed
        globs[canon].append((P.idx, ed, ty, val))
    elif typ == b'GMST':
        ed = ''; val = None
        for st, b in subrecords(data):
            if st == b'EDID': ed = zstr(b)
            elif st == b'DATA':
                k = ed[:1].lower()
                if k == 'f' and len(b) == 4: val = round(struct.unpack('<f', b)[0], 5)
                elif k in ('i', 'b') and len(b) == 4: val = struct.unpack('<i', b)[0]
                elif k == 's': val = P.lstr(b)[:120]
        gmsts[ed].append((P.idx, val))
    elif typ == b'QUST':
        ed = ''; vm = None
        for st, b in subrecords(data):
            if st == b'EDID': ed = zstr(b);
            elif st == b'VMAD' and canon == 'skyrim.esm:05A6DC': vm = parse_vmad(b, 'QUST')
            elif st == b'VMAD' and canon == 'skyrim.esm:05F596': perks_quest.append((P.idx, parse_qust_fragments(b)))
            if ed and canon not in ('skyrim.esm:05A6DC', 'skyrim.esm:05F596'): break
        edids[canon] = ed
        qust_plugins[canon].append(P.idx)
        if canon == 'skyrim.esm:05A6DC':
            favor_quest.append((P.idx, vm))
        if P.lname in FOCUS: focus_dump[P.lname]['QUST'].append({'id': canon, 'edid': ed, 'new': new})
    elif typ == b'PERK':
        p = parse_perk(P, 0, fid, data)
        edids[canon] = p['edid']
        perk_store[canon].append((P.idx, p))
    elif typ == b'AVIF':
        ed = ''; perks = []
        for st, b in subrecords(data):
            if st == b'EDID':
                ed = zstr(b)
                if ed.lower() != 'avspeechcraft': break
            elif st == b'PNAM' and len(b) == 4:
                v = struct.unpack('<I', b)[0]
                if v: perks.append(P.canon(v))
        if ed.lower() == 'avspeechcraft':
            avif_speech.append((P.idx, perks))
    elif typ == b'DLBR':
        d = {'id': canon, 'edid': '', 'quest': None, 'flags': None, 'start': None, 'tnam': None}
        for st, b in subrecords(data):
            if st == b'EDID': d['edid'] = zstr(b)
            elif st == b'QNAM' and len(b) == 4: d['quest'] = P.canon(struct.unpack('<I', b)[0])
            elif st == b'DNAM' and len(b) == 4: d['flags'] = struct.unpack('<I', b)[0]
            elif st == b'SNAM' and len(b) == 4: d['start'] = P.canon(struct.unpack('<I', b)[0])
            elif st == b'TNAM' and len(b) == 4: d['tnam'] = struct.unpack('<I', b)[0]
        edids[canon] = d['edid']
        e = dlbr_store.get(canon)
        if e is None: e = dlbr_store[canon] = {'plugins': [], 'win': None}
        e['plugins'].append(P.idx); e['win'] = d
        if new and d['flags'] is not None:
            if d['flags'] & 1: C['DLBR_new_toplevel'] += 1
            if d['flags'] & 2: C['DLBR_new_blocking'] += 1
        if P.lname in FOCUS: focus_dump[P.lname]['DLBR'].append(d)

def parse_plugin(P, vstrings):
    with open(P.path, 'rb') as f:
        fsize = os.fstat(f.fileno()).st_size
        hdr = f.read(24)
        typ, size, flags, fid = struct.unpack_from('<4sIII', hdr, 0)
        if typ != b'TES4':
            raise ValueError('not a plugin')
        data = f.read(size)
        P.flags = flags
        P.localized = bool(flags & 0x80)
        P.masters = []
        for t, b in subrecords(data):
            if t == b'MAST':
                P.masters.append(zstr(b).lower())
        P.strings = vstrings.get(P.lname.rsplit('.', 1)[0]) if P.localized else None
        pos = 24 + size
        while pos + 24 <= fsize:
            f.seek(pos)
            gh = f.read(24)
            if gh[:4] != b'GRUP':
                errors.append('%s: non-GRUP at top level @%d' % (P.name, pos)); break
            gsize, label, gtype = struct.unpack_from('<I4sI', gh, 4)
            if gsize < 24:
                errors.append('%s: bad group size @%d' % (P.name, pos)); break
            if gtype == 0 and label in WANT:
                buf = f.read(gsize - 24)
                handle_group(P, label, buf)
            pos += gsize

# ---------------------------------------------------------------- main
def main():
    log('indexing mods ...')
    idx, enabled, fav_script = build_index()
    lo, active, starred, implicit = load_order()
    log('enabled mods %d, plugin files indexed %d, loadorder %d, active %d' % (len(enabled), len(idx), len(lo), len(active)))
    vstrings, snote = load_vanilla_strings()
    log('vanilla strings tables:', {k: len(v) for k, v in vstrings.items()}, snote)
    missing = []
    # read headers first to get ESM flag for the engine's master-first ordering
    cand = []
    for name in active:
        ent = idx.get(name.lower())
        if not ent:
            missing.append(name); continue
        cand.append((name, ent))
    def is_master(name, path):
        ln = name.lower()
        if ln.endswith(('.esm', '.esl')): return True
        try:
            with open(path, 'rb') as f:
                h = f.read(24)
            return bool(struct.unpack_from('<I', h, 8)[0] & 1)
        except OSError:
            return False
    mflag = [is_master(n, e[0]) for n, e in cand]
    ordered = [c for c, m in zip(cand, mflag) if m] + [c for c, m in zip(cand, mflag) if not m]
    moved = sum(1 for a, b in zip(cand, ordered) if a[0] != b[0])
    log('master-first reorder changed %d positions' % moved)
    for i, (name, ent) in enumerate(ordered):
        P = Plugin(); P.name = name; P.lname = name.lower(); P.path = ent[0]; P.mod = ent[1]; P.idx = i
        P.masters = []; P.flags = 0; P.localized = False; P.strings = None
        plugins.append(P)
        try:
            parse_plugin(P, vstrings)
        except Exception as ex:
            errors.append('%s: %r' % (name, ex))
        if i % 250 == 0:
            log('parsed %d / %d  (%s)' % (i, len(ordered), name))
    log('parsing done; building report')
    pname = lambda i: plugins[i].name

    def ed(c):
        if c is None: return None
        e = edids.get(c)
        return '%s [%s]' % (e, c) if e else c
    def fmt_cond(c):
        op, fl, comp, func, p1, p2, runon, ref = c
        fn = FUNC.get(func, 'Func#%d' % func)
        if func in AV_FUNCS:
            a1 = 'Speechcraft' if p1 == AV_SPEECH else 'AV#%s' % p1
        else:
            a1 = ed(p1) if isinstance(p1, str) else p1
        a2 = ed(p2) if isinstance(p2, str) else p2
        cs = ed(comp) if isinstance(comp, str) else comp
        if isinstance(comp, str):
            g = globs.get(comp)
            if g: cs = '%s(=%s)' % (cs, g[-1][3])
        ro = RUNON[runon] if runon < len(RUNON) else str(runon)
        if runon == 2: ro += '(%s)' % ref
        elif runon == 5: ro += '(alias %s)' % ref
        return '%s%s.%s(%s,%s) %s %s%s' % ('', ro, fn, a1, a2, OPS[op], cs, '  OR' if fl & 1 else '')
    def fmt_info(s):
        if s is None: return None
        o = dict(s)
        o['conds'] = [fmt_cond(c) for c in s['conds']]
        o['flags'] = '0x%04X' % s['flags']
        return o

    out = {'meta': {}, 'a_speech_check_overrides': {}, 'b_globals_gmst': {}, 'c_override_volume': {},
           'd_new_dialogue_surface': {}, 'favor_quest': {}, 'speech_perks': {}, 'perk_entrypoints': {}, 'focus_plugins': {}}
    out['meta'] = {'generated': time.strftime('%Y-%m-%d %H:%M:%S'), 'enabled_mods': len(enabled), 'loadorder_lines': len(lo),
                   'active_plugins': len(active), 'parsed': len(plugins), 'missing_plugins': missing,
                   'master_first_positions_changed': moved, 'vanilla_strings': {k: len(v) for k, v in vstrings.items()},
                   'strings_note': snote, 'errors': errors[:200], 'error_count': len(errors),
                   'localized_plugins_without_strings': [p.name for p in plugins if p.localized and p.strings is None][:400]}

    # ---- (a) speech-check INFO overrides
    total_van_speech = sum(1 for e in info_store.values() if e.get('van_kinds'))
    kinds_van = Counter()
    for e in info_store.values():
        for k in e.get('van_kinds') or []: kinds_van[k] += 1
    per_plugin_speech = Counter(); winners = Counter(); rows = []
    for canon, e in info_store.items():
        sp_ovr = [(pi, sig, s) for (pi, sig, s) in e['ovr'] if s is not None and (s.get('kinds') or e.get('van_kinds'))]
        if not sp_ovr: continue
        for pi, sig, s in sp_ovr: per_plugin_speech[pname(pi)] += 1
        wpi, wsig, ws = e['ovr'][-1]
        winners[pname(wpi)] += 1
        vs = e['van_sig']
        diff = []
        if vs is None: diff.append('NO_VANILLA_BASE')
        else:
            if vs[0] != wsig[0]: diff.append('conditions')
            if vs[1] != wsig[1]: diff.append('flags')
            if vs[2] != wsig[2]: diff.append('response_count')
            if vs[3] != wsig[3]: diff.append('scripts')
            if vs[4] != wsig[4]: diff.append('links')
            if vs[5] != wsig[5]: diff.append('shared_info')
            if vs[6] != wsig[6]: diff.append('deleted')
        d = dial_store.get(e['dial']) or {}
        dw = d.get('win') or {}
        rows.append({'info': canon, 'dial': e['dial'], 'dial_edid': dw.get('edid'), 'dial_prompt': (d.get('van') or dw).get('full'),
                     'dial_subtype': dw.get('sname'), 'quest': ed(dw.get('quest')), 'vanilla_kinds': e.get('van_kinds'),
                     'overriders': [pname(pi) for pi, _, _ in e['ovr']], 'winner': pname(wpi), 'diff_winner_vs_vanilla': diff,
                     'vanilla': fmt_info(e['van']), 'winner_record': fmt_info(ws)})
    rows.sort(key=lambda r: (0 if 'conditions' in r['diff_winner_vs_vanilla'] else 1, r['info']))
    diffstat = Counter()
    for r in rows:
        diffstat[','.join(r['diff_winner_vs_vanilla']) or 'identical(conds/flags/resp/scripts/links)'] += 1
    out['a_speech_check_overrides'] = {
        'vanilla_speech_check_infos_total': total_van_speech, 'vanilla_by_kind': dict(kinds_van),
        'vanilla_speech_check_topics': len(vanilla_speech_dials),
        'overridden_count': len(rows), 'overrides_per_plugin': per_plugin_speech.most_common(),
        'winner_per_plugin': winners.most_common(), 'winner_diff_stats': diffstat.most_common(),
        'persuade_comparands_vanilla': [[k[0], ed(k[1]) if ':' in k[1] else k[1], v] for k, v in persuade_comparands['vanilla'].most_common()],
        'persuade_comparands_mods': [[k[0], ed(k[1]) if ':' in k[1] else k[1], v] for k, v in persuade_comparands['mods'].most_common(60)],
        'rows': rows,
        'new_infos_injected_into_vanilla_speech_topics': [{'plugin': pname(pi), 'info': fmt_info(s)} for pi, s in injected_into_vanilla_speech_topics][:300],
        'new_infos_injected_into_vanilla_speech_topics_count': len(injected_into_vanilla_speech_topics)}

    # ---- (b) globals + gmst
    gl = []
    used = set(k[1] for k in list(persuade_comparands['vanilla']) + list(persuade_comparands['mods']) if ':' in k[1])
    for canon, lst in globs.items():
        edn = lst[0][1]
        if GLOB_RE.search(edn or '') or canon in used:
            van = [x for x in lst if pname(x[0]).lower() in VANILLA_SET]
            gl.append({'id': canon, 'edid': edn, 'vanilla_value': van[-1][3] if van else None, 'winning_value': lst[-1][3],
                       'winner': pname(lst[-1][0]), 'chain': [[pname(x[0]), x[3]] for x in lst]})
    gl.sort(key=lambda g: g['edid'] or '')
    gm_speech = []; gm_dlg = []
    for edn, lst in gmsts.items():
        van = [x for x in lst if pname(x[0]).lower() in VANILLA_SET]
        row = {'edid': edn, 'vanilla_value': van[-1][1] if van else None, 'winning_value': lst[-1][1], 'winner': pname(lst[-1][0]),
               'chain': [[pname(x[0]), x[1]] for x in lst]}
        if GMST_SPEECH_RE.search(edn): gm_speech.append(row)
        elif GMST_DLG_RE.search(edn) and len(lst) > len(van): gm_dlg.append(row)
    gm_speech.sort(key=lambda r: r['edid']); gm_dlg.sort(key=lambda r: r['edid'])
    out['b_globals_gmst'] = {'globals': gl, 'gmst_speech': gm_speech, 'gmst_dialogue_crime_overridden': gm_dlg}

    # ---- (c) override volume
    def cnt(pl):
        C = per_plugin.get(pl.lower(), Counter()); return dict(C)
    vol = []
    for p in plugins:
        if p.lname in VANILLA_SET: continue
        C = per_plugin[p.lname]
        vol.append((C['DIAL_override'] + C['INFO_override'], p.name, C['DIAL_override'], C['INFO_override'],
                    C['DIAL_override_vanilla'], C['INFO_override_vanilla'], C['QUST_override'], C['QUST_override_vanilla']))
    vol.sort(reverse=True)
    # winners among vanilla INFOs / DIALs
    info_winner = Counter(); info_overridden = 0; info_changed = 0
    for canon, e in info_store.items():
        if e['ovr']:
            info_overridden += 1
            info_winner[pname(e['ovr'][-1][0])] += 1
            if e['van_sig'] is not None and e['ovr'][-1][1] != e['van_sig']: info_changed += 1
    dial_winner = Counter(); dial_overridden = 0
    for canon, e in dial_store.items():
        if canon.split(':')[0] in VANILLA_SET:
            non_van = [pi for pi in e['plugins'] if pname(pi).lower() not in VANILLA_SET]
            if non_van:
                dial_overridden += 1; dial_winner[pname(e['plugins'][-1])] += 1
    qust_winner = Counter(); qust_overridden = 0
    for canon, lst in qust_plugins.items():
        if canon.split(':')[0] in VANILLA_SET:
            non_van = [pi for pi in lst if pname(pi).lower() not in VANILLA_SET]
            if non_van:
                qust_overridden += 1; qust_winner[pname(lst[-1])] += 1
    out['c_override_volume'] = {
        'vanilla_totals': {p: cnt(p) for p in VANILLA},
        'requiem_family': {p: cnt(p) for p in sorted(FOCUS)},
        'top25_by_DIAL_INFO_overrides': [{'plugin': v[1], 'DIAL_override': v[2], 'INFO_override': v[3], 'DIAL_override_vanilla': v[4],
                                          'INFO_override_vanilla': v[5], 'QUST_override': v[6], 'QUST_override_vanilla': v[7]} for v in vol[:25]],
        'vanilla_INFO_overridden_by_any_mod': info_overridden, 'vanilla_INFO_winner_differs_from_vanilla_sig': info_changed,
        'vanilla_INFO_winner_top25': info_winner.most_common(25),
        'vanilla_DIAL_overridden_by_any_mod': dial_overridden, 'vanilla_DIAL_winner_top25': dial_winner.most_common(25),
        'vanilla_QUST_overridden_by_any_mod': qust_overridden, 'vanilla_QUST_winner_top25': qust_winner.most_common(25)}

    # ---- (d) new dialogue surface
    newv = []
    tot = Counter()
    for p in plugins:
        if p.lname in VANILLA_SET: continue
        C = per_plugin[p.lname]
        for k in ('DIAL_new', 'INFO_new', 'QUST_new', 'DLBR_new', 'DLBR_new_toplevel', 'DLBR_new_blocking', 'speech_INFO_new',
                  'speech_new_persuade', 'speech_new_bribe', 'speech_new_intimidate', 'INFO_new_in_vanilla_topic'):
            tot[k] += C[k]
        if C['DIAL_new'] or C['INFO_new'] or C['QUST_new']:
            newv.append({'plugin': p.name, 'mod': p.mod, 'DIAL_new': C['DIAL_new'], 'INFO_new': C['INFO_new'], 'QUST_new': C['QUST_new'],
                         'DLBR_new_toplevel': C['DLBR_new_toplevel'], 'DLBR_new_blocking': C['DLBR_new_blocking'],
                         'speech_INFO_new': C['speech_INFO_new'], 'persuade': C['speech_new_persuade'], 'bribe': C['speech_new_bribe'],
                         'intimidate': C['speech_new_intimidate'], 'INFO_new_in_vanilla_topic': C['INFO_new_in_vanilla_topic']})
    newv.sort(key=lambda r: r['INFO_new'], reverse=True)
    sp = sorted([r for r in newv if r['speech_INFO_new']], key=lambda r: r['speech_INFO_new'], reverse=True)
    out['d_new_dialogue_surface'] = {'totals_nonvanilla': dict(tot), 'plugins_with_new_dialogue_or_quests': len(newv),
                                     'top40_by_new_INFO': newv[:40], 'plugins_with_new_speech_checks': len(sp),
                                     'top40_by_new_speech_checks': sp[:40],
                                     'new_speech_examples': {k: [fmt_info(s) for s in v] for k, v in list(new_speech_examples.items())[:80]}}

    # ---- favor quest + scripts
    out['favor_quest'] = {'DialogueFavorGeneric_chain': [{'plugin': pname(pi), 'scripts': vm[0] if vm else None, 'frag': vm[1] if vm else None}
                                                        for pi, vm in favor_quest],
                          'loose_FavorDialogueScript_by_priority(highest first)': fav_script}

    # ---- speech topics in the WINNING state (what the menu will really show) + RNAM / tag statistics
    by_dial = defaultdict(list)
    for canon, w in info_win.items():
        by_dial[w[1]].append((canon, w))
    TAG = re.compile(r'\((persuade|intimidate|brawl|bribe)\)|bribecost|gold\)', re.I)
    sp_topics = []
    core = {'persuade', 'bribe', 'intimidate'}
    untagged = 0; topics_with_rnam = 0
    for dcanon, lst in by_dial.items():
        if not any(core & set(w[3]) for _, w in lst): continue
        d = (dial_store.get(dcanon) or {}).get('win') or {}
        full = d.get('full')
        infos = []
        any_rnam = False
        shown = [full or '']
        for canon, w in sorted(lst):
            if w[2]: any_rnam = True; shown.append(w[2])
            infos.append({'info': canon, 'winner': pname(w[0]), 'kinds': list(w[3]), 'prompt_override_RNAM': w[2], 'flags': '0x%04X' % w[4],
                          'first_response': w[5], 'links': w[6], 'scripts': list(w[7])})
        tagged_all = all(TAG.search(x or '') for x in shown if x) and any(shown)
        tagged_any = any(TAG.search(x or '') for x in shown)
        if any_rnam: topics_with_rnam += 1
        if not tagged_any: untagged += 1
        sp_topics.append({'topic': dcanon, 'edid': d.get('edid'), 'prompt_FULL': full, 'quest': ed(d.get('quest')), 'subtype': d.get('sname'),
                          'topic_winner': pname(dial_store[dcanon]['plugins'][-1]) if dcanon in dial_store else None,
                          'text_tagged_any': tagged_any, 'text_tagged_all_prompts': tagged_all, 'has_RNAM': any_rnam, 'infos': infos})
    sp_topics.sort(key=lambda t: t['topic'])
    rn = Counter()
    for p in plugins:
        if per_plugin[p.lname]['INFO_new_with_RNAM']: rn[p.name] = per_plugin[p.lname]['INFO_new_with_RNAM']
    out['speech_topics_winning_state'] = {'count': len(sp_topics), 'topics_without_any_text_tag': untagged,
                                          'topics_with_INFO_prompt_override': topics_with_rnam, 'topics': sp_topics}
    out['branching_stats_new_infos'] = {k: dict(v) for k, v in flag_stats.items()}
    out['rnam_prompt_override_top20_plugins'] = rn.most_common(20)
    out['perks_quest_05F596'] = [{'plugin': pname(pi), 'stage_fragments': fr} for pi, fr in perks_quest]
    out['vanilla_speech_perks_first_vs_winner'] = [
        {'perk': c, 'vanilla': perk_store[c][0][1] if c in perk_store else None, 'vanilla_plugin': pname(perk_store[c][0][0]) if c in perk_store else None,
         'winner': perk_store[c][-1][1] if c in perk_store else None, 'winner_plugin': pname(perk_store[c][-1][0]) if c in perk_store else None}
        for c in (avif_speech[0][1] if avif_speech else [])]

    # ---- speech perk tree
    tree = avif_speech[-1][1] if avif_speech else []
    out['speech_perks'] = {'AVSpeechcraft_chain': [[pname(pi), len(pk)] for pi, pk in avif_speech],
                           'winner_tree': [{'perk': ed(c), 'chain': [pname(pi) for pi, _ in perk_store.get(c, [])],
                                            'winner_record': (perk_store[c][-1][1] if c in perk_store else None)} for c in tree],
                           'vanilla_tree': [ed(c) for c in (avif_speech[0][1] if avif_speech else [])]}
    eps = []
    for canon, lst in perk_store.items():
        pi, p = lst[-1]
        for e in p['effects']:
            if e.get('type') == 2 and e.get('ep') in EP:
                eps.append({'perk': ed(canon), 'full': p['full'], 'winner': pname(pi), 'entry_point': EP[e['ep']],
                            'function': EPFUNC.get(e.get('fn'), e.get('fn')), 'value': e.get('val'), 'label': e.get('label'),
                            'rank': e['rank'], 'prio': e['prio'],
                            'conds': [('TAB %s' % c[1]) if c[0] == 'TAB' else fmt_cond(c) for c in e['conds']][:14]})
    out['perk_entrypoints'] = {'counts': Counter(x['entry_point'] for x in eps).most_common(),
                               'dialogue_relevant': [x for x in eps if x['entry_point'] in ('Mod Player Intimidation', 'Mod Favor Points', 'Mod Bribe Amount', 'Mod Player Reputation')],
                               'activation': [x for x in eps if x['entry_point'] in ('Activate', 'Filter Activation', 'Set Activate Label')],
                               'prices': [x for x in eps if x['entry_point'] in ('Mod Buy Prices', 'Mod Sell Prices')][:120]}

    # ---- focus plugin dumps
    for pl, d in focus_dump.items():
        out['focus_plugins'][pl] = {'counts': dict(per_plugin[pl]),
                                    'cond_function_tally': [[FUNC.get(f, 'Func#%d' % f), n] for f, n in cond_func_tally[pl].most_common()],
                                    'DIAL': d['DIAL'][:1500], 'INFO': [fmt_info(s) for s in d['INFO'][:3000]], 'QUST': d['QUST'][:1500],
                                    'DLBR': d['DLBR'][:500]}
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(out, f, indent=1, default=str)
    log('wrote', OUT)

if __name__ == '__main__':
    main()
