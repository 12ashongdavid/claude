-- =====================================================
-- Migration 012: Notification type values (BUG FIX)
-- Widen notifications.type so the app can store 'utility'
-- (inserted by config/paystack_helper.php when a utility
-- bill is paid) and 'announcement' (inserted by
-- api/announcements.php). Without this, those inserts
-- fail with "Data truncated for column 'type'".
-- =====================================================
USE pk_ams;

ALTER TABLE notifications
    MODIFY type ENUM('info', 'warning', 'success', 'payment', 'maintenance', 'utility', 'announcement') DEFAULT 'info';
