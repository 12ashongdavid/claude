<?php
// =====================================================
// API: Tenant Agreements — upload / list / view / delete
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

// ---- View / download (inline streaming, auth enforced) ----
if (($_GET['action'] ?? '') === 'view') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT a.* FROM tenant_agreements a WHERE a.id = ?");
    $stmt->execute([$id]);
    $agreement = $stmt->fetch();
    if (!$agreement) {
        http_response_code(404);
        exit('Agreement not found.');
    }
    // Tenant can only view their own; admin/staff can view all
    if ($user['role'] === 'tenant' && (int)$agreement['tenant_id'] !== (int)$user['id']) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $path = UPLOAD_PATH . 'agreements/' . $agreement['stored_name'];
    if (!is_file($path)) {
        http_response_code(404);
        exit('File missing.');
    }
    $disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
    $safeName = str_replace(['"', '\\'], '', $agreement['original_name']);
    header('Content-Type: ' . $agreement['file_type']);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---- Remaining actions return JSON ----
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($tenant_id <= 0) {
            echo json_encode(['error' => 'Select a tenant.']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, full_name, phone FROM users WHERE id = ? AND role = 'tenant' AND is_active = 1");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            echo json_encode(['error' => 'Tenant not found.']);
            exit;
        }
        if (!isset($_FILES['agreement_file']) || $_FILES['agreement_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'Please choose an agreement document.']);
            exit;
        }
        $uploadError = validateAgreementUpload($_FILES['agreement_file']);
        if ($uploadError) {
            echo json_encode(['error' => $uploadError]);
            exit;
        }

        $orig = basename($_FILES['agreement_file']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $stored = 'agr_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = UPLOAD_PATH . 'agreements/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $dest = $dir . $stored;
        if (!move_uploaded_file($_FILES['agreement_file']['tmp_name'], $dest)) {
            echo json_encode(['error' => 'Could not save the uploaded file.']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($dest);
        $size = filesize($dest);

        $stmt = $db->prepare("INSERT INTO tenant_agreements (tenant_id, original_name, stored_name, file_size, file_type, uploaded_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $orig, $stored, $size, $mime, $user['id'], $notes ?: null]);
        $agreement_id = $db->lastInsertId();

        // Notify the tenant (in-app + SMS best-effort)
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Tenancy Agreement Uploaded', ?, 'info', 'dashboard.php')");
        $stmt->execute([$tenant_id, 'A tenancy agreement document has been uploaded to your dashboard.']);
        if (!empty($tenant['phone'])) {
            sendSMS($tenant['phone'], "Dear " . $tenant['full_name'] . ", your tenancy agreement has been uploaded to your dashboard at PK's Luxury Apartments.");
        }

        echo json_encode(['success' => true, 'id' => $agreement_id]);
        exit;
    }

    if ($action === 'delete') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM tenant_agreements WHERE id = ?");
        $stmt->execute([$id]);
        $agreement = $stmt->fetch();
        if (!$agreement) {
            echo json_encode(['error' => 'Agreement not found.']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM tenant_agreements WHERE id = ?");
        $stmt->execute([$id]);
        @unlink(UPLOAD_PATH . 'agreements/' . $agreement['stored_name']);
        echo json_encode(['success' => true]);
        exit;
    }
}

// ---- GET: tenant's own agreements ----
$action = $_GET['action'] ?? 'list';
if ($action === 'mine') {
    if ($user['role'] !== 'tenant') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $stmt = $db->prepare("SELECT id, tenant_id, original_name, file_size, file_type, notes, created_at FROM tenant_agreements WHERE tenant_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ---- GET: list all (admin/staff), optionally filtered by tenant ----
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$sql = "SELECT a.id, a.tenant_id, a.original_name, a.file_size, a.file_type, a.notes, a.created_at,
               u.full_name AS tenant_name, u.username AS tenant_username, ru.room_number,
               up.full_name AS uploaded_by_name
        FROM tenant_agreements a
        JOIN users u ON a.tenant_id = u.id
        LEFT JOIN users up ON a.uploaded_by = up.id
        LEFT JOIN tenancies t ON t.tenant_id = a.tenant_id AND t.status = 'active'
        LEFT JOIN rooms ru ON t.room_id = ru.id
        WHERE 1=1";
$params = [];
if (!empty($_GET['tenant_id'])) {
    $sql .= " AND a.tenant_id = ?";
    $params[] = intval($_GET['tenant_id']);
}
$sql .= " ORDER BY a.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
