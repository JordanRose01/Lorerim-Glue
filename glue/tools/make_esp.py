#!/usr/bin/env python3
"""Generates LoreRimGlue.esp (ESL-flagged, header 1.70, object ids in the ESL range) and its SEQ file
without the Creation Kit. The layout mirrors known-good MCM plugins from the LoreRim list
(decoded with esp_dump.py): TES4[HEDR,CNAM,SNAM,MAST,DATA,INTV] + GRUP QUST with
QUST[EDID,VMAD,FULL,DNAM,NEXT,ANAM, ALST,ALID,FNAM,ALFR,VTCK,ALED] + [pt16] GRUP PACK with one
PACK (LRG_FollowPackage: CHIM's own follow package with Bethesda's follower radii, see follow_package).
Only master: Skyrim.esm (needed for the player reference 0x14). No master on OStim.esp or
AIAgent.esp - every OStim / CHIM call is a global native function, so removing either mod
can never make this plugin a missing-master crash.
Usage: make_esp.py <output folder>   (writes LoreRimGlue.esp and Seq/LoreRimGlue.seq)"""
import os, struct, sys

PLUGIN = 'LoreRimGlue.esp'
FORM_VERSION = 44
OWN_INDEX = 0x01  # one master -> own forms are 01xxxxxx inside the file
# [0.5.5] LRG_MainQuest moved to a NEW object id. The saves of 22 Sep (Save14, Save15) carry two
# Papyrus stacks frozen against each other on the instances bound to the old main quest (an OnInit
# deadlock, research/pt12-stuck-oninit-fix.md); every event of that quest queued behind them. A
# form that no longer exists leaves those instances unbound, so the new quest starts with fresh
# ones. The old id is RETIRED: reusing it would bind the frozen instances again. The MCM quest keeps
# its id, so MCM Helper's registration (config.json names formId 0x801) is untouched.
MAIN_QUEST_ID = 0x802
MCM_QUEST_ID = 0x801
# [pt16] LRG_FollowPackage (research/pt16-follow.md section 6.1). Spent for good like every id here: a
# save may hold this override by form (PapyrusUtil ActorUtil), so it must never be reused for anything else.
PACK_ID = 0x803
NEXT_OBJECT_ID = 0x804
RETIRED_IDS = (0x800,)  # the pre-0.5.5 LRG_MainQuest - never reuse
ESL_OBJECT_IDS = range(2048, 4096)  # the object ids an ESL-flagged plugin may use

def sub(sig, data): return sig.encode('latin1') + struct.pack('<H', len(data)) + data
def zstr(s): return s.encode('latin1') + b'\0'
def wstr(s): return struct.pack('<H', len(s)) + s.encode('latin1')
def record(sig, flags, formid, body):
    return sig.encode('latin1') + struct.pack('<IIIIHH', len(body), flags, formid, 0, FORM_VERSION, 0) + body

def script_block(names):
    out = struct.pack('<H', len(names))
    for n in names:
        out += wstr(n) + struct.pack('<B', 0) + struct.pack('<H', 0)  # status=local, 0 properties
    return out

def quest(formid, edid, full, scripts, alias_name, alias_scripts):
    vmad = struct.pack('<hh', 5, 2) + script_block(scripts)
    vmad += struct.pack('<B', 2) + struct.pack('<H', 0) + wstr('')          # fragment header: none
    vmad += struct.pack('<H', 1)                                             # one alias with scripts
    vmad += struct.pack('<HhI', 0, 0, formid)                                # object: unused, alias id 0, this quest
    vmad += struct.pack('<hh', 5, 2) + script_block(alias_scripts)
    body = sub('EDID', zstr(edid)) + sub('VMAD', vmad) + sub('FULL', zstr(full))
    body += sub('DNAM', bytes.fromhex('110100ff0000000000000000'))          # start game enabled, run once (as the references)
    body += sub('NEXT', b'') + sub('ANAM', struct.pack('<I', 1))
    body += sub('ALST', struct.pack('<I', 0)) + sub('ALID', zstr(alias_name)) + sub('FNAM', struct.pack('<I', 0))
    body += sub('ALFR', struct.pack('<I', 0x00000014)) + sub('VTCK', struct.pack('<I', 0)) + sub('ALED', b'')
    return record('QUST', 0, formid, body)

def follow_package(formid):
    """[pt16] LRG_FollowPackage - the glue's own follow package, laid over CHIM's by LRG_Main (research/
    pt16-follow.md). CHIM's AIAgentFollowPlayerPackage (AIAgent.esp PACK 0x2226D, form version 44) is a
    Follow procedure authored with Min Radius 512 / Max Radius 1024 units and Need LOS = 1 - four times
    the radii of Skyrim.esm's own follower packages (FollowPlayer 0x750BE / FollowerPackageTemplate
    0xD530D: 128 / 256, no LOS rule) - and package inputs cannot be changed at runtime, so the only way
    to give her the game's own follower distances is a PACK record of our own.
    This is CHIM's record subrecord for subrecord (verified byte for byte against the installed
    AIAgent.esp, matched by the low 24 bits of the form id - that plugin has five masters, so its own
    forms are 05xxxxxx inside the file), MINUS its VMAD (CHIM's fragment script) and MINUS its CTDA (a
    GetFactionRank on an AIAgent.esp faction: Skyrim.esm stays the only master), and with exactly three
    inputs patched: Min Radius 512 -> 128, Max Radius 1024 -> 256, Need LOS 1 -> 0. Everything else -
    PKDT (AllowSwimming, type 18, preferred speed Walk, interrupt flags 0), PSDT, PKCU, the player target,
    Accompany 0, Ride Horse 0, the input map, the one Follow procedure and its bindings, the empty
    OnBegin / OnEnd / OnChange blocks - is CHIM's. No condition: the override is only ever put on and
    taken off by LRG_Main, and PapyrusUtil runs an unconditioned override as always valid. 691 bytes."""
    b = sub('EDID', zstr('LRG_FollowPackage'))
    b += sub('PKDT', bytes.fromhex('000004001200000000000000'))    # general 0x00040000 AllowSwimming, type 18, no interrupt override, Walk, interrupts 0
    b += sub('PSDT', bytes.fromhex('ffff00ffff00000000000000'))    # schedule: any month / day / date / hour / minute, duration 0
    b += sub('PKCU', struct.pack('<III', 6, 0, 4))                 # 6 data inputs, no template, version 4
    b += sub('ANAM', zstr('SingleRef')) + sub('PTDA', struct.pack('<IIi', 0, 0x00000014, 0))  # input 0 Target to Follow: SpecificReference = the player
    b += sub('ANAM', zstr('Float')) + sub('CNAM', struct.pack('<f', 128.0))                   # input 1 Min Radius  (CHIM: 512.0)
    b += sub('ANAM', zstr('Float')) + sub('CNAM', struct.pack('<f', 256.0))                   # input 2 Max Radius  (CHIM: 1024.0)
    b += sub('ANAM', zstr('Bool')) + sub('CNAM', b'\x00')                                     # input 4 Accompany?  0 (as CHIM, as vanilla FollowPlayer)
    b += sub('ANAM', zstr('Bool')) + sub('CNAM', b'\x00')                                     # input 6 Ride Horse? 0
    b += sub('ANAM', zstr('Bool')) + sub('CNAM', b'\x00')                                     # input 8 Need LOS?   0 (CHIM: 1)
    for idx in (0, 1, 2, 4, 6, 8): b += sub('UNAM', bytes([idx]))                             # the input index map
    b += sub('XNAM', b'\x09')
    b += sub('ANAM', zstr('Sequence')) + sub('CITC', struct.pack('<I', 0)) + sub('PRCB', struct.pack('<II', 1, 0))  # one procedure
    b += sub('ANAM', zstr('Procedure')) + sub('CITC', struct.pack('<I', 0)) + sub('PNAM', zstr('Follow')) + sub('FNAM', struct.pack('<I', 0))
    for idx in (0, 1, 1, 4, 6, 8): b += sub('PKC2', bytes([idx]))                             # the procedure's parameter -> input bindings, as CHIM's
    for idx, name in ((0, 'Target to Follow'), (1, 'Min Radius:'), (2, 'Max Radius:'), (4, 'Accompany?'), (6, 'Ride Horse?'), (8, 'Need LOS?')):
        b += sub('UNAM', bytes([idx])) + sub('BNAM', zstr(name)) + sub('PNAM', struct.pack('<I', 1))
    for sig in ('POBA', 'POEA', 'POCA'):                                                      # OnBegin / OnEnd / OnChange: nothing
        b += sub(sig, b'') + sub('INAM', struct.pack('<I', 0)) + sub('PDTO', bytes(8))
    assert len(b) == 691, len(b)
    return record('PACK', 0, formid, b)

def main(outdir):
    assert MAIN_QUEST_ID not in RETIRED_IDS and MCM_QUEST_ID not in RETIRED_IDS and PACK_ID not in RETIRED_IDS
    assert MCM_QUEST_ID in ESL_OBJECT_IDS and MAIN_QUEST_ID in ESL_OBJECT_IDS and PACK_ID in ESL_OBJECT_IDS and NEXT_OBJECT_ID in ESL_OBJECT_IDS
    assert len({MAIN_QUEST_ID, MCM_QUEST_ID, PACK_ID}) == 3 and NEXT_OBJECT_ID > max(MAIN_QUEST_ID, MCM_QUEST_ID, PACK_ID)
    main_q = (OWN_INDEX << 24) | MAIN_QUEST_ID
    mcm_q = (OWN_INDEX << 24) | MCM_QUEST_ID
    pack_f = (OWN_INDEX << 24) | PACK_ID
    # v0.4: the menuless questing driver and its first-playtest probe join the main quest's VMAD
    # (the two added names below). Nothing else about this plugin changes: script_block() already
    # writes N names with 0 properties, no new master. A script added to a running quest's VMAD is
    # attached on load. [0.5.5] The main quest itself moved to MAIN_QUEST_ID (see the top of file).
    quests = quest(main_q, 'LRG_MainQuest', 'LoreRim Glue', ['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe'], 'PlayerAlias', ['LRG_PlayerAlias'])
    quests += quest(mcm_q, 'LRG_MCMQuest', 'LoreRim Glue MCM', ['LRG_MCM'], 'PlayerRef', ['SKI_PlayerLoadGameAlias'])
    grup = b'GRUP' + struct.pack('<I4sI', 24 + len(quests), b'QUST', 0) + bytes(8) + quests
    # [pt16] the follow package in its own top-level group, after the quests
    pack = follow_package(pack_f)
    grup += b'GRUP' + struct.pack('<I4sI', 24 + len(pack), b'PACK', 0) + bytes(8) + pack

    hedr = struct.pack('<fII', 1.70, 5, NEXT_OBJECT_ID)  # 2 groups + 3 records; next free object id
    tes4 = sub('HEDR', hedr) + sub('CNAM', zstr('LoreRim Glue')) + sub('SNAM', zstr('LoreRim x CHIM x OStim integration layer'))
    tes4 += sub('MAST', zstr('Skyrim.esm')) + sub('DATA', struct.pack('<Q', 0)) + sub('INTV', struct.pack('<I', 1))
    data = record('TES4', 0x00000200, 0, tes4) + grup       # 0x200 = light (ESL) flag

    os.makedirs(os.path.join(outdir, 'Seq'), exist_ok=True)
    with open(os.path.join(outdir, PLUGIN), 'wb') as f: f.write(data)
    with open(os.path.join(outdir, 'Seq', PLUGIN[:-4] + '.seq'), 'wb') as f: f.write(struct.pack('<II', main_q, mcm_q))
    print(f'wrote {PLUGIN} ({len(data)} bytes) and Seq/{PLUGIN[:-4]}.seq')

if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else '.')
