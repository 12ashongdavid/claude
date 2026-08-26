<?php
// =====================================================
// Notifications Page
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Notifications';
requireLogin();

$db = getDB();
$user = currentUser();

// Mark all as read
if (isset($_GET['mark_all'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    header('Location: notifications.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <a href="notifications.php?mark_all=1" class="btn btn-secondary">Mark All as Read</a>
</div>

<div class="card">
    <div class="card-header">
        <h3>All Notifications (<?= count($notifications) ?>)</h3>
    </div>
    <div id="notifList">
        <?php if ($notifications): ?>
            <?php foreach ($notifications as $n): ?>
            <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>" style="border-radius:var(--radius-sm);margin-bottom:4px;" id="notif-<?= $n['id'] ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <div class="notif-title">
                            <?php
                            $typeIcons = ['info' => '<i class="bx bx-info-circle"></i>', 'warning' => '<i class="bx bx-error"></i>', 'success' => '<i class="bx bx-check-circle"></i>', 'payment' => '<i class="bx bx-money"></i>', 'maintenance' => '<i class="bx bx-wrench"></i>', 'announcement' => '<i class="bx bx-broadcast"></i>'];
                            echo $typeIcons[$n['type']] ?? '<i class="bx bx-info-circle"></i>'; ?>
                            <?= sanitize($n['title']) ?>
                        </div>
                        <div class="notif-msg"><?= sanitize($n['message']) ?></div>
                        <?php if ($n['link']): ?>
                            <a href="<?= sanitize($n['link']) ?>" style="font-size:0.78rem;font-weight:600;">View Details &rarr;</a>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;min-width:120px;">
                        <div class="notif-time"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
                        <?php if (!$n['is_read']): ?>
                            <button class="btn btn-sm btn-outline" style="margin-top:4px;font-size:0.72rem;" onclick="markRead(<?= $n['id'] ?>)">Mark Read</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="bx bx-bell" style="font-size:3rem;"></i></div>
                <h4>No notifications</h4>
                <p>You're all caught up!</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
async function markRead(id) {
    const form = new FormData();
    form.append('action', 'mark_read');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    await fetch('api/notifications.php', { method: 'POST', body: form });
    const el = document.getElementById('notif-' + id);
    if (el) el.classList.remove('unread');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
