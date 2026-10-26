<?php
/** Compatibility entry point: requirements now belong to each activity. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(['student']);
redirect('participation/my-activities.php');
