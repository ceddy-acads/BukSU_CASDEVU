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
    <div class="card">
        <div class="feed">
            <?php foreach ($notifications as $notification): ?>
                <article class="notice<?= $notification['is_read'] ? '' : ' is-new' ?>">
                    <div class="feed-row">
                        <div>
                            <h3>
                                <?php if (!$notification['is_read']): ?>
                                    <span class="badge badge-warning">New</span>
                                <?php endif; ?>
                                <?= e($notification['title']) ?>
                            </h3>
                            <p class="meta"><?= e(format_datetime($notification['created_at'])) ?></p>
                            <p><?= e(strip_reminder_marker((string) $notification['message'])) ?></p>
                        </div>
                        <?php if ($notification['link_url']): ?>
                            <a class="btn btn-outline btn-sm" href="<?= e($notification['link_url']) ?>">View details</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
