<?php
/** Student-owned checklist, embedded within activity details. */
$registration = $myRegistration;
$registrationId = (int) $registration['registration_id'];
$items = student_requirement_items($activityId, $registrationId);
$progress = requirement_progress($registrationId);
$maxUploadMb = (int) (max_upload_bytes() / 1024 / 1024);
$acceptsSubmissions = in_array($activity['status'], ['upcoming', 'ongoing'], true)
    && in_array($registration['status'], ['pending', 'approved'], true);
$closedReason = !$acceptsSubmissions ? 'This activity or registration no longer accepts documents.' : '';
$turns = ['student' => 0, 'office' => 0, 'none' => 0];
foreach ($items as $countItem) {
    if ($countItem['is_mandatory']) {
        $owner = requirement_state($countItem['submission_status'], $countItem['deadline_at'], (int) $countItem['needs_file'] === 1)['owner'];
        $turns[$owner]++;
    }
}
?>
<div id="my-requirements" tabindex="-1">
<h2>My requirements</h2>
<?php if ($items === []): ?>
    <div class="empty">
        <strong>Nothing to submit</strong>
        This activity does not require any documents.
        <a class="btn btn-outline" href="<?= url('activities/view.php?id=' . $activityId) ?>">Back to the activity</a>
    </div>
<?php else: ?>
    <?php if ($progress['complete']): ?>
        <p class="hint mt-0 mb-4">
            All required documents have been verified. Nothing further is needed from you.
        </p>
    <?php else: ?>
        <?php // What needs the student also opens in the notification pop-up: includes/attention.php. ?>
        <p class="hint mt-0 mb-4">
            <?php if ($turns['student'] > 0): ?>
                <strong><?= $turns['student'] ?></strong> required document<?= $turns['student'] === 1 ? ' needs' : 's need' ?> you.
            <?php endif; ?>
            <?php if ($turns['office'] > 0): ?>
                <strong><?= $turns['office'] ?></strong> <?= $turns['office'] === 1 ? 'is' : 'are' ?> with the office for review.
            <?php endif; ?>
            <strong><?= $turns['none'] ?></strong> of <?= $progress['total'] ?> verified.
        </p>
    <?php endif; ?>

    <?php foreach ($items as $item): ?>
        <?php
        $submissionStatus = $item['submission_status'];           // null when nothing submitted
        $isSettled        = $submissionStatus === 'verified';
        $reqId            = (int) $item['requirement_id'];
        $state            = requirement_state($submissionStatus, $item['deadline_at'], (int) $item['needs_file'] === 1);
        ?>
        <section class="card" id="req-<?= $reqId ?>">
            <div class="item">
                <div class="item-main">
                    <?= status_badge($state['key'], 'submission') ?>
                    <h2 class="item-title">
                        <?= e($item['name']) ?>
                        <?php if (!$item['is_mandatory']): ?>
                            <span class="badge badge-muted">Optional</span>
                        <?php endif; ?>
                    </h2>

                    <?php if ($item['description']): ?>
                        <p><?= e($item['description']) ?></p>
                    <?php endif; ?>

                    <p class="meta">
                        Deadline: <?= $item['deadline_at'] ? e(format_datetime($item['deadline_at'])) : 'None' ?>
                        <?php if ($submissionStatus !== null): ?>
                            &middot; Submitted <?= e(format_datetime($item['submitted_at'])) ?>
                        <?php endif; ?>
                    </p>

                    <?= requirement_steps_html($state['key']) ?>
                    <?= requirement_next_html($state) ?>

                    <?php if ($submissionStatus !== null): ?>

                        <?php if ($item['file_path']): ?>
                            <p class="small mt-2">
                                <a href="<?= url('requirements/download.php?submission_id=' . (int) $item['submission_id']) ?>">
                                    <?= e($item['original_name']) ?>
                                </a>
                                <span class="hint">(<?= e(format_filesize($item['file_size'] === null ? null : (int) $item['file_size'])) ?>)</span>
                            </p>
                        <?php endif; ?>

                        <?php if ($submissionStatus === 'rejected' && $item['reject_reason']): ?>
                            <div class="alert alert-error">
                                <strong>Reason from the office:</strong> <?= e($item['reject_reason']) ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="item-aside">
                    <?php if ($isSettled): ?>
                        <p class="muted small">A verified document is locked and cannot be replaced.</p>
                    <?php elseif (!$acceptsSubmissions): ?>
                        <div class="alert alert-warning alert-flush">
                            <?= e($closedReason) ?>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('requirements/submit.php')) ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                            <input type="hidden" name="requirement_id" value="<?= $reqId ?>">

                            <?php if ((int) $item['needs_file'] === 1): ?>
                                <div class="form-row">
                                    <label for="document-<?= $reqId ?>">
                                        <?= $submissionStatus === null ? 'Upload document' : 'Replace document' ?>
                                    </label>
                                    <input type="file" id="document-<?= $reqId ?>"
                                           name="document" required accept=".pdf,.jpg,.jpeg,.png"
                                           aria-describedby="document-hint-<?= $reqId ?>">
                                    <div class="hint" id="document-hint-<?= $reqId ?>">PDF, JPG, or PNG, up to <?= $maxUploadMb ?> MB.</div>
                                </div>
                            <?php else: ?>
                                <div class="form-row"><label><input type="checkbox" name="acknowledged" value="1" required> I have read and acknowledge this requirement.</label></div>
                            <?php endif; ?>

                            <div class="form-row">
                                <label for="note-<?= $reqId ?>">Note <span class="optional">(optional)</span></label>
                                <input type="text" id="note-<?= $reqId ?>" name="note" maxlength="500"
                                       value="<?= e($item['note'] ?? '') ?>" placeholder="Anything the office should know">
                            </div>

                            <?php // While the office is reviewing, replacing is optional, so the button stays quiet. ?>
                            <button type="submit" class="btn <?= $state['owner'] === 'student' ? 'btn-primary' : 'btn-outline' ?> btn-block">
                                <?= $submissionStatus === null
                                    ? ((int) $item['needs_file'] === 1 ? 'Submit document' : 'Acknowledge')
                                    : ($state['owner'] === 'student' ? 'Submit again'
                                        : ((int) $item['needs_file'] === 1 ? 'Replace file' : 'Confirm again')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>


</div>
