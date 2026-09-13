<?php
require_once __DIR__ . '/auth.php'; // starts session with correct cookie params

session_unset();
session_destroy();

if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/', '',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
}

session_start();
$_SESSION['flash'] = "You have been logged out.";

header("Location: " . base_url('mainPage.php') . "?loggedout=1");
exit();
