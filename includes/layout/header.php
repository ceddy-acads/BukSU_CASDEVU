<?php
/**
 * CASMS — Page header and navigation.
 *
 * Pages set $pageTitle before including this file.
 * Navigation is role-aware (FR-1.4) but is presentation only — the real
 * enforcement is require_role() on each page.
 *
 * Layout: a navy sidebar for navigation, and one top bar for context and
 * the person: breadcrumb, notifications, account menu. Below 960px the
 * sidebar becomes a drawer and the top bar shows the Menu button instead of
 * the breadcrumb (assets/js/app.js).
 */

declare(strict_types=1);

$user         = current_user();
$role         = current_role();
$unreadCount  = $user ? unread_notification_count((int) $user['user_id']) : 0;
$reviewCount  = $user && can_review() ? review_counts()['total'] : 0;
$currentFile  = basename($_SERVER['SCRIPT_NAME']);
$currentDir   = basename(dirname($_SERVER['SCRIPT_NAME']));
$isStudent    = $role === 'student';

/**
 * Work out which sidebar entry owns the page being viewed, so pages deeper
 * in a section (an activity's participants, a requirement review) keep
 * their parent highlighted.
 */
$navSection = match (true) {
    $currentDir === 'public' && $currentFile === 'index.php'   => 'dashboard',
    $currentDir === 'public' && $currentFile === 'review.php'  => 'review',
    $currentFile === 'notifications.php'                        => 'notifications',
    $currentFile === 'profile.php'                              => 'profile',
    $currentFile === 'calendar.php'                             => 'calendar',
    $currentDir === 'activities'                                => 'activities',
    $currentDir === 'announcements'                             => 'announcements',
    $currentDir === 'inventory'                                 => 'inventory',
    $currentDir === 'reports'                                   => 'reports',
    $currentDir === 'admin'                                     => pathinfo($currentFile, PATHINFO_FILENAME),
    $currentDir === 'requirements' && $isStudent                => 'my-requirements',
    $currentDir === 'participation' && $isStudent               => 'my-activities',
    $currentDir === 'participation' || $currentDir === 'requirements' => 'activities',
    default                                                     => '',
};

/** Breadcrumb root for each section: label and landing page. */
$sections = [
    'dashboard'       => ['Dashboard', 'index.php'],
    'review'          => ['Review queue', 'review.php'],
    'notifications'   => ['Notifications', 'notifications.php'],
    'profile'         => ['My profile', 'profile.php'],
    'calendar'        => ['Calendar', 'activities/calendar.php'],
    'activities'      => ['Activities', 'activities/index.php'],
    'announcements'   => ['Announcements', 'announcements/index.php'],
    'inventory'       => [$isStudent ? 'Costumes & equipment' : 'Inventory', 'inventory/index.php'],
    'reports'         => ['Reports', 'reports/index.php'],
    'my-activities'   => ['My activities', 'participation/my-activities.php'],
    'my-requirements' => ['My requirements', 'requirements/mine.php'],
    'users'           => [has_role('admin') ? 'Users' : 'Accounts', 'admin/users.php'],
    'venues'          => ['Venues', 'admin/venues.php'],
    'reminders'       => ['Reminders', 'admin/reminders.php'],
    'categories'      => ['Categories', 'admin/categories.php'],
    'audit'           => ['Audit log', 'admin/audit.php'],
    'backup'          => ['Backup', 'admin/backup.php'],
];

/** Render one sidebar link, marked as the current page when it owns the view. */
function nav_link(string $section, string $href, string $label, string $navSection, int $count = 0, string $countLabel = 'waiting'): string
{
    $current = $section === $navSection ? ' aria-current="page"' : '';
    $badge   = $count > 0
        ? '<span class="nav-count" aria-label="' . $count . ' ' . e($countLabel) . '">' . ($count > 99 ? '99+' : $count) . '</span>'
        : '';
    return '<a class="nav-link" href="' . e(url($href)) . '"' . $current . '>'
         . icon($section === 'inventory' && current_role() === 'student' ? 'equipment' : $section, 'nav-icon')
         . '<span class="nav-label">' . e($label) . '</span>' . $badge . '</a>';
}

$initials = $user
    ? strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10284d">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' · ' : '' ?><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="app">
<?php if ($user): ?>
    <div class="nav-backdrop" data-nav-close></div>

    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <a class="brand" href="<?= url('index.php') ?>">
                <span class="brand-mark"><?= e(APP_NAME) ?></span>
                <span class="brand-sub"><?= e(UNIVERSITY) ?></span>
            </a>
            <button type="button" class="icon-btn nav-close" data-nav-close aria-label="Close menu">
                <?= icon('close') ?>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Sections">
            <div class="nav-group">
                <?= nav_link('dashboard', 'index.php', 'Dashboard', $navSection) ?>
                <?php if ($isStudent): ?>
                    <?= nav_link('my-activities', 'participation/my-activities.php', 'My activities', $navSection) ?>
                    <?= nav_link('my-requirements', 'requirements/mine.php', 'My requirements', $navSection) ?>
                <?php elseif (can_review()): ?>
                    <?= nav_link('review', 'review.php', 'Review queue', $navSection, $reviewCount, 'waiting for review') ?>
                <?php endif; ?>
                <?= nav_link('activities', 'activities/index.php', 'Activities', $navSection) ?>
                <?= nav_link('calendar', 'activities/calendar.php', 'Calendar', $navSection) ?>
                <?= nav_link('announcements', 'announcements/index.php', 'Announcements', $navSection) ?>
                <?php if ($isStudent): ?>
                    <?= nav_link('inventory', 'inventory/index.php', 'Costumes & equipment', $navSection) ?>
                <?php endif; ?>
            </div>

            <?php if (has_role('staff', 'admin', 'coordinator')): ?>
                <div class="nav-group">
                    <p class="nav-group-title">Operations</p>
                    <?= nav_link('inventory', 'inventory/index.php', 'Inventory', $navSection) ?>
                    <?= nav_link('reports', 'reports/index.php', 'Reports', $navSection) ?>
                </div>
            <?php endif; ?>

            <?php if (has_role('staff', 'admin')): ?>
                <div class="nav-group">
                    <p class="nav-group-title">Administration</p>
                    <?= nav_link('users', 'admin/users.php', has_role('admin') ? 'Users' : 'Accounts', $navSection) ?>
                    <?= nav_link('venues', 'admin/venues.php', 'Venues', $navSection) ?>
                    <?= nav_link('reminders', 'admin/reminders.php', 'Reminders', $navSection) ?>
                    <?php if (has_role('admin')): ?>
                        <?= nav_link('categories', 'admin/categories.php', 'Categories', $navSection) ?>
                        <?= nav_link('audit', 'admin/audit.php', 'Audit log', $navSection) ?>
                        <?= nav_link('backup', 'admin/backup.php', 'Backup', $navSection) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </nav>
    </aside>
<?php endif; ?>

<div class="main">
<?php if ($user): ?>
    <?php
    // Breadcrumb: the owning section, then this page when it is a deeper one.
    [$sectionLabel, $sectionHref] = $sections[$navSection] ?? ['', ''];
    $pageLabel  = (string) ($pageTitle ?? '');
    $isSectionHome = $sectionHref !== '' && str_ends_with($_SERVER['SCRIPT_NAME'], '/' . $sectionHref);
    ?>
    <header class="topbar">
        <button type="button" class="icon-btn topbar-menu" data-nav-open aria-controls="sidebar" aria-expanded="false">
            <?= icon('menu') ?><span>Menu</span>
        </button>
        <a class="brand topbar-brand" href="<?= url('index.php') ?>"><span class="brand-mark"><?= e(APP_NAME) ?></span></a>

        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol>
                <?php if ($sectionLabel !== '' && !$isSectionHome): ?>
                    <li><a href="<?= url($sectionHref) ?>"><?= e($sectionLabel) ?></a></li>
                    <li aria-current="page"><?= e($pageLabel) ?></li>
                <?php else: ?>
                    <li aria-current="page"><?= e($sectionLabel !== '' ? $sectionLabel : $pageLabel) ?></li>
                <?php endif; ?>
            </ol>
        </nav>

        <div class="topbar-actions">
            <a class="icon-btn topbar-bell<?= $navSection === 'notifications' ? ' is-current' : '' ?>" href="<?= url('notifications.php') ?>"
               aria-label="Notifications<?= $unreadCount > 0 ? ', ' . $unreadCount . ' unread' : '' ?>"
               <?= $navSection === 'notifications' ? 'aria-current="page"' : '' ?>>
                <?= icon('bell') ?>
                <?php if ($unreadCount > 0): ?>
                    <span class="nav-count" aria-hidden="true"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </a>

            <details class="account-menu" data-menu>
                <summary class="account-trigger" aria-label="Account menu for <?= e(full_name($user)) ?>">
                    <span class="account-initials" aria-hidden="true"><?= e($initials) ?></span>
                    <span class="account-text">
                        <span class="account-name"><?= e(full_name($user)) ?></span>
                        <span class="account-role"><?= e(ucfirst((string) $role)) ?></span>
                    </span>
                    <?= icon('chevron-down', 'icon account-chevron') ?>
                </summary>
                <div class="account-panel">
                    <p class="account-panel-head">
                        <strong><?= e(full_name($user)) ?></strong>
                        <span><?= e($user['email']) ?></span>
                    </p>
                    <a class="account-item" href="<?= url('profile.php') ?>"<?= $navSection === 'profile' ? ' aria-current="page"' : '' ?>>
                        <?= icon('user') ?> My profile
                    </a>
                    <a class="account-item" href="<?= url('logout.php') ?>">
                        <?= icon('sign-out') ?> Sign out
                    </a>
                </div>
            </details>
        </div>
    </header>
<?php endif; ?>
<main class="page" id="main" tabindex="-1">
<?php foreach (take_flashes() as $flashMessage): ?>
    <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message']) ?></div>
<?php endforeach; ?>
