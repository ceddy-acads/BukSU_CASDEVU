<?php
/**
 * CASMS — Outgoing email (FR-3.6, FR-1.7)
 *
 * XAMPP has no mail server by default, so delivery is pluggable:
 *
 *   MAIL_TRANSPORT = 'smtp' — real delivery through an SMTP account (Gmail
 *                             with an App Password). Chosen automatically
 *                             once includes/config.local.php has credentials.
 *   MAIL_TRANSPORT = 'log'  — write the message to storage/logs/mail.log.
 *                             The fallback. Every flow works end to end and
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
        'X-Mailer: ' . APP_NAME,
    ]);

    try {
        if (MAIL_TRANSPORT === 'smtp') {
            $result = smtp_send($to, $subject, $body);
            if (!$result['ok']) {
                error_log('[CASMS] SMTP delivery to ' . $to . ' failed: ' . $result['error']);
            }
            return $result['ok'];
        }

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

/**
 * Deliver one plain-text message over SMTP (Gmail by default).
 *
 * Port 587 upgrades the connection with STARTTLS; port 465 is TLS from the
 * first byte. The server certificate is always verified (PHP's
 * openssl.cafile). The password is never written to any log.
 *
 * @return array{ok: bool, error: string}
 */
function smtp_send(string $to, string $subject, string $body): array
{
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        return ['ok' => false, 'error' => 'SMTP_USERNAME / SMTP_PASSWORD are not set in includes/config.local.php.'];
    }

    $port    = (int) SMTP_PORT;
    $implicit = $port === 465;
    $context = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => SMTP_HOST,
    ]]);

    $errno  = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        ($implicit ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . $port,
        $errno, $errstr, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $context
    );
    if ($socket === false) {
        return ['ok' => false, 'error' => 'Could not connect to ' . SMTP_HOST . ':' . $port . ' (' . $errstr . ').'];
    }
    stream_set_timeout($socket, SMTP_TIMEOUT);

    // Read one (possibly multi-line) reply; returns [code, text].
    $read = static function () use ($socket): array {
        $text = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int) substr($text, 0, 3), trim($text)];
    };
    // Send a command and require one of the expected reply codes.
    $step = static function (?string $command, array $expect, string $label) use ($socket, $read): void {
        if ($command !== null) {
            fwrite($socket, $command . "\r\n");
        }
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException($label . ' was refused: ' . ($text !== '' ? $text : 'no reply'));
        }
    };

    $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? APP_HOSTNAME)) ?: 'localhost';

    try {
        $step(null, [220], 'Connection');
        $step('EHLO ' . $hostname, [250], 'EHLO');

        if (!$implicit) {
            $step('STARTTLS', [220], 'STARTTLS');
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                throw new RuntimeException('TLS handshake failed (check openssl.cafile in php.ini).');
            }
            $step('EHLO ' . $hostname, [250], 'EHLO after STARTTLS');
        }

        $step('AUTH LOGIN', [334], 'AUTH LOGIN');
        $step(base64_encode(SMTP_USERNAME), [334], 'Username');
        // Gmail shows App Passwords in groups of four; spaces are not part of it.
        $step(base64_encode(str_replace(' ', '', SMTP_PASSWORD)), [235],
              'Sign-in (check the App Password)');

        $step('MAIL FROM:<' . MAIL_FROM_ADDRESS . '>', [250], 'Sender');
        $step('RCPT TO:<' . $to . '>', [250, 251], 'Recipient');
        $step('DATA', [354], 'DATA');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName    = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
        $messageId      = '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr(MAIL_FROM_ADDRESS, '@') ?: '@localhost', 1) . '>';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encodedName . ' <' . MAIL_FROM_ADDRESS . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: ' . APP_NAME,
        ];

        // Base64 body: safe for any UTF-8 text and never starts a line with ".".
        $payload = implode("\r\n", $headers) . "\r\n\r\n"
                 . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));

        fwrite($socket, $payload . "\r\n.\r\n");
        $step(null, [250], 'Message');
        fwrite($socket, "QUIT\r\n");

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        fclose($socket);
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
        . OFFICE_NAME . PHP_EOL . UNIVERSITY . PHP_EOL . PHP_EOL
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
