<?php
/**
 * CASDevU — Highlight reports
 *
 * One PDF report per activity, posted at once. A report is visible only while
 * its activity is upcoming, ongoing, completed or closed; the row and file are
 * kept when the activity goes back to draft or cancelled.
 */

declare(strict_types=1);

/** The one visibility rule: used by the upload check, the list, the viewer and the PDF route. */
const HIGHLIGHT_STATUSES = ['upcoming', 'ongoing', 'completed', 'closed'];

function highlight_visible_status(string $status): bool
{
    return in_array($status, HIGHLIGHT_STATUSES, true);
}

/** SQL condition built from the constant only, safe to concatenate. */
function highlight_status_sql(string $column = 'a.status'): string
{
    return $column . " IN ('" . implode("','", HIGHLIGHT_STATUSES) . "')";
}

/** '40M' to bytes; '0', '' and '-1' mean no limit. */
function php_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || (int) $value <= 0) {
        return PHP_INT_MAX;
    }
    $number = (int) $value;
    return match (strtoupper(substr($value, -1))) {
        'G'     => $number * 1024 ** 3,
        'M'     => $number * 1024 ** 2,
        'K'     => $number * 1024,
        default => $number,
    };
}

function highlight_server_limit_bytes(): int
{
    return min(
        php_ini_bytes((string) ini_get('upload_max_filesize')),
        php_ini_bytes((string) ini_get('post_max_size'))
    );
}

/** The setting, never above what the server accepts, at least 1 MB. */
function highlight_max_bytes(): int
{
    $configured = (int) office_setting('highlight_max_upload_mb', '40') * 1024 * 1024;

    return max(1024 * 1024, min($configured, highlight_server_limit_bytes()));
}

/**
 * Every visible report, newest activity first.
 *
 * @return array<int, array<string, mixed>>
 */
function published_highlights(): array
{
    return fetch_all(
        'SELECT h.activity_id, h.title, h.file_size, h.updated_at,
                a.title AS activity_title, a.start_at, a.end_at, c.name AS category
           FROM highlight_reports h
           JOIN activities a ON a.activity_id = h.activity_id
           LEFT JOIN activity_categories c ON c.category_id = a.category_id
          WHERE ' . highlight_status_sql('a.status') . '
          ORDER BY a.start_at DESC, h.report_id DESC'
    );
}

/** One visible report with its file details, or null when missing or hidden. */
function published_highlight(int $activityId): ?array
{
    return fetch_one(
        'SELECT h.activity_id, h.title, h.file_size, h.updated_at, h.file_path, h.original_name,
                a.title AS activity_title, a.start_at, a.end_at, c.name AS category
           FROM highlight_reports h
           JOIN activities a ON a.activity_id = h.activity_id
           LEFT JOIN activity_categories c ON c.category_id = a.category_id
          WHERE h.activity_id = ? AND ' . highlight_status_sql('a.status'),
        [$activityId]
    );
}

/** The report row whatever the activity's status (for the upload page). */
function highlight_for_activity(int $activityId): ?array
{
    return fetch_one('SELECT * FROM highlight_reports WHERE activity_id = ?', [$activityId]);
}

/**
 * Post or replace the report. The old file goes only after the row points at
 * the new one, so a failure never leaves a broken report posted.
 *
 * @param  array<string, mixed> $file  $_FILES['pdf']
 * @return array{ok: bool, error?: string, replaced?: bool}
 */
function save_highlight(int $activityId, string $title, array $file, int $userId): array
{
    $status = fetch_value('SELECT status FROM activities WHERE activity_id = ?', [$activityId]);
    if ($status === null || !highlight_visible_status((string) $status)) {
        return ['ok' => false, 'error' => 'A report can be posted only for an activity that is upcoming, ongoing, completed or closed.'];
    }

    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 200) {
        return ['ok' => false, 'error' => 'Enter a title of up to 200 characters.'];
    }

    if (!preg_match('/\.pdf$/i', (string) ($file['name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Only PDF files are accepted.'];
    }

    $stored = store_upload($file, 'highlights', highlight_max_bytes());
    if (!$stored['ok']) {
        return ['ok' => false, 'error' => $stored['error']];
    }
    if ($stored['mime'] !== 'application/pdf') {
        delete_upload($stored['path']);
        return ['ok' => false, 'error' => 'Only PDF files are accepted.'];
    }

    $previous = highlight_for_activity($activityId);

    try {
        query(
            'INSERT INTO highlight_reports (activity_id, title, file_path, original_name, file_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), file_path = VALUES(file_path),
                 original_name = VALUES(original_name), file_size = VALUES(file_size),
                 uploaded_by = VALUES(uploaded_by)',
            [$activityId, $title, $stored['path'], $stored['original'], $stored['size'], $userId]
        );
    } catch (Throwable $e) {
        delete_upload($stored['path']);
        error_log('[CASMS] Saving highlight report failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The report could not be saved. Please try again.'];
    }

    if ($previous !== null) {
        delete_upload((string) $previous['file_path']);
    }

    return ['ok' => true, 'replaced' => $previous !== null];
}

/** Delete the row, then the file. False when there was no report. */
function remove_highlight(int $activityId): bool
{
    $report = highlight_for_activity($activityId);
    if ($report === null) {
        return false;
    }

    query('DELETE FROM highlight_reports WHERE activity_id = ?', [$activityId]);
    delete_upload((string) $report['file_path']);

    return true;
}

/**
 * One Range header against a file size.
 *
 * @return array{0: int, 1: int}|false|null  [start, end] inclusive; false when
 *         unsatisfiable (416); null for absent, malformed or multi-range (serve all)
 */
function parse_byte_range(?string $header, int $size): array|false|null
{
    if ($header === null || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
        return null;
    }
    [, $from, $to] = $m;

    if ($from === '' && $to === '') {
        return null;
    }
    if ($from === '') {                       // suffix: the last N bytes
        $length = (int) $to;
        return $length === 0 ? false : [max(0, $size - $length), $size - 1];
    }

    $start = (int) $from;
    $end   = $to === '' ? $size - 1 : (int) $to;
    if ($to !== '' && $end < $start) {
        return null;
    }
    if ($start >= $size) {
        return false;
    }

    return [$start, min($end, $size - 1)];
}
