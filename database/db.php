<?php

/* Guard against double-loading: several pages include db.php again
 * after auth.php already loaded it (include, not require_once). Without
 * this guard every re-include would open a SECOND MySQL connection and
 * redo the session setup. */
if (defined('HEHMHS_DB_LOADED')) {
    return;   // $conn and the session are already set up
}
define('HEHMHS_DB_LOADED', true);

require_once __DIR__ . '/config.php';

/* ── Never leak errors to browsers in production ──────────────────
 * XAMPP/Laragon ship with display_errors=On, which prints stack
 * traces and SQL to visitors (an information-disclosure risk).
 * Local dev hosts keep visible errors for convenience; everything
 * else (the real domain) only writes to the PHP error log. */
if (!function_exists('hehmhs_is_dev_host')) {
    function hehmhs_is_dev_host(): bool {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || preg_match('/\.(test|local)$/', $host) === 1;
    }
}
if (!hehmhs_is_dev_host()) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Single source of truth: all credentials come from config.php.
// (Previously this file hardcoded "localhost/root/''/HEHMHS", which ignored
// config.php and silently disagreed with the PDO endpoints.)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    http_response_code(500);
    die("Connection failed. Please try again later.");
}

// Match the schema's utf8mb4 tables (mysqli defaults to latin1 otherwise,
// which mangles accented characters in student names).
$conn->set_charset('utf8mb4');

/* ── One timezone everywhere: Asia/Manila (+08:00) ──────────────────
 * PHP's date() and MySQL's NOW()/CURDATE() otherwise follow the
 * server's default (usually UTC) — on shared hosting that varies,
 * and it skewed dev timestamps by 8h. Pinning both here makes
 * date_requested / date_released, certificate dates and the monthly
 * report's month cutoff behave identically on every machine. */
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

/* ── Normalize user-supplied text to VALID UTF-8 ─────────────────────
 * MySQL's utf8mb4 tables reject byte sequences that are not valid
 * UTF-8 (classic case: text pasted from Windows-1252 sources, where
 * the en-dash is a lone 0x96 byte). Since PHP 8.1 mysqli throws on
 * such a query, one bad byte in e.g. "office hours" would otherwise
 * crash the whole endpoint with a white page. mb_convert_encoding
 * with UTF-8 → UTF-8 silently drops invalid sequences, keeping every
 * write path safe. Available everywhere (every script loads db.php). */
if (!function_exists('db_utf8')) {
    function db_utf8(?string $s): string
    {
        return mb_convert_encoding((string)$s, 'UTF-8', 'UTF-8');
    }
}
