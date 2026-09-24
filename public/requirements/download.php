<?php
/**
 * CASMS — Serve an uploaded requirement file (FR-5.4)
 *
 * Uploads live outside the web root, so this script is the only way to reach
 * them — and it authorizes every request first. A student may open their own
 * documents; office staff and the activity's coordinators may open any.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$submissionId = get_id('submission_id');
if ($submissionId === null) {
    http_response_code(404);
    abort_page(404, 'File not found.');
}

$submission = fetch_one(
    'SELECT rs.*, r.user_id AS owner_id, r.activity_id, ar.name AS requirement_name
       FROM requirement_submissions rs
       JOIN registrations r          ON r.registration_id = rs.registration_id
       JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
      WHERE rs.submission_id = ?',
    [$submissionId]
);

if ($submission === null || !$submission['file_path']) {
    http_response_code(404);
    abort_page(404, 'File not found.');
}

// ------------------------------------------------------------ Authorization
$isOwner = (int) $submission['owner_id'] === current_user_id();

if (!$isOwner && !can_manage_activity((int) $submission['activity_id'])) {
    http_response_code(403);
    abort_page(403, 'You do not have permission to open this document.');
}

$absolutePath = upload_absolute_path($submission['file_path']);
if ($absolutePath === null || !is_file($absolutePath)) {
    error_log('[CASMS] Missing upload on disk: ' . $submission['file_path']);
    http_response_code(404);
    abort_page(404, 'The stored file could not be located. Please contact the office.');
}

audit_log('view', 'requirement_submission', $submissionId,
          'Downloaded "' . $submission['requirement_name'] . '"');

// ------------------------------------------------------------------- Serve
// Only types from our own whitelist are ever stored, so echoing the recorded
// MIME here cannot be used to smuggle a script type past the browser.
$mime = (string) ($submission['mime_type'] ?? 'application/octet-stream');
if (!array_key_exists($mime, ALLOWED_UPLOAD_MIME)) {
    $mime = 'application/octet-stream';
}

$filename = sanitize_filename((string) ($submission['original_name'] ?? 'document'));

// inline for PDFs and images so staff can review without downloading;
// nosniff stops the browser second-guessing the declared type.
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
header('Cache-Control: private, no-store');

readfile($absolutePath);
exit;
