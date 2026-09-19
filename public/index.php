<?php
/**
 * CASMS — Role-specific dashboard (Week 1 milestone)
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
    "SELECT announcement_id, title, body, published_at
       FROM announcements
      WHERE status = 'published'
      ORDER BY is_pinned DESC, published_at DESC
      LIMIT 3"
);

// --------------------------------------------------------- Role statistics
$stats = [];

if ($role === 'student') {
    $stats = [
        ['label' => 'My registrations', 'value' => fetch_value(
            'SELECT COUNT(*) FROM registrations WHERE user_id = ?', [$user['user_id']]), 'class' => ''],
        ['label' => 'Approved',         'value' => fetch_value(
            "SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'approved'", [$user['user_id']]), 'class' => 'stat-success'],
        ['label' => 'Awaiting review',  'value' => fetch_value(
            "SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'pending'", [$user['user_id']]), 'class' => 'stat-gold'],
        ['label' => 'Missing requirements', 'value' => fetch_value(
            'SELECT COUNT(*) FROM v_missing_requirements WHERE user_id = ?', [$user['user_id']]), 'class' => 'stat-danger'],
    ];
} else {
    $stats = [
        ['label' => 'Active activities', 'value' => fetch_value(
            "SELECT COUNT(*) FROM activities WHERE status IN ('upcoming','ongoing')"), 'class' => ''],
        ['label' => 'Pending registrations', 'value' => fetch_value(
            "SELECT COUNT(*) FROM registrations WHERE status = 'pending'"), 'class' => 'stat-gold'],
        ['label' => 'Requirements to verify', 'value' => fetch_value(
            "SELECT COUNT(*) FROM requirement_submissions WHERE status = 'pending'"), 'class' => 'stat-danger'],
        ['label' => 'Items on loan', 'value' => fetch_value(
            'SELECT COUNT(*) FROM borrowings WHERE returned_at IS NULL'), 'class' => 'stat-success'],
    ];
}

// Accounts awaiting activation — the office's most common Week 1 task.
$pendingAccounts = is_office_staff()
    ? (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
    : 0;

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
        <a class="btn btn-gold" href="<?= url('activities/manage.php') ?>">+ New activity</a>
    <?php endif; ?>
</div>

<?php if ($pendingAccounts > 0): ?>
    <div class="alert alert-warning">
        <strong><?= $pendingAccounts ?></strong> student account<?= $pendingAccounts === 1 ? '' : 's' ?>
        awaiting approval.
        <a href="<?= url('admin/users.php?status=pending') ?>">Review now</a>
    </div>
<?php endif; ?>

<?php if (is_office_staff()): ?>
    <?php $overdueItems = overdue_borrowing_count(); ?>
    <?php if ($overdueItems > 0): ?>
        <div class="alert alert-error">
            <strong><?= $overdueItems ?></strong> borrowed item<?= $overdueItems === 1 ? ' is' : 's are' ?>
            past the expected return date.
            <a href="<?= url('inventory/borrowings.php?filter=overdue') ?>">Review now</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="grid grid-4" style="margin-bottom:1.5rem;">
    <?php foreach ($stats as $stat): ?>
        <div class="stat <?= e($stat['class']) ?>">
            <div class="stat-value"><?= (int) $stat['value'] ?></div>
            <div class="stat-label"><?= e($stat['label']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Upcoming activities</h2></div>

        <?php if ($upcoming === []): ?>
            <div class="empty">
                <strong>Nothing scheduled yet</strong>
                <?= is_office_staff() ? 'Create the first activity to get started.' : 'Check back soon.' ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Activity</th><th>Category</th><th>When</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($upcoming as $activity): ?>
                        <tr>
                            <td>
                                <a href="<?= url('activities/view.php?id=' . (int) $activity['activity_id']) ?>">
                                    <?= e($activity['title']) ?>
                                </a>
                                <?php if ($activity['venue']): ?>
                                    <div class="hint"><?= e($activity['venue']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($activity['category']) ?></td>
                            <td><?= e(format_datetime($activity['start_at'])) ?></td>
                            <td><?= status_badge($activity['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="btn-row" style="margin-top:1rem;">
                <a class="btn btn-outline btn-sm" href="<?= url('activities/index.php') ?>">View all activities</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Latest announcements</h2></div>

        <?php if ($announcements === []): ?>
            <div class="empty"><strong>No announcements</strong> Nothing has been posted yet.</div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <article style="padding-bottom:.85rem;margin-bottom:.85rem;border-bottom:1px solid var(--line);">
                    <h3 style="margin:0 0 .25rem;font-size:.98rem;"><?= e($announcement['title']) ?></h3>
                    <p class="hint" style="margin:0 0 .35rem;"><?= e(format_date($announcement['published_at'])) ?></p>
                    <p style="margin:0;font-size:.88rem;">
                        <?= e(mb_strimwidth(strip_tags($announcement['body']), 0, 180, '…')) ?>
                    </p>
                </article>
            <?php endforeach; ?>
            <div class="btn-row" style="margin-top:1rem;">
                <a class="btn btn-outline btn-sm" href="<?= url('announcements/index.php') ?>">All announcements</a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
