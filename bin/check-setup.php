<?php
/**
 * CASDevU — Setup checker (command line only)
 *
 * Run this when the site says "The system is temporarily unavailable", or
 * after cloning, to see exactly what is missing:
 *
 *   C:\xampp\php\php.exe bin\check-setup.php
 *
 * It checks, in order: PHP version and extensions, the MySQL server, the
 * database, its tables and accounts, and the storage folders. Each failure
 * prints what is wrong and how to fix it. Nothing is changed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// config.php works out BASE_URL from the web server; give it something sane here.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '';
require __DIR__ . '/../includes/config.php';

$failures = 0;
function ok(string $message): void   { echo "  [ OK ]  $message\n"; }
function fail(string $message, string $fix): void
{
    global $failures;
    $failures++;
    echo "  [FAIL]  $message\n          Fix: $fix\n";
}

echo "\nCASDevU setup check\n===================\n";

// ------------------------------------------------------------------- PHP
echo "\nPHP\n";
version_compare(PHP_VERSION, '8.1.0', '>=')
    ? ok('PHP ' . PHP_VERSION)
    : fail('PHP ' . PHP_VERSION . ' is too old; the system needs 8.1 or newer.',
           'Install a current XAMPP (PHP 8.1+).');
extension_loaded('pdo_mysql')
    ? ok('pdo_mysql extension loaded')
    : fail('The pdo_mysql extension is not enabled.',
           'In C:\\xampp\\php\\php.ini remove the ";" before "extension=pdo_mysql", then restart Apache.');
extension_loaded('fileinfo')
    ? ok('fileinfo extension loaded (needed for uploads)')
    : fail('The fileinfo extension is not enabled.',
           'In php.ini remove the ";" before "extension=fileinfo", then restart Apache.');

// --------------------------------------------------------- MySQL server
echo "\nDatabase (" . DB_USER . '@' . DB_HOST . ':' . DB_PORT . ', database "' . DB_NAME . "\")\n";
$server = null;
try {
    $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok('Connected to MySQL ' . $server->query('SELECT VERSION()')->fetchColumn());
} catch (PDOException $e) {
    $code = (int) ($e->errorInfo[1] ?? $e->getCode());
    if ($code === 2002 || str_contains($e->getMessage(), 'refused')) {
        fail('Cannot reach MySQL on ' . DB_HOST . ':' . DB_PORT . ' (' . $e->getMessage() . ').',
             'Start MySQL in the XAMPP Control Panel. If it will not start, another MySQL may be using port '
             . DB_PORT . ': stop it (Windows Services: "MySQL80") or change the port.');
    } elseif ($code === 1045) {
        fail('MySQL refused user "' . DB_USER . '" with the password in includes/config.php ('
             . $e->getMessage() . ').',
             'This MySQL has a root password, or it is not XAMPP\'s MySQL. Use XAMPP\'s MySQL '
             . '(root, no password), or set DB_PASS in includes/config.php on this computer only.');
    } else {
        fail('MySQL connection failed: ' . $e->getMessage(), 'Check that XAMPP\'s MySQL is running.');
    }
}

// ------------------------------------------------------------- Database
if ($server) {
    $exists = $server->query("SHOW DATABASES LIKE " . $server->quote(DB_NAME))->fetchColumn();
    if (!$exists) {
        fail('The database "' . DB_NAME . '" does not exist.',
             'Import the setup files:  C:\\xampp\\mysql\\bin\\mysql.exe -u root < database\\schema.sql'
             . '   then   C:\\xampp\\mysql\\bin\\mysql.exe -u root casms < database\\demo-data.sql');
    } else {
        ok('Database "' . DB_NAME . '" exists');
        $server->exec('USE `' . DB_NAME . '`');
        $tables = (int) $server->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $tables >= 20
            ? ok("$tables tables")
            : fail("Only $tables tables; the import did not finish.",
                   'Re-run database\\schema.sql (this resets the database), then database\\demo-data.sql.');

        if ($tables > 0) {
            $users = $server->query(
                "SELECT r.name AS role, COUNT(*) AS n FROM users u JOIN roles r ON r.role_id = u.role_id
                  WHERE u.status = 'active' GROUP BY r.name ORDER BY r.name"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $users !== []
                ? ok('Active accounts: ' . implode(', ', array_map(static fn($r, $n) => "$n $r", array_keys($users), $users)))
                : fail('There are no active accounts.', 'Import database\\schema.sql, which creates admin@buksu.edu.ph.');

            $locked = (int) $server->query(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL " . (int) LOGIN_LOCKOUT_MINUTES . ' MINUTE)'
            )->fetchColumn();
            $locked >= LOGIN_MAX_ATTEMPTS
                ? fail("$locked failed sign-ins in the last " . LOGIN_LOCKOUT_MINUTES . ' minutes: sign-in is locked.',
                       'Wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes, or run in phpMyAdmin:  '
                       . "DELETE FROM audit_logs WHERE action = 'login_failed';")
                : ok('Sign-in is not locked out');
        }
    }
}

// --------------------------------------------------------------- Storage
echo "\nStorage\n";
foreach (['uploads' => UPLOAD_PATH, 'backups' => STORAGE_PATH . '/backups', 'logs' => STORAGE_PATH . '/logs'] as $name => $dir) {
    if (!is_dir($dir) && $name === 'logs') {
        @mkdir($dir, 0775, true);
    }
    is_dir($dir) && is_writable($dir)
        ? ok("storage/$name is writable")
        : fail("storage/$name is missing or not writable.", "Create the folder $dir and make sure it is not read-only.");
}

echo "\n" . ($failures === 0
    ? "All checks passed. If the site still fails, restart Apache and MySQL in XAMPP.\n\n"
    : "$failures problem" . ($failures === 1 ? '' : 's') . " found. Fix the first one, then run this again.\n\n");
exit($failures === 0 ? 0 : 1);
