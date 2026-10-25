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

// ---- On a live server, also set these (the defaults suit XAMPP only) ----

// The site's real name, used in email links and the HTTPS redirect.
// define('APP_HOSTNAME', 'casdevu.buksu.edu.ph');

// A database account of its own, never root with an empty password.
// define('DB_HOST', '127.0.0.1');
// define('DB_NAME', 'casms');
// define('DB_USER', 'casdevu');
// define('DB_PASS', 'a long random password');

// Once the server has a TLS certificate: send every visitor to HTTPS.
// define('FORCE_HTTPS', true);

// Where mysqldump and mysql live, for Backup (Linux: usually /usr/bin).
// define('MYSQL_BIN_PATH', '/usr/bin');

// 'development' prints error detail on screen. Never on a live server.
// define('APP_ENV', 'development');
