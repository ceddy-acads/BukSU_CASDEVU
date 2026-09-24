<?php
/**
 * CASMS — Assign coordinators to an activity (FR-7.2)
 *
 * Office staff and administrators only. A coordinator cannot manage their own
 * assignments, which would let them grant themselves access to any activity.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$activityId = get_id('activity_id') ?? (int) post('activity_id');
if ($activityId <= 0) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

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

$errors = [];

if (is_post()) {
    csrf_verify();

    $action = post('action');

    // ---------------------------------------------------------------- Assign
    if ($action === 'assign') {
        $userIdIn       = post('user_id');
        $assignmentRole = post('assignment_role');

        if ($userIdIn === '' || !ctype_digit($userIdIn)) {
            $errors[] = 'Please choose a person to assign.';
        }

        if ($errors === []) {
            $userId = (int) $userIdIn;

            // Server-side validation: the account must exist, be active, and
            // hold a role that is allowed to coordinate. A crafted POST cannot
            // assign a student or a deactivated account.
            $candidate = fetch_one(
                "SELECT u.user_id, u.first_name, u.last_name, u.status, r.name AS role_name
                   FROM users u JOIN roles r ON r.role_id = u.role_id
                  WHERE u.user_id = ?",
                [$userId]
            );

            if ($candidate === null) {
                $errors[] = 'That user account does not exist.';
            } elseif ($candidate['status'] !== 'active') {
                $errors[] = full_name($candidate) . ' is not an active account.';
            } elseif (!in_array($candidate['role_name'], ['coordinator', 'staff', 'admin'], true)) {
                $errors[] = 'Only coordinator, staff, or administrator accounts can be assigned to an activity.';
            } else {
                // The composite primary key already forbids duplicates; check
                // first so the user sees a message instead of a database error.
                $already = fetch_value(
                    'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
                    [$activityId, $userId]
                );

                if ($already) {
                    $errors[] = full_name($candidate) . ' is already assigned to this activity.';
                } else {
                    query(
                        'INSERT INTO activity_coordinators (activity_id, user_id, assignment_role, assigned_by)
                         VALUES (?, ?, ?, ?)',
                        [
                            $activityId, $userId,
                            $assignmentRole !== '' ? $assignmentRole : null,
                            current_user_id(),
                        ]
                    );

                    audit_log('create', 'activity_coordinator', $activityId,
                              'Assigned ' . full_name($candidate) . ' to ' . $activity['title']);

                    notify(
                        $userId, 'activity', 'You have been assigned as coordinator',
                        $activity['title'] . ($assignmentRole !== '' ? ' (' . $assignmentRole . ')' : ''),
                        url('activities/view.php?id=' . $activityId)
                    );

                    flash('success', full_name($candidate) . ' is now a coordinator for this activity.');
                    redirect('activities/coordinators.php?activity_id=' . $activityId);
                }
            }
        }
    }

    // ---------------------------------------------------------------- Remove
    if ($action === 'remove') {
        $userId = (int) post('user_id');

        $assignment = fetch_one(
            'SELECT ac.*, u.first_name, u.last_name
               FROM activity_coordinators ac
               JOIN users u ON u.user_id = ac.user_id
              WHERE ac.activity_id = ? AND ac.user_id = ?',
            [$activityId, $userId]
        );

        if ($assignment === null) {
            flash('error', 'That assignment no longer exists.');
            redirect('activities/coordinators.php?activity_id=' . $activityId);
        }

        query(
            'DELETE FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
            [$activityId, $userId]
        );

        audit_log('delete', 'activity_coordinator', $activityId,
                  'Removed ' . full_name($assignment) . ' from ' . $activity['title']);

        notify(
            $userId, 'activity', 'Coordinator assignment removed',
            'You are no longer a coordinator for ' . $activity['title'] . '.',
            url('activities/view.php?id=' . $activityId)
        );

        flash('success', full_name($assignment) . ' has been removed from this activity.');
        redirect('activities/coordinators.php?activity_id=' . $activityId);
    }
}

// ------------------------------------------------------------------- Listing
$assigned = fetch_all(
    'SELECT ac.*, u.first_name, u.last_name, u.email, r.name AS role_name,
            b.first_name AS by_first, b.last_name AS by_last
       FROM activity_coordinators ac
       JOIN users u        ON u.user_id = ac.user_id
       JOIN roles r        ON r.role_id = u.role_id
       LEFT JOIN users b   ON b.user_id = ac.assigned_by
      WHERE ac.activity_id = ?
      ORDER BY u.last_name, u.first_name',
    [$activityId]
);

// Candidates: active staff-side accounts not already assigned.
$candidates = fetch_all(
    "SELECT u.user_id, u.first_name, u.last_name, u.email, r.name AS role_name
       FROM users u
       JOIN roles r ON r.role_id = u.role_id
      WHERE u.status = 'active'
        AND r.name IN ('coordinator','staff','admin')
        AND u.user_id NOT IN (SELECT user_id FROM activity_coordinators WHERE activity_id = ?)
      ORDER BY r.name, u.last_name, u.first_name",
    [$activityId]
);

$pageTitle = 'Coordinators: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Coordinators</h1>
        <p><?= e($activity['title']) ?> &middot; <?= e($activity['category']) ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('activities/eligibility.php?activity_id=' . $activityId) ?>">Eligibility</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    An assigned coordinator can manage this activity's participants, requirements,
    and announcements. They have no access to activities they are not assigned to.
</div>

<div class="split">
    <section class="card<?= $assigned !== [] ? ' card-flush' : '' ?>">
        <div class="card-head"><h2>Assigned (<?= count($assigned) ?>)</h2></div>

        <?php if ($assigned === []): ?>
            <div class="empty">
                <strong>No coordinators assigned</strong>
                Only office staff and administrators can manage this activity until someone is assigned.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data table-stack">
                    <thead><tr><th>Person</th><th>Role here</th><th>Assigned</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($assigned as $person): ?>
                        <tr>
                            <td data-label="Person">
                                <div>
                                    <strong><?= e(full_name($person, true)) ?></strong>
                                    <div class="hint"><?= e($person['email']) ?></div>
                                    <div class="hint"><?= e(ucfirst($person['role_name'])) ?> account</div>
                                </div>
                            </td>
                            <td data-label="Role here">
                                <?php if (($person['assignment_role'] ?? '') !== ''): ?>
                                    <?= e($person['assignment_role']) ?>
                                <?php else: ?>
                                    <span class="muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Assigned">
                                <div>
                                    <span class="nowrap"><?= e(format_date($person['assigned_at'])) ?></span>
                                    <?php if ($person['by_first']): ?>
                                        <div class="hint">by <?= e($person['by_first'] . ' ' . $person['by_last']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="actions">
                                <form method="post" class="inline-form"
                                      onsubmit="return confirm('Remove this coordinator? They will lose access to this activity.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $person['user_id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"
                                            aria-label="Remove <?= e(full_name($person, true)) ?>">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Assign a coordinator</h2></div>

        <?php if ($candidates === []): ?>
            <div class="empty">
                <strong>No one left to assign</strong>
                Every eligible account is already assigned to this activity.
            </div>
        <?php else: ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="activity_id" value="<?= $activityId ?>">

                <div class="form-row">
                    <label for="user_id">Person <span class="req">*</span></label>
                    <select id="user_id" name="user_id" required>
                        <option value="">Select a person</option>
                        <?php foreach ($candidates as $candidate): ?>
                            <option value="<?= (int) $candidate['user_id'] ?>">
                                <?= e(full_name($candidate, true)) ?>
                                (<?= e(ucfirst($candidate['role_name'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Only active coordinator, staff, and administrator accounts appear here.</p>
                </div>

                <div class="form-row">
                    <label for="assignment_role">Role in this activity <span class="optional">(optional)</span></label>
                    <input type="text" id="assignment_role" name="assignment_role"
                           placeholder="e.g. Head Coordinator, Trainer">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Assign coordinator</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
