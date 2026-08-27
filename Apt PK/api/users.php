<?php
// =====================================================
// API: Users (Tenants list, Profile update)
// =====================================================
require_once __DIR__ . '/../config/database.php';
requireLogin();

header('Content-Type: application/json');
$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $date_of_birth = $_POST['date_of_birth'] ?? null;

        if (empty($full_name) || empty($phone)) {
            echo json_encode(['error' => 'Name and phone are required.']);
            exit;
        }
        if (!validatePhone($phone)) {
            echo json_encode(['error' => 'Phone number must contain exactly 10 digits (numbers only).']);
            exit;
        }
        if (empty($email)) {
            echo json_encode(['error' => 'Email address is required.']);
            exit;
        }
        $emailCheck = validateEmailDetailed($email);
        if (!$emailCheck['valid']) {
            echo json_encode(['error' => $emailCheck['message']]);
            exit;
        }
        if (!empty($date_of_birth) && !validateAge($date_of_birth)) {
            echo json_encode(['error' => 'Date of birth must indicate an age of at least 18 years.']);
            exit;
        }

        $sql = "UPDATE users SET full_name=?, email=?, phone=?, date_of_birth=?";
        $params = [$full_name, $email, $phone, $date_of_birth ?: null];

        if (!empty($_POST['password'])) {
            $new_pass = $_POST['password'];
            $current = $_POST['current_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!password_verify($current, $user['password'])) {
                echo json_encode(['error' => 'Current password is incorrect.']);
                exit;
            }
            if ($new_pass !== $confirm) {
                echo json_encode(['error' => 'New password and confirm password do not match.']);
                exit;
            }
            $passErrors = validatePassword($new_pass);
            if (!empty($passErrors)) {
                echo json_encode(['error' => 'Password must have: ' . implode(', ', $passErrors) . '.']);
                exit;
            }
            $sql .= ", password=?, must_change_password=0";
            $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id=?";
        $params[] = $user['id'];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Handle profile picture
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadError = validateUpload($_FILES['profile_picture']);
            if ($uploadError) {
                echo json_encode(['error' => $uploadError]);
                exit;
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $ext = safeUploadExtension($finfo->file($_FILES['profile_picture']['tmp_name']));
            $filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
            $dest = UPLOAD_PATH . 'profiles/' . $filename;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                // Remove old picture
                $old = $user['profile_picture'];
                if ($old !== 'default.png') {
                    @unlink(UPLOAD_PATH . 'profiles/' . $old);
                }
                $stmt = $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$filename, $user['id']]);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_tenant') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $room_id = intval($_POST['room_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        $monthly_rent = floatval($_POST['monthly_rent'] ?? 0);
        $date_of_birth = $_POST['date_of_birth'] ?? null;

        if (empty($full_name) || empty($username) || empty($phone) || empty($email)) {
            echo json_encode(['error' => 'Name, username, phone, and email are required.']);
            exit;
        }
        if (!validatePhone($phone)) {
            echo json_encode(['error' => 'Phone number must contain exactly 10 digits (numbers only).']);
            exit;
        }
        $emailCheck = validateEmailDetailed($email);
        if (!$emailCheck['valid']) {
            echo json_encode(['error' => $emailCheck['message']]);
            exit;
        }
        if (!validateStartDate($start_date)) {
            echo json_encode(['error' => 'Start date cannot be more than 1 month in the past.']);
            exit;
        }
        if (empty($date_of_birth)) {
            echo json_encode(['error' => 'Date of birth is required — the tenant must be at least 18 years old.']);
            exit;
        }
        if (!validateAge($date_of_birth)) {
            echo json_encode(['error' => 'Tenant must be at least 18 years old to register.']);
            exit;
        }

        // A room is always required so the tenant can be billed and housed
        if ($room_id <= 0) {
            echo json_encode(['error' => 'Please select a room for the tenant.']);
            exit;
        }
        $stmt = $db->prepare("SELECT status FROM rooms WHERE id = ?");
        $stmt->execute([$room_id]);
        $roomStatus = $stmt->fetchColumn();
        if ($roomStatus === false) {
            echo json_encode(['error' => 'Selected room does not exist.']);
            exit;
        }
        if ($roomStatus !== 'available') {
            echo json_encode(['error' => 'Selected room is not available.']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'Username already exists.']);
            exit;
        }

        // Generate a temporary password and force the tenant to change it on first login
        $temp_password = generateTempPassword();
        $hashed = password_hash($temp_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, phone, date_of_birth, role, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 'tenant', 1)");
        $stmt->execute([$username, $hashed, $full_name, $email, $phone, $date_of_birth ?: null]);
        $tenant_id = $db->lastInsertId();

        // Create the tenancy and mark the room occupied
        $end_date = date('Y-m-d', strtotime($start_date . '+1 year'));
        $stmt = $db->prepare("INSERT INTO tenancies (tenant_id, room_id, start_date, end_date, monthly_rent, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$tenant_id, $room_id, $start_date, $end_date, $monthly_rent]);

        $stmt = $db->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$room_id]);

        // Send welcome SMS with temporary credentials
        sendSMS($phone, "Dear $full_name, your PK's Luxury Apartments tenant account has been created. Username: $username. Temporary password: $temp_password. Please log in and change your password.");

        echo json_encode(['success' => true, 'id' => $tenant_id]);
        exit;
    }

    if ($action === 'add_staff') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($full_name) || empty($username) || empty($phone) || empty($email)) {
            echo json_encode(['error' => 'Name, username, phone, and email are required.']);
            exit;
        }
        if (!validatePhone($phone)) {
            echo json_encode(['error' => 'Phone number must contain exactly 10 digits (numbers only).']);
            exit;
        }
        $emailCheck = validateEmailDetailed($email);
        if (!$emailCheck['valid']) {
            echo json_encode(['error' => $emailCheck['message']]);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'Username already exists.']);
            exit;
        }

        $temp_password = generateTempPassword();
        $hashed = password_hash($temp_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, phone, role, must_change_password) VALUES (?, ?, ?, ?, ?, 'staff', 1)");
        $stmt->execute([$username, $hashed, $full_name, $email, $phone]);
        $staff_id = $db->lastInsertId();

        sendSMS($phone, "Dear $full_name, your PK's Luxury Apartments staff account has been created. Username: $username. Temporary password: $temp_password. Please log in and change your password.");

        echo json_encode(['success' => true, 'id' => $staff_id]);
        exit;
    }

    if ($action === 'reset_password') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            echo json_encode(['error' => 'User not found.']);
            exit;
        }
        if (empty($target['phone'])) {
            echo json_encode(['error' => 'This user has no phone number to receive the password.']);
            exit;
        }

        $temp_password = generateTempPassword();
        $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
        $stmt->execute([password_hash($temp_password, PASSWORD_DEFAULT), $id]);

        sendSMS($target['phone'], "Dear " . $target['full_name'] . ", your PK's Luxury Apartments password has been reset. Temporary password: $temp_password. Please log in and change it.");

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'deactivate_user' || $action === 'activate_user') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id === $user['id']) {
            echo json_encode(['error' => 'Cannot ' . ($action === 'deactivate_user' ? 'deactivate' : 'activate') . ' your own account.']);
            exit;
        }
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $targetRole = $stmt->fetchColumn();
        if (!$targetRole) {
            echo json_encode(['error' => 'User not found.']);
            exit;
        }
        if ($user['role'] === 'staff' && $targetRole !== 'tenant') {
            http_response_code(403);
            echo json_encode(['error' => 'Staff can only manage tenant accounts.']);
            exit;
        }
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$action === 'deactivate_user' ? 0 : 1, $id]);

        if ($action === 'deactivate_user' && $targetRole === 'tenant') {
            // End any active tenancy and free up their residence
            $roomIds = $db->prepare("SELECT room_id FROM tenancies WHERE tenant_id = ? AND status = 'active'");
            $roomIds->execute([$id]);
            $freedRooms = $roomIds->fetchAll(PDO::FETCH_COLUMN);

            $db->prepare("UPDATE tenancies SET status = 'terminated', end_date = CURDATE() WHERE tenant_id = ? AND status = 'active'")->execute([$id]);

            if ($freedRooms) {
                $placeholders = implode(',', array_fill(0, count($freedRooms), '?'));
                $db->prepare("UPDATE rooms SET status = 'available' WHERE id IN ($placeholders) AND status = 'occupied'")->execute($freedRooms);
            }

            $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if ($target) {
                $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Deactivated', 'Your account has been deactivated. Please contact management for details.', 'warning')")->execute([$id]);
                sendSMS($target['phone'], "Dear " . $target['full_name'] . ", your PK's Luxury Apartments account has been deactivated. Please contact management for details.");
            }
        } elseif ($action === 'activate_user') {
            $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if ($target) {
                $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Reactivated', 'Your account has been reactivated. You may now log in.', 'success')")->execute([$id]);
                sendSMS($target['phone'], "Dear " . $target['full_name'] . ", your PK's Luxury Apartments account has been reactivated. You may now log in.");
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List users/tenants
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$role = $_GET['role'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT id, username, full_name, email, phone, role, profile_picture, date_of_birth, is_active, created_at FROM users WHERE 1=1";
$params = [];

if ($role) {
    $sql .= " AND role = ?";
    $params[] = $role;
}
if ($search) {
    $sql .= " AND (full_name LIKE ? OR username LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY full_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// For tenant lists, include current arrears so admin/staff can see balances
if ($role === 'tenant') {
    foreach ($users as &$u) {
        $u['owing'] = computeTenantArrears($u['id'])['owing'];
    }
    unset($u);
}

echo json_encode($users);
