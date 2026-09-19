# CASMS — Role Permission Matrix

Authoritative source for authorization. Every controller must check these
**server-side**. Hiding a link in the UI is presentation, not security (NFR-4).

Legend: **✔** full · **◐** limited (see note) · **✘** none

---

## Activities

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| View published activities | ✔ | ✔ | ✔ | ✔ |
| View draft activities | ✘ | ◐ own | ✔ | ✔ |
| Create activity | ✘ | ✘ | ✔ | ✔ |
| Edit activity | ✘ | ◐ assigned only | ✔ | ✔ |
| Change activity status | ✘ | ◐ assigned only | ✔ | ✔ |
| Delete activity | ✘ | ✘ | ✘ | ✔ |
| Assign coordinators | ✘ | ✘ | ✔ | ✔ |

## Participation

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| Register for an activity | ✔ | ✘ | ✘ | ✘ |
| Withdraw own registration | ◐ before approval | ✘ | ✘ | ✘ |
| View own registration status | ✔ | — | — | — |
| View participant list | ✘ | ◐ assigned only | ✔ | ✔ |
| Approve / reject registration | ✘ | ◐ assigned only | ✔ | ✔ |
| Mark attendance | ✘ | ◐ assigned only | ✔ | ✔ |

## Requirements

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| Define activity requirements | ✘ | ◐ assigned only | ✔ | ✔ |
| Upload own requirement file | ✔ | ✘ | ✘ | ✘ |
| View own submissions | ✔ | — | — | — |
| View any student's submissions | ✘ | ◐ assigned only | ✔ | ✔ |
| Verify / reject a submission | ✘ | ◐ assigned only | ✔ | ✔ |

## Inventory

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| View catalog and availability | ✔ | ✔ | ✔ | ✔ |
| Request a reservation | ✘ | ✔ | ✔ | ✔ |
| Approve / reject reservation | ✘ | ✘ | ✔ | ✔ |
| Record release (borrowing) | ✘ | ✘ | ✔ | ✔ |
| Record return | ✘ | ✘ | ✔ | ✔ |
| Add / edit / delete items | ✘ | ✘ | ✔ | ✔ |

## Announcements and Notifications

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| Read announcements | ✔ | ✔ | ✔ | ✔ |
| Publish announcement | ✘ | ◐ own activity | ✔ | ✔ |
| Delete any announcement | ✘ | ✘ | ✘ | ✔ |
| Receive notifications | ✔ | ✔ | ✔ | ✔ |

## Records and Reports

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| View own participation history | ✔ | ✔ | ✔ | ✔ |
| Browse all student records | ✘ | ◐ own participants | ✔ | ✔ |
| Generate participation report | ✘ | ◐ assigned only | ✔ | ✔ |
| Generate inventory report | ✘ | ✘ | ✔ | ✔ |
| Export / print reports | ✘ | ◐ assigned only | ✔ | ✔ |

## Administration

| Action | Student | Coordinator | Staff | Admin |
|--------|:-------:|:-----------:|:-----:|:-----:|
| Edit own profile | ✔ | ✔ | ✔ | ✔ |
| Activate pending student accounts | ✘ | ✘ | ✔ | ✔ |
| Create / edit / deactivate any user | ✘ | ✘ | ✘ | ✔ |
| Change a user's role | ✘ | ✘ | ✘ | ✔ |
| Manage categories | ✘ | ✘ | ◐ activity only | ✔ |
| View audit trail | ✘ | ✘ | ✘ | ✔ |
| Backup / restore | ✘ | ✘ | ✘ | ✔ |

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
