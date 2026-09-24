<?php
/**
 * CASMS — Notification inbox (FR-3.3, FR-3.4)
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$userId = (int) current_user_id();

// Read the list before marking it read, so unread items can still be styled.
$notifications = fetch_all(
    'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
    [$userId]
);

mark_notifications_read($userId);

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p>Updates about activities, registrations, and requirements.</p>
    </div>
</div>

<?php if ($notifications === []): ?>
    <div class="empty">
        <strong>No notifications yet</strong>
        Registration decisions, document reviews, and deadline reminders will appear here.
    </div>
<?php else: ?>
    <?php
    // Type -> icon and label. Only the types notify() is documented to send.
    $types = [
        'activity'     => ['activities',    'Activity'],
        'announcement' => ['announcements', 'Announcement'],
        'registration' => ['my-activities', 'Registration'],
        'requirement'  => ['requirements',  'Requirement'],
        'reservation'  => ['inventory',     'Reservation'],
        'deadline'     => ['reminders',     'Deadline'],
        'system'       => ['bell',          'Account'],
    ];
    $today  = date('Y-m-d');
    $groups = ['Today' => [], 'Earlier' => []];
    foreach ($notifications as $notification) {
        $groups[substr((string) $notification['created_at'], 0, 10) === $today ? 'Today' : 'Earlier'][] = $notification;
    }
    ?>
    <?php foreach ($groups as $groupLabel => $items): ?>
        <?php if ($items === []) { continue; } ?>
        <section class="card">
            <div class="card-head"><h2><?= e($groupLabel) ?></h2></div>
            <div class="feed">
                <?php foreach ($items as $notification): ?>
                    <?php [$typeIcon, $typeLabel] = $types[$notification['type']] ?? ['bell', 'Update']; ?>
                    <article class="notice<?= $notification['is_read'] ? '' : ' is-new' ?>">
                        <span class="notice-icon notice-<?= e((string) $notification['type']) ?>"><?= icon($typeIcon) ?></span>
                        <div class="notice-body">
                            <div class="feed-row">
                                <div>
                                    <p class="notice-type">
                                        <?= e($typeLabel) ?>
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="badge badge-info">New</span>
                                        <?php endif; ?>
                                    </p>
                                    <h3><?= e($notification['title']) ?></h3>
                                    <p><?= e(strip_reminder_marker((string) $notification['message'])) ?></p>
                                    <p class="meta mb-0">
                                        <?= e($groupLabel === 'Today'
                                            ? date('g:i A', strtotime((string) $notification['created_at']))
                                            : format_datetime($notification['created_at'])) ?>
                                    </p>
                                </div>
                                <?php if ($notification['link_url']): ?>
                                    <a class="btn btn-outline btn-sm" href="<?= e($notification['link_url']) ?>">View details</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
