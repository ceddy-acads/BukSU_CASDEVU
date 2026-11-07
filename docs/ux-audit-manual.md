# CASDevU — manual UX audit kit

You do the audit. This file only keeps you on track. Tick boxes as you go
(VS Code: edit `[ ]` to `[x]`). Write every problem in the **Findings** table at
the bottom the moment you see it — do not trust memory.

Earlier automated pass: `docs/ux-audit-2026-10.md` (24 Oct 2026). Pages marked
**NEW** did not exist then, so check them first.

## Setup (5 minutes)

- [ ] Start the site: `cd public; C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .`
- [ ] Open `http://127.0.0.1:8080/login.php` in Chrome.
- [ ] Back up the database first, because the tasks below change data:
      `C:\xampp\mysql\bin\mysqldump.exe -u root casms > storage\backups\casms-before-manual-audit.sql`
- [ ] Phone view: press `F12`, then `Ctrl+Shift+M`, pick a 390px-wide device.
- [ ] Use a private window per role, so sign-ins do not mix.

Passwords: demo accounts use `demo1234`. Admin is `admin@buksu.edu.ph` / `admin123`.

## How to run it

- One session = one role. About 30–45 minutes each. Stop between sessions.
- Do the **task**, not the page tour. Act like that person on a busy day.
- Do each task once at desktop width, then once at 390px.
- Whenever you hesitate, re-read, or guess — that is a finding. Log it.

### Ask these 6 questions on every screen

1. **Where am I?** The title and the menu show which page this is.
2. **What do I do here?** The main action is obvious. Only one gold button.
3. **Did it work?** After a save, a clear message says what changed.
4. **What went wrong?** Errors say the cause and the fix, next to the field.
5. **Why is it empty?** Empty lists say why, and what fills them.
6. **Phone OK?** No sideways scroll. Buttons are big enough for a thumb.

Severity: **P0** blocks the task · **P1** wrong result or lost work ·
**P2** slows or confuses · **P3** cosmetic.

## Session 1 — Student (`andrea.santos@student.buksu.edu.ph`)

- [ ] Dashboard: can you tell, in 5 seconds, what needs your attention?
- [ ] Find an activity and register (`activities/index.php`, then `activities/join.php`) — **NEW** join flow
- [ ] Upload a requirement (`requirements/submit.php`). Then upload a wrong file type on purpose.
- [ ] Check status in **My requirements** (`requirements/mine.php`). Does it say whose turn it is?
- [ ] **My activities** (`participation/my-activities.php`): does each status make sense?
- [ ] Borrow equipment (`inventory/borrow.php`) — **NEW**. Try one item, then several.
- [ ] Read a highlight (`highlights/index.php`, then open one) — **NEW**
- [ ] Notifications (bell, then `notifications.php`) — **NEW** page. Does each one take you to the right place?
- [ ] Profile and settings: change something, then confirm it saved.

## Session 2 — Coordinator (`bautista.coach@buksu.edu.ph`)

- [ ] Open the assigned activity (Intramurals 2026). Is it easy to find?
- [ ] See participants (`participation/participants.php`). Find one student fast.
- [ ] Verify a requirement (`requirements/verify.php`). Approve one, reject one with a reason.
- [ ] Reserve equipment for the activity (`inventory/reserve.php`).
- [ ] Post a highlight (`highlights/manage.php`) — **NEW**
- [ ] Try to open an activity you are **not** assigned to. Is the refusal clear?

## Session 3 — Staff (`rosales.office@buksu.edu.ph`)

- [ ] Review queue (`review.php`): clear 3 items. Count the clicks per item.
- [ ] Approve and reject a registration. Reject once without a reason.
- [ ] Approve a reservation (`inventory/reservations.php`).
- [ ] Handle a student borrowing (`inventory/borrowings.php`) — **NEW** student borrowing
- [ ] Create an activity (`activities/manage.php`), then set eligibility and coordinators.
- [ ] Post an announcement (`announcements/manage.php`).
- [ ] Run a report, export CSV, then print it (`reports/index.php`). Is anything missing on paper?

## Session 4 — Admin (`admin@buksu.edu.ph`)

- [ ] Approve a pending account (`admin/users.php`).
- [ ] School ID verification (`admin/id-verification.php`) — **NEW**. Approve one, reject one.
- [ ] Guest program approvals (`approvals.php`) — **NEW**
- [ ] Categories, venues, settings: add one, edit it, remove it.
- [ ] Audit log (`admin/audit.php`): can you find what you just did?
- [ ] Backup page (`admin/backup.php`): read it only. Do **not** restore.

## Session 5 — Guest and signed-out visitor

- [ ] Sign up (`register.php`) and forgot password (`forgot-password.php`). Errors clear?
- [ ] Sign in with the pending guest `lim.guest@example.com`. Does the message say what to wait for?
- [ ] As `reyes.guest@example.com`: submit a program (`invitations/submit.php`) — **NEW**
- [ ] Track it (`invitations/mine.php`) and open the letter — **NEW**
- [ ] Signed out: open `highlights/index.php`. Does the public view make sense?

## After all sessions

- [ ] Restore the data: `C:\xampp\mysql\bin\mysql.exe -u root casms < storage\backups\casms-before-manual-audit.sql`
- [ ] Sort the findings by severity. Fix P0 and P1 first.

## Findings

| # | Role | Page | Width | What happened | Severity |
|---|---|---|---|---|---|
| 1 |  |  |  |  |  |
