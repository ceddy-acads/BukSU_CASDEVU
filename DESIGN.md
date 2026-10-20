# Design direction

Direction: **Loud and flat** (dealt by `design-taste-frontend`, seed 985882162,
sector education), mapped onto BukSU's brand commitments in `PRODUCT.md`.

Dial: **ENERGY 2 / RHYTHM 2 / MOTION 2**

## The reading

Poster, not poster clutter. Flat saturated fields, heavy compressed capitals,
one dominant photograph, continuous squircle corners, no gradients, no shadows
at rest, no ornament. The office's own world (intramurals, folkloric dance,
awards night) is the material. Nothing invented.

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
3. **Shape**: the first pass was square. The office asked for a squircle
   language, so cards, bands, controls and fields now share one continuous
   corner scale. The fields stay flat and the type stays loud; the softer edge
   is the only change. Circles stay circles.

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
- Shape: one squircle scale — `--r-xs` 6 (badges, counts), `--r-sm` 10
  (buttons, inputs, menu rows), `--r-md` 12 (sidebar rows and the current-page
  chip), `--r` 16 (cards, panels, filters), `--r-lg` 24 (page bands, dialogs,
  the sign-in panel). Every rounded surface also declares
  `corner-shape: squircle` inside `@supports`; older browsers keep the plain
  border-radius. Avatars, status dots and check rings stay circular.
- Depth: no shadow at rest. Overlays only (menus, dialogs).
- Bands: every page head shares one block height — 315px on phones, 266px from
  720px to 1023px, 211px from 1024px up — whether it carries a photograph or a
  flat field. The floor at each layout fits the tallest standard head;
  unusually long copy can grow past it. Plain heads centre their copy; photo
  heads keep their own internal geometry.
- Type: Anton (display, uppercase, leading .86), Outfit (text, 16px+).
- Type steps: 13 / 14 / 16 / 18 / 24 / 34 / display clamp.

## Rules held

- Headlines two lines or fewer at every width.
- No eyebrows, no monospace, no index numbers, no thin rules as decoration,
  no glass, no shadows at rest, no violet. One gradient is allowed: the navy
  scrim on the dashboard band.
- Photographs sit in their own column or band. The dashboard is the one
  exception: its photograph runs behind the welcome, under a scrim heavy enough
  to hold the headline at full contrast.
- Every photograph is the project's own, credited in the report, replaceable
  under the same file name.
- Print stays first-class: fields print as black on white, bands collapse.
