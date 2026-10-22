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
3. **Shape**: the dealt sheet said square corners. The owner asked for rounded
   boxes after living with the square version, so `--r-sm` is 8px and `--r` is
   14px. Bands — the page head, the shell, table rows — stay square, which keeps
   the poster edge and stops every surface from reading as a floating card.

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
- Shape: rounded boxes. Radius 8px on controls (buttons, inputs, badges,
  chips), 14px on surfaces (cards, panels, dialogs, empty states). Bands stay
  square: the page head, table rows and the shell edges have no radius, so a
  rounded corner always means "this is a box you can hold", never a page edge.
- Depth: no shadow at rest. Overlays only (menus, dialogs).
- Bands: every page head uses the dashboard banner's block height — 315px on
  phones, 211px from 720px up — so the page does not jump between screens.
  Taller copy can grow past it. A photo band is the same sheet printed on one
  of the office's event photographs: same wash, same height, same type
  position, only the picture changes.
- Type: Anton (display, uppercase, leading .86), Outfit (text, 16px+).
- Type steps: 13 / 14 / 16 / 18 / 24 / 34 / display clamp.

## Rules held

- Headlines two lines or fewer at every width.
- No eyebrows, no monospace, no index numbers, no thin rules as decoration,
  no glass, no shadows at rest, no violet.
- No gradients, with one exception: the page-head wash, a navy-to-blue
  gradient over the photograph, which the owner asked for on every screen.
- The page head is a photograph under that wash: the campus picture behind a
  navy-to-blue gradient, heavy on the text side (94% navy) and lighter on the
  open side (82% blue), so the headline and the body copy keep at least
  4.5:1 over the brightest patch of sky. The domain colour survives as a
  strip under the band.
- A photo band carries one of the office's event photographs under that same
  wash, and never under text of its own: the picture is the field, the type
  sits on the dense side of the wash, and no word sits on an uncovered
  photograph.
- The top bar is white on every screen, phones included, with a hairline
  under it. Its text is ink, and the focus ring on it is navy, because the
  gold ring does not read on white.
- Photographs sit in their own column or band, never behind text — except in
  the page head, where the wash above is the rule.
- Every photograph is the project's own, credited in the report, replaceable
  under the same file name.
- Print stays first-class: fields print as black on white, bands collapse.
