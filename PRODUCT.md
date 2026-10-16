# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Four roles, each with its own job. Students and the office are equal
priorities: when a screen has to trade one off against the other, neither
wins by default.

- **Students** (enrolled BukSU students), mostly on phones: find culture,
  arts, and sports activities, register, submit required documents, and
  follow their registration status and announcements.
- **Coordinators** (faculty or staff assigned to an activity): manage that
  activity's participants, requirements, and schedule; request equipment.
- **Office staff** (Culture, Arts, and Sports Development Unit personnel),
  mostly at a desk: run activities, approve registrations, verify
  requirements, manage costumes and equipment, and produce reports.
- **Administrators**: manage accounts, roles, categories, venues, the audit
  trail, and backups.

The authoritative permission table is `docs/03-role-matrix.md`.

## Product Purpose

The Culture, Arts, and Sports Development Unit of Bukidnon State University
coordinated its activities through manual and informal channels. CASDevU
replaces them with one role-aware web system that fixes five recurring
problems (`docs/01-requirements-spec.md`):

1. Students learned about activities late or not at all.
2. There was no single list of who signed up or who was approved.
3. Clearances and forms were tracked on paper, so gaps were found late.
4. There was no record of what costumes and equipment exist, what is out,
   or who has them.
5. Past participation could not be retrieved for reports.

Success means the office runs activities, participation, requirements,
inventory, and reporting through the system instead of paper and chat.

## Positioning

Built for one office's actual workflow: the unit's own activity categories,
BukSU's courses and year levels, its costume and equipment stock, and its
approval steps, rather than a generic event or ticketing tool.

## Operating Context

- Status: the system **will be used by the office** (confirmed); it is not
  yet in day-to-day use.
- Runs on the university's own server under XAMPP and must work on a campus
  network without internet access, so fonts and assets are self-hosted.
- Students mainly use phones; staff mainly use desktop browsers.
- Student accounts start as pending and are activated by staff or an admin.
- Reports are printed as well as read on screen.

## Capabilities and Constraints

- Feature areas (FR-1 to FR-9): accounts and access; activities and events;
  announcements; participation; requirements; costumes and equipment;
  coordination and scheduling (calendar, venues); records and reports;
  administration and security (users, audit log, backups).
- Stack: native PHP 8 and MySQL/MariaDB on XAMPP, plain HTML/CSS/JS, no
  build step and no paid external services (NFR-7).
- Security: prepared statements, escaped output, CSRF tokens, server-side
  authorization on every request (NFR-1 to NFR-4).
- Lists answer within 2 seconds for up to 5,000 records and page at 25 rows
  (NFR-6).
- Courses are BukSU's 29 programs from the Office of the Registrar.
- Terminology: the visible name is **CASDevU**; the internal working name
  **CASMS** remains in the database name, cookies, and logs.

## Brand Commitments

- Bukidnon State University's name, logo, and campus photograph are
  approved for use as the system's official identity (confirmed). Assets:
  `public/assets/img/buksu-logo-white.png`, `public/assets/img/buksu-campus.jpg`.
- Name: CASDevU, "Culture, Arts, and Sports Development Unit", under
  Bukidnon State University.

## Evidence on Hand

- Requirements, database design, role matrix, and build plan in `docs/`.
- Test accounts in `docs/test-accounts.pdf`; an optional demonstration
  dataset in `database/demo-data.sql` (sample content, not real records).
- No real usage data, testimonials, or adoption figures exist yet. Do not
  invent participation numbers, user counts, or endorsements.

## Product Principles

1. Serve students and the office equally: a fast, clear phone flow for
   students must not cost the office the dense, scannable views it works in.
2. Replace paper, not add to it: every step the office used to track by hand
   should have one clear place in the system.
3. Act on behalf of the university: official, trustworthy, and plain-spoken,
   never promotional.
4. Work on the campus network as it is: self-hosted, light, and dependable
   without internet access.
5. Show status honestly: pending, approved, rejected, overdue, and missing
   are always visible and never softened.

## Accessibility & Inclusion

- Text colour pairs meet WCAG 2.1 AA contrast; keyboard focus is always
  visible; touch targets are at least 44px on phones.
- Names include Filipino characters (for example Ñ), which forms and fonts
  must accept and display.
