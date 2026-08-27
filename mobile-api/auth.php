<?php
// Login and logout for the tenant mobile app. Issues a bearer token
// instead of a session cookie — see config/mobile_auth.php.
require_once __DIR__ . '/_bootstrap.php';

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $deviceLabel = trim($_POST['device_label'] ?? '');

    if (empty($username) || empty($password)) {
        mobileJsonOut(['error' => 'Please enter your username and password.'], 400);
    }
    if (!checkRateLimit('mobile_login_' . strtolower($username), 5, 300)) {
        mobileJsonOut(['error' => 'Too many attempts. Please try again in a few minutes.'], 429);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        mobileJsonOut(['error' => 'Incorrect username or password.'], 401);
    }
    if ($user['role'] !== 'tenant') {
        mobileJsonOut(['error' => 'This app is available to tenants only. Staff and admins should use the website.'], 403);
    }

    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    $token = createApiToken($user['id'], $deviceLabel !== '' ? $deviceLabel : null);

    mobileJsonOut([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'profile_picture' => $user['profile_picture'],
            'must_change_password' => (bool)$user['must_change_password'],
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    $token = bearerTokenFromRequest();
    revokeApiToken($token);
    mobileJsonOut(['success' => true]);
}

mobileJsonOut(['error' => 'Invalid request.'], 400);
