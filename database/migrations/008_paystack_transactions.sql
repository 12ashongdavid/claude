-- =====================================================
-- Migration 008: Paystack transaction ledger
-- paystack_transactions provides idempotency and an
-- audit trail for online payments (webhook callbacks).
-- =====================================================
USE pk_ams;

CREATE TABLE IF NOT EXISTS paystack_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(100) NOT NULL UNIQUE,
    pay_type VARCHAR(20) NOT NULL,
    tenant_id INT,
    amount DECIMAL(10,2) NOT NULL,
    bill_id INT,
    month_covered VARCHAR(7),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pst_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
