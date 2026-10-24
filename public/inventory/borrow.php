<?php
/**
 * CASMS — Student equipment request
 *
 * Step 1 picks the activity and dates (GET); step 2 picks items (same page,
 * POST). The coordinator of the activity decides.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

$studentId  = current_user_id();
$activities = student_borrow_activities($studentId);
// Fresh row: current_user() is cached and may predate the ID columns.
$hasId      = has_id_photos(fetch_one('SELECT id_front_path, id_back_path FROM users WHERE user_id = ?', [$studentId]) ?? []);
$canRequest = $activities !== [] && $hasId;

$items = $canRequest ? fetch_all(
    "SELECT i.item_id, i.item_code, i.name, i.size, i.unit, ic.name AS category
       FROM inventory_items i
       JOIN inventory_categories ic ON ic.inv_category_id = i.inv_category_id
      WHERE i.student_requestable = 1
        AND i.status NOT IN ('damaged','under_maintenance','unavailable')
      ORDER BY ic.name, i.name"
) : [];
$itemIds = array_map('intval', array_column($items, 'item_id'));

$errors    = [];
$conflicts = [];

$src         = is_post() ? 'post' : 'get';
$read        = static fn(string $k): string => $src === 'post' ? post($k) : get($k);
$activityIn  = $read('activity_id');
$neededFrom  = $read('needed_from');
$neededUntil = $read('needed_until');
$purpose     = $read('purpose');

$activityId = null;
foreach ($activities as $a) {
    if ((string) $a['activity_id'] === $activityIn) {
        $activityId = (int) $a['activity_id'];
    }
}

if ($canRequest && is_post()) {
    csrf_verify();

    // Tampering, not a typo: the form only offers approved activities and requestable items.
    if ($activityId === null) {
        abort_page(403, 'You can borrow equipment only for an activity you are approved in.');
    }

    $lines        = [];
    $quantitiesIn = $_POST['quantity'] ?? [];
    if (is_array($quantitiesIn)) {
        foreach ($quantitiesIn as $itemIdRaw => $quantityRaw) {
            if (!is_int($itemIdRaw) || !is_scalar($quantityRaw) || !ctype_digit(trim((string) $quantityRaw))) {
                continue;
            }
            $quantity = (int) $quantityRaw;
            if ($quantity > 0) {
                if (!in_array($itemIdRaw, $itemIds, true)) {
                    abort_page(403, 'That item cannot be requested.');
                }
                $lines[] = ['item_id' => $itemIdRaw, 'quantity' => $quantity];
            }
        }
    }

    $fromTs  = $neededFrom === '' ? false : strtotime($neededFrom);
    $untilTs = $neededUntil === '' ? false : strtotime($neededUntil);
    if ($fromTs === false || $untilTs === false) {
        $errors[] = 'Both "Needed from" and "Will return by" are required.';
    } elseif ($untilTs <= $fromTs) {
        $errors[] = '"Will return by" must be after "Needed from".';
    }
    if ($lines === []) {
        $errors[] = 'Select at least one item and a quantity.';
    }

    if ($errors === []) {
        $from  = date('Y-m-d H:i:s', $fromTs);
        $until = date('Y-m-d H:i:s', $untilTs);
        $conflicts = reservation_conflicts($lines, $from, $until);
    }

    if ($errors === [] && $conflicts === []) {
        db()->beginTransaction();
        try {
            query(
                'INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until, student_request, proposed_until)
                 VALUES (?, ?, ?, ?, ?, 1, ?)',
                [$activityId, $studentId, $purpose !== '' ? $purpose : null, $from, $until, $until]
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
            error_log('[CASMS] Student borrow request failed: ' . $e->getMessage());
            flash('error', 'The request could not be saved. Please try again.');
            redirect('inventory/borrow.php');
        }

        audit_log('create', 'reservation', $reservationId,
                  'Student requested ' . count($lines) . ' item(s) for activity ' . $activityId);

        notify_many(
            activity_reviewer_ids($activityId),
            'reservation',
            'New equipment request',
            full_name(current_user()) . ' requested ' . count($lines) . ' item(s).',
            url('inventory/reservations.php?status=pending')
        );

        clear_old_input();
        flash('success', 'Request sent. Your coordinator will review it.');
        redirect('inventory/reservations.php');
    }
}

// Step 2 shows once the dates are usable (GET) or after a failed POST.
$fromTs   = $neededFrom === '' ? false : strtotime($neededFrom);
$untilTs  = $neededUntil === '' ? false : strtotime($neededUntil);
$showStep2 = $canRequest && $activityId !== null && $fromTs !== false && $untilTs !== false && $untilTs > $fromTs;
$datesError = $canRequest && !is_post() && $neededFrom !== '' && $neededUntil !== '' && !$showStep2
    ? ($activityId === null ? 'Choose one of your activities.' : '"Will return by" must be after "Needed from".')
    : '';

$qtyIn = is_post() && is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];

$pageTitle = 'Borrow equipment';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('inventory/index.php') ?>">&larr; Back to inventory</a>
        <h1>Borrow equipment</h1>
        <p>Ask your coordinator to release equipment for an activity you're in.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($datesError !== ''): ?>
    <div class="alert alert-error"><div><?= e($datesError) ?></div></div>
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

<?php if ($activities === []): ?>
    <div class="empty">
        <strong>No activity to borrow for</strong>
        You can borrow equipment only for an activity you're approved in.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Activities</a>
        </div>
    </div>
<?php elseif (!$hasId): ?>
    <div class="empty">
        <strong>School ID needed</strong>
        Add both sides of your school ID before borrowing.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('profile.php#school-id') ?>">Add school ID</a>
        </div>
    </div>
<?php else: ?>

<form method="get" novalidate>
    <section class="card">
        <div class="card-head"><h2>When and what for</h2></div>

        <div class="form-row">
            <label for="activity_id">Activity <span class="req">*</span></label>
            <select id="activity_id" name="activity_id" required>
                <option value="">Choose an activity</option>
                <?php foreach ($activities as $activity): ?>
                    <option value="<?= (int) $activity['activity_id'] ?>"
                        <?= $activityIn === (string) $activity['activity_id'] ? 'selected' : '' ?>>
                        <?= e($activity['title']) ?> (<?= e(format_date($activity['start_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="needed_from">Needed from <span class="req">*</span></label>
                <input type="datetime-local" id="needed_from" name="needed_from" required
                       value="<?= e($neededFrom) ?>">
            </div>
            <div class="form-row">
                <label for="needed_until">Will return by <span class="req">*</span></label>
                <input type="datetime-local" id="needed_until" name="needed_until" required
                       value="<?= e($neededUntil) ?>">
            </div>
        </div>

        <div class="form-row">
            <label for="purpose">Purpose <span class="optional">(optional)</span></label>
            <textarea id="purpose" name="purpose"
                      placeholder="What the items will be used for"><?= e($purpose) ?></textarea>
        </div>

        <?php if (!$showStep2): ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Choose items</button>
            </div>
        <?php else: ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-outline">Update dates</button>
            </div>
        <?php endif; ?>
    </section>
</form>

<?php if ($showStep2): ?>
    <?php if ($items === []): ?>
        <div class="empty">
            <strong>Nothing to borrow yet</strong>
            The office has not opened any items for student requests, or they are all out of service.
        </div>
    <?php else: ?>
    <?php $fromSql = date('Y-m-d H:i:s', $fromTs); $untilSql = date('Y-m-d H:i:s', $untilTs); ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="activity_id" value="<?= (int) $activityId ?>">
        <input type="hidden" name="needed_from" value="<?= e($neededFrom) ?>">
        <input type="hidden" name="needed_until" value="<?= e($neededUntil) ?>">
        <input type="hidden" name="purpose" value="<?= e($purpose) ?>">

        <section class="card card-flush">
            <div class="card-head">
                <h2>Items</h2>
                <p class="hint">These numbers can change before your coordinator approves.</p>
            </div>

            <div class="table-wrap">
                <table class="data table-stack">
                    <thead>
                        <tr><th>Item</th><th>Category</th><th>Size</th><th class="num">Free for those dates</th><th>Quantity needed</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $free = quantity_available_between((int) $item['item_id'], $fromSql, $untilSql); ?>
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
                            <td data-label="Free for those dates" class="num nowrap"><?= $free ?> <?= e($item['unit']) ?></td>
                            <td data-label="Quantity needed">
                                <input type="number" min="0" max="<?= $free ?>"
                                       name="quantity[<?= (int) $item['item_id'] ?>]"
                                       value="<?= e(is_scalar($qtyIn[$item['item_id']] ?? null) ? (string) $qtyIn[$item['item_id']] : '') ?>"
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
            <button type="submit" class="btn btn-primary" data-loading="Saving…">Send request</button>
            <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
