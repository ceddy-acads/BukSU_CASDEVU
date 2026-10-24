<?php
/**
 * CASDevU — Highlights: the unit's posted highlight reports as a cover grid
 *
 * Public by design: no require_login(). Anyone, signed in or not, may read a
 * report once it is posted and its activity is visible (HIGHLIGHT_STATUSES).
 * Each cover is page 1 of the PDF, drawn in the browser by highlights.js; the
 * navy card with the report title stays when it cannot be drawn.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$reports   = published_highlights();
$canPost   = is_office_staff() || has_role('coordinator');

$pageTitle = 'Highlights';
require __DIR__ . '/../../includes/layout/header.php';
?>
<div class="page-head">
    <div>
        <h1>Highlights</h1>
        <p>Reports on the unit's culture, arts and sports activities, posted after each event.</p>
    </div>
    <?php if ($canPost): ?>
        <div class="btn-row">
            <a class="btn btn-gold" href="<?= url('highlights/manage.php') ?>">Post a report</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($reports === []): ?>
    <div class="empty">
        <strong>No highlight report has been posted yet</strong>
        The office or an activity's coordinator posts a report after the activity.
        <?php if ($canPost): ?>
            <div><a class="btn btn-outline" href="<?= url('highlights/manage.php') ?>">Choose an activity</a></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <ul class="hl-grid" data-hl-lib="<?= e(asset('vendor/pdfjs/pdf.min.mjs')) ?>" data-hl-worker="<?= e(asset('vendor/pdfjs/pdf.worker.min.mjs')) ?>">
        <?php foreach ($reports as $report): ?>
            <?php $id = (int) $report['activity_id']; ?>
            <li class="hl-card <?= e(category_field((string) $report['category'])) ?>">
                <a class="hl-link" href="<?= url('highlights/view.php?activity_id=' . $id) ?>">
                    <span class="hl-cover" aria-hidden="true">
                        <span class="hl-cover-title"><?= e($report['title']) ?></span>
                        <canvas data-hl-cover data-pdf="<?= e(url('highlights/file.php?activity_id=' . $id)) ?>"></canvas>
                    </span>
                    <span class="hl-title"><?= e($report['title']) ?></span>
                    <span class="hl-meta"><?= e($report['activity_title']) ?> · <span class="nowrap"><?= e(format_date($report['start_at'])) ?></span></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <script src="<?= asset('js/highlights.js') ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
