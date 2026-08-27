<?php
// The tenant's own profile: view it, edit it, or change the password.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    if ($action === 'update') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $date_of_birth = $_POST['date_of_birth'] ?? null;

        if (empty($full_name) || empty($phone)) {
            mobileJsonOut(['error' => 'Name and phone are required.'], 400);
        }
        if (!validatePhone($phone)) {
            mobileJsonOut(['error' => 'Phone number must contain exactly 10 digits (numbers only).'], 400);
        }
        if (empty($email)) {
            mobileJsonOut(['error' => 'Email address is required.'], 400);
        }
        $emailCheck = validateEmailDetailed($email);
        if (!$emailCheck['valid']) {
            mobileJsonOut(['error' => $emailCheck['message']], 400);
        }
        if (!empty($date_of_birth) && !validateAge($date_of_birth)) {
            mobileJsonOut(['error' => 'Date of birth must indicate an age of at least 18 years.'], 400);
        }

        $db->prepare("UPDATE users SET full_name=?, email=?, phone=?, date_of_birth=? WHERE id=?")
            ->execute([$full_name, $email, $phone, $date_of_birth ?: null, $user['id']]);

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadError = validateUpload($_FILES['profile_picture']);
            if ($uploadError) {
                mobileJsonOut(['error' => $uploadError], 400);
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $ext = safeUploadExtension($finfo->file($_FILES['profile_picture']['tmp_name']));
            $filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
            $dest = UPLOAD_PATH . 'profiles/' . $filename;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                if ($user['profile_picture'] !== 'default.png') {
                    @unlink(UPLOAD_PATH . 'profiles/' . $user['profile_picture']);
                }
                $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$filename, $user['id']]);
            }
        }

        mobileJsonOut(['success' => true]);
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            mobileJsonOut(['error' => 'Current password is incorrect.'], 400);
        }
        if ($new_pass !== $confirm) {
            mobileJsonOut(['error' => 'New password and confirm password do not match.'], 400);
        }
        $passErrors = validatePassword($new_pass);
        if (!empty($passErrors)) {
            mobileJsonOut(['error' => 'Password must have: ' . implode(', ', $passErrors) . '.'], 400);
        }

        $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
            ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user['id']]);
        mobileJsonOut(['success' => true]);
    }

    mobileJsonOut(['error' => 'Invalid action.'], 400);
}

// GET: current profile
$stmt = $db->prepare("SELECT full_name, username, email, phone, date_of_birth, profile_picture, created_at, must_change_password FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
mobileJsonOut(['user' => $stmt->fetch()]);
