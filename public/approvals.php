<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['admin']);
if (!is_post()) { header('Allow: POST'); abort_page(405, 'Use the review controls to make a decision.'); }
csrf_verify();
$kind = post('kind');
$idIn = post('id');
$decision = post('decision');
$reason = post('reason');
$version = post('version');
if (!ctype_digit($idIn) || (int) $idIn < 1 || !in_array($kind, ['activity', 'announcement'], true)) {
    abort_page(400, 'Invalid submission.');
}
$back = $kind === 'activity' ? 'activities/view.php?id=' . (int) $idIn : 'announcements/manage.php?id=' . (int) $idIn;
if ($decision === 'reject' && ($reason === '' || mb_strlen($reason) > 500)) {
    flash('error', 'Give a rejection reason of 500 characters or fewer.');
    redirect($back);
}
db()->beginTransaction();
try {
    if ($kind === 'activity') { require_activity_access((int) $idIn); }
    $changed = decide_publication($kind, (int) $idIn, $decision, $reason, ctype_digit($version) ? (int) $version : 0);
    db()->commit();
} catch (Throwable $error) {
    db()->rollBack();
    throw $error;
}
flash($changed ? 'success' : 'error', $changed
    ? ($decision === 'approve' ? 'Submission approved and published.' : 'Submission rejected. The author can revise and resubmit.')
    : 'This submission changed or was already decided, or its related activity still needs approval. Refresh and check its status.');
redirect($back);
