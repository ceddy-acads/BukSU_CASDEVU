<?php
/**
 * CASDevU — Serve one side of a student's school ID
 *
 * Every refusal looks the same as a missing file (404, no body), so the reply
 * never reveals whether a photo exists. Like profile-photo.php this is a
 * binary route, so it ends with a bare 404 rather than an HTML error page.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$userId = get_id('user');
$side   = get('side');

$path = null;
if ($userId !== null && ($side === 'front' || $side === 'back') && can_view_id_photos($userId)) {
    $path = fetch_value('SELECT id_' . $side . '_path FROM users WHERE user_id = ?', [$userId]);   // $side whitelisted above
}

$absolutePath = upload_absolute_path(is_string($path) ? $path : null);
$mime = $absolutePath !== null && is_file($absolutePath)
    ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath)
    : '';
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($absolutePath);
exit;
