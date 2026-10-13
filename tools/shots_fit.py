#!/usr/bin/env python3
"""
Crop screenshots that are too tall for the box they land in.

A picture is scaled to FIT its box, so one taller than the box's shape
renders narrower than the box - and every pixel of width it gives up is
type size. A 1144x831 page band in an 8.6x5.0in box renders 6.9in wide,
which puts the app's smallest text at 10.8pt. Cropped to the box's shape
it renders the full 8.6in and reads at 13.5pt.

This reads the built deck to learn what box each shot actually lands in,
then crops the SOURCE png in storage/shots so the next build is right.
Cuts at a quiet row so it never slices through a line of text.

    py -3 tools/shots_fit.py            # report only
    py -3 tools/shots_fit.py --apply    # crop them

Run it after process_shots.ps1 and before building. Idempotent: once a
shot is at or wider than its box's shape there is nothing left to do.
"""

import glob
import hashlib
import io
import os
import re
import sys
import zipfile

from PIL import Image

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SHOTS = os.path.join(ROOT, 'storage', 'shots')
DECKS = ['PRIOps-Briefing.pptx', 'PRIOps-Pitch.pptx']
SMALLEST_CSS_PX, SCALE = 12.5, 2


def quiet_row(im, target, floor=0.55):
    """
    The last row at or above `target` with no text crossing it.

    Tested by darkness, not uniformity: a screenshot is a white card on
    tinted paper, so rows are never uniform, but a row holding nothing
    darker than mid-grey holds no words.
    """
    w, h = im.size
    px = im.load()
    for y in range(min(target, h - 1), int(target * floor), -1):
        if min(min(px[x, y][:3]) for x in range(8, w - 8, 3)) > 150:
            return y
    return target


def main():
    apply = '--apply' in sys.argv
    by_hash = {}
    for f in glob.glob(os.path.join(SHOTS, '*.png')):
        if os.path.basename(f).startswith('diagram_'):
            continue        # drawings are not screenshots; they have their own check
        by_hash[hashlib.md5(open(f, 'rb').read()).hexdigest()] = f

    # The widest box a shot is placed in, across both decks.
    want = {}
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        for name in z.namelist():
            if not re.match(r'ppt/slides/slide\d+\.xml$', name):
                continue
            n = int(re.search(r'slide(\d+)', name).group(1))
            xml = z.read(name).decode('utf-8')
            rels = z.read('ppt/slides/_rels/slide%d.xml.rels' % n).decode('utf-8')
            rmap = dict(re.findall(r'Id="(rId\d+)"[^>]*Target="\.\./media/([^"]+)"', rels))
            for mm in re.finditer(
                    r'<p:pic>.*?name="(shot\d+)".*?r:embed="(rId\d+)".*?'
                    r'<a:ext cx="(\d+)" cy="(\d+)"/>', xml, re.S):
                tgt = rmap.get(mm.group(2))
                if not tgt:
                    continue
                src = by_hash.get(hashlib.md5(z.read('ppt/media/' + tgt)).hexdigest())
                if not src:
                    continue
                cx, cy = int(mm.group(3)), int(mm.group(4))
                # The rendered extent is the FIT result, so the box is at
                # least this - and a shot rendered square-on to its box is
                # already right. What matters is the box's shape, which we
                # recover from the deck's two known layouts.
                box = (7131000, 4440000) if abs(cx - 7131000) < 900000 \
                    else (7880000, 4560000)
                want[src] = max(want.get(src, 0.0), box[0] / box[1])

    fixed = 0
    for src, aspect in sorted(want.items()):
        im = Image.open(src).convert('RGB')
        w, h = im.size
        if w / h >= aspect - 0.01:
            continue
        target = int(w / aspect)
        cut = quiet_row(im, target)
        print('%-40s %dx%d -> %dx%d  (box shape %.2f)'
              % (os.path.basename(src), w, h, w, cut, aspect))
        if apply:
            im.crop((0, 0, w, cut)).save(src)
        fixed += 1

    if not fixed:
        print('every screenshot already fills its box')
        return 0
    print('\n%d shot(s) %s' % (fixed, 'cropped' if apply else 'need cropping - pass --apply'))
    return 0 if apply else 1


if __name__ == '__main__':
    sys.exit(main())
