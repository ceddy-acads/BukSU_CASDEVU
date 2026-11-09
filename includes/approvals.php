<?php
/** Publication approval is separate from an activity's operational status. */
declare(strict_types=1);

function can_edit_activity_details(array $activity): bool
{
    return is_office_staff() || (current_role() === 'coordinator'
        && (int) $activity['created_by'] === current_user_id()
        && in_array($activity['approval_status'], ['pending', 'rejected'], true));
}

function can_edit_announcement(array $announcement): bool
{
    return is_office_staff() || (current_role() === 'coordinator'
        && (int) $announcement['posted_by'] === current_user_id()
        && in_array($announcement['status'], ['draft', 'pending', 'rejected'], true));
}

/** An approved activity's status from its dates (FR-2.5). Uses the database clock, as db.php sets its time zone. */
function activity_schedule_status_sql(): string
{
    return "CASE WHEN NOW() < start_at THEN 'upcoming' WHEN end_at IS NULL OR NOW() <= end_at THEN 'ongoing' ELSE 'completed' END";
}

/**
 * Move approved activities along upcoming → ongoing → completed as their dates pass.
 * Draft, closed and cancelled are left alone: approval, closing and cancelling are decisions.
 */
function sync_activity_statuses(): void
{
    // shortcut: runs from page loads at most once a minute per session, so a status can
    // lag up to a minute; use a cron job instead if the site ever gets one.
    if (($_SESSION['activity_status_sync'] ?? 0) > time() - 60) {
        return;
    }
    $_SESSION['activity_status_sync'] = time();
    $auto = activity_schedule_status_sql();
    query("UPDATE activities SET status = $auto
            WHERE approval_status = 'approved'
              AND status IN ('upcoming', 'ongoing', 'completed')
              AND status <> $auto");
}

/** Inform only administrators when content needs their decision. */
function notify_publication_review(string $title, string $link): void
{
    $ids = array_map('intval', array_column(fetch_all(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.name = 'admin' AND u.status = 'active'"
    ), 'user_id'));
    notify_many($ids, 'system', 'Pending approval', mb_strimwidth($title, 0, 500), url($link));
}

/** Called inside the decision transaction; row locks make repeated POSTs harmless. */
function decide_publication(string $kind, int $id, string $decision, string $reason, int $expectedVersion): bool
{
    require_role(['admin']);
    if (!in_array($kind, ['activity', 'announcement'], true)
        || !in_array($decision, ['approve', 'reject'], true)) {
        abort_page(400, 'Unknown approval action.');
    }
    $isActivity = $kind === 'activity';
    $table = $isActivity ? 'activities' : 'announcements';
    $key = $isActivity ? 'activity_id' : 'announcement_id';
    $state = $isActivity ? 'approval_status' : 'status';
    $row = fetch_one("SELECT * FROM $table WHERE $key = ? FOR UPDATE", [$id]);
    if ($row === null || $row[$state] !== 'pending' || (int) $row['review_version'] !== $expectedVersion) {
        return false;
    }
    $approved = $decision === 'approve';
    if ($isActivity) {
        query("UPDATE activities SET approval_status = ?,
                   status = CASE WHEN ? = 1 THEN " . activity_schedule_status_sql() . " ELSE 'draft' END,
                   reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE activity_id = ?",
            [$approved ? 'approved' : 'rejected', $approved ? 1 : 0,
             current_user_id(), $approved ? null : $reason, $id]);
    } else {
        // Announcements about a proposed activity must wait until that activity is approved.
        if ($approved && $row['activity_id'] !== null) {
            $activity = fetch_one('SELECT approval_status FROM activities WHERE activity_id = ? FOR UPDATE', [(int) $row['activity_id']]);
            if ($activity !== null && $activity['approval_status'] !== 'approved') {
                return false;
            }
        }
        query("UPDATE announcements SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                   rejection_reason = ?, published_at = CASE WHEN ? = 1 THEN NOW() ELSE published_at END
                 WHERE announcement_id = ?",
            [$approved ? 'published' : 'rejected', current_user_id(), $approved ? null : $reason,
             $approved ? 1 : 0, $id]);
    }
    audit_log($decision, $kind, $id, ($approved ? 'Approved: ' : 'Rejected: ') . $row['title']);
    $link = $isActivity ? 'activities/view.php?id=' . $id : 'announcements/manage.php?id=' . $id;
    // Published announcements open in the feed because coordinators cannot edit them.
    if (!$isActivity && $approved) { $link = 'announcements/index.php'; }
    notify((int) $row[$isActivity ? 'created_by' : 'posted_by'], 'system',
        $approved ? 'Submission approved' : 'Submission rejected',
        mb_strimwidth($row['title'] . ($approved ? '' : ': ' . $reason), 0, 500), url($link));
    if ($approved) {
        notify_many(all_active_student_ids(), $kind,
            mb_strimwidth(($isActivity ? 'New activity: ' : '') . $row['title'], 0, 200),
            $isActivity ? 'Registration details are now available.' : mb_strimwidth(strip_tags($row['body']), 0, 160),
            url($isActivity ? 'activities/view.php?id=' . $id : 'announcements/index.php'));
    }
    return true;
}
