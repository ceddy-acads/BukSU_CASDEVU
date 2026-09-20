<?php
/**
 * CASMS — Outgoing email (FR-3.6, FR-1.7)
 *
 * XAMPP has no mail server by default, so delivery is pluggable:
 *
 *   MAIL_TRANSPORT = 'log'  — write the message to storage/logs/mail.log.
 *                             The default. Every flow works end to end and
 *                             can be demonstrated without an SMTP account.
 *   MAIL_TRANSPORT = 'mail' — hand the message to PHP's mail(), for a server
 *                             that actually has one configured.
 *
 * A failure to send is never allowed to break the action that triggered it:
 * the in-app notification is the system of record, email is a courtesy.
 */

declare(strict_types=1);

/**
 * Send one message.
 *
 * @param  string $to       Recipient address.
 * @param  string $subject  Plain text, single line.
 * @param  string $body     Plain text body.
 * @return bool             True when handed off successfully.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[CASMS] Refused to send mail to an invalid address.');
        return false;
    }

    // Strip anything that could inject extra headers.
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
    $body    = str_replace("\r\n", "\n", $body);

    $from    = MAIL_FROM_ADDRESS;
    $headers = implode("\r\n", [
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: CASMS',
    ]);

    try {
        if (MAIL_TRANSPORT === 'mail') {
            $sent = @mail($to, $subject, $body, $headers);
            if (!$sent) {
                error_log('[CASMS] mail() returned false for ' . $to);
            }
            return $sent;
        }

        // 'log' transport — the default.
        return log_mail($to, $subject, $body);
    } catch (Throwable $e) {
        error_log('[CASMS] Mail failed: ' . $e->getMessage());
        return false;
    }
}

/** Append a readable copy of the message to storage/logs/mail.log. */
function log_mail(string $to, string $subject, string $body): bool
{
    $directory = STORAGE_PATH . '/logs';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $entry = str_repeat('=', 70) . PHP_EOL
        . 'Date:    ' . date('Y-m-d H:i:s') . PHP_EOL
        . 'To:      ' . $to . PHP_EOL
        . 'From:    ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>' . PHP_EOL
        . 'Subject: ' . $subject . PHP_EOL
        . str_repeat('-', 70) . PHP_EOL
        . $body . PHP_EOL . PHP_EOL;

    return file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Send an in-app notification and, when email is switched on, the same
 * message by email. Existing calls to notify() are untouched; callers that
 * want both use this instead.
 */
function notify_and_email(
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $linkUrl = null
): void {
    notify($userId, $type, $title, $message, $linkUrl);

    if (!MAIL_NOTIFICATIONS_ENABLED) {
        return;
    }

    $user = fetch_one('SELECT email, first_name FROM users WHERE user_id = ?', [$userId]);
    if ($user === null) {
        return;
    }

    send_mail(
        $user['email'],
        $title,
        'Hi ' . $user['first_name'] . ',' . PHP_EOL . PHP_EOL
        . $message . PHP_EOL . PHP_EOL
        . ($linkUrl !== null ? 'Open it here: ' . absolute_url($linkUrl) . PHP_EOL . PHP_EOL : '')
        . '— ' . OFFICE_NAME . PHP_EOL . UNIVERSITY . PHP_EOL . PHP_EOL
        . 'This is an automated message. Please do not reply.'
    );
}

/**
 * Turn an application path into something clickable in an email.
 * url() returns a root-relative path, which is useless outside the browser.
 */
function absolute_url(string $path): string
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? APP_HOSTNAME;

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}
