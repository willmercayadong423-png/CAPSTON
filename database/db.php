<?php

require_once __DIR__ . '/config.php';

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
// (Previously this file hardcoded "localhost/root/''/HEHMS", which ignored
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
