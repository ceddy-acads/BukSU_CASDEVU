# Invitations from outside groups: design

Date: 24 October 2026
Client item: "2. Invitation for sport…". This design also answers part of
"4. Outside people can access".
Status: **approved** on 24 October 2026. Not built yet; the next step is a
build plan (`superpowers:writing-plans`).

## Intent

Outside schools, LGUs, organizations and groups regularly invite BukSU
students to take part in their programs: a provincial meet, a town fiesta
performance, an inter-school festival. The client wants these invitations to
come in through CASDevU, be checked by the office, and appear on the calendar,
so the people who decide who represents BukSU know what is coming.

Example from the client: an LGU posts an invitation, and the admin or a
coordinator decides to send the BukSU Chorale.

**Choosing and sending the representatives stays outside the system.** The
feature ends once the right people know about an approved invitation.

Success: an outside group can send an invitation without an account. The office
approves or rejects it in the same queue it already works from, and the outside
group is told the outcome by email. Admins and coordinators see approved
invitations on the calendar next to BukSU's own activities, without confusing
the two.

## The flow (decided with the client's representative)

1. **The outsider submits.** A public "Submit an invitation" page needs no
   sign-in and no account. The outsider enters:
   - their organization's name and a contact person;
   - an **email address** (required, because the decision is emailed there);
   - the program's title, date and time, place and details.

   After sending, they see a thank-you message saying the office will review
   the invitation and email them.
2. **The office reviews.** The invitation appears in the review queue
   (`review.php`) as its own lane. Office staff or an admin either:
   - **approve** it;
   - **reject** it with a reason; or
   - **discard it as spam**: no email is sent, and the row is deleted.
3. **The outsider is emailed automatically.** An approval or a rejection sends
   an email to the address they gave. A rejection email includes the reason.

   **Approval means "received and posted", nothing more.** The approval email
   says the invitation has been received and shared with the unit's
   coordinators, and that BukSU will get in touch if it sends participants. It
   must not say or suggest that BukSU accepts the invitation or will attend.
   (Decided by the client's representative.)
4. **Approved invitations go on the calendar**, for the **office (staff,
   admin) and coordinators only**. They are labelled "Invitation", so they are
   never mistaken for BukSU's own activities. Students do not see them.
5. **Admins and all active coordinators get an in-app notification** when an
   invitation is approved. Invitations are not tied to an activity, so the
   per-activity coordinator scoping does not apply. Staff are not notified;
   they already work the review queue. Students get nothing.

## Decided

- The outsider has no account. The access route is a public submission page.
  The client's representative marked this as their assumption of what the
  client wants.
- The office approves every invitation before anyone else sees it.
- Only the office and coordinators see invitations: on the calendar, and in
  notifications.
- The outsider is notified by automated email, not contacted by hand.
- Who BukSU sends is decided outside the system and is not recorded.
- Approval means "received and posted", not "BukSU will attend".

## Calls made in this document (to be checked by the client's representative)

- **Optional fields:** a phone number, and one optional attachment for the
  formal invitation letter (PDF, JPG or PNG, same limits as requirement
  uploads, stored through `store_upload()`, served only to the office and
  coordinators). LGUs usually send a letter.
- **Spam protection on the public page:**
  - a hidden honeypot field;
  - a limit on submissions counted by **both** the sender's IP address and
    the email address typed, in the same spirit as the sign-in throttle in
    `includes/auth.php`. If IT exposes the server behind a reverse proxy, the
    builder must make sure the IP counted is the visitor's, not the proxy's;
  - CSRF, like every form.
- **When email is off or fails.** On a fresh install `MAIL_TRANSPORT` is
  `log`, and `send_mail()` returns true even though nothing leaves the
  server. The system must not treat that as delivered:
  - **Delivery state.** Each invitation records the state of its decision
    email: sent, logged only, or failed.
  - **The office sees it.** In log mode, or after a failure, the review lane
    and the invitation show "Email not sent: contact by hand", with the
    contact details.
  - **The outsider is not promised an email.** When email is off
    (`email_delivers()` is false), the thank-you page says the office will
    contact them, as `login_user()` already does for its own message.
- **Cancelling.** The office can cancel an approved invitation, for example
  one approved by mistake or a program the organizer called off. The status
  list is `pending/approved/rejected/cancelled`. A cancelled invitation stays
  on the calendar, marked cancelled, the same way a cancelled activity is
  shown. No email is sent.
- **Discarding spam.** Discarded submissions are deleted, not kept, so the
  system holds no personal data from junk. The attachment is deleted too.
- **No category on invitations.** Every coordinator sees every invitation. If
  the office later wants sports invitations to reach only sports coordinators,
  a category can be added.
- **Past and rejected invitations** never appear on the calendar. Approved
  ones stay there as a record after their date passes.
- **The calendar entry opens a details view** for office and coordinators:
  - organization, contact person, email, phone;
  - date and time, place, details;
  - the letter, if one was attached.

## Goals

- An outside group can invite BukSU students without an account and without
  contacting the office by hand.
- Nothing an outsider sends is visible to anyone except the office until the
  office approves it.
- The outsider always learns the decision.
- Office and coordinators see approved invitations in the calendar they
  already use.

## Non-goals

- Outsider accounts, and outsiders editing or withdrawing an invitation after
  sending it.
- Students seeing invitations, signing up for them, or being notified.
- Recording which group or students BukSU sent, or their attendance.
- Turning an invitation into a BukSU activity automatically.

## Anti-goals (failure even if it technically works)

- An unapproved or rejected invitation shows on any calendar or reaches any
  coordinator or student.
- An invitation looks like an official BukSU activity on the calendar.
- Anything an outsider typed is shown without escaping, or their attachment
  can be opened by someone who is not office or coordinator.
- The public page can be used to flood the office's queue.
- An outsider is never told what happened because email failed silently. If
  sending fails, the office must be able to see that, and contact them by
  hand.

## Constraints and dependencies

- **Reachability (outside our control).** `PRODUCT.md` says the system runs on
  the university's own server and must work on the campus network without
  internet. An outside group can reach the public page only if the university
  exposes the server to the internet. That is the university IT office's
  decision. Without it, this feature works only for visitors on campus.
- **Email.** `includes/mailer.php` sends through an SMTP account once
  `SMTP_USERNAME` and `SMTP_PASSWORD` are set in `config.local.php`. Until
  then, messages are only written to `storage/logs/mail.log` (README, "Email
  delivery"). The office must set up the account for outsiders to be told
  anything.
- **`AGENTS.md` rules hold.** The public page is the first page besides
  sign-in, sign-up and password reset that works without signing in, so it
  needs the same care as those:
  - CSRF on the form;
  - `e()` on everything echoed;
  - bound queries;
  - `abort_page()`;
  - `status_badge()` / `status_meta()` with a new `invitation` domain;
  - phone-friendly at 390px;
  - `bin/smoke.php` green.
- **`docs/03-role-matrix.md` gains rows:**
  - submit an invitation (public);
  - approve or reject an invitation (staff ✔, admin ✔);
  - view approved invitations on the calendar and in detail (coordinator ✔,
    staff ✔, admin ✔, student ✘).
- **`bin/smoke.php`** must list the new pages and the role answers for each.
- Native PHP, no new dependencies.

## Left to the builder

- Data model: a new `invitations` table, or similar, with its status
  (`pending/approved/rejected`), reviewer and remarks.
- How the calendar merges invitations with activities, and how the
  "Invitation" label looks within the existing calendar design.
- Where the public page is linked from. The sign-in page is the obvious
  place, and the office may share the link directly.
- Email wording, following the existing plain, official voice, sent from
  `MAIL_FROM_NAME`.
- Exactly how the "contact by hand" state looks in the review lane.

## Added after the Guest accounts design (item 4)

A new **Guest** role is planned (see
`2026-10-24-guest-accounts-design.md`). Invitation calendar entries and their
detail view must allow an **explicit list** of roles: the office roles and
coordinator. They must not use a rule like "everyone except students",
because that would let Guests see invitations.

## Open for later

The client's representative may add ideas to this feature later. Until the
build starts, they go into this document, not into the code.
