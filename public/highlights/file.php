<?php
/**
 * CASDevU — Serve a highlight report PDF
 *
 * Public by design, so no require_login(): visitors read posted reports
 * without an account (spec 2026-10-24-highlight-reports-design). Only a
 * report whose activity is upcoming, ongoing, completed or closed is served.
 * Supports HTTP Range so the PDF viewer can fetch pages as it needs them.
 *
 *   ?activity_id=N            inline
 *   ?activity_id=N&download=1 attachment
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('activity_id');
$row        = $activityId !== null ? published_highlight($activityId) : null;

$absolutePath = $row !== null ? upload_absolute_path((string) $row['file_path']) : null;
if ($absolutePath === null || !is_file($absolutePath)
    || (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) !== 'application/pdf') {
    http_response_code(404);
    exit;   // binary route: a bare status, as inventory/photo.php does (AGENTS rule 8 exception)
}

// A long read must not hold the session lock.
session_write_close();
// Public, cacheable response: no session cookie or no-cache headers.
header_remove('Set-Cookie');
header_remove('Pragma');
header_remove('Expires');

$size  = (int) filesize($absolutePath);
$range = parse_byte_range($_SERVER['HTTP_RANGE'] ?? null, $size);

$disposition = (($_GET['download'] ?? '') === '1') ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . $disposition . '; filename="' . sanitize_filename(str_replace(['/', '\\'], '-', (string) $row['title'])) . '.pdf"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; object-src 'none'");
// Short, so a report that gets hidden stops being served soon.
header('Cache-Control: public, max-age=300');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) strtotime((string) $row['updated_at'])) . ' GMT');

if ($range === false) {
    http_response_code(416);
    header('Content-Range: bytes */' . $size);
    header('Content-Length: 0');
    exit;
}

if ($range === null) {
    $start = 0;
    $end   = $size - 1;
} else {
    [$start, $end] = $range;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
$length = $end - $start + 1;
header('Content-Length: ' . $length);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($absolutePath, 'rb');
if ($handle === false) {
    exit;
}
fseek($handle, $start);
while ($length > 0 && !feof($handle) && !connection_aborted()) {
    $chunk = fread($handle, min(65536, $length));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
    flush();
}
fclose($handle);
exit;
