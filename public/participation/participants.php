<?php
/**
 * CASMS — Participant list, approval, attendance (FR-4.6 … FR-4.9)
 *
 * Staff and admin reach any activity; a coordinator only the ones assigned to
 * them — enforced by require_activity_access().
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('activity_id') ?? (int) post('activity_id');
if ($activityId <= 0) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

require_activity_access($activityId);

$activity = fetch_one(
    'SELECT a.*, c.name AS category FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
      WHERE a.activity_id = ?',
    [$activityId]
);
if ($activity === null) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

// =====================================================================
// Actions
// =====================================================================
if (is_post()) {
    csrf_verify();

    $action         = post('action');
    $registrationId = (int) post('registration_id');

    $registration = fetch_one(
        'SELECT r.*, u.first_name, u.last_name, u.user_id
           FROM registrations r
           JOIN users u ON u.user_id = r.user_id
          WHERE r.registration_id = ? AND r.activity_id = ?',
        [$registrationId, $activityId]
    );
    if ($registration === null) {
        flash('error', 'That registration no longer exists.');
        redirect('participation/participants.php?activity_id=' . $activityId);
    }

    $studentName = full_name($registration);

    switch ($action) {
        case 'approve':
            // Re-check capacity at the moment of approval: several pending
            // registrations may have been queued while slots ran out.
            if ($activity['max_participants'] !== null
                && approved_participant_count($activityId) >= (int) $activity['max_participants']
                && $registration['status'] !== 'approved') {
                flash('error', 'All ' . (int) $activity['max_participants']
                    . ' slots are already filled. Reject someone first, or raise the limit.');
                break;
            }

            query(
                "UPDATE registrations
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = NULL
                  WHERE registration_id = ?",
                [current_user_id(), $registrationId]
            );
            audit_log('approve', 'registration', $registrationId,
                      'Approved ' . $studentName . ' for ' . $activity['title']);
            notify(
                (int) $registration['user_id'], 'registration',
                'Registration approved',
                'You are confirmed for ' . $activity['title'] . '.',
                url('activities/view.php?id=' . $activityId)
            );
            flash('success', $studentName . ' has been approved.');
            break;

        case 'reject':
            $reason = post('review_remarks');
            if ($reason === '') {
                flash('error', 'Please give a reason so the student knows what to correct.');
                break;
            }

            query(
                "UPDATE registrations
                    SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
                  WHERE registration_id = ?",
                [current_user_id(), $reason, $registrationId]
            );
            audit_log('reject', 'registration', $registrationId,
                      'Rejected ' . $studentName . ' for ' . $activity['title']);
            notify(
                (int) $registration['user_id'], 'registration',
                'Registration not approved',
                $activity['title'] . ': ' . $reason,
                url('participation/my-activities.php')
            );
            flash('success', $studentName . ' has been rejected and notified.');
            break;

        case 'attendance':
            $attended = post('attended') === '1' ? 1 : 0;
            query('UPDATE registrations SET attended = ? WHERE registration_id = ?',
                  [$attended, $registrationId]);
            audit_log('update', 'registration', $registrationId,
                      ($attended ? 'Marked present: ' : 'Marked absent: ') . $studentName);
            flash('success', $studentName . ' marked as ' . ($attended ? 'present' : 'absent') . '.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    redirect('participation/participants.php?' . http_build_query(array_filter([
        'activity_id' => $activityId,
        'status'      => get('status'),
        'year_level'  => get('year_level'),
        'q'           => get('q'),
    ])));
}

// =====================================================================
// Filters (FR-4.8)
// =====================================================================
$statusFilter = get('status');
$yearFilter   = get('year_level');
$keyword      = get('q');

$conditions = ['r.activity_id = ?'];
$params     = [$activityId];

if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'withdrawn', 'completed'], true)) {
    $conditions[] = 'r.status = ?';
    $params[]     = $statusFilter;
}
if ($yearFilter !== '' && ctype_digit($yearFilter)) {
    $conditions[] = 'u.year_level_id = ?';
    $params[]     = (int) $yearFilter;
}
if ($keyword !== '') {
    $conditions[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_number LIKE ? OR r.team_name LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like);
}

$where = ' WHERE ' . implode(' AND ', $conditions);

$participants = fetch_all(
    "SELECT r.*, u.student_number, u.first_name, u.last_name, u.email, u.section,
            c.code AS course_code, y.label AS year_level_label
       FROM registrations r
       JOIN users u            ON u.user_id       = r.user_id
       LEFT JOIN courses c     ON c.course_id     = u.course_id
       LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
       $where
      ORDER BY FIELD(r.status,'pending','approved','completed','rejected','withdrawn'),
               u.last_name, u.first_name",
    $params
);

// Requirement progress per participant (FR-5.6 from the staff side).
foreach ($participants as $index => $participant) {
    $participants[$index]['progress'] = requirement_progress((int) $participant['registration_id']);
}

$counts = fetch_one(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'approved') AS approved,
        SUM(status = 'rejected') AS rejected,
        SUM(attended = 1)        AS attended
       FROM registrations WHERE activity_id = ?",
    [$activityId]
);

$yearLevels       = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');
$requirementCount = (int) fetch_value('SELECT COUNT(*) FROM activity_requirements WHERE activity_id = ?', [$activityId]);

$pageTitle = 'Participants: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Participants</h1>
        <p>
            <?= e($activity['title']) ?>
            &middot; <?= e($activity['category']) ?>
            <?php if ($activity['max_participants']): ?>
                &middot; <?= (int) $counts['approved'] ?> of <?= (int) $activity['max_participants'] ?> slots filled
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('requirements/manage.php?activity_id=' . $activityId) ?>">
            Requirements (<?= $requirementCount ?>)
        </a>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-value"><?= (int) $counts['total'] ?></div>
        <div class="stat-label">Total registered</div>
    </div>
    <div class="stat <?= stat_tone($counts['pending'], 'stat-gold') ?>">
        <div class="stat-value"><?= (int) $counts['pending'] ?></div>
        <div class="stat-label">Awaiting review</div>
    </div>
    <div class="stat <?= stat_tone($counts['approved'], 'stat-success') ?>">
        <div class="stat-value"><?= (int) $counts['approved'] ?></div>
        <div class="stat-label">Approved</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= (int) $counts['attended'] ?></div>
        <div class="stat-label">Marked present</div>
    </div>
</div>

<?php $hasFilters = $keyword !== '' || $statusFilter !== '' || $yearFilter !== ''; ?>

<form method="get" class="filter-bar">
    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Name, student number, team">
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected', 'withdrawn', 'completed'] as $option): ?>
                <option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                    <?= e(ucfirst($option)) ?>
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
                    <?= $yearFilter === (string) $level['year_level_id'] ? 'selected' : '' ?>>
                    <?= e($level['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?>
            <a class="btn btn-outline" href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($participants === []): ?>
    <?php if ((int) $counts['total'] === 0): ?>
        <div class="empty">
            <strong>No registrations yet</strong>
            No one has registered for this activity. Registrations appear here as students sign up.
        </div>
    <?php else: ?>
        <div class="empty">
            <strong>No participants match these filters</strong>
            Try a different search, status, or year level.
            <?php if ($hasFilters): ?>
                <div class="btn-row">
                    <a class="btn btn-outline" href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">Clear filters</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <section class="card card-flush">
        <div class="table-wrap">
            <table class="data table-stack" data-sortable>
                <thead>
                    <tr>
                        <th data-sort="text">Student</th><th data-sort="text">Course / Year</th><th data-sort="text">Team</th>
                        <th>Requirements</th><th data-sort="text">Status</th><th>Present</th><th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $participant): ?>
                    <?php
                    $progress = $participant['progress'];
                    $rowName  = full_name($participant, true);
                    ?>
                    <tr>
                        <td data-label="Student">
                            <strong><?= e($rowName) ?></strong>
                            <div class="hint">
                                <?= $participant['student_number'] ? e($participant['student_number']) : 'No student number' ?>
                            </div>
                        </td>
                        <td data-label="Course / Year">
                            <?= $participant['course_code'] ? e($participant['course_code']) : '<span class="muted">Not set</span>' ?>
                            <div class="hint">
                                <?= e($participant['year_level_label'] ?? '') ?><?= $participant['section'] ? ', ' . e($participant['section']) : '' ?>
                            </div>
                        </td>
                        <td data-label="Team"><?= $participant['team_name'] ? e($participant['team_name']) : '<span class="muted">None</span>' ?></td>
                        <td data-label="Requirements">
                            <?php if ($progress['total'] === 0): ?>
                                <span class="muted">None required</span>
                            <?php elseif ($progress['complete']): ?>
                                <span class="badge badge-success">Complete</span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <?= $progress['satisfied'] ?> of <?= $progress['total'] ?> done
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?= status_badge($participant['status'], 'registration') ?>
                            <?php if ($participant['review_remarks']): ?>
                                <div class="hint"><?= e($participant['review_remarks']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Present">
                            <?php if ($participant['status'] === 'approved'): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="attendance">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                    <input type="hidden" name="attended" value="<?= $participant['attended'] ? '0' : '1' ?>">
                                    <?php if ($participant['attended']): ?>
                                        <button type="submit" class="btn btn-sm btn-primary nowrap"
                                                aria-label="<?= e($rowName) ?> is marked present. Select to mark absent.">
                                            Present
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline nowrap"
                                                aria-label="Mark <?= e($rowName) ?> present">
                                            Mark present
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span class="muted">Not applicable</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <div class="btn-row">
                                <?php if ($participant['status'] !== 'approved'): ?>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm"
                                                aria-label="Approve <?= e($rowName) ?>">Approve</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($participant['status'] !== 'rejected'): ?>
                                    <button type="button" class="btn btn-outline btn-sm"
                                            aria-label="Reject <?= e($rowName) ?>"
                                            onclick="document.getElementById('reject-<?= (int) $participant['registration_id'] ?>').hidden = false; this.hidden = true;">
                                        Reject
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($participant['status'] !== 'rejected'): ?>
                                <form method="post" id="reject-<?= (int) $participant['registration_id'] ?>" hidden class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                    <div class="btn-row">
                                        <input type="text" name="review_remarks" required class="input-auto input-sm"
                                               aria-label="Reason for rejecting <?= e($rowName) ?>"
                                               placeholder="Reason (shown to the student)">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            Confirm rejection
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
