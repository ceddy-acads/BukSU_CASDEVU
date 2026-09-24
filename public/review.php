<?php
/**
 * CASDevU — Review queue: everything waiting on an office decision, oldest
 * first, across activities.
 *
 * Read-only. Each row links to the existing screen where the decision is
 * made (verify.php, participants.php, reservations.php, users.php), so the
 * approval rules, notifications, and audit logging all stay where they are.
 * Coordinators see only their assigned activities; reservations and account
 * approvals are office-only (includes/review.php).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$counts        = review_counts();
$documents     = review_pending_documents();
$registrations = review_pending_registrations();
$reservations  = review_pending_reservations();
$accounts      = review_pending_accounts();

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

$lanes = [
    ['documents',     'Documents to verify',       'documents',     'Students uploaded these and are waiting for your decision.'],
    ['registrations', 'Registrations to approve',  'registrations', 'Students asked to join these activities.'],
];
if (is_office_staff()) {
    $lanes[] = ['reservations', 'Reservation requests', 'reservations', 'Coordinators asked to reserve costumes or equipment.'];
    $lanes[] = ['accounts',     'New student accounts', 'accounts',     'These students cannot sign in until an account is activated.'];
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

<?php if ($counts['total'] === 0): ?>
    <div class="empty">
        <strong>You are all caught up</strong>
        No documents, registrations<?= is_office_staff() ? ', reservations, or accounts' : '' ?> are waiting for review.
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Go to activities</a>
        </div>
    </div>
<?php else: ?>
    <div class="stats<?= is_office_staff() ? '' : ' stats-3' ?>">
        <?php foreach ($lanes as [$key, $label, $anchor]): ?>
            <a class="stat <?= stat_tone($counts[$key], 'stat-gold') ?>" href="#<?= $anchor ?>">
                <span class="stat-value"><?= $counts[$key] ?></span>
                <span class="stat-label"><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <section class="card card-flush" id="documents">
        <div class="card-head">
            <h2>Documents to verify <span class="muted">(<?= $counts['documents'] ?>)</span></h2>
            <p class="hint">Students uploaded these and are waiting for your decision.</p>
        </div>
        <?php if ($documents === []): ?>
            <p class="muted card-pad">No documents are waiting.</p>
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
    </section>

    <section class="card card-flush" id="registrations">
        <div class="card-head">
            <h2>Registrations to approve <span class="muted">(<?= $counts['registrations'] ?>)</span></h2>
            <p class="hint">Students asked to join these activities.</p>
        </div>
        <?php if ($registrations === []): ?>
            <p class="muted card-pad">No registrations are waiting.</p>
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
    </section>

    <?php if (is_office_staff()): ?>
        <section class="card card-flush" id="reservations">
            <div class="card-head">
                <h2>Reservation requests <span class="muted">(<?= $counts['reservations'] ?>)</span></h2>
                <?php if ($reservations !== []): ?>
                    <a class="btn btn-primary btn-sm" href="<?= url('inventory/reservations.php?status=pending') ?>">Review reservations</a>
                <?php endif; ?>
                <p class="hint">Coordinators asked to reserve costumes or equipment.</p>
            </div>
            <?php if ($reservations === []): ?>
                <p class="muted card-pad">No reservation requests are waiting.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack">
                        <thead>
                            <tr><th>Requested by</th><th>For</th><th>Needed</th><th>Asked</th></tr>
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
                                <td data-label="Needed" class="nowrap">
                                    <?= e(format_date($row['needed_from'])) ?> to <?= e(format_date($row['needed_until'])) ?>
                                </td>
                                <td data-label="Asked"><?= e(waiting_label($row['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card card-flush" id="accounts">
            <div class="card-head">
                <h2>New student accounts <span class="muted">(<?= $counts['accounts'] ?>)</span></h2>
                <?php if ($accounts !== []): ?>
                    <a class="btn btn-primary btn-sm" href="<?= url('admin/users.php?status=pending') ?>">Review accounts</a>
                <?php endif; ?>
                <p class="hint">These students cannot sign in until an account is activated.</p>
            </div>
            <?php if ($accounts === []): ?>
                <p class="muted card-pad">No accounts are waiting for activation.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data table-stack">
                        <thead>
                            <tr><th>Student</th><th>Email</th><th>Registered</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $row): ?>
                            <tr>
                                <td data-label="Student">
                                    <strong><?= e($row['last_name'] . ', ' . $row['first_name']) ?></strong>
                                    <div class="hint"><?= e($row['student_number'] ?? '') ?></div>
                                </td>
                                <td data-label="Email"><?= e($row['email']) ?></td>
                                <td data-label="Registered"><?= e(waiting_label($row['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
