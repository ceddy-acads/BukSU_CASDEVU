<?php
/**
 * CASMS — Role-specific dashboard (Week 1 milestone)
 *
 * Built around the one question each role opens it with:
 *   students  — "what do I still need to do?"
 *   office    — "what is waiting on me?"
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
$role = current_role();

// ------------------------------------------------------------- Shared data
$upcoming = fetch_all(
    "SELECT a.activity_id, a.title, a.slug, a.start_at, a.status,
            c.name AS category, c.color_hex, v.name AS venue
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
      WHERE a.status IN ('upcoming','ongoing')
      ORDER BY a.start_at ASC
      LIMIT 5"
);

$announcements = fetch_all(
    "SELECT an.announcement_id, an.title, an.body, an.published_at, an.is_pinned,
            act.activity_id, act.title AS activity_title
       FROM announcements an
       LEFT JOIN activities act ON act.activity_id = an.activity_id
      WHERE an.status = 'published'
      ORDER BY an.is_pinned DESC, an.published_at DESC
      LIMIT 3"
);

// --------------------------------------------------------- Role statistics
// Each tile: label, value, the class it earns when non-zero, and where it leads.
$stats = [];

if ($role === 'student') {
    $stats = [
        ['label' => 'My registrations', 'value' => fetch_value(
            'SELECT COUNT(*) FROM registrations WHERE user_id = ?', [$user['user_id']]),
            'class' => '', 'href' => 'participation/my-activities.php'],
        ['label' => 'Approved',         'value' => fetch_value(
            "SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'approved'", [$user['user_id']]),
            'class' => 'stat-success', 'href' => 'participation/my-activities.php'],
        ['label' => 'Awaiting review',  'value' => fetch_value(
            "SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'pending'", [$user['user_id']]),
            'class' => 'stat-gold', 'href' => 'participation/my-activities.php'],
        ['label' => 'Missing requirements', 'value' => fetch_value(
            'SELECT COUNT(*) FROM v_missing_requirements WHERE user_id = ?', [$user['user_id']]),
            'class' => 'stat-danger', 'href' => 'participation/my-activities.php'],
    ];
} else {
    $stats = [
        ['label' => 'Active activities', 'value' => fetch_value(
            "SELECT COUNT(*) FROM activities WHERE status IN ('upcoming','ongoing')"),
            'class' => '', 'href' => 'activities/index.php'],
        // Same source as the review queue, so the numbers always match it
        // (and a coordinator counts only their own activities).
        ['label' => 'Pending registrations', 'value' => review_counts()['registrations'],
            'class' => 'stat-gold', 'href' => 'review.php#registrations'],
        ['label' => 'Requirements to verify', 'value' => review_counts()['documents'],
            'class' => 'stat-gold', 'href' => 'review.php#documents'],
        ['label' => 'Items on loan', 'value' => fetch_value(
            'SELECT COUNT(*) FROM borrowings WHERE returned_at IS NULL'),
            'class' => '', 'href' => is_office_staff() ? 'inventory/borrowings.php' : null],
    ];
}

// Accounts awaiting activation — the office's most common Week 1 task.
$pendingAccounts = is_office_staff()
    ? (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
    : 0;

// Office work queue: activities with registrations or documents waiting for
// review. Coordinators only see the activities they are assigned to.
$workQueue = [];
if ($role !== 'student') {
    $candidates = fetch_all(
        "SELECT a.activity_id, a.title, a.start_at,
                (SELECT COUNT(*) FROM registrations r
                  WHERE r.activity_id = a.activity_id AND r.status = 'pending') AS pending_registrations,
                (SELECT COUNT(*) FROM requirement_submissions rs
                   JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
                  WHERE ar.activity_id = a.activity_id AND rs.status = 'pending') AS pending_documents
           FROM activities a
          WHERE a.status NOT IN ('cancelled', 'completed')
         HAVING pending_registrations > 0 OR pending_documents > 0
          ORDER BY a.start_at ASC
          LIMIT 8"
    );
    foreach ($candidates as $candidate) {
        if (can_manage_activity((int) $candidate['activity_id'])) {
            $workQueue[] = $candidate;
        }
    }
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Good day, <?= e($user['first_name']) ?>.</h1>
        <p>
            <?= e(ucfirst((string) $role)) ?>
            <?php if ($user['course_code']): ?>
                &middot; <?= e($user['course_code']) ?>
                <?= $user['year_level_label'] ? ' ' . e($user['year_level_label']) : '' ?>
            <?php endif; ?>
        </p>
    </div>
    <?php if (is_office_staff()): ?>
        <div class="btn-row">
            <a class="btn btn-gold" href="<?= url('activities/manage.php') ?>">New activity</a>
        </div>
    <?php elseif ($role === 'student'): ?>
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Browse activities</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($pendingAccounts > 0): ?>
    <div class="alert alert-warning">
        <strong><?= $pendingAccounts ?></strong> student account<?= $pendingAccounts === 1 ? ' is' : 's are' ?>
        waiting for approval.
        <a href="<?= url('admin/users.php?status=pending') ?>">Review accounts</a>
    </div>
<?php endif; ?>

<?php if (is_office_staff()): ?>
    <?php $overdueItems = overdue_borrowing_count(); ?>
    <?php if ($overdueItems > 0): ?>
        <div class="alert alert-error">
            <strong><?= $overdueItems ?></strong> borrowed item<?= $overdueItems === 1 ? ' is' : 's are' ?>
            past the expected return date.
            <a href="<?= url('inventory/borrowings.php?filter=overdue') ?>">See overdue items</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($role === 'student' && (int) $stats[3]['value'] > 0): ?>
    <div class="alert alert-warning">
        You still have <strong><?= (int) $stats[3]['value'] ?></strong>
        document<?= (int) $stats[3]['value'] === 1 ? '' : 's' ?> to submit.
        <a href="<?= url('participation/my-activities.php') ?>">See what is missing</a>
    </div>
<?php endif; ?>

<div class="stats">
    <?php foreach ($stats as $stat): ?>
        <?php
        $value = (int) $stat['value'];
        // Colour is earned: a zero is not an alarm.
        $class = $value > 0 ? $stat['class'] : '';
        $tag   = $stat['href'] ? 'a' : 'div';
        ?>
        <<?= $tag ?> class="stat <?= e($class) ?>"<?= $stat['href'] ? ' href="' . e(url($stat['href'])) . '"' : '' ?>>
            <span class="stat-value"><?= $value ?></span>
            <span class="stat-label"><?= e($stat['label']) ?></span>
        </<?= $tag ?>>
    <?php endforeach; ?>
</div>

<div class="grid grid-2">
    <?php if ($role !== 'student'): ?>
        <section class="card">
            <div class="card-head">
                <h2>Needs your attention</h2>
                <a class="btn btn-ghost btn-sm" href="<?= url('review.php') ?>">Open review queue</a>
                <p class="hint">Registrations and documents waiting for a decision.</p>
            </div>

            <?php if ($workQueue === []): ?>
                <div class="empty">
                    <strong>You are all caught up</strong>
                    No registrations or documents are waiting for review.
                </div>
            <?php else: ?>
                <ul class="feed">
                    <?php foreach ($workQueue as $item): ?>
                        <li>
                            <h3>
                                <a href="<?= url('activities/view.php?id=' . (int) $item['activity_id']) ?>">
                                    <?= e($item['title']) ?>
                                </a>
                            </h3>
                            <p class="meta"><?= e(format_datetime($item['start_at'])) ?></p>
                            <div class="btn-row">
                                <?php if ((int) $item['pending_registrations'] > 0): ?>
                                    <a class="btn btn-outline btn-sm"
                                       href="<?= url('participation/participants.php?activity_id=' . (int) $item['activity_id'] . '&status=pending') ?>">
                                        Review <?= (int) $item['pending_registrations'] ?>
                                        registration<?= (int) $item['pending_registrations'] === 1 ? '' : 's' ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ((int) $item['pending_documents'] > 0): ?>
                                    <a class="btn btn-outline btn-sm"
                                       href="<?= url('requirements/verify.php?activity_id=' . (int) $item['activity_id']) ?>">
                                        Verify <?= (int) $item['pending_documents'] ?>
                                        document<?= (int) $item['pending_documents'] === 1 ? '' : 's' ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h2>Upcoming activities</h2>
            <?php if ($upcoming !== []): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('activities/index.php') ?>">View all</a>
            <?php endif; ?>
        </div>

        <?php if ($upcoming === []): ?>
            <div class="empty">
                <strong>Nothing scheduled yet</strong>
                <?php if (is_office_staff()): ?>
                    Create the first activity so students can register.
                    <div><a class="btn btn-primary" href="<?= url('activities/manage.php') ?>">Create an activity</a></div>
                <?php else: ?>
                    New activities appear here as soon as the office publishes them.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <ul class="agenda">
                <?php foreach ($upcoming as $activity): ?>
                    <?php $start = strtotime((string) $activity['start_at']); ?>
                    <li>
                        <span class="agenda-date" aria-hidden="true">
                            <span class="mon"><?= e(date('M', $start)) ?></span>
                            <span class="day"><?= e(date('j', $start)) ?></span>
                        </span>
                        <div class="agenda-body">
                            <a href="<?= url('activities/view.php?id=' . (int) $activity['activity_id']) ?>">
                                <?= e($activity['title']) ?>
                            </a>
                            <p class="meta">
                                <?= e(format_datetime($activity['start_at'])) ?>
                                &middot; <?= e($activity['category']) ?>
                                <?= $activity['venue'] ? ' &middot; ' . e($activity['venue']) : '' ?>
                            </p>
                        </div>
                        <span class="agenda-status"><?= status_badge($activity['status'], 'activity') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php // Office roles have three cards; the news row takes the full width below. ?>
    <section class="card<?= $role !== 'student' ? ' span-all' : '' ?>">
        <div class="card-head">
            <h2>Latest announcements</h2>
            <?php if ($announcements !== []): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('announcements/index.php') ?>">View all</a>
            <?php endif; ?>
        </div>

        <?php if ($announcements === []): ?>
            <div class="empty">
                <strong>No announcements yet</strong>
                Office news and schedule changes will be posted here.
            </div>
        <?php else: ?>
            <div class="feed<?= $role !== 'student' ? ' feed-cols' : '' ?>">
                <?php foreach ($announcements as $announcement): ?>
                    <article class="news-item">
                        <?php if ($announcement['is_pinned']): ?>
                            <span class="badge badge-info">Pinned</span>
                        <?php endif; ?>
                        <h3><?= e($announcement['title']) ?></h3>
                        <p class="meta">
                            <?= e(format_date($announcement['published_at'])) ?>
                            <?php if ($announcement['activity_title']): ?>
                                &middot; <a href="<?= url('activities/view.php?id=' . (int) $announcement['activity_id']) ?>"><?= e($announcement['activity_title']) ?></a>
                            <?php endif; ?>
                        </p>
                        <p><?= e(mb_strimwidth(strip_tags($announcement['body']), 0, 180, '…')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
