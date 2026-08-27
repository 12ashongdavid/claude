<?php
// Handles utility bills — creating them for a tenant and marking them paid.
require_once __DIR__ . '/../config/database.php';
requireLogin();

header('Content-Type: application/json');
$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($user['role'], ['admin', 'staff'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $bill_type = $_POST['bill_type'] ?? 'water';
        $amount = floatval($_POST['amount'] ?? 0);
        $billing_month = $_POST['billing_month'] ?? date('Y-m');

        if ($tenant_id <= 0 || $amount <= 0) {
            echo json_encode(['error' => 'Please choose a tenant and enter a valid amount.']);
            exit;
        }

        // Residence is resolved from the tenant's active tenancy (one tenant = one residence)
        $stmt = $db->prepare("SELECT room_id FROM tenancies WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tenant_id]);
        $ten = $stmt->fetch();
        if (!$ten || !$ten['room_id']) {
            echo json_encode(['error' => 'This tenant has no active residence. Assign a room first.']);
            exit;
        }
        $room_id = intval($ten['room_id']);

        if (!preg_match('/^\d{4}-\d{2}$/', $billing_month) || $billing_month > date('Y-m')) {
            echo json_encode(['error' => 'Billing month cannot be in the future.']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO utility_bills (tenant_id, room_id, bill_type, amount, billing_month) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $room_id, $bill_type, $amount, $billing_month]);

        // Notify tenant in-app and by SMS
        $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $month_label = date('F Y', strtotime($billing_month . '-01'));
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Utility Bill Due', ?, 'utility', 'utilities.php')");
            $stmt->execute([$tenant_id, "Your $bill_type bill of " . formatCurrency($amount) . " for $month_label is now due."]);
            sendSMS($tenant['phone'], "Dear " . $tenant['full_name'] . ", your $bill_type bill of GH₵ " . number_format($amount, 2) . " for $month_label is now due. Please pay at the office. Thank you.");
        }

        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($action === 'mark_paid') {
        $id = intval($_POST['id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';

        $stmt = $db->prepare("UPDATE utility_bills SET status='paid', payment_date=?, payment_method=? WHERE id=?");
        $stmt->execute([$payment_date, $payment_method, $id]);

        // Notify tenant in-app and by SMS
        $stmt = $db->prepare("SELECT ub.tenant_id, ub.bill_type, ub.amount, ub.billing_month, u.full_name, u.phone FROM utility_bills ub JOIN users u ON ub.tenant_id = u.id WHERE ub.id = ?");
        $stmt->execute([$id]);
        $bill = $stmt->fetch();
        if ($bill) {
            $month_label = date('F Y', strtotime($bill['billing_month'] . '-01'));
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Utility Bill Paid', ?, 'utility', 'utilities.php')");
            $stmt->execute([$bill['tenant_id'], "Your " . $bill['bill_type'] . " bill of " . formatCurrency($bill['amount']) . " for $month_label has been marked as paid."]);
            sendSMS($bill['phone'], "Dear " . $bill['full_name'] . ", your " . $bill['bill_type'] . " bill of GH₵ " . number_format($bill['amount'], 2) . " for $month_label has been marked as paid. Thank you!");
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List utility bills
if (!in_array($user['role'], ['admin', 'staff'])) {
    $tenant_filter = $user['id'];
} else {
    $tenant_filter = $_GET['tenant_id'] ?? '';
}
$month = $_GET['billing_month'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "SELECT ub.*, u.full_name as tenant_name, r.room_number FROM utility_bills ub JOIN users u ON ub.tenant_id = u.id JOIN rooms r ON ub.room_id = r.id WHERE 1=1";
$params = [];

if ($tenant_filter) {
    $sql .= " AND ub.tenant_id = ?";
    $params[] = $tenant_filter;
}
if ($month) {
    $sql .= " AND ub.billing_month = ?";
    $params[] = $month;
}
if ($status) {
    $sql .= " AND ub.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY ub.billing_month DESC, ub.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

echo json_encode($bills);
