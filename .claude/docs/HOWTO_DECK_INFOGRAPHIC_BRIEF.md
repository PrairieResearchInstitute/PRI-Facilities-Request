# Infographics wanted for "PRIOps — How the system works"

A brief for Gemini (or anyone drawing these). The deck is built and
readable as it stands; these would replace bullet cards with pictures on
five slides where a diagram says it better than a list.

## House style — match these exactly

Taken from the deck the slides are modelled on
(`09222026 -Ameren UAV Scope and Protocol.pptx`).

| Element | Value |
|---|---|
| Canvas | 13.33 × 7.50 in (16:9), 300 dpi if raster |
| Navy (headers, dark fills) | `#1E3A5F` |
| Dark green (labels, caps) | `#03532B` |
| Edge accent | `#003300` |
| Pale blue (notes, fills) | `#D8E8F5` |
| Border / rule | `#90B8D8` |
| Body ink | `#13294A` |
| Illinois orange (sparingly) | `#E84A27` |
| Heading font | Source Sans Pro, bold |
| Body font | Calibri |
| Smallest type | **16 pt at final placed size — nothing smaller** |

Backgrounds transparent or white. No drop shadows, no gradients, no
3-D. Flat fills, thin rules, generous white space. Every connector in a
diagram carries a label — an unlabelled arrow is not finished.

**Deliver as SVG** (preferred) or PNG at 300 dpi with transparency.
One file per figure, named as below.

---

## 1. `fig-ten-registers.svg` — the ten registers and what joins them

The centrepiece. Ten rounded boxes, one per register:

Work · Move · Shop · Purchasing · Keys · Fleet · Space · Equipment ·
Incidents · Plans

Joins are **hub and spoke, never a mesh**: a register that carries a
name joins the register that *owns* that name. Keys and Incidents both
carry a room code, so each joins **Space** — they do not join each
other. Draw one lane per shared name, orthogonal only (lanes
horizontal, stubs vertical, nothing crossing a box), a filled dot on
the register that owns the name, and a colour legend naming each shared
key: `RoomCode`, `BldgNumber`, `NetID`, `FleetNumber`, `PropertyTag`,
`TicketNumber`, `CfopCode`.

State plainly somewhere in the figure: **no foreign key crosses a
database boundary**, and reference data (buildings, rooms, people, pick
lists) is *published into* every register.

Placed at about 8 in wide beside a narrow text column.

## 2. `fig-request-journey.svg` — what happens after Submit

A left-to-right ribbon, five or six stops, each labelled:

filed → routed by type → approved → assigned to a shop → worked →
closed

Under each stop, one line of who acts there. Show two branch-offs, both
labelled: **"I am seeing this too"** joining an existing request instead
of creating a duplicate, and **returned for more detail** looping back
to the filer. Mark where the request number is issued (at filing, and it
never changes).

Placed full width, about 12 in × 3 in.

## 3. `fig-one-form-many-routes.svg` — how one front door becomes ten registers

A funnel or fan: a single "What do you need?" entry at the left, fanning
into the request kinds (work, shop, move, space, keys, purchasing,
property, incident, safety plan), each arrow labelled with the register
it lands in. The point being made: the person chooses what they need,
not which database they are writing to.

Placed about 7 in wide.

## 4. `fig-access-layers.svg` — who can do what

Four concentric bands or stacked tiers, widest at the bottom:

1. **Anyone at the Institute** — file a request, see their own
2. **Approvers** — decide on requests in their area
3. **Administrators** — people, access, reference data, settings
4. **Studio** — edit the application itself; *a separate grant, not
   given automatically to administrators*

Label the top tier clearly as the smallest group. Keep it plain — no
padlock clip art.

## 5. `fig-what-travels.svg` — the folder versus the computer

Two columns, honest about the split:

**Travels in the folder:** the application, PHP (bundled), the SQL
Server driver files, the launcher.

**Installed once per computer:** the Microsoft ODBC driver (registers
itself with Windows), SQL Server holding the ten registers.

A single line beneath: *nothing else — no web host, no site, no
account.*

---

## Facts to keep straight

These are wrong in most first drafts:

- Ten registers, not six. Eighty-seven buildings. Nineteen work types.
- Banner codes **classify** spending; CFOP accounts **pay** for it.
  They are not the same thing and not interchangeable.
- What it replaced is **separate unlinked systems** — not "emailing
  PDFs", not "spreadsheets".
- The lanternfly in the logo is the genus *Pyrops* — that is the joke in
  PRIOps. Do not redraw it as a generic bug, and do not add a plate or a
  white margin behind it.
- Reference data is *published into* each register, not shared by
  reference across them.
