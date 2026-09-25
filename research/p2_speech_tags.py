#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
p2_speech_tags.py - READ-ONLY scan of the active LoreRim load order for player-dialogue prompts.

What it does (never writes anywhere except the --out / --dump paths you give it):
  1. Reads MO2 profile files (modlist.txt = priority, plugins.txt / loadorder.txt = load order).
  2. Builds a filename index by listing ONLY the top level (plus a top-level "Strings" folder) of every
     enabled mod, highest priority first, then "Stock Game/Data".
  3. For every active plugin walks the top-level GRUPs by header (seek over everything that is not wanted) and
     parses DIAL (+ child INFO), DLBR, QUST (EDID/FULL only), SCEN (action types only), GMST and GLOB (speech names).
     Compressed records (flag 0x00040000) are zlib-inflated. Localized plugins (TES4 flag 0x80) are resolved through
     <plugin>_English.STRINGS / .DLSTRINGS / .ILSTRINGS, loose first, then BSA (v104 zlib / v105 LZ4-frame,
     pure-python LZ4 decoder, no third-party modules).
  4. Extracts parenthesised / bracketed tags and <Token> tokens from DIAL FULL and INFO RNAM prompt texts,
     counts them per tag per plugin, and cross-checks them against the INFO conditions that really implement
     speech checks (GetActorValue Speechcraft, GetBribeSuccess, GetIntimidateSuccess, ...).
  5. Writes a summary JSON (--out) and an optional full topic dump as gzip JSONL (--dump) for case studies.

Works under WSL (/mnt/f/...) and under Windows python (F:\\...). Python 3.8+.
"""
import os, sys, struct, zlib, json, re, gzip, time, argparse, collections

# ----------------------------------------------------------------------------------------------------------------
# constants
# ----------------------------------------------------------------------------------------------------------------
BASE_MASTERS = ["Skyrim.esm", "Update.esm", "Dawnguard.esm", "HearthFires.esm", "Dragonborn.esm"]
PLUGIN_EXT = (".esp", ".esm", ".esl")

# CTDA function indices (CommonLibSSE-NG FUNCTION_DATA::FunctionID, see research/dialogue-engine.md 2.6)
FN = {
    14: "GetActorValue", 47: "GetItemCount", 48: "GetGold", 58: "GetStage", 59: "GetStageDone",
    72: "GetIsID", 74: "GetGlobalValue", 116: "IsIntimidatedbyPlayer", 277: "GetBaseActorValue",
    402: "IsBribedbyPlayer", 403: "GetRelationshipRank", 653: "GetBribeAmount", 654: "GetBribeSuccess",
    655: "GetIntimidateSuccess", 225: "GetPersuasionNumber", 315: "GetTotalPersuasionNumber",
    566: "GetIsAliasRef", 629: "GetVMQuestVariable", 630: "GetVMScriptVariable", 635: "IsInFavorState",
}
AV_SPEECH = 17            # ActorValue index of Speechcraft
GOLD001 = 0x0000000F      # Skyrim.esm Gold001

DIAL_CATEGORY = {0: "Topic(player)", 1: "Favor(command)", 2: "Scene", 3: "Combat", 4: "Favors", 5: "Detection",
                 6: "Service", 7: "Misc"}
# INFO ENAM bits by CK name (research/dialogue-engine.md 2.1)
IF_GOODBYE, IF_RANDOM, IF_SAYONCE, IF_RANDOMEND, IF_INVISCONT, IF_WALKAWAY, IF_WALKAWAY_INVIS, IF_FAVORPTS = \
    0x0001, 0x0002, 0x0004, 0x0020, 0x0040, 0x0080, 0x0100, 0x4000

SPEECH_GLOB = {"speechveryeasy", "speecheasy", "speechaverage", "speechhard", "speechveryhard"}
SPEECH_GMST_PREFIX = ("fbribe", "fintimidate", "fpersuasion", "fspeech", "ifavor", "ffavor", "fbarter")

RE_PAREN = re.compile(r"\(([^()]{1,80})\)")
RE_BRACK = re.compile(r"\[([^\[\]]{1,80})\]")
RE_ANGLE = re.compile(r"<([^<>]{1,60})>")


# ----------------------------------------------------------------------------------------------------------------
# helpers
# ----------------------------------------------------------------------------------------------------------------
def dec(b):
    if b is None:
        return None
    b = b.split(b"\x00", 1)[0]
    try:
        return b.decode("utf-8")
    except UnicodeDecodeError:
        return b.decode("cp1252", "replace")


def lz4_block(src, dst):
    i, n = 0, len(src)
    while i < n:
        tok = src[i]; i += 1
        lit = tok >> 4
        if lit == 15:
            while True:
                b = src[i]; i += 1
                lit += b
                if b != 255:
                    break
        if lit:
            dst += src[i:i + lit]; i += lit
        if i >= n:
            break
        off = src[i] | (src[i + 1] << 8); i += 2
        ml = tok & 15
        if ml == 15:
            while True:
                b = src[i]; i += 1
                ml += b
                if b != 255:
                    break
        ml += 4
        start = len(dst) - off
        if off >= ml:
            dst += dst[start:start + ml]
        else:
            pat = bytes(dst[start:start + off])
            dst += (pat * (ml // off + 1))[:ml]
    return dst


def lz4_frame(data):
    if data[:4] != b"\x04\x22\x4d\x18":
        raise ValueError("not an LZ4 frame")
    flg = data[4]
    p = 6
    if flg & 0x08:
        p += 8
    if flg & 0x01:
        p += 4
    p += 1  # header checksum
    out = bytearray()
    blk_ck = bool(flg & 0x10)
    while True:
        (bs,) = struct.unpack_from("<I", data, p); p += 4
        if bs == 0:
            break
        raw = bool(bs & 0x80000000)
        bs &= 0x7FFFFFFF
        chunk = data[p:p + bs]; p += bs
        if blk_ck:
            p += 4
        if raw:
            out += chunk
        else:
            lz4_block(chunk, out)
    return bytes(out)


class BSA:
    """Minimal read-only BSA v104/v105 reader (directory + single file extraction)."""

    def __init__(self, path):
        self.path = path
        self.files = {}
        with open(path, "rb") as f:
            hdr = f.read(36)
            magic, ver, off, aflags, nfold, nfile, lfold, lfile, fflags = struct.unpack("<4sIIIIIIII", hdr)
            if magic != b"BSA\x00":
                raise ValueError("not a BSA: " + path)
            self.ver, self.aflags = ver, aflags
            frs = 24 if ver == 105 else 16
            folders = []
            raw = f.read(frs * nfold)
            for i in range(nfold):
                if ver == 105:
                    h, cnt, _pad, o = struct.unpack_from("<QIIQ", raw, i * frs)
                else:
                    h, cnt, o = struct.unpack_from("<QII", raw, i * frs)
                folders.append(cnt)
            recs = []
            for cnt in folders:
                name = ""
                if aflags & 1:
                    ln = f.read(1)[0]
                    name = f.read(ln).split(b"\x00")[0].decode("cp1252", "replace").lower()
                blk = f.read(16 * cnt)
                for j in range(cnt):
                    h, size, o = struct.unpack_from("<QII", blk, j * 16)
                    recs.append((name, size, o))
            names = f.read(lfile).split(b"\x00") if (aflags & 2) else []
            for k, (fold, size, o) in enumerate(recs):
                fn = names[k].decode("cp1252", "replace").lower() if k < len(names) else str(k)
                self.files[fold + "\\" + fn] = (size, o)

    def read(self, inner):
        size, o = self.files[inner.lower()]
        comp = bool(self.aflags & 4) != bool(size & 0x40000000)
        size &= 0x3FFFFFFF
        with open(self.path, "rb") as f:
            f.seek(o)
            data = f.read(size)
        if self.aflags & 0x100:
            ln = data[0]
            data = data[1 + ln:]
        if comp:
            data = data[4:]
            data = lz4_frame(data) if self.ver == 105 else zlib.decompress(data)
        return data


def parse_strings(data, kind):
    """kind: 'strings' (zstring) or 'dlstrings'/'ilstrings' (uint32 length prefixed)."""
    out = {}
    if not data or len(data) < 8:
        return out
    cnt, dsize = struct.unpack_from("<II", data, 0)
    base = 8 + cnt * 8
    for i in range(cnt):
        sid, off = struct.unpack_from("<II", data, 8 + i * 8)
        p = base + off
        if kind == "strings":
            e = data.find(b"\x00", p)
            out[sid] = dec(data[p:e if e >= 0 else len(data)])
        else:
            (ln,) = struct.unpack_from("<I", data, p)
            out[sid] = dec(data[p + 4:p + 4 + ln])
    return out


# ----------------------------------------------------------------------------------------------------------------
# load order
# ----------------------------------------------------------------------------------------------------------------
class LoadOrder:
    def __init__(self, mo2, profile, log):
        self.mo2, self.log = mo2, log
        prof = os.path.join(mo2, "profiles", profile)
        self.index = {}          # lower filename (or 'strings/<file>') -> full path (highest priority wins)
        self.mod_of = {}         # lower filename -> mod name
        mods = []
        with open(os.path.join(prof, "modlist.txt"), encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.rstrip("\r\n")
                if line.startswith("+"):
                    mods.append(line[1:])
        self.enabled_mods = mods
        roots = [("<overwrite>", os.path.join(mo2, "overwrite"))]
        roots += [(m, os.path.join(mo2, "mods", m)) for m in mods]           # modlist.txt: highest priority first
        roots += [("<Stock Game>", os.path.join(mo2, "Stock Game", "Data"))]
        missing = 0
        for name, root in roots:
            try:
                it = list(os.scandir(root))
            except OSError:
                missing += 1
                continue
            for e in it:
                low = e.name.lower()
                if low.endswith(PLUGIN_EXT) or low.endswith(".bsa"):
                    if low not in self.index:
                        self.index[low] = e.path
                        self.mod_of[low] = name
                elif low == "strings":
                    try:
                        for s in os.scandir(e.path):
                            k = "strings/" + s.name.lower()
                            if k not in self.index:
                                self.index[k] = s.path
                                self.mod_of[k] = name
                    except OSError:
                        pass
        log("enabled mods: %d (unreadable folders: %d); indexed files: %d" % (len(mods), missing, len(self.index)))
        # active plugins in load order
        star, mentioned = set(), set()
        with open(os.path.join(prof, "plugins.txt"), encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if line.startswith("*"):
                    star.add(line[1:].lower()); mentioned.add(line[1:].lower())
                else:
                    mentioned.add(line.lower())
        implicit = set(x.lower() for x in BASE_MASTERS)
        ccc = os.path.join(mo2, "Stock Game", "Skyrim.ccc")
        if os.path.exists(ccc):
            with open(ccc, encoding="utf-8", errors="replace") as f:
                for line in f:
                    n = line.strip().lower()
                    if n and n not in mentioned and n in self.index:
                        implicit.add(n)
        order = []
        with open(os.path.join(prof, "loadorder.txt"), encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                order.append(line)
        self.active = [p for p in order if p.lower() in star or p.lower() in implicit]
        self.not_found = [p for p in self.active if p.lower() not in self.index]
        log("loadorder.txt: %d plugins, active: %d, active-but-file-not-found: %d" %
            (len(order), len(self.active), len(self.not_found)))
        self._bsa, self._strings = {}, {}

    def bsa(self, name):
        name = name.lower()
        if name not in self._bsa:
            p = self.index.get(name)
            try:
                self._bsa[name] = BSA(p) if p else None
            except Exception as ex:  # noqa
                self.log("BSA error %s: %s" % (name, ex))
                self._bsa[name] = None
        return self._bsa[name]

    def strings(self, plugin, kind, lang="english"):
        """Returns ({id: text}, source description) for a localized plugin."""
        key = (plugin.lower(), kind)
        if key in self._strings:
            return self._strings[key]
        base = os.path.splitext(plugin)[0].lower()
        fn = "%s_%s.%s" % (base, lang, kind)
        res, src = {}, None
        p = self.index.get("strings/" + fn)
        if p:
            with open(p, "rb") as f:
                res, src = parse_strings(f.read(), kind), "loose:" + p
        else:
            for cand in (base + ".bsa", "skyrim - patch.bsa", "skyrim - interface.bsa", "skyrim - misc.bsa"):
                b = self.bsa(cand)
                if b and ("strings\\" + fn) in b.files:
                    res, src = parse_strings(b.read("strings\\" + fn), kind), "bsa:" + b.path
                    break
        self._strings[key] = (res, src)
        return self._strings[key]


# ----------------------------------------------------------------------------------------------------------------
# plugin parsing
# ----------------------------------------------------------------------------------------------------------------
def subrecords(data):
    p, n, big = 0, len(data), None
    while p + 6 <= n:
        t = data[p:p + 4]
        (sz,) = struct.unpack_from("<H", data, p + 4)
        p += 6
        if t == b"XXXX":
            (big,) = struct.unpack_from("<I", data, p)
            p += sz
            continue
        if big is not None:
            sz, big = big, None
        yield t, data[p:p + sz]
        p += sz


def records(buf, start, end):
    """Yields ('REC', type, flags, formid, data) and ('GRP', label, gtype, start, end)."""
    p = start
    while p + 24 <= end:
        t = buf[p:p + 4]
        size, = struct.unpack_from("<I", buf, p + 4)
        if t == b"GRUP":
            label = buf[p + 8:p + 12]
            gtype, = struct.unpack_from("<i", buf, p + 12)
            yield ("GRP", label, gtype, p + 24, p + size)
            p += size
        else:
            flags, fid = struct.unpack_from("<II", buf, p + 8)
            data = buf[p + 24:p + 24 + size]
            if flags & 0x00040000:
                try:
                    data = zlib.decompress(data[4:])
                except zlib.error:
                    data = b""
            yield ("REC", t, flags, fid, data)
            p += 24 + size


class Scan:
    def __init__(self, lo, log, want_responses=True):
        self.lo, self.log = lo, log
        self.want_responses = want_responses
        self.dial = collections.OrderedDict()   # key -> topic dict (winner = last loaded)
        self.info = collections.OrderedDict()   # key -> info dict  (winner = last loaded)
        self.dlbr, self.qust = {}, {}
        self.per_plugin = collections.OrderedDict()
        self.scen_actions = collections.Counter()
        self.scen_actions_by_plugin = collections.defaultdict(collections.Counter)
        self.gmst, self.glob = {}, {}
        self.unresolved = collections.Counter()
        self.localized = []

    # -- string resolution -------------------------------------------------------------------------------------
    def lstr(self, plugin, localized, raw, kinds=("strings", "dlstrings", "ilstrings")):
        if raw is None:
            return None
        if not localized:
            return dec(raw)
        if len(raw) < 4:
            return None
        (sid,) = struct.unpack_from("<I", raw, 0)
        if sid == 0:
            return ""
        for k in kinds:
            tab, _src = self.lo.strings(plugin, k)
            if sid in tab:
                return tab[sid]
        self.unresolved[plugin] += 1
        return None

    # -- one plugin ---------------------------------------------------------------------------------------------
    def plugin(self, name, path):
        st = dict(dial=0, info=0, dial_with_prompt=0, info_with_rnam=0, localized=False, mod=self.lo.mod_of.get(name.lower()))
        with open(path, "rb") as f:
            hdr = f.read(24)
            if hdr[:4] != b"TES4":
                self.log("not a plugin: " + path)
                return
            size, flags = struct.unpack_from("<II", hdr, 4)
            tes4 = f.read(size)
            localized = bool(flags & 0x80)
            st["localized"] = localized
            st["esl_flag"] = bool(flags & 0x200)
            masters = [dec(d).lower() for t, d in subrecords(tes4) if t == b"MAST"]
            me = name.lower()

            def key(fid):
                i = fid >> 24
                return ((masters[i] if i < len(masters) else me), fid & 0xFFFFFF)

            fsize = os.fstat(f.fileno()).st_size
            while f.tell() + 24 <= fsize:
                gh = f.read(24)
                if gh[:4] != b"GRUP":
                    break
                gsize, = struct.unpack_from("<I", gh, 4)
                label = gh[8:12]
                if label in (b"DIAL", b"DLBR", b"QUST", b"SCEN", b"GMST", b"GLOB"):
                    buf = f.read(gsize - 24)
                    getattr(self, "g_" + label.decode())(name, localized, key, buf, st)
                else:
                    f.seek(gsize - 24, 1)
        if localized:
            self.localized.append(name)
        self.per_plugin[name] = st

    def g_GMST(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC":
                continue
            edid, val = None, None
            for t, d in subrecords(r[4]):
                if t == b"EDID":
                    edid = dec(d)
                elif t == b"DATA":
                    val = d
            if edid and edid.lower().startswith(SPEECH_GMST_PREFIX) and val is not None and len(val) >= 4:
                v = struct.unpack("<f", val[:4])[0] if edid[0] in "fF" else struct.unpack("<i", val[:4])[0]
                self.gmst.setdefault(edid, []).append((name, round(v, 6) if isinstance(v, float) else v))

    def g_GLOB(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC":
                continue
            edid, val = None, None
            for t, d in subrecords(r[4]):
                if t == b"EDID":
                    edid = dec(d)
                elif t == b"FLTV":
                    val = struct.unpack("<f", d[:4])[0]
            if edid and (edid.lower() in SPEECH_GLOB or edid.lower().startswith(("speech", "favor", "bribe", "persua", "intimid"))):
                self.glob.setdefault(edid, []).append((name, val, "%s:%06X" % key(r[3])))

    def g_QUST(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC" or r[1] != b"QUST":
                continue
            edid = full = None
            qtype, has_obj = None, False
            for t, d in subrecords(r[4]):
                if t == b"EDID":
                    edid = dec(d)
                elif t == b"FULL" and full is None:
                    full = self.lstr(name, localized, d)
                elif t == b"DNAM" and len(d) >= 12 and qtype is None:
                    qtype = struct.unpack_from("<I", d, 8)[0]      # QUST DNAM: flags u16, priority u8, unk u8, unk u32, type u32
                elif t == b"QOBJ":
                    has_obj = True
                    break
                elif t == b"ANAM":                                   # aliases start: objectives (QOBJ) come before them
                    break
            k = key(r[3])
            old = self.qust.get(k, {})
            self.qust[k] = dict(edid=edid or old.get("edid"), full=full if full is not None else old.get("full"), plugin=name,
                                qtype=qtype if qtype is not None else old.get("qtype"), has_obj=has_obj or old.get("has_obj", False))

    def g_DLBR(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC" or r[1] != b"DLBR":
                continue
            o = dict(plugin=name)
            for t, d in subrecords(r[4]):
                if t == b"EDID":
                    o["edid"] = dec(d)
                elif t == b"QNAM":
                    o["quest"] = key(struct.unpack("<I", d[:4])[0])
                elif t == b"DNAM":
                    o["flags"] = struct.unpack("<I", d[:4])[0]
                elif t == b"SNAM":
                    o["start"] = key(struct.unpack("<I", d[:4])[0])
                elif t == b"TNAM":
                    o["tnam"] = struct.unpack("<I", d[:4])[0]
            self.dlbr[key(r[3])] = o

    def g_SCEN(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC" or r[1] != b"SCEN":
                continue
            for t, d in subrecords(r[4]):
                if t == b"ANAM" and len(d) >= 2:
                    a = struct.unpack("<H", d[:2])[0]
                    self.scen_actions[a] += 1
                    self.scen_actions_by_plugin[name][a] += 1

    def g_DIAL(self, name, localized, key, buf, st):
        cur = None
        for r in records(buf, 0, len(buf)):
            if r[0] == "REC" and r[1] == b"DIAL":
                o = dict(plugin=name)
                for t, d in subrecords(r[4]):
                    if t == b"EDID":
                        o["edid"] = dec(d)
                    elif t == b"FULL":
                        o["full"] = self.lstr(name, localized, d, ("strings", "dlstrings", "ilstrings"))
                    elif t == b"QNAM":
                        o["quest"] = key(struct.unpack("<I", d[:4])[0])
                    elif t == b"BNAM":
                        o["branch"] = key(struct.unpack("<I", d[:4])[0])
                    elif t == b"PNAM":
                        o["prio"] = struct.unpack("<f", d[:4])[0]
                    elif t == b"DATA" and len(d) >= 4:
                        o["tflags"], o["cat"], o["subtype"] = struct.unpack("<BBH", d[:4])
                    elif t == b"SNAM":
                        o["sname"] = d[:4].decode("cp1252", "replace")
                k = key(r[3])
                cur = k
                prev = self.dial.get(k)
                o["origin"] = prev["origin"] if prev else name
                o["overridden_by"] = (prev.get("overridden_by", []) + [name]) if prev else []
                self.dial[k] = o
                st["dial"] += 1
                if o.get("full"):
                    st["dial_with_prompt"] += 1
                self._count_tags(name, o.get("full"), "FULL", o, st)
            elif r[0] == "GRP" and r[2] == 7:
                parent = key(struct.unpack("<I", r[1])[0])
                pcat = self.dial.get(parent, {}).get("cat")
                for q in records(buf, r[3] - 0, r[4] - 0):
                    if q[0] != "REC" or q[1] != b"INFO":
                        continue
                    self._info(name, localized, key, q, parent, pcat, st)

    def _info(self, name, localized, key, q, parent, pcat, st):
        o = dict(plugin=name, parent=parent)
        conds, links, resp = [], [], None
        vmad_fds = False
        for t, d in subrecords(q[4]):
            if t == b"ENAM" and len(d) >= 4:
                o["flags"], o["reset"] = struct.unpack("<HH", d[:4])
            elif t == b"RNAM":
                o["rnam"] = self.lstr(name, localized, d)
            elif t == b"TCLT":
                links.append(key(struct.unpack("<I", d[:4])[0]))
            elif t == b"DNAM":
                o["shared"] = key(struct.unpack("<I", d[:4])[0])
            elif t == b"TWAT":
                o["walkaway"] = key(struct.unpack("<I", d[:4])[0])
            elif t == b"CNAM" and len(d) >= 1:
                o["favor"] = d[0]
            elif t == b"NAM1" and resp is None and self.want_responses and pcat in (0, 1, None):
                resp = self.lstr(name, localized, d, ("ilstrings", "dlstrings", "strings")) or ""
            elif t == b"VMAD":
                o["vmad"] = True
                low = d.lower()
                if b"pfds" in low or b"favordialogue" in low or b"dialoguefavorgeneric" in low:
                    vmad_fds = True
            elif t == b"CTDA" and len(d) >= 28:
                op = d[0]
                fn, = struct.unpack_from("<H", d, 8)
                p1, p2, runon = struct.unpack_from("<III", d, 12)
                if op & 0x04:
                    cv = "G:%s:%06X" % key(struct.unpack_from("<I", d, 4)[0])
                else:
                    cv = round(struct.unpack_from("<f", d, 4)[0], 4)
                conds.append((fn, p1, (op >> 5), cv, bool(op & 1), runon))
        if conds:
            o["conds"] = conds
        if links:
            o["links"] = links
        if resp:
            o["resp"] = resp[:220]
        if vmad_fds:
            o["fds"] = True
        k = key(q[3])
        prev = self.info.get(k)
        o["origin"] = prev["origin"] if prev else name
        self.info[k] = o
        st["info"] += 1
        if o.get("rnam"):
            st["info_with_rnam"] += 1
            self._count_tags(name, o["rnam"], "RNAM", o, st)

    # -- tags -----------------------------------------------------------------------------------------------------
    def _count_tags(self, plugin, text, field, rec, st):
        if not text:
            return
        tags = extract_tags(text)
        if tags:
            rec.setdefault("tags", [])
            for kind, norm, rawtag in tags:
                rec["tags"].append((kind, norm))
                d = st.setdefault("tags", {})
                d[kind + ":" + norm] = d.get(kind + ":" + norm, 0) + 1


def norm_tag(s):
    s = s.strip().lower()
    s = RE_ANGLE.sub(lambda m: "<" + m.group(1).split("=")[0].strip().lower() + ">", s)
    s = re.sub(r"\d[\d,\.]*", "#", s)
    s = re.sub(r"\s+", " ", s)
    return s


def extract_tags(text):
    out = []
    for m in RE_PAREN.finditer(text):
        out.append(("paren", norm_tag(m.group(1)), m.group(0)))
    for m in RE_BRACK.finditer(text):
        out.append(("brack", norm_tag(m.group(1)), m.group(0)))
    for m in RE_ANGLE.finditer(text):
        out.append(("angle", m.group(1).split("=")[0].strip().lower(), m.group(0)))
    return out


def cond_kinds(conds):
    k = set()
    for fn, p1, op, cv, is_or, runon in conds or ():
        if fn in (14, 277) and p1 == AV_SPEECH:
            k.add("persuade_cond")
        elif fn == 654:
            k.add("bribe_cond")
        elif fn == 655:
            k.add("intimidate_cond")
        elif fn == 653:
            k.add("bribeamount_cond")
        elif fn == 48 or (fn == 47 and p1 == GOLD001):
            k.add("gold_cond")
        elif fn == 116:
            k.add("isintimidated_cond")
        elif fn == 402:
            k.add("isbribed_cond")
    return k


SPEECH_WORDS = {
    "persuade": re.compile(r"\bpersua", re.I), "intimidate": re.compile(r"\bintimidat", re.I),
    "bribe": re.compile(r"\bbribe|<bribecost>|\bgold\b|\bsepti", re.I), "brawl": re.compile(r"\bbrawl", re.I),
    "lie": re.compile(r"\blie\b|\blying\b", re.I), "flirt": re.compile(r"\bflirt|\bseduc|\bcharm", re.I),
    "illusion": re.compile(r"\billusion\b", re.I), "pay": re.compile(r"\bpay\b|\bgive\b.*\bgold\b", re.I),
}


# ----------------------------------------------------------------------------------------------------------------
# deep analysis on the composed winner view (what the MENU can show and what it cannot)
# ----------------------------------------------------------------------------------------------------------------
RE_SPEECHTAG = re.compile(r"\((persuade|intimidate|bribe|brawl)\)|<bribecost>", re.I)
RE_BACKOUT = re.compile(r"\b(never ?mind|forget (i|it)|not (sure|yet|right now|now)|need (more )?time|come back later|"
                        r"i'?ll (think|be back|return)|maybe later|another time|later\.?$|i misspoke|nothing\.?$|changed my mind|"
                        r"i have to go|i should go|i must go|good ?bye|farewell|not interested|no,? thanks?)\b", re.I)
RE_CHOICE = re.compile(r"\b(kill|spare|join|side with|accept|refuse|decline|agree|deal\b|give|keep|take|let (him|her|them|you) (go|live)|"
                       r"free (him|her|them)|release|arrest|betray|tell (him|her|them)|lie\b|promise|swear|marry|destroy|attack|die\b|"
                       r"death|pay\b|bribe|surrender|i'?ll do it|i'?m in|count me in|count me out|no deal|never\b|yes\b|no\b|fine\b|"
                       r"very well|all right)\b", re.I)
RE_CONT = re.compile(r"^(\.\.\.|go on\.?|and\??|and then\??|continue\.?|what happened( next)?\??|tell me more\.?|i'?m listening\.?|"
                     r"what do you mean\??|what\??|so\??|yes\??|okay\.?|i see\.?|\(.*\))$", re.I)


def _speech_kinds(i):
    ks = set()
    for fn, p1, op, cv, is_or, runon in i.get("conds") or []:
        if fn in ("GetActorValue", "GetBaseActorValue") and p1 == AV_SPEECH and op in (2, 3):
            ks.add("persuade")
        if fn == "GetBribeSuccess" and not (op == 0 and cv == 0.0):
            ks.add("bribe")
        if fn == "GetIntimidateSuccess" and not (op == 0 and cv == 0.0):
            ks.add("intimidate")
    return ks


def deep_analysis(T):
    C = collections.Counter
    sp, fail, lay, single = C(), C(), C(), C()
    sp_ex = collections.defaultdict(list)
    winners = C()
    thr = C()

    def norm(x):
        return re.sub(r"\s+", " ", (x or "").strip().lower())

    def tinfo(key):
        t = T.get(key)
        if not t:
            return None
        infos = t["infos"]
        return dict(script=any(i.get("vmad") for i in infos), script_goodbye=any(i.get("vmad") and (i["flags"] & 1) for i in infos),
                    prompts=set([t.get("full") or ""] + [i.get("rnam") or "" for i in infos]) - {""},
                    first=(t.get("full") or (infos[0].get("rnam") if infos else "") or ""))

    player = [t for t in T.values() if t.get("cat") in (0, 1)]
    for t in player:
        ik = [(i, _speech_kinds(i)) for i in t["infos"]]
        kinds = set().union(*[k for _, k in ik]) if ik else set()
        for k in kinds:
            S = [i for i, ks in ik if k in ks]
            F = [i for i, ks in ik if k not in ks]
            spr = set((i.get("rnam") or t.get("full") or "") for i in S)
            fpr = set((i.get("rnam") or t.get("full") or "") for i in F)
            sp[k + ": topics"] += 1
            winners["%s | %s" % (k, t["plugin"])] += 1
            if F and not (spr & fpr):
                sp[k + ": success and failure prompts DIFFER (menu text reveals the outcome before the click)"] += 1
                if len(sp_ex[k + "_differ"]) < 8:
                    sp_ex[k + "_differ"].append(dict(edid=t.get("edid"), success=sorted(spr)[:2], failure=sorted(fpr)[:2], winner=t["plugin"]))
            s_tag = bool(spr) and all(RE_SPEECHTAG.search(p) for p in spr)
            f_tag = bool(fpr) and all(RE_SPEECHTAG.search(p) for p in fpr)
            sp["%s: success prompt tagged=%s / failure prompt tagged=%s" % (k, s_tag, f_tag if F else "n/a")] += 1
            if not s_tag and len(sp_ex[k + "_untagged"]) < 10:
                sp_ex[k + "_untagged"].append(dict(edid=t.get("edid"), quest=t.get("quest"), prompts=sorted(spr)[:2], origin=t["origin"], winner=t["plugin"]))
            if any(i.get("fds") for i in S):
                sp[k + ": success INFO carries a FavorDialogueScript property (stat/XP/gold handled by the vanilla script)"] += 1
            if any(i["flags"] & 4 for i in S):
                sp[k + ": success INFO is Say Once"] += 1
            if any(i["flags"] & 4 for i in F):
                sp[k + ": failure INFO is Say Once"] += 1
            if any(t["key"] in (i.get("links") or []) for i in F):
                sp[k + ": failure INFO links back to the same topic (retry offered immediately)"] += 1
            if any(i.get("vmad") for i in F):
                fail[k + ": a failure INFO runs a script (failing has consequences)"] += 1
            if any(i["flags"] & 1 for i in F):
                fail[k + ": a failure INFO is Goodbye (conversation ends on failure)"] += 1
            if any(i["flags"] & 1 for i in S):
                fail[k + ": a success INFO is Goodbye"] += 1
            if any(i["flags"] & 0x40 for i in S):
                fail[k + ": a success INFO is Invisible Continue"] += 1
            if not F:
                fail[k + ": topic has no failure INFO at all"] += 1
            if k == "persuade":
                for i in S:
                    for fn, p1, op, cv, is_or, runon in i.get("conds") or []:
                        if fn in ("GetActorValue", "GetBaseActorValue") and p1 == AV_SPEECH:
                            thr[str(cv)] += 1
    # layers
    fp = collections.defaultdict(set)
    miss = []
    for t in player:
        for i in t["infos"]:
            links = i.get("links") or []
            tg = [x for x in (tinfo(l) for l in links) if x]
            if len(links) == 1 and tg:
                x = tg[0]
                single["single-link layers (all)"] += 1
                if i["flags"] & 0x40:
                    single["parent INFO is Invisible Continue (engine auto-plays it, the menu never shows it)"] += 1
                    continue
                single["VISIBLE single-entry layers"] += 1
                p = x["first"].strip()
                kind = "continuer-like" if (not p or RE_CONT.match(p)) else "real sentence"
                single["visible single entry: %s, %s" % (kind, "SCRIPTED" if x["script"] else "unscripted")] += 1
                if x["script_goodbye"]:
                    single["visible single entry: scripted AND goodbye (commit-and-close)"] += 1
                if (i.get("resp") or "").strip().endswith("?") and x["script"]:
                    single["visible single entry: NPC line ends with '?' AND entry is scripted"] += 1
            elif len(links) >= 2 and tg:
                lay["multi-entry layers"] += 1
                ns = sum(1 for x in tg if x["script"])
                nsg = sum(1 for x in tg if x["script_goodbye"])
                texts = [" | ".join(sorted(x["prompts"])) for x in tg]
                cw = any(RE_CHOICE.search(s) for s in texts)
                if ns >= 2:
                    lay[">=2 entries scripted (candidate real choice)"] += 1
                    lay[">=2 scripted: choice-word heuristic %s" % ("HIT" if cw else "MISS")] += 1
                    if not cw and len(miss) < 12:
                        miss.append(dict(quest=t.get("quest"), entries=[s[:70] for s in texts][:4]))
                if nsg >= 2:
                    lay[">=2 entries scripted+goodbye (candidate irreversible)"] += 1
                if ns == 0:
                    lay["no entry scripted (pure conversation hub)"] += 1
                    if cw:
                        lay["no entry scripted BUT choice-word heuristic fires (false alarm)"] += 1
                if any(RE_BACKOUT.search(s) for s in texts):
                    lay["has a back-out style entry"] += 1
                if t["key"] in links:
                    lay["layer re-offers its own parent topic (hub loop)"] += 1
                fp[tuple(sorted(norm(x["first"]) for x in tg))].add(t.get("quest"))
    # journal-quest classification (same idea as Smart Talk's iLinkedQuestFilter=3: quest type != 0 OR quest has objectives)
    jq = C()
    for t in player:
        journal = bool(t.get("quest_type")) or bool(t.get("quest_has_obj"))
        top = bool(t.get("is_branch_start")) and bool((t.get("branch_flags") or 0) & 1)
        jq["player topics owned by a journal quest" if journal else "player topics owned by a dialogue-only quest"] += 1
        if top:
            jq["TOP-LEVEL topics owned by a journal quest" if journal else "TOP-LEVEL topics owned by a dialogue-only quest"] += 1
    pm = collections.defaultdict(set)
    paren = C()
    for t in player:
        for p in set([t.get("full") or ""] + [i.get("rnam") or "" for i in t["infos"]]) - {""}:
            pm[norm(p)].add(t["key"])
            if re.match(r"^\s*\(.*\)\s*$", p):
                paren[norm(p)] += 1
    return dict(
        speech_topics=dict(sorted(sp.items())), speech_failure_shape=dict(sorted(fail.items())), speech_examples=sp_ex,
        speech_winner_plugins=dict(winners.most_common(40)), persuade_thresholds=dict(thr.most_common()),
        single_entry_layers=dict(single), multi_entry_layers=dict(lay), choice_word_misses=miss,
        layer_fingerprints=dict(distinct=len(fp), map_to_single_quest=sum(1 for v in fp.values() if len(v) == 1)),
        prompt_uniqueness=dict(distinct_prompts=len(pm), unique_to_one_topic=sum(1 for v in pm.values() if len(v) == 1),
                               with_runtime_tokens=sum(1 for p in pm if "<" in p),
                               most_shared=[[p[:80], len(v)] for p, v in sorted(pm.items(), key=lambda x: -len(x[1]))[:25]]),
        fully_parenthesised_prompts=dict(paren.most_common(40)),
        journal_quest_split=dict(jq),
    )


# ----------------------------------------------------------------------------------------------------------------
# main
# ----------------------------------------------------------------------------------------------------------------
def main():
    ap = argparse.ArgumentParser()
    default_mo2 = r"F:\Modlists\LoreRim" if os.name == "nt" else "/mnt/f/Modlists/LoreRim"
    ap.add_argument("--mo2", default=default_mo2)
    ap.add_argument("--profile", default="Ultra")
    ap.add_argument("--out", required=True, help="summary json")
    ap.add_argument("--dump", default=None, help="optional full topic dump (gzip jsonl)")
    ap.add_argument("--limit", type=int, default=0, help="debug: only first N active plugins")
    a = ap.parse_args()
    t0 = time.time()

    def log(msg):
        sys.stderr.write("[%6.1fs] %s\n" % (time.time() - t0, msg)); sys.stderr.flush()

    lo = LoadOrder(a.mo2, a.profile, log)
    sc = Scan(lo, log)
    todo = lo.active[:a.limit] if a.limit else lo.active
    for n, p in enumerate(todo):
        path = lo.index.get(p.lower())
        if not path:
            continue
        try:
            sc.plugin(p, path)
        except Exception as ex:  # noqa
            log("ERROR %s: %r" % (p, ex))
        if n % 250 == 0:
            log("%d/%d %s  (DIAL winners %d, INFO winners %d)" % (n, len(todo), p, len(sc.dial), len(sc.info)))
    log("parsed. DIAL winners %d, INFO winners %d" % (len(sc.dial), len(sc.info)))

    # ---- compose topics (winner view) ----------------------------------------------------------------------------
    by_parent = collections.defaultdict(list)
    for k, i in sc.info.items():
        by_parent[i["parent"]].append(k)

    def qname(k):
        q = sc.qust.get(k) if k else None
        return (q or {}).get("edid")

    tag_tot = collections.Counter()                     # winner view: topics (not records) per tag
    tag_plug = collections.defaultdict(collections.Counter)
    tag_ex = collections.defaultdict(list)
    cross = collections.Counter()
    cross_ex = collections.defaultdict(list)
    fan = collections.Counter()
    flagstat = collections.Counter()
    player_topics = 0
    bribe_prompt_forms = collections.Counter()
    subtype_names = collections.Counter()
    rnam_variants = 0
    rnam_variant_ex = []
    toplevel_by_quest = collections.Counter()
    top_start = {}
    for bk, b in sc.dlbr.items():
        if b.get("flags", 0) & 1 and b.get("start"):
            top_start[b["start"]] = b
    dumpf = gzip.open(a.dump, "wt", encoding="utf-8") if a.dump else None
    T = collections.OrderedDict()   # "plugin:FORMID" -> composed topic (winner view), same shape as the dump lines

    for k, d in sc.dial.items():
        cat = d.get("cat")
        infos = [sc.info[i] for i in by_parent.get(k, ())]
        prompts = set()
        if d.get("full"):
            prompts.add(d["full"])
        for i in infos:
            if i.get("rnam"):
                prompts.add(i["rnam"])
        is_player = cat in (0, 1) and bool(prompts)
        if is_player or prompts:
            br = sc.dlbr.get(d.get("branch")) if d.get("branch") else None
            T["%s:%06X" % k] = trec = (dict(
                key="%s:%06X" % k, plugin=d["plugin"], origin=d["origin"], edid=d.get("edid"), full=d.get("full"),
                cat=cat, subtype=d.get("sname"), prio=d.get("prio"), quest=qname(d.get("quest")),
                quest_type=(sc.qust.get(d.get("quest")) or {}).get("qtype"), quest_has_obj=(sc.qust.get(d.get("quest")) or {}).get("has_obj", False),
                quest_key=("%s:%06X" % d["quest"]) if d.get("quest") else None,
                branch=(br or {}).get("edid"), branch_flags=(br or {}).get("flags"),
                is_branch_start=bool(br and br.get("start") == k),
                infos=[dict(key="%s:%06X" % ik, plugin=sc.info[ik]["plugin"], rnam=sc.info[ik].get("rnam"),
                            flags=sc.info[ik].get("flags", 0), reset=sc.info[ik].get("reset", 0),
                            links=["%s:%06X" % x for x in sc.info[ik].get("links", [])],
                            walkaway=("%s:%06X" % sc.info[ik]["walkaway"]) if sc.info[ik].get("walkaway") else None,
                            conds=[[FN.get(c[0], c[0]), c[1], c[2], c[3], c[4], c[5]] for c in sc.info[ik].get("conds", [])],
                            vmad=sc.info[ik].get("vmad", False), fds=sc.info[ik].get("fds", False),
                            favor=sc.info[ik].get("favor"), resp=sc.info[ik].get("resp"))
                       for ik in by_parent.get(k, ())]))
            if dumpf:
                dumpf.write(json.dumps(trec, ensure_ascii=False) + "\n")
        if not is_player:
            continue
        player_topics += 1
        subtype_names[d.get("sname") or "?"] += 1
        if k in top_start:
            toplevel_by_quest[qname(d.get("quest")) or "?"] += 1
        # RNAM variants inside one topic (menu text then depends on which INFO the engine picked)
        rn = set(i["rnam"] for i in infos if i.get("rnam"))
        if len(rn) > 1 or (rn and d.get("full") and len(infos) > len([i for i in infos if i.get("rnam")])):
            rnam_variants += 1
            if len(rnam_variant_ex) < 25:
                rnam_variant_ex.append(dict(topic="%s:%06X" % k, edid=d.get("edid"), full=d.get("full"), rnams=sorted(rn)[:6]))
        # tags (winner view)
        tags = set()
        for text in prompts:
            for kind, norm, rawtag in extract_tags(text):
                tags.add((kind, norm))
                if kind == "paren" and ("gold" in norm or "bribecost" in norm or "septim" in norm):
                    bribe_prompt_forms[norm] += 1
        for kind, norm in tags:
            tk = kind + ":" + norm
            tag_tot[tk] += 1
            tag_plug[tk][d["origin"]] += 1
            if len(tag_ex[tk]) < 4:
                tag_ex[tk].append(dict(text=sorted(prompts)[0][:160], plugin=d["origin"], edid=d.get("edid"), quest=qname(d.get("quest"))))
        # conditions
        ck = set()
        fds = False
        for i in infos:
            ck |= cond_kinds(i.get("conds"))
            fds = fds or i.get("fds", False)
        alltext = " | ".join(sorted(prompts))
        paren_text = " ".join(n for kd, n in tags if kd in ("paren", "brack"))
        for word, kind in (("persuade", "persuade_cond"), ("intimidate", "intimidate_cond"), ("bribe", "bribe_cond")):
            has_tag = bool(SPEECH_WORDS[word].search(paren_text)) if word != "bribe" else bool(
                re.search(r"bribe|<bribecost>", paren_text, re.I))
            has_cond = kind in ck
            if has_tag or has_cond:
                cell = "%s: tag=%d cond=%d" % (word, has_tag, has_cond)
                cross[cell] += 1
                if len(cross_ex[cell]) < 12:
                    cross_ex[cell].append(dict(text=alltext[:200], origin=d["origin"], winner=d["plugin"], edid=d.get("edid"),
                                               quest=qname(d.get("quest")), fds=fds))
        if fds and not (ck & {"persuade_cond", "intimidate_cond", "bribe_cond"}):
            cross["fds_script_without_speech_cond"] += 1
            if len(cross_ex["fds_script_without_speech_cond"]) < 12:
                cross_ex["fds_script_without_speech_cond"].append(dict(text=alltext[:200], origin=d["origin"], edid=d.get("edid")))
        # fan-out + flags per INFO
        for i in infos:
            n = len(i.get("links", []))
            fl = i.get("flags", 0)
            fan["links=%s" % (n if n < 6 else "6+")] += 1
            for bit, nm in ((IF_GOODBYE, "goodbye"), (IF_SAYONCE, "say_once"), (IF_INVISCONT, "invisible_continue"),
                            (IF_WALKAWAY, "walk_away"), (IF_WALKAWAY_INVIS, "walk_away_invisible"), (IF_RANDOM, "random"),
                            (IF_FAVORPTS, "spends_favor_points")):
                if fl & bit:
                    flagstat[nm] += 1
            if i.get("walkaway"):
                flagstat["has_TWAT_walkaway_topic"] += 1
            if i.get("reset"):
                flagstat["has_reset_hours"] += 1
            flagstat["infos_total"] += 1
    if dumpf:
        dumpf.close()

    # ---- per-plugin tag table (physical records, includes override copies) -------------------------------------
    plug_tags = {}
    for p, st in sc.per_plugin.items():
        if st.get("tags"):
            plug_tags[p] = dict(sorted(st["tags"].items(), key=lambda x: -x[1]))

    def winners(d):
        return {k: dict(winner=v[-1][0], value=v[-1][1], chain=[[x[0], x[1]] for x in v]) for k, v in sorted(d.items())}

    out = dict(
        generated=time.strftime("%Y-%m-%d %H:%M:%S"), mo2=a.mo2, profile=a.profile,
        counts=dict(enabled_mods=len(lo.enabled_mods), active_plugins=len(lo.active), active_not_found=lo.not_found[:50],
                    plugins_with_DIAL=sum(1 for s in sc.per_plugin.values() if s["dial"]),
                    DIAL_winners=len(sc.dial), INFO_winners=len(sc.info), player_topics_with_prompt=player_topics,
                    localized_plugins=sc.localized, unresolved_lstrings=dict(sc.unresolved)),
        string_sources={"%s.%s" % k: v[1] for k, v in lo._strings.items()},
        tag_vocabulary=[dict(tag=t, topics=c, by_origin_plugin=dict(tag_plug[t].most_common(15)), examples=tag_ex[t])
                        for t, c in tag_tot.most_common()],
        speech_crosstab=dict(cross), speech_crosstab_examples=cross_ex,
        bribe_prompt_forms=dict(bribe_prompt_forms.most_common()),
        rnam_variant_topics=rnam_variants, rnam_variant_examples=rnam_variant_ex,
        info_link_fanout=dict(fan), info_flag_stats=dict(flagstat),
        player_topic_subtypes=dict(subtype_names.most_common()),
        toplevel_topics_by_quest=dict(toplevel_by_quest.most_common(60)),
        scene_action_types=dict((str(k), v) for k, v in sc.scen_actions.items()),
        scene_action_types_nonstandard_plugins={p: dict((str(k), v) for k, v in c.items() if k > 2)
                                                for p, c in sc.scen_actions_by_plugin.items() if any(k > 2 for k in c)},
        gmst_speech=winners(sc.gmst), glob_speech={k: dict(winner=v[-1][0], value=v[-1][1], form=v[-1][2],
                                                           chain=[[x[0], x[1]] for x in v]) for k, v in sorted(sc.glob.items())},
        per_plugin_dialogue=dict((p, dict(dial=s["dial"], info=s["info"], prompts=s["dial_with_prompt"], rnam=s["info_with_rnam"],
                                          localized=s["localized"], mod=s["mod"]))
                                 for p, s in sc.per_plugin.items() if s["dial"] or s["info"]),
        per_plugin_tags=plug_tags,
        deep=deep_analysis(T),
    )
    with open(a.out, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    log("done -> %s" % a.out)


if __name__ == "__main__":
    main()
