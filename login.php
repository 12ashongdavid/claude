<?php
// Login form and authentication handling.
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif (!checkRateLimit('login_' . $username, 5, 300)) {
            $error = 'Too many attempts. Please wait 5 minutes.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['_created'] = time();

                try { $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]); } catch (Exception $e) { /* column may not exist yet */ }

                if ((int)$user['must_change_password'] === 1) {
                    setFlash('warning', 'Welcome, ' . $user['full_name'] . '! You are using a temporary password. Please change it now.');
                    header('Location: profile.php');
                } else {
                    setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
$flash = getFlash();
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — PK's Luxury Apartments</title>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="css/style.css?v=18">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo">
            <h1><span class="brand-gold">PK's</span> Luxury Apartments</h1>
            <p>Apartment Management System</p>
        </div>
        <h2>Sign In</h2>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" value="<?= sanitize($username ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="passwordToggle" tabindex="-1" onclick="togglePasswordVisibility()" aria-label="Show password">
                        <i class="bx bx-show" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:8px;">Sign In</button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:0.8rem;color:rgba(255,255,255,0.7);">
            <a href="index.php">Back to home</a>
        </p>
    </div>
</div>
<script>
// Password visibility toggle. On Chrome/Edge we use a masked text input
// (-webkit-text-security) so the browser's native eye icon never appears.
var pwMaskedInput = false;
(function() {
    var p = document.getElementById('password');
    if (!p) return;
    if (/Chrome|CriOS|Edg/i.test(navigator.userAgent)) {
        p.type = 'text';
        p.classList.add('masked');
        pwMaskedInput = true;
    }
})();

function togglePasswordVisibility() {
    const p = document.getElementById('password');
    const icon = document.getElementById('passwordToggleIcon');
    if (pwMaskedInput) {
        const hidden = p.classList.contains('masked');
        p.classList.toggle('masked', !hidden);
        icon.className = hidden ? 'bx bx-hide' : 'bx bx-show';
    } else {
        const show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        icon.className = show ? 'bx bx-hide' : 'bx bx-show';
    }
    p.focus();
}
</script>
<script src="js/app.js?v=18"></script>
<script>
(function(){
    var saved = localStorage.getItem('pk-theme');
    var dark = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', dark === 'dark' ? 'dark' : 'light');
})();
</script>
</body>
</html>