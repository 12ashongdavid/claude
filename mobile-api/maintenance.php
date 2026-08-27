<?php
// Maintenance requests for the logged-in tenant: their own list, and
// submitting a new one.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'submit') {
        mobileJsonOut(['error' => 'Invalid action.'], 400);
    }

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
        mobileJsonOut(['error' => 'Please fill in all required fields.'], 400);
    }

    $stmt = $db->prepare("SELECT 1 FROM tenancies WHERE tenant_id = ? AND room_id = ? AND status = 'active'");
    $stmt->execute([$user['id'], $room_id]);
    if (!$stmt->fetch()) {
        mobileJsonOut(['error' => 'You may only submit requests for your own room.'], 403);
    }

    $stmt = $db->prepare("INSERT INTO maintenance_requests (tenant_id, room_id, category, subject, description, priority) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $room_id, $category, $subject, $description, $priority]);
    $reqId = $db->lastInsertId();

    $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $admin) {
        $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'New Maintenance Request', ?, 'maintenance', 'maintenance.php')")
            ->execute([$admin['id'], "Tenant submitted: $subject (Priority: $priority)"]);
        sendSMS($admin['phone'], "New maintenance request from " . $user['full_name'] . " (Room #$room_id): $subject. Priority: $priority");
    }

    mobileJsonOut(['success' => true, 'id' => $reqId]);
}

// GET: own requests + the room(s) they're allowed to file against
$stmt = $db->prepare("SELECT mr.*, r.room_number FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id WHERE mr.tenant_id = ? ORDER BY FIELD(mr.priority,'urgent','high','medium','low'), mr.created_at DESC");
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

$stmt = $db->prepare("SELECT r.id, r.room_number FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.tenant_id = ? AND t.status = 'active'");
$stmt->execute([$user['id']]);
$myRooms = $stmt->fetchAll();

mobileJsonOut(['requests' => $requests, 'rooms' => $myRooms]);
