# UI Elements: PRI Facilities Request

The UI Elements spec for PRI Facilities Request (the screens read PRIOps; the product name is an open decision, section 9). Read it before adding or changing anything on screen. It builds on the estate baseline, the skill drez-ui-elements: the baseline sets the rules, and this file sets the values. Owner decisions Q-25 to Q-28 (2026-10-17) apply. Source audit: `reference\UI Audit 2026-10-17.docx` (findings PFR-01 to PFR-36). The owner reads the rendered copy, `reference\UI Elements.docx`.

## 1. Identity

| Item | Value |
|---|---|
| Palette family | Illinois: navy `#13294B` primary, Illinois Orange `#FF5F05` rule and highlight (Q-26), warm paper ground and warm grey lines shared with PaCELab Equip |
| Fonts | Georgia body (`body`); Arial 800 for the PRIOps wordmark only; Cascadia Mono stack for codes and paste blocks. No web font loads. Open decision: one font pairing (section 9). |
| Themes shipped | one light theme; print styles; `body.sheet` (dense office forms) and `body.phone` (phone forms) are layouts, not themes |
| Token sheet | `facilities/assets/app.css`, the `:root` block; served locally, no CDN |
| Chrome | `ui_top($title, $sub, $bodyClass)` and `ui_bottom()` in `facilities/lib/ui.php` |
| Scripts | `facilities/assets/*.js` (location, map, move, odometer, property, purchase, shop, studio, typeahead, work); plain JavaScript |
| Icons | no icon set; approved text glyphs (section 3, Icons); the lanternfly mark `assets/priops.png`; favicon `assets/favicon.svg` and `assets/favicon.ico` (a house) |
| Office documents | decks and Word files follow `.claude/docs/DECK_RULES_AND_FAILURES.md` and drez-inhs-document-branding, not this file |

### Shared PRI web look

Identical in the specs of PaCELab Equip, PRI Facilities Request and PRI District Tracker; change all three together.

The three PRI web apps share one look where they already agree. PaCELab named its tokens after Facilities on purpose, so those two share eight token names; District Tracker reaches the same navy and orange under other names.

| Role | Shared value | PaCELab Equip | PRI Facilities Request | PRI District Tracker |
|---|---|---|---|---|
| Primary | `#13294B` navy | `--accent` | `--accent` | `--blue` (public), `--navy` (Data Console) |
| Highlight | `#FF5F05` Illinois Orange (Q-26) | `--accent2` | `--accent2`, today `#e84a27` | `--orange` |
| White-text orange, orange text on light grounds | `#C84113` (`--il-altgeld`) | new `--il-altgeld` | new `--il-altgeld` | `--il-altgeld` in the vendored toolkit |
| Body text | `#1a1a1a` | `--ink` | `--ink` | `--ink` (public pages) |
| Page ground | `#f5f4f1` warm paper | `--paper` | `--paper` | differs: toolkit white on public pages, `#f4f6f8` in the back office |
| Panel | `#ffffff` | `--panel` | `--panel` | white literals |
| Line | `#d8d5cf` warm grey | `--line` | `--line` | differs: `#e3e3e2` public, `#e3e6ea` back office |
| Success | `#1b6b2f` | `--good` | `--good` | differs: `#14532d` on `#e8f5ec` |
| Danger | `#a4210c` | `--bad` | `--bad` | differs: `#7f1d1d` on `#fdecea` |
| Focus ring | 2 px solid navy, 2 px offset; `#FF5F05` on navy bars | `--focus` | `--focus` | `--focus` |

Orange rule (Q-26). Illinois Orange `#FF5F05` replaces the retired Altgeld `#E84A27` everywhere, favicons included. White text never sits on `#FF5F05` (about 3.0 to 1). A fill that carries white text uses `#C84113` (`--il-altgeld`, about 4.97 to 1 with white); a fill that stays `#FF5F05` carries navy `#13294B` text (about 4.7 to 1). Orange text on white or paper uses `#C84113`. Pure `#FF5F05` stays for rules, borders, underlines, dots and fills with navy text. Navy `#13294B` stays the primary colour.

Header and footer pattern. Each app opens with navy or the Illinois toolkit header, one orange rule as the only accent line, then white panels on a light ground. PaCELab: toolkit `<ilw-header>` with the lab bar below it and the toolkit `<ilw-footer>`. District Tracker: vendored toolkit `<ilw-header>` and the PRI `<ilw-footer>` on public pages; a navy bar with a 6 px orange top rule and no footer in the back office. Facilities: its own navy `header.site` with a 4 px orange bottom rule, the PRIOps lockup and a right-aligned text footer `footer.foot`.

Dialog helper. All three apps use one plain JavaScript helper with one API, `PriDialog.ask`, `PriDialog.confirm` and `PriDialog.notify`, plus the server-rendered confirm page for PHP forms (Q-27). Each app's section 4 names its file.

Open shared decisions, each listed in every app's `reference\open-questions.md`:

- Fonts. PaCELab mixes Georgia, a system sans and the Illinois kit; Facilities uses Georgia with no web font; District Tracker uses Source Sans 3 and Montserrat, vendored offline. Recommendation: Source Sans 3 for body and controls and Montserrat for headings in all three, vendored offline by the District Tracker method (drez-illinois-brand-offline; drez-inhs-document-branding rule 3 names Source Sans 3 for screens), with Georgia retired from screens.
- Header. PaCELab and District Tracker use the toolkit `<ilw-header>`; Facilities draws its own navy bar. Recommendation: the toolkit header in all three, vendored offline for Facilities (which loads nothing from a CDN), with the PRIOps lockup in the site-name slot.
- Neutral greys. PaCELab and Facilities use the warm paper set (`#f5f4f1`, `#d8d5cf`); District Tracker uses cool greys. Recommendation: the warm set for app screens and back offices; the toolkit neutrals stay on District Tracker's public pages.

## 2. Tokens

All tokens sit in `:root` in `facilities/assets/app.css`. The colour, focus, elevation and layer tokens below are in the block as of 17 October 2026; the rows still marked "new" are values the stylesheet uses as literals and the deviation named in the note is still open. `lib/fail.php` keeps an inline copy of the same values for the case where the stylesheet cannot load (PFR-12).

### Colour

| Token | Value | Baseline role | Notes |
|---|---|---|---|
| `--paper` | `#f5f4f1` | `--bg` | page ground |
| `--panel` | `#ffffff` | `--surface` | panels, inputs, active tab |
| `--subtle` | `#fbfaf8` | `--surface-2` | item rows, stat tiles, stage boxes (PFR-24) |
| `--fill` | `#eceae5` | `--surface-2` (fill) | table heads, inactive tabs, readonly inputs, count chips |
| `--hover` | `#f2efe8` | `--surface-2` (hover) | row and nav hover; replaces `#f2efe9`, `#f4f4f6`, `#eef1f5` |
| `--warm` | `#fff5ea` | `--warn-bg` (guidance) | `.worknote`, current stage; replaces `#fff3e0`, `#fdf6e3` |
| `--line` | `#d8d5cf` | `--line` | every border and rule |
| `--ink` | `#1a1a1a` | `--text` | body text |
| `--muted` | `#666` | `--text-muted` | hints, meta; replaces `#6a6a6a`, `#555`, `#4a4a4a`, `#8a8a8a`, `#6b7280` (PFR-10) |
| `--accent` | `#13294b` | `--accent` | header, primary buttons, links, card titles |
| `--navy` | `var(--accent)` | alias | names used by three pages without a definition (PFR-10) |
| `--shade` | `var(--hover)` | alias | `.snippet`, `details.area` hover (PFR-10) |
| `--on-accent` | `#ffffff` | `--on-accent` | text on navy and on `--il-altgeld` |
| `--accent2` | `#FF5F05` | highlight | Q-26; header rule, active tab top rule, card hover rule, `.worknote` border |
| `--il-altgeld` | `#C84113` | highlight, text-safe | orange text on light grounds (`a.card .go`), white-text orange fills, Studio warnings (replaces `#c7461f`) |
| `--good` | `#1b6b2f` | `--ok` | `.panel.ok` rule, `.good` text |
| `--bad` | `#a4210c` | `--danger` | `.panel.err` rule, `.bad` text, `button.danger` |
| `--warn-bg` | `#fdece7` | `--warn-bg` | Studio warnings; 6.80 to 1 with `--warn-text` (PFR-11) |
| `--warn-text` | `#8a3a12` | `--warn-text` | the text on `--warn-bg`, and 7.24 to 1 on `--warm` |
| `--band-critical` | `#a4210c` | priority | `.band-critical` |
| `--band-high` | `#c25400` | priority | `.band-high` |
| `--band-elevated` | `#8a6d00` | priority | `.band-elevated` |
| `--band-routine` | `#4a5a6a` | priority | `.band-routine` |
| `--band-low` | `#4f6f5a` | priority | `.band-low`, the audit's canonical for the class pages already emit (PFR-04) |
| `--brand-ops` (new) | `#8FA9C4` | brand | "Ops" in the header wordmark; open decision (Q-014) |

`--warm` is the guidance ground (a work note, the current stage); `--warn-bg` with `--warn-text` is the warning pair, which the Studio had as literals before it had a name. No info colour exists, and a failure still takes `--bad`.

### Type

| Token | Value | Baseline role | Use |
|---|---|---|---|
| `--font` | "Source Sans 3", "Source Sans Pro", "Source Sans", Arial, sans-serif | body face | `body`, controls. The campus face, carried in `assets/fonts` (Q-017) |
| `--brand-font` | Arial, "Segoe UI", sans-serif, weight 800 | wordmark | `.brandword` only. The one exception to Q-017, made by the owner on 17 Oct 2026: Montserrat is carried at 600, so the lockup rendered as a faked bold and read thin beside the lanternfly |
| `--font-heading` | "Montserrat", "Source Sans 3", Arial, sans-serif | heading face | `h1`, `h2`, `h3`, `.statn`, carried at 600 (Q-017) |
| `--mono` | "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace | code face | `.recblock`, `#recall`, Studio editor, phone big field (PFR-30) |
| `--fs-xs` (new) | .75rem | `--fs-xs` | stage notes, count chips, captions |
| `--fs-sm` (new) | .85rem | `--fs-sm` | header sub line, `.muted`, footer, filters |
| `--fs-md` (new) | .9rem | `--fs-md` | tables, labels, `.worknote` |
| body | 1rem (browser default) | base | body text |
| `--fs-lg` (new) | 1.15rem | `--fs-lg` | h2 |
| `--fs-xl` (new) | 1.3rem | `--fs-xl` | h1 in the header |

h3 is 1rem bold. The wordmark (1.7rem) and `.statn` figures (1.5rem) sit outside the scale. Weights: `bold` for headings, labels in tables and links that act; 800 for the wordmark. Line height 1.45.

### Shape, space, elevation, layers, motion

| Token | Value | Baseline role | Use |
|---|---|---|---|
| `--radius-sm` (new) | 4px | `--radius-sm` | inputs, buttons, `.snippet` |
| `--radius-md` (new) | 6px | `--radius-md` | panels, cards, tabs (top corners), fieldsets |
| `--radius-pill` (new) | 10px | `--radius-pill` | `.band`, `.navcount`, `.soon`, Studio pills (PFR-25) |
| `--space-1` to `--space-5` (new) | .25, .5, .75, 1, 1.25rem | `--space-1` to `--space-5` | padding, gaps, margins |
| `--shadow-1` | 0 2px 8px rgba(19,41,75,.25) | `--shadow-1` | card hover lift, type-ahead list |
| `--z-menu` (new) | 40 | `--z-menu` | `ul.typeahead` |
| `--z-dialog` | 50 | `--z-dialog` | `PriDialog` backdrop (new) |
| `--dur-fast` (new) | .12s | `--dur-fast` | `details.area` marker turn |

### Active state and focus

- Active-state class: `.active`, as `.tabs a.active` and `.navgroup a.active` use today. Filter toggles and any new tab, segment or chip take `.active`, never `.on` (PFR-03); a pressed toggle also carries `aria-pressed="true"`. Stage state on `ol.flowchart li` uses `.done`, `.now` and `.terminal`.
- Focus ring: `--focus: 2px solid var(--accent)` with `outline-offset: 2px` on `:focus-visible` for every button, link, input, select, tab, summary and floor-plan room (shared PRI look). Inside `header.site` the ring uses `--accent2`. Today the rule is `outline: 3px solid var(--accent2)` on `:focus`, and the Studio editor and floor plan remove it (PFR-18, PFR-38).

## 3. Components

One component per family. Copy the markup; change the text, never the classes.

### Buttons

Base: `button` and `.btn`, 4px radius, `.5rem 1.1rem` padding. Variants:

| Variant | Markup | Look | Use |
|---|---|---|---|
| Primary | `button.primary`, `a.btn` | navy fill, white text | the one main action per form |
| Secondary | unclassed `<button>`, `a.button` | white, navy text and border; navy fill on hover | other actions, links that act |
| Secondary (alias) | `.btn.secondary` (to add) | as secondary | the safety pages already emit it (PFR-03) |
| Danger | `button.danger` | red text and border | every delete, reverse, decline, cancel or loss (PFR-09) |
| In-table | `form.inline button` | .2rem .5rem, .8rem | actions inside a cell |
| Row remove | `.rowdel` (`&times;`, `title="Remove"`) | red glyph | unsaved form rows only; stored rows use `button.danger` "Remove" (PFR-08) |
| Text button | `button.link` (to add) | link look | inline "Remove" in lists |
| Big | `body.phone button.big` | full width | phone forms |

States: hover (`filter: brightness(1.15)` on filled buttons; outlined buttons take the `a.button` fill, PFR-29), `:focus-visible` ring, active, `:disabled` at opacity .5 with `cursor: not-allowed` (to add).

```html
<div class="actions">
  <a class="button" href="index.php">Cancel</a>
  <button type="submit" class="primary">Close This Request</button>
</div>
```

### Inputs and selects

`input`, `select`, `textarea`: block, full width, `.45rem` padding, `--line` border, 4px radius, white; readonly fields take `--fill`. The `label` wraps its field. Type-ahead: add `data-typeahead` to the input; `ui_bottom()` loads `assets/typeahead.js` once on every page (PFR-22). Empty first option reads "Choose a building", "Choose a building first", "Choose a vehicle" (PFR-21).

### Checkboxes and radios

```html
<label class="check"><input type="checkbox" name="urgent" value="1"> Urgent</label>
```

Radio groups sit in a `fieldset` with `legend`; `div.radios` (to add) lays them in a row.

### Forms, labels and validation

Fields in `.grid` (two columns from 40rem); a full-width field adds `.span-all` (to add, replaces `style="grid-column:1/-1"`, PFR-23). Group with `fieldset` and `legend`. Help text in `p.help` or `details.hint` on sheets. Validation errors and refusals: `.panel.err` above the form through `flash_set('err', $msg)` and `flash_show()`; `.panel.error` becomes an alias of `.panel.err` (PFR-02). Flagged rows: `tr.warn`.

### Dialogs and confirm pages

See section 4.

### Menus

`nav.mainnav` in the header: Home, My Work, Admin (administrators), Studio (holders of the Studio grant). Administration has its own side menu, `details.navgroup` with `a.active` and `.navcount` chips.

### Tabs and filters

`.tabs a` with `a.active` (white, bold, 3px orange top rule) for views and for filter toggles such as All, Still Open, Everything, Due or Overdue (PFR-03). Form filters: `.filters` (flex row of labelled fields and one button).

```html
<nav class="tabs"><a class="active" href="board.php?view=open">Open</a><a href="board.php?view=all">All</a></nav>
```

### Tables

Bare `<table>` inside `.panel.scroll-x`; `--fill` header; `tr.rowlink` for rows that open a record, with a real `<a>` in the first cell (PFR-37); `tr.warn` for flagged rows; `td.num` (to add) for counts, replacing `td.center` (PFR-07). "Ticket" names the identifier column.

### Panels and cards

`.panel` (white, 6px, `--line`, `1rem 1.25rem`); `.panel.ok` and `.panel.err` (5px left rule); `.panel.card-warn` for a stale data source; `.worknote` for guidance, with `.panel.note` as its alias (PFR-06); `a.card` front-page cards (5px navy top rule, orange on hover, `.go` link text in `--il-altgeld`); `.card-soon` with `.soon` for features in preparation.

### Stat tiles

`.statrow` of `a.stat` with `.statn` (figure) and `.statl` (label); each tile links to its list. `.panel.stats` and `span.big` become aliases (PFR-05).

### Page header and footer

Every page calls `ui_top($title, $sub, $bodyClass)` and `ui_bottom()`, including `logout.php`, `install.php` and `report_audit.php` (PFR-13). Header: navy `header.site`, 4px orange bottom rule, lanternfly lockup (`ui_brand_mark()`), the page `<h1>` (the lockup becomes the heading on the front page), a plain-text sub line, a back link through a new parameter (PFR-01), `nav.mainnav`, then the NetID line or the acting-user switcher (`.devswitch`). Footer: `footer.foot` with Administration, How Requests Move, the app name and the credit `span.credit` "Developed by M. J. Dreslik, with Claude Opus 5", separated by `&middot;`, all in the footer's `--fs-sm` text. Admin, Settings repeats the credit in a `.muted` line. The gate fails when the footer line or the NOTICE file is missing (drez-ui-elements rule 27, D-025). Header bar, `main` and footer share one width (PFR-28).

### Navigation

Back links: `&larr;` plus the destination, as a real link in the header (PFR-01). Record pages follow one pattern: status line, key and value table, `ol.flowchart` "Where It Stands" with `li.done`, `li.now`, `li.terminal`, then the history table (PFR-32).

### Flash messages and alerts

`flash_set($kind, $msg)` with `ok` or `err`, shown by `flash_show()` as `.panel.ok` or `.panel.err`. Async messages in scripts: `.bad` for failures, `.muted` for empty results (PFR-34). No toast and no banner exist.

### Status badges and pills

Priority: `.band` with `.band-critical`, `.band-high`, `.band-elevated`, `.band-routine`, `.band-low`; text through `nice_code()` (PFR-31). Workflow state (approved, ordered, received, closed) is text through `nice_code()`; a separate workflow pill set, when built, gets its own tokens here first (PFR-04). Counts: `.navcount`. Coming soon: `.soon`.

### Tooltips

No tooltip component. Explanations sit in `p.help`, `details.hint` or `.navhint` text; `title` is allowed only on the `.rowdel` glyph.

### Empty, loading and error pages

Empty list: one `p.muted` sentence with a period. Pages render on the server; scripts that load data show `.muted` while waiting. Not found and not yours: `ui_top('Not Found')` and `ui_top('Not Yours to Read')` with one `.panel.err` paragraph (PFR-20). Database down: `lib/fail.php` links `assets/app.css` and uses the same tokens, the lockup and `.btn`, keeping its inline fallback (PFR-12).

### Icons

No icon set and no emoji. Approved glyphs: `&larr;` back, `&rarr;` go, `&times;` remove, `&middot;` separator, `&#9679;` map legend dot, CSS `\25B8` disclosure. Brand: lanternfly PNG beside the PRI and Ops wordmark. Favicon: house in navy and orange; its orange moves from `#e84a27` to `#FF5F05` (Q-26); a lanternfly favicon is an open decision (Q-015).

### Maps

`lib/mapview.php` floor plans: inline SVG `#floorplan` with `path.room` (hover and focus change the fill and keep the focus ring, PFR-18), room labels in Arial for legibility, a legend with `&#9679;` dots whose colour comes from the data (the one allowed inline style). Map-only colours (`#0c5c2f`, `#9a958c`, `#6f7683`, `#cfd8e6`, `#fdfdfb`) stay inside the map view.

### Email

Mail is plain text only (`lib/notify.php`, `lib/digest.php`, `lib/smtp.php`); no HTML email exists. Subjects follow one pattern, `<product name>: <Request Type> <ticket> <event>`, with the request type and event in Title Case and the ticket as the system writes it (PFR-35); the product name waits on Q-013. An HTML email, if one is ever added, gets its component here first.

## 4. Dialogs and feedback

In-app dialog helper (Q-27): `facilities/assets/dialog.js` (to build), plain JavaScript, loaded once by `ui_bottom()` beside `typeahead.js`.

```js
PriDialog.ask({ title, message, label, value = '', placeholder = '', okLabel = 'OK', cancelLabel = 'Cancel', required = false })
  // Promise<string|null>: the text, or null on Cancel, Escape or a backdrop click
PriDialog.confirm({ title, message, okLabel = 'OK', cancelLabel = 'Cancel', danger = false })
  // Promise<boolean>
PriDialog.notify({ title, message, okLabel = 'OK' })
  // Promise<void>
```

Shell (new classes, added to `app.css` with tokens only): `.dialog-backdrop > .dialog[role=dialog][aria-modal=true][aria-labelledby]`, a title row with an `h2` and a close button (`aria-label="Close"`, `&times;`), the body, then `.actions` with Cancel (unclassed `button`) on the left and the main action on the right as the only primary button (`button.primary`, or `button.danger` when `danger` is true). White `.panel` look, 6px radius, `--shadow-1`, backdrop `rgba(19,41,75,.55)` (the District Tracker public value, shared look), `--z-dialog`. Escape and a backdrop click cancel; focus moves into the dialog and returns to the trigger.

Forms that change or remove data use `data-confirm` in place of `onsubmit` or `onclick` `confirm()`: `dialog.js` intercepts, asks through `PriDialog.confirm`, adds `confirmed=1` and submits on yes.

```html
<form method="post" data-confirm="Clear the key? Any scheduled task using it will stop working." data-confirm-ok="Clear Key" data-confirm-danger>
```

Server-rendered confirm page, for a request without `confirmed=1`: `ui_confirm_page($title, $question, $okLabel, $fields, $cancelHref, $danger = false)` (to build in `lib/ui.php`) renders `ui_top($title)`, one `.panel` with the question, the posted fields as hidden inputs plus `csrf_field()` and `confirmed=1`, Cancel as `a.button` to `$cancelHref` on the left and the main action on the right, then `ui_bottom()`. The handler calls it before it writes anything.

Order of replacement: `prompt()` first (none in this app), then the two `confirm()` calls (`lib/adminviews4.php` Clear the key, `lib/adminviews5.php` Remove from list), then `alert()` (none). Each keeps its text and result handling.

Feedback places: inline errors and refusals as `.panel.err` above the form; `.panel.ok` after a redirect for completed actions; `.panel.card-warn` for a data source past its interval. No toast or banner.

## 5. Layout

| Item | Value |
|---|---|
| Content width | `main` 60rem; admin (`main:has(.adminnav)`) 78rem; `body.sheet main` 82rem; `body.phone main` 30rem; Studio full width |
| Header bar | today `max-width: 72rem`; moves to the width of `main` (PFR-28) |
| Page padding | `main` 1rem; phone .75rem |
| Header | `.9rem 1.25rem` padding, 4px orange bottom rule; sheet pages `.55rem 1.25rem` |
| Panel | `1rem 1.25rem` padding, 1rem below; sheet panels `.7rem .9rem` |
| Breakpoints | 40rem (two-column `.grid`), 60rem (sheet rows, Studio), 64rem (admin and map side columns); 52rem and 62rem fold into these (PFR-27) |
| Studio | a flex column page in place of `calc(100vh - 8.5rem)` (PFR-36) |
| Print | header, nav and footer hidden; panels lose borders |

## 6. Copy

Casing (Q-25): Title Case for buttons, links that act, tabs, filter toggles, menu items, dialog titles, page titles and h2 and h3 headings, all through `title_case()`: Record It, Close This Request, Add a Line, Report It, Add Another Person, My Work, How Requests Move, Due or Overdue. Small words stay lowercase inside a label ("Add a Line", "Not Yours to Read"), which `title_case()` already does. Sentence case for body text, help text, form field labels (Q-29), messages, placeholders, empty options and confirm questions. Questions do not serve as headings ("1. Which Item?" becomes a statement, PFR-19). Writing rules: no em dashes, no contractions, no filler.

Glossary:

| Thing | Name on screen | Note |
|---|---|---|
| Product | open: "PRIOps" (`app_name`, header, health, logo, front page) against "PRI Facilities Request System" (logout, install, audit report, exports, mail sender, digest) and "PRI Facilities" (favicon label, digest subject) | owner decision (Q-013) |
| Request types | Facilities Work Request, Move Request, Shop Services Work Request, Space Request, Key Request, Purchase Request, Property Update, Vehicle Stickers, Mileage | as the page titles read |
| Safety | Report an Incident, Incidents, Safety Plans | the front-page card "Report an Incident or Near Miss" and the page "Report An Incident" become one name (PFR-20; casing by Q-25) |
| Lists | Open Requests, My Work, How Requests Move | |
| Identifier | Ticket | |
| Status and stage | the code through `nice_code()` (On Hold, With the Fund Approvers) | never the raw code |
| Priority | Critical, High, Elevated, Routine, Low | through `nice_code()` |
| Administration | Administration (footer), Admin (menu) | |
| Studio | Studio | separate grant |

Formats: screen dates `M j, Y` (Oct 17, 2026), date and time `M j, Y g:ia` (Oct 17, 2026 3:04pm), through `ui_date()` and `ui_datetime()` (to build in `lib/ui.php`, PFR-17); `n/j/Y` only where a campus form requires it; storage `Y-m-d` and `Y-m-d H:i:s`. Whether printed safety reports keep the long `j F Y` form is open (Q-016). Money `$` plus `number_format($amount, 2)`. Empty value (Q-30): the em dash, `&mdash;` alone in a table cell or read-only field; prose, labels, messages and help text never use it.

## 7. Adding a feature

1. Read this file; find the component in section 3.
2. If none fits, add the class here first with its tokens and states, then to `app.css`; never emit a class `app.css` does not define (the safety family shows the cost, PFR-02 to PFR-07).
3. Use tokens only; no literal colour, size or radius, no `style=""` except data-driven legend colours.
4. Open the page with `ui_top()` and close it with `ui_bottom()`; pass the subtitle as plain text and the back link through its parameter.
5. Run headings, titles and buttons through `title_case()`, codes through `nice_code()`.
6. Ask through `PriDialog` or `ui_confirm_page()`, never `confirm()`.
7. Format dates with `ui_date()` and `ui_datetime()`.
8. Run the baseline checks (drez-ui-elements, Checks), `node tools/check.js` and `py -3 tools\offline_check.py`.
9. Remove the section 8 row the change fixes; rebuild `reference\UI Elements.docx`.

## 8. Known deviations

| ID | Problem | Canonical target |
|---|---|---|
| PFR-13 | `install.php` builds its own page | nothing to change until the installer can read settings: `ui_top()` needs a database the installer is there to create. `logout.php` carries the lockup without the nav, because the chrome would show the acting-user line on a signed-out page; `report_audit.php` is a printed document with its own print rules |

## 9. Decisions

Estate decisions (2026-10-17):

- Q-25: Title Case for buttons, tabs, menu items, dialog titles and page titles; sentence case for body, help, messages and placeholders. Settles the audit's casing question for this app as Title Case: about 60 sentence-case button labels move to Title Case through `title_case()`, which already holds the small-word rule (PFR-16).
- Q-26: Illinois Orange `#FF5F05` replaces `#E84A27` in `--accent2`, the favicon and the Studio orange; white text never sits on `#FF5F05`; orange text and white-text fills use `#C84113` (PFR-38). Navy stays the primary.
- Q-27: native dialogs give way to `facilities/assets/dialog.js` (`PriDialog`) and `ui_confirm_page()`, `prompt()` first (PFR-39).
- Q-28: this file in the nine baseline sections is the spec; the owner reads `reference\UI Elements.docx`; a check script joins the gate.
- Q-29: form field labels take sentence case; `title_case()` serves buttons, tabs, menu items and titles, never field labels.
- Q-30: the em dash stays the mark for an empty value in table cells and read-only fields, as `&mdash;` in a cell does today.

App decisions that shape the UI: D-014 (NetID sign-in; the header shows the NetID line or the acting-user switcher); D-016 (the owner as the one superadmin, so Studio shows to one person at first); D-017 and D-018 (favicon kept, no launcher); D-019 (adoption of this spec). `lib/ui.php` `title_case()` and `nice_code()` carry the casing rule in code.

Owner answers of 17 October 2026, which closed every open UI question for this app:

- Q-013 product name: PRIOps everywhere a person reads it, from the `app_name` setting. A mail sender may read "PRIOps (PRI Facilities)" so campus recipients know the source. "PRI Facilities Request System" is gone from every page, mail, export and document property.
- Q-014 lockup: two declared variants. On screens "PRI" is white and "Ops" is `#8FA9C4` on `#13294b`. Printed and deck lockups keep the PRIDE colours, "PRI" `#0775BA` and "Ops" `#6A6E71` on `#131F4E`.
- Q-015 favicon: the house stays, with its orange at `#FF5F05`. `tools/make_favicon.py` redraws the .ico from the .svg.
- Q-016 dates: "Oct 17, 2026" on screens through `ui_date()` and `ui_datetime()`, "October 17, 2026" on printed safety reports through `ui_date_long()`.
- Q-017 fonts: the campus faces, Source Sans 3 for body and controls and Montserrat for headings, carried in `assets/fonts` with their SIL Open Font licences. Nothing is fetched: the Illinois toolkit CSS that District Tracker carries reaches `cdn.brand.illinois.edu` at request time, and this app must render with no internet. Two owner rulings sit on top of it: the wordmark keeps Arial 800 (17 Oct), and an app that must work offline keeps the same faces vendored offline (18 Oct), which is why PaCELab Equip uses the online kit and this app carries the files.
- Q-018 header: unchanged. Facilities keeps its own navy bar.
- Q-019 greys: the cool set. `--paper` `#f4f6f8`, `--fill` `#e3e6ea`, `--line` `#e3e3e2`, with `--subtle` and `--hover` a step either side. `--warm` stays warm because it marks guidance.
Open decisions (`reference\open-questions.md`): Q-013 product name; Q-014 lockup colours; Q-015 favicon mark; Q-016 long dates on printed safety reports; Q-017 fonts (shared); Q-018 header chrome (shared); Q-019 neutral greys (shared). Answered by Q-25: Q-020 button label casing (PFR-16).
