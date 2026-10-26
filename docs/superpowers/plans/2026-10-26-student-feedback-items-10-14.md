# Student feedback items 10 to 14: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Needs revision before building.** Written against `9a59006`. PR #16 (`ed0f2e2`) has since changed `inventory/borrow.php`, `inventory/index.php`, `inventory/reservations.php`, `includes/review.php`, `participation/register.php`, `activities/view.php` and `app.js`, and already covers part of item 13 (typed purpose, no activity needed, office reviews). Tasks 5 to 8 must be re-based on that code, and the open question in `docs/feature-backlog.md` item 13 answered first.

**Goal:** School ID approval by the admin with portrait upload (items 10, 11), withdraw after approval (12), borrowing without an activity (13), and borrowing by picking cards on Costumes & equipment with a popup (14), shipped to the live site as one zip plus one SQL file.

**Architecture:** Native PHP pages over shared helpers in `includes/`. The ID state lives on `users` (three new columns) and its transitions live in `includes/borrowing.php`, so the profile, the admin decision page, the borrow popup and the reservation/handover gates read one source. The borrow popup reuses the shared `[data-modal]` dialog in `app.js`: `inventory/borrow.php` becomes the fragment it loads and the handler it posts to.

**Tech Stack:** PHP 8.1+, MySQL/MariaDB (no views, no `ADD COLUMN IF NOT EXISTS`), vanilla JS in `public/assets/js/app.js`, one stylesheet `public/assets/css/style.css`.

**Spec:** `docs/superpowers/specs/2026-10-26-school-id-approval-design.md` (items 10, 11) and `docs/superpowers/specs/2026-10-26-withdraw-and-borrowing-changes-design.md` (items 12 to 14). Read both before any task.

## Global Constraints

- Every rule in `AGENTS.md` applies: bootstrap first, gate before output, `csrf_field()`/`csrf_verify()`, `e()` on every echo, bound parameters only, `abort_page()` not `exit()`, `status_badge()` with a domain, one `btn-gold` per page, `data-loading` on data-changing forms, no inline `style=""`, no sideways scroll at 390px.
- Grep every new function name in `includes/` and `public/` before adding it (duplicate names are a compile-time 500).
- "The office" in code is `is_office_staff()` (staff and admin). Staff stays until backlog item 7.
- ID decision emails go through `send_mail()` directly, regardless of the email-alerts switch. Subjects, verbatim: `Your school ID has been approved`, `Your school ID needs a new photo`.
- Status words, verbatim: `Waiting for approval`, `Approved`, `Rejected` (domain `school_id`).
- No-activity requests are labelled `Not for an activity` everywhere they are listed.
- **Do not commit or push.** The user's global rule: commit only when they ask. Each task ends with lint and checks instead of a commit.
- Never touch the highlights test PDF or its `casms` row.

## Review Focus

1. A student replaces one ID side while the admin has the decision page open: the decision must be refused, not applied to a photo the admin never saw (Task 2 test `decide_school_id refuses stale photos`).
2. A coordinator approves an existing student request while the student's ID is pending (every ID is pending right after the live SQL runs): refused, request stays pending, declining still works (Task 5 test).
3. A student withdraws from a completed or cancelled activity, or from a rejected registration: refused (Task 8 test).
4. A coordinator assigned to no activity sees a pending no-activity request, but not one another coordinator already decided (Task 6 test).
5. Picks made before a search or on another catalogue page are sent with the request; tampered item ids or quantities in the popup URL are refused (Task 7 server check plus manual step).

---

### Task 1: Database columns and update script

**Files:**
- Modify: `database/schema.sql` (users table, after `id_back_path`)
- Create: `database/update-school-id-approval.sql`
- Modify: `docs/02-database-design.md` (users columns)
- Test: `bin/check-borrowing.php`

**Interfaces:**
- Produces: `users.id_status ENUM('pending','approved','rejected') NULL`, `users.id_review_note VARCHAR(500) NULL`, `users.id_submitted_at DATETIME NULL` (when the current pair became complete; orders the admin queue. Builder call the spec left open).

- [ ] **Step 1: Add the failing checks** after the existing `db columns` check:

```php
check('db column id_status', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_status'"));
check('db column id_review_note', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_review_note'"));
check('db column id_submitted_at', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_submitted_at'"));
```

- [ ] **Step 2:** Run `C:\xampp\php\php.exe bin\check-borrowing.php`. Expected: the three new checks FAIL.
- [ ] **Step 3: Write the update script** with the `information_schema` + `PREPARE` pattern of `database/update-student-borrowing.sql`, one block per column, then:

```sql
UPDATE users SET id_status = 'pending', id_submitted_at = NOW()
 WHERE id_status IS NULL
   AND id_front_path IS NOT NULL AND id_front_path <> ''
   AND id_back_path  IS NOT NULL AND id_back_path  <> '';
```

Header comment: what it does, "Safe to run twice", and the `Run:` line. Add the same three columns to `schema.sql` with one-line comments.
- [ ] **Step 4:** Run it twice: `C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-school-id-approval.sql` (both runs exit 0), then `bin\check-borrowing.php`. Expected: all PASS.
- [ ] **Step 5:** Document the columns in `docs/02-database-design.md`.

---

### Task 2: ID state transitions and photo visibility

**Files:**
- Modify: `includes/borrowing.php`
- Modify: `public/profile.php:54-90` (upload and remove actions)
- Test: `bin/check-borrowing.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces (all in `includes/borrowing.php`):
  - `student_id_status(int $studentId): ?string`: fresh read (never `current_user()`, it is cached).
  - `sync_id_status(int $studentId): void`: both paths present → `id_status = 'pending'`, `id_review_note = NULL`, `id_submitted_at = NOW()`; otherwise all three `NULL`. Called after every upload and removal.
  - `decide_school_id(int $studentId, bool $approve, string $reason, string $frontSeen, string $backSeen): string`: returns `'done'`, `'stale'` or `'reason_required'`. One `UPDATE ... WHERE user_id = ? AND id_status = 'pending' AND id_front_path = ? AND id_back_path = ?`; `rowCount() !== 1` → `'stale'`. Reject needs a non-empty reason of at most 500 characters (trimmed; longer → `'reason_required'`). On `'done'`: `audit_log('approve'|'update', 'user', $studentId, 'Approved school ID' | 'Rejected school ID: <reason>')`, `notify($studentId, 'system', <subject>, <message>, url('profile.php#school-id'))`, and `send_mail()` with the subjects from Global Constraints. Approved body says they can now request costumes and equipment and links `absolute_url(url('inventory/index.php'))`; rejected body quotes the reason and links `absolute_url(url('profile.php#school-id'))`; both end with `office_name()` and `university_name()` like the activation email in `public/admin/users.php`.
  - `delete_id_photos()` also clears the three columns.
  - `can_view_id_photos()`: the office may also view while `student_id_status($studentId) === 'pending'`.

- [ ] **Step 1: Add failing checks** (inside the existing transaction, using `$aid` and two temp files under `UPLOAD_PATH . '/id-photos'` added to `$tempFiles`):
  - `sync_id_status: one side only stays NULL`: front set, back empty → `student_id_status($aid) === null`.
  - `sync_id_status: both sides → pending`.
  - `decide_school_id approves`: `decide_school_id($aid, true, '', $front, $back) === 'done'` and status `'approved'`.
  - `replacing a side of an approved ID → pending`: `replace_id_photo()` then `sync_id_status()` → `'pending'`.
  - `decide_school_id refuses stale photos`: call with the old front path → `'stale'`, status still `'pending'`.
  - `reject needs a reason`: `decide_school_id($aid, false, '  ', ...) === 'reason_required'`.
  - `reject stores the reason`: `'done'`, status `'rejected'`, `id_review_note === 'Blurry photo'`.
  - `office sees a pending ID`: `act_as` staff, status pending, no loan → `can_view_id_photos($aid) === true`; after approval and no loan → `false` (item 8 rule kept).
  - `delete_id_photos clears the status`.
  The existing `send_mail()` writes to `mail.log` when SMTP is off; set `MAIL` config is not needed for these checks. If SMTP is configured locally, the checks still pass (the transaction rollback does not unsend mail; use andrea, a demo address).
- [ ] **Step 2:** Run `bin\check-borrowing.php`. Expected: new checks FAIL (undefined function).
- [ ] **Step 3:** Implement the functions above. In `profile.php`, call `sync_id_status($uid)` after the upload loop and after `delete_id_photos()`.
- [ ] **Step 4:** Run `bin\check-borrowing.php`. Expected: all PASS. Lint `includes/borrowing.php` and `public/profile.php`.

---

### Task 3: Status words and the portrait upload on My profile

**Files:**
- Modify: `includes/helpers.php` (`status_meta()`, new domain)
- Modify: `public/profile.php:405-459` (School ID section)
- Modify: `public/assets/css/style.css` (next to `.id-photos-*`, ~line 414)

**Interfaces:**
- Consumes: `student_id_status()` and `users.id_review_note` from Task 2.
- Produces: `status_badge($s, 'school_id')` for `pending` (warning, `Waiting for approval`), `approved` (success, `Approved`), `rejected` (danger, `Rejected`).

- [ ] **Step 1:** Add the `school_id` domain to `status_meta()`; add it to the domain list in the `AGENTS.md` frontend rule 3.
- [ ] **Step 2:** Under the section heading in `profile.php`, render the state line, copy verbatim from the spec ("Student side" → My profile): nothing uploaded, one side only, pending (badge + "The office is checking your ID. We will email you."), approved (badge + "Your ID has been approved. You can borrow equipment."), rejected (badge + `alert` with the reason + "Upload a clearer photo.").
- [ ] **Step 3:** Portrait frames: each side is a frame with `aspect-ratio: 54 / 85.6`, image `object-fit: contain`, "Not uploaded" centred in an empty frame, file input under its frame; front and back side by side at every width (two columns also at 390px). Preview `<img>` attributes `width="150" height="238"`. Tokens only.
- [ ] **Step 4:** Lint; run `bin\smoke.php --only=profile`. Expected: no failures. Look at `profile.php#school-id` as andrea at 1366 and 390 in each state (set `id_status` by hand in MySQL, then put it back).

---

### Task 4: Admin queue and decision page

**Files:**
- Modify: `includes/review.php` (`review_counts()`, new `review_pending_ids()`)
- Modify: `public/review.php` (new lane)
- Create: `public/admin/id-review.php`
- Modify: `includes/attention.php` (office item; student item when rejected)
- Modify: `docs/03-role-matrix.md`, `bin/smoke.php` (route + id resolver)
- Test: `bin/check-borrowing.php`, `bin/smoke.php`

**Interfaces:**
- Consumes: `decide_school_id()`, `can_view_id_photos()`.
- Produces: `review_counts()['ids']` (office only, included in `total`); `review_pending_ids(int $limit = 50): array` rows `user_id, first_name, last_name, student_number, id_submitted_at, id_front_path, id_back_path`, ordered `id_submitted_at ASC`; page `admin/id-review.php?user=<id>`.

- [ ] **Step 1: Failing checks:** as staff with andrea pending, `review_counts()['ids'] >= 1` and andrea's id is in `array_column(review_pending_ids(), 'user_id')`; as the coordinator, `review_counts()['ids'] === 0`. (`review_counts()` caches statically: run these before any other `review_counts()` call in the script, or check `review_pending_ids()` only for the coordinator.)
- [ ] **Step 2:** Run; expect FAIL. Implement the two functions.
- [ ] **Step 3:** `review.php`: office-only lane `ids` titled `School IDs`, hint "Students uploaded their school ID and cannot borrow until you approve it.", rows with name, student number, `waiting_label($row['id_submitted_at'])`, and a `btn-primary btn-sm` **Review** link to `admin/id-review.php?user=<id>`. Empty state: "No school IDs waiting" + "Students' uploads appear here as soon as both sides are in."
- [ ] **Step 4:** `admin/id-review.php`: `require_role(['staff', 'admin'])`; `get_id('user')`; 404 via `abort_page()` when the user is not a student with `id_status = 'pending'`. Shows name, student number, course, year, both sides large and portrait via `id-photo.php`. Two POST forms, each with `csrf_field()`, hidden `front_seen`/`back_seen` (the paths shown), `data-loading`: **Approve** (`btn-primary`) and **Reject** with a required `reason` textarea (`maxlength="500"`, `btn-outline`). Handler: `csrf_verify()`, `decide_school_id()`, flash per result (`'stale'`: "The student changed their photo or someone already decided. Nothing was saved."), redirect to `review.php?lane=ids`.
- [ ] **Step 5:** `attention.php`: office item `School IDs to check` (same shape as "Accounts waiting for approval", `href` `review.php?lane=ids`); for a student whose `student_id_status()` is `rejected`, item `Your school ID needs a new photo` linking `profile.php#school-id`.
- [ ] **Step 6:** Role matrix: add "Approve or reject a student's school ID" (staff ✔, admin ✔, others ✘); change "View a student's ID photos" staff/admin cell to add "or while their ID is waiting for approval". Smoke: add `['path' => 'admin/id-review.php', 'allow' => ['admin', 'staff'], 'params' => ['user' => 'pending_id_user'], 'optional' => true]` and resolve `$ids['pending_id_user']` from `SELECT user_id FROM users WHERE id_status = 'pending' LIMIT 1`.
- [ ] **Step 7:** Run `bin\check-borrowing.php` and `bin\smoke.php`. Expected: all PASS except the 4 known `highlights/file.php` failures (test PDF lives only in the highlights worktree).

---

### Task 5: No approved ID, no borrowing

**Files:**
- Modify: `includes/borrowing.php` (new `reservation_needs_id()`)
- Modify: `public/inventory/reservations.php` (approve refusal, flag in list)
- Modify: `public/review.php` (flag in reservations lane)
- Modify: `public/inventory/borrowings.php` (handover refusal)
- Test: `bin/check-borrowing.php`

**Interfaces:**
- Consumes: `student_id_status()`.
- Produces: `reservation_needs_id(array $reservation): bool`: true when `student_request = 1` and the requester's `id_status` is not `'approved'`. Task 7 also uses `student_id_status() === 'approved'` to gate sending.

- [ ] **Step 1: Failing checks:** a pending student request by andrea with `id_status = 'pending'` → `reservation_needs_id($row) === true`; after approval → `false`; an office reservation (`student_request = 0`) → `false`.
- [ ] **Step 2:** Run; expect FAIL; implement.
- [ ] **Step 3:** `reservations.php` `case 'approve'`: before the conflict check, `if (reservation_needs_id($reservation))` flash "This student's school ID has not been approved yet." and `break` (stays pending). Reject and cancel unchanged. In the list and in the `review.php` reservations lane, show a `badge-warning` "ID not approved yet" next to such student requests.
- [ ] **Step 4:** `borrowings.php`: when releasing against a linked reservation, load `student_request, requested_by`; if `reservation_needs_id()`, flash "This student's school ID has not been approved yet, so the items cannot be released." and redirect back.
- [ ] **Step 5:** Run checks; lint; smoke `--only=inventory`.

---

### Task 6: Requests with no activity (item 13)

**Files:**
- Modify: `includes/borrowing.php` (`can_decide_reservation()`, `can_view_id_photos()`, new `borrow_reviewer_ids()`)
- Modify: `includes/review.php` (`review_reservation_scope()`)
- Modify: `public/inventory/reservations.php` (coordinator list filter, label)
- Modify: `public/review.php` (label)
- Modify: `docs/03-role-matrix.md` (three inventory rows)
- Test: `bin/check-borrowing.php`

**Interfaces:**
- Produces: `borrow_reviewer_ids(?int $activityId): array<int,int>`: activity → `activity_reviewer_ids($activityId)`; `null` → every active coordinator, or the office (`role IN ('staff','admin')`, active) when there is none. Task 7 calls it after saving a request.

- [ ] **Step 1: Failing checks** (insert a pending student request by andrea with `activity_id = NULL`, `purpose = 'PE class'`):
  - `any coordinator may decide a no-activity request`: as the coordinator, `can_decide_reservation($row) === true`.
  - `coordinator still cannot decide another activity's request`: existing activity-16 check keeps passing.
  - `coordinator sees pending no-activity request in scope`: the row's id is in `review_pending_reservations()`.
  - `coordinator sees ID for a pending no-activity request`: `can_view_id_photos($aid) === true`.
  - `borrow_reviewer_ids(null) lists coordinators`: contains the coordinator's user id.
- [ ] **Step 2:** Run; expect FAIL; implement. `review_reservation_scope()` for a coordinator becomes `' AND rv.student_request = 1 AND (rv.activity_id IS NULL OR rv.activity_id IN (SELECT ac.activity_id FROM activity_coordinators ac WHERE ac.user_id = ?))'`.
- [ ] **Step 3:** `reservations.php` coordinator filter: own requests, OR student requests for their activities, OR (`activity_id IS NULL` AND `student_request = 1` AND (`status = 'pending'` OR `reviewed_by = ?`)). Add a check: a no-activity request decided by another user is not in that coordinator's list (query the page's SQL through a small helper if needed, or assert on the WHERE built by a function `reservation_list_scope(): array{0:string,1:array}` moved into `includes/borrowing.php`).
- [ ] **Step 4:** Wherever a reservation's activity is shown (`reservations.php` list, `review.php` lane, the student's own list), show `Not for an activity` plus `e($row['purpose'])` when `activity_id` is null.
- [ ] **Step 5:** Role matrix rows: "Request a reservation" student cell → "◐ for an approved activity or another stated purpose; school ID approved"; "Approve / reject reservation" coordinator → "◐ student requests for assigned activities, and any student request with no activity"; "View loans from student requests" coordinator → "◐ assigned activities, plus pending no-activity requests and those they decided".
- [ ] **Step 6:** Run checks, lint, smoke.

---

### Task 7: Pick on the catalogue, borrow in a popup (item 14, with item 13's choice)

**Files:**
- Modify: `public/inventory/index.php` (student pick form around the grid, copy)
- Modify: `public/inventory/borrow.php` (becomes the popup fragment `#borrow-form-wrap` and its POST handler)
- Modify: `public/assets/js/app.js` (new IIFE after the shared popup block)
- Modify: `public/assets/css/style.css` (stepper, picked card, bottom bar)
- Modify: `includes/layout/header.php:47` (sidebar highlight), `public/inventory/reservations.php` (links)

**Interfaces:**
- Consumes: `student_id_status()`, `student_borrow_activities()`, `reservation_conflicts()`, `borrow_reviewer_ids()`.
- Produces: `borrow.php?items=<id>:<qty>,<id>:<qty>` (GET) renders the popup fragment; POST fields `quantity[<item_id>]`, `for` (`activity` | `other`), `activity_id`, `purpose`, `needed_from`, `needed_until`.

- [ ] **Step 1: Server side first.** `borrow.php`:
  - Keep `require_role(['student'])`. `$canRequest` = `student_id_status($studentId) === 'approved'` (no activity requirement any more).
  - Parse `items` (GET) or `quantity[]` (POST) into lines; every id must be in the requestable list (same query as today) and every quantity a positive integer, else `abort_page(403, 'That item cannot be requested.')`.
  - `for = activity`: `activity_id` must be one of `student_borrow_activities()`, else 403 as today. `for = other`: `purpose` required, at most 500 characters; `activity_id` saved as `NULL`.
  - Dates, conflicts, insert, audit (`'Student requested N item(s) for activity X'` or `'... with no activity'`) as today; notify `borrow_reviewer_ids($activityId)`; flash "Request sent. Waiting for approval."; redirect `inventory/reservations.php`.
  - Markup: everything the popup needs inside `<div id="borrow-form-wrap">`: chosen items with quantities (hidden `quantity[...]` inputs), the "what for" radios (activity select shown only if the student has approved activities; "Something else" with a `purpose` input), the two date fields, errors and per-item shortfalls, **Send request** (`btn-primary`, `data-loading`). When the ID is not approved, the wrap shows the ID state (`status_badge(..., 'school_id')` or "Not uploaded") and a link to `profile.php#school-id` instead of the form. With no `items`, the wrap says "Choose items on Costumes & equipment first." with a link back.
  - Copy: replace "for an activity you're in" texts per spec item 13.
- [ ] **Step 2:** Smoke `--only=inventory` (student 200 on `borrow.php`; others 403) and `bin\check-borrowing.php`. Add a check: `student_borrow_activities()` is no longer a precondition: a POST is not tested here; instead assert `borrow_reviewer_ids(null)` (Task 6) and leave the page flow to Step 6's manual run.
- [ ] **Step 3: Catalogue markup.** For students only, wrap the `.catalog` list in `<form method="get" action="<?= url('inventory/borrow.php') ?>" data-borrow-pick>`; each requestable, in-service card gets `<input type="number" name="pick[<id>]" min="0" max="<owned>" value="0" class="pick-qty" aria-label="How many <name>">` in a `catalog-foot`; non-requestable or out-of-service cards get none. A fixed bottom bar (`.pick-bar`, hidden until something is picked) with `<span data-pick-count>` and a submit/link **Borrow these** carrying `data-modal data-modal-title="Borrow equipment" data-modal-wrap="#borrow-form-wrap"`. Remove the header `Borrow equipment` gold button for students; the intro becomes "Tap an item to choose it, then press Borrow these. Your coordinator or the office approves the request." The page keeps one gold button rule (students now have none; that is fine).
- [ ] **Step 4: JS** (new IIFE in `app.js`, armed only when `[data-borrow-pick]` exists):
  - State: `sessionStorage['casdevu-picks']` = JSON `{ "<item_id>": qty }`.
  - Tapping a card (not its stepper) toggles qty 0↔1; − / + buttons replace the number input (keep the input, visually hidden, as the source of truth for no-JS).
  - After every live-search swap of `[data-live-region="results"]` and on load, re-apply stored picks to the cards on screen (listen for the event or mutation the live-search code already fires; read `app.js` around `data-live-search`).
  - Bar shows the total of picked lines across all pages; its link `href` is rebuilt as `borrow.php?items=id:qty,...` from storage before the shared popup reads it (the popup reads `link.href` on click).
  - Clear storage when the popup's request succeeds (the response redirects to `reservations.php`: clear on load of any page that is not the catalogue when a flash success "Request sent" is present, or simpler: clear in the submit handler just before the popup posts; a failed post re-renders and the picks are rebuilt from the hidden inputs).
- [ ] **Step 5: CSS:** picked card outline in the gold token, stepper sized for touch (≥ 44px), `.pick-bar` fixed bottom with page padding so the last card row stays visible, popup content fits 390px.
- [ ] **Step 6: Manual run** at 1366 and 390 as andrea with her ID set to `approved` in MySQL: pick 2 items, search for a third, pick it, change page, open the popup: all three listed; send with "Something else: PE class"; it appears on My borrow requests as `Not for an activity`; tamper `items=999:1` in the popup URL → 403. Then as the coordinator, see it in Review. Put andrea's ID columns back afterwards.
- [ ] **Step 7:** Sidebar: highlight `borrow-requests` only for `reservations.php`; the old "Borrow equipment" buttons on `reservations.php` point to `inventory/index.php` with the label "Choose equipment". Lint all touched files; full smoke.

---

### Task 8: Withdraw after approval (item 12)

**Files:**
- Modify: `includes/participation.php` (new `can_withdraw_registration()`; `withdraw_registration()` notifies)
- Modify: `public/participation/register.php:33-55`
- Modify: `public/activities/view.php:~314`, `public/participation/my-activities.php`
- Modify: `includes/review.php` (documents of withdrawn registrations leave the queue)
- Modify: `docs/03-role-matrix.md:40`
- Test: `bin/check-borrowing.php` (new section "withdraw")

**Interfaces:**
- Produces: `can_withdraw_registration(string $registrationStatus, string $activityStatus): bool` → true only for registration `pending|approved` and activity not `completed|cancelled`.

- [ ] **Step 1: Failing checks:** `can_withdraw_registration('approved', 'upcoming') === true`; `('pending', 'ongoing') === true`; `('approved', 'completed') === false`; `('approved', 'cancelled') === false`; `('rejected', 'upcoming') === false`; `('withdrawn', 'upcoming') === false`. Plus: after withdrawing an approved registration that has a pending document, `review_pending_documents()` (as staff) no longer lists that document.
- [ ] **Step 2:** Run; expect FAIL; implement. `withdraw_registration()`: when the registration was `approved`, `notify_many(activity_reviewer_ids($activityId), 'registration', 'A student withdrew', '<Full name> withdrew from <title>.', url('participation/participants.php?activity_id=' . $activityId))`. Review queries for documents and their count add `AND r.status <> 'withdrawn'` via the registration join.
- [ ] **Step 3:** `register.php`: replace the pending-only rule with `can_withdraw_registration()`; refusal message "You can no longer withdraw from this activity."; update the comment above it.
- [ ] **Step 4:** Show the withdraw form (existing markup: `csrf_field()`, confirm text "Withdraw from this activity? Your place will be given up.") on `view.php` and on each qualifying row of `my-activities.php`, both gated by `can_withdraw_registration()`. `btn-outline`.
- [ ] **Step 5:** Role matrix row 40 student cell → "◐ while the activity is not completed or cancelled". Run checks, lint, smoke (`register.php` stays student-only).

---

### Task 9: Backlog, full verification, live bundle

**Files:**
- Modify: `docs/feature-backlog.md` (items 9a, 10 to 14)
- Create (outside the repo): `C:\Users\johnc\Downloads\casdevu-deploy-v2\update-2026-10-26-items-10-14.zip`, `C:\Users\johnc\Downloads\casdevu-deploy-v2\sql\05-school-id-approval.sql`

- [ ] **Step 1:** Backlog: 9a → live (verified in a browser 26 Oct 2026; remove the "Upload to the live site" open task); 10 to 14 → built, with one line each and the spec paths.
- [ ] **Step 2: Full gate.** `C:\xampp\php\php.exe -l` on every changed PHP file; `bin\check-setup.php`; `bin\check-borrowing.php` (0 FAIL); `bin\smoke.php` (only the 4 known highlights failures). Screens at 1366 and 390: profile School ID (each state), review lane, `admin/id-review.php`, catalogue with picks, borrow popup, My borrow requests, My activities, activity page.
- [ ] **Step 3: Bundle.** List `git diff --name-only 9a59006` plus untracked files under `includes/` and `public/`; zip exactly those, at their paths, with the commit/tree note as the zip comment. Copy `database/update-school-id-approval.sql` to `sql\05-school-id-approval.sql`. Unzip into a temp folder and `diff -rq` against the working tree for those paths (no differences).
- [ ] **Step 4:** Tell the user, in order: run the SQL in phpMyAdmin (select `if0_43269308_casms` first), then File Manager → `/htdocs` → Upload & Unzip. Ask whether to commit and open a PR.
