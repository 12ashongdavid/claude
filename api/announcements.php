<?php
// =====================================================
// API: Announcements
// PK's Luxury Apartments — Apartment Management System
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

    if ($action === 'add') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $target_tenant_id = intval($_POST['target_tenant_id'] ?? 0);

        if (empty($title) || empty($content)) {
            echo json_encode(['error' => 'Title and content are required.']);
            exit;
        }

        if ($target_tenant_id > 0) {
            $stmt = $db->prepare("INSERT INTO announcements (title, content, target_tenant_id, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $content, $target_tenant_id, $user['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO announcements (title, content, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$title, $content, $user['id']]);
        }
        $announcement_id = $db->lastInsertId();

        // Notify targeted tenant only, or all active tenants (in-app + SMS)
        if ($target_tenant_id > 0) {
            $tenants = $db->prepare("SELECT id, full_name, phone FROM users WHERE id = ? AND role = 'tenant' AND is_active = 1");
            $tenants->execute([$target_tenant_id]);
            $tenants = $tenants->fetchAll();
        } else {
            $tenants = $db->query("SELECT id, full_name, phone FROM users WHERE role = 'tenant' AND is_active = 1")->fetchAll();
        }
        foreach ($tenants as $tenant) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Announcement', ?, 'announcement', 'announcements.php')");
            $stmt->execute([$tenant['id'], "$title: " . mb_substr($content, 0, 200)]);
            if (!empty($tenant['phone'])) {
                sendSMS($tenant['phone'], "ANNOUNCEMENT from PK's Luxury Apartments: $title. " . mb_substr($content, 0, 140));
            }
        }

        echo json_encode(['success' => true, 'id' => $announcement_id]);
        exit;
    }

    if ($action === 'toggle') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Only admins can delete announcements.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List announcements (with author + targeted recipient names)
$sql = "SELECT a.*, u.full_name AS author, t.full_name AS target_name
        FROM announcements a
        JOIN users u ON a.created_by = u.id
        LEFT JOIN users t ON a.target_tenant_id = t.id
        WHERE 1=1";
$params = [];
if ($user['role'] === 'tenant') {
    $sql .= " AND (a.target_tenant_id IS NULL OR a.target_tenant_id = ?)";
    $params[] = $user['id'];
}
$sql .= " ORDER BY a.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

echo json_encode($announcements);
