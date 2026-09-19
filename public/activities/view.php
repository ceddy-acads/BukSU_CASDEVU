<?php
/**
 * CASMS — Activity detail (FR-2.2, FR-2.4)
 *
 * The "Register" action is stubbed here; Week 2 implements it.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

$activityId = get_id('id');
if ($activityId === null) {
    http_response_code(404);
    exit('404 — Activity not found.');
}

$activity = fetch_one(
    'SELECT a.*, c.name AS category, c.color_hex,
            v.name AS venue, v.location AS venue_location,
            u.first_name AS author_first, u.last_name AS author_last
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
       JOIN users u               ON u.user_id     = a.created_by
      WHERE a.activity_id = ?',
    [$activityId]
);

if ($activity === null) {
    http_response_code(404);
    exit('404 — Activity not found.');
}

// Drafts are visible only to the office and assigned coordinators.
if ($activity['status'] === 'draft' && !can_manage_activity($activityId)) {
    http_response_code(403);
    exit('403 — This activity is not yet published.');
}

$coordinators = fetch_all(
    'SELECT u.first_name, u.last_name, ac.assignment_role
       FROM activity_coordinators ac
       JOIN users u ON u.user_id = ac.user_id
      WHERE ac.activity_id = ?
      ORDER BY u.last_name',
    [$activityId]
);

$requirements = fetch_all(
    'SELECT name, description, is_mandatory, deadline_at
       FROM activity_requirements
      WHERE activity_id = ?
      ORDER BY sort_order, requirement_id',
    [$activityId]
);

$approvedCount = (int) fetch_value(
    "SELECT COUNT(*) FROM registrations WHERE activity_id = ? AND status = 'approved'",
    [$activityId]
);

// The signed-in student's own registration, if any.
$myRegistration = current_role() === 'student'
    ? fetch_one('SELECT * FROM registrations WHERE activity_id = ? AND user_id = ?',
                [$activityId, current_user_id()])
    : null;

$pageTitle = $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <?= status_badge($activity['status']) ?>
        <h1 style="margin-top:.4rem;"><?= e($activity['title']) ?></h1>
        <p><?= e($activity['category']) ?></p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Back to activities</a>
        <?php if (can_manage_activity($activityId)): ?>
            <a class="btn btn-primary" href="<?= url('activities/manage.php?id=' . $activityId) ?>">Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <div>
        <section class="card">
            <div class="card-head"><h2>About this activity</h2></div>
            <?php if ($activity['description']): ?>
                <p style="white-space:pre-line;margin:0;"><?= e($activity['description']) ?></p>
            <?php else: ?>
                <p class="hint" style="margin:0;">No description has been provided.</p>
            <?php endif; ?>
        </section>

        <?php if ($activity['eligibility']): ?>
            <section class="card">
                <div class="card-head"><h2>Who may join</h2></div>
                <p style="white-space:pre-line;margin:0;"><?= e($activity['eligibility']) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($activity['instructions']): ?>
            <section class="card">
                <div class="card-head"><h2>How to participate</h2></div>
                <p style="white-space:pre-line;margin:0;"><?= e($activity['instructions']) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($requirements !== []): ?>
            <section class="card">
                <div class="card-head"><h2>Requirements</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Requirement</th><th>Type</th><th>Deadline</th></tr></thead>
                        <tbody>
                        <?php foreach ($requirements as $requirement): ?>
                            <tr>
                                <td>
                                    <strong><?= e($requirement['name']) ?></strong>
                                    <?php if ($requirement['description']): ?>
                                        <div class="hint"><?= e($requirement['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $requirement['is_mandatory'] ? 'Required' : 'Optional' ?></td>
                                <td><?= e(format_date($requirement['deadline_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div>
        <section class="card">
            <div class="card-head"><h2>Details</h2></div>
            <dl class="detail-list">
                <dt>Starts</dt>  <dd><?= e(format_datetime($activity['start_at'])) ?></dd>
                <dt>Ends</dt>    <dd><?= e(format_datetime($activity['end_at'])) ?></dd>
                <dt>Venue</dt>
                <dd>
                    <?= $activity['venue'] ? e($activity['venue']) : '—' ?>
                    <?php if ($activity['venue_location']): ?>
                        <div class="hint"><?= e($activity['venue_location']) ?></div>
                    <?php endif; ?>
                </dd>
                <dt>Organizer</dt>
                <dd><?= e($activity['author_first'] . ' ' . $activity['author_last']) ?></dd>

                <?php if ($coordinators !== []): ?>
                    <dt>Coordinators</dt>
                    <dd>
                        <?php foreach ($coordinators as $coordinator): ?>
                            <div>
                                <?= e($coordinator['first_name'] . ' ' . $coordinator['last_name']) ?>
                                <?php if ($coordinator['assignment_role']): ?>
                                    <span class="hint">— <?= e($coordinator['assignment_role']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </dd>
                <?php endif; ?>

                <dt>Participants</dt>
                <dd>
                    <?= $approvedCount ?> approved
                    <?php if ($activity['max_participants']): ?>
                        of <?= (int) $activity['max_participants'] ?> slots
                    <?php endif; ?>
                </dd>

                <?php if ($activity['registration_closes_at']): ?>
                    <dt>Registration closes</dt>
                    <dd><?= e(format_datetime($activity['registration_closes_at'])) ?></dd>
                <?php endif; ?>
            </dl>
        </section>

        <?php if (current_role() === 'student'): ?>
            <section class="card">
                <div class="card-head"><h2>Your participation</h2></div>

                <?php if ($myRegistration): ?>
                    <p>Your registration is <?= status_badge($myRegistration['status']) ?></p>
                    <?php if ($myRegistration['review_remarks']): ?>
                        <p class="hint">Office remarks: <?= e($myRegistration['review_remarks']) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="hint">You have not registered for this activity.</p>
                    <button class="btn btn-gold btn-block is-disabled" disabled>
                        Register — available in Week 2
                    </button>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can_manage_activity($activityId)): ?>
            <section class="card">
                <div class="card-head"><h2>Manage</h2></div>
                <div class="btn-row">
                    <a class="btn btn-outline btn-sm is-disabled">Participants — Week 2</a>
                    <a class="btn btn-outline btn-sm is-disabled">Requirements — Week 2</a>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
