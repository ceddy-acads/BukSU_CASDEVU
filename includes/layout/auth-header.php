<?php
/**
 * CASMS — Layout for the signed-out screens (sign in, register, password reset).
 *
 * Pages set before including:
 *   $pageTitle   heading and <title>
 *   $authIntro   optional one-line subtitle under the heading
 *   $authWide    optional true for the wider registration form
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10284d">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
<main class="auth-wrap">
    <div>
        <div class="auth-card<?= !empty($authWide) ? ' wide' : '' ?>">
            <header class="auth-head">
                <span class="brand-mark"><?= e(APP_NAME) ?></span>
                <span class="brand-sub"><?= e(APP_TAGLINE) ?></span>
                <h1><?= e($pageTitle) ?></h1>
                <?php if (!empty($authIntro)): ?>
                    <p><?= e($authIntro) ?></p>
                <?php endif; ?>
            </header>

            <?php foreach (take_flashes() as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>
