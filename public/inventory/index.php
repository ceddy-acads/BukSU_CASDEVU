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

// Students get a browse view (photos and "how many can I get"); the office
// and coordinators keep the stock table they manage from.
$isBrowser = current_role() === 'student';

/** Statuses that take an item out of circulation whatever its count. */
const OUT_OF_SERVICE = ['damaged', 'under_maintenance', 'unavailable'];

// ------------------------------------------------------------------ Filters
$keyword       = get('q');
$categoryId    = get('category');
$statusFilter  = $isBrowser ? '' : get('status');
$availableOnly = $isBrowser && get('available') === '1';

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
if ($availableOnly) {
    // In service, with at least one unit not out on loan right now.
    $conditions[] = "i.status NOT IN ('damaged', 'under_maintenance', 'unavailable')
                     AND i.quantity_total > COALESCE((SELECT SUM(b2.quantity) FROM borrowings b2
                                                       WHERE b2.item_id = i.item_id AND b2.returned_at IS NULL), 0)";
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

// Sort: by the item's name A to Z unless the viewer picks otherwise. The
// ORDER BY comes from this fixed map, never from the request.
[$sortKey, $sortDir] = sort_param(['name', 'category'], 'name');
$orderBy = [
    'name'     => "i.name $sortDir, ic.name, i.item_id",
    'category' => "ic.name $sortDir, i.name, i.item_id",
][$sortKey];
$sortFilters = ['q' => get('q'), 'category' => get('category'), 'status' => get('status'), 'available' => get('available')];

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
      ORDER BY $orderBy
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
        'status' => get('status'), 'available' => get('available'),
        'sort' => get('sort'), 'dir' => get('dir'), 'page' => $page,
    ], static fn($v): bool => $v !== '' && $v !== null);

    return url('inventory/index.php?' . http_build_query($query));
}

$pageTitle = 'Inventory';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Costumes &amp; equipment</h1>
        <?php if ($isBrowser): ?>
            <p data-live-region="summary">What the office has, and how many are free to borrow right now.</p>
        <?php else: ?>
            <p data-live-region="summary"><?= $total ?> item<?= $total === 1 ? '' : 's' ?> in the catalog</p>
        <?php endif; ?>
    </div>
    <div class="btn-row">
        <?php if (has_role('staff', 'admin', 'coordinator')): ?>
            <a class="btn btn-outline" href="<?= url('inventory/reservations.php') ?>">Reservations</a>
        <?php endif; ?>
        <?php if (is_office_staff()): ?>
            <a class="btn btn-outline" href="<?= url('inventory/borrowings.php') ?>">
                Borrowed<?= $overdue > 0 ? ' (' . $overdue . ' overdue)' : '' ?>
            </a>
        <?php endif; ?>
        <?php if (has_role('staff', 'admin', 'coordinator')): ?>
            <a class="btn <?= is_office_staff() ? 'btn-outline' : 'btn-gold' ?>" href="<?= url('inventory/reserve.php') ?>">Request a reservation</a>
        <?php endif; ?>
        <?php if (is_office_staff()): ?>
            <a class="btn btn-gold" href="<?= url('inventory/manage.php') ?>">New item</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($overdue > 0 && is_office_staff()): ?>
    <div class="alert alert-error">
        <strong><?= $overdue ?></strong> item<?= $overdue === 1 ? ' is' : 's are' ?> past the expected return date.
        <a href="<?= url('inventory/borrowings.php?filter=overdue') ?>">Review now</a>
    </div>
<?php endif; ?>

<?php if ($isBrowser): ?>
    <div class="alert alert-info">
        <strong>Need something for an activity?</strong> Ask your activity coordinator or the office.
        They reserve and release items for you; the counts here update as items go out and come back.
    </div>
<?php else: ?>
<div class="stats">
    <div class="stat">
        <div class="stat-value"><?= (int) $summary['items'] ?></div>
        <div class="stat-label">Distinct items</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= (int) $summary['units'] ?></div>
        <div class="stat-label">Total units owned</div>
    </div>
    <div class="stat <?= stat_tone($onLoan, 'stat-gold') ?>">
        <div class="stat-value"><?= $onLoan ?></div>
        <div class="stat-label">Units on loan</div>
    </div>
    <div class="stat <?= stat_tone($summary['out_of_service'], 'stat-danger') ?>">
        <div class="stat-value"><?= (int) $summary['out_of_service'] ?></div>
        <div class="stat-label">Damaged or in maintenance</div>
    </div>
</div>
<?php endif; ?>

<?php $filtered = $keyword !== '' || $categoryId !== '' || $statusFilter !== '' || $availableOnly; ?>
<form method="get" class="filter-bar" data-live-search>
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
    <?php if ($isBrowser): ?>
        <div class="form-row filter-check">
            <label class="check">
                <input type="checkbox" name="available" value="1" <?= $availableOnly ? 'checked' : '' ?>>
                Only items free to borrow now
            </label>
        </div>
    <?php else: ?>
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
    <?php endif; ?>
    <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
    <div class="form-row filter-actions" data-live-region="actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filtered): ?>
            <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div data-live-region="results">
<p class="sr-only" data-live-status><?= $total ?> item<?= $total === 1 ? '' : 's' ?> found</p>

<?php if ($items === []): ?>
    <?php if ((int) $summary['items'] === 0): ?>
        <div class="empty">
            <strong>The catalog is empty</strong>
            No costumes or equipment have been added yet.
            <?php if (is_office_staff()): ?>
                <div class="btn-row">
                    <a class="btn btn-primary" href="<?= url('inventory/manage.php') ?>">Add the first item</a>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            <strong>No items match these filters</strong>
            Try a different search, or clear the filters to see the whole catalog.
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Clear filters</a>
            </div>
        </div>
    <?php endif; ?>
<?php elseif ($isBrowser): ?>
    <ul class="catalog">
        <?php foreach ($items as $item): ?>
            <?php
            $owned     = (int) $item['quantity_total'];
            $available = max(0, $owned - (int) $item['quantity_out']);
            $unit      = (string) ($item['unit'] ?: 'pc');
            $outOfService = in_array($item['status'], OUT_OF_SERVICE, true);
            ?>
            <li class="catalog-item">
                <div class="catalog-photo">
                    <?php if (!empty($item['photo_path'])): ?>
                        <img src="<?= url('inventory/photo.php?id=' . (int) $item['item_id']) ?>"
                             alt="<?= e($item['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <span class="catalog-nophoto">No photo yet</span>
                    <?php endif; ?>
                </div>
                <div class="catalog-body">
                    <p class="meta">
                        <?= e($item['category']) ?>
                        <?php if ($item['size'] !== null && $item['size'] !== ''): ?>
                            &middot; Size <?= e($item['size']) ?>
                        <?php endif; ?>
                    </p>
                    <h3><?= e($item['name']) ?></h3>

                    <?php if ($outOfService): ?>
                        <p class="catalog-avail text-danger">
                            Not available: <?= e(strtolower(str_replace('_', ' ', $item['status']))) ?>
                        </p>
                    <?php elseif ($available > 0): ?>
                        <p class="catalog-avail text-success"><?= $available ?> of <?= $owned ?> <?= e($unit) ?> available</p>
                    <?php else: ?>
                        <p class="catalog-avail text-warning">All <?= $owned ?> <?= e($unit) ?> are on loan</p>
                    <?php endif; ?>

                    <?php if ($item['description']): ?>
                        <p class="small muted"><?= e(mb_strimwidth((string) $item['description'], 0, 140, '…')) ?></p>
                    <?php endif; ?>
                    <p class="small muted">Code <?= e($item['item_code']) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(inventory_page_link($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php else: ?>
    <div class="card card-flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <?= sort_th('Item', 'name', $sortKey, $sortDir, 'inventory/index.php', $sortFilters) ?>
                        <?= sort_th('Category', 'category', $sortKey, $sortDir, 'inventory/index.php', $sortFilters) ?>
                        <th>Size</th>
                        <th class="num">Owned</th><th class="num">On loan</th><th>Available</th>
                        <th>Status</th>
                        <?php if (is_office_staff()): ?><th class="actions">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $available = (int) $item['quantity_total'] - (int) $item['quantity_out']; ?>
                    <tr>
                        <td>
                            <div class="item-cell">
                                <?php if (!empty($item['photo_path'])): ?>
                                    <img class="thumb" src="<?= url('inventory/photo.php?id=' . (int) $item['item_id']) ?>"
                                         alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="thumb thumb-empty" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= e($item['name']) ?></strong>
                                    <div class="hint"><?= e($item['item_code']) ?></div>
                                    <?php if ($item['storage_location']): ?>
                                        <div class="hint">Stored: <?= e($item['storage_location']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= e($item['category']) ?></td>
                        <td>
                            <?php if ($item['size'] !== null && $item['size'] !== ''): ?>
                                <?= e($item['size']) ?>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="num nowrap"><?= (int) $item['quantity_total'] ?> <?= e($item['unit']) ?></td>
                        <td class="num"><?= (int) $item['quantity_out'] ?></td>
                        <td class="nowrap">
                            <?php if ($available > 0): ?>
                                <span class="text-success"><?= $available ?> available</span>
                            <?php else: ?>
                                <span class="text-danger">None available</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= status_badge($item['status'], 'inventory') ?>
                            <?php if ($item['condition_note']): ?>
                                <div class="hint"><?= e($item['condition_note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php if (is_office_staff()): ?>
                            <td class="actions">
                                <div class="btn-row">
                                    <a class="btn btn-outline btn-sm"
                                       href="<?= url('inventory/manage.php?id=' . (int) $item['item_id']) ?>"
                                       aria-label="Edit <?= e($item['name']) ?>">Edit</a>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(inventory_page_link($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
