<?php
/**
 * CASMS — Deadline reminders (FR-5.7)
 *
 * Shows what is due inside the reminder window and lets the office send the
 * reminders by hand. The same work runs unattended from bin/send-reminders.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$errors = [];

if (is_post()) {
    csrf_verify();

    $action = post('action');

    if ($action === 'send') {
        $result = send_due_reminders(false);

        flash('success', $result['sent'] === 0
            ? 'Nothing new to send. Every reminder in the window has already gone out.'
            : $result['sent'] . ' reminder(s) sent'
              . (MAIL_NOTIFICATIONS_ENABLED ? ', ' . $result['emailed'] . ' by email' : '')
              . ($result['skipped'] > 0 ? '. ' . $result['skipped'] . ' had already been sent.' : '.'));

        redirect('admin/reminders.php');
    }

    if ($action === 'lead_days') {
        // Only an administrator changes the office-wide setting.
        if (!has_role('admin')) {
            http_response_code(403);
            abort_page(403, 'Only an administrator may change the reminder lead time.');
        }

        $days = post('reminder_lead_days');
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 30) {
            $errors[] = 'Lead time must be a whole number of days between 1 and 30.';
        } else {
            query(
                "UPDATE settings SET setting_value = ? WHERE setting_key = 'reminder_lead_days'",
                [(string) (int) $days]
            );
            audit_log('update', 'setting', null, 'Reminder lead time set to ' . (int) $days . ' day(s)');
            flash('success', 'Reminders will now go out ' . (int) $days . ' day(s) before a deadline.');
            redirect('admin/reminders.php');
        }
    }
}

// Dry run: what is due, and what has already been handled.
$preview  = send_due_reminders(true);
$leadDays = reminder_lead_days();

$pending = array_values(array_filter(
    $preview['items'], static fn(array $i): bool => $i['outcome'] === 'would send'
));
$already = array_values(array_filter(
    $preview['items'], static fn(array $i): bool => $i['outcome'] === 'already sent'
));

// Deadlines already missed — outside the reminder window, but the office
// still needs to see them.
$overdue = fetch_all(
    "SELECT u.first_name, u.last_name, u.student_number,
            a.title AS activity_title, ar.name AS requirement_name, ar.deadline_at
       FROM registrations r
       JOIN users u                  ON u.user_id      = r.user_id
       JOIN activities a             ON a.activity_id  = r.activity_id
       JOIN activity_requirements ar ON ar.activity_id = a.activity_id
       LEFT JOIN requirement_submissions rs
              ON rs.requirement_id  = ar.requirement_id
             AND rs.registration_id = r.registration_id
      WHERE ar.is_mandatory = 1
        AND ar.deadline_at IS NOT NULL
        AND ar.deadline_at < NOW()
        AND u.status = 'active'
        AND r.status IN ('pending','approved')
        AND a.status IN ('upcoming','ongoing')
        AND (rs.submission_id IS NULL OR rs.status = 'rejected')
      ORDER BY ar.deadline_at DESC"
);

$pageTitle = 'Deadline reminders';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Deadline reminders</h1>
        <p>Students are reminded <?= $leadDays ?> day(s) before a requirement falls due, and again on the day.</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('reports/index.php') ?>">Reports</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="stats stats-3">
    <div class="stat <?= stat_tone(count($pending), 'stat-gold') ?>">
        <div class="stat-value"><?= count($pending) ?></div>
        <div class="stat-label">Ready to send</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= count($already) ?></div>
        <div class="stat-label">Already reminded</div>
    </div>
    <div class="stat <?= $overdue !== [] ? 'stat-danger' : '' ?>">
        <div class="stat-value"><?= count($overdue) ?></div>
        <div class="stat-label">Past the deadline</div>
    </div>
</div>

<section class="card">
    <div class="card-head"><h2>Send reminders</h2></div>

    <?php if ($pending === []): ?>
        <div class="empty">
            <strong>Nothing to send</strong>
            No outstanding requirement falls due in the next <?= $leadDays ?> day(s),
            or everyone in the window has already been reminded.
        </div>
    <?php else: ?>
        <p class="card-intro">
            <?= count($pending) ?> student<?= count($pending) === 1 ? '' : 's' ?>
            will be notified in the system<?= MAIL_NOTIFICATIONS_ENABLED ? ' and by email' : '' ?>.
            Nobody is reminded twice for the same requirement.
        </p>

        <div class="table-wrap">
            <table class="data table-stack">
                <thead><tr><th>Student</th><th>Requirement</th><th>Activity</th><th>Deadline</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $item): ?>
                    <tr>
                        <td data-label="Student">
                            <div>
                                <strong><?= e(full_name($item, true)) ?></strong>
                                <div class="hint"><?= e($item['email']) ?></div>
                            </div>
                        </td>
                        <td data-label="Requirement"><?= e($item['requirement_name']) ?></td>
                        <td data-label="Activity"><?= e($item['activity_title']) ?></td>
                        <td data-label="Deadline" class="nowrap">
                            <div>
                                <?= e(format_date($item['deadline_at'])) ?>
                                <div class="hint">
                                    <?= (int) $item['days_left'] === 0
                                        ? 'Due today'
                                        : (int) $item['days_left'] . ' day(s) away' ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="form-actions mt-4"
              onsubmit="return confirm('Send <?= count($pending) ?> reminder(s) now?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <button type="submit" class="btn btn-gold">Send <?= count($pending) ?> reminder(s) now</button>
        </form>
    <?php endif; ?>

    <p class="hint mt-4">
        To send these automatically once a day, point Windows Task Scheduler at
        <code>bin\send-reminders.php</code>.
    </p>
</section>

<?php if (has_role('admin')): ?>
    <section class="card">
        <div class="card-head">
            <h2>Reminder lead time</h2>
            <p class="hint">How many days ahead of a deadline the first reminder goes out. Applies to the whole office.</p>
        </div>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lead_days">
            <div class="form-row">
                <label for="reminder_lead_days">Days before the deadline</label>
                <input type="number" id="reminder_lead_days" name="reminder_lead_days" class="input-auto"
                       min="1" max="30" value="<?= $leadDays ?>" required>
                <div class="hint">Between 1 and 30.</div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save lead time</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if ($overdue !== []): ?>
    <section class="card">
        <div class="card-head">
            <h2>Past the deadline (<?= count($overdue) ?>)</h2>
            <p class="hint">These are no longer reminded automatically because the deadline has passed.</p>
        </div>
        <div class="table-wrap">
            <table class="data table-stack">
                <thead><tr><th>Student</th><th>Requirement</th><th>Activity</th><th>Was due</th></tr></thead>
                <tbody>
                <?php foreach ($overdue as $row): ?>
                    <tr>
                        <td data-label="Student">
                            <div>
                                <strong><?= e(full_name($row, true)) ?></strong>
                                <div class="hint"><?= $row['student_number'] ? e($row['student_number']) : 'No student number' ?></div>
                            </div>
                        </td>
                        <td data-label="Requirement"><?= e($row['requirement_name']) ?></td>
                        <td data-label="Activity"><?= e($row['activity_title']) ?></td>
                        <td data-label="Was due" class="nowrap">
                            <div>
                                <?= e(format_date($row['deadline_at'])) ?>
                                <div><span class="badge badge-danger">Overdue</span></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
