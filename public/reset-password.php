<?php
/**
 * CASMS — Choose a new password with the emailed PIN (FR-1.7)
 *
 * The PIN is checked against the account's one outstanding reset row, which
 * must be unused, unexpired, and belong to an active account. A 6-digit PIN
 * can be guessed, so wrong tries are capped per PIN and per address, counted
 * from audit_logs like failed sign-ins. Every failure gives the same message,
 * so the page never reveals whether an address is registered.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * The account's usable reset row, or null.
 *
 * @return array<string, mixed>|null
 */
function open_reset_pin(string $email): ?array
{
    return fetch_one(
        "SELECT t.token_id, t.token_hash, t.created_at, u.user_id, u.email, u.first_name
           FROM auth_tokens t
           JOIN users u ON u.user_id = t.user_id
          WHERE u.email      = ?
            AND u.status     = 'active'
            AND t.type       = 'password_reset'
            AND t.used_at    IS NULL
            AND t.expires_at > NOW()
          ORDER BY t.token_id DESC
          LIMIT 1",
        [$email]
    );
}

/** Retire every outstanding reset PIN for the account. */
function retire_reset_pins(int $userId): void
{
    query(
        "UPDATE auth_tokens SET used_at = NOW()
          WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
        [$userId]
    );
}

// Filled in by forgot-password.php; the person can still change it.
$email    = strtolower(is_post() ? post('email') : (string) ($_SESSION['reset_email'] ?? ''));
$pinSent  = !is_post() && isset($_SESSION['reset_email']);
$pin      = preg_replace('/\D/', '', post('pin')) ?? '';
$errors   = [];
$done     = false;
$pinError = 'That PIN is wrong or has expired. Check the latest email, or request a new PIN.';

if (is_post()) {
    csrf_verify();

    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ipFails   = (int) fetch_value(
        "SELECT COUNT(*) FROM audit_logs
          WHERE action = 'reset_pin_failed' AND ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$ipAddress, LOGIN_LOCKOUT_MINUTES]
    );
    $row = $ipFails < LOGIN_MAX_ATTEMPTS ? open_reset_pin($email) : null;

    if ($ipFails >= LOGIN_MAX_ATTEMPTS) {
        $errors[] = 'Too many wrong PINs. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } elseif ($row === null || strlen($pin) !== 6 || !password_verify($pin, trim((string) $row['token_hash']))) {
        audit_log('reset_pin_failed', 'user', $row !== null ? (int) $row['user_id'] : null,
                  'Wrong reset PIN for ' . $email);
        $errors[] = $pinError;

        // Retire the PIN once it has had its share of guesses.
        if ($row !== null) {
            $pinFails = (int) fetch_value(
                "SELECT COUNT(*) FROM audit_logs
                  WHERE action = 'reset_pin_failed' AND entity_type = 'user' AND entity_id = ?
                    AND created_at >= ?",
                [$row['user_id'], $row['created_at']]
            );
            if ($pinFails >= PASSWORD_RESET_PIN_ATTEMPTS) {
                retire_reset_pins((int) $row['user_id']);
            }
        }
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if (($passwordProblem = password_error($password)) !== null) {
            $errors[] = $passwordProblem;
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if ($errors === []) {
            db()->beginTransaction();
            try {
                query(
                    'UPDATE users SET password_hash = ? WHERE user_id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]
                );
                retire_reset_pins((int) $row['user_id']);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                error_log('[CASMS] Password reset failed: ' . $e->getMessage());
                $errors[] = 'The password could not be changed. Please request a new PIN.';
            }

            if ($errors === []) {
                unset($_SESSION['reset_email']);
                audit_log('update', 'user', (int) $row['user_id'],
                          'Password reset completed for ' . $row['email']);

                // Tell the account holder, so an unexpected change is noticed.
                send_mail(
                    (string) $row['email'],
                    'Your ' . APP_NAME . ' password was changed',
                    'Hi ' . $row['first_name'] . ',' . PHP_EOL . PHP_EOL
                    . 'The password for your account was changed on '
                    . date('d M Y \a\t g:i A') . '.' . PHP_EOL . PHP_EOL
                    . 'If this was not you, contact the ' . office_name()
                    . ' immediately.' . PHP_EOL . PHP_EOL
                    . office_name() . PHP_EOL . university_name()
                );

                $done = true;
            }
        }
    }
}
$pageTitle = 'Choose a new password';
require __DIR__ . '/../includes/layout/auth-header.php';
?>

<?php if ($done): ?>
    <div class="alert alert-success" role="status">
        Your password has been changed. You can sign in with it now.
    </div>
    <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Go to sign in</a>

<?php else: ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
        </div>
    <?php elseif ($pinSent): ?>
        <div class="alert alert-success" role="status">
            If that email address belongs to an active account, a 6-digit PIN
            is on its way. It works once and expires in
            <?= PASSWORD_RESET_TTL_MINUTES ?> minutes.
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-row">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required
                   value="<?= e($email) ?>" autocomplete="username" inputmode="email" spellcheck="false"<?= $email === '' ? ' autofocus' : '' ?>>
        </div>

        <div class="form-row">
            <label for="pin">PIN from the email</label>
            <input type="text" id="pin" name="pin" required maxlength="6" pattern="[0-9]{6}"
                   inputmode="numeric" autocomplete="one-time-code" aria-describedby="pin-hint"
                   value="<?= e($pin) ?>"<?= $email !== '' ? ' autofocus' : '' ?>>
            <p class="hint" id="pin-hint">6 digits. Nothing arrived? Check your spam folder.</p>
        </div>

        <div class="form-row">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required
                   autocomplete="new-password" aria-describedby="password-rules">
            <?php $passwordRulesFor = 'password'; require __DIR__ . '/../includes/layout/password-rules.php'; ?>
        </div>

        <div class="form-row">
            <label for="password_confirm">Confirm new password</label>
            <input type="password" id="password_confirm" name="password_confirm" required
                   autocomplete="new-password" data-password-match="password">
        </div>

        <button type="submit" class="btn btn-primary btn-block" data-loading="Changing password…">Change password</button>
    </form>

    <div class="auth-foot">
        <p><a href="<?= url('forgot-password.php') ?>">Request a new PIN</a> · <a href="<?= url('login.php') ?>">Back to sign in</a></p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/auth-footer.php'; ?>
