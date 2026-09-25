#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build_prompt_index.py - LoreRim Glue, Phase 2 OFFLINE PROMPT INDEX (design 4.2, plan 5.1).

READ-ONLY. It reads the MO2 profile, the enabled mods' plugins and ONE uncompressed archive
(Skyrim - Interface.bsa, for the five localized vanilla masters' string tables). It writes exactly one
file: the ndjson index you name with --out. It never writes a plugin, never touches F:\\Modlists
outside a read, and needs nothing installed beyond the stdlib (zlib only).

Derived from research/p2_speech_tags.py (its reader: BSA, LoadOrder, records/subrecords, parse_strings).
That research script is NOT modified and NOT imported - this file is standalone on purpose, so a later
edit of the research scanner can never change what the server indexes.

What it produces, per PLAYER dialogue prompt (DIAL category 0/1 INFO with a prompt text):
  norm      the normalised prompt, the key the server matches a LIVE menu line against
  pattern   the same with <Token>s turned into wildcards (576 token prompts in this load order)
  kind      persuade | intimidate | bribe | '' - decided by CONDITION FUNCTION, never by a text tag
  variant   success | failure | na   (the check INFO vs its unconditional sibling)
  scripted  the INFO carries a VMAD fragment
  compound  the check sits in an OR-chain with a non-check condition (weapon skill, level, perk, amulet)
  twat/crit walk-away target and the two crit grades (2 = LETHAL: DGCrimeResistArrest)
  cost      a parsed gold price (-1 = the price is a <Token>: read it from the live text)
  resp      the NPC's response, with DNAM shared responses resolved
  flags     goodbye / sayonce / walkaway / invisible-continue / random
  links     child topics (TCLT) -> and from them the layer fingerprints
plus a KINDS CENSUS in the header: every distinct (function id, actor value, comparand kind, comparand)
tuple seen on a player INFO, with counts and example topics. The census is the answer to "which speech
check kinds does THIS load order actually have"; it is never assumed.

Usage (inside WSL - Windows has no python3):
  python3 tools/build_prompt_index.py --stats
  python3 tools/build_prompt_index.py --out server/lorerim_glue/data/prompt_index.ndjson
  python3 tools/build_prompt_index.py --stats --json-stats stats.json     # machine-readable census
  python3 tools/build_prompt_index.py --grep "rent a room"                # look one prompt up
Options: --mo2 (default /mnt/f/Modlists/LoreRim) --profile (Ultra) --quiet --limit-plugins N
"""
import os, sys, struct, zlib, json, re, time, argparse, collections, hashlib

VERSION = 1                               # LRG_PROMPT_INDEX_VERSION - must match lib/lrg_prompt_index.php
BASE_MASTERS = ["Skyrim.esm", "Update.esm", "Dawnguard.esm", "HearthFires.esm", "Dragonborn.esm"]
PLUGIN_EXT = (".esp", ".esm", ".esl")
AV_SPEECH = 17                            # ActorValue index of Speechcraft
LETHAL_TWAT = ("dgcrimeresistarrest",)    # design F11 / 2.7: the ~18 winner-state INFOs that mean arrest

# CTDA function ids (CommonLibSSE-NG TESCondition.h / FUNCTION_DATA::FunctionID).
FN = {
    14: "GetActorValue", 47: "GetItemCount", 48: "GetGold", 50: "GetTalkedToPC", 58: "GetStage",
    59: "GetStageDone", 69: "fn69", 72: "GetIsID", 74: "GetGlobalValue", 80: "GetLevel", 91: "GetIsRace",
    100: "GetIsClass", 116: "IsIntimidatedbyPlayer", 172: "GetTalkedToPCParam", 182: "GetEquipped",
    225: "GetPersuasionNumber", 247: "GetIsSex", 248: "GetIsCurrentPackage", 249: "IsInDialogueWithPlayer",
    255: "GetFactionRank", 277: "GetBaseActorValue", 315: "GetTotalPersuasionNumber", 353: "IsInFaction",
    359: "GetFactionRankDifference", 365: "GetPlayerInSameFaction", 375: "GetCrimeGoldViolent",
    402: "IsBribedbyPlayer", 403: "GetRelationshipRank", 448: "HasPerk", 459: "GetCrimeGold",
    476: "GetIsPlayableRace", 494: "GetPermanentActorValue", 507: "GetIsAlignment", 566: "GetIsAliasRef",
    597: "GetKeywordItemCount", 629: "GetVMQuestVariable", 630: "GetVMScriptVariable", 635: "IsInFavorState",
    640: "GetActorValuePercent", 653: "GetBribeAmount", 654: "GetBribeSuccess", 655: "GetIntimidateSuccess",
    682: "WornHasKeyword",
}
# Functions whose param1 is a FORM: resolved to <master>:<id> at parse time so the census can name it.
FORM_PARAM_FN = {47, 48, 72, 74, 91, 100, 172, 182, 248, 255, 353, 359, 365, 448, 566, 597, 682}
AMULET_FLST = 0x0F759C    # FLST TGAmuletofArticulationList - the OR-branch on ~257 vanilla persuade INFOs
# The functions the census keeps FULL tuples for: every one that could implement a speech-ish check,
# plus the crime-gold pair the design names. Everything else is counted by function id only.
CENSUS_FN = {14, 45, 47, 48, 84, 91, 100, 116, 172, 225, 255, 277, 315, 353, 359, 375, 402, 403, 448,
             459, 476, 494, 507, 597, 635, 640, 653, 654, 655, 682}
CHECK_AV_FN = {14, 494, 640}              # an actor-value comparison IS a check when the AV is Speechcraft
BASE_AV_FN = {277}                        # GetBaseActorValue on Speechcraft = a TRAINER CAP, not a check
# Conditions that only SCOPE an INFO to a speaker: an INFO carrying nothing else always passes for that NPC.
SPEAKER_FN = {72, 91, 100, 172, 247, 353, 476, 507, 566, 365, 255}

# DIAL SNAM subtypes a player prompt may carry. Anything else (HELO, GBYE, IDLE, FORC ...) can never be a
# check: without this filter 558 GDO guard-HELLO INFOs are indexed as persuasion (plan 5.1 / REQ R2-C1).
CHECK_SUBTYPES = {"CUST", "", "FAVO", "FAVR", "FVGR", "FVRE", "IREL", "RELA"}

IF_GOODBYE, IF_RANDOM, IF_SAYONCE, IF_RANDOMEND, IF_INVISCONT, IF_WALKAWAY, IF_WALKAWAY_INVIS, IF_FAVORPTS = \
    0x0001, 0x0002, 0x0004, 0x0020, 0x0040, 0x0080, 0x0100, 0x4000

RE_TOKEN = re.compile(r"<([^<>]{1,60})>")
RE_LEAD_TAG = re.compile(r"^\s*[\(\[][^)\]]{1,40}[\)\]]\s*")
RE_TAIL_TAG = re.compile(r"\s*[\(\[][^)\]]{1,40}[\)\]]\s*[.!?\u2026]*\s*$")
RE_DIGITS = re.compile(r"\d[\d,\.]*")
RE_KEEP = re.compile(r"[^a-z0-9#<>' ]+")
RE_WS = re.compile(r"\s+")
# plan 5.1 / BRANCH C3: matches every priced form and rejects "(3 days left)", "(takes 6 hours)", "(goldfish)"
RE_PRICE = re.compile(r"\(([^()]*?)(\d[\d,\.]*)\s*(gold|septims?)\b[^()]*\)\s*\.?\s*$", re.I)
# the same sentence with the number replaced by a token: "(<BribeCost> gold)", "(<Global=X> gold)"
RE_PRICE_TOKEN = re.compile(r"\([^()]*<[^<>]+>[^()]*\b(gold|septims?)\b[^()]*\)\s*\.?\s*$", re.I)
RE_BRAWL = re.compile(r"\(\s*brawl\s*\)\s*\.?\s*$", re.I)
RE_PLACEHOLDER = re.compile(r"^\s*(?:\(\s*(?:invisible\s+continue|forcegreet|force\s*greet|continue|silence|no\s*text)\s*\)"
                            r"|[A-Za-z0-9_]{6,}\d{2,}|<[^<>]+>)\s*$", re.I)


# ---------------------------------------------------------------------------------------------------- helpers
def dec(b):
    if b is None:
        return None
    b = b.split(b"\x00", 1)[0]
    try:
        return b.decode("utf-8")
    except UnicodeDecodeError:
        return b.decode("cp1252", "replace")


class Unreadable(Exception):
    """A file that would need a compression module this build refuses to install (LZ4 / decision D6)."""


class BSA:
    """Read-only BSA directory + extraction. zlib (v104) only; an LZ4-compressed entry raises Unreadable."""

    def __init__(self, path):
        self.path = path
        self.files = {}
        with open(path, "rb") as f:
            magic, ver, off, aflags, nfold, nfile, lfold, lfile, fflags = struct.unpack("<4sIIIIIIII", f.read(36))
            if magic != b"BSA\x00":
                raise ValueError("not a BSA: " + path)
            self.ver, self.aflags = ver, aflags
            frs = 24 if ver == 105 else 16
            raw = f.read(frs * nfold)
            counts = []
            for i in range(nfold):
                if ver == 105:
                    h, cnt, _pad, o = struct.unpack_from("<QIIQ", raw, i * frs)
                else:
                    h, cnt, o = struct.unpack_from("<QII", raw, i * frs)
                counts.append(cnt)
            recs = []
            for cnt in counts:
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
        if self.aflags & 0x100:                       # embedded file name
            ln = data[0]
            data = data[1 + ln:]
        if comp:
            if self.ver != 104:
                raise Unreadable("LZ4-compressed entry in %s (%s)" % (os.path.basename(self.path), inner))
            data = zlib.decompress(data[4:])
        return data


def parse_strings(data, kind):
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
        if size == 0 and t != b"GRUP":
            break
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


# ---------------------------------------------------------------------------------------------------- load order
class LoadOrder:
    def __init__(self, mo2, profile, log):
        self.mo2, self.log = mo2, log
        prof = os.path.join(mo2, "profiles", profile)
        self.index, self.mod_of = {}, {}
        mods = []
        with open(os.path.join(prof, "modlist.txt"), encoding="utf-8", errors="replace") as f:
            modlist_raw = f.read()
        for line in modlist_raw.splitlines():
            if line.startswith("+"):
                mods.append(line[1:])
        self.enabled_mods = mods
        roots = [("<overwrite>", os.path.join(mo2, "overwrite"))]
        roots += [(m, os.path.join(mo2, "mods", m)) for m in mods]        # modlist.txt: highest priority FIRST
        roots += [("<Stock Game>", os.path.join(mo2, "Stock Game", "Data"))]
        self.roots = roots
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
        star, mentioned = set(), set()
        with open(os.path.join(prof, "plugins.txt"), encoding="utf-8", errors="replace") as f:
            plugins_raw = f.read()
        for line in plugins_raw.splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if line.startswith("*"):
                star.add(line[1:].lower())
            mentioned.add(line.lstrip("*").lower())
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
                if line and not line.startswith("#"):
                    order.append(line)
        self.active = [p for p in order if p.lower() in star or p.lower() in implicit]
        self.not_found = [p for p in self.active if p.lower() not in self.index]
        # the index is rebuilt when this hash changes (plan 5.1)
        self.hash = hashlib.md5((plugins_raw + "\n" + modlist_raw).encode("utf-8", "replace")).hexdigest()
        log("enabled mods: %d (unreadable folders: %d); indexed files: %d; active plugins: %d (missing files: %d)"
            % (len(mods), missing, len(self.index), len(self.active), len(self.not_found)))
        self._bsa, self._strings = {}, {}
        self.unreadable = []                 # [(plugin, reason)] - decision D6: engine-only, never guessed

    def bsa(self, name):
        name = name.lower()
        if name not in self._bsa:
            p = self.index.get(name)
            try:
                self._bsa[name] = BSA(p) if p else None
            except Exception as ex:
                self.log("BSA error %s: %s" % (name, ex))
                self._bsa[name] = None
        return self._bsa[name]

    def strings(self, plugin, kind, lang="english"):
        key = (plugin.lower(), kind)
        if key in self._strings:
            return self._strings[key]
        base = os.path.splitext(plugin)[0].lower()
        fn = "%s_%s.%s" % (base, lang, kind)
        res, src = {}, None
        p = self.index.get("strings/" + fn)
        if p:
            with open(p, "rb") as f:
                res, src = parse_strings(f.read(), kind), "loose:" + os.path.basename(p)
        else:
            for cand in (base + ".bsa", "skyrim - interface.bsa", "skyrim - patch.bsa", "skyrim - misc.bsa"):
                b = self.bsa(cand)
                inner = "strings\\" + fn
                if b and inner in b.files:
                    try:
                        res, src = parse_strings(b.read(inner), kind), "bsa:" + os.path.basename(b.path)
                    except Unreadable as ex:
                        src = "unreadable:" + str(ex)
                    break
        self._strings[key] = (res, src)
        return self._strings[key]


# ---------------------------------------------------------------------------------------------------- text rules
def norm_prompt(text):
    """THE key rule. Mirrored byte for byte by lrgPromptNorm() in lib/lrg_prompt_index.php - change both.
    1 lower  2 drop up to two LEADING (tag)/[tag]  3 drop up to two TRAILING tags (a price tag included)
    4 <Token> -> <t>  5 digits -> #  6 keep a-z0-9#<>' and space  7 collapse."""
    if not text:
        return ""
    s = text.lower()
    for _ in range(2):
        s2 = RE_LEAD_TAG.sub("", s, count=1)
        if s2 == s:
            break
        s = s2
    for _ in range(2):
        s2 = RE_TAIL_TAG.sub("", s, count=1)
        if s2 == s:
            break
        s = s2
    s = RE_TOKEN.sub(" <t> ", s)
    s = RE_DIGITS.sub("#", s)
    s = RE_KEEP.sub(" ", s)
    return RE_WS.sub(" ", s).strip()


def pattern_of(text, norm):
    """A token prompt becomes a wildcard pattern: '<t>' -> '*'. '' when the prompt carries no token."""
    if "<t>" not in norm:
        return ""
    return RE_WS.sub(" ", norm.replace("<t>", "*")).strip()


def parse_cost(text):
    """(cost, dynamic). cost > 0 = a literal price; -1 = the price is a token, read it live; 0 = no price."""
    if not text:
        return 0, False
    m = RE_PRICE.search(text)
    if m:
        try:
            return int(re.sub(r"[^0-9]", "", m.group(2))), False
        except ValueError:
            return 0, False
    if RE_PRICE_TOKEN.search(text):
        return -1, True
    return 0, False


# ---------------------------------------------------------------------------------------------------- the scan
class Scan:
    def __init__(self, lo, log):
        self.lo, self.log = lo, log
        self.dial = {}          # key -> topic (winner = last plugin in load order that carries it)
        self.info = {}          # key -> info  (same)
        self.info_of_topic = collections.defaultdict(list)
        self.qust = {}
        self.edid = {}          # key -> EditorID, for the small groups we resolve (GLOB/PERK/KYWD/FACT/VTYP/CLAS/RACE)
        self.per_plugin = collections.OrderedDict()
        self.localized = []
        self.unresolved = collections.Counter()
        self.kinds = collections.defaultdict(lambda: {"n": 0, "ex": []})
        self.fn_census = collections.Counter()
        self.subtype_census = collections.Counter()
        self.check_subtype_rejected = collections.Counter()
        self.lost = collections.Counter()   # [0.5.0] player INFOs this plugin defined that another plugin wins

    # -- strings --------------------------------------------------------------------------------------
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

    # -- one plugin -----------------------------------------------------------------------------------
    def plugin(self, name, path):
        st = dict(dial=0, info=0, player_info=0, localized=False, mod=self.lo.mod_of.get(name.lower()), strings=None)
        with open(path, "rb") as f:
            hdr = f.read(24)
            if hdr[:4] != b"TES4":
                self.log("not a plugin: " + path)
                return
            size, flags = struct.unpack_from("<II", hdr, 4)
            tes4 = f.read(size)
            localized = bool(flags & 0x80)
            st["localized"] = localized
            masters = [dec(d).lower() for t, d in subrecords(tes4) if t == b"MAST"]
            me = name.lower()

            def key(fid):
                i = fid >> 24
                return "%s:%06X" % ((masters[i] if i < len(masters) else me), fid & 0xFFFFFF)

            if localized:
                tab, src = self.lo.strings(name, "strings")
                st["strings"] = src
                if not tab:
                    self.lo.unreadable.append((name, src or "no string table found"))
            fsize = os.fstat(f.fileno()).st_size
            while f.tell() + 24 <= fsize:
                gh = f.read(24)
                if gh[:4] != b"GRUP":
                    break
                gsize, = struct.unpack_from("<I", gh, 4)
                if gsize < 24:
                    break
                label = gh[8:12]
                if label in (b"DIAL", b"QUST", b"GLOB", b"PERK", b"KYWD", b"FACT", b"VTYP", b"CLAS", b"RACE", b"FLST"):
                    buf = f.read(gsize - 24)
                    if label == b"DIAL":
                        self.g_DIAL(name, localized, key, buf, st)
                    elif label == b"QUST":
                        self.g_QUST(name, localized, key, buf, st)
                    else:
                        self.g_EDID(key, buf)
                else:
                    f.seek(gsize - 24, 1)
        if localized:
            self.localized.append(name)
        self.per_plugin[name] = st

    def g_EDID(self, key, buf):
        for r in records(buf, 0, len(buf)):
            if r[0] != "REC":
                continue
            for t, d in subrecords(r[4]):
                if t == b"EDID":
                    self.edid[key(r[3])] = dec(d)
                    break

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
                    qtype = struct.unpack_from("<I", d, 8)[0]
                elif t == b"QOBJ":
                    has_obj = True
                    break
                elif t == b"ANAM":
                    break
            k = key(r[3])
            old = self.qust.get(k, {})
            self.qust[k] = dict(edid=edid or old.get("edid"), full=full if full is not None else old.get("full"),
                                qtype=qtype if qtype is not None else old.get("qtype"),
                                has_obj=has_obj or old.get("has_obj", False))
            if edid:
                self.edid[k] = edid

    def g_DIAL(self, name, localized, key, buf, st):
        for r in records(buf, 0, len(buf)):
            if r[0] == "REC" and r[1] == b"DIAL":
                o = dict(plugin=name)
                for t, d in subrecords(r[4]):
                    if t == b"EDID":
                        o["edid"] = dec(d)
                    elif t == b"FULL":
                        o["full"] = self.lstr(name, localized, d)
                    elif t == b"QNAM":
                        o["quest"] = key(struct.unpack("<I", d[:4])[0])
                    elif t == b"BNAM":
                        o["branch"] = key(struct.unpack("<I", d[:4])[0])
                    elif t == b"PNAM":
                        o["prio"] = struct.unpack("<f", d[:4])[0]
                    elif t == b"DATA" and len(d) >= 4:
                        o["tflags"], o["cat"], o["subnum"] = struct.unpack("<BBH", d[:4])
                    elif t == b"SNAM":
                        o["snam"] = d[:4].decode("cp1252", "replace")
                k = key(r[3])
                prev = self.dial.get(k)
                if prev:                                  # a later plugin wins, but keeps what it does not say
                    merged = dict(prev)
                    merged.update({kk: vv for kk, vv in o.items() if vv is not None})
                    merged["origin"] = prev.get("origin", prev.get("plugin"))
                    o = merged
                else:
                    o["origin"] = name
                self.dial[k] = o
                st["dial"] += 1
                if o.get("edid"):
                    self.edid[k] = o["edid"]
            elif r[0] == "GRP" and r[2] == 7:
                parent = key(struct.unpack("<I", r[1])[0])
                order = 0
                for q in records(buf, r[3], r[4]):
                    if q[0] != "REC" or q[1] != b"INFO":
                        continue
                    self._info(name, localized, key, q, parent, order, st)
                    order += 1

    def _info(self, name, localized, key, q, parent, order, st):
        o = dict(plugin=name, parent=parent, order=order)
        conds, links, resp = [], [], None
        for t, d in subrecords(q[4]):
            if t == b"ENAM" and len(d) >= 4:
                o["iflags"], o["reset"] = struct.unpack("<HH", d[:4])
            elif t == b"RNAM":
                o["rnam"] = self.lstr(name, localized, d)
            elif t == b"TCLT":
                links.append(key(struct.unpack("<I", d[:4])[0]))
            elif t == b"DNAM":
                o["shared"] = key(struct.unpack("<I", d[:4])[0])
            elif t == b"TWAT":
                o["twat"] = key(struct.unpack("<I", d[:4])[0])
            elif t == b"PNAM":
                o["prev"] = key(struct.unpack("<I", d[:4])[0])
            elif t == b"CNAM" and len(d) >= 1:
                o["favor"] = d[0]
            elif t == b"NAM1" and resp is None:
                resp = self.lstr(name, localized, d, ("ilstrings", "dlstrings", "strings")) or ""
            elif t == b"VMAD":
                o["vmad"] = True
            elif t == b"CTDA" and len(d) >= 28:
                op = d[0]
                fn, = struct.unpack_from("<H", d, 8)
                p1, p2, runon = struct.unpack_from("<III", d, 12)
                if op & 0x04:
                    cv = ["G", key(struct.unpack_from("<I", d, 4)[0])]
                else:
                    cv = ["L", round(struct.unpack_from("<f", d, 4)[0], 4)]
                pk = key(p1) if (fn in FORM_PARAM_FN and p1) else ""
                conds.append([fn, p1, p2, (op >> 5), cv, bool(op & 1), runon, pk])
        o["conds"] = conds
        o["links"] = links
        if resp:
            o["resp"] = resp
        k = key(q[3])
        prev = self.info.get(k)
        o["origin"] = prev.get("origin", prev.get("plugin")) if prev else name
        if k not in self.info:
            self.info_of_topic[parent].append(k)
        elif prev:
            # [0.5.0 / E5] LOST TO OVERRIDE is its own column in the coverage report, and it is NOT a gap:
            # the record exists and IS covered - by whichever plugin wins it. Collapsing it into
            # "not indexed" is how a coverage report lies. Only player topics are counted, so the number
            # means what the column name says. The parent DIAL is always parsed before its INFO group.
            if (self.dial.get(parent) or {}).get("cat") in (0, 1):
                self.lost[prev.get("plugin") or "?"] += 1
        self.info[k] = o
        st["info"] += 1

    # -- second pass: resolve, classify, emit ---------------------------------------------------------
    def resolve(self):
        """DNAM shared responses, TWAT EditorIDs, PNAM order, the TCLT child set, check kinds, variants."""
        # DNAM shared response (BRANCH D1/D2: without this 21% of player INFOs look silent)
        shared_hits = 0
        for k, inf in self.info.items():
            if inf.get("resp"):
                continue
            tgt = inf.get("shared")
            seen = set()
            while tgt and tgt in self.info and tgt not in seen:
                seen.add(tgt)
                src = self.info[tgt]
                if src.get("resp"):
                    inf["resp"] = src["resp"]
                    inf["resp_shared"] = 1
                    shared_hits += 1
                    break
                tgt = src.get("shared")
        # every topic that is the TCLT target of some INFO is NOT a root topic
        self.linked = set()
        for inf in self.info.values():
            for t in inf.get("links") or []:
                self.linked.add(t)
        # PNAM re-ordering (BRANCH B6). INFO PNAM points at the PREVIOUS info; the engine evaluates in that
        # order and shows the first INFO whose conditions pass. Where the chain is complete it wins over file
        # order; where it is not, file order stands and 'pnam_chain' says how often that happened.
        self.pnam_full = self.pnam_partial = 0
        for tkey, ikeys in self.info_of_topic.items():
            inside = set(ikeys)
            by_prev = {}
            for ik in ikeys:
                p = self.info[ik].get("prev")
                by_prev.setdefault(p if p in inside else None, []).append(ik)
            chain, cur = [], None
            seen = set()
            while True:
                nxt = [x for x in by_prev.get(cur, []) if x not in seen]
                if not nxt:
                    break
                ik = nxt[0]
                chain.append(ik)
                seen.add(ik)
                cur = ik
            if len(chain) == len(ikeys):
                self.pnam_full += 1
                for n, ik in enumerate(chain):
                    self.info[ik]["order"] = n
            else:
                self.pnam_partial += 1
        return shared_hits

    def cond_chains(self, conds):
        """Bethesda's OR flag means 'or with the NEXT condition'. Returns a list of chains (lists of conds)."""
        chains, cur = [], []
        for c in conds:
            cur.append(c)
            if not c[5]:
                chains.append(cur)
                cur = []
        if cur:
            chains.append(cur)
        return chains

    def cond_tuple(self, c):
        fn, p1, p2, op, cv, orf, runon, pk = c
        name = FN.get(fn, "fn%d" % fn)
        if fn in CHECK_AV_FN or fn in BASE_AV_FN:
            av = str(p1)
        else:
            av = self.edid.get(pk, "") if pk else ""
        kindc, val = cv[0], cv[1]
        if kindc == "G":
            val = self.edid.get(val, val)
        return (fn, name, av, kindc, str(val), op)

    def cond_row(self, c):
        """The compact condition a report can read: [fn, av-or-form, op, 'G|L', comparand, or]."""
        fn, p1, p2, op, cv, orf, runon, pk = c
        val = self.edid.get(cv[1], cv[1]) if cv[0] == "G" else cv[1]
        par = p1 if (fn in CHECK_AV_FN or fn in BASE_AV_FN) else (self.edid.get(pk) or pk or 0)
        return [FN.get(fn, "fn%d" % fn), par, op, cv[0], val, 1 if orf else 0]

    @staticmethod
    def is_amulet(c):
        """GetEquipped on FLST TGAmuletofArticulationList: the OR-branch that sits on most vanilla persuade
        INFOs. It is NOT what 'compound' means (design: a weapon skill / level / race / perk instead)."""
        return c[0] == 182 and (c[1] & 0xFFFFFF) == AMULET_FLST

    @staticmethod
    def is_check_cond(c):
        return c[0] in (654, 655) or (c[0] in CHECK_AV_FN and c[1] == AV_SPEECH)

    @staticmethod
    def speaker_only(conds):
        """True when every condition merely scopes the INFO to a speaker / race / sex / faction, i.e. the INFO
        is UNCONDITIONAL for the NPC who shows it. That is what makes a later check INFO unreachable."""
        for c in conds:
            if c[0] not in SPEAKER_FN:
                return False
        return True

    def classify(self, k, inf, topic):
        """kind/variant/compound for ONE info. kind is decided by condition FUNCTION only (owner addendum 5a)."""
        snam = (topic.get("snam") or "").strip()
        subtype_ok = snam in CHECK_SUBTYPES
        kind, compound, base_av, amulet = "", False, False, False
        for chain in self.cond_chains(inf.get("conds") or []):
            found = ""
            for c in chain:
                fn, p1 = c[0], c[1]
                if fn == 654:
                    found = "bribe"
                elif fn == 655:
                    found = "intimidate"
                elif fn in CHECK_AV_FN and p1 == AV_SPEECH:
                    found = "persuade"
                elif fn in BASE_AV_FN and p1 == AV_SPEECH:
                    base_av = True
            if not found:
                continue
            if not subtype_ok:
                self.check_subtype_rejected[snam or "(none)"] += 1
                continue
            kind = kind or found
            for c in chain:
                if self.is_amulet(c):
                    amulet = True
                elif not self.is_check_cond(c):
                    compound = True          # a weapon skill / level / race / perk passes INSTEAD of the check
        return kind, compound, base_av, amulet


# ---------------------------------------------------------------------------------------------------- DSD
def scan_dsd(lo, log):
    """SKSE/Plugins/DynamicStringDistributor/**/*.json. A DIAL FULL / INFO RNAM / INFO NAM1 entry means the
    displayed text is NOT the plugin text -> FAIL LOUDLY (plan 5.1 / REQ R2-A4)."""
    out = {"files": 0, "folders": 0, "types": collections.Counter(), "dialogue": [], "roots": []}
    for name, root in lo.roots:
        base = os.path.join(root, "SKSE", "Plugins", "DynamicStringDistributor")
        if not os.path.isdir(base):
            continue
        out["roots"].append(name)
        for dirpath, _dirs, files in os.walk(base):
            hit = False
            for fn in files:
                if not fn.lower().endswith(".json"):
                    continue
                hit = True
                out["files"] += 1
                try:
                    with open(os.path.join(dirpath, fn), encoding="utf-8-sig", errors="replace") as f:
                        data = json.load(f)
                except Exception as ex:
                    out["types"]["(unparsable)"] += 1
                    log("DSD: cannot parse %s: %s" % (fn, ex))
                    continue
                for ent in (data if isinstance(data, list) else [data]):
                    if not isinstance(ent, dict):
                        continue
                    t = str(ent.get("type", "?"))
                    out["types"][t] += 1
                    tl = t.upper().replace("_", " ")
                    if tl.startswith("DIAL") or tl.startswith("INFO"):
                        out["dialogue"].append({"type": t, "file": fn, "form": ent.get("form_id", "")})
            if hit:
                out["folders"] += 1
    out["types"] = dict(out["types"])
    return out


# ---------------------------------------------------------------------------------------------------- main
# ======================================================================================================
# [0.5.0] E6 - THE SERVICE CENSUS, and E5 - THE PER-PLUGIN COVERAGE REPORT
# ======================================================================================================
RE_SERVICE_TOPIC = re.compile(r"(fasttravel|carriage|ferry|boat|stable)", re.I)
RE_PLACE_ONLY = re.compile(r"^\s*([A-Z][A-Za-z'’\- ]{2,34}?)\s*\.?\s*(?:\((?:[^()]*)\))?\s*\.?\s*$")
# A skill name in dialogue is CAPITALISED ("Can you train me in Alchemy?"), which is what separates a real
# skill from "teach me about the war". 'learn' is deliberately NOT a trigger: it matched half of Skyrim.
# [0.5.0 fix pass / S-6] Two things were wrong and the owner's own named example proved both.
#   1. [A-Z][a-z]{2,14} cannot cross a hyphen, so "I need training in One-Handed." yielded the skill
#      name "One" - and "One" is what shipped in service_catalog.json.
#   2. The trigger list missed the two commonest vanilla / USSEP forms, "train me to <Skill>" and
#      "training in the art of <Skill>", so Block, Sneak, Pickpocket, Speech and Two-Handed were absent
#      from the census altogether.
# The capture now allows ' and - inside a word, and an interior capital (Two-Handed, One-Handed).
# The TRIGGER is case-insensitive through a SCOPED (?i:...) group - a sentence starts with a capital
# ("Train me to be a better Pickpocket.") - while the CAPTURE stays case-sensitive on purpose, because a
# leading capital is the whole thing that separates a real skill from "teach me about the war".
# A plain re.I on the pattern would have made [A-Z] match lowercase and destroyed that test.
RE_TRAIN = re.compile(r"(?i:train me in|train me to(?:\s+(?:be|a|an|better|use|become))*|train you in"
                      r"|teach me|training in the art of|training in|lessons in)"
                      r"\s+(?i:the\s+|a\s+|an\s+|better\s+)*"
                      r"([A-Z][A-Za-z'\u2019-]{2,16}(?:\s+[A-Z][A-Za-z'\u2019-]{2,16})?)"
                      r"(?i:\s+(?:magic|skill|spells?|weapons?|armor|armour))?\b")
TRAIN_STOP = {"about", "all", "any", "a", "an", "the", "it", "that", "this", "something", "more", "how",
              "what", "you", "your", "me", "my", "everything", "nothing", "some"}
# First words a PLACE never has. A carriage topic also carries the courtesies that surround the ride.
DEST_STOP = {"i", "im", "id", "ive", "ill", "we", "you", "excuse", "wait", "hold", "never", "nevermind",
             "yes", "no", "ok", "okay", "alright", "sorry", "thanks", "thank", "goodbye", "farewell",
             "nothing", "forget", "let", "just", "actually", "maybe", "well", "what", "how", "where",
             "who", "why", "and", "but", "then", "so", "take", "give", "show", "tell", "here", "there"}
RE_GLOBAL_TOKEN = re.compile(r"<Global=([^<>]{1,40})>", re.I)
RE_CRIME_TOPIC = re.compile(r"^DGCrime", re.I)


def build_service_catalog(rows, layers, lo):
    """
    Everything the service half of E6 needs, derived from the index that was just built.

    destinations  a slot NAME the player can say out loud. Taken from the ENTRY TEXT of a row that sits
                  on a service topic (KmodFastTravel*, *Carriage*, *Ferry*, *Boat*) or that is a bare
                  "<Place>." with a price, on a non-top-level topic. The live layer is still the only
                  authority at run time - this list exists so the server can tell a destination it has
                  NEVER heard of from one this list knows but this driver does not serve (E7).
    skills        the object of "train me in X" / "teach me X". Requiem and LoreRim rename skills, so
                  the vanilla 18 are never assumed.
    price_globals every distinct <Global=...> token and the plugin that owns it. These are TEMPLATES,
                  not prices - which is exactly why a price may only ever come from a live entry.
    crime_topics  the DGCrime* family and the walk-away targets its rows point at: the LETHAL set that
                  extends dialogue.crit.lethal_twat. Never hand-typed.
    """
    dest, skills, globs, crime, plugins = {}, {}, {}, {}, collections.Counter()
    travel = set()      # destinations that really come from a carriage / ferry topic (E7 uses only these)
    # "a price list" is a LAYER property, not a row property: >= 3 siblings that are one to three words.
    # Without this the census swallowed every HearthFires house part and every one-word "Alright."
    short_layer_norms = set()
    for l in (layers or {}).values():
        shorts = [n for n in l.get("norms", []) if 0 < len(n.split()) <= 3]
        if len(shorts) >= 3:
            short_layer_norms.update(shorts)
    for r in rows:
        topic = r.get("topic") or ""
        txt = (r.get("txt") or "").strip()
        plug = r.get("plugin") or "?"
        is_service_topic = bool(RE_SERVICE_TOPIC.search(topic))
        # --- price globals (a template, never a price)
        for m in RE_GLOBAL_TOKEN.finditer(txt):
            tok = "<Global=%s>" % m.group(1)
            globs.setdefault(tok, {"token": tok, "plugin": plug, "n": 0})
            globs[tok]["n"] += 1
        # --- destinations. A destination is always a SUB-entry under the hub ("I'd like to hire your
        # carriage." is the hub and is toplevel), and it never starts with a pronoun or an interjection -
        # a carriage topic also carries "Excuse me." and "I'm ready to go.", which are not places.
        if r.get("toplevel"):
            pass
        elif is_service_topic or (r.get("cost") and r.get("norm") in short_layer_norms):
            m = RE_PLACE_ONLY.match(txt)
            if m:
                name = m.group(1).strip()
                first = name.split()[0].lower().replace("'", "").replace("’", "")
                # a whole sentence is not a destination, and neither is a courtesy
                if 2 < len(name) <= 34 and len(name.split()) <= 4 and first not in DEST_STOP:
                    d = dest.setdefault(name, {"name": name, "topics": set(), "plugins": set(), "priced": 0})
                    d["topics"].add(topic)
                    d["plugins"].add(plug)
                    if r.get("cost"):
                        d["priced"] += 1
                    if is_service_topic:
                        travel.add(name)
        if is_service_topic:
            plugins[plug] += 1
        # --- training skills
        m = RE_TRAIN.search(txt)
        if m:
            s = " ".join(m.group(1).split())
            if 2 < len(s) <= 26 and s.split()[0].lower() not in TRAIN_STOP:
                skills.setdefault(s, {"skill": s, "n": 0, "plugins": set()})
                skills[s]["n"] += 1
                skills[s]["plugins"].add(plug)
                plugins[plug] += 1
        # --- crime
        if RE_CRIME_TOPIC.match(topic):
            crime[topic] = 1
            plugins[plug] += 1
        tw = r.get("twat") or ""
        if tw and RE_CRIME_TOPIC.match(topic):
            crime[tw] = 1
    out = {
        "_": "service_catalog", "v": 1, "built_at": int(time.time()), "hash": lo.hash,
        "destinations": sorted(dest.keys()),
        # E7 compares what she SAID against this narrower list only: a carriage / ferry topic really is a
        # place you can be taken to, while a priced one-word entry on some other layer is not.
        "destinations_travel": sorted(travel),
        "destination_rows": sorted(
            [{"name": d["name"], "topics": sorted(d["topics"])[:6], "plugins": sorted(d["plugins"])[:6],
              "priced": d["priced"]} for d in dest.values()], key=lambda x: x["name"]),
        "skills": sorted(skills.keys()),
        "skill_rows": sorted([{"skill": s["skill"], "n": s["n"], "plugins": sorted(s["plugins"])[:4]}
                              for s in skills.values()], key=lambda x: -x["n"]),
        "price_globals": sorted(globs.values(), key=lambda x: -x["n"]),
        "crime_topics": sorted(crime.keys()),
        "service_plugins": [{"plugin": p, "n": n} for p, n in plugins.most_common()],
    }
    return out


COVERAGE_COLUMNS = ["plugin", "mod_folder", "localized", "readable", "dial", "info", "player_prompts",
                    "winning_prompts", "with_text", "indexed_rows", "layers", "patterns", "checks_persuade",
                    "checks_intimidate", "checks_bribe", "other_kinds", "services", "no_prompt_text",
                    "unresolved_string", "norm_empty", "lost_to_override", "unreadable_reason", "covered_pct"]


def write_coverage(path, cov, sc, lo, svc, layers, log, quiet):
    """One row per plugin that carries any DIAL record. covered_pct = indexed / max(1, winning)."""
    unread = {p: w for p, w in lo.unreadable}
    svc_by_plugin = {p["plugin"]: p["n"] for p in (svc or {}).get("service_plugins", [])}
    lay = collections.Counter()
    for l in (layers or {}).values():
        owner = (sc.info.get(l.get("parent_info")) or {}).get("plugin")
        if owner:
            lay[owner] += 1
    out = []
    for name, st in sc.per_plugin.items():
        if not st.get("dial") and not st.get("info"):
            continue
        c = cov.get(name, collections.Counter())
        win = int(c.get("winning_prompts", 0))
        idx = int(c.get("indexed_rows", 0))
        # A player-topic INFO with no prompt text is a RESPONSE-ONLY INFO - reason 2 of the four, and
        # explicitly NOT a gap. Dividing by the raw winning count would charge every mod for Bethesda's
        # own response-only records (Skyrim.esm alone has 1,764 of them) and make the report useless.
        with_text = max(0, win - int(c.get("no_prompt_text", 0)))
        other = sum(v for kk, v in c.items()
                    if kk.startswith("check_") and kk not in ("check_persuade", "check_intimidate", "check_bribe"))
        out.append({
            "plugin": name,
            "mod_folder": st.get("mod") or "",
            "localized": 1 if st.get("localized") else 0,
            "readable": 0 if name in unread else 1,
            "dial": int(st.get("dial", 0)),
            "info": int(st.get("info", 0)),
            "player_prompts": win + int(sc.lost.get(name, 0)),
            "winning_prompts": win,
            "with_text": with_text,
            "indexed_rows": idx,
            "layers": int(lay.get(name, 0)),
            "patterns": int(c.get("patterns", 0)),
            "checks_persuade": int(c.get("check_persuade", 0)),
            "checks_intimidate": int(c.get("check_intimidate", 0)),
            "checks_bribe": int(c.get("check_bribe", 0)),
            "other_kinds": other,
            "services": int(svc_by_plugin.get(name, 0)),
            "no_prompt_text": int(c.get("no_prompt_text", 0)),
            "unresolved_string": int(c.get("unresolved_string", 0)),
            "norm_empty": int(c.get("norm_empty", 0)),
            "lost_to_override": int(sc.lost.get(name, 0)),
            "unreadable_reason": (unread.get(name) or "")[:90].replace(",", ";"),
            "covered_pct": round(idx / float(max(1, with_text)), 4),
        })
    out.sort(key=lambda r: -r["winning_prompts"])
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(",".join(COVERAGE_COLUMNS) + "\n")
        for r in out:
            f.write(",".join('"%s"' % str(r[k]).replace('"', "'") if isinstance(r[k], str) else str(r[k])
                             for k in COVERAGE_COLUMNS) + "\n")
    tot_win = sum(r["winning_prompts"] for r in out)
    tot_txt = sum(r["with_text"] for r in out)
    tot_idx = sum(r["indexed_rows"] for r in out)
    log("coverage -> %s: %d plugins, %d winning prompts (%d with text), %d indexed (%.1f%% of with-text)"
        % (path, len(out), tot_win, tot_txt, tot_idx, 100.0 * tot_idx / max(1, tot_txt)))
    if not quiet:
        print("=== PER-PLUGIN COVERAGE (top 20 by winning prompts) ==========================")
        print("%-46s %7s %7s %7s %6s %5s %5s" % ("plugin", "winning", "w/text", "indexed", "pct", "lost", "svc"))
        for r in out[:20]:
            print("%-46s %7d %7d %7d %5.1f%% %5d %5d" % (r["plugin"][:46], r["winning_prompts"], r["with_text"],
                  r["indexed_rows"], 100.0 * r["covered_pct"], r["lost_to_override"], r["services"]))
        bad = [r for r in out if r["unresolved_string"] > 0]
        if bad:
            print("UNRESOLVED <strid> (a reader bug or a broken plugin, never a data gap): "
                  + ", ".join("%s=%d" % (r["plugin"], r["unresolved_string"]) for r in bad[:10]))
        low = [r for r in out[:40] if r["covered_pct"] < 0.95 and r["with_text"] >= 100]
        if low:
            print("BELOW 0.95 among the 40 largest: "
                  + ", ".join("%s=%.3f" % (r["plugin"], r["covered_pct"]) for r in low[:10]))
        print("total: %d winning prompts, %d with text, %d indexed (%.2f%%) over %d plugins"
              % (tot_win, tot_txt, tot_idx, 100.0 * tot_idx / max(1, tot_txt), len(out)))
    return out


def main():
    ap = argparse.ArgumentParser(description="Build the LoreRim Glue Phase 2 prompt index (read-only).")
    ap.add_argument("--mo2", default="/mnt/f/Modlists/LoreRim")
    ap.add_argument("--profile", default="Ultra")
    ap.add_argument("--out", default="")
    ap.add_argument("--json-stats", default="")
    ap.add_argument("--stats", action="store_true", help="print the coverage and the kinds census")
    ap.add_argument("--grep", default="", help="print the index rows whose prompt contains this text")
    ap.add_argument("--limit-plugins", type=int, default=0)
    ap.add_argument("--quiet", action="store_true")
    # [0.5.0 / E5] one row per plugin that carries any DIAL record, with the four gap reasons apart
    ap.add_argument("--coverage", action="store_true", help="write the per-plugin coverage report (E5)")
    ap.add_argument("--coverage-out", default="", help="where to write it (default: next to --out)")
    # [0.5.0 / E6] the service census: destinations, skills, price globals, crime topics - FOUND, never guessed
    ap.add_argument("--services", action="store_true", help="write data/service_catalog.json (E6)")
    ap.add_argument("--services-out", default="", help="where to write it (default: next to --out)")
    a = ap.parse_args()

    t0 = time.time()
    lines = []

    def log(msg):
        lines.append(msg)
        if not a.quiet:
            sys.stderr.write(msg + "\n")

    if not a.out:
        here = os.path.dirname(os.path.abspath(__file__))
        a.out = os.path.join(here, "..", "server", "lorerim_glue", "data", "prompt_index.ndjson")
    a.out = os.path.abspath(a.out)

    lo = LoadOrder(a.mo2, a.profile, log)
    sc = Scan(lo, log)
    active = lo.active[:a.limit_plugins] if a.limit_plugins else lo.active
    read = 0
    for p in active:
        path = lo.index.get(p.lower())
        if not path:
            continue
        try:
            sc.plugin(p, path)
            read += 1
        except Unreadable as ex:
            lo.unreadable.append((p, str(ex)))
        except Exception as ex:
            log("ERROR %s: %r" % (p, ex))
    log("plugins read: %d/%d in %.1fs; DIAL %d, INFO %d" % (read, len(active), time.time() - t0,
                                                            len(sc.dial), len(sc.info)))
    shared = sc.resolve()
    dsd = scan_dsd(lo, log)

    # ------------------------------------------------------------------ rows
    rows, topics_seen = [], {}
    st = collections.Counter()
    by_topic_kind = collections.defaultdict(set)
    # [0.5.0 / E5] per-plugin coverage. A SECOND ACCUMULATOR over the pass that already runs - no second
    # scan and no extra run time. The four reasons a prompt is not in the index are kept APART, because
    # collapsing them is how a coverage report lies (plan 10.1).
    cov = collections.defaultdict(collections.Counter)
    for k, inf in sc.info.items():
        topic = sc.dial.get(inf["parent"]) or {}
        cat = topic.get("cat")
        if cat not in (0, 1):
            continue
        st["player_info"] += 1
        plug = inf.get("plugin") or "?"
        cov[plug]["winning_prompts"] += 1
        text = inf.get("rnam") or ""
        if not text:
            text = topic.get("full") or ""
            if text:
                st["text_from_dial_full"] += 1
        if not text:
            st["no_prompt_text"] += 1
            cov[plug]["no_prompt_text"] += 1
            continue
        if text.startswith("<strid ") or text.strip() == "":
            st["unresolved_string"] += 1
            cov[plug]["unresolved_string"] += 1
            continue
        norm = norm_prompt(text)
        if norm == "":
            st["norm_empty"] += 1
            cov[plug]["norm_empty"] += 1
            continue
        kind, compound, base_av, amulet = sc.classify(k, inf, topic)
        if kind:
            st["check_" + kind] += 1
            by_topic_kind[inf["parent"]].add(kind)
        if base_av:
            st["speech_base_av"] += 1
        iflags = int(inf.get("iflags") or 0)
        twat_key = inf.get("twat") or ""
        twat = sc.edid.get(twat_key, "") if twat_key else ""
        crit = 0
        if twat_key:
            crit = 2 if (twat or "").lower() in LETHAL_TWAT else 1
        cost, dynamic = parse_cost(text)
        quest_key = topic.get("quest") or ""
        qu = sc.qust.get(quest_key) or {}
        rows.append({
            "norm": norm,
            "pattern": pattern_of(text, norm),
            "txt": text[:160],
            "topic_key": inf["parent"],
            "info_key": k,
            "topic": topic.get("edid") or "",
            "quest": qu.get("edid") or "",
            "journal": 1 if qu.get("has_obj") else 0,
            "toplevel": 0 if inf["parent"] in sc.linked else 1,
            "kind": kind,
            "variant": "na",
            "flags": {
                "goodbye": 1 if iflags & IF_GOODBYE else 0,
                "sayonce": 1 if iflags & IF_SAYONCE else 0,
                "walkaway": 1 if iflags & (IF_WALKAWAY | IF_WALKAWAY_INVIS) else 0,
                "invis": 1 if iflags & IF_INVISCONT else 0,
                "random": 1 if iflags & IF_RANDOM else 0,
                "favor": 1 if iflags & IF_FAVORPTS else 0,
                "placeholder": 1 if RE_PLACEHOLDER.match(text) else 0,
            },
            "scripted": 1 if inf.get("vmad") else 0,
            "compound": 1 if compound else 0,
            "amulet": 1 if amulet else 0,
            "base_av": 1 if base_av else 0,
            "twat": twat or twat_key,
            "crit": crit,
            "cost": cost,
            "dyn": 1 if dynamic else 0,
            "links": inf.get("links") or [],
            "resp": (inf.get("resp") or "")[:220],
            "shared_resp": int(inf.get("resp_shared") or 0),
            "subtype": (topic.get("snam") or "").strip(),
            "cat": cat,
            "order": inf.get("order", 0),
            "nconds": len(inf.get("conds") or []),
            "free": 1 if sc.speaker_only(inf.get("conds") or []) else 0,
            "plugin": inf.get("plugin") or "",
            "origin": inf.get("origin") or "",
        })
        if kind or twat_key:
            # the FULL condition list, so a report can answer "which globals / perks does this check use"
            rows[-1]["conds"] = [sc.cond_row(c) for c in (inf.get("conds") or [])][:24]
        topics_seen.setdefault(inf["parent"], []).append(len(rows) - 1)
        cov[plug]["indexed_rows"] += 1
        if rows[-1]["pattern"]:
            cov[plug]["patterns"] += 1
        if kind:
            cov[plug]["check_" + kind] += 1
        sc.subtype_census[(topic.get("snam") or "(none)").strip() or "(none)"] += 1
        # census: every condition on a player INFO
        for c in inf.get("conds") or []:
            sc.fn_census[FN.get(c[0], "fn%d" % c[0])] += 1
            if c[0] in CENSUS_FN:
                tup = sc.cond_tuple(c)
                e = sc.kinds["|".join(map(str, tup))]
                e["n"] += 1
                if len(e["ex"]) < 3:
                    e["ex"].append((topic.get("edid") or inf["parent"]) + " :: " + text[:60])

    # ------------------------------------------------------------------ variants + PNAM inversion
    inverted = []
    for tkey, idxs in topics_seen.items():
        checks = [i for i in idxs if rows[i]["kind"]]
        if not checks:
            continue
        plain = [i for i in idxs if not rows[i]["kind"]]
        tkind = rows[checks[0]]["kind"]
        for i in idxs:
            # the TOPIC's check kind, on every INFO of it. A failure-variant INFO implements no check of its
            # own (kind stays ''), but the server still has to label and gate it as part of that check -
            # that is exactly what design 4.5's scoff-first rule needs ("the shown text is the FAILURE variant").
            rows[i]["topic_kind"] = tkind
        for i in checks:
            rows[i]["variant"] = "success"
        for i in plain:
            rows[i]["variant"] = "failure"
            if RE_BRAWL.search(rows[i]["txt"]):
                for j in checks:
                    rows[j]["fail_brawl"] = 1
        # the failure variant's own response/scripted flag decides scoff-first (design 4.5)
        for j in checks:
            rows[j]["fail_hard"] = 1 if any(rows[i]["scripted"] or rows[i]["flags"]["goodbye"] for i in plain) else 0
        # PNAM inversion (BRANCH B6): a sibling that is unconditional FOR THIS SPEAKER (no condition beyond
        # GetIsID / race / sex / faction scoping) sorted before the conditional check INFO makes the check
        # unreachable - the engine shows the first INFO whose conditions pass. 'order' is the PNAM order where
        # the chain was complete, file order otherwise.
        first_free = min([rows[i]["order"] for i in plain if rows[i]["free"]] or [10 ** 6])
        for j in checks:
            if rows[j]["order"] > first_free:
                rows[j]["unreachable"] = 1
                inverted.append({"topic": rows[j]["topic"] or tkey, "quest": rows[j]["quest"],
                                 "kind": rows[j]["kind"], "txt": rows[j]["txt"][:70],
                                 "order": rows[j]["order"], "free_at": first_free})

    # ------------------------------------------------------------------ how many topics share one prompt
    # "questions shared by more than 20 topics" rank DOWN (design 4.3). The count belongs in the index, so the
    # server never has to guess it from a capped query.
    per_norm = collections.defaultdict(set)
    for r in rows:
        per_norm[r["norm"]].add(r["topic_key"])
    for r in rows:
        r["shared"] = len(per_norm[r["norm"]])

    # ------------------------------------------------------------------ layer fingerprints
    layers = {}
    for k, inf in sc.info.items():
        links = inf.get("links") or []
        if len(links) < 1:
            continue
        norms = []
        for t in links:
            for ik in sc.info_of_topic.get(t, []):
                ii = sc.info.get(ik) or {}
                d = sc.dial.get(t) or {}
                txt = ii.get("rnam") or d.get("full") or ""
                n = norm_prompt(txt)
                if n:
                    norms.append(n)
        norms = sorted(set(norms))
        if len(norms) < 2:
            continue
        fp = hashlib.md5(("\x1f".join(norms)).encode("utf-8", "replace")).hexdigest()[:16]
        layers[fp] = {"_": "layer", "fingerprint": fp, "parent_info": k, "kind": "closed",
                      "n": len(norms), "norms": norms}

    # ------------------------------------------------------------------ header + write
    kinds_list = sorted(
        [{"tuple": kk, "n": vv["n"], "ex": vv["ex"]} for kk, vv in sc.kinds.items()],
        key=lambda x: -x["n"])
    real_kinds = sorted({r["kind"] for r in rows if r["kind"]})
    header = {
        "_": "header", "v": VERSION, "built_at": int(time.time()), "took_s": round(time.time() - t0, 1),
        "mo2": a.mo2, "profile": a.profile, "hash": lo.hash,
        "plugins_active": len(lo.active), "plugins_read": read, "plugins_missing": lo.not_found[:20],
        "localized": len(sc.localized),
        "unreadable": [{"plugin": p, "why": w} for p, w in lo.unreadable],
        "unresolved_strings": sc.unresolved.most_common(10),
        "pnam_chain": {"complete": sc.pnam_full, "partial": sc.pnam_partial},
        "rows": len(rows), "layers": len(layers),
        "check_kinds": real_kinds,
        "stats": dict(st) | {"dial": len(sc.dial), "info": len(sc.info), "shared_resp_resolved": shared,
                             "with_resp": sum(1 for r in rows if r["resp"]),
                             "silent": sum(1 for r in rows if not r["resp"]),
                             "token_patterns": sum(1 for r in rows if r["pattern"]),
                             "priced": sum(1 for r in rows if r["cost"] > 0),
                             "priced_token": sum(1 for r in rows if r["cost"] == -1),
                             "scripted": sum(1 for r in rows if r["scripted"]),
                             "compound": sum(1 for r in rows if r["compound"]),
                             "amulet_or_branch": sum(1 for r in rows if r.get("amulet")),
                             "twat": sum(1 for r in rows if r["twat"]),
                             "lethal": sum(1 for r in rows if r["crit"] == 2),
                             "toplevel": sum(1 for r in rows if r["toplevel"]),
                             "unreachable": len([r for r in rows if r.get("unreachable")])},
        "kinds": kinds_list[:200],
        "fn_census": sc.fn_census.most_common(60),
        "subtypes": sc.subtype_census.most_common(30),
        "check_subtype_rejected": sc.check_subtype_rejected.most_common(20),
        "pnam_inverted": inverted[:20],
        "dsd": dsd,
        "dsd_dialogue_entries": len(dsd["dialogue"]),
    }
    if dsd["dialogue"]:
        header["FAIL"] = ("DynamicStringDistributor rewrites %d DIAL/INFO string(s): the displayed text is no "
                          "longer the plugin text and this index must be rebuilt from the DSD files"
                          % len(dsd["dialogue"]))
        log("FAIL LOUDLY: " + header["FAIL"])

    if a.grep:
        g = a.grep.lower()
        for r in rows:
            if g in r["txt"].lower() or g in r["norm"]:
                print(json.dumps(r, ensure_ascii=False))
        return 0

    os.makedirs(os.path.dirname(a.out), exist_ok=True)
    tmp = a.out + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(json.dumps(header, ensure_ascii=False) + "\n")
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
        for l in layers.values():
            f.write(json.dumps(l, ensure_ascii=False) + "\n")
    os.replace(tmp, a.out)
    log("wrote %s: %d rows + %d layers (%.1f MB) in %.1fs"
        % (a.out, len(rows), len(layers), os.path.getsize(a.out) / 1048576.0, time.time() - t0))

    if a.json_stats:
        with open(a.json_stats, "w", encoding="utf-8") as f:
            json.dump(header, f, ensure_ascii=False, indent=1)

    # ------------------------------------------------------------------ [0.5.0 / E6] the SERVICE CENSUS
    # The mods that change rooms, rides, training and crime on THIS install are FOUND here, never guessed.
    # CHIM's own HireCarriage knows 19 vanilla destinations and 8 vanilla drivers; this load order runs
    # CFTO.esp with its own topics and destinations CHIM has never heard of ("Solitude Lighthouse.").
    # Written once, offline, and read by the server as data - never queried live.
    svc = None
    if a.services or a.coverage:
        svc = build_service_catalog(rows, layers, lo)
    if a.services:
        so = a.services_out or os.path.join(os.path.dirname(a.out), "service_catalog.json")
        so = os.path.abspath(so)
        os.makedirs(os.path.dirname(so), exist_ok=True)
        with open(so, "w", encoding="utf-8") as f:
            json.dump(svc, f, ensure_ascii=False, indent=1)
        log("services census -> %s: %d destinations, %d skills, %d price globals, %d crime topics, %d plugins"
            % (so, len(svc["destinations"]), len(svc["skills"]), len(svc["price_globals"]),
               len(svc["crime_topics"]), len(svc["service_plugins"])))
        if not a.quiet:
            print("=== SERVICE CENSUS =========================================================")
            print("destinations (%d), e.g. %s" % (len(svc["destinations"]), ", ".join(svc["destinations"][:8])))
            multi = [d for d in svc["destinations"] if " " in d]
            print("multi-word destinations (%d), e.g. %s" % (len(multi), ", ".join(multi[:6]) or "NONE - the census is wrong"))
            print("skills (%d): %s" % (len(svc["skills"]), ", ".join(svc["skills"][:20])))
            print("price globals (%d), e.g. %s" % (len(svc["price_globals"]),
                  ", ".join("%s(%s)" % (g["token"], g["plugin"]) for g in svc["price_globals"][:6])))
            print("crime topics (%d): %s" % (len(svc["crime_topics"]), ", ".join(svc["crime_topics"][:12])))
            print("service plugins (%d): %s" % (len(svc["service_plugins"]),
                  ", ".join("%s=%d" % (p["plugin"], p["n"]) for p in svc["service_plugins"][:14])))

    # ------------------------------------------------------------------ [0.5.0 / E5] the COVERAGE report
    if a.coverage:
        co = a.coverage_out or os.path.join(os.path.dirname(a.out), "prompt_coverage.csv")
        co = os.path.abspath(co)
        write_coverage(co, cov, sc, lo, svc, layers, log, a.quiet)

    if a.stats:
        h = header
        print("=== LoreRim Glue prompt index v%d =============================================" % VERSION)
        print("profile %s   plugins read %d/%d   localized %d   unreadable %d   %.1fs"
              % (a.profile, h["plugins_read"], h["plugins_active"], h["localized"], len(h["unreadable"]), h["took_s"]))
        print("rows %d   layers %d   toplevel %d   with response %d   truly silent %d   shared responses resolved %d"
              % (h["rows"], h["layers"], h["stats"]["toplevel"], h["stats"]["with_resp"], h["stats"]["silent"], shared))
        print("player INFOs seen %d   no prompt text %d   unresolved <strid> %d"
              % (h["stats"].get("player_info", 0), h["stats"].get("no_prompt_text", 0), h["stats"].get("unresolved_string", 0)))
        print("checks: " + ", ".join("%s=%d" % (kk, h["stats"].get("check_" + kk, 0)) for kk in real_kinds)
              + "   compound %d   amulet-OR %d   unreachable(PNAM) %d   speech_base_av(trainer cap, NOT a check) %d"
              % (h["stats"]["compound"], h["stats"]["amulet_or_branch"], h["stats"]["unreachable"],
                 h["stats"].get("speech_base_av", 0)))
        print("prices: literal %d   token %d   token patterns %d   scripted %d   twat %d (LETHAL %d)"
              % (h["stats"]["priced"], h["stats"]["priced_token"], h["stats"]["token_patterns"],
                 h["stats"]["scripted"], h["stats"]["twat"], h["stats"]["lethal"]))
        print("DSD: %d files in %d folders under %d mod root(s), DIALOGUE entries %d  %s"
              % (dsd["files"], dsd["folders"], len(dsd["roots"]), len(dsd["dialogue"]),
                 "<-- FAIL" if dsd["dialogue"] else "(plugin text == displayed text)"))
        print("DSD roots: " + "; ".join(dsd["roots"]))
        print("PNAM chains: complete %d, partial(file order used) %d   unresolved string ids: %s"
              % (sc.pnam_full, sc.pnam_partial, sc.unresolved.most_common(3) or "none"))
        print("DSD types: " + ", ".join("%s=%d" % kv for kv in sorted(dsd["types"].items(), key=lambda x: -x[1])[:8]))
        print("--- KINDS CENSUS (function id | name | actorvalue | comparand kind | comparand | op) ---")
        for e in kinds_list[:40]:
            print("%7d  %s" % (e["n"], e["tuple"]))
            for ex in e["ex"][:1]:
                print("         e.g. %s" % ex[:100])
        print("--- DIAL subtypes carrying player prompts ---")
        print(", ".join("%s=%d" % kv for kv in h["subtypes"]))
        if h["check_subtype_rejected"]:
            print("checks REJECTED by the subtype filter: " + ", ".join("%s=%d" % kv for kv in h["check_subtype_rejected"]))
        if inverted:
            print("--- PNAM-inverted check topics (the success INFO sorts after an unconditional one) ---")
            for i in inverted[:8]:
                print("  %s  %s  %s" % (i["kind"], i["topic"], i["txt"][:60]))
        if h["unreadable"]:
            print("--- unreadable (engine-only, decision D6): %d ---" % len(h["unreadable"]))
            for u in h["unreadable"][:5]:
                print("  %s  %s" % (u["plugin"], u["why"][:80]))
    return 0


if __name__ == "__main__":
    sys.exit(main())
