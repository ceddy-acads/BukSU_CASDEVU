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
              WHERE r.activity_id = a.activity_id AND r.status = 'approved') AS approved_count,
            -- The viewer's own latest registration, so a student sees where
            -- they stand without opening every activity.
            (SELECT r2.status FROM registrations r2
              WHERE r2.activity_id = a.activity_id AND r2.user_id = ?
              ORDER BY r2.registered_at DESC LIMIT 1) AS my_status
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
       $where
      -- Upcoming and ongoing first, soonest first; then past ones, most
      -- recent first. An activity counts as past once its end has gone by.
      ORDER BY (COALESCE(a.end_at, a.start_at) < NOW()),
               CASE WHEN COALESCE(a.end_at, a.start_at) >= NOW() THEN a.start_at END ASC,
               a.start_at DESC, a.title
      LIMIT " . PER_PAGE . " OFFSET $offset",
    array_merge([(int) current_user_id()], $params)
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

<?php $hasFilters = $keyword !== '' || $categoryId !== '' || $status !== ''; ?>

<div class="page-head">
    <div>
        <h1>Activities</h1>
        <p data-live-region="summary"><?= $total ?> activit<?= $total === 1 ? 'y' : 'ies' ?> found. Open one to see details and register.</p>
    </div>
    <div class="btn-row">
        <?= activity_view_switch('list') ?>
        <?php if (is_office_staff()): ?>
            <a class="btn btn-gold" href="<?= url('activities/manage.php') ?>">New activity</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="filter-bar" data-live-search>
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
    <div class="form-row filter-actions" data-live-region="actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?>
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div data-live-region="results">

<?php if ($activities === []): ?>
    <?php if ($hasFilters): ?>
        <div class="empty">
            <strong>No activities match these filters</strong>
            Try a different keyword, category, or status.
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Clear filters</a>
        </div>
    <?php else: ?>
        <div class="empty">
            <strong>No activities yet</strong>
            <?php if (is_office_staff()): ?>
                Create an activity so students can find and register for it.
                <a class="btn btn-primary" href="<?= url('activities/manage.php') ?>">Create the first activity</a>
            <?php else: ?>
                The office has not published any activities. Check back soon.
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($activities as $activity): ?>
            <?php $start = strtotime((string) $activity['start_at']); ?>
            <article class="activity-card">
                <div class="cat-strip" style="background: <?= e($activity['color_hex'] ?: '#10284d') ?>"></div>
                <div class="body activity-card-body">
                    <span class="agenda-date" aria-hidden="true">
                        <span class="mon"><?= e(date('M', $start)) ?></span>
                        <span class="day"><?= e(date('j', $start)) ?></span>
                    </span>
                    <div class="activity-card-main">
                        <p class="meta"><strong><?= e($activity['category']) ?></strong></p>
                        <h3>
                            <a href="<?= url('activities/view.php?id=' . (int) $activity['activity_id']) ?>">
                                <?= e($activity['title']) ?>
                            </a>
                        </h3>
                        <p class="meta"><?= e(date('D, j M Y · g:i A', $start)) ?></p>
                        <?php if ($activity['venue']): ?>
                            <p class="meta"><?= e($activity['venue']) ?></p>
                        <?php endif; ?>
                        <div class="activity-card-status">
                            <?= status_badge($activity['status'], 'activity') ?>
                            <?php if ($activity['my_status'] !== null): ?>
                                <span class="my-status">You: <?= status_badge($activity['my_status'], 'registration') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="foot">
                    <?php if ($activity['max_participants']): ?>
                        <?= (int) $activity['approved_count'] ?> of <?= (int) $activity['max_participants'] ?> places filled
                    <?php else: ?>
                        <?= (int) $activity['approved_count'] ?>
                        participant<?= (int) $activity['approved_count'] === 1 ? '' : 's' ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(page_link($page - 1)) ?>">Previous</a>
            <?php endif; ?>

            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(page_link($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
                <a href="<?= e(page_link($page + 1)) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
