# School ID approval: design

Date: 26 October 2026
Client items: 10 and 11 in `docs/feature-backlog.md` (student dashboard
feedback, 26 Oct 2026).
Status: **approved in chat** on 26 October 2026. Next step: a build plan
(`superpowers:writing-plans`).

## Intent

The client's words: "There should be an indication like waiting for approval
and your ID has been approved. Once approved it should send a notification to
the email. The layout for submitting an ID should be landscape."

In the conversation the user settled what this means:

- "ID" is the **school ID** a student uploads on My profile ("School ID for
  borrowing"), not the account. Account approval already has its own message
  at sign-in and its own email (item 9, done).
- Today nobody checks those photos. They are stored and only looked at when
  the student borrows. The client wants the office to **check the ID first**.
- **No approved ID, no borrowing.** A student cannot send an equipment
  request until the admin has approved their ID.
- **The admin decides.** Not the coordinator. If the photo is blurry or wrong,
  the admin rejects it with a reason and the student uploads a new one.
- **Portrait, not landscape.** BukSU's school ID is a portrait card; the
  client's "landscape" was about the upload looking wrong. The upload boxes
  and previews become portrait, card-shaped.

Success: a student always knows where their ID stands (not uploaded, waiting,
approved, or rejected and why), gets an email when the admin decides, and the
admin can clear the queue from one screen.

## Goals

1. The student sees the state of their school ID on My profile and, while it
   is not approved, on their dashboard.
2. The admin approves or rejects an uploaded ID from the review queue; a
   rejection carries a written reason.
3. The student gets an in-app notification **and an email** on approval and
   on rejection.
4. Borrowing requires an approved ID: sending a request
   (`inventory/borrow.php`), approving one, and handing items over.
5. The ID upload and previews are portrait.
6. The whole change ships to the live site as **one zip** (code) plus **one SQL
   file** (database), so the user uploads once.

## Non-goals

- Removing the staff role (item 7, later).
- Coordinators approving IDs.
- ID expiry, school-year renewal, OCR or any automatic check.
- Collecting the ID at sign-up.
- Items 12 to 14 (withdraw after approval, borrowing without an activity,
  matching layouts). They are separate and come next.

## Anti-goals (a failure even if it works)

- A student blocked from borrowing without being told why or what to do.
- ID photos visible to more people than today. Item 8's privacy rule stays;
  the only new viewer is the office while the ID is waiting for a decision.
- Every admin emailed on every upload. Admins learn about new uploads from the
  bell and the review queue only.

## How it works today (for the builder)

- Photos: `users.id_front_path`, `users.id_back_path`. Upload and removal in
  `public/profile.php` (actions `id_photo_upload`, `id_photo_delete`), helpers
  in `includes/borrowing.php` (`has_id_photos()`, `replace_id_photo()`,
  `delete_id_photos()`, `can_view_id_photos()`), storage in
  `includes/uploads.php` (`store_id_photo()`), served by `public/id-photo.php`.
- Borrow gate: `public/inventory/borrow.php` sets
  `$canRequest = $activities !== [] && $hasId`.
- Review queue: `includes/review.php` (`review_counts()`, one
  `review_pending_*()` per lane) feeds `public/review.php`, the header badge,
  and the bell (`includes/attention.php`). `review.php` itself is read-only:
  each lane links to the page where the decision is made (accounts link to
  `admin/users.php`).
- The account-activation email in `public/admin/users.php` calls `send_mail()`
  directly, so it goes out even when the person switched email alerts off.
  `notify_and_email()` in `includes/mailer.php` respects that switch.
- Office check: `is_office_staff()` is true for staff and admin.
- Statuses: `status_meta()` in `includes/helpers.php`, shown with
  `status_badge($status, $domain)`.

## The design

### Data

Two new columns on `users`:

| Column | Type | Meaning |
|---|---|---|
| `id_status` | `ENUM('pending','approved','rejected') NULL` | `NULL`: nothing to review (no photos, or only one side) |
| `id_review_note` | `VARCHAR(500) NULL` | The admin's reason, set on rejection, cleared on a new upload |

Who decided and when goes to `audit_log` as today; no extra columns. *(Builder
call made in design: the audit log already records actor and time.)*

### State changes

| Event | New `id_status` | Note |
|---|---|---|
| Student uploads a side and **both** sides are now present | `pending` | `id_review_note` cleared |
| Student uploads a side and only one side is present | `NULL` | |
| Student replaces a side of an approved or rejected ID | `pending` | goes back to the queue |
| Student removes the ID | `NULL` | |
| Admin approves (only from `pending`) | `approved` | |
| Admin rejects (only from `pending`, reason required, max 500) | `rejected` | photos kept until the student replaces them |
| Account deactivated or sign-up declined (`delete_id_photos()`) | `NULL` | |

Deciding on an ID that is no longer `pending` (another admin got there first,
or the student replaced it) changes nothing and says so in a flash message.
Replacing one side of a pending ID keeps it `pending`, so a status check alone
cannot see that: the decision forms also post the two photo paths the admin
was shown, and the decision is refused if they no longer match. *(Design call
after the builder check. It also removes the need for an `id_submitted_at`
column.)*

Put the transitions in `includes/borrowing.php` next to the existing ID
helpers, so `profile.php`, the decision page and `delete_id_photos()` share
them.

### Who sees and decides

- **Decides:** the office (`is_office_staff()`), so admin, and staff until
  item 7 removes that role. Not coordinators.
- **Sees the photos:** unchanged, plus the office while `id_status` is
  `pending`. Add that one condition to `can_view_id_photos()`.
- Role matrix (`docs/03-role-matrix.md`): add a row "Approve or reject a
  student's school ID": staff and admin yes, everyone else no.

### Admin side

- `includes/review.php`: a new count `ids` (office only, `id_status =
  'pending'`) included in `total`, and `review_pending_ids()` (oldest upload
  first)
- `public/review.php`: a new office-only lane **School IDs**, hint "Students
  uploaded their school ID and cannot borrow until you approve it." Each row:
  name, student number, how long it has waited, and a **Review** button.
- New page `public/admin/id-review.php?user=<id>`, office only:
  - both sides large and portrait, with the student's name, number, course
    and year;
  - **Approve** (`btn-primary`) and **Reject** with a required reason
    textarea (`btn-outline`), each its own POST form with `csrf_field()` and
    `data-loading`;
  - after deciding, back to `review.php?lane=ids` with a flash message.
- `includes/attention.php`: an office item "School IDs to check", same shape
  as "Accounts waiting for approval", linking to the lane.

### Student side

- `status_meta()`: new domain `school_id`: `pending` → warning "Waiting for
  approval", `approved` → success "Approved", `rejected` → danger "Rejected".
- **My profile** (`#school-id`): a status line under the heading:
  - nothing uploaded: "Upload both sides so the office can check your ID.
    You need an approved ID to borrow equipment."
  - one side only: "Upload the other side too."
  - pending: badge + "The office is checking your ID. We will email you."
  - approved: badge + "Your ID has been approved. You can borrow equipment."
  - rejected: badge + the admin's reason in an alert + "Upload a clearer photo."
- **Dashboard:** on-page dashboard alerts were moved into the bell
  (`attention_items()` in `includes/attention.php`; see the comment in
  `public/index.php`), so the student's prompt is a bell item, not a box on
  the page. It shows only when the ID is **rejected** ("Your school ID needs
  a new photo", linking to `profile.php#school-id`). "Waiting for approval"
  shows on My profile and on the borrow page; the decision itself arrives as
  a notification and an email. A student who never uploaded is not nagged.
  *(Design call after the builder check: a permanent prompt for every
  student who never borrows would be noise.)*
- **Borrow page:** (item 14 replaces the page with a popup on the
  catalogue; the same rule applies there, see
  `2026-10-26-withdraw-and-borrowing-changes-design.md`.)
  `$canRequest` requires `id_status = 'approved'`. When it
  is not, the page says which state the ID is in and links to My profile,
  instead of showing the form. Check `inventory/index.php` for any other way
  into a student request and gate it the same way.

### Requests that already exist

The user decided (26 Oct 2026): **a coordinator or the office cannot approve a
student's equipment request until that student's ID is approved.** This covers
requests already waiting when the SQL runs on the live site.

- `public/inventory/reservations.php`: approving a student request
  (`student_request = 1`) is refused while the student's `id_status` is not
  `approved`. The request stays pending and the decider is told why ("This
  student's school ID has not been approved yet"). Declining stays allowed.
- In the review lane and the request list, a student request whose ID is not
  approved shows that fact next to it, so the coordinator is not surprised by
  the refusal.
- **Handover** (`public/inventory/borrowings.php`, items given out against an
  approved student request) is also refused while the ID is not approved.
  *(Design call: "no approved ID, no borrowing" should hold at the counter
  too, e.g. after a student replaces an approved ID with a bad photo.)*
- A rejected ID does **not** cancel the student's open requests. They wait
  until a new photo is approved, and **the student may cancel them
  themselves** while they are not yet approved (user, 26 Oct 2026). The
  student's own cancel already exists (`can_cancel_reservation()`, action
  `cancel` in `inventory/reservations.php`); check that it stays reachable
  while the ID is pending or rejected.

### Portrait upload

- Front and back sit side by side, also at 390px (two portrait cards fit).
- Each side is a frame with the ID card's portrait shape
  (`aspect-ratio: 54 / 85.6`), the photo inside with `object-fit: contain`
  so a sideways photo is still fully visible, and "Not uploaded" inside an
  empty frame. The file input sits under its frame.
- Preview `<img>` gets portrait `width`/`height` attributes.
- New CSS in `style.css` next to the existing `.id-photos-*` rules, using
  tokens only.

### Emails and notifications

Both go out on every decision, by `send_mail()` directly (like account
activation), **regardless of the email-alerts switch**: the client asked for
the email, and a rejected student must learn why. In-app: `notify()` with type
`system`, link `profile.php#school-id`.

- Approved: subject "Your school ID has been approved". Body: they can now
  request costumes and equipment, with a link to `inventory/borrow.php`.
- Rejected: subject "Your school ID needs a new photo". Body: the admin's
  reason, and a link to `profile.php#school-id`.

Office name and university at the bottom, as in the activation email.

### Database update and live deploy

- `database/schema.sql`: the two columns (clean installs).
- `database/update-school-id-approval.sql`: adds the columns using the
  `information_schema` pattern of `update-student-borrowing.sql` (safe to run
  twice; no `ADD COLUMN IF NOT EXISTS`, no views), then sets
  `id_status = 'pending'` where both photo paths are present and
  `id_status IS NULL`. Every student who uploaded before this change goes to
  the queue.
- `docs/02-database-design.md`: document the columns.
- **Live bundle**, built after the branch is merged:
  - `Downloads\casdevu-deploy-v2\update-2026-10-26-school-id.zip`, with every
    changed or new file under `includes/` and `public/` at its path, made
    from `git diff --name-only 9a59006` (no `docs/`, `bin/`, `database/`);
  - the SQL file beside it as `sql\05-school-id-approval.sql`.
  - Order for the user: **run the SQL in phpMyAdmin first**, then File
    Manager → `/htdocs` → Upload & Unzip. New nullable columns do not
    break the old code, so the site stays up between the two steps; the other
    order would give errors until the SQL runs.

### Checks

- `bin/check-borrowing.php`: every row of the state table, the borrow gate,
  the approval and handover refusals, and `can_view_id_photos()` for a
  pending ID.
- `bin/smoke.php`: `admin/id-review.php` for every role (staff and admin 200,
  everyone else 403) and the new lane. It already fails on a role answering
  differently from `docs/03-role-matrix.md`.
- Lint, smoke, and the profile, review lane, decision page and borrow page
  at 390px and desktop.
- One real approval and one rejection locally, reading `storage/logs/mail.log`
  or the inbox.

### Backlog

Mark items 10 and 11 done in `docs/feature-backlog.md`, and correct item 9a:
the PIN reset is live (checked in a browser on 26 Oct 2026).

## Left to the builder

Exact copy beyond what is quoted above, the decision page's layout, how the
bell item is worded, and how `review_pending_ids()` orders the queue
(oldest upload first is the aim).
