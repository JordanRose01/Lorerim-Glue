#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
p2_swf_strings.py  -  READ-ONLY SWF (ActionScript 2) identifier extractor + AS2 disassembler / pseudo-decompiler.

Purpose (LoreRim Glue, phase 2 research): establish with evidence which instance paths, fields and
functions exist inside the dialoguemenu.swf that actually wins in the LoreRim install (CHIM's), and
diff it against the other provider(s) (Norden UI / Dear Diary Dark Mode).

It never writes next to the input files. Outputs:
    <out_dir>/p2_swf_identifiers.json      identifier sets, class/member map, callbacks, diffs
    <out_dir>/p2_swf_disasm_<label>.txt    per-SWF listing: tags + pseudo-code + raw disassembly

Usage:
    py p2_swf_strings.py <out_dir> <label>=<path.swf> [<label>=<path.swf> ...]

No third-party modules. CWS = zlib after the 8-byte header, ZWS = LZMA, FWS = raw.
"""
import sys, os, json, zlib, struct, lzma, hashlib, re

# ----------------------------------------------------------------------------------------------
# byte / bit reader
# ----------------------------------------------------------------------------------------------
class Rd:
    def __init__(self, data, pos=0, end=None):
        self.d = data
        self.p = pos
        self.end = len(data) if end is None else end
        self.bitbuf = 0
        self.bitcnt = 0

    def eof(self):
        return self.p >= self.end

    def u8(self):
        v = self.d[self.p]; self.p += 1; return v

    def u16(self):
        v = struct.unpack_from('<H', self.d, self.p)[0]; self.p += 2; return v

    def s16(self):
        v = struct.unpack_from('<h', self.d, self.p)[0]; self.p += 2; return v

    def u32(self):
        v = struct.unpack_from('<I', self.d, self.p)[0]; self.p += 4; return v

    def f32(self):
        v = struct.unpack_from('<f', self.d, self.p)[0]; self.p += 4; return v

    def f64swf(self):
        # SWF stores doubles as two little-endian 32-bit words, high word first
        b = self.d[self.p:self.p + 8]; self.p += 8
        return struct.unpack('<d', b[4:8] + b[0:4])[0]

    def cstr(self):
        e = self.d.index(b'\x00', self.p)
        s = self.d[self.p:e]; self.p = e + 1
        try:
            return s.decode('utf-8')
        except UnicodeDecodeError:
            return s.decode('latin-1')

    def align(self):
        self.bitbuf = 0; self.bitcnt = 0

    def ub(self, n):
        v = 0
        for _ in range(n):
            if self.bitcnt == 0:
                self.bitbuf = self.u8(); self.bitcnt = 8
            self.bitcnt -= 1
            v = (v << 1) | ((self.bitbuf >> self.bitcnt) & 1)
        return v

    def sb(self, n):
        v = self.ub(n)
        if n and (v & (1 << (n - 1))):
            v -= (1 << n)
        return v

    def rect(self):
        self.align()
        n = self.ub(5)
        r = [self.sb(n) for _ in range(4)]  # xmin xmax ymin ymax (twips)
        self.align()
        return r

    def matrix(self):
        self.align()
        m = {'sx': 1.0, 'sy': 1.0, 'r0': 0.0, 'r1': 0.0}
        if self.ub(1):
            n = self.ub(5); m['sx'] = self.sb(n) / 65536.0; m['sy'] = self.sb(n) / 65536.0
        if self.ub(1):
            n = self.ub(5); m['r0'] = self.sb(n) / 65536.0; m['r1'] = self.sb(n) / 65536.0
        n = self.ub(5)
        m['tx'] = self.sb(n) / 20.0; m['ty'] = self.sb(n) / 20.0
        self.align()
        return m

    def cxform_alpha(self):
        self.align()
        has_add = self.ub(1); has_mul = self.ub(1); n = self.ub(4)
        if has_mul:
            for _ in range(4): self.sb(n)
        if has_add:
            for _ in range(4): self.sb(n)
        self.align()


# ----------------------------------------------------------------------------------------------
# AS2 opcodes
# ----------------------------------------------------------------------------------------------
OPS = {
    0x04: 'NextFrame', 0x05: 'PrevFrame', 0x06: 'Play', 0x07: 'Stop', 0x08: 'ToggleQuality', 0x09: 'StopSounds',
    0x0A: 'Add', 0x0B: 'Subtract', 0x0C: 'Multiply', 0x0D: 'Divide', 0x0E: 'Equals', 0x0F: 'Less',
    0x10: 'And', 0x11: 'Or', 0x12: 'Not', 0x13: 'StringEquals', 0x14: 'StringLength', 0x15: 'StringExtract',
    0x17: 'Pop', 0x18: 'ToInteger', 0x1C: 'GetVariable', 0x1D: 'SetVariable', 0x20: 'SetTarget2', 0x21: 'StringAdd',
    0x22: 'GetProperty', 0x23: 'SetProperty', 0x24: 'CloneSprite', 0x25: 'RemoveSprite', 0x26: 'Trace',
    0x27: 'StartDrag', 0x28: 'EndDrag', 0x29: 'StringLess', 0x2A: 'Throw', 0x2B: 'CastOp', 0x2C: 'ImplementsOp',
    0x30: 'RandomNumber', 0x31: 'MBStringLength', 0x32: 'CharToAscii', 0x33: 'AsciiToChar', 0x34: 'GetTime',
    0x35: 'MBStringExtract', 0x36: 'MBCharToAscii', 0x37: 'MBAsciiToChar',
    0x3A: 'Delete', 0x3B: 'Delete2', 0x3C: 'DefineLocal', 0x3D: 'CallFunction', 0x3E: 'Return', 0x3F: 'Modulo',
    0x40: 'NewObject', 0x41: 'DefineLocal2', 0x42: 'InitArray', 0x43: 'InitObject', 0x44: 'TypeOf', 0x45: 'TargetPath',
    0x46: 'Enumerate', 0x47: 'Add2', 0x48: 'Less2', 0x49: 'Equals2', 0x4A: 'ToNumber', 0x4B: 'ToString',
    0x4C: 'PushDuplicate', 0x4D: 'StackSwap', 0x4E: 'GetMember', 0x4F: 'SetMember', 0x50: 'Increment', 0x51: 'Decrement',
    0x52: 'CallMethod', 0x53: 'NewMethod', 0x54: 'InstanceOf', 0x55: 'Enumerate2',
    0x60: 'BitAnd', 0x61: 'BitOr', 0x62: 'BitXor', 0x63: 'BitLShift', 0x64: 'BitRShift', 0x65: 'BitURShift',
    0x66: 'StrictEquals', 0x67: 'Greater', 0x68: 'StringGreater', 0x69: 'Extends',
    0x81: 'GotoFrame', 0x83: 'GetURL', 0x87: 'StoreRegister', 0x88: 'ConstantPool', 0x8A: 'WaitForFrame',
    0x8B: 'SetTarget', 0x8C: 'GoToLabel', 0x8D: 'WaitForFrame2', 0x8E: 'DefineFunction2', 0x8F: 'Try',
    0x94: 'With', 0x96: 'Push', 0x99: 'Jump', 0x9A: 'GetURL2', 0x9B: 'DefineFunction', 0x9D: 'If', 0x9E: 'Call',
    0x9F: 'GotoFrame2',
}
BINOPS = {
    'Add': '+', 'Add2': '+', 'Subtract': '-', 'Multiply': '*', 'Divide': '/', 'Modulo': '%',
    'Equals': '==', 'Equals2': '==', 'StrictEquals': '===', 'Less': '<', 'Less2': '<', 'Greater': '>',
    'And': '&&', 'Or': '||', 'BitAnd': '&', 'BitOr': '|', 'BitXor': '^', 'BitLShift': '<<', 'BitRShift': '>>',
    'BitURShift': '>>>', 'StringAdd': 'add', 'StringEquals': 'eq', 'StringLess': 'lt', 'StringGreater': 'gt',
}
IDENT_RE = re.compile(r'^[A-Za-z_$][A-Za-z0-9_$]*$')


def q(s):
    return json.dumps(s, ensure_ascii=False)


class Collect:
    """Everything harvested from one SWF."""
    def __init__(self):
        self.pool_strings = set()
        self.push_strings = set()
        self.func_names = set()          # named DefineFunction/DefineFunction2
        self.member_funcs = []           # {scope, name, params}
        self.member_sets = set()         # every X.<name> = ... target name
        self.member_gets = set()         # every .<name> read
        self.method_calls = set()
        self.var_names = set()


class Ctx:
    def __init__(self, col, scope):
        self.col = col
        self.scope = scope
        self.pool = []
        self.raw = []   # raw disassembly lines


def parse_push(r, end, pool, col):
    vals = []
    while r.p < end:
        t = r.u8()
        if t == 0:
            s = r.cstr(); col.push_strings.add(s); vals.append(('s', s))
        elif t == 1:
            vals.append(('n', repr(round(r.f32(), 6))))
        elif t == 2:
            vals.append(('k', 'null'))
        elif t == 3:
            vals.append(('k', 'undefined'))
        elif t == 4:
            vals.append(('r', r.u8()))
        elif t == 5:
            vals.append(('k', 'true' if r.u8() else 'false'))
        elif t == 6:
            v = r.f64swf(); vals.append(('n', repr(int(v)) if v == int(v) and abs(v) < 1e15 else repr(v)))
        elif t == 7:
            v = r.u32()
            if v & 0x80000000: v -= 0x100000000
            vals.append(('n', str(v)))
        elif t == 8:
            i = r.u8(); vals.append(('s', pool[i] if i < len(pool) else '<pool#%d>' % i))
        elif t == 9:
            i = r.u16(); vals.append(('s', pool[i] if i < len(pool) else '<pool#%d>' % i))
        else:
            vals.append(('k', '<pushtype%d>' % t)); break
    return vals


def member(obj, name_expr, name_is_str, name_raw):
    if name_is_str and IDENT_RE.match(name_raw or ''):
        return '%s.%s' % (obj, name_raw)
    return '%s[%s]' % (obj, name_expr)


class Val:
    __slots__ = ('e', 's')
    def __init__(self, e, s=None):
        self.e = e      # expression text
        self.s = s      # raw string if this is a string literal


def decompile(data, start, end, ctx, indent, regnames, depth=0):
    """Linear stack-simulating pseudo-decompiler. Returns list of text lines.
    Branches are emitted as 'if (c) goto L'. Approximate around &&, || and ?: (values kept on the stack
    across a jump) - the raw disassembly in the same listing is authoritative."""
    col = ctx.col
    pad = '    ' * indent
    out = []
    # pass 1: jump targets
    targets = set()
    r = Rd(data, start, end)
    while r.p < end:
        op = r.u8()
        ln = 0
        if op >= 0x80:
            ln = r.u16()
        body = r.p
        if op in (0x99, 0x9D):
            off = struct.unpack_from('<h', data, body)[0]
            targets.add(body + ln + off)
        if op == 0x9B:      # DefineFunction: skip body
            rr = Rd(data, body); rr.cstr(); n = rr.u16()
            for _ in range(n): rr.cstr()
            cs = rr.u16(); r.p = body + ln + cs; continue
        if op == 0x8E:
            rr = Rd(data, body); rr.cstr(); n = rr.u16(); rr.u8(); rr.u16()
            for _ in range(n): rr.u8(); rr.cstr()
            cs = rr.u16(); r.p = body + ln + cs; continue
        r.p = body + ln
        if op == 0:
            pass
    # pass 2
    st = []

    def pop():
        return st.pop() if st else Val('<?>')

    def emit(s):
        for i, line in enumerate(s.split('\n')):
            out.append(pad + line)

    def rawl(s):
        ctx.raw.append('%s%s' % ('  ' * depth, s))

    r = Rd(data, start, end)
    while r.p < end:
        at = r.p
        if at in targets:
            out.append('%sL_%04X:' % ('    ' * max(indent - 1, 0) + '  ', at))
            rawl('L_%04X:' % at)
        op = r.u8()
        ln = 0
        if op >= 0x80:
            ln = r.u16()
        body = r.p
        nxt = body + ln
        name = OPS.get(op, 'op_%02X' % op)
        if op == 0x00:
            rawl('%04X End' % at)
            continue
        if name == 'ConstantPool':
            rr = Rd(data, body, nxt); n = rr.u16()
            ctx.pool = [rr.cstr() for _ in range(n)]
            for s in ctx.pool: col.pool_strings.add(s)
            rawl('%04X ConstantPool [%d] %s' % (at, n, ', '.join(q(s) for s in ctx.pool)))
        elif name == 'Push':
            vals = parse_push(Rd(data, body, nxt), nxt, ctx.pool, col)
            txt = []
            for k, v in vals:
                if k == 's':
                    st.append(Val(q(v), v)); txt.append(q(v))
                elif k == 'r':
                    rn = regnames.get(v, 'r%d' % v)
                    st.append(Val(rn)); txt.append('reg%d(%s)' % (v, rn))
                else:
                    st.append(Val(v)); txt.append(v)
            rawl('%04X Push %s' % (at, ', '.join(txt)))
        elif name in ('DefineFunction', 'DefineFunction2'):
            rr = Rd(data, body, nxt)
            fname = rr.cstr(); n = rr.u16()
            rn = {}
            params = []
            if name == 'DefineFunction2':
                regcount = rr.u8(); flags = rr.u16()
                reg = 1
                for bit, nm in ((0x01, 'this'), (0x04, 'arguments'), (0x10, 'super'), (0x40, '_root'), (0x80, '_parent'), (0x100, '_global')):
                    if flags & bit:
                        rn[reg] = nm; reg += 1
                for _ in range(n):
                    pr = rr.u8(); pn = rr.cstr(); params.append(pn)
                    if pr: rn[pr] = pn
            else:
                for _ in range(n): params.append(rr.cstr())
            cs = rr.u16()
            fstart = nxt; fend = nxt + cs
            rawl('%04X %s %s(%s) codeSize=%d {' % (at, name, fname or '<anon>', ', '.join(params), cs))
            blines = decompile(data, fstart, fend, ctx, 1, rn, depth + 1)
            rawl('%04X }' % fend)
            text = 'function %s(%s) {\n%s\n}' % (fname, ', '.join(params), '\n'.join(blines))
            if fname:
                col.func_names.add(fname)
                col.member_funcs.append({'scope': ctx.scope, 'name': fname, 'params': params, 'kind': 'named'})
                emit(text)
            else:
                v = Val(text)
                st.append(v)
                FUNC_PARAMS[id(v)] = params   # remembered so 'X.name = function' can record the parameter list
                KEEP.append(v)
            r.p = fend
            continue
        elif name == 'StoreRegister':
            n = data[body]
            rn = regnames.get(n, 'r%d' % n)
            rawl('%04X StoreRegister %d' % (at, n))
            if st:
                top = st[-1]
                nv = Val('(%s = %s)' % (rn, top.e)) if not top.e.startswith('function ') else top
                if top.e.startswith('function '):
                    # keep function value but note the register alias
                    emit('// %s = <function below>' % rn)
                else:
                    if id(top) in FUNC_PARAMS: FUNC_PARAMS[id(nv)] = FUNC_PARAMS[id(top)]
                    st[-1] = nv
        elif name in ('Jump', 'If'):
            off = struct.unpack_from('<h', data, body)[0]
            tgt = nxt + off
            if name == 'If':
                c = pop()
                emit('if (%s) goto L_%04X' % (c.e, tgt))
            else:
                emit('goto L_%04X' % tgt)
            rawl('%04X %s L_%04X' % (at, name, tgt))
        elif name == 'GetVariable':
            v = pop(); rawl('%04X GetVariable' % at)
            if v.s is not None:
                col.var_names.add(v.s); st.append(Val(v.s if IDENT_RE.match(v.s) or '.' in v.s or ':' in v.s else 'eval(%s)' % v.e))
            else:
                st.append(Val('eval(%s)' % v.e))
        elif name == 'SetVariable':
            val = pop(); nm = pop(); rawl('%04X SetVariable' % at)
            if nm.s is not None: col.var_names.add(nm.s)
            emit('%s = %s' % (nm.s if nm.s is not None else 'eval(%s)' % nm.e, val.e))
        elif name == 'GetMember':
            nm = pop(); ob = pop(); rawl('%04X GetMember' % at)
            if nm.s is not None: col.member_gets.add(nm.s)
            st.append(Val(member(ob.e, nm.e, nm.s is not None, nm.s)))
        elif name == 'SetMember':
            val = pop(); nm = pop(); ob = pop(); rawl('%04X SetMember' % at)
            if nm.s is not None:
                col.member_sets.add(nm.s)
                if id(val) in FUNC_PARAMS:
                    col.member_funcs.append({'scope': ctx.scope, 'owner': ob.e, 'name': nm.s, 'params': FUNC_PARAMS[id(val)], 'kind': 'member'})
            emit('%s = %s' % (member(ob.e, nm.e, nm.s is not None, nm.s), val.e))
        elif name in ('CallFunction', 'NewObject'):
            nm = pop(); n = pop(); rawl('%04X %s' % (at, name))
            try: cnt = int(n.e)
            except ValueError: cnt = 0
            args = [pop().e for _ in range(cnt)]
            fn = nm.s if nm.s is not None else nm.e
            if nm.s is not None: col.method_calls.add(nm.s)
            st.append(Val('%s%s(%s)' % ('new ' if name == 'NewObject' else '', fn, ', '.join(args))))
        elif name in ('CallMethod', 'NewMethod'):
            nm = pop(); ob = pop(); n = pop(); rawl('%04X %s' % (at, name))
            try: cnt = int(n.e)
            except ValueError: cnt = 0
            args = [pop().e for _ in range(cnt)]
            if nm.s is not None and nm.s != '':
                col.method_calls.add(nm.s)
                tgt = member(ob.e, nm.e, True, nm.s)
            elif nm.e in ('undefined', '""'):
                tgt = ob.e
            else:
                tgt = '%s[%s]' % (ob.e, nm.e)
            st.append(Val('%s%s(%s)' % ('new ' if name == 'NewMethod' else '', tgt, ', '.join(args))))
        elif name == 'InitArray':
            n = pop(); rawl('%04X InitArray' % at)
            try: cnt = int(n.e)
            except ValueError: cnt = 0
            st.append(Val('[%s]' % ', '.join(pop().e for _ in range(cnt))))
        elif name == 'InitObject':
            n = pop(); rawl('%04X InitObject' % at)
            try: cnt = int(n.e)
            except ValueError: cnt = 0
            items = []
            for _ in range(cnt):
                v = pop(); k = pop()
                if k.s is not None: col.member_sets.add(k.s)
                items.append('%s: %s' % (k.s if k.s is not None else k.e, v.e))
            st.append(Val('{%s}' % ', '.join(items)))
        elif name == 'Pop':
            v = pop(); rawl('%04X Pop' % at)
            emit(v.e)
        elif name == 'DefineLocal':
            v = pop(); nm = pop(); rawl('%04X DefineLocal' % at)
            if nm.s is not None: col.var_names.add(nm.s)
            emit('var %s = %s' % (nm.s if nm.s is not None else nm.e, v.e))
        elif name == 'DefineLocal2':
            nm = pop(); rawl('%04X DefineLocal2' % at)
            emit('var %s' % (nm.s if nm.s is not None else nm.e))
        elif name == 'Return':
            v = pop(); rawl('%04X Return' % at)
            emit('return %s' % v.e)
        elif name in BINOPS:
            b = pop(); a = pop(); rawl('%04X %s' % (at, name))
            st.append(Val('(%s %s %s)' % (a.e, BINOPS[name], b.e)))
        elif name == 'Not':
            a = pop(); rawl('%04X Not' % at); st.append(Val('!%s' % a.e))
        elif name == 'Increment':
            a = pop(); rawl('%04X Increment' % at); st.append(Val('(%s + 1)' % a.e))
        elif name == 'Decrement':
            a = pop(); rawl('%04X Decrement' % at); st.append(Val('(%s - 1)' % a.e))
        elif name == 'PushDuplicate':
            rawl('%04X PushDuplicate' % at)
            if st:
                t = st[-1]; nv = Val(t.e, t.s)
                if id(t) in FUNC_PARAMS: FUNC_PARAMS[id(nv)] = FUNC_PARAMS[id(t)]; KEEP.append(nv)
                st.append(nv)
            else:
                st.append(Val('<?>'))
        elif name == 'StackSwap':
            rawl('%04X StackSwap' % at)
            a = pop(); b = pop(); st.append(a); st.append(b)
        elif name in ('TypeOf', 'ToNumber', 'ToString', 'ToInteger', 'TargetPath', 'StringLength', 'MBStringLength',
                      'CharToAscii', 'AsciiToChar', 'RandomNumber', 'MBCharToAscii', 'MBAsciiToChar'):
            a = pop(); rawl('%04X %s' % (at, name))
            fn = {'TypeOf': 'typeof', 'ToNumber': 'Number', 'ToString': 'String', 'ToInteger': 'int',
                  'TargetPath': 'targetPath', 'StringLength': 'length', 'MBStringLength': 'mblength',
                  'CharToAscii': 'ord', 'AsciiToChar': 'chr', 'RandomNumber': 'random',
                  'MBCharToAscii': 'mbord', 'MBAsciiToChar': 'mbchr'}[name]
            st.append(Val('%s(%s)' % (fn, a.e)))
        elif name == 'GetTime':
            rawl('%04X GetTime' % at); st.append(Val('getTimer()'))
        elif name in ('Enumerate', 'Enumerate2'):
            a = pop(); rawl('%04X %s' % (at, name))
            emit('// for..in over %s' % a.e)
            st.append(Val('<enum-next of %s>' % a.e))
        elif name == 'Extends':
            sup = pop(); sub = pop(); rawl('%04X Extends' % at)
            emit('%s extends %s' % (sub.e, sup.e))
        elif name == 'ImplementsOp':
            c = pop(); n = pop(); rawl('%04X ImplementsOp' % at)
            try: cnt = int(n.e)
            except ValueError: cnt = 0
            emit('%s implements %s' % (c.e, ', '.join(pop().e for _ in range(cnt))))
        elif name == 'CastOp':
            o = pop(); c = pop(); rawl('%04X CastOp' % at); st.append(Val('%s(%s)' % (c.e, o.e)))
        elif name == 'InstanceOf':
            c = pop(); o = pop(); rawl('%04X InstanceOf' % at); st.append(Val('(%s instanceof %s)' % (o.e, c.e)))
        elif name == 'Delete':
            nm = pop(); ob = pop(); rawl('%04X Delete' % at)
            st.append(Val('delete %s' % member(ob.e, nm.e, nm.s is not None, nm.s)))
        elif name == 'Delete2':
            nm = pop(); rawl('%04X Delete2' % at); st.append(Val('delete %s' % (nm.s if nm.s is not None else nm.e)))
        elif name == 'GetProperty':
            i = pop(); t = pop(); rawl('%04X GetProperty' % at); st.append(Val('getProperty(%s, %s)' % (t.e, i.e)))
        elif name == 'SetProperty':
            v = pop(); i = pop(); t = pop(); rawl('%04X SetProperty' % at); emit('setProperty(%s, %s, %s)' % (t.e, i.e, v.e))
        elif name == 'Trace':
            v = pop(); rawl('%04X Trace' % at); emit('trace(%s)' % v.e)
        elif name == 'Throw':
            v = pop(); rawl('%04X Throw' % at); emit('throw %s' % v.e)
        elif name == 'With':
            o = pop(); size = struct.unpack_from('<H', data, body)[0]
            rawl('%04X With size=%d' % (at, size)); emit('with (%s) { // next %d bytes' % (o.e, size))
        elif name == 'Try':
            rr = Rd(data, body, nxt); fl = rr.u8(); ts = rr.u16(); cs = rr.u16(); fs = rr.u16()
            cn = ('r%d' % rr.u8()) if (fl & 4) else rr.cstr()
            rawl('%04X Try try=%d catch=%d finally=%d catchVar=%s' % (at, ts, cs, fs, cn))
            emit('try { // try=%d bytes, catch(%s)=%d bytes, finally=%d bytes' % (ts, cn, cs, fs))
        elif name == 'GotoFrame':
            f = struct.unpack_from('<H', data, body)[0]; rawl('%04X GotoFrame %d' % (at, f)); emit('gotoFrame(%d)' % f)
        elif name == 'GoToLabel':
            s = Rd(data, body).cstr(); col.push_strings.add(s); rawl('%04X GoToLabel %s' % (at, q(s))); emit('gotoLabel(%s)' % q(s))
        elif name == 'SetTarget':
            s = Rd(data, body).cstr(); col.push_strings.add(s); rawl('%04X SetTarget %s' % (at, q(s))); emit('tellTarget(%s)' % q(s))
        elif name == 'SetTarget2':
            v = pop(); rawl('%04X SetTarget2' % at); emit('tellTarget(%s)' % v.e)
        elif name == 'GetURL':
            rr = Rd(data, body); u = rr.cstr(); t = rr.cstr(); col.push_strings.add(u)
            rawl('%04X GetURL %s %s' % (at, q(u), q(t))); emit('getURL(%s, %s)' % (q(u), q(t)))
        elif name == 'GetURL2':
            t = pop(); u = pop(); rawl('%04X GetURL2 flags=%d' % (at, data[body])); emit('getURL2(%s, %s)' % (u.e, t.e))
        elif name == 'GotoFrame2':
            f = pop(); rawl('%04X GotoFrame2 flags=%d' % (at, data[body])); emit('gotoFrame2(%s)%s' % (f.e, ' play' if data[body] & 1 else ' stop'))
        elif name == 'Call':
            f = pop(); rawl('%04X Call' % at); emit('call(%s)' % f.e)
        elif name in ('Play', 'Stop', 'NextFrame', 'PrevFrame', 'StopSounds', 'ToggleQuality', 'EndDrag'):
            rawl('%04X %s' % (at, name)); emit('%s()' % name.lower())
        elif name == 'CloneSprite':
            d = pop(); t = pop(); s = pop(); rawl('%04X CloneSprite' % at); emit('duplicateMovieClip(%s, %s, %s)' % (s.e, t.e, d.e))
        elif name == 'RemoveSprite':
            t = pop(); rawl('%04X RemoveSprite' % at); emit('removeMovieClip(%s)' % t.e)
        elif name == 'StartDrag':
            t = pop(); lock = pop(); con = pop(); rawl('%04X StartDrag' % at)
            if con.e not in ('0', 'false'):
                for _ in range(4): pop()
            emit('startDrag(%s)' % t.e)
        elif name in ('StringExtract', 'MBStringExtract'):
            c = pop(); i = pop(); s = pop(); rawl('%04X %s' % (at, name)); st.append(Val('substring(%s, %s, %s)' % (s.e, i.e, c.e)))
        else:
            rawl('%04X %s len=%d %s' % (at, name, ln, data[body:nxt].hex()))
            emit('// unhandled %s' % name)
        r.p = nxt
    if st:
        leftovers = [v.e for v in st if not v.e.startswith('<enum-next')]
        if leftovers:
            out.append(pad + '// stack left: ' + ' | '.join(x.replace('\n', ' ')[:120] for x in leftovers))
    return out


FUNC_PARAMS = {}
KEEP = []   # keep Val objects alive so id() keys stay unique


# ----------------------------------------------------------------------------------------------
# SWF container
# ----------------------------------------------------------------------------------------------
TAGNAMES = {0: 'End', 1: 'ShowFrame', 2: 'DefineShape', 4: 'PlaceObject', 5: 'RemoveObject', 6: 'DefineBits', 7: 'DefineButton',
            8: 'JPEGTables', 9: 'SetBackgroundColor', 10: 'DefineFont', 11: 'DefineText', 12: 'DoAction', 13: 'DefineFontInfo',
            14: 'DefineSound', 20: 'DefineBitsLossless', 21: 'DefineBitsJPEG2', 22: 'DefineShape2', 24: 'Protect',
            26: 'PlaceObject2', 28: 'RemoveObject2', 32: 'DefineShape3', 33: 'DefineText2', 34: 'DefineButton2',
            35: 'DefineBitsJPEG3', 36: 'DefineBitsLossless2', 37: 'DefineEditText', 39: 'DefineSprite', 43: 'FrameLabel',
            45: 'SoundStreamHead2', 46: 'DefineMorphShape', 48: 'DefineFont2', 56: 'ExportAssets', 57: 'ImportAssets',
            58: 'EnableDebugger', 59: 'DoInitAction', 60: 'DefineVideoStream', 62: 'DefineFontInfo2', 64: 'EnableDebugger2',
            65: 'ScriptLimits', 66: 'SetTabIndex', 69: 'FileAttributes', 70: 'PlaceObject3', 71: 'ImportAssets2',
            73: 'DefineFontAlignZones', 74: 'CSMTextSettings', 75: 'DefineFont3', 76: 'SymbolClass', 77: 'Metadata',
            78: 'DefineScalingGrid', 83: 'DefineShape4', 84: 'DefineMorphShape2', 86: 'DefineSceneAndFrameLabelData',
            88: 'DefineFontName', 91: 'DefineFont4'}


def load_swf(path):
    raw = open(path, 'rb').read()
    sig = raw[:3]
    ver = raw[3]
    flen = struct.unpack_from('<I', raw, 4)[0]
    if sig == b'FWS':
        body = raw[8:]
    elif sig == b'CWS':
        body = zlib.decompress(raw[8:])
    elif sig == b'ZWS':
        # 4 bytes compressed length, 5 bytes LZMA props, then data
        props = raw[12:17]
        body = lzma.LZMADecompressor(format=lzma.FORMAT_RAW, filters=[lzma._decode_filter_properties(lzma.FILTER_LZMA1, props)]).decompress(raw[17:])
    else:
        raise ValueError('not a SWF: %r' % sig)
    return {'sig': sig.decode(), 'version': ver, 'declared_len': flen, 'file_len': len(raw),
            'sha256': hashlib.sha256(raw).hexdigest(), 'body_sha256': hashlib.sha256(body).hexdigest()}, body


def analyse(label, path):
    hdr, body = load_swf(path)
    r = Rd(body)
    frame = r.rect()
    rate = r.u16() / 256.0
    nframes = r.u16()
    hdr.update({'stage_twips': frame, 'stage_px': [(frame[1] - frame[0]) / 20.0, (frame[3] - frame[2]) / 20.0],
                'fps': rate, 'frames': nframes, 'path': path})
    col = Collect()
    info = {'header': hdr, 'exports': {}, 'imports': [], 'symbol_classes': {}, 'frame_labels': [], 'placements': [],
            'edit_texts': [], 'sprites': {}, 'tag_counts': {}, 'script_blocks': [], 'char_types': {}}
    listing = ['=' * 100, 'SWF %s  (%s)' % (label, path), json.dumps(hdr), '=' * 100]
    pending_blocks = []   # (title, scope, data, start, end) decompiled after exports are known

    def walk(rd, end, sprite_id, frame_no_box):
        while rd.p < end:
            tagpos = rd.p
            ch = rd.u16(); code = ch >> 6; ln = ch & 0x3F
            if ln == 0x3F: ln = rd.u32()
            b = rd.p; e = b + ln
            nm = TAGNAMES.get(code, 'Tag%d' % code)
            info['tag_counts'][nm] = info['tag_counts'].get(nm, 0) + 1
            try:
                if code == 1:
                    frame_no_box[0] += 1
                elif code == 39:
                    rr = Rd(body, b, e); sid = rr.u16(); fc = rr.u16()
                    info['sprites'][sid] = {'frames': fc}
                    info['char_types'][sid] = 'Sprite'
                    walk(rr, e, sid, [0])
                elif code == 56:
                    rr = Rd(body, b, e)
                    for _ in range(rr.u16()):
                        cid = rr.u16(); info['exports'][cid] = rr.cstr()
                elif code in (57, 71):
                    rr = Rd(body, b, e); url = rr.cstr()
                    if code == 71: rr.u8(); rr.u8()
                    for _ in range(rr.u16()):
                        cid = rr.u16(); info['imports'].append({'url': url, 'id': cid, 'name': rr.cstr()})
                elif code == 76:
                    rr = Rd(body, b, e)
                    for _ in range(rr.u16()):
                        cid = rr.u16(); info['symbol_classes'][cid] = rr.cstr()
                elif code == 43:
                    rr = Rd(body, b, e)
                    info['frame_labels'].append({'sprite': sprite_id, 'frame': frame_no_box[0] + 1, 'label': rr.cstr()})
                elif code in (26, 70):
                    rr = Rd(body, b, e); fl = rr.u8(); fl2 = rr.u8() if code == 70 else 0
                    depth = rr.u16(); cls = None; cid = None; name = None; mtx = None
                    if code == 70 and ((fl2 & 0x08) or ((fl2 & 0x10) and (fl & 0x02))): cls = rr.cstr()
                    if fl & 0x02: cid = rr.u16()
                    if fl & 0x04: mtx = rr.matrix()
                    if fl & 0x08: rr.cxform_alpha()
                    if fl & 0x10: rr.u16()
                    if fl & 0x20: name = rr.cstr()
                    if fl & 0x40: rr.u16()
                    clip_events = False
                    if fl & 0x80 and code == 26:
                        clip_events = True
                        rr.u16()
                        rr.u32() if hdr['version'] >= 6 else rr.u16()
                        while rr.p < e:
                            ev = rr.u32() if hdr['version'] >= 6 else rr.u16()
                            if ev == 0: break
                            size = rr.u32(); astart = rr.p
                            if ev & 0x00020000: astart += 1  # key code byte
                            pending_blocks.append(('ClipActions sprite=%s depth=%d name=%s events=0x%08X' % (sprite_id, depth, name, ev),
                                                   'clip:%s' % name, astart, rr.p + size))
                            rr.p += size
                    if name is not None or cid is not None:
                        info['placements'].append({'parent_sprite': sprite_id, 'frame': frame_no_box[0] + 1, 'depth': depth,
                                                   'char_id': cid, 'name': name, 'class': cls, 'move': bool(fl & 1),
                                                   'pos': [mtx['tx'], mtx['ty']] if mtx else None, 'clip_events': clip_events})
                elif code == 37:
                    rr = Rd(body, b, e); cid = rr.u16(); rect = rr.rect(); f1 = rr.u8(); f2 = rr.u8()
                    font = None; fcls = None
                    if f1 & 0x01: font = rr.u16()
                    if f2 & 0x80: fcls = rr.cstr()
                    if (f1 & 0x01) or (f2 & 0x80): rr.u16()
                    if f1 & 0x04: rr.u32()
                    if f1 & 0x02: rr.u16()
                    if f2 & 0x20:
                        rr.u8(); rr.u16(); rr.u16(); rr.u16(); rr.s16()
                    var = rr.cstr(); txt = rr.cstr() if (f1 & 0x80) else None
                    info['char_types'][cid] = 'EditText'
                    info['edit_texts'].append({'char_id': cid, 'variable': var, 'initial_text': txt,
                                               'multiline': bool(f1 & 0x20), 'wordwrap': bool(f1 & 0x40), 'html': bool(f2 & 0x02),
                                               'rect_px': [x / 20.0 for x in rect]})
                elif code == 12:
                    pending_blocks.append(('DoAction sprite=%s frame=%d' % (sprite_id, frame_no_box[0] + 1),
                                           'frame:%s:%d' % (sprite_id, frame_no_box[0] + 1), b, e))
                elif code == 59:
                    sid = struct.unpack_from('<H', body, b)[0]
                    pending_blocks.append(('DoInitAction sprite=%d' % sid, 'init:%d' % sid, b + 2, e))
                elif code in (7, 34):
                    rr = Rd(body, b, e); bid = rr.u16(); info['char_types'][bid] = 'Button'
                    if code == 34:
                        rr.u8(); offpos = rr.p; aoff = rr.u16()
                        if aoff:
                            p = offpos + aoff
                            while True:
                                size = struct.unpack_from('<H', body, p)[0]; cond = struct.unpack_from('<H', body, p + 2)[0]
                                aend = (p + size) if size else e
                                pending_blocks.append(('Button2 id=%d cond=0x%04X' % (bid, cond), 'button:%d' % bid, p + 4, aend))
                                if not size: break
                                p += size
                elif code in (2, 22, 32, 83):
                    info['char_types'][struct.unpack_from('<H', body, b)[0]] = 'Shape'
                elif code in (11, 33):
                    info['char_types'][struct.unpack_from('<H', body, b)[0]] = 'StaticText'
                elif code in (10, 48, 75, 91):
                    info['char_types'][struct.unpack_from('<H', body, b)[0]] = 'Font'
                elif code in (6, 20, 21, 35, 36):
                    info['char_types'][struct.unpack_from('<H', body, b)[0]] = 'Bitmap'
            except Exception as ex:   # keep going: a bad tag must not hide the rest
                listing.append('!! tag %s at %d: %r' % (nm, tagpos, ex))
            rd.p = e
            if code == 0:
                break

    walk(r, len(body), 'root', [0])

    # decompile script blocks now that export names are known
    for title, scope, s, e in pending_blocks:
        scope_name = scope
        if scope.startswith('init:'):
            sid = int(scope[5:]); scope_name = 'init:%d:%s' % (sid, info['exports'].get(sid, '?'))
        ctx = Ctx(col, scope_name)
        try:
            lines = decompile(body, s, e, ctx, 0, {})
        except Exception as ex:
            lines = ['!! decompile failed: %r' % ex]
        listing.append('')
        listing.append('#' * 100)
        listing.append('# %s   [%s]   bytes %d..%d' % (title, scope_name, s, e))
        listing.append('#' * 100)
        listing.append('---- pseudo-code (best effort; raw listing below is authoritative) ----')
        listing.extend(lines)
        listing.append('---- raw disassembly ----')
        listing.extend(ctx.raw)
        info['script_blocks'].append({'title': title, 'scope': scope_name, 'bytes': e - s})

    text = '\n'.join(listing)
    # harvest call patterns from the pseudo-code
    gd_calls = sorted(set(re.findall(r'GameDelegate\.call\(("[^"]*")', text)))
    gd_callbacks = sorted(set(re.findall(r'GameDelegate\.addCallBack\(("[^"]*"), [^,]+, ("[^"]*")\)', text)))
    skse_calls = sorted(set(re.findall(r'\bskse\.([A-Za-z_]+)\(', text)))
    modevents = sorted(set(re.findall(r'skse\.SendModEvent\(([^\n]*?)\)\s*$', text, flags=re.M)))
    ext_if = sorted(set(re.findall(r'ExternalInterface\.[A-Za-z_]+\([^\n]*', text)))
    info['game_delegate_calls'] = [json.loads(x) for x in gd_calls]
    info['game_delegate_callbacks'] = [{'event': json.loads(a), 'function': json.loads(b)} for a, b in gd_callbacks]
    info['skse_calls'] = skse_calls
    info['skse_modevents'] = modevents
    info['external_interface'] = ext_if
    info['pool_strings'] = sorted(col.pool_strings)
    info['push_strings'] = sorted(col.push_strings)
    info['named_functions'] = sorted(col.func_names)
    info['member_functions'] = col.member_funcs
    info['member_sets'] = sorted(col.member_sets)
    info['member_gets'] = sorted(col.member_gets)
    info['method_calls'] = sorted(col.method_calls)
    info['variables'] = sorted(col.var_names)
    info['instance_names'] = sorted(set(p['name'] for p in info['placements'] if p['name']))
    ids = set(col.pool_strings) | set(col.push_strings) | set(info['instance_names']) | set(info['exports'].values()) \
        | set(x['label'] for x in info['frame_labels']) | set(x['variable'] for x in info['edit_texts'] if x['variable'])
    info['all_identifiers'] = sorted(ids)
    # tidy key types for json
    info['exports'] = {str(k): v for k, v in info['exports'].items()}
    info['symbol_classes'] = {str(k): v for k, v in info['symbol_classes'].items()}
    info['sprites'] = {str(k): v for k, v in info['sprites'].items()}
    info['char_types'] = {str(k): v for k, v in info['char_types'].items()}
    return info, text


def main():
    if len(sys.argv) < 3:
        print(__doc__); return 2
    out_dir = sys.argv[1]
    result = {'tool': 'p2_swf_strings.py', 'swfs': {}, 'diffs': {}}
    seen = {}   # sha256 -> first label (byte-identical files are analysed once)
    result['identical_files'] = {}
    for arg in sys.argv[2:]:
        label, path = arg.split('=', 1)
        digest = hashlib.sha256(open(path, 'rb').read()).hexdigest()
        if digest in seen:
            result['identical_files'][label] = {'path': path, 'sha256': digest, 'identical_to': seen[digest]}
            print('%-10s byte-identical to %s (sha256 %s) - not analysed twice' % (label, seen[digest], digest[:16]))
            continue
        seen[digest] = label
        FUNC_PARAMS.clear(); KEEP.clear()
        info, text = analyse(label, path)
        result['swfs'][label] = info
        with open(os.path.join(out_dir, 'p2_swf_disasm_%s.txt' % label), 'w', encoding='utf-8') as f:
            f.write(text)
        print('%-10s v%d %s frames=%d tags=%d ids=%d sha256=%s' % (label, info['header']['version'], info['header']['sig'],
              info['header']['frames'], sum(info['tag_counts'].values()), len(info['all_identifiers']), info['header']['sha256'][:16]))
    labels = list(result['swfs'].keys())
    for i in range(len(labels)):
        for j in range(len(labels)):
            if i == j: continue
            a, b = labels[i], labels[j]
            sa = set(result['swfs'][a]['all_identifiers']); sb = set(result['swfs'][b]['all_identifiers'])
            fa = set((m.get('name')) for m in result['swfs'][a]['member_functions'])
            fb = set((m.get('name')) for m in result['swfs'][b]['member_functions'])
            result['diffs']['%s_minus_%s' % (a, b)] = {'identifiers': sorted(sa - sb), 'functions': sorted(x for x in (fa - fb) if x)}
    with open(os.path.join(out_dir, 'p2_swf_identifiers.json'), 'w', encoding='utf-8') as f:
        json.dump(result, f, indent=1, ensure_ascii=False)
    return 0


if __name__ == '__main__':
    sys.exit(main())
