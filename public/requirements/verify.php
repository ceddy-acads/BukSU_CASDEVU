<?php
/**
 * CASMS — Verify submitted requirements (FR-5.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('activity_id') ?? (int) post('activity_id');
if ($activityId <= 0) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

require_activity_access($activityId);

$activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

// =====================================================================
// Verify / reject
// =====================================================================
if (is_post()) {
    csrf_verify();

    $action       = post('action');
    $submissionId = (int) post('submission_id');

    $submission = fetch_one(
        'SELECT rs.*, ar.name AS requirement_name, r.user_id AS student_id,
                u.first_name, u.last_name
           FROM requirement_submissions rs
           JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
           JOIN registrations r          ON r.registration_id = rs.registration_id
           JOIN users u                  ON u.user_id = r.user_id
          WHERE rs.submission_id = ? AND ar.activity_id = ?',
        [$submissionId, $activityId]
    );
    if ($submission === null) {
        flash('error', 'That submission no longer exists.');
        redirect('requirements/verify.php?activity_id=' . $activityId);
    }

    $studentName = full_name($submission);

    if ($action === 'verify') {
        query(
            "UPDATE requirement_submissions
                SET status = 'verified', verified_by = ?, verified_at = NOW(), reject_reason = NULL
              WHERE submission_id = ?",
            [current_user_id(), $submissionId]
        );
        audit_log('approve', 'requirement_submission', $submissionId,
                  'Verified "' . $submission['requirement_name'] . '" for ' . $studentName);
        notify(
            (int) $submission['student_id'], 'requirement',
            'Requirement verified',
            $submission['requirement_name'] . ' for ' . $activity['title'] . ' has been accepted.',
            url('requirements/submit.php?activity_id=' . $activityId)
        );
        flash('success', 'Verified "' . $submission['requirement_name'] . '" for ' . $studentName . '.');
    } elseif ($action === 'reject') {
        $reason = post('reject_reason');
        if ($reason === '') {
            flash('error', 'Please state what is wrong so the student can correct it.');
            redirect('requirements/verify.php?activity_id=' . $activityId);
        }

        query(
            "UPDATE requirement_submissions
                SET status = 'rejected', verified_by = ?, verified_at = NOW(), reject_reason = ?
              WHERE submission_id = ?",
            [current_user_id(), $reason, $submissionId]
        );
        audit_log('reject', 'requirement_submission', $submissionId,
                  'Rejected "' . $submission['requirement_name'] . '" for ' . $studentName);
        notify(
            (int) $submission['student_id'], 'requirement',
            'Requirement needs correction',
            $submission['requirement_name'] . ': ' . $reason,
            url('requirements/submit.php?activity_id=' . $activityId)
        );
        flash('success', 'Rejected and the student has been notified.');
    }

    redirect('requirements/verify.php?' . http_build_query(array_filter([
        'activity_id' => $activityId,
        'status'      => get('status'),
    ])));
}

// =====================================================================
// Listing
// =====================================================================
$statusFilter = get('status', 'pending');

$conditions = ['ar.activity_id = ?'];
$params     = [$activityId];

if (in_array($statusFilter, ['pending', 'verified', 'rejected'], true)) {
    $conditions[] = 'rs.status = ?';
    $params[]     = $statusFilter;
}

$where = ' WHERE ' . implode(' AND ', $conditions);

$submissions = fetch_all(
    "SELECT rs.*, ar.name AS requirement_name, ar.deadline_at, ar.needs_file,
            u.student_number, u.first_name, u.last_name,
            c.code AS course_code, y.label AS year_level_label
       FROM requirement_submissions rs
       JOIN activity_requirements ar ON ar.requirement_id  = rs.requirement_id
       JOIN registrations r          ON r.registration_id  = rs.registration_id
       JOIN users u                  ON u.user_id          = r.user_id
       LEFT JOIN courses c           ON c.course_id        = u.course_id
       LEFT JOIN year_levels y       ON y.year_level_id    = u.year_level_id
       $where
      ORDER BY FIELD(rs.status,'pending','rejected','verified'), rs.submitted_at ASC",
    $params
);

$counts = fetch_one(
    "SELECT
        SUM(rs.status = 'pending')  AS pending,
        SUM(rs.status = 'verified') AS verified,
        SUM(rs.status = 'rejected') AS rejected
       FROM requirement_submissions rs
       JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
      WHERE ar.activity_id = ?",
    [$activityId]
);

// Students who have not submitted a mandatory requirement at all (FR-5.6).
$outstanding = fetch_all(
    'SELECT student_name, student_number, requirement_name, deadline_at
       FROM v_missing_requirements
      WHERE activity_id = ?
      ORDER BY student_name, requirement_name',
    [$activityId]
);

$pageTitle = 'Verify requirements: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Verify requirements</h1>
        <p><?= e($activity['title']) ?> &middot; review what students have submitted</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('requirements/manage.php?activity_id=' . $activityId) ?>">Define requirements</a>
        <a class="btn btn-outline" href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">Participants</a>
    </div>
</div>

<div class="stats stats-3">
    <div class="stat <?= stat_tone($counts['pending'], 'stat-gold') ?>">
        <div class="stat-value"><?= (int) $counts['pending'] ?></div>
        <div class="stat-label">Awaiting verification</div>
    </div>
    <div class="stat <?= stat_tone($counts['verified'], 'stat-success') ?>">
        <div class="stat-value"><?= (int) $counts['verified'] ?></div>
        <div class="stat-label">Verified</div>
    </div>
    <div class="stat <?= stat_tone(count($outstanding), 'stat-danger') ?>">
        <div class="stat-value"><?= count($outstanding) ?></div>
        <div class="stat-label">Still outstanding</div>
    </div>
</div>

<form method="get" class="filter-bar filter-bar-compact">
    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
    <div class="form-row">
        <label for="status">Show</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value=""         <?= $statusFilter === ''         ? 'selected' : '' ?>>All submissions</option>
            <option value="pending"  <?= $statusFilter === 'pending'  ? 'selected' : '' ?>>Awaiting verification</option>
            <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Show</button>
    </div>
</form>

<?php if ($submissions === []): ?>
    <div class="empty mb-6">
        <?php if ($statusFilter === 'pending'): ?>
            <strong>Nothing waiting to be verified</strong>
            Every submission for this activity has been reviewed.
        <?php elseif ($statusFilter === ''): ?>
            <strong>No submissions yet</strong>
            Documents appear here once registered students submit them.
        <?php else: ?>
            <strong>No <?= e($statusFilter) ?> submissions</strong>
            No submissions match this filter. Choose another option under Show.
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="card card-flush">
        <div class="table-wrap">
            <table class="data" data-sortable>
                <thead>
                    <tr>
                        <th data-sort="text">Student</th><th data-sort="text">Requirement</th><th>Document</th>
                        <th data-sort="number">Submitted</th><th data-sort="text">Status</th><th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($submissions as $submission): ?>
                    <?php $rowName = full_name($submission, true); ?>
                    <tr>
                        <td>
                            <strong><?= e($rowName) ?></strong>
                            <div class="hint">
                                <?= $submission['student_number'] ? e($submission['student_number']) : 'No student number' ?>
                                <?= $submission['course_code'] ? ' &middot; ' . e($submission['course_code']) : '' ?>
                                <?= $submission['year_level_label'] ? ' ' . e($submission['year_level_label']) : '' ?>
                            </div>
                        </td>
                        <td>
                            <?= e($submission['requirement_name']) ?>
                            <?php if ($submission['note']): ?>
                                <div class="hint">Note: <?= e($submission['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($submission['file_path']): ?>
                                <a href="<?= url('requirements/download.php?submission_id=' . (int) $submission['submission_id']) ?>"
                                   target="_blank" rel="noopener">
                                    <?= e($submission['original_name']) ?>
                                </a>
                                <div class="hint"><?= e(format_filesize($submission['file_size'] === null ? null : (int) $submission['file_size'])) ?></div>
                            <?php else: ?>
                                <span class="muted">Acknowledgement only</span>
                            <?php endif; ?>
                        </td>
                        <td data-sort-value="<?= (int) strtotime((string) $submission['submitted_at']) ?>">
                            <span class="nowrap"><?= e(format_datetime($submission['submitted_at'])) ?></span>
                            <?php if ($submission['deadline_at']
                                      && strtotime((string) $submission['submitted_at'])
                                         > strtotime((string) $submission['deadline_at'])): ?>
                                <div><span class="badge badge-danger">Late</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= status_badge($submission['status'], 'submission') ?>
                            <?php if ($submission['reject_reason']): ?>
                                <div class="hint"><?= e($submission['reject_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <div class="btn-row">
                                <?php if ($submission['status'] !== 'verified'): ?>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="verify">
                                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                        <input type="hidden" name="submission_id" value="<?= (int) $submission['submission_id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm"
                                                aria-label="Verify <?= e($submission['requirement_name']) ?> for <?= e($rowName) ?>">Verify</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($submission['status'] !== 'rejected'): ?>
                                    <button type="button" class="btn btn-outline btn-sm"
                                            aria-label="Reject <?= e($submission['requirement_name']) ?> for <?= e($rowName) ?>"
                                            onclick="document.getElementById('rej-<?= (int) $submission['submission_id'] ?>').hidden = false; this.hidden = true;">
                                        Reject
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($submission['status'] !== 'rejected'): ?>
                                <form method="post" id="rej-<?= (int) $submission['submission_id'] ?>" hidden class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="submission_id" value="<?= (int) $submission['submission_id'] ?>">
                                    <div class="btn-row">
                                        <input type="text" name="reject_reason" required class="input-auto input-sm"
                                               aria-label="What needs correcting for <?= e($rowName) ?>"
                                               placeholder="What needs correcting?">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            Confirm rejection
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($outstanding !== []): ?>
    <section class="card card-flush">
        <div class="card-head">
            <h2>Not yet submitted (<?= count($outstanding) ?>)</h2>
            <p class="hint">
                Mandatory requirements with nothing submitted, or with a rejected submission awaiting correction.
            </p>
        </div>
        <div class="table-wrap">
            <table class="data table-stack">
                <thead><tr><th>Student</th><th>Requirement</th><th>Deadline</th></tr></thead>
                <tbody>
                <?php foreach ($outstanding as $row): ?>
                    <tr>
                        <td data-label="Student">
                            <div>
                                <?= e($row['student_name']) ?>
                                <div class="hint"><?= $row['student_number'] ? e($row['student_number']) : 'No student number' ?></div>
                            </div>
                        </td>
                        <td data-label="Requirement"><?= e($row['requirement_name']) ?></td>
                        <td data-label="Deadline">
                            <div>
                                <?= $row['deadline_at'] ? e(format_datetime($row['deadline_at'])) : '<span class="muted">No deadline</span>' ?>
                                <?php if ($row['deadline_at'] && strtotime((string) $row['deadline_at']) < time()): ?>
                                    <span class="badge badge-danger">Overdue</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
