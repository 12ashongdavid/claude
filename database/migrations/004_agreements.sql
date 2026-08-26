-- =====================================================
-- Migration 004: Tenant agreements
-- Admin/staff upload tenancy agreement documents per tenant,
-- shown on the tenant and admin dashboards.
-- =====================================================
USE pk_ams;

CREATE TABLE IF NOT EXISTS tenant_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_size INT DEFAULT 0,
    file_type VARCHAR(50) DEFAULT 'application/pdf',
    uploaded_by INT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_agr_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
