<?php
/**
 * CASMS — Announcements (FR-3.1, FR-3.2, FR-3.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$canPost = has_role('staff', 'admin', 'coordinator');
$keyword = get('q');

// Students only ever see published announcements.
$conditions = [];
$params     = [];

if (!$canPost) {
    $conditions[] = "a.status = 'published'";
} elseif (get('status') === 'draft') {
    $conditions[] = "a.status = 'draft'";
}

if ($keyword !== '') {
    $conditions[] = '(a.title LIKE ? OR a.body LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like);
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$page   = max(1, (int) (get('page') ?: 1));
$total  = (int) fetch_value("SELECT COUNT(*) FROM announcements a$where", $params);
$pages  = max(1, (int) ceil($total / PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

$announcements = fetch_all(
    "SELECT a.*, u.first_name, u.last_name, act.title AS activity_title
       FROM announcements a
       JOIN users u            ON u.user_id     = a.posted_by
       LEFT JOIN activities act ON act.activity_id = a.activity_id
       $where
      ORDER BY a.is_pinned DESC, COALESCE(a.published_at, a.created_at) DESC
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$pageTitle = 'Announcements';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php $hasFilters = $keyword !== '' || get('status') !== ''; ?>

<div class="page-head">
    <div>
        <h1>Announcements</h1>
        <p><?= $total ?> announcement<?= $total === 1 ? '' : 's' ?> from the office. Pinned notices are listed first.</p>
    </div>
    <?php if ($canPost): ?>
        <div class="btn-row">
            <a class="btn btn-gold" href="<?= url('announcements/manage.php') ?>">New announcement</a>
        </div>
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Title or content">
    </div>
    <?php if ($canPost): ?>
        <div class="form-row">
            <label for="status">Show</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value=""      <?= get('status') === ''      ? 'selected' : '' ?>>All</option>
                <option value="draft" <?= get('status') === 'draft' ? 'selected' : '' ?>>Drafts only</option>
            </select>
        </div>
    <?php endif; ?>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($keyword !== ''): ?>
            <a class="btn btn-outline" href="<?= url('announcements/index.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($announcements === []): ?>
    <?php if ($hasFilters): ?>
        <div class="empty">
            <strong>No announcements match your search</strong>
            Try a different keyword, or show everything.
            <a class="btn btn-outline" href="<?= url('announcements/index.php') ?>">Clear search</a>
        </div>
    <?php else: ?>
        <div class="empty">
            <strong>No announcements yet</strong>
            <?php if ($canPost): ?>
                Post an announcement to let students know what is coming up.
                <a class="btn btn-primary" href="<?= url('announcements/manage.php') ?>">Post the first announcement</a>
            <?php else: ?>
                News from the office will appear here once it is posted.
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php foreach ($announcements as $announcement): ?>
        <article class="card">
            <div class="item">
                <div class="item-main">
                    <?php if ($announcement['is_pinned'] || $announcement['status'] !== 'published'): ?>
                        <div class="btn-row">
                            <?php if ($announcement['is_pinned']): ?>
                                <span class="badge badge-info">Pinned</span>
                            <?php endif; ?>
                            <?php if ($announcement['status'] !== 'published'): ?>
                                <?= status_badge($announcement['status'], 'announcement') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h2 class="item-title"><?= e($announcement['title']) ?></h2>
                    <p class="meta">
                        <?= e(format_datetime($announcement['published_at'] ?? $announcement['created_at'])) ?>
                        &middot; <?= e($announcement['first_name'] . ' ' . $announcement['last_name']) ?>
                        <?php if ($announcement['activity_title']): ?>
                            &middot; <?= e($announcement['activity_title']) ?>
                        <?php endif; ?>
                    </p>
                    <div class="item-body preline"><?= e($announcement['body']) ?></div>
                </div>

                <?php if ($canPost): ?>
                    <div class="btn-row">
                        <a class="btn btn-outline btn-sm"
                           href="<?= url('announcements/manage.php?id=' . (int) $announcement['announcement_id']) ?>"
                           aria-label="Edit announcement: <?= e($announcement['title']) ?>">
                            Edit
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(url('announcements/index.php?' . http_build_query(array_filter([
                        'q' => $keyword, 'status' => get('status'), 'page' => $p,
                    ])))) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
