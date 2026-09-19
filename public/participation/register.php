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

csrf_verify();

$student    = current_user();
$activityId = (int) post('activity_id');
$action     = post('action', 'register');

$activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) {
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
        flash('error', 'Only a registration still awaiting review can be withdrawn. Please contact the office.');
        redirect($backTo);
    }

    query(
        "UPDATE registrations SET status = 'withdrawn' WHERE registration_id = ?",
        [$registration['registration_id']]
    );
    audit_log('update', 'registration', (int) $registration['registration_id'],
              'Student withdrew from: ' . $activity['title']);

    flash('success', 'You have withdrawn your registration.');
    redirect($backTo);
}

// =====================================================================
// Register
// =====================================================================
$check = can_register($activity, $student);
if (!$check['ok']) {
    flash('error', $check['reason']);
    redirect($backTo);
}

$teamName = post('team_name');
$remarks  = post('remarks');

// A previous withdrawal is reactivated rather than duplicated — the UNIQUE
// key on (activity_id, user_id) means a second INSERT would fail anyway.
$previous = fetch_one(
    'SELECT registration_id FROM registrations WHERE activity_id = ? AND user_id = ?',
    [$activityId, $student['user_id']]
);

if ($previous !== null) {
    query(
        "UPDATE registrations
            SET status = 'pending', team_name = ?, remarks = ?,
                reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL,
                registered_at = NOW()
          WHERE registration_id = ?",
        [
            $teamName !== '' ? $teamName : null,
            $remarks  !== '' ? $remarks  : null,
            $previous['registration_id'],
        ]
    );
    $registrationId = (int) $previous['registration_id'];
} else {
    query(
        'INSERT INTO registrations (activity_id, user_id, team_name, remarks)
         VALUES (?, ?, ?, ?)',
        [
            $activityId,
            $student['user_id'],
            $teamName !== '' ? $teamName : null,
            $remarks  !== '' ? $remarks  : null,
        ]
    );
    $registrationId = (int) db()->lastInsertId();
}

audit_log('create', 'registration', $registrationId,
          'Registered for: ' . $activity['title']);

// Tell whoever will review it: the assigned coordinators, or the office.
$reviewers = fetch_all(
    "SELECT ac.user_id FROM activity_coordinators ac
       JOIN users u ON u.user_id = ac.user_id
      WHERE ac.activity_id = ? AND u.status = 'active'",
    [$activityId]
);
if ($reviewers === []) {
    $reviewers = fetch_all(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.name IN ('staff','admin') AND u.status = 'active'"
    );
}

notify_many(
    array_map(static fn(array $row): int => (int) $row['user_id'], $reviewers),
    'registration',
    'New registration to review',
    full_name($student) . ' registered for ' . $activity['title'] . '.',
    url('participation/participants.php?activity_id=' . $activityId . '&status=pending')
);

$requirementCount = (int) fetch_value(
    'SELECT COUNT(*) FROM activity_requirements WHERE activity_id = ?',
    [$activityId]
);

flash('success', $requirementCount > 0
    ? 'You are registered. Your registration is awaiting review — please submit the required documents in the meantime.'
    : 'You are registered. Your registration is awaiting review by the office.');

redirect($requirementCount > 0
    ? 'requirements/submit.php?activity_id=' . $activityId
    : $backTo);
