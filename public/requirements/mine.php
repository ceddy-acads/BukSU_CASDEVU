<?php
/**
 * CASDevU — My requirements: every document a student's activities ask for,
 * in one place, grouped by whose turn it is (FR-5.1, FR-5.6).
 *
 * Read-only. Uploading still happens on requirements/submit.php, which each
 * item links to directly.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

// Requirements for every live registration: still awaiting review or
// approved, on an activity that is not cancelled or over.
$rows = fetch_all(
    "SELECT ar.requirement_id, ar.name, ar.description, ar.is_mandatory, ar.needs_file, ar.deadline_at,
            a.activity_id, a.title AS activity_title,
            g.status AS registration_status,
            rs.submission_id, rs.status AS submission_status, rs.submitted_at,
            rs.reject_reason, rs.original_name
       FROM registrations g
       JOIN activities a             ON a.activity_id     = g.activity_id
       JOIN activity_requirements ar ON ar.activity_id    = a.activity_id
       LEFT JOIN requirement_submissions rs
              ON rs.requirement_id = ar.requirement_id AND rs.registration_id = g.registration_id
      WHERE g.user_id = ?
        AND g.status IN ('pending', 'approved')
        AND a.status NOT IN ('cancelled', 'completed')
      ORDER BY ar.deadline_at IS NULL, ar.deadline_at, a.start_at, ar.sort_order, ar.requirement_id",
    [(int) current_user_id()]
);

$lanes = ['student' => [], 'office' => [], 'none' => []];
foreach ($rows as $row) {
    $row['state'] = requirement_state($row['submission_status'], $row['deadline_at'], (int) $row['needs_file'] === 1);
    $lanes[$row['state']['owner']][] = $row;
}

$laneInfo = [
    'student' => ['Needs you', 'needs-you', 'Upload or fix these. The office cannot review them until you do.'],
    'office'  => ['With the office', 'with-office', 'You have done your part. The office is reviewing these.'],
    'none'    => ['Done', 'done', 'Verified by the office. Nothing more to do.'],
];

$pageTitle = 'My requirements';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>My requirements</h1>
        <p>Every document your activities ask for, and whose turn it is.</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('participation/my-activities.php') ?>">My activities</a>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="empty">
        <strong>No requirements yet</strong>
        Requirements appear here once you register for an activity that asks for documents.
        <div class="btn-row">
            <a class="btn btn-primary" href="<?= url('activities/index.php') ?>">Find an activity</a>
        </div>
    </div>
<?php else: ?>
    <div class="stats stats-3">
        <a class="stat <?= stat_tone(count($lanes['student']), 'stat-gold') ?>" href="#needs-you">
            <span class="stat-value"><?= count($lanes['student']) ?></span>
            <span class="stat-label">Need you</span>
        </a>
        <a class="stat" href="#with-office">
            <span class="stat-value"><?= count($lanes['office']) ?></span>
            <span class="stat-label">With the office</span>
        </a>
        <a class="stat <?= stat_tone(count($lanes['none']), 'stat-success') ?>" href="#done">
            <span class="stat-value"><?= count($lanes['none']) ?></span>
            <span class="stat-label">Done</span>
        </a>
    </div>

    <?php foreach ($laneInfo as $owner => [$title, $anchor, $intro]): ?>
        <section class="card" id="<?= $anchor ?>">
            <div class="card-head">
                <h2><?= e($title) ?> <span class="muted">(<?= count($lanes[$owner]) ?>)</span></h2>
                <p class="hint"><?= e($intro) ?></p>
            </div>

            <?php if ($lanes[$owner] === []): ?>
                <p class="muted mb-0">
                    <?= $owner === 'student' ? 'Nothing needs you right now.' : ($owner === 'office' ? 'Nothing is waiting for review.' : 'Nothing verified yet.') ?>
                </p>
            <?php else: ?>
                <ul class="feed">
                    <?php foreach ($lanes[$owner] as $item): ?>
                        <?php
                        $submitUrl = url('requirements/submit.php?activity_id=' . (int) $item['activity_id'])
                                   . '#req-' . (int) $item['requirement_id'];
                        ?>
                        <li class="req-row">
                            <div class="feed-row">
                                <div>
                                    <?= status_badge($item['state']['key'], 'submission') ?>
                                    <?php if (!$item['is_mandatory']): ?>
                                        <span class="badge badge-muted">Optional</span>
                                    <?php endif; ?>
                                    <h3 class="mt-2"><?= e($item['name']) ?></h3>
                                    <p class="meta">
                                        <a href="<?= url('activities/view.php?id=' . (int) $item['activity_id']) ?>"><?= e($item['activity_title']) ?></a>
                                        &middot; Deadline: <?= $item['deadline_at'] ? e(format_datetime($item['deadline_at'])) : 'None' ?>
                                        <?php if ($item['submitted_at']): ?>
                                            &middot; Submitted <?= e(format_datetime($item['submitted_at'])) ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($item['registration_status'] === 'pending'): ?>
                                        <p class="hint">Your registration for this activity is still waiting for approval.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($owner === 'student'): ?>
                                    <a class="btn btn-primary btn-sm" href="<?= e($submitUrl) ?>">
                                        <?= $item['state']['key'] === 'rejected' ? 'Upload again' : ((int) $item['needs_file'] === 1 ? 'Upload' : 'Confirm') ?>
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-outline btn-sm" href="<?= e($submitUrl) ?>">View</a>
                                <?php endif; ?>
                            </div>

                            <?= requirement_steps_html($item['state']['key']) ?>
                            <?= requirement_next_html($item['state']) ?>

                            <?php if ($item['state']['key'] === 'rejected' && $item['reject_reason']): ?>
                                <div class="alert alert-error mt-2 mb-0">
                                    <strong>Reason from the office:</strong> <?= e($item['reject_reason']) ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
