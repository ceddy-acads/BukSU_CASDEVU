# Student Equipment Borrowing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a student request office equipment for an activity they are approved in. The request carries a proposed return date. The activity's coordinator approves it, keeping or changing that date, and the office hands the items over after checking the student's ID photos.

**Architecture:**
- **Reuse `reservations`/`reservation_items`.** A student request is a reservation flagged `student_request = 1`, so the conflict checks, the borrowing link and the `fulfilled` status work unchanged.
- **One home for the new rules.** Who may decide, cancel or see ID photos lives in a new `includes/borrowing.php`, and pages call it.
- **ID photos are private files.** They sit on `users`, are stored with `store_upload()`, and are served only by a new `public/id-photo.php`.

**Tech Stack:** Native PHP 8.1+, MySQL/MariaDB, plain HTML/CSS/JS. No framework, no Composer, no build step (`AGENTS.md`).

**Spec:** `docs/superpowers/specs/2026-10-24-student-equipment-borrowing-design.md` (approved, revision 2, office ID access option B). Read it alongside this plan.

## Global Constraints

- Every page: `require_once __DIR__ . '/../includes/bootstrap.php';`, then `require_login()`/`require_role()` before output; activity pages also call `require_activity_access()`.
- Every POST form has `<?= csrf_field() ?>`, and every handler calls `csrf_verify()` first. Every echo goes through `e()`. Queries are bound and never concatenated.
- Uploads go through `store_upload()`. Stop a request with `abort_page($code, $message)`, never a bare `exit()`.
- Status words go through `status_badge($status, 'reservation')`. One `btn-gold` per page. Form submits use `btn-primary`; all other buttons use `btn-outline`.
- At 390px wide, no page scrolls sideways.
- "The office" means `is_office_staff()` (staff and admin today; item 7 may change that later).
- Lint every touched file: `C:\xampp\php\php.exe -l <file>`. The regression gate is `C:\xampp\php\php.exe bin\smoke.php`.
- Never reload `database/schema.sql` or `demo-data.sql` into the shared `casms` database during this build. Apply only `database/update-student-borrowing.sql`, because other features may be building against the same database in parallel.

## Review Focus

1. **A student who replaces their ID photos while a request is pending.** The old files are deleted from disk, and the coordinator then sees the new photos. Pinned in Task 2.
2. **A coordinator assigned to two activities opening a student's ID** when that student's pending request belongs to an activity the coordinator does not run. This must be refused. Pinned in Task 1.
3. **A coordinator moving the return date to before the start date, or into a window with no stock.** Approval is refused with the reason, and nothing changes. Pinned in Task 5.
4. **A student cancelling after the items were partly released.** Cancelling is refused, because some items are out. Pinned in Task 1.
5. **A student whose only approved registration is withdrawn while a request is pending.** The request becomes `cancelled`, with a notice. Pinned in Task 7.

---

### Task 0: Branch, database columns and the self-check harness

**Files:**
- Create: `database/update-student-borrowing.sql`
- Modify: `database/schema.sql` (the `reservations`, `inventory_items` and `users` tables)
- Create: `bin/check-borrowing.php`

**Interfaces:**
- Produces these columns:
  - `reservations.student_request TINYINT(1) NOT NULL DEFAULT 0`
  - `reservations.proposed_until DATETIME NULL`
  - `inventory_items.student_requestable TINYINT(1) NOT NULL DEFAULT 1`
  - `users.id_front_path VARCHAR(255) NULL`
  - `users.id_back_path VARCHAR(255) NULL`
- Produces `bin/check-borrowing.php`. It runs every check inside one transaction that is always rolled back, prints `PASS name` / `FAIL name: why`, and exits 1 on any failure. It has a helper `check(string $name, bool $ok, string $why = ''): void` and fixtures built from the demo data: the student `andrea.santos@student.buksu.edu.ph`, the coordinator `bautista.coach@buksu.edu.ph` (assigned to activity 15 and activity 17), and an activity the coordinator does not run (16).

- [ ] **Step 1:** `git switch -c feature/student-borrowing`
- [ ] **Step 2:** Write `update-student-borrowing.sql`. Use `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (MariaDB), so it can be run twice. Mirror the same columns in `schema.sql`.
- [ ] **Step 3:** Apply it: `C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-student-borrowing.sql`. Then `SHOW COLUMNS FROM reservations LIKE 'student_request'` returns 1 row.
- [ ] **Step 4:** Write `bin/check-borrowing.php` with the harness and one smoke check: `check('db columns', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_front_path'"))`. Run `C:\xampp\php\php.exe bin\check-borrowing.php`. Expected: `PASS db columns`, exit 0.
- [ ] **Step 5:** Commit: `git add database bin/check-borrowing.php && git commit -m "Add columns for student equipment requests and ID photos"`

### Task 1: The rules (`includes/borrowing.php`)

**Files:**
- Create: `includes/borrowing.php`, required from `includes/bootstrap.php` after `inventory.php`
- Test: `bin/check-borrowing.php`

**Interfaces:**
- Produces:
  - `student_borrow_activities(int $studentId): array`: rows `{activity_id, title, start_at}`. The student's registration is `approved`, and the activity status is `upcoming` or `ongoing`.
  - `has_id_photos(array $user): bool`: both paths are non-empty.
  - `reservation_collected_quantity(int $reservationId): int`: the sum of `borrowings.quantity` for that reservation.
  - `student_has_open_borrowing(int $studentId): bool`: true if the student has any of:
    - a student request that is `pending`;
    - one that is `approved` with nothing collected;
    - a loan with `returned_at IS NULL`.

    Blocks deleting the ID photos.
  - `office_may_view_id(int $studentId): bool` (option B): an `approved` student request not fully collected, or any loan to the student with `returned_at IS NULL`.
  - `can_view_id_photos(int $studentId): bool`: true for:
    - the student themself;
    - the office, when `office_may_view_id()`;
    - a coordinator, when a `pending` student request by that student exists for an activity in `activity_coordinators` for the coordinator.

    False for everyone else.
  - `can_decide_reservation(array $reservation): bool`: the office, always. A coordinator only when `student_request = 1` and the coordinator is assigned to `activity_id`.
  - `can_cancel_reservation(array $reservation): bool`:
    - the requester or the office;
    - when `status = 'pending'`, or `status = 'approved'` and `reservation_collected_quantity() === 0`.
  - `cancel_open_student_requests(int $studentId, ?int $activityId, string $reason): int`:
    - sets `cancelled` on that student's `pending` requests and on `approved` ones with nothing collected (limited to `$activityId` when it is given);
    - calls `refresh_item_status()` for each item and `notify()`s the student with `$reason`;
    - returns how many it cancelled.
  - `delete_id_photos(int $studentId): void`: `delete_upload()` on both files, then sets both columns to NULL.

- [ ] **Step 1: Write the failing checks** in `bin/check-borrowing.php`. Insert fixture rows inside the transaction, then check:
  - `can_view_id_photos` is **true** for the coordinator while Andrea has a pending request for activity 15.
  - It is **false** for the same coordinator when Andrea's only pending request is for activity 16 (Review Focus 2).
  - It is **false** for the coordinator once the activity 15 request is `approved`.
  - It is **true** for staff once the request is `approved` with nothing collected.
  - It is **false** for staff after the loan is returned and nothing else is open.
  - It is **false** for another student.
  - `can_cancel_reservation` is **false** once one borrowing row exists for an approved request (Review Focus 4).
  - `can_decide_reservation` is **false** for the coordinator on a request with `student_request = 0`.
  - `cancel_open_student_requests(andrea, 15, 'x')` returns 2 with one pending and one approved-uncollected request, and leaves a `fulfilled` one alone.

  Log in as a role inside the script by setting `$_SESSION['user_id']`/`role` the way `includes/auth.php` reads them.
- [ ] **Step 2:** Run it. Expected: FAIL, because the functions are not defined.
- [ ] **Step 3:** Implement the functions above in `includes/borrowing.php`, with bound queries only.
- [ ] **Step 4:** Run `bin/check-borrowing.php`. Expected: all PASS, exit 0. Then `php -l includes/borrowing.php`.
- [ ] **Step 5:** Commit: `Add the borrowing rules: who decides, cancels and sees ID photos`

### Task 2: ID photos (upload, replace, delete, serve)

**Files:**
- Modify: `includes/uploads.php`. Add `store_id_photo(array $file): array`. Same shape as `store_profile_photo()`: subdirectory `id-photos`, JPG/PNG only, JPEG metadata stripped, the existing 5 MB `max_upload_bytes()`.
- Modify: `public/profile.php`. For students only, add a "School ID for borrowing" panel with front and back.
- Create: `public/id-photo.php`
- Test: `bin/check-borrowing.php`, `bin/smoke.php`

**Interfaces:**
- Consumes: `can_view_id_photos()`, `student_has_open_borrowing()`, `delete_id_photos()` (Task 1).
- Produces:
  - **The photo route.** `id-photo.php?user=<id>&side=front|back` returns the image with `Cache-Control: private, no-store`. Anything not allowed, or missing, gets `abort_page(404, …)`, so the reply does not reveal whether a photo exists.
  - **The profile actions.** `profile.php` takes `action=id_photo_upload` (fields `id_front`, `id_back`; either one may come alone, so a student can replace one side) and `action=id_photo_delete`.

- [ ] **Step 1: Write the failing checks**:
  - Replacing a photo while a request is pending removes the old file from disk (Review Focus 1). Call the replace path through a helper `replace_id_photo(int $studentId, string $side, string $newRelativePath): void` and assert `is_file()` on the old path is false.
  - `id-photo.php` returns 404 to another student. Add this to the smoke table in Task 8.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement:
  - `store_id_photo()`;
  - `replace_id_photo()` in `includes/borrowing.php`, which deletes the old file after saving the new path;
  - the profile panel and actions. Delete is refused while `student_has_open_borrowing()`, with the message "You can't remove your ID while you have a borrow request or borrowed items. You can still replace it.";
  - `id-photo.php`, using `readfile()` after the check.

  Copy for the panel: "Upload both sides of your school ID. Only you, the office while you have borrowed items, and the coordinator deciding your request can see it."
- [ ] **Step 4:** Run `bin/check-borrowing.php` and `php -l` on the touched files. In the browser, upload as Andrea at 390px: no sideways scroll.
- [ ] **Step 5:** Commit: `Let students keep their school ID on file, privately`

### Task 3: Office-only items

**Files:**
- Modify: `public/inventory/manage.php`. Add a checkbox `student_requestable`, labelled "Students may request this item". It is saved on create and on edit, and defaults to checked.
- Modify: `public/inventory/index.php`. For students, items with `student_requestable = 0` show a neutral badge "Office use only" and no request link.

**Interfaces:** Produces the `student_requestable` value used by Task 4.

- [ ] **Step 1:** Implement both edits. Untick one demo item (Portable Speaker, `AV-002`) as staff.
- [ ] **Step 2:** As Andrea, `inventory/index.php` shows "Office use only" on AV-002. As staff, the checkbox round-trips.
- [ ] **Step 3:** Commit: `Let the office mark items as office use only`

### Task 4: The student request form (`public/inventory/borrow.php`)

**Files:**
- Create: `public/inventory/borrow.php`, with `require_role(['student'])`
- Modify: `public/inventory/index.php`. For students, add a `btn-gold` "Borrow equipment" link to this page in the page head.

**Interfaces:**
- Consumes: `student_borrow_activities()`, `has_id_photos()` (Task 1), and `quantity_available_between()`/`reservation_conflicts()` (`includes/inventory.php`).
- Produces: reservation rows with `student_request = 1`, `requested_by` = the student, `needed_from`, `needed_until` = the proposed return, `proposed_until` = the proposed return, and `activity_id` required.

**Behaviour (two steps on one page, GET then POST):**
1. **Step 1 (GET):** activity (from `student_borrow_activities()`), "Needed from" and "Will return by" (`datetime-local`), and purpose.
   - No approved activity: an `.empty` block saying "You can borrow equipment only for an activity you're approved in." with a link to Activities.
   - No ID on file: an `.empty` block saying "Add both sides of your school ID before borrowing." with a link to `profile.php#school-id`.
2. **Step 2 (same page, once the dates are valid):** the items table, limited to `student_requestable = 1` and status not in `damaged/under_maintenance/unavailable`, with a "Free for those dates" column from `quantity_available_between()`. The hint reads "These numbers can change before your coordinator approves."
3. **POST:**
   - Re-validate everything:
     - the activity must be in `student_borrow_activities()`;
     - "Will return by" must be after "Needed from";
     - at least one line;
     - each item must be student-requestable;
     - ID photos on file;
     - `reservation_conflicts()` returns none.
   - Insert the reservation and its lines in one transaction.
   - `notify_many(activity_reviewer_ids($activityId), 'reservation', 'New equipment request', …, url('inventory/reservations.php?status=pending'))`.
   - Audit with `audit_log('create','reservation',…)`.
   - Redirect to `inventory/reservations.php` with the flash "Request sent. Your coordinator will review it."

- [ ] **Step 1:** Implement it as above. Every echo goes through `e()`. Use the "Saving…" state that `app.js` already handles.
- [ ] **Step 2:** As Andrea at 390px, send a request for activity 15. Expected:
  - the coordinator gets a notification;
  - the reservation row has `student_request = 1` and `proposed_until = needed_until`;
  - no sideways scroll.
- [ ] **Step 3:** As Andrea, a POST that tampers the activity id to 16 (not approved) is refused with 403 via `abort_page`. So is an item with `student_requestable = 0`.
- [ ] **Step 4:** Commit: `Let students request equipment for their activities`

### Task 5: Deciding and cancelling (`public/inventory/reservations.php`)

**Files:**
- Modify: `public/inventory/reservations.php`

**Interfaces:**
- Consumes: `can_decide_reservation()`, `can_cancel_reservation()`, `can_view_id_photos()`, `reservation_collected_quantity()` (Task 1).
- Produces: `approve` accepts an optional `return_at` (datetime) for student requests.

**Changes:**
- **Gate:** `require_role(['staff','admin','coordinator','student'])`.
- **Listing scope:**
  - office: everything;
  - coordinator: their own requests plus student requests for activities they are assigned to;
  - student: their own.

  The head text follows the role ("Your equipment requests" for students). The student page has no gold "New request" button; it links to `borrow.php` instead.
- **Approve:**
  - only if `can_decide_reservation()`, otherwise 403;
  - for a student request, read `return_at`, or keep `needed_until` when it is blank. Refuse if `return_at <= needed_from` ("The return date must be after the start."). Run `reservation_conflicts($lines, needed_from, return_at, $id)` and refuse with its message on a clash (Review Focus 3).
  - On success, `UPDATE … needed_until = return_at`, and keep `proposed_until` untouched.
  - If the date changed, write an audit row "Return date changed from X to Y". The student's notification says "Approved. Please return the items by <date>." and, when changed, adds "(your coordinator changed the date you proposed, <X>)".
- **Reject:** the same permission rule. The reason is still required.
- **Cancel:** only if `can_cancel_reservation()`. Cancelling an approved request frees stock via `refresh_item_status()`, as today.
- **Card for a student request**, seen by a decider:
  - "Proposed return: <proposed_until>";
  - a `datetime-local` "Return by" prefilled with `needed_until`, inside the approve form;
  - "View ID (front) / (back)" links to `id-photo.php` when `can_view_id_photos($requested_by)`.
- **Notification links** go to `inventory/reservations.php`, which students can now open. This fixes the 403 noted in the spec.

- [ ] **Step 1:** Implement the changes above.
- [ ] **Step 2:** As the coordinator, approve Andrea's request with "Return by" set before the start. Expected: the error message, and the row stays unchanged. Then set a date one day later than proposed. Expected:
  - status `approved`;
  - `needed_until` is the new date and `proposed_until` is the old one;
  - one audit row;
  - Andrea's notification names both dates.
- [ ] **Step 3:** As the coordinator, a request for activity 16 shows no Approve or Reject, and a forged POST gets 403.
- [ ] **Step 4:** As Andrea, cancel the approved request before collection. Expected: `cancelled`, and the stock is freed.
- [ ] **Step 5:** Commit: `Let coordinators decide student equipment requests and set the return date`

### Task 6: Review queue lane and handover

**Files:**
- Modify: `includes/review.php`:
  - `review_counts()['reservations']` counts pending student requests in scope for a coordinator (office: all pending);
  - `review_pending_reservations()` gets the same scope, and adds `student_request` and `activity_id` to its select.
- Modify: `public/review.php`. Show the Reservations lane to coordinators too, with the copy "Students asked to borrow equipment for your activities." The office keeps its existing copy.
- Modify: `public/inventory/borrowings.php`. With `?reservation_id=` for a student request:
  - prefill the borrower with the requester, and `expected_return_at` with `needed_until`;
  - show "Check the student's ID" with the `id-photo.php` links (they work under option B, because the request is approved and not yet collected).

  On release, if the posted `expected_return_at` differs from the reservation's `needed_until`, write an audit row "Return date changed at handover from X to Y".

**Interfaces:** Consumes `can_view_id_photos()` (Task 1).

- [ ] **Step 1:** Implement the changes above.
- [ ] **Step 2:** As the coordinator, the sidebar badge and the dashboard count include Andrea's pending request, and `review.php?lane=reservations` lists it. As staff, the release form is prefilled, the ID links open the photos, and releasing with a changed date writes the audit row.
- [ ] **Step 3:** Commit: `Show student requests in the coordinator's queue and check ID at handover`

### Task 7: Automatic cancelling and account deactivation

**Files:**
- Modify: `public/participation/register.php` (withdrawal at line 54). After the update, call `cancel_open_student_requests($userId, $activityId, 'Your registration was withdrawn, so your equipment request was cancelled.')`.
- Modify: `public/participation/participants.php` (the reject branches near lines 95 and 119). Same call, with the reason "Your registration was not approved, so your equipment request was cancelled."
- Modify: `public/admin/users.php`, case `deactivate` (line 90). Call `cancel_open_student_requests($targetUserId, null, 'Your account was deactivated.')` and `delete_id_photos($targetUserId)`. `suspend` is unchanged and keeps the photos. `decline` applies only to pending sign-ups; call `delete_id_photos()` there too, for safety.

**Interfaces:** Consumes `cancel_open_student_requests()` and `delete_id_photos()` (Task 1).

- [ ] **Step 1: Write the failing check:** after a simulated withdrawal path (call the same function the page calls), Andrea's pending request for activity 15 is `cancelled` and a notification row exists (Review Focus 5).
- [ ] **Step 2:** Run. Expected: FAIL until the hooks call it. Factor the withdrawal into a function in `includes/participation.php`, `withdraw_registration(int $registrationId): void`, so the check can call it.
- [ ] **Step 3:** Implement the hooks.
- [ ] **Step 4:** Run `bin/check-borrowing.php`. Expected: all PASS. As an admin, deactivate a fixture student who has photos. Expected: the files are gone from `storage/uploads/id-photos/`.
- [ ] **Step 5:** Commit: `Cancel equipment requests when a registration or account ends`

### Task 8: Coordinator loan list, return reminder, docs and the regression gate

**Files:**
- Modify: `public/activities/view.php`. In the Manage area (`$canManage`), add a card "Equipment out for this activity": loans whose reservation is a student request for this activity, with item, student, due date, and an overdue badge via `status_badge('overdue', …)` (add `overdue` to `status_meta()` only if it is missing). Use an `.empty` state when there are none.
- Modify: `includes/reminders.php`. Add `send_return_reminders(bool $dryRun = false): array`. It covers loans where `returned_at IS NULL` and `expected_return_at` falls within the next 24 hours, and the borrower is a student. It sends `notify()` (and email via `notify_and_email()` when enabled), with the de-duplication marker `[loan:<borrowing_id>:due_soon]` in the same way as `reminder_already_sent()`.
- Modify: `bin/send-reminders.php`. Call `send_return_reminders()` after the requirement reminders, and print its count.
- Modify: `includes/layout/header.php`. Students get a nav item "My borrow requests" → `inventory/reservations.php`.
- Modify: `docs/03-role-matrix.md`. Add the rows listed in the spec's Constraints, including option B's wording for the office.
- Modify: `bin/smoke.php`:
  - `inventory/reservations.php` allows `student`;
  - add `inventory/borrow.php` (allow `student`);
  - add `id-photo.php` (allow `student` with `user=own_user&side=front`, `optional`, `binary`; others get 404);
  - add a check that a second student requesting Andrea's photo gets 404.
- Modify: `README.md`. Under the reminders and known-limits section, add a line saying `bin/send-reminders.php` now also sends return reminders and still needs scheduling.

- [ ] **Step 1:** Implement the changes above.
- [ ] **Step 2:** Run `C:\xampp\php\php.exe bin\send-reminders.php` with a loan due tomorrow. Expected: 1 return reminder. Run it again: 0 (de-duplicated).
- [ ] **Step 3:** Run `php -l` on every touched file, then `C:\xampp\php\php.exe bin\smoke.php` and `C:\xampp\php\php.exe bin\check-borrowing.php`. Expected: both green.
- [ ] **Step 4:** Look at `inventory/borrow.php`, `inventory/reservations.php`, the profile ID panel and the activity loan card at 390px and at 1440px. Expected: no sideways scroll, and one gold button per page.
- [ ] **Step 5:** Commit: `Coordinator loan list, return reminders, role matrix and smoke coverage`
