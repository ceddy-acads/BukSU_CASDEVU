# Client feature backlog

New features the client asked for in October 2026. The client gave only short
labels, so each item is brainstormed one at a time into a written design.
After a design is approved, it gets a build plan and then the build.

| # | Client's words | Status | Design |
|---|---|---|---|
| 1 | Fill up form | **Built and merged** (PR #1, 25 Oct 2026) | [Student equipment borrowing](superpowers/specs/2026-10-24-student-equipment-borrowing-design.md) |
| 2 | Invitation for sport | **Built and merged** (PR #5, 25 Oct 2026) | [Invitations from outside groups](superpowers/specs/2026-10-24-outside-invitations-design.md) |
| 3 | Coaches | Not brainstormed | — |
| 4 | Outside people can access | **Built and merged** (PR #4, 25 Oct 2026) | [Guest accounts](superpowers/specs/2026-10-24-guest-accounts-design.md) |
| 5 | Attendance if there is an event ("basin pwede ma implement", if it can be done) | Not brainstormed | — |
| 6 | Newsletter (narrative report): the office just uploads the report | **Built and merged** (PR #3, 25 Oct 2026) | [Highlight reports (flipbook)](superpowers/specs/2026-10-24-highlight-reports-design.md) |
| 7 | Remove the staff role (raised 24 Oct 2026 by the client's representative) | Not brainstormed | — |
| 8 | Fix: profile photos are visible to any signed-in user (found 24 Oct 2026) | **Built and merged** with PR #1: a photo is served to its owner and the office only; everyone else gets 404 | See below |

### Item 8: profile photo privacy fix

`public/profile-photo.php` serves any user's photo to any signed-in user, by
id. A student can fetch other students' photos by trying ids one by one.

- **Rule (as built):** a user may fetch their own photo, and the office
  (staff and admins) may fetch anyone's. Everyone else, coordinators
  included, gets 404.
- **Check first:** which pages show other people's photos (the user list, the
  participants list, the header avatar), so the rule does not break them.
- **Guests:** they get their own photo only. The Guest design (item 4)
  already says so.
- **Done when:** a student or Guest asking for someone else's photo gets
  nothing, and `bin/smoke.php` checks it.

### Item 7: what removing the staff role touches

These are facts to start the brainstorm from. Nothing is decided yet.

- **Accounts.** The database has 4 roles: admin (2 accounts), staff (4),
  coordinator (5) and student (34) in the demo data. Existing staff accounts
  need a destination, most likely admin or coordinator.
- **Permissions.** Staff and admin differ in `docs/03-role-matrix.md`:
  - Admin only: delete activities; create, edit, deactivate or change the
    role of any user; reset passwords; manage inventory categories; office
    settings; the audit trail; backup and restore.
  - Removing staff means the office's people either get all of that (they
    become admins) or the admin role is split differently.
- **Code.** 22 files under `public/`, `includes/` and `bin/` mention `'staff'`.
  This includes `is_office_staff()`, the role gates, `bin/smoke.php` and its
  staff test account.
- **Docs.** `docs/03-role-matrix.md`, `docs/01-requirements-spec.md`,
  `PRODUCT.md`, `AGENTS.md` and the README all describe four roles.
- **Approved designs.** Items 1 and 2 mention "office staff and admins". Until
  item 7 is decided, read "staff" there as "the office". Whichever role the
  office ends up with does those steps.

## Decisions that affect every item

- **The system will be published online** (decided 24 Oct 2026 by the
  client's representative). It will no longer be a campus-network-only
  install. Consequences:
  - **Docs.** `PRODUCT.md` ("must work on a campus network without internet
    access, so fonts and assets are self-hosted"), `docs/01-requirements-spec.md`
    and the README describe an offline install and must be updated when the
    first online-dependent feature is built.
  - **Reachability.** Items 2 (outside invitations) and 4 (Guest accounts)
    listed reachability from off campus as a dependency outside our control.
    That is now settled.
  - **Hosting.** Going online needs a host or the university's IT office to
    expose the server, HTTPS, a real email account for `includes/mailer.php`,
    and a review of every page that works without signing in (sign-in,
    sign-up, password reset, the item 2 invitation form). These pages are now
    reachable by anyone on the internet.
  - **Outside services.** Online services such as Heyzine flipbooks become
    possible. Self-hosting is still the safer default where it costs little.

## Notes for the next brainstorms

- "Fill up form" turned out to mean the student equipment borrow request, not
  a registration form. Do not assume what a label means; ask.
- A rough, low-confidence reading of the client meeting recording (Whisper
  could not handle the Cebuano well) suggested these points. Confirm each one
  before relying on it:
  - **Invitational events:** someone outside the university, possibly from
    the province, invites participants. It can be for sports or for culture,
    and it is for students only.
  - **Events limited to a class or section:** an admin or a coordinator can
    set these up.
  - **Attendance:** photo proof was mentioned, possibly with a check that the
    phone is inside the campus. Attendance may be handled by the SBO.
  - **Reports:** they need to be exportable.
  - **Inventory:** items should have photos.
- Set aside: a per-event registration form built by the coordinator (custom
  questions, Google Forms style). It came from misreading item 1 and is not a
  client request, but the idea is worked out if the client ever asks for it.
