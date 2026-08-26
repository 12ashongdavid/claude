<?php
// =====================================================
// API: Admin/Staff dashboard live stats
// PK's Luxury Apartments — Apartment Management System
// Polled by the dashboard so finance figures update
// automatically when a tenant pays (e.g. via Paystack).
// =====================================================
require_once __DIR__ . '/../config/database.php';
requireRole(['admin', 'staff']);

header('Content-Type: application/json');
$db = getDB();

$revenueThisMonth = $db->query("
    SELECT
        (SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE status='completed' AND MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE()))
        + (SELECT COALESCE(SUM(amount),0) FROM utility_bills WHERE status='paid' AND payment_date IS NOT NULL AND MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE()))
")->fetchColumn();

$unpaidBills = (int)$db->query("SELECT COUNT(*) FROM utility_bills WHERE status='unpaid'")->fetchColumn();
$pendingMaintenance = (int)$db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('submitted','in_progress')")->fetchColumn();
$pendingBookings = (int)$db->query("SELECT COUNT(*) FROM booking_requests WHERE status='pending'")->fetchColumn();

$recentPayments = $db->query("
    SELECT payment_date, amount, payment_method, tenant_name, room_number, kind
    FROM (
        SELECT rp.payment_date AS payment_date, rp.amount AS amount, rp.payment_method AS payment_method,
               u.full_name AS tenant_name, r.room_number AS room_number, 'Rent' AS kind, rp.created_at AS created_at
        FROM rent_payments rp
        JOIN users u ON rp.tenant_id = u.id
        JOIN rooms r ON rp.room_id = r.id
        WHERE rp.status = 'completed'
        UNION ALL
        SELECT ub.payment_date, ub.amount, ub.payment_method,
               u.full_name, r.room_number, CONCAT('Utility (', UCASE(ub.bill_type), ')'), ub.created_at
        FROM utility_bills ub
        JOIN users u ON ub.tenant_id = u.id
        JOIN rooms r ON ub.room_id = r.id
        WHERE ub.status = 'paid' AND ub.payment_date IS NOT NULL
    ) t
    ORDER BY payment_date DESC, created_at DESC
    LIMIT 5
")->fetchAll();

echo json_encode([
    'revenueThisMonth' => round((float)$revenueThisMonth, 2),
    'unpaidBills' => $unpaidBills,
    'pendingMaintenance' => $pendingMaintenance,
    'pendingBookings' => $pendingBookings,
    'recentPayments' => $recentPayments,
]);
