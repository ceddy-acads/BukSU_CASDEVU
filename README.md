# CASMS — Culture, Arts, and Sports Management System

Bukidnon State University · Office of Culture, Arts, and Sports
Native PHP 8 + MySQL (XAMPP)

---

## Setup

**1. Place the project under XAMPP**

```
C:\xampp\htdocs\casms\
```

If the folder is named differently, update `BASE_URL` in `includes/config.php`
to match — it must be the URL path to `public/`.

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
├── database/schema.sql      Clean installer: 20 tables, 3 views, seed data
├── docs/                    Requirements, DB design, role matrix, build plan
├── includes/                Application code — NOT web-accessible
│   ├── config.php           Credentials and settings
│   ├── bootstrap.php        Loaded first by every page
│   ├── db.php               PDO connection + query helpers
│   ├── auth.php             Sessions, login, role gates, audit log
│   ├── csrf.php             CSRF tokens
│   ├── helpers.php          Escaping, URLs, flash messages, formatting
│   ├── notifications.php    In-app notifications
│   └── layout/              header.php, footer.php
├── public/                  Web root — point the browser here
│   ├── login.php  logout.php  register.php
│   ├── index.php            Role-specific dashboard
│   ├── profile.php  notifications.php
│   ├── activities/          index.php, view.php, manage.php
│   ├── admin/users.php      Account activation and user management
│   └── assets/css/style.css
└── storage/uploads/         Uploaded files — NOT web-accessible
```

`includes/` and `storage/` each carry an `.htaccess` denying direct web access.
On a real deployment, point the virtual host at `public/` so they sit outside
the web root entirely.

---

## Status — Week 1 complete

**Built and tested**

- Login, logout, session handling with idle timeout
- Student self-registration; accounts start `pending` and need office approval
- Role gate (`require_role`) and ownership gate (`require_activity_access`)
- Profile editing and password change
- Activity list with search, category/status filters, and pagination
- Activity detail page
- Activity create and edit, with venue double-booking warning
- User management: activate, deactivate, change role (admin only)
- In-app notifications and inbox
- Audit logging on login, account changes, and activity changes

**Verified end to end against XAMPP** — login flow, activity creation,
account activation, CSRF rejection (419), role denial (403), missing record
(404), and draft activities staying invisible to students.

**Stubbed, marked in the UI as "Week 2"**

- Registering for an activity
- Participant lists and approval
- Requirements definition, upload, and verification
- Inventory (nav link present, module not yet built)

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

---

## Documentation

| File | Contents |
|------|----------|
| `docs/01-requirements-spec.md` | 45 functional + 9 non-functional requirements, prioritized |
| `docs/02-database-design.md` | ERD, table reference, design rationale |
| `docs/03-role-matrix.md` | Permission matrix and enforcement pattern |
| `docs/04-build-plan.md` | Week-by-week schedule, security checklist, definition of done |
