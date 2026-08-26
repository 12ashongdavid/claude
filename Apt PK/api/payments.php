<?php
// =====================================================
// API: Payments CRUD
// =====================================================
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

    $action = $_POST['action'] ?? '';

    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }

    if ($action === 'record') {
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $room_id = intval($_POST['room_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $month_covered = $_POST['month_covered'] ?? date('Y-m');
        $notes = trim($_POST['notes'] ?? '');

        if ($tenant_id <= 0 || $room_id <= 0 || $amount <= 0) {
            echo json_encode(['error' => 'Please fill in all required fields.']);
            exit;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month_covered) && $month_covered > date('Y-m', strtotime('+11 months'))) {
            echo json_encode(['error' => 'Advance rent is limited to one year from now.']);
            exit;
        }

        // Never allow a second (completed) payment for the same tenant and month
        $stmt = $db->prepare("SELECT id FROM rent_payments WHERE tenant_id = ? AND month_covered = ? AND status = 'completed'");
        $stmt->execute([$tenant_id, $month_covered]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => $month_covered . ' is already paid for this tenant. Select a different month.']);
            exit;
        }

        $ref = generateRef('RNT');
        $stmt = $db->prepare("INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, reference_number, month_covered, status, received_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)");
        $stmt->execute([$tenant_id, $room_id, $amount, $payment_date, $payment_method, $ref, $month_covered, $user['id'], $notes]);

        $payment_id = $db->lastInsertId();

        // Create notification for tenant
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Payment Received', ?, 'payment')");
        $stmt->execute([$tenant_id, "Your rent payment of " . formatCurrency($amount) . " for $month_covered has been recorded. Ref: $ref"]);

        // Send SMS confirmation to tenant
        $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            sendSMS($tenant['phone'], "Dear " . $tenant['full_name'] . ", your rent payment of GH₵ " . number_format($amount, 2) . " for $month_covered has been received. Ref: $ref. Thank you!");
        }

        echo json_encode(['success' => true, 'id' => $payment_id, 'reference' => $ref]);
        exit;
    }
}

// GET: List payments
if (!in_array($user['role'], ['admin', 'staff'])) {
    $tenant_filter = $user['id'];
} else {
    $tenant_filter = $_GET['tenant_id'] ?? '';
}
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT rp.*, u.full_name as tenant_name, r.room_number FROM rent_payments rp JOIN users u ON rp.tenant_id = u.id JOIN rooms r ON rp.room_id = r.id WHERE 1=1";
$params = [];

if ($tenant_filter) {
    $sql .= " AND rp.tenant_id = ?";
    $params[] = $tenant_filter;
}
if ($date_from) {
    $sql .= " AND rp.payment_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND rp.payment_date <= ?";
    $params[] = $date_to;
}
if ($search) {
    $sql .= " AND (u.full_name LIKE ? OR rp.reference_number LIKE ? OR r.room_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY rp.payment_date DESC, rp.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

echo json_encode($payments);
