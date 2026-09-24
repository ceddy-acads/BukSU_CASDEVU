<?php
/**
 * CASDevU — The review queue: everything waiting on an office decision.
 *
 * Read-only views over existing tables. The same scoping rules as the review
 * screens themselves apply: office staff see every activity, a coordinator
 * sees only the activities they are assigned to, and reservation requests
 * and account approvals are office-only. The sidebar badge, the dashboard,
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

/**
 * How many items wait in each lane, for the current user.
 *
 * @return array{documents: int, registrations: int, reservations: int, accounts: int, total: int}
 */
function review_counts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $counts = ['documents' => 0, 'registrations' => 0, 'reservations' => 0, 'accounts' => 0, 'total' => 0];
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
    if (is_office_staff()) {
        $counts['reservations'] = (int) fetch_value("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
        $counts['accounts']     = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
    }
    $counts['total'] = $counts['documents'] + $counts['registrations'] + $counts['reservations'] + $counts['accounts'];

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

/** Reservation requests waiting for the office, oldest first. Office only. */
function review_pending_reservations(int $limit = 50): array
{
    if (!is_office_staff()) {
        return [];
    }
    return fetch_all(
        "SELECT rv.reservation_id, rv.created_at, rv.needed_from, rv.needed_until, rv.purpose,
                a.title AS activity_title,
                u.first_name, u.last_name
           FROM reservations rv
           LEFT JOIN activities a ON a.activity_id = rv.activity_id
           JOIN users u           ON u.user_id     = rv.requested_by
          WHERE rv.status = 'pending'
          ORDER BY rv.needed_from ASC
          LIMIT " . max(1, $limit)
    );
}

/** Student accounts waiting for activation, oldest first. Office only. */
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
