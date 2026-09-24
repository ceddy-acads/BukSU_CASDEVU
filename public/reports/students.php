<?php
/**
 * CASMS — Student and player records (FR-8.1, FR-8.2, FR-8.5)
 *
 * Browse participants across all activities, and open one student's full
 * participation history.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$courses    = fetch_all('SELECT course_id, code, name FROM courses ORDER BY code');
$yearLevels = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');

// =====================================================================
// One student's history
// =====================================================================
$studentId = get_id('student');

if ($studentId !== null) {
    $student = fetch_one(
        "SELECT u.*, c.code AS course_code, c.name AS course_name, y.label AS year_level_label
           FROM users u
           JOIN roles r            ON r.role_id       = u.role_id
           LEFT JOIN courses c     ON c.course_id     = u.course_id
           LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
          WHERE u.user_id = ? AND r.name = 'student'",
        [$studentId]
    );

    if ($student === null) {
        http_response_code(404);
        abort_page(404, 'Student not found.');
    }

    $history = student_participation_history($studentId);

    // A coordinator may only see activities they run.
    if (!is_office_staff()) {
        $mine = array_map('intval', array_column(
            fetch_all('SELECT activity_id FROM activity_coordinators WHERE user_id = ?', [current_user_id()]),
            'activity_id'
        ));
        $history = array_values(array_filter(
            $history,
            static fn(array $h): bool => in_array((int) $h['activity_id'], $mine, true)
        ));
    }

    if (get('export') === 'csv') {
        audit_log('export', 'report', $studentId,
                  'Exported participation history for ' . full_name($student));

        send_csv(
            'participation-history-' . ($student['student_number'] ?? $studentId) . '.csv',
            ['Activity', 'Category', 'Starts', 'Activity status',
             'Registration status', 'Attended', 'Team', 'Registered on'],
            array_map(static fn(array $h): array => [
                $h['title'], $h['category'], $h['start_at'], $h['activity_status'],
                $h['registration_status'], $h['attended'] ? 'Yes' : 'No',
                $h['team_name'] ?? '', $h['registered_at'],
            ], $history)
        );
    }

    $approved = count(array_filter($history, static fn(array $h): bool => $h['registration_status'] === 'approved'));
    $attended = count(array_filter($history, static fn(array $h): bool => (int) $h['attended'] === 1));

    $pageTitle = full_name($student);
    require __DIR__ . '/../../includes/layout/header.php';
    ?>

    <div class="page-head">
        <div>
            <a class="crumb" href="<?= url('reports/students.php') ?>">&larr; Back to student records</a>
            <h1><?= e(full_name($student, true)) ?></h1>
            <p>
                <?= $student['student_number'] ? e($student['student_number']) : 'No student number' ?>
                <?= $student['course_code'] ? ' &middot; ' . e($student['course_code']) : '' ?>
                <?= $student['year_level_label'] ? ' ' . e($student['year_level_label']) : '' ?>
                <?= $student['section'] ? ' &middot; Section ' . e($student['section']) : '' ?>
            </p>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
            <a class="btn btn-gold" href="<?= url('reports/students.php?student=' . $studentId . '&export=csv') ?>">Export CSV</a>
        </div>
    </div>

    <div class="report-meta">
        <strong><?= e(UNIVERSITY) ?></strong> &middot; <?= e(OFFICE_NAME) ?><br>
        Participation history generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
    </div>

    <div class="stats stats-3">
        <div class="stat">
            <div class="stat-value"><?= count($history) ?></div>
            <div class="stat-label">Activities joined</div>
        </div>
        <div class="stat <?= stat_tone($approved, 'stat-success') ?>">
            <div class="stat-value"><?= $approved ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= $attended ?></div>
            <div class="stat-label">Attended</div>
        </div>
    </div>

    <section class="card<?= $history !== [] ? ' card-flush' : '' ?>">
        <div class="card-head"><h2>Participation history</h2></div>
        <?php if ($history === []): ?>
            <div class="empty">
                <strong>No participation recorded</strong>
                <?= is_office_staff()
                    ? 'This student has not registered for any activity.'
                    : 'This student has not joined any activity you coordinate.' ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Activity</th><th>Category</th><th>Date</th>
                            <th>Registration</th><th>Attended</th><th>Team</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= url('activities/view.php?id=' . (int) $row['activity_id']) ?>">
                                    <?= e($row['title']) ?>
                                </a>
                                <div class="hint"><?= e(ucfirst((string) $row['activity_status'])) ?></div>
                            </td>
                            <td><?= e($row['category']) ?></td>
                            <td class="nowrap"><?= e(format_date($row['start_at'])) ?></td>
                            <td><?= status_badge($row['registration_status']) ?></td>
                            <td><?= (int) $row['attended'] === 1 ? 'Yes' : '<span class="muted">No</span>' ?></td>
                            <td>
                                <?php if ($row['team_name']): ?>
                                    <?= e($row['team_name']) ?>
                                <?php else: ?>
                                    <span class="muted">None</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php
    require __DIR__ . '/../../includes/layout/footer.php';
    return;
}

// =====================================================================
// Student listing
// =====================================================================
$keyword    = get('q');
$courseIn   = get('course');
$yearIn     = get('year_level');
$onlyActive = get('participants') === '1';

$conditions = ["r.name = 'student'"];
$params     = [];

if ($keyword !== '') {
    $conditions[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_number LIKE ? OR u.email LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($courseIn !== '' && ctype_digit($courseIn)) {
    $conditions[] = 'u.course_id = ?';
    $params[]     = (int) $courseIn;
}
if ($yearIn !== '' && ctype_digit($yearIn)) {
    $conditions[] = 'u.year_level_id = ?';
    $params[]     = (int) $yearIn;
}
if ($onlyActive) {
    $conditions[] = 'EXISTS (SELECT 1 FROM registrations reg WHERE reg.user_id = u.user_id)';
}

$where = ' WHERE ' . implode(' AND ', $conditions);

$page   = max(1, (int) (get('page') ?: 1));
$total  = (int) fetch_value(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id$where", $params);
$pages  = max(1, (int) ceil($total / PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

$students = fetch_all(
    "SELECT u.user_id, u.student_number, u.first_name, u.last_name, u.email,
            u.section, u.status,
            c.code AS course_code, y.label AS year_level_label,
            (SELECT COUNT(*) FROM registrations reg WHERE reg.user_id = u.user_id) AS total_joined,
            (SELECT COUNT(*) FROM registrations reg2
              WHERE reg2.user_id = u.user_id AND reg2.status = 'approved') AS total_approved
       FROM users u
       JOIN roles r            ON r.role_id       = u.role_id
       LEFT JOIN courses c     ON c.course_id     = u.course_id
       LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
       $where
      ORDER BY u.last_name, u.first_name
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

if (get('export') === 'csv') {
    // Export the whole filtered set, not just the page on screen.
    $allStudents = fetch_all(
        "SELECT u.student_number, u.first_name, u.last_name, u.email, u.section, u.status,
                c.code AS course_code, y.label AS year_level_label,
                (SELECT COUNT(*) FROM registrations reg WHERE reg.user_id = u.user_id) AS total_joined,
                (SELECT COUNT(*) FROM registrations reg2
                  WHERE reg2.user_id = u.user_id AND reg2.status = 'approved') AS total_approved
           FROM users u
           JOIN roles r            ON r.role_id       = u.role_id
           LEFT JOIN courses c     ON c.course_id     = u.course_id
           LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
           $where
          ORDER BY u.last_name, u.first_name",
        $params
    );

    audit_log('export', 'report', null, 'Exported student records (' . count($allStudents) . ' rows)');

    send_csv(
        'student-records-' . date('Ymd') . '.csv',
        ['Student number', 'Last name', 'First name', 'Email', 'Course',
         'Year level', 'Section', 'Account status', 'Activities joined', 'Approved'],
        array_map(static fn(array $s): array => [
            $s['student_number'] ?? '', $s['last_name'], $s['first_name'], $s['email'],
            $s['course_code'] ?? '', $s['year_level_label'] ?? '', $s['section'] ?? '',
            $s['status'], (int) $s['total_joined'], (int) $s['total_approved'],
        ], $allStudents)
    );
}

function students_link(array $overrides = []): string
{
    $query = array_filter(array_merge([
        'q' => get('q'), 'course' => get('course'),
        'year_level' => get('year_level'), 'participants' => get('participants'),
    ], $overrides), static fn($v): bool => $v !== '' && $v !== null);

    return url('reports/students.php' . ($query === [] ? '' : '?' . http_build_query($query)));
}

$pageTitle = 'Student records';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('reports/index.php') ?>">&larr; Back to reports</a>
        <h1>Student &amp; player records</h1>
        <p><?= number_format($total) ?> student<?= $total === 1 ? '' : 's' ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-gold" href="<?= e(students_link(['export' => 'csv'])) ?>">Export CSV</a>
    </div>
</div>

<?php $filtered = $keyword !== '' || $courseIn !== '' || $yearIn !== '' || $onlyActive; ?>
<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Name, student number, email">
    </div>
    <div class="form-row">
        <label for="course">Course</label>
        <select id="course" name="course">
            <option value="">All courses</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['course_id'] ?>"
                    <?= $courseIn === (string) $course['course_id'] ? 'selected' : '' ?>>
                    <?= e($course['code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="year_level">Year level</label>
        <select id="year_level" name="year_level">
            <option value="">All year levels</option>
            <?php foreach ($yearLevels as $level): ?>
                <option value="<?= (int) $level['year_level_id'] ?>"
                    <?= $yearIn === (string) $level['year_level_id'] ? 'selected' : '' ?>>
                    <?= e($level['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label class="check">
            <input type="checkbox" name="participants" value="1"
                <?= $onlyActive ? 'checked' : '' ?>>
            Only those who have joined something
        </label>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filtered): ?>
            <a class="btn btn-outline" href="<?= url('reports/students.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($students === []): ?>
    <div class="empty">
        <?php if ($filtered): ?>
            <strong>No students match these filters</strong>
            Try a different search, or clear the filters to see every student.
            <div class="btn-row">
                <a class="btn btn-outline" href="<?= url('reports/students.php') ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <strong>No student accounts yet</strong>
            Students appear here once they register for an account.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card card-flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Student</th><th>Course / Year</th><th class="num">Joined</th>
                        <th class="num">Approved</th><th>Account</th><th class="actions"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <strong><?= e(full_name($student, true)) ?></strong>
                            <div class="hint"><?= $student['student_number'] ? e($student['student_number']) : 'No student number' ?></div>
                        </td>
                        <td>
                            <?php if ($student['course_code']): ?>
                                <?= e($student['course_code']) ?>
                            <?php else: ?>
                                <span class="muted">Course not set</span>
                            <?php endif; ?>
                            <?php if ($student['year_level_label'] || $student['section']): ?>
                                <div class="hint">
                                    <?= e($student['year_level_label'] ?? '') ?>
                                    <?= $student['section'] ? ' &middot; Section ' . e($student['section']) : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $student['total_joined'] ?></td>
                        <td class="num"><strong><?= (int) $student['total_approved'] ?></strong></td>
                        <td><?= status_badge($student['status']) ?></td>
                        <td class="actions">
                            <div class="btn-row">
                                <a class="btn btn-outline btn-sm"
                                   href="<?= url('reports/students.php?student=' . (int) $student['user_id']) ?>">
                                    History<span class="sr-only"> for <?= e(full_name($student)) ?></span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(students_link(['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
