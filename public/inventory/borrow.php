<?php
/**
 * CASMS — Student equipment request
 *
 * Item cards open a single-item form; the full page also supports multiple
 * items. A typed activity/purpose is required. Registered activity links are
 * optional; the office reviews requests without a registered activity link.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

$studentId  = current_user_id();
$activities = student_borrow_activities($studentId);
// Fresh row: current_user() is cached and may predate the ID columns.
$canRequest = student_id_approved((int) $studentId);

if (is_post()) {
    csrf_verify();
    if (!$canRequest) {
        abort_page(403, 'Upload both sides of your school ID and wait for administrator approval before borrowing.');
    }
}

$itemIn = is_post() ? post('item_id') : get('item_id');
$selectedItem = null;
if ($itemIn !== '') {
    if (!ctype_digit($itemIn)) {
        abort_page(404, 'That item is not available for student requests.');
    }
    $selectedItem = fetch_one(
        "SELECT item_id, name, item_code, size, unit FROM inventory_items
          WHERE item_id = ? AND student_requestable = 1
            AND status NOT IN ('damaged','under_maintenance','unavailable')",
        [(int) $itemIn]
    );
    if ($selectedItem === null) {
        abort_page(404, 'That item is not available for student requests.');
    }
}

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
$purposeError = $purpose === '' ? 'Enter the activity or purpose you will use the items for.'
    : (mb_strlen($purpose) > 500 ? 'Activity / purpose must be 500 characters or fewer.' : '');

$activityId = null;
foreach ($activities as $a) {
    if ((string) $a['activity_id'] === $activityIn) {
        $activityId = (int) $a['activity_id'];
    }
}

if ($canRequest && is_post()) {
    // Tampering, not a typo: the form only offers approved activities and requestable items.
    if ($activityIn !== '' && $activityId === null) {
        abort_page(403, 'Choose a registered activity you are approved in, or leave the registered activity unlinked.');
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
                if (!in_array($itemIdRaw, $itemIds, true)
                    || ($selectedItem !== null && $itemIdRaw !== (int) $selectedItem['item_id'])) {
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
    if ($purposeError !== '') {
        $errors[] = $purposeError;
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
            if (!student_id_approved((int) $studentId, true)) {
                throw new RuntimeException('ID approval changed before request creation.');
            }
            query(
                'INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until, student_request, proposed_until)
                 VALUES (?, ?, ?, ?, ?, 1, ?)',
                [$activityId, $studentId, $purpose, $from, $until, $until]
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
            $errors[] = 'The request could not be saved. Please try again.';
        }

        if ($errors === []) {
            audit_log('create', 'reservation', $reservationId,
                      'Student requested ' . count($lines) . ' item(s)'
                      . ($activityId === null ? ' with a typed activity/purpose, for office review' : ' for activity ' . $activityId));

            notify_many(
                $activityId === null ? office_reviewer_ids() : activity_reviewer_ids($activityId),
                'reservation',
                'New equipment request',
                full_name(current_user()) . ' requested ' . count($lines) . ' item(s).',
                url('inventory/reservations.php?status=pending')
            );

            clear_old_input();
            flash('success', $activityId === null ? 'Request sent. The office will review it.' : 'Request sent. Your activity reviewers will review it.');
            redirect('inventory/reservations.php');
        }
    }
}

// Step 2 shows once the dates are usable (GET) or after a failed POST.
$fromTs   = $neededFrom === '' ? false : strtotime($neededFrom);
$untilTs  = $neededUntil === '' ? false : strtotime($neededUntil);
$showStep2 = $selectedItem === null && $canRequest && get('change') === '' && $purposeError === '' && ($activityIn === '' || $activityId !== null) && $fromTs !== false && $untilTs !== false && $untilTs > $fromTs;
$datesError = $selectedItem === null && $canRequest && !is_post() && $neededFrom !== '' && $neededUntil !== '' && get('change') === ''
    && (($activityIn !== '' && $activityId === null) || $fromTs === false || $untilTs === false || $untilTs <= $fromTs)
    ? ($activityIn !== '' && $activityId === null ? 'Choose one of your registered activities, or leave it unlinked.' : '"Will return by" must be after "Needed from".')
    : '';
if ($selectedItem === null && $canRequest && !is_post() && get('change') === ''
    && $neededFrom !== '' && $neededUntil !== '' && $purposeError !== '') {
    $errors[] = $purposeError;
}

$qtyIn = is_post() && is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];

$pageTitle = 'Borrow equipment';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('inventory/index.php') ?>">&larr; Back to inventory</a>
        <h1>Borrow equipment</h1>
        <p>Tell us which activity or purpose you need the costumes or equipment for.</p>
    </div>
</div>

<div id="borrow-form-wrap">
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

<?php if (!$canRequest): ?>
    <div class="empty">
        <strong>School ID needed</strong>
        Upload both sides of your school ID and wait for administrator approval before borrowing.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('profile.php#school-id') ?>">View school ID status</a>
        </div>
    </div>
<?php else: ?>

<?php if (!$showStep2): ?>
<form method="<?= $selectedItem === null ? 'get' : 'post' ?>" novalidate>
    <?php if ($selectedItem !== null): ?>
        <?= csrf_field() ?>
        <input type="hidden" name="item_id" value="<?= (int) $selectedItem['item_id'] ?>">
        <h2><?= e($selectedItem['name']) ?></h2>
        <p class="hint">Code <?= e($selectedItem['item_code']) ?><?php if ($selectedItem['size']): ?> &middot; Size <?= e($selectedItem['size']) ?><?php endif; ?></p>
        <div class="form-row">
            <label for="borrow-quantity">Quantity <span class="req">*</span></label>
            <input id="borrow-quantity" type="number" min="1" required
                   name="quantity[<?= (int) $selectedItem['item_id'] ?>]"
                   value="<?= e(is_post() ? (is_scalar($qtyIn[$selectedItem['item_id']] ?? null) ? (string) $qtyIn[$selectedItem['item_id']] : '') : '1') ?>">
            <p class="hint">Availability for your dates is checked when you send the request.</p>
        </div>
    <?php endif; ?>
    <section<?= $selectedItem === null ? ' class="card"' : '' ?>>
        <div class="card-head"><h2>When and what for</h2></div>

        <div class="form-row">
            <label for="purpose">Activity / purpose <span class="req">*</span></label>
            <textarea id="purpose" name="purpose" maxlength="500" required aria-describedby="purpose-hint"
                      placeholder="e.g. Intramurals practice or a class presentation"><?= e($purpose) ?></textarea>
            <p class="hint" id="purpose-hint">Type the activity or purpose for these items, even if it is not listed in the system.</p>
        </div>

        <div class="form-row">
            <label for="activity_id">Link a registered activity <span class="optional">(optional)</span></label>
            <select id="activity_id" name="activity_id">
                <option value="">Not linked — office review</option>
                <?php foreach ($activities as $activity): ?>
                    <option value="<?= (int) $activity['activity_id'] ?>"
                        <?= $activityIn === (string) $activity['activity_id'] ? 'selected' : '' ?>>
                        <?= e($activity['title']) ?> (<?= e(format_date($activity['start_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Link an activity you are approved in for coordinator review, or leave it unlinked for office review.</p>
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

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" <?= $selectedItem !== null ? 'data-loading="Saving…"' : '' ?>><?= $selectedItem === null ? 'Choose items' : 'Send request' ?></button>
        </div>
    </section>
</form>
<?php else: ?>
<?php
$activityTitle = 'Not linked — office review';
foreach ($activities as $a) {
    if ((int) $a['activity_id'] === $activityId) {
        $activityTitle = (string) $a['title'];
    }
}
$changeQuery = http_build_query([
    'activity_id' => $activityId, 'needed_from' => $neededFrom,
    'needed_until' => $neededUntil, 'purpose' => $purpose, 'change' => 1,
]);
?>
<section class="card">
    <div class="card-head">
        <h2>When and what for</h2>
        <a class="btn btn-outline btn-sm" href="<?= url('inventory/borrow.php?' . $changeQuery) ?>">Change details</a>
    </div>
    <dl class="detail-list">
        <dt>Registered activity</dt><dd><?= e($activityTitle) ?></dd>
        <dt>Needed from</dt><dd><?= e(format_datetime($fromTs !== false ? date('Y-m-d H:i:s', $fromTs) : '')) ?></dd>
        <dt>Will return by</dt><dd><?= e(format_datetime($untilTs !== false ? date('Y-m-d H:i:s', $untilTs) : '')) ?></dd>
        <dt>Activity / purpose</dt><dd><?= e($purpose) ?></dd>
    </dl>
</section>
<?php endif; ?>

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
        <input type="hidden" name="activity_id" value="<?= $activityId === null ? '' : (int) $activityId ?>">
        <input type="hidden" name="needed_from" value="<?= e($neededFrom) ?>">
        <input type="hidden" name="needed_until" value="<?= e($neededUntil) ?>">
        <input type="hidden" name="purpose" value="<?= e($purpose) ?>">

        <section class="card card-flush">
            <div class="card-head">
                <h2>Items</h2>
                <p class="hint">These numbers can change before your request is approved.</p>
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
                            <td data-label="Free" class="num"><?= $free ?> <?= e($item['unit']) ?></td>
                            <td data-label="Quantity">
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

</div>
<?php
require __DIR__ . '/../../includes/layout/footer.php';
