<?php
/**
 * CASMS — Sign out (FR-1.1)
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

logout_user();
start_session();                       // fresh session so the flash survives
flash('info', 'You have been signed out.');
redirect('login.php');
