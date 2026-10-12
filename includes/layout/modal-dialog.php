<?php
/**
 * CASDevU — Shared popup shell (every "new …" button that opens one).
 *
 * A native <dialog>: backdrop, Esc to close and focus handling come free.
 * A trigger is an ordinary link to the form page with data-modal, plus
 * data-modal-title for the heading and data-modal-wrap for the fragment id
 * to pull out of the fetched page (e.g. #activity-form-wrap). app.js
 * intercepts the click, fetches the page and injects the fragment.
 * Without JavaScript (or without <dialog> support) the link just opens
 * the page. No data is needed here — the form arrives with the fetch.
 */
declare(strict_types=1);
?>
<dialog class="modal" id="modal-dialog" aria-labelledby="modal-dialog-title">
    <div class="modal-panel">
        <div class="modal-head">
            <h2 id="modal-dialog-title" data-modal-heading>Details</h2>
            <button type="button" class="icon-btn modal-close" data-modal-close aria-label="Close dialog">
                <?= icon('close') ?>
            </button>
        </div>
        <div class="modal-body" data-modal-body>
            <p class="muted">Loading…</p>
        </div>
    </div>
</dialog>
