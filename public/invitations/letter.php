<?php
/**
 * CASMS — Serve an invitation's letter
 *
 * Office and coordinators only, and only for an invitation they may see:
 * a coordinator gets 404 for a pending or rejected one.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
if (!can_view_invitations()) {
    http_response_code(403);
    abort_page(403, 'You do not have permission to open this document.');
}

$id         = get_id('id');
$invitation = $id !== null ? fetch_visible_invitation($id) : null;
if ($invitation === null || !$invitation['letter_path']) {
    http_response_code(404);
    abort_page(404, 'File not found.');
}

$absolutePath = upload_absolute_path($invitation['letter_path']);
if ($absolutePath === null || !is_file($absolutePath)) {
    error_log('[CASMS] Missing upload on disk: ' . $invitation['letter_path']);
    http_response_code(404);
    abort_page(404, 'The stored file could not be located.');
}

audit_log('view', 'invitation', (int) $id, 'Opened the invitation letter');

$mime = (string) ($invitation['letter_mime'] ?? 'application/octet-stream');
if (!array_key_exists($mime, ALLOWED_UPLOAD_MIME)) {
    $mime = 'application/octet-stream';
}

$filename = sanitize_filename((string) ($invitation['letter_name'] ?? 'letter'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
header('Cache-Control: private, no-store');

readfile($absolutePath);
exit;
