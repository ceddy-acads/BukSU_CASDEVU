# Design direction

Direction: **Loud and flat** (dealt by `design-taste-frontend`, seed 985882162,
sector education), mapped onto BukSU's brand commitments in `PRODUCT.md`.

Dial: **ENERGY 2 / RHYTHM 2 / MOTION 2**

## The reading

Poster, not poster clutter. Flat saturated fields, heavy compressed capitals,
one dominant photograph, square corners, no gradients, no shadows at rest, no
ornament. The office's own world (intramurals, folkloric dance, awards night)
is the material. Nothing invented.

## What the direction asked for, and what changed

The dealt sheet named Anton, Work Sans and a grey/mint/orange palette. Two
redraws, both forced by a stated constraint:

1. **Type**: Anton kept as the display face, self-hosted with its OFL licence.
   Work Sans dropped: the project already self-hosts Outfit (SIL OFL), and a
   second text face would add weight to a campus-LAN install for no gain. Outfit
   does the text work at 16px and up.
2. **Palette**: the dealt proportions are kept (one ground, one ink, one accent,
   fields that flood whole sections), the colours come from the subject. BukSU's
   navy and gold (confirmed brand assets), plus one field per domain:
   sports green, culture maroon, arts teal. Mint and orange would have fought
   the university mark on every page.

## The idea

> On this page, **the office's own event photographs and category colours**
> become **the field a page is printed on**.

The competitor test: a generic event platform could use green, maroon and
teal, but not the BukSU mark, the unit's three domains, and the actual
intramurals and folkloric photographs. Two of the three are ours alone.

## The live thing

The dashboard's figures are live records, not decoration: upcoming activities,
pending requirements, unread announcements, each a link to the filtered list.
Phase two adds a domain filter on Activities that recolours the page field in
place, without a reload.

## Tokens

- Ground: `--navy` (sidebars, poster bands), `--bg` (page).
- Ink: `--ink` on light, `--on-dark` on fields.
- Accent: `--gold`, on the one headline action only.
- Fields: `--field-sports`, `--field-culture`, `--field-arts`.
- Shape: square. Radius 0 on cards, buttons, inputs, badges.
- Depth: no shadow at rest. Overlays only (menus, dialogs).
- Bands: every page head uses the dashboard banner's block height — 315px on
  phones, 211px from 720px up — so the page does not jump between screens.
  Taller copy can grow past it.
- Type: Anton (display, uppercase, leading .86), Outfit (text, 16px+).
- Type steps: 13 / 14 / 16 / 18 / 24 / 34 / display clamp.

## Rules held

- Headlines two lines or fewer at every width.
- No eyebrows, no monospace, no index numbers, no thin rules as decoration,
  no gradients, no glass, no shadows at rest, no violet.
- Nothing lies on top of a headline. Photographs sit in their own column or
  band, never behind text.
- Every photograph is the project's own, credited in the report, replaceable
  under the same file name.
- Print stays first-class: fields print as black on white, bands collapse.
