#!/usr/bin/env python3
"""Build "PRIOps - How the system works" on the Ameren deck's master.

    py -X utf8 tools/make_howto_deck.py

The design is not invented here. It is lifted, measured, from
"09222026 -Ameren UAV Scope and Protocol.pptx": the navy header band with
its dark green edge, the green-capped cards, the pale blue callout, the
PRI footer, and the watermark that comes with the DEFAULT layout. The
template is copied rather than imitated, so the theme, fonts and master
travel with the file.

Screens come from tools/capture_howto.py via storage/shots/howto, each one
already cropped to the picture area and carrying the width it may be
placed at - so nothing is scaled down into illegibility (deck rule B1).
"""

import json
import math
import os
import shutil
import sys

from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.util import Emu, Inches, Pt

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SHOTS = os.path.join(ROOT, 'storage', 'shots', 'howto')
# The PowerPoint template whose colours and layouts the deck reuses. It used to
# sit on one computer's Desktop; it now lives in the git-ignored reference
# folder, or wherever PRI_DECK_TEMPLATE names.
TEMPLATE = os.environ.get('PRI_DECK_TEMPLATE') or os.path.join(ROOT, 'reference', 'deck-template.pptx')
OUT = os.path.join(ROOT, 'PRIOps-How-It-Works.pptx')

# Sampled from the template, not guessed.
NAVY = RGBColor(0x1E, 0x3A, 0x5F)
EDGE = RGBColor(0x00, 0x33, 0x00)
GREEN = RGBColor(0x03, 0x53, 0x2B)
PALE = RGBColor(0xD8, 0xE8, 0xF5)
BORDER = RGBColor(0x90, 0xB8, 0xD8)
INK = RGBColor(0x13, 0x29, 0x4A)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
ORANGE = RGBColor(0xE8, 0x4A, 0x27)

HEAD_FONT = 'Source Sans Pro'
BODY_FONT = 'Calibri'

BAND_H = 1.40
FOOT_T = 7.10
AREA_T = 1.62          # where the working area starts
AREA_B = 6.92          # and ends, when there is no callout band


def box(slide, x, y, w, h, fill=None, line=None, width_pt=1.0):
    sh = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y),
                                Inches(w), Inches(h))
    if fill is None:
        sh.fill.background()
    else:
        sh.fill.solid()
        sh.fill.fore_color.rgb = fill
    if line is None:
        sh.line.fill.background()
    else:
        sh.line.color.rgb = line
        sh.line.width = Pt(width_pt)
    sh.shadow.inherit = False
    return sh


def text(slide, x, y, w, h, runs, size=18, font=BODY_FONT, color=INK,
         bold=False, italic=False, align=PP_ALIGN.LEFT, space=6,
         anchor=MSO_ANCHOR.TOP, bullet=False):
    """runs: a string, or a list of (string, bold) pairs, or a list of lines."""
    tb = slide.shapes.add_textbox(Inches(x), Inches(y), Inches(w), Inches(h))
    tf = tb.text_frame
    tf.word_wrap = True
    tf.vertical_anchor = anchor
    tf.margin_left = tf.margin_right = tf.margin_top = tf.margin_bottom = 0
    lines = runs if isinstance(runs, list) else [runs]
    for i, line in enumerate(lines):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = align
        p.space_after = Pt(space)
        parts = line if isinstance(line, list) else [(line, bold)]
        if bullet:
            pPr = p._p.get_or_add_pPr()
            pPr.set('marL', '237744')
            pPr.set('indent', '-237744')
        for txt, b in parts:
            r = p.add_run()
            r.text = ('\u2022  ' + txt) if (bullet and parts.index((txt, b)) == 0) else txt
            r.font.size = Pt(size)
            r.font.name = font
            r.font.bold = b
            r.font.italic = italic
            r.font.color.rgb = color
    return tb


def header(slide, title, kicker):
    box(slide, 0, 0, 13.33, BAND_H, NAVY)
    box(slide, 0, 0, 0.24, BAND_H, EDGE)
    text(slide, 0.37, 0.12, 12.5, 0.80, title, size=34, font=HEAD_FONT,
         color=WHITE, bold=True, anchor=MSO_ANCHOR.MIDDLE)
    if kicker:
        text(slide, 0.38, 0.93, 12.5, 0.42, kicker, size=18, font=BODY_FONT,
             color=WHITE, italic=True)


def footer(slide):
    box(slide, 0, FOOT_T, 13.33, 0.40, RGBColor(0xF2, 0xF6, 0xF8))
    box(slide, 0.40, FOOT_T + 0.06, 0.19, 0.28, ORANGE)
    text(slide, 0.90, FOOT_T + 0.03, 0.8, 0.34, 'PRI', size=16,
         font=HEAD_FONT, color=INK, bold=True)
    text(slide, 1.75, FOOT_T + 0.03, 8.0, 0.34,
         'P R A I R I E   R E S E A R C H   I N S T I T U T E', size=12,
         font=BODY_FONT, color=INK)


def card(slide, x, y, w, h, label, bullets, size=18):
    box(slide, x, y, w, h, WHITE, BORDER)
    box(slide, x, y, w, 0.42, GREEN)
    text(slide, x + 0.13, y + 0.04, w - 0.26, 0.36, label, size=size,
         font=HEAD_FONT, color=WHITE, bold=True)
    if bullets:
        text(slide, x + 0.13, y + 0.52, w - 0.26, h - 0.62, bullets,
             size=size, bullet=True, space=7)


def note(slide, x, y, w, h, label, body):
    """The template's pale callout, kept to the text column.

    Run full width it sat under the screenshot and the card both - a
    collision, and the picture lost its bottom inch to it."""
    box(slide, x, y, w, h, PALE)
    text(slide, x + 0.16, y + 0.12, w - 0.32, h - 0.24,
         [[(label + '  ', True), (body, False)]], size=16)


def picture(slide, key, man, x=None, y=AREA_T, frame=True):
    """Place a shot at exactly the width it was cropped for."""
    m = man[key]
    w, h = m['in']
    if x is None:
        x = 13.33 - 0.47 - w
    if frame:
        box(slide, x - 0.07, y - 0.07, w + 0.14, h + 0.14, WHITE, BORDER)
    slide.shapes.add_picture(os.path.join(SHOTS, key + '.png'),
                             Inches(x), Inches(y), Inches(w), Inches(h))
    return x, y, w, h


def new_slide(prs):
    layout = None
    for l in prs.slide_masters[0].slide_layouts:
        if l.name == 'DEFAULT':
            layout = l
            break
    s = prs.slides.add_slide(layout)
    for ph in list(s.placeholders):
        ph._element.getparent().remove(ph._element)
    return s


def strip(prs):
    lst = prs.slides._sldIdLst
    for sid in list(lst):
        prs.part.drop_rel(sid.rId)
        lst.remove(sid)


def build():
    man = json.load(open(os.path.join(SHOTS, 'manifest.json')))
    shutil.copyfile(TEMPLATE, OUT)
    prs = Presentation(OUT)
    strip(prs)
    n = 0

    # 1 - title
    s = new_slide(prs)
    box(s, 0, 0, 13.33, 7.5, NAVY)
    box(s, 0, 0, 0.30, 7.5, EDGE)
    # The lockup as the app draws it: the lanternfly (genus Pyrops, which
    # is the joke) beside the wordmark, white on the navy.
    mark = os.path.join(ROOT, 'facilities', 'assets', 'priops.png')
    if os.path.isfile(mark):
        s.shapes.add_picture(mark, Inches(1.1), Inches(1.95),
                             Inches(1.10 * 1.571), Inches(1.10))
        text(s, 3.05, 1.86, 9.0, 1.3, 'PRIOps', size=72, font=HEAD_FONT,
             color=WHITE, bold=True)
    else:
        text(s, 1.1, 1.9, 11.0, 1.6, 'PRIOps', size=72, font=HEAD_FONT,
             color=WHITE, bold=True)
    text(s, 1.1, 3.3, 11.0, 0.9, 'How the system works', size=34,
         font=BODY_FONT, color=RGBColor(0xC6, 0xD6, 0xE8))
    box(s, 1.1, 4.35, 3.2, 0.06, ORANGE)
    text(s, 1.1, 4.7, 11.0, 1.2,
         ['Filing a request, and what the system does with it',
          'Prairie Research Institute, University of Illinois Urbana-Champaign'],
         size=18, color=RGBColor(0xC6, 0xD6, 0xE8))
    n += 1

    # 2 - what it is
    s = new_slide(prs)
    header(s, 'One place for the work of the Institute',
           'Ten registers behind one front door, instead of separate unlinked systems')
    card(s, 0.47, AREA_T, 6.05, 2.45, 'What it holds', [
        'Work, shop and move requests',
        'Keys, space and the fleet',
        'Purchasing, property and safety'])
    card(s, 0.47, AREA_T + 2.60, 6.05, 2.45, 'What it gives back', [
        'One request number, from filing to close',
        'The rules applied the same way each time',
        'A record that can be reported on'])
    card(s, 6.80, AREA_T, 6.05, 5.05, 'Why it exists', [
        'The same request used to live in several places at once',
        'Nothing could be counted across the whole Institute',
        'Reference data is published into every register, so each one '
        'answers its own questions'])
    footer(s)
    n += 1

    # 3-15: the walkthrough, then how it is built
    plan = [
        ('home', 'Start by saying what you need',
         'The front page is a chooser, and nothing stands in front of it',
         'What this is', ['Every kind of request begins here',
                          'Choose the area, then the request',
                          'Anything waiting on you is a slim line underneath'],
         'On purpose:', 'most people arrive to file something, so that is what the page is for.'),
        ('work_form', 'Filing a work request',
         'Section 1 asks only where the problem is and what kind it is',
         'Where and what', ['The building, then the work type',
                            'One line describing the problem',
                            'Floor and room narrow down once a building is chosen'],
         'Why so short:', 'the form asks what it needs to route the request, and defers the rest.'),
        ('dd_building', 'Choosing the building',
         'Eighty-seven buildings, drawn from the estate register',
         'The list is the data', ['Buildings come from the space register, not a typed list',
                                  'Aliases are matched, so older names still find the place',
                                  'Choosing one loads that building\u2019s floors and rooms'],
         'Kept true:', 'the list cannot drift from the estate, because it is the estate.'),
        ('dd_type', 'Choosing the work type',
         'Nineteen types, each routed to the people who do that work',
         'What the type does', ['Sends the request to the right shop',
                                'Sets which questions come next',
                                'Decides who is asked to approve it'],
         'Not cosmetic:', 'the type is what turns a description into a routed piece of work.'),
        ('dd_key', 'Keys are their own register',
         'What kind of key, and why it is needed',
         'Asked here', ['Whether this is an extra, a replacement or a return',
                        'The kind of key: iCard, padlock, cabinet, door',
                        'The holder\u2019s status, which sets who approves'],
         'Audited separately:', 'key custody is a programme in its own right, so it keeps its own record.'),
        ('dd_purch', 'Purchasing asks how it will be bought',
         'The purchase type decides the route and the paperwork',
         'Seven routes', ['Standing and regular purchase orders',
                          'I-Buy, T-Card and payment requests',
                          'Each one carries its own approvals'],
         'Codes and money:', 'Banner codes classify the spend; CFOP accounts pay for it.'),
        ('dd_incident', 'Safety incidents',
         'Injuries, near misses, spills and exposures',
         'Recorded once', ['Which Survey the people belong to',
                           'Who was involved and what treatment followed',
                           'What was done, and what came of it'],
         'Why it matters:', 'a near miss recorded today is the injury that does not happen next year.'),
        ('mywork', 'Following your request',
         'My work lists what is waiting on you and what you have filed',
         'Two questions', ['What needs a decision from me?',
                           'Where have my own requests got to?'],
         'No chasing:', 'the queue comes to the person, rather than the person hunting for it.'),
        ('stages', 'How a request moves',
         'Each kind of request has its own stages, written down',
         'The same every time', ['Stages are defined per request type',
                                 'Who may move it is part of the definition',
                                 'Every move is stamped and kept'],
         'Published:', 'anyone can read how their request will travel before they file it.'),
        ('studio_db', 'Ten registers, one application',
         'Each programme keeps its own database, and says so',
         'Why ten', ['Audited on their own terms',
                     'Reference data in every one',
                     'No join crosses a boundary'],
         'Read live:', 'every register is tried as the page loads.'),
        ('admin', 'Administration',
         'People, access and the reference data everything shares',
         'Held here', ['Who may see and do what, by function',
                       'Buildings, rooms, people and pick lists',
                       'The settings every rule reads from'],
         'One source:', 'every weight, threshold and limit is a setting, never a number in the code.'),
        ('studio', 'The Studio',
         'The application\u2019s own source, edited from inside it',
         'For very few people', ['A separate grant, not given to administrators',
                                 'Saves are parsed before they are written',
                                 'Every save is backed up and logged'],
         'Deliberately narrow:', 'a file that would not run is refused, and nothing on disk changes.'),
    ]

    for key, title, kicker, label, bullets, cl, cb in plan:
        s = new_slide(prs)
        header(s, title, kicker)
        x, y, w, h = picture(s, key, man)
        col_w = x - 0.30 - 0.47
        # The card takes only the height its bullets need and the note
        # sits under it, both inside the text column - clear of the
        # picture, which used to lose its bottom inch to a full-width band.
        # How many lines the bullets really take in THIS column. A fixed
        # guess of 44 characters clipped the last bullet wherever the
        # picture was wide and the column narrow.
        cpl = max(12, int((col_w - 0.40) * 10.4))
        lines = sum(max(1, math.ceil(len(b) / cpl)) for b in bullets)
        card_h = 0.52 + 0.34 * lines + 0.22
        note_h = 0.30 + 0.28 * max(1, math.ceil(len(cl + cb) / max(12, cpl)))
        # Card and note together must fit beside the picture.
        room = h - 0.22
        if card_h + note_h > room:
            card_h = max(1.2, room - note_h)
        card(s, 0.47, AREA_T, col_w, card_h, label, bullets, size=18)
        note_y = AREA_T + card_h + 0.22
        note(s, 0.47, note_y, col_w,
             min(note_h, AREA_T + h - note_y), cl, cb)
        footer(s)
        n += 1

    # last - running it
    s = new_slide(prs)
    header(s, 'Running it', 'What a computer needs, and what travels with the folder')
    card(s, 0.47, AREA_T, 6.05, 5.05, 'Travels in the folder', [
        'The application itself',
        'PHP, bundled in tools\\php',
        'The SQL Server driver, ready to register'])
    card(s, 6.80, AREA_T, 6.05, 5.05, 'Installed once per computer', [
        'The Microsoft ODBC driver, registered with Windows',
        'SQL Server, holding the ten registers',
        'Nothing else - no server, no site, no account'])
    footer(s)
    n += 1

    prs.save(OUT)
    print('Wrote %s / %d slides' % (os.path.basename(OUT), n))
    return n


if __name__ == '__main__':
    build()
