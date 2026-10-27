# CASMS — Role Permission Matrix

Authoritative source for authorization. Every controller must check these
**server-side**. Hiding a link in the UI is presentation, not security (NFR-4).

Legend: **✔** full · **◐** limited (see note) · **✘** none

Guests are people outside the student body, such as parents and outside
coaches. Activities and the calendar are schedule-only for them: no
registration, requirements, inventory, participant-management pages or reports.
Guests may read the names of approved players on published activity pages.

---

## Activities

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| View published activities | ✔ | ✔ | ✔ | ✔ | ✔ |
| View draft activities | ✘ | ✘ | ◐ own | ✔ | ✔ |
| Create activity | ✘ | ✘ | ◐ submits pending; automatically assigned | ✔ | ✔ |
| Edit activity details / eligibility | ✘ | ✘ | ◐ own pending or rejected submissions only | ✔ | ✔ |
| Change published activity status | ✘ | ✘ | ✘ | ✔ | ✔ |
| Approve / reject a coordinator activity | ✘ | ✘ | ✘ | ✘ | ✔ |
| Delete activity | ✘ | ✘ | ✘ | ✘ | ✔ |
| Assign coordinators | ✘ | ✘ | ✘ | ✔ | ✔ |

## Highlights

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| Upload / replace / delete a highlight report | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Read highlight reports | ✔ | ✔ | ✔ | ✔ | ✔ |

Read highlight reports also works signed out (public page); a Guest account cannot upload.

## Participation

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| Register for an activity | ✘ | ✔ | ✘ | ✘ | ✘ |
| Withdraw own registration | ✘ | ◐ before approval | ✘ | ✘ | ✘ |
| View own registration status | ✘ | ✔ | — | — | — |
| Read approved-player names on a published activity | ✔ | ✔ | ✔ | ✔ | ✔ |
| View participant management / pending registrations | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Approve / reject registration | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Mark attendance | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |

## Requirements

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| Define activity requirements | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Upload own requirement file | ✘ | ✔ | ✘ | ✘ | ✘ |
| View own submissions | ✘ | ✔ | — | — | — |
| View any student's submissions | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Verify / reject a submission | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |

## Inventory

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| View catalog and availability | ✘ | ✔ | ✔ | ✔ | ✔ |
| Request a reservation | ✘ | ✔ with an admin-approved school ID and a required typed activity/purpose; linking a registered activity is optional and requires approved registration | ✔ | ✔ | ✔ |
| Approve / reject reservation | ✘ | ✘ | ◐ only student requests for assigned activities | ✔ | ✔ |
| Record release (borrowing) | ✘ | ✘ | ✘ | ✔ | ✔ |
| Record return | ✘ | ✘ | ✘ | ✔ | ✔ |
| Add / edit / delete items | ✘ | ✘ | ✘ | ✔ | ✔ |
| View loans from student requests | ✘ | ◐ own only | ◐ assigned activities only | ✔ | ✔ |
| Mark an item office-only | ✘ | ✘ | ✘ | ✔ | ✔ |
| Upload / replace own ID photos | ✘ | ✔ | ✘ | ✘ | ✘ |
| View a student's ID photos | ✘ | ◐ own only | ◐ approved ID, while that student's request for an assigned activity is pending | ◐ approved ID, while the student has an approved request not yet collected, or a loan out | ✔ ID review and office duties |

## Announcements and Notifications

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| Read announcements | ✔ | ✔ | ✔ | ✔ | ✔ |
| Save announcement drafts / submit for approval | ✘ | ✘ | ◐ own posts for assigned activities | ✔ | ✔ |
| Publish announcement directly | ✘ | ✘ | ✘ | ✔ own office workflow | ✔ |
| Approve / reject coordinator announcements | ✘ | ✘ | ✘ | ✘ | ✔ |
| Edit published announcements | ✘ | ✘ | ✘ | ✔ | ✔ |
| Delete any announcement | ✘ | ✘ | ✘ | ✘ | ✔ |
| Receive notifications | ◐ account notices only | ✔ | ✔ | ✔ | ✔ |

## Records and Reports

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| View own participation history | ✘ | ✔ | ✔ | ✔ | ✔ |
| Browse all student records | ✘ | ✘ | ◐ own participants | ✔ | ✔ |
| Generate participation report | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |
| Generate inventory report | ✘ | ✘ | ✘ | ✔ | ✔ |
| Export / print reports | ✘ | ✘ | ◐ assigned only | ✔ | ✔ |

## Invitations from outside groups

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| Submit an invitation (public form, no account needed) | ✔ | ✔ | ✔ | ✔ | ✔ |
| View posted invitations on the calendar and in detail | ◐ own account-linked programs only | ✘ | ✔ | ✔ | ✔ |
| View pending or rejected invitations | ◐ own account-linked programs only | ✘ | ✘ | ✔ | ✔ |
| Approve / reject / discard / cancel public invitations | ✘ | ✘ | ✘ | ✔ | ✔ |
| Approve / reject / discard / cancel guest programs | ✘ | ✘ | ✘ | ✘ | ✔ |
| Track submitted guest programs | ◐ own only | ✘ | ✘ | ✘ | ✔ |

The submission form remains public. Signed-in guests submit account-linked
programs using their account email. Guests can track their own submissions
and see their own approved programs on the calendar; other guests' programs
remain private. Approval emails track sent, logged, or failed delivery and
can be retried without repeating the decision. Public invitations retain the
existing office workflow. Coordinator participants, requirements, attendance,
and equipment duties remain available after activity publication.

## Administration

| Action | Guest | Student | Coordinator | Staff | Admin |
|--------|:-----:|:-------:|:-----------:|:-----:|:-----:|
| Edit own profile | ✔ | ✔ | ✔ | ✔ | ✔ |
| View another user's profile photo | ✘ | ✘ | ✘ | ✔ | ✔ |
| Choose own email and list settings | ◐ list setting only | ✔ | ✔ | ✔ | ✔ |
| Edit the office's name, university, upload limit | ✘ | ✘ | ✘ | ✘ | ✔ |
| Activate pending student accounts | ✘ | ✘ | ✘ | ✔ | ✔ |
| Activate pending Guest accounts | ✘ | ✘ | ✘ | ✔ | ✔ |
| Create / edit / deactivate any user | ✘ | ✘ | ✘ | ✘ | ✔ |
| Change a user's role | ✘ | ✘ | ✘ | ✘ | ✔ |
| Change a role to or from Guest | ✘ | ✘ | ✘ | ✘ | ✘ |
| Reset a user's password | ✘ | ✘ | ✘ | ✘ | ✔ |
| Manage categories | ✘ | ✘ | ✘ | ◐ activity only | ✔ |
| View audit trail | ✘ | ✘ | ✘ | ✘ | ✔ |
| Backup / restore | ✘ | ✘ | ✘ | ✘ | ✔ |

---

## Implementation Pattern

Two gates, applied on every request that is not public:

```php
// includes/auth.php

/** Blocks the request unless the user holds one of the given roles. */
function require_role(array $allowed): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php'); exit;
    }
    if (!in_array($_SESSION['role'], $allowed, true)) {
        http_response_code(403);
        exit('403 — You do not have permission to access this page.');
    }
}

/** For the ◐ rows: a coordinator may only touch activities assigned to them. */
function require_activity_access(PDO $db, int $activityId): void {
    if (in_array($_SESSION['role'], ['staff', 'admin'], true)) {
        return;                                  // unrestricted
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM activity_coordinators
          WHERE activity_id = ? AND user_id = ?'
    );
    $stmt->execute([$activityId, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        exit('403 — You are not assigned to this activity.');
    }
}
```

Usage at the top of a protected page:

```php
require_role(['staff', 'coordinator', 'admin']);
require_activity_access($db, (int) $_GET['activity_id']);
```

**Rule of thumb:** if a page reads or writes data belonging to a specific
activity, it needs *both* calls — the role gate and the ownership gate.

Only admins approve or reject school IDs. Pending/rejected IDs are visible only
to the owner and admins. Staff and assigned coordinators retain their existing
ID access scopes after approval. All registration reviewers must verify every
mandatory requirement before approval; activities without mandatory requirements
remain approvable.
