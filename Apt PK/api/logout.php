<?php
// Logs the user out by wiping the session and its cookie, then sends them back to the login page.
require_once __DIR__ . '/../config/database.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();
header('Location: ../login.php');
exit;
