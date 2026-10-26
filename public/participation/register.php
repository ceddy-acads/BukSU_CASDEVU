<?php
/**
 * CASMS — Register for / withdraw from an activity (FR-4.1, FR-4.2, FR-4.5)
 *
 * POST-only handler. All decisions run through can_register() so the rule
 * lives in exactly one place.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['student']);

if (!is_post()) {
    redirect('activities/index.php');
}

// Oversized multipart requests have no POST token because PHP discards the body.
// Reject before any mutation and explain how to recover.
$isJoinFetch = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
if (student_upload_body_too_large()) {
    if ($isJoinFetch) {
        http_response_code(413);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'errors' => ['form' => 'The combined files exceed the server upload limit. Choose smaller files and try again.']]);
    } else {
        abort_page(413, 'The combined files exceed the server upload limit. Go back and choose smaller files.');
    }
    return;
}
csrf_verify();

$student    = current_user();
$activityId = (int) post('activity_id');
$action     = post('action', 'register');

$activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) {
    if ($isJoinFetch) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'errors' => ['form' => 'This activity no longer exists. Return to Activities to choose another one.']]);
        return;
    }
    flash('error', 'That activity no longer exists.');
    redirect('activities/index.php');
}

$backTo = 'activities/view.php?id=' . $activityId;

// =====================================================================
// Withdraw
// =====================================================================
if ($action === 'withdraw') {
    $registration = fetch_one(
        'SELECT * FROM registrations WHERE activity_id = ? AND user_id = ?',
        [$activityId, $student['user_id']]
    );

    if ($registration === null) {
        flash('error', 'You are not registered for this activity.');
        redirect($backTo);
    }

    // Once the office has approved a place, withdrawing is their call —
    // otherwise a student could quietly vacate a confirmed slot.
    if ($registration['status'] !== 'pending') {
        flash('error', 'Only a registration still awaiting review can be withdrawn. Please contact the office' . office_contact_hint() . '.');
        redirect($backTo);
    }

    withdraw_registration((int) $registration['registration_id']);

    flash('success', 'You have withdrawn your registration.');
    redirect($backTo);
}

// =====================================================================
// Register
// =====================================================================
$joinValues = student_join_values();
$result = register_student_with_requirements($activityId, $student, $joinValues);
if (!$result['ok']) {
    if ($isJoinFetch) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result);
    } else {
        $joinErrors = $result['errors'];
        require __DIR__ . '/../activities/join.php';
    }
    return;
}

// The application is committed before any reviewer notification is attempted.
try {
    notify_many_and_email(
        activity_reviewer_ids($activityId), 'registration', 'New registration to review',
        full_name($student) . ' registered for ' . $activity['title'] . '.',
        url('participation/participants.php?activity_id=' . $activityId . '&status=pending')
    );
} catch (Throwable $error) {
    error_log('[CASMS] Registration notification failed: ' . $error->getMessage());
}
flash('success', 'Registration submitted. Waiting for approval.'
    . ($result['submitted'] > 0 ? ' Your documents are also awaiting review.' : ''));
if ($isJoinFetch) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'redirect' => url($backTo . '#my-requirements')]);
    return;
}
redirect($backTo . '#my-requirements');
