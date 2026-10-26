<?php
/**
 * CASMS — One invitation from an outside group
 *
 * The office reads it and decides; coordinators read what was posted. A
 * coordinator asking for a pending or rejected one gets the same 404 as for
 * an id that does not exist, so its existence is not revealed.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
if (!can_view_invitations()) {
    http_response_code(403);
    abort_page(403, 'You do not have permission to open invitations.');
}

$id         = get_id('id');
$invitation = $id !== null ? fetch_visible_invitation($id) : null;
if ($invitation === null) {
    http_response_code(404);
    abort_page(404, 'Invitation not found.');
}
$id       = (int) $invitation['invitation_id'];
$isOffice = is_office_staff();
$mayDecide = $isOffice && ($invitation['submitted_by'] === null || has_role('admin'));
$self     = 'invitations/view.php?id=' . $id;

// ------------------------------------------------------------------ Actions
if (is_post()) {
    csrf_verify();
    if (!$mayDecide) {
        http_response_code(403);
        abort_page(403, 'Only the office can act on invitations.');
    }

    $action = post('action');

    switch ($action) {
        case 'retry_email':
            db()->beginTransaction();
            $locked = fetch_one('SELECT * FROM invitations WHERE invitation_id = ? FOR UPDATE', [$id]);
            if ($locked === null || !in_array($locked['email_status'], ['logged', 'failed'], true)
                || !in_array($locked['status'], ['approved', 'rejected'], true)) {
                db()->rollBack();
                flash('error', 'There is no failed or logged decision email to retry.');
                redirect($self);
            }
            $state = invitation_send_decision($locked);
            query('UPDATE invitations SET email_status = ? WHERE invitation_id = ?', [$state, $id]);
            audit_log('retry_email', 'invitation', $id, 'Decision email: ' . $state);
            db()->commit();
            flash($state === 'sent' ? 'success' : 'error', $state === 'sent' ? 'Decision email sent.'
                : ($state === 'logged' ? 'Email recorded locally. Real email delivery is not configured.' : 'Email delivery failed. The approval is unchanged; try again later.'));
            break;
        case 'approve':
        case 'reject':
            $remarks = '';
            if ($action === 'reject') {
                $remarks = post('remarks');
                if ($remarks === '' || mb_strlen($remarks) > 500) {
                    flash('error', 'Give a reason; it is sent to the sender.');
                    redirect($self);
                }
            }
            $changed = query(
                "UPDATE invitations
                    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?, email_status = 'failed'
                  WHERE invitation_id = ? AND status = 'pending'",
                [$action === 'approve' ? 'approved' : 'rejected', current_user_id(),
                 $remarks !== '' ? $remarks : null, $id]
            )->rowCount();
            if ($changed === 0) {
                flash('error', 'This invitation was already decided.');
                redirect($self);
            }

            $row   = fetch_one('SELECT * FROM invitations WHERE invitation_id = ?', [$id]);
            $state = invitation_send_decision($row);
            query('UPDATE invitations SET email_status = ? WHERE invitation_id = ?', [$state, $id]);
            $emailed = $state === 'sent' ? ' The sender has been emailed.' : '';

            if ($action === 'approve') {
                invitation_notify_approved($row);
                audit_log('approve', 'invitation', $id, 'Approved invitation "' . $row['title'] . '"');
                flash('success', 'Invitation posted to the calendar. Admins and coordinators have been notified.' . $emailed);
            } else {
                audit_log('reject', 'invitation', $id, 'Rejected invitation "' . $row['title'] . '"');
                flash('success', 'Invitation rejected.' . $emailed);
            }
            break;

        case 'discard':
            $gone = query(
                "DELETE FROM invitations WHERE invitation_id = ? AND status = 'pending'",
                [$id]
            )->rowCount();
            if ($gone === 0) {
                flash('error', 'Only a pending invitation can be discarded.');
                break;
            }
            delete_upload($invitation['letter_path']);
            audit_log('discard', 'invitation', $id, 'Discarded as spam');
            flash('success', 'Invitation discarded. Nothing was sent.');
            redirect('review.php?lane=invitations');

        case 'cancel':
            $changed = query(
                "UPDATE invitations SET status = 'cancelled' WHERE invitation_id = ? AND status = 'approved'",
                [$id]
            )->rowCount();
            if ($changed === 0) {
                flash('error', 'Only a posted invitation can be cancelled.');
                break;
            }
            invitation_notify_cancelled($invitation);
            audit_log('cancel', 'invitation', $id, 'Cancelled invitation "' . $invitation['title'] . '"');
            flash('success', 'Invitation cancelled. It stays on the calendar, marked cancelled. No email was sent.');
            break;

        case 'contacted':
            $changed = query(
                "UPDATE invitations SET email_status = 'contacted'
                  WHERE invitation_id = ? AND email_status IN ('logged', 'failed')",
                [$id]
            )->rowCount();
            if ($changed === 0) {
                flash('error', 'Nothing to mark.');
                break;
            }
            audit_log('contacted', 'invitation', $id, 'Sender contacted by hand');
            flash('success', 'Marked as contacted.');
            break;

        default:
            abort_page(400, 'Unknown action.');
    }

    redirect($self);
}

// ------------------------------------------------------------------- Page
$reviewer = null;
if ($invitation['reviewed_by']) {
    $r = fetch_one('SELECT first_name, last_name FROM users WHERE user_id = ?', [(int) $invitation['reviewed_by']]);
    $reviewer = $r !== null ? $r['first_name'] . ' ' . $r['last_name'] : null;
}

$pageTitle = 'Invitation: ' . $invitation['title'];
require __DIR__ . '/../../includes/layout/header.php';

$status    = (string) $invitation['status'];
$byHand    = $mayDecide && in_array($invitation['email_status'], ['logged', 'failed'], true);
$startDate = strtotime((string) $invitation['start_at']);
$backUrl   = $isOffice
    ? url('review.php?lane=invitations')
    : url('activities/calendar.php?year=' . date('Y', $startDate) . '&month=' . (int) date('n', $startDate));
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= $backUrl ?>">&larr; <?= $isOffice ? 'Review queue' : 'Calendar' ?></a>
        <h1><?= e($invitation['title']) ?></h1>
        <p><?= $invitation['submitted_by'] !== null ? 'Program from ' : 'Invitation from ' ?><?= e($invitation['organization']) ?> <?= status_badge($status, $invitation['submitted_by'] !== null ? 'approval' : 'invitation') ?></p>
    </div>
</div>

<?php if ($byHand): ?>
    <div class="alert alert-warning">
        <div><strong>Email not sent: contact by hand.</strong>
            <?= $invitation['email_status'] === 'logged' ? 'Email is not switched on for this system.' : 'The email could not be sent.' ?>
            <a href="mailto:<?= e($invitation['email']) ?>"><?= e($invitation['email']) ?></a><?php if ($invitation['phone']): ?>,
            <a href="tel:<?= e($invitation['phone']) ?>"><?= e($invitation['phone']) ?></a><?php endif; ?>
        </div>
        <form method="post" action="<?= url($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="contacted">
            <button type="submit" class="btn btn-outline btn-sm" data-loading="Saving…">Mark as contacted</button>
        </form>
    </div>
<?php endif; ?>

<section class="card">
    <dl class="detail-list">
        <div><dt>Organization</dt><dd><?= e($invitation['organization']) ?></dd></div>
        <div><dt>Contact person</dt><dd><?= e($invitation['contact_name']) ?></dd></div>
        <div><dt>Email</dt><dd><a href="mailto:<?= e($invitation['email']) ?>"><?= e($invitation['email']) ?></a></dd></div>
        <div><dt>Phone</dt><dd>
            <?php if ($invitation['phone']): ?>
                <a href="tel:<?= e($invitation['phone']) ?>"><?= e($invitation['phone']) ?></a>
            <?php else: ?>Not given<?php endif; ?>
        </dd></div>
        <div><dt>When</dt><dd><?= e(invitation_when($invitation)) ?></dd></div>
        <div><dt>Place</dt><dd><?= e($invitation['place']) ?></dd></div>
        <div><dt>Details</dt><dd><?= nl2br(e($invitation['details'])) ?></dd></div>
        <div><dt>Letter</dt><dd>
            <?php if ($invitation['letter_path']): ?>
                <a href="<?= url('invitations/letter.php?id=' . $id) ?>" target="_blank" rel="noopener"><?= e($invitation['letter_name']) ?></a>
                (<?= e(format_filesize((int) $invitation['letter_size'])) ?>)
            <?php else: ?>No letter attached<?php endif; ?>
        </dd></div>
        <div><dt>Received</dt><dd><?= e(format_datetime($invitation['created_at'])) ?></dd></div>
        <?php if ($invitation['reviewed_at']): ?>
            <div><dt>Decided</dt><dd><?= e(($reviewer ?? 'Unknown') . ', ' . format_datetime($invitation['reviewed_at'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($status === 'rejected'): ?>
            <div><dt>Reason</dt><dd><?= nl2br(e((string) $invitation['remarks'])) ?></dd></div>
        <?php endif; ?>
    </dl>
</section>

<?php if ($mayDecide && $status === 'pending'): ?>
    <section class="card">
        <div class="card-head"><h2>Decide</h2></div>
        <div class="stack">
        <div>
        <p><?= $invitation['submitted_by'] !== null ? 'Approving adds this program to the guest’s calendar and emails the approval decision to their account address.' : 'Approving posts it to the calendar for admins and coordinators and emails the sender that it was received. It does not commit BukSU to attend.' ?></p>
        <form method="post" action="<?= url($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-primary" data-loading="Posting…">Approve</button>
        </form>
        </div>

        <form method="post" action="<?= url($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <div class="form-row">
                <label for="remarks">Reason for rejecting (sent to the sender)</label>
                <textarea id="remarks" name="remarks" maxlength="500" required></textarea>
            </div>
            <button type="submit" class="btn btn-outline" data-loading="Rejecting…">Reject</button>
        </form>

        <form method="post" action="<?= url($self) ?>"
              onsubmit="return confirm('Discard this invitation as spam? It is deleted with its letter, and nothing is sent.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="discard">
            <button type="submit" class="btn btn-outline">Discard as spam</button>
        </form>
        </div>
    </section>
<?php elseif ($mayDecide && $status === 'approved'): ?>
    <section class="card">
        <div class="card-head"><h2>Cancel</h2></div>
        <p>It stays on the calendar, marked cancelled. Admins and coordinators are told. No email is sent.</p>
        <form method="post" action="<?= url($self) ?>"
              onsubmit="return confirm('Cancel this invitation? It stays on the calendar, marked cancelled. No email is sent.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-outline" data-loading="Cancelling…">Cancel invitation</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($byHand && in_array($status, ['approved', 'rejected'], true)): ?>
<section class="card"><h2>Decision email</h2><p><?= $invitation['email_status'] === 'logged' ? 'Recorded locally; it has not been sent to an inbox.' : 'Delivery failed. The decision is saved.' ?></p>
<form method="post" action="<?= url($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="retry_email"><button class="btn btn-outline" type="submit" data-loading="Sending…">Retry decision email</button></form></section>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
