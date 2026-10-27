# CASMS — Requirements Specification

**System:** Culture, Arts, and Sports Management System
**Client:** Bukidnon State University — Office of Culture, Arts, and Sports
**Stack:** Native PHP 8 + MySQL (XAMPP), HTML/CSS/JS
**Source:** `Culture, Arts, Sports_System.pdf` (proposed features, subject to office validation)

---

## 1. Problem Statement

### Publication approval workflow (October 2026)

- Every published activity shows an alphabetized, read-only list of approved
  player names for every signed-in role, including guests. Pending, rejected,
  withdrawn and completed registrations are excluded; private student details
  remain restricted to existing management screens.
- Relevant approval pages, status lists, dashboards, calendars and notification
  counts update through five-second background checks while visible. Approval,
  rejection, verification, activation and cancellation actions update in place.
  Drafts and selected uploads survive incoming changes; stale decisions require
  reviewing the latest record. Hidden tabs pause updates, and background checks
  do not prolong idle sessions or mark notifications read.

- Coordinator dashboards label the existing loan statistic **Borrowed Items**.
- Coordinators create activities as **Pending approval**, automatically assigned
  to themselves. Administrators approve publication or reject with a reason;
  coordinators may revise pending submissions and resubmit rejected ones.
- Coordinator announcements have a **Draft bin**. **Save draft** keeps work
  private; **Announce** submits it for administrator approval. Students see
  announcements and receive publication notifications only after approval.
- Coordinators cannot change approved activity details, eligibility, or published
  announcements. Participant, requirement, attendance, and equipment workflows
  continue. Existing office management permissions remain.
- Guests add calendar programs and track their own requests. Administrators alone
  decide account-linked programs. Approved programs appear on the submitting
  guest's calendar and a decision email goes to their account email. Other
  guests' programs are private; programs do not enable student participation.
- Pending publication submissions appear in the administrator's review queue.
  Repeated or stale review decisions cannot publish content or repeat notices.

The Office of Culture, Arts, and Sports currently coordinates activities through
manual and informal channels. This produces five recurring problems, each named
in the client's feature document:

| # | Concern | Consequence today |
|---|---------|-------------------|
| 1 | Activity dissemination | Students learn about activities late or not at all |
| 2 | Student participation | No single list of who signed up or who was approved |
| 3 | Requirements | Clearances and forms are tracked on paper; gaps found late |
| 4 | Costumes and equipment | No record of what exists, what is out, or who has it |
| 5 | Participant records | Past participation cannot be retrieved for reports |

**CASMS** centralizes all five into one role-aware web system.

---

## 2. Users and Roles

| Role | Who they are | Primary goal |
|------|--------------|--------------|
| **Student** | Enrolled BukSU student | Find activities, register, submit requirements |
| **Coordinator** | Faculty/staff assigned to an activity | Manage that activity's participants and schedule |
| **Staff** | Office of Culture, Arts, and Sports personnel | Run activities, verify requirements, manage inventory |
| **Admin** | System administrator | Manage users, categories, audit trail, backups |

Roles are **cumulative in practice but enforced per-action** — see
`03-role-matrix.md` for the authoritative permission table.

---

## 3. Functional Requirements

Each requirement traces to a feature in the client PDF. **Priority** drives the
build plan: `MUST` = core spine, required for a working system;
`SHOULD` = expected but deferrable; `COULD` = stretch.

### FR-1 — User Account and Access

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-1.1 | Users log in with email and password; sessions expire on logout or timeout | MUST |
| FR-1.2 | Students self-register with student number, name, course, year level, section | MUST |
| FR-1.3 | New student accounts start as `pending` and require staff/admin activation | MUST |
| FR-1.4 | Every page checks the user's role and shows only permitted features | MUST |
| FR-1.5 | Users view and update their own profile, contact details, and password | MUST |
| FR-1.6 | Passwords are stored using `password_hash()`; never plaintext or MD5 | MUST |
| FR-1.7 | Forgotten-password reset via emailed one-time token | COULD |

### FR-2 — Activity and Event Management

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-2.1 | Any logged-in user can browse current and upcoming activities | MUST |
| FR-2.2 | Activity detail page shows title, description, date, venue, organizer, eligibility, instructions | MUST |
| FR-2.3 | Staff/admin can create, edit, and delete activities | MUST |
| FR-2.4 | Activity carries a status: draft, upcoming, ongoing, completed, cancelled, closed | MUST |
| FR-2.5 | Status transitions from `upcoming` to `ongoing` to `completed` follow the schedule automatically | SHOULD |
| FR-2.6 | Activities display in a monthly calendar view colored by category | SHOULD |
| FR-2.7 | An activity may be restricted to specific year levels or courses | SHOULD |

### FR-3 — Announcements and Dissemination

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-3.1 | Staff publish announcements with title, body, and optional attachment | MUST |
| FR-3.2 | Announcements can be pinned to the top of the student dashboard | SHOULD |
| FR-3.3 | Users receive in-app notifications for: new activities, schedule changes, approval results, deadline reminders | MUST |
| FR-3.4 | Unread notification count is visible on every page | SHOULD |
| FR-3.5 | Users can search and filter activities and announcements by category, date, status, or keyword | MUST |
| FR-3.6 | Notifications are also sent by email | COULD |

### FR-4 — Participation Management

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-4.1 | A student registers from the activity detail page through a popup, submitting mandatory files and acknowledgements with the registration before office review | MUST |
| FR-4.2 | A student cannot register twice for the same activity (enforced at database level) | MUST |
| FR-4.3 | Registration is blocked when the activity is closed, full, or the student is ineligible | MUST |
| FR-4.4 | The activity page displays its eligibility conditions before the student applies | MUST |
| FR-4.5 | A student tracks their registration status: pending, approved, rejected, withdrawn, completed | MUST |
| FR-4.6 | Staff/coordinators approve or reject registrations, with a reason on rejection | MUST |
| FR-4.7 | Staff view the participant list for an activity | MUST |
| FR-4.8 | Participant lists can be grouped and filtered by year level, section, course, or team | MUST |
| FR-4.9 | Staff mark attendance for approved participants | SHOULD |

### FR-5 — Requirements Management

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-5.1 | Staff define the requirements attached to an activity, each with a deadline | MUST |
| FR-5.2 | A requirement is flagged mandatory or optional | MUST |
| FR-5.3 | Students upload requirement files (PDF/JPG/PNG, max 5 MB) | MUST |
| FR-5.4 | Uploads are validated by extension **and** MIME type, stored outside the web root, renamed to a random filename | MUST |
| FR-5.5 | Staff mark a submission verified, pending, or rejected, with a reason on rejection | MUST |
| FR-5.6 | Students see and manage their checklist, submission status, and rejected-file replacements inside each activity; no separate student requirements sidebar entry | MUST |
| FR-5.7 | Students are reminded a configurable number of days before a requirement deadline | SHOULD |

### FR-6 — Costume and Equipment Management

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-6.1 | Inventory catalog lists items with code, name, description, size, quantity, and photo | MUST |
| FR-6.2 | Each item shows a status: available, reserved, borrowed, damaged, under maintenance, unavailable | MUST |
| FR-6.3 | Available quantity is computed as total owned minus quantity currently on loan | MUST |
| FR-6.4 | Authorized users request a reservation of items for a date range and purpose | MUST |
| FR-6.5 | Staff approve or reject reservation requests | MUST |
| FR-6.6 | Borrowing records capture borrower, item, quantity, release date, expected return, actual return, and condition | MUST |
| FR-6.7 | Overdue items (past expected return, not yet returned) are highlighted | SHOULD |
| FR-6.8 | Staff add, edit, and update quantity and condition of inventory items | MUST |

### FR-7 — Coordination and Scheduling

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-7.1 | Coordinators set activity dates, venues, and times | MUST |
| FR-7.2 | Admin/staff assign coordinators to an activity | MUST |
| FR-7.3 | System warns when a new activity overlaps an existing one at the same venue | SHOULD |
| FR-7.4 | System warns when reserved equipment is already committed in that date range | SHOULD |
| FR-7.5 | Staff see the venue and equipment needed for an upcoming activity in one view | SHOULD |

### FR-8 — Records and Reports

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-8.1 | Staff browse and search student/participant records | MUST |
| FR-8.2 | A student's participation history lists their past activities and outcomes | MUST |
| FR-8.3 | Inventory report summarizes available, borrowed, damaged, and unavailable items | SHOULD |
| FR-8.4 | Participation report totals registered and approved participants by activity, year level, or category | SHOULD |
| FR-8.5 | Reports export to CSV and render in a printer-friendly layout | SHOULD |

### FR-9 — Administration and Security

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-9.1 | Admin creates, edits, deactivates, and reactivates user accounts | MUST |
| FR-9.2 | Admin manages activity categories and inventory categories | SHOULD |
| FR-9.3 | System logs create/update/delete/approve/login actions with user, timestamp, and IP | SHOULD |
| FR-9.4 | Admin can export a full database backup and restore from one | COULD |

---

## 4. Non-Functional Requirements

| ID | Requirement |
|----|-------------|
| NFR-1 | **Security.** All database access uses PDO prepared statements — no string-concatenated SQL anywhere. |
| NFR-2 | **Security.** All user-supplied output is escaped with `htmlspecialchars()` to prevent XSS. |
| NFR-3 | **Security.** Every state-changing form (POST) carries and validates a CSRF token. |
| NFR-4 | **Security.** Authorization is checked server-side on every request, never by hiding links alone. |
| NFR-5 | **Usability.** Layout is responsive; students will primarily use phones. |
| NFR-6 | **Performance.** Any list view returns within 2 seconds for up to 5,000 records; lists are paginated at 25 rows. |
| NFR-7 | **Compatibility.** Runs on XAMPP (PHP 8.x, MySQL/MariaDB) with no external paid services. |
| NFR-8 | **Maintainability.** Shared logic lives in `/includes`; no copy-pasted database code per page. |
| NFR-9 | **Data integrity.** Foreign keys and unique constraints are enforced by the database, not only by PHP. |

---

## 5. Assumptions and Open Questions

These were **not** settled by the client document and are currently assumed.
Confirm with the office before the corresponding module is built.

| # | Assumption made | Needs confirming because |
|---|-----------------|--------------------------|
| A-1 | Students self-register; accounts are activated by staff | If the office instead supplies a student master list, registration becomes an import |
| A-2 | Notifications are in-app only for the MVP | Email/SMS needs an SMTP account or paid gateway |
| A-3 | Students may request costumes and equipment individually; a typed activity/purpose is required, and linking a registered activity is optional | The office reviews unlinked requests; assigned activity reviewers review linked requests |
| A-4 | One student may join multiple activities concurrently | The office may cap concurrent participation |
| A-5 | Requirement files are office-internal and not shared between activities | A shared "medical clearance on file" model would change the schema |
| A-6 | Backup means an SQL dump downloaded by the admin | A scheduled off-server backup would need hosting support |

---

## 6. Out of Scope

Explicitly not built, and stated as future work in the defense:

- Payment or fee collection
- Live scoring or tournament brackets
- Integration with the BukSU student information system
- Mobile applications (the web app is responsive instead)
- Biometric or QR attendance capture

School ID verification: administrators review complete uploaded IDs before they
are available to other authorized reviewers. Replacements reset approval. Students
must have an approved ID for new borrowing requests, approval of pending requests,
and item release. Existing loans and reservations are retained.
Registration approval requires all mandatory requirements to be verified, with a
file present when the requirement needs one. Existing approved registrations are
retained; activities without mandatory requirements remain approvable.
