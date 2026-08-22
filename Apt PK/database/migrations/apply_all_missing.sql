-- =====================================================
-- Run ALL missing migrations 013–015 at once
-- phpMyAdmin → pk_ams database → SQL tab → paste → Go
--
-- NOTE: If you see "Duplicate column name" errors for
-- migrations 013 or 014, that's fine — it just means
-- they were already applied. The 015 CREATE TABLE uses
-- IF NOT EXISTS so it won't error.
-- =====================================================

-- 013: Add last_login to users
ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL AFTER is_active;

-- 014: Add payment columns to booking_requests
ALTER TABLE booking_requests
    ADD COLUMN payment_type ENUM('none','down_payment','full_payment') DEFAULT 'none' AFTER message,
    ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT 0 AFTER payment_type,
    ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER payment_amount,
    ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL AFTER payment_method,
    ADD COLUMN payment_status ENUM('pending','completed','failed') DEFAULT 'pending' AFTER payment_reference;

-- 015: Create feedback_reports table
CREATE TABLE IF NOT EXISTS feedback_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    reporter_name VARCHAR(100) NOT NULL,
    reporter_email VARCHAR(100) DEFAULT NULL,
    reporter_phone VARCHAR(20) DEFAULT NULL,
    category ENUM('general','complaint','suggestion','maintenance','feedback','other') NOT NULL DEFAULT 'general',
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new','in_progress','resolved','closed') DEFAULT 'new',
    admin_reply TEXT DEFAULT NULL,
    admin_reply_by INT DEFAULT NULL,
    admin_reply_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_reply_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
