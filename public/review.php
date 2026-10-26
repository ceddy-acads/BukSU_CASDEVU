<?php
/**
 * CASDevU — Review queue: everything waiting on an office decision, oldest
 * first, across activities.
 *
 * Read-only. Each row links to the existing screen where the decision is
 * made (verify.php, participants.php, reservations.php, users.php,
 * invitations/view.php), so the
 * approval rules, notifications, and audit logging all stay where they are.
 * Coordinators see only their assigned activities and the student equipment
 * requests for them; account approvals and outside invitations are
 * office-only (includes/review.php).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$counts        = review_counts();
$documents     = review_pending_documents();
$registrations = review_pending_registrations();
$reservations  = review_pending_reservations();
$accounts      = review_pending_accounts();
$invitations   = review_pending_invitations();
$unsentInvitations = review_unsent_invitations();

/** "Waiting 3 days", "Waiting since today": how long an item has sat. */
function waiting_label(?string $since): string
{
    if (!$since) {
        return '';
    }
    $days = (int) floor((time() - strtotime($since)) / 86400);
    return match (true) {
        $days <= 0 => 'Waiting since today',
        $days === 1 => 'Waiting 1 day',
        default => 'Waiting ' . $days . ' days',
    };
}

// One lane per kind of decision: a tab in the card's head, so the queue reads
// as one card instead of four stacked ones.
$lanes = [
    'documents'     => ['Documents', 'Students uploaded these and are waiting for your decision.'],
    'registrations' => ['Registrations', 'Students asked to join these activities.'],
];
$lanes['reservations'] = ['Reservations', is_office_staff()
    ? 'Coordinators and students asked to reserve costumes or equipment.'
    : 'Students asked to borrow equipment for your activities.'];
if (is_office_staff()) {
    $lanes['accounts']     = ['Accounts', 'These people cannot sign in until their account is activated.'];
    $lanes['invitations']  = ['Invitations', 'Outside groups invited BukSU students to these programs.'];
}
if (has_role('admin')) {
    $lanes['activities'] = ['Activities', 'Coordinators submitted these activities for publication approval.'];
    $lanes['announcements'] = ['Announcements', 'Coordinators chose Announce; these posts need publication approval.'];
}

// Open on whatever the URL asked for, else the first lane that has work.
$lane = get('lane');
if (!isset($lanes[$lane])) {
    $lane = 'documents';
    foreach (array_keys($lanes) as $key) {
        if ($counts[$key] > 0) {
            $lane = $key;
            break;
        }
    }
}

$pageTitle = 'Review queue';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Review queue</h1>
        <p>
            <?php if ($counts['total'] > 0): ?>
                <?= $counts['total'] ?> item<?= $counts['total'] === 1 ? '' : 's' ?> waiting for a decision, oldest first.
            <?php else: ?>
                Everything waiting for an office decision shows up here.
            <?php endif; ?>
            <?php if (!is_office_staff()): ?>
                Only the activities you coordinate are included.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php // Documents per open activity, including ones students have not sent yet. ?>
<?php $documentRows = document_status_rows(); ?>
<?php ob_start(); ?>
<?php if ($documentRows !== []): ?>
    <section class="card">
        <?= bar_chart(
            'Required documents by activity',
            'Every required document for pending and approved registrations in open activities, including those not submitted yet.',
            $documentRows
        ) ?>
    </section>
<?php endif; ?>
<?php $documentChart = (string) ob_get_clean(); ?>

<?php if ($counts['total'] === 0): ?>
    <div class="empty">
        <strong>You are all caught up</strong>
        Nothing is waiting for review in the categories available to you.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Go to activities</a>
        </div>
    </div>
<?= $documentChart ?>
<?php else: ?>
    <?= $documentChart ?>

    <section class="card card-flush">
        <div class="card-head">
            <nav class="tabs" aria-label="Show one category of the queue">
                <?php foreach ($lanes as $key => $copy): ?>
                    <a class="tab" href="<?= url('review.php?lane=' . $key) ?>"<?= $key === $lane ? ' aria-current="page"' : '' ?>>
                        <?= e($copy[0]) ?><span class="tab-count"><?= $counts[$key] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <p class="hint"><?= e($lanes[$lane][1]) ?></p>
        </div>

        <?php if (has_role('admin') && in_array($lane, ['activities', 'announcements'], true)): ?>
            <?php
            $submissions = $lane === 'activities'
                ? fetch_all("SELECT activity_id AS id, title, created_at FROM activities WHERE approval_status = 'pending' ORDER BY created_at LIMIT 50")
                : fetch_all("SELECT announcement_id AS id, title, created_at FROM announcements WHERE status = 'pending' ORDER BY created_at LIMIT 50");
            ?>
            <?php if ($submissions === []): ?>
                <div class="empty"><strong>No submissions waiting</strong>New coordinator submissions will appear here.</div>
            <?php else: ?>
                <div class="table-wrap"><table class="data table-stack"><thead><tr><th>Title</th><th>Submitted</th><th>Review</th></tr></thead><tbody>
                <?php foreach ($submissions as $submission): ?>
                    <tr><td data-label="Title"><?= e($submission['title']) ?></td><td data-label="Submitted"><?= e(format_datetime($submission['created_at'])) ?></td>
                    <td data-label="Review"><a class="btn btn-outline btn-sm" href="<?= url(($lane === 'activities' ? 'activities/view.php?id=' : 'announcements/manage.php?id=') . (int) $submission['id']) ?>">Review</a></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        <?php elseif ($lane === 'documents'): ?>
            <?php if ($documents === []): ?>
                <div class="empty">
                    <strong>No documents waiting</strong>
                    New uploads appear here as soon as students submit them.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr>
                                <th data-sort="text">Student</th><th data-sort="text">Document</th>
                                <th data-sort="text">Activity</th><th data-sort="number">Submitted</th>
                                <th class="actions"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $row): ?>
                            <tr>
                                <td data-label="Student">
                                    <strong><?= e($row['last_name'] . ', ' . $row['first_name']) ?></strong>
                                    <div class="hint"><?= e($row['student_number'] ?? '') ?></div>
                                </td>
                                <td data-label="Document">
                                    <?= e($row['requirement_name']) ?>
                                    <?php if ($row['original_name']): ?>
                                        <div class="hint"><?= e($row['original_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Activity"><?= e($row['activity_title']) ?></td>
                                <td data-label="Submitted" data-sort-value="<?= (int) strtotime((string) $row['submitted_at']) ?>">
                                    <span class="nowrap"><?= e(format_date($row['submitted_at'])) ?></span>
                                    <div class="hint"><?= e(waiting_label($row['submitted_at'])) ?></div>
                                </td>
                                <td class="actions">
                                    <a class="btn btn-primary btn-sm"
                                       href="<?= url('requirements/verify.php?activity_id=' . (int) $row['activity_id'] . '&status=pending') ?>"
                                       aria-label="Review <?= e($row['requirement_name']) ?> from <?= e($row['first_name'] . ' ' . $row['last_name']) ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($lane === 'registrations'): ?>
            <?php if ($registrations === []): ?>
                <div class="empty">
                    <strong>No registrations waiting</strong>
                    New sign-ups appear here as soon as students register.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr>
                                <th data-sort="text">Student</th><th data-sort="text">Activity</th>
                                <th data-sort="number">Activity date</th><th data-sort="number">Registered</th>
                                <th class="actions"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registrations as $row): ?>
                            <tr>
                                <td data-label="Student">
                                    <strong><?= e($row['last_name'] . ', ' . $row['first_name']) ?></strong>
                                    <div class="hint"><?= e($row['student_number'] ?? '') ?><?= $row['team_name'] ? ' · ' . e($row['team_name']) : '' ?></div>
                                </td>
                                <td data-label="Activity"><?= e($row['activity_title']) ?></td>
                                <td data-label="Activity date" data-sort-value="<?= (int) strtotime((string) $row['start_at']) ?>" class="nowrap">
                                    <?= e(format_date($row['start_at'])) ?>
                                </td>
                                <td data-label="Registered" data-sort-value="<?= (int) strtotime((string) $row['registered_at']) ?>">
                                    <span class="nowrap"><?= e(format_date($row['registered_at'])) ?></span>
                                    <div class="hint"><?= e(waiting_label($row['registered_at'])) ?></div>
                                </td>
                                <td class="actions">
                                    <a class="btn btn-primary btn-sm"
                                       href="<?= url('participation/participants.php?activity_id=' . (int) $row['activity_id'] . '&status=pending') ?>"
                                       aria-label="Review the registration of <?= e($row['first_name'] . ' ' . $row['last_name']) ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($lane === 'reservations'): ?>
            <?php if ($reservations === []): ?>
                <div class="empty">
                    <strong>No reservation requests waiting</strong>
                    <?php if (is_office_staff()): ?>
                    New requests appear here as soon as coordinators or students send them.
                    <?php else: ?>
                    Student equipment requests for your activities appear here as soon as they're sent.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr><th data-sort="text">Requested by</th><th data-sort="text">For</th><th data-sort="number">Needed</th><th data-sort="number">Asked</th>
                                <th class="actions"><span class="sr-only">Action</span></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $row): ?>
                            <tr>
                                <td data-label="Requested by"><strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong></td>
                                <td data-label="For">
                                    <?= $row['activity_title'] ? e($row['activity_title']) : '<span class="muted">No activity</span>' ?>
                                    <?php if ($row['purpose']): ?>
                                        <div class="hint"><?= e(mb_strimwidth((string) $row['purpose'], 0, 90, '…')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Needed" class="nowrap" data-sort-value="<?= (int) strtotime((string) $row['needed_from']) ?>">
                                    <?= e(format_date($row['needed_from'])) ?> to <?= e(format_date($row['needed_until'])) ?>
                                </td>
                                <td data-label="Asked" data-sort-value="<?= (int) strtotime((string) $row['created_at']) ?>"><?= e(waiting_label($row['created_at'])) ?></td>
                                <td class="actions">
                                    <a class="btn btn-primary btn-sm"
                                       href="<?= url('inventory/reservations.php?status=pending') ?>"
                                       aria-label="Review the reservation request of <?= e($row['first_name'] . ' ' . $row['last_name']) ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($lane === 'accounts'): ?>
            <?php if ($accounts === []): ?>
                <div class="empty">
                    <strong>No accounts waiting</strong>
                    New sign-ups appear here as soon as someone signs up.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr><th data-sort="text">Name</th><th data-sort="text">Email</th><th data-sort="number">Registered</th>
                                <th class="actions"><span class="sr-only">Action</span></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $row): ?>
                            <tr>
                                <td data-label="Name">
                                    <strong><?= e($row['last_name'] . ', ' . $row['first_name']) ?></strong>
                                    <div class="hint"><?= e(($row['student_number'] ?? '') !== '' ? $row['student_number'] : 'Guest') ?></div>
                                </td>
                                <td data-label="Email"><?= e($row['email']) ?></td>
                                <td data-label="Registered" data-sort-value="<?= (int) strtotime((string) $row['created_at']) ?>"><?= e(waiting_label($row['created_at'])) ?></td>
                                <td class="actions">
                                    <a class="btn btn-primary btn-sm"
                                       href="<?= url('admin/users.php?status=pending') ?>"
                                       aria-label="Review the account of <?= e($row['first_name'] . ' ' . $row['last_name']) ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($lane === 'invitations'): ?>
            <?php if ($invitations === [] && $unsentInvitations === []): ?>
                <div class="empty">
                    <strong>No invitations waiting</strong>
                    Invitations from outside groups appear here as soon as they are sent from the public form.
                </div>
            <?php endif; ?>
            <?php if ($invitations !== []): ?>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr><th data-sort="text">From</th><th data-sort="text">Program</th><th data-sort="number">When</th><th data-sort="number">Received</th>
                                <th class="actions"><span class="sr-only">Action</span></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invitations as $row): ?>
                            <tr>
                                <td data-label="From">
                                    <strong><?= e($row['organization']) ?></strong>
                                    <div class="hint"><?= e($row['contact_name']) ?></div>
                                </td>
                                <td data-label="Program">
                                    <?= e($row['title']) ?>
                                    <?php if ($row['place']): ?><div class="hint"><?= e($row['place']) ?></div><?php endif; ?>
                                    <?php if ($row['has_letter']): ?><div class="hint">Letter attached</div><?php endif; ?>
                                </td>
                                <td data-label="When" data-sort-value="<?= (int) strtotime((string) $row['start_at']) ?>"><?= e(invitation_when($row)) ?></td>
                                <td data-label="Received" data-sort-value="<?= (int) strtotime((string) $row['created_at']) ?>"><?= e(waiting_label($row['created_at'])) ?></td>
                                <td class="actions">
                                    <a class="btn btn-primary btn-sm"
                                       href="<?= url('invitations/view.php?id=' . (int) $row['invitation_id']) ?>"
                                       aria-label="Review the invitation from <?= e($row['organization']) ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($unsentInvitations !== []): ?>
                <div class="card-head">
                    <h2>Email not sent: contact by hand</h2>
                    <p class="hint">The sender has not been told the decision. Reach them, then mark them as contacted.</p>
                </div>
                <div class="table-wrap">
                    <table class="data table-stack" data-sortable>
                        <thead>
                            <tr><th data-sort="text">From</th><th data-sort="text">Decision</th><th>Contact</th>
                                <th class="actions"><span class="sr-only">Action</span></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unsentInvitations as $row): ?>
                            <tr>
                                <td data-label="From">
                                    <strong><?= e($row['organization']) ?></strong>
                                    <div class="hint"><?= e($row['contact_name']) ?></div>
                                </td>
                                <td data-label="Decision"><?= status_badge($row['status'], 'invitation') ?></td>
                                <td data-label="Contact">
                                    <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
                                    <?php if ($row['phone']): ?><div class="hint"><a href="tel:<?= e($row['phone']) ?>"><?= e($row['phone']) ?></a></div><?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a class="btn btn-outline btn-sm"
                                       href="<?= url('invitations/view.php?id=' . (int) $row['invitation_id']) ?>"
                                       aria-label="Open the invitation from <?= e($row['organization']) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
