<?php
// The tenant's notification list, and marking them read/deleting them.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        }
        mobileJsonOut(['success' => true]);
    }
    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
        mobileJsonOut(['success' => true]);
    }
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        }
        mobileJsonOut(['success' => true]);
    }
    mobileJsonOut(['error' => 'Invalid action.'], 400);
}

// GET: full list, own notifications only
$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$user['id']]);
mobileJsonOut(['notifications' => $stmt->fetchAll()]);
