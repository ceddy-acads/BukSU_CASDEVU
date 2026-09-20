<?php
/**
 * CASMS — Reports hub (FR-8.1 … FR-8.5)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$activityCount = (int) fetch_value('SELECT COUNT(*) FROM activities');
$studentCount  = (int) fetch_value(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.name = 'student'");
$itemCount     = (int) fetch_value('SELECT COUNT(*) FROM inventory_items');
$overdueCount  = overdue_borrowing_count();

$pageTitle = 'Reports';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p>Summaries for documentation and coordination. Every report can be printed or exported.</p>
    </div>
</div>

<div class="grid grid-3">
    <section class="card">
        <div class="card-head"><h2>Participation</h2></div>
        <p style="font-size:.9rem;">
            Registered and approved participants by activity, with a breakdown
            by year level and course.
        </p>
        <p class="hint"><?= number_format($activityCount) ?> activities on record</p>
        <a class="btn btn-primary btn-block" href="<?= url('reports/participation.php') ?>">
            Open report
        </a>
    </section>

    <section class="card">
        <div class="card-head"><h2>Student &amp; player records</h2></div>
        <p style="font-size:.9rem;">
            Search students, see how many activities each has joined, and open
            one student's full participation history.
        </p>
        <p class="hint"><?= number_format($studentCount) ?> students on record</p>
        <a class="btn btn-primary btn-block" href="<?= url('reports/students.php') ?>">
            Open report
        </a>
    </section>

    <?php if (is_office_staff()): ?>
        <section class="card">
            <div class="card-head"><h2>Inventory</h2></div>
            <p style="font-size:.9rem;">
                Owned, on loan, available, damaged and unavailable items, plus
                the full borrowing log.
            </p>
            <p class="hint">
                <?= number_format($itemCount) ?> items
                <?= $overdueCount > 0 ? ' · ' . $overdueCount . ' overdue' : '' ?>
            </p>
            <a class="btn btn-primary btn-block" href="<?= url('reports/inventory.php') ?>">
                Open report
            </a>
        </section>
    <?php endif; ?>
</div>

<div class="alert alert-info">
    <strong>Exports.</strong> CSV files open in Excel or Google Sheets and carry
    the filters you have applied. Use Print for a paper copy — the navigation
    and buttons are hidden automatically.
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
