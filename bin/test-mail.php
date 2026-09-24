<?php
/**
 * CASDevU — Check that outgoing email works.
 *
 *   C:\xampp\php\php.exe bin\test-mail.php you@example.com
 *
 * Sends one short message with the configured transport and prints exactly
 * why it failed when it does (wrong App Password, blocked port, and so on).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/test-mail.php you@example.com\n");
    exit(2);
}

echo 'Transport: ' . MAIL_TRANSPORT . PHP_EOL;

if (MAIL_TRANSPORT !== 'smtp') {
    echo 'Email is not being sent for real: messages are written to storage/logs/mail.log.' . PHP_EOL
       . 'Copy includes/config.local.example.php to includes/config.local.php and add the' . PHP_EOL
       . 'Gmail address and App Password to switch to real delivery.' . PHP_EOL;
    exit(1);
}

echo 'Server:    ' . SMTP_HOST . ':' . SMTP_PORT . PHP_EOL;
echo 'Sending as ' . MAIL_FROM_ADDRESS . ' to ' . $to . ' ...' . PHP_EOL;

$result = smtp_send(
    $to,
    APP_NAME . ' test email',
    'This is a test message from ' . APP_NAME . ', the ' . OFFICE_NAME . ' system of '
    . UNIVERSITY . '.' . PHP_EOL . PHP_EOL
    . 'If you can read this, password reset links and reminders will be delivered.'
);

if ($result['ok']) {
    echo 'Sent. Check the inbox (and the spam folder the first time).' . PHP_EOL;
    exit(0);
}

echo 'FAILED: ' . $result['error'] . PHP_EOL;
exit(1);
