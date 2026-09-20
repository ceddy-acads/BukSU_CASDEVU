<?php
/**
 * CASMS — Requirement deadline reminders (FR-5.7)
 *
 * A student is reminded once per requirement, a configurable number of days
 * before its deadline, and once more on the day it falls due. Only students
 * who still owe something are contacted.
 *
 * Triggered two ways, both calling send_due_reminders():
 *   - bin/send-reminders.php, for Windows Task Scheduler or cron
 *   - a "Send now" button on the admin reminders screen
 */

declare(strict_types=1);

/** Days before a deadline to send the first reminder (from the settings table). */
function reminder_lead_days(): int
{
    $value = fetch_value("SELECT setting_value FROM settings WHERE setting_key = 'reminder_lead_days'");
    $days  = (int) ($value ?? 3);

    return max(1, min(30, $days));
}

/**
 * Outstanding mandatory requirements whose deadline falls inside the reminder
 * window, for students whose registration is still live.
 *
 * `stage` is 'due_soon' or 'due_today', so the two reminders for the same
 * requirement are distinguishable and neither is sent twice.
 *
 * @return array<int, array<string, mixed>>
 */
function due_reminders(?int $leadDays = null): array
{
    $leadDays = $leadDays ?? reminder_lead_days();

    return fetch_all(
        "SELECT r.registration_id, r.user_id,
                u.email, u.first_name, u.last_name,
                a.activity_id, a.title AS activity_title,
                ar.requirement_id, ar.name AS requirement_name, ar.deadline_at,
                DATEDIFF(ar.deadline_at, CURDATE()) AS days_left,
                CASE WHEN DATEDIFF(ar.deadline_at, CURDATE()) = 0
                     THEN 'due_today' ELSE 'due_soon' END AS stage
           FROM registrations r
           JOIN users u                  ON u.user_id     = r.user_id
           JOIN activities a             ON a.activity_id = r.activity_id
           JOIN activity_requirements ar ON ar.activity_id = a.activity_id
           LEFT JOIN requirement_submissions rs
                  ON rs.requirement_id  = ar.requirement_id
                 AND rs.registration_id = r.registration_id
          WHERE ar.is_mandatory = 1
            AND ar.deadline_at IS NOT NULL
            AND u.status = 'active'
            AND r.status IN ('pending', 'approved')
            AND a.status IN ('upcoming', 'ongoing')
            -- nothing submitted, or it came back rejected
            AND (rs.submission_id IS NULL OR rs.status = 'rejected')
            -- inside the window, and not already past
            AND DATEDIFF(ar.deadline_at, CURDATE()) BETWEEN 0 AND ?
          ORDER BY ar.deadline_at, u.last_name",
        [$leadDays]
    );
}

/**
 * Has this exact reminder already gone out?
 *
 * There is no reminder-log table, so the notification itself is the record:
 * a 'deadline' notification whose link points at this activity and whose
 * message names this requirement and stage means it has been sent.
 */
function reminder_already_sent(int $userId, int $requirementId, string $stage): bool
{
    return (bool) fetch_value(
        "SELECT 1 FROM notifications
          WHERE user_id = ?
            AND type = 'deadline'
            AND message LIKE ?
          LIMIT 1",
        [$userId, '%[req:' . $requirementId . ':' . $stage . ']%']
    );
}

/**
 * Send every reminder that is due and not already sent.
 *
 * @param  bool $dryRun  Report what would be sent without sending it.
 * @return array{due: int, sent: int, skipped: int, emailed: int,
 *               items: array<int, array<string, mixed>>}
 */
function send_due_reminders(bool $dryRun = false): array
{
    $rows    = due_reminders();
    $sent    = 0;
    $skipped = 0;
    $emailed = 0;
    $items   = [];

    foreach ($rows as $row) {
        $userId        = (int) $row['user_id'];
        $requirementId = (int) $row['requirement_id'];
        $stage         = (string) $row['stage'];
        $daysLeft      = (int) $row['days_left'];

        if (reminder_already_sent($userId, $requirementId, $stage)) {
            $skipped++;
            $items[] = $row + ['outcome' => 'already sent'];
            continue;
        }

        if ($dryRun) {
            $items[] = $row + ['outcome' => 'would send'];
            continue;
        }

        $title = $stage === 'due_today'
            ? 'Requirement due today'
            : 'Requirement due in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');

        // The bracketed marker is how reminder_already_sent() recognises this
        // reminder later. It is stripped before display.
        $message = $row['requirement_name'] . ' for ' . $row['activity_title']
            . ($stage === 'due_today'
                ? ' is due today.'
                : ' is due on ' . format_date($row['deadline_at']) . '.')
            . ' [req:' . $requirementId . ':' . $stage . ']';

        notify(
            $userId, 'deadline', $title, $message,
            url('requirements/submit.php?activity_id=' . (int) $row['activity_id'])
        );
        $sent++;

        if (MAIL_NOTIFICATIONS_ENABLED) {
            $ok = send_mail(
                (string) $row['email'],
                $title . ' — ' . $row['activity_title'],
                'Hi ' . $row['first_name'] . ',' . PHP_EOL . PHP_EOL
                . 'This is a reminder that the following requirement is still outstanding:' . PHP_EOL . PHP_EOL
                . '  Requirement: ' . $row['requirement_name'] . PHP_EOL
                . '  Activity:    ' . $row['activity_title'] . PHP_EOL
                . '  Deadline:    ' . format_datetime($row['deadline_at'])
                . ($stage === 'due_today' ? ' (today)' : ' (' . $daysLeft . ' day(s) away)') . PHP_EOL . PHP_EOL
                . 'Submit it here: '
                . absolute_url(url('requirements/submit.php?activity_id=' . (int) $row['activity_id']))
                . PHP_EOL . PHP_EOL
                . '— ' . OFFICE_NAME . PHP_EOL . UNIVERSITY . PHP_EOL . PHP_EOL
                . 'This is an automated message. Please do not reply.'
            );
            if ($ok) {
                $emailed++;
            }
        }

        $items[] = $row + ['outcome' => 'sent'];
    }

    if (!$dryRun && $sent > 0) {
        audit_log('notify', 'reminder', null,
                  'Sent ' . $sent . ' deadline reminder(s), ' . $emailed . ' by email');
    }

    return [
        'due'     => count($rows),
        'sent'    => $sent,
        'skipped' => $skipped,
        'emailed' => $emailed,
        'items'   => $items,
    ];
}

/** Remove the internal marker before a reminder is shown to anyone. */
function strip_reminder_marker(string $message): string
{
    return trim(preg_replace('/\s*\[req:\d+:[a-z_]+\]\s*/', '', $message) ?? $message);
}
