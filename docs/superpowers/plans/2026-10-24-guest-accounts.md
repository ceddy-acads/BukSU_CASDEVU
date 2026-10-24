# Guest Accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new **Guest** role for people outside the student body: they sign up (or an admin creates them), the office activates them, and they then see the schedule (activities, calendar, announcements) and their own profile and settings, and nothing else.

**Architecture:** One new `roles` row (`guest`) and one new column (`users.connection_note`). Every page that today uses bare `require_login()` is checked: schedule pages keep it and grow a guest view; inventory pages switch to an explicit `require_role()` allow-list; `profile-photo.php` serves a guest only their own photo. Every role branch whose `else` meant "the office" is narrowed so a guest falls into no office branch. `bin/smoke.php` gains a guest session and a check that no page a guest opens links to an office or student-data page.

**Tech Stack:** Native PHP 8.1+, MariaDB 10.4 (XAMPP). No framework, no build step.

**Spec:** `C:\xampp\htdocs\BukSU_CASDEVU\docs\superpowers\specs\2026-10-24-guest-accounts-design.md` (not committed; read it by that absolute path). Also read `AGENTS.md` in the worktree before any task.

## Global Constraints

- Work only in the worktree `C:\xampp\htdocs\BukSU_CASDEVU-guests`, branch `feature/guest-accounts`. Never merge into `main`.
- The `casms` database is **shared with other builds**. Never reload `database/schema.sql` or `database/demo-data.sql`. The only SQL you run is `database/update-guest-accounts.sql`, which must be additive: `INSERT IGNORE` for the roles row and demo accounts, `ADD COLUMN IF NOT EXISTS` for the column. Mirror every change in `schema.sql` and `demo-data.sql` for clean installs.
- Serve the worktree from the worktree root: `C:\xampp\php\php.exe -S 127.0.0.1:8081 -t public`. Gate: `C:\xampp\php\php.exe bin\smoke.php --base=http://127.0.0.1:8081`.
- Item 7 (removing the staff role) is undecided. "The office" is `is_office_staff()`; never write `has_role('staff', 'admin')` anew and never let `else` mean "the office".
- Allow-lists, never "everyone except students": a rule that names the roles it lets in (`['student', 'coordinator', 'staff', 'admin']`), so a role added later is refused by default.
- The role name is `guest`; the word shown everywhere is **Guest** (`ucfirst()`, as every other role is shown today).
- `AGENTS.md` security rules 1-10 hold: gate before output, `csrf_field()` / `csrf_verify()`, `e()` on every echo, bound parameters, `abort_page()` not `exit()` (the two photo scripts keep their existing bare 404 + `exit`, which is the house pattern there).
- Copy is plain and official, matching the existing pages. One `btn-gold` per page at most; form submits `btn-primary`; everything else `btn-outline`. Every data-changing form gets `data-loading`.
- Lint every file you touch: `C:\xampp\php\php.exe -l <file>`.
- Before adding any function, grep its name across `includes/` and `public/` (duplicate names are a fatal error).

## Review Focus

1. **A guest types an office URL** (`review.php`, `admin/users.php`, `reports/students.php`, `participation/participants.php?activity_id=…`, `requirements/download.php?submission_id=…`): 403, never 200. Pinned by the guest column in `bin/smoke.php` (Task 1).
2. **A guest on a page they may open sees no link into office or student-data pages** (dashboard stat tiles, Manage box, inventory link). Pinned by the smoke link scan (Task 1).
3. **A guest fetching another user's photo by id:** 404, same as a missing photo. Pinned by the smoke `photo_user` row (Task 2).
4. **An admin changes a guest's role, or anyone's role to guest, by crafted POST:** refused with a flash, role unchanged. Pinned by the curl check in Task 4.
5. **The student sign-up path is unchanged:** `register.php` with no query string shows the same four-step student form and posts as before. Pinned by the manual check in Task 3.

---

### Task 1: Guest role in the database and in the smoke gate

**Files:**
- Create: `database/update-guest-accounts.sql`
- Modify: `database/schema.sql` (users table ~line 54-75; roles seed ~line 598)
- Modify: `database/demo-data.sql` (after the office personnel block, ~line 43)
- Modify: `includes/auth.php:108-112` (beside `is_office_staff()`)
- Modify: `bin/smoke.php` (options ~line 52-64, page list ~line 410-470, request loop ~line 590-640)

**Interfaces:**
- Produces: role `guest` in `roles`; column `users.connection_note VARCHAR(500) NULL` (after `section`); `is_guest(): bool` in `includes/auth.php`; demo accounts `reyes.guest@example.com` (active) and `lim.guest@example.com` (pending), password `demo1234`; smoke option `--guest=EMAIL:PASS`.

- [ ] **Step 1: Write `database/update-guest-accounts.sql`.** Header comment in the style of `update-user-preferences.sql`. Contents:
  - `SET NAMES utf8mb4; USE casms;`
  - `INSERT IGNORE INTO roles (name, description) VALUES ('guest', 'Outside the student body: views the schedule and announcements');`
  - `ALTER TABLE users ADD COLUMN IF NOT EXISTS connection_note VARCHAR(500) NULL AFTER section;` with a comment: guests only, their answer to "How are you connected to the unit's activities?".
  - Two `INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, contact_number, connection_note, status) SELECT … FROM roles WHERE name = 'guest'` rows, using the demo hash `$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO` (demo1234):
    - `reyes.guest@example.com`, Maricel Reyes, `0917-555-0201`, `Parent of Andrea Santos, who dances with the university troupe.`, `active`
    - `lim.guest@example.com`, Joel Lim, `0917-555-0202`, `Volunteer coach of the Malaybalay youth chess club; we join the open tournaments.`, `pending`
- [ ] **Step 2: Mirror it.** In `schema.sql`: the column in `CREATE TABLE users` after `section` (same comment), `guest` in the roles comment on `name`, and the `('guest', …)` row in the roles seed. In `demo-data.sql`: the two guest accounts in a new `-- GUESTS (outside the student body)` block after the office personnel block, same `INSERT IGNORE … SELECT` shape.
- [ ] **Step 3: Apply to the shared DB.** Run: `C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-guest-accounts.sql`, then run it a second time. Expected: no error either time; `SELECT name FROM roles` lists `guest`; `SELECT email, status FROM users WHERE email LIKE '%.guest@example.com'` returns the two rows once each.
- [ ] **Step 4: Add `is_guest(): bool`** under `is_office_staff()` in `includes/auth.php`, doc comment `/** Guests: people outside the student body, schedule only. */`, body `return has_role('guest');`.
- [ ] **Step 5: Teach smoke the guest.** In `bin/smoke.php`:
  - `$defaults['guest'] = 'reyes.guest@example.com:demo1234'`, `$roles[] = 'guest'`, and `--guest=` in both help texts.
  - Add `'guest'` to `allow` for exactly: `index.php`, `profile.php`, `settings.php`, `notifications.php`, `profile-photo.php` (own_user), `activities/index.php`, `activities/view.php`, `activities/calendar.php`, `announcements/index.php`, and the four public pages (`login.php`, `register.php`, `forgot-password.php`, `reset-password.php`). Every other row stays as it is, so the guest is expected to be refused there.
  - **Link scan:** in the request loop, after the existing `$problem` checks and only when `$problem === null && $role === 'guest' && $result['status'] === 200 && empty($page['binary'])`, fail if the body matches `#href="[^"]*/(review\.php|admin/|reports/|inventory/|requirements/|participation/|activities/(manage|coordinators|eligibility)\.php)#`. Message: `links to <matched path>, which a guest may not open`. One-line comment above it: the spec's "no path, by link, from a Guest account to student data or the office's pages".
- [ ] **Step 6: Run the gate to see it red.** Start the server (Global Constraints), then run the gate. Expected: guest signs in; FAIL lines for `inventory/index.php as guest` (HTTP 200), `inventory/photo.php as guest` (200 or tolerated 404), and `index.php as guest` (links to `review.php`). All other roles unchanged and passing.
- [ ] **Step 7: Lint** `includes/auth.php`, `bin/smoke.php`. **Commit:** `Add the Guest role, its demo accounts, and a guest column in the smoke gate`

### Task 2: Gates on the pages any signed-in user could open

**Files:**
- Modify: `public/inventory/index.php:10`, `public/inventory/photo.php:11`
- Modify: `public/profile-photo.php:14-15`
- Modify: `public/requirements/download.php` (read only; confirm)
- Modify: `bin/smoke.php` (ids block ~line 175-210, page list)

**Interfaces:**
- Consumes: `is_guest()` (Task 1).

- [ ] **Step 1: Failing check for other people's photos.** In smoke's ids block: `$ids['photo_user']` = `SELECT user_id FROM users WHERE photo_path IS NOT NULL AND email <> ? ORDER BY user_id LIMIT 1` with the guest's email, falling back to `$studentUserId`. Add a page row: `profile-photo.php`, `allow` = admin, staff, coordinator, student, `params` = `['id' => 'photo_user']`, `optional` and `binary` true, comment `// another person's photo: a guest gets the same 404 as a missing one`. Run `--only=photo`. Expected: FAIL for guest when that user has a photo (200); if no user has a photo, note it in the report and continue.
- [ ] **Step 2: Inventory allow-list.** Replace `require_login()` with `require_role(['student', 'coordinator', 'staff', 'admin']);` in both inventory files. Update `inventory/index.php`'s doc line "Readable by any signed-in user" to "Readable by students, coordinators and the office; not by guests".
- [ ] **Step 3: Guest sees only their own photo.** In `profile-photo.php`, right after `$userId = get_id('id');`: if `is_guest() && $userId !== current_user_id()`, answer exactly like a missing photo (`http_response_code(404); exit;`). Add one sentence to the file's doc comment saying so.
- [ ] **Step 4: Confirm `requirements/download.php`.** A guest is neither the owner of a submission (they have no registrations) nor passes `can_manage_activity()`, so it already answers 403. No code change; the smoke row (guest not in `allow`) pins it.
- [ ] **Step 5:** Run the gate. Expected: the inventory and photo failures from Task 1 are gone; only `index.php as guest` remains (Task 3 fixes it). **Lint**, **commit:** `Keep guests out of the inventory and other people's photos`

### Task 3: Guest views of the shared pages, and the guest sign-up path

**Files:**
- Modify: `public/index.php:42-78, 96-105, 111-124, 130, 196, 210`
- Modify: `public/settings.php:20-21, 103-105, 120, ~135-170` (the section nav and the Notifications card)
- Modify: `public/profile.php:13-90, 132, 270-279`
- Modify: `includes/attention.php:61`, `includes/review.php:157`, `public/review.php:46, 251, 257-265`
- Modify: `public/register.php` (whole file)

**Interfaces:**
- Consumes: `is_guest()`, `users.connection_note`.

- [ ] **Step 1: Dashboard (`index.php`).** The stats `else` becomes `elseif (can_review())` (coordinators and the office keep their tiles; a guest gets `$stats = []`). Render the `.stats` block only when `$stats !== []`. The page-head button row: the `elseif ($role === 'student')` "Browse activities" outline button also shows for guests (`in_array($role, ['student', 'guest'], true)`). Replace the three `$role !== 'student'` layout tests with one `$wideRows = !in_array($role, ['student', 'guest'], true);` computed beside `$stats`, so a guest gets the student's two-column layout. Update the file's doc comment: add `guests — "what is on, and when?"`.
- [ ] **Step 2: Settings.** A guest receives no email, so the Notifications card and its `#notifications` link in the section nav are hidden when `is_guest()`; the `notifications` POST action is refused for a guest with `abort_page(403, …)` like the office action. Activity list and Account cards stay.
- [ ] **Step 3: Profile.** Move `$isStudent = current_role() === 'student';` up to just after `$user = current_user();`. In the `details` handler, when `!$isStudent`, force `$courseId = $yearLevelId = $section = ''` so only students can hold academic placement. In the read-only view's `else` branch (Role row), add for guests a `profile-wide` row `<dt>Connection to the unit</dt>` showing `connection_note` through `$show`.
- [ ] **Step 4: Account-approval copy no longer says "student".** `attention.php:61` "student account(s)" → "account(s)". `review.php` accounts lane: lane hint "These students cannot sign in…" → "These people cannot sign in until their account is activated."; empty state "as soon as students register" → "as soon as someone signs up"; column header and `data-label` "Student" → "Name"; the hint under the name shows the student number, or `Guest` when it is empty. `includes/review.php` doc "Student accounts waiting" → "Accounts waiting".
- [ ] **Step 5: Gate green.** Run the gate. Expected: 0 failed, guest included.
- [ ] **Step 6: Sign-up chooser.** `register.php` reads `$as = (is_post() ? post('as') : get('as')) === 'guest' ? 'guest' : 'student';`. Above the errors, a `<nav class="tabs" aria-label="Who is signing up">` with two `.tab` links, `register.php` "I'm a BukSU student" and `register.php?as=guest` "I'm not a BukSU student", `aria-current="page"` on the chosen one. With no query string the student form, its validation and its insert are byte-for-byte the current ones. `$authIntro` for guests: "For parents, coaches and others who follow the unit's activities. The office reviews new accounts before you can sign in."
- [ ] **Step 7: Guest form and handler.** When `$as === 'guest'`, the same `data-steps` form (with `<input type="hidden" name="as" value="guest">`) has three steps: **About you** (first name, last name required; email required; contact number required), **Your connection** (`<textarea id="connection_note" name="connection_note" maxlength="500" required>` labelled "How are you connected to the unit's activities?" with hint "For example: parent of a student in the dance troupe, or coach of a visiting team. The office reads this before activating your account."), **Password** (the existing password block). Server, in the guest branch of the POST handler: same name/email/password checks as the student branch; contact number and connection note required, note at most 500 characters (`mb_strlen`); uniqueness on email only (`SELECT 1 FROM users WHERE email = ?`, message "An account with this email address already exists. Sign in instead, or reset your password if you have forgotten it."); insert with role `guest`, `status = 'pending'`, `connection_note`, no academic fields; `audit_log('create', 'user', $id, 'Guest self-registered: ' . $email)`; notify the same office recipients as students (`r.name IN ('staff','admin')` → keep the existing query, it is the office list) with title "New guest sign-up" and message "<First Last> is awaiting account approval as a guest."; same success flash and redirect. Update the file doc comment: students and guests.
- [ ] **Step 8: Manual check of both paths.** In a browser on `http://127.0.0.1:8081/register.php`: the student tab is selected and the form is the four steps as before; submit a student sign-up with a throwaway email (`smoke.student+<time>@student.buksu.edu.ph`) and see the success flash. On `?as=guest`, submit a guest sign-up (`smoke.guest+<time>@example.com`) and see the success flash; `SELECT r.name, u.status, u.connection_note FROM users u JOIN roles r USING (role_id) WHERE u.email = …` shows `guest`, `pending`, the note. Leave these rows; they are pending test accounts.
- [ ] **Step 9:** Gate green. **Lint** every file touched. **Commit:** `Give guests the schedule views, and a sign-up path of their own`

### Task 4: Guests on the user list

**Files:**
- Modify: `public/admin/users.php`
- Modify: `includes/helpers.php` (new `temporary_password()`)

**Interfaces:**
- Consumes: `is_guest()` is not used here (this is the office's page); role name `'guest'`, `users.connection_note`.
- Produces: `temporary_password(): string` in `includes/helpers.php` (12 characters from the existing unambiguous alphabet), used by `reset_password` and `add_guest`.

- [ ] **Step 1: Show the connection note.** The "Student" column becomes "Details" (header and `data-label`). For a `guest` row it shows `Guest` and, under it, `connection_note` in a `hint preline` div (full text, escaped), so the office reads it before activating. Other rows unchanged.
- [ ] **Step 2: No role changes to or from Guest.** UI: a guest row shows the role as plain text (no select), and the `guest` role is left out of the role `<select>` options on every other row (the filter dropdown keeps it). Server, in `case 'role'` after `$newRole` is known: if `$target['role_name'] === 'guest' || $newRole === 'guest'`, `flash('error', 'Guest accounts keep their role. If someone signed up on the wrong path, decline the account and ask them to sign up again.')` and `break`.
- [ ] **Step 3: Curl check of the refusal.** Signed in as admin over HTTP (copy `sign_in()`'s steps from `bin/smoke.php`, or a browser devtools POST), POST `action=role&user_id=<reyes guest id>&role_id=<student role id>` with a valid token, then POST `action=role&user_id=<a demo student id>&role_id=<guest role id>`. Expected: both redirect with the error flash; `SELECT` shows both roles unchanged.
- [ ] **Step 4: Activation copy.** In `case 'activate'`, a guest's in-app notice and email say they can sign in and follow the unit's schedule, instead of "register for activities". Fetch `role_name` with the email (`$target['role_name']` is already loaded).
- [ ] **Step 5: `temporary_password()`.** Grep the name first. Move the alphabet and loop from `case 'reset_password'` into `includes/helpers.php`; `reset_password` calls it.
- [ ] **Step 6: Add a guest (admin only).** A page-head `btn-outline` link "Add a guest" to `admin/users.php?add=guest`, shown when `$isAdmin`. With `?add=guest` (or after a failed `add_guest` POST), a `card` above the filter bar holds a form: first name, last name, email, contact number, connection note (same labels and limits as Task 3), `<?= csrf_field() ?>`, `action=add_guest`, a `btn-primary` "Create guest account" with `data-loading="Creating…"`, and a `btn-outline` Cancel back to `admin/users.php`. Handle `add_guest` **before** the `$target` lookup (it has no `user_id`): refuse non-admins with `abort_page(403, 'Only an administrator may create an account.')`; validate like Task 3 (email unique); insert role `guest`, `status = 'active'`, `password_hash` of `temporary_password()`; `audit_log('create', 'user', $id, 'Administrator created guest account: ' . $email)`; `flash('success', 'Guest account created for <name>. Temporary password: <temp> — copy it now, it will not be shown again.', $temp)`; redirect to `admin/users.php`. On a validation error re-show the card with the errors in an `alert alert-error` and the typed values kept.
- [ ] **Step 7: Manual check.** As admin: create a guest (`smoke.added+<time>@example.com`), copy the password, sign in as that guest in a private window and land on the guest dashboard. Activate `lim.guest@example.com` from the list after reading its note; then deactivate it again so the demo queue stays as seeded.
- [ ] **Step 8:** Gate green. **Lint.** **Commit:** `Let the office read, activate and create guest accounts`

### Task 5: Documentation

**Files:**
- Modify: `docs/03-role-matrix.md`, `docs/02-database-design.md:75`, `README.md` (roles/accounts mentions, Testing section's smoke description), `AGENTS.md` (accounts table, smoke role list)

- [ ] **Step 1: Role matrix.** Add a **Guest** column (first column, before Student) to every table: ✔ for "View published activities", "Read announcements", "Edit own profile", "Choose own email and list settings" (note: list setting only); ✘ everywhere else, including "View catalog and availability" and "Receive notifications"; `—` where Student has `—`. Below "Activate pending student accounts" (kept as is), add the row "Activate pending Guest accounts" with the same answers, and add a row "Change a role to or from Guest": ✘ for every role. Add a short note under the legend: guests are people outside the student body; activities and calendar are schedule-only for them.
- [ ] **Step 2: Database design.** `users` row: mention `connection_note` (guests only). `README.md` and `AGENTS.md`: add the guest demo account `reyes.guest@example.com` / `demo1234` and "guest" to the smoke role list; README's update-scripts list gains `update-guest-accounts.sql`.
- [ ] **Step 3: Commit:** `Document the Guest role`

### Final: verification (superpowers:verification-before-completion)

- [ ] Lint every PHP file changed on the branch (`git diff --name-only main -- '*.php'`).
- [ ] Gate green with the guest role: `C:\xampp\php\php.exe bin\smoke.php --base=http://127.0.0.1:8081`.
- [ ] Screens at 390px and desktop (1366px): guest dashboard, guest activity page, guest calendar, guest settings, guest profile, `register.php` (both tabs), `admin/users.php` (pending guest row and the Add a guest card). No sideways scroll at 390px.
- [ ] Report the branch `feature/guest-accounts` as ready; do not merge.
