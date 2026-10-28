# AGENTS.md — working on CASDevU

Read this before touching code. It is the short version of `README.md`,
`DESIGN.md` and `docs/`. Those remain the source of truth; this file exists so
a fresh session does not have to re-derive the rules.

## What this is

Native PHP 8.1+ and MySQL, no framework, no Composer, no build step. XAMPP.
The Culture, Arts, and Sports Development Unit's activity, participation,
requirements, inventory and reporting system for Bukidnon State University.

- `public/` — web root, one file per page (`activities/index.php`)
- `includes/` — application code, never web-accessible
- `database/` — `schema.sql` (clean install), `demo-data.sql`, update scripts
- `storage/` — uploads, backups, logs; never web-accessible
- `docs/` — requirements spec, DB design, role matrix, build plan

Internal identifiers keep the old name `casms` (database, session cookie,
`[CASMS]` log tags). Do not rename them.

## Running and checking

```powershell
# Fastest loop: PHP's own server, no Apache needed
cd public; C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .

# Installed under htdocs instead — Apache applies .htaccess, so no public/ and
# no .php in the URL
# http://localhost/<folder>/login

# Fresh database
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root casms < database\demo-data.sql

C:\xampp\php\php.exe bin\check-setup.php    # environment, DB, storage
C:\xampp\php\php.exe -l path\to\file.php    # lint one file after editing
C:\xampp\php\php.exe bin\smoke.php          # every page, every role
```

`bin/smoke.php` is the regression gate. It signs in over HTTP as admin, staff,
coordinator, student and guest, requests every page under `public/` with real ids from
the database, and fails on a 5xx, a notice in the log, a page containing an
error, or a role getting the wrong answer against `docs/03-role-matrix.md`.
Run it after any change to `includes/`, `style.css`, the layout or a page gate.
`--only=activities` narrows it; `--verbose` prints every request.

**Before saying a change is done:** lint the files you touched, run
`bin/smoke.php`, and look at the changed screen at 390px wide as well as
desktop. There is no automated test suite beyond this.

## Accounts

Demo accounts use password `demo1234`; the seeded administrator is
`admin@buksu.edu.ph` / `admin123`. Useful ones:

| Role | Email |
|---|---|
| admin | `admin@buksu.edu.ph` |
| staff | `rosales.office@buksu.edu.ph` |
| coordinator | `bautista.coach@buksu.edu.ph` (assigned to Intramurals 2026) |
| student | `andrea.santos@student.buksu.edu.ph` |
| guest | `reyes.guest@example.com` (view-only; `lim.guest@example.com` is pending) |

## Security rules — non-negotiable

1. Every page starts with `require_once __DIR__ . '/../includes/bootstrap.php';`
2. `require_login()` or `require_role([...])` runs **before any output**.
3. A page about one activity also calls `require_activity_access($id)`.
   `staff` and `admin` are the office; a `coordinator` passes only when assigned.
4. Every `<form method="post">` contains `<?= csrf_field() ?>`; every POST
   handler calls `csrf_verify()` first.
5. Every echoed variable goes through `e()`.
6. Every query uses `query()` / `fetch_one()` / `fetch_all()` / `fetch_value()`
   with bound parameters — never string concatenation. Values that cannot be
   bound (ORDER BY, LIMIT) are cast to `int` or checked against a whitelist.
7. Uploads go through `store_upload()`, never `move_uploaded_file()`. Files are
   served only through `requirements/download.php`, `inventory/photo.php`,
   `profile-photo.php`, `id-photo.php` and `highlights/file.php`; all but the
   last authorize first, the last is deliberately public (visible statuses only).
8. Stop a request with `abort_page($code, $message)`, never a bare `exit()`.
9. Report queries live in `includes/reports.php` so the screen and its CSV agree.
   CSV cells pass through `csv_cell()`.
10. Never print SQL, file paths or exception text to the browser. The global
    handler in `includes/errors.php` turns notices and warnings into exceptions,
    so a stray undefined index becomes a 500 rather than a warning in the HTML.

`APP_ENV` is `production`; detail goes to `storage/logs/php-error.log`.

### PHP gotchas that have already caused a production 500

- **Duplicate function names.** A page-local helper that shares a name with a
  global one in `includes/` is a fatal error at compile time — and only on that
  page. Grep the function name before adding one. (`admin/categories.php` had
  its own `category_field()` next to the global one.)
- Functions declared after `require .../header.php` cannot be used in the
  markup above them, and a function declared inside an `if` is conditional.

### CSS gotcha that has already caused a silent layout bug

- **A comment closed without being opened.** `--fs-display: ...; · page title
  (desktop) */` swallowed the next declaration, so `--s1` never existed and
  every `gap: var(--s1) ...` fell back to `normal`. The symptom looked like
  chart legends overlapping. `bin/smoke.php` now fails on an unbalanced `*/`,
  an empty custom property, or a `var()` with no definition and no fallback.

## Frontend rules

One design system: `public/assets/css/style.css` (tokens plus components).
Build pages from its classes — `page-head`, `card`, `filter-bar`, `stats`,
`item`, `feed`, `table.data`, `empty`, `badge`, `alert`. No inline `style=""`
except a colour or width that comes from data.

1. Link CSS and JS with `asset()`, which stamps the modification time.
2. Icons come from `icon('name')` in `includes/icons.php`; add the drawing
   there instead of pasting an SVG.
3. Status is always words, never colour alone: `status_badge($status, $domain)`
   with the domain (`activity`, `registration`, `submission`, `reservation`,
   `inventory`, `account`, `announcement`). New statuses go into
   `status_meta()` — never into a page.
4. Stat tiles use `stat_tone()` so a zero stays neutral.
5. One gold button (`btn-gold`) per page, for its headline action. Form submits
   are `btn-primary`; everything else is `btn-outline`.
6. Requirement state comes from `requirement_state()`, so the upload page and
   **My requirements** agree on whose turn it is.
7. Phone width (~390px) must not scroll sideways. Data tables scroll inside
   their own box; the ones people really use on a phone become labelled rows.
8. Sorting is opt-in: `data-sortable` on the table, `data-sort="text|number"`
   on headers, `data-sort-value` for dates. Only on unpaginated tables.
9. Every data-changing form gets the "Saving…" submit state (handled by
   `app.js` through `data-loading`).
10. Empty states say why the view is empty and what fills it, and tell "nothing
    yet" apart from "no results for these filters".

## Where to look

| Question | File |
|---|---|
| What must the system do? | `docs/01-requirements-spec.md` |
| Tables, columns, relations | `docs/02-database-design.md`, `database/schema.sql` |
| Who may do what | `docs/03-role-matrix.md` |
| Why it looks like this | `DESIGN.md`, `PRODUCT.md` |
| Setup, config, known limits | `README.md` |
| Auth, sessions, audit log | `includes/auth.php` |
| Escaping, URLs, statuses | `includes/helpers.php` |
| Review queue and counts | `includes/review.php` |
| Registration/requirement workflow | `includes/participation.php` |

Known gaps worth remembering: email is wired for password reset and reminders
only (`notify_and_email()` has no callers); activity status is changed by hand;
nothing schedules `bin/send-reminders.php` by default.
