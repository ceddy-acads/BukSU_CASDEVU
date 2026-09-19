<?php
/**
 * CASMS — Participation rules (FR-4.2, FR-4.3, FR-4.4)
 *
 * The single place that decides whether a student may register. Both the
 * activity page (to render the button) and the register handler (to enforce
 * it) call can_register(), so what the student sees and what the server
 * allows can never disagree.
 */

declare(strict_types=1);

/**
 * Does this student satisfy the activity's year-level / course whitelist?
 *
 * No rules rows at all means the activity is open to everyone. Otherwise the
 * student must match at least one row, where a NULL column means "any".
 */
function meets_eligibility_rules(int $activityId, array $student): bool
{
    $rules = fetch_all(
        'SELECT year_level_id, course_id FROM activity_eligibility_rules WHERE activity_id = ?',
        [$activityId]
    );

    if ($rules === []) {
        return true;
    }

    foreach ($rules as $rule) {
        $yearOk   = $rule['year_level_id'] === null
                 || (int) $rule['year_level_id'] === (int) $student['year_level_id'];
        $courseOk = $rule['course_id'] === null
                 || (int) $rule['course_id'] === (int) $student['course_id'];

        if ($yearOk && $courseOk) {
            return true;
        }
    }

    return false;
}

/** Approved participants currently holding a slot. */
function approved_participant_count(int $activityId): int
{
    return (int) fetch_value(
        "SELECT COUNT(*) FROM registrations WHERE activity_id = ? AND status = 'approved'",
        [$activityId]
    );
}

/**
 * May this student register for this activity right now?
 *
 * @return array{ok: bool, reason: string}
 */
function can_register(array $activity, array $student): array
{
    $activityId = (int) $activity['activity_id'];

    if (($student['role_name'] ?? null) !== 'student') {
        return ['ok' => false, 'reason' => 'Only students can register for activities.'];
    }

    // Already in the list — the UNIQUE key would reject a second row anyway.
    $existing = fetch_one(
        'SELECT status FROM registrations WHERE activity_id = ? AND user_id = ?',
        [$activityId, $student['user_id']]
    );
    if ($existing !== null && $existing['status'] !== 'withdrawn') {
        return ['ok' => false, 'reason' => 'You have already registered for this activity.'];
    }

    if (!in_array($activity['status'], ['upcoming', 'ongoing'], true)) {
        return ['ok' => false, 'reason' => 'This activity is not open for registration.'];
    }

    $now = time();
    if ($activity['registration_opens_at'] && $now < strtotime((string) $activity['registration_opens_at'])) {
        return ['ok' => false, 'reason' => 'Registration opens on '
            . format_datetime($activity['registration_opens_at']) . '.'];
    }
    if ($activity['registration_closes_at'] && $now > strtotime((string) $activity['registration_closes_at'])) {
        return ['ok' => false, 'reason' => 'Registration closed on '
            . format_datetime($activity['registration_closes_at']) . '.'];
    }

    if ($activity['max_participants'] !== null
        && approved_participant_count($activityId) >= (int) $activity['max_participants']) {
        return ['ok' => false, 'reason' => 'This activity has reached its maximum number of participants.'];
    }

    if (!meets_eligibility_rules($activityId, $student)) {
        return ['ok' => false, 'reason' => 'Your year level or course does not meet the requirements for this activity.'];
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * Requirement progress for one registration.
 *
 * @return array{total: int, satisfied: int, missing: int, complete: bool}
 */
function requirement_progress(int $registrationId): array
{
    $row = fetch_one(
        "SELECT
             (SELECT COUNT(*) FROM activity_requirements ar
                JOIN registrations r ON r.activity_id = ar.activity_id
               WHERE r.registration_id = ? AND ar.is_mandatory = 1) AS total,
             (SELECT COUNT(*) FROM requirement_submissions rs
                JOIN activity_requirements ar2 ON ar2.requirement_id = rs.requirement_id
               WHERE rs.registration_id = ? AND ar2.is_mandatory = 1
                 AND rs.status = 'verified') AS satisfied",
        [$registrationId, $registrationId]
    );

    $total     = (int) ($row['total'] ?? 0);
    $satisfied = (int) ($row['satisfied'] ?? 0);

    return [
        'total'     => $total,
        'satisfied' => $satisfied,
        'missing'   => max(0, $total - $satisfied),
        'complete'  => $total === 0 || $satisfied >= $total,
    ];
}

/** Human label for a registration's place in the workflow. */
function registration_stage_label(string $status): string
{
    return [
        'pending'   => 'Waiting for the office to review your registration',
        'approved'  => 'You are confirmed for this activity',
        'rejected'  => 'Your registration was not approved',
        'withdrawn' => 'You withdrew from this activity',
        'completed' => 'You participated in this activity',
    ][$status] ?? '';
}
