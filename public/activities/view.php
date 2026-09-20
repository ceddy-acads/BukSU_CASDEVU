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

// Eligibility whitelist. No rows means the activity is open to everyone.
$eligibilityRules = fetch_all(
    'SELECT y.label AS year_label, c.code AS course_code, c.name AS course_name
       FROM activity_eligibility_rules r
       LEFT JOIN year_levels y ON y.year_level_id = r.year_level_id
       LEFT JOIN courses c     ON c.course_id     = r.course_id
      WHERE r.activity_id = ?
      ORDER BY y.sort_order, c.code',
    [$activityId]
);
$eligibilityRuleCount = count($eligibilityRules);

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

        <?php if ($activity['eligibility'] || $eligibilityRules !== []): ?>
            <section class="card">
                <div class="card-head"><h2>Who may join</h2></div>

                <?php if ($activity['eligibility']): ?>
                    <p style="white-space:pre-line;margin:0 0 .75rem;"><?= e($activity['eligibility']) ?></p>
                <?php endif; ?>

                <?php if ($eligibilityRules !== []): ?>
                    <p class="hint" style="margin:0 0 .4rem;">
                        Restricted to students matching any one of these:
                    </p>
                    <ul style="margin:0;padding-left:1.15rem;font-size:.9rem;">
                        <?php foreach ($eligibilityRules as $rule): ?>
                            <li>
                                <?= $rule['year_label'] ? e($rule['year_label']) : 'Any year level' ?>
                                &middot;
                                <?= $rule['course_code'] ? e($rule['course_code']) : 'any course' ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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
            <?php
            $student        = current_user();
            $eligibility    = can_register($activity, $student);
            $isActiveReg    = $myRegistration
                              && !in_array($myRegistration['status'], ['withdrawn'], true);
            $regProgress    = $isActiveReg
                              ? requirement_progress((int) $myRegistration['registration_id'])
                              : null;
            ?>
            <section class="card">
                <div class="card-head"><h2>Your participation</h2></div>

                <?php if ($isActiveReg): ?>
                    <p style="margin-top:0;">
                        <?= status_badge($myRegistration['status']) ?>
                    </p>
                    <p style="font-size:.9rem;">
                        <?= e(registration_stage_label($myRegistration['status'])) ?>
                    </p>

                    <?php if ($myRegistration['review_remarks']): ?>
                        <div class="alert alert-<?= $myRegistration['status'] === 'rejected' ? 'error' : 'info' ?>">
                            <strong>Office remarks:</strong> <?= e($myRegistration['review_remarks']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($regProgress && $regProgress['total'] > 0): ?>
                        <p class="hint">
                            Requirements verified: <?= $regProgress['satisfied'] ?> of <?= $regProgress['total'] ?>
                        </p>
                        <a class="btn <?= $regProgress['complete'] ? 'btn-outline' : 'btn-gold' ?> btn-block"
                           href="<?= url('requirements/submit.php?activity_id=' . $activityId) ?>">
                            <?= $regProgress['complete'] ? 'View my documents' : 'Submit requirements' ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($myRegistration['status'] === 'pending'): ?>
                        <form method="post" action="<?= url('participation/register.php') ?>"
                              style="margin-top:.6rem;"
                              onsubmit="return confirm('Withdraw your registration for this activity?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                            <button type="submit" class="btn btn-outline btn-block">Withdraw registration</button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($eligibility['ok']): ?>
                    <form method="post" action="<?= url('participation/register.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">

                        <div class="form-row">
                            <label for="team_name">Team or group name (optional)</label>
                            <input type="text" id="team_name" name="team_name"
                                   placeholder="e.g. College of Technologies">
                        </div>
                        <div class="form-row">
                            <label for="remarks">Anything the office should know (optional)</label>
                            <input type="text" id="remarks" name="remarks">
                        </div>

                        <button type="submit" class="btn btn-gold btn-block">Register for this activity</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning" style="margin:0;">
                        <?= e($eligibility['reason']) ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can_manage_activity($activityId)): ?>
            <?php
            $pendingRegistrations = (int) fetch_value(
                "SELECT COUNT(*) FROM registrations WHERE activity_id = ? AND status = 'pending'",
                [$activityId]
            );
            $pendingSubmissions = (int) fetch_value(
                "SELECT COUNT(*) FROM requirement_submissions rs
                   JOIN activity_requirements ar ON ar.requirement_id = rs.requirement_id
                  WHERE ar.activity_id = ? AND rs.status = 'pending'",
                [$activityId]
            );
            ?>
            <section class="card">
                <div class="card-head"><h2>Manage</h2></div>
                <div class="btn-row">
                    <a class="btn btn-outline btn-sm"
                       href="<?= url('participation/participants.php?activity_id=' . $activityId) ?>">
                        Participants<?= $pendingRegistrations > 0 ? ' (' . $pendingRegistrations . ' to review)' : '' ?>
                    </a>
                    <a class="btn btn-outline btn-sm"
                       href="<?= url('requirements/manage.php?activity_id=' . $activityId) ?>">
                        Requirements (<?= count($requirements) ?>)
                    </a>
                    <a class="btn btn-outline btn-sm"
                       href="<?= url('requirements/verify.php?activity_id=' . $activityId) ?>">
                        Verify<?= $pendingSubmissions > 0 ? ' (' . $pendingSubmissions . ')' : '' ?>
                    </a>
                    <a class="btn btn-outline btn-sm"
                       href="<?= url('activities/eligibility.php?activity_id=' . $activityId) ?>">
                        Eligibility (<?= $eligibilityRuleCount ?>)
                    </a>
                    <?php if (is_office_staff()): ?>
                        <a class="btn btn-outline btn-sm"
                           href="<?= url('activities/coordinators.php?activity_id=' . $activityId) ?>">
                            Coordinators (<?= count($coordinators) ?>)
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
