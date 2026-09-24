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
    abort_page(404, 'Activity not found.');
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
    abort_page(404, 'Activity not found.');
}

// Drafts are visible only to the office and assigned coordinators.
if ($activity['status'] === 'draft' && !can_manage_activity($activityId)) {
    http_response_code(403);
    abort_page(403, 'This activity is not yet published.');
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

// Student participation state, worked out before the header so the page
// head can offer the headline action.
$isStudent = current_role() === 'student';
if ($isStudent) {
    $student        = current_user();
    $eligibility    = can_register($activity, $student);
    $isActiveReg    = $myRegistration
                      && !in_array($myRegistration['status'], ['withdrawn'], true);
    $regProgress    = $isActiveReg
                      ? requirement_progress((int) $myRegistration['registration_id'])
                      : null;
}
$canManage   = can_manage_activity($activityId);
$canRegister = $isStudent && !$isActiveReg && $eligibility['ok'];

$pageTitle = $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/index.php') ?>">&larr; Back to activities</a>
        <div><?= status_badge($activity['status']) ?></div>
        <h1 class="mt-2"><?= e($activity['title']) ?></h1>
        <p><?= e($activity['category']) ?> &middot; <?= e(format_datetime($activity['start_at'])) ?></p>
    </div>
    <?php if ($canManage || $canRegister): ?>
        <div class="btn-row">
            <?php if ($canRegister): ?>
                <a class="btn btn-gold" href="#participation">Register</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a class="btn btn-outline" href="<?= url('activities/manage.php?id=' . $activityId) ?>">Edit activity</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="split">
    <div>
        <section class="card">
            <div class="card-head"><h2>About this activity</h2></div>
            <?php if ($activity['description']): ?>
                <p class="preline"><?= e($activity['description']) ?></p>
            <?php else: ?>
                <p class="muted">No description has been provided.</p>
            <?php endif; ?>
        </section>

        <?php if ($activity['eligibility'] || $eligibilityRules !== []): ?>
            <section class="card">
                <div class="card-head"><h2>Who may join</h2></div>

                <?php if ($activity['eligibility']): ?>
                    <p class="preline"><?= e($activity['eligibility']) ?></p>
                <?php endif; ?>

                <?php if ($eligibilityRules !== []): ?>
                    <p class="muted mb-2">Restricted to students matching any one of these:</p>
                    <ul class="bullets">
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
                <p class="preline"><?= e($activity['instructions']) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($requirements !== []): ?>
            <section class="card card-flush">
                <div class="card-head"><h2>Requirements</h2></div>
                <div class="table-wrap">
                    <table class="data table-stack">
                        <thead><tr><th>Requirement</th><th>Type</th><th>Deadline</th></tr></thead>
                        <tbody>
                        <?php foreach ($requirements as $requirement): ?>
                            <tr>
                                <td data-label="Requirement">
                                    <div>
                                        <strong><?= e($requirement['name']) ?></strong>
                                        <?php if ($requirement['description']): ?>
                                            <div class="hint"><?= e($requirement['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Type"><?= $requirement['is_mandatory'] ? 'Required' : 'Optional' ?></td>
                                <td data-label="Deadline">
                                    <?= $requirement['deadline_at'] ? e(format_date($requirement['deadline_at'])) : '<span class="muted">No deadline</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($isStudent): ?>
            <section class="card" id="participation">
                <div class="card-head"><h2>Your participation</h2></div>

                <?php if ($isActiveReg): ?>
                    <p><?= status_badge($myRegistration['status']) ?></p>
                    <p><?= e(registration_stage_label($myRegistration['status'])) ?></p>

                    <?php if ($myRegistration['review_remarks']): ?>
                        <div class="alert alert-<?= $myRegistration['status'] === 'rejected' ? 'error' : 'info' ?>">
                            <strong>Office remarks:</strong> <?= e($myRegistration['review_remarks']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($regProgress && $regProgress['total'] > 0): ?>
                        <?php $regPct = (int) round($regProgress['satisfied'] / $regProgress['total'] * 100); ?>
                        <div class="progress-label">
                            Requirements verified: <strong><?= $regProgress['satisfied'] ?></strong> of <?= $regProgress['total'] ?>
                        </div>
                        <div class="progress" role="img"
                             aria-label="<?= $regProgress['satisfied'] ?> of <?= $regProgress['total'] ?> requirements verified">
                            <span style="width: <?= $regPct ?>%"></span>
                        </div>
                        <a class="btn <?= $regProgress['complete'] ? 'btn-outline' : 'btn-gold' ?> btn-block"
                           href="<?= url('requirements/submit.php?activity_id=' . $activityId) ?>">
                            <?= $regProgress['complete'] ? 'View my documents' : 'Submit requirements' ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($myRegistration['status'] === 'pending'): ?>
                        <form method="post" action="<?= url('participation/register.php') ?>"
                              class="mt-2"
                              onsubmit="return confirm('Withdraw your registration for this activity?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                            <button type="submit" class="btn btn-outline btn-block">Withdraw registration</button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($eligibility['ok']): ?>
                    <p class="card-intro">You can join this activity. The office reviews every registration.</p>
                    <form method="post" action="<?= url('participation/register.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="activity_id" value="<?= $activityId ?>">

                        <div class="form-row">
                            <label for="team_name">Team or group name <span class="optional">(optional)</span></label>
                            <input type="text" id="team_name" name="team_name"
                                   placeholder="e.g. College of Technologies">
                        </div>
                        <div class="form-row">
                            <label for="remarks">Anything the office should know <span class="optional">(optional)</span></label>
                            <input type="text" id="remarks" name="remarks">
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Register for this activity</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning alert-flush">
                        <?= e($eligibility['reason']) ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($canManage): ?>
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
                        Verify documents<?= $pendingSubmissions > 0 ? ' (' . $pendingSubmissions . ' pending)' : '' ?>
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

        <section class="card">
            <div class="card-head"><h2>Details</h2></div>
            <dl class="detail-list">
                <div><dt>Starts</dt><dd><?= e(format_datetime($activity['start_at'])) ?></dd></div>
                <div><dt>Ends</dt><dd><?= e(format_datetime($activity['end_at'])) ?></dd></div>
                <div>
                    <dt>Venue</dt>
                    <dd>
                        <?= $activity['venue'] ? e($activity['venue']) : '<span class="muted">Not set</span>' ?>
                        <?php if ($activity['venue_location']): ?>
                            <div class="hint"><?= e($activity['venue_location']) ?></div>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Organizer</dt>
                    <dd><?= e($activity['author_first'] . ' ' . $activity['author_last']) ?></dd>
                </div>

                <?php if ($coordinators !== []): ?>
                    <div>
                        <dt>Coordinators</dt>
                        <dd>
                            <?php foreach ($coordinators as $coordinator): ?>
                                <div>
                                    <?= e($coordinator['first_name'] . ' ' . $coordinator['last_name']) ?>
                                    <?php if ($coordinator['assignment_role']): ?>
                                        <span class="hint">(<?= e($coordinator['assignment_role']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <div>
                    <dt>Participants</dt>
                    <dd>
                        <?= $approvedCount ?> approved
                        <?php if ($activity['max_participants']): ?>
                            of <?= (int) $activity['max_participants'] ?> places
                        <?php endif; ?>
                    </dd>
                </div>

                <?php if ($activity['registration_closes_at']): ?>
                    <div>
                        <dt>Registration closes</dt>
                        <dd><?= e(format_datetime($activity['registration_closes_at'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
