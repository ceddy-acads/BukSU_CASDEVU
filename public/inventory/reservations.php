<?php
/**
 * CASMS — Review reservation requests (FR-6.5)
 *
 * Students see their own; coordinators their own plus student requests for
 * their activities; the office sees and decides on all.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator', 'student']);

$isOffice  = is_office_staff();
$isStudent = has_role('student');

// =====================================================================
// Approve / reject / cancel
// =====================================================================
if (is_post()) {
    csrf_verify();

    $action        = post('action');
    $reservationId = (int) post('reservation_id');

    $reservation = fetch_one(
        'SELECT r.*, u.first_name, u.last_name
           FROM reservations r JOIN users u ON u.user_id = r.requested_by
          WHERE r.reservation_id = ?',
        [$reservationId]
    );
    if ($reservation === null) {
        flash('error', 'That reservation no longer exists.');
        redirect('inventory/reservations.php');
    }

    if ($action === 'cancel') {
        if (!can_cancel_reservation($reservation)) {
            abort_page(403, 'You cannot cancel this reservation.');
        }
    } elseif (!can_decide_reservation($reservation)) {
        abort_page(403, 'You may not approve or reject this reservation.');
    }
    if (($action === 'approve' || $action === 'reject') && $reservation['status'] !== 'pending') {
        flash('error', 'This request has already been decided.');
        redirect('inventory/reservations.php');
    }

    $lines = fetch_all('SELECT item_id, quantity FROM reservation_items WHERE reservation_id = ?', [$reservationId]);

    switch ($action) {
        case 'approve':
            $returnAt = (string) $reservation['needed_until'];
            if ((int) $reservation['student_request'] === 1 && post('return_at') !== '') {
                $ts = strtotime(post('return_at'));
                if ($ts === false) {
                    flash('error', 'Enter a valid return date.');
                    break;
                }
                $returnAt = date('Y-m-d H:i:s', $ts);
            }
            if (strtotime($returnAt) <= strtotime((string) $reservation['needed_from'])) {
                flash('error', 'The return date must be after the start.');
                break;
            }

            // Re-check availability now: other reservations may have been
            // approved since this one was submitted.
            $conflicts = reservation_conflicts(
                array_map(static fn(array $l): array => [
                    'item_id' => (int) $l['item_id'], 'quantity' => (int) $l['quantity'],
                ], $lines),
                (string) $reservation['needed_from'],
                $returnAt,
                $reservationId
            );

            if ($conflicts !== []) {
                flash('error', 'Cannot approve: ' . implode(' ', $conflicts));
                break;
            }

            $done = query(
                "UPDATE reservations
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = NULL,
                        needed_until = ?
                  WHERE reservation_id = ? AND status = 'pending'",
                [current_user_id(), $returnAt, $reservationId]
            );
            if ($done->rowCount() !== 1) {
                flash('error', 'This request has already been decided.');
                break;
            }
            foreach ($lines as $line) {
                refresh_item_status((int) $line['item_id']);
            }

            audit_log('approve', 'reservation', $reservationId,
                      'Approved reservation for ' . full_name($reservation));
            $changed = strtotime($returnAt) !== strtotime((string) $reservation['needed_until']);
            if ($changed) {
                audit_log('update', 'reservation', $reservationId,
                          'Return date changed from ' . format_datetime($reservation['needed_until'])
                          . ' to ' . format_datetime($returnAt));
            }
            if ((int) $reservation['student_request'] === 1) {
                $note = 'Approved. Collect the items from the office, and return them by ' . format_datetime($returnAt) . '.'
                      . ($changed ? ' (the return date was changed from the one you proposed, '
                          . format_datetime($reservation['proposed_until'] ?? $reservation['needed_until']) . ')' : '');
            } else {
                $note = 'Your reservation for ' . format_date($reservation['needed_from']) . ' has been approved.';
            }
            notify(
                (int) $reservation['requested_by'], 'reservation',
                'Reservation approved', $note,
                url('inventory/reservations.php')
            );
            flash('success', 'Reservation approved.');
            break;

        case 'reject':
            $reason = post('review_remarks');
            if ($reason === '') {
                flash('error', 'Please give a reason for the rejection.');
                break;
            }

            $done = query(
                "UPDATE reservations
                    SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
                  WHERE reservation_id = ? AND status = 'pending'",
                [current_user_id(), $reason, $reservationId]
            );
            if ($done->rowCount() !== 1) {
                flash('error', 'This request has already been decided.');
                break;
            }
            audit_log('reject', 'reservation', $reservationId, 'Rejected reservation');
            notify(
                (int) $reservation['requested_by'], 'reservation',
                'Reservation not approved', $reason,
                url('inventory/reservations.php')
            );
            flash('success', 'Reservation rejected and the requester notified.');
            break;

        case 'cancel':
            $done = query(
                "UPDATE reservations SET status = 'cancelled'
                  WHERE reservation_id = ?
                    AND (status = 'pending'
                         OR (status = 'approved' AND NOT EXISTS
                             (SELECT 1 FROM borrowings b WHERE b.reservation_id = reservations.reservation_id)))",
                [$reservationId]
            );
            if ($done->rowCount() !== 1) {
                flash('error', 'This request can no longer be cancelled.');
                break;
            }
            foreach ($lines as $line) {
                refresh_item_status((int) $line['item_id']);
            }
            audit_log('update', 'reservation', $reservationId, 'Cancelled reservation');
            if ((int) $reservation['requested_by'] !== current_user_id()) {
                notify(
                    (int) $reservation['requested_by'], 'reservation',
                    'Reservation cancelled', 'Your equipment request was cancelled by the office.',
                    url('inventory/reservations.php')
                );
            }
            flash('success', 'Reservation cancelled.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    redirect('inventory/reservations.php?' . http_build_query(array_filter(['status' => get('status')])));
}

// =====================================================================
// Listing
// =====================================================================
$statusFilter = get('status');

$conditions = [];
$params     = [];

if (!$isOffice) {
    if (has_role('coordinator')) {
        $conditions[] = '(r.requested_by = ? OR (r.student_request = 1 AND r.activity_id IN
                         (SELECT activity_id FROM activity_coordinators WHERE user_id = ?)))';
        $params[]     = current_user_id();
        $params[]     = current_user_id();
    } else {
        $conditions[] = 'r.requested_by = ?';
        $params[]     = current_user_id();
    }
}
if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'cancelled', 'fulfilled'], true)) {
    $conditions[] = 'r.status = ?';
    $params[]     = $statusFilter;
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$reservations = fetch_all(
    "SELECT r.*, u.first_name, u.last_name, a.title AS activity_title
       FROM reservations r
       JOIN users u          ON u.user_id     = r.requested_by
       LEFT JOIN activities a ON a.activity_id = r.activity_id
       $where
      ORDER BY FIELD(r.status,'pending','approved','fulfilled','rejected','cancelled'),
               r.needed_from ASC",
    $params
);

// Line items for each reservation, in one query rather than N.
$lineMap = [];
if ($reservations !== []) {
    $ids          = array_map(static fn(array $r): int => (int) $r['reservation_id'], $reservations);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $allLines     = fetch_all(
        "SELECT ri.reservation_id, ri.quantity, i.name, i.item_code, i.unit
           FROM reservation_items ri
           JOIN inventory_items i ON i.item_id = ri.item_id
          WHERE ri.reservation_id IN ($placeholders)
          ORDER BY i.name",
        $ids
    );
    foreach ($allLines as $line) {
        $lineMap[(int) $line['reservation_id']][] = $line;
    }
}

$pageTitle = 'Reservations';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $isStudent ? 'Your equipment requests' : 'Reservations' ?></h1>
        <p><?= $isOffice ? 'All reservation requests' : ($isStudent ? 'Equipment you asked to borrow for your activities' : 'Your requests and student requests for your activities') ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Inventory</a>
        <?php if ($isStudent): ?>
            <a class="btn btn-gold" href="<?= url('inventory/borrow.php') ?>">Borrow equipment</a>
        <?php else: ?>
            <a class="btn btn-gold" href="<?= url('inventory/reserve.php') ?>">New request</a>
        <?php endif; ?>
    </div>
</div>

<?php // Requests awaiting a decision now open in the notification pop-up: includes/attention.php. ?>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'fulfilled', 'rejected', 'cancelled'] as $option): ?>
                <option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                    <?= e(ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($statusFilter !== ''): ?>
            <a class="btn btn-outline" href="<?= url('inventory/reservations.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($reservations === []): ?>
    <?php if ($statusFilter !== ''): ?>
        <div class="empty">
            <strong>No reservations with this status</strong>
            Nothing matches this filter. Clear it to see every request.
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('inventory/reservations.php') ?>">Clear filter</a>
            </div>
        </div>
    <?php else: ?>
        <div class="empty">
            <?php if ($isStudent): ?>
                <strong>No equipment requests yet</strong>
                Requests you make to borrow equipment for an activity you are approved for appear here.
                <div class="btn-row">
                    <a class="btn btn-primary" href="<?= url('inventory/borrow.php') ?>">Borrow equipment</a>
                </div>
            <?php else: ?>
                <strong>No reservations yet</strong>
                <?= $isOffice ? 'No requests have been made. Reserve costumes or equipment ahead of an activity.'
                              : 'Your own requests and student requests for your activities appear here.' ?>
                <div class="btn-row">
                    <a class="btn btn-primary" href="<?= url('inventory/reserve.php') ?>">Request a reservation</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php foreach ($reservations as $reservation): ?>
        <?php
        $lines     = $lineMap[(int) $reservation['reservation_id']] ?? [];
        $rid       = (int) $reservation['reservation_id'];
        $mayDecide = $reservation['status'] === 'pending' && can_decide_reservation($reservation);
        $mayCancel = can_cancel_reservation($reservation);
        $seeId     = (int) $reservation['student_request'] === 1 && can_view_id_photos((int) $reservation['requested_by']);
        ?>
        <section class="card">
            <div class="item">
                <div class="item-main">
                    <?= status_badge($reservation['status'], 'reservation') ?>
                    <h2 class="item-title">
                        <?= e(format_datetime($reservation['needed_from'])) ?>
                        to <?= e(format_datetime($reservation['needed_until'])) ?>
                    </h2>
                    <p class="meta">
                        Requested by <?= e(full_name($reservation)) ?>
                        <?php if ($reservation['activity_title']): ?>
                            &middot; for <?= e($reservation['activity_title']) ?>
                        <?php endif; ?>
                    </p>
                    <?php if ((int) $reservation['student_request'] === 1 && $reservation['proposed_until']): ?>
                        <p class="meta">Proposed return: <?= e(format_datetime($reservation['proposed_until'])) ?></p>
                    <?php endif; ?>
                    <?php if ($seeId): ?>
                        <p class="meta">
                            <a href="<?= url('id-photo.php?user=' . (int) $reservation['requested_by'] . '&side=front') ?>" target="_blank" rel="noopener">View ID (front)</a>
                            &middot;
                            <a href="<?= url('id-photo.php?user=' . (int) $reservation['requested_by'] . '&side=back') ?>" target="_blank" rel="noopener">View ID (back)</a>
                        </p>
                    <?php endif; ?>
                    <?php if ($reservation['purpose']): ?>
                        <p class="item-body"><?= e($reservation['purpose']) ?></p>
                    <?php endif; ?>

                    <?php if ($reservation['review_remarks']): ?>
                        <div class="alert alert-error">
                            <strong>Reason given:</strong> <?= e($reservation['review_remarks']) ?>
                        </div>
                    <?php endif; ?>

                    <ul class="bullets item-body">
                        <?php foreach ($lines as $line): ?>
                            <li>
                                <?= (int) $line['quantity'] ?> &times; <?= e($line['name']) ?>
                                <span class="muted small">(<?= e($line['item_code']) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if ($mayDecide || $mayCancel || ($reservation['status'] === 'approved' && $isOffice)): ?>
                <div class="item-aside">
                    <?php if ($mayDecide): ?>
                        <form method="post" class="mb-4" data-loading>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="reservation_id" value="<?= $rid ?>">
                            <?php if ((int) $reservation['student_request'] === 1): ?>
                                <div class="form-row mb-2">
                                    <label for="return_at_<?= $rid ?>">Return by</label>
                                    <?php // Not before now, and not before the request's start (the server also checks the start). ?>
                                    <input type="datetime-local" id="return_at_<?= $rid ?>" name="return_at"
                                           min="<?= e(date('Y-m-d\TH:i', max(time(), (int) strtotime((string) $reservation['needed_from'])))) ?>"
                                           value="<?= e(date('Y-m-d\TH:i', strtotime((string) $reservation['needed_until']))) ?>">
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-block">Approve</button>
                        </form>
                        <form method="post" class="mb-4" data-loading>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="reservation_id" value="<?= $rid ?>">
                            <div class="form-row mb-2">
                                <input type="text" name="review_remarks" required placeholder="Reason for rejection"
                                       aria-label="Reason for rejecting this reservation">
                            </div>
                            <button type="submit" class="btn btn-outline btn-block btn-sm">Reject</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($mayCancel): ?>
                        <form method="post" data-loading
                              onsubmit="return confirm('Cancel this reservation request?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="reservation_id" value="<?= $rid ?>">
                            <button type="submit" class="btn btn-outline btn-block">Cancel request</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($reservation['status'] === 'approved' && $isOffice): ?>
                        <a class="btn btn-primary btn-block"
                           href="<?= url('inventory/borrowings.php?reservation_id=' . $rid) ?>">
                            Release items
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
