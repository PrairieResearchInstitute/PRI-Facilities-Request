"""Redraw facilities/assets/favicon.ico from favicon.svg's geometry.

No SVG renderer is installed, and the icon is four shapes on a 64 unit
grid, so Pillow draws them at 4x and downsamples each frame. The colours
and coordinates are read from the SVG rather than typed again, so the two
files cannot drift: if the SVG changes shape this script stops instead of
drawing the old picture.
"""
import os
import re
import sys

APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
from PIL import Image, ImageDraw

SRC = os.path.join(APP, "facilities", "assets", "favicon.svg")
OUT = os.path.join(APP, "facilities", "assets", "favicon.ico")
SIZES = [16, 32, 48, 256]
SS = 4  # supersample

svg = open(SRC, encoding="utf-8").read()

rects = re.findall(
    r'<rect([^>]*)/>', svg)
def attrs(s):
    return dict(re.findall(r'(\w[\w-]*)="([^"]*)"', s))

r = [attrs(x) for x in rects]
if len(r) != 3:
    sys.exit("expected 3 rects in the SVG, found %d" % len(r))
ground, house, door = r[0], r[1], r[2]

m = re.search(r'<path d="M(\d+) (\d+) L(\d+) (\d+) L(\d+) (\d+)"[^>]*stroke="([^"]+)"[^>]*stroke-width="(\d+)"', svg, re.S)
if not m:
    sys.exit("the roof path is not the shape this script knows")
x1, y1, x2, y2, x3, y3 = (int(m.group(i)) for i in range(1, 7))
roof_colour, roof_w = m.group(7), int(m.group(8))

def px(v, n):
    return float(v) * n * SS / 64.0

for n in SIZES:
    S = n * SS
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([0, 0, S - 1, S - 1], radius=px(ground.get("rx", 10), n),
                        fill=ground["fill"])
    w = max(1, int(px(roof_w, n)))
    d.line([px(x1, n), px(y1, n), px(x2, n), px(y2, n), px(x3, n), px(y3, n)],
           fill=roof_colour, width=w, joint="curve")
    for cap in ((x1, y1), (x3, y3)):
        cx, cy = px(cap[0], n), px(cap[1], n)
        d.ellipse([cx - w / 2, cy - w / 2, cx + w / 2, cy + w / 2], fill=roof_colour)
    d.rounded_rectangle(
        [px(house["x"], n), px(house["y"], n),
         px(float(house["x"]) + float(house["width"]), n) - 1,
         px(float(house["y"]) + float(house["height"]), n) - 1],
        radius=px(house.get("rx", 2), n), fill=house["fill"])
    d.rectangle(
        [px(door["x"], n), px(door["y"], n),
         px(float(door["x"]) + float(door["width"]), n) - 1,
         px(float(door["y"]) + float(door["height"]), n) - 1],
        fill=door["fill"])
    img = img.resize((n, n), Image.LANCZOS)
    globals().setdefault("frames", []).append(img)

frames = globals()["frames"]
frames[-1].save(OUT, format="ICO", sizes=[(f.width, f.height) for f in frames],
                append_images=frames[:-1])
print("wrote %s with frames %s, roof %s" %
      (OUT, [f.width for f in frames], roof_colour))
