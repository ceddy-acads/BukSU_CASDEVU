<?php
/**
 * CASMS — Student requirement checklist and upload (FR-5.3, FR-5.6)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

$student    = current_user();
$activityId = get_id('activity_id') ?? (int) post('activity_id');
if ($activityId <= 0) {
    http_response_code(404);
    exit('404 — Activity not found.');
}

$activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) {
    http_response_code(404);
    exit('404 — Activity not found.');
}

// A student may only submit for an activity they actually registered for.
$registration = fetch_one(
    'SELECT * FROM registrations WHERE activity_id = ? AND user_id = ?',
    [$activityId, $student['user_id']]
);
if ($registration === null || in_array($registration['status'], ['withdrawn', 'rejected'], true)) {
    flash('error', 'You need an active registration for this activity before submitting documents.');
    redirect('activities/view.php?id=' . $activityId);
}

$registrationId = (int) $registration['registration_id'];

// Documents are only accepted while the activity is still running. A closed,
// completed, or cancelled activity is settled — accepting files for it would
// create submissions nobody will ever review.
$acceptsSubmissions = in_array($activity['status'], ['upcoming', 'ongoing'], true);
$closedReason       = match ($activity['status']) {
    'cancelled' => 'This activity has been cancelled, so documents are no longer accepted.',
    'completed' => 'This activity has finished, so documents are no longer accepted.',
    'closed'    => 'This activity is closed, so documents are no longer accepted.',
    'draft'     => 'This activity is not yet open.',
    default     => '',
};

// =====================================================================
// Upload / acknowledge
// =====================================================================
if (is_post()) {
    csrf_verify();

    // Enforced server-side, not merely by hiding the form below.
    if (!$acceptsSubmissions) {
        flash('error', $closedReason);
        redirect('requirements/submit.php?activity_id=' . $activityId);
    }

    $requirementId = (int) post('requirement_id');

    $requirement = fetch_one(
        'SELECT * FROM activity_requirements WHERE requirement_id = ? AND activity_id = ?',
        [$requirementId, $activityId]
    );
    if ($requirement === null) {
        flash('error', 'That requirement does not belong to this activity.');
        redirect('requirements/submit.php?activity_id=' . $activityId);
    }

    $existing = fetch_one(
        'SELECT * FROM requirement_submissions WHERE requirement_id = ? AND registration_id = ?',
        [$requirementId, $registrationId]
    );

    // A verified submission is settled; re-uploading would quietly undo the
    // office's decision.
    if ($existing !== null && $existing['status'] === 'verified') {
        flash('error', 'That requirement has already been verified and cannot be replaced.');
        redirect('requirements/submit.php?activity_id=' . $activityId);
    }

    $note = post('note');

    if ((int) $requirement['needs_file'] === 1) {
        $stored = store_upload($_FILES['document'] ?? [], 'requirements');

        if (!$stored['ok']) {
            flash('error', $stored['error']);
            redirect('requirements/submit.php?activity_id=' . $activityId);
        }

        if ($existing !== null) {
            delete_upload($existing['file_path']);          // replace, don't accumulate
            query(
                "UPDATE requirement_submissions
                    SET file_path = ?, original_name = ?, mime_type = ?, file_size = ?, note = ?,
                        status = 'pending', verified_by = NULL, verified_at = NULL,
                        reject_reason = NULL, submitted_at = NOW()
                  WHERE submission_id = ?",
                [
                    $stored['path'], $stored['original'], $stored['mime'], $stored['size'],
                    $note !== '' ? $note : null, $existing['submission_id'],
                ]
            );
            $submissionId = (int) $existing['submission_id'];
        } else {
            query(
                'INSERT INTO requirement_submissions
                     (requirement_id, registration_id, file_path, original_name, mime_type, file_size, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $requirementId, $registrationId, $stored['path'], $stored['original'],
                    $stored['mime'], $stored['size'], $note !== '' ? $note : null,
                ]
            );
            $submissionId = (int) db()->lastInsertId();
        }
    } else {
        // Acknowledgement-only requirement — no file involved.
        if ($existing !== null) {
            query(
                "UPDATE requirement_submissions
                    SET note = ?, status = 'pending', verified_by = NULL, verified_at = NULL,
                        reject_reason = NULL, submitted_at = NOW()
                  WHERE submission_id = ?",
                [$note !== '' ? $note : null, $existing['submission_id']]
            );
            $submissionId = (int) $existing['submission_id'];
        } else {
            query(
                'INSERT INTO requirement_submissions (requirement_id, registration_id, note)
                 VALUES (?, ?, ?)',
                [$requirementId, $registrationId, $note !== '' ? $note : null]
            );
            $submissionId = (int) db()->lastInsertId();
        }
    }

    audit_log('create', 'requirement_submission', $submissionId,
              'Submitted "' . $requirement['name'] . '" for ' . $activity['title']);

    flash('success', '"' . $requirement['name'] . '" submitted. The office will review it shortly.');
    redirect('requirements/submit.php?activity_id=' . $activityId);
}

// =====================================================================
// Checklist
// =====================================================================
$items = fetch_all(
    'SELECT ar.*,
            rs.submission_id, rs.file_path, rs.original_name, rs.file_size,
            rs.status AS submission_status, rs.reject_reason, rs.submitted_at, rs.note
       FROM activity_requirements ar
       LEFT JOIN requirement_submissions rs
              ON rs.requirement_id = ar.requirement_id AND rs.registration_id = ?
      WHERE ar.activity_id = ?
      ORDER BY ar.sort_order, ar.requirement_id',
    [$registrationId, $activityId]
);

$progress    = requirement_progress($registrationId);
$maxUploadMb = (int) (MAX_UPLOAD_BYTES / 1024 / 1024);

$pageTitle = 'My requirements — ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>My requirements</h1>
        <p>
            <a href="<?= url('activities/view.php?id=' . $activityId) ?>"><?= e($activity['title']) ?></a>
            &middot; registration is <?= status_badge($registration['status']) ?>
        </p>
    </div>
    <a class="btn btn-outline" href="<?= url('participation/my-activities.php') ?>">My activities</a>
</div>

<?php if ($items === []): ?>
    <div class="empty">
        <strong>Nothing to submit</strong>
        This activity does not require any documents.
    </div>
<?php else: ?>
    <?php if ($progress['complete']): ?>
        <div class="alert alert-success">
            All required documents have been verified. Nothing further is needed from you.
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong><?= $progress['missing'] ?></strong> of <?= $progress['total'] ?>
            required document<?= $progress['total'] === 1 ? '' : 's' ?> still outstanding.
        </div>
    <?php endif; ?>

    <?php foreach ($items as $item): ?>
        <?php
        $submissionStatus = $item['submission_status'];           // null when nothing submitted
        $isSettled        = $submissionStatus === 'verified';
        $deadlinePassed   = $item['deadline_at'] && strtotime((string) $item['deadline_at']) < time();
        ?>
        <section class="card">
            <div style="display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;">
                <div style="flex:1 1 300px;">
                    <h2 style="margin:0 0 .35rem;">
                        <?= e($item['name']) ?>
                        <?php if (!$item['is_mandatory']): ?>
                            <span class="badge badge-muted">Optional</span>
                        <?php endif; ?>
                    </h2>

                    <?php if ($item['description']): ?>
                        <p style="margin:0 0 .5rem;font-size:.9rem;"><?= e($item['description']) ?></p>
                    <?php endif; ?>

                    <p class="hint" style="margin:0;">
                        Deadline: <?= e(format_datetime($item['deadline_at'])) ?>
                        <?php if ($deadlinePassed && !$isSettled): ?>
                            <span class="badge badge-danger">Past due</span>
                        <?php endif; ?>
                    </p>

                    <?php if ($submissionStatus === null): ?>
                        <p style="margin:.6rem 0 0;"><span class="badge badge-warning">Not yet submitted</span></p>
                    <?php else: ?>
                        <p style="margin:.6rem 0 0;">
                            <?= status_badge($submissionStatus) ?>
                            <span class="hint">submitted <?= e(format_datetime($item['submitted_at'])) ?></span>
                        </p>

                        <?php if ($item['file_path']): ?>
                            <p style="margin:.35rem 0 0;font-size:.88rem;">
                                <a href="<?= url('requirements/download.php?submission_id=' . (int) $item['submission_id']) ?>">
                                    <?= e($item['original_name']) ?>
                                </a>
                                <span class="hint">(<?= e(format_filesize($item['file_size'] === null ? null : (int) $item['file_size'])) ?>)</span>
                            </p>
                        <?php endif; ?>

                        <?php if ($submissionStatus === 'rejected' && $item['reject_reason']): ?>
                            <div class="alert alert-error" style="margin:.6rem 0 0;">
                                <strong>Not accepted:</strong> <?= e($item['reject_reason']) ?><br>
                                Please correct it and submit again below.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div style="flex:0 1 300px;">
                    <?php if ($isSettled): ?>
                        <div class="alert alert-success" style="margin:0;">
                            Verified by the office. No further action needed.
                        </div>
                    <?php elseif (!$acceptsSubmissions): ?>
                        <div class="alert alert-warning" style="margin:0;">
                            <?= e($closedReason) ?>
                        </div>
                    <?php else: ?>
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                            <input type="hidden" name="requirement_id" value="<?= (int) $item['requirement_id'] ?>">

                            <?php if ((int) $item['needs_file'] === 1): ?>
                                <div class="form-row">
                                    <label for="document-<?= (int) $item['requirement_id'] ?>">
                                        <?= $submissionStatus === null ? 'Upload document' : 'Replace document' ?>
                                    </label>
                                    <input type="file" id="document-<?= (int) $item['requirement_id'] ?>"
                                           name="document" required accept=".pdf,.jpg,.jpeg,.png">
                                    <div class="hint">PDF, JPG, or PNG — up to <?= $maxUploadMb ?> MB.</div>
                                </div>
                            <?php endif; ?>

                            <div class="form-row">
                                <label for="note-<?= (int) $item['requirement_id'] ?>">Note (optional)</label>
                                <input type="text" id="note-<?= (int) $item['requirement_id'] ?>" name="note"
                                       value="<?= e($item['note'] ?? '') ?>" placeholder="Anything the office should know">
                            </div>

                            <button type="submit" class="btn btn-gold btn-block">
                                <?= $submissionStatus === null
                                    ? ((int) $item['needs_file'] === 1 ? 'Submit document' : 'Acknowledge')
                                    : 'Submit again' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
