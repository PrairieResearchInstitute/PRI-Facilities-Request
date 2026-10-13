#!/usr/bin/env python3
"""
Prove the application renders with no internet connection.

It has to: PRI shared hosting installs nothing, staff test on machines
that are not always on the campus network, and a page that quietly waits
on a font or a script from somewhere else is a page that hangs when the
link is down.

This reads the source, not a running server, so it catches a regression
the moment it is written rather than the next time somebody happens to
test offline.

    py -3 tools/offline_check.py

What it refuses:

  remote asset   a src= or href= pointing at another host, an @import
                 or url() to one, or any named CDN
  remote fetch   fetch()/XHR to an absolute URL rather than a relative
                 path under the app
  web font       @font-face or a font served from anywhere but the disk
  render call    the server reaching out mid-request - file_get_contents
                 on a URL, curl, fsockopen

Deliberately allowed, with a reason each:

  smtp.php       sending mail is not rendering a page; if the relay is
                 unreachable the outbox holds the message
  gis_etl / api  loading data FROM campus exports is a separate errand,
                 run when there is a network, never during a render
  qr payloads    the URL printed into a sticker is data, not a fetch
"""

import os
import re
import sys

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
APP = os.path.join(ROOT, 'facilities')

# Files that are allowed to touch the network, and why.
ALLOWED = {
    'lib/smtp.php':      'sending mail is not a page render',
    'lib/mailer.php':    'sending mail is not a page render',
    'qrtest.php':        'the URL is printed into a QR sticker, not fetched',
    'api/ingest.php':    'campus data load, run deliberately and not during a render',
}

RULES = [
    (r'(?:src|href)\s*=\s*["\'](?:https?:)?//', 'asset loaded from another host'),
    (r'@import[^;]*(?:https?:)?//',             'stylesheet imported from another host'),
    (r'url\(\s*["\']?(?:https?:)?//',           'CSS url() pointing at another host'),
    (r'@font-face',                             'web font - the deck and app use system fonts'),
    (r'fonts\.googleapis|fonts\.gstatic|jsdelivr|unpkg|cdnjs|bootstrapcdn|ajax\.googleapis|code\.jquery',
                                                'a CDN'),
    (r'(?:fetch|\.open)\s*\(\s*["\'`](?:https?:)?//', 'fetch/XHR to an absolute URL'),
    (r'file_get_contents\s*\(\s*["\']https?://', 'the server fetching a URL while rendering'),
    (r'\bcurl_init\b|\bfsockopen\b',            'the server opening a socket while rendering'),
]

EXTS = ('.php', '.css', '.js', '.html', '.htm')


def main():
    findings = []
    scanned = 0
    for base, dirs, files in os.walk(APP):
        dirs[:] = [d for d in dirs if d not in ('.git', 'vendor', 'node_modules')]
        for f in sorted(files):
            if not f.endswith(EXTS):
                continue
            path = os.path.join(base, f)
            rel = os.path.relpath(path, APP).replace('\\', '/')
            scanned += 1
            try:
                text = open(path, encoding='utf-8', errors='replace').read()
            except OSError as e:
                findings.append((rel, 0, 'unreadable: %s' % e))
                continue
            if rel in ALLOWED:
                continue
            for pat, why in RULES:
                for m in re.finditer(pat, text, re.I):
                    line = text.count('\n', 0, m.start()) + 1
                    snippet = text[m.start():m.start() + 70].split('\n')[0]
                    findings.append((rel, line, '%s - %s' % (why, snippet.strip())))

    print('offline check: %d source files under facilities/' % scanned)
    for rel, why in sorted(ALLOWED.items()):
        if os.path.isfile(os.path.join(APP, rel)):
            print('  allowed  %-18s %s' % (rel, why))
    if not findings:
        print('  ok       nothing is loaded from anywhere but this folder')
        return 0
    for rel, line, msg in findings[:40]:
        print('  FAIL     %s:%d  %s' % (rel, line, msg))
    print('\n%d reference(s) would need a network to render.' % len(findings))
    return 1


if __name__ == '__main__':
    sys.exit(main())
