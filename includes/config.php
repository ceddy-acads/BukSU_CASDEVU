<?php
/**
 * CASMS — Application configuration
 *
 * Local development credentials. For any real deployment, move these values
 * into environment variables and keep this file out of version control.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- Environment
// Ships as 'production' so a deployed copy never prints an error, a file path,
// or a fragment of SQL to a visitor. Switch to 'development' while working on
// the code to see the detail on screen; either way everything is written to
// storage/logs/php-error.log.
define('APP_ENV', 'production');           // 'development' | 'production'
define('APP_DEBUG', APP_ENV === 'development');

// ---------------------------------------------------------------- Application
define('APP_NAME',    'CASMS');
define('APP_TAGLINE', 'Culture, Arts, and Sports Management System');
define('UNIVERSITY',  'Bukidnon State University');
define('OFFICE_NAME', 'Office of Culture, Arts, and Sports');

/**
 * Public URL prefix — the URL path that maps to public/.
 *
 * Detected automatically by comparing public/ against the server's document
 * root, so the same code runs under XAMPP (/casms/public), under a virtual
 * host pointed at public/ (''), and under `php -S` (''). Override it below
 * only if the detection ever gets it wrong.
 */
function detect_base_url(): string
{
    $publicDir = str_replace('\\', '/', (string) realpath(__DIR__ . '/../public'));
    $docRoot   = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));

    // public/ is itself the document root — no prefix needed.
    if ($docRoot === '' || $docRoot === $publicDir) {
        return '';
    }

    // public/ sits inside the document root: the prefix is what lies between.
    if (str_starts_with($publicDir, $docRoot . '/')) {
        return rtrim(substr($publicDir, strlen($docRoot)), '/');
    }

    // Served from outside the document root (an Alias, for instance). Derive
    // the prefix from the request URL, stripping as many trailing segments as
    // the running script sits deep inside public/ — so a page at
    // public/activities/index.php strips 'activities/index.php', not just the
    // filename.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if ($scriptFile !== '' && str_starts_with($scriptFile, $publicDir . '/')) {
        $relative = substr($scriptFile, strlen($publicDir) + 1);   // 'activities/index.php'
        $depth    = substr_count($relative, '/') + 1;              // segments to remove
        $segments = explode('/', trim($scriptName, '/'));
        $prefix   = implode('/', array_slice($segments, 0, max(0, count($segments) - $depth)));
        return $prefix === '' ? '' : '/' . $prefix;
    }

    return '';
}

define('BASE_URL', detect_base_url());

// ------------------------------------------------------------------- Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'casms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --------------------------------------------------------------- Filesystem
// Project root — the directory that contains includes/, public/, storage/.
define('BASE_PATH',    dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH',  STORAGE_PATH . '/uploads');

// ---------------------------------------------------------------- Uploads
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);          // 5 MB — mirrors settings table
define('ALLOWED_UPLOAD_MIME', [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
]);

// ---------------------------------------------------------------- Sessions
define('SESSION_NAME',          'casms_session');
define('SESSION_IDLE_TIMEOUT',  60 * 60);              // 1 hour of inactivity

// --------------------------------------------------------- Sign-in throttling
// Failed attempts are counted per IP address from the audit log.
define('LOGIN_MAX_ATTEMPTS',    8);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ------------------------------------------------------------------- Email
// 'log'  — write messages to storage/logs/mail.log (default; XAMPP has no
//          mail server, and every flow still works end to end)
// 'mail' — hand messages to PHP's mail() on a server that has one
define('MAIL_TRANSPORT',      'log');          // 'log' | 'mail'
define('MAIL_FROM_ADDRESS',   'no-reply@buksu.edu.ph');
define('MAIL_FROM_NAME',      'BukSU Office of Culture, Arts, and Sports');
define('APP_HOSTNAME',        'localhost');    // used to build links inside emails

// Whether approvals, rejections and reminders are also emailed. The in-app
// notification is always sent either way.
define('MAIL_NOTIFICATIONS_ENABLED', true);

// ------------------------------------------------------------ Password reset
define('PASSWORD_RESET_TTL_MINUTES', 60);
define('PASSWORD_RESET_MAX_PER_HOUR', 5);      // per account, to stop mail flooding

// ------------------------------------------------------------------ Backups
// mysqldump / mysql live beside each other in the XAMPP install.
define('MYSQL_BIN_PATH', 'C:/xampp/mysql/bin');
define('BACKUP_PATH',    STORAGE_PATH . '/backups');
define('BACKUP_KEEP',    20);                  // newest N kept; older ones listed for deletion

// ---------------------------------------------------------------- Pagination
define('PER_PAGE', 25);                                // NFR-6

// ---------------------------------------------------------------- Error display
// Errors are logged either way; they are only *printed* in development.
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Keep the log inside the project (and outside the web root) so it is easy to
// find when something goes wrong during a demo.
if (!is_dir(STORAGE_PATH . '/logs')) {
    @mkdir(STORAGE_PATH . '/logs', 0775, true);
}
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

date_default_timezone_set('Asia/Manila');
