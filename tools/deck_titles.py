#!/usr/bin/env python3
"""Slide number and title, straight out of the built file."""
import re
import sys
import zipfile
from xml.etree import ElementTree as ET

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

A = '{http://schemas.openxmlformats.org/drawingml/2006/main}'
P = '{http://schemas.openxmlformats.org/presentationml/2006/main}'


def title_of(xml):
    root = ET.fromstring(xml)
    best = ''
    for sp in root.iter(P + 'sp'):
        tx = sp.find(P + 'txBody')
        if tx is None:
            continue
        for p in tx.findall(A + 'p'):
            for r in p.findall(A + 'r'):
                rPr = r.find(A + 'rPr')
                t = r.find(A + 't')
                if rPr is None or t is None or not t.text:
                    continue
                if int(rPr.get('sz') or 0) >= 2400:
                    return t.text
                if not best:
                    best = t.text
    return best


def main():
    path = sys.argv[1]
    want = None
    if len(sys.argv) > 2:
        want = set(int(v) for v in sys.argv[2].replace(',', ' ').split())
    z = zipfile.ZipFile(path)
    names = [n for n in z.namelist() if re.match(r'ppt/slides/slide\d+\.xml$', n)]
    names.sort(key=lambda n: int(re.search(r'(\d+)', n.split('/')[-1]).group(1)))
    for n in names:
        i = int(re.search(r'slide(\d+)', n).group(1))
        if want and i not in want:
            continue
        print('%4d  %s' % (i, title_of(z.read(n))))


main()
