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

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/participation.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/backup.php';

start_session();
