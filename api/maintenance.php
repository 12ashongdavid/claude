<?php
// =====================================================
// API: Maintenance Requests
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

    if ($action === 'submit') {
        // Tenant submits request
        $room_id = intval($_POST['room_id'] ?? 0);
        $category = $_POST['category'] ?? 'other';
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';

        $allowedCategories = ['plumbing', 'electrical', 'structural', 'pest_control', 'appliance', 'other'];
        $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'other';
        }
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'medium';
        }

        if (!$room_id || empty($subject) || empty($description)) {
            echo json_encode(['error' => 'Please fill in all required fields.']);
            exit;
        }

        if ($user['role'] === 'tenant') {
            $stmt = $db->prepare("SELECT 1 FROM tenancies WHERE tenant_id = ? AND room_id = ? AND status = 'active'");
            $stmt->execute([$user['id'], $room_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['error' => 'You may only submit requests for your own room.']);
                exit;
            }
        }

        $stmt = $db->prepare("INSERT INTO maintenance_requests (tenant_id, room_id, category, subject, description, priority) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user['id'], $room_id, $category, $subject, $description, $priority]);

        $req_id = $db->lastInsertId();

        // Notify admin
        $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($admins as $admin) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'New Maintenance Request', ?, 'maintenance', 'maintenance.php')");
            $stmt->execute([$admin['id'], "Tenant submitted: $subject (Priority: $priority)"]);
            sendSMS($admin['phone'], "New maintenance request from " . $user['full_name'] . " (Room #$room_id): $subject. Priority: $priority");
        }

        echo json_encode(['success' => true, 'id' => $req_id]);
        exit;
    }

    if ($action === 'update_status') {
        if (!in_array($user['role'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'submitted';
        $assigned_name = trim($_POST['assigned_name'] ?? '');
        $assigned_phone = trim($_POST['assigned_phone'] ?? '');

        if (!empty($assigned_phone) && !validatePhone($assigned_phone)) {
            echo json_encode(['error' => 'Assignee phone must be exactly 10 digits.']);
            exit;
        }

        $resolution_notes = trim($_POST['resolution_notes'] ?? '');

        $sql = "UPDATE maintenance_requests SET status=?";
        $params = [$status];

        $sql .= ", assigned_name=?, assigned_phone=?";
        $params[] = $assigned_name;
        $params[] = $assigned_phone;

        if ($status === 'resolved') {
            $sql .= ", resolved_date=NOW(), resolution_notes=?";
            $params[] = $resolution_notes;
        }

        $sql .= " WHERE id=?";
        $params[] = $id;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Notify tenant
        $stmt = $db->prepare("SELECT tenant_id FROM maintenance_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if ($req) {
            $status_msg = ucfirst(str_replace('_', ' ', $status));
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Request Updated', ?, 'maintenance', 'maintenance.php')");
            $stmt->execute([$req['tenant_id'], "Your maintenance request #$id status: $status_msg"]);

            $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $stmt->execute([$req['tenant_id']]);
            $tenant = $stmt->fetch();
            if ($tenant) {
                $sms_msg = "Dear " . $tenant['full_name'] . ", your maintenance request #$id status is now: $status_msg.";
                if (!empty($assigned_name)) {
                    $sms_msg .= " Assigned to: $assigned_name (" . ($assigned_phone ?: 'contact office') . ").";
                }
                sendSMS($tenant['phone'], $sms_msg);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List maintenance requests
$status_filter = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';

$sql = "SELECT mr.*, u.full_name as tenant_name, r.room_number FROM maintenance_requests mr JOIN users u ON mr.tenant_id = u.id JOIN rooms r ON mr.room_id = r.id WHERE 1=1";
$params = [];

if ($user['role'] === 'tenant') {
    $sql .= " AND mr.tenant_id = ?";
    $params[] = $user['id'];
}

if ($status_filter) {
    $sql .= " AND mr.status = ?";
    $params[] = $status_filter;
}
if ($priority) {
    $sql .= " AND mr.priority = ?";
    $params[] = $priority;
}

$sql .= " ORDER BY FIELD(mr.priority, 'urgent', 'high', 'medium', 'low'), mr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

echo json_encode($requests);
