<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(['guest']);
$programs = fetch_all('SELECT invitation_id, title, start_at, place, status, remarks FROM invitations WHERE submitted_by = ? ORDER BY created_at DESC', [current_user_id()]);
$pageTitle = 'My programs';
require __DIR__ . '/../../includes/layout/header.php';
?>
<div class="page-head"><div><h1>My programs</h1><p>Track your program submissions and administrator decisions.</p></div>
<div class="btn-row"><a class="btn btn-outline" href="<?= url('activities/calendar.php') ?>">Calendar</a><a class="btn btn-gold" href="<?= url('invitations/submit.php') ?>">Add program</a></div></div>
<?php if ($programs === []): ?>
<div class="empty"><strong>No programs submitted yet</strong>Use Add program to send your program to an administrator for approval.</div>
<?php else: ?>
<section class="card"><div class="table-wrap"><table class="data table-stack"><thead><tr><th>Program</th><th>When</th><th>Place</th><th>Status</th><th>Decision</th></tr></thead><tbody>
<?php foreach ($programs as $program): ?>
<tr><td data-label="Program"><a href="<?= url('invitations/view.php?id=' . (int) $program['invitation_id']) ?>"><?= e($program['title']) ?></a></td>
<td data-label="When"><?= e(format_datetime($program['start_at'])) ?></td><td data-label="Place"><?= e($program['place']) ?></td>
<td data-label="Status"><?= status_badge($program['status'], 'approval') ?></td><td data-label="Decision"><?= e($program['remarks'] ?? '—') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
