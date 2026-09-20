<?php
/**
 * CASMS — Deadline reminder runner (FR-5.7)
 *
 * Command line only. Point Windows Task Scheduler (or cron) at it once a day:
 *
 *   C:\xampp\php\php.exe "C:\path\to\CASMS\bin\send-reminders.php"
 *
 * Options:
 *   --dry-run   Report what would be sent without sending anything.
 *   --quiet     Print only the summary line.
 *
 * Reminders are also sendable from Admin > Reminders in the browser.
 */

declare(strict_types=1);

// Refuse to run over HTTP: this has no session and therefore no authorization.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 — This script runs from the command line only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$quiet  = in_array('--quiet', $argv, true);

$startedAt = date('Y-m-d H:i:s');
$result    = send_due_reminders($dryRun);

if (!$quiet) {
    echo 'CASMS deadline reminders — ' . $startedAt . PHP_EOL;
    echo 'Lead time: ' . reminder_lead_days() . ' day(s) before the deadline' . PHP_EOL;
    echo str_repeat('-', 62) . PHP_EOL;

    if ($result['items'] === []) {
        echo 'Nothing is due inside the reminder window.' . PHP_EOL;
    } else {
        foreach ($result['items'] as $item) {
            printf(
                "%-10s %-28s %-24s %s%s",
                $item['outcome'],
                mb_strimwidth($item['last_name'] . ', ' . $item['first_name'], 0, 27, '…'),
                mb_strimwidth((string) $item['requirement_name'], 0, 23, '…'),
                'due ' . date('d M Y', strtotime((string) $item['deadline_at'])),
                PHP_EOL
            );
        }
    }
    echo str_repeat('-', 62) . PHP_EOL;
}

printf(
    "%s%d due, %d sent, %d already sent, %d emailed%s",
    $dryRun ? '[DRY RUN] ' : '',
    $result['due'], $result['sent'], $result['skipped'], $result['emailed'],
    PHP_EOL
);

exit(0);
