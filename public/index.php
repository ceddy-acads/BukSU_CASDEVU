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
            // Same set as the documents page (requirements/mine.php): completed
            // activities no longer ask for anything.
            "SELECT COUNT(*) FROM v_missing_requirements m
               JOIN activities a ON a.activity_id = m.activity_id
              WHERE m.user_id = ? AND a.status <> 'completed'", [$user['user_id']]),
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
            'class' => 'stat-gold', 'href' => 'review.php?lane=registrations'],
        ['label' => 'Requirements to verify', 'value' => review_counts()['documents'],
            'class' => 'stat-gold', 'href' => 'review.php?lane=documents'],
        ['label' => 'Items on loan', 'value' => fetch_value(
            'SELECT COUNT(*) FROM borrowings WHERE returned_at IS NULL'),
            'class' => '', 'href' => is_office_staff() ? 'inventory/borrowings.php' : null],
    ];
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layout/header.php';
?>

<?php // The welcome sits on a BukSU event photo: the first screen after signing in. ?>
<div class="page-head photo-band"<?= photo_band_style('stadium') ?>>
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
            <a class="btn btn-gold" href="<?= url('activities/manage.php') ?>"
               data-modal data-modal-title="New activity" data-modal-wrap="#activity-form-wrap">New activity</a>
        </div>
    <?php elseif ($role === 'student'): ?>
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Browse activities</a>
        </div>
    <?php endif; ?>
</div>

<?php // Alerts that used to sit here (pending accounts, overdue returns, missing
      // documents) now open in the notification pop-up: includes/attention.php. ?>

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
    <?php // Registrations and documents waiting for review open in the
          // notification pop-up (includes/attention.php), so for office roles
          // the agenda takes the row the old work-queue card shared. ?>
    <section class="card<?= $role !== 'student' ? ' span-all' : '' ?>">
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

    <?php if (is_office_staff()): ?>
        <?php // Participation at a glance for the office: the trend and where it goes. ?>
        <section class="card">
            <?= bar_chart(
                'Registrations per month',
                'Registrations received over the last six months, by outcome so far.',
                registration_trend_rows(6)
            ) ?>
        </section>
        <section class="card">
            <?= bar_chart(
                'Participants by category',
                'Approved or completed registrations in each category, all time.',
                participants_by_category_rows()
            ) ?>
            <p class="chart-more"><a href="<?= url('reports/participation.php') ?>">Open the participation report</a></p>
        </section>
    <?php endif; ?>

    <?php // The news row takes the full width below for office roles. ?>
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
                        <p class="announcement-body"><?= e(mb_strimwidth(strip_tags($announcement['body']), 0, 180, '…')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout/modal-dialog.php'; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
