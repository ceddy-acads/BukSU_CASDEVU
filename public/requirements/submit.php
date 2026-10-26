<?php
/** Authorized compatibility route and replacement handler for activity requirements. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(['student']);

if (is_post()) {
    if (student_upload_body_too_large()) {
        abort_page(413, 'The files exceed the server upload limit. Go back and choose a smaller file.');
    }
    csrf_verify();
}
$activityId = get_id('activity_id') ?? (int) post('activity_id');
$activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) { abort_page(404, 'Activity not found.'); }
$registration = fetch_one('SELECT * FROM registrations WHERE activity_id = ? AND user_id = ?',
    [$activityId, current_user_id()]);
$backTo = 'activities/view.php?id=' . $activityId . '#my-requirements';
if ($registration === null || in_array($registration['status'], ['withdrawn', 'rejected'], true)) {
    flash('error', 'You need an active registration for this activity before submitting documents.');
    redirect('activities/view.php?id=' . $activityId);
}
if (!is_post()) { redirect($backTo); }

$stored = null;
$oldPath = null;
$errorMessage = null;
try {
    db()->beginTransaction();
    $activity = fetch_one('SELECT * FROM activities WHERE activity_id = ? FOR UPDATE', [$activityId]);
    $registration = fetch_one('SELECT * FROM registrations WHERE activity_id = ? AND user_id = ? FOR UPDATE',
        [$activityId, current_user_id()]);
    if ($activity === null || !in_array($activity['status'], ['upcoming', 'ongoing'], true)
        || $registration === null || !in_array($registration['status'], ['pending', 'approved'], true)) {
        $errorMessage = 'This activity or registration no longer accepts documents.';
    }
    $item = null;
    foreach (student_requirement_items($activityId, (int) ($registration['registration_id'] ?? 0), true) as $candidate) {
        if ((int) $candidate['requirement_id'] === (int) post('requirement_id')) { $item = $candidate; break; }
    }
    if ($item === null) { $errorMessage = 'That requirement does not belong to this activity.'; }
    $note = post('note');
    if ($errorMessage === null) {
        $errorMessage = student_requirement_error($item, $_FILES['document'] ?? null, post('acknowledged') === '1', $note);
    }
    if ($errorMessage === null && (int) $item['needs_file'] === 1) {
        $stored = store_upload($_FILES['document'] ?? [], 'requirements');
        if (!$stored['ok']) { $errorMessage = $stored['error']; }
    }
    if ($errorMessage !== null) {
        db()->rollBack();
    } else {
        $submissionId = save_student_requirement((int) $registration['registration_id'], $item, $stored, $note);
        $oldPath = $item['file_path'];
        db()->commit();
    }
} catch (Throwable $error) {
    if (db()->inTransaction()) { db()->rollBack(); }
    $errorMessage = 'Your document could not be saved. Please try again.';
    error_log('[CASMS] Requirement submission failed: ' . $error->getMessage());
}
if ($errorMessage !== null) {
    if (isset($stored['path'])) { delete_upload($stored['path']); }
    flash('error', $errorMessage);
    redirect($backTo);
}
delete_upload($oldPath);
audit_log('create', 'requirement_submission', $submissionId, 'Submitted "' . $item['name'] . '" for ' . $activity['title']);
try {
    notify_many_and_email(activity_reviewer_ids($activityId), 'requirement', 'Document waiting for review',
        full_name(current_user()) . ' submitted "' . $item['name'] . '" for ' . $activity['title'] . '.',
        url('requirements/verify.php?activity_id=' . $activityId));
} catch (Throwable $error) {
    error_log('[CASMS] Requirement notification failed: ' . $error->getMessage());
}
flash('success', '"' . $item['name'] . '" submitted. The office will review it shortly.');
redirect($backTo);
