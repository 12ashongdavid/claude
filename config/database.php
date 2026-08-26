<?php
// =====================================================
// Database Configuration
// PK's Luxury Apartments — Apartment Management System
// =====================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'pk_ams');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SITE_NAME', "PK's Luxury Apartments");

// Auto-detect base URL — always resolves to the site ROOT,
// even when this file is included from an /api/ script
if (!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname($appRoot)));
    $urlPath = '';
    if ($docRoot && strpos($appRoot, $docRoot) === 0) {
        $rel = trim(substr($appRoot, strlen($docRoot)), '/');
        if ($rel !== '') {
            $urlPath = '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
        }
    }
    define('SITE_URL', $protocol . '://' . $host . $urlPath);
}

define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// =====================================================
// mNotify SMS Configuration
// =====================================================
define('MNOTIFY_API_KEY', 'WmR0TbwuaoDoDJmwm38qhysfz');
define('MNOTIFY_SENDER_ID', 'PkluxuryAPT');
define('MNOTIFY_ENDPOINT', 'https://api.mnotify.com/api/sms/quick');

// =====================================================
// Paystack Configuration (Test keys)
// =====================================================
define('PAYSTACK_SECRET_KEY', 'sk_test_98b041aab15fb78624406cd3d0d56930f8e7642a');
define('PAYSTACK_PUBLIC_KEY', 'pk_test_37e1ef9de3ebdaa9ab1439031ecf4b6fb2a21f4f');
define('PAYSTACK_API', 'https://api.paystack.co');
define('PAYSTACK_CALLBACK_URL', SITE_URL . '/api/paystack.php?action=verify');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['_created'])) {
    $_SESSION['_created'] = time();
} elseif (time() - $_SESSION['_created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_created'] = time();
}

// Database connection (auto-creates database + tables if missing)
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
            $dsn .= ";dbname=" . DB_NAME;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Verify tables exist — if not, run bootstrap
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tables)) {
                $pdo = _bootstrapDatabase();
            }
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Unknown database') !== false) {
                $pdo = _bootstrapDatabase();
            } else {
                die("Database connection failed: " . $e->getMessage());
            }
        }
    }
    return $pdo;
}

/**
 * Auto-create the database, all tables, and seed data.
 * Called when getDB() detects the database doesn't exist or has no tables.
 */
function _bootstrapDatabase() {
    $dbName = DB_NAME;

    // 1. Connect to MySQL WITHOUT selecting a database
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        die("<h2>Cannot connect to MySQL server</h2><p>" . $e->getMessage() . "</p><p>Make sure MySQL/MariaDB is running (start Apache & MySQL in XAMPP).</p>");
    }

    // 2. Create the database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `$dbName`");

    // 3. Read and execute setup.sql
    $sqlFile = __DIR__ . '/../setup.sql';
    if (!is_file($sqlFile)) {
        die("<h2>setup.sql not found</h2><p>Expected at: " . htmlspecialchars($sqlFile) . "</p>");
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false || $sql === '') {
        die("<h2>Cannot read setup.sql</h2>");
    }

    // Strip the CREATE DATABASE and USE lines — we already handled those
    $sql = preg_replace('/CREATE DATABASE[^;]+;/', '', $sql);
    $sql = preg_replace('/USE\s+`?' . preg_quote($dbName, '/') . '`?\s*;/', '', $sql);

    // Remove comment-only lines (-- ...) but keep statement lines
    $lines = explode("\n", $sql);
    $filtered = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $filtered[] = $line;
    }
    $sql = implode("\n", $filtered);

    // Split into individual statements on semicolons
    $statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');

    $errors = [];
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $code = $e->getCode();
            // Suppress: table exists (42S01), column exists (42S21), duplicate key / FK (23000)
            if (in_array($code, ['42S01', '42S21', '23000'], true)) continue;
            $errors[] = $e->getMessage();
        }
    }

    if ($errors) {
        die("<h2>Database setup errors</h2><ul><li>" . implode("</li><li>", array_map('htmlspecialchars', $errors)) . "</li></ul>");
    }

    return $pdo;
}

// Flash messages
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate password strength (industry standard)
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'an uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'a lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'a number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'a special character';
    }
    return $errors;
}

// Get password strength score (0-4)
function getPasswordStrength($password) {
    $score = 0;
    if (strlen($password) >= 8) $score++;
    if (preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    return $score;
}

// Generate a secure temporary password (meets password policy: upper, lower, number, special)
function generateTempPassword($length = 10) {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $numbers = '23456789';
    $special = '!@#$%&*';
    $all = $upper . $lower . $numbers . $special;
    $password = $upper[random_int(0, strlen($upper) - 1)]
        . $lower[random_int(0, strlen($lower) - 1)]
        . $numbers[random_int(0, strlen($numbers) - 1)]
        . $special[random_int(0, strlen($special) - 1)];
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($password);
}

// Generate unique reference number
function generateRef($prefix = 'REF') {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Format currency (Ghana Cedis)
function formatCurrency($amount) {
    return 'GH₵ ' . number_format($amount, 2);
}

// Get current user
function currentUser() {
    if (isset($_SESSION['user_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Require login
function requireLogin() {
    if (!isLoggedIn()) {
        setFlash('warning', 'Please log in to access that page.');
        header('Location: login.php');
        exit;
    }
    // Force temporary-password users to set a new password first (page views only, not API calls)
    $isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
    if (!$isApi && basename($_SERVER['PHP_SELF']) !== 'profile.php') {
        $u = currentUser();
        if ($u && (int)$u['must_change_password'] === 1) {
            setFlash('warning', 'You are using a temporary password. Please change it now.');
            header('Location: profile.php');
            exit;
        }
    }
}

// Require specific role
function requireRole($roles) {
    requireLogin();
    if (!is_array($roles)) $roles = [$roles];
    $user = currentUser();
    if (!$user || !in_array($user['role'], $roles)) {
        setFlash('error', 'You do not have permission to access that page.');
        header('Location: dashboard.php');
        exit;
    }
}

// Validate phone number (exactly 10 digits, digits only)
function validatePhone($phone) {
    if (!preg_match('/^[0-9]+$/', $phone)) {
        return false;
    }
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return strlen($phone) === 10;
}

// Validate email (strict IT standards)
function validateEmail($email) {
    if (empty($email)) {
        return true;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return false;
    }
    $local = $parts[0];
    $domain = $parts[1];
    if (strlen($local) > 64 || strlen($domain) > 255) {
        return false;
    }
    if (preg_match('/\.\./', $local) || preg_match('/\.\./', $domain)) {
        return false;
    }
    $domainParts = explode('.', $domain);
    $tld = end($domainParts);
    if (strlen($tld) < 2 || strlen($tld) > 10) {
        return false;
    }
    return true;
}

// Validate that a start date is not more than 1 month in the past
function validateStartDate($date) {
    if (empty($date)) {
        return true;
    }
    $minDate = date('Y-m-d', strtotime('-1 month'));
    return $date >= $minDate;
}

// Calculate a person's age (in years) from a date of birth
function calculateAge($dob) {
    if (empty($dob)) {
        return null;
    }
    try {
        $d = new DateTime($dob);
        return $d->diff(new DateTime())->y;
    } catch (Exception $e) {
        return null;
    }
}

// Check that a date of birth indicates an age of at least $minAge
function validateAge($dob, $minAge = 18) {
    $age = calculateAge($dob);
    return $age !== null && $age >= $minAge;
}

// Months from month-string $a to month-string $b (b - a)
function ymDiffMonths($a, $b) {
    $pa = array_map('intval', explode('-', $a));
    $pb = array_map('intval', explode('-', $b));
    return ($pb[0] * 12 + $pb[1]) - ($pa[0] * 12 + $pa[1]);
}

// Compute a tenant's rent arrears (amount owing).
// A tenant is considered up-to-date when they have paid through the current
// month. Owing = unpaid months after the last covered month, up to now,
// capped at the tenancy move-in date. Advance payments yield 0.
function computeTenantArrears($tenantId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT monthly_rent, start_date FROM tenancies WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    $ten = $stmt->fetch();
    if (!$ten) {
        return ['owing' => 0.0, 'months' => 0, 'last_covered' => null];
    }

    $stmt = $db->prepare("SELECT MAX(month_covered) FROM rent_payments WHERE tenant_id = ? AND status = 'completed'");
    $stmt->execute([$tenantId]);
    $lastCovered = $stmt->fetchColumn();

    $nowYm = date('Y-m');
    $startYm = !empty($ten['start_date']) ? date('Y-m', strtotime($ten['start_date'])) : $nowYm;

    if (!$lastCovered) {
        $startOwingYm = $startYm;
    } elseif ($lastCovered >= $nowYm) {
        return ['owing' => 0.0, 'months' => 0, 'last_covered' => $lastCovered];
    } else {
        $startOwingYm = date('Y-m', strtotime($lastCovered . ' +1 month'));
    }

    if ($startOwingYm < $startYm) {
        $startOwingYm = $startYm; // never charge before move-in
    }
    if ($startOwingYm > $nowYm) {
        return ['owing' => 0.0, 'months' => 0, 'last_covered' => $lastCovered];
    }

    $months = ymDiffMonths($startOwingYm, $nowYm) + 1; // inclusive of current month
    $monthly = (float)$ten['monthly_rent'];
    return ['owing' => round($months * $monthly, 2), 'months' => $months, 'last_covered' => $lastCovered];
}

// Get every month (YYYY-MM) already covered by a completed rent payment for a tenant
function getTenantPaidMonths($tenantId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT DISTINCT month_covered FROM rent_payments WHERE tenant_id = ? AND status = 'completed' ORDER BY month_covered");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Determine the first month a tenant still needs to pay (contiguous extension point):
// current month if they are behind or have no payments yet, otherwise the month
// right after their latest covered month.
function getTenantNextDueMonth($tenantId) {
    $db = getDB();
    $nowYm = date('Y-m');
    $stmt = $db->prepare("SELECT MAX(month_covered) FROM rent_payments WHERE tenant_id = ? AND status = 'completed'");
    $stmt->execute([$tenantId]);
    $lastCovered = $stmt->fetchColumn();
    if (!$lastCovered || $lastCovered < $nowYm) {
        return $nowYm;
    }
    return date('Y-m', strtotime($lastCovered . ' +1 month'));
}

// Get the paid-through range as a human-readable string.
// Returns e.g. "Aug 2026 — Oct 2026" or just "Aug 2026" for a single month.
function getTenantPaidThroughRange($tenantId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT MIN(month_covered), MAX(month_covered) FROM rent_payments WHERE tenant_id = ? AND status = 'completed'");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row || empty($row[0])) return null;
    $from = date('M Y', strtotime($row[0] . '-01'));
    $to = date('M Y', strtotime($row[1] . '-01'));
    return $from === $to ? $from : "$from — $to";
}

// CSRF token generation
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF token validation
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Security headers (called on every page load)
function sendSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Rate limiting (simple file-based)
function checkRateLimit($key, $maxAttempts = 5, $windowSeconds = 300) {
    $file = sys_get_temp_dir() . '/rate_' . md5($key);
    $attempts = [];
    if (file_exists($file)) {
        $attempts = json_decode(file_get_contents($file), true) ?: [];
    }
    $now = time();
    $attempts = array_filter($attempts, fn($t) => $now - $t < $windowSeconds);
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    $attempts[] = $now;
    file_put_contents($file, json_encode($attempts));
    return true;
}

// Validate file upload (type + size)
function validateUpload($file, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], $maxSize = 5242880) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed.';
    }
    if ($file['size'] > $maxSize) {
        return 'File too large. Max 5MB.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedTypes)) {
        return 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP.';
    }
    return null;
}

// Validate agreement document upload (PDF, Word, or scanned images), max 10MB
function validateAgreementUpload($file, $maxSize = 10485760) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed.';
    }
    if ($file['size'] > $maxSize) {
        return 'File too large. Max 10MB.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    if (!in_array($mime, $allowed)) {
        return 'Invalid file type. Allowed: PDF, DOC, DOCX, or image (JPEG/PNG/GIF/WebP).';
    }
    return null;
}

// Send SMS via mNotify (non-blocking: releases session lock before cURL call)
function sendSMS($phone, $message, $sender = MNOTIFY_SENDER_ID) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) !== 10 || empty($message)) {
        return false;
    }

    $payload = json_encode([
        'recipient' => [$phone],
        'sender' => $sender,
        'message' => mb_substr($message, 0, 160),
        'is_schedule' => false,
        'schedule_date' => '',
    ]);

    $url = MNOTIFY_ENDPOINT . '?key=' . MNOTIFY_API_KEY;

    // Release session lock so other requests aren't blocked while SMS sends
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!$result) {
        return false;
    }
    $data = json_decode($result, true);
    return isset($data['status']) && $data['status'] === 'success';
}
