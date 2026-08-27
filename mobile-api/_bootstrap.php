<?php
// Shared setup for every mobile-api endpoint: CORS (safe here since auth
// is a bearer token the browser never attaches automatically, unlike a
// cookie), JSON response headers, and the auth() helper each endpoint
// calls to resolve the logged-in tenant from their token.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mobile_auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function mobileJsonOut($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Every mobile-api endpoint (except auth.php itself) starts by calling
// this. Dies with a 401/403 JSON response if the token is missing,
// unknown, or belongs to a non-tenant account — this app is tenant-only.
function requireMobileAuth() {
    $token = bearerTokenFromRequest();
    if (!$token) {
        mobileJsonOut(['error' => 'Missing authorization token.'], 401);
    }
    $user = getUserByApiToken($token);
    if (!$user) {
        mobileJsonOut(['error' => 'Your session has expired. Please log in again.'], 401);
    }
    if ($user['role'] !== 'tenant') {
        mobileJsonOut(['error' => 'This app is available to tenants only.'], 403);
    }
    return $user;
}
