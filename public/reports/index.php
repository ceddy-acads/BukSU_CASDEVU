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

<div class="stack">
    <section class="card">
        <div class="item">
            <div class="item-main">
                <h2 class="item-title">Participation</h2>
                <p class="mb-2">Registered and approved participants by activity, with a breakdown by year level and course.</p>
                <p class="meta"><?= number_format($activityCount) ?> activities on record</p>
            </div>
            <div class="btn-row">
                <a class="btn btn-primary" href="<?= url('reports/participation.php') ?>">Open report<span class="sr-only">: participation</span></a>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="item">
            <div class="item-main">
                <h2 class="item-title">Student &amp; player records</h2>
                <p class="mb-2">Search students, see how many activities each has joined, and open one student's full participation history.</p>
                <p class="meta"><?= number_format($studentCount) ?> students on record</p>
            </div>
            <div class="btn-row">
                <a class="btn btn-primary" href="<?= url('reports/students.php') ?>">Open report<span class="sr-only">: student and player records</span></a>
            </div>
        </div>
    </section>

    <?php if (is_office_staff()): ?>
        <section class="card">
            <div class="item">
                <div class="item-main">
                    <h2 class="item-title">Inventory</h2>
                    <p class="mb-2">Owned, on loan, available, damaged and unavailable items, plus the full borrowing log.</p>
                    <p class="meta">
                        <?= number_format($itemCount) ?> items<?php if ($overdueCount > 0): ?>
                            &middot; <span class="text-danger"><?= $overdueCount ?> overdue</span><?php endif; ?>
                    </p>
                </div>
                <div class="btn-row">
                    <a class="btn btn-primary" href="<?= url('reports/inventory.php') ?>">Open report<span class="sr-only">: inventory</span></a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong>Exports.</strong> CSV files open in Excel or Google Sheets and carry
        the filters you have applied. Use Print for a paper copy. The navigation
        and buttons are hidden automatically.
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
