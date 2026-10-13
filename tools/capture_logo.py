"""Capture the PRIOps lockup for the deck's title slide.

Shot at exactly the banner's size and then trimmed to the ink, so the
title slide carries the mark itself with no pale margin around it.

    py -X utf8 tools/capture_logo.py
"""
import os
import shutil
import subprocess

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SHOTS = os.path.join(ROOT, 'storage', 'shots')
TMP = os.path.join(ROOT, 'reference', 'Visual Pack', 'regen', 'tmp')
os.makedirs(TMP, exist_ok=True)

EDGE = os.path.join(os.environ.get("ProgramFiles(x86)", ""), r"Microsoft\Edge\Application\msedge.exe")
if not os.path.isfile(EDGE):
    EDGE = os.path.join(os.environ.get("ProgramFiles", ""), r"Microsoft\Edge\Application\msedge.exe")

raw = os.path.join(TMP, 'logo_raw.png')
out = os.path.join(SHOTS, 'diagram_logo.png')
if os.path.isfile(raw):
    os.remove(raw)

profile = os.path.join(TMP, 'cap-logo')
p = subprocess.Popen([EDGE, '--headless=new', '--disable-gpu', '--no-first-run',
                      f'--user-data-dir={profile}', '--window-size=1200,360',
                      '--force-device-scale-factor=2', '--hide-scrollbars',
                      '--default-background-color=00000000',
                      '--virtual-time-budget=6000',
                      f'--screenshot={raw}', 'http://localhost:8080/logo.php?bare=1'],
                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    p.wait(timeout=60)
except subprocess.TimeoutExpired:
    subprocess.run(['taskkill', '/F', '/T', '/PID', str(p.pid)], capture_output=True)
shutil.rmtree(profile, ignore_errors=True)

if not os.path.isfile(raw):
    raise SystemExit('FAILED to capture the lockup')

def make_blank(transparent):
    """
    What counts as "not the mark".

    On a transparent capture that is alpha and only alpha - the lockup's
    lettering and the lanternfly are WHITE, so treating pale pixels as
    background eats the mark from the outside in. On an opaque capture
    there is no alpha to go on, so pale is the only signal there is.
    """
    if transparent:
        return lambda p: p[3] < 16
    return lambda p: p[3] < 16 or (p[0] > 200 and p[1] > 200 and p[2] > 200)


def trim(im, blank):
    """
    Crop every blank edge away. Every pixel of an edge is tested, not
    every fifth: a sampled scan can step straight over a ten-pixel band
    and leave a white L down the side of a navy slide, which is exactly
    what shipped last time.
    """
    w, h = im.size
    px = im.load()
    left = next((x for x in range(w)
                 if not all(blank(px[x, y]) for y in range(h))), 0)
    right = next((x for x in range(w - 1, -1, -1)
                  if not all(blank(px[x, y]) for y in range(h))), w - 1)
    top = next((y for y in range(h)
                if not all(blank(px[x, y]) for x in range(w))), 0)
    bottom = next((y for y in range(h - 1, -1, -1)
                   if not all(blank(px[x, y]) for x in range(w))), h - 1)
    return im.crop((left, top, right + 1, bottom + 1))


im = Image.open(raw).convert('RGBA')
was = im.size
transparent = im.getextrema()[3][0] < 16
blank = make_blank(transparent)
trim(im, blank).save(out)

# Prove it: re-open what was written and refuse to claim success while a
# blank edge survives.
chk = Image.open(out).convert('RGBA')
w, h = chk.size
p = chk.load()
bad = [side for side, ok in (
    ('left', not all(blank(p[0, y]) for y in range(h))),
    ('right', not all(blank(p[w - 1, y]) for y in range(h))),
    ('top', not all(blank(p[x, 0]) for x in range(w))),
    ('bottom', not all(blank(p[x, h - 1]) for x in range(w)))) if not ok]
if bad:
    raise SystemExit('FAILED: blank border survives on ' + ', '.join(bad))
opaque = sum(1 for y in range(0, h, 4) for x in range(0, w, 4) if p[x, y][3] > 200)
print('lockup captured', (w, h), '(was', was, ')',
      '- %d%% of it is ink' % (100 * opaque / max(1, (w // 4 + 1) * (h // 4 + 1))))
