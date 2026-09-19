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
    exit('404 — Activity not found.');
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
    exit('404 — Activity not found.');
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

$pageTitle = 'Participants — ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Participants</h1>
        <p>
            <a href="<?= url('activities/view.php?id=' . $activityId) ?>"><?= e($activity['title']) ?></a>
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
        <a class="btn btn-outline" href="<?= url('activities/view.php?id=' . $activityId) ?>">Back to activity</a>
    </div>
</div>

<div class="grid grid-4" style="margin-bottom:1.5rem;">
    <div class="stat">
        <div class="stat-value"><?= (int) $counts['total'] ?></div>
        <div class="stat-label">Total registered</div>
    </div>
    <div class="stat stat-gold">
        <div class="stat-value"><?= (int) $counts['pending'] ?></div>
        <div class="stat-label">Awaiting review</div>
    </div>
    <div class="stat stat-success">
        <div class="stat-value"><?= (int) $counts['approved'] ?></div>
        <div class="stat-label">Approved</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= (int) $counts['attended'] ?></div>
        <div class="stat-label">Marked present</div>
    </div>
</div>

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
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
    <?php if ($keyword !== '' || $statusFilter !== '' || $yearFilter !== ''): ?>
        <div class="form-row" style="flex:0 0 auto;">
            <a class="btn btn-outline" href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">Clear</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($participants === []): ?>
    <div class="empty">
        <strong>No participants match</strong>
        <?= (int) $counts['total'] === 0
            ? 'No one has registered for this activity yet.'
            : 'Try different filters.' ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Student</th><th>Course / Year</th><th>Team</th>
                        <th>Requirements</th><th>Status</th><th>Present</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $participant): ?>
                    <?php $progress = $participant['progress']; ?>
                    <tr>
                        <td>
                            <strong><?= e(full_name($participant, true)) ?></strong>
                            <div class="hint"><?= e($participant['student_number'] ?? '—') ?></div>
                        </td>
                        <td>
                            <?= e($participant['course_code'] ?? '—') ?>
                            <div class="hint">
                                <?= e($participant['year_level_label'] ?? '') ?>
                                <?= $participant['section'] ? ' — ' . e($participant['section']) : '' ?>
                            </div>
                        </td>
                        <td><?= e($participant['team_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($progress['total'] === 0): ?>
                                <span class="hint">None required</span>
                            <?php elseif ($progress['complete']): ?>
                                <span class="badge badge-success">Complete</span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <?= $progress['satisfied'] ?>/<?= $progress['total'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= status_badge($participant['status']) ?>
                            <?php if ($participant['review_remarks']): ?>
                                <div class="hint"><?= e($participant['review_remarks']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($participant['status'] === 'approved'): ?>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="attendance">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                    <input type="hidden" name="attended" value="<?= $participant['attended'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm <?= $participant['attended'] ? 'btn-gold' : 'btn-outline' ?>">
                                        <?= $participant['attended'] ? 'Present' : 'Mark present' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <div class="btn-row">
                                <?php if ($participant['status'] !== 'approved'): ?>
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($participant['status'] !== 'rejected'): ?>
                                    <button type="button" class="btn btn-outline btn-sm"
                                            onclick="document.getElementById('reject-<?= (int) $participant['registration_id'] ?>').hidden = false; this.hidden = true;">
                                        Reject
                                    </button>
                                    <form method="post" id="reject-<?= (int) $participant['registration_id'] ?>" hidden
                                          style="margin-top:.35rem;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $participant['registration_id'] ?>">
                                        <input type="text" name="review_remarks" required
                                               placeholder="Reason (shown to the student)" style="font-size:.8rem;">
                                        <button type="submit" class="btn btn-danger btn-sm" style="margin-top:.3rem;">
                                            Confirm rejection
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
