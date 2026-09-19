<?php
/**
 * CASMS — Sessions, authentication, and authorization (FR-1.1, FR-1.4, NFR-4)
 */

declare(strict_types=1);

// =====================================================================
// Session bootstrap
// =====================================================================

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                                   // not readable by JavaScript
        'samesite' => 'Lax',                                  // blocks cross-site form posts
        'secure'   => !empty($_SERVER['HTTPS']),              // HTTPS-only once deployed
    ]);
    session_start();

    enforce_idle_timeout();
}

/** Log the user out after a period of inactivity. */
function enforce_idle_timeout(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $lastSeen = $_SESSION['last_activity'] ?? time();
    if (time() - $lastSeen > SESSION_IDLE_TIMEOUT) {
        logout_user();
        flash('info', 'You were signed out after a period of inactivity.');
        redirect('login.php');
    }

    $_SESSION['last_activity'] = time();
}

// =====================================================================
// Current user
// =====================================================================

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * The signed-in user's full record, loaded once per request.
 *
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    static $user = null;

    if (!is_logged_in()) {
        return null;
    }
    if ($user !== null) {
        return $user;
    }

    $user = fetch_one(
        'SELECT u.*, r.name AS role_name, c.code AS course_code, c.name AS course_name,
                y.label AS year_level_label
           FROM users u
           JOIN roles r        ON r.role_id = u.role_id
           LEFT JOIN courses c ON c.course_id = u.course_id
           LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
          WHERE u.user_id = ?',
        [$_SESSION['user_id']]
    );

    // The account was deleted or deactivated while the session was still open.
    if ($user === null || $user['status'] !== 'active') {
        logout_user();
        return null;
    }

    return $user;
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function has_role(string ...$roles): bool
{
    return in_array(current_role(), $roles, true);
}

/** Staff and admin have unrestricted access to office functions. */
function is_office_staff(): bool
{
    return has_role('staff', 'admin');
}

// =====================================================================
// Login / logout
// =====================================================================

/**
 * Verify credentials and open a session.
 *
 * @return array{ok: bool, error?: string}
 */
function login_user(string $email, string $password): array
{
    $user = fetch_one(
        'SELECT u.user_id, u.email, u.password_hash, u.status, r.name AS role_name
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
          WHERE u.email = ?',
        [$email]
    );

    // Same message whether the email is unknown or the password is wrong —
    // a distinct message would let an attacker enumerate valid accounts.
    if ($user === null || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Incorrect email or password.'];
    }

    $statusMessage = [
        'pending'   => 'Your account is awaiting approval by the office. Please try again later.',
        'inactive'  => 'This account has been deactivated. Please contact the office.',
        'suspended' => 'This account is suspended. Please contact the office.',
    ];
    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => $statusMessage[$user['status']] ?? 'This account is not active.'];
    }

    // Re-key the session on privilege change — prevents session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id']       = (int) $user['user_id'];
    $_SESSION['role']          = $user['role_name'];
    $_SESSION['last_activity'] = time();

    query('UPDATE users SET last_login_at = NOW() WHERE user_id = ?', [$user['user_id']]);
    audit_log('login', 'user', (int) $user['user_id'], 'Signed in');

    return ['ok' => true];
}

function logout_user(): void
{
    $userId = current_user_id();
    if ($userId !== null) {
        audit_log('logout', 'user', $userId, 'Signed out');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
                  $params['secure'], $params['httponly']);
    }

    session_destroy();
}

// =====================================================================
// Authorization gates — see docs/03-role-matrix.md
// =====================================================================

/** Require any signed-in user. */
function require_login(): void
{
    if (!is_logged_in() || current_user() === null) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/**
 * Require one of the given roles. Call before any output is sent.
 *
 * @param array<int, string> $allowed
 */
function require_role(array $allowed): void
{
    require_login();

    if (!in_array(current_role(), $allowed, true)) {
        http_response_code(403);
        exit('403 — You do not have permission to access this page.');
    }
}

/**
 * For the "limited" rows of the permission matrix: staff and admin pass
 * unrestricted; a coordinator only passes for activities assigned to them.
 */
function require_activity_access(int $activityId): void
{
    require_login();

    if (is_office_staff()) {
        return;
    }

    if (current_role() === 'coordinator') {
        $assigned = fetch_value(
            'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
            [$activityId, current_user_id()]
        );
        if ($assigned) {
            return;
        }
    }

    http_response_code(403);
    exit('403 — You are not assigned to this activity.');
}

/** Non-fatal variant, for deciding whether to render an "Edit" button. */
function can_manage_activity(int $activityId): bool
{
    if (is_office_staff()) {
        return true;
    }
    if (current_role() !== 'coordinator') {
        return false;
    }
    return (bool) fetch_value(
        'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
        [$activityId, current_user_id()]
    );
}

// =====================================================================
// Audit trail (FR-9.3)
// =====================================================================

/**
 * Record a significant action. Never allowed to break the request it logs.
 *
 * @param array<string, mixed>|null $oldValues
 * @param array<string, mixed>|null $newValues
 */
function audit_log(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $description = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
        query(
            'INSERT INTO audit_logs
                 (user_id, action, entity_type, entity_id, description,
                  old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                current_user_id(),
                $action,
                $entityType,
                $entityId,
                $description,
                $oldValues !== null ? json_encode($oldValues) : null,
                $newValues !== null ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('[CASMS] Audit log failed: ' . $e->getMessage());
    }
}
