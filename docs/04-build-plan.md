# CASMS — Build Plan (3–4 Weeks)

---

## The scoping problem, stated plainly

The client document lists **~40 features across 9 modules**. In native PHP,
solo, that is roughly 8–10 weeks of work. You have 3–4.

Attempting all 40 produces 40 half-built screens and a failed demo. The strategy
below instead delivers a **complete, working core** plus honest documentation of
what is deferred — which is both a better system and a stronger defense.

**What makes this defensible:** the database schema in `database/schema.sql`
covers **all 9 modules**. Nothing you build in week 1 needs rewriting when the
deferred features are added later. You are shipping a subset of a complete
design, not an incomplete design.

---

## Scope decision

### Tier 1 — MUST BUILD (the demoable system)

This is a genuinely useful system on its own: a student finds an activity,
registers, uploads requirements, and staff approve it all.

| Module | Scope |
|--------|-------|
| 1. Accounts | Login, logout, student registration, role gate, profile |
| 2. Activities | List, detail, create/edit, status |
| 3. Announcements | Publish, view, in-app notifications, search/filter |
| 4. Participation | Register, status tracking, approve/reject, participant list |
| 5. Requirements | Define, upload, verify, missing-checklist |
| 6. Inventory | Catalog, availability, reservation, borrow/return |
| 9. Admin | User management, activate pending accounts |

### Tier 2 — BUILD IF TIME REMAINS

Calendar view · eligibility rules · attendance marking · deadline reminders ·
schedule conflict checking · coordinator assignment · reports with CSV export ·
audit trail

### Tier 3 — DOCUMENTED FUTURE WORK

Email/SMS notifications · password reset by email · backup/restore UI ·
category management UI

> Present Tier 3 in your defense as *"designed for, schema supports, deferred by
> scope"* — pointing at the `settings`, `audit_logs`, and `auth_tokens` tables
> that already exist for them. That reads as planning, not omission.

---

## Week-by-week

### Week 1 — Foundation and Activities
**Goal: a user can log in and see real activities.**

| Day | Work |
|-----|------|
| 1 | Import `schema.sql`. Set up folder structure, `config.php`, PDO connection, base layout/CSS |
| 2 | Login, logout, session handling, `require_role()` gate |
| 3 | Student registration form, staff account activation, profile page |
| 4 | Activity list + detail pages (student view) |
| 5 | Activity create/edit/delete (staff view), status changes |
| 6–7 | Role-specific dashboards. **Milestone: end-to-end login → browse → manage.** |

### Week 2 — Participation and Requirements
**Goal: the core workflow works start to finish. This is the heart of the demo.**

| Day | Work |
|-----|------|
| 8 | Student registers for an activity; duplicate and capacity checks |
| 9 | Student "My Activities" page with registration status |
| 10 | Staff participant list, with year-level/course filters |
| 11 | Approve/reject registrations, with rejection reason |
| 12 | Staff define requirements per activity |
| 13 | Student file upload — validate type and size, store safely, random filename |
| 14 | Staff verify/reject submissions; student missing-requirements checklist. **Milestone: full participation workflow.** |

### Week 3 — Inventory, Announcements, Admin
**Goal: remaining core modules; system is feature-complete for Tier 1.**

| Day | Work |
|-----|------|
| 15 | Inventory catalog + item CRUD |
| 16 | Availability view (`v_item_availability`), status badges |
| 17 | Reservation request and approval |
| 18 | Borrowing release and return recording; overdue highlighting |
| 19 | Announcements: publish and view |
| 20 | In-app notifications on approval, rejection, and new activity |
| 21 | Search and filtering across activities and announcements. **Milestone: Tier 1 complete.** |

### Week 4 — Hardening, Tier 2, Documentation
**Goal: it does not break during the demo.**

| Day | Work |
|-----|------|
| 22 | **Security pass** — checklist below. Do this before adding any new feature |
| 23 | Seed realistic demo data: ~30 students, 6 activities, 20 inventory items, mixed statuses |
| 24 | Test every role end to end. Fix what breaks |
| 25 | Tier 2 picks — highest value first: reports with CSV export, then calendar |
| 26 | Responsive/mobile pass; empty states; error messages |
| 27 | User manual, updated documentation, screenshots |
| 28 | **Rehearse the demo end to end, twice.** Freeze the code. |

---

## Suggested folder structure

```
BukSU CASDEVU/
├── database/
│   └── schema.sql
├── docs/
│   ├── 01-requirements-spec.md
│   ├── 02-database-design.md
│   ├── 03-role-matrix.md
│   └── 04-build-plan.md
├── public/                     ← set this as the web root
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── register.php
│   ├── activities/
│   ├── participation/
│   ├── requirements/
│   ├── inventory/
│   ├── admin/
│   └── assets/  (css, js, img)
├── includes/                   ← NOT web-accessible
│   ├── config.php              ← DB credentials; never commit real ones
│   ├── db.php                  ← PDO connection
│   ├── auth.php                ← require_role(), require_activity_access()
│   ├── csrf.php
│   ├── helpers.php
│   └── layout/  (header.php, footer.php, sidebar.php)
└── storage/                    ← NOT web-accessible
    └── uploads/requirements/
```

**`storage/` and `includes/` must sit outside the web root.** If a student can
reach `/storage/uploads/requirements/` in a browser, every uploaded medical
clearance is public.

---

## Security checklist (Week 4, Day 22)

Walk this list page by page. Each item is a likely defense question.

- [ ] Every SQL query uses PDO **prepared statements** — zero string concatenation
- [ ] Every echoed variable passes through `htmlspecialchars()`
- [ ] Every POST form carries a CSRF token, validated server-side
- [ ] Every protected page calls `require_role()` **before** any output
- [ ] Ownership-scoped pages also call `require_activity_access()`
- [ ] Passwords use `password_hash()` / `password_verify()` — never MD5 or SHA1
- [ ] `session_regenerate_id(true)` runs on login (prevents session fixation)
- [ ] Uploads validated by **MIME type**, not just extension
- [ ] Uploads renamed to a random filename; original name stored in the database only
- [ ] Upload directory is outside the web root, or has `.htaccess` denying execution
- [ ] Default admin password changed
- [ ] Database errors are logged, not printed to the page
- [ ] Direct URL access tested: log in as a student, try a staff URL manually

---

## Risks

| Risk | Mitigation |
|------|-----------|
| Scope creep back toward all 40 features | Tier 1 is the contract. Tier 2 only after Day 21 |
| File uploads eat a day (a classic) | Build it on Day 13 with a time limit; fall back to "acknowledge requirement" if blocked |
| Demo data missing at defense time | It is a scheduled task on Day 23, not an afterthought |
| Losing work | Initialize git on Day 1 and commit daily. This folder is not yet a repository |
| Discovering a schema gap mid-build | The schema covers all 9 modules; gaps should be rare. Add columns, never redesign |

---

## Definition of done for Tier 1

The system is demo-ready when a reviewer can watch this, unbroken:

1. A student registers an account; staff activates it
2. Staff create an activity with two requirements attached
3. The student logs in, finds the activity by search, and registers
4. Staff see the registration pending and approve it
5. The student is notified, sees the missing-requirements checklist, and uploads both files
6. Staff verify one and reject the other with a reason
7. The student sees the rejection and re-uploads
8. Staff reserve two costumes for the activity and record them as borrowed
9. Staff record the return; availability updates correctly
10. Admin opens the user list and deactivates an account

If all ten steps run without an error page, you can defend the system.
