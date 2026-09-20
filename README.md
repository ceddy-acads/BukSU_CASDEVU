# CASMS — Culture, Arts, and Sports Management System

Bukidnon State University · Office of Culture, Arts, and Sports
Native PHP 8 + MySQL (XAMPP)

---

## Setup

**1. Place the project under XAMPP**

```
C:\xampp\htdocs\casms\
```

The folder may be named anything — `BASE_URL` is detected automatically, so
the same code runs under XAMPP, under a virtual host pointed at `public/`, and
under `php -S`. Override it in `includes/config.php` only if detection fails.

To run without touching XAMPP's htdocs at all:

```bash
cd public
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .
# then open http://127.0.0.1:8080/login.php
```

**2. Create the database**

```bash
C:\xampp\mysql\bin\mysql.exe -u root < database/schema.sql
```

**2b. Optional — load the demonstration dataset**

```bash
C:\xampp\mysql\bin\mysql.exe -u root casms < database/demo-data.sql
```

Adds 37 accounts, 6 activities, 36 registrations, 20 inventory items,
6 venues, reservations, loans and announcements — enough to walk through
every screen. The dataset is deliberately mixed: activities in every status,
registrations pending/approved/rejected/completed, two accounts awaiting
approval, one overdue loan, one damaged item and one under maintenance.

All demo accounts use the password `demo1234`; the administrator seeded by
`schema.sql` keeps its own password and is left untouched. The script is
idempotent — re-running it adds nothing.

**3. Start Apache and MySQL in the XAMPP Control Panel**

**4. Open the system**

```
http://localhost/casms/public/login.php
```

**5. Sign in and change the default password immediately**

| Field | Value |
|-------|-------|
| Email | `admin@buksu.edu.ph` |
| Password | `admin123` |

**6. Optional — schedule the deadline reminders**

Reminders can be sent by hand from **Admin > Reminders**. To send them
unattended, point Windows Task Scheduler (or cron) at the runner once a day:

```bash
C:\xampp\php\php.exe "C:\path\to\CASMS\bin\send-reminders.php"
```

It accepts `--dry-run` to report what would be sent without sending, and
`--quiet` for the summary line only. The script refuses to run over HTTP.

---

## Configuration

All settings live in `includes/config.php`. The defaults suit a local XAMPP
install; the ones worth knowing about:

| Constant | Default | What it controls |
|----------|---------|------------------|
| `APP_ENV` | `production` | `development` shows error detail on screen. Either way the detail is written to `storage/logs/php-error.log` and the user sees a reference code |
| `BASE_URL` | auto-detected | URL path to `public/`. Override only if detection fails |
| `MAIL_TRANSPORT` | `log` | `log` writes messages to `storage/logs/mail.log`; `mail` hands them to PHP's `mail()` |
| `MAIL_NOTIFICATIONS_ENABLED` | `true` | Whether reminders are emailed as well as shown in-app |
| `LOGIN_MAX_ATTEMPTS` | `8` | Failed sign-ins per IP address before a lockout |
| `LOGIN_LOCKOUT_MINUTES` | `15` | How long that lockout lasts |
| `PASSWORD_RESET_TTL_MINUTES` | `60` | Lifetime of a reset link |
| `MAX_UPLOAD_BYTES` | 5 MB | Largest accepted requirement file |
| `MYSQL_BIN_PATH` | `C:/xampp/mysql/bin` | Where `mysqldump.exe` and `mysql.exe` live, used by Backup |

**Email delivery.** `MAIL_TRANSPORT` ships as `log`, so nothing is actually
sent — messages are appended to `storage/logs/mail.log`, which is enough to
demonstrate password reset and reminders end to end without an SMTP account.
Set it to `mail` only on a server that has a working mail transport; XAMPP
does not provide one by default.

---

## Project layout

```
BukSU CASDEVU/
├── database/
│   ├── schema.sql           Clean installer: 22 tables, 3 views, seed data
│   └── demo-data.sql        Optional realistic dataset for a walkthrough
├── docs/                    Requirements, DB design, role matrix, build plan
├── bin/send-reminders.php   Deadline reminder runner (command line only)
├── includes/                Application code — NOT web-accessible
│   ├── config.php           Credentials and settings
│   ├── bootstrap.php        Loaded first by every page
│   ├── db.php               PDO connection + query helpers
│   ├── auth.php             Sessions, login, role gates, audit log
│   ├── csrf.php             CSRF tokens
│   ├── helpers.php          Escaping, URLs, flash messages, formatting
│   ├── notifications.php    In-app notifications
│   ├── participation.php    Registration eligibility and requirement progress
│   ├── uploads.php          Upload validation and safe storage
│   ├── inventory.php        Availability maths, reservations, borrowing
│   ├── reports.php          Report queries and CSV export
│   ├── errors.php           Global exception and fatal-error handling
│   ├── mailer.php           Outgoing email (log or mail transport)
│   ├── reminders.php        Deadline reminder rules
│   ├── backup.php           Database dump, restore, and file guards
│   └── layout/              header.php, footer.php
├── public/                  Web root — point the browser here
│   ├── login.php  logout.php  register.php
│   ├── index.php            Role-specific dashboard
│   ├── profile.php  notifications.php
│   ├── activities/          index.php, view.php, manage.php, calendar.php,
│   │                        coordinators.php, eligibility.php
│   ├── participation/       register.php, my-activities.php, participants.php
│   ├── requirements/        manage.php, submit.php, verify.php, download.php
│   ├── inventory/           index.php, manage.php, reserve.php,
│   │                        reservations.php, borrowings.php
│   ├── announcements/       index.php, manage.php
│   ├── reports/             index.php, participation.php, inventory.php,
│   │                        students.php
│   ├── admin/               users.php, venues.php, categories.php,
│   │                        audit.php, backup.php, reminders.php
│   ├── forgot-password.php  reset-password.php
│   └── assets/css/style.css
└── storage/                 NOT web-accessible
    ├── uploads/             Submitted requirement files
    ├── backups/             Database dumps (git-ignored)
    └── logs/                php-error.log, mail.log (git-ignored)
```

`includes/` and `storage/` each carry an `.htaccess` denying direct web access.
On a real deployment, point the virtual host at `public/` so they sit outside
the web root entirely.

---

## Status — Weeks 1–4 complete

**Week 1 — foundation**

- Login, logout, session handling with idle timeout
- Student self-registration; accounts start `pending` and need office approval
- Role gate (`require_role`) and ownership gate (`require_activity_access`)
- Profile editing and password change
- Activity list with search, category/status filters, and pagination
- Activity detail, create and edit, with venue double-booking warning
- User management: activate, deactivate, change role (admin only)
- In-app notifications and audit logging

**Week 2 — participation and requirements**

- Student registers for an activity, with team name and remarks
- Guards: duplicate, capacity, registration window, activity status, and the
  year-level / course eligibility whitelist
- Withdraw, allowed only while still awaiting review
- "My activities" with per-activity requirement progress
- Staff participant list: search, status and year-level filters, approval,
  rejection with a reason, attendance marking
- Staff define requirements per activity (mandatory/optional, file or
  acknowledgement, deadline)
- Student uploads documents; missing-requirements checklist
- Staff verify or reject submissions, with the reason shown to the student
- Re-upload after rejection; a verified submission is locked
- Authenticated download route for files stored outside the web root

**Verified end to end against XAMPP.** Beyond the Week 1 checks, Week 2 was
tested for: a PHP script renamed `.pdf` being rejected by content sniffing,
one student being unable to open another's document (403), the storage folder
being unreachable by URL (404), replaced files not leaving orphans on disk,
rejection without a reason being refused, and every capacity / window /
eligibility guard blocking with the correct message.

**Week 3 — inventory, reservations, announcements**

- Inventory catalog with search, category and status filters, live availability
- Item create, edit, delete; deletion refused for items with borrowing history
- Availability is derived, never stored: total owned, minus open loans, minus
  approved reservations overlapping the requested window
- Reservation requests with per-item quantities, reviewed by the office
- Availability re-checked at approval, not only at request time
- Borrowing ledger: release, expected return, actual return, condition
- Returns marked good, damaged, or lost; damaged flags the item, lost reduces
  the owned quantity
- Overdue loans highlighted on the dashboard and the inventory screens
- Item status (available / reserved / borrowed) recalculated as items move;
  damaged, under maintenance and unavailable stay under office control
- Announcements with drafts, publishing, and pinning; publishing notifies every
  active student exactly once, and re-saving does not notify again

**Phase 4 — management interfaces and security hardening**

- Venue management: list, add, edit, archive/reactivate; venues are seeded so
  scheduling and conflict detection work on a fresh install
- Archived venues cannot be assigned to an activity (enforced server-side)
- Coordinator assignment: assign and remove, restricted to active
  coordinator/staff/admin accounts, duplicates prevented
- Eligibility rules: add and remove year-level / course rules per activity,
  with duplicate and redundant-rule detection; rules shown on the activity page
- Staff can no longer deactivate or suspend an administrator
- The last active administrator cannot be deactivated or demoted
- `reservation_id` is validated before release (exists, approved, and actually
  lists that item) instead of raising an uncaught database error
- Coordinators can only post announcements for activities they coordinate
- Requirement submission is refused once an activity is cancelled or completed

**Week 4 — reporting, hardening, and demo data**

- Participation report: totals per activity, plus a breakdown by year level
  and course; coordinators see only the activities assigned to them
- Inventory report: owned, on loan, available, damaged and unavailable, with
  the full borrowing log
- Student and player records: search, and a per-student participation history
- CSV export on every report, carrying the filters currently applied; cells
  that a spreadsheet could execute as a formula are neutralised
- Print layout with a letterhead, repeated table headings, and no navigation
- Audit trail viewer for administrators, filterable by action, record type,
  person, date range and keyword
- Category management for both activity and inventory categories
- Activity deletion (administrators only), which also removes the submitted
  files from disk rather than orphaning them
- Global exception handler: no error, file path, or SQL reaches the browser.
  `APP_ENV` ships as `production`; detail goes to
  `storage/logs/php-error.log` and the user sees a reference code
- Sign-in throttling: failed attempts are recorded, and an address is locked
  out after 8 failures within 15 minutes
- Optional demonstration dataset (`database/demo-data.sql`)

## Requirements status

Measured against the 58 functional requirements in
`docs/01-requirements-spec.md`, at commit `d4101c4`.

| Priority | Implemented | Total |
|----------|-------------|-------|
| **MUST** | **39** | 39 |
| **SHOULD** | **14** | 16 |
| **COULD** | 2 full, 1 partial | 3 |

**Not implemented (2)**

| Requirement | Priority | Status |
|-------------|----------|--------|
| FR-2.5 — activity status moves from `upcoming` to `ongoing` to `completed` automatically | SHOULD | **Not implemented.** Status is changed by hand on the activity form. No scheduled job updates it |
| FR-7.5 — staff see the venue and equipment needed for an activity in one view | SHOULD | **Not implemented.** Venue is on the activity page and equipment on the reservations page; there is no combined screen |

**Partially implemented (1)**

| Requirement | Priority | Status |
|-------------|----------|--------|
| FR-3.6 — notifications are also sent by email | COULD | **Partial.** Password reset and deadline reminders send email. Registration approvals and rejections, requirement verification and reservation decisions are in-app only — `notify_and_email()` exists in `includes/mailer.php` but no caller uses it yet |

Everything else in the specification is implemented and was exercised during
testing.

---

## Testing

A full evaluation was run against the demo dataset at commit `d4101c4`:
roughly **290 checks**, covering every page, all four roles, and anonymous
access.

| Area | Checks | Result |
|------|--------|--------|
| Authentication, including negative cases | 7 | Pass |
| Access control — 31 pages · 5 identities | 155 | Pass, matching `docs/03-role-matrix.md` |
| Coordinator per-activity scoping | 18 | Pass |
| Core workflows (activity, registration, requirements, approval) | ~25 | Pass |
| Inventory, reservations, borrow and return | ~20 | Pass |
| Calendar, reminders, reports, password reset, backup | ~25 | Pass |
| Security probes | ~40 | No vulnerabilities found |

**No critical or high-severity defects were found.** No HTTP 500 responses, no
fatal errors, and nothing written to the application error log during the run.

**Security probes and what they showed**

- SQL injection payloads against 7 endpoints and 12 parameters: no effect.
  Every query uses bound parameters
- Stored XSS: a script payload saved through the announcement form was
  rendered escaped, with no live tag in the output
- CSRF: 17 write endpoints rejected a forged or absent token
- Insecure direct object reference: one student could not open another
  student's document, or their participation history
- Path traversal: 8 payloads against the backup download and direct requests
  for `includes/`, `storage/` and `bin/` all failed to return a file
- File upload: a PHP script renamed `.pdf` was rejected by content-type
  sniffing

**What this testing did not cover.** These are limits of the method, not
statements that the system fails them:

- Tested over HTTP with a command-line client, **not a real browser**. Page
  layout, JavaScript confirmation dialogs, mobile rendering and printed output
  are unverified
- **No concurrency testing.** Reservation approval re-checks availability but
  takes no row lock, so two simultaneous approvals are untested
- **No real email delivery tested.** `MAIL_TRANSPORT` was `log` throughout;
  messages were verified in `storage/logs/mail.log`, not in an inbox
- No load or performance testing beyond the 25-row pagination in ordinary use
- No automated test suite exists; all testing was manual and must be repeated
  by hand after changes

---

## Known limitations

1. **Email is partly wired.** See FR-3.6 above. Approvals and rejections reach
   the student in-app but not by email
2. **Activity status is manual.** An activity whose start date has passed stays
   `upcoming` until someone edits it
3. **No combined venue-and-equipment view.** The two live on separate screens
4. **Sign-in throttling is per IP address.** On a shared or NAT'd campus
   connection, one person's repeated failures can lock out others from the same
   address for `LOGIN_LOCKOUT_MINUTES`
5. **A restore cannot be undone.** `admin/backup.php` takes a safety copy of
   the current data first and requires the filename to be typed, but the
   replacement itself is immediate and irreversible
6. **Students cannot borrow equipment directly.** They can view the catalog and
   availability; requesting a reservation is restricted to coordinators and
   office staff, as specified in `docs/03-role-matrix.md`. This is a
   documented decision, recorded as open assumption A-3 in
   `docs/01-requirements-spec.md`, pending confirmation with the office
7. **No scheduled task is installed by default.** Deadline reminders only go
   out when the runner is invoked — see setup step 6
8. **Default credentials ship in the repository.** `admin123` for the seeded
   administrator and `demo1234` for demo accounts. Change them before the
   system is used with real data

---

## Conventions to keep

These are what make the security checklist pass at the end of Week 4.

1. Every page starts with `require_once __DIR__ . '/../includes/bootstrap.php';`
2. Every protected page calls `require_login()` or `require_role([...])`
   **before any output**
3. Every page touching one activity also calls `require_activity_access($id)`
4. Every `<form method="post">` includes `<?= csrf_field() ?>`
5. Every POST handler calls `csrf_verify()` first
6. Every echoed variable goes through `e()`
7. Every query uses `query()` / `fetch_one()` / `fetch_all()` with bound
   parameters — never string concatenation
8. Values that cannot be bound (ORDER BY, LIMIT) are cast to `int` or matched
   against a whitelist array
9. Uploads go through `store_upload()` — never `move_uploaded_file()` directly.
   It checks the real MIME type, not the extension, and renames the file
10. Stored files are reached only through `requirements/download.php`, which
    authorizes the request first. Never link into `storage/` directly
11. Report queries live in `includes/reports.php` so a screen and its CSV
    export always return the same rows
12. Values written to CSV pass through `csv_cell()`, which neutralises a cell
    a spreadsheet would otherwise execute as a formula

---

## Documentation

| File | Contents |
|------|----------|
| `docs/01-requirements-spec.md` | 58 functional + 9 non-functional requirements, prioritized |
| `docs/02-database-design.md` | ERD, table reference, design rationale |
| `docs/03-role-matrix.md` | Permission matrix and enforcement pattern |
| `docs/04-build-plan.md` | Week-by-week schedule, security checklist, definition of done |
