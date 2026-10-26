<?php
/**
 * CASDevU — What needs the signed-in person's attention.
 *
 * Computed live from the records, never stored: the count, the wording and
 * the page it links to are always current. Rendered at the top of the
 * notification pop-up (includes/layout/notifications-menu.php) instead of
 * as alert boxes on the page itself, so a page never opens with a strip
 * pushing its content down.
 *
 * Each item: tone, icon, title, message, href, action.
 */
declare(strict_types=1);

function attention_items(): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }

    $items = [];
    $userId = (int) $user['user_id'];

    if (can_review()) {
        // Same counts as the review queue, so a coordinator sees only the
        // activities they are assigned to (replaces the dashboard work queue).
        $counts = review_counts();
        if ($counts['registrations'] > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'review',
                'title'   => 'Registrations to review',
                'message' => $counts['registrations'] . ' registration' . ($counts['registrations'] === 1 ? ' is' : 's are')
                             . ' waiting for a decision.',
                'href'    => 'review.php?lane=registrations',
                'action'  => 'Open the review queue',
            ];
        }
        if ($counts['documents'] > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'requirements',
                'title'   => 'Documents to verify',
                'message' => $counts['documents'] . ' document' . ($counts['documents'] === 1 ? ' is' : 's are')
                             . ' waiting to be checked.',
                'href'    => 'review.php?lane=documents',
                'action'  => 'Open the review queue',
            ];
        }
        if (!is_office_staff() && $counts['reservations'] > 0) {   // the office has its own reservation item below
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'inventory',
                'title'   => 'Equipment requests',
                'message' => $counts['reservations'] . ' student request' . ($counts['reservations'] === 1 ? ' is' : 's are')
                             . ' waiting for your decision.',
                'href'    => 'review.php?lane=reservations',
                'action'  => 'Open the review queue',
            ];
        }
    }

    if (is_office_staff()) {
        $invitations = review_counts()['invitations'];
        if ($invitations > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'review',
                'title'   => 'Invitations to review',
                'message' => $invitations . ' invitation' . ($invitations === 1 ? '' : 's')
                             . ' from outside groups ' . ($invitations === 1 ? 'needs' : 'need') . ' a decision or a reply.',
                'href'    => 'review.php?lane=invitations',
                'action'  => 'Open the review queue',
            ];
        }

        // Accounts awaiting activation — the office's most common Week 1 task.
        $pendingAccounts = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        if ($pendingAccounts > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'users',
                'title'   => 'Accounts waiting for approval',
                'message' => $pendingAccounts . ' account' . ($pendingAccounts === 1 ? ' is' : 's are')
                             . ' waiting for approval.',
                'href'    => 'admin/users.php?status=pending',
                'action'  => 'Review accounts',
            ];
        }

        $overdueLoans = overdue_borrowing_count();
        if ($overdueLoans > 0) {
            $items[] = [
                'tone'    => 'danger',
                'icon'    => 'equipment',
                'title'   => 'Overdue returns',
                'message' => $overdueLoans . ' borrowed item' . ($overdueLoans === 1 ? ' is' : 's are')
                             . ' past the expected return date.',
                'href'    => 'inventory/borrowings.php?filter=overdue',
                'action'  => 'See overdue items',
            ];
        }

        $pendingReservations = (int) fetch_value("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
        if ($pendingReservations > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'inventory',
                'title'   => 'Reservations to decide',
                'message' => $pendingReservations . ' request' . ($pendingReservations === 1 ? ' is' : 's are')
                             . ' waiting for a decision.',
                'href'    => 'inventory/reservations.php?status=pending',
                'action'  => 'Show only pending',
            ];
        }

        // Without a venue, activities cannot record a location or check double-booking.
        if ((int) fetch_value('SELECT COUNT(*) FROM venues') === 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'venues',
                'title'   => 'No venues yet',
                'message' => 'Activities cannot record a location, and double-booking checks will not run.',
                'href'    => 'admin/venues.php',
                'action'  => 'Add a venue',
            ];
        }
    }

    if (current_role() === 'admin') {
        $failedLogins = (int) fetch_value(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        if ($failedLogins > 0) {
            $items[] = [
                'tone'    => 'danger',
                'icon'    => 'audit',
                'title'   => 'Failed sign-ins',
                'message' => $failedLogins . ' failed sign-in attempt' . ($failedLogins === 1 ? '' : 's')
                             . ' in the last 7 days.',
                'href'    => 'admin/audit.php?action_type=login_failed',
                'action'  => 'Review them',
            ];
        }

        // is_dir first: backup_directory() would try to create the folder on every page.
        $backupCount = is_dir(BACKUP_PATH) ? count(list_backups()) : 0;
        if ($backupCount > BACKUP_KEEP) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'backup',
                'title'   => 'Too many backups stored',
                'message' => $backupCount . ' backups are stored, more than the ' . BACKUP_KEEP
                             . ' recommended. Delete the oldest to save space.',
                'href'    => 'admin/backup.php',
                'action'  => 'Manage backups',
            ];
        }
    }

    if (current_role() === 'student') {
        // Same set as the documents page (requirements/mine.php): completed
        // activities no longer ask for anything.
        $missing = (int) fetch_value(
            "SELECT COUNT(*) FROM (" . missing_requirements_sql() . ") m
               JOIN activities a ON a.activity_id = m.activity_id
              WHERE m.user_id = ? AND a.status <> 'completed'",
            [$userId]
        );
        if ($missing > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'requirements',
                'title'   => 'Documents to submit',
                'message' => 'You still have ' . $missing . ' document' . ($missing === 1 ? '' : 's') . ' to submit.',
                'href'    => 'participation/my-activities.php',
                'action'  => 'See what is missing',
            ];
        }
    }

    // Clicked items stay hidden while their wording is unchanged; a new count
    // changes the message and brings the item back.
    // shortcut: kept in the session, so items return after signing in again; move to a table if that annoys.
    $dismissed = $_SESSION['attention_dismissed'] ?? [];
    return array_values(array_filter(
        $items,
        static fn(array $item): bool => ($dismissed[$item['href']] ?? null) !== $item['message']
    ));
}

/** Hide the attention item that links to $href, as it reads right now. */
function dismiss_attention_item(string $href): void
{
    foreach (attention_items() as $item) {
        if ($item['href'] === $href) {
            $_SESSION['attention_dismissed'][$href] = $item['message'];
        }
    }
}
