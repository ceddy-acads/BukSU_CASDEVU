<?php
/**
 * CASMS — Participation report (FR-8.4, FR-8.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$categories = fetch_all('SELECT category_id, name FROM activity_categories ORDER BY name');
$statuses   = ['draft', 'upcoming', 'ongoing', 'completed', 'cancelled', 'closed'];

$filters = [
    'category_id' => get('category') !== '' && ctype_digit(get('category')) ? (int) get('category') : null,
    'status'      => in_array(get('status'), $statuses, true) ? get('status') : null,
    'from'        => get('from') !== '' && strtotime(get('from')) !== false ? get('from') : null,
    'to'          => get('to')   !== '' && strtotime(get('to'))   !== false ? get('to')   : null,
];

$rows = participation_report($filters);

// A coordinator sees only the activities assigned to them.
if (!is_office_staff()) {
    $mine = array_column(
        fetch_all('SELECT activity_id FROM activity_coordinators WHERE user_id = ?', [current_user_id()]),
        'activity_id'
    );
    $mine = array_map('intval', $mine);
    $rows = array_values(array_filter(
        $rows,
        static fn(array $r): bool => in_array((int) $r['activity_id'], $mine, true)
    ));
}

// ------------------------------------------------------------------ Export
if (get('export') === 'csv') {
    audit_log('export', 'report', null, 'Exported participation report (' . count($rows) . ' rows)');

    send_csv(
        'participation-report-' . date('Ymd') . '.csv',
        ['Activity', 'Category', 'Venue', 'Starts', 'Status',
         'Registered', 'Approved', 'Pending', 'Rejected', 'Attended'],
        array_map(static fn(array $r): array => [
            $r['title'], $r['category'], $r['venue'] ?? '',
            $r['start_at'], $r['status'],
            (int) $r['total_registered'], (int) $r['total_approved'],
            (int) $r['total_pending'], (int) $r['total_rejected'], (int) $r['total_attended'],
        ], $rows)
    );
}

// ------------------------------------------------------------------ Totals
$totals = ['registered' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'attended' => 0];
foreach ($rows as $row) {
    $totals['registered'] += (int) $row['total_registered'];
    $totals['approved']   += (int) $row['total_approved'];
    $totals['pending']    += (int) $row['total_pending'];
    $totals['rejected']   += (int) $row['total_rejected'];
    $totals['attended']   += (int) $row['total_attended'];
}

$byYearLevel = is_office_staff() ? participation_by_year_level() : [];

/** Keep filters when switching to the CSV link. */
function participation_link(array $overrides = []): string
{
    $query = array_filter(array_merge([
        'category' => get('category'), 'status' => get('status'),
        'from' => get('from'), 'to' => get('to'),
    ], $overrides), static fn($v): bool => $v !== '' && $v !== null);

    return url('reports/participation.php' . ($query === [] ? '' : '?' . http_build_query($query)));
}

$pageTitle = 'Participation report';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('reports/index.php') ?>">&larr; Back to reports</a>
        <h1>Participation report</h1>
        <p>
            <?= count($rows) ?> activit<?= count($rows) === 1 ? 'y' : 'ies' ?>
            &middot; <?= number_format($totals['approved']) ?> approved participants
        </p>
    </div>
    <div class="btn-row">
        <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
        <a class="btn btn-gold" href="<?= e(participation_link(['export' => 'csv'])) ?>">Export CSV</a>
    </div>
</div>

<div class="report-meta">
    <strong><?= e(UNIVERSITY) ?></strong> &middot; <?= e(OFFICE_NAME) ?><br>
    Participation report generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
    by <?= e(full_name(current_user())) ?>
</div>

<?php $filtered = get('category') !== '' || get('status') !== '' || get('from') !== '' || get('to') !== ''; ?>
<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['category_id'] ?>"
                    <?= get('category') === (string) $category['category_id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $option): ?>
                <option value="<?= e($option) ?>" <?= get('status') === $option ? 'selected' : '' ?>>
                    <?= e(ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e(get('from')) ?>">
    </div>
    <div class="form-row">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e(get('to')) ?>">
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($filtered): ?>
            <a class="btn btn-outline" href="<?= url('reports/participation.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="empty">
        <?php if ($filtered): ?>
            <strong>No activities match these filters</strong>
            Try a different category, status, or date range, or clear the filters.
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('reports/participation.php') ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <strong>Nothing to report yet</strong>
            <?= is_office_staff()
                ? 'Participation figures appear here once activities are created.'
                : 'Participation figures appear here once you are assigned to an activity.' ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= number_format($totals['registered']) ?></div>
            <div class="stat-label">Registrations</div>
        </div>
        <div class="stat <?= stat_tone($totals['approved'], 'stat-success') ?>">
            <div class="stat-value"><?= number_format($totals['approved']) ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat <?= stat_tone($totals['pending'], 'stat-gold') ?>">
            <div class="stat-value"><?= number_format($totals['pending']) ?></div>
            <div class="stat-label">Awaiting review</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= number_format($totals['attended']) ?></div>
            <div class="stat-label">Marked present</div>
        </div>
    </div>

    <section class="card card-flush">
        <div class="card-head"><h2>By activity</h2></div>
        <div class="table-wrap">
            <table class="data" data-sortable>
                <thead>
                    <tr>
                        <th data-sort="text">Activity</th><th data-sort="text">Category</th><th data-sort="number">Starts</th><th data-sort="text">Status</th>
                        <th class="num" data-sort="number">Registered</th><th class="num" data-sort="number">Approved</th><th class="num" data-sort="number">Pending</th>
                        <th class="num" data-sort="number">Rejected</th><th class="num" data-sort="number">Attended</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= url('activities/view.php?id=' . (int) $row['activity_id']) ?>">
                                <?= e($row['title']) ?>
                            </a>
                            <?php if ($row['venue']): ?>
                                <div class="hint"><?= e($row['venue']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['category']) ?></td>
                        <td class="nowrap" data-sort-value="<?= (int) strtotime((string) $row['start_at']) ?>"><?= e(format_date($row['start_at'])) ?></td>
                        <td><?= status_badge($row['status'], 'activity') ?></td>
                        <td class="num"><?= (int) $row['total_registered'] ?></td>
                        <td class="num"><strong><?= (int) $row['total_approved'] ?></strong></td>
                        <td class="num"><?= (int) $row['total_pending'] ?></td>
                        <td class="num"><?= (int) $row['total_rejected'] ?></td>
                        <td class="num"><?= (int) $row['total_attended'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Total</td>
                        <td class="num"><?= number_format($totals['registered']) ?></td>
                        <td class="num"><?= number_format($totals['approved']) ?></td>
                        <td class="num"><?= number_format($totals['pending']) ?></td>
                        <td class="num"><?= number_format($totals['rejected']) ?></td>
                        <td class="num"><?= number_format($totals['attended']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <?php if ($byYearLevel !== []): ?>
        <section class="card card-flush">
            <div class="card-head">
                <h2>Approved participants by year level and course</h2>
                <p class="hint">
                    Counts every approved registration across all activities, so a
                    student who joined two activities is counted twice.
                </p>
            </div>
            <div class="table-wrap">
                <table class="data table-stack">
                    <thead><tr><th>Year level</th><th>Course</th><th class="num">Participants</th></tr></thead>
                    <tbody>
                    <?php foreach ($byYearLevel as $row): ?>
                        <tr>
                            <td data-label="Year level"><?= e($row['year_level']) ?></td>
                            <td data-label="Course"><?= e($row['course']) ?></td>
                            <td data-label="Participants" class="num"><?= (int) $row['participants'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
