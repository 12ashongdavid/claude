<?php
// Home screen summary for a tenant: their residence, rent status, and
// recent activity.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

$stmt = $db->prepare("SELECT r.*, t.id as tenancy_id, t.monthly_rent, t.start_date, t.end_date, rt.charge_period
    FROM tenancies t JOIN rooms r ON t.room_id = r.id LEFT JOIN room_types rt ON rt.name = r.room_type
    WHERE t.tenant_id = ? AND t.status = 'active'");
$stmt->execute([$user['id']]);
$room = $stmt->fetch();

$arrears = computeTenantArrears($user['id']);
$paidThrough = getTenantPaidThroughRange($user['id']);

$stmt = $db->prepare("SELECT COUNT(*) FROM utility_bills WHERE tenant_id = ? AND status = 'unpaid'");
$stmt->execute([$user['id']]);
$unpaidBills = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = ? AND status IN ('submitted','in_progress')");
$stmt->execute([$user['id']]);
$openMaintenance = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user['id']]);
$unreadNotifications = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT payment_date, amount, payment_method, kind FROM (
        SELECT payment_date, amount, payment_method, 'Rent' AS kind, created_at
        FROM rent_payments WHERE tenant_id = ? AND status = 'completed'
        UNION ALL
        SELECT payment_date, amount, payment_method, CONCAT('Utility (', UCASE(bill_type), ')'), created_at
        FROM utility_bills WHERE tenant_id = ? AND status = 'paid' AND payment_date IS NOT NULL
    ) t
    ORDER BY payment_date DESC, created_at DESC
    LIMIT 5
");
$stmt->execute([$user['id'], $user['id']]);
$recentPayments = $stmt->fetchAll();

mobileJsonOut([
    'room' => $room ?: null,
    'owing' => $arrears['owing'],
    'owingMonths' => $arrears['months'],
    'paidThrough' => $paidThrough,
    'unpaidBills' => $unpaidBills,
    'openMaintenance' => $openMaintenance,
    'unreadNotifications' => $unreadNotifications,
    'recentPayments' => $recentPayments,
]);
