<?php
/**
 * CASDevU — The review queue: everything waiting on an office decision.
 *
 * Read-only views over existing tables. The same scoping rules as the review
 * screens themselves apply: office staff see every activity, a coordinator
 * sees only the activities they are assigned to, and reservation requests
 * account approvals and invitations from outside groups are office-only. The sidebar badge, the dashboard,
 * and review.php all read from here, so their numbers always agree.
 */

declare(strict_types=1);

/** Can the signed-in user act on anything in the queue at all? */
function can_review(): bool
{
    return has_role('staff', 'admin', 'coordinator');
}

/**
 * SQL fragment restricting an activity alias to what the user may review.
 *
 * @return array{0: string, 1: array<int, mixed>}
 */
function review_activity_scope(string $alias = 'a'): array
{
    if (is_office_staff()) {
        return ['', []];
    }
    return [
        " AND $alias.activity_id IN (SELECT ac.activity_id FROM activity_coordinators ac WHERE ac.user_id = ?)",
        [current_user_id()],
    ];
}

/** Office sees every pending reservation; a coordinator only student requests for their activities. */
function review_reservation_scope(): array
{
    if (is_office_staff()) {
        return ['', []];
    }
    [$scope, $params] = review_activity_scope('rv');
    return [' AND rv.student_request = 1' . $scope, $params];
}

/**
 * Who decides on one activity: the coordinators assigned to it, or the office
 * when it has none. Used to tell reviewers that a registration or a document
 * has arrived.
 *
 * @return array<int, int>
 */
function activity_reviewer_ids(int $activityId): array
{
    $reviewers = fetch_all(
        "SELECT ac.user_id FROM activity_coordinators ac
           JOIN users u ON u.user_id = ac.user_id
          WHERE ac.activity_id = ? AND u.status = 'active'",
        [$activityId]
    );

    if ($reviewers === []) {
        return office_reviewer_ids();
    }

    return array_map(static fn(array $row): int => (int) $row['user_id'], $reviewers);
}

/** @return int[] Active office reviewers for requests without an activity. */
function office_reviewer_ids(): array
{
    return array_map('intval', array_column(fetch_all(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.name IN ('staff','admin') AND u.status = 'active'"
    ), 'user_id'));
}

/**
 * How many items wait in each lane, for the current user.
 *
 * @return array{documents: int, registrations: int, reservations: int, accounts: int, invitations: int, total: int}
 */
function review_counts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $counts = ['documents' => 0, 'registrations' => 0, 'reservations' => 0, 'accounts' => 0, 'invitations' => 0, 'activities' => 0, 'announcements' => 0, 'ids' => 0, 'total' => 0];
    if (!can_review()) {
        return $cache = $counts;
    }

    [$scope, $params] = review_activity_scope();

    $counts['documents'] = (int) fetch_value(
        "SELECT COUNT(*) FROM requirement_submissions rs
           JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
           JOIN activities a            ON a.activity_id     = ar.activity_id
          WHERE rs.status = 'pending' AND a.status NOT IN ('cancelled', 'completed')$scope",
        $params
    );
    $counts['registrations'] = (int) fetch_value(
        "SELECT COUNT(*) FROM registrations r
           JOIN activities a ON a.activity_id = r.activity_id
          WHERE r.status = 'pending' AND a.status NOT IN ('cancelled', 'completed')$scope",
        $params
    );
    [$rvScope, $rvParams] = review_reservation_scope();
    $counts['reservations'] = (int) fetch_value(
        "SELECT COUNT(*) FROM reservations rv WHERE rv.status = 'pending'$rvScope",
        $rvParams
    );
    if (is_office_staff()) {
        $counts['accounts']     = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        // Pending decisions, plus decided ones whose sender still has to be told by hand.
        $counts['invitations']  = (int) fetch_value(
            "SELECT COUNT(*) FROM invitations WHERE (status = 'pending' OR email_status IN ('logged','failed'))"
            . (has_role('admin') ? '' : ' AND submitted_by IS NULL')
        );
    }
    if (has_role('admin')) {
        $counts['ids'] = (int) fetch_value("SELECT COUNT(*) FROM users WHERE id_review_status = 'pending'");
        $counts['activities'] = (int) fetch_value("SELECT COUNT(*) FROM activities WHERE approval_status = 'pending'");
        $counts['announcements'] = (int) fetch_value("SELECT COUNT(*) FROM announcements WHERE status = 'pending'");
    }
    $counts['total'] = array_sum(array_diff_key($counts, ['total' => true]));

    return $cache = $counts;
}

/** Documents uploaded and waiting for verification, oldest first. */
function review_pending_documents(int $limit = 50): array
{
    [$scope, $params] = review_activity_scope();
    return fetch_all(
        "SELECT rs.submission_id, rs.submitted_at, rs.original_name,
                ar.name AS requirement_name, ar.deadline_at,
                a.activity_id, a.title AS activity_title,
                u.first_name, u.last_name, u.student_number
           FROM requirement_submissions rs
           JOIN activity_requirements ar ON ar.requirement_id  = rs.requirement_id
           JOIN activities a            ON a.activity_id      = ar.activity_id
           JOIN registrations r         ON r.registration_id  = rs.registration_id
           JOIN users u                 ON u.user_id          = r.user_id
          WHERE rs.status = 'pending' AND a.status NOT IN ('cancelled', 'completed')$scope
          ORDER BY rs.submitted_at ASC
          LIMIT " . max(1, $limit),
        $params
    );
}

/** Registrations waiting for approval, oldest first. */
function review_pending_registrations(int $limit = 50): array
{
    [$scope, $params] = review_activity_scope();
    return fetch_all(
        "SELECT r.registration_id, r.registered_at, r.team_name,
                a.activity_id, a.title AS activity_title, a.start_at,
                u.first_name, u.last_name, u.student_number
           FROM registrations r
           JOIN activities a ON a.activity_id = r.activity_id
           JOIN users u      ON u.user_id     = r.user_id
          WHERE r.status = 'pending' AND a.status NOT IN ('cancelled', 'completed')$scope
          ORDER BY r.registered_at ASC
          LIMIT " . max(1, $limit),
        $params
    );
}

/** Reservation requests waiting for a decision, oldest first (see review_reservation_scope). */
function review_pending_reservations(int $limit = 50): array
{
    if (!can_review()) {
        return [];
    }
    [$scope, $params] = review_reservation_scope();
    return fetch_all(
        "SELECT rv.reservation_id, rv.created_at, rv.needed_from, rv.needed_until, rv.purpose,
                rv.student_request, rv.activity_id,
                a.title AS activity_title,
                u.first_name, u.last_name
           FROM reservations rv
           LEFT JOIN activities a ON a.activity_id = rv.activity_id
           JOIN users u           ON u.user_id     = rv.requested_by
          WHERE rv.status = 'pending'$scope
          ORDER BY rv.needed_from ASC
          LIMIT " . max(1, $limit),
        $params
    );
}

/** Accounts waiting for activation, oldest first. Office only. */
function review_pending_accounts(int $limit = 50): array
{
    if (!is_office_staff()) {
        return [];
    }
    return fetch_all(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.student_number, u.created_at
           FROM users u
          WHERE u.status = 'pending'
          ORDER BY u.created_at ASC
          LIMIT " . max(1, $limit)
    );
}

/** Invitations from outside groups waiting for a decision, oldest first. Office only. */
function review_pending_invitations(int $limit = 50): array
{
    if (!is_office_staff()) {
        return [];
    }
    return fetch_all(
        "SELECT invitation_id, organization, contact_name, title, start_at, end_at, place, created_at,
                letter_path IS NOT NULL AS has_letter
           FROM invitations
          WHERE status = 'pending'" . (has_role('admin') ? '' : ' AND submitted_by IS NULL') . "
          ORDER BY created_at ASC
          LIMIT " . max(1, $limit)
    );
}

/** Decided invitations whose sender was not emailed, oldest decision first. Office only. */
function review_unsent_invitations(int $limit = 50): array
{
    if (!is_office_staff()) {
        return [];
    }
    return fetch_all(
        "SELECT invitation_id, organization, contact_name, title, status, email, phone, reviewed_at, email_status
           FROM invitations
          WHERE email_status IN ('logged','failed')" . (has_role('admin') ? '' : ' AND submitted_by IS NULL') . "
          ORDER BY reviewed_at ASC
          LIMIT " . max(1, $limit)
    );
}
