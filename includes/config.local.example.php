<?php
/**
 * CASDevU — Local settings template.
 *
 * Copy this file to config.local.php (same folder) and fill in the values.
 * config.local.php is git-ignored, so the password never reaches the repo.
 *
 * Gmail setup (one time):
 *   1. Sign in to the Gmail account the system should send from.
 *   2. Turn on 2-Step Verification: https://myaccount.google.com/security
 *   3. Create an App Password:       https://myaccount.google.com/apppasswords
 *      (name it "CASDevU"). Google shows 16 letters; paste them below.
 *   4. Test it:  C:\xampp\php\php.exe bin\test-mail.php you@example.com
 */

declare(strict_types=1);

define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USERNAME', 'your.account@gmail.com');
define('SMTP_PASSWORD', 'abcd efgh ijkl mnop');   // the App Password, not your normal password

// Optional: the name recipients see.
// define('MAIL_FROM_NAME', 'BukSU Culture, Arts, and Sports Development Unit');
