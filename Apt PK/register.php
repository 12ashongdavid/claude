<?php
// =====================================================
// Registration Page — disabled
// Tenant accounts are created by management only.
// =====================================================
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

setFlash('warning', 'Tenant accounts are created by apartment management only. Please contact the office to register.');
header('Location: login.php');
exit;
