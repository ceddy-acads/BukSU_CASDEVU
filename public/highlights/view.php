<?php
/**
 * CASDevU — Highlights: one report as a page-turning flipbook
 *
 * Public by design: no require_login(), like the cover grid and the PDF
 * route. The pages are drawn in the browser by highlights.js (PDF.js) and
 * turned by StPageFlip; the server sends only the PDF, by range requests.
 * Download PDF sits outside the book and is the accessible way to read it.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('activity_id');
$report     = $activityId !== null ? published_highlight($activityId) : null;
if ($report === null) {
    abort_page(404, 'This highlight report is not available.');
}

$fileUrl   = highlight_file_url($report);
$pageTitle = (string) $report['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>
<div class="page-head photo-band flip-head"<?= photo_band_style(category_photo((string) $report['category'])) ?>>
    <div>
        <h1><?= e($report['title']) ?></h1>
        <p><?= e($report['activity_title']) ?> · <span class="nowrap"><?= e(format_date($report['start_at'])) ?></span></p>
    </div>
    <?php if (is_logged_in()): ?>
        <div class="btn-row">
            <a class="btn btn-outline" href="<?= url('activities/view.php?id=' . $activityId) ?>">About this activity</a>
        </div>
    <?php endif; ?>
</div>

<section class="flipbook" data-flipbook data-pdf="<?= e($fileUrl) ?>"
         data-hl-lib="<?= e(asset('vendor/pdfjs/pdf.min.mjs')) ?>" data-hl-worker="<?= e(asset('vendor/pdfjs/pdf.worker.min.mjs')) ?>"
         aria-label="Report pages">
    <div class="flip-stage" data-flip-stage></div>
    <noscript><p class="flip-noscript">The page-turning view needs JavaScript. Use Download PDF below to read the report.</p></noscript>
    <div class="flip-controls">
        <button type="button" class="btn flip-btn" data-flip-prev disabled><?= icon('chevron-right', 'icon flip') ?><span class="sr-only">Previous page</span></button>
        <p class="flip-status" data-flip-status role="status">Loading the report…</p>
        <button type="button" class="btn flip-btn" data-flip-next disabled><?= icon('chevron-right') ?><span class="sr-only">Next page</span></button>
    </div>
</section>

<div class="btn-row flip-actions">
    <a class="btn btn-outline" href="<?= e(highlight_file_url($report, true)) ?>">Download PDF (<?= e(format_filesize((int) $report['file_size'])) ?>)</a>
    <a class="btn btn-outline" href="<?= url('highlights/index.php') ?>">All highlights</a>
</div>

<script src="<?= asset('vendor/page-flip/page-flip.browser.js') ?>" defer></script>
<script src="<?= asset('js/highlights.js') ?>" defer></script>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
