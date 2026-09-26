<?php
/**
 * CASDevU — Sign-in portal layout (login page only).
 *
 * A navy brand field holds the identity, the headline and a line on what the
 * system does. On desktop the campus photo fills the whole screen and the
 * sign-in panel floats over it. Registration and the password-reset pages
 * keep the plain auth layout (auth-header.php).
 *
 * Background photo: a campus photo at
 *   public/assets/img/buksu-campus.jpg
 * and it shows through a navy overlay that keeps the text readable: behind
 * the brand band on phones and tablets, full screen on desktop.
 *
 * The BukSU logo beside the name is public/assets/img/buksu-logo-white.png,
 * a white version of the university's supplied mark. Until
 * the file exists the field is plain navy and no image is requested. Use a
 * photo the university owns or has licensed; none ships with the project.
 *
 * Pages set before including:
 *   $pageTitle   heading and <title>
 *   $authIntro   optional one-line subtitle under the heading
 */

declare(strict_types=1);

$portalPhoto = is_file(dirname(__DIR__, 2) . '/public/assets/img/buksu-campus.jpg')
    ? asset('img/buksu-campus.jpg')
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10284d">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body class="portal-body">
<div class="portal<?= $portalPhoto ? ' has-photo' : '' ?>"<?php if ($portalPhoto): ?> style="--portal-photo: url('<?= e($portalPhoto) ?>')"<?php endif; ?>>
    <header class="portal-brand">
        <div class="portal-brand-inner">
            <p class="portal-mark">
                <?php // The university name is written beside it, so the logo is decorative. ?>
                <img class="portal-logo" src="<?= asset('img/buksu-logo-white.png') ?>" alt="" width="126" height="128">
                <span class="portal-mark-text">
                    <span class="brand-mark"><?= e(APP_NAME) ?></span>
                    <span class="portal-mark-sub"><?= e(UNIVERSITY) ?></span>
                </span>
            </p>
            <p class="portal-headline">
                <span>Culture.</span>
                <span>Arts.</span>
                <span>Sports.</span>
            </p>
            <p class="portal-lede">
                Register for activities, submit requirements, and keep up with announcements.
                Office staff and coordinators run activities, inventory, and reports from here.
            </p>
        </div>
    </header>

    <main class="portal-main" id="main">
        <div class="portal-panel">
            <header class="portal-panel-head">
                <h1><?= e($pageTitle) ?></h1>
                <?php if (!empty($authIntro)): ?>
                    <p><?= e($authIntro) ?></p>
                <?php endif; ?>
            </header>

            <?php foreach (take_flashes() as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>
