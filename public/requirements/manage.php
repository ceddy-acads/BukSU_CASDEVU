<?php
/**
 * CASMS — Define the requirements attached to an activity (FR-5.1, FR-5.2)
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

$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------------------------------------------------------- Add / edit
    if ($action === 'save') {
        $requirementId = (int) post('requirement_id');
        $name          = post('name');
        $description   = post('description');
        $deadline      = post('deadline_at');
        $isMandatory   = post('is_mandatory') === '1' ? 1 : 0;
        $needsFile     = post('needs_file') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'The requirement name is required.';
        }
        if ($deadline !== '' && strtotime($deadline) === false) {
            $errors[] = 'The deadline is not a valid date.';
        }

        if ($errors === []) {
            if ($requirementId > 0) {
                // Confirm it belongs to this activity before touching it.
                $owns = fetch_value(
                    'SELECT 1 FROM activity_requirements WHERE requirement_id = ? AND activity_id = ?',
                    [$requirementId, $activityId]
                );
                if (!$owns) {
                    http_response_code(403);
                    abort_page(403, 'That requirement belongs to another activity.');
                }

                query(
                    'UPDATE activity_requirements
                        SET name = ?, description = ?, deadline_at = ?, is_mandatory = ?, needs_file = ?
                      WHERE requirement_id = ?',
                    [
                        $name,
                        $description !== '' ? $description : null,
                        $deadline !== '' ? $deadline : null,
                        $isMandatory, $needsFile, $requirementId,
                    ]
                );
                audit_log('update', 'requirement', $requirementId, 'Updated requirement: ' . $name);
                flash('success', 'Requirement updated.');
            } else {
                $nextOrder = (int) fetch_value(
                    'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM activity_requirements WHERE activity_id = ?',
                    [$activityId]
                );

                query(
                    'INSERT INTO activity_requirements
                         (activity_id, name, description, deadline_at, is_mandatory, needs_file, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $activityId, $name,
                        $description !== '' ? $description : null,
                        $deadline !== '' ? $deadline : null,
                        $isMandatory, $needsFile, $nextOrder,
                    ]
                );
                $newId = (int) db()->lastInsertId();
                audit_log('create', 'requirement', $newId, 'Added requirement: ' . $name);

                // Tell everyone already registered that something new is owed.
                $registered = fetch_all(
                    "SELECT user_id FROM registrations
                      WHERE activity_id = ? AND status IN ('pending','approved')",
                    [$activityId]
                );
                notify_many(
                    array_map(static fn(array $r): int => (int) $r['user_id'], $registered),
                    'requirement',
                    'New requirement added',
                    $activity['title'] . ' now requires: ' . $name,
                    url('requirements/submit.php?activity_id=' . $activityId)
                );

                flash('success', 'Requirement added.');
            }

            redirect('requirements/manage.php?activity_id=' . $activityId);
        }

        remember_input($_POST);
    }

    // -------------------------------------------------------------- Delete
    if ($action === 'delete') {
        $requirementId = (int) post('requirement_id');

        $requirement = fetch_one(
            'SELECT * FROM activity_requirements WHERE requirement_id = ? AND activity_id = ?',
            [$requirementId, $activityId]
        );
        if ($requirement === null) {
            flash('error', 'That requirement no longer exists.');
            redirect('requirements/manage.php?activity_id=' . $activityId);
        }

        // Remove the stored files too — the cascade drops the rows, but the
        // documents on disk would otherwise be orphaned forever.
        $submissions = fetch_all(
            'SELECT file_path FROM requirement_submissions WHERE requirement_id = ?',
            [$requirementId]
        );
        foreach ($submissions as $submission) {
            delete_upload($submission['file_path']);
        }

        query('DELETE FROM activity_requirements WHERE requirement_id = ?', [$requirementId]);
        audit_log('delete', 'requirement', $requirementId,
                  'Deleted requirement: ' . $requirement['name']);

        flash('success', 'Requirement deleted, along with ' . count($submissions) . ' submitted file(s).');
        redirect('requirements/manage.php?activity_id=' . $activityId);
    }
}

$requirements = fetch_all(
    "SELECT ar.*,
            (SELECT COUNT(*) FROM requirement_submissions rs
              WHERE rs.requirement_id = ar.requirement_id) AS submitted_count,
            (SELECT COUNT(*) FROM requirement_submissions rs2
              WHERE rs2.requirement_id = ar.requirement_id AND rs2.status = 'pending') AS pending_count
       FROM activity_requirements ar
      WHERE ar.activity_id = ?
      ORDER BY ar.sort_order, ar.requirement_id",
    [$activityId]
);

// Editing one? Pre-fill the form.
$editId      = get_id('edit');
$editing     = null;
if ($editId !== null) {
    $editing = fetch_one(
        'SELECT * FROM activity_requirements WHERE requirement_id = ? AND activity_id = ?',
        [$editId, $activityId]
    );
}

$pageTitle = 'Requirements: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Requirements</h1>
        <p><?= e($activity['title']) ?> &middot; what students must submit before participating</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('requirements/verify.php?activity_id=' . $activityId) ?>">
            Verify submissions
        </a>
        <a class="btn btn-outline" href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">
            Participants
        </a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="split">
    <section class="card">
        <div class="card-head"><h2>Current requirements (<?= count($requirements) ?>)</h2></div>

        <?php if ($requirements === []): ?>
            <div class="empty">
                <strong>No requirements yet</strong>
                Students can register without submitting anything. Add one with the form on this page.
            </div>
        <?php else: ?>
            <div class="feed">
                <?php foreach ($requirements as $requirement): ?>
                    <article>
                        <div class="feed-row">
                            <div>
                                <h3>
                                    <?= e($requirement['name']) ?>
                                    <?php if (!$requirement['is_mandatory']): ?>
                                        <span class="badge badge-muted">Optional</span>
                                    <?php endif; ?>
                                    <?php if (!$requirement['needs_file']): ?>
                                        <span class="badge badge-info">No file</span>
                                    <?php endif; ?>
                                </h3>
                                <p class="meta">
                                    Deadline: <?= $requirement['deadline_at'] ? e(format_datetime($requirement['deadline_at'])) : 'None' ?>
                                    &middot; <?= (int) $requirement['submitted_count'] ?> submitted
                                    <?php if ((int) $requirement['pending_count'] > 0): ?>
                                        &middot; <span class="text-warning"><?= (int) $requirement['pending_count'] ?> to verify</span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($requirement['description']): ?>
                                    <p class="mb-0"><?= e($requirement['description']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="btn-row">
                                <a class="btn btn-outline btn-sm"
                                   href="<?= url('requirements/manage.php?activity_id=' . $activityId . '&edit=' . (int) $requirement['requirement_id']) ?>"
                                   aria-label="Edit <?= e($requirement['name']) ?>">
                                    Edit
                                </a>
                                <form method="post" class="inline-form"
                                      onsubmit="return confirm('Delete this requirement? Any files students already submitted for it will be permanently deleted.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="requirement_id" value="<?= (int) $requirement['requirement_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            aria-label="Delete <?= e($requirement['name']) ?>">Delete</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2><?= $editing ? 'Edit requirement' : 'Add a requirement' ?></h2>
        </div>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="activity_id" value="<?= $activityId ?>">
            <input type="hidden" name="requirement_id" value="<?= $editing ? (int) $editing['requirement_id'] : 0 ?>">

            <div class="form-row">
                <label for="name">Requirement name <span class="req">*</span></label>
                <input type="text" id="name" name="name" required
                       placeholder="e.g. Medical Clearance"
                       value="<?= e(old('name', (string) ($editing['name'] ?? ''))) ?>">
            </div>

            <div class="form-row">
                <label for="description">Instructions for the student <span class="optional">(optional)</span></label>
                <textarea id="description" name="description"
                          placeholder="Where to get it, what it must show"><?= e(old('description', (string) ($editing['description'] ?? ''))) ?></textarea>
            </div>

            <div class="form-row">
                <label for="deadline_at">Deadline <span class="optional">(optional)</span></label>
                <input type="datetime-local" id="deadline_at" name="deadline_at"
                       value="<?= e(old('deadline_at', $editing && $editing['deadline_at']
                            ? date('Y-m-d\TH:i', strtotime((string) $editing['deadline_at'])) : '')) ?>">
                <p class="hint">Leave blank if there is no fixed deadline.</p>
            </div>

            <div class="form-row">
                <label class="check">
                    <input type="checkbox" name="is_mandatory" value="1"
                        <?= !$editing || (int) $editing['is_mandatory'] === 1 ? 'checked' : '' ?>>
                    <span>Mandatory: the student is not complete without it</span>
                </label>
            </div>

            <div class="form-row">
                <label class="check">
                    <input type="checkbox" name="needs_file" value="1"
                        <?= !$editing || (int) $editing['needs_file'] === 1 ? 'checked' : '' ?>>
                    <span>Requires a file upload (otherwise the student just acknowledges it)</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editing ? 'Save changes' : 'Add requirement' ?>
                </button>
                <?php if ($editing): ?>
                    <a class="btn btn-outline" href="<?= url('requirements/manage.php?activity_id=' . $activityId) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
