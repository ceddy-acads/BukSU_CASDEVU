<?php
/**
 * CASMS — Inventory catalog and availability (FR-6.1, FR-6.2, FR-6.3)
 *
 * Readable by any signed-in user; only the office may edit.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$categories = fetch_all('SELECT inv_category_id, name FROM inventory_categories ORDER BY name');

// ------------------------------------------------------------------ Filters
$keyword      = get('q');
$categoryId   = get('category');
$statusFilter = get('status');

$conditions = [];
$params     = [];

if ($keyword !== '') {
    $conditions[] = '(i.name LIKE ? OR i.item_code LIKE ? OR i.description LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if ($categoryId !== '' && ctype_digit($categoryId)) {
    $conditions[] = 'i.inv_category_id = ?';
    $params[]     = (int) $categoryId;
}
if (in_array($statusFilter, inventory_statuses(), true)) {
    $conditions[] = 'i.status = ?';
    $params[]     = $statusFilter;
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$page   = max(1, (int) (get('page') ?: 1));
$total  = (int) fetch_value("SELECT COUNT(*) FROM inventory_items i$where", $params);
$pages  = max(1, (int) ceil($total / PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

// quantity_out comes from open borrowings, so availability is always live.
$items = fetch_all(
    "SELECT i.*, ic.name AS category,
            COALESCE((SELECT SUM(b.quantity) FROM borrowings b
                       WHERE b.item_id = i.item_id AND b.returned_at IS NULL), 0) AS quantity_out
       FROM inventory_items i
       JOIN inventory_categories ic ON ic.inv_category_id = i.inv_category_id
       $where
      ORDER BY ic.name, i.name
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$summary = fetch_one(
    "SELECT COUNT(*) AS items,
            COALESCE(SUM(quantity_total), 0) AS units,
            SUM(status = 'damaged' OR status = 'under_maintenance') AS out_of_service
       FROM inventory_items"
);
$onLoan  = (int) fetch_value('SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE returned_at IS NULL');
$overdue = overdue_borrowing_count();

function inventory_page_link(int $page): string
{
    $query = array_filter([
        'q' => get('q'), 'category' => get('category'),
        'status' => get('status'), 'page' => $page,
    ], static fn($v): bool => $v !== '' && $v !== null);

    return url('inventory/index.php?' . http_build_query($query));
}

$pageTitle = 'Inventory';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Costumes &amp; equipment</h1>
        <p><?= $total ?> item<?= $total === 1 ? '' : 's' ?> in the catalog</p>
    </div>
    <div class="btn-row">
        <?php if (has_role('staff', 'admin', 'coordinator')): ?>
            <a class="btn btn-outline" href="<?= url('inventory/reservations.php') ?>">Reservations</a>
        <?php endif; ?>
        <?php if (is_office_staff()): ?>
            <a class="btn btn-outline" href="<?= url('inventory/borrowings.php') ?>">
                Borrowed<?= $overdue > 0 ? ' (' . $overdue . ' overdue)' : '' ?>
            </a>
            <a class="btn btn-gold" href="<?= url('inventory/manage.php') ?>">+ New item</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($overdue > 0 && is_office_staff()): ?>
    <div class="alert alert-error">
        <strong><?= $overdue ?></strong> item<?= $overdue === 1 ? ' is' : 's are' ?> past the expected return date.
        <a href="<?= url('inventory/borrowings.php?filter=overdue') ?>">Review now</a>
    </div>
<?php endif; ?>

<div class="grid grid-4" style="margin-bottom:1.5rem;">
    <div class="stat">
        <div class="stat-value"><?= (int) $summary['items'] ?></div>
        <div class="stat-label">Distinct items</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= (int) $summary['units'] ?></div>
        <div class="stat-label">Total units owned</div>
    </div>
    <div class="stat stat-gold">
        <div class="stat-value"><?= $onLoan ?></div>
        <div class="stat-label">Units on loan</div>
    </div>
    <div class="stat stat-danger">
        <div class="stat-value"><?= (int) $summary['out_of_service'] ?></div>
        <div class="stat-label">Damaged / in maintenance</div>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Name, code, description">
    </div>
    <div class="form-row">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['inv_category_id'] ?>"
                    <?= $categoryId === (string) $category['inv_category_id'] ? 'selected' : '' ?>>
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
                <option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                    <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
    <?php if ($keyword !== '' || $categoryId !== '' || $statusFilter !== ''): ?>
        <div class="form-row" style="flex:0 0 auto;">
            <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Clear</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($items === []): ?>
    <div class="empty">
        <strong>No items match</strong>
        <?= (int) $summary['items'] === 0
            ? 'The catalog is empty. Add the first item to get started.'
            : 'Try a different search or clear the filters.' ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Item</th><th>Category</th><th>Size</th>
                        <th>Owned</th><th>On loan</th><th>Available</th>
                        <th>Status</th>
                        <?php if (is_office_staff()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $available = (int) $item['quantity_total'] - (int) $item['quantity_out']; ?>
                    <tr>
                        <td>
                            <strong><?= e($item['name']) ?></strong>
                            <div class="hint"><?= e($item['item_code']) ?></div>
                            <?php if ($item['storage_location']): ?>
                                <div class="hint">Stored: <?= e($item['storage_location']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($item['category']) ?></td>
                        <td><?= e($item['size'] ?? '—') ?></td>
                        <td><?= (int) $item['quantity_total'] ?> <?= e($item['unit']) ?></td>
                        <td><?= (int) $item['quantity_out'] ?></td>
                        <td>
                            <strong style="color:<?= $available > 0 ? 'var(--success)' : 'var(--danger)' ?>">
                                <?= $available ?>
                            </strong>
                        </td>
                        <td>
                            <?= status_badge($item['status']) ?>
                            <?php if ($item['condition_note']): ?>
                                <div class="hint"><?= e($item['condition_note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php if (is_office_staff()): ?>
                            <td class="actions">
                                <a class="btn btn-outline btn-sm"
                                   href="<?= url('inventory/manage.php?id=' . (int) $item['item_id']) ?>">Edit</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(inventory_page_link($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php if (has_role('staff', 'admin', 'coordinator')): ?>
    <div class="btn-row" style="margin-top:1.25rem;">
        <a class="btn btn-gold" href="<?= url('inventory/reserve.php') ?>">Request a reservation</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
