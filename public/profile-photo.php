<?php
/**
 * CASDevU — Serve a user's profile photo
 *
 * Photos live outside the web root like every other upload, so they are
 * streamed through here to signed-in users only. The URL carries a version
 * (see user_photo_url()), so a changed photo is fetched fresh while an
 * unchanged one can be cached.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$userId = get_id('id');
$path   = $userId !== null
    ? fetch_value('SELECT photo_path FROM users WHERE user_id = ?', [$userId])
    : null;

$absolutePath = upload_absolute_path(is_string($path) ? $path : null);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    exit;
}

// Only JPG and PNG are ever accepted as photos; anything else is refused
// rather than served with a guessed type.
$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=604800');
readfile($absolutePath);
exit;
