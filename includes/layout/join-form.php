<?php
/** Shared by the activity dialog and its standalone fallback. */
$joinErrors = $joinErrors ?? [];
$joinValues = $joinValues ?? [];
$previousJoin = fetch_one('SELECT registration_id FROM registrations WHERE activity_id = ? AND user_id = ?',
    [$activityId, current_user_id()]);
$joinItems = student_requirement_items($activityId, (int) ($previousJoin['registration_id'] ?? 0));
$joinUploadMb = (int) (max_upload_bytes() / 1024 / 1024);
$joinBodyLimit = php_ini_bytes((string) ini_get('post_max_size'));
$joinFileLimit = (int) ini_get('max_file_uploads');
?>
<form method="post" action="<?= e(url('participation/register.php?activity_id=' . $activityId)) ?>"
      enctype="multipart/form-data" data-join-form data-file-limit="<?= $joinFileLimit ?>" data-body-limit="<?= $joinBodyLimit ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
    <input type="hidden" name="action" value="register">
    <input type="hidden" name="file_count" value="0">
    <p>Submit your requirements for <strong><?= e($activity['title']) ?></strong>. The office will review your registration and documents.</p>
    <div class="alert alert-error" data-join-errors role="alert" tabindex="-1"<?= $joinErrors === [] ? ' hidden' : '' ?>>
        <?php foreach ($joinErrors as $joinError): ?><p><?= e($joinError) ?></p><?php endforeach; ?>
    </div>
    <?php if ($joinErrors !== []): ?><p class="hint">Please select your files again before submitting.</p><?php endif; ?>
    <?php if ($joinItems === []): ?>
        <p class="alert alert-info">No documents are required for this activity.</p>
    <?php else: ?>
        <p class="hint">Submit every required item. Optional items can be added later. PDF, JPG, or PNG, up to <?= $joinUploadMb ?> MB per file.
            <?php if ($joinBodyLimit > 0): ?>Up to <?= e(format_filesize($joinBodyLimit)) ?> in one submission.<?php endif; ?>
            Up to <?= $joinFileLimit ?> files per submission.
        </p>
        <?php foreach ($joinItems as $joinItem): ?>
            <?php $joinReqId = (int) $joinItem['requirement_id']; $joinVerified = $joinItem['submission_status'] === 'verified'; ?>
            <fieldset class="join-requirement">
                <legend><?= e($joinItem['name']) ?> <span class="badge badge-muted"><?= $joinItem['is_mandatory'] ? 'Required' : 'Optional' ?></span></legend>
                <?php if ($joinItem['description']): ?><p><?= e($joinItem['description']) ?></p><?php endif; ?>
                <p class="hint">Deadline: <?= $joinItem['deadline_at'] ? e(format_datetime($joinItem['deadline_at'])) : 'None' ?></p>
                <?php if ($joinVerified): ?>
                    <p><?= status_badge('verified', 'submission') ?> Your verified submission will be retained.</p>
                <?php else: ?>
                    <?php if ((int) $joinItem['needs_file'] === 1): ?>
                        <div class="form-row">
                            <label for="join-document-<?= $joinReqId ?>">Upload document</label>
                            <input type="file" id="join-document-<?= $joinReqId ?>" name="documents[<?= $joinReqId ?>]"
                                   accept=".pdf,.jpg,.jpeg,.png"<?= $joinItem['is_mandatory'] ? ' required' : '' ?>
                                   data-max-bytes="<?= max_upload_bytes() ?>" aria-describedby="join-error-<?= $joinReqId ?>">
                        </div>
                    <?php else: ?>
                        <div class="form-row">
                            <label><input type="checkbox" id="join-ack-<?= $joinReqId ?>" name="acknowledgements[<?= $joinReqId ?>]" value="1"
                                <?= !empty($joinValues['acknowledgements'][$joinReqId]) ? ' checked' : '' ?><?= $joinItem['is_mandatory'] ? ' required' : '' ?>
                                aria-describedby="join-error-<?= $joinReqId ?>"> I have read and acknowledge this requirement.</label>
                        </div>
                    <?php endif; ?>
                    <div class="form-row">
                        <label for="join-note-<?= $joinReqId ?>">Note <span class="optional">(optional)</span></label>
                        <input type="text" id="join-note-<?= $joinReqId ?>" name="notes[<?= $joinReqId ?>]" maxlength="500"
                               value="<?= e($joinValues['notes'][$joinReqId] ?? '') ?>">
                    </div>
                    <p class="hint text-danger" id="join-error-<?= $joinReqId ?>" data-join-error="<?= $joinReqId ?>" hidden></p>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="form-row">
        <label for="join-team">Team or group name <span class="optional">(optional)</span></label>
        <input type="text" id="join-team" name="team_name" maxlength="120" placeholder="e.g. College of Technologies" value="<?= e($joinValues['team_name'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="join-remarks">Anything the office should know <span class="optional">(optional)</span></label>
        <input type="text" id="join-remarks" name="remarks" maxlength="500" value="<?= e($joinValues['remarks'] ?? '') ?>">
    </div>
    <p class="hint">Nothing is saved until you submit. Reloading or leaving this page clears your unfinished form.</p>
    <button type="submit" class="btn btn-primary btn-block" data-loading="Submitting…">Submit registration</button>
</form>
