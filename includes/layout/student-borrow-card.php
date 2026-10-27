<?php
/** Student request details. The caller supplies the authorized reservation and its lines. */
$requestTitle = $lines !== [] ? (string) $lines[0]['name'] : 'Borrow request #' . (int) $reservation['reservation_id'];
if (count($lines) > 1) { $requestTitle .= ' + ' . (count($lines) - 1) . ' more'; }
$requestPurpose = trim((string) ($reservation['purpose'] ?? ''));
if ($requestPurpose === '') {
    $requestPurpose = trim((string) ($reservation['activity_title'] ?? '')) ?: 'No activity or purpose provided.';
}
$proposedReturn = trim((string) ($reservation['proposed_until'] ?? '')) ?: (string) $reservation['needed_until'];
$returnChanged = strtotime($proposedReturn) !== strtotime((string) $reservation['needed_until']);
?>
<?= status_badge($reservation['status'], 'reservation') ?>
<h2 class="item-title"><?= e($requestTitle) ?></h2>
<p class="meta preline request-purpose"><strong>For what?</strong> <?= e($requestPurpose) ?></p>

<dl class="detail-list item-body">
    <div>
        <dt>Proposed return</dt>
        <dd><?= e(format_datetime($proposedReturn)) ?></dd>
    </div>
    <?php if ($returnChanged): ?>
    <div>
        <dt>Approved return by</dt>
        <dd><?= e(format_datetime($reservation['needed_until'])) ?></dd>
    </div>
    <?php endif; ?>
    <div>
        <dt>Duration</dt>
        <dd><?= e(format_datetime($reservation['needed_from'])) ?> to <?= e(format_datetime($reservation['needed_until'])) ?></dd>
    </div>
    <div>
        <dt>Description</dt>
        <dd>
            <?php if ($lines === []): ?>
                No description provided.
            <?php else: ?>
                <?php foreach ($lines as $line): ?>
                    <?php if (count($lines) > 1): ?><strong><?= e($line['name']) ?></strong><?php endif; ?>
                    <p class="preline"><?= e(trim((string) ($line['description'] ?? '')) ?: 'No description provided.') ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </dd>
    </div>
    <div>
        <dt>Costume &amp; Equipment</dt>
        <dd>
            <?php if ($lines === []): ?>
                No items recorded for this request.
            <?php else: ?>
                <ul class="bullets">
                    <?php foreach ($lines as $line): ?>
                    <li>
                        <?= e($line['name']) ?> &mdash; <?= (int) $line['quantity'] ?> <?= e($line['unit']) ?>
                        <span class="muted small">(<?= e($line['item_code']) ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </dd>
    </div>
</dl>
