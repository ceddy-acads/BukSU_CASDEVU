<?php
/**
 * CASMS — Inventory report (FR-8.3, FR-8.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$categories = fetch_all('SELECT inv_category_id, name FROM inventory_categories ORDER BY name');

$filters = [
    'category_id' => get('category') !== '' && ctype_digit(get('category')) ? (int) get('category') : null,
    'status'      => in_array(get('status'), inventory_statuses(), true) ? get('status') : null,
];

$items       = inventory_report($filters);
$loanState   = in_array(get('loans'), ['open', 'overdue', 'returned'], true) ? get('loans') : 'open';
$borrowings  = borrowing_report(['state' => $loanState]);

// ------------------------------------------------------------------ Export
if (get('export') === 'items') {
    audit_log('export', 'report', null, 'Exported inventory report (' . count($items) . ' rows)');

    send_csv(
        'inventory-report-' . date('Ymd') . '.csv',
        ['Code', 'Item', 'Category', 'Size', 'Unit', 'Owned', 'On loan',
         'Available', 'Status', 'Overdue loans', 'Times damaged', 'Location', 'Condition note'],
        array_map(static fn(array $i): array => [
            $i['item_code'], $i['name'], $i['category'], $i['size'] ?? '', $i['unit'],
            (int) $i['quantity_total'], (int) $i['quantity_out'],
            (int) $i['quantity_total'] - (int) $i['quantity_out'],
            $i['status'], (int) $i['overdue_loans'], (int) $i['times_damaged'],
            $i['storage_location'] ?? '', $i['condition_note'] ?? '',
        ], $items)
    );
}

if (get('export') === 'loans') {
    audit_log('export', 'report', null, 'Exported borrowing log (' . count($borrowings) . ' rows)');

    send_csv(
        'borrowing-log-' . date('Ymd') . '.csv',
        ['Code', 'Item', 'Borrower', 'Student number', 'Quantity',
         'Released', 'Due back', 'Returned', 'Condition', 'Remarks'],
        array_map(static fn(array $b): array => [
            $b['item_code'], $b['item_name'],
            $b['last_name'] . ', ' . $b['first_name'], $b['student_number'] ?? '',
            (int) $b['quantity'], $b['released_at'], $b['expected_return_at'],
            $b['returned_at'] ?? '', $b['return_condition'] ?? '', $b['return_remarks'] ?? '',
        ], $borrowings)
    );
}

// ------------------------------------------------------------------ Summary
$summary = ['owned' => 0, 'out' => 0, 'available' => 0, 'overdue' => 0, 'out_of_service' => 0];
foreach ($items as $item) {
    $owned = (int) $item['quantity_total'];
    $out   = (int) $item['quantity_out'];

    $summary['owned']     += $owned;
    $summary['out']       += $out;
    $summary['available'] += $owned - $out;
    $summary['overdue']   += (int) $item['overdue_loans'];

    if (in_array($item['status'], ['damaged', 'under_maintenance', 'unavailable'], true)) {
        $summary['out_of_service']++;
    }
}

function inventory_report_link(array $overrides = []): string
{
    $query = array_filter(array_merge([
        'category' => get('category'), 'status' => get('status'), 'loans' => get('loans'),
    ], $overrides), static fn($v): bool => $v !== '' && $v !== null);

    return url('reports/inventory.php' . ($query === [] ? '' : '?' . http_build_query($query)));
}

$pageTitle = 'Inventory report';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('reports/index.php') ?>">&larr; Back to reports</a>
        <h1>Inventory report</h1>
        <p><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>
           &middot; <?= number_format($summary['available']) ?> units available</p>
    </div>
    <div class="btn-row">
        <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
        <a class="btn btn-gold" href="<?= e(inventory_report_link(['export' => 'items'])) ?>">Export items</a>
    </div>
</div>

<div class="report-meta">
    <strong><?= e(UNIVERSITY) ?></strong> &middot; <?= e(OFFICE_NAME) ?><br>
    Inventory report generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
    by <?= e(full_name(current_user())) ?>
</div>

<?php if ($summary['overdue'] > 0): ?>
    <div class="alert alert-error">
        <strong><?= $summary['overdue'] ?></strong> loan<?= $summary['overdue'] === 1 ? ' is' : 's are' ?>
        past the expected return date.
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="stat-value"><?= number_format($summary['owned']) ?></div>
        <div class="stat-label">Units owned</div>
    </div>
    <div class="stat <?= stat_tone($summary['out'], 'stat-gold') ?>">
        <div class="stat-value"><?= number_format($summary['out']) ?></div>
        <div class="stat-label">Units on loan</div>
    </div>
    <div class="stat <?= stat_tone($summary['available'], 'stat-success') ?>">
        <div class="stat-value"><?= number_format($summary['available']) ?></div>
        <div class="stat-label">Units available</div>
    </div>
    <div class="stat <?= stat_tone($summary['out_of_service'], 'stat-danger') ?>">
        <div class="stat-value"><?= number_format($summary['out_of_service']) ?></div>
        <div class="stat-label">Items out of service</div>
    </div>
</div>

<?php $itemFiltered = get('category') !== '' || get('status') !== ''; ?>
<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['inv_category_id'] ?>"
                    <?= get('category') === (string) $category['inv_category_id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (inventory_statuses() as $option): ?>
                <option value="<?= e($option) ?>" <?= get('status') === $option ? 'selected' : '' ?>>
                    <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($itemFiltered): ?>
            <a class="btn btn-outline" href="<?= url('reports/inventory.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($items === []): ?>
    <div class="empty">
        <?php if ($itemFiltered): ?>
            <strong>No items match these filters</strong>
            Try a different category or status, or clear the filters.
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('reports/inventory.php') ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <strong>No items in the catalog yet</strong>
            The stock position appears here once items are added to the inventory.
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="card card-flush">
        <div class="card-head"><h2>Stock position</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Item</th><th>Category</th><th class="num">Owned</th><th class="num">On loan</th>
                        <th class="num">Available</th><th>Status</th><th>Overdue</th><th class="num">Damaged</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $available = (int) $item['quantity_total'] - (int) $item['quantity_out']; ?>
                    <tr>
                        <td>
                            <strong><?= e($item['name']) ?></strong>
                            <div class="hint"><?= e($item['item_code']) ?><?= $item['size'] ? ' · ' . e($item['size']) : '' ?></div>
                        </td>
                        <td><?= e($item['category']) ?></td>
                        <td class="num"><?= (int) $item['quantity_total'] ?></td>
                        <td class="num"><?= (int) $item['quantity_out'] ?></td>
                        <td class="num"><strong><?= $available ?></strong></td>
                        <td><?= status_badge($item['status']) ?></td>
                        <td class="nowrap">
                            <?php if ((int) $item['overdue_loans'] > 0): ?>
                                <span class="badge badge-danger"><?= (int) $item['overdue_loans'] ?> overdue</span>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ((int) $item['times_damaged'] > 0): ?>
                                <?= (int) $item['times_damaged'] ?>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="num"><?= number_format($summary['owned']) ?></td>
                        <td class="num"><?= number_format($summary['out']) ?></td>
                        <td class="num"><?= number_format($summary['available']) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
<?php endif; ?>

<h2 class="section-title">Borrowing log</h2>

<form method="get" class="filter-bar">
    <input type="hidden" name="category" value="<?= e(get('category')) ?>">
    <input type="hidden" name="status" value="<?= e(get('status')) ?>">
    <div class="form-row">
        <label for="loans">Show</label>
        <select id="loans" name="loans" onchange="this.form.submit()">
            <option value="open"     <?= $loanState === 'open'     ? 'selected' : '' ?>>Currently out</option>
            <option value="overdue"  <?= $loanState === 'overdue'  ? 'selected' : '' ?>>Overdue only</option>
            <option value="returned" <?= $loanState === 'returned' ? 'selected' : '' ?>>Returned</option>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn btn-outline" href="<?= e(inventory_report_link(['export' => 'loans'])) ?>">Export this log</a>
    </div>
</form>

<?php if ($borrowings === []): ?>
    <div class="empty">
        <?php if ($loanState === 'overdue'): ?>
            <strong>No overdue loans</strong>
            Everything that is out is still within its expected return date.
        <?php elseif ($loanState === 'returned'): ?>
            <strong>No returns recorded yet</strong>
            Returned loans appear here once a return is recorded.
        <?php else: ?>
            <strong>Nothing is out right now</strong>
            No items are currently on loan.
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="card card-flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Item</th><th>Borrower</th><th class="num">Qty</th><th>Released</th>
                        <th>Due back</th><th>Returned</th><th>Condition</th></tr>
                </thead>
                <tbody>
                <?php foreach ($borrowings as $borrowing): ?>
                    <tr>
                        <td>
                            <?= e($borrowing['item_name']) ?>
                            <div class="hint"><?= e($borrowing['item_code']) ?></div>
                        </td>
                        <td>
                            <?= e(full_name($borrowing, true)) ?>
                            <?php if ($borrowing['student_number']): ?>
                                <div class="hint"><?= e($borrowing['student_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $borrowing['quantity'] ?></td>
                        <td class="nowrap"><?= e(format_date($borrowing['released_at'])) ?></td>
                        <td class="nowrap">
                            <?= e(format_date($borrowing['expected_return_at'])) ?>
                            <?php if (is_overdue($borrowing)): ?>
                                <div><span class="badge badge-danger">Overdue</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?php if ($borrowing['returned_at']): ?>
                                <?= e(format_date($borrowing['returned_at'])) ?>
                            <?php else: ?>
                                <span class="muted">Still out</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($borrowing['return_condition']): ?>
                                <?php $cls = match ($borrowing['return_condition']) {
                                    'damaged' => 'badge-warning', 'lost' => 'badge-danger',
                                    default   => 'badge-success',
                                }; ?>
                                <span class="badge <?= $cls ?>"><?= e(ucfirst((string) $borrowing['return_condition'])) ?></span>
                            <?php else: ?>
                                <span class="muted">Not returned</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
