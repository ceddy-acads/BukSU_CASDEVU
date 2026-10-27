<?php
/**
 * CASMS — Single entry point for every page.
 *
 * Any file under public/ starts with:
 *     require_once __DIR__ . '/../includes/bootstrap.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Installed before anything that can fail, so no error escapes to the browser.
require_once __DIR__ . '/errors.php';
register_error_handlers();
require_once __DIR__ . '/live.php';
if (PHP_SAPI !== 'cli') { ob_start('live_output'); }

if (PHP_SAPI !== 'cli') {
    // shortcut: reads HTTPS from this server only; behind a proxy that ends TLS
    // this redirects forever, so trust X-Forwarded-Proto before using one.
    $isHttps = ($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '';
    if (FORCE_HTTPS && !$isHttps) {
        header('Location: https://' . APP_HOSTNAME . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('X-Frame-Options: SAMEORIGIN');           // no framing by other sites (clickjacking)
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');          // page URLs carry record ids
    if (FORCE_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    unset($isHttps);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/attention.php';
require_once __DIR__ . '/participation.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/student-requirements.php';
require_once __DIR__ . '/highlights.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/borrowing.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/invitations.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/backup.php';

start_session();
if (live_is_poll()) { require_login(); }
