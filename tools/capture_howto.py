#!/usr/bin/env python3
"""Screens for the "How PRIOps works" deck, including open dropdowns.

A native select draws its list outside the page, so a plain screenshot can
never show the choices. The app's capture mode takes ?open=<names> and
renders those selects as list boxes - the real control, holding the real
options from the database.

    py -X utf8 tools/capture_howto.py

Legibility is arithmetic, not opinion. The app's smallest body text is
0.78rem = 12.5 CSS px. Placed on a slide, it renders at

    pt = 12.5 * (placed_inches / capture_px) * 72

so every shot declares the width it will be placed at, and the script
prints what its smallest text will actually read. Anything under 12pt is
refused rather than shipped (deck rule B1).
"""

import os
import re
import shutil
import subprocess
import sys
import urllib.request

from PIL import Image

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = os.path.join(ROOT, 'storage', 'shots', 'howto')
TMP = os.environ.get('TEMP', os.path.join(ROOT, 'storage', 'tmp'))

BASE = 'http://127.0.0.1:8080'
SCALE = 2
SMALLEST_CSS_PX = 12.5
FLOOR_PT = 12.0

EDGE = os.path.join(os.environ.get("ProgramFiles(x86)", ""), r"Microsoft\Edge\Application\msedge.exe")
if not os.path.isfile(EDGE):
    EDGE = os.path.join(os.environ.get("ProgramFiles", ""), r"Microsoft\Edge\Application\msedge.exe")

# key, url, capture width, capture height, inches it is placed at
SHOTS = [
    ('home',        '/index.php',                                          820, 1150, 11.6),
    ('work_form',   '/new_work.php?demo=work_order',                       640, 1500,  9.2),
    ('dd_building', '/new_work.php?demo=work_order&open=building_id&rows=8', 640, 1400, 9.2),
    ('dd_type',     '/new_work.php?demo=work_order&open=type_id&rows=9',   640, 1400,  9.2),
    ('dd_nature',   '/new_work.php?demo=work_order&open=nature_id,severity&rows=7', 640, 1400, 9.2),
    ('mywork',      '/digest.php',                                         820, 1250, 11.6),
    ('stages',      '/stages.php',                                         820, 1250, 11.6),
    ('admin',       '/admin.php',                                          820, 1250, 11.6),
    ('studio',      '/studio.php',                                         900, 1150, 11.6),
    ('studio_db',   '/studio.php?view=database',                           900, 1250, 11.6),
]


def page_fault(url):
    """Refuse a page that errored or came back empty (deck rule C8)."""
    try:
        with urllib.request.urlopen(url, timeout=25) as r:
            body = r.read().decode('utf-8', 'replace')
    except Exception as e:
        return 'unreachable: %s' % e
    for bad in ('Fatal error', 'Parse error', 'Uncaught', '<b>Warning</b>',
                'The database is not answering', 'needs restarting',
                'missing the SQL Server driver'):
        if bad in body:
            return 'page shows "%s"' % bad
    if len(body) < 800:
        return 'page is only %d bytes' % len(body)
    return None


def shoot(key, path, w, h):
    out = os.path.join(OUT, key + '.png')
    if os.path.isfile(out):
        os.remove(out)
    profile = os.path.join(TMP, 'howto-' + key)
    # Edge is started through PowerShell. Launched straight from this
    # process under Git Bash it returns 0 and writes nothing at all -
    # no error, no file - so the shell that starts it is not incidental.
    args = ["'--headless=new'", "'--disable-gpu'", "'--no-first-run'",
            "'--user-data-dir=%s'" % profile,
            "'--window-size=%d,%d'" % (w, h),
            "'--force-device-scale-factor=%d'" % SCALE,
            "'--virtual-time-budget=9000'",
            "'--screenshot=%s'" % out,
            "'%s'" % (BASE + path)]
    ps = ("$p = Start-Process -FilePath '%s' -PassThru -NoNewWindow "
          "-ArgumentList @(%s); $p | Wait-Process -Timeout 90"
          % (EDGE, ','.join(args)))
    subprocess.run(['powershell', '-NoProfile', '-Command', ps],
                   capture_output=True, timeout=120)
    shutil.rmtree(profile, ignore_errors=True)
    return out if os.path.isfile(out) else None


def trim(path):
    """Drop the scrollbar column and any dead space under the content.

    The cut is made at a row with no ink at all, never at a fixed
    fraction - slicing through a sentence is what shipped once before.
    """
    im = Image.open(path).convert('RGB')
    w, h = im.size
    im = im.crop((0, 0, max(1, w - 34), h))
    w, h = im.size
    g = im.convert('L')
    last = 0
    for y in range(h - 1, -1, -1):
        row = g.crop((0, y, w, y + 1)).getdata()
        if min(row) < 150:
            last = y
            break
    bottom = min(h, last + 24)
    im = im.crop((0, 0, w, bottom))
    im.save(path)
    return im.size


def main():
    os.makedirs(OUT, exist_ok=True)
    bad = 0
    print('capturing %d screens from %s' % (len(SHOTS), BASE))
    for key, path, w, h, placed in SHOTS:
        fault = page_fault(BASE + path)
        if fault:
            print('  REFUSED %-12s %s' % (key, fault))
            bad += 1
            continue
        f = shoot(key, path, w, h)
        if not f:
            print('  FAILED  %-12s no image written' % key)
            bad += 1
            continue
        px = trim(f)
        pt = SMALLEST_CSS_PX * (placed / w) * 72
        flag = 'ok ' if pt >= FLOOR_PT else 'TOO SMALL'
        if pt < FLOOR_PT:
            bad += 1
        print('  %-9s %-12s %sx%s px  placed %.1fin  smallest text %.1fpt  %s'
              % (flag, key, px[0], px[1], placed, pt, ''))
    print('\n%s' % ('all shots captured and legible'
                    if bad == 0 else '%d problem(s)' % bad))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
