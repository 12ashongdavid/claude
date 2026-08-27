<?php
// Builds the revenue report for a date range — combined rent + utility totals plus the underlying transaction list.
require_once __DIR__ . '/../config/database.php';
requireRole(['admin', 'staff']);

header('Content-Type: application/json');
$db = getDB();

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$dateOk = fn($d) => $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$dateOk($from) || !$dateOk($to)) {
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}
if ($from && $to && $from > $to) {
    echo json_encode(['error' => 'Start date must be before end date.']);
    exit;
}

// --- Rent payments within range ---
$where = '';
$params = [];
if ($from) { $where .= ' AND rp.payment_date >= ?'; $params[] = $from; }
if ($to)   { $where .= ' AND rp.payment_date <= ?'; $params[] = $to; }
$stmt = $db->prepare("SELECT COALESCE(SUM(rp.amount),0) AS revenue, COUNT(*) AS cnt FROM rent_payments rp WHERE rp.status='completed' $where");
$stmt->execute($params);
$rent = $stmt->fetch();
$rentRevenue = round((float)$rent['revenue'], 2);
$rentCount = (int)$rent['cnt'];

// --- Paid utility bills within range ---
$uWhere = '';
$uParams = [];
if ($from) { $uWhere .= ' AND ub.payment_date >= ?'; $uParams[] = $from; }
if ($to)   { $uWhere .= ' AND ub.payment_date <= ?'; $uParams[] = $to; }
$stmt = $db->prepare("SELECT COALESCE(SUM(ub.amount),0) AS revenue, COUNT(*) AS cnt FROM utility_bills ub WHERE ub.status='paid' $uWhere");
$stmt->execute($uParams);
$bills = $stmt->fetch();
$billRevenue = round((float)$bills['revenue'], 2);
$billCount = (int)$bills['cnt'];

// --- Combined transaction rows (newest first) ---
$stmt = $db->prepare("SELECT rp.payment_date AS d, 'rent' AS kind, u.full_name AS tenant, r.room_number AS room, rp.reference_number AS ref, rp.payment_method AS method, rp.amount AS amount, rp.month_covered AS note FROM rent_payments rp JOIN users u ON rp.tenant_id = u.id JOIN rooms r ON rp.room_id = r.id WHERE rp.status='completed' $where");
$stmt->execute($params);
$rentRows = $stmt->fetchAll();

$stmt = $db->prepare("SELECT ub.payment_date AS d, 'utility' AS kind, u.full_name AS tenant, r.room_number AS room, CONCAT('UBIL-', ub.id) AS ref, ub.payment_method AS method, ub.amount AS amount, ub.billing_month AS note FROM utility_bills ub JOIN users u ON ub.tenant_id = u.id JOIN rooms r ON ub.room_id = r.id WHERE ub.status='paid' $uWhere");
$stmt->execute($uParams);
$utilRows = $stmt->fetchAll();

$rows = array_merge($rentRows, $utilRows);
usort($rows, function ($a, $b) {
    $cmp = strcmp($b['d'], $a['d']);
    if ($cmp !== 0) return $cmp;
    return $a['amount'] <=> $b['amount'];
});
$rows = array_slice($rows, 0, 300);

echo json_encode([
    'from' => $from,
    'to' => $to,
    'totalRevenue' => $rentRevenue + $billRevenue,
    'rentRevenue' => $rentRevenue,
    'rentCount' => $rentCount,
    'billRevenue' => $billRevenue,
    'billCount' => $billCount,
    'rows' => $rows,
]);
