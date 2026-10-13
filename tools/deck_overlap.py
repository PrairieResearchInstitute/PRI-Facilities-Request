#!/usr/bin/env python3
"""
Geometry defects in a .pptx, read out of the built file.

Nothing here trusts the generator: it opens the zip, reads every shape's
box out of ppt/slides/slideN.xml, and reports what a person would see -
things sitting on top of each other, text spilling past its box, shapes
off the slide, and anything trespassing on the master's chrome.

    python tools/deck_overlap.py PRIOps-Briefing.pptx [--slides 1,2,12]

Exit code 1 when anything is reported, so a build chain can stop on it.
"""

import sys
import re
import zipfile
import math
from collections import defaultdict
from xml.etree import ElementTree as ET

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

A = 'http://schemas.openxmlformats.org/drawingml/2006/main'
P = 'http://schemas.openxmlformats.org/presentationml/2006/main'

EMU = 914400.0
SLIDE_W = 12192000
SLIDE_H = 6858000

# What the slide master paints. A slide's own shapes may live inside the
# header band (the title sits there by design) but must not stray into
# the campus banner, and must not straddle the orange rule.
HEADER_BOT = 1005840         # navy band + orange rule
BANNER_TOP = 6085840         # campus banner starts

TOL = 9525                   # a hairline: overlaps under this are contact, not collision
MIN_AREA = 0.004             # in square inches - below this an overlap is invisible


def q(tag, ns=A):
    return '{%s}%s' % (ns, tag)


class Shape:
    __slots__ = ('sid', 'name', 'kind', 'x', 'y', 'w', 'h', 'rot', 'text',
                 'paras', 'filled', 'outlined', 'scale', 'ins', 'anchor')

    @property
    def x2(self):
        return self.x + self.w

    @property
    def y2(self):
        return self.y + self.h

    @property
    def has_text(self):
        return bool(self.text.strip())

    def __repr__(self):
        return '#%s %s [%.2f,%.2f %.2fx%.2f]"%s"' % (
            self.sid, self.kind, self.x / EMU, self.y / EMU,
            self.w / EMU, self.h / EMU, self.text[:28])


def rotated_box(x, y, w, h, rot):
    """The axis-aligned box a rotated shape actually occupies."""
    if not rot:
        return x, y, w, h
    ang = math.radians(rot / 60000.0)
    cx, cy = x + w / 2.0, y + h / 2.0
    c, s = abs(math.cos(ang)), abs(math.sin(ang))
    nw = w * c + h * s
    nh = w * s + h * c
    return cx - nw / 2.0, cy - nh / 2.0, nw, nh


def read_shapes(xml):
    """Every drawable on one slide, with its box and its text."""
    root = ET.fromstring(xml)
    tree = root.find('.//' + q('cSld', P) + '/' + q('spTree', P))
    out = []
    if tree is None:
        return out
    for el in list(tree):
        tag = el.tag.split('}')[-1]
        if tag not in ('sp', 'pic', 'cxnSp', 'graphicFrame', 'grpSp'):
            continue
        sp = Shape()
        sp.kind = tag
        cNvPr = el.find('.//' + q('cNvPr', P))
        sp.sid = cNvPr.get('id') if cNvPr is not None else '?'
        sp.name = cNvPr.get('name', '') if cNvPr is not None else ''

        xfrm = el.find('.//' + q('xfrm'))
        if xfrm is None:
            continue
        off = xfrm.find(q('off'))
        ext = xfrm.find(q('ext'))
        if off is None or ext is None:
            continue
        x, y = int(off.get('x')), int(off.get('y'))
        w, h = int(ext.get('cx')), int(ext.get('cy'))
        rot = int(xfrm.get('rot') or 0)
        x, y, w, h = rotated_box(x, y, w, h, rot)
        sp.x, sp.y, sp.w, sp.h, sp.rot = x, y, w, h, rot

        spPr = el.find(q('spPr', P))
        sp.filled = spPr is not None and spPr.find(q('solidFill')) is not None
        ln = spPr.find(q('ln')) if spPr is not None else None
        sp.outlined = ln is not None and ln.find(q('solidFill')) is not None
        if tag == 'pic':
            sp.filled = True
            # A mark screened back to a tenth of opacity is a watermark:
            # it is put there to be read through, so text over it is the
            # design, not a collision.
            amt = el.find('.//' + q('alphaModFix'))
            if amt is not None and int(amt.get('amt') or 100000) <= 20000:
                sp.kind = 'watermark'

        # Text, paragraph by paragraph, with the size each run asks for.
        sp.paras = []
        sp.text = ''
        sp.scale = 1.0
        sp.ins = (91440, 45720)
        sp.anchor = 't'
        tx = el.find(q('txBody', P))
        if tx is not None:
            bodyPr = tx.find(q('bodyPr'))
            if bodyPr is not None:
                sp.anchor = bodyPr.get('anchor', 't')
                sp.ins = (int(bodyPr.get('lIns', 91440)) + int(bodyPr.get('rIns', 91440)),
                          int(bodyPr.get('tIns', 45720)) + int(bodyPr.get('bIns', 45720)))
                naf = bodyPr.find(q('normAutofit'))
                if naf is not None and naf.get('fontScale'):
                    sp.scale = int(naf.get('fontScale')) / 100000.0
            for p in tx.findall(q('p')):
                runs = p.findall(q('r'))
                t = ''.join((r.find(q('t')).text or '') for r in runs
                            if r.find(q('t')) is not None)
                sz = 1800
                bullet = 0
                before = 0
                for r in runs:
                    rPr = r.find(q('rPr'))
                    if rPr is not None and rPr.get('sz'):
                        sz = int(rPr.get('sz'))
                        break
                pPr = p.find(q('pPr'))
                if pPr is not None:
                    if pPr.find(q('buChar')) is not None or pPr.find(q('buAutoNum')) is not None:
                        bullet = int(pPr.get('marL') or 0)
                    sb = pPr.find(q('spcBef'))
                    if sb is not None and sb.find(q('spcPts')) is not None:
                        before = int(sb.find(q('spcPts')).get('val'))
                algn = pPr.get('algn', 'l') if pPr is not None else 'l'
                bold = any((r.find(q('rPr')) is not None
                            and r.find(q('rPr')).get('b') == '1') for r in runs)
                lnspc = 0
                if pPr is not None:
                    ls = pPr.find(q('lnSpc'))
                    if ls is not None and ls.find(q('spcPts')) is not None:
                        lnspc = int(ls.find(q('spcPts')).get('val'))
                sp.paras.append((t, sz, bullet, before, algn, bold, lnspc))
                sp.text += t + ' '
        out.append(sp)
    return out


def ink_box(sp):
    """
    Where a shape's text actually puts marks on the page. A full-width
    title box holding two words is empty across most of its span, and
    calling that an overlap buries the real ones.
    """
    if not sp.has_text:
        return sp.x, sp.y, sp.w, sp.h
    wIn = (sp.w - sp.ins[0]) / EMU
    widest, left = 0.0, wIn
    for t, sz, bullet, before, algn, bold, lnspc in sp.paras:
        if not t.strip():
            continue
        pt = sz / 100.0 * sp.scale
        indent = (bullet / EMU if bullet else 0.0)
        avail = max(0.2, wIn - indent)
        run = len(t) * pt * (0.0075 if bold else 0.0070)
        if run > avail:            # it wraps, so it uses the full width
            run, indent = avail, indent
        widest = max(widest, indent + run)
        left = min(left, indent if algn == 'l' else 0.0)
    if widest <= 0:
        return sp.x, sp.y, sp.w, sp.h
    w = min(sp.w, int(widest * EMU) + sp.ins[0])
    algn = sp.paras[0][4]
    if algn == 'ctr':
        x = sp.x + (sp.w - w) / 2.0
    elif algn == 'r':
        x = sp.x2 - w
    else:
        x = sp.x
    need = min(sp.h / EMU, text_height(sp))
    h = max(int(need * EMU), 1)
    if sp.anchor == 'ctr':
        y = sp.y + (sp.h - h) / 2.0
    elif sp.anchor == 'b':
        y = sp.y2 - h
    else:
        y = sp.y
    return x, y, w, h


LINE_RATIO = 1.125          # must match PPTX_LINE_RATIO in pptx.php
CHAR_W = 0.0063             # must match PPTX_CHAR_W


def text_height(sp):
    """
    Inches the text occupies, at the size it will actually render - the
    run size times the shape's own autofit scale, which is what the eye
    sees and what the font box in PowerPoint hides.

    Line spacing is read from the paragraph where it is stated, so this
    is the same arithmetic the writer used. When the two disagree the
    difference is a real defect, not two approximations drifting.
    """
    if not sp.paras:
        return 0.0
    wIn = (sp.w - sp.ins[0]) / EMU
    need = 0.0
    for t, sz, bullet, before, algn, bold, lnspc in sp.paras:
        pt = sz / 100.0 * sp.scale
        line = (lnspc / 100.0 * sp.scale) if lnspc else pt * LINE_RATIO
        avail = wIn - (bullet / EMU if bullet else 0.0)
        if avail <= 0.2:
            avail = 0.2
        cpl = max(6, int(avail / (pt * CHAR_W)))
        lines = max(1, math.ceil(len(t) / cpl)) if t else 1
        need += (lines * line + before / 100.0) / 72.0
    return need + sp.ins[1] / EMU


def text_height_at(sp, pt_body):
    """
    What this shape's text would occupy at a different body size.

    Line count is a step function of size, so this re-derives it rather
    than scaling the current height - the difference between "this box
    is 70% full" and "this box could take a bigger size".
    """
    if not sp.paras:
        return 0.0
    # The BODY size, not the largest - headings run two points above it,
    # and scaling from the heading asks the wrong question by a step.
    body = next((p[1] for p in sp.paras if p[2]), None)
    if body is None:
        body = max((p[1] for p in sp.paras), default=1600)
    base = body / 100.0 * sp.scale
    if base <= 0:
        return 0.0
    k = pt_body / base
    wIn = (sp.w - sp.ins[0]) / EMU
    need = 0.0
    for t, sz, bullet, before, algn, bold, lnspc in sp.paras:
        pt = sz / 100.0 * sp.scale * k
        line = pt * LINE_RATIO
        avail = max(0.2, wIn - (bullet / EMU if bullet else 0.0))
        cpl = max(6, int(avail / (pt * CHAR_W)))
        lines = max(1, math.ceil(len(t) / cpl)) if t else 1
        need += (lines * line + before / 100.0) / 72.0
    return need + sp.ins[1] / EMU


def overlap(a, b):
    """a and b are (x, y, w, h) boxes."""
    ox = min(a[0] + a[2], b[0] + b[2]) - max(a[0], b[0])
    oy = min(a[1] + a[3], b[1] + b[3]) - max(a[1], b[1])
    if ox <= TOL or oy <= TOL:
        return 0.0, 0.0, 0.0
    return ox * oy / (EMU * EMU), ox / EMU, oy / EMU


def contains(outer, inner, pad=TOL * 2):
    return (outer[0] - pad <= inner[0] and outer[1] - pad <= inner[1]
            and outer[0] + outer[2] + pad >= inner[0] + inner[2]
            and outer[1] + outer[3] + pad >= inner[1] + inner[3])


def full_bleed(sp):
    return sp.w >= SLIDE_W * 0.97 and sp.h >= SLIDE_H * 0.97


def backdrop(sp):
    """
    Art the slide is meant to be built on top of - the section lattice,
    the Block I. pptx_crop_pic names these; screenshots are 'shot' and
    overlay figures are 'vfig', and nothing may sit on either of those.
    """
    return sp.kind == 'pic' and sp.name.startswith('crop')


def check_slide(n, shapes):
    faults = []

    def add(cat, msg):
        faults.append((cat, msg))

    # A slide that paints its own full-bleed background owns the whole
    # canvas: the master's bands are underneath it, not in play.
    own_bg = any(full_bleed(s) for s in shapes)

    for s in shapes:
        if (s.w <= 0 or s.h <= 0) and s.kind != 'cxnSp':
            # A connector drawn straight has no thickness in one axis;
            # anything else with no size is a mistake.
            add('ZERO', '#%s %s has no size' % (s.sid, s.name))
            continue
        if s.x < -TOL or s.y < -TOL or s.x2 > SLIDE_W + TOL or s.y2 > SLIDE_H + TOL:
            if not full_bleed(s):
                add('OFFSLIDE', '#%s %s runs to [%.2f,%.2f]-[%.2f,%.2f]in'
                    % (s.sid, s.name, s.x / EMU, s.y / EMU, s.x2 / EMU, s.y2 / EMU))
        if not own_bg and s.has_text:
            # Text that stops one hairline above the banner still reads as
            # sitting on it. Demand real clearance, not bare non-overlap.
            if s.y2 > BANNER_TOP - 0.05 * EMU:
                add('BANNER', '#%s "%s" reaches %.2fin, over the campus banner at %.2fin'
                    % (s.sid, s.text[:34].strip(), s.y2 / EMU, BANNER_TOP / EMU))
            if s.y < HEADER_BOT - TOL and s.y2 > HEADER_BOT + TOL:
                add('HEADER', '#%s "%s" straddles the orange rule'
                    % (s.sid, s.text[:34].strip()))

        # A column that ends on a section heading has left that heading's
        # body in the other column. Nothing about the geometry is wrong,
        # so only reading the paragraphs catches it.
        if len(s.paras) > 1 and any(p[2] for p in s.paras):
            last = s.paras[-1]
            if last[5] and not last[2] and last[0].strip():
                add('ORPHANHEAD', '#%s ends on the heading "%s" - its body is elsewhere'
                    % (s.sid, last[0].strip()[:40]))

        if s.paras and s.has_text:
            need = text_height(s)
            box = s.h / EMU
            if need > box * 1.06 + 0.02:
                add('OVERFLOW', '#%s needs %.2fin in a %.2fin box (%.0f%% over) "%s"'
                    % (s.sid, need, box, 100 * (need / box - 1), s.text[:40].strip()))

    drawn = [s for s in shapes
             if s.kind not in ('cxnSp', 'watermark')
             and not full_bleed(s) and not backdrop(s)]
    boxes = {id(s): (ink_box(s) if s.has_text else (s.x, s.y, s.w, s.h)) for s in drawn}
    plates = {id(s): (s.x, s.y, s.w, s.h) for s in drawn}
    for i, a in enumerate(drawn):
        for b in drawn[i + 1:]:
            at, bt = a.has_text, b.has_text
            area, ox, oy = overlap(boxes[id(a)], boxes[id(b)])
            if area < MIN_AREA:
                continue
            if at and bt:
                add('TEXT/TEXT', '#%s "%s" over #%s "%s" (%.2fx%.2fin)'
                    % (a.sid, a.text[:26].strip(), b.sid, b.text[:26].strip(), ox, oy))
            elif at != bt:
                txt, panel = (a, b) if at else (b, a)
                if panel.kind == 'pic':
                    add('TEXT/IMAGE', '#%s "%s" over image #%s (%.2fx%.2fin)'
                        % (txt.sid, txt.text[:30].strip(), panel.sid, ox, oy))
                elif not contains(plates[id(panel)], boxes[id(txt)]):
                    # Text must sit wholly inside whatever plate is behind
                    # it, or it hangs off the edge of it.
                    add('TEXT/PANEL', '#%s "%s" hangs off plate #%s (%.2fx%.2fin)'
                        % (txt.sid, txt.text[:30].strip(), panel.sid, ox, oy))
            elif a.kind == 'pic' and b.kind == 'pic':
                add('IMAGE/IMAGE', '#%s over #%s (%.2fx%.2fin)' % (a.sid, b.sid, ox, oy))
            elif (a.filled and b.filled
                  and not contains(plates[id(a)], plates[id(b)])
                  and not contains(plates[id(b)], plates[id(a)]) and area > 0.05):
                add('PLATE/PLATE', '#%s over #%s (%.2fx%.2fin)' % (a.sid, b.sid, ox, oy))
    return faults


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    only = None
    for a in sys.argv[1:]:
        if a.startswith('--slides'):
            only = set(int(v) for v in a.split('=', 1)[1].replace(',', ' ').split())
    if not args:
        print(__doc__)
        return 2
    total = 0
    for path in args:
        z = zipfile.ZipFile(path)
        names = [n for n in z.namelist() if re.match(r'ppt/slides/slide\d+\.xml$', n)]
        names.sort(key=lambda n: int(re.search(r'(\d+)', n.split('/')[-1]).group(1)))
        print('=== %s : %d slides ===' % (path, len(names)))
        tally = defaultdict(int)
        for name in names:
            n = int(re.search(r'slide(\d+)', name).group(1))
            if only and n not in only:
                continue
            shapes = read_shapes(z.read(name))
            if not shapes:
                print('  slide %-4d EMPTY SLIDE' % n)
                tally['EMPTY'] += 1
                total += 1
                continue
            faults = check_slide(n, shapes)
            for cat, msg in faults:
                print('  slide %-4d %-12s %s' % (n, cat, msg))
                tally[cat] += 1
            total += len(faults)
        if tally:
            print('  --- ' + '  '.join('%s:%d' % kv for kv in sorted(tally.items())))
        else:
            print('  clean')
    return 1 if total else 0


if __name__ == '__main__':
    sys.exit(main())
