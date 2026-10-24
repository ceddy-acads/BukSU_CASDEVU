# Guest accounts for people outside the student body: design

Date: 24 October 2026
Client item: "4. Outside people can access" (user that is not a BukSU
student, or not from BukSU, can sign in, with limited access).
Status: **approved** on 24 October 2026. Not built yet; the next step is a
build plan (`superpowers:writing-plans`).

## Intent

Parents and outside coaches want to follow the unit's schedule: what is on,
when and where. The client wants people who are not BukSU students to have
their own sign-in with **view-only** access to the schedule. The client
expects to add more to this role later, so it is named and described
generally, not as "parent" or "coach".

Success: a parent or coach can sign up, be activated by an admin, and then see
the same schedule a student sees. They can never see anything about individual
students, or reach any page that changes data beyond their own profile.

## Relation to other items

- **Item 2 (invitations)** uses a public form with **no account**. That stays
  as approved. Guests do not see invitations.
- **Item 7 (remove the staff role)** is not decided yet. This design speaks
  of "the admin" as the person who activates guests. If item 7 changes which
  role the office uses, that role does it.

## Decided with the client's representative

1. **A new role, Guest.** This is the name shown everywhere: the user list,
   the role badge and the role matrix.
2. **Self sign-up with admin activation.** The sign-up page first asks:
   - **"I'm a BukSU student"**: the current form, unchanged;
   - **"I'm not a BukSU student"**: name, email, phone, password, and one
     free-text field, "How are you connected to the unit's activities?". A
     parent or coach describes themselves there.

   The account starts **pending**. An admin reads the description and
   activates or declines it, exactly as student sign-ups are handled today.
   No student number, course, year level or section is asked for.
3. **A Guest sees the whole schedule, the same as students:**
   - the activity list and each activity's details;
   - the calendar;
   - announcements.
4. **A Guest also has their own profile, settings and password reset.**

## Calls made in this document (accepted by the client's representative as a group)

- An admin can also create a Guest account directly.
- **The Guest dashboard** shows upcoming activities and the latest
  announcements, the student panels without registration or requirement
  numbers. There are no stat tiles about the guest, since they have no
  registrations.
- **A Guest cannot:**
  - register for activities (the Register box does not appear);
  - upload documents or request equipment;
  - see the costume and equipment catalogue;
  - see participant lists, student records, reports or the review queue;
  - see outside invitations.
- **No notifications to Guests at first.** They check the schedule when they
  want to.

## Calls settled after the builder review

- **Who activates Guests.** Whoever activates student sign-ups activates
  Guest sign-ups, in the same place (`admin/users.php`). Today that is staff
  and admin. After item 7, it is whichever role the office ends up with. The
  "new sign-up" notice goes to the same people. The role matrix gains the row
  "Activate pending Guest accounts", with the same answers as the student row.
- **No role changes to or from Guest.** On the user list, a Guest cannot be
  changed into a student, coordinator or admin, and no account can be changed
  into a Guest. This avoids:
  - student accounts with no student number;
  - Guests left holding registrations and documents.

  If someone signed up on the wrong path, the admin declines that account
  and the person signs up again on the right one. The user list must offer
  no role change on Guest rows, and the server must refuse one.
- **Creating a Guest directly is new work.** No page can create a user today
  (only `register.php` inserts users). Build a small "Add a guest" form on the
  user list that reuses the temporary-password pattern of the existing
  password reset (the password is shown once and copied). If this turns out
  bigger than expected, it can be dropped without hurting the rest. Self
  sign-up covers the need.
- **Profile photos.** `profile-photo.php` serves any user's photo to any
  signed-in user, by id. A Guest may fetch **only their own** photo, so a
  Guest cannot collect student photos by trying ids. (Students can do the
  same today; that is an existing issue outside this project, noted for the
  office.)
- **Invitations are allow-listed, not "not a student".** Whichever of item 2
  and this item ships second must make sure invitation calendar entries and
  their detail view use an explicit list of allowed roles: the office roles
  and coordinator. A rule written as "everyone except students" must not be
  used, because it would let Guests in. The same rule goes into the item 2
  design.

## Goals

- A Guest role with the least access that still shows the full schedule.
- No path, by link or by typing a URL, from a Guest account to student data,
  the office's pages, or any action other than editing their own profile.
- Admins handle Guest sign-ups in the same place and the same way as student
  sign-ups.

## Non-goals

- Linking a Guest to a particular student or team ("my child's events").
- Guests registering, uploading, borrowing or receiving notifications.
- Item 2's invitation submission. That stays a public form.

## Anti-goals (failure even if it technically works)

- A Guest reaches any page or file with student data: participants,
  documents, records, reports or exports.
- A Guest lands on the office's dashboard or sees office numbers. Today
  `public/index.php` uses `if ($role === 'student') … else …`, so any
  non-student role falls into the office branch.
- A Guest is activated without an admin seeing their description.
- The student sign-up path gets harder or longer because of the new choice.

## Constraints and things the builder must know

- **Pages that let in any signed-in user.** These use bare `require_login()`,
  so a new role gets them by default. Each must be checked deliberately:
  - **allow, with the guest view:** `index.php`, `activities/index.php`,
    `activities/view.php`, `activities/calendar.php`,
    `announcements/index.php`, `profile.php`, `settings.php`;
  - **allow for their own record only:** `profile-photo.php`;
  - **deny or hide:** `inventory/index.php`, `inventory/photo.php`;
  - **check what it authorizes:** `notifications.php` (no notifications are
    planned) and `requirements/download.php` (it authorizes inside the page;
    confirm a Guest gets nothing).
- **Inside pages Guests may open, role branches assume four roles.** For
  example, the dashboard's student/else split, the activity page's Register
  and Manage boxes, the calendar's student-only "my status", and the sidebar
  menu. Every `else` that means "office" has to stop meaning "anyone who is
  not a student".
- **Database:**
  - a new row in `roles`;
  - the student-only profile fields (`student_number`, `course_id`,
    `year_level_id`, `section`) are already nullable in
    `database/schema.sql`, so no change is needed there;
  - the free-text "connection" answer needs a place to live.

  Declining sets an account to `inactive`, as for students, so a declined
  Guest can still be activated later.
- **`docs/03-role-matrix.md`** gains a **Guest** column: ✔ for viewing
  published activities, the calendar and announcements, and for their own
  profile; ✘ elsewhere. `bin/smoke.php` checks every page against the matrix,
  so it needs a Guest test account in `database/demo-data.sql` and the Guest
  answers for every page.
- **`AGENTS.md` rules hold:** gates before output, `status_badge()` for the
  new role word, `bin/smoke.php` green, 390px.
- **Reachability.** A parent at home can reach the system only if the
  university exposes the server to the internet (`PRODUCT.md`: campus-network
  install). This is the same dependency as item 2, and it is outside this
  project.

## Left to the builder

- Data model details: where the "connection" text is stored, and how the
  student-only columns become optional.
- The sign-up layout for the two paths, keeping the current four-step student
  form intact.
- How the admin's user list shows a Guest's description so it can be read
  before activating.
- Exact copy, in the existing plain, official voice.

## Open for later

The client expects to add more permissions to Guests. Until the build starts,
those go into this document as new decisions, not into the code.
