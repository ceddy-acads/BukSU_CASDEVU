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

/**
 * Where one requirement stands for a student, and whose move it is.
 *
 * The workflow is Required -> Submitted -> Under review -> Verified or
 * Rejected. What a student most needs to know is whether the next step is
 * theirs ('student') or the office's ('office'), so every state names an
 * owner and a next action. Late uploads are still accepted, so "past due"
 * stays the student's move.
 *
 * @return array{key: string, owner: string, next: string}
 */
function requirement_state(?string $submissionStatus, ?string $deadlineAt, bool $needsFile): array
{
    $pastDue = $deadlineAt !== null && $deadlineAt !== '' && strtotime($deadlineAt) < time();

    return match ($submissionStatus) {
        'pending'  => ['key' => 'pending',  'owner' => 'office',
                       'next' => 'The office is reviewing it. Nothing to do for now.'],
        'verified' => ['key' => 'verified', 'owner' => 'none',
                       'next' => 'Verified by the office. Nothing more to do.'],
        'rejected' => ['key' => 'rejected', 'owner' => 'student',
                       'next' => 'Fix it and ' . ($needsFile ? 'upload it again.' : 'confirm again.')],
        default    => ['key' => $pastDue ? 'past_due' : 'missing', 'owner' => 'student',
                       'next' => ($needsFile ? 'Upload your file' : 'Confirm you have read it')
                                 . ($pastDue ? ' now. The deadline has passed.' : '.')],
    };
}

/** The four-step workflow line for a requirement state. */
function requirement_steps_html(string $stateKey): string
{
    $decided = in_array($stateKey, ['verified', 'rejected'], true);
    $steps = [
        ['Required',     in_array($stateKey, ['missing', 'past_due'], true) ? 'current' : 'done'],
        ['Submitted',    in_array($stateKey, ['missing', 'past_due'], true) ? 'todo' : 'done'],
        ['Under review', $stateKey === 'pending' ? 'current' : ($decided ? 'done' : 'todo')],
        [$stateKey === 'rejected' ? 'Rejected' : 'Verified',
                         $stateKey === 'verified' ? 'done' : ($stateKey === 'rejected' ? 'bad' : 'todo')],
    ];
    $spoken = ['done' => 'done', 'current' => 'current step', 'todo' => 'not yet', 'bad' => 'needs attention'];

    $html = '<ol class="steps" aria-label="Progress">';
    foreach ($steps as [$label, $state]) {
        $html .= '<li class="step is-' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>'
               . '<span class="step-dot" aria-hidden="true"></span>'
               . '<span class="step-label">' . e($label) . '</span>'
               . '<span class="sr-only"> (' . $spoken[$state] . ')</span></li>';
    }
    return $html . '</ol>';
}

/** "Your turn" / "With the office" / "Done" line under a requirement. */
function requirement_next_html(array $state): string
{
    $lead = ['student' => 'Your turn:', 'office' => 'With the office:', 'none' => 'Done:'][$state['owner']] ?? '';
    return '<p class="next-action next-' . e($state['owner']) . '"><strong>' . $lead . '</strong> ' . e($state['next']) . '</p>';
}
