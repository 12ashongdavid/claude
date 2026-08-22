-- =====================================================
-- Migration 010: Rent reminder tracking
-- rent_reminder_log tracks which weekly rent-due reminder
-- was sent per tenant / covered month so the cron never
-- double-sends.
-- =====================================================
USE pk_ams;

CREATE TABLE IF NOT EXISTS rent_reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    month_covered VARCHAR(7) NOT NULL,
    week_no TINYINT NOT NULL,
    sent_at DATETIME NOT NULL,
    UNIQUE KEY uq_tenant_month_week (tenant_id, month_covered, week_no),
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
