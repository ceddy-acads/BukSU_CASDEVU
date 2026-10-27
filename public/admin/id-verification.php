<?php
/** Administrator review of student school IDs. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(['admin']);

if (is_post()) {
    csrf_verify();
    $id = (int) post('user_id');
    live_lock_revision('users', $id);
    $student = fetch_one('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?', [$id]);
    if ($student === null || $student['role_name'] !== 'student') { abort_page(404, 'Student not found.'); }
    $action = post('action');
    $reason = post('reject_reason');
    if (!in_array($action, ['approve', 'reject'], true)) { abort_page(400, 'Choose an ID review action.'); }
    if ($student['id_review_status'] !== 'pending' || !has_id_photos($student)) {
        flash('error', 'This ID is no longer awaiting review. Check the latest uploads before deciding.');
    } elseif ($action === 'reject' && ($reason === '' || mb_strlen($reason) > 1000)) {
        flash('error', 'Give a rejection reason of 1 to 1,000 characters.');
    } else {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        query('UPDATE users SET id_review_status = ?, id_reviewed_by = ?, id_reviewed_at = NOW(), id_reject_reason = ? WHERE user_id = ?',
            [$status, current_user_id(), $action === 'reject' ? $reason : null, $id]);
        audit_log($action, 'user', $id, 'School ID ' . $status);
        notify($id, 'system', 'School ID ' . $status,
            $action === 'approve' ? 'Your school ID is approved. You may now request items to borrow.' : mb_substr('Your school ID needs correction: ' . $reason, 0, 500),
            url('profile.php#school-id'));
        flash('success', full_name($student) . ': school ID ' . $status . '.');
    }
    $filter = post('status');
    redirect('admin/id-verification.php' . (in_array($filter, ['pending', 'approved', 'rejected'], true) ? '?status=' . $filter : ''));
}
$status = get('status');
if (!in_array($status, ['pending', 'approved', 'rejected'], true)) { $status = 'pending'; }
$students = fetch_all("SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.name = 'student' AND u.id_review_status = ? ORDER BY u.last_name, u.first_name, u.user_id", [$status]);
$pageTitle = 'School ID verification';
require __DIR__ . '/../../includes/layout/header.php';
?>
<div class="page-head"><div><h1>School ID verification</h1><p>Review both sides before approving an ID for borrowing.</p></div><a class="btn btn-outline" href="<?= url('review.php?lane=ids') ?>">Review queue</a></div>
<form method="get" class="filter-bar">
    <div class="form-row"><label for="id-status">Show</label><select id="id-status" name="status">
        <?php foreach (['pending' => 'Pending approval', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select></div><button class="btn btn-outline" type="submit">Filter</button>
</form>
<?php if ($students === []): ?>
<div class="empty"><strong>No <?= e($status) ?> school IDs</strong><?= $status === 'pending' ? 'Student IDs appear here after both sides are uploaded.' : 'No school IDs match this status. Choose another filter to review uploads.' ?></div>
<?php endif; ?>
<?php foreach ($students as $student): $id = (int) $student['user_id']; ?>
<section class="card" data-refresh-key="users:<?= $id ?>">
    <h2><?= e(full_name($student)) ?></h2>
    <p><?= e($student['student_number'] ?? 'No student number') ?> &middot; <?= status_badge($student['id_review_status'], 'id') ?></p>
    <div class="id-photos-grid">
        <?php foreach (['front', 'back'] as $side): ?>
        <div><h3><?= e(ucfirst($side)) ?></h3>
            <a href="<?= e(url('id-photo.php?user=' . $id . '&side=' . $side . '&v=' . rawurlencode(hash('sha256', (string) $student['id_' . $side . '_path'])))) ?>" target="_blank" rel="noopener">
                <img class="id-photos-preview" src="<?= e(url('id-photo.php?user=' . $id . '&side=' . $side . '&v=' . rawurlencode(hash('sha256', (string) $student['id_' . $side . '_path'])))) ?>" alt="<?= e(ucfirst($side) . ' of school ID for ' . full_name($student)) ?>" loading="lazy">
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($student['id_reject_reason']): ?><p class="alert alert-danger"><?= e($student['id_reject_reason']) ?></p><?php endif; ?>
    <?php if ($status === 'pending'): ?>
    <div class="form-actions">
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $id ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="status" value="<?= e($status) ?>"><button class="btn btn-primary" type="submit" data-loading="Saving…">Approve ID</button></form>
        <form method="post" class="id-reject-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $id ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="status" value="<?= e($status) ?>">
            <label for="id-reason-<?= $id ?>">Reason for rejection</label><input type="text" id="id-reason-<?= $id ?>" name="reject_reason" required maxlength="1000"><button class="btn btn-outline" type="submit" data-loading="Saving…">Reject ID</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
