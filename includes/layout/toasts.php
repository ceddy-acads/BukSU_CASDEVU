<?php
/**
 * CASDevU — Flash messages as floating toasts (every layout).
 *
 * The message floats top-center over the page instead of pushing content
 * down; app.js dismisses each one after a few seconds, and the close
 * button dismisses it at once. The error tone uses role="alert" so it is
 * announced immediately; the rest use role="status".
 */
declare(strict_types=1);

$flashes = take_flashes();
?>
<?php if ($flashes !== []): ?>
    <div class="toasts" data-toasts>
        <?php foreach ($flashes as $flashMessage): ?>
            <?php $copyText = (string) ($flashMessage['copy'] ?? ''); ?>
            <div class="toast toast-<?= e($flashMessage['type']) ?>"
                 role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>" data-toast>
                <p><?= e($flashMessage['message']) ?></p>
                <?php if ($copyText !== ''): ?>
                    <button type="button" class="toast-copy" data-toast-copy
                            data-toast-copy-text="<?= e($copyText) ?>" aria-label="Copy temporary password">
                        <?= icon('copy') ?>
                    </button>
                <?php endif; ?>
                <button type="button" class="toast-close" data-toast-close aria-label="Dismiss notification">
                    <?= icon('close') ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
