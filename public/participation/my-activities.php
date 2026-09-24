<?php
/**
 * CASMS — A student's own registrations (FR-4.5, FR-8.2)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

$student = current_user();

$registrations = fetch_all(
    "SELECT r.*, a.title, a.start_at, a.end_at, a.status AS activity_status,
            c.name AS category, c.color_hex, v.name AS venue
       FROM registrations r
       JOIN activities a          ON a.activity_id = r.activity_id
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
      WHERE r.user_id = ?
      ORDER BY FIELD(r.status,'pending','approved','completed','rejected','withdrawn'),
               a.start_at DESC",
    [$student['user_id']]
);

// Requirement progress per registration, so the student sees what is still owed.
foreach ($registrations as $index => $registration) {
    $registrations[$index]['progress'] = requirement_progress((int) $registration['registration_id']);
}

$activeCount = count(array_filter(
    $registrations,
    static fn(array $r): bool => in_array($r['status'], ['pending', 'approved'], true)
));

$pageTitle = 'My activities';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>My activities</h1>
        <p><?= $activeCount ?> active registration<?= $activeCount === 1 ? '' : 's' ?>. Check each one for documents you still need to submit.</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Browse activities</a>
    </div>
</div>

<?php if ($registrations === []): ?>
    <div class="empty">
        <strong>You have not registered for anything yet</strong>
        Browse the activities list to find something to join.
        <a class="btn btn-primary" href="<?= url('activities/index.php') ?>">Find an activity</a>
    </div>
<?php else: ?>
    <?php foreach ($registrations as $registration): ?>
        <?php
        $progress   = $registration['progress'];
        $needsFiles = $progress['total'] > 0 && !$progress['complete']
                      && in_array($registration['status'], ['pending', 'approved'], true);
        $pct        = $progress['total'] > 0
                      ? (int) round($progress['satisfied'] / $progress['total'] * 100)
                      : 0;
        ?>
        <section class="card">
            <div class="item">
                <div class="item-main">
                    <?= status_badge($registration['status'], 'registration') ?>
                    <h2 class="item-title">
                        <a href="<?= url('activities/view.php?id=' . (int) $registration['activity_id']) ?>">
                            <?= e($registration['title']) ?>
                        </a>
                    </h2>
                    <p class="meta">
                        <?= e($registration['category']) ?> &middot;
                        <?= e(format_datetime($registration['start_at'])) ?>
                        <?= $registration['venue'] ? ' &middot; ' . e($registration['venue']) : '' ?>
                    </p>
                    <p class="item-body">
                        <?= e(registration_stage_label($registration['status'])) ?>
                    </p>

                    <?php if ($registration['status'] === 'rejected' && $registration['review_remarks']): ?>
                        <div class="alert alert-error">
                            <strong>Reason:</strong> <?= e($registration['review_remarks']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="item-aside">
                    <?php if ($progress['total'] > 0): ?>
                        <div class="progress-label">
                            Requirements verified: <strong><?= $progress['satisfied'] ?></strong> of <?= $progress['total'] ?>
                        </div>
                        <div class="progress" role="img"
                             aria-label="<?= $progress['satisfied'] ?> of <?= $progress['total'] ?> requirements verified">
                            <span style="width: <?= $pct ?>%"></span>
                        </div>
                        <?php if ($needsFiles): ?>
                            <a class="btn btn-gold btn-sm btn-block"
                               href="<?= url('requirements/submit.php?activity_id=' . (int) $registration['activity_id']) ?>">
                                Upload or check documents
                            </a>
                        <?php else: ?>
                            <a class="btn btn-outline btn-sm btn-block"
                               href="<?= url('requirements/submit.php?activity_id=' . (int) $registration['activity_id']) ?>">
                                View my documents
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="muted">No documents are required.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
