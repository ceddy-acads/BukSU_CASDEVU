<?php
/**
 * CASMS — Public invitation form
 *
 * An outside group invites the university to a program. No account and no
 * sign-in: the office reviews every row before anyone else sees it.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

// field => [label, max length, required]
$limits = [
    'organization' => ['Organization or group', 200, true],
    'contact_name' => ['Contact person', 150, true],
    'email'        => ['Email address', 190, true],
    'phone'        => ['Phone number', 40, false],
    'title'        => ['Program title', 200, true],
    'place'        => ['Place', 255, true],
    'details'      => ['Details', 5000, true],
];

$errors = [];   // field name => message; 'form' for a problem with the whole form
$sent   = false;
$sentTo = '';

if (is_post()) {
    csrf_verify();

    $in = [];
    foreach (array_keys($limits) as $field) {
        $in[$field] = post($field);
    }
    $in['email'] = strtolower($in['email']);
    $startRaw    = post('start_at');
    $endRaw      = post('end_at');

    if (post('website') !== '') {
        // A bot filled the hidden field. Look the same as success, store nothing.
        audit_log('spam', 'invitation', null, 'Honeypot filled on the invitation form');
        $sent = true;
    } else {
        foreach ($limits as $field => [$label, $max, $required]) {
            if ($in[$field] === '') {
                if ($required) {
                    $errors[$field] = $label . ' is required.';
                }
            } elseif (mb_strlen($in[$field]) > $max) {
                $errors[$field] = $label . ' must be ' . $max . ' characters or fewer.';
            }
        }
        if (!isset($errors['email']) && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $parse = static function (string $value): ?DateTime {
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
            return $date !== false && $date->format('Y-m-d\TH:i') === $value ? $date : null;
        };
        $start = null;
        $end   = null;
        if ($startRaw === '') {
            $errors['start_at'] = 'The start date and time are required.';
        } elseif (($start = $parse($startRaw)) === null) {
            $errors['start_at'] = 'Enter the start as a date and time.';
        } elseif ($start < new DateTime('now')) {
            $errors['start_at'] = "The program's start is in the past.";
        }
        if ($endRaw !== '') {
            if (($end = $parse($endRaw)) === null) {
                $errors['end_at'] = 'Enter the end as a date and time.';
            } elseif ($start !== null && $end < $start) {
                $errors['end_at'] = 'The end cannot be before the start.';
            }
        }

        if ($errors === [] && invitation_submissions_limited($_SERVER['REMOTE_ADDR'] ?? 'unknown', $in['email'])) {
            $errors['form'] = 'Too many invitations have been sent from here in the last hour. '
                            . 'Please try again later, or contact the office' . office_contact_hint() . '.';
        }

        $letter = null;
        if ($errors === [] && ($_FILES['letter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = store_upload($_FILES['letter'], 'invitations');
            if ($stored['ok']) {
                $letter = $stored;
            } else {
                $errors['letter'] = (string) $stored['error'];
            }
        }

        if ($errors === []) {
            query(
                "INSERT INTO invitations
                     (organization, contact_name, email, phone, title, start_at, end_at, place, details,
                      letter_path, letter_name, letter_mime, letter_size, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                [
                    $in['organization'], $in['contact_name'], $in['email'],
                    $in['phone'] !== '' ? $in['phone'] : null,
                    $in['title'],
                    $start->format('Y-m-d H:i:s'), ($end ?? $start)->format('Y-m-d H:i:s'),
                    $in['place'], $in['details'],
                    $letter['path'] ?? null, $letter['original'] ?? null,
                    $letter['mime'] ?? null, $letter['size'] ?? null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]
            );
            audit_log('create', 'invitation', (int) db()->lastInsertId(), 'Invitation received from ' . $in['organization']);
            $sent   = true;
            $sentTo = $in['email'];
        }
    }

    if ($sent) {
        clear_old_input();
    } else {
        remember_input($_POST);
    }
}

$pageTitle  = 'Submit an invitation';
$authIntro  = 'Invite Bukidnon State University students to your program. No account needed: the office reviews every invitation.';
$portalWide = true;
require __DIR__ . '/../../includes/layout/portal-header.php';

// Wrapper class, aria attributes and message for one field.
$rowClass = static fn(string $f): string => 'form-row' . (isset($errors[$f]) ? ' has-error' : '');
$aria     = static fn(string $f): string => isset($errors[$f])
    ? ' aria-invalid="true" aria-describedby="' . $f . '-error"' : '';
$fieldErr = static fn(string $f): string => isset($errors[$f])
    ? '<p class="field-error" id="' . $f . '-error">' . e($errors[$f]) . '</p>' : '';
?>

<?php if ($sent): ?>
    <div class="alert alert-success" role="status">
        <div>Thank you. Your invitation has reached the office.</div>
        <div>
            <?php if (email_delivers() && $sentTo !== ''): ?>
                The office will review it and email its decision to <?= e($sentTo) ?>.
            <?php else: ?>
                The office will review it and contact you<?= e(office_contact_hint()) ?>.
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-outline btn-block" href="<?= url('invitations/submit.php') ?>">Send another invitation</a>
<?php else: ?>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="portal-form" novalidate>
        <?= csrf_field() ?>

        <div class="<?= $rowClass('organization') ?>">
            <label for="organization">Organization or group <span class="req">*</span></label>
            <input type="text" id="organization" name="organization" maxlength="200" required
                   value="<?= e(old('organization')) ?>" autocomplete="organization"<?= $aria('organization') ?>>
            <?= $fieldErr('organization') ?>
        </div>

        <div class="<?= $rowClass('contact_name') ?>">
            <label for="contact_name">Contact person <span class="req">*</span></label>
            <input type="text" id="contact_name" name="contact_name" maxlength="150" required
                   value="<?= e(old('contact_name')) ?>" autocomplete="name"<?= $aria('contact_name') ?>>
            <?= $fieldErr('contact_name') ?>
        </div>

        <div class="<?= $rowClass('email') ?>">
            <label for="email">Email address <span class="req">*</span></label>
            <input type="email" id="email" name="email" maxlength="190" required
                   value="<?= e(old('email')) ?>" autocomplete="email" inputmode="email" spellcheck="false"<?= $aria('email') ?>>
            <p class="hint">The office's decision is sent here.</p>
            <?= $fieldErr('email') ?>
        </div>

        <div class="<?= $rowClass('phone') ?>">
            <label for="phone">Phone number <span class="optional">(optional)</span></label>
            <input type="tel" id="phone" name="phone" maxlength="40"
                   value="<?= e(old('phone')) ?>" autocomplete="tel"<?= $aria('phone') ?>>
            <?= $fieldErr('phone') ?>
        </div>

        <div class="<?= $rowClass('title') ?>">
            <label for="title">Program title <span class="req">*</span></label>
            <input type="text" id="title" name="title" maxlength="200" required
                   value="<?= e(old('title')) ?>"<?= $aria('title') ?>>
            <?= $fieldErr('title') ?>
        </div>

        <div class="<?= $rowClass('start_at') ?>">
            <label for="start_at">Starts <span class="req">*</span></label>
            <input type="datetime-local" id="start_at" name="start_at" required
                   value="<?= e(old('start_at')) ?>"<?= $aria('start_at') ?>>
            <?= $fieldErr('start_at') ?>
        </div>

        <div class="<?= $rowClass('end_at') ?>">
            <label for="end_at">Ends <span class="optional">(optional)</span></label>
            <input type="datetime-local" id="end_at" name="end_at"
                   value="<?= e(old('end_at')) ?>"<?= $aria('end_at') ?>>
            <?= $fieldErr('end_at') ?>
        </div>

        <div class="<?= $rowClass('place') ?>">
            <label for="place">Place <span class="req">*</span></label>
            <input type="text" id="place" name="place" maxlength="255" required
                   value="<?= e(old('place')) ?>"<?= $aria('place') ?>>
            <?= $fieldErr('place') ?>
        </div>

        <div class="<?= $rowClass('details') ?>">
            <label for="details">Details <span class="req">*</span></label>
            <textarea id="details" name="details" rows="5" maxlength="5000" required<?= $aria('details') ?>><?= e(old('details')) ?></textarea>
            <p class="hint">What the program is, and who you hope BukSU will send.</p>
            <?= $fieldErr('details') ?>
        </div>

        <div class="<?= $rowClass('letter') ?>">
            <label for="letter">Invitation letter <span class="optional">(optional)</span></label>
            <input type="file" id="letter" name="letter" accept=".pdf,.jpg,.jpeg,.png"<?= $aria('letter') ?>>
            <p class="hint">PDF, JPG or PNG, up to <?= (int) (max_upload_bytes() / 1024 / 1024) ?> MB.<?= $errors !== [] ? ' Choose the letter again.' : '' ?></p>
            <?= $fieldErr('letter') ?>
        </div>

        <?php // Honeypot: people and screen readers never reach it; a bot that fills every field does. ?>
        <div hidden>
            <label for="website">Leave this empty</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn btn-primary btn-block portal-submit" data-loading="Sending…">Send invitation</button>
    </form>

    <div class="portal-secondary">
        <p><a href="<?= url('login.php') ?>">Staff and students: sign in</a></p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/portal-footer.php'; ?>
<?php clear_old_input(); ?>
