<?php
/**
 * CASMS — Request a reservation of costumes or equipment (FR-6.4)
 *
 * Students cannot reserve; coordinators and office staff can.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

// Activities the requester may reserve against. A coordinator sees only
// theirs; staff and admin see everything still upcoming.
$activities = is_office_staff()
    ? fetch_all("SELECT activity_id, title, start_at FROM activities
                  WHERE status IN ('draft','upcoming','ongoing')
                  ORDER BY start_at")
    : fetch_all("SELECT a.activity_id, a.title, a.start_at
                   FROM activities a
                   JOIN activity_coordinators ac ON ac.activity_id = a.activity_id
                  WHERE ac.user_id = ? AND a.status IN ('draft','upcoming','ongoing')
                  ORDER BY a.start_at", [current_user_id()]);

$items = fetch_all(
    "SELECT i.item_id, i.item_code, i.name, i.size, i.unit, i.quantity_total, ic.name AS category
       FROM inventory_items i
       JOIN inventory_categories ic ON ic.inv_category_id = i.inv_category_id
      WHERE i.status NOT IN ('damaged','under_maintenance','unavailable')
      ORDER BY ic.name, i.name"
);

$errors    = [];
$conflicts = [];

if (is_post()) {
    csrf_verify();

    $activityIn  = post('activity_id');
    $purpose     = post('purpose');
    $neededFrom  = post('needed_from');
    $neededUntil = post('needed_until');

    // Build the requested lines from the quantity inputs.
    $lines        = [];
    $quantitiesIn = $_POST['quantity'] ?? [];
    if (is_array($quantitiesIn)) {
        foreach ($quantitiesIn as $itemIdRaw => $quantityRaw) {
            $quantity = (int) $quantityRaw;
            if ($quantity > 0) {
                $lines[] = ['item_id' => (int) $itemIdRaw, 'quantity' => $quantity];
            }
        }
    }

    if ($neededFrom === '' || $neededUntil === '') {
        $errors[] = 'Both the start and end of the period are required.';
    } elseif (strtotime($neededUntil) < strtotime($neededFrom)) {
        $errors[] = 'The end of the period cannot be earlier than its start.';
    }
    if ($lines === []) {
        $errors[] = 'Select at least one item and a quantity.';
    }

    // A coordinator may only reserve against an activity assigned to them.
    if ($activityIn !== '' && ctype_digit($activityIn) && !is_office_staff()) {
        $allowed = fetch_value(
            'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
            [(int) $activityIn, current_user_id()]
        );
        if (!$allowed) {
            http_response_code(403);
            abort_page(403, 'You are not assigned to that activity.');
        }
    }

    // Availability across the requested window.
    if ($errors === []) {
        $conflicts = reservation_conflicts($lines, $neededFrom, $neededUntil);
    }

    if ($errors === [] && $conflicts === []) {
        db()->beginTransaction();
        try {
            query(
                'INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $activityIn !== '' && ctype_digit($activityIn) ? (int) $activityIn : null,
                    current_user_id(),
                    $purpose !== '' ? $purpose : null,
                    $neededFrom, $neededUntil,
                ]
            );
            $reservationId = (int) db()->lastInsertId();

            foreach ($lines as $line) {
                query(
                    'INSERT INTO reservation_items (reservation_id, item_id, quantity) VALUES (?, ?, ?)',
                    [$reservationId, $line['item_id'], $line['quantity']]
                );
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[CASMS] Reservation failed: ' . $e->getMessage());
            flash('error', 'The reservation could not be saved. Please try again.');
            redirect('inventory/reserve.php');
        }

        audit_log('create', 'reservation', $reservationId,
                  'Requested ' . count($lines) . ' item(s) for ' . $neededFrom);

        // Only the office approves reservations.
        $approvers = fetch_all(
            "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
              WHERE r.name IN ('staff','admin') AND u.status = 'active'"
        );
        notify_many(
            array_map(static fn(array $r): int => (int) $r['user_id'], $approvers),
            'reservation',
            'New reservation request',
            full_name(current_user()) . ' requested ' . count($lines) . ' item(s).',
            url('inventory/reservations.php?status=pending')
        );

        clear_old_input();
        flash('success', 'Reservation requested. The office will review it shortly.');
        redirect('inventory/reservations.php');
    }

    remember_input($_POST);
}

$pageTitle = 'Request a reservation';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('inventory/index.php') ?>">&larr; Back to inventory</a>
        <h1>Request a reservation</h1>
        <p>Hold costumes or equipment for a specific period.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($conflicts !== []): ?>
    <div class="alert alert-warning">
        <strong>Not enough stock for that period:</strong>
        <ul>
            <?php foreach ($conflicts as $conflict): ?><li><?= e($conflict) ?></li><?php endforeach; ?>
        </ul>
        Adjust the quantities or choose different dates.
    </div>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="empty">
        <strong>Nothing to reserve</strong>
        No catalog items can be reserved right now. Items that are damaged, under maintenance,
        or unavailable are left out.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">View inventory</a>
        </div>
    </div>
<?php else: ?>
<form method="post" novalidate>
    <?= csrf_field() ?>

    <section class="card">
        <div class="card-head"><h2>When and what for</h2></div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="needed_from">Needed from <span class="req">*</span></label>
                <input type="datetime-local" id="needed_from" name="needed_from" required
                       value="<?= e(old('needed_from')) ?>">
            </div>
            <div class="form-row">
                <label for="needed_until">Needed until <span class="req">*</span></label>
                <input type="datetime-local" id="needed_until" name="needed_until" required
                       value="<?= e(old('needed_until')) ?>">
            </div>
        </div>

        <div class="form-row">
            <label for="activity_id">For which activity? <span class="optional">(optional)</span></label>
            <select id="activity_id" name="activity_id">
                <option value="">Not tied to an activity</option>
                <?php foreach ($activities as $activity): ?>
                    <option value="<?= (int) $activity['activity_id'] ?>"
                        <?= old('activity_id') === (string) $activity['activity_id'] ? 'selected' : '' ?>>
                        <?= e($activity['title']) ?> (<?= e(format_date($activity['start_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label for="purpose">Purpose <span class="optional">(optional)</span></label>
            <textarea id="purpose" name="purpose"
                      placeholder="What the items will be used for"><?= e(old('purpose')) ?></textarea>
        </div>
    </section>

    <section class="card card-flush">
        <div class="card-head">
            <h2>Items</h2>
            <p class="hint">Enter a quantity beside each item you need. Leave the rest blank.</p>
        </div>

        <div class="table-wrap">
            <table class="data table-stack">
                <thead>
                    <tr><th>Item</th><th>Category</th><th>Size</th><th class="num">Owned</th><th>Quantity needed</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td data-label="Item">
                            <div>
                                <strong><?= e($item['name']) ?></strong>
                                <div class="hint"><?= e($item['item_code']) ?></div>
                            </div>
                        </td>
                        <td data-label="Category"><?= e($item['category']) ?></td>
                        <td data-label="Size">
                            <?php if ($item['size'] !== null && $item['size'] !== ''): ?>
                                <?= e($item['size']) ?>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Owned" class="num nowrap"><?= (int) $item['quantity_total'] ?> <?= e($item['unit']) ?></td>
                        <td data-label="Quantity needed">
                            <input type="number" min="0" max="<?= (int) $item['quantity_total'] ?>"
                                   name="quantity[<?= (int) $item['item_id'] ?>]"
                                   value="<?= e((string) ($_POST['quantity'][$item['item_id']] ?? '')) ?>"
                                   class="input-auto" size="4"
                                   aria-label="Quantity of <?= e($item['name']) ?> needed">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit request</button>
        <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Cancel</a>
    </div>
</form>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
