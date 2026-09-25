<?php
/**
 * CASMS — Report helpers (FR-8.3, FR-8.4, FR-8.5)
 *
 * The queries live here so a report screen and its CSV export always show the
 * same rows — the export cannot drift away from what is on screen.
 */

declare(strict_types=1);

/**
 * Stream rows as a CSV download and stop.
 *
 * @param array<int, string>                 $headers
 * @param array<int, array<int, mixed>>      $rows
 */
function send_csv(string $filename, array $headers, array $rows): never
{
    // Strip anything that could break out of the header or the filename.
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'report.csv';

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }

    $out = fopen('php://output', 'wb');

    // UTF-8 BOM so Excel opens accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($value): string => csv_cell($value), $row));
    }

    fclose($out);
    exit;
}

/**
 * Neutralise a value that a spreadsheet might execute as a formula.
 * A cell beginning =, +, - or @ is prefixed with an apostrophe.
 */
function csv_cell(mixed $value): string
{
    $text = $value === null ? '' : (string) $value;

    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $text;
    }

    return $text;
}

/**
 * Participation figures per activity (FR-8.4).
 * Built on the v_activity_participation view created in schema.sql.
 *
 * @param  array<string, mixed> $filters  category_id, status, from, to
 * @return array<int, array<string, mixed>>
 */
function participation_report(array $filters = []): array
{
    $conditions = [];
    $params     = [];

    if (!empty($filters['category_id'])) {
        $conditions[] = 'a.category_id = ?';
        $params[]     = (int) $filters['category_id'];
    }
    if (!empty($filters['status'])) {
        $conditions[] = 'a.status = ?';
        $params[]     = (string) $filters['status'];
    }
    if (!empty($filters['from'])) {
        $conditions[] = 'a.start_at >= ?';
        $params[]     = date('Y-m-d 00:00:00', strtotime((string) $filters['from']));
    }
    if (!empty($filters['to'])) {
        $conditions[] = 'a.start_at <= ?';
        $params[]     = date('Y-m-d 23:59:59', strtotime((string) $filters['to']));
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return fetch_all(
        "SELECT a.activity_id, a.title, a.start_at, a.status,
                c.name AS category, v.name AS venue,
                COUNT(r.registration_id)                               AS total_registered,
                SUM(r.status = 'approved')                             AS total_approved,
                SUM(r.status = 'pending')                              AS total_pending,
                SUM(r.status = 'rejected')                             AS total_rejected,
                SUM(r.attended = 1)                                    AS total_attended
           FROM activities a
           JOIN activity_categories c ON c.category_id = a.category_id
           LEFT JOIN venues v         ON v.venue_id    = a.venue_id
           LEFT JOIN registrations r  ON r.activity_id = a.activity_id
           $where
          GROUP BY a.activity_id, a.title, a.start_at, a.status, c.name, v.name
          ORDER BY a.start_at DESC",
        $params
    );
}

/**
 * Approved participants broken down by year level (FR-8.4).
 *
 * @return array<int, array<string, mixed>>
 */
function participation_by_year_level(?int $activityId = null): array
{
    $conditions = ["r.status = 'approved'"];
    $params     = [];

    if ($activityId !== null) {
        $conditions[] = 'r.activity_id = ?';
        $params[]     = $activityId;
    }

    $where = ' WHERE ' . implode(' AND ', $conditions);

    return fetch_all(
        "SELECT COALESCE(y.label, 'Not set') AS year_level,
                COALESCE(c.code, 'Not set')  AS course,
                COUNT(*) AS participants
           FROM registrations r
           JOIN users u            ON u.user_id       = r.user_id
           LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
           LEFT JOIN courses c     ON c.course_id     = u.course_id
           $where
          GROUP BY y.label, y.sort_order, c.code
          ORDER BY y.sort_order, c.code",
        $params
    );
}

/**
 * Inventory position: owned, on loan, available, and condition (FR-8.3).
 *
 * @return array<int, array<string, mixed>>
 */
function inventory_report(array $filters = []): array
{
    $conditions = [];
    $params     = [];

    if (!empty($filters['category_id'])) {
        $conditions[] = 'i.inv_category_id = ?';
        $params[]     = (int) $filters['category_id'];
    }
    if (!empty($filters['status'])) {
        $conditions[] = 'i.status = ?';
        $params[]     = (string) $filters['status'];
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return fetch_all(
        "SELECT i.item_id, i.item_code, i.name, i.size, i.unit, i.status,
                i.quantity_total, i.condition_note, i.storage_location,
                ic.name AS category,
                COALESCE((SELECT SUM(b.quantity) FROM borrowings b
                           WHERE b.item_id = i.item_id AND b.returned_at IS NULL), 0) AS quantity_out,
                COALESCE((SELECT COUNT(*) FROM borrowings b2
                           WHERE b2.item_id = i.item_id
                             AND b2.returned_at IS NULL
                             AND b2.expected_return_at < NOW()), 0) AS overdue_loans,
                COALESCE((SELECT COUNT(*) FROM borrowings b3
                           WHERE b3.item_id = i.item_id
                             AND b3.return_condition = 'damaged'), 0) AS times_damaged
           FROM inventory_items i
           JOIN inventory_categories ic ON ic.inv_category_id = i.inv_category_id
           $where
          ORDER BY ic.name, i.name",
        $params
    );
}

/**
 * Every borrowing record, for the movement log (FR-8.3).
 *
 * @return array<int, array<string, mixed>>
 */
function borrowing_report(array $filters = []): array
{
    $conditions = [];
    $params     = [];

    if (($filters['state'] ?? '') === 'open') {
        $conditions[] = 'b.returned_at IS NULL';
    } elseif (($filters['state'] ?? '') === 'overdue') {
        $conditions[] = 'b.returned_at IS NULL AND b.expected_return_at < NOW()';
    } elseif (($filters['state'] ?? '') === 'returned') {
        $conditions[] = 'b.returned_at IS NOT NULL';
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return fetch_all(
        "SELECT b.*, i.item_code, i.name AS item_name,
                u.first_name, u.last_name, u.student_number
           FROM borrowings b
           JOIN inventory_items i ON i.item_id = b.item_id
           JOIN users u           ON u.user_id = b.borrower_id
           $where
          ORDER BY b.released_at DESC",
        $params
    );
}

/**
 * One student's participation history (FR-8.2).
 *
 * @return array<int, array<string, mixed>>
 */
function student_participation_history(int $userId): array
{
    return fetch_all(
        "SELECT a.activity_id, a.title, a.start_at, a.status AS activity_status,
                c.name AS category, r.status AS registration_status,
                r.attended, r.team_name, r.registered_at
           FROM registrations r
           JOIN activities a          ON a.activity_id = r.activity_id
           JOIN activity_categories c ON c.category_id = a.category_id
          WHERE r.user_id = ?
          ORDER BY a.start_at DESC",
        [$userId]
    );
}

/**
 * A horizontal bar chart in plain HTML and CSS: no chart library, prints
 * cleanly, and reads without the picture. Every row states its numbers in
 * words beside the bar, and the detailed table stays on the page under it.
 *
 * Each row: ['label' => string, 'segments' => [['value' => int, 'tone' => string, 'name' => string], ...]]
 * Tones are the status tones: success, warning, danger, info, muted, navy.
 * Bars share one scale (the largest row total), so lengths compare honestly.
 *
 * @param array<int, array{label: string, segments: array<int, array{value: int, tone: string, name: string}>}> $rows
 */
function bar_chart(string $title, string $caption, array $rows, bool $withLegend = true): string
{
    // Values are counts: whole and never negative, whatever the caller passed.
    foreach ($rows as $i => $row) {
        foreach ($row['segments'] as $j => $segment) {
            $rows[$i]['segments'][$j]['value'] = max(0, (int) $segment['value']);
        }
    }

    $max = 0;
    $legend = [];
    foreach ($rows as $row) {
        $max = max($max, array_sum(array_column($row['segments'], 'value')));
        foreach ($row['segments'] as $segment) {
            $legend[$segment['tone']] = $segment['name'];
        }
    }

    $html = '<figure class="chart"><figcaption><h2>' . e($title) . '</h2>'
          . '<p class="hint">' . e($caption) . '</p>';
    if ($withLegend && count($legend) > 1) {
        $html .= '<ul class="chart-legend">';
        foreach ($legend as $tone => $name) {
            $html .= '<li><span class="chart-key chart-' . e($tone) . '" aria-hidden="true"></span>' . e(ucfirst($name)) . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '</figcaption>';

    if ($max === 0) {
        return $html . '<p class="muted">Nothing to chart yet.</p></figure>';
    }

    $html .= '<ul class="chart-rows">';
    foreach ($rows as $row) {
        $parts = [];
        $bars  = '';
        foreach ($row['segments'] as $segment) {
            if ($segment['value'] <= 0) {
                continue;
            }
            $width  = round($segment['value'] / $max * 100, 2);
            $bars  .= '<span class="chart-seg chart-' . e($segment['tone']) . '" style="width: ' . $width . '%"></span>';
            $parts[] = $segment['value'] . ' ' . $segment['name'];
        }
        $html .= '<li class="chart-row"><span class="chart-label">' . e($row['label']) . '</span>'
               . '<span class="chart-track" aria-hidden="true">' . $bars . '</span>'
               . '<span class="chart-value">' . e($parts === [] ? 'None' : implode(', ', $parts)) . '</span></li>';
    }

    return $html . '</ul></figure>';
}
