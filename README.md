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

---

## Project layout

```
BukSU CASDEVU/
├── database/schema.sql      Clean installer: 22 tables, 3 views, seed data
├── docs/                    Requirements, DB design, role matrix, build plan
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
│   └── layout/              header.php, footer.php
├── public/                  Web root — point the browser here
│   ├── login.php  logout.php  register.php
│   ├── index.php            Role-specific dashboard
│   ├── profile.php  notifications.php
│   ├── activities/          index.php, view.php, manage.php,
│   │                        coordinators.php, eligibility.php
│   ├── participation/       register.php, my-activities.php, participants.php
│   ├── requirements/        manage.php, submit.php, verify.php, download.php
│   ├── inventory/           index.php, manage.php, reserve.php,
│   │                        reservations.php, borrowings.php
│   ├── announcements/       index.php, manage.php
│   ├── admin/                users.php (accounts), venues.php
│   └── assets/css/style.css
└── storage/uploads/         Uploaded files — NOT web-accessible
```

`includes/` and `storage/` each carry an `.htaccess` denying direct web access.
On a real deployment, point the virtual host at `public/` so they sit outside
the web root entirely.

---

## Status — Weeks 1–3 complete

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

**Not yet built**

- Reports and CSV export (Week 4)
- Deadline reminder notifications (Week 4)
- Activity calendar view
- Backup and restore
- Activity deletion (create/edit only)
- Category management UI (categories are seeded)
- Audit trail viewer (entries are written but not displayed)
- Login rate limiting

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

---

## Documentation

| File | Contents |
|------|----------|
| `docs/01-requirements-spec.md` | 58 functional + 9 non-functional requirements, prioritized |
| `docs/02-database-design.md` | ERD, table reference, design rationale |
| `docs/03-role-matrix.md` | Permission matrix and enforcement pattern |
| `docs/04-build-plan.md` | Week-by-week schedule, security checklist, definition of done |
