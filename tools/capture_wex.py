"""Capture the job-aid form shots: each intake form rendered mid-fill
with the faux data from facilities/lib/demofill.php.

The fixture keys and pages are read straight out of demofill.php so this
script can never drift from the deck.

    py -X utf8 tools/capture_wex.py
"""
import os
import re
import shutil
import subprocess
import urllib.request

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
RAW = os.path.join(ROOT, 'storage', 'shots', 'raw')
SHOTS = os.path.join(ROOT, 'storage', 'shots')

# The slide's job-aid box, in inches. The capture is cropped to this
# shape, and the shape is wide, because the arithmetic that matters is
# CSS pixels per placed inch: a form photographed 560px wide and shown
# 7.5in wide puts its smallest label at 12pt. Shown 5.6in wide it is
# 9.8pt, and shown UNCROPPED - a 620x1720 scroll in a 1.14 box - it
# renders 1.8in wide and cannot be read at all. That is what shipped.
BOX_W, BOX_H = 7.8, 4.85

# What the page is photographed at. Narrower than a desktop because the
# fewer CSS pixels across, the larger everything lands on the slide.
CAP_W, CAP_H = 560, 1400

# Where the page chrome ends and the form begins, per page. The work
# form carries an extra emergency notice above its first section.
NAV_DEFAULT = 20
SCROLLBAR = 32

# The app's smallest body text is 0.78rem, and every capture is drawn at
# 2x. Both numbers are needed to know what a screenshot reads at.
SMALLEST_CSS_PX = 12.5
SCALE = 2


def page_fault(url):
    """The page's own HTML, checked before it is ever photographed.

    A capture taken while the database was down is a PHP stack trace,
    and one shipped into a deck. Reading the markup is deterministic
    where looking at pixels is not.
    """
    try:
        with urllib.request.urlopen(url, timeout=20) as r:
            html = r.read().decode('utf-8', 'replace')
    except Exception as e:
        return f'could not be fetched ({e})'
    for bad in ('Fatal error', 'Uncaught', 'Warning:', 'Notice:', 'PDOException'):
        if bad in html:
            return f'the page returned "{bad}"'
    if '<form' not in html and 'demo=' in url:
        return 'the page carried no form'
    return ''


def crop_to_box(src, dst, page):
    """Cut the box's shape out of the top of the capture, and prove it."""
    im = Image.open(src).convert('RGB')
    w, h = im.size
    right = w - SCROLLBAR
    top = NAV_DEFAULT
    want = int(right * (BOX_H / BOX_W))
    if top + want > h:
        raise SystemExit(f'{dst}: capture is only {h}px tall, needs {top + want}')
    # Cut where there is no ink, not where the pixels happen to match.
    # A form page is a white card on tinted paper, so no row is ever
    # "uniform" - but a row with nothing darker than mid-grey in it has
    # no text crossing it, which is the thing that actually matters.
    px = im.load()
    bottom = top + want
    for y in range(top + want, top + int(want * 0.55), -1):
        if min(min(px[x, y][:3]) for x in range(8, right - 8, 3)) > 150:
            bottom = y
            break
    out = im.crop((0, top, right, bottom))
    out.save(dst)
    # The check that was missing: a shot is only worth showing if its
    # smallest text lands at or above the deck's 12pt floor.
    px_per_in = out.size[0] / BOX_W
    pt = SMALLEST_CSS_PX * SCALE / px_per_in * 72
    if pt < 12.0:
        raise SystemExit(f'{dst}: smallest text would place at {pt:.1f}pt')
    return pt
TMP = os.path.join(ROOT, 'reference', 'Visual Pack', 'regen', 'tmp')
os.makedirs(TMP, exist_ok=True)

EDGE = os.path.join(os.environ.get("ProgramFiles(x86)", ""), r"Microsoft\Edge\Application\msedge.exe")
if not os.path.isfile(EDGE):
    EDGE = os.path.join(os.environ.get("ProgramFiles", ""), r"Microsoft\Edge\Application\msedge.exe")

BASE = 'http://localhost:8080/'

src = open(os.path.join(ROOT, 'facilities', 'lib', 'demofill.php'),
           encoding='utf-8').read()
# Split on the top-level fixture keys, then read page/shot from each
# block in whatever order they were written.
starts = [(m.start(), m.group(1))
          for m in re.finditer(r"^    '([a-z_0-9]+)' => \[$", src, re.M)]
blocks = []
for i, (pos, key) in enumerate(starts):
    end = starts[i + 1][0] if i + 1 < len(starts) else len(src)
    chunk = src[pos:end]
    page = re.search(r"'page'\s*=>\s*'([^']+)'", chunk)
    shot = re.search(r"'shot'\s*=>\s*'([^']+)'", chunk)
    if page and shot:
        blocks.append((key, page.group(1), shot.group(1)))
if not blocks:
    raise SystemExit('no fixtures found in demofill.php')

done = failed = 0
for key, page, shot in blocks:
    out = os.path.join(RAW, shot + '.png')
    url = f'{BASE}{page}?demo={key}'
    ok = False
    for attempt in range(2):
        if os.path.isfile(out):
            os.remove(out)
        profile = os.path.join(TMP, f'wex-{key}-{attempt}')
        # Drawn at 2x, then cropped to the slide's box. Only the top of
        # the form is shown: photographing the whole scrolling page is
        # what forced it to be shrunk into an unreadable strip.
        p = subprocess.Popen([EDGE, '--headless=new', '--disable-gpu',
                              '--no-first-run', f'--user-data-dir={profile}',
                              f'--window-size={CAP_W},{CAP_H}',
                              '--force-device-scale-factor=2',
                              '--virtual-time-budget=8000',
                              f'--screenshot={out}', url],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            p.wait(timeout=60)
        except subprocess.TimeoutExpired:
            subprocess.run(['taskkill', '/F', '/T', '/PID', str(p.pid)],
                           capture_output=True)
        shutil.rmtree(profile, ignore_errors=True)
        if os.path.isfile(out):
            ok = True
            break
    fault = page_fault(url)
    if ok and fault:
        ok = False
        print('REFUSED', shot, '-', fault)
    if ok:
        # Written straight into storage/shots: these are finished images,
        # not pages to be banded by process_shots.
        pt = crop_to_box(out, os.path.join(SHOTS, shot + '.png'), page)
        done += 1
        print('captured %-22s smallest text %.1fpt' % (shot, pt))
    else:
        failed += 1
        print('FAILED', shot)
print(f'{done} captured, {failed} failed')
