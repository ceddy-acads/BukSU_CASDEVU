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

    if (is_office_staff()) {
        // Accounts awaiting activation — the office's most common Week 1 task.
        $pendingAccounts = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        if ($pendingAccounts > 0) {
            $items[] = [
                'tone'    => 'warning',
                'icon'    => 'users',
                'title'   => 'Accounts waiting for approval',
                'message' => $pendingAccounts . ' student account' . ($pendingAccounts === 1 ? ' is' : 's are')
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
    }

    if (current_role() === 'student') {
        // Same set as the documents page (requirements/mine.php): completed
        // activities no longer ask for anything.
        $missing = (int) fetch_value(
            "SELECT COUNT(*) FROM v_missing_requirements m
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

    return $items;
}
