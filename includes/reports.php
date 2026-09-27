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
    [$conditions, $params] = report_activity_conditions($filters);

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return fetch_all(
        "SELECT a.activity_id, a.title, a.start_at, a.status,
                c.name AS category, v.name AS venue,
                COUNT(r.registration_id)                               AS total_registered,
                SUM(r.status = 'approved')                             AS total_approved,
                SUM(r.status = 'pending')                              AS total_pending,
                SUM(r.status = 'rejected')                             AS total_rejected,
                SUM(r.status = 'completed')                            AS total_completed,
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
 * The participation report's filters as SQL conditions on activities (a.*),
 * shared by the report table and its charts so they always agree.
 *
 * @return array{0: array<int, string>, 1: array<int, mixed>} [conditions, params]
 */
function report_activity_conditions(array $filters): array
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

    return [$conditions, $params];
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
 * A segment may add 'one' => its singular name, used when the value is 1
 * ("1 participant" rather than "1 participants").
 * Tones are the status tones: success, warning, danger, info, muted, navy.
 * Bars share one scale (the largest row total), so lengths compare honestly.
 * With $ownScale each row fills the track instead, showing its own split:
 * for rows that measure different things, where one scale would dwarf some.
 *
 * @param array<int, array{label: string, segments: array<int, array{value: int, tone: string, name: string}>}> $rows
 */
function bar_chart(string $title, string $caption, array $rows, bool $withLegend = true, bool $ownScale = false): string
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
        $scale = $ownScale ? max(1, array_sum(array_column($row['segments'], 'value'))) : $max;
        foreach ($row['segments'] as $segment) {
            if ($segment['value'] <= 0) {
                continue;
            }
            $width  = round($segment['value'] / $scale * 100, 2);
            $bars  .= '<span class="chart-seg chart-' . e($segment['tone']) . '" style="width: ' . $width . '%"></span>';
            $parts[] = $segment['value'] . ' ' . ($segment['value'] === 1 && isset($segment['one']) ? $segment['one'] : $segment['name']);
        }
        $html .= '<li class="chart-row"><span class="chart-label">' . e($row['label']) . '</span>'
               . '<span class="chart-track" aria-hidden="true">' . $bars . '</span>'
               . '<span class="chart-value">' . e($parts === [] ? 'None' : implode(', ', $parts)) . '</span></li>';
    }

    return $html . '</ul></figure>';
}

// =====================================================================
// Chart data. Each function returns rows for bar_chart(). "Participants"
// means approved or completed registrations throughout.
// =====================================================================

/**
 * Registrations received per month, oldest month first, split into
 * accepted (approved or completed), waiting, and declined (rejected or
 * withdrawn). Every month in the window has a row, so a quiet month shows
 * as an empty bar rather than disappearing.
 */
function registration_trend_rows(int $months = 6): array
{
    $months = max(1, min(24, $months));
    $start  = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));

    $byMonth = [];
    foreach (fetch_all(
        "SELECT DATE_FORMAT(registered_at, '%Y-%m') AS ym,
                SUM(status IN ('approved', 'completed')) AS accepted,
                SUM(status = 'pending')                  AS waiting,
                SUM(status IN ('rejected', 'withdrawn')) AS declined
           FROM registrations
          WHERE registered_at >= ?
          GROUP BY ym",
        [$start . ' 00:00:00']
    ) as $row) {
        $byMonth[$row['ym']] = $row;
    }

    $rows = [];
    for ($i = 0; $i < $months; $i++) {
        $ts  = strtotime("+$i months", strtotime($start));
        $row = $byMonth[date('Y-m', $ts)] ?? ['accepted' => 0, 'waiting' => 0, 'declined' => 0];
        $rows[] = ['label' => date('M Y', $ts), 'segments' => [
            ['value' => (int) $row['accepted'], 'tone' => 'success', 'name' => 'accepted'],
            ['value' => (int) $row['waiting'],  'tone' => 'warning', 'name' => 'waiting for review'],
            ['value' => (int) $row['declined'], 'tone' => 'muted',   'name' => 'rejected or withdrawn'],
        ]];
    }
    return $rows;
}

/**
 * Participants per activity category, largest first. Every active category
 * is listed, so one with no participants shows as zero. Takes the
 * participation report's filters.
 */
function participants_by_category_rows(array $filters = []): array
{
    [$conditions, $params] = report_activity_conditions($filters);
    $activityFilter = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

    $rows = [];
    foreach (fetch_all(
        "SELECT c.name, COUNT(r.registration_id) AS participants
           FROM activity_categories c
           LEFT JOIN activities a    ON a.category_id = c.category_id$activityFilter
           LEFT JOIN registrations r ON r.activity_id = a.activity_id AND r.status IN ('approved', 'completed')
          WHERE c.is_active = 1
          GROUP BY c.category_id, c.name
          ORDER BY participants DESC, c.name",
        $params
    ) as $row) {
        $rows[] = ['label' => $row['name'], 'segments' => [
            ['value' => (int) $row['participants'], 'tone' => 'navy', 'name' => 'participants', 'one' => 'participant'],
        ]];
    }
    return $rows;
}

/** Participants per course, largest first, under the report's filters. */
function participants_by_course_rows(array $filters = []): array
{
    [$conditions, $params] = report_activity_conditions($filters);
    $where = ' WHERE ' . implode(' AND ', array_merge(["r.status IN ('approved', 'completed')"], $conditions));

    $rows = [];
    foreach (fetch_all(
        "SELECT COALESCE(c.code, 'Not set') AS course, COUNT(*) AS participants
           FROM registrations r
           JOIN activities a   ON a.activity_id = r.activity_id
           JOIN users u        ON u.user_id     = r.user_id
           LEFT JOIN courses c ON c.course_id   = u.course_id
           $where
          GROUP BY course
          ORDER BY participants DESC, course",
        $params
    ) as $row) {
        $rows[] = ['label' => $row['course'], 'segments' => [
            ['value' => (int) $row['participants'], 'tone' => 'navy', 'name' => 'participants', 'one' => 'participant'],
        ]];
    }
    return $rows;
}

/**
 * Attendance per activity from participation_report() rows: participants
 * marked present against those not marked. Activities with no participants
 * are left out, since there is no attendance to show.
 */
function attendance_rows(array $reportRows): array
{
    $rows = [];
    foreach ($reportRows as $row) {
        $participants = (int) $row['total_approved'] + (int) $row['total_completed'];
        if ($participants === 0) {
            continue;
        }
        $present = min((int) $row['total_attended'], $participants);
        $rows[] = ['label' => $row['title'] . ' (' . (int) round($present / $participants * 100) . '%)', 'segments' => [
            ['value' => $present,                 'tone' => 'success', 'name' => 'present'],
            ['value' => $participants - $present, 'tone' => 'muted',   'name' => 'not marked present'],
        ]];
    }
    return $rows;
}

/**
 * One activity at a glance, for its participants page: registrations by
 * status, slots filled against the limit, and attendance. The rows measure
 * different things, so draw them with bar_chart()'s own-scale option.
 *
 * @param array<string, int> $counts registration counts keyed by status
 */
function activity_glance_rows(array $counts, ?int $maxParticipants, int $attended): array
{
    $participants = ($counts['approved'] ?? 0) + ($counts['completed'] ?? 0);

    $rows = [['label' => 'Registrations', 'segments' => [
        ['value' => $counts['approved']  ?? 0, 'tone' => 'success', 'name' => 'approved'],
        ['value' => $counts['completed'] ?? 0, 'tone' => 'navy',    'name' => 'completed'],
        ['value' => $counts['pending']   ?? 0, 'tone' => 'warning', 'name' => 'waiting for review'],
        ['value' => $counts['rejected']  ?? 0, 'tone' => 'danger',  'name' => 'rejected'],
        ['value' => $counts['withdrawn'] ?? 0, 'tone' => 'muted',   'name' => 'withdrawn'],
    ]]];

    if ($maxParticipants !== null && $maxParticipants > 0) {
        $rows[] = ['label' => 'Slots (' . $maxParticipants . ')', 'segments' => [
            ['value' => min($participants, $maxParticipants),     'tone' => 'success', 'name' => 'filled'],
            ['value' => max(0, $maxParticipants - $participants), 'tone' => 'muted',   'name' => 'open'],
        ]];
    }
    if ($participants > 0) {
        $rows[] = ['label' => 'Attendance', 'segments' => [
            ['value' => min($attended, $participants),     'tone' => 'success', 'name' => 'present'],
            ['value' => max(0, $participants - $attended), 'tone' => 'muted',   'name' => 'not marked present'],
        ]];
    }
    return $rows;
}

/**
 * One student's record from student_participation_history(): activities
 * joined per category (rejected and withdrawn ones left out), largest first.
 */
function student_category_rows(array $history): array
{
    $perCategory = [];
    foreach ($history as $item) {
        if (in_array($item['registration_status'], ['rejected', 'withdrawn'], true)) {
            continue;
        }
        $perCategory[$item['category']] = ($perCategory[$item['category']] ?? 0) + 1;
    }
    arsort($perCategory);

    $rows = [];
    foreach ($perCategory as $category => $count) {
        $rows[] = ['label' => $category, 'segments' => [
            ['value' => $count, 'tone' => 'navy', 'name' => 'activities', 'one' => 'activity'],
        ]];
    }
    return $rows;
}

/**
 * One student's attendance from student_participation_history(): of the
 * activities they took part in (approved or completed), how many they were
 * marked present at. Empty when they have not taken part in any yet.
 */
function student_attendance_rows(array $history): array
{
    $taken = array_filter($history, static fn(array $h): bool => in_array($h['registration_status'], ['approved', 'completed'], true));
    if ($taken === []) {
        return [];
    }
    $present = count(array_filter($taken, static fn(array $h): bool => (int) $h['attended'] === 1));
    return [['label' => 'Activities taken part in', 'segments' => [
        ['value' => $present,                'tone' => 'success', 'name' => 'present'],
        ['value' => count($taken) - $present, 'tone' => 'muted',  'name' => 'not marked present'],
    ]]];
}

/**
 * Required documents per activity for the review queue: not yet submitted,
 * waiting for review, verified, rejected. Counts one document per mandatory
 * requirement per pending or approved registration, for open activities in
 * the reviewer's scope (a coordinator sees only their own activities).
 */
function document_status_rows(): array
{
    [$scope, $params] = review_activity_scope();

    $rows = [];
    foreach (fetch_all(
        "SELECT a.activity_id, a.title,
                SUM(rs.submission_id IS NULL) AS missing,
                SUM(rs.status = 'pending')    AS waiting,
                SUM(rs.status = 'verified')   AS verified,
                SUM(rs.status = 'rejected')   AS rejected
           FROM activities a
           JOIN activity_requirements ar ON ar.activity_id = a.activity_id AND ar.is_mandatory = 1
           JOIN registrations r          ON r.activity_id  = a.activity_id AND r.status IN ('pending', 'approved')
           LEFT JOIN requirement_submissions rs
                  ON rs.requirement_id = ar.requirement_id AND rs.registration_id = r.registration_id
          WHERE a.status NOT IN ('cancelled', 'completed')$scope
          GROUP BY a.activity_id, a.title, a.start_at
          ORDER BY a.start_at",
        $params
    ) as $row) {
        $rows[] = ['label' => $row['title'], 'segments' => [
            ['value' => (int) $row['missing'],  'tone' => 'warning', 'name' => 'not submitted'],
            ['value' => (int) $row['waiting'],  'tone' => 'info',    'name' => 'waiting for review'],
            ['value' => (int) $row['verified'], 'tone' => 'success', 'name' => 'verified'],
            ['value' => (int) $row['rejected'], 'tone' => 'danger',  'name' => 'rejected'],
        ]];
    }
    return $rows;
}
