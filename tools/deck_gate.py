#!/usr/bin/env python3
"""
The gate both decks must pass before either is called done.

Everything here reads the BUILT FILE. Nothing asks the generator what it
meant to do, because the recurring failure on this deck has not been bad
intentions - it has been reporting a fix from the source while the file
on disk still carried the defect.

    py -3 tools/deck_gate.py

Runs, over PRIOps-Briefing.pptx and PRIOps-Pitch.pptx:

  freshness  the file is newer than every generator and library it
             is built from, so a stale deck cannot pass
  lint       text defects - cut sentences, doubled words, orphans
  typesize   the size body text actually renders at, after autofit
  overlap    collisions, overflow, off-slide and chrome trespass
  logo       the title-slide lockup carries no pale border
  open       the package holds what PowerPoint refuses to open without

Exit code 0 only when every deck passes every check.
"""

import os
import re
import subprocess
import sys
import zipfile

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
PY = sys.executable
DECKS = ['PRIOps-Briefing.pptx', 'PRIOps-Pitch.pptx']

# Everything a deck is built out of. Newer than the deck means the deck
# is stale, whatever the last build said.
SOURCES = [
    'tools/make_presentation.php', 'tools/make_pitch.php', 'tools/deck_visuals.php',
    'facilities/lib/pptx.php', 'facilities/lib/pptxnative.php',
    'facilities/lib/joinmap.php', 'facilities/lib/demofill.php',
    'facilities/lib/backlog.php', 'facilities/lib/completeness.php',
    'facilities/lib/dataaudit.php',
]

FAILURES = []


def fail(check, msg):
    FAILURES.append((check, msg))
    print('  FAIL  %-10s %s' % (check, msg))


def ok(check, msg):
    print('  ok    %-10s %s' % (check, msg))


def check_freshness():
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            fail('freshness', deck + ' has not been built')
            continue
        built = os.path.getmtime(path)
        stale = [s for s in SOURCES
                 if os.path.isfile(os.path.join(ROOT, s))
                 and os.path.getmtime(os.path.join(ROOT, s)) > built]
        if stale:
            fail('freshness', '%s predates %s' % (deck, ', '.join(stale)))
        else:
            ok('freshness', deck + ' is newer than every source it is built from')


def check_logo():
    """The title-slide lockup must meet the slide with no pale border."""
    try:
        from PIL import Image
    except ImportError:
        fail('logo', 'Pillow is not available, so the lockup went unchecked')
        return
    import io

    def blank_for(im):
        """
        A transparent lockup is judged on alpha alone - its lettering is
        white, so calling pale pixels background would call the mark
        itself a border.
        """
        if im.getextrema()[3][0] < 16:
            return lambda p: p[3] < 16
        return lambda p: p[3] < 16 or (p[0] > 200 and p[1] > 200 and p[2] > 200)

    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        try:
            rels = z.read('ppt/slides/_rels/slide1.xml.rels').decode('utf-8')
        except KeyError:
            continue
        tgts = re.findall(r'Target="\.\./media/([^"]+)"', rels)
        if not tgts:
            fail('logo', deck + ' title slide carries no lockup')
            continue
        im = Image.open(io.BytesIO(z.read('ppt/media/' + tgts[0]))).convert('RGBA')
        blank = blank_for(im)
        w, h = im.size
        p = im.load()
        bad = [side for side, clean in (
            ('left', not all(blank(p[0, y]) for y in range(h))),
            ('right', not all(blank(p[w - 1, y]) for y in range(h))),
            ('top', not all(blank(p[x, 0]) for x in range(w))),
            ('bottom', not all(blank(p[x, h - 1]) for x in range(w)))) if not clean]
        if bad:
            fail('logo', '%s lockup has a blank border on %s' % (deck, ', '.join(bad)))
        else:
            ok('logo', '%s lockup meets the slide with no border (%dx%d)'
               % (deck, w, h))


def check_openable():
    """
    The two package faults PowerPoint refuses outright: a text body with
    no paragraph in it, and a slide referencing a relationship the part
    does not declare.
    """
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        names = [n for n in z.namelist() if re.match(r'ppt/slides/slide\d+\.xml$', n)]
        bad = []
        for n in sorted(names, key=lambda s: int(re.search(r'(\d+)', s.split('/')[-1]).group(1))):
            xml = z.read(n).decode('utf-8')
            no = int(re.search(r'slide(\d+)', n).group(1))
            for body in re.findall(r'<p:txBody>.*?</p:txBody>', xml, re.S):
                if '<a:p>' not in body and '<a:p/>' not in body:
                    bad.append('slide %d has a text body with no paragraph' % no)
            declared = set(re.findall(
                r'Id="(rId\d+)"',
                z.read('ppt/slides/_rels/slide%d.xml.rels' % no).decode('utf-8')))
            for rid in set(re.findall(r'r:embed="(rId\d+)"', xml)):
                if rid not in declared:
                    bad.append('slide %d uses undeclared %s' % (no, rid))
        if bad:
            for b in bad[:8]:
                fail('open', deck + ': ' + b)
        else:
            ok('open', '%s: %d slides, every part declared' % (deck, len(names)))


def check_drawing_type():
    """
    The smallest type inside the database drawing, as placed.

    A drawing is a picture: PowerPoint scales it, so the size its labels
    end up at is (font px / drawn px) x (placed inches) x 72 - nothing to
    do with what the SVG says. Getting this wrong by eye is how a drawing
    ships at 12.0pt while the arithmetic on a wrong box size says 13.
    """
    try:
        from PIL import Image
    except ImportError:
        fail('drawing', 'Pillow is not available, so the drawing went unchecked')
        return
    import io
    src = os.path.join(ROOT, 'facilities', 'diagram.php')
    m = re.search(r"const FS = (\d+);", open(src, encoding='utf-8').read())
    if not m:
        fail('drawing', 'cannot read FS out of diagram.php')
        return
    fs = int(m.group(1))
    drawn = os.path.join(ROOT, 'storage', 'shots', 'diagram_dbs.png')
    if not os.path.isfile(drawn):
        fail('drawing', 'the overview drawing has not been captured')
        return
    want = open(drawn, 'rb').read()
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        worst = None
        for name in z.namelist():
            if not re.match(r'ppt/slides/slide\d+\.xml$', name):
                continue
            n = int(re.search(r'slide(\d+)', name).group(1))
            xml = z.read(name).decode('utf-8')
            rels = z.read('ppt/slides/_rels/slide%d.xml.rels' % n).decode('utf-8')
            rmap = dict(re.findall(r'Id="(rId\d+)"[^>]*Target="\.\./media/([^"]+)"', rels))
            for mm in re.finditer(
                    r'<p:pic>.*?r:embed="(rId\d+)".*?<a:ext cx="(\d+)" cy="(\d+)"/>', xml, re.S):
                rid, cy = mm.group(1), int(mm.group(3))
                tgt = rmap.get(rid)
                # Only the database overview: identified by being byte-for-
                # byte the drawing that was captured, not by guessing from
                # its size - a background photo is big too.
                if not tgt or z.read('ppt/media/' + tgt) != want:
                    continue
                im = Image.open(io.BytesIO(want))
                pt = fs / im.size[1] * (cy / 914400.0) * 72
                if worst is None or pt < worst[0]:
                    worst = (pt, n, im.size, cy / 914400.0)
        if worst is None:
            continue
        pt, n, size, inches = worst
        msg = ('%s slide %d: %dpx type in a %dpx drawing placed %.2fin tall = %.2fpt'
               % (deck, n, fs, size[1], inches, pt))
        if pt < 12.0:
            fail('drawing', msg + ' - under the 12pt floor')
        else:
            ok('drawing', msg)


def check_screenshot_type():
    """
    What the text INSIDE every screenshot reads at, once placed.

    A screenshot is scaled by PowerPoint like any picture, so the size its
    words end up at is (CSS px of app text x capture scale) / (image px
    per placed inch) x 72. Nothing about the deck's own type checks tells
    you this, which is how a job-aid form shipped at 9pt - and, uncropped,
    rendered 1.8in wide on a 5.6in box and could not be read at all.
    """
    try:
        from PIL import Image
    except ImportError:
        fail('shots', 'Pillow is not available, so screenshots went unchecked')
        return
    import io
    import hashlib
    import glob
    SMALLEST_CSS_PX, SCALE = 12.5, 2      # the app's 0.78rem, captured at 2x
    # Every picture in the package is named "shotN", drawings included, so
    # the drawings are excluded by their content: they are SVG captures
    # with no app text in them and their own check above.
    drawings = {hashlib.md5(open(f, 'rb').read()).hexdigest()
                for f in glob.glob(os.path.join(ROOT, 'storage', 'shots', 'diagram_*.png'))}
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        worst = None
        for name in sorted(z.namelist()):
            if not re.match(r'ppt/slides/slide\d+\.xml$', name):
                continue
            n = int(re.search(r'slide(\d+)', name).group(1))
            xml = z.read(name).decode('utf-8')
            rels = z.read('ppt/slides/_rels/slide%d.xml.rels' % n).decode('utf-8')
            rmap = dict(re.findall(r'Id="(rId\d+)"[^>]*Target="\.\./media/([^"]+)"', rels))
            # Only <p:pic name="shotN"> - the app captures. Drawings and
            # section art are not screenshots and have no app text in them.
            for mm in re.finditer(
                    r'<p:pic>.*?name="(shot\d+)".*?r:embed="(rId\d+)".*?'
                    r'<a:ext cx="(\d+)" cy="(\d+)"/>', xml, re.S):
                tgt = rmap.get(mm.group(2))
                if not tgt:
                    continue
                raw = z.read('ppt/media/' + tgt)
                if hashlib.md5(raw).hexdigest() in drawings:
                    continue
                im = Image.open(io.BytesIO(raw))
                inches = int(mm.group(3)) / 914400.0
                pt = SMALLEST_CSS_PX * SCALE / (im.size[0] / inches) * 72
                if worst is None or pt < worst[0]:
                    worst = (pt, n, im.size, inches)
        if worst is None:
            continue
        pt, n, size, inches = worst
        msg = ('%s: smallest screenshot text is %.1fpt (slide %d, %dpx shown %.2fin wide)'
               % (deck, pt, n, size[0], inches))
        if pt < 12.0:
            fail('shots', msg + ' - under the 12pt floor')
        else:
            ok('shots', msg)


def check_titles():
    """
    Titles are 48pt. One that will not fit on a line steps down, which is
    correct behaviour but reads as an inconsistency across the deck - so
    the gate names them and the words get cut instead.
    """
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        small = []
        total = 0
        for name in z.namelist():
            if not re.match(r'ppt/slides/slide\d+\.xml$', name):
                continue
            n = int(re.search(r'slide(\d+)', name).group(1))
            xml = z.read(name).decode('utf-8')
            m = re.search(r'<a:off x="457200" y="60000"/>.*?sz="(\d+)".*?<a:t>(.*?)</a:t>',
                          xml, re.S)
            if not m:
                continue
            total += 1
            if int(m.group(1)) < 4800:
                small.append((n, int(m.group(1)) // 100, m.group(2)))
        if not total:
            continue
        if small:
            for n, sz, t in sorted(small)[:10]:
                fail('titles', '%s slide %d: %dpt, %d chars - "%s"'
                     % (deck, n, sz, len(t), t))
        else:
            ok('titles', '%s: all %d titles take 48pt' % (deck, total))


def check_fill():
    """
    Slides that had room and did not take it.

    Every other size check here is a FLOOR - nothing under 12pt. None of
    them ever asked the opposite question, so each time the geometry
    changed the fit ladder legitimately stepped down and nothing noticed.
    That is how a slide ends up at 13.5pt in a box a third empty while
    the gate reports clean.

    Also flags any body still carrying an autofit shrink: a run that says
    16pt and renders at 13.9pt is the same defect wearing a disguise.
    """
    sys.path.insert(0, HERE)
    import deck_overlap as D
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            continue
        z = zipfile.ZipFile(path)
        thin, shrunk, total = [], [], 0
        names = [n for n in z.namelist() if re.match(r'ppt/slides/slide\d+\.xml$', n)]
        for name in sorted(names, key=lambda s: int(re.search(r'(\d+)', s.split('/')[-1]).group(1))):
            no = int(re.search(r'slide(\d+)', name).group(1))
            best = None
            for sp in D.read_shapes(z.read(name)):
                if not sp.paras or not sp.has_text:
                    continue
                if not any(p[2] for p in sp.paras):      # bulleted bodies only
                    continue
                box = sp.h / D.EMU
                if box < 1.5:
                    continue
                if sp.scale < 0.999:
                    shrunk.append((no, round(sp.scale, 2)))
                need = D.text_height(sp)
                size = max(p[1] for p in sp.paras) * sp.scale / 100.0
                if best is None or need / box > best[0]:
                    best = (need / box, size, sp, box)
            if best:
                total += 1
                # "Had room" means the NEXT size up would actually fit -
                # not merely that the box looks empty. Line count is a
                # step function: a row that fits on one line at 13pt takes
                # two at 14, so a 71%-full box can be genuinely maxed out.
                if best[1] < 19.5:
                    sp, box = best[2], best[3]
                    nxt = round(best[1]) + 1
                    grown = D.text_height_at(sp, nxt)
                    if grown <= box * 0.98:
                        thin.append((no, round(best[1], 1), round(100 * best[0]), nxt))
        if not total:
            continue
        for no, sz, pct, nxt in thin[:8]:
            fail('fill', '%s slide %d: %.1fpt in a box %d%% full - %dpt would fit'
                 % (deck, no, sz, pct, nxt))
        for no, sc in shrunk[:5]:
            fail('fill', '%s slide %d: body carries a %.0f%% autofit shrink'
                 % (deck, no, sc * 100))
        if not thin and not shrunk:
            ok('fill', '%s: all %d bodies fill their box or sit at the 20pt cap'
               % (deck, total))


def run(check, argv, expect_zero=True):
    r = subprocess.run([PY] + argv, cwd=ROOT, capture_output=True, text=True,
                       encoding='utf-8', errors='replace')
    out = (r.stdout or '') + (r.stderr or '')
    if r.returncode != 0 and expect_zero:
        fail(check, 'reported defects:')
        for line in out.splitlines():
            if line.strip():
                print('        ' + line)
    else:
        ok(check, out.strip().splitlines()[-1] if out.strip() else 'clean')
    return out


def main():
    print('== gate ==')
    check_freshness()
    check_openable()
    check_logo()
    check_drawing_type()
    check_screenshot_type()
    check_titles()
    check_fill()
    run('lint', ['tools/deck_lint.py'])
    run('overlap', ['tools/deck_overlap.py'] + DECKS)
    # Type size is reported, not gated: some slides genuinely hold more
    # than 16pt of content, and the floor that matters is 12pt.
    size = run('typesize', ['tools/deck_typesize.py', '12'], expect_zero=False)
    for line in size.splitlines():
        if 'renders at' in line:
            fail('typesize', line.strip() + ' - under the 12pt floor')

    print()
    if FAILURES:
        print('%d check(s) failed. The decks are NOT ready.' % len(FAILURES))
        return 1
    print('Both decks pass every check.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
