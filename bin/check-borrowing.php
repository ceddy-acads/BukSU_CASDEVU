<?php
/**
 * CASMS — Self-check for student equipment borrowing.
 *
 * Command line only:  C:\xampp\php\php.exe bin\check-borrowing.php
 *
 * Every check runs inside one transaction that is always rolled back, so the
 * database is left as it was. Prints PASS/FAIL per check; exits 1 on any FAIL.
 * Needs the demo data (andrea, the coordinator, activities 15/16/17).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 — This script runs from the command line only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$failures = 0;

function check(string $name, bool $ok, string $why = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : ': ' . $why) . PHP_EOL;
}

/**
 * Sign in as the user with this email, the way auth.php reads the session.
 * Limit: current_user() keeps a static cache that cannot be reset, so after
 * the first call to it the script keeps seeing that user. current_role(),
 * current_user_id() and has_role() follow act_as() correctly.
 *
 * @return array<string, mixed>
 */
function act_as(string $email): array
{
    $user = fetch_one(
        'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.email = ?',
        [$email]
    );
    if ($user === null) {
        throw new RuntimeException('No such user: ' . $email);
    }
    $_SESSION['user_id']       = (int) $user['user_id'];
    $_SESSION['role']          = $user['role_name'];
    $_SESSION['last_activity'] = time();
    return $user;
}

db()->beginTransaction();
try {
    $andrea      = act_as('andrea.santos@student.buksu.edu.ph');
    $coordinator = act_as('bautista.coach@buksu.edu.ph');
    $staff       = act_as('rosales.office@buksu.edu.ph');
    // Coordinator runs activities 15 and 17, not 16.

    check('db columns', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_front_path'"));
} catch (Throwable $e) {
    check('script ran', false, $e->getMessage());
} finally {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
}

exit($failures > 0 ? 1 : 0);
