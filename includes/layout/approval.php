<?php
/** Caller supplies $approvalKind, $approvalId, $approvalState, $approvalReason. */
if ($approvalState !== 'approved' && $approvalState !== 'published'): ?>
<section class="card">
    <div class="card-head"><h2>Publication review</h2><?= status_badge($approvalState, 'approval') ?></div>
    <?php if ($approvalReason): ?><p><?= e($approvalReason) ?></p><?php endif; ?>
    <?php if ($approvalState === 'pending'): ?>
        <p>Pending approval by an administrator. This submission is not published.</p>
        <?php if (has_role('admin')): ?>
        <form method="post" action="<?= url('approvals.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="<?= e($approvalKind) ?>">
            <input type="hidden" name="id" value="<?= (int) $approvalId ?>">
            <input type="hidden" name="version" value="<?= (int) $approvalVersion ?>">
            <div class="form-row">
                <label for="approval-reason">Rejection reason (required when rejecting)</label>
                <textarea id="approval-reason" name="reason" maxlength="500"></textarea>
            </div>
            <div class="btn-row">
                <button class="btn btn-primary" name="decision" value="approve" data-loading="Approving…">Approve and publish</button>
                <button class="btn btn-outline" name="decision" value="reject" data-loading="Rejecting…">Reject</button>
            </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
