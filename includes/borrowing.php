<?php
/**
 * CASMS — Student equipment borrowing rules.
 *
 * Who may decide or cancel a student's request, and who may see their ID
 * photos. Pages call these; none reads current_user() (it is cached).
 */

declare(strict_types=1);

/** @return array<int, array<string, mixed>> Upcoming/ongoing activities the student is approved for. */
function student_borrow_activities(int $studentId): array
{
    return fetch_all(
        "SELECT a.activity_id, a.title, a.start_at
           FROM registrations r
           JOIN activities a ON a.activity_id = r.activity_id
          WHERE r.user_id = ? AND r.status = 'approved' AND a.status IN ('upcoming','ongoing')
          ORDER BY a.start_at",
        [$studentId]
    );
}

/** @param array<string, mixed> $user */
function has_id_photos(array $user): bool
{
    return trim((string) ($user['id_front_path'] ?? '')) !== ''
        && trim((string) ($user['id_back_path'] ?? '')) !== '';
}

function reservation_collected_quantity(int $reservationId): int
{
    return (int) fetch_value(
        'SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE reservation_id = ?',
        [$reservationId]
    );
}

/** Any open loan to the student (not yet returned). */
function student_has_open_loan(int $studentId): bool
{
    return (bool) fetch_value(
        'SELECT 1 FROM borrowings WHERE borrower_id = ? AND returned_at IS NULL LIMIT 1',
        [$studentId]
    );
}

/** An approved student request whose items are not all collected yet. */
function student_has_uncollected_approved(int $studentId): bool
{
    return (bool) fetch_value(
        "SELECT 1 FROM reservations r
          WHERE r.requested_by = ? AND r.student_request = 1 AND r.status = 'approved'
            AND (SELECT COALESCE(SUM(b.quantity), 0) FROM borrowings b WHERE b.reservation_id = r.reservation_id)
              < (SELECT COALESCE(SUM(ri.quantity), 0) FROM reservation_items ri WHERE ri.reservation_id = r.reservation_id)
          LIMIT 1",
        [$studentId]
    );
}

/** Blocks deleting the ID photos. */
function student_has_open_borrowing(int $studentId): bool
{
    return (bool) fetch_value(
        "SELECT 1 FROM reservations WHERE requested_by = ? AND student_request = 1 AND status = 'pending' LIMIT 1",
        [$studentId]
    ) || student_has_uncollected_approved($studentId) || student_has_open_loan($studentId);
}

/** Option B: approved and not fully collected, or any open loan. */
function office_may_view_id(int $studentId): bool
{
    return student_has_uncollected_approved($studentId) || student_has_open_loan($studentId);
}

function can_view_id_photos(int $studentId): bool
{
    if (current_user_id() === $studentId) {
        return true;
    }
    if (is_office_staff()) {
        return office_may_view_id($studentId);
    }
    if (has_role('coordinator')) {
        return (bool) fetch_value(
            "SELECT 1 FROM reservations r
               JOIN activity_coordinators ac ON ac.activity_id = r.activity_id AND ac.user_id = ?
              WHERE r.requested_by = ? AND r.student_request = 1 AND r.status = 'pending' LIMIT 1",
            [(int) current_user_id(), $studentId]
        );
    }
    return false;
}

/** @param array<string, mixed> $reservation */
function can_decide_reservation(array $reservation): bool
{
    if (is_office_staff()) {
        return true;
    }
    if (!has_role('coordinator') || (int) ($reservation['student_request'] ?? 0) !== 1) {
        return false;
    }
    return (bool) fetch_value(
        'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ? LIMIT 1',
        [(int) ($reservation['activity_id'] ?? 0), (int) current_user_id()]
    );
}

/** @param array<string, mixed> $reservation */
function can_cancel_reservation(array $reservation): bool
{
    if (!is_office_staff() && current_user_id() !== (int) ($reservation['requested_by'] ?? 0)) {
        return false;
    }
    $status = (string) ($reservation['status'] ?? '');
    return $status === 'pending'
        || ($status === 'approved' && reservation_collected_quantity((int) $reservation['reservation_id']) === 0);
}

/** Cancel the student's pending and approved-uncollected requests; returns how many. */
function cancel_open_student_requests(int $studentId, ?int $activityId, string $reason): int
{
    $sql = "SELECT r.reservation_id FROM reservations r
             WHERE r.requested_by = ? AND r.student_request = 1
               AND (r.status = 'pending'
                    OR (r.status = 'approved'
                        AND NOT EXISTS (SELECT 1 FROM borrowings b WHERE b.reservation_id = r.reservation_id)))";
    $params = [$studentId];
    if ($activityId !== null) {
        $sql     .= ' AND r.activity_id = ?';
        $params[] = $activityId;
    }
    $ids = array_map('intval', array_column(fetch_all($sql, $params), 'reservation_id'));
    $cancelled = 0;
    foreach ($ids as $id) {
        $done = query(
            "UPDATE reservations r SET r.status = 'cancelled'
              WHERE r.reservation_id = ? AND r.student_request = 1
                AND (r.status = 'pending'
                     OR (r.status = 'approved'
                         AND NOT EXISTS (SELECT 1 FROM borrowings b WHERE b.reservation_id = r.reservation_id)))",
            [$id]
        );
        if ($done->rowCount() !== 1) {
            continue; // released or decided since the SELECT
        }
        $cancelled++;
        foreach (fetch_all('SELECT item_id FROM reservation_items WHERE reservation_id = ?', [$id]) as $row) {
            refresh_item_status((int) $row['item_id']);
        }
        notify($studentId, 'reservation', 'Equipment request cancelled', $reason, url('inventory/reservations.php'));
    }
    return $cancelled;
}

function delete_id_photos(int $studentId): void
{
    $row = fetch_one('SELECT id_front_path, id_back_path FROM users WHERE user_id = ?', [$studentId]);
    if ($row === null) {
        return;
    }
    delete_upload($row['id_front_path']);
    delete_upload($row['id_back_path']);
    query('UPDATE users SET id_front_path = NULL, id_back_path = NULL WHERE user_id = ?', [$studentId]);
}

/** Save a new ID photo path for one side, then delete the file it replaces. */
function replace_id_photo(int $studentId, string $side, string $newRelativePath): void
{
    if ($side !== 'front' && $side !== 'back') {
        throw new InvalidArgumentException('Unknown ID side.');
    }
    $column = 'id_' . $side . '_path';   // whitelisted above
    $old    = fetch_value("SELECT $column FROM users WHERE user_id = ?", [$studentId]);
    query("UPDATE users SET $column = ? WHERE user_id = ?", [$newRelativePath, $studentId]);
    if (is_string($old) && $old !== $newRelativePath) {
        delete_upload($old);
    }
}
