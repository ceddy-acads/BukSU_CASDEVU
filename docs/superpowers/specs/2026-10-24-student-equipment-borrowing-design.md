# Student equipment borrowing: design

Date: 24 October 2026
Client item: "1. Fill up form" from the client's feature list.
Status: **approved** on 24 October 2026, **amended** the same day (revision
2: the student proposes a return date, and student ID verification is
added). Not built yet; the next step is a build plan
(`superpowers:writing-plans`).

## Revision 2 (24 October 2026)

The client's representative returned to this feature with the client's own
note: "The students will fill up a form with scheduled return of the
equipment and verification (a photo of the ID, front and back)." Two changes
follow. Both are written into the sections below. Where they contradict an
earlier decision, revision 2 wins.

1. **The student proposes the return date, and the coordinator may change
   it.** This replaces "the coordinator alone picks the return date".
2. **Student ID verification.** The student keeps a photo of their school ID,
   front and back, on their account. See "Student ID photos".

## Intent

Students need to borrow the office's costumes and equipment for the activities
they take part in (a basketball for tryouts practice, for example). Today a
student has to find the activity's coordinator in person to get permission,
and the coordinator is often not around. The client wants a **request form**
the student fills in from the system, so the coordinator can approve it
whenever they are available. **The student proposes when the equipment will
come back, and the coordinator confirms or changes that date.** The student's
ID, front and back, is on file so the office knows who has the equipment.

Success: a student can ask for equipment without catching the coordinator in
person, the coordinator decides from their phone or desk in a few taps, and
the office hands the item over with the return date already decided.

## How it works today (for the builder)

- Students can browse the catalogue (`inventory/index.php`) but cannot request
  anything. The catalogue tells them to "ask your activity coordinator or the
  office". `docs/03-role-matrix.md` gives "Request a reservation" to
  coordinator, staff and admin only.
- Coordinators and staff request **reservations** (`inventory/reserve.php`):
  tables `reservations` (activity, requester, purpose, `needed_from`,
  `needed_until`, status `pending/approved/rejected/cancelled/fulfilled`,
  reviewer and remarks) and `reservation_items` (item, quantity).
- Only office staff and admins approve or reject reservations
  (`inventory/reservations.php`, `$canDecide = is_office_staff()`), and only
  they see the Reservations lane of the review queue (`review.php`,
  `review_pending_reservations()`).
- Clashes are checked with `reservation_conflicts()` and
  `quantity_available_between()` in `includes/inventory.php`, both when a
  request is made and when it is approved.
- Office staff record the physical hand-over as a **borrowing**
  (`inventory/borrowings.php`): one row per item, linked to its reservation,
  with `expected_return_at`. Returning records `returned_at`, the receiver and
  the condition (`good/damaged/lost`). Releasing everything on a reservation
  marks it `fulfilled`. Overdue loans are highlighted (`is_overdue()`).

## The flow (decided with the client's representative)

1. **The student requests.** A student fills in a borrow request with:
   - which activity it is for;
   - which items and how many;
   - **when they need them** (start date and time);
   - **when they will return them** (proposed return date and time, after the
     start);
   - what for.

   The activity list offers only activities where the student's registration
   is **approved**. A student with no approved activity cannot request, and
   the page says why. Borrowing that is not tied to an activity is not
   allowed.

   **A student cannot send a request until both ID photos are on file.** The
   form says why and links to where they are uploaded.
2. **The coordinator decides.** The request goes to the coordinator(s)
   assigned to that activity. While deciding, the coordinator can see the
   student's ID photos. They either:
   - **approve it, keeping the student's proposed return date or changing
     it**. The final return date must be after the start date. If the
     coordinator changed it, the approval notice to the student states the
     new date clearly.
   - **reject it with a reason** the student can see.

   Approval is **all or nothing**. Apart from the return date, the coordinator
   cannot change the request (fewer units, some items, another start date). If part of it cannot be
   met, they reject with a reason and the student asks again.

   Approval re-checks availability for the whole window from the start date
   to the final return date, with the existing conflict check. If the window
   clashes, approval is refused with the reason, and the coordinator can pick
   an earlier return date or reject.
3. **The office hands it over.** The student is told the request is approved
   and goes to the office. Office staff find the approved request, look at
   the student's ID photos to check that the person collecting is that
   student, and record the loan. The final return date is already filled in.
   Seeing the ID at handover **is** the verification; there is no separate
   "ID verified" stamp.
4. **The return.** Office staff check the item back in and note its condition,
   exactly as today.

## Decisions marked as proposals the client accepted

These were offered as guesses during brainstorming and confirmed ("yes, that's
correct"):

- **Availability while requesting:** the request form shows how many of each
  item are free between the start and proposed return dates the student
  chose, so a student does not ask for more than exist.
- **Cancelling:** the student can cancel their own request while it is
  pending, and also after approval as long as nothing has been collected yet
  (revision 2: the coordinator may have set a return date the student cannot
  meet). Cancelling frees the stock.
- **Backup deciders:** office staff and admins can also approve or reject a
  student request, in case the coordinator is away for days.
- **Notifications:**
  - the coordinator is notified of a new request, and it appears in their
    review queue;
  - the student is notified when the request is approved or rejected.
- **Reminder and overdue:**
  - the student is reminded the day before the return date;
  - a late loan shows as overdue to staff, as today, and also to the
    activity's coordinator.

## Decided after the builder review

The client's representative decided these two:

- ~~The coordinator alone picks the return date.~~ **Replaced by revision
  2:** the student proposes a return date, and the coordinator keeps or
  changes it.
- **Office-only items.** Each inventory item gets a setting for whether
  students may request it. Items marked office-only **do not appear on the
  student request form**. The office sets this per item on the item's edit
  page (`inventory/manage.php`).

The rest are calls made in this document. The client's representative should
check them while reading:

- **Pending requests do not hold stock.** Only approved reservations and open
  loans count, as today. The form says the numbers can change before
  approval. Approval re-checks availability, so a request that no longer fits
  is refused at approval with the reason, and never silently. The shared
  availability code and the coordinators' and staff's own reservations are
  unchanged.
- **Coordinators see overdue loans passively.** The activity's page gets a
  list of loans from **student requests for that activity**, with overdue
  ones highlighted. There are no extra alerts, and staff walk-in loans are
  not included. The role matrix gains a row for this (see Constraints).
- **Office-only items still show in the catalogue.** Students can see what
  the office owns, labelled "Office use only", but cannot request them. If
  the client wants them hidden from students entirely, that is a one-line
  change in scope.
- **Existing items start as requestable.** The office then marks the
  office-only ones. The other default (nothing requestable until the office
  opts items in) would make the feature look broken at launch.
- **If the student's registration is withdrawn or rejected** (revised during
  the build, Ruling R5), the student's pending requests and approved-but-
  uncollected requests for that activity are cancelled automatically and the
  student is told why. Loans already out are left alone; the office handles
  those as today.
- **An activity with no coordinator assigned:** its student requests go to
  office staff, as `activity_reviewer_ids()` already does for registrations.

## Student ID photos (revision 2)

Decided with the client's representative:

- **Once per account.** The student uploads a photo of their school ID, front
  and back, and it is kept on their account and reused for every request. The
  student can view and replace their own photos at any time. The obvious place
  is their profile page.
- **Who may see them** (the smallest circle that works):
  - the student, for their own;
  - the office, **while the student has anything open**: an approved
    request waiting to be collected, or a loan still out (overdue included).
    Once everything is returned, the office loses access until the next
    request. (Decided by the client's representative, option B, so the
    office can check the ID when chasing overdue or damaged items.)
  - a coordinator, **only while a request from that student is in front of
    them** for one of the coordinator's activities.

  No one else: no other students, no Guests, and no coordinators without such
  a request.

Calls accepted with the playback:

- **Stored like requirement documents.** Through `store_upload()`, outside
  the web root, JPG or PNG only. Served by a script that checks the circle
  above before sending a single byte, in the pattern of
  `requirements/download.php`.
- **Data Privacy Act.** ID photos are sensitive personal data.
  - **Replacing.** Allowed at any time. The old files are deleted, not only
    the database row.
  - **Deleting.** Allowed only while the student has no open request or
    loan: nothing pending, approved but not collected, or out. Otherwise the
    page says why.
  - **Deactivation** (`inactive`) deletes the photos. It also cancels the
    student's pending requests, and approved ones not yet collected, with a
    notice. Loans already out are unaffected; the loan record says who has
    the items.
  - **Suspension** (`suspended`) is reversible, so the photos are kept.
- **A coordinator's access ends with the decision.** A coordinator can open
  a student's ID photos only while that student's request for one of their
  activities is **pending**. After approval or rejection, they cannot.
- **Nothing else shows them.** They never appear in lists, exports, reports,
  notifications or emails. They do not show in the profile photo, and they
  are not available through `profile-photo.php`.

## Goals

- A student can request equipment for an activity they are approved in,
  without being in the same place as the coordinator.
- The coordinator of that activity owns the decision and the return date.
  The student's proposed date is the starting point, and the office may
  still adjust it at handover (see anti-goals). Every change of the date is
  traceable.
- The office can confirm at handover that the person collecting is the
  student who asked.
- The office keeps sole control of the physical hand-over and the return.
- A request and its loan stay linked, so the office, the coordinator and the
  student see one story from request to return.

## Non-goals

- Penalties, fines or blocking students with overdue items.
- Borrowing not tied to an activity.
- Students collecting equipment without going through the office.
- Coordinators recording the hand-over or the return themselves.
- Changes to how coordinators and staff make their own reservations.

## Anti-goals (failure even if it technically works)

- A student request skips the coordinator: it is approved by default, or the
  office can release items for it before anyone approved it.
- A coordinator can see or approve requests for activities they are not
  assigned to.
- The return date the coordinator set is silently replaced at hand-over. Staff
  may adjust it, but the original must not be lost without trace.
- A request is refused at approval with no reason shown. If availability
  changed after the student asked, the coordinator and the student must see
  why.
- A student can request an item marked office-only, by any route.
- **An ID photo reaches anyone outside its circle,** through a guessable URL,
  a list, an export, a backup download page or an error message. This is the
  most serious failure in this design.
- A student's old ID photos stay on disk after they replace them, or after
  their account is deactivated.

## Constraints

- `AGENTS.md` rules hold:
  - role gates and `require_activity_access()`;
  - CSRF on every form and bound queries;
  - `status_badge()` / `status_meta()` for every new status word;
  - one gold button per page;
  - phone first for the student screens (students mainly use phones);
  - `bin/smoke.php` green.
- `docs/03-role-matrix.md` changes and must be updated with the code:
  - **Request a reservation:** student ◐ (only for an activity where their
    registration is approved).
  - **Approve / reject reservation:** coordinator ◐ (only student requests for
    assigned activities).
  - **New row, view loans from student requests:** coordinator ◐ (assigned
    activities only), staff ✔, admin ✔, student ◐ (own only).
  - **New row, mark an item office-only:** staff ✔, admin ✔.
  - **New row, upload / replace own ID photos:** student ✔.
  - **New row, view a student's ID photos:** the office ◐ (while the student
    has an approved request not yet collected, or a loan out), coordinator ◐ (only while that student's request for
    an assigned activity is pending), student ◐ (own only), Guest ✘.
  - Coordinators still do not approve other coordinators' or staff
    reservations.
- The smoke test's page table (`bin/smoke.php`) must reflect any page a
  student can now open.
- Native PHP, no new dependencies.

## Left to the builder

- **Data model.**
  - Whether a student request is a `reservations` row with the student as
    `requested_by`, or a new table. Reusing `reservations` keeps the conflict
    checks, the borrowing link and the `fulfilled` status for free, and looks
    like the smaller change.
  - The student's proposed return date fits `needed_until`. There can be
    three dates: the student's proposal, the coordinator's date and a change
    by the office at handover. Keep each one, or log each change in the audit
    trail, so none is replaced silently. The coordinator's date prefills
    `expected_return_at` when staff record the loan.
  - Where the two ID photo files are recorded per student.
  - Plus a per-item "students may request" flag on `inventory_items`, and a
    database update script for existing installs.
- **How a student request is told apart** from a coordinator or staff
  reservation, so the review lanes and gates can filter on it.
- **Screens.** Where the student starts a request (a button on the catalogue,
  and/or on the activity page), and how the coordinator sees requests (their
  review queue gains a lane for student equipment requests on their
  activities).
- **The reminder.** It needs a scheduled run. `bin/send-reminders.php` exists
  for requirement deadlines but is not scheduled by default (README, known
  gaps). Whether to extend it or add a sibling script, and how to document
  scheduling it, is the builder's call.
- Exact copy, following the existing plain, official voice.

Known issue the builder should fix along the way: the notification links that
`inventory/reservations.php` and `inventory/borrowings.php` send point at
pages students cannot open. Once students receive reservation and loan
notifications, those links must lead somewhere the student is allowed to see,
not to a 403 page.

## Out of scope for this document

The client's other items are separate projects. See `docs/feature-backlog.md`
for their designs and status. In particular, item 7 (removing the staff role)
is undecided; until it is, "staff" and "the office" in this document mean
whichever role the office ends up with.

A per-event registration form built by the coordinator was discussed and then
set aside: it was a misreading of "fill up form", not the client's request.
