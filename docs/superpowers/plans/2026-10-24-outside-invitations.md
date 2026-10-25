# Invitations from outside groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An outside group submits an invitation on a public page; the office approves, rejects or discards it from the review queue; the sender is emailed; approved invitations show on the calendar for the office and coordinators only.

**Architecture:** One new table `invitations` and one new include `includes/invitations.php` holding every rule (who may see, throttle, decision email, notifications). Three new pages under `public/invitations/` (public submit form, detail-and-decision page, letter download). The review queue gains an office-only lane; the calendar merges approved and cancelled invitations into its existing per-day event list.

**Tech Stack:** Native PHP 8.1+, MySQL/MariaDB (XAMPP), no framework, no Composer, no build step.

**Spec:** `C:\xampp\htdocs\BukSU_CASDEVU\docs\superpowers\specs\2026-10-24-outside-invitations-design.md` (not committed; read it by that absolute path). Also read `AGENTS.md` in the worktree before touching code.

## Global Constraints

- Work only in the worktree `C:\xampp\htdocs\BukSU_CASDEVU-invitations`, branch `feature/outside-invitations`. Never merge into `main`.
- The `casms` database is shared with other builds. **Never reload `database/schema.sql` or `database/demo-data.sql`.** Schema changes go in `database/update-outside-invitations.sql`, additive only (`CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS`), and are mirrored in `schema.sql`. Do not alter existing tables or enums.
- Server: from the worktree, `C:\xampp\php\php.exe -S 127.0.0.1:8082 -t public` (already running during the build). Gate: `C:\xampp\php\php.exe bin\smoke.php --base=http://127.0.0.1:8082`. Lint: `C:\xampp\php\php.exe -l <file>`.
- "The office" is `is_office_staff()`. Seeing invitations is an **explicit allow-list**: `is_office_staff() || current_role() === 'coordinator'`. Never "not a student" (a Guest role is being built in parallel).
- AGENTS.md security rules 1–10 hold on every page: bootstrap first, gate before output, `csrf_field()` / `csrf_verify()`, `e()` on every echo, bound queries, `store_upload()`, `abort_page()`, no SQL/paths/exception text in the browser.
- Status words through `status_badge($status, 'invitation')`; the new domain lives in `status_meta()` only.
- One `btn-gold` per page at most; form submits `btn-primary`; everything else `btn-outline`. Data-changing forms get `data-loading`.
- No inline `style=""` except colour/width from data. Icons only via `icon()`.
- 390px wide must not scroll sideways.
- Approval email must say the invitation was **received and shared with the unit's coordinators**, and that BukSU will get in touch **if it sends participants**. It must not say or suggest BukSU accepts or will attend.
- No notification `type` enum change: invitation notifications use the existing `'activity'` type.
- Commit after each task with a plain message ending in:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. A coordinator opens `invitations/view.php?id=` (or `letter.php`) for a **pending or rejected** invitation by guessing the id → must get 404, never the details or the file. (Task 3 verification step.)
2. Email off (`MAIL_TRANSPORT = 'log'`, the state of this install): every decision records `email_status = 'logged'`, the lane and detail page say "Email not sent: contact by hand" with contact details, and the thank-you page does not promise an email. (Tasks 1, 2, 3, 4.)
3. Two office users decide the same pending invitation at once (or a resubmitted form) → only the first decision applies and only one email goes out. (Task 3: `UPDATE … WHERE status = 'pending'` and check `rowCount()`.)
4. A flood from one IP or one email address → the sixth submission within an hour is refused with a plain message, and nothing is stored. A filled honeypot shows the thank-you page and stores nothing. (Task 2 verification.)
5. Hostile text in any outsider field (`<script>`, quotes, very long strings) → escaped everywhere it shows (lane, detail, calendar chip `aria-label`, agenda, notification), and over-long input is refused by validation, not truncated by MySQL strict mode into a 500. (Task 2 length checks; Task 5 calendar escaping.)

---

### Task 1: Table, rules and status words

**Files:**
- Create: `database/update-outside-invitations.sql`
- Modify: `database/schema.sql` (new table after `user_preferences`, before the VIEWS section)
- Create: `includes/invitations.php`
- Modify: `includes/bootstrap.php` (require `invitations.php` after `mailer.php`)
- Modify: `includes/config.php` (constant beside the sign-in throttle block)
- Modify: `includes/helpers.php` (`status_meta()` gains the `invitation` domain)

**Interfaces:**
- Produces:
  - table `invitations` (columns below)
  - `const INVITATION_MAX_PER_HOUR = 5` via `define()` in config.php
  - `can_view_invitations(): bool`
  - `fetch_visible_invitation(int $id): ?array` — the row if the current user may see it, else null
  - `invitation_submissions_limited(string $ipAddress, string $email): bool`
  - `invitation_send_decision(array $invitation): string` — sends the approval/rejection email, returns `'sent' | 'logged' | 'failed'`
  - `invitation_notify_approved(array $invitation): void`
  - `invitation_when(array $invitation): string`
  - `status_meta($s, 'invitation')` for `pending/approved/rejected/cancelled`

- [ ] **Step 1: Write `database/update-outside-invitations.sql`** with a header comment in the style of `update-user-preferences.sql`, `SET NAMES utf8mb4;`, `USE casms;` is **not** used (the run command names the DB), and:

```sql
CREATE TABLE IF NOT EXISTS invitations (
    invitation_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization    VARCHAR(200) NOT NULL,
    contact_name    VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(40)  NULL,
    title           VARCHAR(200) NOT NULL,
    start_at        DATETIME     NOT NULL,
    end_at          DATETIME     NOT NULL,          -- same as start_at when the sender gave no end
    place           VARCHAR(255) NOT NULL,
    details         TEXT         NOT NULL,
    letter_path     VARCHAR(255) NULL,              -- relative to UPLOAD_PATH, via store_upload()
    letter_name     VARCHAR(255) NULL,
    letter_mime     VARCHAR(100) NULL,
    letter_size     INT UNSIGNED NULL,
    status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    remarks         VARCHAR(500) NULL,              -- rejection reason, sent to the sender
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME     NULL,
    -- The decision email: NULL until a decision; 'logged' = written to mail.log only;
    -- 'contacted' = the office reached them by hand after 'logged' or 'failed'.
    email_status    ENUM('sent','logged','failed','contacted') NULL,
    ip_address      VARCHAR(45)  NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_invitations_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
                                           ON DELETE SET NULL,
    CONSTRAINT chk_invitations_dates   CHECK (end_at >= start_at),
    INDEX idx_invitations_status   (status, start_at),
    INDEX idx_invitations_ip       (ip_address, created_at),
    INDEX idx_invitations_email    (email, created_at)
) ENGINE=InnoDB;
```

- [ ] **Step 2: Mirror it in `schema.sql`** as `CREATE TABLE invitations (...)` (no `IF NOT EXISTS`, matching the file) under a short comment block "Invitations from outside groups".

- [ ] **Step 3: Apply and check**

Run: `C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-outside-invitations.sql` then `C:\xampp\mysql\bin\mysql.exe -u root casms -e "DESCRIBE invitations"`
Expected: 22 rows, no error. Run the update a second time: still no error (idempotent).

- [ ] **Step 4: Add the config constant** after `LOGIN_LOCKOUT_MINUTES`:
`define('INVITATION_MAX_PER_HOUR', 5);   // per sender address and per email, public invitation form`

- [ ] **Step 5: Add the `invitation` domain to `status_meta()`**: `pending => ['warning','Pending review']`, `approved => ['success','Posted']`, `rejected => ['danger','Rejected']`, `cancelled => ['muted','Cancelled']`. ("Posted", not "Approved"/"Accepted": approval means received and posted.)

- [ ] **Step 6: Write `includes/invitations.php`** (file header docblock in the house style; `declare(strict_types=1);`):

  - `can_view_invitations()`: `is_office_staff() || current_role() === 'coordinator'`. Comment the why: explicit list so a new role (Guest) gets nothing by default.
  - `fetch_visible_invitation(int $id)`: null unless `can_view_invitations()`; office gets any row; coordinator only `status IN ('approved','cancelled')`. Bound query.
  - `invitation_submissions_limited($ip, $email)`: true when `COUNT(*)` of rows with that `ip_address` in the last 60 minutes `>= INVITATION_MAX_PER_HOUR`, **or** the same for that `email`. Comment: `shortcut: REMOTE_ADDR is the proxy's address behind a reverse proxy; read the proxy's forwarded-for header there once IT publishes the server.` (The caller passes `$_SERVER['REMOTE_ADDR']`.)
  - `invitation_when(array $inv)`: `format_datetime(start_at)`, plus `' to ' . <end>` when `end_at > start_at` (end as `g:i A` when the same day, else `format_datetime`).
  - `invitation_send_decision(array $inv)`: `$inv` has the decided `status` (`approved` or `rejected`) and `remarks`. Builds subject `'Your invitation to ' . university_name() . ': ' . $inv['title']` and the body below, calls `send_mail()`. Returns `'failed'` when `send_mail()` is false, else `email_delivers() ? 'sent' : 'logged'`. Body (exact copy; `PHP_EOL` line breaks; `{…}` are the row's values, plain text, not escaped — it is a text/plain email):

```
Good day, {contact_name}.

Thank you for inviting {university_name()} to "{title}" ({invitation_when}, {place}).

approved:
The {office_name()} has received your invitation and shared it with the unit's coordinators. If the university sends participants, the office will get in touch with you at this email address.

rejected:
The {office_name()} has reviewed your invitation and is not able to take it up.

Reason: {remarks}

{office_name()}
{university_name()}

This is an automated message. Please do not reply.
```

  - `invitation_notify_approved(array $inv)`: recipients = active users whose role is `'admin'` or `'coordinator'` (explicit `IN`, staff excluded per spec); `notify_many($ids, 'activity', 'New invitation: ' . $title, $organization . ' invites BukSU to ' . $title . ', ' . invitation_when . ', ' . $place . '. It is on the calendar.', 'invitations/view.php?id=' . $id)`. Truncate title to 200 and message to 500 chars with `mb_strimwidth(..., '…')` (column limits). Check how other `notify()` callers pass `link_url` (relative path vs `url()`), and match them.

- [ ] **Step 7: Require it in `includes/bootstrap.php`** after `mailer.php`.

- [ ] **Step 8: Lint and gate**

Run: `php -l` on every touched PHP file, then the smoke gate.
Expected: `No syntax errors detected` ×5; smoke `0 failed`.

- [ ] **Step 9: Commit** — `git add database includes && git commit` ("Add the invitations table and its rules").

---

### Task 2: Public "Submit an invitation" page

**Files:**
- Create: `public/invitations/submit.php`
- Modify: `public/login.php` (link in `.portal-secondary`)

**Interfaces:**
- Consumes: `invitation_submissions_limited()`, `store_upload()`, `delete_upload()`, `email_delivers()`, `office_contact_hint()`, `INVITATION_MAX_PER_HOUR`.
- Produces: URL `invitations/submit.php` (public, no sign-in; signed-in visitors may use it too).

- [ ] **Step 1: Build the page** on the registration layout (`includes/layout/portal-header.php` / `portal-footer.php`, `$portalWide = true`; read `public/register.php` for field markup, `has-error`, `field-error`, `aria-invalid`, `remember_input()` / `old()` / `clear_old_input()`). No `require_login()`. `<form method="post" enctype="multipart/form-data" class="portal-form">` with `csrf_field()`.
  Fields (label text in quotes; `maxlength` matching the column):
  - "Organization or group" `organization` required, 200
  - "Contact person" `contact_name` required, 150
  - "Email address" `email` required, 190, `type=email`; hint: "The office's decision is sent here."
  - "Phone number (optional)" `phone`, 40, `type=tel`
  - "Program title" `title` required, 200
  - "Starts" `start_at` required, `type=datetime-local`; "Ends (optional)" `end_at`
  - "Place" `place` required, 255
  - "Details" `details` required, textarea, max 5000; hint: "What the program is, and who you hope BukSU will send."
  - "Invitation letter (optional)" `letter`, `type=file accept=".pdf,.jpg,.jpeg,.png"`; hint "PDF, JPG or PNG, up to {MAX_UPLOAD_BYTES in MB} MB."
  - Honeypot: a text input `name="website"` inside a wrapper hidden from people and assistive tech (`hidden` attribute on the wrapper plus `tabindex="-1" autocomplete="off"`), label "Leave this empty".
  - Submit: `btn btn-primary btn-block portal-submit`, text "Send invitation", `data-loading="Sending…"`.
  - Page title "Submit an invitation"; `$authIntro` = "Invite Bukidnon State University students to your program. No account needed: the office reviews every invitation."
  - Secondary link back to `login.php` ("Staff and students: sign in").

- [ ] **Step 2: POST handling, in this order**
  1. `csrf_verify()`.
  2. Honeypot non-empty → show the thank-you state, store nothing, `audit_log('spam','invitation',null,'Honeypot filled on the invitation form')`.
  3. Validate: required fields non-empty; lengths ≤ the maxlengths above (`mb_strlen`); `filter_var(email, FILTER_VALIDATE_EMAIL)`; `start_at` and optional `end_at` parse with `DateTime::createFromFormat('Y-m-d\TH:i', …)` (exact format check, as `activities/manage.php` does); end ≥ start; start not in the past (`< now` → "The program's start is in the past."). Errors are per field, in plain words.
  4. `invitation_submissions_limited($_SERVER['REMOTE_ADDR'] ?? 'unknown', $email)` → form error: "Too many invitations have been sent from here in the last hour. Please try again later, or contact the office{office_contact_hint()}."
  5. Only when everything above passed and a file was chosen (`$_FILES['letter']['error'] !== UPLOAD_ERR_NO_FILE`): `store_upload($_FILES['letter'], 'invitations')`; its error becomes the letter field's error.
  6. Insert with `status='pending'`, `end_at = end ?: start`, `ip_address`. `audit_log('create','invitation',$id,'Invitation received from ' . $organization)`.
  7. Show the thank-you state (no redirect needed, as `forgot-password.php` does).
  On any error: `remember_input($_POST)` and redisplay; never keep the file across a failed submit (say so in the letter hint after an error: "Choose the letter again.").

- [ ] **Step 3: Thank-you state** (`alert alert-success role="status"`): "Thank you. Your invitation has reached the office." Then, if `email_delivers()`: "The office will review it and email its decision to {email}." else: "The office will review it and contact you{office_contact_hint()}." Plus a `btn-outline` link "Send another invitation" to `invitations/submit.php`.

- [ ] **Step 4: Link it from the sign-in page**: in `.portal-secondary` of `public/login.php`, a `<p>` "Inviting BukSU students to your program? <a>Submit an invitation</a>".

- [ ] **Step 5: Verify by hand over HTTP** (signed out, curl with a cookie jar: GET the form, pull `csrf_token`, POST):
  - valid POST without letter → 200, thank-you text present, one new `pending` row (`mysql -e "SELECT invitation_id,status,ip_address FROM invitations ORDER BY invitation_id DESC LIMIT 1"`).
  - POST with `website=x` → thank-you text, no new row.
  - POST with `title=<script>alert(1)</script>` and an invalid email → error shown, the title value comes back escaped (`&lt;script&gt;`).
  - 5 more valid POSTs from the same address within the hour → the 6th total shows the "Too many invitations" message, row count unchanged.
  - Delete your test rows afterwards: `DELETE FROM invitations WHERE organization LIKE 'TEST %'` (use that prefix for every test submission).

- [ ] **Step 6: Lint, smoke, check 390px and desktop** (Playwright or Chrome: `http://127.0.0.1:8082/invitations/submit.php` and `login.php`; no sideways scroll at 390px).

- [ ] **Step 7: Commit** ("Add the public invitation form").

---

### Task 3: Detail page, decisions, and the letter

**Files:**
- Create: `public/invitations/view.php`
- Create: `public/invitations/letter.php`

**Interfaces:**
- Consumes: `can_view_invitations()`, `fetch_visible_invitation()`, `invitation_send_decision()`, `invitation_notify_approved()`, `invitation_when()`, `delete_upload()`, `status_badge(…, 'invitation')`.
- Produces: `invitations/view.php?id={id}` (the review lane and calendar link here), `invitations/letter.php?id={id}`.

- [ ] **Step 1: Gate (`view.php`)**, before any output: `require_login()`; `if (!can_view_invitations())` → `abort_page(403, …)` with `http_response_code(403)` as `require_role()` does; `$id = get_id('id')`; `$inv = $id ? fetch_visible_invitation($id) : null`; null → 404 "Invitation not found." (a coordinator asking for a pending or rejected one gets the same 404, so its existence is not revealed).

- [ ] **Step 2: POST actions (office only)**: on POST, `csrf_verify()`, then `is_office_staff()` or 403. `$action = post('action')`:
  - `approve`: `UPDATE invitations SET status='approved', reviewed_by=?, reviewed_at=NOW(), remarks=NULL WHERE invitation_id=? AND status='pending'`. If `rowCount() === 0` → flash error "This invitation was already decided." and redirect. Otherwise re-read the row, `$state = invitation_send_decision($row)`, store `email_status`, `invitation_notify_approved($row)`, `audit_log('approve','invitation',$id,…)`, flash success ("Invitation posted to the calendar. Admins and coordinators have been notified." + " The sender has been emailed." only when `$state === 'sent'`).
  - `reject`: `remarks` required (≤ 500, error "Give a reason; it is sent to the sender."), same guarded UPDATE to `rejected` with remarks, send, store `email_status`, audit, flash.
  - `discard`: only when `status='pending'`: `delete_upload(letter_path)`, `DELETE … WHERE invitation_id=? AND status='pending'`, `audit_log('discard','invitation',$id,'Discarded as spam')` (no outsider data in the log line), flash "Invitation discarded. Nothing was sent.", redirect to `review.php?lane=invitations`.
  - `cancel`: only when `status='approved'`: UPDATE to `cancelled` (guarded on `status='approved'`), audit, flash "Invitation cancelled. It stays on the calendar, marked cancelled. No email was sent."
  - `contacted`: only when `email_status IN ('logged','failed')`: set `email_status='contacted'`, audit, flash.
  - anything else → 400 via `abort_page`.
  Every successful action ends in `redirect('invitations/view.php?id=' . $id)` (except discard).

- [ ] **Step 3: Markup** (`includes/layout/header.php` / `footer.php`; `$pageTitle = 'Invitation: ' . $title`):
  - `page-head`: h1 = title, `<p>` "Invitation from {organization}" and `status_badge($status,'invitation')`. Breadcrumb/back link: office → "Review queue" (`review.php?lane=invitations`), coordinator → "Calendar" (`activities/calendar.php?year=&month=` of the start date).
  - When `email_status` is `logged` or `failed` and the viewer is office: `alert alert-warning` "Email not sent: contact by hand." + one sentence ("Email is not switched on for this system." for `logged`, "The email could not be sent." for `failed`) + the email (mailto link) and phone (tel link) + a small POST form, `action=contacted`, `btn-outline btn-sm` "Mark as contacted".
  - `card` with `dl.detail-list`: Organization, Contact person, Email (mailto), Phone (tel, or "Not given"), When (`invitation_when`), Place, Details (`nl2br(e(...))`), Letter (link to `letter.php?id=` with `letter_name` and `format_filesize(letter_size)`, or "No letter attached"), Received (`format_datetime(created_at)`), and for decided ones "Decided" (reviewer name + `format_datetime(reviewed_at)`) and "Reason" when rejected.
  - Office actions card (only for office): pending → Approve form (`btn-primary`, `data-loading="Posting…"`), Reject form (textarea `remarks` required, `btn-outline`, `data-loading`), Discard form (`btn-outline`, `onsubmit="return confirm('Discard this invitation as spam? It is deleted with its letter, and nothing is sent.');"`). Short hint above Approve: "Approving posts it to the calendar for admins and coordinators and emails the sender that it was received. It does not commit BukSU to attend." Approved → Cancel form (`btn-outline`, confirm "Cancel this invitation? It stays on the calendar, marked cancelled. No email is sent."). No `btn-gold` on this page.

- [ ] **Step 4: `letter.php`**: copy the serving half of `public/requirements/download.php` exactly (MIME whitelist, `sanitize_filename`, `nosniff`, CSP, `Cache-Control: private, no-store`, `readfile`, `exit`). Gate: `require_login()`, `can_view_invitations()` else 403, `fetch_visible_invitation(get_id('id'))` null or no `letter_path` → 404. `audit_log('view','invitation',$id,'Opened the invitation letter')`.

- [ ] **Step 5: Verify over HTTP** (sign in with curl as in `bin/smoke.php`'s `sign_in()`; use `TEST ` prefixed rows created through the public form in Task 2's way, one with a small PDF letter):
  - staff: view pending → 200 with Approve/Reject/Discard; approve → row `approved`, `email_status='logged'`, a new block in `storage/logs/mail.log` whose body contains "received your invitation and shared it with the unit's coordinators" and no "accept"/"attend"; notifications rows created for every active admin and coordinator and **none** for staff or students; page shows "Email not sent: contact by hand".
  - POST approve again on the same id → "already decided", no second mail.log block.
  - reject a second one without a reason → error; with a reason → `rejected`, mail.log body contains the reason.
  - discard a third (with letter) → row gone, file gone from `storage/uploads/invitations/`.
  - coordinator `bautista.coach@buksu.edu.ph`: view/letter of the approved one → 200; of a pending or rejected one → 404; any POST → 403.
  - student `andrea.santos@student.buksu.edu.ph`: view and letter → 403.
  - signed out: view → 302 to `login.php`.
  - Leave one approved `TEST` invitation with a letter in place for Task 5's calendar check; delete the rest.

- [ ] **Step 6: Lint, smoke, check 390px and desktop** (staff on a pending one, coordinator on an approved one).

- [ ] **Step 7: Commit** ("Add the invitation page with office decisions and the letter download").

---

### Task 4: Review queue lane and bell item

**Files:**
- Modify: `includes/review.php`
- Modify: `public/review.php`
- Modify: `includes/attention.php`

**Interfaces:**
- Consumes: invitations table, `status_badge`, `invitation_when()`.
- Produces: `review_counts()['invitations']` (office only; pending + awaiting hand contact) included in `total`; `review_pending_invitations(int $limit = 50): array`; `review_unsent_invitations(int $limit = 50): array`.

- [ ] **Step 1: `includes/review.php`**: add `'invitations' => 0` to the counts array and its `@return` shape; for office: `COUNT(*) FROM invitations WHERE status = 'pending' OR email_status IN ('logged','failed')`; add it to `total`. Add the two list functions, office-only (return `[]` otherwise), oldest first: pending rows (`invitation_id, organization, contact_name, title, start_at, end_at, place, created_at, letter_path IS NOT NULL AS has_letter`); unsent rows (`… status, email, phone, reviewed_at, email_status`), ordered by `reviewed_at`. Update the file's docblock sentence about office-only lanes.

- [ ] **Step 2: `public/review.php`**: office-only lane `'invitations' => ['Invitations', 'Outside groups invited BukSU students to these programs.']`. Lane body:
  - pending table (`table.data table-stack data-sortable`, labelled cells like the other lanes): "From" (organization, contact as hint), "Program" (title, place as hint, "Letter attached" hint when `has_letter`), "When" (`invitation_when`, `data-sort-value` = start timestamp), "Received" (`waiting_label`), action `btn-primary btn-sm` "Review" → `invitations/view.php?id=`.
  - when unsent rows exist, a second block under the heading "Email not sent: contact by hand" with a one-line hint ("The sender has not been told the decision. Reach them, then mark them as contacted."): "From", "Decision" (`status_badge`), "Contact" (email mailto, phone tel), action `btn-outline btn-sm` "Open".
  - empty state (nothing pending and nothing unsent): "No invitations waiting" / "Invitations from outside groups appear here as soon as they are sent from the public form."
  - Update the all-caught-up sentence for office to name invitations; update the file docblock (rows link to `invitations/view.php`).

- [ ] **Step 3: `includes/attention.php`**: for office, when `review_counts()['invitations'] > 0`, an item: tone `warning`, icon `review`, title "Invitations to review", message "{n} invitation(s) from outside groups need a decision or a reply.", href `review.php?lane=invitations`, action "Open the review queue". (Use an existing icon name from `includes/icons.php`.)

- [ ] **Step 4: Verify**: with one pending `TEST` invitation and one approved with `email_status='logged'`: `review.php?lane=invitations` as staff shows both blocks and a lane count of 2; as coordinator the Invitations tab is absent and `?lane=invitations` falls back to another lane; the sidebar badge total grew by 2 for staff only.

- [ ] **Step 5: Lint, smoke, check 390px and desktop** (the lane at 390px stacks into labelled rows).

- [ ] **Step 6: Commit** ("Add the invitations lane to the review queue").

---

### Task 5: Invitations on the calendar

**Files:**
- Modify: `public/activities/calendar.php`
- Modify: `public/assets/css/style.css` (calendar section, beside `.cal-chip.is-draft`)

**Interfaces:**
- Consumes: `can_view_invitations()`, `status_badge(…, 'invitation')`, `invitations/view.php?id=`.

- [ ] **Step 1: Normalise events.** Every event row in `$byDay` gains `kind` (`'activity'|'invitation'`), `href`, and `domain` (`'activity'|'invitation'`). Activity rows: `href = url('activities/view.php?id=…')`, `domain = 'activity'`. Replace the two hard-coded `activities/view.php` links in the chip and `calendar_agenda_item()` with `$event['href']`, and `status_badge($status, 'activity')` with `$event['domain']`.

- [ ] **Step 2: Fetch invitations** only when `can_view_invitations()` **and** no category filter is set: `status IN ('approved','cancelled') AND start_at <= monthEnd AND end_at >= monthStart`, ordered by `start_at`. Map each to an event: `title`, `start_at`, `end_at`, `status`, `category = 'Invitation from ' . organization`, `venue = place`, `color_hex = null`, `my_status = null`, `kind='invitation'`, `domain='invitation'`, `href = url('invitations/view.php?id=…')`. Spread them over days with the same loop as activities (extract the spreading loop body into a closure or loop over `array_merge($activities, $invitationEvents)`), then sort each day's list by `start_at` so the two kinds interleave by time.

- [ ] **Step 3: Label them.** `calendar_status_word()` returns `'Invitation'` for an approved invitation and `'Cancelled invitation'` for a cancelled one (activities unchanged). The chip and agenda item get class `is-invitation` (plus `is-cancelled` when cancelled). In the agenda, the `cal-cat` tag shows the "Invitation from …" text, and the badge shows for invitations always (`Posted` / `Cancelled`). Page-head line: append ", {n} invitation(s)" when there are any. Phone dots: invitations use the neutral invitation colour.

- [ ] **Step 4: CSS.** `.cal-chip.is-invitation` and `.cal-item.is-invitation` use a neutral, non-category look: `--cat: var(--ink-soft)`, chip background `var(--surface)` with a 1px solid `var(--line)` border (activities are tinted fills; drafts are dashed outlines, so invitations must differ from both). Use only tokens that already exist in `style.css`. Cancelled invitations reuse the existing `is-cancelled` rules.

- [ ] **Step 5: Verify**: with the approved `TEST` invitation from Task 3 in the current month: staff, admin and coordinator see it on the grid and in the agenda, labelled "Invitation", linking to `invitations/view.php`; student sees nothing of it (check the HTML for the title: absent); choosing a category hides it; a `pending` and a `rejected` `TEST` row in the same month never appear for anyone; cancel the approved one → shows struck through, "Cancelled invitation".

- [ ] **Step 6: Lint, smoke, check 390px and desktop** (calendar as coordinator: grid dots and agenda at 390px; chips at desktop).

- [ ] **Step 7: Commit** ("Show posted invitations on the calendar for the office and coordinators").

---

### Task 6: Smoke gate and documentation

**Files:**
- Modify: `bin/smoke.php`
- Modify: `docs/03-role-matrix.md`, `docs/02-database-design.md`, `README.md`

- [ ] **Step 1: Smoke ids**: `$ids['invitation']` = first `invitation_id` with `status IN ('approved','cancelled')` (0 when none); `$ids['invitation_letter']` = the same with `letter_path IS NOT NULL`.

- [ ] **Step 2: Smoke pages** (after `review.php`):
  - `invitations/submit.php` allow all four roles.
  - `invitations/view.php` allow admin, staff, coordinator; `params => ['id' => 'invitation']`, `optional`.
  - `invitations/letter.php` allow admin, staff, coordinator; `params => ['id' => 'invitation_letter']`, `optional`, `binary`.

- [ ] **Step 3: Signed-out checks**: a short `section('Signed out')` before "Signing in" that, with a fresh cookie jar, requests `invitations/submit.php` (expect 200 and no `body_error()`, and the body contains `name="csrf_token"`) and `invitations/view.php?id=1` (expect 302 with `login.php` in Location). Each counts as a check and uses `ok()`/`fail()`.

- [ ] **Step 4: Run the gate**: Expected `0 failed`, the request count up by 12 (3 pages × 4 roles) plus the 2 signed-out checks.

- [ ] **Step 5: Docs.**
  - Role matrix: a new "Invitations from outside groups" section, same table shape, plus a "Public" note: Submit an invitation (public, no account) ✔ for everyone; Approve / reject / discard / cancel: ✘ ✘ ✔ ✔; View posted invitations on the calendar and in detail: ✘ ✔ ✔ ✔; View pending or rejected invitations: ✘ ✘ ✔ ✔. Note under it: the allow-list is explicit; any new role sees nothing until added.
  - Database design: the `invitations` table, one line per column group, and the update script name.
  - README: under the existing update-scripts / known-limits text — run `database/update-outside-invitations.sql` on existing installs; the public page `invitations/submit.php` is reachable from outside only if IT exposes the server; behind a reverse proxy the throttle counts the proxy's address until `invitation_submissions_limited()`'s caller reads the forwarded header; decisions are emailed only once SMTP is set up, otherwise the review lane lists them under "Email not sent: contact by hand".

- [ ] **Step 6: Commit** ("Gate the invitation pages in smoke and document them").
