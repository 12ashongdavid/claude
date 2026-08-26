<?php
// =====================================================
// Includes: Header
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/../config/database.php';
sendSecurityHeaders();
$user = currentUser();
$unreadNotifs = 0;
if ($user) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unreadNotifs = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? sanitize($pageTitle) . ' — ' : '' ?>PK's Luxury Apartments</title>
    <meta name="theme-color" content="#1B2A4A">
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/css/style.css?v=18">
</head>
<body>
<div class="app-layout">
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo"><i class='bx bx-building-house'></i></div>
            <div>
                <h2><span class="brand-gold">PK's</span> Luxury Apartments</h2>
                <small>Apartment Management</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bxs-dashboard'></i></span> Dashboard
            </a>

            <?php if ($user && in_array($user['role'], ['admin', 'staff'])): ?>
            <div class="nav-section">Management</div>
            <a href="rooms.php" class="<?= basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-door-open'></i></span> Residence
            </a>
            <a href="tenants.php" class="<?= basename($_SERVER['PHP_SELF']) == 'tenants.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-group'></i></span> Tenants
            </a>
            <?php if ($user && $user['role'] === 'admin'): ?>
            <a href="staff.php" class="<?= basename($_SERVER['PHP_SELF']) == 'staff.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-id-card'></i></span> Staff
            </a>
            <?php endif; ?>
            <a href="bookings.php" class="<?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-calendar-check'></i></span> Bookings
            </a>
            <?php endif; ?>

            <div class="nav-section">Finance</div>
            <a href="payments.php" class="<?= basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-money'></i></span> Rent Payments
            </a>
            <a href="utilities.php" class="<?= basename($_SERVER['PHP_SELF']) == 'utilities.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-bolt-circle'></i></span> Utility Bills
            </a>

            <?php if ($user && in_array($user['role'], ['admin', 'staff'])): ?>
            <div class="nav-section">Analytics</div>
            <a href="reports.php" class="<?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-line-chart'></i></span> Reports
            </a>
            <?php endif; ?>

            <div class="nav-section">Support</div>
            <a href="maintenance.php" class="<?= basename($_SERVER['PHP_SELF']) == 'maintenance.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-wrench'></i></span> Maintenance
            </a>
            <?php if ($user && in_array($user['role'], ['admin', 'staff'])): ?>
            <a href="feedback.php" class="<?= basename($_SERVER['PHP_SELF']) == 'feedback.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-message-detail'></i></span> Feedback Reports
            </a>
            <?php endif; ?>
            <a href="announcements.php" class="<?= basename($_SERVER['PHP_SELF']) == 'announcements.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-broadcast'></i></span> Announcements
            </a>
            <a href="notifications.php" class="<?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : '' ?>">
                <span class="icon"><i class='bx bx-bell'></i></span> Notifications
                <?php if ($unreadNotifs > 0): ?>
                    <span class="notif-badge" style="position:static;margin-left:auto;"><?= $unreadNotifs ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="profile.php"><span class="icon"><i class='bx bx-user-circle'></i></span> My Profile</a>
            <a href="api/logout.php"><span class="icon"><i class='bx bx-log-out'></i></span> Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <?php $flash = getFlash(); ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>" style="margin:16px 24px 0;"><?= sanitize($flash['message']) ?></div>
        <?php endif; ?>
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" onclick="toggleSidebar()"><i class='bx bx-menu'></i></button>
                <h2><?= isset($pageTitle) ? sanitize($pageTitle) : 'Dashboard' ?></h2>
            </div>
            <div class="topbar-right">
                <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle dark mode">
                    <i class='bx bx-moon'></i>
                </button>
                <div style="position:relative;">
                    <button class="notif-btn" onclick="toggleNotifDropdown()" id="notifBtn">
                        <i class='bx bx-bell'></i>
                        <?php if ($unreadNotifs > 0): ?>
                            <span class="notif-badge"><?= $unreadNotifs ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <h4>Notifications</h4>
                            <a href="notifications.php" style="font-size:0.78rem;">View All</a>
                        </div>
                        <?php
                        $db = getDB();
                        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                        $stmt->execute([$user['id']]);
                        $notifs = $stmt->fetchAll();
                        if ($notifs):
                            foreach ($notifs as $n): ?>
                            <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
                                <div class="notif-title"><?= sanitize($n['title']) ?></div>
                                <div class="notif-msg"><?= sanitize($n['message']) ?></div>
                                <div class="notif-time"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
                            </div>
                            <?php endforeach; else: ?>
                            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:0.85rem;">No notifications</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="topbar-user" onclick="location.href='profile.php'">
                    <img src="<?= SITE_URL ?>/uploads/profiles/<?= $user['profile_picture'] ?>" alt="Profile" loading="lazy" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['full_name']) ?>&background=47433E&color=fff'">
                    <span><?= sanitize($user['full_name']) ?></span>
                </div>
            </div>
        </header>
