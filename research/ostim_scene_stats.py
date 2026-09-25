#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ostim_scene_stats.py  -  READ-ONLY indexer for OStim Standalone scene packs.

Indexes every scene .json under  <mod>/SKSE/Plugins/OStim/scenes  for the packs
listed in PACKS, resolves action aliases against the action definition files,
builds the declared navigation graph and writes ostim_scene_stats.json next to
this script (or to --out).

Usage (inside WSL):
    python3 ostim_scene_stats.py
    python3 ostim_scene_stats.py --mods-root /mnt/f/Modlists/LoreRim/mods --out /some/dir/ostim_scene_stats.json
Usage (Windows python):
    python ostim_scene_stats.py --mods-root "F:\\Modlists\\LoreRim\\mods"

Nothing under the mods root is ever written to.

Semantics implemented (source: OStim "scenes README.txt" shipped with the mod):
  * sceneID  = file name without .json (folder names are NOT part of the id)
  * scene-level "destination"  -> the scene is a TRANSITION: it plays once and
    then moves to the destination; its "navigations" list is ignored.
  * scene-level "origin" (transition only) -> adds an edge  origin -> this scene
  * navigations[i].destination -> edge  this -> destination
  * navigations[i].origin      -> edge  origin -> this
  * action "type" may be an alias declared in an action definition file
  * all ids / tags / action types are compared in lower case
"""
import argparse
import collections
import datetime
import json
import os
import re
import sys

PACKS = [
    # (pack key, mod folder name)
    ("OStim", "OStim Standalone - Advanced Adult Animation Framework"),
    ("OARE", "Open Animations Romance and Erotica for OStim Standalone"),
    ("OCR", "OStim Community Resource"),
]
OSTIM_DATA = os.path.join("SKSE", "Plugins", "OStim")

ROMANTIC_NAME_RE = re.compile(
    r"(kiss|hug|cuddl|caress|embrace|holdhand|handhold|hold(ing)?hands?|flirt|court|lappillow|headpat|patting)",
    re.I,
)
KISS_NAME_RE = re.compile(r"kiss", re.I)

# ---- intensity tiers (glue-side convention, NOT an OStim concept) -------------
# 0 neutral   : no actions / only untagged pose actions (holdingbody, spectating, ...)
# 1 affection : hand holding, hugging, cuddling, head patting ... (or OARE scene tag "cuddling", or name match)
# 2 kissing   : any kissing action (or "kiss" in the scene id)
# 3 sensual   : groping, undressing, vampire feeding, spanking, other "sensual"-tagged actions
# 4 sexual    : any action whose definition carries the "sexual" action tag
# plus a boolean "staging" (computed in main): tier-0 looping scene with a suggestive posture tag or with
# >= 40 % of its folded navigations ending in a tier-4 scene (positional idle of an explicit branch).
AFFECTION_ACTIONS = {"holdinghand", "hugging", "cuddling", "pattinghead", "strokinghead", "holdingchin",
                     "spooning", "massaging"}
KISS_ACTIONS = {"kissing", "frenchkissing", "kissingcheek", "kissinghand", "kissingneck", "3pp_kissing"}
TIER3_ACTIONS = {"gropingbreast", "gropingbutt", "3pp_gropingbreast", "3pp_gropingbutt", "vampirebiting",
                 "spanking", "breastsliding", "breastsmothering", "buttsmothering", "kissingfoot", "lickingear",
                 "pullinghair", "oralfingering", "choking", "undress_full_target", "undress_foot_item_target",
                 "undress_head_item_target"}
TIER3_NAME_RE = re.compile(r"(undress|vampire|devour|spank|grop)", re.I)
TIER_NAMES = ["neutral", "affection", "kissing", "sensual", "sexual"]


def intensity_tier(rec):
    acts = {a["type"] for a in rec.get("actions", [])}
    atags = set(rec.get("action_tags", []))
    sid = rec["id"]
    if "sexual" in atags:
        return 4
    if acts & TIER3_ACTIONS or TIER3_NAME_RE.search(sid) or "undressing" in rec.get("tags", []):
        return 3
    if acts & KISS_ACTIONS or KISS_NAME_RE.search(sid):
        return 2
    if acts & AFFECTION_ACTIONS or "cuddling" in rec.get("tags", []) or ROMANTIC_NAME_RE.search(sid):
        return 1
    if atags & {"sensual", "seductive"}:
        return 3
    return 0


# --------------------------------------------------------------------------
# tolerant JSON loading
# --------------------------------------------------------------------------
def _strip_json_noise(text):
    # remove // line comments (not inside strings - best effort) and trailing commas
    out = []
    in_str = False
    esc = False
    i = 0
    n = len(text)
    while i < n:
        c = text[i]
        if in_str:
            out.append(c)
            if esc:
                esc = False
            elif c == "\\":
                esc = True
            elif c == '"':
                in_str = False
            i += 1
            continue
        if c == '"':
            in_str = True
            out.append(c)
            i += 1
            continue
        if c == "/" and i + 1 < n and text[i + 1] == "/":
            while i < n and text[i] not in "\r\n":
                i += 1
            continue
        if c == "/" and i + 1 < n and text[i + 1] == "*":
            j = text.find("*/", i + 2)
            i = n if j < 0 else j + 2
            continue
        out.append(c)
        i += 1
    cleaned = "".join(out)
    cleaned = re.sub(r",(\s*[}\]])", r"\1", cleaned)
    return cleaned


def load_json(path):
    """returns (obj, status) ; status in strict|tolerant|error:<msg>"""
    with open(path, "rb") as fh:
        raw = fh.read()
    text = None
    for enc in ("utf-8-sig", "utf-16", "cp1252"):
        try:
            text = raw.decode(enc)
            break
        except UnicodeError:
            continue
    if text is None:
        return None, "error:undecodable"
    try:
        return json.loads(text), "strict"
    except ValueError:
        pass
    try:
        return json.loads(_strip_json_noise(text)), "tolerant"
    except ValueError as exc:
        return None, "error:%s" % exc


def lower_keys(d):
    """OStim READMEs spell keys in camelCase but packs vary (modpack/modPack).
    We look fields up case-insensitively and keep a census of raw spellings."""
    return {str(k).lower(): v for k, v in d.items()} if isinstance(d, dict) else {}


def iter_json_files(root):
    for dirpath, _dirs, files in os.walk(root):
        for fn in sorted(files):
            if fn.lower().endswith(".json"):
                yield os.path.join(dirpath, fn)


# --------------------------------------------------------------------------
# translations ($keys)
# --------------------------------------------------------------------------
def load_translations(mods_root):
    table = {}
    for _key, folder in PACKS:
        tdir = os.path.join(mods_root, folder, "Interface", "translations")
        if not os.path.isdir(tdir):
            continue
        for fn in sorted(os.listdir(tdir)):
            if not fn.lower().endswith("_english.txt"):
                continue
            try:
                with open(os.path.join(tdir, fn), "rb") as fh:
                    text = fh.read().decode("utf-16")
            except (OSError, UnicodeError):
                continue
            for line in text.splitlines():
                if not line.startswith("$") or "\t" not in line:
                    continue
                k, v = line.split("\t", 1)
                table[k.strip().lower()] = v.strip()
    return table


def translate(s, table):
    """'$ostim_nav_hold_t{1}' -> 'hold {1}' using key '$ostim_nav_hold_t{}' -> 'hold {{}}'"""
    if not isinstance(s, str) or not s.startswith("$"):
        return s
    k = s.strip().lower()
    if k in table:
        return table[k]
    m = re.match(r"^(.*)\{(\d+)\}$", k)
    if m:
        generic = m.group(1) + "{}"
        if generic in table:
            return table[generic].replace("{{}}", "{%s}" % m.group(2))
    return s


# --------------------------------------------------------------------------
# definitions: actions + furniture types
# --------------------------------------------------------------------------
def load_action_defs(mods_root):
    defs = {}      # canonical(lower) -> {aliases, tags, pack, file}
    alias = {}     # alias(lower) -> canonical
    for pack, folder in PACKS:
        adir = os.path.join(mods_root, folder, OSTIM_DATA, "actions")
        if not os.path.isdir(adir):
            continue
        for path in iter_json_files(adir):
            obj, status = load_json(path)
            name = os.path.splitext(os.path.basename(path))[0].lower()
            if obj is None:
                defs[name] = {"aliases": [], "tags": [], "pack": pack, "parse": status}
                continue
            lk = lower_keys(obj)
            aliases = [str(a).lower() for a in (lk.get("aliases") or [])]
            tags = [str(t).lower() for t in (lk.get("tags") or [])]

            def role(r):
                rr = lower_keys(lk.get(r) or {})
                return {
                    "stimulation": rr.get("stimulation"),
                    "maxStimulation": rr.get("maxstimulation"),
                    "requirements": [str(x).lower() for x in (rr.get("requirements") or [])],
                    "fullStrip": rr.get("fullstrip"),
                }

            defs[name] = {
                "aliases": aliases,
                "tags": tags,
                "pack": pack,
                "actor": role("actor"),
                "target": role("target"),
                "performer": role("performer"),
            }
            alias[name] = name
            for a in aliases:
                alias.setdefault(a, name)
    return defs, alias


def load_furniture_types(mods_root):
    out = {}
    for pack, folder in PACKS:
        fdir = os.path.join(mods_root, folder, OSTIM_DATA, "furniture types")
        if not os.path.isdir(fdir):
            continue
        for path in iter_json_files(fdir):
            obj, _status = load_json(path)
            name = os.path.splitext(os.path.basename(path))[0].lower()
            lk = lower_keys(obj or {})
            out[name] = {
                "supertype": (str(lk["supertype"]).lower() if "supertype" in lk else None),
                "priority": lk.get("priority", 0),
                "listIndividually": lk.get("listindividually", False),
                "name": lk.get("name"),
                "pack": pack,
            }
    return out


def furniture_chain(ftype, ftypes):
    """bench -> [bench, chair]; doublebed -> [doublebed, bed, none]"""
    chain = []
    cur = ftype
    seen = set()
    while cur is not None and cur not in seen:
        chain.append(cur)
        seen.add(cur)
        cur = (ftypes.get(cur) or {}).get("supertype")
    return chain


# --------------------------------------------------------------------------
# scenes
# --------------------------------------------------------------------------
def sex_letter(s):
    s = (s or "any").lower()
    return {"male": "M", "female": "F"}.get(s, "A")


def parse_scene(path, pack, scenes_root, alias, action_defs, census, tr):
    obj, status = load_json(path)
    sid = os.path.splitext(os.path.basename(path))[0]
    rel = os.path.relpath(path, scenes_root).replace("\\", "/")
    rec = {
        "id": sid,
        "pack": pack,
        "file": rel,
        "folder": rel.split("/")[0] if "/" in rel else "",
        "parse": status,
    }
    if not isinstance(obj, dict):
        rec["error"] = True
        return rec
    for k in obj:
        census["scene"][k] += 1
    lk = lower_keys(obj)

    rec["name"] = lk.get("name")
    rec["name_en"] = translate(lk.get("name"), tr)
    rec["modpack"] = lk.get("modpack")
    rec["length"] = lk.get("length")
    dest = lk.get("destination")
    rec["destination"] = dest if isinstance(dest, str) and dest else None
    origin = lk.get("origin")
    rec["origin"] = origin if isinstance(origin, str) and origin else None
    rec["is_transition"] = rec["destination"] is not None
    rec["furniture"] = str(lk.get("furniture") or "none").lower()
    rec["tags"] = sorted({str(t).lower() for t in (lk.get("tags") or [])})
    rec["noRandomSelection"] = bool(lk.get("norandomselection", False))
    rec["defaultSpeed"] = lk.get("defaultspeed", 0)
    rec["has_offset"] = "offset" in lk

    speeds = lk.get("speeds") or []
    rec["n_speeds"] = len(speeds)
    rec["animations"] = []
    for sp in speeds:
        if isinstance(sp, dict):
            for k in sp:
                census["speed"][k] += 1
            rec["animations"].append(lower_keys(sp).get("animation"))

    at = lk.get("autotransitions") or {}
    rec["autoTransitions"] = {str(k): str(v) for k, v in at.items()} if isinstance(at, dict) else {}

    # actors
    actors = []
    for a in lk.get("actors") or []:
        if not isinstance(a, dict):
            continue
        for k in a:
            census["actor"][k] += 1
        la = lower_keys(a)
        aat = la.get("autotransitions") or {}
        actors.append({
            "type": str(la.get("type") or "npc").lower(),
            "intendedSex": str(la.get("intendedsex") or "any").lower(),
            "tags": sorted({str(t).lower() for t in (la.get("tags") or [])}),
            "requirements": [str(x).lower() for x in (la.get("requirements") or [])],
            "noStrip": bool(la.get("nostrip", False)),
            "animationIndex": la.get("animationindex"),
            "autoTransitions": {str(k): str(v) for k, v in aat.items()} if isinstance(aat, dict) else {},
        })
    rec["actors"] = actors
    rec["actor_count"] = len(actors)
    rec["sex_config"] = "".join(sex_letter(a["intendedSex"]) for a in actors)

    # actions
    actions = []
    for ac in lk.get("actions") or []:
        if not isinstance(ac, dict):
            continue
        for k in ac:
            census["action"][k] += 1
        la = lower_keys(ac)
        raw = str(la.get("type") or "").lower()
        canon = alias.get(raw)
        actor_i = la.get("actor", 0)
        actions.append({
            "type_raw": raw,
            "type": canon if canon else raw,
            "resolved": canon is not None,
            "actor": actor_i,
            "target": la.get("target", actor_i),
            "performer": la.get("performer", actor_i),
            "tags": (action_defs.get(canon) or {}).get("tags", []) if canon else [],
        })
    rec["actions"] = actions

    # navigations (ignored by OStim for transitions, we still record them)
    navs = []
    for nv in lk.get("navigations") or []:
        if not isinstance(nv, dict):
            continue
        for k in nv:
            census["navigation"][k] += 1
        ln = lower_keys(nv)
        navs.append({
            "destination": ln.get("destination"),
            "origin": ln.get("origin"),
            "priority": ln.get("priority", 0),
            "description": ln.get("description"),
            "description_en": translate(ln.get("description"), tr),
            "icon": ln.get("icon"),
            "noWarnings": bool(ln.get("nowarnings", False)),
        })
    rec["navigations"] = navs

    # classification from action definition tags
    atags = set()
    for ac in actions:
        atags.update(ac["tags"])
    rec["action_tags"] = sorted(atags)
    if "sexual" in atags:
        rec["class"] = "sexual"
    elif atags & {"sensual", "romantic"}:
        rec["class"] = "sensual_romantic"
    elif actions:
        rec["class"] = "other_actions_only"
    else:
        rec["class"] = "no_actions"
    rec["tier"] = intensity_tier(rec)
    rec["tier_name"] = TIER_NAMES[rec["tier"]]
    return rec


# --------------------------------------------------------------------------
# graph helpers
# --------------------------------------------------------------------------
def build_edges(scenes):
    """returns (edges dict (src,dst)->list of decl, dangling list). ids lower-cased."""
    by_lc = {s["id"].lower(): s for s in scenes.values()}
    edges = collections.defaultdict(list)
    dangling = []
    case_mismatch = 0

    def add(src, dst, form, decl, nav=None):
        nonlocal case_mismatch
        s_l, d_l = str(src).lower(), str(dst).lower()
        missing = [x for x in (s_l, d_l) if x not in by_lc]
        if missing:
            dangling.append({"declared_in": decl["id"], "form": form, "src": src, "dst": dst,
                             "missing": missing, "noWarnings": bool(nav and nav.get("noWarnings"))})
            return
        if by_lc[s_l]["id"] != src or by_lc[d_l]["id"] != dst:
            case_mismatch += 1
        edges[(by_lc[s_l]["id"], by_lc[d_l]["id"])].append({
            "form": form, "declared_in": decl["id"],
            "priority": (nav or {}).get("priority"),
            "description": (nav or {}).get("description_en"),
        })

    for s in scenes.values():
        if s.get("error"):
            continue
        if s["is_transition"]:
            add(s["id"], s["destination"], "transition", s)
            if s["origin"]:
                add(s["origin"], s["id"], "transition-origin", s)
            continue
        for nv in s["navigations"]:
            if nv.get("destination"):
                add(s["id"], nv["destination"], "destination", s, nv)
            elif nv.get("origin"):
                add(nv["origin"], s["id"], "origin", s, nv)
    return edges, dangling, case_mismatch


def adjacency(nodes, edge_keys):
    out = {n: set() for n in nodes}
    inc = {n: set() for n in nodes}
    for (a, b) in edge_keys:
        if a in out and b in out:
            out[a].add(b)
            inc[b].add(a)
    return out, inc


def weak_components(nodes, out, inc):
    seen, comps = set(), []
    for n in nodes:
        if n in seen:
            continue
        comp, stack = [], [n]
        seen.add(n)
        while stack:
            x = stack.pop()
            comp.append(x)
            for y in out[x] | inc[x]:
                if y not in seen:
                    seen.add(y)
                    stack.append(y)
        comps.append(sorted(comp))
    comps.sort(key=lambda c: (-len(c), c[0]))
    return comps


def strong_components(nodes, out):
    """iterative Tarjan"""
    index, low, onstack = {}, {}, set()
    stack, comps, counter = [], [], [0]
    for root in nodes:
        if root in index:
            continue
        work = [(root, iter(sorted(out[root])))]
        index[root] = low[root] = counter[0]
        counter[0] += 1
        stack.append(root)
        onstack.add(root)
        while work:
            v, it = work[-1]
            advanced = False
            for w in it:
                if w not in index:
                    index[w] = low[w] = counter[0]
                    counter[0] += 1
                    stack.append(w)
                    onstack.add(w)
                    work.append((w, iter(sorted(out[w]))))
                    advanced = True
                    break
                elif w in onstack:
                    low[v] = min(low[v], index[w])
            if advanced:
                continue
            work.pop()
            if work:
                p = work[-1][0]
                low[p] = min(low[p], low[v])
            if low[v] == index[v]:
                comp = []
                while True:
                    w = stack.pop()
                    onstack.discard(w)
                    comp.append(w)
                    if w == v:
                        break
                comps.append(sorted(comp))
    comps.sort(key=lambda c: (-len(c), c[0]))
    return comps


def bfs(out, sources, allowed=None):
    """multi-source BFS; returns (dist, parent)"""
    dist, parent = {}, {}
    dq = collections.deque()
    for s in sources:
        if allowed is not None and s not in allowed:
            continue
        dist[s] = 0
        parent[s] = None
        dq.append(s)
    while dq:
        x = dq.popleft()
        for y in sorted(out.get(x, ())):
            if y in dist:
                continue
            if allowed is not None and y not in allowed:
                continue
            dist[y] = dist[x] + 1
            parent[y] = x
            dq.append(y)
    return dist, parent


def path_to(parent, target):
    p, cur = [], target
    while cur is not None:
        p.append(cur)
        cur = parent[cur]
    return list(reversed(p))


def shortest_path(out, src, dst, allowed=None):
    dist, parent = bfs(out, [src], allowed)
    if dst not in dist:
        return None
    return path_to(parent, dst)


# --------------------------------------------------------------------------
# stats
# --------------------------------------------------------------------------
def counter_table(counter):
    return [[k, v] for k, v in sorted(counter.items(), key=lambda kv: (-kv[1], str(kv[0])))]


def pack_stats(scenes):
    st = {}
    ok = [s for s in scenes if not s.get("error")]
    st["scene_count"] = len(scenes)
    st["parse_status"] = counter_table(collections.Counter(s["parse"].split(":")[0] for s in scenes))
    st["transition_count"] = sum(1 for s in ok if s["is_transition"])
    st["transitions_with_actions"] = sum(1 for s in ok if s["is_transition"] and s["actions"])
    st["transitions_with_origin"] = sum(1 for s in ok if s["is_transition"] and s["origin"])
    st["transitions_with_nonempty_navigations"] = sum(1 for s in ok if s["is_transition"] and s["navigations"])
    st["noRandomSelection_count"] = sum(1 for s in ok if s["noRandomSelection"])
    st["actor_count_dist"] = counter_table(collections.Counter(s["actor_count"] for s in ok))
    st["sex_config_dist"] = counter_table(collections.Counter(s["sex_config"] for s in ok))
    st["furniture_dist"] = counter_table(collections.Counter(s["furniture"] for s in ok))
    st["furniture_x_actor_count"] = counter_table(collections.Counter("%s|%d" % (s["furniture"], s["actor_count"]) for s in ok))
    st["modpack_values"] = counter_table(collections.Counter(str(s["modpack"]) for s in ok))
    st["folder_counts"] = counter_table(collections.Counter(s["folder"] for s in ok))
    st["class_dist"] = counter_table(collections.Counter(s["class"] for s in ok))
    st["class_dist_non_transition"] = counter_table(collections.Counter(s["class"] for s in ok if not s["is_transition"]))
    st["n_speeds_dist"] = counter_table(collections.Counter(s["n_speeds"] for s in ok))
    st["intensity_tier_dist"] = counter_table(collections.Counter(
        "%d_%s" % (s["tier"], s["tier_name"]) for s in ok))
    st["intensity_tier_dist_non_transition"] = counter_table(collections.Counter(
        "%d_%s" % (s["tier"], s["tier_name"]) for s in ok if not s["is_transition"]))

    tagc, atagc, atypec, actc, actrawc, unres, acttagc = (collections.Counter() for _ in range(7))
    for s in ok:
        tagc.update(s["tags"])
        for a in s["actors"]:
            atagc.update(a["tags"])
            atypec[a["type"]] += 1
        seen_types = set()
        for ac in s["actions"]:
            actc[ac["type"]] += 1
            actrawc[ac["type_raw"]] += 1
            if not ac["resolved"]:
                unres[ac["type_raw"]] += 1
            seen_types.add(ac["type"])
        acttagc.update(s["action_tags"])
    st["scene_tags"] = counter_table(tagc)
    st["actor_tags"] = counter_table(atagc)
    st["actor_types"] = counter_table(atypec)
    st["action_types"] = counter_table(actc)                 # canonical, counted per action record
    st["action_types_raw_spelling"] = counter_table(actrawc)
    st["action_types_unresolved"] = counter_table(unres)
    st["scenes_per_action_type"] = counter_table(collections.Counter(
        t for s in ok for t in {ac["type"] for ac in s["actions"]}))
    st["scenes_per_action_tag"] = counter_table(acttagc)

    lengths = [s["length"] for s in ok if isinstance(s["length"], (int, float))]
    if lengths:
        st["length_stats"] = {"min": min(lengths), "max": max(lengths),
                              "mean": round(sum(lengths) / len(lengths), 3)}
    st["autoTransition_keys_scene"] = counter_table(collections.Counter(k for s in ok for k in s["autoTransitions"]))
    st["autoTransition_keys_actor"] = counter_table(collections.Counter(
        k for s in ok for a in s["actors"] for k in a["autoTransitions"]))
    return st


def id_conventions(scenes):
    out = {}
    ids = [s["id"] for s in scenes]
    out["prefix_before_underscore"] = counter_table(collections.Counter(
        (i.split("_", 1)[0] + "_") if "_" in i else "(no underscore)" for i in ids))
    ostim_re = re.compile(r"^OStim(?P<furn>[A-Za-z]*?)(?P<n>\d)P(?P<desc>.+?)(?P<sex>[MF]{1,5})$")
    m_ok, furn_c, sex_c = 0, collections.Counter(), collections.Counter()
    for i in ids:
        m = ostim_re.match(i)
        if m:
            m_ok += 1
            furn_c[m.group("furn") or "(none)"] += 1
            sex_c[m.group("sex")] += 1
    out["ostim_pattern"] = {"regex": ostim_re.pattern, "matching": m_ok, "of": len(ids),
                            "furniture_token": counter_table(furn_c), "sex_suffix": counter_table(sex_c)}

    def first_token(i):
        body = i.split("_", 1)[1] if "_" in i else i
        m = re.match(r"([A-Z]+(?![a-z])|[A-Z]?[a-z]+|\d+)", body)
        return m.group(1) if m else body[:6]
    out["first_camel_token_after_prefix"] = counter_table(collections.Counter(first_token(i) for i in ids))[:25]
    out["ids_with_non_alnum_underscore_chars"] = sorted(i for i in ids if re.search(r"[^A-Za-z0-9_]", i))[:50]
    out["max_id_length"] = max((len(i) for i in ids), default=0)
    return out


def group_graph_stats(scenes, edges, idle_like=("idle", "intro")):
    groups = collections.defaultdict(list)
    for s in scenes.values():
        if s.get("error"):
            continue
        groups["%s|%d" % (s["furniture"], s["actor_count"])].append(s["id"])
    gkey = {sid: k for k, ids in groups.items() for sid in ids}
    all_nodes = [s["id"] for s in scenes.values() if not s.get("error")]
    g_out, g_inc = adjacency(all_nodes, edges.keys())
    result = {}
    for key, ids in sorted(groups.items()):
        idset = set(ids)
        intra = [(a, b) for (a, b) in edges if a in idset and b in idset]
        cross_out = [(a, b) for (a, b) in edges if a in idset and b not in idset]
        cross_in = [(a, b) for (a, b) in edges if a not in idset and b in idset]
        out, inc = adjacency(ids, intra)
        wcc = weak_components(sorted(ids), out, inc)
        scc = strong_components(sorted(ids), out)
        deg = sorted(((len(out[n]) + len(inc[n]), len(out[n]), len(inc[n]), n) for n in ids), reverse=True)
        entry = [n for n in ids if set(scenes[n]["tags"]) & set(idle_like) and not scenes[n]["is_transition"]]
        dist, _p = bfs(out, entry)
        result[key] = {
            "nodes": len(ids),
            "edges_intra": len(intra),
            "edges_cross_out": len(cross_out),
            "edges_cross_in": len(cross_in),
            "cross_out_targets": counter_table(collections.Counter(gkey[b] for (_a, b) in cross_out)),
            "cross_in_sources": counter_table(collections.Counter(gkey[a] for (a, _b) in cross_in)),
            "transitions": sum(1 for n in ids if scenes[n]["is_transition"]),
            "weak_components": len(wcc),
            "weak_component_sizes": [len(c) for c in wcc][:20],
            "weak_component_samples": [c[:6] for c in wcc[:8]],
            "strong_components_gt1": [len(c) for c in scc if len(c) > 1],
            "largest_scc_size": len(scc[0]) if scc else 0,
            "top_hubs": [{"id": n, "degree": d, "out": o, "in": i} for (d, o, i, n) in deg[:10]],
            # global in/out-degree (any declared edge from anywhere)
            "unreachable_in0": sorted(n for n in ids if not g_inc[n]),
            "dead_end_out0": sorted(n for n in ids if not g_out[n]),
            "idle_or_intro_entries": sorted(entry),
            "not_reachable_from_group_idles": sorted(n for n in ids if n not in dist) if entry else None,
        }
    return result


# --------------------------------------------------------------------------
# OStim-style collapsed navigation graph
#   (OStim's GraphTable::addNavigations folds chains of transition nodes into ONE
#    navigation whose end point is the first non-transition node; Node::getRoute
#    counts distance in such navigations, bounded by MCM navigationDistanceMax, default 5)
# --------------------------------------------------------------------------
def collapse_transitions(scenes, out):
    """returns cout: non-transition src -> {final non-transition dst: [transition ids passed]}"""
    cout = {}
    for src, s in scenes.items():
        if s.get("error") or s["is_transition"]:
            continue
        m = {}
        for first in sorted(out.get(src, ())):
            chain, cur, guard = [], first, 0
            while cur is not None and scenes[cur]["is_transition"] and guard < 16:
                chain.append(cur)
                nxt = sorted(out.get(cur, ()))
                cur = nxt[0] if nxt else None
                guard += 1
            if cur is None or scenes[cur]["is_transition"]:
                continue
            if cur not in m or len(chain) < len(m[cur]):
                m[cur] = chain
        cout[src] = m
    return cout


def collapsed_stats(scenes, out, starts):
    cout = collapse_transitions(scenes, out)
    simple = {k: set(v) for k, v in cout.items()}
    res = {"nodes_non_transition": len(simple), "navigations": sum(len(v) for v in simple.values()),
           "self_return_navigations": sum(1 for k, v in simple.items() if k in v), "from_start": {}}
    for st in starts:
        if st not in simple:
            continue
        dist, _p = bfs(simple, [st])
        hist = collections.Counter(dist.values())
        cum, acc = [], 0
        for d in range(0, (max(hist) if hist else 0) + 1):
            acc += hist.get(d, 0)
            cum.append([d, hist.get(d, 0), acc])
        res["from_start"][st] = {"reachable": len(dist), "max_distance": max(hist) if hist else 0,
                                 "distance_histogram_[d,count,cumulative]": cum}
    return res


# --------------------------------------------------------------------------
# romantic / non explicit analysis (uses the glue-side intensity tiers)
# --------------------------------------------------------------------------
def romantic_analysis(scenes, edges):
    ok = {k: s for k, s in scenes.items() if not s.get("error")}
    nodes = sorted(ok)
    out, inc = adjacency(nodes, edges.keys())
    nonsex = {n for n in nodes if ok[n]["tier"] <= 3}
    gentle = {n for n in nodes if ok[n]["tier"] <= 2}
    # strict = gentle minus "staging" scenes (action-less positional idles that mainly lead to tier-4 scenes)
    strict = {n for n in gentle if not ok[n].get("staging")}

    def acts(n):
        return {a["type"] for a in ok[n]["actions"]}

    def standing(n):
        return ok[n]["actors"] and all("standing" in a["tags"] for a in ok[n]["actors"])

    romantic = []
    for n in nodes:
        s = ok[n]
        if s["tier"] not in (1, 2) or s["actor_count"] < 2:
            continue
        a = acts(n)
        by_action = sorted(a & (AFFECTION_ACTIONS | KISS_ACTIONS))
        romantic.append({
            "id": n, "pack": s["pack"], "tier": s["tier"], "furniture": s["furniture"],
            "sex_config": s["sex_config"], "is_transition": s["is_transition"], "destination": s["destination"],
            "romantic_actions": by_action, "matched_by_name_or_tag_only": not by_action,
            "all_actions": sorted(a), "scene_tags": s["tags"], "actor_tags": [x["tags"] for x in s["actors"]],
            "in_degree": len(inc[n]), "out_degree": len(out[n]),
            "out_to": sorted(out[n])[:12], "in_from": sorted(inc[n])[:12],
            "gentle_neighbours_out": sorted(x for x in out[n] if x in gentle)[:12],
        })

    standing_kiss = [n for n in nodes if ok[n]["tier"] in (1, 2) and ok[n]["actor_count"] == 2
                     and ok[n]["furniture"] == "none" and standing(n) and ok[n]["pack"] != "OCR"
                     and ((acts(n) & (KISS_ACTIONS | {"hugging"})) or re.search(r"(kiss|hug|embrace)", n, re.I))]
    cuddles = [n for n in nodes if ok[n]["tier"] in (1, 2) and ok[n]["actor_count"] == 2
               and ("cuddling" in acts(n) or re.search(r"cuddl", n, re.I))]

    pairs = []
    for src in standing_kiss:
        d_any, p_any = bfs(out, [src])
        d_ns, p_ns = bfs(out, [src], allowed=nonsex)
        d_g, p_g = bfs(out, [src], allowed=gentle)
        for dst in cuddles:
            if dst == src:
                continue
            fwd_any = path_to(p_any, dst) if dst in d_any else None
            fwd_ns = path_to(p_ns, dst) if dst in d_ns else None
            fwd_g = path_to(p_g, dst) if dst in d_g else None
            back_any = shortest_path(out, dst, src)
            back_ns = shortest_path(out, dst, src, allowed=nonsex)
            back_g = shortest_path(out, dst, src, allowed=gentle)
            fwd_s = shortest_path(out, src, dst, allowed=strict | {src, dst})
            back_s = shortest_path(out, dst, src, allowed=strict | {src, dst})
            pairs.append({"src": src, "dst": dst,
                          "fwd_len_strict": len(fwd_s) - 1 if fwd_s else None,
                          "back_len_strict": len(back_s) - 1 if back_s else None,
                          "fwd_path_strict": fwd_s, "back_path_strict": back_s,
                          "fwd_len_any": len(fwd_any) - 1 if fwd_any else None,
                          "fwd_len_tier_le3": len(fwd_ns) - 1 if fwd_ns else None,
                          "fwd_len_tier_le2": len(fwd_g) - 1 if fwd_g else None,
                          "back_len_any": len(back_any) - 1 if back_any else None,
                          "back_len_tier_le3": len(back_ns) - 1 if back_ns else None,
                          "back_len_tier_le2": len(back_g) - 1 if back_g else None,
                          "fwd_path_tier_le2": fwd_g, "fwd_path_tier_le3": fwd_ns, "fwd_path_any": fwd_any,
                          "back_path_tier_le2": back_g, "back_path_tier_le3": back_ns, "back_path_any": back_any})

    def cnt(key):
        return sum(1 for p in pairs if p[key] is not None)
    summary = {
        "standing_kiss_or_hug_sources": standing_kiss,
        "cuddle_targets": cuddles,
        "pairs_total": len(pairs),
        "pairs_fwd_reachable_any": cnt("fwd_len_any"),
        "pairs_fwd_reachable_tier_le3_(no_sexual_nodes)": cnt("fwd_len_tier_le3"),
        "pairs_fwd_reachable_tier_le2_(neutral/affection/kissing_only)": cnt("fwd_len_tier_le2"),
        "pairs_back_reachable_any": cnt("back_len_any"),
        "pairs_back_reachable_tier_le3_(no_sexual_nodes)": cnt("back_len_tier_le3"),
        "pairs_back_reachable_tier_le2_(neutral/affection/kissing_only)": cnt("back_len_tier_le2"),
        "pairs_fwd_reachable_strict_(tier_le2_and_not_staging)": cnt("fwd_len_strict"),
        "pairs_back_reachable_strict_(tier_le2_and_not_staging)": cnt("back_len_strict"),
        "staging_scenes": sorted(n for n in nodes if ok[n].get("staging")),
        "gentle_subgraph_nodes": len(gentle),
        "gentle_subgraph_edges": sum(1 for (a, b) in edges if a in gentle and b in gentle),
    }
    # weakly connected components of the gentle (tier<=2) induced subgraph, 2-actor no-furniture only
    g_nodes = sorted(n for n in gentle if ok[n]["actor_count"] == 2)
    g_out, g_inc = adjacency(g_nodes, [(a, b) for (a, b) in edges if a in gentle and b in gentle])
    g_wcc = weak_components(g_nodes, g_out, g_inc)
    g_scc = strong_components(g_nodes, g_out)
    summary["gentle_2actor_weak_component_sizes"] = [len(c) for c in g_wcc]
    summary["gentle_2actor_strong_component_sizes_gt1"] = [len(c) for c in g_scc if len(c) > 1]

    best = {}
    for p in pairs:
        if p["fwd_len_tier_le2"] is None:
            continue
        k = (ok[p["src"]]["pack"], ok[p["dst"]]["pack"])
        if k not in best or p["fwd_len_tier_le2"] < best[k]["fwd_len_tier_le2"]:
            best[k] = p
    examples = [dict(v, src_pack=k[0], dst_pack=k[1]) for k, v in sorted(best.items())]
    # one canonical long example: vanilla-OStim standing kiss -> OARE spooning cuddle and back
    canon = next((p for p in pairs if p["src"] == "OStim2PStandingKissEmbraceMF"
                  and p["dst"] == "OARE_SpooningCuddling1"), None)
    canon2 = next((p for p in pairs if p["src"] == "OARE_StandingEmbraceKiss"
                   and p["dst"] == "OARE_SpooningCuddling1"), None)
    compact = [{k: p[k] for k in ("src", "dst", "fwd_len_any", "fwd_len_tier_le3", "fwd_len_tier_le2",
                                  "fwd_len_strict", "back_len_any", "back_len_tier_le3", "back_len_tier_le2",
                                  "back_len_strict")} for p in pairs]
    return {"romantic_scenes": romantic, "connectivity_summary": summary,
            "shortest_examples": examples, "canonical_examples": [x for x in (canon, canon2) if x],
            "pair_matrix": compact}


# --------------------------------------------------------------------------
# neutral human readable description (prototype for the glue)
# --------------------------------------------------------------------------
POSTURE_TAGS = ["standing", "sitting", "kneeling", "squatting", "allfours", "bendover", "lyingback",
                "lyingfront", "lyingside", "handstanding", "suspended", "upsidedown", "sleeping"]


def describe(s):
    if s.get("error"):
        return ""
    parts = []
    roles = []
    for i, a in enumerate(s["actors"]):
        post = [t for t in a["tags"] if t in POSTURE_TAGS]
        roles.append("actor %d (%s%s)" % (i, a["intendedSex"], (", " + "/".join(post)) if post else ""))
    parts.append("%d actor%s: %s" % (s["actor_count"], "" if s["actor_count"] == 1 else "s", "; ".join(roles)))
    if s["furniture"] != "none":
        parts.append("furniture: %s" % s["furniture"])
    if s["actions"]:
        seen, acts_txt = set(), []
        for ac in s["actions"]:
            key = (ac["type"], ac["actor"], ac["target"])
            if key in seen:
                continue
            seen.add(key)
            acts_txt.append("%s %d->%d" % (ac["type"], ac["actor"], ac["target"])
                            if ac["actor"] != ac["target"] else "%s (%d)" % (ac["type"], ac["actor"]))
        parts.append("actions: " + ", ".join(acts_txt))
    else:
        parts.append("no actions (idle/pose)")
    if s["is_transition"]:
        parts.append("one-shot transition (%ss) -> %s" % (s["length"], s["destination"]))
    if s["tags"]:
        parts.append("tags: " + ",".join(s["tags"]))
    return " | ".join(parts)


# --------------------------------------------------------------------------
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mods-root", default="/mnt/f/Modlists/LoreRim/mods")
    ap.add_argument("--out", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "ostim_scene_stats.json"))
    args = ap.parse_args()

    tr = load_translations(args.mods_root)
    action_defs, alias = load_action_defs(args.mods_root)
    ftypes = load_furniture_types(args.mods_root)

    census = {k: collections.Counter() for k in ("scene", "navigation", "speed", "actor", "action")}
    scenes, duplicates, per_pack = {}, [], collections.defaultdict(list)
    roots = {}
    for pack, folder in PACKS:
        sroot = os.path.join(args.mods_root, folder, OSTIM_DATA, "scenes")
        roots[pack] = sroot
        if not os.path.isdir(sroot):
            continue
        for path in iter_json_files(sroot):
            rec = parse_scene(path, pack, sroot, alias, action_defs, census, tr)
            key = rec["id"].lower()
            if key in scenes:
                duplicates.append({"id": rec["id"], "first": scenes[key]["pack"] + ":" + scenes[key]["file"],
                                   "second": pack + ":" + rec["file"]})
            scenes[key] = rec
            per_pack[pack].append(rec)
    scenes_by_id = {s["id"]: s for s in scenes.values()}

    # sequences (OCR ships some)
    sequences = {}
    for pack, folder in PACKS:
        qdir = os.path.join(args.mods_root, folder, OSTIM_DATA, "sequences")
        if not os.path.isdir(qdir):
            continue
        for path in iter_json_files(qdir):
            obj, _st = load_json(path)
            lk = lower_keys(obj or {})
            sequences[os.path.splitext(os.path.basename(path))[0]] = {
                "pack": pack,
                "scenes": [{"id": lower_keys(e).get("id"), "duration": lower_keys(e).get("duration")}
                           for e in (lk.get("scenes") or []) if isinstance(e, dict)],
                "tags": lk.get("tags") or [],
            }

    edges, dangling, case_mismatch = build_edges(scenes_by_id)
    nodes = sorted(s["id"] for s in scenes_by_id.values() if not s.get("error"))
    out, inc = adjacency(nodes, edges.keys())
    # "staging" flag (glue-side convention): a tier-0 looping scene that is really the positional idle of an
    # explicit branch. Signals available in metadata: suggestive posture tags, or >= 40 % of its folded
    # navigations end in a tier-4 scene.
    cout = collapse_transitions(scenes_by_id, out)
    suggestive = {"allfours", "bendover", "spreadlegs", "ontop", "onbottom"}
    for sid, s in scenes_by_id.items():
        s["staging"] = False
        s["explicit_out_share"] = None
        if s.get("error") or s["is_transition"]:
            continue
        nb = list(cout.get(sid, {}))
        share4 = (sum(1 for x in nb if scenes_by_id[x]["tier"] == 4) / len(nb)) if nb else 0.0
        s["explicit_out_share"] = round(share4, 2)
        if s["tier"] == 0:
            atags = {t for a in s["actors"] for t in a["tags"]}
            s["staging"] = bool(share4 >= 0.4 or (atags & suggestive))

    wcc = weak_components(nodes, out, inc)
    scc = strong_components(nodes, out)
    form_counter = collections.Counter(d["form"] for decls in edges.values() for d in decls)
    cross_pack = collections.Counter(
        "%s->%s" % (scenes_by_id[a]["pack"], scenes_by_id[b]["pack"]) for (a, b) in edges)
    mismatch_actor_count = [(a, b) for (a, b) in edges
                            if scenes_by_id[a]["actor_count"] != scenes_by_id[b]["actor_count"]]
    furn_pairs = collections.Counter(
        "%s->%s" % (scenes_by_id[a]["furniture"], scenes_by_id[b]["furniture"]) for (a, b) in edges
        if scenes_by_id[a]["furniture"] != scenes_by_id[b]["furniture"])

    # autoTransition targets that do not exist
    auto_missing = collections.Counter()
    auto_total = 0
    for s in scenes_by_id.values():
        if s.get("error"):
            continue
        targets = list(s["autoTransitions"].values())
        for a in s["actors"]:
            targets += list(a["autoTransitions"].values())
        for t in targets:
            auto_total += 1
            if t.lower() not in scenes:
                auto_missing[t] += 1

    for s in scenes_by_id.values():
        s["description"] = describe(s)

    result = {
        "generated_utc": datetime.datetime.utcnow().isoformat() + "Z",
        "mods_root": args.mods_root,
        "scene_roots": roots,
        "translation_keys_loaded": len(tr),
        "key_census_raw_spelling": {k: counter_table(v) for k, v in census.items()},
        "action_definitions": {k: {"aliases": v.get("aliases", []), "tags": v.get("tags", []), "pack": v.get("pack"),
                                   "actor_requirements": (v.get("actor") or {}).get("requirements", []),
                                   "target_requirements": (v.get("target") or {}).get("requirements", []),
                                   "actor_stimulation": (v.get("actor") or {}).get("stimulation"),
                                   "target_stimulation": (v.get("target") or {}).get("stimulation")}
                               for k, v in sorted(action_defs.items())},
        "action_alias_map": dict(sorted((a, c) for a, c in alias.items() if a != c)),
        "furniture_types": {k: dict(v, chain=furniture_chain(k, ftypes)) for k, v in sorted(ftypes.items())},
        "duplicate_scene_ids": duplicates,
        "parse_problems": [{"id": s["id"], "pack": s["pack"], "file": s["file"], "parse": s["parse"]}
                           for s in scenes_by_id.values() if s["parse"] != "strict"],
        "packs": {p: dict(pack_stats(lst), id_conventions=id_conventions(lst)) for p, lst in per_pack.items()},
        "all": dict(pack_stats(list(scenes_by_id.values())), id_conventions=id_conventions(list(scenes_by_id.values()))),
        "graph_global": {
            "nodes": len(nodes),
            "edges_unique": len(edges),
            "edge_declarations": sum(len(v) for v in edges.values()),
            "edge_declarations_by_form": counter_table(form_counter),
            "edges_by_pack_pair": counter_table(cross_pack),
            "edges_between_different_actor_counts": len(mismatch_actor_count),
            "edges_between_different_actor_counts_samples": mismatch_actor_count[:20],
            "edges_between_different_furniture": counter_table(furn_pairs),
            "id_case_mismatch_references": case_mismatch,
            "dangling_references": len(dangling),
            "dangling_missing_ids": counter_table(collections.Counter(m for d in dangling for m in d["missing"])),
            "dangling_details": dangling[:80],
            "weak_components": len(wcc),
            "weak_component_sizes": [len(c) for c in wcc],
            "weak_components_small": [c for c in wcc if len(c) <= 12],
            "strong_components_gt1_sizes": [len(c) for c in scc if len(c) > 1],
            "in_degree_0": sorted(n for n in nodes if not inc[n]),
            "out_degree_0": sorted(n for n in nodes if not out[n]),
            "top_hubs": [{"id": n, "degree": len(out[n]) + len(inc[n]), "out": len(out[n]), "in": len(inc[n]),
                          "pack": scenes_by_id[n]["pack"]}
                         for n in sorted(nodes, key=lambda x: -(len(out[x]) + len(inc[x])))[:25]],
            "autoTransition_targets_total": auto_total,
            "autoTransition_targets_missing": counter_table(auto_missing),
        },
        "graph_collapsed_ostim_style": collapsed_stats(
            scenes_by_id, out,
            ["OStim2PStandingApartMF", "OARE_Standing", "OStim2PStandingKissEmbraceMF", "OARE_SpooningIdle",
             "OStim2PBothLyingMF", "OStimDoubleBedLeft2PBothSittingMF", "OStimChair2PSittingStandingMF"]),
        "graph_groups_furniture_x_actor_count": group_graph_stats(scenes_by_id, edges),
        "romantic": romantic_analysis(scenes_by_id, edges),
        "sequences": sequences,
        "edges": [{"src": a, "dst": b, "forms": sorted({d["form"] for d in decls}),
                   "description": next((d["description"] for d in decls if d.get("description")), None),
                   "priority": next((d["priority"] for d in decls if d.get("priority") is not None), None)}
                  for (a, b), decls in sorted(edges.items())],
        "scenes": {sid: {k: s.get(k) for k in (
            "pack", "file", "name_en", "modpack", "length", "is_transition", "destination", "origin", "furniture",
            "tags", "noRandomSelection", "n_speeds", "actor_count", "sex_config", "class", "tier", "tier_name",
            "staging", "explicit_out_share", "action_tags", "description")} | {
            "actors": [{"sex": a["intendedSex"], "type": a["type"], "tags": a["tags"],
                        "requirements": a["requirements"]} for a in s.get("actors", [])],
            "actions": [[a["type"], a["actor"], a["target"], a["performer"]] for a in s.get("actions", [])],
        } for sid, s in sorted(scenes_by_id.items())},
    }
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=1, ensure_ascii=False)
    print("scenes: %d  edges: %d  dangling: %d  ->  %s" % (len(nodes), len(edges), len(dangling), args.out))
    for p, lst in per_pack.items():
        print("  %-6s %4d scenes, %3d transitions" % (p, len(lst), sum(1 for s in lst if s.get("is_transition"))))
    return 0


if __name__ == "__main__":
    sys.exit(main())
