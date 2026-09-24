<?php
/**
 * CASDevU — Serve an inventory item's photo (FR-6.1)
 *
 * Photos live outside the web root like every other upload, so they are
 * streamed through here to signed-in users only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$itemId = get_id('id');
$path   = $itemId !== null
    ? fetch_value('SELECT photo_path FROM inventory_items WHERE item_id = ?', [$itemId])
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
header('Cache-Control: private, max-age=86400');
readfile($absolutePath);
exit;
