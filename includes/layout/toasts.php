<?php
/**
 * CASDevU — Flash messages as floating toasts (every layout).
 *
 * The message floats top-center over the page instead of pushing content
 * down; app.js dismisses each one after a few seconds, and the close
 * button dismisses it at once. The error tone uses role="alert" so it is
 * announced immediately; the rest use role="status".
 *
 * Set $toastsInline = true before including to show the messages in place
 * instead (the sign-in card): they sit in the flow of the panel and stay
 * until closed, so the form never jumps while someone is typing.
 */
declare(strict_types=1);

// A page fetched in the background (the shared popup loading a form, or
// following a save's redirect) leaves the messages for the page that is
// actually shown next.
$flashes = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' ? [] : take_flashes();
?>
<?php if ($flashes !== []): ?>
    <div class="toasts<?= !empty($toastsInline) ? ' toasts-inline' : '' ?>" data-toasts
         <?= !empty($toastsInline) ? 'data-toasts-inline' : '' ?>>
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
<?php // Raised from script (CASDevU.toast in app.js) for saves that do not reload the page. ?>
<template id="toast-template">
    <div class="toast" role="status" data-toast>
        <p></p>
        <button type="button" class="toast-close" data-toast-close aria-label="Dismiss notification">
            <?= icon('close') ?>
        </button>
    </div>
</template>
