<?php
/**
 * CASMS — Activity listing with search and filters (FR-2.1, FR-3.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$categories = fetch_all('SELECT category_id, name FROM activity_categories WHERE is_active = 1 ORDER BY name');

// ------------------------------------------------------------------ Filters
$keyword    = get('q');
$categoryId = get('category');
$status     = get('status');

// Students never see drafts (FR-2.2 / role matrix).
$conditions = [];
$params     = [];

if (!is_office_staff() && current_role() !== 'coordinator') {
    $conditions[] = "a.status <> 'draft'";
}

if ($keyword !== '') {
    $conditions[] = '(a.title LIKE ? OR a.description LIKE ?)';
    $params[]     = '%' . $keyword . '%';
    $params[]     = '%' . $keyword . '%';
}

if ($categoryId !== '' && ctype_digit($categoryId)) {
    $conditions[] = 'a.category_id = ?';
    $params[]     = (int) $categoryId;
}

// Whitelist the status value — never interpolate user input into SQL.
$allowedStatuses = ['draft', 'upcoming', 'ongoing', 'completed', 'cancelled', 'closed'];
if (in_array($status, $allowedStatuses, true)) {
    $conditions[] = 'a.status = ?';
    $params[]     = $status;
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

// --------------------------------------------------------------- Pagination
$page  = max(1, (int) (get('page') ?: 1));
$total = (int) fetch_value("SELECT COUNT(*) FROM activities a$where", $params);
$pages = max(1, (int) ceil($total / PER_PAGE));
$page  = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

// LIMIT/OFFSET are integers computed above, never raw input.
$activities = fetch_all(
    "SELECT a.activity_id, a.title, a.slug, a.description, a.start_at, a.end_at, a.status,
            a.max_participants,
            c.name AS category, c.color_hex,
            v.name AS venue,
            (SELECT COUNT(*) FROM registrations r
              WHERE r.activity_id = a.activity_id AND r.status = 'approved') AS approved_count
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
       $where
      ORDER BY a.start_at DESC
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

/** Preserve the current filters when building a page link. */
function page_link(int $page): string
{
    $query = array_filter([
        'q'        => get('q'),
        'category' => get('category'),
        'status'   => get('status'),
        'page'     => $page,
    ], static fn($value): bool => $value !== '' && $value !== null);

    return url('activities/index.php?' . http_build_query($query));
}

$pageTitle = 'Activities';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Activities</h1>
        <p><?= $total ?> activit<?= $total === 1 ? 'y' : 'ies' ?> found</p>
    </div>
    <?php if (is_office_staff()): ?>
        <a class="btn btn-gold" href="<?= url('activities/manage.php') ?>">+ New activity</a>
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Title or description">
    </div>
    <div class="form-row">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['category_id'] ?>"
                    <?= $categoryId === (string) $category['category_id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach ($allowedStatuses as $allowedStatus): ?>
                <?php if ($allowedStatus === 'draft' && !is_office_staff()) { continue; } ?>
                <option value="<?= e($allowedStatus) ?>" <?= $status === $allowedStatus ? 'selected' : '' ?>>
                    <?= e(ucfirst($allowedStatus)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
    <?php if ($keyword !== '' || $categoryId !== '' || $status !== ''): ?>
        <div class="form-row" style="flex:0 0 auto;">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Clear</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($activities === []): ?>
    <div class="empty">
        <strong>No activities match your search</strong>
        Try a different keyword or clear the filters.
    </div>
<?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($activities as $activity): ?>
            <article class="activity-card">
                <div class="cat-strip" style="background: <?= e($activity['color_hex'] ?: '#10284d') ?>"></div>
                <div class="body">
                    <?= status_badge($activity['status']) ?>
                    <h3>
                        <a href="<?= url('activities/view.php?id=' . (int) $activity['activity_id']) ?>">
                            <?= e($activity['title']) ?>
                        </a>
                    </h3>
                    <p class="meta"><?= e($activity['category']) ?></p>
                    <p class="meta"><?= e(format_datetime($activity['start_at'])) ?></p>
                    <?php if ($activity['venue']): ?>
                        <p class="meta"><?= e($activity['venue']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="foot">
                    <span class="hint">
                        <?= (int) $activity['approved_count'] ?>
                        <?= $activity['max_participants'] ? ' / ' . (int) $activity['max_participants'] : '' ?>
                        participant<?= (int) $activity['approved_count'] === 1 ? '' : 's' ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(page_link($page - 1)) ?>">&laquo; Previous</a>
            <?php endif; ?>

            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(page_link($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
                <a href="<?= e(page_link($page + 1)) ?>">Next &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
