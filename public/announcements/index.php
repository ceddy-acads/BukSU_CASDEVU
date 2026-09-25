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

// ---------------------------------------------------------- Time grouping
// Each announcement is placed by the date it is about: the start of its
// linked activity when it has one, otherwise when it was published. The
// week boundaries come from today, so items move from "Next week" to "This
// week" on their own. Weeks start on Monday.
$weekStart     = new DateTimeImmutable('monday this week');
$nextWeekStart = $weekStart->modify('+1 week');
$laterStart    = $weekStart->modify('+2 weeks');

$groupLabels = [0 => 'This week', 1 => 'Next week', 2 => 'Later', 3 => 'Earlier'];
$groupRanges = [
    0 => $weekStart->format('j M') . ' to ' . $nextWeekStart->modify('-1 day')->format('j M'),
    1 => $nextWeekStart->format('j M') . ' to ' . $laterStart->modify('-1 day')->format('j M'),
    2 => 'From ' . $laterStart->format('j M'),
    3 => 'Before ' . $weekStart->format('j M'),
];

// Upcoming groups run soonest first; the earlier group runs most recent
// first. Pinned notices lead their own group. Sorting in SQL keeps
// pagination consistent with the grouping.
$announcements = fetch_all(
    "SELECT * FROM (
        SELECT a.*, u.first_name, u.last_name,
               act.title AS activity_title, act.start_at AS activity_start,
               COALESCE(act.start_at, a.published_at, a.created_at) AS relevant_at,
               CASE
                   WHEN COALESCE(act.start_at, a.published_at, a.created_at) < ? THEN 3
                   WHEN COALESCE(act.start_at, a.published_at, a.created_at) < ? THEN 0
                   WHEN COALESCE(act.start_at, a.published_at, a.created_at) < ? THEN 1
                   ELSE 2
               END AS time_group
          FROM announcements a
          JOIN users u             ON u.user_id     = a.posted_by
          LEFT JOIN activities act ON act.activity_id = a.activity_id
          $where
     ) grouped
      ORDER BY time_group, is_pinned DESC,
               CASE WHEN time_group = 3 THEN NULL ELSE relevant_at END ASC,
               relevant_at DESC, announcement_id DESC
      LIMIT " . PER_PAGE . " OFFSET $offset",
    array_merge([
        $weekStart->format('Y-m-d H:i:s'),
        $nextWeekStart->format('Y-m-d H:i:s'),
        $laterStart->format('Y-m-d H:i:s'),
    ], $params)
);

$pageTitle = 'Announcements';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php $hasFilters = $keyword !== '' || get('status') !== ''; ?>

<div class="page-head">
    <div>
        <h1>Announcements</h1>
        <p><?= $total ?> announcement<?= $total === 1 ? '' : 's' ?> from the office, grouped by when they matter.</p>
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
    <?php $currentGroup = null; ?>
    <?php foreach ($announcements as $announcement): ?>
        <?php if ((int) $announcement['time_group'] !== $currentGroup): ?>
            <?php $currentGroup = (int) $announcement['time_group']; ?>
            <h2 class="group-title">
                <?= e($groupLabels[$currentGroup]) ?>
                <span class="group-range"><?= e($groupRanges[$currentGroup]) ?></span>
            </h2>
        <?php endif; ?>
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
                        <?php if ($announcement['activity_start']): ?>
                            <strong>Event: <?= e(date('D, j M Y · g:i A', strtotime((string) $announcement['activity_start']))) ?></strong>
                            &middot; <a href="<?= url('activities/view.php?id=' . (int) $announcement['activity_id']) ?>"><?= e($announcement['activity_title']) ?></a>
                            &middot; Posted <?= e(format_date($announcement['published_at'] ?? $announcement['created_at'])) ?>
                        <?php else: ?>
                            <strong>Posted: <?= e(date('D, j M Y', strtotime((string) ($announcement['published_at'] ?? $announcement['created_at'])))) ?></strong>
                        <?php endif; ?>
                        &middot; <?= e($announcement['first_name'] . ' ' . $announcement['last_name']) ?>
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
