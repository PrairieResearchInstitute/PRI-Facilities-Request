"""Read the built decks back and report text defects, slide by slide.

This exists because the generator can produce grammatical nonsense that
no build check notices: a sentence broken by a bad edit, a doubled word,
a bullet that trails off, punctuation left behind. Run it after every
build and fix what it names.

    py -X utf8 tools/deck_lint.py
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

# Phrasing that asks the reader to decode a metaphor instead of reading a
# fact. Every one of these shipped and had to be rewritten; the pattern is
# always the same - a clever line standing where a plain one belongs.
RIDDLES = [
    (r'water is off', 'say "once the emergency has been dealt with"'),
    (r'the register is away', 'say who cannot answer what'),
    (r'the door opens', 'say "launch"'),
    (r'stops being archaeology', 'say what no longer has to be reconstructed'),
    (r'writes itself', 'say what fills it in, and from where'),
    (r'carr(y|ies) names, not identities', 'say which field, and what is not on it'),
    (r'a feeling in a meeting', 'say what the number is'),
    (r'is away(?!.*on leave)', 'say who is unavailable and for what'),
]

# Words that legitimately repeat, and shorthand that is not a typo.
OK_REPEATS = {'had', 'that', 'the'}


def slide_texts(path):
    """
    [(slide number, [(paragraph, is_prose), ...]), ...] in order.

    is_prose marks a bulleted body paragraph - running English that must
    read as a sentence. A table cell or a device label is a fragment on
    purpose ("a dated line, never an edit"), so the prose rules do not
    apply to it and flagging it buries the real defects.
    """
    z = zipfile.ZipFile(path)
    pres = z.read('ppt/presentation.xml').decode('utf-8')
    rids = re.findall(r'<p:sldId id="\d+" r:id="(rId\d+)"/>', pres)
    rels = z.read('ppt/_rels/presentation.xml.rels').decode('utf-8')
    rmap = dict(re.findall(r'Id="(rId\d+)"[^>]*Target="slides/(slide\d+\.xml)"', rels))
    out = []
    for n, rid in enumerate(rids, 1):
        xml = z.read('ppt/slides/' + rmap[rid]).decode('utf-8')
        paras = []
        # Which text box a paragraph sits in: the same words in two
        # different boxes are usually two rows of a form, not a repeat.
        bounds = [(m.start(), m.end()) for m in
                  re.finditer(r'<p:txBody>.*?</p:txBody>', xml, re.S)]

        def box_of(pos):
            for k, (a, b) in enumerate(bounds):
                if a <= pos < b:
                    return k
            return -1

        for m in re.finditer(r'<a:p>.*?</a:p>', xml, re.S):
            p = m.group(0)
            box = box_of(m.start())
            runs = re.findall(r'<a:t>(.*?)</a:t>', p, re.S)
            text = ''.join(runs)
            text = (text.replace('&amp;', '&').replace('&lt;', '<')
                        .replace('&gt;', '>').replace('&apos;', "'")
                        .replace('&quot;', '"'))
            if text.strip():
                paras.append((text, '<a:buChar' in p, box))
        out.append((n, paras))
    return out


def defects(text, is_prose):
    """Every defect this paragraph carries, named."""
    found = []
    stripped = text.strip()

    # Worklist rows rule their columns with " · " and " → "; that
    # wider spacing is deliberate, not a typo.
    tabular = (' · ' in text or ' → ' in text or ' — ' in text)
    if not tabular and '  ' in text.strip():
        found.append('double space')
    for bad in (' ,', ' ;', ' .', '..', ',,', ',.', '.,'):
        if bad in stripped.replace('...', '…'):
            found.append(f'stray punctuation "{bad.strip() or bad}"')
    if re.search(r'\b(\w+)\s+\1\b', stripped, re.I):
        w = re.search(r'\b(\w+)\s+\1\b', stripped, re.I).group(1).lower()
        if w not in OK_REPEATS:
            found.append(f'doubled word "{w}"')
    # A sentence cut by a bad edit ends on a joining word AND carries no
    # terminal punctuation. Headings and short labels do neither.
    if (len(stripped.split()) >= 8
            and not stripped.endswith(('.', '!', '?', ':', '"', ')', '%'))
            and re.search(r'\b(and|or|the|a|an|of|to|with|both|its|their)$',
                          stripped, re.I)):
        found.append('sentence appears cut off')
    if stripped.count('"') % 2:
        found.append('unbalanced quotation mark')
    if stripped.count('(') != stripped.count(')'):
        found.append('unbalanced bracket')
    # A line may open lowercase when the first word is a name the system
    # actually uses - an event key, a table, a file path. Those carry
    # _ . or / and are quoted verbatim; anything else is a broken sentence.
    first = stripped.split(' ', 1)[0] if stripped else ''
    identifier = bool(re.search(r'[_./]', first))
    if is_prose and re.match(r'^[a-z]', stripped) and not identifier:
        found.append('starts lowercase')
    if text.startswith('h:') or text.startswith('s:'):
        found.append('literal section marker leaked into the slide')
    if re.search(r'\bTODO\b|\bTBD\b|\bXXX\b|lorem', stripped, re.I):
        found.append('placeholder text')
    for pat, fix in RIDDLES:
        if re.search(pat, stripped, re.I):
            found.append(f'riddling phrase - {fix}')
    return found


def main():
    total = 0
    for deck in DECKS:
        path = os.path.join(ROOT, deck)
        if not os.path.isfile(path):
            print(f'-- {deck}: not built')
            continue
        print(f'== {deck}')
        hits = 0
        for n, paras in slide_texts(path):
            seen = {}
            for p, is_prose, box in paras:
                for d in defects(p, is_prose):
                    print(f'   slide {n:>3}  {d}')
                    print(f'            "{p[:96]}"')
                    hits += 1
                key = (box, p.strip().lower())
                # Two table rows may legitimately hold the same value;
                # two lines repeating inside one box is a defect.
                if len(key[1]) > 25 and key in seen:
                    print(f'   slide {n:>3}  duplicated line')
                    print(f'            "{p[:96]}"')
                    hits += 1
                seen[key] = True
        print(f'   {hits} defect(s)')
        total += hits
    print(f'\n{total} defect(s) in total')
    return 1 if total else 0


if __name__ == '__main__':
    sys.exit(main())
