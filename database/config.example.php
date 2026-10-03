<?php
/* ── HEHMS central configuration — TEMPLATE ────────────────────────
 * Copy this file to config.php and fill in the real values:
 *     cp config.example.php config.php
 * config.php is gitignored — real credentials must NEVER be committed.
 * ────────────────────────────────────────────────────────────────── */

// ── Database ──
// Production: create a dedicated MySQL user with a strong password
// (GRANT SELECT,INSERT,UPDATE,DELETE ON hehms.* TO 'hehms_app'@'localhost';)
// instead of root with an empty password (XAMPP default, local dev only).
define('DB_HOST', 'localhost');
define('DB_NAME', 'hehms');
define('DB_USER', 'hehms_app');
define('DB_PASS', 'CHANGE_ME');

// ── SMTP (mail sending) ──
// Gmail app password: Google Account → Security → 2-Step Verification →
// App passwords. If the password below is ever exposed, revoke it in the
// Google account and generate a new one.
define('SMTP_HOST',   'smtp.gmail.com');
define('SMTP_USER',   'you@gmail.com');
define('SMTP_PASS',   'GENERATED_APP_PASSWORD');
define('SMTP_PORT',   587);
define('SMTP_SECURE', 'tls'); // STARTTLS
define('SMTP_FROM',   'you@gmail.com');
define('SMTP_FROM_NAME', 'HEHMS');
