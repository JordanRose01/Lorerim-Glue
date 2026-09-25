#!/usr/bin/env python3
"""Read-only structural dump of a small Skyrim SE plugin: TES4 header, records, subrecords,
and a decode of QUST VMAD (scripts, fragment header, alias scripts). Used to compare the
generated LoreRimGlue.esp against a known-good MCM plugin. Usage: esp_dump.py <file.esp>"""
import struct, sys, zlib

def subrecords(data):
    i = 0
    while i + 6 <= len(data):
        sig = data[i:i+4].decode('latin1'); size = struct.unpack_from('<H', data, i+4)[0]
        yield sig, data[i+6:i+6+size]
        i += 6 + size

def wstr(b, o):
    n = struct.unpack_from('<H', b, o)[0]
    return b[o+2:o+2+n].decode('latin1'), o + 2 + n

def scripts(b, o, fmt):
    count = struct.unpack_from('<H', b, o)[0]; o += 2; out = []
    for _ in range(count):
        name, o = wstr(b, o); status = b[o]; o += 1
        props = struct.unpack_from('<H', b, o)[0]; o += 2
        if props:
            out.append(f'{name}(status={status}, props={props}: NOT DECODED)'); return out, None
        out.append(f'{name}(status={status}, props=0)')
    return out, o

def vmad(b):
    ver, fmt = struct.unpack_from('<hh', b, 0)
    s, o = scripts(b, 4, fmt)
    lines = [f'VMAD version={ver} objFormat={fmt} scripts={s}']
    if o is None or o >= len(b): return lines
    unk = b[o]; o += 1
    fragc = struct.unpack_from('<H', b, o)[0]; o += 2
    fname, o = wstr(b, o)
    lines.append(f'  fragments: unknown={unk} count={fragc} file="{fname}"')
    if fragc: lines.append('  (fragments present: alias section not decoded)'); return lines
    aliasc = struct.unpack_from('<H', b, o)[0]; o += 2
    for _ in range(aliasc):
        if fmt == 2:
            unused, aid, fid = struct.unpack_from('<HhI', b, o)
        else:
            fid, aid, unused = struct.unpack_from('<IhH', b, o)
        o += 8
        aver, afmt = struct.unpack_from('<hh', b, o); o += 4
        s, o2 = scripts(b, o, afmt)
        lines.append(f'  alias obj: formid={fid:08X} alias={aid} unused={unused} ver={aver} fmt={afmt} scripts={s}')
        if o2 is None: break
        o = o2
    lines.append(f'  bytes consumed {o}/{len(b)}')
    return lines

def main(path):
    d = open(path, 'rb').read()
    print(f'== {path} ({len(d)} bytes)')
    o = 0
    while o + 24 <= len(d):
        sig = d[o:o+4].decode('latin1')
        if sig == 'GRUP':
            size, label, gtype = struct.unpack_from('<I4sI', d, o+4)
            print(f'GRUP size={size} label={label!r} type={gtype} rest={d[o+16:o+24].hex()}')
            o += 24; continue
        size, flags, fid, vc, fver, unk = struct.unpack_from('<IIIIHH', d, o+4)
        body = d[o+24:o+24+size]
        print(f'{sig} size={size} flags={flags:08X} formid={fid:08X} vc={vc:08X} formver={fver} unk={unk}')
        if flags & 0x00040000:
            body = zlib.decompress(body[4:])
        for ssig, sdata in subrecords(body):
            if ssig == 'VMAD':
                for ln in vmad(sdata): print('   ' + ln)
            elif ssig == 'HEDR':
                v, n, nxt = struct.unpack('<fII', sdata); print(f'   HEDR version={v:.2f} records={n} nextObjectId={nxt:08X}')
            elif len(sdata) <= 24:
                txt = sdata.rstrip(b'\0').decode('latin1') if ssig in ('EDID', 'ALID', 'MAST', 'CNAM', 'SNAM', 'FULL') else sdata.hex()
                print(f'   {ssig} [{len(sdata)}] {txt}')
            else:
                print(f'   {ssig} [{len(sdata)}] {sdata[:24].hex()}...')
        o += 24 + size

if __name__ == '__main__':
    for p in sys.argv[1:]: main(p)
