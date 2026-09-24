<?php
/**
 * CASMS — Who may join an activity (FR-2.7, FR-4.4)
 *
 * Rules act as a whitelist: with no rows, the activity is open to everyone.
 * Enforcement lives in meets_eligibility_rules() (includes/participation.php);
 * this screen only manages the rows it reads, so the rule and its enforcement
 * cannot drift apart.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('activity_id') ?? (int) post('activity_id');
if ($activityId <= 0) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

// Staff and admin unrestricted; a coordinator only for their own activity.
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

$yearLevels = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');
$courses    = fetch_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');

$errors = [];

if (is_post()) {
    csrf_verify();

    $action = post('action');

    // ------------------------------------------------------------- Add rule
    if ($action === 'add') {
        $yearIn   = post('year_level_id');
        $courseIn = post('course_id');

        $yearLevelId = $yearIn   !== '' && ctype_digit($yearIn)   ? (int) $yearIn   : null;
        $courseId    = $courseIn !== '' && ctype_digit($courseIn) ? (int) $courseIn : null;

        // A rule with neither column set matches everyone, which is what "no
        // rules at all" already means — it would only be confusing.
        if ($yearLevelId === null && $courseId === null) {
            $errors[] = 'Choose a year level, a course, or both. '
                      . 'To open the activity to everyone, delete all rules instead.';
        }

        // Validate the references exist — a crafted POST cannot invent IDs.
        if ($yearLevelId !== null
            && !fetch_value('SELECT 1 FROM year_levels WHERE year_level_id = ?', [$yearLevelId])) {
            $errors[] = 'That year level does not exist.';
        }
        if ($courseId !== null
            && !fetch_value('SELECT 1 FROM courses WHERE course_id = ?', [$courseId])) {
            $errors[] = 'That course does not exist.';
        }

        // The schema has no unique key here (NULLs would defeat it anyway), so
        // duplicates are prevented in application code.
        if ($errors === []) {
            $duplicate = fetch_value(
                'SELECT 1 FROM activity_eligibility_rules
                  WHERE activity_id = ?
                    AND year_level_id <=> ?
                    AND course_id <=> ?',
                [$activityId, $yearLevelId, $courseId]
            );
            if ($duplicate) {
                $errors[] = 'That exact rule already exists for this activity.';
            }
        }

        // A broader rule already admits everyone the narrower one would, so
        // adding the narrower one changes nothing and misleads the reader.
        if ($errors === [] && $yearLevelId !== null && $courseId !== null) {
            $broader = fetch_value(
                'SELECT 1 FROM activity_eligibility_rules
                  WHERE activity_id = ?
                    AND ((year_level_id <=> ? AND course_id IS NULL)
                      OR (course_id <=> ? AND year_level_id IS NULL))',
                [$activityId, $yearLevelId, $courseId]
            );
            if ($broader) {
                $errors[] = 'A broader rule already covers this combination, so it would have no effect.';
            }
        }

        if ($errors === []) {
            query(
                'INSERT INTO activity_eligibility_rules (activity_id, year_level_id, course_id)
                 VALUES (?, ?, ?)',
                [$activityId, $yearLevelId, $courseId]
            );
            $ruleId = (int) db()->lastInsertId();

            audit_log('create', 'eligibility_rule', $ruleId,
                      'Added eligibility rule to ' . $activity['title']);

            flash('success', 'Eligibility rule added. Only students matching a rule can now register.');
            redirect('activities/eligibility.php?activity_id=' . $activityId);
        }
    }

    // ---------------------------------------------------------- Delete rule
    if ($action === 'delete') {
        $ruleId = (int) post('rule_id');

        // Scope the lookup to this activity so a rule cannot be deleted from
        // another activity by guessing its id.
        $rule = fetch_one(
            'SELECT * FROM activity_eligibility_rules WHERE rule_id = ? AND activity_id = ?',
            [$ruleId, $activityId]
        );
        if ($rule === null) {
            flash('error', 'That rule no longer exists.');
            redirect('activities/eligibility.php?activity_id=' . $activityId);
        }

        query('DELETE FROM activity_eligibility_rules WHERE rule_id = ?', [$ruleId]);
        audit_log('delete', 'eligibility_rule', $ruleId,
                  'Removed eligibility rule from ' . $activity['title']);

        $remaining = (int) fetch_value(
            'SELECT COUNT(*) FROM activity_eligibility_rules WHERE activity_id = ?',
            [$activityId]
        );

        flash('success', $remaining === 0
            ? 'Rule removed. With no rules left, the activity is open to all students.'
            : 'Rule removed.');
        redirect('activities/eligibility.php?activity_id=' . $activityId);
    }
}

$rules = fetch_all(
    'SELECT r.*, y.label AS year_label, c.code AS course_code, c.name AS course_name
       FROM activity_eligibility_rules r
       LEFT JOIN year_levels y ON y.year_level_id = r.year_level_id
       LEFT JOIN courses c     ON c.course_id     = r.course_id
      WHERE r.activity_id = ?
      ORDER BY y.sort_order, c.code',
    [$activityId]
);

// How many active students each rule set currently admits — makes the effect
// of a rule concrete rather than theoretical.
$eligibleCount = $rules === []
    ? (int) fetch_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
                          WHERE r.name = 'student' AND u.status = 'active'")
    : (int) fetch_value(
        "SELECT COUNT(DISTINCT u.user_id)
           FROM users u
           JOIN roles ro ON ro.role_id = u.role_id
           JOIN activity_eligibility_rules r ON r.activity_id = ?
          WHERE ro.name = 'student' AND u.status = 'active'
            AND (r.year_level_id IS NULL OR r.year_level_id = u.year_level_id)
            AND (r.course_id     IS NULL OR r.course_id     = u.course_id)",
        [$activityId]
    );

$pageTitle = 'Eligibility: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Who may join</h1>
        <p><?= e($activity['title']) ?> &middot; <?= e($activity['category']) ?></p>
    </div>
    <?php if (is_office_staff()): ?>
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/coordinators.php?activity_id=' . $activityId) ?>">Coordinators</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($rules === []): ?>
    <div class="alert alert-info">
        <strong>No restrictions.</strong> This activity is open to all
        <?= $eligibleCount ?> active student<?= $eligibleCount === 1 ? '' : 's' ?>.
        Adding a rule restricts it to students who match at least one rule.
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <strong>Restricted.</strong> Only students matching one of the
        <?= count($rules) ?> rule<?= count($rules) === 1 ? '' : 's' ?> below may register,
        currently <?= $eligibleCount ?> student<?= $eligibleCount === 1 ? '' : 's' ?>.
    </div>
<?php endif; ?>

<div class="split">
    <section class="card<?= $rules !== [] ? ' card-flush' : '' ?>">
        <div class="card-head"><h2>Current rules (<?= count($rules) ?>)</h2></div>

        <?php if ($rules === []): ?>
            <div class="empty">
                <strong>No rules set</strong>
                Every active student may register for this activity.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data table-stack">
                    <thead><tr><th>Year level</th><th>Course</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td data-label="Year level">
                                <?= $rule['year_label']
                                    ? e($rule['year_label'])
                                    : '<span class="muted">Any year level</span>' ?>
                            </td>
                            <td data-label="Course">
                                <?php if ($rule['course_code']): ?>
                                    <div>
                                        <strong><?= e($rule['course_code']) ?></strong>
                                        <div class="hint"><?= e($rule['course_name']) ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Any course</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <form method="post" class="inline-form"
                                      onsubmit="return confirm('Remove this eligibility rule?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="activity_id" value="<?= $activityId ?>">
                                    <input type="hidden" name="rule_id" value="<?= (int) $rule['rule_id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Remove</button>
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
        <div class="card-head"><h2>Add a rule</h2></div>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="activity_id" value="<?= $activityId ?>">

            <div class="form-row">
                <label for="year_level_id">Year level</label>
                <select id="year_level_id" name="year_level_id">
                    <option value="">Any year level</option>
                    <?php foreach ($yearLevels as $level): ?>
                        <option value="<?= (int) $level['year_level_id'] ?>">
                            <?= e($level['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="course_id">Course</label>
                <select id="course_id" name="course_id">
                    <option value="">Any course</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int) $course['course_id'] ?>">
                            <?= e($course['code']) ?>: <?= e($course['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <p class="hint mb-4">
                Leaving one field as "Any" makes the rule broader. Setting both
                restricts it to that exact combination. At least one must be chosen.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add rule</button>
            </div>
        </form>
    </section>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
