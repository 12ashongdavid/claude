<?php
// Daily cron job: deactivates tenants/staff who haven't logged in for 365 days.
// Schedule example: 0 2 * * * php /path/to/cron/deactivate_inactive.php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

$inactiveDays = 365;
$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$inactiveDays} days"));

// Find users who haven't logged in within the period
// Include users who have NEVER logged in (last_login IS NULL) and were created > 365 days ago
$stmt = $db->prepare("
    SELECT id, full_name, username, role, email, phone, last_login, created_at
    FROM users
    WHERE is_active = 1
      AND role IN ('tenant', 'staff')
      AND (
          (last_login IS NULL AND created_at < ?)
          OR (last_login IS NOT NULL AND last_login < ?)
      )
");
$stmt->execute([$cutoffDate, $cutoffDate]);
$inactiveUsers = $stmt->fetchAll();

if (empty($inactiveUsers)) {
    echo "No inactive accounts found.\n";
    exit(0);
}

$deactivated = 0;
$failed = 0;

foreach ($inactiveUsers as $u) {
    try {
        // Set user to inactive
        $upd = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $upd->execute([$u['id']]);

        // End any active tenancy
        $roomIds = $db->prepare("SELECT room_id FROM tenancies WHERE tenant_id = ? AND status = 'active'");
        $roomIds->execute([$u['id']]);
        $freedRooms = $roomIds->fetchAll(PDO::FETCH_COLUMN);

        $tenancy = $db->prepare("UPDATE tenancies SET status = 'terminated', end_date = CURDATE() WHERE tenant_id = ? AND status = 'active'");
        $tenancy->execute([$u['id']]);

        // Free up their room(s)
        if ($freedRooms) {
            $placeholders = implode(',', array_fill(0, count($freedRooms), '?'));
            $tenanted = $db->prepare("UPDATE rooms SET status = 'available' WHERE id IN ($placeholders) AND status = 'occupied'");
            $tenanted->execute($freedRooms);
        }

        // Log notification
        $logMsg = $u['last_login']
            ? "Account deactivated: no login since " . date('M j, Y', strtotime($u['last_login'])) . " (>{$inactiveDays} days)."
            : "Account deactivated: never logged in. Account created " . date('M j, Y', strtotime($u['created_at'])) . " (>{$inactiveDays} days ago).";

        // Notify admin
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            $n = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Auto-Deactivated Account', ?, 'warning')");
            $n->execute([$adminId, "User {$u['full_name']} ({$u['role']}) was auto-deactivated. $logMsg"]);
        }

        // Notify the user
        $n = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Deactivated', ?, 'warning')");
        $n->execute([$u['id'], "Your account has been deactivated due to inactivity (> {$inactiveDays} days without login). Please contact management to reactivate."]);

        sendSMS($u['phone'], "Dear {$u['full_name']}, your PK's Luxury Apartments account has been deactivated due to inactivity. Please contact management to reactivate.");

        echo "Deactivated: {$u['full_name']} ({$u['role']}) — {$logMsg}\n";
        $deactivated++;
    } catch (Exception $e) {
        echo "FAILED: {$u['full_name']} — " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nDone. Deactivated: $deactivated, Failed: $failed.\n";
exit(0);
