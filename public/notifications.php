<?php
/**
 * CASMS — Notifications (FR-3.3, FR-3.4)
 *
 * Notifications are read in the pop-up under the bell on every page
 * (includes/layout/notifications-menu.php). This address remains for two
 * things:
 *   POST  mark all of the user's notifications read. The pop-up's script
 *         calls it when the pop-up opens (answered with 204); without
 *         JavaScript the "Mark all as read" button posts here and returns.
 *   GET   old links and bookmarks: open the dashboard with the pop-up open.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

if (!is_post()) {
    redirect('index.php?notifications=open');
}

csrf_verify();

// A link clicked in the pop-up: app.js sends this as a beacon while the
// browser follows the link, so there is no page to answer.
$dismiss = post('dismiss');
if ($dismiss !== '') {
    if (str_starts_with($dismiss, 'notification:')) {
        dismiss_notification((int) current_user_id(), (int) substr($dismiss, strlen('notification:')));
    } elseif (str_starts_with($dismiss, 'attention:')) {
        dismiss_attention_item(substr($dismiss, strlen('attention:')));
    }
    http_response_code(204);
    exit;
}

mark_notifications_read((int) current_user_id());

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    http_response_code(204);
    exit;
}

// Back to the page the button was pressed on, if it is one of ours.
$back  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$parts = parse_url($back);
$backHost = isset($parts['host']) ? $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';
$sameSite = $back !== ''
    && isset($parts['path'])
    && strcasecmp($backHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0
    && str_starts_with($parts['path'], BASE_URL . '/');

if ($sameSite) {
    header('Location: ' . $back);
    exit;
}
redirect('index.php');
