<?php
// =====================================================
// Cron: Rent Due Reminders
// PK's Luxury Apartments — Apartment Management System
//
// For every active tenancy, finds the latest covered rent
// month (max month_covered among completed rent payments).
// Starting one month before that covered period expires and
// ending on its last day, sends up to 4 weekly reminders
// (SMS + in-app notification) to the tenant and to all admins.
//
// Idempotent: each tenant / covered month / week is sent once
// (tracked in rent_reminder_log).
//
// Run daily via Windows Task Scheduler:
//   "C:\xampp1\php\php.exe" "C:\xampp1\htdocs\Apt PK\cron\rent_reminders.php"
// =====================================================

// CLI only (prevents direct web access)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$today = date('Y-m-d');

$tenancies = $db->query("
    SELECT t.tenant_id, t.room_id, u.full_name, u.phone, r.room_number
    FROM tenancies t
    JOIN users u ON u.id = t.tenant_id AND u.is_active = 1
    JOIN rooms r ON r.id = t.room_id
    WHERE t.status = 'active'
")->fetchAll();

$admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();

$insLog = $db->prepare("INSERT IGNORE INTO rent_reminder_log (tenant_id, month_covered, week_no, sent_at) VALUES (?, ?, ?, NOW())");
$insNotifTenant = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Rent Due Reminder', ?, 'payment', 'payments.php')");
$insNotifAdmin = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, 'Tenant Rent Due', ?, 'payment', 'payments.php')");

$sent = 0;
$due = [];

foreach ($tenancies as $t) {
    $stmt = $db->prepare("SELECT MAX(month_covered) FROM rent_payments WHERE tenant_id = ? AND status = 'completed'");
    $stmt->execute([$t['tenant_id']]);
    $covered = $stmt->fetchColumn();
    if (!$covered) {
        continue; // no paid month yet — nothing to remind about
    }

    // The covered period expires on the LAST day of the covered month
    $expiry = date('Y-m-t', strtotime($covered . '-01'));
    $windowStart = $covered . '-01';

    if ($today < $windowStart) {
        continue; // too early — reminder starts one month before expiry
    }
    if ($today > $expiry) {
        continue; // rent is over — reminders stop
    }

    $days = (int)((strtotime($today) - strtotime($windowStart)) / 86400);
    $week = (int)floor($days / 7) + 1;
    if ($week < 1) $week = 1;
    if ($week > 4) $week = 4;

    // Idempotency guard (unique key also protects against races)
    $stmt = $db->prepare("SELECT id FROM rent_reminder_log WHERE tenant_id = ? AND month_covered = ? AND week_no = ?");
    $stmt->execute([$t['tenant_id'], $covered, $week]);
    if ($stmt->fetch()) {
        continue;
    }

    $monthLabel = date('F Y', strtotime($covered . '-01'));
    $expiryLabel = date('jS F Y', strtotime($expiry));

    // Tenant — in-app + SMS
    $tenantMsg = "Friendly reminder: your rent covering $monthLabel ends on $expiryLabel. Please pay before then to avoid interruption. — " . SITE_NAME;
    $insNotifTenant->execute([$t['tenant_id'], $tenantMsg]);
    sendSMS($t['phone'], "Dear " . $t['full_name'] . ", " . $tenantMsg);

    // Admins — in-app + SMS
    $adminMsg = "Rent due reminder: " . $t['full_name'] . " (Room " . $t['room_number'] . ") — $monthLabel ends $expiryLabel. Weekly reminder $week/4.";
    foreach ($admins as $admin) {
        $insNotifAdmin->execute([$admin['id'], $adminMsg]);
        sendSMS($admin['phone'], $adminMsg);
    }

    $insLog->execute([$t['tenant_id'], $covered, $week]);
    $sent++;
    $due[] = $t['full_name'] . ' (' . $t['room_number'] . ')';
}

echo date('Y-m-d H:i:s') . " — rent reminders sent: $sent\n";
if ($sent > 0) {
    echo "Tenants reminded: " . implode(', ', $due) . "\n";
}
