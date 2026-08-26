-- Migration 015: Create feedback_reports table
-- Tenants and public website visitors can submit feedback/complaints.
-- Admin/staff can view, respond, and manage status.

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
