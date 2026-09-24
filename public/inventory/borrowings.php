<?php
/**
 * CASMS — Release and return records (FR-6.6, FR-6.7)
 *
 * The physical ledger: who took what, when it is due, and what came back.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

// =====================================================================
// Release / return
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ------------------------------------------------------------ Release
    if ($action === 'release') {
        $itemId       = (int) post('item_id');
        $borrowerId   = (int) post('borrower_id');
        $quantity     = (int) post('quantity');
        $expectedBack = post('expected_return_at');
        $reservationIn = post('reservation_id');

        $item     = fetch_one('SELECT * FROM inventory_items WHERE item_id = ?', [$itemId]);
        $borrower = fetch_one('SELECT user_id, first_name, last_name FROM users WHERE user_id = ?', [$borrowerId]);

        if ($item === null || $borrower === null) {
            flash('error', 'Please choose both an item and a borrower.');
            redirect('inventory/borrowings.php');
        }
        if ($quantity < 1) {
            flash('error', 'Quantity must be at least 1.');
            redirect('inventory/borrowings.php');
        }
        if ($expectedBack === '') {
            flash('error', 'An expected return date is required.');
            redirect('inventory/borrowings.php');
        }

        // Cannot hand out more than is physically free.
        $free = quantity_available_now($itemId);
        if ($quantity > $free) {
            flash('error', 'Only ' . $free . ' unit(s) of ' . $item['name'] . ' are available right now.');
            redirect('inventory/borrowings.php');
        }

        // Validate the reservation before the INSERT. Without this, an
        // unknown id reaches the foreign key and raises an uncaught
        // PDOException — a 500 page instead of a readable message.
        $linkedReservationId = null;
        if ($reservationIn !== '') {
            if (!ctype_digit($reservationIn)) {
                flash('error', 'That reservation reference is not valid.');
                redirect('inventory/borrowings.php');
            }

            $linkedReservationId = (int) $reservationIn;
            $reservation = fetch_one(
                'SELECT reservation_id, status FROM reservations WHERE reservation_id = ?',
                [$linkedReservationId]
            );

            if ($reservation === null) {
                flash('error', 'That reservation does not exist.');
                redirect('inventory/borrowings.php');
            }
            if (!in_array($reservation['status'], ['approved', 'fulfilled'], true)) {
                flash('error', 'Items can only be released against an approved reservation. '
                    . 'This one is ' . $reservation['status'] . '.');
                redirect('inventory/borrowings.php');
            }

            // The item must actually be on that reservation, otherwise the
            // fulfilment check below would be measuring the wrong thing.
            $onReservation = fetch_value(
                'SELECT 1 FROM reservation_items WHERE reservation_id = ? AND item_id = ?',
                [$linkedReservationId, $itemId]
            );
            if (!$onReservation) {
                flash('error', $item['name'] . ' is not part of that reservation. '
                    . 'Release it without a reservation, or choose the correct item.');
                redirect('inventory/borrowings.php');
            }
        }

        query(
            'INSERT INTO borrowings
                 (reservation_id, item_id, borrower_id, quantity, released_by, released_at, expected_return_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)',
            [
                $linkedReservationId,
                $itemId, $borrowerId, $quantity, current_user_id(), $expectedBack,
            ]
        );
        $borrowingId = (int) db()->lastInsertId();

        refresh_item_status($itemId);

        // A reservation whose items are all out has been fulfilled.
        if ($linkedReservationId !== null) {
            $reservationId = $linkedReservationId;
            $stillPending  = (int) fetch_value(
                'SELECT COUNT(*) FROM reservation_items ri
                  WHERE ri.reservation_id = ?
                    AND ri.quantity > COALESCE((SELECT SUM(b.quantity) FROM borrowings b
                                                 WHERE b.reservation_id = ri.reservation_id
                                                   AND b.item_id = ri.item_id), 0)',
                [$reservationId]
            );
            if ($stillPending === 0) {
                query("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?", [$reservationId]);
            }
        }

        audit_log('create', 'borrowing', $borrowingId,
                  'Released ' . $quantity . ' x ' . $item['name'] . ' to ' . full_name($borrower));
        notify(
            $borrowerId, 'reservation', 'Item released to you',
            $quantity . ' x ' . $item['name'] . ', due back ' . format_datetime($expectedBack),
            url('inventory/borrowings.php')
        );

        flash('success', $quantity . ' x ' . $item['name'] . ' released to ' . full_name($borrower) . '.');
        redirect('inventory/borrowings.php');
    }

    // ------------------------------------------------------------- Return
    if ($action === 'return') {
        $borrowingId = (int) post('borrowing_id');
        $condition   = post('return_condition', 'good');
        $remarks     = post('return_remarks');

        $borrowing = fetch_one(
            'SELECT b.*, i.name AS item_name FROM borrowings b
               JOIN inventory_items i ON i.item_id = b.item_id
              WHERE b.borrowing_id = ?',
            [$borrowingId]
        );
        if ($borrowing === null) {
            flash('error', 'That borrowing record no longer exists.');
            redirect('inventory/borrowings.php');
        }
        if ($borrowing['returned_at'] !== null) {
            flash('error', 'That item has already been returned.');
            redirect('inventory/borrowings.php');
        }
        if (!in_array($condition, ['good', 'damaged', 'lost'], true)) {
            flash('error', 'Please choose a valid condition.');
            redirect('inventory/borrowings.php');
        }

        query(
            'UPDATE borrowings
                SET returned_at = NOW(), received_by = ?, return_condition = ?, return_remarks = ?
              WHERE borrowing_id = ?',
            [current_user_id(), $condition, $remarks !== '' ? $remarks : null, $borrowingId]
        );

        // Something broken or lost is flagged for the office rather than
        // silently returning to the available pool.
        if ($condition === 'damaged') {
            query("UPDATE inventory_items SET status = 'damaged', condition_note = ? WHERE item_id = ?",
                  [$remarks !== '' ? $remarks : 'Returned damaged', $borrowing['item_id']]);
        } elseif ($condition === 'lost') {
            query('UPDATE inventory_items
                      SET quantity_total = GREATEST(0, quantity_total - ?),
                          condition_note = ?
                    WHERE item_id = ?',
                  [(int) $borrowing['quantity'], 'Unit(s) reported lost', $borrowing['item_id']]);
            refresh_item_status((int) $borrowing['item_id']);
        } else {
            refresh_item_status((int) $borrowing['item_id']);
        }

        audit_log('update', 'borrowing', $borrowingId,
                  'Returned ' . $borrowing['item_name'] . ' in ' . $condition . ' condition');

        flash('success', $borrowing['item_name'] . ' marked as returned (' . $condition . ').');
        redirect('inventory/borrowings.php');
    }
}

// =====================================================================
// Listing
// =====================================================================
$filter        = get('filter', 'open');
$reservationId = get_id('reservation_id');

$conditions = [];
$params     = [];

if ($filter === 'open') {
    $conditions[] = 'b.returned_at IS NULL';
} elseif ($filter === 'overdue') {
    $conditions[] = 'b.returned_at IS NULL AND b.expected_return_at < NOW()';
} elseif ($filter === 'returned') {
    $conditions[] = 'b.returned_at IS NOT NULL';
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$borrowings = fetch_all(
    "SELECT b.*, i.name AS item_name, i.item_code, i.unit,
            u.first_name, u.last_name, u.student_number,
            rel.first_name AS released_by_first, rel.last_name AS released_by_last
       FROM borrowings b
       JOIN inventory_items i ON i.item_id = b.item_id
       JOIN users u           ON u.user_id = b.borrower_id
       JOIN users rel         ON rel.user_id = b.released_by
       $where
      ORDER BY b.returned_at IS NOT NULL, b.expected_return_at ASC",
    $params
);

// Release form options.
$availableItems = fetch_all(
    "SELECT i.item_id, i.item_code, i.name, i.unit, i.quantity_total,
            COALESCE((SELECT SUM(b.quantity) FROM borrowings b
                       WHERE b.item_id = i.item_id AND b.returned_at IS NULL), 0) AS quantity_out
       FROM inventory_items i
      WHERE i.status NOT IN ('damaged','under_maintenance','unavailable')
      ORDER BY i.name"
);
$borrowers = fetch_all(
    "SELECT user_id, first_name, last_name, student_number FROM users
      WHERE status = 'active' ORDER BY last_name, first_name"
);

// Pre-fill from an approved reservation when arriving from that screen.
$prefill = null;
if ($reservationId !== null) {
    $prefill = fetch_one(
        'SELECT r.*, u.first_name, u.last_name FROM reservations r
           JOIN users u ON u.user_id = r.requested_by
          WHERE r.reservation_id = ?',
        [$reservationId]
    );
}

$openCount    = (int) fetch_value('SELECT COUNT(*) FROM borrowings WHERE returned_at IS NULL');
$overdueCount = overdue_borrowing_count();

$pageTitle = 'Borrowed items';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Borrowed items</h1>
        <p><?= $openCount ?> currently out<?= $overdueCount > 0 ? ', ' . $overdueCount . ' overdue' : '' ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('inventory/reservations.php') ?>">Reservations</a>
        <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Inventory</a>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>Release an item</h2></div>

    <?php if ($prefill): ?>
        <div class="alert alert-info">
            Releasing against the reservation by <?= e(full_name($prefill)) ?>
            for <?= e(format_datetime($prefill['needed_from'])) ?>.
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="release">
        <?php if ($reservationId !== null): ?>
            <input type="hidden" name="reservation_id" value="<?= $reservationId ?>">
        <?php endif; ?>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="item_id">Item <span class="req">*</span></label>
                <select id="item_id" name="item_id" required>
                    <option value="">Select an item</option>
                    <?php foreach ($availableItems as $item): ?>
                        <?php $free = (int) $item['quantity_total'] - (int) $item['quantity_out']; ?>
                        <option value="<?= (int) $item['item_id'] ?>" <?= $free < 1 ? 'disabled' : '' ?>>
                            <?= e($item['name']) ?> (<?= e($item['item_code']) ?>), <?= $free > 0 ? $free . ' available' : 'none available' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="borrower_id">Borrower <span class="req">*</span></label>
                <select id="borrower_id" name="borrower_id" required>
                    <option value="">Select a person</option>
                    <?php foreach ($borrowers as $person): ?>
                        <option value="<?= (int) $person['user_id'] ?>"
                            <?= $prefill && (int) $prefill['requested_by'] === (int) $person['user_id'] ? 'selected' : '' ?>>
                            <?= e(full_name($person, true)) ?>
                            <?= $person['student_number'] ? ' (' . e($person['student_number']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="quantity">Quantity <span class="req">*</span></label>
                <input type="number" id="quantity" name="quantity" min="1" value="1" required>
            </div>
            <div class="form-row">
                <label for="expected_return_at">Expected return <span class="req">*</span></label>
                <input type="datetime-local" id="expected_return_at" name="expected_return_at" required
                       value="<?= $prefill ? e(date('Y-m-d\TH:i', strtotime((string) $prefill['needed_until']))) : '' ?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Record release</button>
        </div>
    </form>
</section>

<h2 class="section-title">Borrowing records</h2>

<form method="get" class="filter-bar filter-bar-compact">
    <div class="form-row">
        <label for="filter">Show</label>
        <select id="filter" name="filter" onchange="this.form.submit()">
            <option value="open"     <?= $filter === 'open'     ? 'selected' : '' ?>>Currently out</option>
            <option value="overdue"  <?= $filter === 'overdue'  ? 'selected' : '' ?>>Overdue only</option>
            <option value="returned" <?= $filter === 'returned' ? 'selected' : '' ?>>Returned</option>
            <option value="all"      <?= $filter === 'all'      ? 'selected' : '' ?>>All records</option>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
</form>

<?php if ($borrowings === []): ?>
    <div class="empty">
        <?php if ($filter === 'overdue'): ?>
            <strong>No overdue items</strong>
            Everything that is out is still within its expected return date.
        <?php elseif ($filter === 'open'): ?>
            <strong>Nothing is out right now</strong>
            Every borrowed item has been returned. Use the form above to record a release.
        <?php elseif ($filter === 'returned'): ?>
            <strong>No returns recorded yet</strong>
            Returned items appear here once a return is recorded.
        <?php else: ?>
            <strong>No borrowing records yet</strong>
            Use the form above to record the first release.
        <?php endif; ?>
        <?php if ($filter !== 'all'): ?>
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('inventory/borrowings.php?filter=all') ?>">Show all records</a>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card card-flush">
        <div class="table-wrap">
            <table class="data" data-sortable>
                <thead>
                    <tr>
                        <th data-sort="text">Item</th><th data-sort="text">Borrower</th><th class="num">Qty</th>
                        <th data-sort="number">Released</th><th data-sort="number">Due back</th><th>Returned</th><th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($borrowings as $borrowing): ?>
                    <tr>
                        <td>
                            <strong><?= e($borrowing['item_name']) ?></strong>
                            <div class="hint"><?= e($borrowing['item_code']) ?></div>
                        </td>
                        <td>
                            <?= e(full_name($borrowing, true)) ?>
                            <?php if ($borrowing['student_number']): ?>
                                <div class="hint"><?= e($borrowing['student_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $borrowing['quantity'] ?></td>
                        <td class="nowrap" data-sort-value="<?= (int) strtotime((string) $borrowing['released_at']) ?>">
                            <?= e(format_date($borrowing['released_at'])) ?>
                            <div class="hint">by <?= e($borrowing['released_by_first'] . ' ' . $borrowing['released_by_last']) ?></div>
                        </td>
                        <td class="nowrap" data-sort-value="<?= (int) strtotime((string) $borrowing['expected_return_at']) ?>">
                            <?php if (is_overdue($borrowing)): ?>
                                <?php $daysLate = max(1, (int) floor((time() - strtotime((string) $borrowing['expected_return_at'])) / 86400)); ?>
                                <span class="text-danger"><?= e(format_date($borrowing['expected_return_at'])) ?></span>
                                <div>
                                    <span class="badge badge-danger">Overdue by <?= $daysLate ?> day<?= $daysLate === 1 ? '' : 's' ?></span>
                                </div>
                            <?php else: ?>
                                <?= e(format_date($borrowing['expected_return_at'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?php if ($borrowing['returned_at']): ?>
                                <?= e(format_date($borrowing['returned_at'])) ?>
                                <div>
                                    <?php $conditionClass = match ($borrowing['return_condition']) {
                                        'damaged' => 'badge-warning',
                                        'lost'    => 'badge-danger',
                                        default   => 'badge-success',
                                    }; ?>
                                    <span class="badge <?= $conditionClass ?>">
                                        <?= e(ucfirst((string) $borrowing['return_condition'])) ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span class="muted">Still out</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if ($borrowing['returned_at'] === null): ?>
                                <div class="btn-row">
                                    <button type="button" class="btn btn-outline btn-sm"
                                            aria-label="Record return of <?= e($borrowing['item_name']) ?>"
                                            onclick="document.getElementById('ret-<?= (int) $borrowing['borrowing_id'] ?>').hidden = false; this.hidden = true;">
                                        Record return
                                    </button>
                                </div>
                                <form method="post" id="ret-<?= (int) $borrowing['borrowing_id'] ?>" hidden>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="return">
                                    <input type="hidden" name="borrowing_id" value="<?= (int) $borrowing['borrowing_id'] ?>">
                                    <div class="form-row mb-2">
                                        <select name="return_condition" class="input-sm" aria-label="Condition on return">
                                            <option value="good">Returned in good condition</option>
                                            <option value="damaged">Returned damaged</option>
                                            <option value="lost">Reported lost</option>
                                        </select>
                                    </div>
                                    <div class="form-row mb-2">
                                        <input type="text" name="return_remarks" class="input-sm" placeholder="Remarks (optional)"
                                               aria-label="Return remarks (optional)">
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm">Confirm return</button>
                                </form>
                            <?php elseif ($borrowing['return_remarks']): ?>
                                <span class="hint"><?= e($borrowing['return_remarks']) ?></span>
                            <?php else: ?>
                                <span class="muted small">No remarks</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
