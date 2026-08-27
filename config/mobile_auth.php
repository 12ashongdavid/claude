<?php
// Bearer-token issuing and lookup for the tenant mobile app. The web app
// uses PHP session cookies, which don't work well for a PWA that may be
// installed from a different origin than the API — so the mobile app
// gets its own lightweight token instead.

// Where the installed PWA lives. Paystack redirects the tenant's browser
// here after checkout, so this needs to be the real, public URL of
// mobile-app/ once it's deployed — update it before going live.
if (!defined('MOBILE_APP_URL')) {
    define('MOBILE_APP_URL', SITE_URL . '/mobile-app');
}
if (!defined('MOBILE_PAYSTACK_CALLBACK_URL')) {
    define('MOBILE_PAYSTACK_CALLBACK_URL', SITE_URL . '/mobile-api/paystack_verify.php?action=verify');
}

function createApiToken($userId, $deviceLabel = null) {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("INSERT INTO api_tokens (user_id, token_hash, device_label) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $hash, $deviceLabel]);
    return $token;
}

// Looks up the user for a bearer token and bumps last_used_at. Only
// returns active accounts, same as the web session's currentUser().
function getUserByApiToken($token) {
    if (empty($token)) {
        return null;
    }
    $db = getDB();
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT u.* FROM api_tokens t JOIN users u ON t.user_id = u.id WHERE t.token_hash = ? AND u.is_active = 1");
    $stmt->execute([$hash]);
    $user = $stmt->fetch();
    if ($user) {
        $db->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?")->execute([$hash]);
    }
    return $user;
}

function revokeApiToken($token) {
    if (empty($token)) {
        return;
    }
    $db = getDB();
    $db->prepare("DELETE FROM api_tokens WHERE token_hash = ?")->execute([hash('sha256', $token)]);
}

// Pulls the bearer token out of the Authorization header, wherever it
// ended up. Some hosts (this app targets shared cPanel hosting) strip
// custom headers unless the server config forwards them explicitly —
// see mobile-api/.htaccess for the matching rewrite rule.
function bearerTokenFromRequest() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    return null;
}
