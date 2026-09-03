<?php
// Registration is closed to the public — tenant accounts are created by management, so just bounce back to login.
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

setFlash('warning', 'Tenant accounts are created by apartment management only. Please contact the office to register.');
header('Location: login.php');
exit;
