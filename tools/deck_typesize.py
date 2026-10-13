"""Report the effective body text size on every slide of both decks.

PowerPoint renders a run at its size times the shape's autofit
fontScale, so a 16pt run inside a shape scaled to 80% is 12.8pt on the
screen. This reads both numbers out of the built file and reports the
smallest body size each slide actually shows.

    py -X utf8 tools/deck_typesize.py [floor]
"""
import os
import re
import sys
import zipfile

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DECKS = ['PRIOps-Briefing.pptx', 'PRIOps-Pitch.pptx']

# Chrome that is deliberately small: the pipeline strip, captions, the
# part rail and the campus banner all sit at 12pt by ruling. Those are
# device shapes, numbered from 500 by every nv_* builder - which is how
# they are told apart from BODY text that happens to be 12pt. Excluding
# by size alone let a genuine 11.8pt body through this check unseen.
CHROME_ID = 500


def slide_order(z):
    pres = z.read('ppt/presentation.xml').decode('utf-8')
    rids = re.findall(r'<p:sldId id="\d+" r:id="(rId\d+)"/>', pres)
    rels = z.read('ppt/_rels/presentation.xml.rels').decode('utf-8')
    rmap = dict(re.findall(r'Id="(rId\d+)"[^>]*Target="slides/(slide\d+\.xml)"', rels))
    return [rmap[r] for r in rids]


def shapes(xml):
    """Each <p:sp>, with its autofit scale and the run sizes inside it."""
    for sp in re.findall(r'<p:sp>.*?</p:sp>', xml, re.S):
        m = re.search(r'<a:normAutofit fontScale="(\d+)"', sp)
        scale = int(m.group(1)) / 100000 if m else 1.0
        sizes = [int(s) for s in re.findall(r'sz="(\d+)"', sp)]
        idm = re.search(r'<p:cNvPr id="(\d+)"', sp)
        chrome = idm is not None and int(idm.group(1)) >= CHROME_ID
        title = 'y="60000"' in sp or 'y="120000"' in sp or 'y="91440"' in sp
        yield scale, sizes, (title or chrome)


def main():
    floor = float(sys.argv[1]) if len(sys.argv) > 1 else 16.0
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            print(f'-- {deck}: not built')
            continue
        z = zipfile.ZipFile(path)
        print(f'== {deck}   (body floor {floor:g}pt)')
        under = []
        for n, name in enumerate(slide_order(z), 1):
            xml = z.read('ppt/slides/' + name).decode('utf-8')
            smallest = None
            for scale, sizes, is_title in shapes(xml):
                if is_title:
                    continue
                for s in sizes:
                    if s >= 2000:
                        continue          # chrome, or a heading/number block
                    eff = round(s * scale / 100, 1)
                    if smallest is None or eff < smallest:
                        smallest = eff
            if smallest is not None and smallest < floor - 0.05:
                under.append((smallest, n))
        under.sort()
        for eff, n in under:
            print(f'   slide {n:>3}  body renders at {eff}pt')
        print(f'   {len(under)} slide(s) below {floor:g}pt')
    return 0


if __name__ == '__main__':
    sys.exit(main())
