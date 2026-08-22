-- =====================================================
-- Migration 005: Targeted announcements
-- Adds announcements.target_tenant_id so an announcement
-- can be sent to a single tenant (NULL = broadcast to all).
-- =====================================================
USE pk_ams;

ALTER TABLE announcements ADD COLUMN target_tenant_id INT NULL AFTER content;

ALTER TABLE announcements
    ADD CONSTRAINT fk_announcement_target_tenant
    FOREIGN KEY (target_tenant_id) REFERENCES users(id) ON DELETE SET NULL;
