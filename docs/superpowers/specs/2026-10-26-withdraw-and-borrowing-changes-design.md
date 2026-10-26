# Withdraw after approval, borrowing without an activity, and borrowing from the catalogue: design

Date: 26 October 2026
Client items: 12, 13 and 14 in `docs/feature-backlog.md` (student dashboard
feedback, 26 Oct 2026).
Status: **agreed in chat** on 26 October 2026. Built together with
`2026-10-26-school-id-approval-design.md` (items 10 and 11) from **one** build
plan (the user asked for one plan and one live upload).

Read the school ID design first: items 13 and 14 depend on its
`users.id_status` and its rule that a request cannot be approved or handed
over until the student's ID is approved.

---

## Item 12: withdraw after the registration is approved

### Intent

Client: "There should be a withdraw button even though the registered student
already approved." The user: withdrawing is the student's freedom; no one
approves it.

Today `public/participation/register.php` refuses to withdraw anything but a
`pending` registration ("Once the office has approved a place, withdrawing is
their call"), and `public/activities/view.php` shows the button only while
pending. That rule is reversed.

### Design

- A student may withdraw a registration whose status is `pending` **or
  `approved`**, while the activity is not `completed` or `cancelled`. Not when
  the registration is `rejected`, `completed` or already `withdrawn`.
- It takes effect at once (`withdraw_registration()` as today). A confirm
  dialog first: "Withdraw from this activity? Your place will be given up."
- The button shows on the activity page (`activities/view.php`) and on each
  matching row of **My activities** (`participation/my-activities.php`).
- When an **approved** registration is withdrawn, the activity's reviewers
  (`activity_reviewer_ids()`: its coordinators, or the office when it has
  none) get an in-app notification: "<Name> withdrew from <Activity>". No
  email. *(Design call.)*
- Unchanged: `withdraw_registration()` already cancels the student's open
  equipment requests for that activity; items already collected must still
  be returned; the student may register again later.
- Update the comment in `register.php` that explains the old rule, and
  `docs/03-role-matrix.md` if it states the old rule.

---

## Item 13: borrowing without an activity

### Intent

Client: "Student can still borrow equipment even though he/she didn't join or
register an activity." This is a **wish, not a bug report**: the user
explained that equipment is not only for activities, e.g. a section needs
equipment for its PE class. Today a student can borrow only for an activity
they are approved in (`student_borrow_activities()`; checked on local and
live on 26 Oct 2026: a student without one sees "No activity to borrow for").

### Design

- When borrowing, the student chooses **what it is for**:
  - one of their approved upcoming or ongoing activities (as today), or
  - **something else**, with a short required description (for example
    "PE class"). One text box, not a structured form. Saved in the existing
    `reservations.purpose`; `reservations.activity_id` stays `NULL`. **No
    database change.**
- **Who decides a request with no activity: any coordinator**, and the office
  as today. All coordinators may approve (user: "naay ability si coordinator
  na mag approve"). The first decision wins; the existing
  "already decided" check in `inventory/reservations.php` covers the race.
- Change, in `includes/review.php` and `includes/borrowing.php`:
  - `review_reservation_scope()`: a coordinator also sees student requests
    with `activity_id IS NULL`;
  - `can_decide_reservation()`: a coordinator may decide a student request
    with no activity;
  - `can_view_id_photos()`: the coordinator rule also matches a pending
    student request with no activity.
- Notification on a new request with no activity: every active coordinator
  (in-app), plus the office if there are none.
- The request lists (`inventory/reservations.php`) and the review lane show
  such a request as **"Not for an activity"** with the description. No
  report shows borrow requests by activity today (`borrowing_report()` has
  no activity column), so no report work. Check every query that joins
  `reservations` to `activities` with an inner `JOIN` and make it a
  `LEFT JOIN` where a no-activity request must still appear. *(Design call
  after the builder check.)*
- **What a coordinator sees in `inventory/reservations.php`** (it has its
  own coordinator filter, not `review_reservation_scope()`): besides their
  activities as today, every **pending** no-activity student request, plus
  the no-activity requests **they decided** themselves. Not other
  coordinators' decided ones, so no coordinator browses every student's
  borrowing history. Update the role matrix row "View loans from student
  requests" to match. *(Design call after the builder check.)*
- The school ID rule applies the same way: approved ID needed (see the school
  ID design).
- Replace the copy that says borrowing is only for activities
  ("Ask your coordinator to release equipment for an activity you're in",
  "You can borrow equipment only for an activity you're approved in",
  the catalogue intro "Need something for an activity?").

---

## Item 14: borrow from the Costumes & equipment page

### Intent

Client: "The layout for borrow request and costume & equipment should be the
same." Today the catalogue (`inventory/index.php`) is a card grid with photos,
search and filters, while `inventory/borrow.php` step 2 is a plain table with
quantity boxes. The user's plan: the student picks items **on the catalogue's
cards**, and the rest happens in a **popup**. Shopping-like, but not a
shopping system: no cart page, no cart badge, nothing saved for later.

Mockups shown in chat (visual companion, 26 Oct 2026):
`.superpowers/brainstorm/67213-1792997291/content/borrow-select-popup.html`
is the agreed one.

### Design

1. **Pick on the cards.** For a student, tapping a requestable card selects
   it; a − / + quantity appears on the card. A bar fixed at the bottom shows
   "N items chosen" and **Borrow these**. Items that are not requestable
   (`student_requestable = 0`, damaged, under maintenance, unavailable) cannot
   be picked. Picks are kept in the browser (`sessionStorage`) while the
   student searches, filters or changes catalogue pages (the live search
   rebuilds the cards, and `PER_PAGE` splits the list), and are put back on
   the cards after each swap; the bar, the popup and the POST carry picks
   that are not on screen. Sending the request, or closing the tab, clears
   them. *(Design call after the builder check.)*
2. **The popup** (the shared `<dialog>` in `app.js`, `[data-modal]`
   pattern) lists the chosen items with quantities, then:
   - what it is for: an activity, or something else with a description
     (item 13);
   - needed from and will return by;
   - **Send request** (`btn-primary`, `data-loading`).
   Stock for the chosen dates is checked on submit with the existing conflict
   logic; shortfalls are shown in the popup per item and nothing is saved.
3. **After sending**: "Request sent. Waiting for approval." and the student
   lands on **My borrow requests** (`inventory/reservations.php`), which
   stays: it is where the student follows status, sees return dates and
   cancels a request that is not yet approved.
4. **School ID not approved**: the student can still browse and pick, but the
   popup shows the ID status and a link to My profile instead of the form.
   The ID is uploaded once, on My profile only (user, 26 Oct 2026).
5. **`inventory/borrow.php` as a page goes away.** Its POST handling
   (validation, conflict check, insert, notify) moves to where the popup posts.
   The builder may keep `borrow.php` as that handler and as the no-JavaScript
   fallback, as long as students no longer land on the old table page from
   any link. Remove or redirect the old links ("Borrow equipment" buttons on
   the catalogue and My borrow requests, the sidebar highlight in
   `includes/layout/header.php`).
6. Only students get selection. Coordinators and the office keep
   `inventory/reserve.php` and the catalogue as it is.
7. Phone (390px): cards stay in the existing grid, the bottom bar does not
   cover the last row (pad the page), the popup fits without sideways scroll.

---

## Checks (all three items)

- `bin/smoke.php`: withdraw POST for an approved registration; the catalogue
  as a student with selection markup; the borrow handler; a coordinator
  seeing and deciding a no-activity request; the role matrix still matches.
- `bin/check-borrowing.php`: no-activity request saved with `activity_id`
  `NULL`; scope and decision rules for coordinators; reports include it.
- Lint, smoke, and the catalogue, popup, My borrow requests, My activities and
  activity page at 390px and desktop.

## Left to the builder

Exact copy beyond the quotes above, how the selection is marked on a card, the
popup's layout, and whether `borrow.php` survives as the handler.

## Also fix while here

Pending documents from a withdrawn registration stay in the review queue
today. Item 12 makes withdrawn registrations more common, so filter them out
of `review_counts()` and `review_pending_documents()`. *(Found by the builder
check.)*
