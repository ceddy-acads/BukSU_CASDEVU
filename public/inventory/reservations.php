<?php
/**
 * CASMS — Review reservation requests (FR-6.5)
 *
 * Coordinators see their own requests; the office sees and decides on all.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$canDecide = is_office_staff();

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

    // A requester may cancel their own; only the office approves or rejects.
    $isOwner = (int) $reservation['requested_by'] === current_user_id();
    if ($action === 'cancel') {
        if (!$isOwner && !$canDecide) {
            http_response_code(403);
            abort_page(403, 'You may only cancel your own reservation.');
        }
    } elseif (!$canDecide) {
        http_response_code(403);
        abort_page(403, 'Only office staff may approve or reject reservations.');
    }

    $lines = fetch_all('SELECT item_id, quantity FROM reservation_items WHERE reservation_id = ?', [$reservationId]);

    switch ($action) {
        case 'approve':
            // Re-check availability now: other reservations may have been
            // approved since this one was submitted.
            $conflicts = reservation_conflicts(
                array_map(static fn(array $l): array => [
                    'item_id' => (int) $l['item_id'], 'quantity' => (int) $l['quantity'],
                ], $lines),
                (string) $reservation['needed_from'],
                (string) $reservation['needed_until'],
                $reservationId
            );

            if ($conflicts !== []) {
                flash('error', 'Cannot approve: ' . implode(' ', $conflicts));
                break;
            }

            query(
                "UPDATE reservations
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = NULL
                  WHERE reservation_id = ?",
                [current_user_id(), $reservationId]
            );
            foreach ($lines as $line) {
                refresh_item_status((int) $line['item_id']);
            }

            audit_log('approve', 'reservation', $reservationId,
                      'Approved reservation for ' . full_name($reservation));
            notify(
                (int) $reservation['requested_by'], 'reservation',
                'Reservation approved',
                'Your reservation for ' . format_date($reservation['needed_from']) . ' has been approved.',
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

            query(
                "UPDATE reservations
                    SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
                  WHERE reservation_id = ?",
                [current_user_id(), $reason, $reservationId]
            );
            audit_log('reject', 'reservation', $reservationId, 'Rejected reservation');
            notify(
                (int) $reservation['requested_by'], 'reservation',
                'Reservation not approved', $reason,
                url('inventory/reservations.php')
            );
            flash('success', 'Reservation rejected and the requester notified.');
            break;

        case 'cancel':
            query("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?", [$reservationId]);
            foreach ($lines as $line) {
                refresh_item_status((int) $line['item_id']);
            }
            audit_log('update', 'reservation', $reservationId, 'Cancelled reservation');
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

// A coordinator only ever sees their own requests.
if (!$canDecide) {
    $conditions[] = 'r.requested_by = ?';
    $params[]     = current_user_id();
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

$pendingCount = (int) fetch_value("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");

$pageTitle = 'Reservations';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reservations</h1>
        <p><?= $canDecide ? 'All reservation requests' : 'Your reservation requests' ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Inventory</a>
        <a class="btn btn-gold" href="<?= url('inventory/reserve.php') ?>">New request</a>
    </div>
</div>

<?php if ($canDecide && $pendingCount > 0 && $statusFilter !== 'pending'): ?>
    <div class="alert alert-warning">
        <strong><?= $pendingCount ?></strong> request<?= $pendingCount === 1 ? '' : 's' ?> awaiting your decision.
        <a href="<?= url('inventory/reservations.php?status=pending') ?>">Show only pending</a>
    </div>
<?php endif; ?>

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
            <strong>No reservations yet</strong>
            No requests have been made. Reserve costumes or equipment ahead of an activity.
            <div class="btn-row">
                <a class="btn btn-primary" href="<?= url('inventory/reserve.php') ?>">Request a reservation</a>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php foreach ($reservations as $reservation): ?>
        <?php $lines = $lineMap[(int) $reservation['reservation_id']] ?? []; ?>
        <section class="card">
            <div class="item">
                <div class="item-main">
                    <?= status_badge($reservation['status']) ?>
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

                <?php if ($reservation['status'] === 'pending' || ($reservation['status'] === 'approved' && is_office_staff())): ?>
                <div class="item-aside">
                    <?php if ($reservation['status'] === 'pending'): ?>
                        <?php if ($canDecide): ?>
                            <form method="post" class="mb-4">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                <button type="submit" class="btn btn-primary btn-block">Approve</button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                <div class="form-row mb-2">
                                    <input type="text" name="review_remarks" required placeholder="Reason for rejection"
                                           aria-label="Reason for rejecting this reservation">
                                </div>
                                <button type="submit" class="btn btn-outline btn-block btn-sm">Reject</button>
                            </form>
                        <?php else: ?>
                            <form method="post"
                                  onsubmit="return confirm('Cancel this reservation request?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                <button type="submit" class="btn btn-outline btn-block">Cancel request</button>
                            </form>
                        <?php endif; ?>

                    <?php elseif ($reservation['status'] === 'approved' && is_office_staff()): ?>
                        <a class="btn btn-primary btn-block"
                           href="<?= url('inventory/borrowings.php?reservation_id=' . (int) $reservation['reservation_id']) ?>">
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
