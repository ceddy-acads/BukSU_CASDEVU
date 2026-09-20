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
        <h1>Inventory report</h1>
        <p><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>
           &middot; <?= number_format($summary['available']) ?> units available</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-gold" href="<?= e(inventory_report_link(['export' => 'items'])) ?>">Export items</a>
        <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
        <a class="btn btn-outline" href="<?= url('reports/index.php') ?>">All reports</a>
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

<div class="grid grid-4" style="margin-bottom:1.5rem;">
    <div class="stat">
        <div class="stat-value"><?= number_format($summary['owned']) ?></div>
        <div class="stat-label">Units owned</div>
    </div>
    <div class="stat stat-gold">
        <div class="stat-value"><?= number_format($summary['out']) ?></div>
        <div class="stat-label">Units on loan</div>
    </div>
    <div class="stat stat-success">
        <div class="stat-value"><?= number_format($summary['available']) ?></div>
        <div class="stat-label">Units available</div>
    </div>
    <div class="stat stat-danger">
        <div class="stat-value"><?= number_format($summary['out_of_service']) ?></div>
        <div class="stat-label">Items out of service</div>
    </div>
</div>

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
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Apply</button>
    </div>
</form>

<?php if ($items === []): ?>
    <div class="empty"><strong>No items match</strong> Try different filters.</div>
<?php else: ?>
    <div class="card">
        <div class="card-head"><h2>Stock position</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Item</th><th>Category</th><th>Owned</th><th>On loan</th>
                        <th>Available</th><th>Status</th><th>Overdue</th><th>Damaged</th>
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
                        <td><?= (int) $item['quantity_total'] ?></td>
                        <td><?= (int) $item['quantity_out'] ?></td>
                        <td><strong><?= $available ?></strong></td>
                        <td><?= status_badge($item['status']) ?></td>
                        <td><?= (int) $item['overdue_loans'] > 0
                                ? '<span class="badge badge-danger">' . (int) $item['overdue_loans'] . '</span>'
                                : '—' ?></td>
                        <td><?= (int) $item['times_damaged'] ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Total</th>
                        <th><?= number_format($summary['owned']) ?></th>
                        <th><?= number_format($summary['out']) ?></th>
                        <th><?= number_format($summary['available']) ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Borrowing log</h2>
    </div>

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
        <div class="form-row" style="flex:0 0 auto;">
            <a class="btn btn-outline btn-sm" href="<?= e(inventory_report_link(['export' => 'loans'])) ?>">
                Export this log
            </a>
        </div>
    </form>

    <?php if ($borrowings === []): ?>
        <div class="empty"><strong>Nothing to show</strong> No records match.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Item</th><th>Borrower</th><th>Qty</th><th>Released</th>
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
                            <div class="hint"><?= e($borrowing['student_number'] ?? '—') ?></div>
                        </td>
                        <td><?= (int) $borrowing['quantity'] ?></td>
                        <td><?= e(format_date($borrowing['released_at'])) ?></td>
                        <td>
                            <?= e(format_date($borrowing['expected_return_at'])) ?>
                            <?php if (is_overdue($borrowing)): ?>
                                <div><span class="badge badge-danger">Overdue</span></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $borrowing['returned_at'] ? e(format_date($borrowing['returned_at'])) : '<span class="hint">Still out</span>' ?></td>
                        <td>
                            <?php if ($borrowing['return_condition']): ?>
                                <?php $cls = match ($borrowing['return_condition']) {
                                    'damaged' => 'badge-warning', 'lost' => 'badge-danger',
                                    default   => 'badge-success',
                                }; ?>
                                <span class="badge <?= $cls ?>"><?= e(ucfirst((string) $borrowing['return_condition'])) ?></span>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
